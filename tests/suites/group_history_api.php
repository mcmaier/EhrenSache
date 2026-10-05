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

test('Selbstauskunft my_data: ehemalige Gruppe steht im Verlauf, heutige Gruppen bleiben', function () {
    $userMember = apiMemberId('user');
    assertTrue($userMember !== null, 'Testkonto user hat kein Mitglied');
    $before = array_keys(ghSince(ghMember($userMember)));
    $g = 0;
    try {
        $g = ghCreate('member_groups', ['group_name' => 'GH Auskunft ' . uniqid()]);
        $twoYears = date('Y-m-d', strtotime('-2 years'));
        assertStatus(200, ghPut($userMember, ['group_ids' => array_merge($before, [$g]), 'groups_valid_from' => $twoYears]));
        assertStatus(200, ghPut($userMember, ['group_ids' => $before, 'groups_valid_from' => date('Y-m-d')]));

        $res = apiRequest('GET', 'my_data', ['token' => apiToken('user')]);
        assertStatus(200, $res, $res['raw']);
        assertTrue(is_array($res['body']['group_history'] ?? null), 'group_history fehlt oder ist keine Liste');
        $found = null;
        foreach ($res['body']['group_history'] as $h) {
            if (str_starts_with($h['group_name'], 'GH Auskunft')) {
                $found = $h;
            }
        }
        assertTrue($found !== null, 'ehemalige Gruppe fehlt im Verlauf: ' . $res['raw']);
        assertSame($twoYears, $found['valid_from']);
        assertSame(date('Y-m-d', strtotime('-1 day')), $found['valid_to']);
        foreach ($res['body']['groups'] ?? [] as $grp) {
            assertTrue(array_key_exists('valid_from', $grp), 'groups[].valid_from fehlt');
        }
    } finally {
        if ($g) { ghDelete('member_groups', $g); }
    }
    assertSame($before, array_keys(ghSince(ghMember($userMember))), 'heutige Gruppen des Kontos unveraendert');
});

test('PUT: unbekannte Gruppe ergibt 400, nichts wird gespeichert', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => 0];
    try {
        $a = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH A {$s}"]);
        $m = $ids['m'] = ghCreate('members', ['name' => 'Gh', 'surname' => "Unbek {$s}", 'active' => 1, 'group_ids' => [$a]]);
        $res = ghPut($m, ['group_ids' => [$a, 999999999], 'surname' => "Geaendert {$s}"]);
        assertStatus(400, $res, $res['raw']);
        assertSame('group_ids', $res['body']['field'] ?? null);
        $member = ghMember($m);
        assertSame("Unbek {$s}", $member['surname'], 'Name unveraendert');
        assertSame([$a => null], ghSince($member));
        $post = apiRequest('POST', 'members', ['token' => apiToken('admin'), 'body' =>
            ['name' => 'Gh', 'surname' => "Unbek2 {$s}", 'active' => 1, 'group_ids' => [999999999]]]);
        assertStatus(400, $post, $post['raw']);
        $list = apiRequest('GET', 'members', ['token' => apiToken('admin')]);
        foreach ($list['body'] as $row) {
            if (($row['surname'] ?? '') === "Unbek2 {$s}") {
                $ids['extra'] = (int) $row['member_id'];
            }
        }
        assertTrue(!isset($ids['extra']), 'POST mit unbekannter Gruppe hat ein Mitglied angelegt');
    } finally {
        if (!empty($ids['extra'])) { ghDelete('members', $ids['extra']); }
        if ($ids['m']) { ghDelete('members', $ids['m']); }
        foreach ($ids['g'] as $g) { ghDelete('member_groups', $g); }
    }
});

test('PUT: unbekanntes Mitglied ergibt 404, auch nur mit group_ids', function () {
    $s = uniqid();
    $g = 0;
    try {
        $g = ghCreate('member_groups', ['group_name' => "GH A {$s}"]);
        $res = ghPut(999999999, ['group_ids' => [$g]]);
        assertStatus(404, $res, $res['raw']);
    } finally {
        if ($g) { ghDelete('member_groups', $g); }
    }
});

test('groups_valid_from wird nur zusammen mit group_ids geprueft', function () {
    $s = uniqid();
    $m = 0;
    try {
        $m = ghCreate('members', ['name' => 'Gh', 'surname' => "Ignor {$s}", 'active' => 1]);
        $res = ghPut($m, ['surname' => "Neu {$s}", 'groups_valid_from' => 'unsinn']);
        assertStatus(200, $res, $res['raw']);
        assertSame("Neu {$s}", ghMember($m)['surname']);
    } finally {
        if ($m) { ghDelete('members', $m); }
    }
});

test('group_ids als Zeichenkette ergibt 400', function () {
    $s = uniqid();
    $m = 0;
    try {
        $m = ghCreate('members', ['name' => 'Gh', 'surname' => "Str {$s}", 'active' => 1]);
        $res = ghPut($m, ['group_ids' => '1,2']);
        assertStatus(400, $res, $res['raw']);
    } finally {
        if ($m) { ghDelete('members', $m); }
    }
});

test('Korrektur: am selben Tag oder davor entfernt ergibt keinen Verlaufseintrag', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => 0];
    try {
        $a = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH A {$s}"]);
        $m = $ids['m'] = ghCreate('members', ['name' => 'Gh', 'surname' => "Korr {$s}", 'active' => 1]);
        assertStatus(200, ghPut($m, ['group_ids' => [$a], 'groups_valid_from' => '2026-06-10']));
        assertStatus(200, ghPut($m, ['group_ids' => [], 'groups_valid_from' => '2026-06-01']));
        $member = ghMember($m);
        assertSame([], ghSince($member));
        assertSame([], ghHistory($member));
    } finally {
        if ($ids['m']) { ghDelete('members', $ids['m']); }
        foreach ($ids['g'] as $g) { ghDelete('member_groups', $g); }
    }
});

test('Registerregel ueber den Planer: ueberlappender Verlauf der ergaenzten Gruppe wird zusammengefuehrt', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => 0];
    try {
        $p = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH P {$s}"]);
        $x = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH X {$s}"]);
        $m = $ids['m'] = ghCreate('members', ['name' => 'Gh', 'surname' => "Ueberl {$s}", 'active' => 1, 'group_ids' => [$x]]);
        assertStatus(200, ghPut($m, ['group_ids' => [$x, $p], 'groups_valid_from' => '2025-01-01']));
        assertStatus(200, ghPut($m, ['group_ids' => [$x], 'groups_valid_from' => '2026-06-01']));
        assertSame([[$p, '2025-01-01', '2026-05-31']], ghHistory(ghMember($m)));

        $r = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH R {$s}", 'is_subgroup' => true, 'parent_group_ids' => []]);
        assertStatus(200, ghPut($m, ['group_ids' => [$x, $r], 'groups_valid_from' => '2026-04-01']));
        $put = apiRequest('PUT', 'member_groups', ['token' => apiToken('admin'), 'query' => ['id' => $r],
            'body' => ['group_name' => "GH R {$s}", 'is_subgroup' => true, 'parent_group_ids' => [$p]]]);
        assertStatus(200, $put, $put['raw']);

        $member = ghMember($m);
        // P galt bis 31.05.; ab 01.04. kommt P ueber das Register hinzu -- durchgehend seit
        // 2025-01-01, also zusammengefuehrt (Spec 7.1). Ein direktes INSERT ergaebe dagegen
        // P ab 01.04. plus den unveraenderten Verlauf bis 31.05. (Ueberlappung).
        assertSame('2025-01-01', ghSince($member)[$p] ?? 'fehlt', 'P durchgehend seit 2025-01-01');
        assertSame([], ghHistory($member), 'Verlauf von P aufgeloest, keine Ueberlappung');
    } finally {
        if ($ids['m']) { ghDelete('members', $ids['m']); }
        foreach (array_reverse($ids['g']) as $g) { ghDelete('member_groups', $g); }
    }
});

function ghImport(string $csv): array
{
    $file = tempnam(sys_get_temp_dir(), 'gh');
    file_put_contents($file, $csv);
    $cfg = testConfig();
    $ch = curl_init(rtrim($cfg['base_url'], '/') . '/api/api.php?resource=import&type=members');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . apiToken('admin')],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($file, 'text/csv', 'gh.csv')],
    ]);
    $raw = (string) curl_exec($ch);
    $res = ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => json_decode($raw, true), 'raw' => $raw];
    curl_close($ch);
    unlink($file);

    return $res;
}

/** Entfernt die Importprotokolle, die dieser Test erzeugt hat (Dateiname gh.csv). */
function ghDropImportLogs(): void
{
    $logs = apiRequest('GET', 'import_logs', ['token' => apiToken('admin')])['body'] ?? [];
    foreach ($logs as $log) {
        if (($log['filename'] ?? '') === 'gh.csv') {
            apiRequest('DELETE', 'import_logs', ['token' => apiToken('admin'), 'query' => ['id' => (int) $log['log_id']]]);
        }
    }
}

test('Import: bestehendes Mitglied vergleichen ab heute, neues von Anfang an', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => []];
    try {
        $a = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH A {$s}"]);
        $b = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH B {$s}"]);
        $m = $ids['m'][] = ghCreate('members', ['name' => 'Gh', 'surname' => "Imp M {$s}", 'member_number' => "GHM{$s}",
                                               'active' => 1, 'group_ids' => [$a]]);
        $csv = "name;surname;member_number;groups\n"
             . "Gh;Imp M {$s};GHM{$s};GH B {$s}\n"
             . "Gh;Imp N {$s};GHN{$s};GH A {$s}\n";

        $res = ghImport($csv);
        assertStatus(200, $res, $res['raw']);
        assertSame(1, $res['body']['group_changes'] ?? null, 'ein bestehendes Mitglied mit geaenderten Gruppen');

        $all = apiRequest('GET', 'members', ['token' => apiToken('admin')])['body'] ?? [];
        $n = 0;
        foreach ($all as $row) {
            if (($row['member_number'] ?? '') === "GHN{$s}") { $n = (int) $row['member_id']; }
        }
        assertTrue($n > 0, 'neues Mitglied N nicht gefunden');
        $ids['m'][] = $n;

        $memberM = ghMember($m);
        assertSame([$b => date('Y-m-d')], ghSince($memberM), 'M: B ab heute');
        assertSame([[$a, null, date('Y-m-d', strtotime('-1 day'))]], ghHistory($memberM), 'M: A bis gestern');
        $memberN = ghMember($n);
        assertSame([$a => null], ghSince($memberN), 'N: von Anfang an');
        assertSame([], ghHistory($memberN), 'N: kein Verlauf');

        $again = ghImport($csv);
        assertStatus(200, $again, $again['raw']);
        assertSame(0, $again['body']['group_changes'] ?? null, 'zweiter Import aendert nichts');
        assertSame(1, count(ghMember($m)['group_history']), 'Verlauf von M unveraendert');
    } finally {
        foreach ($ids['m'] as $id) { ghDelete('members', $id); }
        foreach ($ids['g'] as $g) { ghDelete('member_groups', $g); }
        ghDropImportLogs();
    }
});

test('Import: nur unbekannte Gruppe laesst die Gruppen eines bestehenden Mitglieds unveraendert', function () {
    $s = uniqid();
    $ids = ['g' => [], 'm' => []];
    try {
        $a = $ids['g'][] = ghCreate('member_groups', ['group_name' => "GH U {$s}"]);
        $m = $ids['m'][] = ghCreate('members', ['name' => 'Gh', 'surname' => "Imp U {$s}", 'member_number' => "GHU{$s}",
                                               'active' => 1, 'group_ids' => [$a]]);
        $res = ghImport("name;surname;member_number;groups\nGh;Imp U {$s};GHU{$s};GH Unbekannt {$s}\n");
        assertStatus(200, $res, $res['raw']);
        assertSame(0, $res['body']['group_changes'] ?? null, 'keine Aenderung gezaehlt');
        assertTrue(count($res['body']['errors'] ?? []) === 1, 'Fehlermeldung fuer unbekannte Gruppe');
        $member = ghMember($m);
        assertSame([$a => null], ghSince($member), 'Gruppe A bleibt');
        assertSame([], ghHistory($member), 'kein Verlauf');
    } finally {
        foreach ($ids['m'] as $id) { ghDelete('members', $id); }
        foreach ($ids['g'] as $g) { ghDelete('member_groups', $g); }
        ghDropImportLogs();
    }
});
