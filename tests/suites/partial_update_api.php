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
 */

/** Sammelt Angelegtes fuer das Aufraeumen. */
function puaTrack(string $art, int $id): int
{
    static $ids = ['appointment_type' => [], 'activity_type' => [], 'group' => []];

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

test('Aufraeumen: die Suite entfernt alles, was sie angelegt hat', function () {
    $rest = [];

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
