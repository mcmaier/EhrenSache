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

test('Eine genehmigte Entschuldigung erzeugt keine Ankunftszeit', function () {
    $token    = apiToken('admin');
    $memberId = arrAnyMemberId($token);
    $aptId    = arrTempAppointment($token, '2031-03-06');

    try {
        $create = apiRequest('POST', 'exceptions', [
            'token' => $token,
            'body'  => ['member_id'      => $memberId,
                        'appointment_id' => $aptId,
                        'exception_type' => 'absence',
                        'reason'         => 'Ankunftszeit-Test',
                        'status'         => 'pending'],
        ]);
        assertStatus(201, $create);
        $exceptionId = (int) $create['body']['id'];

        // Erst die Genehmigung legt den Record an (handleApprovedAbsence).
        $approve = apiRequest('PUT', 'exceptions', [
            'token' => $token,
            'query' => ['id' => $exceptionId],
            'body'  => ['exception_type' => 'absence',
                        'reason'         => 'Ankunftszeit-Test',
                        'status'         => 'approved'],
        ]);
        assertStatus(200, $approve);

        $records = arrRecordsOf($token, $aptId);
        assertSame(1, count($records), 'Die Genehmigung sollte einen Record anlegen');
        assertSame('excused', $records[0]['status']);
        assertSame(null, $records[0]['arrival_time'],
            'Entschuldigt heisst nicht "um 20:00 erschienen"');
    } finally {
        arrDropAppointment($token, $aptId);
    }
});

test('Eine geleerte Ankunftszeit wird als leer gespeichert', function () {
    $token    = apiToken('admin');
    $memberId = arrAnyMemberId($token);
    $aptId    = arrTempAppointment($token, '2031-03-05');

    try {
        $create = apiRequest('POST', 'records', [
            'token' => $token,
            'body'  => ['member_id'      => $memberId,
                        'appointment_id' => $aptId,
                        'arrival_time'   => '2031-03-05 19:58:00'],
        ]);
        assertStatus(201, $create);
        assertSame('2031-03-05 19:58:00', $create['body']['arrival_time']);
        $recordId = (int) $create['body']['id'];

        // Vollstaendiger Koerper, wie ihn das Dashboard schickt. Ein Teilaufruf
        // liefe in records.php gegen undefinierte Felder -- siehe OI-54.
        $res = apiRequest('PUT', 'records', [
            'token' => $token,
            'query' => ['id' => $recordId],
            'body'  => ['member_id'      => $memberId,
                        'appointment_id' => $aptId,
                        'arrival_time'   => '',
                        'status'         => 'present'],
        ]);
        assertStatus(200, $res);

        $records = arrRecordsOf($token, $aptId);
        assertSame(null, $records[0]['arrival_time'],
            'Ein Leerstring muss als fehlende Zeit ankommen, nicht als Nulldatum');
    } finally {
        arrDropAppointment($token, $aptId);
    }
});

test('Die Jahresliste kennt kein Jahr ohne Termin', function () {
    $token = apiToken('admin');

    $before = apiRequest('GET', 'available_years', ['token' => $token]);
    assertStatus(200, $before);
    assertTrue(!empty($before['body']), 'Keine Jahre im Bestand');

    // Ein Jahr jenseits des Bestands, damit der Test niemandem ins Gehege kommt.
    $silvester = max(array_map('intval', $before['body'])) + 5;
    $folgejahr = $silvester + 1;

    $memberId = arrAnyMemberId($token);
    $aptId    = arrTempAppointment($token, "{$silvester}-12-31", '23:00:00');

    try {
        // Ankunft nach Mitternacht: Der Record faellt ins Folgejahr, der
        // Termin bleibt im alten.
        $rec = apiRequest('POST', 'records', [
            'token' => $token,
            'body'  => ['member_id'      => $memberId,
                        'appointment_id' => $aptId,
                        'arrival_time'   => "{$folgejahr}-01-01 00:15:00"],
        ]);
        assertStatus(201, $rec);

        $after = apiRequest('GET', 'available_years', ['token' => $token]);
        $years = array_map('intval', $after['body']);

        assertTrue(in_array($silvester, $years, true),
            "Jahr {$silvester} hat einen Termin und muss waehlbar sein");
        assertTrue(!in_array($folgejahr, $years, true),
            "Jahr {$folgejahr} hat keinen Termin und darf nicht waehlbar sein");
    } finally {
        arrDropAppointment($token, $aptId);
    }
});

test('Ein Record ohne Ankunftszeit wird nicht auf 1970 datiert', function () {
    $admin    = apiToken('admin');
    $memberId = (int) apiMemberId('user');
    $aptId    = arrTempAppointment($admin, '2031-03-07');

    try {
        $rec = apiRequest('POST', 'records', [
            'token' => $admin,
            'body'  => ['member_id' => $memberId, 'appointment_id' => $aptId],
        ]);
        assertStatus(201, $rec);

        $csv = apiRequest('GET', 'my_data', [
            'token' => apiToken('user'),
            'query' => ['format' => 'csv'],
        ]);
        assertStatus(200, $csv);

        assertTrue(
            !str_contains($csv['raw'], '01.01.1970'),
            'strtotime(null) hat den 01.01.1970 in den Auskunftsexport geschrieben'
        );
        assertTrue(
            str_contains($csv['raw'], '07.03.2031'),
            'Das Termindatum sollte im Auskunftsexport stehen'
        );
    } finally {
        arrDropAppointment($admin, $aptId);
    }
});

test('Eine beantragte Ankunft weit vom Termin wird abgewiesen', function () {
    $token    = apiToken('admin');
    $memberId = arrAnyMemberId($token);
    $aptId    = arrTempAppointment($token, '2031-03-08');   // 20:00 Uhr

    try {
        // Fuenf Stunden vor dem Termin -- ausserhalb jedes Toleranzfensters.
        $res = apiRequest('POST', 'exceptions', [
            'token' => $token,
            'body'  => ['member_id'              => $memberId,
                        'appointment_id'         => $aptId,
                        'exception_type'         => 'time_correction',
                        'reason'                 => 'Ankunftszeit-Test',
                        'requested_arrival_time' => '2031-03-08 15:00:00',
                        'status'                 => 'pending'],
        ]);

        assertStatus(400, $res,
            'Ohne Grenze liesse sich eine Puenktlichkeit behaupten, die niemand pruefen kann');

        // Innerhalb des Fensters geht es weiterhin.
        $ok = apiRequest('POST', 'exceptions', [
            'token' => $token,
            'body'  => ['member_id'              => $memberId,
                        'appointment_id'         => $aptId,
                        'exception_type'         => 'time_correction',
                        'reason'                 => 'Ankunftszeit-Test',
                        'requested_arrival_time' => '2031-03-08 19:55:00',
                        'status'                 => 'pending'],
        ]);
        assertStatus(201, $ok);
    } finally {
        arrDropAppointment($token, $aptId);
    }
});

test('Eine Ankunftszeit weit vom Termin wird auch im Record abgewiesen', function () {
    $token    = apiToken('admin');
    $memberId = arrAnyMemberId($token);
    $aptId    = arrTempAppointment($token, '2031-03-09');   // 20:00 Uhr

    try {
        // Dasselbe Toleranzband wie beim Antrag -- sonst waere der direkte Weg
        // ueber den Record-Dialog die Luecke im Zaun.
        $res = apiRequest('POST', 'records', [
            'token' => $token,
            'body'  => ['member_id'      => $memberId,
                        'appointment_id' => $aptId,
                        'arrival_time'   => '2031-03-09 15:00:00'],
        ]);
        assertStatus(400, $res, 'Eine Ankunft fuenf Stunden vor dem Termin muss auffallen');

        // Ohne Zeit bleibt es erlaubt -- das ist der Hauptfall des Umbaus.
        $ohne = apiRequest('POST', 'records', [
            'token' => $token,
            'body'  => ['member_id' => $memberId, 'appointment_id' => $aptId],
        ]);
        assertStatus(201, $ohne);
        $recordId = (int) $ohne['body']['id'];

        // Nachtraeglich eine unsinnige Zeit setzen: ebenfalls abgewiesen.
        $put = apiRequest('PUT', 'records', [
            'token' => $token,
            'query' => ['id' => $recordId],
            'body'  => ['member_id'      => $memberId,
                        'appointment_id' => $aptId,
                        'arrival_time'   => '2031-03-09 03:00:00',
                        'status'         => 'present'],
        ]);
        assertStatus(400, $put);
    } finally {
        arrDropAppointment($token, $aptId);
    }
});
