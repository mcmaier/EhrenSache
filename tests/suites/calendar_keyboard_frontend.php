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
 * Statische Gegenproben: Kalender per Tastatur bedienen (OI-96, mit OI-80).
 *
 * Spec: docs/superpowers/specs/2026-09-25-kalender-tastaturbedienung-design.md
 */

$ckRoot = dirname(__DIR__, 2);

function ckFile(string $root, string $rel): string
{
    $path = $root . '/' . $rel;
    assertTrue(is_file($path), "{$rel} fehlt");

    return (string) file_get_contents($path);
}

/** Rumpf einer JS-Funktion ab ihrer Signatur bis zur schliessenden Klammer in Spalte 0. */
function ckFunctionBody(string $js, string $signature): string
{
    assertSame(1, substr_count($js, $signature), "{$signature} kommt nicht genau einmal vor");
    $start = strpos($js, $signature);
    $next = preg_match('/\n\}/', $js, $m, PREG_OFFSET_CAPTURE, $start) ? $m[0][1] : strlen($js);

    return substr($js, $start, $next - $start);
}

/** Entfernt Zeilenkommentare, damit eine Zusicherung nicht vom Kommentar erfuellt wird (OI-107). */
function ckOhneKommentare(string $js): string
{
    return preg_replace('~^\s*//.*$~m', '', $js);
}

test('trapFocus steht in utils.js und haelt Tab, Escape und die Rueckgabe', function () use ($ckRoot) {
    $js = ckFile($ckRoot, 'public/js/modules/utils.js');
    $body = ckOhneKommentare(ckFunctionBody($js, 'export function trapFocus('));

    assertTrue(str_contains($body, "'Escape'"), 'Escape wird nicht behandelt');
    assertTrue(str_contains($body, "'Tab'"), 'Tab wird nicht behandelt');
    assertTrue(str_contains($body, 'shiftKey'), 'Shift+Tab fehlt -- rueckwaerts bliebe der Fang offen');

    // Das zuvor fokussierte Element muss gemerkt UND in der Freigabe wieder
    // angefahren werden -- geprueft auf die Herkunft des Wertes, nicht auf das
    // Vorkommen eines Namens (OI-107). Ein blosses
    // str_contains($body, 'activeElement') genuegt dafuer NICHT: Der Name steht
    // auch in den Tab-Vergleichen. Nachgestellt am 2026-09-25 -- die
    // Fokusrueckgabe ersatzlos entfernt (Merker und Wiederanfahren, fuenf
    // Zeilen), und die Suite blieb gruen. Genau dann faellt der Fang auf, wenn
    // es darauf ankommt: nach Escape stuende der Fokus auf body statt am Tag.
    assertTrue((bool) preg_match('/(?:const|let|var)\s+(\w+)\s*=\s*document\.activeElement/', $body, $merker),
        'Das zuvor fokussierte Element muss gemerkt werden, sonst gibt es keine Rueckgabe');

    // Am return aufgeteilt: Was danach steht, ist die Freigabe. Nur dort darf
    // das Wiederanfahren zaehlen -- im Rumpf davor waere es das Setzen des
    // Anfangsfokus und sagte nichts ueber die Rueckgabe.
    $teile = preg_split('/return\s+function|return\s*\(\s*\)\s*=>/', $body);
    assertSame(2, count($teile),
        'trapFocus muss eine Freigabe-Funktion liefern, sonst bleibt der Hoerer haengen');
    $freigabe = $teile[1];

    assertTrue((bool) preg_match('/\b' . preg_quote($merker[1], '/') . '\s*\.focus\(\)/', $freigabe),
        "Die Freigabe muss den Fokus auf das gemerkte Element zurueckgeben -- \"{$merker[1]}\" wird dort nicht angefahren");
    assertTrue(str_contains($freigabe, 'removeEventListener'),
        'Die Freigabe muss den Hoerer entfernen, sonst haelt der Fang nach dem Schliessen weiter Tab');
});

test('Die Liste der bedienbaren Elemente wird bei jedem Tab neu gelesen', function () use ($ckRoot) {
    $js = ckFile($ckRoot, 'public/js/modules/utils.js');
    $body = ckOhneKommentare(ckFunctionBody($js, 'export function trapFocus('));

    // Die Rueckmeldezeile erscheint je nach Rolle, die Knopfreihe je nach
    // appointmentHasStarted() -- eine einmal beim Oeffnen gelesene Liste
    // waere nach dem ersten Neuzeichnen falsch.
    assertTrue(substr_count($body, 'querySelectorAll') >= 1, 'Kein Selektor fuer bedienbare Elemente');
    assertTrue((bool) preg_match('/(const|let)\s+\w+\s*=\s*\(\)\s*=>[^;]*querySelectorAll/s', $body),
        'Die Liste muss aus einer Funktion kommen, nicht aus einer einmal gesetzten Konstanten');
});
