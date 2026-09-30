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
