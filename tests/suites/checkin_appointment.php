<?php
/**
 * Check-in und Terminbezug.
 *
 * Deckt ab: das Zeitfenster aus checkin_tolerance_hours, den Schalter
 * checkin_auto_create_appointment, den vom Client gewaehlten Termin und die
 * Markierung is_auto_created.
 *
 * Die Suite setzt Systemeinstellungen um. Jeder Test, der sie veraendert,
 * setzt sie am Ende zurueck — das Zeitfenster wirkt auch auf die
 * Dublettenpruefung beim Terminanlegen, und worktime_api.php legt Termine im
 * Drei-Stunden-Raster an. Ein stehen gebliebener Wert von 4 bricht die
 * nachfolgenden Suiten.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** Setzt eine Systemeinstellung. PUT settings erwartet {setting_key, setting_value}. */
function ciSetSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

/**
 * Merkt sich, was die Suite angelegt hat, damit der Abschlusstest es entfernen
 * kann. Ohne das waechst die Entwicklungsdatenbank mit jedem Lauf.
 */
function ciTrack(string $kind, int $id): int
{
    static $created = ['appointment' => [], 'appointment_type' => [], 'group' => []];
    if ($id > 0) {
        $created[$kind][] = $id;
    }

    return $id;
}

/** @return array<int, int> */
function ciCreated(string $kind): array
{
    $statics = (new ReflectionFunction('ciTrack'))->getStaticVariables();

    return $statics['created'][$kind] ?? [];
}

/** Eigene Terminart, damit die Dublettenpruefung nie echte Termine trifft. */
function ciTypeId(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $res = apiRequest('POST', 'appointment_types', [
        'token' => apiToken('admin'),
        'body'  => ['type_name' => 'Checkin-Terminwahl ' . uniqid()],
    ]);
    assertStatus(201, $res, 'Test-Terminart konnte nicht angelegt werden');

    return $id = ciTrack('appointment_type', (int) $res['body']['id']);
}

/** Legt einen Termin an und liefert [status, id]. */
function ciCreateAppointment(string $title, string $date, string $time, ?int $typeId = null): array
{
    $res = apiRequest('POST', 'appointments', [
        'token' => apiToken('admin'),
        'body'  => [
            'title'      => $title,
            'date'       => $date,
            'start_time' => $time,
            'type_id'    => $typeId ?? ciTypeId(),
        ],
    ]);

    return [$res['status'], ciTrack('appointment', (int) ($res['body']['id'] ?? 0))];
}

/**
 * Fuehrt $fn aus und setzt die genannten Einstellungen danach zurueck — auch
 * wenn $fn eine Assertion reisst.
 *
 * Ohne finally hinterlaesst der erste rote Test einen veraenderten Schalter in
 * der Datenbank und bricht damit die nachfolgenden Suiten. Beim ersten Lauf
 * dieser Suite ist genau das passiert: checkin_tolerance_hours blieb auf 4
 * stehen.
 *
 * @param array<string, string> $reset Schluessel => Wert nach dem Test
 */
function ciWithReset(array $reset, callable $fn): void
{
    try {
        $fn();
    } finally {
        foreach ($reset as $key => $value) {
            ciSetSetting($key, $value);
        }
    }
}

test('checkin_tolerance_hours steuert die Dublettenpruefung der Termine', function () {
    ciWithReset(['checkin_tolerance_hours' => '2'], function () {
        // Vergleichstermine bei der Vorgabe-Toleranz anlegen, sonst kollidieren
        // sie miteinander, sobald das Fenster groesser wird.
        ciSetSetting('checkin_tolerance_hours', '2');

        [$status] = ciCreateAppointment('Toleranz-Anker', date('Y-m-d'), '09:00:00');
        assertSame(201, $status, 'Ankertermin konnte nicht angelegt werden');

        // Drei Stunden Abstand: bei Toleranz 2 kein Konflikt.
        [$status] = ciCreateAppointment('Toleranz-Probe weit', date('Y-m-d'), '12:00:00');
        assertSame(201, $status, 'Bei Toleranz 2 darf ein Termin nach 3 h angelegt werden');

        // Fenster auf 4 h aufziehen — derselbe Abstand ist jetzt ein Konflikt.
        ciSetSetting('checkin_tolerance_hours', '4');

        [$status] = ciCreateAppointment('Toleranz-Probe eng', date('Y-m-d'), '15:00:00');
        assertSame(409, $status,
            'Bei Toleranz 4 muss ein Termin 3 h nach dem letzten abgewiesen werden — '
            . 'die Toleranz wird also noch aus der Konstante gelesen');
    });
});
