<?php
declare(strict_types=1);

/**
 * Statische Gegenproben der Check-in-PWA fuer die Terminrueckmeldung.
 *
 * Eigene Datei statt Ergaenzung von responses_frontend.php (Dashboard): beide
 * Suiten teilen einen Prozess, deshalb ein eigenes Praefix (rsp…) fuer die
 * Hilfsfunktion dieser Datei.
 */

$rspRoot = dirname(__DIR__, 2);

/** Das <…>-Tag, das $needle enthaelt. */
function rspTag(string $html, string $needle): string
{
    $pos = strpos($html, $needle);
    assertTrue($pos !== false, "{$needle} fehlt");
    $start = strrpos(substr($html, 0, $pos), '<');
    $end   = strpos($html, '>', $pos);

    return substr($html, $start, $end - $start + 1);
}

test('PWA: Tab Termine ist vorhanden und zunaechst verborgen', function () use ($rspRoot) {
    $html = (string) file_get_contents($rspRoot . '/public/checkin/index.html');
    $tag  = rspTag($html, 'data-tab="responses"');

    assertTrue(str_contains($tag, 'hidden'), 'Der Tab erscheint erst, wenn es etwas zu beantworten gibt');
    assertTrue(str_contains($html, 'id="responsesList"'), 'Liste fehlt');
    assertTrue(str_contains($html, 'id="responsesTabBadge"'), 'Zaehler fehlt');
});

test('PWA: Tab wird beim Start und beim Wechsel geladen, maskiert Freitext', function () use ($rspRoot) {
    $js = (string) file_get_contents($rspRoot . '/public/checkin/js/app.js');

    assertTrue(str_contains($js, 'initResponsesTab()'), 'initResponsesTab wird nicht aufgerufen');
    assertTrue(str_contains($js, "targetTab === 'responses'"), 'Tab-Wechsel laedt nicht nach');
    assertTrue(str_contains($js, 'escapeHtml(item.own?.comment'), 'Bemerkung wird nicht maskiert');
    assertTrue(str_contains($js, 'resetResponsesTab()'), 'Abmeldung setzt den Tab nicht zurueck');
});

test('PWA: hidden schlaegt display:flex der Tab-Knoepfe', function () use ($rspRoot) {
    // .tab-button setzt display:flex und ueberstimmt damit das hidden-Attribut
    // des Browsers -- ohne eigene Regel waere der Tab immer sichtbar.
    $css = (string) file_get_contents($rspRoot . '/public/checkin/css/style.css');
    assertTrue(str_contains($css, '.tab-button[hidden]'), 'Regel .tab-button[hidden] fehlt');
});
