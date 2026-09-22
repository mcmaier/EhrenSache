<?php
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
