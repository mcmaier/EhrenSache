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

test('PWA: vorgemerkte Absage hat Vorrang vor gespeicherter Antwort, gespeicherte Karte bleibt nicht gesperrt', function () use ($rspRoot) {
    // Regression 1: "Bemerkung speichern" bevorzugte frueher item.own.status
    // (die gespeicherte Antwort) vor der gerade eingetippten, noch nicht
    // gespeicherten Absage -- eine bestehende Zusage ueberlebte damit einen
    // Absage-mit-Begruendung-Versuch unveraendert. Reihenfolge im Code
    // entscheidet: pendingStatusFor() muss VOR item.own stehen.
    //
    // Regression 2: renderResponses(key) baut die gespeicherte Karte neu auf
    // und ruft an seinem Ende refreshAllResponseCards() auf. Stand der
    // gespeicherte appointment_id-Schluessel dabei noch in responsesInFlight,
    // sperrte genau dieser Aufruf die frisch gebaute Karte dauerhaft.
    // responsesInFlight.delete(key) muss deshalb VOR renderResponses(key)
    // erfolgen, nicht erst danach im finally.
    $js = (string) file_get_contents($rspRoot . '/public/checkin/js/app.js');

    assertTrue((bool) preg_match('/pendingStatusFor\(appointmentId\)\s*\|\|\s*item\.own/', $js),
        'Die gespeicherte Antwort (item.own) schlaegt weiterhin die vorgemerkte Absage');

    assertTrue((bool) preg_match('/responsesInFlight\.delete\(key\);[\s\S]{0,300}renderResponses\(key\)/', $js),
        'responsesInFlight.delete(key) erfolgt nicht vor renderResponses(key) -- die gespeicherte Karte bleibt gesperrt');
});

test('PWA: "Wer hat geantwortet?" zeigt Namen als Chips statt Bullet-Liste, maskiert', function () use ($rspRoot) {
    // Nutzer-Feedback: die fruehere <ul><li>-Liste wirkte bei vielen
    // Mitgliedern unuebersichtlich und die Punkte sassen zu weit links am
    // Kartenrand. Ersetzt durch nach Status gruppierte, umbrechende Chips.
    $js = (string) file_get_contents($rspRoot . '/public/checkin/js/app.js');

    assertTrue(str_contains($js, 'response-name-chip'), 'Namen tragen keine Chip-Klasse mehr');
    assertTrue(str_contains($js, 'escapeHtml(m.name)') && str_contains($js, 'escapeHtml(m.surname)'),
        'Name/Nachname werden nicht (mehr) maskiert');
});

test('PWA: "Wer hat geantwortet?" gliedert ueber groupingSections() (Vorgabe Gruppe), Gruppenname maskiert', function () use ($rspRoot) {
    // Nutzer-Entscheidung bleibt gueltig: primaer nach Gruppe vor Status,
    // Mitglieder ohne Gruppe zuletzt als "Ohne Gruppe". Seit Task 8 (1.8.0,
    // Untergruppen/FI-14) laeuft die Gliederung nicht mehr ueber eine eigene
    // responseGroupKey() nach group_name (die Zeichenkette gibt es serverseitig
    // nicht mehr), sondern -- wie die Anwesenheitsliste -- ueber die geteilte
    // groupingSections(). Die Verdrahtung des Umschalters selbst (Speicher-
    // schluessel, verfuegbare Stufen, window.setResponsesGrouping) sichert
    // tests/suites/subgroups_frontend.php ab; hier bleibt nur, was dort NICHT
    // geprueft wird: der Ohne-Gruppe-Sammelabschnitt und die Maskierung der
    // Abschnittsueberschrift selbst.
    $js = (string) file_get_contents($rspRoot . '/public/checkin/js/app.js');

    assertTrue(!str_contains($js, 'responseGroupKey'), 'responseGroupKey() ist wieder da -- sollte durch groupingSections() ersetzt bleiben');

    $start = strpos($js, 'function responseNamesHtml');
    assertTrue($start !== false, 'responseNamesHtml() fehlt');
    $end = strpos($js, 'window.setResponsesGrouping', $start);
    assertTrue($end !== false, 'window.setResponsesGrouping nach responseNamesHtml() nicht gefunden');
    $body = substr($js, $start, $end - $start);

    assertTrue(str_contains($body, 'groupingSections('), 'responseNamesHtml() bildet die Abschnitte nicht ueber groupingSections()');
    assertTrue(str_contains($body, "'Ohne Gruppe'"), 'Mitglieder ohne Gruppe landen nicht in "Ohne Gruppe"');
    assertTrue(str_contains($body, 'escapeHtml(label)'), 'Gruppenname wird nicht maskiert');
});

test('PWA: Namens-Chips tragen Status-Icon und eigene Farbklasse, nicht nur Farbe', function () use ($rspRoot) {
    // Kontrast/Nicht-nur-Farbe: jeder Chip traegt zusaetzlich zur getoenten
    // Klasse ein Icon-Praefix (✓/?/✗/—) im Text.
    $js = (string) file_get_contents($rspRoot . '/public/checkin/js/app.js');

    assertTrue((bool) preg_match('/response-name-chip response-name-chip--\$\{meta\.key\}"[^`]*\$\{meta\.icon\}/', $js),
        'Chip traegt weder eine statusabhaengige Klasse noch ein vorangestelltes Icon');

    $css = (string) file_get_contents($rspRoot . '/public/checkin/css/style.css');
    foreach (['yes', 'maybe', 'no', 'open'] as $key) {
        assertTrue(str_contains($css, ".response-name-chip--{$key}"), "Farbklasse fuer '{$key}' fehlt in der CSS");
    }
});

test('PWA: Offen-Status von "Bemerkung" und "Wer hat geantwortet?" ueberlebt einen Neuaufbau, wird bei Abmeldung geleert', function () use ($rspRoot) {
    // Nutzer-Vorgabe: Details bleiben zugeklappt, bis der Nutzer sie selbst
    // oeffnet oder eine der drei Bedingungen (Pflichtbegruendung, Entwurf,
    // Fehlschlag) sie automatisch oeffnet -- ein Neuaufbau (renderResponses)
    // ersetzt das <details>-Element, ein modulweites Set haelt den Zustand
    // deshalb ausserhalb des DOM fest.
    $js = (string) file_get_contents($rspRoot . '/public/checkin/js/app.js');

    assertTrue((bool) preg_match('/const\s+responsesOpenComments\s*=\s*new Set\(\)/', $js),
        'responsesOpenComments fehlt als Set');
    assertTrue((bool) preg_match('/const\s+responsesOpenNames\s*=\s*new Set\(\)/', $js),
        'responsesOpenNames fehlt als Set');

    assertTrue((bool) preg_match('/function resetResponsesTab\(\)[\s\S]{0,400}responsesOpenComments\.clear\(\)/', $js),
        'resetResponsesTab() leert responsesOpenComments nicht');
    assertTrue((bool) preg_match('/function resetResponsesTab\(\)[\s\S]{0,400}responsesOpenNames\.clear\(\)/', $js),
        'resetResponsesTab() leert responsesOpenNames nicht');
});

test('PWA: "Bemerkung" oeffnet automatisch bei Pflichtbegruendung und bei Speicherfehler', function () use ($rspRoot) {
    $js = (string) file_get_contents($rspRoot . '/public/checkin/js/app.js');

    assertTrue((bool) preg_match('/isPendingNo\s*\|\|\s*responsesSaveFailed\.has\(id\)\)\s*\{\s*\n\s*responsesOpenComments\.add\(id\)/', $js),
        'Vorgemerkte Absage mit Pflichtbegruendung oder ein fehlgeschlagener Speicherversuch oeffnen die Bemerkung nicht automatisch');
    assertTrue(str_contains($js, 'responsesSaveFailed.add(key)') && str_contains($js, 'responsesSaveFailed.delete(key)'),
        'responsesSaveFailed wird nicht bei Fehlschlag gesetzt und bei Erfolg wieder geloescht');
});

test('PWA: Termine-Tab bekommt denselben weissen Rahmen wie der Verlauf-Tab, Karten werden hellgrau', function () use ($rspRoot) {
    // Nutzer-Feedback: die Ueberschrift sass zu nah am Kartenrand -- Fix ist
    // derselbe weisse Container wie .history-section, die Karten darin
    // werden hellgrau wie .history-item statt weiss.
    $css = (string) file_get_contents($rspRoot . '/public/checkin/css/style.css');

    assertTrue((bool) preg_match('/\.responses-section\s*\{[^}]*background:\s*var\(--card-bg\)/', $css),
        '.responses-section ist kein weisser Container wie .history-section');
    assertTrue((bool) preg_match('/\.response-card\s*\{[^}]*background:\s*#f8f9fa/', $css),
        '.response-card ist nicht hellgrau wie .history-item');
});
