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

/** Gruppenzugehoerigkeit mit Zeitraum: Schreibwege ueber die API (Spec 2026-10-05, 4). */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function ghCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden: " . $res['raw']);
    $id = (int) ($res['body']['id'] ?? 0);
    assertTrue($id > 0, "{$resource} lieferte keine ID: " . $res['raw']);

    return $id;
}

function ghDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

function ghMember(int $memberId): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);

    return $res['body'];
}

/** group_id => valid_from der heutigen Zuordnungen. */
function ghSince(array $member): array
{
    $out = [];
    foreach ($member['groups'] as $g) {
        $out[(int) $g['group_id']] = $g['valid_from'];
    }
    ksort($out);

    return $out;
}

/** Verlauf als [group_id, valid_from, valid_to], sortiert. */
function ghHistory(array $member): array
{
    $rows = array_map(static fn ($h) => [(int) $h['group_id'], $h['valid_from'], $h['valid_to']], $member['group_history'] ?? []);
    sort($rows);

    return $rows;
}

function ghPut(int $memberId, array $body): array
{
    return apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId], 'body' => $body]);
}

test('Anlegen: Zuordnungen von Anfang an, kein Verlauf', function () {
    $s = uniqid();
    $g = $m = 0;
    try {
        $g = ghCreate('member_groups', ['group_name' => "GH A {$s}"]);
        $m = ghCreate('members', ['name' => 'Gh', 'surname' => "Anlegen {$s}", 'active' => 1, 'group_ids' => [$g]]);
        $member = ghMember($m);
        assertSame([$g => null], ghSince($member));
        assertSame([], ghHistory($member));
    } finally {
        if ($m) { ghDelete('members', $m); }
        if ($g) { ghDelete('member_groups', $g); }
    }
});

test('Wechsel mit Datum: Verlauf bis Vortag, neue Gruppe ab Datum, Unveraendertes bleibt', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => 0];
    try {
        $a = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH A {$s}"]);
        $b = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH B {$s}"]);
        $c = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH C {$s}"]);
        $m = $ids['m'] = ghCreate('members', ['name' => 'Gh', 'surname' => "Wechsel {$s}", 'active' => 1, 'group_ids' => [$a, $c]]);

        $res = ghPut($m, ['group_ids' => [$b, $c], 'groups_valid_from' => '2026-06-01']);
        assertStatus(200, $res, $res['raw']);

        $member = ghMember($m);
        $expected = [$b => '2026-06-01', $c => null];
        ksort($expected);
        assertSame($expected, ghSince($member), 'B ab 01.06., C unveraendert von Anfang an');
        assertSame([[$a, null, '2026-05-31']], ghHistory($member), 'A endet am 31.05.');
        assertSame($a, (int) $member['group_history'][0]['group_id']);
        assertSame("GH A {$s}", $member['group_history'][0]['group_name'], 'group_name im Verlauf');
    } finally {
        if ($ids['m']) { ghDelete('members', $ids['m']); }
        foreach ($ids['g'] as $g) { ghDelete('member_groups', $g); }
    }
});

test('PUT ohne groups_valid_from: Aenderung gilt ab heute', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => 0];
    try {
        $a = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH A {$s}"]);
        $b = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH B {$s}"]);
        $m = $ids['m'] = ghCreate('members', ['name' => 'Gh', 'surname' => "Heute {$s}", 'active' => 1, 'group_ids' => [$a]]);
        assertStatus(200, ghPut($m, ['group_ids' => [$b]]));
        $member = ghMember($m);
        $today = date('Y-m-d');
        assertSame([$b => $today], ghSince($member));
        assertSame([[$a, null, date('Y-m-d', strtotime('-1 day'))]], ghHistory($member));
    } finally {
        if ($ids['m']) { ghDelete('members', $ids['m']); }
        foreach ($ids['g'] as $g) { ghDelete('member_groups', $g); }
    }
});

test('groups_valid_from: Zukunft und Unsinn ergeben 422 und aendern nichts', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => 0];
    try {
        $a = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH A {$s}"]);
        $b = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH B {$s}"]);
        $m = $ids['m'] = ghCreate('members', ['name' => 'Gh', 'surname' => "Pruef {$s}", 'active' => 1, 'group_ids' => [$a]]);
        foreach ([date('Y-m-d', strtotime('+1 day')), '2026-02-30', '01.06.2026'] as $bad) {
            $res = ghPut($m, ['group_ids' => [$b], 'groups_valid_from' => $bad, 'surname' => "Geaendert {$s}"]);
            assertStatus(422, $res, "{$bad} muss abgelehnt werden: " . $res['raw']);
            assertSame('groups_valid_from', $res['body']['field'] ?? null);
        }
        $member = ghMember($m);
        assertSame([$a => null], ghSince($member), 'Gruppen unveraendert');
        assertSame("Pruef {$s}", $member['surname'], 'auch der Name wurde nicht gespeichert');
    } finally {
        if ($ids['m']) { ghDelete('members', $ids['m']); }
        foreach ($ids['g'] as $g) { ghDelete('member_groups', $g); }
    }
});

test('Mitgliedschaftsregel: ergaenzte Gruppe bekommt dasselbe Datum', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => 0];
    try {
        $p = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH P {$s}"]);
        $r = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH R {$s}", 'is_subgroup' => true, 'parent_group_ids' => [$p]]);
        $x = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH X {$s}"]);
        $m = $ids['m'] = ghCreate('members', ['name' => 'Gh', 'surname' => "Regel {$s}", 'active' => 1, 'group_ids' => [$x]]);
        $res = ghPut($m, ['group_ids' => [$x, $r], 'groups_valid_from' => '2026-03-01']);
        assertStatus(200, $res);
        assertSame([['member_id' => $m, 'group_id' => $p]], $res['body']['added_groups'] ?? null);
        $since = ghSince(ghMember($m));
        assertSame('2026-03-01', $since[$p] ?? 'fehlt', 'P ab 01.03.');
        assertSame('2026-03-01', $since[$r] ?? 'fehlt', 'R ab 01.03.');
        assertTrue(array_key_exists($x, $since) && $since[$x] === null, 'X unveraendert von Anfang an');
    } finally {
        if ($ids['m']) { ghDelete('members', $ids['m']); }
        foreach (array_reverse($ids['g']) as $g) { ghDelete('member_groups', $g); }
    }
});

test('Register bekommt Gruppe nachtraeglich: valid_from der Registerzuordnung wird uebernommen', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => 0];
    try {
        $p = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH P {$s}"]);
        $r = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH R {$s}", 'is_subgroup' => true, 'parent_group_ids' => []]);
        $x = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH X {$s}"]);
        $m = $ids['m'] = ghCreate('members', ['name' => 'Gh', 'surname' => "Nach {$s}", 'active' => 1, 'group_ids' => [$x]]);
        assertStatus(200, ghPut($m, ['group_ids' => [$x, $r], 'groups_valid_from' => '2026-04-01']));
        $put = apiRequest('PUT', 'member_groups', ['token' => apiToken('admin'), 'query' => ['id' => $r],
            'body' => ['group_name' => "GH R {$s}", 'is_subgroup' => true, 'parent_group_ids' => [$p]]]);
        assertStatus(200, $put, $put['raw']);
        $since = ghSince(ghMember($m));
        assertSame('2026-04-01', $since[$p] ?? 'fehlt', 'P uebernimmt das Datum der Registerzuordnung');
    } finally {
        if ($ids['m']) { ghDelete('members', $ids['m']); }
        foreach (array_reverse($ids['g']) as $g) { ghDelete('member_groups', $g); }
    }
});

test('Mitglied loeschen raeumt den Verlauf', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => 0];
    try {
        $a = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH A {$s}"]);
        $m = $ids['m'] = ghCreate('members', ['name' => 'Gh', 'surname' => "Loesch {$s}", 'active' => 1, 'group_ids' => [$a]]);
        assertStatus(200, ghPut($m, ['group_ids' => [], 'groups_valid_from' => '2026-01-15']));
        assertSame(1, count(ghMember($m)['group_history']));
        $del = apiRequest('DELETE', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $m]]);
        assertStatus(200, $del, $del['raw']);
        $ids['m'] = 0;
        // Die Gruppe laesst sich danach ohne Fremdschluesselfehler loeschen
        $delG = apiRequest('DELETE', 'member_groups', ['token' => apiToken('admin'), 'query' => ['id' => $a]]);
        assertStatus(200, $delG, $delG['raw']);
        $ids['g'] = [];
    } finally {
        if ($ids['m']) { ghDelete('members', $ids['m']); }
        foreach ($ids['g'] as $g) { ghDelete('member_groups', $g); }
    }
});

test('Selbstauskunft my_data enthaelt Verlauf und valid_from', function () {
    $res = apiRequest('GET', 'my_data', ['token' => apiToken('user')]);
    assertStatus(200, $res, $res['raw']);
    assertTrue(is_array($res['body']['group_history'] ?? null), 'group_history fehlt oder ist keine Liste');
    foreach ($res['body']['groups'] ?? [] as $g) {
        assertTrue(array_key_exists('valid_from', $g), 'groups[].valid_from fehlt');
    }
});

