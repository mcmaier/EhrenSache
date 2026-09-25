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
 * Statische Gegenproben der Oberflaeche fuer die Terminrueckmeldung.
 *
 * Dashboard und PWA haben keinen eigenen JS-Testlauf; pruefbar bleibt die
 * Verdrahtung zwischen HTML, Modul und Serverantwort.
 */

$rsRoot = dirname(__DIR__, 2);

/** Das <input …>-Tag, das $needle enthaelt. */
function rsfTag(string $html, string $needle): string
{
    $pos = strpos($html, $needle);
    assertTrue($pos !== false, "{$needle} fehlt");
    $start = strrpos(substr($html, 0, $pos), '<');
    $end   = strpos($html, '>', $pos);

    return substr($html, $start, $end - $start + 1);
}

test('Einstellungskarte: Frist mit demselben Bereich wie der Server', function () use ($rsRoot) {
    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    $tag  = rsfTag($html, 'data-key="response_deadline_hours"');

    assertTrue(str_contains($tag, 'type="number"'), 'Zahlenfeld erwartet');
    assertTrue(str_contains($tag, 'min="0"'), 'min muss 0 sein wie responseHoursFromRaw()');
    assertTrue(str_contains($tag, 'max="720"'), 'max muss 720 sein wie RESPONSE_DEADLINE_MAX_HOURS');
});

test('Die Frist ist fuer Neuinstallationen angelegt', function () use ($rsRoot) {
    // Ein fehlender Schluessel laesst das Zahlenfeld leer, und settings.js
    // blockiert dann das Speichern ALLER Einstellungen.
    $sql = (string) file_get_contents($rsRoot . '/private/setup/ehrensache_db.sql');
    assertTrue(str_contains($sql, "('response_deadline_hours',"), 'Schluessel fehlt im Insert-Block');
});

test('saveAllSettings meldet eine serverseitige Ablehnung nicht als gespeichert', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/settings.js');

    // Die Funktion wird schon vorher als Event-Listener referenziert
    // ("saveBtn.addEventListener('click', saveAllSettings)") -- die Definition
    // beginnt erst bei "function saveAllSettings".
    $start = strpos($js, 'function saveAllSettings');
    assertTrue($start !== false, 'saveAllSettings() fehlt');
    $ende  = strpos($js, 'function ', $start + strlen('function saveAllSettings'));
    assertTrue($ende !== false, 'Ende von saveAllSettings() nicht gefunden');
    $body  = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, '.success'),
        'saveAllSettings() muss result.success (oder !result) pruefen, bevor es Erfolg meldet');
});

test('Terminart-Modal fuehrt die vier Rueckmeldungs-Felder', function () use ($rsRoot) {
    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    foreach (['type_responses_enabled', 'type_responses_names_visible',
              'type_responses_require_excuse', 'type_response_deadline_hours'] as $id) {
        assertTrue(str_contains($html, "id=\"{$id}\""), "Feld {$id} fehlt im Terminart-Modal");
    }

    $tag = rsfTag($html, 'id="type_response_deadline_hours"');
    assertTrue(str_contains($tag, 'min="0"') && str_contains($tag, 'max="720"'), 'Bereich wie der Server');
});

test('management.js schickt die vier Felder beim Speichern', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/management.js');
    foreach (['responses_enabled:', 'responses_names_visible:', 'responses_require_excuse:', 'response_deadline_hours:'] as $key) {
        assertTrue(str_contains($js, $key), "{$key} fehlt in saveType()");
    }
});

test('renderTypeGroupOverview maskiert Terminart-Werte und die Gruppennamen aus loadTypeGroup', function () use ($rsRoot) {
    // Keine CSP (OI-17): type_name/description kommen frei aus der DB, ebenso
    // die Gruppennamen, die loadTypeGroup() nachtraeglich in die Zelle rendert.
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/management.js');

    $start = strpos($js, 'export async function renderTypeGroupOverview');
    assertTrue($start !== false, 'renderTypeGroupOverview fehlt');
    $ende = strpos($js, 'async function loadTypeGroup', $start);
    assertTrue($ende !== false, 'Ende von renderTypeGroupOverview nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'escapeHtml(type.type_name)'), 'type.type_name wird nicht maskiert');
    assertTrue(str_contains($body, 'escapeHtml(type.description)'), 'type.description wird nicht maskiert');
    assertTrue(!preg_match('/background:\s*\$\{type\.color\}/', $body),
        'type.color landet ungeprueft im style-Attribut -- muss gegen ein Hexformat geprueft werden (wie in appointments.js)');

    $groupFnStart = strpos($js, 'async function loadTypeGroup');
    $groupFnEnde = strpos($js, 'function ', $groupFnStart + strlen('async function loadTypeGroup'));
    assertTrue($groupFnEnde !== false, 'Ende von loadTypeGroup nicht gefunden');
    $groupFnBody = substr($js, $groupFnStart, $groupFnEnde - $groupFnStart);
    assertTrue(str_contains($groupFnBody, 'escapeHtml(g.group_name)'), 'Gruppenname in loadTypeGroup() wird nicht maskiert');
});

test('Terminliste hat die Spalte Rueckmeldung und passende colspan', function () use ($rsRoot) {
    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    assertTrue(str_contains($html, '<th>Rückmeldung</th>'), 'Spaltenkopf fehlt');

    // Die urspruengliche Form verbot colspan="4", weil FI-1 die Liste mit der
    // Spalte "Rueckmeldung" von vier auf fuenf Spalten wachsen liess -- ein
    // stehengebliebenes colspan="4" war damals der Rueckstand.
    // Seit OI-94 entfaellt dafuer die Spalte "Terminart": Die Terminliste hat
    // wieder vier Spalten (Termin, Beschreibung, Rueckmeldung, Aktionen), und
    // der Rueckstand waere jetzt ein colspan="5". Dieselbe Zusicherung dreht
    // sich deshalb ein zweites Mal um.
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/appointments.js');
    assertTrue(str_contains($js, 'colspan="4"'), 'appointments.js ueberspannt nicht vier Spalten');
    assertTrue(!str_contains($js, 'colspan="5"'), 'appointments.js rendert noch fuenf Spalten');
});

test('updateTableHeaders() in ui.js fuehrt Rueckmeldung fuer die Terminliste', function () use ($rsRoot) {
    // updateTableHeaders() baut das thead nach jedem Login neu auf und
    // ueberschrieb dabei den korrekten Header aus index.html -- ohne
    // 'Rückmeldung' in diesem Array verschieben sich die Spalten gegen die
    // Zeilen aus appointments.js.
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/ui.js');
    $start = strpos($js, "id: 'appointmentsTableBody'");
    assertTrue($start !== false, 'appointmentsTableBody fehlt in updateTableHeaders()');
    $end = strpos($js, ']', $start);
    assertTrue($end !== false, 'Ende der headers-Liste nicht gefunden');
    $entry = substr($js, $start, $end - $start);
    assertTrue(str_contains($entry, 'Rückmeldung'), "'Rückmeldung' fehlt im headers-Array von appointmentsTableBody");
});

test('Rueckmeldungs-Modal ist eingebunden', function () use ($rsRoot) {
    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    assertTrue(str_contains($html, 'id="responsesModal"'), 'Modal fehlt');
    assertTrue(str_contains($html, 'src="./js/modules/responses.js"'), 'Modul nicht geladen');

    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');
    foreach (['openResponsesModal', 'closeResponsesModal', 'setOwnResponse', 'setMemberResponse'] as $fn) {
        assertTrue(str_contains($js, "window.{$fn} = {$fn}"), "{$fn} ist nicht global erreichbar");
    }
});

test('responses.js maskiert Namen und Bemerkungen', function () use ($rsRoot) {
    // Keine CSP (OI-17): Bemerkungen sind freie Eingaben von Mitgliedern.
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains($js, 'escapeHtml(m.comment'), 'Bemerkung wird nicht maskiert');
    assertTrue(str_contains($js, 'escapeHtml(m.surname'), 'Name wird nicht maskiert');
});

test('setMemberResponse sendet die bestehende Bemerkung des Mitglieds mit (W1)', function () use ($rsRoot) {
    // Ein Verwalter, der nur den Status setzt, darf die Bemerkung des Mitglieds
    // nicht loeschen. Statischer Beleg statt eines echten JS-Testlaufs: die
    // Funktion muss current.members nach der bestehenden Bemerkung durchsuchen
    // und sie in existingComment/comment weiterreichen, statt fest null zu senden.
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');

    $start = strpos($js, 'export async function setMemberResponse');
    assertTrue($start !== false, 'setMemberResponse fehlt');
    $ende = strpos($js, 'export function', $start + 1);
    assertTrue($ende !== false, 'Ende von setMemberResponse nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'member?.comment'), 'die bestehende Bemerkung des Mitglieds wird nicht gelesen');
    assertTrue(str_contains($body, 'existingComment'), 'existingComment fehlt -- Vorgabe fuer den Prompt bei Absage');
    assertTrue(!preg_match('/let comment = null;/', $body), 'comment darf nicht mehr fest auf null gesetzt werden');
});

test('responses.js sichert sich gegen ueberholte Antworten ab und laedt die Liste nur einmal neu', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains($js, 'loadToken'), 'Kein Zaehler gegen ueberholte reloadResponses()-Antworten');

    $appointmentsJs = (string) file_get_contents($rsRoot . '/public/js/modules/appointments.js');
    assertTrue(str_contains($appointmentsJs, 'window.refreshAppointmentsKeepPage'),
        'appointments.js stellt refreshAppointmentsKeepPage nicht global bereit');
    assertTrue(str_contains($js, 'refreshAppointmentsKeepPage'),
        'responses.js ruft refreshAppointmentsKeepPage nicht auf');
});

test('Terminliste und Modal zeigen die Ampel als Chips', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains($js, 'response-chip'), "'response-chip' fehlt -- die Ampel wird nicht mehr als Chip-Gruppe gerendert");
});

test('Modal setzt Mitglieder-Rueckmeldungen ueber die bestehenden Icon-Aktionen statt eines Auswahlfelds', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains($js, 'action-btn btn-icon'),
        "Mitglieder-Aktionen im Modal nutzen nicht die bestehenden Klassen 'action-btn btn-icon'");
    assertTrue(!str_contains($js, '<select class="response-set"'), 'Das fruehere Auswahlfeld response-set ist noch vorhanden');
    assertTrue(!str_contains($js, 'type="radio"'), 'responses.js enthaelt noch ein Radio-Eingabefeld -- der Filter muss die Segmentgruppe sein');
});

test('Kalender-Popup zeigt die Rueckmeldung ueber responseChipsHtml und maskiert Termindaten', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/appointments.js');

    assertTrue(str_contains($js, "import { responseSummaryCell, responseChipsHtml, responseSummaryTitle, RESPONSE_ICONS, RESPONSE_LABELS } from './responses.js';"),
        'appointments.js importiert die Ampel-Bausteine nicht aus responses.js');

    $start = strpos($js, 'function showAppointmentPopup');
    assertTrue($start !== false, 'showAppointmentPopup fehlt');
    $ende = strpos($js, 'function previousMonth', $start);
    assertTrue($ende !== false, 'Ende von showAppointmentPopup nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'escapeHtml(apt.title)'), 'apt.title wird im Popup nicht maskiert');
    assertTrue(str_contains($body, 'escapeHtml(apt.type_name)'), 'apt.type_name wird im Popup nicht maskiert');
    assertTrue(str_contains($body, 'escapeHtml(apt.description)'), 'apt.description wird im Popup nicht maskiert');
    assertTrue(str_contains($body, 'calendarResponseLineHtml'), 'Popup ruft calendarResponseLineHtml() nicht auf');

    $responses = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains($responses, 'export function responseChipsHtml'), 'responseChipsHtml ist nicht exportiert');
    assertTrue(str_contains($responses, 'export function responseSummaryTitle'), 'responseSummaryTitle ist nicht exportiert');
});

test('calendarResponseLineHtml() macht die Ampel nur im festgehaltenen Popup anklickbar', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/appointments.js');

    $start = strpos($js, 'function calendarResponseLineHtml');
    assertTrue($start !== false, 'calendarResponseLineHtml fehlt');
    $ende = strpos($js, 'function ', $start + strlen('function calendarResponseLineHtml'));
    assertTrue($ende !== false, 'Ende von calendarResponseLineHtml nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'fest && (r.expected || isAdminOrManager)'),
        'Klickbarkeit folgt nicht derselben Regel wie responseSummaryCell() (erwartet oder Verwaltung, nur im festen Popup)');
    assertTrue(str_contains($body, "document.querySelector('.calendar-event-popup')?.remove()"),
        'Der Klick auf die Ampel im Popup entfernt das Popup nicht explizit, bevor das Modal oeffnet');
    assertTrue(str_contains($body, 'window.openResponsesModal('), 'Klick oeffnet das Rueckmeldungs-Modal nicht');
});

test('Kalendertag zeigt einen Rueckmeldungs-Punkt und die Hervorhebung fuer offene Rueckmeldungen', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/appointments.js');

    assertTrue(str_contains($js, "'calendar-response-dot'"), 'createCalendarDay() setzt keine calendar-response-dot-Klasse');
    assertTrue(str_contains($js, 'calendar-response-dot--open'), 'Hervorhebungs-Variante fuer offene Rueckmeldungen fehlt');
    assertTrue(str_contains($js, 'responses.expected === true && !a.responses.own'),
        'Bedingung fuer offene Rueckmeldung (erwartet, keine eigene Antwort) fehlt');
    assertTrue(str_contains($js, 'Rückmeldung offen'), 'aria-label-Zusatz fuer offene Rueckmeldung fehlt');
    assertTrue(str_contains($js, 'responseSummaryTitle(a.responses)'), 'aria-label fuehrt responseSummaryTitle je Termin nicht mit');

    $css = (string) file_get_contents($rsRoot . '/public/css/components/calendar.css');
    assertTrue(str_contains($css, '.calendar-response-dot'), 'calendar.css enthaelt keine Regel fuer calendar-response-dot');
    assertTrue(str_contains($css, '.calendar-response-dot--open'), 'calendar.css enthaelt keine Regel fuer calendar-response-dot--open');
});

test('responses.js nutzt keinen window.prompt mehr fuer die Begruendung', function () use ($rsRoot) {
    // Ersetzt durch den eigenen Begruendungsdialog (showReasonDialog) --
    // window.prompt() wird in einer installierten PWA teils unterdrueckt und
    // passt sich nicht der Optik der Oberflaeche an.
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');
    assertTrue(!str_contains($js, 'window.prompt'), 'responses.js ruft noch window.prompt() auf');
    assertTrue(!preg_match('/(?<!window\\.)\\bprompt\\(/', $js), 'responses.js ruft noch prompt() auf');
    assertTrue(str_contains($js, 'showReasonDialog'), 'setMemberResponse nutzt showReasonDialog nicht');
});

test('ui.js stellt showReasonDialog bereit und index.html fuehrt das zugehoerige Modal', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/ui.js');
    assertTrue(str_contains($js, 'export async function showReasonDialog') || str_contains($js, 'export function showReasonDialog'),
        'ui.js exportiert showReasonDialog nicht');

    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    assertTrue(str_contains($html, 'id="reasonModal"'), 'reasonModal fehlt in index.html');
});

test('showAppointmentPopup klammert gegen documentElement.clientWidth/clientHeight statt window.innerWidth/innerHeight', function () use ($rsRoot) {
    // window.innerWidth/innerHeight schliessen die Breite eines sichtbaren
    // Scrollbalkens mit ein -- das Popup lief dort unter dem Scrollbalken
    // hinaus statt daneben umzuklappen.
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/appointments.js');

    $start = strpos($js, 'function showAppointmentPopup');
    assertTrue($start !== false, 'showAppointmentPopup fehlt');
    $ende = strpos($js, 'function previousMonth', $start);
    assertTrue($ende !== false, 'Ende von showAppointmentPopup nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'documentElement.clientWidth'), 'Klammerung nutzt nicht documentElement.clientWidth');
    assertTrue(str_contains($body, 'documentElement.clientHeight'), 'Klammerung nutzt nicht documentElement.clientHeight');
    assertTrue(!str_contains($body, 'window.innerWidth') && !str_contains($body, 'window.innerHeight'),
        'showAppointmentPopup verwendet noch window.innerWidth/innerHeight');
});

test('deadlineText() erklaert vergangene Termine als abgeschlossen', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');

    $start = strpos($js, 'function deadlineText');
    assertTrue($start !== false, 'deadlineText fehlt');
    $ende = strpos($js, 'function renderResponsesModal', $start);
    assertTrue($ende !== false, 'Ende von deadlineText nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'vorbei'), "Text fuer laengst vergangene Termine fehlt ('vorbei')");
    assertTrue(str_contains($body, 'Der Termin hat begonnen.'), 'Text fuer heute begonnene Termine fehlt');
});

test('ownResponseHtml zeigt bei begonnenen Terminen die kompakte, nur lesende Zeile', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');

    $start = strpos($js, 'function ownResponseHtml');
    assertTrue($start !== false, 'ownResponseHtml fehlt');
    $ende = strpos($js, 'function ownResponseCompactHtml', $start);
    assertTrue($ende !== false, 'Ende von ownResponseHtml nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'data.started'), 'ownResponseHtml prueft data.started nicht');
    assertTrue(str_contains($body, 'ownResponseCompactHtml'), 'ownResponseHtml ruft die kompakte Variante nicht auf');
    assertTrue(!str_contains($body, 'disabled'),
        'Segmentgruppe/Textfeld des vollen Blocks brauchen kein disabled mehr -- der Block erscheint nur noch vor Terminbeginn');

    $compactStart = strpos($js, 'function ownResponseCompactHtml');
    assertTrue($compactStart !== false, 'ownResponseCompactHtml fehlt');
    $compactEnde = strpos($js, 'function comparisonHtml', $compactStart);
    assertTrue($compactEnde !== false, 'Ende von ownResponseCompactHtml nicht gefunden');
    $compactBody = substr($js, $compactStart, $compactEnde - $compactStart);

    assertTrue(str_contains($compactBody, 'Meine Rückmeldung:'), "Kompakte Zeile fuehrt 'Meine Rückmeldung:' nicht");
    assertTrue(str_contains($compactBody, 'statusBadge('), 'Kompakte Zeile nutzt den bestehenden Status-Badge nicht');
    assertTrue(str_contains($compactBody, 'response-own-compact__comment'), 'Kompakte Zeile zeigt die Bemerkung nicht in eigener Klasse');
    assertTrue(str_contains($compactBody, 'Entschuldigung:'), 'Kompakte Zeile fuehrt den Entschuldigungsstatus nicht');
    assertTrue(str_contains($compactBody, "own?.status === 'no' && own?.is_late"), "'kurzfristig' bleibt nicht auf Absage+is_late beschraenkt");
});

test('namesListHtml gliedert Antworten anderer Mitglieder nach Gruppe als Chips', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');

    $start = strpos($js, 'function namesListHtml');
    assertTrue($start !== false, 'namesListHtml fehlt');
    $ende = strpos($js, 'export function filterResponses', $start);
    assertTrue($ende !== false, 'Ende von namesListHtml nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(!str_contains($body, '<ul'), 'namesListHtml rendert noch eine <ul>-Liste mit Aufzaehlungspunkten');
    assertTrue(str_contains($body, 'response-name-chip'), "namesListHtml nutzt die Klasse 'response-name-chip' nicht");
    assertTrue(str_contains($body, 'response-names-grouped'), "Aeusserer Rahmen 'response-names-grouped' fehlt");
    assertTrue(str_contains($body, 'escapeHtml(label)'), 'Gruppenname wird nicht maskiert');
    assertTrue(str_contains($body, 'Ohne Gruppe'), "Mitglieder ohne Gruppe fehlt 'Ohne Gruppe'");
    assertTrue(str_contains($body, 'responseChipsHtml('), 'Gruppenkopf nutzt responseChipsHtml() nicht fuer die Ampel-Zeile');

    $css = (string) file_get_contents($rsRoot . '/public/css/components/badges.css');
    foreach (['--yes', '--maybe', '--no', '--open'] as $suffix) {
        assertTrue(str_contains($css, "response-name-chip{$suffix}"), "badges.css fuehrt response-name-chip{$suffix} nicht");
    }

    $modalsCss = (string) file_get_contents($rsRoot . '/public/css/components/modals.css');
    assertTrue(str_contains($modalsCss, 'response-names-grouped'), 'modals.css fuehrt das Layout response-names-grouped nicht');
});

test('OI-63: Das Modal oeffnet gesperrt', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');

    $start = strpos($js, 'export async function openResponsesModal');
    assertTrue($start !== false, 'openResponsesModal fehlt');
    $ende = strpos($js, 'export function closeResponsesModal', $start);
    assertTrue($ende !== false, 'Ende von openResponsesModal nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'locked = true'),
        'openResponsesModal setzt locked nicht auf true -- der Dialog muss bei jedem Oeffnen erneut gesperrt sein');
});

test('OI-63: Es gibt einen Sperr-Umschalter fuer den ganzen Dialog', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');

    assertTrue(str_contains($js, 'export function toggleResponsesLock'), 'toggleResponsesLock fehlt');
    assertTrue(str_contains($js, 'window.toggleResponsesLock = toggleResponsesLock'),
        'toggleResponsesLock ist nicht global erreichbar');

    $start = strpos($js, 'function responseLockToggleHtml');
    assertTrue($start !== false, 'responseLockToggleHtml fehlt');
    $ende = strpos($js, 'export function toggleResponsesLock', $start);
    assertTrue($ende !== false, 'Ende von responseLockToggleHtml nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, '<button'), 'Der Umschalter ist kein <button> -- Barrierefreiheit (aria-pressed braucht ein natives Element)');
    assertTrue(str_contains($body, 'aria-pressed='), 'aria-pressed fehlt am Umschalter');
    assertTrue(str_contains($body, '🔒') && str_contains($body, '🔓'), 'Beide Zustaende (gesperrt/entsperrt) fehlen als Symbol');

    $css = (string) file_get_contents($rsRoot . '/public/css/components/buttons.css');
    assertTrue(str_contains($css, '.response-lock-toggle'), 'buttons.css fuehrt .response-lock-toggle nicht');
});

test('OI-63: Zeilen anderer Mitglieder sind gesperrt bedienungsunfaehig, die eigene Zeile bleibt frei', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');

    assertTrue(str_contains($js, 'function isOwnMember'), 'isOwnMember fehlt -- eigene Zeile des Verwalters muss erkannt werden');
    assertTrue(str_contains($js, 'currentUser?.member_id'), 'isOwnMember prueft nicht gegen currentUser.member_id');

    $start = strpos($js, 'function memberActionButtons');
    assertTrue($start !== false, 'memberActionButtons fehlt');
    $ende = strpos($js, 'function responseLockToggleHtml', $start);
    assertTrue($ende !== false, 'Ende von memberActionButtons nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'locked && !isOwnMember(m.member_id)'),
        'memberActionButtons sperrt Zeilen nicht nach "gesperrt und nicht die eigene Zeile"');
    assertTrue(str_contains($body, '${disabledAttr}'), 'Die Aktionsknoepfe tragen das disabled-Attribut nicht bedingt');
    assertTrue(str_contains($body, 'Zum Ändern zuerst entsperren'), 'Der erklaerende Titel fuer gesperrte Knoepfe fehlt');

    $css = (string) file_get_contents($rsRoot . '/public/css/components/buttons.css');
    assertTrue(str_contains($css, '.response-action:disabled'), 'buttons.css stellt gesperrte Aktionsknoepfe nicht sichtbar anders dar');
});

test('OI-63: setMemberResponse verweigert fremde Aenderungen im gesperrten Zustand serverunabhaengig', function () use ($rsRoot) {
    // Der Schutzschritt darf nicht nur im disabled-Attribut stecken -- ein
    // direkter Aufruf von setMemberResponse() (Konsole, veraltetes DOM nach
    // einem Re-Render) muss ebenfalls verweigert werden.
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');

    $start = strpos($js, 'export async function setMemberResponse');
    assertTrue($start !== false, 'setMemberResponse fehlt');
    $ende = strpos($js, 'export function printResponses', $start);
    assertTrue($ende !== false, 'Ende von setMemberResponse nicht gefunden');
    $body = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, 'if (locked && !isOwnMember(memberId)) return;'),
        'setMemberResponse hat keinen fruehen Guard gegen eine gesperrte, fremde Aenderung');

    // Der Guard muss vor jedem mutierenden apiCall greifen.
    $guardPos = strpos($body, 'if (locked && !isOwnMember(memberId)) return;');
    $firstApiCallPos = strpos($body, 'apiCall(');
    assertTrue($guardPos !== false && $firstApiCallPos !== false && $guardPos < $firstApiCallPos,
        'Der Sperr-Guard steht nicht vor dem ersten apiCall()');
});
