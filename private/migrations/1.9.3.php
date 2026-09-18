<?php

/**
 * EhrenSache - Migration v1.9.3 → v1.10.0
 *
 * Ort und Ende am Termin (FI-23): zwei nullbare Spalten in appointments.
 * Beide sind rein informativ und fliessen in keine Auswertung ein.
 *
 * Idempotent: Eine bereits vorhandene Spalte bleibt unberuehrt.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_9_3(PDO $pdo, string $prefix, string $configPath): array
{
    $log      = [];
    $warnings = [];

    $table = $prefix . 'appointments';

    $spalten = [
        'end_time' => "ALTER TABLE `{$table}` ADD COLUMN `end_time` TIME NULL DEFAULT NULL AFTER `start_time`",
        'location' => "ALTER TABLE `{$table}` ADD COLUMN `location` VARCHAR(200) NULL DEFAULT NULL AFTER `description`",
    ];

    $exists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");

    foreach ($spalten as $spalte => $sql) {
        $exists->execute([$table, $spalte]);
        if ((int) $exists->fetchColumn() > 0) {
            $log[] = "Spalte appointments.{$spalte} bestand bereits — unverändert";
            continue;
        }
        $pdo->exec($sql);
        $log[] = "Spalte appointments.{$spalte} angelegt";
    }

    return ['log' => $log, 'warnings' => $warnings];
}
