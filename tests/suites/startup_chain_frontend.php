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

test('PWA: beide Anmeldewege laufen ueber startSession()', function () use ($scPwa) {
    foreach (['async function checkAutoLogin(', 'async function handleLogin('] as $sig) {
        $body = scBody($scPwa, $sig);
        assertTrue($body !== '', "{$sig} fehlt");
        assertTrue(str_contains($body, 'startSession('), "{$sig} ruft startSession() nicht auf");
        foreach (['loadClientSettings(', 'loadCheckinAppointments(', 'initTabs(', 'initWorktime(', 'loadAppointmentTypes('] as $eigen) {
            assertTrue(!str_contains($body, $eigen), "{$sig} traegt wieder eine eigene Ladekette ({$eigen})");
        }
    }
});

test('PWA: me wird beim Start nur einmal geholt', function () use ($scPwa) {
    assertSame(2, substr_count($scPwa, "apiCall('me')"),
        "apiCall('me') gehoert genau in checkAutoLogin() und handleLogin() -- ein drittes ist eine zusaetzliche Stufe");
    assertTrue(!str_contains($scPwa, 'function loadUserData('), 'loadUserData() holte me ein zweites Mal und ist ersetzt');
});

test('PWA: startSession() laedt gleichzeitig und blendet danach ein', function () use ($scPwa) {
    $body = scBody($scPwa, 'async function startSession(');
    $all  = strpos($body, 'Promise.all(');
    $main = strpos($body, "showScreen('main')");
    assertTrue($all !== false, 'startSession() laedt nicht gleichzeitig');
    assertTrue($main !== false && $main > $all, 'Der Hauptbildschirm erscheint nicht nach Stufe 2');
    assertTrue(preg_match('/generation\s*!==\s*sessionGeneration\)\s*return/', $body) === 1,
        'startSession() zeichnet auch nach einem Abmelden weiter');
});

test('PWA: Token nur bei 401 oder 403 loeschen', function () use ($scPwa) {
    $body = scBody($scPwa, 'async function checkAutoLogin(');
    assertSame(1, substr_count($body, "localStorage.removeItem('api_token')"),
        'Der Token wird an mehr als einer Stelle geloescht');
    assertTrue(preg_match("/status\s*===\s*401\s*\|\|\s*result\.status\s*===\s*403\)\s*\{[^}]*localStorage\.removeItem\('api_token'\)/s", $body) === 1,
        'Der Token wird nicht im 401/403-Zweig geloescht -- ein Haenger beim Hoster meldete sonst ab');
});

test('PWA: Abmelden verwirft einen laufenden Start', function () use ($scPwa) {
    assertTrue(str_contains(scBody($scPwa, 'function resetSessionState('), 'sessionGeneration++'),
        'resetSessionState() erhoeht sessionGeneration nicht');
});

test('PWA: Zeiterfassung fragt Taetigkeiten und laufende Sitzung gleichzeitig', function () use ($scPwa) {
    $body = scBody($scPwa, 'async function initWorktime(');
    assertTrue(preg_match("/Promise\.all\(\[\s*apiCall\('activity_types'/", $body) === 1,
        'initWorktime() wartet die Taetigkeiten ab, bevor es die laufende Sitzung fragt');
});
