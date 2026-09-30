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

declare(strict_types=1);

/**
 * Statische Gegenproben an den Tabellen des Dashboards.
 *
 * Die Aktionsspalte klebt beim waagerechten Rollen am rechten Rand -- seit
 * OI-10 in der Zeiterfassung, seit OI-92 in jeder Tabelle mit .actions-cell.
 * Ob sie im Browser tatsaechlich stehen bleibt, prueft nur ein Blick; hier
 * gesichert ist, dass die Regel allgemein gilt, den Kopf nur bei vorhandenen
 * Aktionszellen erfasst und in den Sonderfaellen (inaktive Zeilen, schmales
 * Polster) deckend bleibt.
 */

$repoRoot = dirname(__DIR__, 2);

/**
 * Zerlegt CSS in [Selektor, Rumpf]-Paare. Verschachtelte @media-Bloecke
 * liefern ihre inneren Regeln mit; der @media-Kopf selbst faellt heraus, weil
 * er keinen eigenen Rumpf ohne geschweifte Klammern hat.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function tablesCssRules(string $css): array
{
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);

    return array_map(static fn(array $r): array => [trim($r[1]), $r[2]], $m);
}

/** Erste Regel, deren Selektorliste genau diesen Selektor enthaelt. */
function tablesCssRule(array $rules, string $selector): ?string
{
    foreach ($rules as [$selectors, $body]) {
        $list = array_map('trim', explode(',', $selectors));
        if (in_array($selector, $list, true)) {
            return $body;
        }
    }

    return null;
}

$tablesCss = (string) sourceCode($repoRoot . '/public/css/components/tables.css');
$tableRules = tablesCssRules($tablesCss);

test('Tabellen: die Aktionsspalte klebt in jeder Tabelle, nicht nur in der Zeiterfassung (OI-92)', function () use ($tableRules) {
    $cell = tablesCssRule($tableRules, 'td.actions-cell');
    assertTrue($cell !== null, 'tables.css haelt td.actions-cell nicht allgemein fest');
    assertTrue(preg_match('/position:\s*sticky;/', $cell) === 1, 'Aktionsspalte ist nicht sticky');
    assertTrue(preg_match('/right:\s*0;/', $cell) === 1, 'Aktionsspalte haengt nicht am rechten Rand');
    assertTrue(preg_match('/background:\s*var\(--bg-white\);/', $cell) === 1,
        'Aktionsspalte hat keinen deckenden Hintergrund -- die Nachbarspalten scheinen durch');

    $head = tablesCssRule($tableRules, 'table:has(td.actions-cell) > thead th:last-child');
    assertSame($cell, $head, 'Kopf und Zellen der Aktionsspalte werden nicht gemeinsam festgehalten');
});

test('Tabellen: ohne Aktionszellen klebt auch keine Kopfzelle (Rolle user)', function () use ($repoRoot) {
    // updateTableHeaders() und records.js lassen die Spalte "Aktionen" fuer
    // user weg. Ein blosses th:last-child hielte dann die letzte Datenspalte
    // fest, deren Zellen aber nicht.
    foreach (projectFiles($repoRoot . '/public/css', 'css') as $file) {
        foreach (tablesCssRules((string) sourceCode($file)) as [$selectors, $body]) {
            if (preg_match('/position:\s*sticky/', $body) !== 1) {
                continue;
            }
            foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                if (strpos($selector, 'th:last-child') !== false) {
                    assertTrue(strpos($selector, ':has(td.actions-cell)') !== false,
                        basename($file) . ": \"{$selector}\" klebt ohne Bindung an Aktionszellen");
                }
            }
        }
    }
});

test('Tabellen: jedes Modul mit Aktionsknoepfen setzt sie in eine .actions-cell', function () use ($repoRoot) {
    $modules = 0;
    foreach (projectFiles($repoRoot . '/public/js/modules', 'js') as $file) {
        $js = (string) sourceCode($file);
        if (strpos($js, 'action-btn') === false) {
            continue;
        }
        $modules++;
        assertTrue(strpos($js, '<td class="actions-cell">') !== false,
            basename($file) . ' rendert Aktionsknoepfe, aber keine <td class="actions-cell"> -- die Spalte klebt dort nicht');
    }
    assertTrue($modules >= 10, "nur {$modules} Module mit Aktionsknoepfen gefunden -- Suche greift nicht");
});

test('Tabellen: inaktive Zeilen machen die klebende Spalte nicht durchscheinend', function () use ($tableRules) {
    // tr.row-inactive td setzt opacity auf die ganze Zelle samt Hintergrund;
    // die klebende Zelle liesse dann den Text der Nachbarspalten durch.
    $inactive = tablesCssRule($tableRules, 'tr.row-inactive td.actions-cell');
    assertTrue($inactive !== null, 'keine Ausnahme fuer die Aktionsspalte in inaktiven Zeilen');
    assertTrue(preg_match('/opacity:\s*1;/', $inactive) === 1,
        'Aktionsspalte in inaktiven Zeilen bleibt durchscheinend');

    $hover = tablesCssRule($tableRules, 'tr.row-inactive:hover td.actions-cell');
    assertTrue($hover !== null && preg_match('/background-color:/', $hover) === 1,
        'Aktionsspalte in inaktiven Zeilen hat beim Ueberfahren keinen eigenen Hintergrund');
    assertTrue(preg_match('/opacity:/', $hover) !== 1,
        'Hover-Regel der inaktiven Aktionszelle setzt wieder opacity auf die Zelle');
});

test('Tabellen: die Abdeckung rechts neben der Spalte folgt dem Polster', function () use ($repoRoot, $tableRules) {
    $after = tablesCssRule($tableRules, 'td.actions-cell::after');
    assertTrue($after !== null && preg_match('/width:\s*var\(--table-pad\b/', $after) === 1,
        'Abdeckung hat eine feste Breite -- bei schmalerem Polster ragt sie ueber den Rand');

    $base = tablesCssRule($tableRules, '.data-table');
    assertTrue($base !== null && preg_match('/padding:\s*var\(--table-pad\);/', $base) === 1,
        '.data-table nimmt sein Polster nicht aus --table-pad');

    // Wer das Polster anderswo direkt setzt, laesst Abdeckung und Polster
    // auseinanderlaufen.
    foreach (projectFiles($repoRoot . '/public/css', 'css') as $file) {
        foreach (tablesCssRules((string) sourceCode($file)) as [$selectors, $body]) {
            foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                if (preg_match('/\.data-table$/', $selector) === 1 && $body !== $base) {
                    assertTrue(preg_match('/(^|[;\s])padding\s*:/', $body) !== 1,
                        basename($file) . ": \"{$selector}\" setzt padding direkt statt --table-pad");
                }
            }
        }
    }
});
