<?php
declare(strict_types=1);

/**
 * Statische Gegenproben: Sprung vom Kalender in die Anwesenheitsliste.
 *
 * Spec: docs/superpowers/specs/2026-09-22-kalender-anwesenheit-design.md (Schritt 1)
 */

$caRoot = dirname(__DIR__, 2);

function caFile(string $root, string $rel): string
{
    $src = file_get_contents($root . '/' . $rel);
    assertTrue($src !== false, "{$rel} fehlt");

    return (string) $src;
}

/** Rumpf einer JS-Funktion ab ihrem Namen bis zur naechsten Top-Level-Funktion. */
function caFunctionBody(string $js, string $signature): string
{
    $start = strpos($js, $signature);
    assertTrue($start !== false, "{$signature} fehlt");
    $next = preg_match('/\n(export )?(async )?function /', $js, $m, PREG_OFFSET_CAPTURE, $start + strlen($signature))
        ? $m[0][1] : strlen($js);

    return substr($js, $start, $next - $start);
}

// ---- ui.js --------------------------------------------------------------------------

test('navigateToSection ist exportiert und laedt den Bereich', function () use ($caRoot) {
    $js = caFile($caRoot, 'public/js/modules/ui.js');
    $body = caFunctionBody($js, 'export async function navigateToSection(');
    assertTrue(str_contains($body, "sessionStorage.setItem('currentSection', section)"));
    assertTrue(str_contains($body, 'await loadAllData()'));
});

test('Der Navigationsklick nutzt navigateToSection', function () use ($caRoot) {
    $js = caFile($caRoot, 'public/js/modules/ui.js');
    $body = caFunctionBody($js, 'export async function initNavigation(');
    assertTrue(str_contains($body, 'navigateToSection('), 'Klick und Sprung muessen denselben Weg gehen');
});

test('setCurrentYear kann ohne Neuladen setzen', function () use ($caRoot) {
    $js = caFile($caRoot, 'public/js/modules/ui.js');
    $body = caFunctionBody($js, 'export function setCurrentYear(');
    assertTrue(str_contains($body, 'reload = true'), 'Vorgabe muss das bisherige Verhalten sein');
    assertTrue(str_contains($body, 'if (reload)'));
});
