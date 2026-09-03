<?php

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
    $constant = 2;
    if (is_file($configPath)) {
        require_once $configPath;
    }
    if (defined('AUTO_CHECKIN_TOLERANCE_HOURS')) {
        $constant = (int) AUTO_CHECKIN_TOLERANCE_HOURS;
    }
    if ($constant < 0 || $constant > 8) {
        $warn[]   = "AUTO_CHECKIN_TOLERANCE_HOURS steht auf {$constant} – "
                  . 'außerhalb des gültigen Bereichs 0–8, es wird 2 übernommen';
        $constant = 2;
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
