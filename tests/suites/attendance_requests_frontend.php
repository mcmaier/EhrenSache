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
 * Anträge in der Anwesenheitsliste des Dashboards (OI-87): statische Proben.
 *
 * Die PWA kann das seit 1.12.0; das Dashboard bekam dieselben Daten
 * (`pending_exceptions` aus `attendance_list`), zeigte sie aber nicht. Hier
 * steht, was an einzelnen Zeilen hängt: dass die Anträge überhaupt gerendert
 * werden, dass entschieden wird wie in der Antragsliste (über den Dialog),
 * und dass der eigene Antrag der Serverregel folgt statt einer eigenen.
 */

$arRoot = dirname(__DIR__, 2);
$arJs   = (string) file_get_contents($arRoot . '/public/js/modules/records.js');
$arExc  = (string) file_get_contents($arRoot . '/public/js/modules/exceptions.js');
$arHtml = (string) file_get_contents($arRoot . '/public/index.html');

/** Rumpf einer Funktion bis zur naechsten Funktionsdefinition auf oberster Ebene. */
function arFunktion(string $js, string $name): string
{
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');

    if (preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }

    return substr($js, $start);
}

test('Zeile zeigt offene Antraege und entscheidet ueber den Antragsdialog', function () use ($arJs) {
    // Gebaut wird der Zusatz in attendanceRequestParts(), eingehaengt in buildAttendanceRow()
    $rumpf = arFunktion($arJs, 'attendanceRequestParts');
    assertTrue(str_contains(arFunktion($arJs, 'buildAttendanceRow'), 'attendanceRequestParts(member)'),
        'Die Zeile bindet die Antraege nicht ein');

    assertTrue(str_contains($rumpf, 'pending_exceptions'),
        'Die Zeile liest die offenen Antraege nicht');
    assertTrue(str_contains($rumpf, 'quickApproveException(') && str_contains($rumpf, 'quickRejectException('),
        'Entschieden wird ueber denselben Dialog wie in der Antragsliste');
});

test('Den eigenen Antrag entscheidet das Dashboard nach der Serverregel', function () use ($arJs) {
    // Nicht pauschal sperren wie die PWA vor OI-87: Im Verein mit einem
    // einzigen Verwalter erlaubt der Server die Selbstgenehmigung, dann
    // muessen die Knoepfe auch da sein.
    assertTrue(str_contains($arJs, 'self_approval_blocked'),
        'Das Flag aus attendance_list wird nicht ausgewertet');

    $rumpf = arFunktion($arJs, 'attendanceRequestParts');
    assertTrue(preg_match('/eigenerAntrag|istEigener|selbstAntrag/i', $rumpf) === 1,
        'Die Zeile unterscheidet den eigenen Antrag nicht');
});

test('Der Zaehler offener Antraege ist ein reiner Anzeige-Chip', function () use ($arJs, $arHtml) {
    assertTrue(str_contains($arHtml, 'id="recordRequestChip"'),
        'Der Container fuer den Zaehler fehlt im Markup');

    $rumpf = arFunktion($arJs, 'renderAttendanceRequestChip');
    assertTrue(str_contains($rumpf, 'static: true'),
        'Der Zaehler waere sonst ein Filter -- entschieden ist: nur anzeigen');
});

test('Nach einer Entscheidung laedt die Anwesenheitsliste neu', function () use ($arJs, $arExc) {
    $save = arFunktion($arExc, 'saveException');

    assertTrue(str_contains($save, 'exception-saved'),
        'saveException() meldet die Entscheidung nicht — die Liste zeigte den Antrag weiter');
    // Eine genehmigte Entschuldigung legt einen Record an; ohne Verwerfen des
    // Zwischenspeichers zeigte die Gesamtliste ihn bis zu 10 Minuten nicht.
    assertTrue(preg_match("/status === 'approved'[^\\n]*\\n?[^\\n]*invalidateCache\('records'\)|invalidateCache\('records'\)/", $save) === 1,
        'Der Zwischenspeicher der Anwesenheiten wird nicht verworfen');
    assertTrue(!str_contains($save, "data.exception_type === 'time_correction'"),
        'Das Verwerfen haengt noch am Antragstyp — eine genehmigte Entschuldigung legt ebenfalls einen Eintrag an');

    assertTrue(str_contains($arJs, "'exception-saved'"),
        'records.js hoert nicht auf die Entscheidung');
});

test('Anwesend-Status: das schliessende Tag ist vollstaendig', function () use ($arJs) {
    // Gemeldet von der Sitzung der Filter-Chips: '</span' ohne '>'.
    $rumpf = arFunktion($arJs, 'buildAttendanceRow');
    assertTrue(preg_match('/✓ Anwesend<\/span(?!>)/u', $rumpf) !== 1,
        'Dem schliessenden Tag von „✓ Anwesend" fehlt das >');
});
