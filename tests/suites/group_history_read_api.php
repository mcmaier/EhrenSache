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
/** Gruppenzugehoerigkeit mit Zeitraum: Lesen am Termindatum (Spec 2026-10-05, 5). */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function ghrCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden: " . $res['raw']);
    $id = (int) ($res['body']['id'] ?? 0);
    assertTrue($id > 0, "{$resource} lieferte keine ID: " . $res['raw']);

    return $id;
}

function ghrDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

function ghrStats(array $w, array $query, string $role = 'admin'): array
{
    $res = apiRequest('GET', 'statistics', ['token' => apiToken($role), 'query' => ['year' => $w['year']] + $query]);
    assertStatus(200, $res);

    return $res['body'];
}

/** Tabelle einer Gruppe aus der Antwort, oder null. */
function ghrTable(array $stats, int $groupId): ?array
{
    foreach ($stats['statistics'] as $group) {
        if ((int) $group['group_id'] === $groupId) {
            return $group;
        }
    }

    return null;
}

/** Zeile eines Mitglieds als [Termine, anwesend, entschuldigt, unentschuldigt]. */
function ghrRow(array $table, int $memberId): ?array
{
    foreach ($table['members'] as $m) {
        if ((int) $m['member_id'] === $memberId) {
            return [(int) $m['total_appointments'], (int) $m['attended'],
                    (int) $m['excused'], (int) $m['unexcused_absences']];
        }
    }

    return null;
}

/**
 * Welt im Vorjahr: Gruppen A und B mit eigenen Terminarten (Rueckmeldung an),
 * Mitglied M erst in A, Wechsel nach B zum 01.06., Mitglied N durchgehend in A.
 * Termine: A1 03-01, A2 09-01 (Art A), B1 03-15, B2 09-15 (Art B).
 * Anwesend: M bei A1 und B2, N bei A1.
 */
function ghrWorld(array &$w): void
{
    $y = (int) date('Y') - 1;
    $s = uniqid();
    $w = ['year' => $y, 'groups' => [], 'types' => [], 'members' => [], 'appointments' => []];
    $w['groups']['A'] = ghrCreate('member_groups', ['group_name' => "GHR A {$s}"]);
    $w['groups']['B'] = ghrCreate('member_groups', ['group_name' => "GHR B {$s}"]);
    foreach (['A', 'B'] as $g) {
        $w['types'][$g] = ghrCreate('appointment_types', ['type_name' => "GHR Probe {$g} {$s}", 'is_default' => 0,
            'color' => '#667eea', 'group_ids' => [$w['groups'][$g]], 'responses_enabled' => 1]);
    }
    $w['members']['M'] = ghrCreate('members', ['name' => 'Ghr', 'surname' => "M {$s}", 'active' => 1, 'group_ids' => [$w['groups']['A']]]);
    $w['members']['N'] = ghrCreate('members', ['name' => 'Ghr', 'surname' => "N {$s}", 'active' => 1, 'group_ids' => [$w['groups']['A']]]);
    $put = apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $w['members']['M']],
        'body' => ['group_ids' => [$w['groups']['B']], 'groups_valid_from' => "{$y}-06-01"]]);
    assertStatus(200, $put, $put['raw']);

    foreach (['A1' => ['A', '03-01'], 'A2' => ['A', '09-01'], 'B1' => ['B', '03-15'], 'B2' => ['B', '09-15']] as $key => [$g, $md]) {
        $w['appointments'][$key] = ghrCreate('appointments', ['title' => "GHR {$key}", 'date' => "{$y}-{$md}",
            'start_time' => '19:00:00', 'type_id' => $w['types'][$g]]);
    }
    foreach ([['M', 'A1'], ['M', 'B2'], ['N', 'A1']] as [$m, $a]) {
        assertStatus(201, apiRequest('POST', 'records', ['token' => apiToken('admin'), 'body' => [
            'member_id' => $w['members'][$m], 'appointment_id' => $w['appointments'][$a], 'status' => 'present']]));
    }
}

function ghrDropWorld(array $w): void
{
    foreach ($w['appointments'] ?? [] as $id) { ghrDelete('appointments', $id); }
    foreach ($w['members'] ?? [] as $id)      { ghrDelete('members', $id); }
    foreach ($w['types'] ?? [] as $id)        { ghrDelete('appointment_types', $id); }
    foreach ($w['groups'] ?? [] as $id)       { ghrDelete('member_groups', $id); }
}

$ghrWorld = [];

test('Lese-Welt laesst sich anlegen', function () use (&$ghrWorld) {
    ghrWorld($ghrWorld);
});

test('Statistik: Wechsel mitten im Jahr zaehlt je Gruppe nur den eigenen Zeitraum', function () use (&$ghrWorld) {
    assertTrue(!empty($ghrWorld['members']['N']), 'Welt fehlt');
    $w = $ghrWorld;
    $a = ghrTable(ghrStats($w, ['group_id' => $w['groups']['A']]), $w['groups']['A']);
    $b = ghrTable(ghrStats($w, ['group_id' => $w['groups']['B']]), $w['groups']['B']);
    assertTrue($a !== null && $b !== null, 'Tabellen A und B fehlen');
    assertSame([1, 1, 0, 0], ghrRow($a, $w['members']['M']), 'A: nur A1 (vor dem Wechsel)');
    assertSame([1, 1, 0, 0], ghrRow($b, $w['members']['M']), 'B: nur B2 -- B1 vor dem Wechsel zaehlt nicht als unentschuldigt');
    assertSame([2, 1, 0, 1], ghrRow($a, $w['members']['N']), 'N durchgehend in A: Gegenprobe');
});

// Weitere Tests dieser Datei kommen in Task 6 und 7 hinzu.

test('Stichtag am Termindatum: Grenztage und Register (eigene Welt)', function () {
    $y = (int) date('Y') - 1;
    $s = uniqid();
    $ids = ['appointments' => [], 'members' => [], 'types' => [], 'groups' => []];
    try {
        $a = $ids['groups'][] = ghrCreate('member_groups', ['group_name' => "GHR SA {$s}"]);
        $b = $ids['groups'][] = ghrCreate('member_groups', ['group_name' => "GHR SB {$s}"]);
        $r = $ids['groups'][] = ghrCreate('member_groups', ['group_name' => "GHR SR {$s}", 'is_subgroup' => true, 'parent_group_ids' => [$a]]);
        $type = [];
        foreach (['TA' => $a, 'TB' => $b, 'TR' => $r] as $key => $g) {
            $type[$key] = $ids['types'][] = ghrCreate('appointment_types', ['type_name' => "GHR S{$key} {$s}", 'is_default' => 0,
                'color' => '#667eea', 'group_ids' => [$g], 'responses_enabled' => 1]);
        }
        // M: von Anfang an in A und R, wechselt zum 01.06. nach B (A und R enden am 31.05.)
        $m = $ids['members'][] = ghrCreate('members', ['name' => 'Ghr', 'surname' => "SM {$s}", 'active' => 1, 'group_ids' => [$a, $r]]);
        $put = apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $m],
            'body' => ['group_ids' => [$b], 'groups_valid_from' => "{$y}-06-01"]]);
        assertStatus(200, $put, $put['raw']);
        // P: von Anfang an in A, tritt dem Register R erst zum 01.06. bei
        $p = $ids['members'][] = ghrCreate('members', ['name' => 'Ghr', 'surname' => "SP {$s}", 'active' => 1, 'group_ids' => [$a]]);
        $put = apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $p],
            'body' => ['group_ids' => [$a, $r], 'groups_valid_from' => "{$y}-06-01"]]);
        assertStatus(200, $put, $put['raw']);

        foreach ([['TA', '05-31'], ['TA', '06-01'], ['TB', '06-01'], ['TB', '05-31'], ['TR', '05-31'], ['TR', '06-01'], ['TA', '09-01']] as $i => [$t, $md]) {
            $ids['appointments'][] = ghrCreate('appointments', ['title' => "GHR S{$i}", 'date' => "{$y}-{$md}",
                'start_time' => '19:00:00', 'type_id' => $type[$t]]);
        }

        $w = ['year' => $y];
        $ta = ghrTable(ghrStats($w, ['group_id' => $a]), $a);
        $tb = ghrTable(ghrStats($w, ['group_id' => $b]), $b);
        $tr = ghrTable(ghrStats($w, ['group_id' => $r]), $r);
        assertTrue($ta !== null && $tb !== null && $tr !== null, 'Tabellen A, B und R fehlen');
        assertSame(1, ghrRow($ta, $m)[0] ?? null, 'A: M nur am letzten Tag (31.05.), nicht am 01.06.');
        assertSame(1, ghrRow($tb, $m)[0] ?? null, 'B: M ab dem ersten Tag (01.06.), nicht am 31.05.');
        assertSame(2, ghrRow($tr, $m)[0] ?? null, 'R: A am 31.05. (ueber die Gruppe A) und R am 31.05.; nichts nach dem Austritt');
        assertSame(3, ghrRow($ta, $p)[0] ?? null, 'A: P durchgehend, drei Termine der Art A');
        assertSame(3, ghrRow($tr, $p)[0] ?? null, 'R: P erst ab 01.06. im Register -- A am 31.05. zaehlt dort nicht');
    } finally {
        foreach ($ids['appointments'] as $id) { ghrDelete('appointments', $id); }
        foreach ($ids['members'] as $id)      { ghrDelete('members', $id); }
        foreach ($ids['types'] as $id)        { ghrDelete('appointment_types', $id); }
        foreach (array_reverse($ids['groups']) as $id) { ghrDelete('member_groups', $id); }
    }
});

test('Rueckmeldung eines Maerztermins: M ist bei A erwartet, bei B nicht', function () use (&$ghrWorld) {
    assertTrue(!empty($ghrWorld['members']['N']), 'Welt fehlt');
    $w = $ghrWorld;
    $ids = static function (int $appointmentId): array {
        $res = apiRequest('GET', 'appointment_responses', ['token' => apiToken('admin'), 'query' => ['appointment_id' => $appointmentId]]);
        assertStatus(200, $res, $res['raw']);
        return array_map(static fn ($m) => (int) $m['member_id'], $res['body']['members'] ?? []);
    };
    assertTrue(in_array($w['members']['M'], $ids($w['appointments']['A1']), true), 'M fehlt bei A1');
    assertTrue(!in_array($w['members']['M'], $ids($w['appointments']['B1']), true), 'M darf bei B1 nicht erwartet sein');
    assertTrue(in_array($w['members']['M'], $ids($w['appointments']['B2']), true), 'M fehlt bei B2');
});

test('Anwesenheitsliste je Termin: Gruppe am Termindatum', function () use (&$ghrWorld) {
    assertTrue(!empty($ghrWorld['members']['N']), 'Welt fehlt');
    $w = $ghrWorld;
    $ids = static function (int $appointmentId): array {
        $res = apiRequest('GET', 'attendance_list', ['token' => apiToken('admin'), 'query' => ['appointment_id' => $appointmentId]]);
        assertStatus(200, $res, $res['raw']);
        return array_map(static fn ($m) => (int) $m['member_id'], $res['body']['members'] ?? []);
    };
    assertTrue(in_array($w['members']['M'], $ids($w['appointments']['A1']), true), 'M fehlt bei A1');
    assertTrue(!in_array($w['members']['M'], $ids($w['appointments']['A2']), true), 'M darf bei A2 nicht stehen');
    assertTrue(!in_array($w['members']['M'], $ids($w['appointments']['B1']), true), 'M darf bei B1 nicht stehen');
});

test('Anwesenheitsliste je Mitglied: Termine aus dem jeweiligen Zeitraum', function () use (&$ghrWorld) {
    assertTrue(!empty($ghrWorld['members']['N']), 'Welt fehlt');
    $w = $ghrWorld;
    $res = apiRequest('GET', 'attendance_list', ['token' => apiToken('admin'),
        'query' => ['member_id' => $w['members']['M'], 'year' => $w['year']]]);
    assertStatus(200, $res, $res['raw']);
    $got = array_map(static fn ($a) => (int) $a['appointment_id'], $res['body']['appointments'] ?? []);
    sort($got);
    $expected = [$w['appointments']['A1'], $w['appointments']['B2']];
    sort($expected);
    assertSame($expected, $got, 'nur A1 und B2');
});

test('Anwesenheitsliste je Mitglied: auch ohne heutige Gruppe', function () use (&$ghrWorld) {
    assertTrue(!empty($ghrWorld['members']['N']), 'Welt fehlt');
    $w = $ghrWorld;
    $put = apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $w['members']['N']],
        'body' => ['group_ids' => [], 'groups_valid_from' => date('Y-m-d')]]);
    assertStatus(200, $put);
    $res = apiRequest('GET', 'attendance_list', ['token' => apiToken('admin'),
        'query' => ['member_id' => $w['members']['N'], 'year' => $w['year']]]);
    assertStatus(200, $res, $res['raw']);
    $got = array_map(static fn ($a) => (int) $a['appointment_id'], $res['body']['appointments'] ?? []);
    sort($got);
    $expected = [$w['appointments']['A1'], $w['appointments']['A2']];
    sort($expected);
    assertSame($expected, $got, 'N war im Vorjahr durchgehend in A');
});

test('Lese-Welt aufraeumen', function () use (&$ghrWorld) {
    ghrDropWorld($ghrWorld);
});
