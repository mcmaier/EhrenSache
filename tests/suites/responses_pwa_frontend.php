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

    // Substring-Zaehlung statt einmaligem str_contains: die Definition allein
    // beweist noch keinen Aufruf. initResponsesTab() muss also mindestens
    // Definition + Aufruf in initTabs() liefern (>= 2), resetResponsesTab()
    // Definition + Aufruf in initResponsesTab() + Aufruf bei der Abmeldung (>= 3).
    assertTrue(substr_count($js, 'initResponsesTab()') >= 2, 'initResponsesTab wird nicht definiert UND aufgerufen');
    assertTrue(substr_count($js, 'resetResponsesTab()') >= 3, 'resetResponsesTab wird nicht definiert, beim Start und bei der Abmeldung aufgerufen');

    assertTrue((bool) preg_match('/initCaptureTab\(\);[\s\S]{0,200}initResponsesTab\(\);/', $js),
        'initTabs() ruft initResponsesTab() nicht nach initCaptureTab() auf');
    assertTrue((bool) preg_match('/userData = null;\s*\n\s*resetResponsesTab\(\);/', $js),
        'Die Abmeldung (resetSessionState) raeumt den Termine-Tab nicht ab');

    assertTrue(str_contains($js, "targetTab === 'responses'"), 'Tab-Wechsel laedt nicht nach');
    assertTrue(str_contains($js, 'escapeHtml(item.own?.comment'), 'Bemerkung wird nicht maskiert');
});

test('PWA: hidden schlaegt display:flex der Tab-Knoepfe', function () use ($rspRoot) {
    // .tab-button setzt display:flex und ueberstimmt damit das hidden-Attribut
    // des Browsers -- ohne eigene Regel waere der Tab immer sichtbar.
    $css = (string) file_get_contents($rspRoot . '/public/checkin/css/style.css');
    assertTrue(str_contains($css, '.tab-button[hidden]'), 'Regel .tab-button[hidden] fehlt');
});

test('PWA: vorgemerkte Absage hat Vorrang vor gespeicherter Antwort', function () use ($rspRoot) {
    // Regression: "Bemerkung speichern" bevorzugte frueher item.own.status
    // (die gespeicherte Antwort) vor der gerade eingetippten, noch nicht
    // gespeicherten Absage -- eine bestehende Zusage ueberlebte damit einen
    // Absage-mit-Begruendung-Versuch unveraendert.
    $js = (string) file_get_contents($rspRoot . '/public/checkin/js/app.js');

    assertTrue(!str_contains($js, 'item.own ? item.own.status : card.dataset.pendingStatus'),
        'Die gespeicherte Antwort schlaegt weiterhin die vorgemerkte Absage');
    assertTrue(str_contains($js, 'responsesPending'),
        'responsesPending fehlt -- die Vormerkung ueberlebt sonst keinen Neuaufbau der Liste');
});
