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
 * Drei Fehler, die in veröffentlichten Versionen steckten (1.12.2).
 *
 * Alle drei sind eine Zeile und im Browser nur unter Bedingungen sichtbar, die
 * ein Entwicklerkonto selten hat: Rolle `user`, Untergruppen im Bestand, ein
 * frisch eingeschaltetes Feature. Genau deshalb stehen sie hier.
 */

$drRoot = dirname(__DIR__, 2);

/** Rumpf einer Funktion bis zur naechsten Funktionsdefinition auf oberster Ebene. */
function drFunktion(string $js, string $name): string
{
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');

    if (preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }

    return substr($js, $start);
}

test('Statistik: leere Optgroup wird ueber ihre Optionen geprueft, nicht ueber .options', function () use ($drRoot) {
    $js = (string) file_get_contents($drRoot . '/public/js/modules/statistics.js');
    $rumpf = drFunktion($js, 'loadStatisticsFilters');

    // <optgroup> hat keine options-Eigenschaft (die gibt es nur am <select>).
    // Der Zugriff warf einen TypeError, und zwar nur fuer Nicht-Verwalter und
    // nur bei vorhandenen Untergruppen: Die Statistik lud dann gar nicht.
    assertTrue(preg_match('/optgroup\.options\b/', $rumpf) !== 1,
        'optgroup.options ist undefined — die Statistik bricht fuer die Rolle user ab');
    assertTrue(preg_match('/optgroup\.(?:querySelectorAll\([\'"]option[\'"]\)|children)\.length/', $rumpf) === 1,
        'Die Pruefung auf eine leere Optgroup fehlt');
});

test('Geraeteliste: Badge und Kennzahl lesen is_active gleich', function () use ($drRoot) {
    $js = (string) file_get_contents($drRoot . '/public/js/modules/devices.js');

    // Der Badge pruefte auf truthy. Liefert PDO die Spalte als Text, ist "0"
    // truthy — die Liste zeigte „Aktiv“, die Kennzahl daneben zaehlte das
    // Geraet als inaktiv.
    assertTrue(preg_match('/statusBadge\s*=\s*device\.is_active\s/', $js) !== 1,
        'Der Badge prueft auf truthy: "0" aus der Datenbank gilt damit als aktiv');
    assertTrue(preg_match('/statusBadge\s*=\s*Number\(device\.is_active\)\s*===\s*1/', $js) === 1,
        'Der Badge liest is_active nicht wie die Kennzahl');
});

test('Zeiterfassung: nach dem Einschalten stehen die Taetigkeitsarten sofort da', function () use ($drRoot) {
    $js = (string) file_get_contents($drRoot . '/public/js/modules/worktime.js');
    $rumpf = drFunktion($js, 'checkWorktimeEnabled');

    // settings.js ruft checkWorktimeEnabled() direkt nach dem Einschalten.
    // Die Liste kam an, wurde aber nicht gezeichnet: Die Tabelle blieb bis
    // zum Neuladen auf „Lade Daten…“.
    assertTrue(str_contains($rumpf, 'renderActivityTypes()'),
        'checkWorktimeEnabled() zeichnet die Taetigkeitsarten nicht');
});
