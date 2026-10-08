<?php
/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

/**
 * Wessen Uhr gilt beim Check-in?
 *
 * Ein Mitglied (Rolle user) stempelt mit der Uhr des Servers — eine
 * mitgeschickte arrival_time wirkt nicht. Sonst liesse sich mit einem
 * erfundenen Zeitpunkt jeder vergangene Termin der eigenen Gruppe als
 * anwesend und puenktlich eintragen, beim TOTP-Weg sogar mit Ortsnachweis.
 * Tagesgrenze und Toleranzfenster rechneten bis dahin gegen genau diesen
 * Client-Wert.
 *
 * Ein Geraet darf seine Zeit mitschicken (Offline-Warteschlange des
 * IoT-Terminals), aber nur innerhalb des Toleranzfensters zurueck und nur
 * knapp in die Zukunft. Admin und Manager duerfen sie frei setzen — sie
 * schreiben Anwesenheiten ohnehin ueber records.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../../private/helpers/totp.php';

if (!extension_loaded('curl')) {
    return;
}

const CST_TOTP_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

/** Merkt sich Angelegtes fuer den Abschlusstest. */
function cstTrack(string $kind, int $id): int
{
    static $created = ['appointment' => [], 'appointment_type' => [], 'device' => []];
    if ($id > 0) {
        $created[$kind][] = $id;
    }

    return $id;
}

/** @return array<int, int> */
function cstCreated(string $kind): array
{
    $statics = (new ReflectionFunction('cstTrack'))->getStaticVariables();

    return $statics['created'][$kind] ?? [];
}

/** Frische Terminart je Aufruf, damit die Dublettenpruefung nie anschlaegt. */
function cstTypeId(): int
{
    $res = apiRequest('POST', 'appointment_types', [
        'token' => apiToken('admin'),
        'body'  => ['type_name' => 'Serverzeit-Art ' . uniqid()],
    ]);
    assertStatus(201, $res, 'Terminart konnte nicht angelegt werden');

    return cstTrack('appointment_type', (int) $res['body']['id']);
}

/** Legt einen Termin zum Zeitpunkt $ts an (Minute genau) und liefert seine ID. */
function cstAppointment(string $title, int $ts): int
{
    $res = apiRequest('POST', 'appointments', [
        'token' => apiToken('admin'),
        'body'  => [
            'title'      => $title,
            'date'       => date('Y-m-d', $ts),
            'start_time' => date('H:i:00', $ts),
            'type_id'    => cstTypeId(),
        ],
    ]);
    assertStatus(201, $res, "Termin '{$title}' konnte nicht angelegt werden");

    return cstTrack('appointment', (int) $res['body']['id']);
}

/** Anwesenheiten eines Termins, gelesen als Admin. */
function cstRecords(int $appointmentId): array
{
    $res = apiRequest('GET', 'records', [
        'token' => apiToken('admin'),
        'query' => ['appointment_id' => $appointmentId],
    ]);
    assertStatus(200, $res, 'Anwesenheiten konnten nicht gelesen werden');

    return array_values(array_filter($res['body'] ?? [],
        fn($r) => (int) ($r['appointment_id'] ?? 0) === $appointmentId));
}

/** Prueft, dass ein Zeitstempel hoechstens $slack Sekunden von jetzt abweicht. */
function cstAssertNow(?string $stamp, string $msg, int $slack = 120): void
{
    assertTrue($stamp !== null && $stamp !== '', "{$msg}: keine arrival_time");
    $diff = abs(strtotime((string) $stamp) - time());
    assertTrue($diff <= $slack, "{$msg}: {$stamp} liegt {$diff} s von jetzt entfernt");
}

/** Legt ein Geraet an und liefert [user_id, api_token]. */
function cstDevice(string $type): array
{
    static $devices = [];
    if (isset($devices[$type])) {
        return $devices[$type];
    }

    $res = apiRequest('POST', 'users', [
        'token' => apiToken('admin'),
        'body'  => [
            'action'       => 'create_device',
            'device_name'  => "Serverzeit-{$type} " . uniqid(),
            'device_type'  => $type,
            'totp_enabled' => $type === 'totp_location',
        ],
    ]);
    assertStatus(200, $res, "Geraet '{$type}' konnte nicht angelegt werden");
    $id = cstTrack('device', (int) $res['body']['device']['user_id']);

    if ($type === 'totp_location') {
        $put = apiRequest('PUT', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => $id],
            'body'  => ['totp_secret' => CST_TOTP_SECRET],
        ]);
        assertStatus(200, $put, 'Secret der Test-Station konnte nicht gesetzt werden');
    }

    return $devices[$type] = [$id, (string) $res['body']['device']['api_token']];
}

function cstTotpCode(): string
{
    cstDevice('totp_location');

    return (new TOTP(CST_TOTP_SECRET))->getCode(time());
}

// ---- Rolle user: die Uhr des Servers gilt ----------------------------------

test('user: rueckdatierter Check-in auf einen Termin von gestern wird abgewiesen', function () {
    $gestern = time() - 86400;
    $id = cstAppointment('Serverzeit gestern', $gestern);

    $res = apiRequest('POST', 'auto_checkin', [
        'token' => apiToken('user'),
        'body'  => [
            'arrival_time'   => date('Y-m-d H:i:00', $gestern),
            'appointment_id' => $id,
        ],
    ]);

    assertSame(409, $res['status'],
        'Ein Mitglied darf sich nicht mit erfundener Ankunftszeit fuer gestern eintragen, HTTP '
        . $res['status']);
    assertSame('appointment_wrong_day', $res['body']['reason'] ?? null);
    assertSame(0, count(cstRecords($id)), 'Es darf keine Anwesenheit entstanden sein');
});

test('user: mitgeschickte arrival_time wirkt nicht, gespeichert wird die Serverzeit', function () {
    $id = cstAppointment('Serverzeit jetzt', time());

    $res = apiRequest('POST', 'auto_checkin', [
        'token' => apiToken('user'),
        'body'  => [
            'arrival_time'   => date('Y-m-d', time()) . ' 00:00:01',
            'appointment_id' => $id,
        ],
    ]);

    assertTrue(in_array($res['status'], [200, 201], true), 'Check-in muss gelingen, HTTP ' . $res['status']);
    cstAssertNow($res['body']['arrival_time'] ?? null, 'Antwort');
    $records = cstRecords($id);
    assertSame(1, count($records), 'Genau eine Anwesenheit erwartet');
    cstAssertNow($records[0]['arrival_time'] ?? null, 'Gespeicherter Eintrag');
});

test('user: Check-in ohne arrival_time gelingt', function () {
    $id = cstAppointment('Serverzeit ohne Angabe', time());

    $res = apiRequest('POST', 'auto_checkin', [
        'token' => apiToken('user'),
        'body'  => ['appointment_id' => $id],
    ]);

    assertTrue(in_array($res['status'], [200, 201], true),
        'arrival_time ist fuer Mitglieder bedeutungslos und darf nicht Pflicht sein, HTTP ' . $res['status']);
    cstAssertNow($res['body']['arrival_time'] ?? null, 'Antwort');
});

test('user: tolerance_hours aus dem Request wirkt nicht', function () {
    if ((int) date('G') < 5) {
        assertTrue(true, 'vor 05:00 nicht pruefbar, ohne Aussage');
        return;
    }
    // 5 Stunden zurueck: ausserhalb der Vorgabe 2 h, innerhalb der 8 h, die
    // ein Request bisher selbst setzen durfte.
    $id = cstAppointment('Serverzeit Toleranz', time() - 5 * 3600);

    $res = apiRequest('POST', 'auto_checkin', [
        'token' => apiToken('user'),
        'body'  => ['appointment_id' => $id, 'tolerance_hours' => 8],
    ]);

    assertSame(409, $res['status'],
        'Ein Mitglied darf sein Zeitfenster nicht selbst aufziehen, HTTP ' . $res['status']);
    assertSame('appointment_outside_tolerance', $res['body']['reason'] ?? null);
});

// ---- TOTP: der Code belegt "jetzt", nicht einen frueheren Zeitpunkt ---------

test('totp: gueltiger Code mit rueckdatierter Ankunft traegt nicht fuer gestern ein', function () {
    $gestern = time() - 86400;
    $id = cstAppointment('Serverzeit TOTP gestern', $gestern);

    $res = apiRequest('POST', 'totp_checkin', [
        'token' => apiToken('user'),
        'body'  => [
            'totp_code'      => cstTotpCode(),
            'arrival_time'   => date('Y-m-d H:i:00', $gestern),
            'appointment_id' => $id,
        ],
    ]);

    assertSame(409, $res['status'],
        'Ein heute gueltiger Code darf keinen Ortsnachweis fuer gestern erzeugen, HTTP ' . $res['status']);
    assertSame(0, count(cstRecords($id)), 'Es darf keine Anwesenheit entstanden sein');
});

test('totp: gespeichert wird die Serverzeit', function () {
    $id = cstAppointment('Serverzeit TOTP jetzt', time());

    $res = apiRequest('POST', 'totp_checkin', [
        'token' => apiToken('user'),
        'body'  => [
            'totp_code'      => cstTotpCode(),
            'arrival_time'   => date('Y-m-d', time()) . ' 00:00:01',
            'appointment_id' => $id,
        ],
    ]);

    assertTrue(in_array($res['status'], [200, 201], true), 'TOTP-Check-in muss gelingen, HTTP ' . $res['status']);
    assertSame('user_totp', $res['body']['checkin_source'] ?? null);
    cstAssertNow($res['body']['arrival_time'] ?? null, 'Antwort');
});

test('totp: Admin kann ueber den TOTP-Weg ebenfalls nicht rueckdatieren', function () {
    $gestern = time() - 86400;
    $id = cstAppointment('Serverzeit TOTP Admin', $gestern);

    $res = apiRequest('POST', 'totp_checkin', [
        'token' => apiToken('admin'),
        'body'  => [
            'totp_code'      => cstTotpCode(),
            'arrival_time'   => date('Y-m-d H:i:00', $gestern),
            'appointment_id' => $id,
            'member_id'      => apiMemberId('user'),
        ],
    ]);

    assertSame(409, $res['status'],
        'Ein TOTP-Eintrag belegt Anwesenheit jetzt — auch fuer Verwalter, HTTP ' . $res['status']);
});

// ---- Geraet: eigene Uhr, aber begrenzt -------------------------------------

/** Check-in als Auth-Geraet fuer das Mitglied der Rolle user. */
function cstDeviceCheckin(array $body): array
{
    [, $token] = cstDevice('auth_device');

    return apiRequest('POST', 'auto_checkin', [
        'token' => $token,
        'body'  => $body + ['member_id' => apiMemberId('user')],
    ]);
}

test('device: nachgereichte Ankunft im Toleranzfenster wird mit ihrer Zeit gespeichert', function () {
    $vorhin = time() - 30 * 60;
    $id = cstAppointment('Serverzeit Geraet vorhin', $vorhin);
    $stamp = date('Y-m-d H:i:s', $vorhin);

    $res = cstDeviceCheckin(['arrival_time' => $stamp, 'appointment_id' => $id]);

    assertTrue(in_array($res['status'], [200, 201], true), 'Nachgereichter Eintrag muss gelingen, HTTP ' . $res['status']);
    assertSame($stamp, $res['body']['arrival_time'] ?? null,
        'Das Geraet stempelt mit seiner eigenen Uhr — die Warteschlange braucht das');
});

test('device: Ankunft aelter als das Toleranzfenster wird abgewiesen', function () {
    $res = cstDeviceCheckin(['arrival_time' => date('Y-m-d H:i:s', time() - 3 * 3600)]);

    assertSame(400, $res['status'], 'Ein Eintrag von vor 3 h liegt ausserhalb von 2 h, HTTP ' . $res['status']);
    assertSame('arrival_time_out_of_range', $res['body']['reason'] ?? null);
});

test('device: Ankunft in der Zukunft wird abgewiesen', function () {
    $res = cstDeviceCheckin(['arrival_time' => date('Y-m-d H:i:s', time() + 3600)]);

    assertSame(400, $res['status'], 'Eine Ankunft in einer Stunde gibt es nicht, HTTP ' . $res['status']);
    assertSame('arrival_time_out_of_range', $res['body']['reason'] ?? null);
});

test('device: ohne arrival_time stempelt der Server', function () {
    $id = cstAppointment('Serverzeit Geraet jetzt', time());

    $res = cstDeviceCheckin(['appointment_id' => $id]);

    assertTrue(in_array($res['status'], [200, 201], true), 'Check-in muss gelingen, HTTP ' . $res['status']);
    cstAssertNow($res['body']['arrival_time'] ?? null, 'Antwort');
});

/**
 * Muss der LETZTE Test der Datei sein. Anwesenheiten verschwinden per
 * ON DELETE CASCADE mit ihren Terminen.
 */
test('Aufraeumen: die Suite entfernt alles, was sie angelegt hat', function () {
    $token = apiToken('admin');

    foreach (['appointment' => 'appointments',
              'appointment_type' => 'appointment_types',
              'device' => 'users'] as $kind => $resource) {
        foreach (cstCreated($kind) as $id) {
            apiRequest('DELETE', $resource, ['token' => $token, 'query' => ['id' => $id]]);
        }
    }

    foreach (cstCreated('device') as $id) {
        $res = apiRequest('GET', 'users', ['token' => $token, 'query' => ['id' => $id]]);
        assertTrue($res['status'] === 404 || empty($res['body']['user_id']),
            "Testgeraet {$id} haette entfernt sein muessen, HTTP {$res['status']}");
    }
});
