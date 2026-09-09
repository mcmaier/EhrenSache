<?php
/**
 * EhrenSache - Verifikation der Selbstheilung eines verlorenen AUTO_INCREMENT (OI-1)
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Nicht Teil von tests/run.php, weil die Datei eine echte Tabelle mit
 * AUTO_INCREMENT anlegt und deren Zähler direkt per ALTER TABLE verstellt --
 * ein DDL-Eingriff, der in tests/suites/ nichts zu suchen hat.
 *
 * Prüft nur worktimeRepairAutoinc() selbst gegen eine Wegwerftabelle. Der
 * eigentliche Auslöser -- InnoDB schaltet nach einer Crash-Recovery die
 * AUTOINC-Generierung ab und liefert MySQL-Fehler 1467 -- lässt sich nicht auf
 * Kommando herstellen, deshalb bleibt der Wiederholungspfad in
 * worktimeWithAutoincRepair() hier unbelegt.
 *
 * Die künstliche Verstellung des Zählers zielt bewusst nach oben, nicht nach
 * unten: InnoDB lässt sich per ALTER TABLE nicht unter MAX(row_id) + 1
 * drücken, ein zu kleiner Wert wird beim Setzen still auf dieses Minimum
 * angehoben. Der reale Fehlerzustand (Zähler liest sich als 0) bleibt damit
 * ebenfalls unnachstellbar -- geprüft wird nur, dass worktimeRepairAutoinc()
 * den Zähler zuverlässig auf MAX(row_id) + 1 zieht, aus welcher Richtung auch
 * immer er verstellt war.
 *
 * Aufruf:
 *   php tests/db/verify_autoinc_repair.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" tester test123 ez_
 */
declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Aufruf: php tests/db/verify_autoinc_repair.php <dsn-mit-dbname> <user> <password> <prefix>\n");
    fwrite(STDERR, "Beispiel: php tests/db/verify_autoinc_repair.php \"mysql:host=127.0.0.1;port=3306;dbname=ehrensache\" tester test123 ez_\n");
    exit(2);
}

[$_, $dsn, $dbUser, $dbPass, $prefix] = $argv;

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../../private/helpers/worktime.php';

$pdo = new PDO($dsn . ';charset=utf8mb4', $dbUser, $dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$table = $prefix . 'autoinc_repair_test';

/** Legt die Wegwerftabelle frisch an. */
function createScratchTable(PDO $pdo, string $table): void
{
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    $pdo->exec("CREATE TABLE `{$table}` (
        row_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        label  VARCHAR(50) NOT NULL
    ) ENGINE=InnoDB");
}

function currentAutoIncrement(PDO $pdo, string $table): int
{
    $stmt = $pdo->prepare("SELECT AUTO_INCREMENT FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn();
}

// ============================================================================
createScratchTable($pdo, $table);

test('worktimeRepairAutoinc setzt den Zaehler auf MAX(row_id) + 1', function () use ($pdo, $table) {
    // Drei Zeilen anlegen, mittlere und letzte wieder loeschen -- row_id 1
    // bleibt uebrig, 2 und 3 sind vergeben und frei.
    $pdo->prepare("INSERT INTO `{$table}` (label) VALUES (?), (?), (?)")
        ->execute(['erste', 'zweite', 'dritte']);
    $pdo->exec("DELETE FROM `{$table}` WHERE row_id IN (2, 3)");

    $maxId = (int) $pdo->query("SELECT COALESCE(MAX(row_id), 0) FROM `{$table}`")->fetchColumn();
    assertSame(1, $maxId, 'Nach dem Loeschen sollte nur row_id 1 uebrig sein');

    // Zaehler kuenstlich verstellen. InnoDB selbst laesst sich per ALTER TABLE
    // nicht unter MAX(row_id) + 1 druecken -- ein ALTER auf einen zu kleinen
    // Wert wird stillschweigend auf das Minimum angehoben (mit dieser Probe
    // nachgewiesen). Der reale Fehlerzustand aus OI-1, ein Zaehler, der als 0
    // ausgelesen wird, laesst sich damit nicht nachstellen -- nur die
    // Gegenrichtung: hier wird bewusst zu hoch gesetzt, um zu pruefen, dass
    // worktimeRepairAutoinc() den Zaehler zuverlaessig auf MAX(row_id) + 1
    // zurueckzieht, unabhaengig davon, in welche Richtung er verstellt war.
    $pdo->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = 1000");
    assertSame(1000, currentAutoIncrement($pdo, $table), 'Der Zaehler haette verstellt sein muessen');

    $repaired = worktimeRepairAutoinc($pdo, $table, 'row_id');
    assertTrue($repaired, 'worktimeRepairAutoinc haette true liefern muessen');

    assertSame(2, currentAutoIncrement($pdo, $table), 'Erwartet: MAX(row_id) + 1 = 2');

    // Der naechste INSERT darf jetzt nicht mehr mit Fehler 1467 scheitern.
    $pdo->prepare("INSERT INTO `{$table}` (label) VALUES (?)")->execute(['vierte']);
    $newId = (int) $pdo->lastInsertId();
    assertSame(2, $newId, 'Die naechste vergebene row_id sollte 2 sein');
});

// --- Aufraeumen --------------------------------------------------------------
$pdo->exec("DROP TABLE IF EXISTS `{$table}`");

exit(harnessSummary());
