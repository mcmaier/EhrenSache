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
