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

    $w['types']['TG'] = sgCreate('appointment_types', ['type_name' => "SG Gesamtprobe {$s}", 'is_default' => 0,
        'color' => '#667eea', 'group_ids' => [$w['groups']['G']]]);
    $w['types']['TR'] = sgCreate('appointment_types', ['type_name' => "SG Registerprobe {$s}", 'is_default' => 0,
        'color' => '#667eea', 'group_ids' => [$w['groups']['R']]]);

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

test('Statistik-Welt wird aufgeraeumt', function () use (&$sgWorld) {
    if (!empty($sgWorld)) {
        sgDropWorld($sgWorld);
    }
});
