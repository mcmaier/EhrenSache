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
    assertTrue(str_contains($body, 'jumpInProgress = false'), 'Sprungmarke muss im finally zurueckgesetzt werden');
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
