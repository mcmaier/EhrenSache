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
 * Gruppengrenze beim Abruf der Terminliste.
 *
 * Ein Mitglied sieht nur Termine, deren Terminart einer seiner Gruppen
 * zugeordnet ist (Testplan APT-GET-2). Der Parameter member_id grenzt die
 * Liste fuer Verwalter auf die Gruppen eines bestimmten Mitglieds ein --
 * fuer die Rolle user darf er die Grenze nicht verschieben, sonst liest ein
 * Mitglied mit einer fremden ID die Termine fremder Gruppen.
 *
 * Die Welt hier ist eine eigene Gruppe mit Terminart, Mitglied und Termin.
 * Das Mitglied des Kontos "user" gehoert ihr nicht an.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function avCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");
    $id = (int) $res['body']['id'];
    assertTrue($id > 0, "{$resource} lieferte keine brauchbare ID: " . $res['raw']);

    return $id;
}

function avDelete(string $resource, ?int $id): void
{
    if ($id !== null) {
        apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    }
}

/** @return int[] appointment_ids der Liste */
function avListIds(string $role, array $query): array
{
    $res = apiRequest('GET', 'appointments', ['token' => apiToken($role), 'query' => $query]);
    assertStatus(200, $res, "Terminliste fuer {$role} nicht abrufbar");
    assertTrue(is_array($res['body']), 'Terminliste ist kein Array: ' . $res['raw']);

    return array_map(static fn ($a) => (int) $a['appointment_id'], $res['body']);
}

test('Fremde member_id verschiebt die Gruppengrenze fuer user nicht', function () {
    assertTrue(apiMemberId('user') !== null, 'Das Konto "user" braucht ein verknuepftes Mitglied');

    $suffix = uniqid();
    $group = $type = $member = $appointment = null;

    try {
        $group  = avCreate('member_groups', ['group_name' => "AV {$suffix}"]);
        $type   = avCreate('appointment_types', [
            'type_name'  => "AV {$suffix}",
            'is_default' => 0,
            'color'      => '#667eea',
            'group_ids'  => [$group],
        ]);
        $member = avCreate('members', [
            'name'      => 'Av',
            'surname'   => "Fremd {$suffix}",
            'active'    => 1,
            'group_ids' => [$group],
        ]);
        $appointment = avCreate('appointments', [
            'title'      => 'AV-Termin fremde Gruppe',
            'date'       => '2026-11-15',
            'start_time' => '19:00',
            'type_id'    => $type,
        ]);

        $filter = ['from_date' => '2026-11-15', 'to_date' => '2026-11-15'];

        // Gegenprobe: Der Verwalter sieht den Termin ueber die member_id.
        assertTrue(in_array($appointment, avListIds('admin', $filter + ['member_id' => $member]), true),
                   'Admin muss den Termin ueber member_id des Gruppenmitglieds sehen');

        // Ohne Parameter sieht das Mitglied ihn nicht -- das galt schon immer.
        assertTrue(!in_array($appointment, avListIds('user', $filter), true),
                   'user sieht einen Termin einer fremden Gruppe');

        // Der eigentliche Fall: fremde member_id als user.
        assertTrue(!in_array($appointment, avListIds('user', $filter + ['member_id' => $member]), true),
                   'user sieht ueber eine fremde member_id die Termine fremder Gruppen');
    } finally {
        avDelete('appointments', $appointment);
        avDelete('members', $member);
        avDelete('appointment_types', $type);
        avDelete('member_groups', $group);
    }
});

test('Eigene member_id liefert fuer user weiter die eigenen Termine', function () {
    // Die Check-in-PWA ruft die Liste mit der eigenen member_id ab
    // (public/checkin/js/app.js, loadAppointments). Das muss gleich bleiben.
    $own = apiMemberId('user');
    assertTrue($own !== null, 'Das Konto "user" braucht ein verknuepftes Mitglied');

    $filter = ['from_date' => '2026-01-01', 'to_date' => '2026-12-31'];
    $ohne = avListIds('user', $filter);
    $mit  = avListIds('user', $filter + ['member_id' => $own]);

    sort($ohne);
    sort($mit);
    assertSame($ohne, $mit, 'Mit eigener member_id muss dieselbe Liste kommen wie ohne');
});

/*
 * Gruppengrenze beim Verknuepfen eines Termins (1.11.2).
 *
 * Antraege und Arbeitszeiten nahmen von der Rolle user jede vorhandene
 * appointment_id an. Der Abruf des eigenen Eintrags lieferte danach Titel,
 * Datum und Terminart mit -- auch fuer den Termin einer fremden Gruppe. Die
 * Grenze ist dieselbe wie beim Check-in (memberMayAttendAppointment()), und ein
 * fremder Termin wird genauso beantwortet wie ein nicht vorhandener.
 */

/** Gruppen des Mitglieds hinter dem Konto $role. */
function avGroupsOf(string $role): array
{
    $member = apiMemberId($role);
    assertTrue($member !== null, "Das Konto \"{$role}\" braucht ein verknuepftes Mitglied");

    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $member]]);
    assertStatus(200, $res);
    $ids = array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups'] ?? []);
    assertTrue($ids !== [], "Das Mitglied des Kontos \"{$role}\" braucht eine Gruppe");

    return $ids;
}

/**
 * Baut eine fremde und eine eigene Welt, ruft $fn und raeumt immer auf.
 *
 * $fn bekommt ['foreign' => Termin fremder Gruppe, 'own' => Termin der eigenen
 * Gruppen, 'activity' => Taetigkeit fuer die eigenen Gruppen].
 */
function avWithWorlds(callable $fn): void
{
    $suffix = uniqid();
    $ids = ['group' => null, 'foreignType' => null, 'ownType' => null,
            'foreign' => null, 'own' => null, 'activity' => null];
    $sessions = [];

    try {
        $ids['group']       = avCreate('member_groups', ['group_name' => "AV2 {$suffix}"]);
        $ids['foreignType'] = avCreate('appointment_types', [
            'type_name' => "AV2 fremd {$suffix}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => [$ids['group']],
        ]);
        $ids['ownType'] = avCreate('appointment_types', [
            'type_name' => "AV2 eigen {$suffix}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => avGroupsOf('user'),
        ]);
        // Vergangen: Ein Zeitantrag braucht eine Ankunft, die schon war (OI-82)
        $ids['foreign'] = avCreate('appointments', [
            'title' => 'AV2-Termin fremde Gruppe', 'date' => '2021-05-05',
            'start_time' => '19:00', 'type_id' => $ids['foreignType'],
        ]);
        $ids['own'] = avCreate('appointments', [
            'title' => 'AV2-Termin eigene Gruppe', 'date' => '2021-05-06',
            'start_time' => '19:00', 'type_id' => $ids['ownType'],
        ]);
        $ids['activity'] = avCreate('activity_types', [
            'activity_name' => "AV2 {$suffix}", 'group_ids' => avGroupsOf('user'),
        ]);

        $fn($ids, $sessions);
    } finally {
        foreach ($sessions as $sessionId) {
            avDelete('work_sessions', $sessionId);
        }
        // Termine zuerst: ihre Antraege gehen per ON DELETE CASCADE mit
        avDelete('appointments', $ids['foreign']);
        avDelete('appointments', $ids['own']);
        avDelete('activity_types', $ids['activity']);
        avDelete('appointment_types', $ids['foreignType']);
        avDelete('appointment_types', $ids['ownType']);
        avDelete('member_groups', $ids['group']);
    }
}

/** Legt einen Antrag als user an; merkt sich nichts, die Termine raeumen auf. */
function avUserException(int $appointmentId, string $type): array
{
    $body = ['member_id' => apiMemberId('user'), 'appointment_id' => $appointmentId,
             'exception_type' => $type, 'reason' => 'AV2-Test'];
    if ($type === 'time_correction') {
        $body['requested_arrival_time'] = '2021-05-05 19:00:00';
    }

    return apiRequest('POST', 'exceptions', ['token' => apiToken('user'), 'body' => $body]);
}

test('Antrag: user kann keinen Termin einer fremden Gruppe verknuepfen', function () {
    avWithWorlds(function (array $ids) {
        foreach (['absence', 'time_correction'] as $type) {
            $res = avUserException($ids['foreign'], $type);
            assertTrue($res['status'] !== 201,
                "{$type} zu einem Termin einer fremden Gruppe wurde angenommen");
            assertTrue(strpos($res['raw'], 'AV2-Termin fremde Gruppe') === false,
                'Die Antwort verraet den Titel des fremden Termins');
        }

        // Fremd und nicht vorhanden muessen gleich aussehen -- sonst laesst
        // sich ausprobieren, welche Termin-IDs es gibt.
        $fremd = avUserException($ids['foreign'], 'absence');
        $weg   = avUserException(999999999, 'absence');
        assertSame($weg['status'], $fremd['status'], 'Fremder und fehlender Termin unterscheiden sich im Status');
        assertSame($weg['body']['message'] ?? null, $fremd['body']['message'] ?? null,
            'Fremder und fehlender Termin unterscheiden sich in der Meldung');

        // Gegenprobe: der eigene Termin geht weiter
        assertStatus(201, avUserException($ids['own'], 'absence'), 'Antrag zum eigenen Termin');

        // Und der Verwalter bleibt ohne Gruppengrenze
        $admin = apiRequest('POST', 'exceptions', ['token' => apiToken('admin'), 'body' => [
            'member_id' => apiMemberId('user'), 'appointment_id' => $ids['foreign'],
            'exception_type' => 'absence', 'reason' => 'AV2-Verwalter',
        ]]);
        assertStatus(201, $admin, 'Admin darf jeden Termin verknuepfen');
    });
});

test('Arbeitszeit: user kann keinen Termin einer fremden Gruppe verknuepfen', function () {
    avWithWorlds(function (array $ids, array &$sessions) {
        $nachtrag = static function (int $appointmentId) use ($ids): array {
            return apiRequest('POST', 'work_sessions', ['token' => apiToken('user'), 'body' => [
                'activity_id' => $ids['activity'], 'appointment_id' => $appointmentId,
                'start_time' => '2021-05-05 17:00:00', 'end_time' => '2021-05-05 18:00:00',
                'note' => 'AV2-Test',
            ]]);
        };

        // Nachtrag
        $fremd = $nachtrag($ids['foreign']);
        if ($fremd['status'] === 201) {
            $sessions[] = (int) ($fremd['body']['session']['session_id'] ?? 0);
        }
        assertStatus(400, $fremd, 'Nachtrag mit dem Termin einer fremden Gruppe');
        assertTrue(strpos($fremd['raw'], 'AV2-Termin fremde Gruppe') === false,
            'Die Antwort verraet den Titel des fremden Termins');

        $eigen = $nachtrag($ids['own']);
        assertStatus(201, $eigen, 'Nachtrag mit dem eigenen Termin');
        $sessions[] = (int) ($eigen['body']['session']['session_id'] ?? 0);

        // Aenderung: den eigenen Nachtrag auf den fremden Termin umhaengen
        $put = apiRequest('PUT', 'work_sessions', ['token' => apiToken('user'),
            'query' => ['id' => (int) ($eigen['body']['session']['session_id'] ?? 0)],
            'body'  => ['appointment_id' => $ids['foreign']]]);
        assertStatus(400, $put, 'Aenderung auf den Termin einer fremden Gruppe');

        // Start: das Konto user hat hier keine laufende Sitzung
        $start = apiRequest('POST', 'work_sessions', ['token' => apiToken('user'), 'body' => [
            'action' => 'start', 'activity_id' => $ids['activity'], 'appointment_id' => $ids['foreign'],
        ]]);
        if ($start['status'] === 201 || $start['status'] === 200) {
            $sessions[] = (int) ($start['body']['session']['session_id'] ?? 0);
        }
        assertStatus(400, $start, 'Start mit dem Termin einer fremden Gruppe');
    });
});
