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
 * FI-24: Freigaben in der Check-in-App — statische Gegenproben.
 * Das Laufzeitverhalten wurde einmal mit einem Puppeteer-Lauf und in der
 * Browser-Pane waehrend der Umsetzung geprueft (2026-10-08); ein eingecheckter
 * Browser-Durchgang dafuer existiert nicht.
 */

function prJs(): string
{
    return (string) sourceCode(dirname(__DIR__, 2) . '/public/checkin/js/app.js');
}

function prFunktion(string $name): string
{
    $js    = prJs();
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');
    if (preg_match('/
(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }

    return substr($js, $start);
}

test('Antragszeile ist eine gemeinsame Funktion, die Liste nutzt sie', function () {
    $zeile = prFunktion('requestItemHtml');
    assertTrue(str_contains($zeile, 'escapeHtml('), 'Antragszeile maskiert nicht');
    assertTrue(str_contains($zeile, 'btn-request-decide'), 'Antragszeile hat keine Entscheidungsknoepfe');
    assertTrue(str_contains($zeile, 'Eigener Antrag'), 'Hinweis fuer den eigenen Antrag fehlt');
    assertTrue(str_contains(prFunktion('attendanceRequestsHtml'), 'requestItemHtml('),
        'Die Anwesenheitsliste nutzt die gemeinsame Antragszeile nicht');
});

test('Zeitantrag zeigt die erfasste Ankunft', function () {
    $zeile = prFunktion('requestItemHtml');
    assertTrue(str_contains($zeile, 'recorded_arrival_time') && str_contains($zeile, 'keine Erfassung'),
        'Erfasste Ankunft bzw. „keine Erfassung“ fehlt');
});

function prHtml(): string
{
    return (string) sourceCode(dirname(__DIR__, 2) . '/public/checkin/index.html');
}

test('Umschalter im Tab Liste', function () {
    $html = prHtml();
    foreach (['id="attendanceViewSwitch"', 'data-action="attendance-view"', 'data-view="attendance"',
              'data-view="requests"', 'id="pendingRequestsCount"', 'id="attendanceView"', 'id="pendingRequestsView"'] as $s) {
        assertTrue(str_contains($html, $s), "Markup fehlt: {$s}");
    }
    assertTrue(strpos($html, 'id="attendanceViewSwitch"') < strpos($html, 'id="attendanceAppointmentFilter"'),
        'Der Umschalter steht nicht ueber der Terminauswahl');
});

test('Ansicht laedt offene Antraege und teilt kommend/vergangen', function () {
    $load = prFunktion('loadPendingRequests');
    assertTrue(str_contains($load, "'exceptions'") && str_contains($load, "status: 'pending'"),
        'loadPendingRequests fragt nicht exceptions?status=pending ab');
    $render = prFunktion('renderPendingRequests');
    assertTrue(str_contains($render, 'Kommende Termine') && str_contains($render, 'Vergangene Termine'),
        'Abschnitte fehlen');
    assertTrue(str_contains($render, 'requestItemHtml('), 'Ansicht nutzt die gemeinsame Antragszeile nicht');
    assertTrue(str_contains($render, 'self_decision_blocked'), 'Selbstgenehmigungsflag wird nicht ausgewertet');
    assertTrue(str_contains($render, 'Keine offenen Anträge'), 'Leerzustand fehlt');
});

test('Nach einer Entscheidung laedt die sichtbare Ansicht neu', function () {
    $rumpf = prFunktion('handleRequestDecision');
    assertTrue(str_contains($rumpf, 'reloadAttendanceView('), 'handleRequestDecision laedt nicht die sichtbare Ansicht');
    $reload = prFunktion('reloadAttendanceView');
    assertTrue(str_contains($reload, 'loadPendingRequests(') && str_contains($reload, 'loadAttendanceList('),
        'reloadAttendanceView unterscheidet die Ansichten nicht');
});

test('Umschalter ist in der Aktionstabelle registriert, keine Inline-Handler', function () {
    assertTrue(str_contains(prJs(), "'attendance-view':"), 'Aktion attendance-view nicht registriert');
    assertTrue(!preg_match('/\son[a-z]+=/i', prHtml()), 'Inline-Handler im Markup');
});

test('Abrufe ueberholen sich nicht: Sequenzwaechter vor Zahl und Ansicht', function () {
    $load = prFunktion('loadPendingRequests');
    assertTrue(str_contains($load, 'const seq = ++pendingRequestsSeq'), 'Kein Sequenzwaechter in loadPendingRequests');
    $waechter = strpos($load, 'if (seq !== pendingRequestsSeq) return;');
    assertTrue($waechter !== false, 'Veraltete Antwort wird nicht verworfen');
    assertTrue($waechter > strpos($load, 'await apiCall('),
        'Der Waechter steht nicht nach dem Abruf');
    assertTrue($waechter < strpos($load, 'pendingRequestsCount'), 'Die Zahl wird vor dem Waechter gesetzt');
    assertTrue(str_contains(prJs(), 'let pendingRequestsSeq = 0;'), 'Zaehler pendingRequestsSeq fehlt');
});

test('countOnly zeichnet die Ansicht nicht', function () {
    $load = prFunktion('loadPendingRequests');
    assertTrue(preg_match('/if\s*\(\s*!countOnly\s*\)\s*renderPendingRequests\(/', $load) === 1,
        'renderPendingRequests() laeuft auch beim reinen Zaehlen');
    assertTrue(substr_count($load, 'renderPendingRequests(') === 1, 'renderPendingRequests() wird mehrfach aufgerufen');
});

test('Heute zaehlt als kommend', function () {
    $render = prFunktion('renderPendingRequests');
    assertTrue(preg_match('/kommend = .*?>= heute/s', $render) === 1, 'Kommende Termine nicht mit >= heute');
    assertTrue(preg_match('/vergangen = .*?< heute/s', $render) === 1, 'Vergangene Termine nicht mit < heute');
});

test('Terminkopf maskiert Titel und Terminart', function () {
    $render = prFunktion('renderPendingRequests');
    assertTrue(str_contains($render, 'escapeHtml(k.appointment_title)'), 'Termintitel unmaskiert');
    assertTrue(str_contains($render, 'escapeHtml(k.appointment_type_name)'), 'Terminart unmaskiert');
});

test('Sortierschluessel machen aus null keinen Text', function () {
    $render = prFunktion('renderPendingRequests');
    assertTrue(str_contains($render, "a.appointment_start_time ?? ''"), 'Beginn ohne ?? im Sortierschluessel');
    $namen = prFunktion('pendingRequestNameOrder');
    foreach (['x.surname', 'x.name', 'y.surname', 'y.name'] as $f) {
        assertTrue(str_contains($namen, "{$f} ?? ''"), "{$f} ohne ?? im Namensvergleich");
    }
});

test('Beim Oeffnen des Tabs gilt Anwesenheit, ohne doppeltes Laden', function () {
    $tabs  = prFunktion('initTabs');
    $start = strpos($tabs, "targetTab === 'attendance-list'");
    assertTrue($start !== false, 'Zweig attendance-list fehlt');
    $zweig = substr($tabs, $start);
    $setzen = strpos($zweig, "setAttendanceView('attendance')");
    assertTrue($setzen !== false, 'Tab-Handler waehlt nicht Anwesenheit');
    assertTrue(str_contains($zweig, 'loadPendingRequests({ countOnly: true })'), 'Zahl wird beim Oeffnen nicht geladen');
    $reset = strpos($zweig, 'attendanceListStale = false');
    assertTrue($reset !== false && $reset < $setzen,
        'attendanceListStale wird nicht vor setAttendanceView zurueckgesetzt -- die Liste laedt doppelt');
});
