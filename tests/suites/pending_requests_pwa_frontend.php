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
 * Laufzeitverhalten prueft der Browser-Durchgang (Task 5).
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
