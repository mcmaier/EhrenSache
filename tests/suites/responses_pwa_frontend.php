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
    $html = (string) sourceCode($rspRoot . '/public/checkin/index.html');
    $tag  = rspTag($html, 'data-tab="responses"');

    assertTrue(str_contains($tag, 'hidden'), 'Der Tab erscheint erst, wenn es etwas zu beantworten gibt');
    assertTrue(str_contains($html, 'id="responsesList"'), 'Liste fehlt');
    assertTrue(str_contains($html, 'id="responsesTabBadge"'), 'Zaehler fehlt');
});

test('PWA: Tab wird beim Start und beim Wechsel geladen, maskiert Freitext', function () use ($rspRoot) {
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

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
    $css = (string) sourceCode($rspRoot . '/public/checkin/css/style.css');
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
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

    assertTrue((bool) preg_match('/pendingStatusFor\(appointmentId\)\s*\|\|\s*item\.own/', $js),
        'Die gespeicherte Antwort (item.own) schlaegt weiterhin die vorgemerkte Absage');

    assertTrue((bool) preg_match('/responsesInFlight\.delete\(key\);[\s\S]{0,300}renderResponses\(key\)/', $js),
        'responsesInFlight.delete(key) erfolgt nicht vor renderResponses(key) -- die gespeicherte Karte bleibt gesperrt');
});

test('PWA: "Wer hat geantwortet?" zeigt Namen als Chips statt Bullet-Liste, maskiert', function () use ($rspRoot) {
    // Nutzer-Feedback: die fruehere <ul><li>-Liste wirkte bei vielen
    // Mitgliedern unuebersichtlich und die Punkte sassen zu weit links am
    // Kartenrand. Ersetzt durch nach Status gruppierte, umbrechende Chips.
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

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
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

    assertTrue(!str_contains($js, 'responseGroupKey'), 'responseGroupKey() ist wieder da -- sollte durch groupingSections() ersetzt bleiben');

    $start = strpos($js, 'function responseNamesHtml');
    assertTrue($start !== false, 'responseNamesHtml() fehlt');
    $end = strpos($js, 'window.setResponsesGrouping', $start);
    assertTrue($end !== false, 'window.setResponsesGrouping nach responseNamesHtml() nicht gefunden');
    $body = substr($js, $start, $end - $start);

    assertTrue(str_contains($body, 'groupingSections('), 'responseNamesHtml() bildet die Abschnitte nicht ueber groupingSections()');
    assertTrue(str_contains($body, "'Ohne Gruppe'"), 'Mitglieder ohne Gruppe landen nicht in "Ohne Gruppe"');
    // Der Name geht seit der Kopfzeile ueber groupingSectionHeaderHtml(), die ihn maskiert.
    assertTrue(str_contains($body, 'groupingSectionHeaderHtml({ key: sectionKey(section), label,'), 'Gruppenname geht nicht an die Kopfzeile');
    $hs = strpos($js, 'function groupingSectionHeaderHtml');
    assertTrue($hs !== false && str_contains(substr($js, $hs, 1500), 'escapeHtml(label)'), 'Gruppenname wird nicht maskiert');
});

test('PWA: Namens-Chips tragen Status-Icon und eigene Farbklasse, nicht nur Farbe', function () use ($rspRoot) {
    // Kontrast/Nicht-nur-Farbe: jeder Chip traegt zusaetzlich zur getoenten
    // Klasse ein Icon-Praefix (✓/?/✗/—) im Text.
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

    assertTrue((bool) preg_match('/response-name-chip response-name-chip--\$\{meta\.key\}"[^`]*\$\{meta\.icon\}/', $js),
        'Chip traegt weder eine statusabhaengige Klasse noch ein vorangestelltes Icon');

    $css = (string) sourceCode($rspRoot . '/public/checkin/css/style.css');
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
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

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
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

    assertTrue((bool) preg_match('/isPendingNo\s*\|\|\s*responsesSaveFailed\.has\(id\)\)\s*\{\s*\n\s*responsesOpenComments\.add\(id\)/', $js),
        'Vorgemerkte Absage mit Pflichtbegruendung oder ein fehlgeschlagener Speicherversuch oeffnen die Bemerkung nicht automatisch');
    assertTrue(str_contains($js, 'responsesSaveFailed.add(key)') && str_contains($js, 'responsesSaveFailed.delete(key)'),
        'responsesSaveFailed wird nicht bei Fehlschlag gesetzt und bei Erfolg wieder geloescht');
});

test('PWA: Termine-Tab bekommt denselben weissen Rahmen wie der Verlauf-Tab, Karten werden hellgrau', function () use ($rspRoot) {
    // Nutzer-Feedback: die Ueberschrift sass zu nah am Kartenrand -- Fix ist
    // derselbe weisse Container wie .history-section, die Karten darin
    // werden hellgrau wie .history-item statt weiss.
    $css = (string) sourceCode($rspRoot . '/public/checkin/css/style.css');

    assertTrue((bool) preg_match('/\.responses-section\s*\{[^}]*background:\s*var\(--card-bg\)/', $css),
        '.responses-section ist kein weisser Container wie .history-section');
    assertTrue((bool) preg_match('/\.response-card\s*\{[^}]*background:\s*#f8f9fa/', $css),
        '.response-card ist nicht hellgrau wie .history-item');
});

// ---------------------------------------------------------------------------
// OI-123: Termin anlegen aus dem Tab „Termine“ (Admin/Manager), auch ohne
// Anwesenheitserfassung.
// ---------------------------------------------------------------------------

/** Rumpf von function $name( bis zur naechsten Funktion auf oberster Ebene. */
function rspFunktion(string $js, string $name): string
{
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');

    if (preg_match('/\n(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }
    return substr($js, $start);
}

test('PWA OI-123: Plus-Knopf im Kopf des Termine-Tabs, zunaechst verborgen', function () use ($rspRoot) {
    $html  = (string) sourceCode($rspRoot . '/public/checkin/index.html');
    $start = strpos($html, 'class="tab-content" data-tab="responses"');
    assertTrue($start !== false, 'Inhalt des Termine-Tabs fehlt');

    $knopf = strpos($html, 'id="btnAddAppointment"', $start);
    $liste = strpos($html, 'id="responsesList"', $start);
    assertTrue($knopf !== false && $liste !== false && $knopf < $liste,
        'Der Knopf steht nicht im Kopf des Termine-Tabs');

    $tag = rspTag($html, 'id="btnAddAppointment"');
    assertTrue(str_contains($tag, ' hidden'), 'Der Knopf muss verborgen starten -- nur Verwalter sehen ihn');
    assertTrue(str_contains($tag, 'aria-label="Termin anlegen"'), 'Ein reines Plus braucht eine Beschriftung');
    assertTrue(str_contains($tag, 'type="button"'), 'Ohne type="button" waere es ein Absendeknopf');
});

test('PWA OI-123: hidden schlaegt display des Plus-Knopfs', function () use ($rspRoot) {
    $css = (string) sourceCode($rspRoot . '/public/checkin/css/style.css');
    assertTrue(str_contains($css, '.btn-add-appointment[hidden]'),
        'Ohne [hidden]-Regel zeigt display:inline-flex den Knopf auch Mitgliedern');
});

test('PWA OI-123: Termin-Dialog wird unabhaengig von der Anwesenheit gebunden', function () use ($rspRoot) {
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

    assertTrue(!str_contains(rspFunktion($js, 'initAttendanceList'), 'appointmentForm'),
        'Bindung haengt noch an initAttendanceList -- bei „nur Terminplanung“ speichert der Dialog nicht');

    $modal = rspFunktion($js, 'initAppointmentModal');
    assertTrue(str_contains($modal, "'submit', submitAppointmentForm"), 'Speichern nicht gebunden');
    assertTrue(str_contains($modal, 'btnCancelAppointment'), 'Abbrechen nicht gebunden');

    assertTrue((bool) preg_match('/initAppointmentModal\(\);\s*await initAttendanceList\(\);/',
        rspFunktion($js, 'startSession')),
        'startSession bindet den Dialog nicht unmittelbar vor der Anwesenheitsliste');
});

test('PWA OI-123: beide Knoepfe nennen ihre Herkunft', function () use ($rspRoot) {
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

    // Als direkte Referenz gebunden bekaeme die Funktion das Click-Event als
    // origin -- dann waere weder 'attendance' noch 'responses' gesetzt.
    assertTrue(str_contains(rspFunktion($js, 'initAttendanceList'), "showCreateAppointmentModal('attendance')"),
        'Anwesenheitsliste uebergibt ihre Herkunft nicht');
    assertTrue(str_contains(rspFunktion($js, 'initResponsesTab'), "showCreateAppointmentModal('responses')"),
        'Termine-Tab uebergibt seine Herkunft nicht');
    assertTrue(str_contains(rspFunktion($js, 'showEditAppointmentModal'), 'appointmentModalOrigin = origin'),
        'Bearbeiten merkt sich seine Herkunft nicht');
    assertTrue(str_contains(rspFunktion($js, 'initAttendanceList'), "showEditAppointmentModal('attendance')"),
        'Als direkte Referenz bekaeme Bearbeiten das Click-Event als Herkunft');
});

test('PWA OI-123: Plus-Knopf nur fuer Verwalter, bei der Abmeldung wieder verborgen', function () use ($rspRoot) {
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

    $init = rspFunktion($js, 'initResponsesTab');
    assertTrue(str_contains($init, 'btnAddAppointment') && str_contains($init, 'isPwaManager()'),
        'initResponsesTab blendet den Knopf nicht nach Rolle ein');
    // Erst nach der Schalterpruefung: ohne Terminplanung bleibt er verborgen.
    $schalter = strpos($init, "pwaFeatureOn('appointments')");
    $knopf    = strpos($init, 'btnAddAppointment');
    assertTrue($schalter !== false && $knopf !== false && $schalter < $knopf,
        'Der Knopf wird vor der Schalterpruefung eingeblendet');

    assertTrue(str_contains(rspFunktion($js, 'resetResponsesTab'), 'btnAddAppointment'),
        'Nach Abmelden und Anmelden als Mitglied stuende der Knopf noch da');

    $rolle = rspFunktion($js, 'isPwaManager');
    assertTrue(str_contains($rolle, "'admin'") && str_contains($rolle, "'manager'"), 'Rollenpruefung unvollstaendig');
});

test('PWA OI-123: Termine-Tab bleibt fuer Verwalter auch ohne Termine sichtbar', function () use ($rspRoot) {
    $rumpf = rspFunktion((string) sourceCode($rspRoot . '/public/checkin/js/app.js'), 'loadResponses');
    assertTrue((bool) preg_match('/tab\.hidden\s*=\s*!hasResponses\s*&&\s*!isPwaManager\(\)/', $rumpf),
        'Ohne kommende Termine verschwaende der Tab samt Plus-Knopf');
    assertTrue(str_contains($rumpf, 'tab.hidden && wasActiveTab'),
        'Rueckfall auf den Erfassen-Tab muss an der tatsaechlichen Sichtbarkeit haengen');
});

test('PWA OI-123: aus dem Termine-Tab kein „jetzt“ vorbelegen', function () use ($rspRoot) {
    $rumpf = rspFunktion((string) sourceCode($rspRoot . '/public/checkin/js/app.js'), 'showCreateAppointmentModal');
    assertTrue(str_contains($rumpf, 'appointmentModalOrigin = origin'), 'Herkunft wird nicht gemerkt');
    assertTrue((bool) preg_match("/if \(origin === 'attendance'\) \{[^}]*new Date\(\)/", $rumpf),
        '„jetzt“ darf nur aus der Anwesenheitsliste vorbelegt werden');
});

test('PWA OI-123: nach dem Speichern aus dem Termine-Tab Liste neu laden und Unsichtbares melden', function () use ($rspRoot) {
    $rumpf = rspFunktion((string) sourceCode($rspRoot . '/public/checkin/js/app.js'), 'submitAppointmentForm');
    assertTrue((bool) preg_match("/appointmentModalOrigin === 'responses'[\s\S]{0,200}await loadResponses\(\)/", $rumpf),
        'Der Termine-Tab wird nach dem Speichern nicht neu geladen');
    assertTrue(str_contains($rumpf, 'upcomingResponses.some('),
        'Ohne Sichtbarkeitspruefung meldet die App „erstellt“ fuer einen Termin, der nirgends erscheint');
    assertTrue(str_contains($rumpf, 'nicht in deiner Liste'), 'Meldung fuer den unsichtbaren Termin fehlt');
});

test('PWA OI-123: Tab Termine fuer Verwalter schon vor dem Laden sichtbar', function () use ($rspRoot) {
    $init = rspFunktion((string) sourceCode($rspRoot . '/public/checkin/js/app.js'), 'initResponsesTab');
    $sichtbar = strpos($init, 'isPwaManager()) respTab.hidden = false');
    $laden    = strpos($init, 'await loadResponses()');
    assertTrue($sichtbar !== false && $laden !== false && $sichtbar < $laden,
        'Scheitert das erste Laden, fehlten dem Verwalter Tab und Plus-Knopf');
});

test('PWA OI-123: gescheitertes Neuladen meldet keinen unsichtbaren Termin', function () use ($rspRoot) {
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');
    assertTrue(str_contains(rspFunktion($js, 'loadResponses'), 'return false'), 'loadResponses meldet Fehler nicht zurueck');
    assertTrue(str_contains(rspFunktion($js, 'submitAppointmentForm'), '!geladen'),
        'Ohne frische Liste darf die App nicht behaupten, der Termin sei unsichtbar');
    assertTrue(str_contains(rspFunktion($js, 'submitAppointmentForm'), 'acht Wochen'),
        'Die Meldung muss den Horizont von acht Wochen nennen');
});

test('PWA OI-123: Dialoge rollen selbst, der Hintergrund steht still', function () use ($rspRoot) {
    $css = (string) sourceCode($rspRoot . '/public/checkin/css/style.css');

    // Der Termin-Dialog hat sechs Felder: Bei 560 px Hoehe ragte er oben
    // 93 px aus dem Bild, „Speichern“ war nicht erreichbar.
    assertTrue((bool) preg_match('/\n\.modal-content\s*\{[^}]*max-height:[^}]*overflow-y:\s*auto/', $css),
        '.modal-content braucht max-height und overflow-y: auto -- fuer alle Dialoge, nicht nur einen');
    assertTrue((bool) preg_match('/body:has\(\.modal\.active\)\s*\{[^}]*overflow:\s*hidden/', $css),
        'Bei offenem Dialog rollt sonst die ausgeblendete Seite dahinter');
});

test('PWA: Termin-Dialog sperrt Speichern waehrend des Aufrufs', function () use ($rspRoot) {
    $rumpf = rspFunktion((string) sourceCode($rspRoot . '/public/checkin/js/app.js'), 'submitAppointmentForm');

    // Ein Doppeltipp bei langsamem Netz legte den Termin an und zeigte danach
    // im noch offenen Dialog die Dublette (409) des zweiten Aufrufs.
    $sperre  = strpos($rumpf, 'knopf.disabled = true');
    $aufruf  = strpos($rumpf, "apiCall('appointments'");
    assertTrue($sperre !== false && $aufruf !== false && $sperre < $aufruf,
        'Der Speichern-Knopf wird nicht vor dem Aufruf gesperrt');
    assertTrue((bool) preg_match('/finally\s*\{[^}]*knopf\.disabled = false/', $rumpf),
        'Ohne finally bliebe der Knopf nach einem Fehler gesperrt');
    assertTrue(str_contains($rumpf, "getElementById('btnSaveAppointment')"), 'Gesperrt wird nicht der Speichern-Knopf');
});

test('PWA: Termin-Dialog gibt den Fokus an seinen Knopf zurueck', function () use ($rspRoot) {
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');

    $schliessen = rspFunktion($js, 'closeAppointmentModal');
    assertTrue(str_contains($schliessen, 'appointmentModalOpener') && str_contains($schliessen, '.focus()'),
        'closeAppointmentModal setzt den Fokus nicht zurueck');

    // Alle drei Wege aus dem Dialog laufen ueber closeAppointmentModal().
    assertTrue(substr_count(rspFunktion($js, 'initAppointmentModal'), 'closeAppointmentModal()') === 2,
        'Abbrechen und Klick daneben schliessen nicht ueber closeAppointmentModal()');
    assertTrue(str_contains(rspFunktion($js, 'submitAppointmentForm'), 'closeAppointmentModal()'),
        'Speichern schliesst nicht ueber closeAppointmentModal()');
    assertTrue(!str_contains($js, "getElementById('appointmentModal').classList.remove('active')"),
        'Noch eine Stelle schliesst den Dialog am Fokus vorbei');

    // Gemerkt wird der Knopf selbst, nicht document.activeElement: Safari
    // fokussiert Knoepfe beim Antippen nicht.
    assertTrue(str_contains(rspFunktion($js, 'showCreateAppointmentModal'), 'appointmentModalOpener ='),
        'Anlegen merkt sich seinen Knopf nicht');
    $bearbeiten = rspFunktion($js, 'showEditAppointmentModal');
    assertTrue(str_contains($bearbeiten, "'btnEditAppointment'") && str_contains($bearbeiten, "'btnAddAppointment'"),
        'Bearbeiten merkt sich seinen Knopf nicht (Anwesenheitsliste bzw. Plus-Knopf im Tab Termine)');
});

test('PWA: Plus-Knopf hat 44 px Tippflaeche und ein gezeichnetes Plus', function () use ($rspRoot) {
    $css = (string) sourceCode($rspRoot . '/public/checkin/css/style.css');
    assertTrue((bool) preg_match('/\n\.btn-add-appointment\s*\{[^}]*width:\s*44px;[^}]*height:\s*44px;/', $css),
        'Unter 44 px ist der Knopf auf dem Telefon schwer zu treffen');

    // Ein Textzeichen „+“ sitzt je nach Schrift versetzt (gemessen 1 px zu
    // hoch); zwei Balken liegen auf jeder Plattform mittig.
    assertTrue(str_contains($css, '.btn-add-appointment::before') && str_contains($css, '.btn-add-appointment::after'),
        'Das Plus wird nicht gezeichnet');
    $html = (string) sourceCode($rspRoot . '/public/checkin/index.html');
    assertTrue((bool) preg_match('/id="btnAddAppointment"[^>]*>\s*<\/button>/', $html),
        'Neben dem gezeichneten Plus stuende noch das Zeichen');
});

// ---------------------------------------------------------------------------
// OI-125: kommende Termine im Tab „Termine“ bearbeiten und loeschen.
// ---------------------------------------------------------------------------

test('PWA OI-125: Bearbeiten nur fuer Verwalter und nur vor Beginn', function () use ($rspRoot) {
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');
    $rumpf = rspFunktion($js, 'managerActionsHtml');
    assertTrue(str_contains($rumpf, 'isPwaManager()') && str_contains($rumpf, 'item.started'),
        'Bearbeiten haengt nicht an Rolle und Beginn');
    assertTrue(str_contains($rumpf, 'response-card__edit'), 'Knopf fehlt');
    assertTrue(str_contains(rspFunktion($js, 'infoCardHtml'), 'managerActionsHtml(item)'), 'Infokarte ohne Bearbeiten');
    assertTrue(str_contains(rspFunktion($js, 'responseCardHtml'), 'managerActionsHtml(item)'), 'Rueckmeldekarte ohne Bearbeiten');

    $klick = rspFunktion($js, 'onResponsesClick');
    assertTrue(str_contains($klick, "classList.contains('response-card__edit')")
        && str_contains($klick, "showEditAppointmentModal('responses', appointmentId)"),
        'Der Klick auf Bearbeiten oeffnet den Dialog nicht');
    // Vor der Kommentarlogik: die Rueckmeldekarte haette sonst kein
    // .response-comment-details mehr fuer einen Verwalter-Knopf.
    assertTrue(strpos($klick, 'response-card__edit') < strpos($klick, 'response-comment-details'),
        'Bearbeiten muss vor der Kommentarlogik abzweigen');
});

test('PWA OI-125: Loeschen nur beim Bearbeiten aus dem Tab Termine', function () use ($rspRoot) {
    $html = (string) sourceCode($rspRoot . '/public/checkin/index.html');
    $tag = rspTag($html, 'id="appointmentDeleteRow"');
    assertTrue(str_contains($tag, ' hidden'), 'Loeschen muss verborgen starten');
    assertTrue(str_contains($html, 'id="btnDeleteAppointment"'), 'Loeschen-Knopf fehlt');

    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');
    assertTrue(str_contains(rspFunktion($js, 'showCreateAppointmentModal'), "getElementById('appointmentDeleteRow').hidden = true"),
        'Beim Anlegen stuende Loeschen im Dialog');
    assertTrue(str_contains(rspFunktion($js, 'showEditAppointmentModal'), "getElementById('appointmentDeleteRow').hidden = origin !== 'responses'"),
        'Loeschen haengt nicht an der Herkunft');
    assertTrue(str_contains(rspFunktion($js, 'initAppointmentModal'), "'btnDeleteAppointment'"),
        'Loeschen-Knopf nicht gebunden');
});

test('PWA OI-125: Loeschen prueft Beginn und Erfassungen vor der Rueckfrage', function () use ($rspRoot) {
    $rumpf = rspFunktion((string) sourceCode($rspRoot . '/public/checkin/js/app.js'), 'deleteAppointmentFromModal');
    $beginn    = strpos($rumpf, 'new Date()');
    $abruf     = strpos($rumpf, 'dependents: 1');
    $records   = strpos($rumpf, 'dependents.records > 0');
    $rueckfrage = strpos($rumpf, 'openConfirmDeleteModal(');
    assertTrue($beginn !== false && $abruf !== false && $records !== false && $rueckfrage !== false,
        'Pruefung auf Beginn, Zaehlungen, Erfassungen oder Rueckfrage fehlt');
    assertTrue($beginn < $abruf && $abruf < $records && $records < $rueckfrage,
        'Reihenfolge: Beginn, Zaehlungen holen, Erfassungen pruefen, dann fragen');
    assertTrue(str_contains($rumpf, 'im Dashboard'), 'Bei Erfassungen fehlt der Verweis aufs Dashboard');
    assertTrue((bool) preg_match('/finally\s*\{[^}]*knopf\.disabled = false/', $rumpf),
        'Der Loeschen-Knopf bliebe nach einem Fehler gesperrt');
});

test('PWA OI-125: Rueckfrage nennt Folgen, Serie und Einzahl', function () use ($rspRoot) {
    $rumpf = rspFunktion((string) sourceCode($rspRoot . '/public/checkin/js/app.js'), 'appointmentDeleteQuestion');
    assertTrue(str_contains($rumpf, "'1 Rückmeldung'") && str_contains($rumpf, "'1 Antrag'"), 'Einzahl fehlt');
    assertTrue(str_contains($rumpf, 'Rückmeldungen') && str_contains($rumpf, 'Anträge'), 'Mehrzahl fehlt');
    assertTrue(str_contains($rumpf, 'series_id') && str_contains($rumpf, 'die Serie bleibt bestehen'),
        'Serienhinweis fehlt');
});

test('PWA OI-125: Bestaetigungsdialog nimmt Text und Aktion', function () use ($rspRoot) {
    $js = (string) sourceCode($rspRoot . '/public/checkin/js/app.js');
    $oeffnen = rspFunktion($js, 'openConfirmDeleteModal');
    assertTrue(str_contains($oeffnen, 'onConfirm') && str_contains($oeffnen, 'confirmDeleteText'),
        'openConfirmDeleteModal uebernimmt Text oder Aktion nicht');
    assertTrue(!str_contains($js, 'deleteExceptionId'), 'Der feste Antrags-Weg lebt noch neben dem allgemeinen');
    assertTrue(str_contains(rspFunktion($js, 'deleteException'), 'openConfirmDeleteModal('),
        'Antrag loeschen nutzt den allgemeinen Dialog nicht');
    assertTrue(str_contains((string) sourceCode($rspRoot . '/public/checkin/index.html'), 'id="confirmDeleteText"'),
        'Textfeld im Bestaetigungsdialog fehlt');
});

test('PWA OI-125: nach dem Loeschen Liste neu laden, Fokus auf Plus', function () use ($rspRoot) {
    $rumpf = rspFunktion((string) sourceCode($rspRoot . '/public/checkin/js/app.js'), 'confirmDeleteAppointment');
    assertTrue(str_contains($rumpf, "apiCall('appointments', 'DELETE'"), 'Kein DELETE');
    assertTrue(str_contains($rumpf, 'closeAppointmentModal()') && str_contains($rumpf, 'await loadResponses()'),
        'Dialog schliessen und Liste neu laden fehlt');
    assertTrue(str_contains($rumpf, 'status === 404'), 'Ein inzwischen geloeschter Termin waere ein Fehler');
});

test('PWA OI-125: Bearbeiten aus dem Tab Termine meldet „aktualisiert“', function () use ($rspRoot) {
    $rumpf = rspFunktion((string) sourceCode($rspRoot . '/public/checkin/js/app.js'), 'submitAppointmentForm');
    $start = (int) strpos($rumpf, "appointmentModalOrigin === 'responses'");
    // Nur der Zweig des Tabs Termine, nicht die Anwesenheitsliste dahinter.
    $zweig = substr($rumpf, $start, (int) strpos($rumpf, 'return;', $start) - $start);
    assertTrue(str_contains($zweig, "neu ? 'Termin erstellt' : 'Termin aktualisiert'"),
        'Nach dem Bearbeiten stuende „Termin erstellt“');
});
