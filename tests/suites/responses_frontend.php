<?php
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

test('Terminliste hat die Spalte Rueckmeldung und passende colspan', function () use ($rsRoot) {
    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    assertTrue(str_contains($html, '<th>Rückmeldung</th>'), 'Spaltenkopf fehlt');

    $js = (string) file_get_contents($rsRoot . '/public/js/modules/appointments.js');
    assertTrue(!str_contains($js, 'colspan="4"'), 'appointments.js rendert noch vier Spalten');
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
