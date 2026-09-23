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
 * Terminliste der Check-in-PWA (seit 1.10.0): statische Gegenproben.
 *
 * Laufzeitverhalten -- Aufklappen, Monatsgliederung, Chips -- prueft die
 * Sichtpruefung im Testplan. Hier steht, was an einer Zeile Code haengt und
 * unbemerkt zurueckfallen kann.
 */

function ptFunktion(string $name): string
{
    $js    = (string) file_get_contents(dirname(__DIR__, 2) . '/public/checkin/js/app.js');
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');

    if (preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }
    return substr($js, $start);
}

test('loadResponses fragt mit with_info', function () {
    assertTrue(str_contains(ptFunktion('loadResponses'), 'with_info'),
        'Ohne with_info liefert der Server nur Rueckmeldetermine');
});

test('Badge zaehlt nur offene, nicht begonnene Rueckmeldungen', function () {
    $rumpf = ptFunktion('updateResponsesBadge');
    assertTrue(str_contains($rumpf, 'responses_enabled'), 'Infotermine duerfen nicht zaehlen');
    assertTrue(str_contains($rumpf, 'started'), 'Begonnene Termine duerfen nicht zaehlen');
});

test('Liste ist nach Monaten gegliedert', function () {
    assertTrue(str_contains(ptFunktion('renderResponses'), 'responses-month'));
});

test('Infokarten maskieren Beschreibung und Ort', function () {
    $rumpf = ptFunktion('infoCardHtml');
    assertTrue(str_contains($rumpf, 'escapeHtml(apt.description)'));
    assertTrue(str_contains($rumpf, 'responseLocationHtml('));
});

test('Begonnene Rueckmeldetermine zeigen keine Knoepfe', function () {
    assertTrue(str_contains(ptFunktion('responseCardHtml'), 'item.started'));
});

test('Offline-Hinweis nur an Rueckmeldekarten', function () {
    assertTrue(str_contains(ptFunktion('refreshAllResponseCards'), ':not(.response-card--info)'),
        'Sonst stuende an Infoterminen "Ohne Netz ist keine Rueckmeldung moeglich"');
});

// Entschuldigen an der Infokarte (seit 1.12.0)

test('Infokarte: Entschuldigen nur bis Terminbeginn', function () {
    $rumpf = ptFunktion('excuseSectionHtml');
    assertTrue(str_contains($rumpf, 'item.started'),
        'Nach Beginn darf die Karte keine neue Entschuldigung anbieten');
    assertTrue(str_contains($rumpf, 'response-excuse__submit') && str_contains($rumpf, 'response-excuse__withdraw'),
        'Einreichen und Zurueckziehen fehlen');
    // Die Entwurfslogik der Liste sucht .response-comment textarea
    assertTrue(str_contains($rumpf, 'class="response-comment"'),
        'Ohne .response-comment ginge eine angefangene Begruendung beim Neuladen verloren');
});

test('Infokarte: die Begruendung ist Pflicht', function () {
    $rumpf = ptFunktion('submitExcuse');
    assertTrue(str_contains($rumpf, "reason === ''"), 'Ohne Begruendung darf nichts abgeschickt werden');
    assertTrue(str_contains($rumpf, "exception_type: 'absence'"), 'Eingereicht wird eine Entschuldigung');
});

test('Infokarte: Zurueckziehen fragt nach und loescht', function () {
    $rumpf = ptFunktion('withdrawExcuse');
    assertTrue(str_contains($rumpf, 'showNavigationConfirm('), 'Zurueckziehen ohne Rueckfrage');
    assertTrue(str_contains($rumpf, "'DELETE'"), 'Zurueckziehen loescht den offenen Antrag');
});

test('Klicks der Infokarte laufen nicht in die Logik der Rueckmeldekarte', function () {
    $rumpf = ptFunktion('onResponsesClick');
    $weiche = strpos($rumpf, 'response-excuse__submit');
    $details = strpos($rumpf, ".response-comment-details'");
    assertTrue($weiche !== false && $details !== false && $weiche < $details,
        'Die Infokarte hat kein .response-comment-details -- die Weiche muss davor stehen');
});

// Verlauf im Aufbau der Terminkarten (seit 1.12.0)

test('Verlauf: alle Eintragsarten laufen durch historyCardHtml', function () {
    foreach (['addRecordToHistory', 'addExceptionToHistory', 'addWorkSessionToHistory'] as $funktion) {
        assertTrue(str_contains(ptFunktion($funktion), 'historyCardHtml('),
            "{$funktion}() baut sein Markup selbst -- Aufbau und Maskierung laufen dann auseinander");
    }
});

test('Verlauf: historyCardHtml maskiert Titel, Zeitpunkt, Meta und Chip', function () {
    $rumpf = ptFunktion('historyCardHtml');
    foreach (['escapeHtml(p.title)', 'escapeHtml(p.when)', "escapeHtml(p.meta || '')", 'escapeHtml(p.chip.text)'] as $stelle) {
        assertTrue(str_contains($rumpf, $stelle), "historyCardHtml() setzt ungeschuetzt ein: {$stelle}");
    }
    assertTrue(str_contains(ptFunktion('historyItem'), 'safeHexColor('),
        'Die Randfarbe kommt aus der Datenbank und muss ein Hexwert sein');
});

// Antraege in der Anwesenheitsliste (seit 1.12.0)

test('Liste: den eigenen Antrag entscheidet niemand in der PWA', function () {
    $rumpf = ptFunktion('attendanceRequestsHtml');
    assertTrue(str_contains($rumpf, 'userData.member_id') && str_contains($rumpf, 'eigener'),
        'Ohne Abgleich mit dem eigenen Mitglied genehmigt sich ein Manager selbst (OI-3)');
    assertTrue(str_contains($rumpf, 'escapeHtml(a.reason'), 'Die Begruendung kommt vom Mitglied und muss maskiert werden');
    assertTrue(str_contains($rumpf, '<details'), 'Die Begruendung gehoert zugeklappt');
});

test('Liste: Ablehnen fragt nach, Genehmigen laedt die Liste neu', function () {
    $rumpf = ptFunktion('handleRequestDecision');
    assertTrue(str_contains($rumpf, 'showNavigationConfirm('), 'Ablehnen ohne Rueckfrage');
    assertTrue(str_contains($rumpf, 'loadAttendanceList()'), 'Ohne Neuladen fehlt der Eintrag, den die Genehmigung anlegt');
});

test('Liste: entschuldigt ist keine Anwesenheit', function () {
    $rumpf = ptFunktion('renderAttendanceList');
    assertTrue(str_contains($rumpf, "member.status === 'excused'"),
        'Bis 1.12.0 zeigte die Liste jeden Eintrag als anwesend');
});
