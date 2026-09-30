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
 * Statische Gegenproben zu kleinen Dashboard-Fehlern aus OPEN-ITEMS
 * (OI-79, OI-88, OI-91, OI-93, OI-99, OI-105).
 *
 * Jeder Fall ist im Browser nur unter einer Bedingung sichtbar, die beim
 * Entwickeln selten eintritt: letzte Seite leer geloescht, Rolle user,
 * Einstellung ohne Neuladen, neues Serienjahr. Deshalb stehen sie hier.
 */

$dfRoot = dirname(__DIR__, 2);

/** Rumpf einer Funktion bis zur naechsten Funktionsdefinition auf oberster Ebene. */
function dfFunction(string $js, string $name): string
{
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');

    if (preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }

    return substr($js, $start);
}

function dfModule(string $root, string $name): string
{
    return (string) sourceCode($root . '/public/js/modules/' . $name . '.js');
}

test('OI-88: clampPage() haelt die Seite in [1, totalPages]', function () use ($dfRoot) {
    $body = dfFunction(dfModule($dfRoot, 'utils'), 'clampPage');

    assertTrue(str_contains(dfModule($dfRoot, 'utils'), 'export function clampPage('),
        'clampPage() wird nicht aus utils.js exportiert');
    assertTrue(preg_match('/Math\.min\(\s*Math\.max\(\s*1\s*,/', $body) === 1
            || preg_match('/Math\.max\(\s*1\s*,\s*Math\.min\(/', $body) === 1,
        'clampPage() begrenzt nicht nach unten auf 1 und nach oben auf totalPages');
});

test('OI-88: jede paginierte Liste begrenzt die Seite, bevor sie schneidet', function () use ($dfRoot) {
    $lists = [
        'exceptions'   => ['renderExceptions', 'currentExceptionsPage'],
        'records'      => ['renderRecords', 'currentRecordsPage'],
        'members'      => ['renderMembers', 'currentMembersPage'],
        'users'        => ['renderUsers', 'currentUsersPage'],
        'devices'      => ['renderDevices', 'currentDevicesPage'],
        'appointments' => ['renderAppointments', 'currentAppointmentsPage'],
    ];

    foreach ($lists as $module => [$function, $state]) {
        $js = dfModule($dfRoot, $module);
        $body = dfFunction($js, $function);

        assertTrue(preg_match('/import\s*\{[^}]*\bclampPage\b[^}]*\}\s*from\s*[\'"]\.\/utils\.js[\'"]/', $js) === 1,
            "{$module}.js importiert clampPage nicht");

        // Unter "Ausstehend" auf Seite 2 den letzten Antrag genehmigen: Die
        // Liste hat danach nur noch eine Seite, gerendert wurde Seite 2 --
        // eine leere Tabelle ohne Hinweis.
        $clamp = strpos($body, 'page = clampPage(page, totalPages)');
        assertTrue($clamp !== false, "{$function}() begrenzt die Seitenzahl nicht");

        $slice = strpos($body, 'const startIndex');
        assertTrue($slice !== false && $clamp < $slice, "{$function}() schneidet vor der Begrenzung");

        // Der gemerkte Stand muss die begrenzte Seite sein, sonst springt der
        // naechste Reload wieder auf die leere.
        $assign = strpos($body, "{$state} = page");
        assertTrue($assign !== false && $assign > $clamp,
            "{$function}() merkt sich die Seite vor der Begrenzung");
    }
});

test('OI-91: loadMembers() fragt das eigene Mitglied mit Jahr ab', function () use ($dfRoot) {
    $body = dfFunction(dfModule($dfRoot, 'members'), 'loadMembers');

    // Ohne year liefert der Einzelabruf is_active_in_period nur als
    // Stammdatum; der Zeitraum des gewaehlten Jahres fehlte dann.
    assertTrue(preg_match("/apiCall\('members',\s*'GET',\s*null,\s*\{\s*id:\s*userDetails\.member_id,\s*year\b/", $body) === 1,
        'loadMembers() ruft den Einzelabruf fuer user ohne year auf');
});

test('OI-93: Speichern zieht den Theme-Zwischenspeicher nach', function () use ($dfRoot) {
    $js = dfModule($dfRoot, 'settings');
    $sync = dfFunction($js, 'syncThemeSettingsCache');
    $save = dfFunction($js, 'saveAllSettings');

    // subgroupLabel() liest aus sessionStorage['theme-settings'], das nur
    // theme.js beim Seitenaufruf schrieb -- nach dem Speichern kam das alte
    // Wort zurueck.
    assertTrue(str_contains($sync, "sessionStorage.setItem('theme-settings'"),
        'syncThemeSettingsCache() schreibt theme-settings nicht zurueck');
    assertTrue(preg_match('/hasOwnProperty\.call\(cached,\s*key\)/', $sync) === 1,
        'syncThemeSettingsCache() uebernimmt auch Schluessel, die nicht oeffentlich sind');
    assertTrue(preg_match('/if\s*\(!raw\)\s*return/', $sync) === 1,
        'Ohne vorhandenen Zwischenspeicher legte die Funktion ein Teilobjekt an');

    $call = strpos($save, 'syncThemeSettingsCache(');
    $label = strpos($save, 'updateSubgroupLabelElements()');
    assertTrue($call !== false, 'saveAllSettings() zieht den Zwischenspeicher nicht nach');
    assertTrue($label !== false && $call < $label,
        'Der Zwischenspeicher wird erst nach dem Beschriften nachgezogen -- das alte Wort bleibt');
    assertTrue(preg_match('/syncThemeSettingsCache\(updates\.filter\([^)]*abgelehnt\.includes/', $save) === 1,
        'Abgelehnte Werte landen im Zwischenspeicher');
});

test('OI-79: Termin- und Serienaenderungen befuellen die Jahresfilter neu', function () use ($dfRoot) {
    $ui = dfModule($dfRoot, 'ui');
    $refresh = dfFunction($ui, 'refreshYearFilters');

    // Nur der erzwungene Abruf holt ein neues Jahr; der Zwischenspeicher der
    // Jahre ist global und haelt zehn Minuten.
    assertTrue(str_contains($refresh, 'loadAvailableYears(true)'),
        'refreshYearFilters() liest die Jahre aus dem Zwischenspeicher');
    assertTrue(str_contains($refresh, 'populateYearFilter(') && str_contains($refresh, 'YEAR_FILTER_IDS'),
        'refreshYearFilters() befuellt nicht alle Jahresfilter');
    assertTrue(preg_match('/const yearFilters = YEAR_FILTER_IDS;/', dfFunction($ui, 'initAllYearFilters')) === 1,
        'initAllYearFilters() fuehrt eine eigene Liste der Jahresfilter');

    $apt = dfModule($dfRoot, 'appointments');
    // Anlegen, Split und Fortsetzen einer Serie laufen alle ueber diese Stelle
    assertTrue(str_contains(dfFunction($apt, 'invalidateSeriesYears'), 'await refreshYearFilters()'),
        'Serienaktionen aktualisieren die Jahresfilter nicht');
    assertTrue(str_contains(dfFunction($apt, 'saveAppointment'), 'await refreshYearFilters()'),
        'Ein einzelner Termin in einem neuen Jahr aktualisiert die Jahresfilter nicht');
});

test('OI-99: nach dem Aufraeumen der Altdaten wird der Zwischenspeicher verworfen', function () use ($dfRoot) {
    $js = dfModule($dfRoot, 'settings');
    $start = strpos($js, "apiCall('cleanup', 'POST'");
    assertTrue($start !== false, 'Aufruf von cleanup nicht gefunden');
    $end = strpos($js, 'catch', $start);
    $after = substr($js, $start, $end - $start);

    // Ohne Jahr: Die Loeschfrist trifft mehrere Jahre auf einmal.
    foreach (['records', 'appointments', 'exceptions', 'workSessions'] as $key) {
        assertTrue(preg_match("/\[[^\]]*'{$key}'[^\]]*\]/", $after) === 1,
            "Nach dem Aufraeumen wird {$key} nicht verworfen");
    }
    assertTrue(preg_match('/await invalidateCache\(key\)/', $after) === 1,
        'Der Zwischenspeicher wird nicht ohne Jahresangabe verworfen');
});
