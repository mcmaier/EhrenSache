<?php
/**
 * Ankunftszeit darf fehlen (ab 1.5.0).
 *
 * Frueher setzte records.php die Startzeit des Termins ein, wenn beim Anlegen
 * keine Uhrzeit mitkam -- der Datensatz war damit konstruiert puenktlich.
 * Diese Suite haelt fest, dass eine fehlende Ankunft jetzt fehlend bleibt.
 *
 * Jeder Test legt seinen eigenen Termin an und raeumt ihn im finally wieder
 * weg: Ein Paar aus Mitglied und Termin aus dem Bestand traegt oft schon einen
 * Record, und der Endpunkt antwortet dann mit 409.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** Legt einen Termin an und liefert seine ID. */
function arrTempAppointment(string $token, string $date = '2031-03-04',
                            string $startTime = '20:00:00'): int
{
    $res = apiRequest('POST', 'appointments', [
        'token' => $token,
        'body'  => ['title' => 'Ankunftszeit-Test', 'date' => $date, 'start_time' => $startTime],
    ]);
    assertStatus(201, $res, 'Testtermin konnte nicht angelegt werden');

    return (int) $res['body']['id'];
}

/**
 * Raeumt den Testtermin samt seiner Records weg.
 *
 * Die ID gehoert in 'query': apiRequest() kennt keinen Schluessel 'id', und
 * ein DELETE ohne sie laeuft in appointments.php gegen appointment_id = NULL
 * -- es loescht nichts und meldet trotzdem Erfolg.
 */
function arrDropAppointment(string $token, int $appointmentId): void
{
    apiRequest('DELETE', 'appointments', [
        'token' => $token,
        'query' => ['id' => $appointmentId],
    ]);
}

/** Irgendein vorhandenes Mitglied — welches, ist fuer diese Suite gleichgueltig. */
function arrAnyMemberId(string $token): int
{
    $res = apiRequest('GET', 'members', ['token' => $token]);
    assertStatus(200, $res);
    assertTrue(!empty($res['body']), 'Kein Mitglied im Bestand');

    return (int) $res['body'][0]['member_id'];
}

/** Die Records eines Termins (GET records liefert eine Liste, kein Objekt). */
function arrRecordsOf(string $token, int $appointmentId): array
{
    $res = apiRequest('GET', 'records', [
        'token' => $token,
        'query' => ['appointment_id' => $appointmentId],
    ]);
    assertStatus(200, $res);

    return $res['body'];
}

test('Ein Record ohne Ankunftszeit bekommt keine erfundene Uhrzeit', function () {
    $token    = apiToken('admin');
    $memberId = arrAnyMemberId($token);
    $aptId    = arrTempAppointment($token);

    try {
        $res = apiRequest('POST', 'records', [
            'token' => $token,
            'body'  => ['member_id' => $memberId, 'appointment_id' => $aptId],
        ]);

        assertStatus(201, $res);
        assertSame(null, $res['body']['arrival_time'],
            'Ohne uebergebene Zeit darf keine Uhrzeit entstehen');

        $records = arrRecordsOf($token, $aptId);
        assertSame(1, count($records));
        assertSame(null, $records[0]['arrival_time'],
            'Auch gelesen muss die Ankunft leer bleiben');
    } finally {
        arrDropAppointment($token, $aptId);
    }
});
