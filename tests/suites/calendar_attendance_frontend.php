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
 * Statische Gegenproben: Sprung vom Kalender in die Anwesenheitsliste.
 *
 * Spec: docs/superpowers/specs/2026-09-22-kalender-anwesenheit-design.md (Schritt 1)
 *
 * Praefix caFe (statt ca): calendar_attendance_api.php definiert bereits zehn
 * globale ca*-Funktionen im selben Namensraum -- run.php laedt alle Suiten
 * hintereinander in den globalen Scope, eine Namensgleichheit waere ein
 * Fatal Error, der den gesamten Lauf abbricht.
 */

$caFeRoot = dirname(__DIR__, 2);

function caFeFile(string $root, string $rel): string
{
    $path = $root . '/' . $rel;
    assertTrue(is_file($path), "{$rel} fehlt");

    $src = file_get_contents($path);
    assertTrue($src !== false, "{$rel} konnte nicht gelesen werden");

    return (string) $src;
}

/** Rumpf einer JS-Funktion ab ihrem Namen bis zur schliessenden Klammer in Spalte 0. */
function caFeFunctionBody(string $js, string $signature): string
{
    assertSame(1, substr_count($js, $signature), "{$signature} kommt mehrfach vor");
    $start = strpos($js, $signature);
    assertTrue($start !== false, "{$signature} fehlt");

    $end = preg_match('/\n\}/', $js, $m, PREG_OFFSET_CAPTURE, $start)
        ? $m[0][1] + 2
        : strlen($js);

    return substr($js, $start, $end - $start);
}

// ---- ui.js --------------------------------------------------------------------------

test('navigateToSection ist exportiert und laedt den Bereich', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/ui.js');
    $body = caFeFunctionBody($js, 'export async function navigateToSection(');
    assertTrue(str_contains($body, "sessionStorage.setItem('currentSection', section)"), 'currentSection muss gemerkt werden');
    assertTrue(str_contains($body, 'await loadAllData()'), 'loadAllData muss abgewartet werden');
});

test('navigateToSection schliesst die Seitenleiste vor dem Laden', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/ui.js');
    $body = caFeFunctionBody($js, 'export async function navigateToSection(');

    $close = strpos($body, 'closeMobileSidebar()');
    $load = strpos($body, 'await loadAllData()');
    assertTrue($close !== false, 'closeMobileSidebar() fehlt');
    assertTrue($load !== false, 'await loadAllData() fehlt');
    assertTrue($close < $load, 'closeMobileSidebar() muss vor dem Laden stehen, sonst haengt die Seitenleiste am Handy bis zu einer Minute ueber dem Inhalt');
});

test('navigateToSection beruecksichtigt beim Rueckgabewert den Wettlauf', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/ui.js');
    $body = caFeFunctionBody($js, 'export async function navigateToSection(');

    assertTrue(str_contains($js, 'let navSeq = 0;'), 'navSeq-Zaehler fehlt');
    assertTrue(str_contains($body, '++navSeq'), 'Sequenznummer muss bei jedem Sprung erhoeht werden');
    assertTrue(str_contains($body, 'seq === navSeq'), 'Rueckgabewert muss einen ueberholten Aufruf erkennen');
});

test('Der Navigationsklick nutzt navigateToSection', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/ui.js');
    $body = caFeFunctionBody($js, 'export async function initNavigation(');
    assertTrue(str_contains($body, 'navigateToSection('), 'Klick und Sprung muessen denselben Weg gehen');
});

test('setCurrentYear kann ohne Neuladen setzen', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/ui.js');
    $body = caFeFunctionBody($js, 'export function setCurrentYear(');
    assertTrue(str_contains($body, 'reload = true'), 'Vorgabe muss das bisherige Verhalten sein');
    assertTrue(str_contains($body, 'if (reload)'), 'Neuladen muss hinter der Bedingung stehen');
});

// ---- records.js ---------------------------------------------------------------------

test('openAttendanceForAppointment ist exportiert und nutzt den Termin-Filter', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');
    assertTrue(str_contains($body, "navigateToSection('anwesenheit')"), 'Bereichswechsel fehlt');
    assertTrue(str_contains($body, 'setCurrentYear('), 'Jahr des Termins muss gesetzt werden');
    assertTrue(str_contains($body, 'reload: false'), 'Der Bereichswechsel laedt selbst -- kein zweites Laden');
    assertTrue(str_contains($body, 'enterAppointmentAttendance('), 'Gemeinsamer Weg in die Liste fehlt');
    assertTrue(str_contains($body, "'Termin nicht gefunden'"), 'Meldung fuer den unbekannten Termin fehlt');
    assertTrue(str_contains($body, 'jumpActive--'), 'Sprungtiefe muss im finally zurueckgezaehlt werden');
});

test('Der Sprung wertet den Rueckgabewert des Bereichswechsels aus', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');
    assertTrue((bool) preg_match('/if\s*\(!\s*await navigateToSection/', $body),
        'Bei false (unbekannt, Ladefehler, ueberholt) darf nicht weitergearbeitet werden');
});

test('Change-Handler und Sprung gehen denselben Weg in die Anwesenheitsliste', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'async function enterAppointmentAttendance(');
    assertTrue(str_contains($body, 'RecordMode.ATTENDANCE_BY_APPOINTMENT'), 'Modus fehlt');
    assertTrue(str_contains($body, 'loadAttendanceList('), 'Liste wird nicht geladen');
    $handlers = caFeFunctionBody($js, 'export async function initRecordEventHandlers(');
    assertTrue(str_contains($handlers, 'enterAppointmentAttendance('), 'Der Filter nutzt den gemeinsamen Weg nicht');
});

test('Rueckweg: Knopf, Ziel und Verwerfen', function () use ($caFeRoot) {
    $html = caFeFile($caFeRoot, 'public/index.html');
    assertTrue((bool) preg_match('/id="recordsBackToAppointments"[^>]*hidden/', $html), 'Knopf fehlt oder ist nicht verborgen');
    assertTrue(str_contains($html, 'onclick="backToAppointmentCalendar()"'), 'Knopf ohne Handler');

    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $back = caFeFunctionBody($js, 'export async function backToAppointmentCalendar(');
    assertTrue(str_contains($back, 'setCalendarMonth('), 'Zielmonat wird nicht gesetzt');
    assertTrue(str_contains($back, "navigateToSection('termine')"), 'Rueckweg wechselt den Bereich nicht');
    assertTrue(str_contains($js, 'window.backToAppointmentCalendar = backToAppointmentCalendar'), 'Knopf im HTML braucht die globale Zuweisung');

    $show = caFeFunctionBody($js, 'export async function showRecordsSection(');
    assertTrue(str_contains($show, 'clearAttendanceReturn()'), 'Normaler Aufruf des Bereichs muss den Rueckweg verwerfen');
    $handlers = caFeFunctionBody($js, 'export async function initRecordEventHandlers(');
    assertTrue(substr_count($handlers, 'clearAttendanceReturn()') >= 3, 'Filterwechsel muessen den Rueckweg verwerfen');
});

test('Der Sprung prueft das Datum, bevor er das Jahr setzt', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');

    $pattern = '\\d{4}-\\d{2}-\\d{2}';
    assertTrue(str_contains($body, $pattern),
        'Ohne Guard macht ein unbrauchbares Datum aus dem Jahr NaN -- setCurrentYear(NaN) legt die Anwendung lahm');

    $guard = strpos($body, $pattern);
    $set = strpos($body, 'setCurrentYear(year');
    assertTrue($set !== false, 'setCurrentYear(year, ...) fehlt');
    assertTrue($guard < $set, 'Die Pruefung muss vor dem Setzen des Jahres stehen');
});

test('Der Sprung nimmt nur eine echte Terminliste an', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');

    assertTrue(str_contains($body, 'Array.isArray(appointments)'),
        'apiCall() liefert bei HTTP-Fehler {success:false} und bei 401 null -- beides ist keine Liste');
    assertTrue(str_contains($body, "'Termine konnten nicht geladen werden'"),
        'Der Ladefehler braucht eine eigene Meldung, sonst sieht er aus wie ein fehlender Termin');
});

test('Der Sprung faengt Fehler und stellt das Jahr im finally zurueck', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');

    assertTrue(str_contains($body, '} catch ('), 'catch fehlt -- ein Fehler liesse das fremde Jahr stehen');
    assertTrue(str_contains($body, 'debug.error('), 'Fehler gehoeren in debug.error, nicht in console.error');
    assertTrue(str_contains($body, "'Anwesenheitsliste konnte nicht geoeffnet werden'"), 'Meldung im catch fehlt');

    assertTrue(str_contains($body, 'let done = false;'), 'Erfolgsmarke fehlt');
    assertTrue(str_contains($body, 'done = true;'), 'Erfolgsmarke wird nie gesetzt');

    $finally = strpos($body, '} finally {');
    assertTrue($finally !== false, 'finally fehlt');
    $tail = substr($body, $finally);
    assertTrue(str_contains($tail, '!done'), 'Das Zurueckstellen muss an der Erfolgsmarke haengen');
    assertTrue(str_contains($tail, 'setCurrentYear(previousYear'), 'Jahr wird im finally nicht zurueckgestellt');
});

test('Der Sprung zaehlt statt zu schalten', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    assertTrue(str_contains($js, 'let jumpSeq = 0;'), 'Sequenzzaehler fehlt');
    assertTrue(str_contains($js, 'let jumpActive = 0;'), 'Tiefenzaehler fehlt');
    assertTrue(!str_contains($js, 'jumpInProgress'), 'Das Boolean ist durch die Zaehler ersetzt');

    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');
    assertTrue(str_contains($body, 'const seq = ++jumpSeq;'), 'Sequenznummer wird nicht gezogen');
    assertTrue(str_contains($body, 'jumpActive++'), 'Tiefe wird nicht erhoeht');
    assertTrue(substr_count($body, 'if (seq !== jumpSeq) return;') >= 4,
        'Nach jedem await muss der ueberholte Sprung abbrechen');

    $show = caFeFunctionBody($js, 'export async function showRecordsSection(');
    assertTrue(str_contains($show, 'jumpActive === 0'), 'Der Bereich muss die Tiefe abfragen, nicht einen Zustand');
});

test('Modus und Termin stehen vor dem Bereichswechsel', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');

    $mode = strpos($body, 'setRecordMode(RecordMode.ATTENDANCE_BY_APPOINTMENT)');
    $id = strpos($body, 'currentAppointmentId = String(appointmentId)');
    $chip = strpos($body, "recordStatusChip = 'all'");
    $nav = strpos($body, "navigateToSection('anwesenheit')");

    assertTrue($mode !== false, 'Modus wird nicht gesetzt');
    assertTrue($id !== false, 'Termin-Id wird nicht gesetzt');
    assertTrue($chip !== false, 'Statusfilter wird nicht zurueckgesetzt');
    assertTrue($nav !== false, 'Bereichswechsel fehlt');

    // Sonst laedt showRecordsSection() erst die vorige Auswahl -- eine
    // komplette Liste, die niemand sehen will -- und danach die richtige.
    assertTrue($mode < $nav, 'Modus muss vor dem Bereichswechsel stehen');
    assertTrue($id < $nav, 'Termin-Id muss vor dem Bereichswechsel stehen');
    assertTrue($chip < $nav, 'Statusfilter muss vor dem Bereichswechsel stehen');
});

test('Scheitert der Sprung, wird auch die Auswahl zurueckgestellt', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');

    $finally = strpos($body, '} finally {');
    assertTrue($finally !== false, 'finally fehlt');
    $tail = substr($body, $finally);

    assertTrue(str_contains($tail, 'seq === jumpSeq'),
        'Ein ueberholter Sprung darf die Auswahl des neueren nicht zurueckdrehen');
    assertTrue(str_contains($tail, 'setRecordMode(previousMode)'),
        'Ohne das bliebe der Bereich im Terminmodus eines Termins, der gar nicht geoeffnet wurde');
    assertTrue(str_contains($tail, 'currentAppointmentId = previousAppointmentId'), 'Termin-Id wird nicht zurueckgestellt');
});

test('Gemeinsamer Weg typisiert die Termin-Id und leert die Terminart', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'async function enterAppointmentAttendance(');
    assertTrue(str_contains($body, 'appointmentId = String(appointmentId);'), 'Termin-Id muss ueber beide Wege gleich typisiert sein');
    assertTrue(str_contains($body, 'currentAppointmentType = null;'), 'Terminart muss mitgeleert werden');
});

test('Der Rueckweg meldet einen gescheiterten Bereichswechsel', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $back = caFeFunctionBody($js, 'export async function backToAppointmentCalendar(');
    assertTrue((bool) preg_match('/if\s*\(!\s*await navigateToSection/', $back),
        'Rueckgabewert des Bereichswechsels wird nicht ausgewertet');
    assertTrue(str_contains($back, "'Wechsel zu den Terminen nicht moeglich'"), 'Meldung fehlt');
});

test('loadAttendanceList meldet den Fehler, statt ihn zu verschlucken', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'async function loadAttendanceList(');
    assertTrue(!str_contains($body, 'console.error'), 'console.* ist im Projekt nicht erlaubt');
    assertTrue(str_contains($body, 'debug.error('), 'debug.error fehlt');
    assertTrue(str_contains($body, "'Anwesenheitsliste konnte nicht geladen werden'"),
        'Ohne Meldung gilt der Sprung als geglueckt, waehrend die Tabelle alten Inhalt zeigt');
});
