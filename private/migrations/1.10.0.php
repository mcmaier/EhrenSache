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
 * EhrenSache - Migration v1.10.0 → v1.11.0
 *
 * Terminserien (FI-7) und Feiertage (FI-16):
 *  - Tabelle appointment_series (Regel, Zeitraum, exdates, Vorlage)
 *  - appointments.series_id und appointments.is_detached
 *  - Einstellung holiday_region (leer = nur bundesweite Feiertage)
 *
 * Idempotent: Vorhandenes bleibt unberuehrt. Index- und Constraint-Namen
 * muessen denen in private/setup/ehrensache_db.sql entsprechen, sonst meldet
 * tests/db/verify_schema_convergence.php eine Abweichung.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_10_0(PDO $pdo, string $prefix, string $configPath): array
{
    $log      = [];
    $warnings = [];

    $series = $prefix . 'appointment_series';
    $apts   = $prefix . 'appointments';

    $tableExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $tableExists->execute([$series]);
    if ((int) $tableExists->fetchColumn() > 0) {
        $log[] = 'Tabelle appointment_series bestand bereits — unverändert';
    } else {
        $pdo->exec("
            CREATE TABLE `{$series}` (
              `series_id` int(11) NOT NULL AUTO_INCREMENT,
              `rrule` varchar(100) NOT NULL,
              `start_date` date NOT NULL,
              `until` date NOT NULL,
              `exdates` text DEFAULT NULL,
              `title` varchar(200) NOT NULL,
              `type_id` int(11) DEFAULT NULL,
              `description` text DEFAULT NULL,
              `start_time` time NOT NULL,
              `end_time` time DEFAULT NULL,
              `location` varchar(200) DEFAULT NULL,
              `created_by` int(11) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`series_id`),
              KEY `type_id` (`type_id`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $log[] = 'Tabelle appointment_series angelegt';
    }

    $columnExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $columns = [
        'series_id'   => "ALTER TABLE `{$apts}` ADD COLUMN `series_id` INT(11) NULL DEFAULT NULL AFTER `type_id`",
        'is_detached' => "ALTER TABLE `{$apts}` ADD COLUMN `is_detached` TINYINT(1) NOT NULL DEFAULT 0 AFTER `series_id`",
    ];
    foreach ($columns as $column => $sql) {
        $columnExists->execute([$apts, $column]);
        if ((int) $columnExists->fetchColumn() > 0) {
            $log[] = "Spalte appointments.{$column} bestand bereits — unverändert";
            continue;
        }
        $pdo->exec($sql);
        $log[] = "Spalte appointments.{$column} angelegt";
    }

    $indexExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ");
    $indexExists->execute([$apts, 'idx_series']);
    if ((int) $indexExists->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$apts}` ADD INDEX `idx_series` (`series_id`)");
        $log[] = 'Index appointments.idx_series angelegt';
    }

    $constraintExists = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
    ");
    $constraints = [
        [$series, "{$prefix}series_type_fk",
            "ALTER TABLE `{$series}` ADD CONSTRAINT `{$prefix}series_type_fk` FOREIGN KEY (`type_id`) REFERENCES `{$prefix}appointment_types` (`type_id`) ON DELETE SET NULL"],
        [$series, "{$prefix}series_created_by_fk",
            "ALTER TABLE `{$series}` ADD CONSTRAINT `{$prefix}series_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `{$prefix}users` (`user_id`) ON DELETE SET NULL"],
        [$apts, "{$prefix}appointments_series_fk",
            "ALTER TABLE `{$apts}` ADD CONSTRAINT `{$prefix}appointments_series_fk` FOREIGN KEY (`series_id`) REFERENCES `{$series}` (`series_id`) ON DELETE SET NULL"],
    ];
    foreach ($constraints as [$table, $name, $sql]) {
        $constraintExists->execute([$table, $name]);
        if ((int) $constraintExists->fetchColumn() > 0) {
            continue;
        }
        $pdo->exec($sql);
        $log[] = "Fremdschlüssel {$name} angelegt";
    }

    $pdo->prepare("
        INSERT IGNORE INTO `{$prefix}system_settings` (`setting_key`, `setting_value`, `setting_type`, `category`, `description`)
        VALUES ('holiday_region', '', 'text', 'general', 'Bundesland für die Feiertage im Kalender (leer = nur bundesweite)')
    ")->execute();
    $log[] = 'Einstellung holiday_region angelegt (falls fehlend)';

    return ['log' => $log, 'warnings' => $warnings];
}
