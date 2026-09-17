<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * PUT darf nur schreiben, was mitgeschickt wurde (OI-54).
 *
 * `appointment_types` und `activity_types` schrieben ihre Grundfelder
 * bedingungslos: Ein PUT, das nur die Farbe aendern wollte, loeschte die
 * Beschreibung, setzte is_default zurueck und konnte eine ausgemusterte
 * Taetigkeitsart still wieder aktivieren. `members` macht es seit jeher
 * richtig -- zwei Ressourcen desselben Projekts mit gegensaetzlichem
 * PUT-Verhalten sind fuer jeden Verbraucher eine Falle.
 *
 * Seit 1.9.1 deckt die Suite dieselbe Klasse bei `appointments`,
 * `membership_dates`, `records` und `exceptions` ab (OI-69). Die schwerste
 * Folge nennt nicht der verlorene Titel, sondern die verlorene Terminart: Ein
 * Termin ohne type_id hat keine Gruppenzuordnung mehr, verschwindet aus den
 * Listen der Mitglieder, die ihn ueber Terminart -> Gruppe gesehen haetten,
 * und zaehlt in keiner Auswertung mehr mit. Der fehlende Titel faellt sofort
 * auf, die fehlende Terminart nicht.
 */

/** Sammelt Angelegtes fuer das Aufraeumen. */
function puaTrack(string $art, int $id): int
{
    static $ids = ['appointment_type' => [], 'activity_type' => [], 'group' => [],
                   'appointment' => [], 'member' => [], 'membership_date' => []];

    assertTrue(array_key_exists($art, $ids), "puaTrack(): unbekannte Art '{$art}'");

    if ($id > 0) {
        $ids[$art][] = $id;
    }

    return $id;
}

/** @return array<int, int> */
function puaTracked(string $art): array
{
    $ref = new ReflectionFunction('puaTrack');

    return $ref->getStaticVariables()['ids'][$art] ?? [];
}

function puaCreateGroup(): int
{
    $res = apiRequest('POST', 'member_groups', [
        'token' => apiToken('admin'),
        'body'  => ['group_name' => 'PUA-Gruppe ' . uniqid()],
    ]);
    assertStatus(201, $res);

    return puaTrack('group', (int) $res['body']['id']);
}

function puaCreateAppointmentType(array $overrides = []): int
{
    $body = array_merge([
        'type_name'   => 'PUA-Terminart ' . uniqid(),
        'description' => 'Beschreibung, die bleiben muss',
        'color'       => '#123456',
        'is_default'  => false,
    ], $overrides);

    $res = apiRequest('POST', 'appointment_types', ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res);

    return puaTrack('appointment_type', (int) $res['body']['id']);
}

function puaGetAppointmentType(int $id): array
{
    $res = apiRequest('GET', 'appointment_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
    ]);
    assertStatus(200, $res);

    return $res['body'];
}

function puaCreateActivityType(array $overrides = []): int
{
    $body = array_merge([
        'activity_name' => 'PUA-Taetigkeit ' . uniqid(),
        'description'   => 'Beschreibung, die bleiben muss',
        'group_ids'     => [puaCreateGroup()],
        'verification'  => 'start',
    ], $overrides);

    $res = apiRequest('POST', 'activity_types', ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res);

    return puaTrack('activity_type', (int) $res['body']['id']);
}

function puaGetActivityType(int $id): array
{
    $res = apiRequest('GET', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
    ]);
    assertStatus(200, $res);

    return $res['body'];
}

test('appointment_types: PUT nur mit color laesst die uebrigen Felder stehen', function () {
    $id  = puaCreateAppointmentType();
    $vor = puaGetAppointmentType($id);

    $res = apiRequest('PUT', 'appointment_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['color' => '#abcdef'],
    ]);
    assertStatus(200, $res);

    $nach = puaGetAppointmentType($id);
    assertSame('#abcdef', $nach['color'], 'Die Farbe muss ankommen');
    assertSame($vor['type_name'], $nach['type_name'], 'Der Name darf nicht verloren gehen');
    assertSame($vor['description'], $nach['description'], 'Die Beschreibung darf nicht verloren gehen');
    assertSame((int) $vor['is_default'], (int) $nach['is_default'], 'is_default darf nicht kippen');
});

test('appointment_types: PUT ohne group_ids laesst die Gruppen unangetastet', function () {
    $gruppe = puaCreateGroup();
    $id     = puaCreateAppointmentType(['group_ids' => [$gruppe]]);

    $vor = puaGetAppointmentType($id);
    assertSame(1, count($vor['groups']), 'Vorbedingung: genau eine Gruppe verknuepft');

    assertStatus(200, apiRequest('PUT', 'appointment_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['description' => 'nur die Beschreibung'],
    ]));

    $nach = puaGetAppointmentType($id);
    assertSame('nur die Beschreibung', $nach['description']);
    assertSame(1, count($nach['groups']), 'Die Gruppenverknuepfung wurde still geloescht');
});

test('activity_types: PUT nur mit description reaktiviert keine ausgemusterte Art', function () {
    $id = puaCreateActivityType();

    assertStatus(200, apiRequest('PUT', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['activity_name' => puaGetActivityType($id)['activity_name'], 'is_active' => false],
    ]));
    assertSame(0, (int) puaGetActivityType($id)['is_active'], 'Vorbedingung: ausgemustert');

    assertStatus(200, apiRequest('PUT', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['description' => 'nur die Beschreibung'],
    ]));

    $nach = puaGetActivityType($id);
    assertSame('nur die Beschreibung', $nach['description']);
    assertSame(0, (int) $nach['is_active'], 'Eine ausgemusterte Art wurde still wieder aktiviert');
    assertSame('start', $nach['verification'], 'Der Nachweisgrad darf nicht zurueckfallen');
});

test('activity_types: PUT nur mit color laesst Name und Beschreibung stehen', function () {
    $id  = puaCreateActivityType();
    $vor = puaGetActivityType($id);

    assertStatus(200, apiRequest('PUT', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['color' => '#fedcba'],
    ]));

    $nach = puaGetActivityType($id);
    assertSame('#fedcba', $nach['color']);
    assertSame($vor['activity_name'], $nach['activity_name'], 'Der Name darf nicht verloren gehen');
    assertSame($vor['description'], $nach['description'], 'Die Beschreibung darf nicht verloren gehen');
});

// ============================================
// appointments, membership_dates (OI-69)
// ============================================

function puaCreateAppointment(array $overrides = []): int
{
    $body = array_merge([
        'title'       => 'PUA-Termin ' . uniqid(),
        'description' => 'Beschreibung, die bleiben muss',
        'date'        => date('Y-m-d', strtotime('+40 days')),
        'start_time'  => '19:45:00',
        'type_id'     => puaCreateAppointmentType(),
    ], $overrides);

    $res = apiRequest('POST', 'appointments', ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res);

    return puaTrack('appointment', (int) $res['body']['id']);
}

function puaGetAppointment(int $id): array
{
    $res = apiRequest('GET', 'appointments', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
    ]);
    assertStatus(200, $res);

    return $res['body'];
}

test('appointments: PUT nur mit date laesst Titel und Terminart stehen', function () {
    $id  = puaCreateAppointment();
    $vor = puaGetAppointment($id);

    // Genau der Fall aus OI-69: einen Termin verschieben, sonst nichts.
    $neuesDatum = date('Y-m-d', strtotime('+41 days'));
    assertStatus(200, apiRequest('PUT', 'appointments', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['date' => $neuesDatum],
    ]));

    $nach = puaGetAppointment($id);
    assertSame($neuesDatum, $nach['date'], 'Das Datum muss ankommen');
    assertSame($vor['title'], $nach['title'], 'Der Titel darf nicht verloren gehen');
    assertSame(
        (int) $vor['type_id'],
        (int) $nach['type_id'],
        'Die Terminart darf nicht verloren gehen -- ohne sie faellt der Termin aus jeder Auswertung'
    );
    assertSame($vor['start_time'], $nach['start_time'], 'Die Uhrzeit darf nicht verloren gehen');
    assertSame($vor['description'], $nach['description'], 'Die Beschreibung darf nicht verloren gehen');
});

test('appointments: PUT nur mit title laesst Datum und Uhrzeit stehen', function () {
    $id  = puaCreateAppointment();
    $vor = puaGetAppointment($id);

    assertStatus(200, apiRequest('PUT', 'appointments', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['title' => 'Umbenannt ' . uniqid()],
    ]));

    $nach = puaGetAppointment($id);
    assertSame($vor['date'], $nach['date'], 'Das Datum darf nicht verloren gehen');
    assertSame($vor['start_time'], $nach['start_time'], 'Die Uhrzeit darf nicht verloren gehen');
    assertSame((int) $vor['type_id'], (int) $nach['type_id'], 'Die Terminart darf nicht verloren gehen');
});

test('appointments: die Dublettenpruefung greift auch bei einem Teil-Update', function () {
    // Ohne date/start_time verglich der alte Code gegen " " und type_id NULL --
    // die Pruefung fiel still aus. Zwei Termine derselben Art im
    // Toleranzfenster muessen sich aber auch dann noch in die Quere kommen.
    $art   = puaCreateAppointmentType();
    $tag   = date('Y-m-d', strtotime('+55 days'));
    $erst  = puaCreateAppointment(['type_id' => $art, 'date' => $tag, 'start_time' => '10:00:00']);
    $zweit = puaCreateAppointment(['type_id' => $art, 'date' => $tag, 'start_time' => '20:00:00']);

    assertTrue($erst > 0 && $zweit > 0, 'Vorbedingung: zwei Termine derselben Art am selben Tag');

    // Der zweite wandert in das Fenster des ersten -- nur die Uhrzeit im Body.
    $res = apiRequest('PUT', 'appointments', [
        'token' => apiToken('admin'),
        'query' => ['id' => $zweit],
        'body'  => ['start_time' => '10:30:00'],
    ]);

    assertStatus(409, $res);
});

test('appointments: PUT auf eine unbekannte id meldet 404', function () {
    $res = apiRequest('PUT', 'appointments', [
        'token' => apiToken('admin'),
        'query' => ['id' => 999888777],
        'body'  => ['title' => 'gibt es nicht'],
    ]);

    assertStatus(404, $res);
});

test('membership_dates: PUT nur mit end_date laesst Beginn und Status stehen', function () {
    // Der Handler schrieb start_date bedingungslos und setzte status ohne
    // Angabe auf 'active' zurueck -- ein beendeter Zeitraum wurde damit beim
    // Nachtragen des Enddatums wieder als laufend gefuehrt.
    $mitglieder = apiRequest('GET', 'members', ['token' => apiToken('admin')]);
    assertStatus(200, $mitglieder);
    assertTrue(count($mitglieder['body']) > 0, 'Vorbedingung: mindestens ein Mitglied');
    $memberId = (int) $mitglieder['body'][0]['member_id'];

    $angelegt = apiRequest('POST', 'membership_dates', [
        'token' => apiToken('admin'),
        'body'  => [
            'member_id'  => $memberId,
            'start_date' => '2019-03-01',
            'status'     => 'inactive',
        ],
    ]);
    assertStatus(201, $angelegt);
    $id = puaTrack('membership_date', (int) $angelegt['body']['id']);

    assertStatus(200, apiRequest('PUT', 'membership_dates', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['end_date' => '2020-06-30'],
    ]));

    $alle = apiRequest('GET', 'membership_dates', [
        'token' => apiToken('admin'),
        'query' => ['member_id' => $memberId],
    ]);
    assertStatus(200, $alle);

    $zeile = null;
    foreach ($alle['body'] as $row) {
        if ((int) $row['membership_date_id'] === $id) {
            $zeile = $row;
        }
    }

    assertTrue($zeile !== null, 'Der angelegte Zeitraum ist nicht mehr auffindbar');
    assertSame('2020-06-30', $zeile['end_date'], 'Das Enddatum muss ankommen');
    assertSame('2019-03-01', $zeile['start_date'], 'Der Beginn darf nicht verloren gehen');
    assertSame('inactive', $zeile['status'], 'Der Status darf nicht auf active zurueckfallen');
});

// ============================================
// records, exceptions (OI-69)
// ============================================

/** Irgendein aktives Mitglied -- fuer Datensaetze, die eines brauchen. */
function puaAnyMemberId(): int
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin')]);
    assertStatus(200, $res);
    assertTrue(count($res['body']) > 0, 'Vorbedingung: mindestens ein Mitglied');

    return (int) $res['body'][0]['member_id'];
}

function puaGetRecord(int $id): array
{
    $res = apiRequest('GET', 'records', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    assertStatus(200, $res);

    return $res['body'];
}

test('records: PUT nur mit arrival_time laesst Status, Mitglied und Termin stehen', function () {
    $termin   = puaCreateAppointment();
    $memberId = puaAnyMemberId();

    $angelegt = apiRequest('POST', 'records', [
        'token' => apiToken('admin'),
        'body'  => [
            'member_id'      => $memberId,
            'appointment_id' => $termin,
            'arrival_time'   => null,
            'status'         => 'excused',
        ],
    ]);
    assertStatus(201, $angelegt);
    $recordId = (int) $angelegt['body']['id'];

    // Nur die Ankunftszeit nachtragen. Vorher las der Handler member_id,
    // appointment_id und status bedingungslos: Der Status fiel auf leer, die
    // Zuordnung auf NULL -- und weil member_id != NULL als "geaendert" galt,
    // lief der Datensatz auch noch durch die Dublettenpruefung.
    assertStatus(200, apiRequest('PUT', 'records', [
        'token' => apiToken('admin'),
        'query' => ['id' => $recordId],
        'body'  => ['arrival_time' => date('Y-m-d', strtotime('+40 days')) . ' 19:50:00'],
    ]));

    $nach = puaGetRecord($recordId);
    assertSame('excused', $nach['status'], 'Der Status darf nicht verloren gehen');
    assertSame($memberId, (int) $nach['member_id'], 'Das Mitglied darf nicht verloren gehen');
    assertSame($termin, (int) $nach['appointment_id'], 'Der Termin darf nicht verloren gehen');
});

test('records: ein Leerstring loescht die Ankunftszeit weiterhin', function () {
    // Gegenprobe zum Test darueber: Ein fehlendes Feld heisst "nicht
    // anfassen", ein Leerstring heisst "Angabe loeschen". Die beiden duerfen
    // beim Umbau nicht zusammenfallen (arrival_api deckt denselben Fall ueber
    // den vollstaendigen Koerper ab).
    $termin   = puaCreateAppointment();
    $memberId = puaAnyMemberId();

    $angelegt = apiRequest('POST', 'records', [
        'token' => apiToken('admin'),
        'body'  => [
            'member_id'      => $memberId,
            'appointment_id' => $termin,
            'arrival_time'   => date('Y-m-d', strtotime('+40 days')) . ' 19:45:00',
            'status'         => 'present',
        ],
    ]);
    assertStatus(201, $angelegt);
    $recordId = (int) $angelegt['body']['id'];

    assertStatus(200, apiRequest('PUT', 'records', [
        'token' => apiToken('admin'),
        'query' => ['id' => $recordId],
        'body'  => ['arrival_time' => ''],
    ]));

    $nach = puaGetRecord($recordId);
    assertSame(null, $nach['arrival_time'], 'Ein Leerstring muss die Zeit loeschen');
    assertSame('present', $nach['status'], 'Der Status darf dabei nicht verloren gehen');
});

test('exceptions: PUT nur mit status laesst die Begruendung stehen', function () {
    $termin   = puaCreateAppointment();
    $memberId = puaAnyMemberId();
    $grund    = 'Begruendung, die bleiben muss ' . uniqid();

    $angelegt = apiRequest('POST', 'exceptions', [
        'token' => apiToken('admin'),
        'body'  => [
            'member_id'      => $memberId,
            'appointment_id' => $termin,
            'exception_type' => 'absence',
            'reason'         => $grund,
        ],
    ]);
    assertStatus(201, $angelegt);
    $exceptionId = (int) $angelegt['body']['id'];

    // Ablehnen statt genehmigen: Eine Genehmigung zieht einen Anwesenheits-
    // eintrag nach sich, und darum geht es hier nicht.
    assertStatus(200, apiRequest('PUT', 'exceptions', [
        'token' => apiToken('admin'),
        'query' => ['id' => $exceptionId],
        'body'  => ['status' => 'rejected'],
    ]));

    $alle = apiRequest('GET', 'exceptions', ['token' => apiToken('admin')]);
    assertStatus(200, $alle);

    $zeile = null;
    foreach ($alle['body'] as $row) {
        if ((int) $row['exception_id'] === $exceptionId) {
            $zeile = $row;
        }
    }

    assertTrue($zeile !== null, 'Der angelegte Antrag ist nicht mehr auffindbar');
    assertSame('rejected', $zeile['status'], 'Der Status muss ankommen');
    assertSame($grund, $zeile['reason'], 'Die Begruendung darf nicht verloren gehen');
});

test('Aufraeumen: die Suite entfernt alles, was sie angelegt hat', function () {
    $rest = [];

    foreach (puaTracked('membership_date') as $id) {
        $res = apiRequest('DELETE', 'membership_dates', [
            'token' => apiToken('admin'), 'query' => ['id' => $id],
        ]);
        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "membership_date {$id} (HTTP {$res['status']})";
        }
    }

    // Termine vor ihren Terminarten: An der Art haengt eine Fremdschluessel-
    // Beziehung, die Reihenfolge ist also nicht beliebig.
    foreach (puaTracked('appointment') as $id) {
        $res = apiRequest('DELETE', 'appointments', [
            'token' => apiToken('admin'), 'query' => ['id' => $id],
        ]);
        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "appointment {$id} (HTTP {$res['status']})";
        }
    }

    foreach (puaTracked('activity_type') as $id) {
        $res = apiRequest('DELETE', 'activity_types', [
            'token' => apiToken('admin'), 'query' => ['id' => $id],
        ]);
        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "activity_type {$id} (HTTP {$res['status']})";
        }
    }

    foreach (puaTracked('appointment_type') as $id) {
        $res = apiRequest('DELETE', 'appointment_types', [
            'token' => apiToken('admin'), 'query' => ['id' => $id],
        ]);
        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "appointment_type {$id} (HTTP {$res['status']})";
        }
    }

    foreach (puaTracked('group') as $id) {
        $res = apiRequest('DELETE', 'member_groups', [
            'token' => apiToken('admin'), 'query' => ['id' => $id],
        ]);
        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "group {$id} (HTTP {$res['status']})";
        }
    }

    assertSame([], $rest, 'Nicht alles konnte entfernt werden');
});
