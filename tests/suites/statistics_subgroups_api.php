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
 * Statistik ueber die gemeinsame Soll-Menge: Aktivitaet am Termindatum und
 * Untergruppen, die ueber ihre Mitglieder rechnen
 * (Spec 2026-10-01-register-statistik-besetzung, Abschnitte 3, 4 und 6.1).
 *
 * Eine eigene Welt im Vorjahr -- alle Termine sind dann begonnen, unabhaengig
 * vom Testtag. Gefiltert wird immer auf eine Gruppe der Welt oder ein
 * Mitglied der Welt; der Bestand zaehlt nie mit.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function sgCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");
    $id = (int) ($res['body']['id'] ?? 0);
    assertTrue($id > 0, "{$resource} lieferte keine brauchbare ID: " . $res['raw']);

    return $id;
}

/** Die ID gehoert in 'query' -- ohne sie meldet DELETE Erfolg und loescht nichts (OI-56). */
function sgDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

function sgWorld(array &$w): void
{
    $y = (int) date('Y') - 1;
    $s = uniqid();
    $w = ['year' => $y, 'appointments' => [], 'members' => [], 'groups' => [], 'types' => []];

    $w['groups']['G']  = sgCreate('member_groups', ['group_name' => "SG Gesamt {$s}"]);
    $w['groups']['R']  = sgCreate('member_groups', ['group_name' => "SG Reg {$s}",  'is_subgroup' => true, 'sort_order' => 1]);
    $w['groups']['R2'] = sgCreate('member_groups', ['group_name' => "SG Reg2 {$s}", 'is_subgroup' => true, 'sort_order' => 0]);

    // Registerprobe zuerst anlegen: Ihre ID ist kleiner, die Namensfolge (TG, TR)
    // unterscheidet sich damit von der ID-Folge.
    $w['types']['TR'] = sgCreate('appointment_types', ['type_name' => "SG Registerprobe {$s}", 'is_default' => 0,
        'color' => '#667eea', 'group_ids' => [$w['groups']['R']]]);
    $w['types']['TG'] = sgCreate('appointment_types', ['type_name' => "SG Gesamtprobe {$s}", 'is_default' => 0,
        'color' => '#667eea', 'group_ids' => [$w['groups']['G']]]);

    $members = [
        'A' => ['G', 'R'],
        'B' => ['R'],
        'C' => ['G', 'R', 'R2'],
        'D' => ['G', 'R'],
        'E' => ['G'],
    ];
    foreach ($members as $key => $groups) {
        $w['members'][$key] = sgCreate('members', [
            'name' => 'Sg', 'surname' => "{$key} {$s}", 'active' => 1,
            'group_ids' => array_map(static fn ($g) => $w['groups'][$g], $groups),
        ]);
    }

    assertStatus(201, apiRequest('POST', 'membership_dates', ['token' => apiToken('admin'), 'body' => [
        'member_id' => $w['members']['D'], 'start_date' => "{$y}-05-01", 'end_date' => null]]));
    assertStatus(201, apiRequest('POST', 'membership_dates', ['token' => apiToken('admin'), 'body' => [
        'member_id' => $w['members']['E'], 'start_date' => ($y - 1) . '-01-01', 'end_date' => "{$y}-07-01"]]));

    $plan = [
        'G1' => ['TG', "{$y}-03-01", '10:00:00'],
        'G2' => ['TG', "{$y}-06-01", '10:00:00'],
        'G3' => ['TG', "{$y}-09-01", '10:00:00'],
        'R1' => ['TR', "{$y}-04-01", '19:00:00'],
        'R2' => ['TR', "{$y}-08-01", '19:00:00'],
    ];
    foreach ($plan as $key => [$type, $date, $time]) {
        $w['appointments'][$key] = sgCreate('appointments', [
            'title' => "SG {$key}", 'date' => $date, 'start_time' => $time, 'type_id' => $w['types'][$type],
        ]);
    }

    $records = [
        ['A', 'G1', 'present'], ['A', 'G2', 'present'], ['A', 'G3', 'present'],
        ['A', 'R1', 'present'], ['A', 'R2', 'present'],
        ['B', 'R1', 'present'],
        ['C', 'G1', 'present'], ['C', 'G3', 'present'], ['C', 'R1', 'present'], ['C', 'G2', 'excused'],
        ['D', 'G2', 'present'],
        ['E', 'G1', 'present'],
    ];
    foreach ($records as [$m, $a, $status]) {
        assertStatus(201, apiRequest('POST', 'records', ['token' => apiToken('admin'), 'body' => [
            'member_id' => $w['members'][$m], 'appointment_id' => $w['appointments'][$a], 'status' => $status,
        ]]), "Eintrag {$m}/{$a}");
    }

}

/** Termine zuerst -- der Handler raeumt ihre Records mit weg. */
function sgDropWorld(array $w): void
{
    foreach ($w['appointments'] ?? [] as $id) { sgDelete('appointments', $id); }
    foreach ($w['members'] ?? [] as $id)      { sgDelete('members', $id); }
    foreach ($w['types'] ?? [] as $id)        { sgDelete('appointment_types', $id); }
    foreach ($w['groups'] ?? [] as $id)       { sgDelete('member_groups', $id); }
}

function sgStats(array $w, array $query, string $role = 'admin'): array
{
    $res = apiRequest('GET', 'statistics', ['token' => apiToken($role), 'query' => ['year' => $w['year']] + $query]);
    assertStatus(200, $res);

    return $res['body'];
}

/** Tabelle einer Gruppe aus der Antwort, oder null. */
function sgTable(array $stats, int $groupId): ?array
{
    foreach ($stats['statistics'] as $group) {
        if ((int) $group['group_id'] === $groupId) {
            return $group;
        }
    }

    return null;
}

/** Zeile eines Mitglieds als [Termine, anwesend, entschuldigt, unentschuldigt]. */
function sgRow(array $table, int $memberId): ?array
{
    foreach ($table['members'] as $m) {
        if ((int) $m['member_id'] === $memberId) {
            return [(int) $m['total_appointments'], (int) $m['attended'],
                    (int) $m['excused'], (int) $m['unexcused_absences']];
        }
    }

    return null;
}

/** Eine Welt fuer alle Tests -- der Aufbau kostet rund 30 Anfragen. */
$sgWorld = [];

test('Statistik-Welt laesst sich anlegen', function () use (&$sgWorld) {
    sgWorld($sgWorld);
});

test('Gruppentabelle zaehlt nur Termine im Aktivzeitraum (Jahresregel)', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    $g = sgTable(sgStats($w, ['group_id' => $w['groups']['G']]), $w['groups']['G']);
    assertTrue($g !== null, 'Tabelle der Gruppe G fehlt');

    assertSame([3, 3, 0, 0], sgRow($g, $w['members']['A']), 'A');
    assertSame([3, 2, 1, 0], sgRow($g, $w['members']['C']), 'C');
    assertSame([2, 1, 0, 1], sgRow($g, $w['members']['D']), 'D tritt am 1.5. ein: nur G2 und G3');
    assertSame([2, 1, 0, 1], sgRow($g, $w['members']['E']), 'E tritt am 1.7. aus: nur G1 und G2');
    assertSame(null, sgRow($g, $w['members']['B']), 'B steht nicht in G');
});

test('Kopfzahlen der Gruppe folgen der Jahresregel', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    $s = sgStats($w, ['group_id' => $w['groups']['G']])['summary'];

    assertSame(3,  (int) $s['total_appointments']);
    assertSame(7,  (int) $s['total_present']);
    assertSame(1,  (int) $s['total_excused']);
    assertSame(2,  (int) $s['total_unexcused']);
    assertSame(70.0, (float) $s['overall_average']);
});

test('Registertabelle rechnet ueber alle Termine ihrer Mitglieder', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    $r = sgTable(sgStats($w, ['group_id' => $w['groups']['R']]), $w['groups']['R']);
    assertTrue($r !== null, 'Tabelle des Registers R fehlt');

    $typeIds = array_map(static fn ($t) => (int) $t['type_id'], $r['appointment_types']);
    assertSame([$w['types']['TG'], $w['types']['TR']], $typeIds,
        'Spalten: Gesamtprobe (ueber G) und Registerprobe (eigene), nach Name sortiert');

    assertSame([5, 5, 0, 0], sgRow($r, $w['members']['A']), 'A');
    assertSame([2, 1, 0, 1], sgRow($r, $w['members']['B']), 'B nur Registerprobe');
    assertSame([5, 3, 1, 1], sgRow($r, $w['members']['C']), 'C');
    assertSame([3, 1, 0, 2], sgRow($r, $w['members']['D']), 'D erst ab Eintritt');
    assertSame(null, sgRow($r, $w['members']['E']), 'E steht nicht in R');
    assertSame(true, $r['is_subgroup'] ?? null, 'is_subgroup fehlt oder ist falsch');
});

test('Untergruppe ohne eigene Terminart bekommt die Spalten ihrer Mitglieder', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w  = $sgWorld;
    $r2 = sgTable(sgStats($w, ['group_id' => $w['groups']['R2']]), $w['groups']['R2']);
    assertTrue($r2 !== null, 'Tabelle von R2 fehlt -- vor dem Umbau blieb sie leer');

    $typeIds = array_map(static fn ($t) => (int) $t['type_id'], $r2['appointment_types']);
    assertSame([$w['types']['TG'], $w['types']['TR']], $typeIds);
    assertSame(1, count($r2['members']), 'Nur C steht in R2');
    assertSame([5, 3, 1, 1], sgRow($r2, $w['members']['C']));
});

test('Kopfzahlen mit Untergruppen-Filter: entdoppelt ueber die Mitglieder', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    $s = sgStats($w, ['group_id' => $w['groups']['R']])['summary'];

    assertSame(5,    (int) $s['total_appointments']);
    assertSame(4,    (int) $s['total_members']);
    assertSame(10,   (int) $s['total_present']);
    assertSame(1,    (int) $s['total_excused']);
    assertSame(4,    (int) $s['total_unexcused']);
    assertSame(66.7, (float) $s['overall_average']);
});

test('Ohne Gruppenfilter aendern Untergruppen die Kopfzahlen nicht', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    // C steht in G, R und R2. Ohne Filter: jeder seiner Termine einmal.
    $s = sgStats($w, ['member_id' => $w['members']['C']])['summary'];
    assertSame(5, (int) $s['total_appointments']);
    assertSame(3, (int) $s['total_present']);
    assertSame(1, (int) $s['total_excused']);
    assertSame(1, (int) $s['total_unexcused']);
});

test('Gewoehnliche Gruppen zuerst, Untergruppen danach nach sort_order', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w   = $sgWorld;
    $stats = sgStats($w, ['member_id' => $w['members']['C']]);
    $ids = array_map(static fn ($g) => (int) $g['group_id'], $stats['statistics']);

    $posG  = array_search($w['groups']['G'], $ids, true);
    $posR  = array_search($w['groups']['R'], $ids, true);
    $posR2 = array_search($w['groups']['R2'], $ids, true);
    assertTrue($posG !== false && $posR !== false && $posR2 !== false, 'Tabellen fehlen: ' . json_encode($ids));
    assertTrue($posG < $posR2 && $posR2 < $posR, 'Reihenfolge G, R2 (sort 0), R (sort 1) erwartet: ' . json_encode($ids));

    foreach ($stats['statistics'] as $g) {
        assertTrue(array_key_exists('is_subgroup', $g), 'is_subgroup fehlt bei Gruppe ' . $g['group_id']);
    }
});

test('Mitglied sieht in seiner Registertabelle nur sich, fremdes Register ist gesperrt', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w        = $sgWorld;
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Testkonto user ohne Mitglied');

    $res    = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    $vorher = array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups']);

    try {
        assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['group_ids' => array_merge($vorher, [$w['groups']['R']])]]));

        $r = sgTable(sgStats($w, ['group_id' => $w['groups']['R']], 'user'), $w['groups']['R']);
        assertTrue($r !== null, 'Registertabelle fehlt fuer das Mitglied');
        assertSame(1, count($r['members']), 'Nur die eigene Zeile');
        assertSame($memberId, (int) $r['members'][0]['member_id']);

        $fremd = apiRequest('GET', 'statistics', ['token' => apiToken('user'),
            'query' => ['year' => $w['year'], 'group_id' => $w['groups']['R2']]]);
        assertStatus(403, $fremd, 'Fremdes Register muss gesperrt sein');
    } finally {
        apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['group_ids' => $vorher]]);
    }
});

test('Anwesenheitsbericht listet nur Termine im Aktivzeitraum', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w   = $sgWorld;
    $res = apiRequest('GET', 'statistics_report', ['token' => apiToken('admin'), 'query' => [
        'year' => $w['year'], 'member_id' => $w['members']['D']]]);
    assertStatus(200, $res);

    $html = $res['raw'];
    assertTrue(str_contains($html, 'SG G2'), 'G2 liegt im Aktivzeitraum von D');
    assertTrue(str_contains($html, 'SG G3'), 'G3 liegt im Aktivzeitraum von D');
    assertTrue(!str_contains($html, 'SG G1'), 'G1 liegt vor dem Eintritt von D');
    assertTrue(!str_contains($html, 'SG R1'), 'R1 liegt vor dem Eintritt von D');
});

test('Anwesenheitsbericht listet einen Termin einmal, auch wenn mehrere Gruppen ihn erwarten', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    // Terminart an zwei Gruppen, in denen C steht: die Soll-Menge enthaelt (C, Termin) doppelt.
    $typeId = sgCreate('appointment_types', ['type_name' => 'SG Doppelprobe ' . uniqid(), 'is_default' => 0,
        'color' => '#667eea', 'group_ids' => [$w['groups']['G'], $w['groups']['R2']]]);
    $apptId = sgCreate('appointments', ['title' => 'SG DOPPEL', 'date' => "{$w['year']}-10-01",
        'start_time' => '19:00:00', 'type_id' => $typeId]);
    try {
        $res = apiRequest('GET', 'statistics_report', ['token' => apiToken('admin'), 'query' => [
            'year' => $w['year'], 'member_id' => $w['members']['C']]]);
        assertStatus(200, $res);
        assertSame(1, substr_count($res['raw'], 'SG DOPPEL'), 'Der Termin muss genau einmal im Bericht stehen');
    } finally {
        sgDelete('appointments', $apptId);
        sgDelete('appointment_types', $typeId);
    }
});

test('Statistik-Welt wird aufgeraeumt', function () use (&$sgWorld) {
    if (!empty($sgWorld)) {
        sgDropWorld($sgWorld);
    }
});
