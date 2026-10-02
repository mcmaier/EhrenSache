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
 * Register, die ueber ihre Gruppen P(S) und eigene Termine rechnen
 * (Spec 2026-10-01-register-statistik-besetzung, Abschnitt 3;
 * Spec 2026-10-02-register-gruppe-besetzung, Abschnitt 5.1).
 *
 * Welt: gewoehnliche Gruppen G, J, V; Register R (Gruppen G und J),
 * R2 (ohne Gruppe) und R3 (Gruppe G).
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
    $w = ['year' => $y, 'appointments' => [], 'members' => [], 'groups' => [], 'types' => [],
          'group_names' => [], 'member_responses' => []];

    $groupPlan = [
        'G'  => ["SG Gesamt {$s}",   []],
        'J'  => ["SG Jugend {$s}",   []],
        'V'  => ["SG Vorstand {$s}", []],
        'R'  => ["SG Reg {$s}",      ['is_subgroup' => true, 'sort_order' => 1, 'parents' => ['G', 'J']]],
        'R2' => ["SG Reg2 {$s}",     ['is_subgroup' => true, 'sort_order' => 0, 'parents' => []]],
        'R3' => ["SG Reg3 {$s}",     ['is_subgroup' => true, 'sort_order' => 2, 'parents' => ['G']]],
    ];
    foreach ($groupPlan as $key => [$name, $opts]) {
        $body = ['group_name' => $name];
        if ($opts !== []) {
            $body['is_subgroup']      = true;
            $body['sort_order']       = $opts['sort_order'];
            $body['parent_group_ids'] = array_map(static fn ($p) => $w['groups'][$p], $opts['parents']);
        }
        $w['groups'][$key]      = sgCreate('member_groups', $body);
        $w['group_names'][$key] = $name;
    }

    // Registerprobe zuerst anlegen: Ihre ID ist kleiner, die Namensfolge (TG, TJ, TR)
    // unterscheidet sich damit von der ID-Folge.
    $typePlan = [
        'TR' => ["SG Registerprobe {$s}",    'R'],
        'TG' => ["SG Gesamtprobe {$s}",      'G'],
        'TJ' => ["SG Jugendprobe {$s}",      'J'],
        'TV' => ["SG Vorstandssitzung {$s}", 'V'],
        'T3' => ["SG Registerprobe3 {$s}",   'R3'],
    ];
    foreach ($typePlan as $key => [$name, $group]) {
        $w['types'][$key] = sgCreate('appointment_types', ['type_name' => $name, 'is_default' => 0,
            'color' => '#667eea', 'group_ids' => [$w['groups'][$group]]]);
    }

    $members = [
        'A' => ['G', 'R'],
        'B' => ['R'],               // R hat zwei Gruppen -> Warnung, keine Ergaenzung
        'C' => ['G', 'R', 'R2', 'R3', 'V'],
        'D' => ['G', 'R'],
        'E' => ['G'],
        'K' => ['J', 'R'],
    ];
    foreach ($members as $key => $groups) {
        $res = apiRequest('POST', 'members', ['token' => apiToken('admin'), 'body' => [
            'name' => 'Sg', 'surname' => "{$key} {$s}", 'active' => 1,
            'group_ids' => array_map(static fn ($g) => $w['groups'][$g], $groups),
        ]]);
        assertStatus(201, $res, "Mitglied {$key} konnte nicht angelegt werden");
        $id = (int) ($res['body']['id'] ?? 0);
        assertTrue($id > 0, "Mitglied {$key} lieferte keine brauchbare ID: " . $res['raw']);
        $w['members'][$key]          = $id;
        $w['member_responses'][$key] = $res['body'];
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
        'J1' => ['TJ', "{$y}-10-01", '17:00:00'],
        'V1' => ['TV', "{$y}-05-15", '19:00:00'],
        'X1' => ['T3', "{$y}-07-15", '19:00:00'],
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
        ['C', 'G1', 'present'], ['C', 'G3', 'present'], ['C', 'R1', 'present'],
        ['C', 'V1', 'present'], ['C', 'X1', 'present'], ['C', 'G2', 'excused'],
        ['D', 'G2', 'present'],
        ['E', 'G1', 'present'],
        ['K', 'J1', 'present'], ['K', 'R1', 'present'],
    ];
    foreach ($records as [$m, $a, $status]) {
        $res = apiRequest('POST', 'records', ['token' => apiToken('admin'), 'body' => [
            'member_id' => $w['members'][$m], 'appointment_id' => $w['appointments'][$a], 'status' => $status,
        ]]);
        assertStatus(201, $res, "Eintrag {$m}/{$a}");
        $w['records']["{$m}/{$a}"] = (int) $res['body']['id'];
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

function sgSettingValue(string $key): ?string
{
    $res = apiRequest('GET', 'settings', ['token' => apiToken('admin')]);
    foreach ($res['body']['settings'] ?? [] as $setting) {
        if ($setting['setting_key'] === $key) {
            return (string) $setting['setting_value'];
        }
    }

    return null;
}

function sgSetSetting(string $key, string $value): void
{
    assertStatus(200, apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]), "Einstellung {$key} konnte nicht gesetzt werden");
}

/** Fuehrt $fn mit den Einstellungen aus und stellt danach den Vorzustand her. */
function sgWithSettings(array $settings, callable $fn): void
{
    $vorher = [];
    foreach ($settings as $key => $value) {
        $vorher[$key] = sgSettingValue($key) ?? '0';
        sgSetSetting($key, $value);
    }

    try {
        $fn();
    } finally {
        foreach ($vorher as $key => $value) {
            sgSetSetting($key, $value);
        }
    }
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

/** Spalten einer Tabelle als Liste von type_ids. */
function sgTypeIds(array $table): array
{
    return array_map(static fn ($t) => (int) $t['type_id'], $table['appointment_types']);
}

/** Eine Welt fuer alle Tests -- der Aufbau kostet rund 40 Anfragen. */
$sgWorld = [];

test('Statistik-Welt laesst sich anlegen', function () use (&$sgWorld) {
    sgWorld($sgWorld);
});

test('Register mit zwei Gruppen: Mitglied ohne beide wird gewarnt, nicht ergaenzt', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    $b = $w['member_responses']['B'];

    assertSame([['member_id' => $w['members']['B'], 'subgroup_id' => $w['groups']['R']]],
        $b['group_warnings'] ?? null, 'B steht in keiner der Gruppen von R: Warnung erwartet');
    assertSame([], $b['added_groups'] ?? null, 'Bei zwei Gruppen wird keine ergaenzt');

    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $w['members']['B']]]);
    assertStatus(200, $res);
    $groups = array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups']);
    assertSame([$w['groups']['R']], $groups, 'B steht nur in R');

    foreach (['A', 'C', 'D', 'K'] as $key) {
        assertSame([], $w['member_responses'][$key]['group_warnings'] ?? null, "{$key} steht in einer Gruppe seiner Register");
    }
});

test('Gruppentabelle zaehlt nur Termine im Aktivzeitraum (Jahresregel)', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    $g = sgTable(sgStats($w, ['group_id' => $w['groups']['G']]), $w['groups']['G']);
    assertTrue($g !== null, 'Tabelle der Gruppe G fehlt');

    assertSame([$w['types']['TG']], sgTypeIds($g), 'Spalten von G: nur die Gesamtprobe');
    assertSame([3, 3, 0, 0], sgRow($g, $w['members']['A']), 'A');
    assertSame([3, 2, 1, 0], sgRow($g, $w['members']['C']), 'C');
    assertSame([2, 1, 0, 1], sgRow($g, $w['members']['D']), 'D tritt am 1.5. ein: nur G2 und G3');
    assertSame([2, 1, 0, 1], sgRow($g, $w['members']['E']), 'E tritt am 1.7. aus: nur G1 und G2');
    assertSame(null, sgRow($g, $w['members']['B']), 'B steht nicht in G -- die Regel hat ihn nicht ergaenzt');
    assertSame(null, sgRow($g, $w['members']['K']), 'K steht nicht in G');
    assertSame(false, $g['is_subgroup'] ?? null, 'is_subgroup fehlt oder ist falsch');
    assertSame([], $g['parent_group_names'] ?? null, 'Gewoehnliche Gruppe: parent_group_names leer');
});

test('Kopfzahlen der Gruppe folgen der Jahresregel', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    $s = sgStats($w, ['group_id' => $w['groups']['G']])['summary'];

    assertSame(3,  (int) $s['total_appointments']);
    assertSame(7,  (int) $s['total_present']);
    assertSame(1,  (int) $s['total_excused']);
    assertSame(2,  (int) $s['total_unexcused']);
    assertSame(70.0, (float) $s['overall_average']);
});

test('Registertabelle rechnet ueber die Termine ihrer Gruppen und eigene', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    $r = sgTable(sgStats($w, ['group_id' => $w['groups']['R']]), $w['groups']['R']);
    assertTrue($r !== null, 'Tabelle des Registers R fehlt');

    assertSame([$w['types']['TG'], $w['types']['TJ'], $w['types']['TR']], sgTypeIds($r),
        'Spalten: Gesamtprobe (G), Jugendprobe (J), Registerprobe (eigene), nach Name -- '
        . 'keine Vorstandssitzung (V) und keine Registerprobe3 (R3), obwohl C dort steht');

    assertSame([5, 5, 0, 0], sgRow($r, $w['members']['A']), 'A: G1-G3, R1, R2');
    assertSame([2, 1, 0, 1], sgRow($r, $w['members']['B']), 'B steht in keiner Gruppe von R: nur R1, R2');
    assertSame([5, 3, 1, 1], sgRow($r, $w['members']['C']), 'C: ohne V1 und X1');
    assertSame([3, 1, 0, 2], sgRow($r, $w['members']['D']), 'D erst ab Eintritt: G2, G3, R2');
    assertSame([3, 2, 0, 1], sgRow($r, $w['members']['K']), 'K: J1 (ueber J), R1, R2');
    assertSame(null, sgRow($r, $w['members']['E']), 'E steht nicht in R');
    assertSame(true, $r['is_subgroup'] ?? null, 'is_subgroup fehlt oder ist falsch');
    assertSame([$w['group_names']['G'], $w['group_names']['J']], $r['parent_group_names'] ?? null,
        'parent_group_names: Gruppen von R nach Name');
});

test('Register ohne Gruppe rechnet auch in den Kopfzahlen nicht', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    // Eigene Terminart an R2 mit einem Termin, zu dem C anwesend ist: ohne die
    // Regel aus Spec 5.1 zaehlten Kopfzahlen und Kennzahlen ihn ueber R2 selbst.
    $typeId = sgCreate('appointment_types', ['type_name' => 'SG Reg2probe ' . uniqid(), 'is_default' => 0,
        'color' => '#667eea', 'group_ids' => [$w['groups']['R2']]]);
    $apptId = sgCreate('appointments', ['title' => 'SG REG2', 'date' => "{$w['year']}-10-04",
        'start_time' => '19:00:00', 'type_id' => $typeId]);
    try {
        assertStatus(201, apiRequest('POST', 'records', ['token' => apiToken('admin'), 'body' => [
            'member_id' => $w['members']['C'], 'appointment_id' => $apptId, 'status' => 'present']]));

        sgWithSettings(['punctuality_enabled' => '1', 'reliability_enabled' => '1'], function () use ($w) {
            $stats = sgStats($w, ['group_id' => $w['groups']['R2']]);
            assertSame([], $stats['statistics'], 'R2 ohne Gruppe: keine Tabelle');
            assertSame(null, $stats['warning'] ?? null, 'Kein Zugriffsproblem, also keine Warnung');
            assertSame(0, (int) $stats['summary']['total_appointments'], 'Kopfzahlen leer');
            assertSame(0, (int) $stats['summary']['total_present'], 'Kopfzahlen leer');
            assertSame(0, (int) $stats['punctuality']['total_count'], 'Puenktlichkeit leer');
            assertSame(0, (int) $stats['reliability']['total'], 'Zuverlaessigkeit leer');
        });
    } finally {
        sgDelete('appointments', $apptId);
        sgDelete('appointment_types', $typeId);
    }
});

test('Register mit einer Gruppe: Termine der Gruppe und eigene', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w  = $sgWorld;
    $r3 = sgTable(sgStats($w, ['group_id' => $w['groups']['R3']]), $w['groups']['R3']);
    assertTrue($r3 !== null, 'Tabelle des Registers R3 fehlt');

    assertSame([$w['types']['TG'], $w['types']['T3']], sgTypeIds($r3));
    assertSame(1, count($r3['members']), 'Nur C steht in R3');
    assertSame([4, 3, 1, 0], sgRow($r3, $w['members']['C']), 'C: G1-G3 und X1');
    assertSame([$w['group_names']['G']], $r3['parent_group_names'] ?? null);
});

test('Register ohne Gruppe bekommt keine Tabelle', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w     = $sgWorld;
    $stats = sgStats($w, ['group_id' => $w['groups']['R2']]);
    assertSame([], $stats['statistics'], 'R2 hat keine Gruppe: keine Registerstatistik (Spec 5.1)');
});

test('Kopfzahlen mit Registerfilter: entdoppelt ueber die Mitglieder', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    $s = sgStats($w, ['group_id' => $w['groups']['R']])['summary'];

    // Termine G1-G3, R1, R2, J1; Paare A 5 + B 2 + C 5 + D 3 + K 3 = 18
    assertSame(6,    (int) $s['total_appointments']);
    assertSame(5,    (int) $s['total_members']);
    assertSame(12,   (int) $s['total_present']);
    assertSame(1,    (int) $s['total_excused']);
    assertSame(5,    (int) $s['total_unexcused']);
    assertSame(66.7, (float) $s['overall_average']);
});

test('Ohne Gruppenfilter aendern Register die Kopfzahlen nicht', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    // C steht in G, R, R2, R3 und V. Ohne Filter: jeder seiner Termine einmal.
    $s = sgStats($w, ['member_id' => $w['members']['C']])['summary'];
    assertSame(7, (int) $s['total_appointments'], 'G1-G3, R1, R2, V1, X1');
    assertSame(5, (int) $s['total_present']);
    assertSame(1, (int) $s['total_excused']);
    assertSame(1, (int) $s['total_unexcused']);
});

test('Kopfzahlen ohne Filter: Termin, den zwei Gruppen erwarten, zaehlt einmal', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w    = $sgWorld;
    $sum0 = sgStats($w, ['member_id' => $w['members']['C']])['summary'];
    $base = (int) $sum0['total_appointments'];
    // Terminart an zwei Gruppen, in denen C steht: die Soll-Menge enthaelt (C, Termin) doppelt.
    $typeId = sgCreate('appointment_types', ['type_name' => 'SG Summenprobe ' . uniqid(), 'is_default' => 0,
        'color' => '#667eea', 'group_ids' => [$w['groups']['G'], $w['groups']['R2']]]);
    $apptId = sgCreate('appointments', ['title' => 'SG SUMME', 'date' => "{$w['year']}-10-02",
        'start_time' => '19:00:00', 'type_id' => $typeId]);
    try {
        assertStatus(201, apiRequest('POST', 'records', ['token' => apiToken('admin'), 'body' => [
            'member_id' => $w['members']['C'], 'appointment_id' => $apptId, 'status' => 'present']]));
        $sum1 = sgStats($w, ['member_id' => $w['members']['C']])['summary'];
        assertSame($base + 1, (int) $sum1['total_appointments'], 'Der Doppeltermin zaehlt in den Kopfzahlen genau einmal');
        assertSame((int) $sum0['total_present'] + 1, (int) $sum1['total_present'], 'Die Anwesenheit zaehlt genau einmal');
    } finally {
        sgDelete('appointments', $apptId);
        sgDelete('appointment_types', $typeId);
    }
});

test('Registertabelle: Termin ueber Gruppe und Register zugleich zaehlt einmal', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;
    // Terminart an G und an R: A erreicht der Termin ueber P(R) und ueber R selbst,
    // beide Wege liegen im Bereich von R.
    $typeId = sgCreate('appointment_types', ['type_name' => 'SG Wegprobe ' . uniqid(), 'is_default' => 0,
        'color' => '#667eea', 'group_ids' => [$w['groups']['G'], $w['groups']['R']]]);
    $apptId = sgCreate('appointments', ['title' => 'SG WEG', 'date' => "{$w['year']}-10-03",
        'start_time' => '19:00:00', 'type_id' => $typeId]);
    try {
        assertStatus(201, apiRequest('POST', 'records', ['token' => apiToken('admin'), 'body' => [
            'member_id' => $w['members']['A'], 'appointment_id' => $apptId, 'status' => 'present']]));
        $stats = sgStats($w, ['group_id' => $w['groups']['R']]);
        $r     = sgTable($stats, $w['groups']['R']);
        assertTrue($r !== null, 'Tabelle des Registers R fehlt');
        assertSame([6, 6, 0, 0], sgRow($r, $w['members']['A']), 'A: der Termin zaehlt genau einmal');
        assertSame([3, 1, 0, 2], sgRow($r, $w['members']['B']), 'B: nur ueber R erwartet, einmal');
        assertSame(7, (int) $stats['summary']['total_appointments'], 'Kopfzahlen: ein Termin mehr');
    } finally {
        sgDelete('appointments', $apptId);
        sgDelete('appointment_types', $typeId);
    }
});

test('Gewoehnliche Gruppen zuerst, Register danach nach sort_order, Register ohne Gruppe fehlt', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w     = $sgWorld;
    $stats = sgStats($w, ['member_id' => $w['members']['C']]);
    $ids   = array_map(static fn ($g) => (int) $g['group_id'], $stats['statistics']);

    $posG  = array_search($w['groups']['G'], $ids, true);
    $posV  = array_search($w['groups']['V'], $ids, true);
    $posR  = array_search($w['groups']['R'], $ids, true);
    $posR3 = array_search($w['groups']['R3'], $ids, true);
    assertTrue($posG !== false && $posV !== false && $posR !== false && $posR3 !== false,
        'Tabellen fehlen: ' . json_encode($ids));
    assertTrue($posG < $posV && $posV < $posR && $posR < $posR3,
        'Reihenfolge G, V, R (sort 1), R3 (sort 2) erwartet: ' . json_encode($ids));
    assertSame(false, array_search($w['groups']['R2'], $ids, true), 'R2 ohne Gruppe hat keine Tabelle');
    assertSame(false, array_search($w['groups']['J'], $ids, true), 'C steht nicht in J');

    foreach ($stats['statistics'] as $g) {
        assertTrue(array_key_exists('is_subgroup', $g), 'is_subgroup fehlt bei Gruppe ' . $g['group_id']);
        assertTrue(array_key_exists('parent_group_names', $g), 'parent_group_names fehlt bei Gruppe ' . $g['group_id']);
    }
    $r = sgTable($stats, $w['groups']['R']);
    assertSame([$w['group_names']['G'], $w['group_names']['J']], $r['parent_group_names'] ?? null);
    assertSame([5, 3, 1, 1], sgRow($r, $w['members']['C']), 'C in R auch mit Mitgliedsfilter');
});

test('Mitglied sieht in seiner Registertabelle nur sich, fremdes Register ist gesperrt', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w        = $sgWorld;
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Testkonto user ohne Mitglied');

    $res    = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    $vorher = array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups']);

    try {
        $put = apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['group_ids' => array_merge($vorher, [$w['groups']['R']])]]);
        assertStatus(200, $put);
        assertSame([['member_id' => $memberId, 'subgroup_id' => $w['groups']['R']]], $put['body']['group_warnings'] ?? null,
            'R hat zwei Gruppen, das Testmitglied steht in keiner: Warnung');
        assertSame([], $put['body']['added_groups'] ?? null, 'Keine Ergaenzung bei zwei Gruppen');

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
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w   = $sgWorld;
    $res = apiRequest('GET', 'statistics_report', ['token' => apiToken('admin'), 'query' => [
        'year' => $w['year'], 'member_id' => $w['members']['D']]]);
    assertStatus(200, $res);

    $html = $res['raw'];
    assertTrue(str_contains($html, 'SG G2'), 'G2 liegt im Aktivzeitraum von D');
    assertTrue(str_contains($html, 'SG G3'), 'G3 liegt im Aktivzeitraum von D');
    assertTrue(str_contains($html, 'SG R2'), 'R2 liegt im Aktivzeitraum von D');
    assertTrue(!str_contains($html, 'SG G1'), 'G1 liegt vor dem Eintritt von D');
    assertTrue(!str_contains($html, 'SG R1'), 'R1 liegt vor dem Eintritt von D');
});

test('Anwesenheitsbericht listet einen Termin einmal, auch wenn mehrere Gruppen ihn erwarten', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
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

test('Anwesenheitsbericht mit Gruppenfilter listet nur Termine dieser Spalten', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w   = $sgWorld;
    // Spalten = nur Gesamtprobe (Gruppe G). D wird zwar auch zur Registerprobe R2 erwartet
    // (ueber R), diese Terminart gehoert aber nicht zu den Spalten des Berichts.
    $res = apiRequest('GET', 'statistics_report', ['token' => apiToken('admin'), 'query' => [
        'year' => $w['year'], 'member_id' => $w['members']['D'], 'group_id' => $w['groups']['G']]]);
    assertStatus(200, $res);

    $html = $res['raw'];
    assertTrue(preg_match('#<td>SG G2</td>#', $html) === 1, 'G2 (Gesamtprobe) muss als Tabellenzelle im Bericht stehen');
    assertTrue(preg_match('#<td>SG R2</td>#', $html) !== 1, 'R2 gehoert zu einer Terminart ausserhalb der Spalten');
});

test('Puenktlichkeit und Zuverlaessigkeit mit Filter R ueber denselben Bereich', function () use (&$sgWorld) {
    assertTrue(!empty($sgWorld['members']['K']), 'Statistik-Welt fehlt -- Aufbau gescheitert');
    $w = $sgWorld;

    // Vier gemessene Ankuenfte im Bereich von R: drei Registerproben, eine Gesamtprobe.
    $ankuenfte = ['A/R1', 'B/R1', 'C/R1', 'D/G2'];
    foreach ($ankuenfte as $key) {
        [$m, $a] = explode('/', $key);
        $tag  = $w['year'] . ($a === 'R1' ? '-04-01 19:00:00' : '-06-01 10:00:00');
        assertStatus(200, apiRequest('PUT', 'records', [
            'token' => apiToken('admin'), 'query' => ['id' => $w['records'][$key]],
            'body'  => ['arrival_time' => $tag],
        ]), "Ankunft {$key}");
    }

    sgWithSettings(['punctuality_enabled' => '1', 'reliability_enabled' => '1'], function () use ($w) {
        $stats = sgStats($w, ['group_id' => $w['groups']['R']]);

        // 18 = A 5 + B 2 + C 5 + D 3 + K 3, jedes Paar einmal (siehe Kopfzahlen-Probe)
        assertSame(18, (int) $stats['punctuality']['total_count']);
        assertSame(4,  (int) $stats['punctuality']['measured_count'], 'Messungen gehoeren zum Bereich von R');
        assertSame(18, (int) $stats['reliability']['total']);
    });
});

test('Statistik-Welt wird aufgeraeumt', function () use (&$sgWorld) {
    if (!empty($sgWorld)) {
        sgDropWorld($sgWorld);
    }
});
