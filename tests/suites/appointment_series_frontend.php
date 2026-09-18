<?php
declare(strict_types=1);

/**
 * Statische Gegenproben der Oberflaeche fuer Terminserien, Feiertage und das
 * Anlegen im Kalender (FI-7, FI-16, OI-64).
 *
 * Das Dashboard hat keinen eigenen JS-Testlauf; pruefbar bleibt die
 * Verdrahtung zwischen HTML, Modul und Serverantwort.
 */

$sfRoot = dirname(__DIR__, 2);

function sfFile(string $root, string $rel): string
{
    $src = file_get_contents($root . '/' . $rel);
    assertTrue($src !== false, "{$rel} fehlt");

    return (string) $src;
}

// ---- Bausteine ------------------------------------------------------------------

test('date_checklist.js exportiert renderDateChecklist und haelt Daten nicht im DOM', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/date_checklist.js');
    assertTrue(str_contains($js, 'export function renderDateChecklist('));
    assertTrue(str_contains($js, 'getDeselected'));
    assertTrue(!str_contains($js, 'data-suggestion'), 'kein JSON in data-Attributen');
    assertTrue(str_contains($js, 'escapeHtml('), 'Beschriftungen werden escaped');
});

test('Die Import-Vorschau nutzt die Datumsliste statt JSON im data-Attribut', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/import_export.js');
    assertTrue(str_contains($js, "from './date_checklist.js'"));
    assertTrue(!str_contains($js, 'data-suggestion'));
    assertTrue(str_contains($js, 'getSelectedItems()'));
});

test('showChoice ist exportiert und das Modal existiert', function () use ($sfRoot) {
    assertTrue(str_contains(sfFile($sfRoot, 'public/js/modules/ui.js'), 'export function showChoice('));
    $html = sfFile($sfRoot, 'public/index.html');
    foreach (['id="choiceModal"', 'id="choiceTitle"', 'id="choiceMessage"', 'id="choiceButtons"'] as $needle) {
        assertTrue(str_contains($html, $needle), "{$needle} fehlt");
    }
});

test('Der Cache kennt die Feiertage je Jahr', function () use ($sfRoot) {
    assertTrue((bool) preg_match('/holidays:\s*\{\}/', sfFile($sfRoot, 'public/js/modules/ui.js')));
});
