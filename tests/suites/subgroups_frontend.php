<?php
declare(strict_types=1);

/**
 * Statische Gegenproben der Oberflaeche fuer die Gliederung von
 * Anwesenheits- und Namensliste nach Gruppe/Untergruppe (FI-14, 1.8.0,
 * Task 7). Kein eigener JS-Testlauf im Projekt -- pruefbar bleibt die
 * Verdrahtung zwischen grouping.js, records.js, responses.js und der
 * Gestaltung. Muster: tests/suites/responses_frontend.php.
 */

$ugfRoot = dirname(__DIR__, 2);

/** Funktionskoerper von $start bis vor die naechste Top-Level-Definition mit $stopMarker. */
function ugfBody(string $code, string $startMarker, string $stopMarker, int $offset = 0): string
{
    $start = strpos($code, $startMarker, $offset);
    assertTrue($start !== false, "{$startMarker} fehlt");
    $ende = strpos($code, $stopMarker, $start + strlen($startMarker));
    assertTrue($ende !== false, "Ende nach {$startMarker} nicht gefunden ({$stopMarker})");
    return substr($code, $start, $ende - $start);
}

test('grouping.js existiert mit Copyright-Kopf und den erwarteten Exporten', function () use ($ugfRoot) {
    $path = $ugfRoot . '/public/js/modules/grouping.js';
    assertTrue(is_file($path), 'public/js/modules/grouping.js fehlt');

    $js = (string) file_get_contents($path);
    assertTrue(str_contains($js, 'Copyright (c) 2026 Martin Maier'), 'Copyright-Kopf fehlt');
    assertTrue(str_contains($js, 'AGPL-3.0'), 'Lizenzhinweis fehlt');

    foreach ([
        'export function groupingSections',
        'export function groupingAvailableStages',
        'export function groupingDuplicateCount',
        'export function groupingStored',
        'export function groupingStore',
        "export const GROUPING_KEY_ATTENDANCE",
        "export const GROUPING_KEY_RESPONSES",
    ] as $needle) {
        assertTrue(str_contains($js, $needle), "{$needle} fehlt in grouping.js");
    }

    assertTrue(str_contains($js, "'es_grouping_attendance'"), 'Speicherschluessel es_grouping_attendance fehlt');
    assertTrue(str_contains($js, "'es_grouping_responses'"), 'Speicherschluessel es_grouping_responses fehlt');
});

test('grouping.js kapselt jeden localStorage-Zugriff in try/catch', function () use ($ugfRoot) {
    // 6.2: ein unlesbarer oder gesperrter Speicher (privates Fenster) darf
    // die Listen nicht zum Absturz bringen.
    $js = (string) file_get_contents($ugfRoot . '/public/js/modules/grouping.js');

    $stored = ugfBody($js, 'export function groupingStored', 'export function groupingStore');
    assertTrue(str_contains($stored, 'try {') && str_contains($stored, 'catch'), 'groupingStored() sichert localStorage.getItem nicht ab');

    $store = substr($js, (int) strpos($js, 'export function groupingStore('));
    assertTrue(str_contains($store, 'try {') && str_contains($store, 'catch'), 'groupingStore() sichert localStorage.setItem nicht ab');
});

test('records.js bindet grouping.js ein und traegt den Anwesenheits-Umschalter', function () use ($ugfRoot) {
    $js = (string) file_get_contents($ugfRoot . '/public/js/modules/records.js');

    assertTrue(str_contains($js, "from './grouping.js'"), 'records.js importiert nicht aus grouping.js');
    foreach (['groupingAvailableStages', 'groupingSections', 'groupingDuplicateCount', 'groupingStored', 'groupingStore', 'GROUPING_KEY_ATTENDANCE'] as $needle) {
        assertTrue(str_contains($js, $needle), "{$needle} wird in records.js nicht verwendet");
    }

    assertTrue(str_contains($js, "'es_grouping_attendance'") || str_contains($js, 'GROUPING_KEY_ATTENDANCE'),
        'Speicherschluessel der Anwesenheitsliste taucht nicht auf');

    // renderAttendanceList() gliedert in Abschnitte und escaped die Ueberschrift.
    $body = ugfBody($js, 'function renderAttendanceList', 'function renderAttendanceGroupingBar');
    assertTrue(str_contains($body, 'response-group-row'), 'Abschnittskopf nutzt response-group-row nicht (bereits vorhandenes Muster)');
    assertTrue(str_contains($body, 'escapeHtml('), 'renderAttendanceList() maskiert die Abschnittsueberschrift nicht');
    assertTrue(str_contains($body, 'groupingSections('), 'renderAttendanceList() bildet die Abschnitte nicht ueber groupingSections()');

    assertTrue(str_contains($js, 'window.setAttendanceGrouping'), 'Der Umschalter-Klick ist nicht global erreichbar (onclick)');
});

test('responses.js bindet grouping.js ein, gliedert die Namensliste und fuehrt responseGroupKey nicht mehr', function () use ($ugfRoot) {
    $js = (string) file_get_contents($ugfRoot . '/public/js/modules/responses.js');

    assertTrue(str_contains($js, "from './grouping.js'"), 'responses.js importiert nicht aus grouping.js');
    foreach (['groupingAvailableStages', 'groupingSections', 'groupingDuplicateCount', 'groupingStored', 'groupingStore', 'GROUPING_KEY_RESPONSES'] as $needle) {
        assertTrue(str_contains($js, $needle), "{$needle} wird in responses.js nicht verwendet");
    }

    assertTrue(!str_contains($js, 'responseGroupKey'), 'responseGroupKey() ist noch vorhanden -- sollte durch grouping.js ersetzt sein');

    $body = ugfBody($js, 'function namesListHtml', 'window.setResponsesGrouping');
    assertTrue(str_contains($body, 'escapeHtml(label)'), 'namesListHtml() maskiert die Abschnittsueberschrift nicht');
    assertTrue(str_contains($body, 'response-names-grouped'), 'Aeusserer Rahmen response-names-grouped fehlt weiterhin');
    assertTrue(str_contains($body, 'groupingSections('), 'namesListHtml() bildet die Abschnitte nicht ueber groupingSections()');

    assertTrue(str_contains($js, 'window.setResponsesGrouping'), 'Der Umschalter-Klick ist nicht global erreichbar (onclick)');
});

test('responses.js: die Verwalter-Tabelle gliedert ebenfalls ueber groupingSections() und fuehrt group_name nirgends mehr (a291efd)', function () use ($ugfRoot) {
    $js = (string) file_get_contents($ugfRoot . '/public/js/modules/responses.js');

    assertTrue(!str_contains($js, 'group_name'), 'group_name kommt in responses.js noch vor -- die Gliederung soll vollstaendig ueber groups/subgroups laufen');

    $body = ugfBody($js, 'function managerTableHtml', 'function responseNameChip');
    assertTrue(str_contains($body, 'groupingSections('), 'managerTableHtml() bildet die Abschnitte nicht ueber groupingSections()');
    assertTrue(str_contains($body, 'GROUPING_KEY_RESPONSES'), 'managerTableHtml() nutzt nicht denselben Speicherschluessel wie die Namensliste');
    assertTrue(str_contains($body, 'responsesGroupingSwitcher('), 'managerTableHtml() bindet den gemeinsamen Umschalter nicht ein');
    assertTrue(str_contains($body, 'managerMemberRowHtml'), 'managerTableHtml() baut die Zeilen nicht ueber managerMemberRowHtml()');
});

test('Der Umschalter nutzt die gemeinsame Gestaltung .list-grouping', function () use ($ugfRoot) {
    $css = (string) file_get_contents($ugfRoot . '/public/css/components/buttons.css');

    assertTrue(str_contains($css, '.response-filter, .list-grouping'), 'buttons.css erweitert .response-filter nicht um .list-grouping');
    assertTrue(str_contains($css, '.response-filter__btn, .list-grouping__btn'), 'buttons.css erweitert .response-filter__btn nicht um .list-grouping__btn');

    $recordsJs = (string) file_get_contents($ugfRoot . '/public/js/modules/records.js');
    assertTrue(str_contains($recordsJs, 'list-grouping'), 'records.js verwendet die Klasse list-grouping nicht im Markup');

    $responsesJs = (string) file_get_contents($ugfRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains($responsesJs, 'list-grouping'), 'responses.js verwendet die Klasse list-grouping nicht im Markup');
});

test('index.html traegt einen Container fuer den Gruppierungs-Umschalter der Anwesenheitsliste', function () use ($ugfRoot) {
    $html = (string) file_get_contents($ugfRoot . '/public/index.html');
    assertTrue(str_contains($html, 'id="recordsGroupingBar"'), 'recordsGroupingBar fehlt in index.html');
    assertTrue(str_contains($html, 'src="./js/modules/records.js"') || str_contains($html, "type=\"module\""),
        'index.html laedt die Module nicht wie erwartet');
});

// ----------------------------------------------------------------------
// Check-in-PWA (Task 8): kein Modulsystem, deshalb eine klassische Fassung
// von grouping.js direkt in public/checkin/js/app.js -- siehe die
// Begruendung dort (Kommentar "GRUPPIERUNG DER LISTEN").
// ----------------------------------------------------------------------

test('checkin/js/app.js enthaelt eine eigene Gruppierungsfassung ohne export, mit beiden Speicherschluesseln', function () use ($ugfRoot) {
    $path = $ugfRoot . '/public/checkin/js/app.js';
    assertTrue(is_file($path), 'public/checkin/js/app.js fehlt');

    $js = (string) file_get_contents($path);

    foreach ([
        'function groupingSections',
        'function groupingAvailableStages',
        'function groupingDuplicateCount',
        'function groupingStored',
        'function groupingStore',
    ] as $needle) {
        assertTrue(str_contains($js, $needle), "{$needle} fehlt in app.js");
        assertTrue(!str_contains($js, "export {$needle}"), "{$needle} traegt ein export -- die PWA hat kein Modulsystem");
    }

    assertTrue(str_contains($js, "GROUPING_KEY_ATTENDANCE") && str_contains($js, "'es_grouping_attendance'"), 'Speicherschluessel es_grouping_attendance fehlt');
    assertTrue(str_contains($js, "GROUPING_KEY_RESPONSES") && str_contains($js, "'es_grouping_responses'"), 'Speicherschluessel es_grouping_responses fehlt');
    assertTrue(str_contains($js, 'function subgroupLabel'), 'subgroupLabel() fehlt -- das eingestellte Wort muss ueber appearanceSettings kommen');
});

test('app.js kapselt jeden localStorage-Zugriff der Gruppierung in try/catch', function () use ($ugfRoot) {
    $js = (string) file_get_contents($ugfRoot . '/public/checkin/js/app.js');

    $stored = ugfBody($js, 'function groupingStored', 'function groupingStore');
    assertTrue(str_contains($stored, 'try {') && str_contains($stored, 'catch'), 'groupingStored() sichert localStorage.getItem nicht ab');

    $store = substr($js, (int) strpos($js, 'function groupingStore('));
    $store = substr($store, 0, (int) strpos($store, "const GROUPING_KEY_ATTENDANCE"));
    assertTrue(str_contains($store, 'try {') && str_contains($store, 'catch'), 'groupingStore() sichert localStorage.setItem nicht ab');
});

test('app.js: renderAttendanceList() gliedert ueber groupingSections(), escaped Namen/Gruppen und fuehrt die alte Zeichenketten-Gruppierung nicht mehr', function () use ($ugfRoot) {
    $js = (string) file_get_contents($ugfRoot . '/public/checkin/js/app.js');

    assertTrue(!str_contains($js, "member.groups || "), 'member.groups || (Zeichenketten-Gruppierung) kommt noch vor -- das Feld ist jetzt eine Liste');
    assertTrue(!str_contains($js, "member.groups ||'"), 'member.groups || (Zeichenketten-Gruppierung) kommt noch vor -- das Feld ist jetzt eine Liste');

    $body = ugfBody($js, 'function renderAttendanceList', 'function renderAttendanceGroupingBar');
    assertTrue(str_contains($body, 'groupingSections('), 'renderAttendanceList() bildet die Abschnitte nicht ueber groupingSections()');
    assertTrue(str_contains($body, 'escapeHtml(section.label)'), 'renderAttendanceList() maskiert die Abschnittsueberschrift nicht');
    assertTrue(str_contains($body, 'escapeHtml(member.surname)') && str_contains($body, 'escapeHtml(member.name)'), 'renderAttendanceList() maskiert den Mitgliedsnamen nicht');
    assertTrue(!str_contains($body, '${member.surname}, ${member.name}'), 'Der unmaskierte Name (Altlast) ist noch vorhanden');

    assertTrue(str_contains($js, 'GROUPING_KEY_ATTENDANCE'), 'Der Speicherschluessel der Anwesenheitsliste taucht nicht auf');
    assertTrue(str_contains($js, 'window.setAttendanceGrouping'), 'Der Umschalter-Klick ist nicht global erreichbar (onclick)');
});

test('app.js: die Namensliste "Wer hat geantwortet?" gliedert ueber groupingSections() und fuehrt responseGroupKey/sortByNameSurname nicht mehr', function () use ($ugfRoot) {
    $js = (string) file_get_contents($ugfRoot . '/public/checkin/js/app.js');

    assertTrue(!str_contains($js, 'function responseGroupKey'), 'responseGroupKey() ist noch vorhanden -- sollte durch groupingSections() ersetzt sein');
    assertTrue(!str_contains($js, 'function sortByNameSurname'), 'sortByNameSurname() ist noch vorhanden -- groupingSections() sortiert bereits');

    $body = ugfBody($js, 'function responseNamesHtml', 'window.setResponsesGrouping');
    assertTrue(str_contains($body, 'groupingSections('), 'responseNamesHtml() bildet die Abschnitte nicht ueber groupingSections()');
    assertTrue(str_contains($body, 'GROUPING_KEY_RESPONSES'), 'responseNamesHtml() nutzt nicht den Speicherschluessel der Namensliste');
    assertTrue(str_contains($body, 'responsesGroupingSwitcher('), 'responseNamesHtml() bindet den Umschalter nicht ein');

    assertTrue(str_contains($js, 'window.setResponsesGrouping'), 'Der Umschalter-Klick ist nicht global erreichbar (onclick)');
});

test('checkin/css/style.css und index.html tragen die Gruppierungsleiste der PWA', function () use ($ugfRoot) {
    $css = (string) file_get_contents($ugfRoot . '/public/checkin/css/style.css');
    assertTrue(str_contains($css, '.list-grouping'), 'style.css definiert .list-grouping nicht');
    assertTrue(str_contains($css, '.list-grouping__btn'), 'style.css definiert .list-grouping__btn nicht');

    $html = (string) file_get_contents($ugfRoot . '/public/checkin/index.html');
    assertTrue(str_contains($html, 'id="attendanceGroupingBar"'), 'attendanceGroupingBar fehlt in index.html');
});
