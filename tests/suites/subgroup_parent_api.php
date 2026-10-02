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

/** Register gehoeren zu Gruppen: API und Mitgliedschaftsregel (Spec 2026-10-02, 3 und 4). */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function spCreate(string $resource, array $body): array
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden: " . $res['raw']);
    assertTrue((int) ($res['body']['id'] ?? 0) > 0, 'keine ID: ' . $res['raw']);

    return $res['body'];
}

function spDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

function spGroupIdsOf(int $memberId): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    $ids = array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups']);
    sort($ids);

    return $ids;
}

function spGroup(int $groupId): array
{
    $res = apiRequest('GET', 'member_groups', ['token' => apiToken('admin'), 'query' => ['id' => $groupId]]);
    assertStatus(200, $res);

    return $res['body'];
}

/** PUT member_groups als Voll-Update; $extra ergaenzt den Koerper. */
function spPutGroup(int $groupId, string $name, array $extra): array
{
    return apiRequest('PUT', 'member_groups', ['token' => apiToken('admin'), 'query' => ['id' => $groupId],
        'body' => array_merge(['group_name' => $name], $extra)]);
}

test('Gruppen eines Registers: anlegen, lesen, Regeln', function () {
    $s   = uniqid();
    $ids = [];
    try {
        $g  = $ids[] = (int) spCreate('member_groups', ['group_name' => "SP G {$s}"])['id'];
        $g2 = $ids[] = (int) spCreate('member_groups', ['group_name' => "SP G2 {$s}"])['id'];
        $r  = $ids[] = (int) spCreate('member_groups', ['group_name' => "SP R {$s}", 'is_subgroup' => true,
                                                      'parent_group_ids' => [$g2, $g]])['id'];
        $expected = [$g, $g2];
        sort($expected);
        assertSame($expected, spGroup($r)['parent_group_ids'] ?? null, 'parent_group_ids wird sortiert gelesen');
        assertSame([], spGroup($g)['parent_group_ids'] ?? null, 'gewoehnliche Gruppe: leere Liste');

        $list = apiRequest('GET', 'member_groups', ['token' => apiToken('admin')]);
        assertStatus(200, $list);
        $row = null;
        foreach ($list['body'] as $candidate) {
            if ((int) $candidate['group_id'] === $r) { $row = $candidate; }
        }
        assertSame($expected, $row['parent_group_ids'] ?? null, 'Liste liefert parent_group_ids');

        // Untergruppe als Ziel: 400
        $bad = apiRequest('POST', 'member_groups', ['token' => apiToken('admin'), 'body' => [
            'group_name' => "SP X {$s}", 'is_subgroup' => true, 'parent_group_ids' => [$r]]]);
        assertStatus(400, $bad, 'Register eines Registers muss abgewiesen werden');

        // unbekannte Gruppe, falscher Typ: 400
        $bad = apiRequest('POST', 'member_groups', ['token' => apiToken('admin'), 'body' => [
            'group_name' => "SP X {$s}", 'is_subgroup' => true, 'parent_group_ids' => [999999999]]]);
        assertStatus(400, $bad, 'unbekannte Gruppe');
        $bad = apiRequest('POST', 'member_groups', ['token' => apiToken('admin'), 'body' => [
            'group_name' => "SP X {$s}", 'is_subgroup' => true, 'parent_group_ids' => $g]]);
        assertStatus(400, $bad, 'parent_group_ids muss eine Liste sein');

        // Gruppen fuer gewoehnliche Gruppe: 400
        $bad = spPutGroup($g2, "SP G2 {$s}", ['parent_group_ids' => [$g]]);
        assertStatus(400, $bad, 'parent_group_ids nur fuer Untergruppen');

        // Gruppe mit Registern darf nicht zur Untergruppe werden
        $bad = spPutGroup($g, "SP G {$s}", ['is_subgroup' => true]);
        assertStatus(400, $bad, 'Gruppe mit Registern darf keine Untergruppe werden');

        // PUT ohne das Feld behaelt die Zuordnungen
        $res = spPutGroup($r, "SP R {$s} neu", ['is_subgroup' => true]);
        assertStatus(200, $res);
        assertSame($expected, spGroup($r)['parent_group_ids'] ?? null, 'PUT ohne Feld behaelt Zuordnungen');

        // Loeschen einer der Gruppen entfernt nur deren Zuordnung
        spDelete('member_groups', $g);
        $ids = array_values(array_diff($ids, [$g]));
        assertSame([$g2], spGroup($r)['parent_group_ids'] ?? null, 'CASCADE: nur die geloeschte Zuordnung faellt weg');

        // Register -> gewoehnliche Gruppe: Zuordnungen weg
        $res = spPutGroup($r, "SP R {$s}", ['is_subgroup' => false]);
        assertStatus(200, $res);
        assertSame([], spGroup($r)['parent_group_ids'] ?? null, 'gewoehnlich: keine Zuordnungen');
    } finally {
        foreach (array_reverse($ids) as $id) { spDelete('member_groups', $id); }
    }
});

test('Mitgliedschaftsregel: nachtraegliche Zuordnung (eine Gruppe ergaenzt, mehrere warnen)', function () {
    $s       = uniqid();
    $groups  = [];
    $members = [];
    try {
        $g = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP G {$s}"])['id'];
        $h = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP H {$s}"])['id'];
        $q = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP Q {$s}", 'is_subgroup' => true])['id'];
        $w = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP W {$s}", 'is_subgroup' => true])['id'];

        $m1 = $members[] = (int) spCreate('members', ['name' => 'Sp', 'surname' => "1 {$s}", 'active' => 1,
                                                      'group_ids' => [$q]])['id'];

        // Eine Gruppe: Mitglied wird ergaenzt, Antwort meldet es
        $res = spPutGroup($q, "SP Q {$s}", ['is_subgroup' => true, 'parent_group_ids' => [$h]]);
        assertStatus(200, $res);
        $exp = [$h, $q]; sort($exp);
        assertSame($exp, spGroupIdsOf($m1));
        assertSame([['member_id' => $m1, 'group_id' => $h]], $res['body']['added_groups'] ?? null, 'Meldung bei Zuordnung');
        assertSame([], $res['body']['group_warnings'] ?? null);

        // Zweiter Lauf ist ohne Wirkung (Mitglied steht schon in H)
        $res = spPutGroup($q, "SP Q {$s}", ['is_subgroup' => true, 'parent_group_ids' => [$h]]);
        assertSame([], $res['body']['added_groups'] ?? null, 'nichts mehr zu ergaenzen');

        // Zwei Gruppen: Mitglied in keiner -> keine Ergaenzung, Warnung
        $m2 = $members[] = (int) spCreate('members', ['name' => 'Sp', 'surname' => "2 {$s}", 'active' => 1,
                                                      'group_ids' => [$w]])['id'];
        $m3 = $members[] = (int) spCreate('members', ['name' => 'Sp', 'surname' => "3 {$s}", 'active' => 1,
                                                      'group_ids' => [$w, $g]])['id'];
        $res = spPutGroup($w, "SP W {$s}", ['is_subgroup' => true, 'parent_group_ids' => [$g, $h]]);
        assertStatus(200, $res);
        assertSame([$w], spGroupIdsOf($m2), 'bei mehreren Gruppen wird nichts ergaenzt');
        assertSame([], $res['body']['added_groups'] ?? null);
        assertSame([['member_id' => $m2, 'subgroup_id' => $w]], $res['body']['group_warnings'] ?? null,
            'Warnung nur fuer das Mitglied ohne beide Gruppen');
        $exp = [$g, $w]; sort($exp);
        assertSame($exp, spGroupIdsOf($m3), 'Mitglied in einer der Gruppen bleibt unveraendert');

        // POST mit Gruppe: ohne Mitglieder leere Listen
        $body = spCreate('member_groups', ['group_name' => "SP N {$s}", 'is_subgroup' => true, 'parent_group_ids' => [$g]]);
        $groups[] = (int) $body['id'];
        assertSame([], $body['added_groups'] ?? null);
        assertSame([], $body['group_warnings'] ?? null);
        assertSame([$g], spGroup((int) $body['id'])['parent_group_ids'] ?? null);
    } finally {
        foreach ($members as $id) { spDelete('members', $id); }
        foreach (array_reverse($groups) as $id) { spDelete('member_groups', $id); }
    }
});

test('Mitgliedschaftsregel: POST und PUT members ziehen die Gruppe nach', function () {
    $s       = uniqid();
    $groups  = [];
    $members = [];
    try {
        $g = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP G {$s}"])['id'];
        $h = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP H {$s}"])['id'];
        $r = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP R {$s}", 'is_subgroup' => true,
                                                         'parent_group_ids' => [$g]])['id'];

        $body = spCreate('members', ['name' => 'Sp', 'surname' => "1 {$s}", 'active' => 1, 'group_ids' => [$r]]);
        $m1 = $members[] = (int) $body['id'];
        $exp = [$g, $r]; sort($exp);
        assertSame($exp, spGroupIdsOf($m1));
        assertSame([['member_id' => $m1, 'group_id' => $g]], $body['added_groups'] ?? null, 'Meldung bei POST');

        $res = apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $m1],
            'body' => ['group_ids' => [$r, $h]]]);
        assertStatus(200, $res);
        $exp = [$g, $h, $r]; sort($exp);
        assertSame($exp, spGroupIdsOf($m1), 'Register gewinnt bei einer Gruppe');
    } finally {
        foreach ($members as $id) { spDelete('members', $id); }
        foreach (array_reverse($groups) as $id) { spDelete('member_groups', $id); }
    }
});

test('Mitgliedschaftsregel: Register mit zwei Gruppen warnt bei POST und PUT members', function () {
    $s       = uniqid();
    $groups  = [];
    $members = [];
    try {
        $g = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP G {$s}"])['id'];
        $h = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP H {$s}"])['id'];
        $r = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP R {$s}", 'is_subgroup' => true,
                                                         'parent_group_ids' => [$g, $h]])['id'];

        // In keiner der beiden Gruppen: unveraendert, Warnung
        $body = spCreate('members', ['name' => 'Sp', 'surname' => "1 {$s}", 'active' => 1, 'group_ids' => [$r]]);
        $m1 = $members[] = (int) $body['id'];
        assertSame([$r], spGroupIdsOf($m1), 'bei mehreren Gruppen wird nichts ergaenzt');
        assertSame([], $body['added_groups'] ?? null);
        assertSame([['member_id' => $m1, 'subgroup_id' => $r]], $body['group_warnings'] ?? null, 'Warnung bei POST');

        // In einer der beiden: keine Warnung
        $body = spCreate('members', ['name' => 'Sp', 'surname' => "2 {$s}", 'active' => 1, 'group_ids' => [$r, $h]]);
        $m2 = $members[] = (int) $body['id'];
        $exp = [$h, $r]; sort($exp);
        assertSame($exp, spGroupIdsOf($m2));
        assertSame([], $body['added_groups'] ?? null);
        assertSame([], $body['group_warnings'] ?? null, 'Mitglied in einer der Gruppen: keine Warnung');

        // PUT: Warnung, dann mit Gruppe keine mehr
        $res = apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $m2],
            'body' => ['group_ids' => [$r]]]);
        assertStatus(200, $res);
        assertSame([['member_id' => $m2, 'subgroup_id' => $r]], $res['body']['group_warnings'] ?? null, 'Warnung bei PUT');
        assertSame([$r], spGroupIdsOf($m2));
        $res = apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $m2],
            'body' => ['group_ids' => [$r, $g]]]);
        assertSame([], $res['body']['group_warnings'] ?? null);
        assertSame([], $res['body']['added_groups'] ?? null);
    } finally {
        foreach ($members as $id) { spDelete('members', $id); }
        foreach (array_reverse($groups) as $id) { spDelete('member_groups', $id); }
    }
});

test('Mitgliedschaftsregel: Ergebnis haengt nicht von der Reihenfolge der Register ab', function () {
    $s       = uniqid();
    $groups  = [];
    $members = [];
    try {
        $g = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP G {$s}"])['id'];
        $h = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP H {$s}"])['id'];
        // R2 zuerst angelegt: seine ID sortiert vor der von R1
        $r2 = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP R2 {$s}", 'is_subgroup' => true,
                                                          'parent_group_ids' => [$g, $h]])['id'];
        $r1 = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP R1 {$s}", 'is_subgroup' => true,
                                                          'parent_group_ids' => [$g]])['id'];
        assertTrue($r2 < $r1, 'Testvoraussetzung: R2 vor R1');

        $body = spCreate('members', ['name' => 'Sp', 'surname' => "1 {$s}", 'active' => 1, 'group_ids' => [$r2, $r1]]);
        $m = $members[] = (int) $body['id'];
        $exp = [$g, $r1, $r2]; sort($exp);
        assertSame($exp, spGroupIdsOf($m), 'G wird ueber R1 ergaenzt');
        assertSame([['member_id' => $m, 'group_id' => $g]], $body['added_groups'] ?? null);
        assertSame([], $body['group_warnings'] ?? null, 'R2 hat durch R1 seine Gruppe G, keine Warnung');
    } finally {
        foreach ($members as $id) { spDelete('members', $id); }
        foreach (array_reverse($groups) as $id) { spDelete('member_groups', $id); }
    }
});

/** Laedt eine Mitglieder-CSV ueber POST import hoch. */
function spImportMembers(string $csv): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'spimp');
    file_put_contents($tmp, $csv);
    try {
        $cfg = testConfig();
        $ch = curl_init(rtrim($cfg['base_url'], '/') . '/api/api.php?resource=import&type=members');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json',
                                              'Authorization: Bearer ' . apiToken('admin')]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($tmp, 'text/csv', 'sp_import.csv')]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } finally {
        unlink($tmp);
    }

    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
}

test('Mitgliedschaftsregel: CSV-Import zieht die Gruppe nach und meldet Warnungen', function () {
    $s       = uniqid();
    $groups  = [];
    $numbers = ["SPI1{$s}", "SPI2{$s}"];
    try {
        $g  = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP G {$s}"])['id'];
        $h  = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP H {$s}"])['id'];
        $r  = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP R {$s}", 'is_subgroup' => true,
                                                          'parent_group_ids' => [$g]])['id'];
        $r2 = $groups[] = (int) spCreate('member_groups', ['group_name' => "SP R2 {$s}", 'is_subgroup' => true,
                                                          'parent_group_ids' => [$g, $h]])['id'];

        $csv = "name;surname;member_number;active;groups\n"
             . "Sp;Eins {$s};{$numbers[0]};1;SP R {$s}\n"
             . "Sp;Zwei {$s};{$numbers[1]};1;SP R2 {$s}\n";
        $res = spImportMembers($csv);
        assertStatus(200, $res, $res['raw']);
        assertSame(2, (int) ($res['body']['imported'] ?? 0), $res['raw']);

        $ids = [];
        foreach (apiRequest('GET', 'members', ['token' => apiToken('admin')])['body'] as $m) {
            if (in_array($m['member_number'] ?? '', $numbers, true)) {
                $ids[$m['member_number']] = (int) $m['member_id'];
            }
        }
        assertSame(2, count($ids), 'importierte Mitglieder nicht gefunden');
        $exp = [$g, $r]; sort($exp);
        assertSame($exp, spGroupIdsOf($ids[$numbers[0]]), 'Import ergaenzt die einzige Gruppe');
        assertSame([$r2], spGroupIdsOf($ids[$numbers[1]]), 'Import ergaenzt bei mehreren Gruppen nichts');
        assertSame([['member_id' => $ids[$numbers[0]], 'group_id' => $g]], $res['body']['added_groups'] ?? null);
        assertSame([['member_id' => $ids[$numbers[1]], 'subgroup_id' => $r2]], $res['body']['group_warnings'] ?? null);
    } finally {
        foreach (apiRequest('GET', 'members', ['token' => apiToken('admin')])['body'] ?? [] as $m) {
            if (in_array($m['member_number'] ?? '', $numbers, true)) {
                spDelete('members', (int) $m['member_id']);
            }
        }
        $logs = apiRequest('GET', 'import_logs', ['token' => apiToken('admin')])['body'] ?? [];
        foreach ($logs['logs'] ?? $logs as $log) {
            if (($log['filename'] ?? '') === 'sp_import.csv') {
                spDelete('import_logs', (int) $log['log_id']);
            }
        }
        foreach (array_reverse($groups) as $id) { spDelete('member_groups', $id); }
    }
});

test('Gruppen eines Registers: Selbstbezug, Duplikate, Ziffern-Strings, Rechte, unbekannte ID', function () {
    $s   = uniqid();
    $ids = [];
    try {
        $g = $ids[] = (int) spCreate('member_groups', ['group_name' => "SP G {$s}"])['id'];
        $r = $ids[] = (int) spCreate('member_groups', ['group_name' => "SP R {$s}", 'is_subgroup' => true])['id'];

        // Selbstbezug: 400, nichts gespeichert
        $bad = spPutGroup($r, "SP R {$s}", ['is_subgroup' => true, 'parent_group_ids' => [$r]]);
        assertStatus(400, $bad, 'Selbstbezug muss abgewiesen werden');
        assertSame([], spGroup($r)['parent_group_ids'] ?? null);

        // Selbstbezug beim Umwandeln: die Gruppe ist in der Datenbank noch gewoehnlich,
        // nur der eigene Pruefschritt faengt das ab
        $x = $ids[] = (int) spCreate('member_groups', ['group_name' => "SP X {$s}"])['id'];
        $bad = spPutGroup($x, "SP X {$s}", ['is_subgroup' => true, 'parent_group_ids' => [$x]]);
        assertStatus(400, $bad, 'Selbstbezug beim Umwandeln muss abgewiesen werden');
        assertSame(0, (int) spGroup($x)['is_subgroup'], 'Gruppe bleibt gewoehnlich');

        // Duplikate werden zusammengefasst
        $res = spPutGroup($r, "SP R {$s}", ['is_subgroup' => true, 'parent_group_ids' => [$g, $g]]);
        assertStatus(200, $res);
        assertSame([$g], spGroup($r)['parent_group_ids'] ?? null, 'Duplikate ergeben eine Zuordnung');

        // Ziffern-Strings werden angenommen
        $res = spPutGroup($r, "SP R {$s}", ['is_subgroup' => true, 'parent_group_ids' => [(string) $g]]);
        assertStatus(200, $res);
        assertSame([$g], spGroup($r)['parent_group_ids'] ?? null, 'Ziffern-String gilt als ID');

        // Manager darf keine Gruppe anlegen
        $res = apiRequest('POST', 'member_groups', ['token' => apiToken('manager'),
            'body' => ['group_name' => "SP M {$s}"]]);
        assertStatus(403, $res, 'POST member_groups ist Admin-Sache');

        // PUT auf unbekannte ID: 404
        $res = spPutGroup(999999999, "SP U {$s}", []);
        assertStatus(404, $res, 'unbekannte Gruppe');
    } finally {
        foreach (array_reverse($ids) as $id) { spDelete('member_groups', $id); }
    }
});
