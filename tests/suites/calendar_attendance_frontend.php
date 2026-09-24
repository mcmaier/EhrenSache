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
    assertTrue(str_contains($body, 'resolveJumpTarget('), 'Vorlauf (Datum, Jahr, Termin suchen) fehlt');
    assertTrue(str_contains($body, 'enterAppointmentAttendance('), 'Gemeinsamer Weg in die Liste fehlt');
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
    assertTrue(str_contains($html, 'onclick="backToAppointments()"'), 'Knopf ohne Handler');

    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $back = caFeFunctionBody($js, 'export async function backToAppointments(');
    assertTrue(str_contains($back, 'setCalendarMonth('), 'Zielmonat wird nicht gesetzt');
    assertTrue(str_contains($back, "navigateToSection('termine')"), 'Rueckweg wechselt den Bereich nicht');
    assertTrue(str_contains($js, 'window.backToAppointments = backToAppointments'), 'Knopf im HTML braucht die globale Zuweisung');

    $show = caFeFunctionBody($js, 'export async function showRecordsSection(');
    assertTrue(str_contains($show, 'clearAttendanceReturn()'), 'Normaler Aufruf des Bereichs muss den Rueckweg verwerfen');
    $handlers = caFeFunctionBody($js, 'export async function initRecordEventHandlers(');
    assertTrue(substr_count($handlers, 'clearAttendanceReturn()') >= 3, 'Filterwechsel muessen den Rueckweg verwerfen');
});

test('Der Vorlauf prueft das Datum, bevor er das Jahr setzt', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'async function resolveJumpTarget(');

    $pattern = '\\d{4}-\\d{2}-\\d{2}';
    assertTrue(str_contains($body, $pattern),
        'Ohne Guard macht ein unbrauchbares Datum aus dem Jahr NaN -- setCurrentYear(NaN) legt die Anwendung lahm');
    assertTrue(str_contains($body, 'reload: false'), 'Der Bereichswechsel laedt selbst -- kein zweites Laden');

    $guard = strpos($body, $pattern);
    $set = strpos($body, 'setCurrentYear(year');
    assertTrue($set !== false, 'setCurrentYear(year, ...) fehlt');
    assertTrue($guard < $set, 'Die Pruefung muss vor dem Setzen des Jahres stehen');

    // Sonst passiert auf den Klick sichtbar nichts.
    assertTrue(substr_count($body, "'Anwesenheitsliste konnte nicht geoeffnet werden'") === 1,
        'Das unbrauchbare Datum braucht dieselbe Meldung wie der Fehlerfall');
});

test('Der Vorlauf nimmt nur eine echte Terminliste an', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'async function resolveJumpTarget(');

    assertTrue(str_contains($body, 'Array.isArray(appointments)'),
        'apiCall() liefert bei HTTP-Fehler {success:false} und bei 401 null -- beides ist keine Liste');
    assertTrue(str_contains($body, "'Termine konnten nicht geladen werden'"),
        'Der Ladefehler braucht eine eigene Meldung, sonst sieht er aus wie ein fehlender Termin');
    assertTrue(str_contains($body, "invalidateCache('appointments', year)"),
        'loadAppointments() legt auch das Fehlerobjekt im Cache ab -- ohne Verwerfen wiederholt sich die Meldung zehn Minuten lang');
    assertTrue(str_contains($body, "'Termin nicht gefunden'"), 'Meldung fuer den unbekannten Termin fehlt');
});

test('Der Sprung faengt Fehler und stellt den Stand im finally zurueck', function () use ($caFeRoot) {
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

    // Die Kette als Ganzes: mit || statt && draehte ein ueberholter oder ein
    // verschachtelter Sprung die Arbeit des juengeren zurueck.
    assertTrue((bool) preg_match('/!\s*done\s*&&\s*jumpActive\s*===\s*0\s*&&\s*seq\s*===\s*jumpSeq/', $tail),
        'Zurueckgestellt wird nur bei Misserfolg, als letzter aussteigender und als juengster Sprung');
    assertTrue(str_contains($tail, 'restoreRecordState(jumpBaseline)'), 'Der gesicherte Stand wird nicht hergestellt');

    $down = strpos($tail, 'jumpActive--');
    $restore = strpos($tail, 'restoreRecordState(');
    assertTrue($down !== false && $down < $restore, 'jumpActive muss vor der Abfrage heruntergezaehlt werden');
});

test('Der Sprung zaehlt statt zu schalten', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    assertTrue(str_contains($js, 'let jumpSeq = 0;'), 'Sequenzzaehler fehlt');
    assertTrue(str_contains($js, 'let jumpActive = 0;'), 'Tiefenzaehler fehlt');
    assertTrue(str_contains($js, 'let jumpBaseline = null;'), 'Baseline der Sprungkette fehlt');
    assertTrue(!str_contains($js, 'jumpInProgress'), 'Das Boolean ist durch die Zaehler ersetzt');

    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');
    assertTrue(str_contains($body, 'const seq = ++jumpSeq;'), 'Sequenznummer wird nicht gezogen');
    assertTrue(str_contains($body, 'jumpActive++'), 'Tiefe wird nicht erhoeht');
    assertTrue(preg_match_all('/seq\s*!==\s*jumpSeq/', $body) >= 4,
        'Nach jedem await muss der ueberholte Sprung abbrechen');

    // Ein zweiter Sprung darf den halben Stand des ersten nicht als "vorher"
    // ansehen -- sonst stellt er spaeter dessen Jahr her.
    assertTrue((bool) preg_match('/if\s*\(jumpActive === 0\)\s*\{\s*\n\s*jumpBaseline = captureRecordState\(\);/', $body),
        'Die Baseline darf nur beim ersten Sprung einer Kette gezogen werden');

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

test('Die Sicherung umfasst Zustand, Auswahlfelder und Rueckweg', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $capture = caFeFunctionBody($js, 'function captureRecordState(');
    $restore = caFeFunctionBody($js, 'function restoreRecordState(');

    foreach (['year', 'mode', 'appointmentId', 'memberId', 'appointmentType', 'chip', 'attendanceReturn'] as $key) {
        assertTrue(str_contains($capture, $key . ':'), "Sicherung ohne {$key}");
    }
    // Ohne die DOM-Haelfte filtert applyRecordFilters() nach einem Termin,
    // der nie geoeffnet wurde, und der Mitgliedsfilter bleibt ausgegraut.
    foreach (['aptTypeValue', 'aptTypeDisabled', 'appointmentValue', 'appointmentDisabled', 'memberValue', 'memberDisabled'] as $key) {
        assertTrue(str_contains($capture, $key . ':'), "Sicherung ohne {$key}");
        assertTrue(str_contains($restore, 'state.' . $key), "Zurueckstellen ohne {$key}");
    }

    assertTrue(str_contains($restore, 'setRecordMode(state.mode)'), 'Modus wird nicht zurueckgestellt');
    assertTrue(str_contains($restore, 'setCurrentYear(state.year'), 'Jahr wird nicht zurueckgestellt');
    assertTrue(str_contains($restore, 'attendanceReturn = state.attendanceReturn'), 'Rueckweg wird nicht zurueckgestellt');
    assertTrue(str_contains($restore, 'back.hidden = !attendanceReturn'),
        'Sonst zeigt der Knopf nach einem gescheiterten zweiten Sprung noch auf den Termin des ersten');
});

test('Gemeinsamer Weg typisiert die Termin-Id und erhaelt die Terminart', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'async function enterAppointmentAttendance(');
    assertTrue(str_contains($body, 'appointmentId = String(appointmentId);'), 'Termin-Id muss ueber beide Wege gleich typisiert sein');
    // Hart auf null wuerde die Mitgliedsliste nach "Terminart, Termin, Termin
    // leeren, Mitglied" ungefiltert zeigen, obwohl das Feld die Terminart noch
    // anzeigt -- es wird nur gesperrt, nicht geleert.
    assertTrue(str_contains($body, 'currentAppointmentType = aptTypeFilter.value || null;'),
        'Die Terminart muss aus dem Auswahlfeld kommen, nicht hart auf null gesetzt werden');
});

test('Der Rueckweg meldet einen gescheiterten Bereichswechsel', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $back = caFeFunctionBody($js, 'export async function backToAppointments(');
    assertTrue((bool) preg_match('/if\s*\(!\s*await navigateToSection/', $back),
        'Rueckgabewert des Bereichswechsels wird nicht ausgewertet');
    assertTrue(str_contains($back, "'Wechsel zu den Terminen nicht moeglich'"), 'Meldung fehlt');
});

test('Der Sprung merkt sich die Herkunft', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    assertTrue(str_contains($js, "export async function openAttendanceForAppointment(appointmentId, date, from = 'calendar')"),
        'Die Herkunft muss ein Parameter mit Vorgabe calendar sein');

    $body = caFeFunctionBody($js, 'export async function openAttendanceForAppointment(');
    assertTrue((bool) preg_match("/from:\s*from === 'list' \? 'list' : 'calendar'/", $body),
        'Nur calendar und list sind gueltige Herkuenfte');
    assertTrue(str_contains($body, 'appointmentId: String(appointmentId)'),
        'Fuer die Zeile in der Liste braucht der Rueckweg die Termin-Id');
});

test('Aus der Liste bleibt der Kalendermonat unberuehrt', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $back = caFeFunctionBody($js, 'export async function backToAppointments(');

    assertTrue((bool) preg_match("/if \(target\.from !== 'list'\) \{\s*\n\s*setCalendarMonth\(target\.date\);/", $back),
        'setCalendarMonth darf nur fuer die Herkunft calendar laufen');
    assertTrue(str_contains($back, 'scrollToAppointmentOrigin(target)'), 'Es wird nicht zum Ausgangspunkt gerollt');
});

test('Gerollt wird zur Zeile oder zum Kalender', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'function scrollToAppointmentOrigin(');

    assertTrue(str_contains($body, '#appointmentsTableBody tr[data-appointment-id='), 'Die Zeile wird nicht ueber die Termin-Id gesucht');
    assertTrue(str_contains($body, 'Number(target.appointmentId)'), 'Die Id gehoert durch Number(), nicht roh in den Selektor');
    assertTrue(str_contains($body, "block: 'center'"), 'Die Zeile soll mittig stehen');
    assertTrue(str_contains($body, "block: 'start'"), 'Fallback auf den Kopf der Liste fehlt');
    assertTrue(str_contains($body, 'calendarDaysContainer'), 'Ziel fuer die Herkunft calendar fehlt');
    assertTrue(str_contains($body, 'if (row)'), 'Ohne Pruefung bricht scrollIntoView auf null ab');
});

test('loadAttendanceList meldet den Fehler, statt ihn zu verschlucken', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');
    $body = caFeFunctionBody($js, 'async function loadAttendanceList(');
    assertTrue(!str_contains($body, 'console.error'), 'console.* ist im Projekt nicht erlaubt');
    assertTrue(str_contains($body, 'debug.error('), 'debug.error fehlt');
    assertTrue(str_contains($body, "'Anwesenheitsliste konnte nicht geladen werden'"),
        'Ohne Meldung gilt der Sprung als geglueckt, waehrend die Tabelle alten Inhalt zeigt');
});

// ---- appointments.js ----------------------------------------------------------------

test('Kalendermonat laesst sich von aussen setzen', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    assertTrue(str_contains($js, 'export function setCalendarMonth('),
        'Muss eine gehobene Funktionsdeklaration sein -- records.js und appointments.js importieren sich gegenseitig');
    $body = caFeFunctionBody($js, 'export function setCalendarMonth(');
    assertTrue(str_contains($body, 'currentCalendarDate = new Date('), 'Zielmonat wird nicht gesetzt');
});

test('Anwesenheit ab dem Check-in-Fenster und nur fuer Verwalter', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');

    // auto_checkin.php erfasst symmetrisch um den Start
    // (ABS(TIMESTAMPDIFF(...)) <= toleranceSeconds, Vorgabe zwei Stunden).
    // Ohne Vorlauf fehlte der Knopf genau in der Aufbauphase, in der schon
    // Erfassungen vorliegen.
    assertTrue(str_contains($js, 'const ATTENDANCE_LEAD_MS = 2 * 60 * 60 * 1000;'),
        'Vorlauf-Konstante fehlt');

    $started = caFeFunctionBody($js, 'function appointmentHasStarted(');
    assertTrue(str_contains($started, 'start.getTime() - ATTENDANCE_LEAD_MS <= Date.now()'),
        'Zeitpruefung ohne Vorlauf -- der Knopf erschiene erst ab Beginn');

    $popup = caFeFunctionBody($js, 'function showAppointmentPopup(');
    assertTrue((bool) preg_match('/fest && isAdminOrManager && appointmentHasStarted\(apt\)/', $popup),
        'Popup-Knopf ohne vollstaendige Bedingung');

    $list = caFeFunctionBody($js, 'async function renderAppointments(');
    assertTrue(str_contains($list, 'appointmentHasStarted(apt)'), 'Listenknopf ohne Bedingung');
    assertTrue(str_contains($list, 'aria-label="Anwesenheit anzeigen"'), 'Symbolknopf braucht einen Namen');
});

test('Die Terminzeile traegt ihre Id fuer den Rueckweg', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    $list = caFeFunctionBody($js, 'async function renderAppointments(');
    assertTrue((bool) preg_match('/dataset\.appointmentId\s*=|setAttribute\(.data-appointment-id./', $list),
        'Ohne Kennung an der Zeile rollt der Rueckweg aus der Liste nur zum Tabellenkopf');
});

test('jumpToAttendance liest das Datum aus dem Cache und nennt die Herkunft', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    $body = caFeFunctionBody($js, 'export async function jumpToAttendance(');
    assertTrue(str_contains($body, "import('./records.js')"), 'records.js muss dynamisch geladen werden');
    assertTrue(str_contains($body, 'openAttendanceForAppointment('), 'Sprungfunktion wird nicht gerufen');
    assertTrue(str_contains($body, "'list'"), 'Die Herkunft muss durchgereicht werden');
    assertTrue(str_contains($js, 'window.jumpToAttendance = jumpToAttendance'), 'Der onclick braucht die globale Zuweisung');
});

test('jumpToAttendance faengt einen gescheiterten dynamischen Import ab', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    $body = caFeFunctionBody($js, 'export async function jumpToAttendance(');

    // Der Aufrufer ist ein onclick: eine unbehandelte Ablehnung landet nur in
    // der Konsole, der Knopf wirkt tot.
    assertTrue((bool) preg_match("/try\s*\{\s*\n\s*\(\{ openAttendanceForAppointment \} = await import\('\.\/records\.js'\)\);/", $body),
        'Der dynamische Import steht nicht in einem try');
    assertTrue(str_contains($body, '} catch ('), 'catch fehlt');
    assertTrue(str_contains($body, 'debug.error('), 'Fehler gehoeren in debug.error, nicht in console.error');
    assertTrue(str_contains($body, "showToast('Anwesenheitsliste konnte nicht geoeffnet werden', 'error')"),
        'Ohne Meldung sieht der Nutzer nichts');
});

test('jumpToAttendance faellt nach einem Jahreswechsel auf den Einzelabruf zurueck', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    $body = caFeFunctionBody($js, 'export async function jumpToAttendance(');

    // Ein festgehaltenes Popup ueberlebt renderCalendar() und den Jahreswechsel:
    // dann steht der Termin in keinem geladenen Jahr mehr.
    assertTrue(str_contains($body, "apt = await apiCall('appointments', 'GET', null, { id: Number(appointmentId) }"),
        'Rueckfall auf den Einzelabruf fehlt');
    assertTrue(str_contains($body, 'silentStatuses'), 'Der 404 wuerde sonst zwei Meldungen zeigen');

    // apiCall() wirft nicht: bei 401 kommt null, sonst {success:false} ohne date.
    assertTrue(str_contains($body, '!apt || !apt.date'),
        'Ohne beide Pruefungen laeuft der Fehlerfall in einen TypeError statt in die Meldung');

    $abruf = strpos($body, "apiCall('appointments'");
    $meldung = strpos($body, "showToast('Termin nicht gefunden'");
    assertTrue($abruf !== false && $meldung !== false && $abruf < $meldung,
        'Der Einzelabruf muss vor der Fehlermeldung stehen');
});

// ---- Schritt 2b: Zahlen im Terminabruf ----------------------------------------------

test('Der Jahresabruf der Termine holt die Anwesenheitszahlen mit', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    $body = caFeFunctionBody($js, 'export async function loadAppointments(');
    assertTrue(str_contains($body, "include: 'attendance'"),
        'Ohne den Zusatz stehen im Cache keine Zahlen, und der Kalender haette nichts zu zeichnen');
    assertTrue(str_contains($body, 'year: year'), 'Der Zeitraum muss erhalten bleiben -- ohne ihn ignoriert der Server den Zusatz');
});

test('Eine geaenderte Anwesenheit verwirft die Termine des Jahres', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');

    $quick = caFeFunctionBody($js, 'async function quickCreateRecordForMember(');
    assertTrue(str_contains($quick, "invalidateCache('appointments'"),
        'Sonst zeigt der Kalender die alten Zahlen weiter, bis der Cache von selbst ablaeuft');

    $del = caFeFunctionBody($js, 'export async function deleteRecord(');
    assertTrue(str_contains($del, "invalidateCache('appointments'"),
        'Loeschen aendert die Zahlen genauso wie Erfassen');
});

test('Auch die uebrigen Anwesenheitsaenderungen in records.js verwerfen die Termine', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');

    // Die Mitgliedsansicht erfasst ueber einen zweiten Weg -- dieselbe Wirkung
    // auf die Zahlen, also dasselbe Verwerfen.
    $quickApt = caFeFunctionBody($js, 'async function quickCreateRecordForAppointment(');
    assertTrue(str_contains($quickApt, "invalidateCache('appointments'"),
        'Die Erfassung aus der Mitgliedsansicht aendert die Zahlen ebenso');

    // Das Modal legt an und aendert: eine Aenderung von anwesend auf
    // entschuldigt verschiebt den Balken, ohne die Summe zu aendern.
    $save = caFeFunctionBody($js, 'export async function saveRecord(');
    assertTrue(str_contains($save, "invalidateCache('appointments'"),
        'Anlegen und Bearbeiten im Modal aendern die Zahlen');
});

test('Eine genehmigte Entschuldigung verwirft die Termine ebenfalls', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/exceptions.js');
    $body = caFeFunctionBody($js, 'export async function saveException(');

    // Die Genehmigung legt serverseitig einen Anwesenheitseintrag an
    // (handleApprovedAbsence/handleApprovedTimeCorrection) -- aus "fehlend"
    // wird "entschuldigt".
    assertTrue(str_contains($body, "invalidateCache('appointments')"),
        'Ohne Verwerfen zeigte der Kalender das Mitglied weiter als fehlend');
});

test('Der Import von Anwesenheiten verwirft die Termine aller Jahre', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/import_export.js');
    assertTrue(str_contains($js, 'invalidateCache'), 'invalidateCache wird nicht verwendet');
    assertTrue((bool) preg_match("/import \{[^}]*invalidateCache[^}]*\} from '\.\/ui\.js'/", $js),
        'invalidateCache muss aus ui.js importiert sein');

    $body = caFeFunctionBody($js, 'export async function executeRecordsImport(');
    assertTrue(str_contains($body, "invalidateCache('appointments')"),
        'Eine CSV kann Termine mehrerer Jahre treffen -- deshalb ohne Jahresangabe');
});

// ---- Schritt 2b: Tagesfeld ----------------------------------------------------------

test('attendanceTotals summiert die Termine eines Tages', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    $body = caFeFunctionBody($js, 'function attendanceTotals(');
    assertTrue(str_contains($body, 'expected'), 'Ohne expected laesst sich kein Anteil rechnen');
    assertTrue(str_contains($body, 'present'), 'Anwesend fehlt');
    assertTrue(str_contains($body, 'excused'), 'Entschuldigt fehlt');
    assertTrue(str_contains($body, 'missing'), 'Fehlend fehlt');
    assertTrue((bool) preg_match('/if\s*\(\s*!\s*a\s*\)|if\s*\(\s*!\s*apt\.attendance\s*\)/', $body),
        'Ein Termin ohne Zahlen (noch nicht begonnen) darf nicht als drei Nullen mitzaehlen');

    // Ein "=" statt "+=" bestuende alle Zusicherungen oben: der Tag zeigte dann
    // die Zahlen des letzten Termins statt der Summe.
    foreach (['expected', 'present', 'excused', 'missing'] as $feld) {
        assertTrue((bool) preg_match('/sum\.' . $feld . '\s*\+=/', $body),
            "sum.{$feld} muss addieren, nicht zuweisen -- sonst zaehlt nur der letzte Termin");
    }
});

test('worstOwnStatus nimmt den schlechtesten eigenen Status des Tages', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    $body = caFeFunctionBody($js, 'function worstOwnStatus(');

    // Nicht die Reihenfolge der Woerter pruefen: { missing: 1, excused: 2,
    // present: 3 } -- also genau die verkehrte Rangfolge -- bestuende das.
    assertTrue((bool) preg_match('/missing:\s*3[\s\S]*excused:\s*2[\s\S]*present:\s*1/', $body),
        'Die Rangzahlen entscheiden: fehlend schlaegt entschuldigt schlaegt anwesend');
});

test('Das Tagesfeld zeichnet Balken fuer Verwalter und Punkt fuer Mitglieder', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    $body = caFeFunctionBody($js, 'function createCalendarDay(');
    assertTrue(str_contains($body, 'calendar-attendance-bar'), 'Balken fehlt');
    assertTrue(str_contains($body, 'calendar-own-dot'), 'Punkt fuer Mitglieder fehlt');
    assertTrue(str_contains($body, 'isAdminOrManager'), 'Balken und Punkt duerfen sich nicht vermischen');
    assertTrue(str_contains($body, 'attendanceTotals('), 'Summierung wird nicht benutzt');
    assertTrue(str_contains($body, 'worstOwnStatus('), 'Eigener Status wird nicht benutzt');
    assertTrue(str_contains($body, 'totals.expected > 0'), 'Ohne erwartete Mitglieder gibt es keinen Balken');

    // Ein Segment der Breite 0 waere ueber min-width trotzdem 2 px breit --
    // der Tag zeigte eine Farbe, die es an ihm nicht gibt.
    assertTrue((bool) preg_match('/if\s*\(\s*(value|wert)\s*<=\s*0\s*\)/', $body),
        'Ein Wert von 0 darf kein Segment erzeugen');
    assertTrue(str_contains($body, 'flexGrow'),
        'Die Segmente teilen sich die Breite anteilig ueber flex-grow');
});

test('Der Vorlesetext nennt die Zahlen bzw. den eigenen Status', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/appointments.js');
    $body = caFeFunctionBody($js, 'function createCalendarDay(');
    assertTrue(str_contains($body, 'aria-label'), 'Vorlesetext fehlt');
    assertTrue(str_contains($body, 'Anwesend'), 'Der Balken ist rein grafisch -- ohne Text bleibt er fuer Screenreader stumm');
});

test('Die Segmente des Balkens nehmen die Farben aus den Variablen', function () use ($caFeRoot) {
    $css = caFeFile($caFeRoot, 'public/css/components/calendar.css');
    assertTrue(str_contains($css, '.calendar-attendance-bar'), 'CSS fuer den Balken fehlt');

    // Auf die Regel zielen, nicht auf die Datei: Erfolgs- und Warnfarbe stehen
    // hier ohnehin schon (has-event, response-dot). Ein str_contains ueber die
    // ganze Datei bliebe gruen, auch wenn die Segmente harte Farben truegen.
    $farben = [
        'present' => '--success-color',
        'excused' => '--warning-color',
        'missing' => '--danger-color',
    ];

    foreach (['.calendar-attendance-bar__seg', '.calendar-own-dot'] as $basis) {
        foreach ($farben as $status => $variable) {
            $muster = '/' . preg_quote($basis, '/') . '\.is-' . $status . '\s*\{[^}]*var\(' . $variable . '\)/';
            assertTrue((bool) preg_match($muster, $css),
                "{$basis}.is-{$status} muss var({$variable}) nehmen");
        }
    }
});

// ---- Review: Rueckgabewert der Schnellerfassung ---------------------------------------

test('Die Schnellerfassung meldet erst Erfolg, wenn der Server einen meldet', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');

    // apiCall() wirft nicht: 401 liefert null, jeder andere Fehler {success:false}.
    // Ohne Pruefung lief der Code bis zum Erfolgs-Toast weiter, obwohl nichts
    // gespeichert wurde -- und verwarf obendrein den Cache fuer nichts.
    foreach (['async function quickCreateRecordForMember(',
              'async function quickCreateRecordForAppointment('] as $signature) {
        $body = caFeFunctionBody($js, $signature);

        assertTrue(str_contains($body, "const result = await apiCall('records', 'POST'"),
            "{$signature}: Der Rueckgabewert von apiCall() wird nicht festgehalten");
        assertTrue(str_contains($body, 'if (!result || !result.success)'),
            "{$signature}: null (401) und {success:false} muessen beide abgefangen werden");
        assertTrue(str_contains($body, "showToast('Anwesenheit konnte nicht erfasst werden', 'error')"),
            "{$signature}: Fehlermeldung fehlt");

        $guard   = strpos($body, 'if (!result || !result.success)');
        $success = strpos($body, "showToast(message, 'success')");
        assertTrue($guard !== false && $success !== false && $guard < $success,
            "{$signature}: Die Pruefung muss vor dem Erfolgs-Toast stehen");

        $invalidate = strpos($body, "invalidateCache('appointments'");
        assertTrue($invalidate !== false && $guard < $invalidate,
            "{$signature}: Ohne gespeicherten Datensatz gibt es nichts zu verwerfen");
    }
});

test('records.js protokolliert ueber debug, nicht ueber console', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/records.js');

    // Projektregel: console.* umgeht den Debug-Schalter und schreibt auch im
    // Normalbetrieb in die Konsole.
    assertTrue(!str_contains($js, 'console.'), 'records.js darf console.* nicht verwenden');

    foreach (['async function loadMemberAttendanceList(',
              'async function quickCreateRecordForMember(',
              'async function quickCreateRecordForAppointment('] as $signature) {
        $body = caFeFunctionBody($js, $signature);
        assertTrue(str_contains($body, 'debug.error('), "{$signature}: catch ohne debug.error");
    }
});

// ---- Review: Gruppen- und Mitgliederaenderungen ---------------------------------------

test('Mitgliederaenderungen verwerfen den Terminabruf aller Jahre', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/members.js');

    // "Erwartet" bildet der Server aus appointment_type_groups x
    // member_group_assignments x getMemberActivityWhere
    // (private/helpers/appointment_attendance.php). Ein neues, geaendertes oder
    // geloeschtes Mitglied verschiebt die Zahlen, ohne dass eine Anwesenheit
    // angefasst wurde.
    assertTrue((bool) preg_match("/import \{[^}]*invalidateCache[^}]*\} from '\.\/ui\.js'/", $js),
        'invalidateCache muss aus ui.js importiert sein');

    foreach (['export async function saveMember(' => 'Anlegen, Aendern und die Gruppenzuordnung',
              'export async function deleteMember(' => 'Loeschen',
              'async function saveMembershipDates(' => 'Aktiv/Inaktiv-Zeitraeume'] as $signature => $what) {
        $body = caFeFunctionBody($js, $signature);

        // Bewusst ohne Jahresangabe: eine Gruppenzuordnung gilt fuer alle Jahre,
        // nicht nur fuer das gerade angezeigte.
        assertTrue(str_contains($body, "invalidateCache('appointments')"),
            "{$signature}: {$what} aendert die erwarteten Mitglieder -- ohne Jahr verwerfen");
        assertTrue(!str_contains($body, "invalidateCache('appointments', currentYear)"),
            "{$signature}: Mit Jahr blieben die uebrigen Jahre zehn Minuten falsch");
    }
});

test('Terminarten und geloeschte Gruppen verwerfen den Terminabruf aller Jahre', function () use ($caFeRoot) {
    $js = caFeFile($caFeRoot, 'public/js/modules/management.js');

    // saveType schreibt appointment_type_groups fort; deleteGroup und deleteType
    // raeumen sie per ON DELETE CASCADE ab. Beides verschiebt "erwartet".
    foreach (['export async function saveType(' => 'Die Gruppen der Terminart',
              'export async function deleteType(' => 'Die geloeschte Terminart',
              'export async function deleteGroup(' => 'Die geloeschte Gruppe'] as $signature => $what) {
        $body = caFeFunctionBody($js, $signature);
        assertTrue(str_contains($body, "invalidateCache('appointments')"),
            "{$signature}: {$what} aendert die erwarteten Mitglieder -- ohne Jahr verwerfen");
    }
});
