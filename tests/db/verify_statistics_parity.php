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
 * Gleichheitspruefung der Statistik vor und nach dem Umbau auf die
 * gemeinsame Soll-Menge (Spec 2026-10-01-register-statistik-besetzung, 6.2).
 *
 *   php tests/db/verify_statistics_parity.php snapshot <datei.json>
 *   php tests/db/verify_statistics_parity.php compare  <vorher.json>
 *   php tests/db/verify_statistics_parity.php timing
 *
 * snapshot: Statistikantwort je Jahr und je Gruppe (plus ohne Filter) als
 * Admin, ueber HTTP gegen tests/config.php.
 * compare: holt dieselben Antworten neu und vergleicht Zeile fuer Zeile.
 * Erlaubt sind nur Abweichungen bei Mitgliedern mit einem Mitgliedschafts-
 * zeitraum, der im betreffenden Jahr beginnt oder endet -- genau sie
 * betrifft die Korrektur der Jahresregel. Kopf- und Gruppenzahlen werden
 * ausgegeben, aber nicht bewertet: Sie aendern sich mit, wenn ein solches
 * Mitglied darin steckt. Neue Gruppentabellen (Untergruppen) werden
 * aufgelistet. Rueckgabewert 1 bei jeder unerwarteten Abweichung.
 * timing: Median aus fuenf Laeufen je Anfrage, in Millisekunden.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '';

function spFetch(array $query): array
{
    $res = apiRequest('GET', 'statistics', ['token' => apiToken('admin'), 'query' => $query]);
    if ($res['status'] !== 200) {
        throw new RuntimeException('statistics ' . json_encode($query) . " -> HTTP {$res['status']}");
    }

    return $res['body'];
}

function spYears(): array
{
    $res = apiRequest('GET', 'available_years', ['token' => apiToken('admin')]);
    $out = [];
    foreach ((array) $res['body'] as $y) {
        $out[] = (int) (is_array($y) ? ($y['year'] ?? 0) : $y);
    }

    return array_values(array_filter($out));
}

function spGroups(): array
{
    $res = apiRequest('GET', 'member_groups', ['token' => apiToken('admin')]);

    return array_map(static fn ($g) => (int) $g['group_id'], (array) $res['body']);
}

function spCollect(): array
{
    $out = [];
    foreach (spYears() as $year) {
        $out["{$year}|all"] = spFetch(['year' => $year]);
        foreach (spGroups() as $groupId) {
            $out["{$year}|{$groupId}"] = spFetch(['year' => $year, 'group_id' => $groupId]);
        }
    }

    return $out;
}

/** Mitglieder mit einem Zeitraum, der im Jahr beginnt oder endet. */
function spBoundaryMembers(int $year): array
{
    static $rows = null; // einmal laden, nicht je Schluessel
    $rows ??= (array) apiRequest('GET', 'membership_dates', ['token' => apiToken('admin')])['body'];
    $ids = [];
    foreach ($rows as $row) {
        $start = (string) ($row['start_date'] ?? '');
        $end   = (string) ($row['end_date'] ?? '');
        if (substr($start, 0, 4) === (string) $year || substr($end, 0, 4) === (string) $year) {
            $ids[(int) $row['member_id']] = true;
        }
    }

    return $ids;
}

/** Tabellen einer Antwort als group_id => [member_id => Zeile], dazu die Spalten. */
function spTables(array $stats): array
{
    $out = [];
    foreach ($stats['statistics'] ?? [] as $group) {
        $rows = [];
        foreach ($group['members'] as $m) {
            $rows[(int) $m['member_id']] = $m;
        }
        $out[(int) $group['group_id']] = [
            'types' => array_map(static fn ($t) => (int) $t['type_id'], $group['appointment_types']),
            'rows'  => $rows,
        ];
    }

    return $out;
}

if ($mode === 'snapshot') {
    if ($file === '') { fwrite(STDERR, "Zieldatei fehlt\n"); exit(2); }
    file_put_contents($file, json_encode(spCollect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Momentaufnahme geschrieben: {$file}\n";
    exit(0);
}

if ($mode === 'compare') {
    $before = json_decode((string) file_get_contents($file), true);
    if (!is_array($before)) { fwrite(STDERR, "Momentaufnahme unlesbar: {$file}\n"); exit(2); }

    $unexpected = 0;
    foreach ($before as $key => $old) {
        [$year, $scope] = explode('|', $key);
        $new      = spFetch($scope === 'all' ? ['year' => $year] : ['year' => $year, 'group_id' => $scope]);
        $boundary = spBoundaryMembers((int) $year);

        if ($old['summary'] != $new['summary']) {
            echo "[{$key}] Kopfzahlen: " . json_encode($old['summary']) . ' -> ' . json_encode($new['summary']) . "\n";
        }

        $oldTables = spTables($old);
        $newTables = spTables($new);

        foreach ($newTables as $gid => $table) {
            if (!isset($oldTables[$gid])) {
                echo "[{$key}] neue Tabelle: Gruppe {$gid} (" . count($table['rows']) . " Zeilen)\n";
            }
        }

        foreach ($oldTables as $gid => $table) {
            if (!isset($newTables[$gid])) {
                echo "[{$key}] UNERWARTET: Tabelle Gruppe {$gid} fehlt\n";
                $unexpected++;
                continue;
            }
            if ($table['types'] !== $newTables[$gid]['types']) {
                echo "[{$key}] UNERWARTET: Spalten Gruppe {$gid}: " . json_encode($table['types'])
                    . ' -> ' . json_encode($newTables[$gid]['types']) . "\n";
                $unexpected++;
            }
            $ids = array_unique(array_merge(array_keys($table['rows']), array_keys($newTables[$gid]['rows'])));
            foreach ($ids as $mid) {
                $a = $table['rows'][$mid] ?? null;
                $b = $newTables[$gid]['rows'][$mid] ?? null;
                if ($a == $b) {
                    continue;
                }
                $label = isset($boundary[$mid]) ? 'erlaubt (Ein-/Austritt)' : 'UNERWARTET';
                if (!isset($boundary[$mid])) {
                    $unexpected++;
                }
                echo "[{$key}] {$label}: Gruppe {$gid}, Mitglied {$mid}: "
                    . json_encode($a === null ? null : [$a['total_appointments'], $a['attended'], $a['excused'], $a['unexcused_absences']])
                    . ' -> '
                    . json_encode($b === null ? null : [$b['total_appointments'], $b['attended'], $b['excused'], $b['unexcused_absences']])
                    . "\n";
            }
        }
    }

    echo $unexpected === 0 ? "Keine unerwartete Abweichung.\n" : "{$unexpected} unerwartete Abweichung(en).\n";
    exit($unexpected === 0 ? 0 : 1);
}

if ($mode === 'timing') {
    $year    = (int) date('Y');
    $cases   = [
        'statistics ohne Filter'    => ['statistics', ['year' => $year]],
        'statistics Gruppe 1'       => ['statistics', ['year' => $year, 'group_id' => 1]],
        'statistics Untergruppe'    => ['statistics', ['year' => $year, 'group_id' => (int) ($argv[2] ?? 5722)]],
        'appointments+attendance'   => ['appointments', ['year' => $year, 'include' => 'attendance']],
    ];
    foreach ($cases as $label => [$resource, $query]) {
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $t0 = microtime(true);
            $res = apiRequest('GET', $resource, ['token' => apiToken('admin'), 'query' => $query]);
            $times[] = (microtime(true) - $t0) * 1000;
            if ($res['status'] !== 200) { fwrite(STDERR, "{$label}: HTTP {$res['status']}\n"); exit(1); }
        }
        sort($times);
        printf("%-28s %7.1f ms\n", $label, $times[2]);
    }
    exit(0);
}

fwrite(STDERR, "Aufruf: snapshot <datei> | compare <datei> | timing [untergruppen-id]\n");
exit(2);
