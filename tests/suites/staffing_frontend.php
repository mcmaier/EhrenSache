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
    assertTrue(preg_match('/group\.parent_group_names\?\.length\s*\?[^;]*statistics-group__note/s', $js) === 1,
        'Die Unterzeile muss im Ternaer an group.parent_group_names haengen');
    assertTrue(str_contains($js, 'statistics-group__note'), 'Klasse der Unterzeile fehlt');
    assertTrue(str_contains($js, 'eigene Termine'), 'Text der Unterzeile fehlt');
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

test('Dashboard: kein eigener Besetzungsblock mehr', function () use ($stRoot) {
    $js = (string) sourceCode($stRoot . '/public/js/modules/responses.js');
    assertTrue(!str_contains($js, 'staffingHtml'), 'staffingHtml() steht noch in responses.js');
    assertTrue(!str_contains($js, '.staffing'), '.staffing steht noch in responses.js');
    assertTrue(!str_contains($js, 'data.staffing'), 'Der Dialog liest noch data.staffing');
    $css = (string) file_get_contents($stRoot . '/public/css/components/modals.css');
    assertTrue(!str_contains($css, '.staffing'), '.staffing-Regeln stehen noch in modals.css');
});

test('Dashboard: grouping.js liefert Zaehlung und Kopfzeile, beide Listen nutzen sie', function () use ($stRoot) {
    $g = (string) sourceCode($stRoot . '/public/js/modules/grouping.js');
    assertTrue(str_contains($g, 'export function groupingStatusCounts('), 'groupingStatusCounts fehlt');
    assertTrue(str_contains($g, 'export function groupingSectionHeaderHtml('), 'groupingSectionHeaderHtml fehlt');
    assertTrue(preg_match('/import\s*\{[^}]*escapeHtml[^}]*\}\s*from\s*\'\.\/utils\.js\'/', $g) === 1,
        'grouping.js muss escapeHtml aus utils.js importieren');

    $js = (string) sourceCode($stRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains(stFunctionBody($js, 'managerTableHtml'), 'groupingSectionHeaderHtml('),
        'managerTableHtml() muss die gemeinsame Kopfzeile nutzen');
    assertTrue(str_contains(stFunctionBody($js, 'namesListHtml'), 'groupingSectionHeaderHtml('),
        'namesListHtml() muss die gemeinsame Kopfzeile nutzen');
});

test('Dashboard: Kopfzeile ist ein Knopf mit aria-expanded, darueber "Alle aufklappen"', function () use ($stRoot) {
    $g    = (string) sourceCode($stRoot . '/public/js/modules/grouping.js');
    $head = stFunctionBody($g, 'groupingSectionHeaderHtml');
    assertTrue(str_contains($head, '<button'), 'Kopfzeile muss ein <button sein');
    assertTrue(str_contains($head, 'aria-expanded'), 'aria-expanded fehlt');
    assertTrue(str_contains($head, 'toggle-response-section'), 'Aktion toggle-response-section fehlt');
    assertTrue(str_contains($head, 'escapeHtml(label)'), 'Bezeichnung wird nicht maskiert');

    $js = (string) sourceCode($stRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains($js, 'data-action="toggle-all-response-sections"'), 'Knopf "Alle aufklappen" fehlt');
    assertTrue(str_contains($js, "'toggle-response-section':"), 'Aktion toggle-response-section nicht registriert');
    assertTrue(str_contains($js, "'toggle-all-response-sections':"), 'Aktion toggle-all-response-sections nicht registriert');
});

test('Dashboard: Zahlen der Kopfzeile aus ungefilterten Abschnitten, ueber section.key', function () use ($stRoot) {
    $js   = (string) sourceCode($stRoot . '/public/js/modules/responses.js');
    $body = stFunctionBody($js, 'managerTableHtml');

    assertTrue(str_contains($body, 'groupingSections(data.members'),
        'Die Zahl muss aus allen Mitgliedern des Abschnitts kommen, nicht aus der gefilterten Liste');
    assertTrue(str_contains($body, 'groupingStatusCounts('), 'Zaehlung muss groupingStatusCounts nutzen');
    assertTrue(str_contains($body, 'sectionCounts.get(section.key)'),
        'Die Zahlen muessen ueber den Abschnittsschluessel gesucht werden, Bezeichnungen sind nicht eindeutig');
    assertTrue(str_contains($body, 's.key,'), 'Die Zahlen muessen nach s.key abgelegt werden');
});

test('Dashboard: bei jedem aktiven Filter sind Abschnitte aufgeklappt und nicht bedienbar', function () use ($stRoot) {
    $js   = (string) sourceCode($stRoot . '/public/js/modules/responses.js');
    $body = stFunctionBody($js, 'isSectionExpanded');
    assertTrue(str_contains($body, "currentFilter !== 'all'"), "Aufklapp-Entscheidung kennt aktive Filter nicht (!== 'all')");
    assertTrue(str_contains($body, 'expandedSections.has('), 'Aufklapp-Entscheidung liest expandedSections nicht');

    assertTrue(str_contains(stFunctionBody($js, 'toggleAllSectionsHtml'), "currentFilter !== 'all'"),
        'Der Alle-Knopf muss unter einem Filter entfallen');
    assertTrue(str_contains(stFunctionBody($js, 'toggleAllResponseSections'), "currentFilter !== 'all'"),
        'toggleAllResponseSections() muss unter einem Filter zurueckkehren');
    assertTrue(preg_match("/'toggle-response-section':.*?currentFilter !== 'all'.*?return/s", $js) === 1,
        'toggle-response-section muss unter einem Filter zurueckkehren');
    assertTrue(substr_count($js, 'disabled: currentFilter') >= 2, 'Beide Listen muessen die Kopfzeilen unter Filter sperren');

    $g = (string) sourceCode($stRoot . '/public/js/modules/grouping.js');
    assertTrue(str_contains(stFunctionBody($g, 'groupingSectionHeaderHtml'), 'disabled'), 'Kopfzeile kennt disabled nicht');
});

test('Dashboard: Fokus bleibt nach dem Umschalten auf dem Knopf', function () use ($stRoot) {
    $js = (string) sourceCode($stRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains($js, 'CSS.escape('), 'Fokus wird nicht ueber CSS.escape wiedergefunden');
    assertTrue(str_contains($js, '.focus()'), 'Fokus wird nicht wiederhergestellt');
});

test('Dashboard: Aufklappzustand wird beim Oeffnen und beim Stufenwechsel geleert', function () use ($stRoot) {
    $js = (string) sourceCode($stRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains(stFunctionBody($js, 'openResponsesModal'), 'expandedSections.clear()'),
        'openResponsesModal() leert expandedSections nicht');
    assertTrue(str_contains(stFunctionBody($js, 'setResponsesGrouping'), 'expandedSections.clear()'),
        'setResponsesGrouping() leert expandedSections nicht');
});

test('App: Besetzungsblock liest staffing und rechnet nicht selbst', function () use ($stRoot) {
    $js   = (string) sourceCode($stRoot . '/public/checkin/js/app.js');
    $body = stFunctionBody($js, 'staffingHtml');

    assertTrue(str_contains($body, '.expected'), 'staffingHtml() muss expected aus staffing lesen');
    assertTrue(!str_contains($body, 'members'), 'staffingHtml() darf nicht aus der Mitgliederliste zaehlen');
    assertTrue(!str_contains($body, '++'), 'staffingHtml() darf nicht zaehlen');

    $card = stFunctionBody($js, 'responseCardHtml');
    assertTrue(str_contains($card, 'staffingHtml(item.staffing)'), 'Die Karte muss den Block aus item.staffing zeigen');
});
