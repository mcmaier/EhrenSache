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

/**
 * EhrenSache - Migration v1.2.3 → v1.2.4
 *
 * Änderungen:
 * - appointments.is_auto_created — markiert Termine, die ein Check-in erzeugt hat
 * - Einstellung checkin_auto_create_appointment
 * - Einstellung checkin_tolerance_hours
 *
 * Der Schalter startet im Bestand auf '1' und nicht auf '0'. Die Automatik
 * läuft seit jeher; ein Update, das sie stillschweigend abschaltet, ließe die
 * nächste Probe ins Leere laufen. Neuinstallationen bekommen über
 * ehrensache_db.sql die '0' und entscheiden bewusst.
 *
 * Die Toleranz übernimmt den Wert der Konstante AUTO_CHECKIN_TOLERANCE_HOURS
 * und NICHT eine feste 2. Ein Verein, der die Konstante auf 4 gesetzt hat,
 * bekäme sonst durch das Update still ein anderes Suchfenster.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

require_once __DIR__ . '/../helpers/config_reader.php';

/**
 * Toleranz in Stunden aus AUTO_CHECKIN_TOLERANCE_HOURS in der config.php.
 *
 * Gelesen wird der Text, die Datei wird nicht eingebunden: Ein require der alten
 * Klassenform definierte im Update-Assistenten class Database und bräche den
 * Direktsprung von alten Versionen, sobald der Assistent database.php lädt.
 *
 * Nur ganzzahlige Werte werden übernommen, auch als String-Literal aus Ziffern.
 * Ein Ausdruck ließe sich ohne Einbinden nicht auswerten; (int) machte daraus
 * still die 0 -- einen gültigen, aber falschen Wert. Dann gilt 2 mit Warnung.
 *
 * @return array{0:int, 1:?string} Toleranz und gegebenenfalls eine Warnung
 */
function migrate_1_2_3_tolerance(string $configPath): array
{
    $roh = is_file($configPath)
        ? configLegacyDefineRaw((string) file_get_contents($configPath), 'AUTO_CHECKIN_TOLERANCE_HOURS')
        : null;

    if ($roh === null) {
        return [2, null];
    }
    if (is_string($roh) && preg_match('/^-?\d+$/', $roh)) {
        $roh = (int) $roh;
    }
    if (!is_int($roh)) {
        return [2, 'AUTO_CHECKIN_TOLERANCE_HOURS ist kein ganzzahliger Wert ('
            . htmlspecialchars(var_export($roh, true)) . ') – es wird 2 übernommen'];
    }
    if ($roh < 0 || $roh > 8) {
        return [2, "AUTO_CHECKIN_TOLERANCE_HOURS steht auf {$roh} – "
            . 'außerhalb des gültigen Bereichs 0–8, es wird 2 übernommen'];
    }
    return [$roh, null];
}

function migrate_1_2_3(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    // ---- Spalte is_auto_created ----
    $columnStmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = ?
          AND COLUMN_NAME  = 'is_auto_created'
    ");
    $columnStmt->execute([$prefix . 'appointments']);

    if ((int) $columnStmt->fetchColumn() === 0) {
        $pdo->exec("
            ALTER TABLE `{$prefix}appointments`
              ADD COLUMN `is_auto_created` TINYINT(1) NOT NULL DEFAULT 0
        ");
        $log[] = "Spalte <code>{$prefix}appointments.is_auto_created</code> angelegt";
    } else {
        $log[] = "Spalte <code>{$prefix}appointments.is_auto_created</code> existiert bereits – übersprungen";
    }

    // ---- Altbestand markieren ----
    // Heuristik über zwei Zeichenketten, die auto_checkin.php seit jeher
    // unverändert schreibt. Ein von Hand umbenannter Auto-Termin bleibt
    // unmarkiert; das ist folgenlos, weil die Markierung nur der Sichtbarkeit
    // dient und in keine Auswertung eingeht.
    $backfill = $pdo->prepare("
        UPDATE `{$prefix}appointments`
           SET is_auto_created = 1
         WHERE title = 'Automatisch erstellter Termin'
           AND description = 'Erstellt durch Zeiterfassung'
           AND is_auto_created = 0
    ");
    $backfill->execute();
    $marked = $backfill->rowCount();

    $log[] = $marked > 0
        ? "{$marked} vorhandene Termine als automatisch erzeugt markiert"
        : 'Keine vorhandenen Auto-Termine gefunden';

    // ---- Einstellung: Automatik ----
    $insert = $pdo->prepare("
        INSERT IGNORE INTO `{$prefix}system_settings`
            (setting_key, setting_value, setting_type, category, description)
        VALUES (?, ?, ?, 'general', ?)
    ");

    $insert->execute([
        'checkin_auto_create_appointment',
        '1',
        'boolean',
        'Beim Check-in einen Termin anlegen, wenn keiner passt '
        . '(Bestandsinstallationen starten mit 1, Neuinstallationen mit 0)',
    ]);
    $log[] = 'Einstellung <code>checkin_auto_create_appointment</code> auf <code>1</code> gesetzt '
           . '– bisheriges Verhalten bleibt erhalten';

    // ---- Einstellung: Toleranz ----
    [$constant, $hinweis] = migrate_1_2_3_tolerance($configPath);
    if ($hinweis !== null) {
        $warn[] = $hinweis;
    }

    $insert->execute([
        'checkin_tolerance_hours',
        (string) $constant,
        'number',
        'Zeitfenster in Stunden, in dem ein Check-in einem Termin zugeordnet wird',
    ]);

    $log[] = "Einstellung <code>checkin_tolerance_hours</code> auf <code>{$constant}</code> gesetzt"
           . ($constant !== 2 ? ' – Wert aus config.php übernommen' : '');

    return ['log' => $log, 'warnings' => $warn];
}
