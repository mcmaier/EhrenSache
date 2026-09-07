<?php
declare(strict_types=1);

/**
 * Export und Import müssen zueinander passen.
 *
 * OI-24 nennt genau das als die eigentliche Anforderung: Ein exportiertes CSV
 * soll ohne Umbau wieder importierbar sein. Zweimal war das nicht der Fall —
 * der Termin-Export schrieb `type` statt `type_name`, der Anwesenheits-Export
 * `arrival_time` statt `arrival_date_time`. Beide Male wies der Import die
 * Datei ab, bevor eine einzige Zeile gelesen war.
 *
 * Diese Suite prüft die Kopfzeile, nicht den Datenweg: Sie ruft den Export ab
 * und hält die Spalten gegen die Pflichtfelder des Imports. Bewusst ohne
 * echten Importlauf — der schreibt in die Datenbank, und ein Test, der
 * Datensätze anlegt, gehört nicht in einen Durchlauf, den man beiläufig
 * startet.
 */

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * Pflichtspalten, die der jeweilige Import verlangt.
 *
 * Gespiegelt aus private/handlers/import.php. Ändert sich dort etwas, ohne
 * dass der Export nachzieht, schlägt diese Suite an.
 */
const IMPORT_REQUIRED = [
    'members'      => ['name', 'surname'],
    'appointments' => ['date', 'start_time', 'title', 'type_name'],
    'records'      => ['member_number', 'arrival_date_time'],
];

/** Kopfzeile eines Exports als Liste von Spaltennamen. */
function exportHeader(string $type): array
{
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('admin'),
        'query' => ['type' => $type, 'year' => date('Y')],
    ]);
    assertStatus(200, $res, "Export '{$type}' nicht abrufbar");

    // Führendes UTF-8-BOM entfernen, sonst trägt die erste Spalte es im Namen
    $raw  = preg_replace('/^\xEF\xBB\xBF/', '', $res['raw']);
    $line = strtok((string) $raw, "\r\n");

    return str_getcsv((string) $line, ';');
}

foreach (IMPORT_REQUIRED as $type => $required) {
    test("export/import: '{$type}' liefert alle Pflichtspalten des Imports", function () use ($type, $required) {
        $header  = exportHeader($type);
        $missing = array_values(array_diff($required, $header));

        assertSame([], $missing,
            "Dem Export '{$type}' fehlen Spalten, die der Import verlangt: "
            . implode(', ', $missing) . ' — vorhanden: ' . implode(', ', $header));
    });
}

test('export/import: Mitglieder-Gruppen nutzen dasselbe Trennzeichen', function () {
    // Der Export verkettet mit GROUP_CONCAT(... SEPARATOR '|'), der Import
    // zerlegt mit explode('|', ...). Ein anderes Zeichen auf einer Seite
    // brächte alle Gruppen als einen einzigen Namen zurück.
    $exportSrc = (string) file_get_contents(__DIR__ . '/../../private/handlers/export.php');
    $importSrc = (string) file_get_contents(__DIR__ . '/../../private/handlers/import.php');

    assertTrue(strpos($exportSrc, "SEPARATOR '|'") !== false,
        'Der Mitglieder-Export verkettet Gruppen nicht mehr mit |');
    assertTrue(strpos($importSrc, "explode('|'") !== false,
        'Der Mitglieder-Import zerlegt Gruppen nicht mehr an |');
});

test('export/import: der Import akzeptiert die alten Spaltennamen weiter', function () {
    // Dateien aus Exporten bis 1.3.1 tragen 'type' bzw. 'arrival_time'. Sie
    // sollen einlesbar bleiben, sonst wird jede archivierte Datei wertlos.
    $src = (string) file_get_contents(__DIR__ . '/../../private/handlers/import.php');

    assertTrue(strpos($src, "in_array('type', \$header)") !== false,
        "importAppointments akzeptiert den Zweitnamen 'type' nicht mehr");
    assertTrue(strpos($src, "in_array('arrival_time', \$header)") !== false,
        "importRecords akzeptiert den Zweitnamen 'arrival_time' nicht mehr");
});
