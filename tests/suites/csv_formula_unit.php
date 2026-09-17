<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/utils.php';

/**
 * Die Entschaerfung der CSV-Zellen und der Waechter darueber (OI-59).
 *
 * Der zweite Teil ist der wichtigere: Eine Regel, die man bei jeder neuen
 * Exportspalte von Hand einhalten muss, wird irgendwann vergessen. Deshalb
 * haelt ein statischer Test fest, dass in den beiden Exporthandlern kein
 * direktes fputcsv() mehr steht -- wie tests/suites/demo_mode.php es fuer die
 * Registrierung neuer Ressourcen tut.
 */

test('csvCell maskiert die vier Formelzeichen', function () {
    foreach (['=1+1', '+1+1', '-1+1', '@SUM(A1)'] as $eingabe) {
        assertSame("'" . $eingabe, csvCell($eingabe), "Nicht maskiert: {$eingabe}");
    }
});

test('csvCell maskiert fuehrenden Tabulator und Wagenruecklauf', function () {
    // Manche Programme schneiden beides vor der Formelerkennung ab -- dann
    // steht das Gleichheitszeichen wieder vorn.
    assertSame("'\t=1+1", csvCell("\t=1+1"));
    assertSame("'\r=1+1", csvCell("\r=1+1"));
});

test('csvCell laesst Zahlen in Ruhe', function () {
    // Ohne diese Ausnahme wuerde jede negative Zahl zu Text, und der
    // Empfaenger koennte im Stundennachweis nicht mehr rechnen.
    foreach (['-5', '-12.5', '0', '42', '3.75'] as $zahl) {
        assertSame($zahl, csvCell($zahl), "Zahl wurde veraendert: {$zahl}");
    }
});

test('csvCell laesst gewoehnlichen Text in Ruhe', function () {
    foreach (['Probe', 'Müller, Anna', '1+1', 'a=b', ''] as $text) {
        assertSame($text, csvCell($text), "Text wurde veraendert: {$text}");
    }
});

test('csvCell reicht null durch', function () {
    // Eine leere Spalte bleibt leer und wird nicht zum Wort "null".
    assertSame(null, csvCell(null));
});

test('csvRow schreibt jede Zelle entschaerft', function () {
    $datei = tempnam(sys_get_temp_dir(), 'csvrow');
    $h     = fopen($datei, 'w');

    csvRow($h, ['=1+1', 'Probe', '-5'], ';');
    fclose($h);

    $zeile = trim((string) file_get_contents($datei));
    unlink($datei);

    $felder = str_getcsv($zeile, ';');
    assertSame("'=1+1", $felder[0], 'Die Formel muss entschaerft sein');
    assertSame('Probe', $felder[1], 'Harmloser Text bleibt unveraendert');
    assertSame('-5', $felder[2], 'Die Zahl bleibt eine Zahl');
});

test('Kein Exporthandler schreibt am Entschaerfen vorbei', function () {
    // Der eigentliche Schutz: Wer eine Spalte ergaenzt, kann csvCell() nicht
    // mehr vergessen, weil fputcsv() in diesen Dateien nicht mehr vorkommt.
    $dateien = [
        'private/handlers/export.php',
        'private/handlers/my_data.php',
    ];

    $treffer = [];
    foreach ($dateien as $relativ) {
        $pfad  = __DIR__ . '/../../' . $relativ;
        $zeilen = preg_split('/\r\n|\n|\r/', (string) file_get_contents($pfad)) ?: [];

        foreach ($zeilen as $nr => $zeile) {
            if (strpos($zeile, 'fputcsv') !== false) {
                $treffer[] = $relativ . ':' . ($nr + 1);
            }
        }
    }

    assertSame([], $treffer,
        'Direktes fputcsv() gefunden — csvRow() nehmen, sonst faellt die '
        . 'Entschaerfung fuer diese Spalte aus: ' . implode(', ', $treffer));
});

test('Die Selbstauskunft beginnt keine Zeile mit einem Formelzeichen', function () {
    // Die Abschnittstrenner hiessen bis 1.9.0 "=== STAMMDATEN ===". Die
    // Entschaerfung haette jedem davon ein Apostroph verpasst, das der
    // Empfaenger beim CSV-Import je nach Programm zu sehen bekommt. Programm-
    // erzeugte Ueberschriften brauchen kein Formelzeichen.
    $quelle = (string) file_get_contents(__DIR__ . '/../../private/handlers/my_data.php');

    assertSame(0, preg_match_all("/\\['===/", $quelle),
        'Eine Abschnittsueberschrift beginnt wieder mit ===');
});
