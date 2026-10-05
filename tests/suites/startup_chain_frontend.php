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
$scApp     = (string) sourceCode($scRoot . '/public/js/app.js');
$scUsers   = (string) sourceCode($scRoot . '/public/js/modules/users.js');
$scWork    = (string) sourceCode($scRoot . '/public/js/modules/worktime.js');

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
    foreach (['async function checkAutoLogin(', 'async function handleLogin('] as $sig) {
        assertSame(1, substr_count(scBody($scPwa, $sig), "apiCall('me')"), "{$sig} holt me nicht genau einmal");
    }
    assertTrue(!str_contains(scBody($scPwa, 'async function startSession('), "apiCall('me')"), 'startSession() holt me erneut');
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

test('PWA: ein Fehler im Start laesst einen Ausweg', function () use ($scPwa) {
    $auto = scBody($scPwa, 'async function checkAutoLogin(');
    assertTrue(preg_match('/try\s*\{\s*await startSession\(result\.data\);\s*\}\s*catch/', $auto) === 1,
        'checkAutoLogin() faengt einen Fehler in startSession() nicht ab -- die Ladeanzeige hinge ohne Ausweg');
    $form = scBody($scPwa, 'async function handleLogin(');
    $catch = substr($form, (int) strrpos($form, 'catch (error)'));
    assertTrue(str_contains($catch, "showScreen('login')"), 'Die Fehlermeldung der Anmeldung steht auf einem verdeckten Bildschirm');
});

test('PWA: von der Ladeanzeige fuehrt ein Weg zur Anmeldemaske', function () use ($scPwa, $scPwaHtml) {
    assertTrue(str_contains($scPwaHtml, 'data-action="start-switch-account"'), 'Knopf "Mit anderem Konto anmelden" fehlt');
    assertTrue(preg_match("/'start-switch-account'\s*:\s*\(\)\s*=>\s*switchAccount\(\)/", $scPwa) === 1, 'start-switch-account ist nicht registriert');
    $body = scBody($scPwa, 'function switchAccount(');
    assertTrue(str_contains($body, "localStorage.removeItem('api_token')") && str_contains($body, "showScreen('login')"),
        'switchAccount() verwirft den Zugang nicht oder zeigt die Anmeldemaske nicht');
    assertTrue(str_contains(scBody($scPwa, 'function showStartStatus('), "getElementById('startSwitchBtn')"),
        'showStartStatus() blendet den Knopf nicht mit ein');
});

test('Dashboard: init() wartet nach me nichts mehr ab', function () use ($scApp) {
    $body = scBody($scApp, 'async function init(');
    assertTrue($body !== '', 'init() nicht gefunden');
    assertTrue(!str_contains($body, 'await loadVersion('), 'Die Version haelt die Ansicht auf');
    assertTrue(!str_contains($body, 'await initAllYearFilters('), 'Die Jahresliste haelt die Ansicht auf');
    $show = strpos($body, 'showDashboard()');
    $load = strpos($body, 'loadAllData()');
    assertTrue($show !== false && $load !== false && $show < $load, 'Das Dashboard erscheint nicht vor dem Laden der Daten');
});

test('Dashboard: setCurrentUser() holt nichts', function () use ($scApi) {
    assertTrue(!str_contains(scBody($scApi, 'export async function setCurrentUser('), 'apiCall('),
        'setCurrentUser() wartet wieder auf members -- eine Stufe vor dem Einblenden');
});

test('Dashboard: die Benutzerliste laedt nicht beim Start', function () use ($scUsers) {
    $body = scBody($scUsers, 'export async function initUsersEventHandlers(');
    // Die Filter-Zuhoerer rufen applyUserFilters() bei einer Aenderung -- das
    // ist gewollt. Verboten ist nur der Aufruf beim Einrichten selbst.
    assertTrue(!str_contains($body, 'await applyUserFilters(') && !str_contains($body, 'loadUsers('),
        'initUsersEventHandlers() laedt die Liste, auch wenn der Bereich Benutzer gar nicht offen ist');
});

test('Dashboard: gleichzeitige Freischaltpruefungen teilen sich eine Anfrage', function () use ($scWork) {
    assertTrue(preg_match("/sharedLoad\('worktimeEnabled'/", scBody($scWork, 'export async function checkWorktimeEnabled(')) === 1,
        'Startet der Bereich Zeiterfassung, fragt der Start activity_types doppelt');
});
