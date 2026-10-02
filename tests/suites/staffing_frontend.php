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
 * Oberflaechen zu Registerstatistik und Besetzung
 * (Spec 2026-10-01-register-statistik-besetzung, 4.4 und 5.2).
 * Gelesen wird ueber sourceCode() -- Kommentare zaehlen nicht als Treffer.
 */
declare(strict_types=1);

$stRoot = dirname(__DIR__, 2);

test('Statistik: der Hinweis "keine Auswertung fuer Untergruppen" ist entfallen', function () use ($stRoot) {
    $js = (string) sourceCode($stRoot . '/public/js/modules/statistics.js');
    assertTrue(!str_contains($js, 'noch keine Auswertung'), 'Hinweis aus 1.8.0 steht noch im Code');
    assertTrue(!str_contains($js, 'isSubgroupSelected'), 'isSubgroupSelected() ist ohne Hinweis ueberfluessig');
});

test('Statistik: Untergruppen-Tabelle traegt die Unterzeile aus is_subgroup', function () use ($stRoot) {
    $js = (string) sourceCode($stRoot . '/public/js/modules/statistics.js');
    assertTrue(str_contains($js, 'group.is_subgroup'), 'Die Unterzeile muss an is_subgroup der Antwort haengen');
    assertTrue(str_contains($js, 'statistics-group__note'), 'Klasse der Unterzeile fehlt');
    assertTrue(str_contains($js, 'alle Termine der Mitglieder'), 'Text der Unterzeile fehlt');
});

/** Rumpf einer JS-Funktion: von "function <name>(" bis vor die naechste Top-Level-Funktion. */
function stFunctionBody(string $js, string $name): string
{
    $start = strpos($js, "function {$name}(");
    assertTrue($start !== false, "{$name}() fehlt");
    $next = preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 10)
        ? $m[0][1] : strlen($js);

    return substr($js, $start, $next - $start);
}

test('Dashboard: Besetzungsblock liest staffing und rechnet nicht selbst', function () use ($stRoot) {
    $js   = (string) sourceCode($stRoot . '/public/js/modules/responses.js');
    $body = stFunctionBody($js, 'staffingHtml');

    assertTrue(str_contains($body, '.expected'), 'staffingHtml() muss expected aus staffing lesen');
    assertTrue(!str_contains($body, 'members'), 'staffingHtml() darf nicht aus der Mitgliederliste zaehlen');
    assertTrue(!str_contains($body, '++'), 'staffingHtml() darf nicht zaehlen');

    $render = stFunctionBody($js, 'renderResponsesModal');
    assertTrue(str_contains($render, 'staffingHtml(data.staffing)'), 'Der Dialog muss den Block aus data.staffing zeigen');
});

test('Dashboard: Abschnittszeilen sagen "x von n zugesagt", unabhaengig vom Filter', function () use ($stRoot) {
    $js   = (string) sourceCode($stRoot . '/public/js/modules/responses.js');
    $body = stFunctionBody($js, 'managerTableHtml');

    assertTrue(str_contains($body, 'zugesagt'), 'Abschnittszeile ohne Zusagen');
    assertTrue(str_contains($body, 'groupingSections(data.members'),
        'Die Zahl muss aus allen Mitgliedern des Abschnitts kommen, nicht aus der gefilterten Liste');
});
