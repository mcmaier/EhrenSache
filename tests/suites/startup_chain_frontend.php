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
 * Startkette und Wiederholung (OI-121): statische Gegenproben.
 *
 * Gemessen am 2026-10-05 auf der Demo: Das Dashboard lud in fuenf, die
 * Check-in-App in zehn Stufen nacheinander, und jeder 1-s-Ausreisser des
 * Hosters addierte sich. Spec
 * docs/superpowers/specs/2026-10-05-startkette-parallel-design.md.
 *
 * Das Laufzeitverhalten (Stufen, Wiederholung, Abmelderegel) prueft
 * tests/browser/startup-chain.mjs.
 */

$scRoot    = dirname(__DIR__, 2);
$scApi     = (string) sourceCode($scRoot . '/public/js/modules/api.js');
$scPwa     = (string) sourceCode($scRoot . '/public/checkin/js/app.js');
$scPwaHtml = (string) sourceCode($scRoot . '/public/checkin/index.html');

const SC_RETRY_STATUSES = [502, 503, 504, 520, 521, 522, 523, 524];

/**
 * Rumpf einer Funktion ab ihrer Signatur bis zur schliessenden Klammer in
 * Spalte 0. Fehlt die Signatur, ist das Ergebnis leer.
 */
function scBody(string $js, string $signature): string
{
    $start = strpos($js, $signature);
    if ($start === false) {
        return '';
    }
    $end = strpos($js, "\n}", $start);

    return substr($js, $start, ($end === false ? strlen($js) : $end) - $start);
}

foreach (['Dashboard' => 'scApi', 'PWA' => 'scPwa'] as $wo => $var) {
    test("{$wo}: Wiederholung nur bei Ueberlastungsantworten", function () use (&$scApi, &$scPwa, $var) {
        $js = $var === 'scApi' ? $scApi : $scPwa;
        assertTrue(preg_match('/RETRY_STATUSES\s*=\s*\[([^\]]*)\]/', $js, $m) === 1, 'RETRY_STATUSES fehlt');
        $codes = array_map('intval', preg_split('/\s*,\s*/', trim($m[1])) ?: []);
        assertSame(SC_RETRY_STATUSES, $codes, 'Statusliste weicht von der Spec ab');
    });

    test("{$wo}: nur GET wird wiederholt, nach einer Pause", function () use (&$scApi, &$scPwa, $var) {
        $js   = $var === 'scApi' ? $scApi : $scPwa;
        $body = scBody($js, 'async function apiCall(');
        assertTrue($body !== '', 'apiCall() nicht gefunden');
        assertTrue(preg_match("/method\s*===\s*'GET'\s*&&\s*RETRY_STATUSES\.includes\(response\.status\)/", $body) === 1,
            'Die Wiederholung ist nicht an GET gebunden -- ein wiederholter POST koennte doppelt anlegen');
        assertTrue(str_contains($body, 'await retryPause()'), 'Kein Abstand vor dem zweiten Versuch');
        assertSame(2, substr_count($body, 'await fetch(url, options)'), 'Nicht genau ein zweiter Versuch');
    });
}

test('PWA: Ladeanzeige ist der Anfangszustand, nicht die Anmeldemaske', function () use ($scPwaHtml) {
    assertTrue(preg_match('/<div id="startScreen" class="active">/', $scPwaHtml) === 1,
        'startScreen fehlt oder ist beim Laden nicht aktiv');
    assertTrue(preg_match('/<div id="loginScreen" class="active">/', $scPwaHtml) === 0,
        'Die Anmeldemaske ist beim Laden aktiv -- ein angemeldetes Mitglied saehe sie bis zum Ende des Starts');
    assertTrue(str_contains($scPwaHtml, 'data-action="start-retry"'), 'Knopf "Erneut versuchen" fehlt');
});

test('PWA: "Erneut versuchen" startet die Anmeldepruefung neu', function () use ($scPwa) {
    assertTrue(preg_match("/'start-retry'\s*:\s*\(\)\s*=>\s*checkAutoLogin\(\)/", $scPwa) === 1,
        'start-retry ist nicht in dataActions registriert');
    assertTrue(str_contains(scBody($scPwa, 'function showScreen('), "getElementById('startScreen')"),
        'showScreen() kennt die Ladeanzeige nicht');
});
