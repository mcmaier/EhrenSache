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

/** Check-in als Rolle 'user' auf die eigene member_id. */
function ciCheckin(array $body): array
{
    return apiRequest('POST', 'auto_checkin', [
        'token' => apiToken('user'),
        'body'  => $body,
    ]);
}

/** Terminart, die an eine Gruppe gebunden ist, in der die Rolle user NICHT ist. */
function ciRestrictedTypeId(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $group = apiRequest('POST', 'member_groups', [
        'token' => apiToken('admin'),
        'body'  => ['group_name' => 'Fremdgruppe ' . uniqid()],
    ]);
    assertStatus(201, $group, 'Testgruppe konnte nicht angelegt werden');
    $groupId = ciTrack('group', (int) $group['body']['id']);

    $type = apiRequest('POST', 'appointment_types', [
        'token' => apiToken('admin'),
        'body'  => [
            'type_name' => 'Fremdart ' . uniqid(),
            'group_ids' => [$groupId],
        ],
    ]);
    assertStatus(201, $type, 'Eingeschraenkte Terminart konnte nicht angelegt werden');

    return $id = ciTrack('appointment_type', (int) $type['body']['id']);
}

test('Check-in trifft einen Termin im Toleranzfenster', function () {
    ciWithReset(['checkin_tolerance_hours' => '2'], function () {
        ciSetSetting('checkin_tolerance_hours', '2');

        $now  = new DateTime();
        $time = $now->format('H:i:s');

        [$status, $id] = ciCreateAppointment('Treffer-Probe', date('Y-m-d'), $time);
        assertSame(201, $status, 'Termin fuer den Treffer konnte nicht angelegt werden');

        $res = ciCheckin(['arrival_time' => $now->format('Y-m-d H:i:s')]);

        assertTrue(in_array($res['status'], [200, 201], true),
            'Check-in muss gelingen, HTTP ' . $res['status']);
        assertSame('matched', $res['body']['appointment_action'] ?? null,
            'Ein vorhandener Termin im Fenster muss getroffen, nicht neu angelegt werden');
        assertSame($id, (int) ($res['body']['appointment_id'] ?? 0),
            'Es muss genau der angelegte Termin getroffen werden');
    });
});

/** Zaehlt die heutigen Termine einer Terminart. */
function ciCountAppointments(int $typeId): int
{
    $res = apiRequest('GET', 'appointments', [
        'token' => apiToken('admin'),
        'query' => ['from_date' => date('Y-m-d'), 'to_date' => date('Y-m-d')],
    ]);

    $count = 0;
    foreach (($res['body'] ?? []) as $apt) {
        if ((int) ($apt['type_id'] ?? 0) === $typeId) {
            $count++;
        }
    }

    return $count;
}

test('Automatik aus: Check-in ohne passenden Termin wird abgewiesen', function () {
    ciWithReset(['checkin_auto_create_appointment' => '1', 'checkin_tolerance_hours' => '2'], function () {
        ciSetSetting('checkin_auto_create_appointment', '0');
        ciSetSetting('checkin_tolerance_hours', '0');

        $before = ciCountAppointments(ciTypeId());

        // Toleranz 0 heisst: nur ein sekundengenauer Treffer zaehlt. Eine
        // Ankunft auf einer krummen Sekunde trifft deshalb sicher nichts.
        $res = ciCheckin(['arrival_time' => date('Y-m-d') . ' 03:17:43']);

        assertSame(409, $res['status'], 'Ohne Automatik muss der Check-in mit 409 scheitern');
        assertSame('no_matching_appointment', $res['body']['reason'] ?? null,
            'Die Antwort muss den Grund maschinenlesbar nennen');
        assertSame($before, ciCountAppointments(ciTypeId()),
            'Es darf kein Termin angelegt worden sein');
    });
});

test('Automatik an: Check-in ohne passenden Termin legt einen Termin an', function () {
    ciWithReset(['checkin_auto_create_appointment' => '1', 'checkin_tolerance_hours' => '2'], function () {
        ciSetSetting('checkin_auto_create_appointment', '1');
        ciSetSetting('checkin_tolerance_hours', '0');

        $res = ciCheckin(['arrival_time' => date('Y-m-d') . ' 04:19:47']);

        assertSame(201, $res['status'], 'Mit Automatik muss der Check-in gelingen');
        assertSame('created', $res['body']['appointment_action'] ?? null,
            'Die Antwort muss melden, dass ein Termin angelegt wurde');

        ciTrack('appointment', (int) ($res['body']['appointment_id'] ?? 0));
    });
});

test('Gewaehlter Termin desselben Tages wird uebernommen', function () {
    ciWithReset(['checkin_auto_create_appointment' => '1', 'checkin_tolerance_hours' => '2'], function () {
        ciSetSetting('checkin_auto_create_appointment', '0');
        ciSetSetting('checkin_tolerance_hours', '0');

        [$status, $id] = ciCreateAppointment('Gewaehlt-Probe', date('Y-m-d'), '21:00:00');
        assertSame(201, $status, 'Termin fuer die Auswahl konnte nicht angelegt werden');

        // Toleranz 0 heisst hier: nur ein exakter Treffer zaehlt. Die
        // automatische Suche faende bei 0h nichts (Isolationszweck dieses
        // Tests), die Ankunft trifft aber exakt die Terminzeit und erfuellt
        // damit auch die seit dem 2026-09-03 gemeldeten Toleranzpruefung.
        $res = ciCheckin([
            'arrival_time'   => date('Y-m-d') . ' 21:00:00',
            'appointment_id' => $id,
        ]);

        assertTrue(in_array($res['status'], [200, 201], true),
            'Gewaehlter Termin muss den Check-in retten, HTTP ' . $res['status']);
        assertSame($id, (int) ($res['body']['appointment_id'] ?? 0),
            'Es muss der gewaehlte Termin verwendet werden');
    });
});

test('Gewaehlter Termin von gestern wird abgewiesen', function () {
    $gestern = (new DateTime('-1 day'))->format('Y-m-d');

    [$status, $id] = ciCreateAppointment('Gestern-Probe', $gestern, '20:00:00');
    assertSame(201, $status, 'Termin von gestern konnte nicht angelegt werden');

    $res = ciCheckin([
        'arrival_time'   => date('Y-m-d') . ' 06:22:13',
        'appointment_id' => $id,
    ]);

    assertSame(409, $res['status'],
        'Ein Termin an einem anderen Tag darf nicht waehlbar sein — sonst liesse '
        . 'sich Anwesenheit rueckwirkend behaupten');
});

test('Gewaehlter Termin einer fremden Gruppe wird abgewiesen', function () {
    [$status, $id] = ciCreateAppointment(
        'Fremdgruppe-Probe', date('Y-m-d'), '22:30:00', ciRestrictedTypeId()
    );
    assertSame(201, $status, 'Termin der Fremdgruppe konnte nicht angelegt werden');

    $res = ciCheckin([
        'arrival_time'   => date('Y-m-d') . ' 07:23:17',
        'appointment_id' => $id,
    ]);

    assertSame(403, $res['status'], 'Termin einer fremden Gruppe muss 403 liefern');
});

test('Unbekannter Termin wird abgewiesen', function () {
    $res = ciCheckin([
        'arrival_time'   => date('Y-m-d') . ' 08:24:19',
        'appointment_id' => 999999999,
    ]);

    assertSame(404, $res['status'], 'Ein nicht existierender Termin muss 404 liefern');
});

test('Automatisch erzeugter Termin traegt is_auto_created', function () {
    ciWithReset(['checkin_auto_create_appointment' => '1', 'checkin_tolerance_hours' => '2'], function () {
        ciSetSetting('checkin_auto_create_appointment', '1');
        ciSetSetting('checkin_tolerance_hours', '0');

        $res = ciCheckin(['arrival_time' => date('Y-m-d') . ' 09:26:29']);
        assertSame(201, $res['status'], 'Check-in mit Automatik muss gelingen');

        $id = ciTrack('appointment', (int) $res['body']['appointment_id']);
        $apt = apiRequest('GET', 'appointments', [
            'token' => apiToken('admin'),
            'query' => ['id' => $id],
        ]);

        assertSame(1, (int) ($apt['body']['is_auto_created'] ?? 0),
            'Ein vom Check-in erzeugter Termin muss markiert sein');
    });
});

test('Von Hand angelegter Termin traegt is_auto_created nicht', function () {
    [$status, $id] = ciCreateAppointment('Handarbeit-Probe', date('Y-m-d'), '23:30:00');
    assertSame(201, $status, 'Termin konnte nicht angelegt werden');

    $apt = apiRequest('GET', 'appointments', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
    ]);

    assertSame(0, (int) ($apt['body']['is_auto_created'] ?? 0),
        'Die Markierung darf sich nicht ueber die Termin-API setzen lassen — '
        . 'sonst waere sie faelschbar und damit wertlos');
});

test('scope=client liefert genau die Whitelist an eine Rolle user', function () {
    $res = apiRequest('GET', 'settings', [
        'token' => apiToken('user'),
        'query' => ['scope' => 'client'],
    ]);

    assertStatus(200, $res, 'Rolle user muss die Client-Einstellungen lesen duerfen');

    $keys = array_keys($res['body']['settings'] ?? []);
    sort($keys);

    assertSame(
        ['checkin_auto_create_appointment', 'checkin_tolerance_hours'],
        $keys,
        'Es duerfen ausschliesslich die zwei freigegebenen Schluessel erscheinen'
    );
});

test('settings ohne scope bleibt Administratoren vorbehalten', function () {
    $res = apiRequest('GET', 'settings', ['token' => apiToken('user')]);

    assertTrue(in_array($res['status'], [401, 403], true),
        'Ohne scope=client muss die Rolle user abgewiesen werden, HTTP ' . $res['status']);
});

test('scope=client ohne Anmeldung wird abgewiesen', function () {
    $res = apiRequest('GET', 'settings', ['query' => ['scope' => 'client']]);

    assertTrue(in_array($res['status'], [401, 403], true),
        'Unangemeldet darf nichts gelesen werden, HTTP ' . $res['status']);
});

/**
 * Neue, eigene Terminart je Aufruf — NICHT statisch zwischengespeichert.
 *
 * Zwei Tests, die sich dieselbe Terminart teilen, kollidieren untereinander
 * an der Dublettenpruefung von appointments.php, sobald ihre Termine
 * innerhalb der Toleranz liegen — genau das, was diese Tests pruefen sollen.
 * Jeder Aufruf bekommt deshalb eine frische Terminart.
 */
function ciFreshTypeId(): int
{
    $res = apiRequest('POST', 'appointment_types', [
        'token' => apiToken('admin'),
        'body'  => ['type_name' => 'Toleranz-Auswahl-Art ' . uniqid()],
    ]);
    assertSame(201, $res['status'], 'Terminart fuer die Toleranz-Tests konnte nicht angelegt werden');

    return ciTrack('appointment_type', (int) $res['body']['id']);
}

test('Gewaehlter Termin ausserhalb der Toleranz wird abgewiesen', function () {
    // Gemeldet am 2026-09-03: Ein Mitglied konnte einen Termin von 10:00 Uhr
    // waehlen und noch um 16:47 Uhr dafuer einchecken — 6h47 Abstand bei
    // Standardtoleranz 2h. Der gewaehlte Pfad pruefte bislang nur Existenz,
    // Gruppe und Tag, nie die Zeitnaehe. Die Tagesgrenze ist eine harte
    // Sicherung unabhaengig von der Toleranz (siehe Kommentar im Handler);
    // die Toleranz ist die zusaetzliche, konfigurierbare Schranke dazu.
    ciWithReset(['checkin_tolerance_hours' => '2'], function () {
        ciSetSetting('checkin_tolerance_hours', '2');

        [$status, $id] = ciCreateAppointment(
            'Toleranz-Auswahl weit', date('Y-m-d'), '10:00:00', ciFreshTypeId()
        );
        assertSame(201, $status, 'Termin fuer den Toleranztest konnte nicht angelegt werden');

        $res = ciCheckin([
            'arrival_time'   => date('Y-m-d') . ' 16:47:00',
            'appointment_id' => $id,
        ]);

        assertSame(409, $res['status'],
            'Ein 6h47 entfernter Termin darf bei 2h Toleranz nicht angenommen werden');
        assertSame('appointment_outside_tolerance', $res['body']['reason'] ?? null,
            'Die Antwort muss den Grund maschinenlesbar nennen');
    });
});

test('Gewaehlter Termin innerhalb der Toleranz wird weiterhin uebernommen', function () {
    ciWithReset(['checkin_tolerance_hours' => '2'], function () {
        ciSetSetting('checkin_tolerance_hours', '2');

        [$status, $id] = ciCreateAppointment(
            'Toleranz-Auswahl nah', date('Y-m-d'), '11:00:00', ciFreshTypeId()
        );
        assertSame(201, $status, 'Termin fuer den Toleranztest konnte nicht angelegt werden');

        // 90 Minuten Abstand — innerhalb der 2h-Toleranz.
        $res = ciCheckin([
            'arrival_time'   => date('Y-m-d') . ' 12:30:00',
            'appointment_id' => $id,
        ]);

        assertTrue(in_array($res['status'], [200, 201], true),
            'Ein 90 Minuten entfernter Termin muss bei 2h Toleranz weiterhin gelingen, HTTP '
            . $res['status']);
        assertSame($id, (int) ($res['body']['appointment_id'] ?? 0),
            'Es muss der gewaehlte Termin verwendet werden');
    });
});

/**
 * Muss der LETZTE Test der Datei sein — der Runner laedt Suiten in
 * Dateireihenfolge, und alles davor legt noch Daten an.
 *
 * Reihenfolge der Loeschung: Termine vor Terminarten vor Gruppen. Die
 * records-Eintraege der Check-ins verschwinden per ON DELETE CASCADE mit ihren
 * Terminen.
 */
test('Aufraeumen: die Suite entfernt alles, was sie angelegt hat', function () {
    $token = apiToken('admin');

    foreach (['appointment' => 'appointments',
              'appointment_type' => 'appointment_types',
              'group' => 'member_groups'] as $kind => $resource) {
        foreach (ciCreated($kind) as $id) {
            apiRequest('DELETE', $resource, ['token' => $token, 'query' => ['id' => $id]]);
        }
    }

    // Stichprobe: der erste angelegte Termin ist fort.
    $appointments = ciCreated('appointment');
    if ($appointments) {
        $res = apiRequest('GET', 'appointments', [
            'token' => $token,
            'query' => ['id' => $appointments[0]],
        ]);
        assertTrue(
            $res['status'] === 404 || empty($res['body']['appointment_id']),
            'Der erste Testtermin haette entfernt sein muessen, HTTP ' . $res['status']
        );
    }

    assertTrue(true, 'Aufraeumen durchgelaufen');
});
