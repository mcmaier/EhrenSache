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
 * Gruppenzugehoerigkeit mit Zeitraum (Spec 2026-10-05-gruppen-zeitraum).
 *
 * member_group_assignments ist der heutige Stand (valid_from NULL = von Anfang
 * an), member_group_history haelt beendete Zuordnungen. Diese Datei laedt
 * spaeter der Update-Pfad: nur Syntax bis PHP 8.0
 * (tests/suites/update_path_syntax.php). Den Schritt in der Migrationskette
 * legt die Release-Sitzung an und ruft dort groupHistoryMigrate() auf.
 */
declare(strict_types=1);

/**
 * Umstellung einer Installation: Spalte valid_from und Tabelle
 * member_group_history anlegen, falls sie fehlen. Veraendert keine Daten,
 * wiederholbar.
 *
 * @return array{log: string[], warnings: string[]}
 */
function groupHistoryMigrate(PDO $pdo, string $prefix): array
{
    $log         = [];
    $assignments = $prefix . 'member_group_assignments';
    $history     = $prefix . 'member_group_history';
    $members     = $prefix . 'members';
    $groups      = $prefix . 'member_groups';

    $hasColumn = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($assignments) . "
            AND COLUMN_NAME = 'valid_from'"
    )->fetchColumn();
    if ($hasColumn === 0) {
        $pdo->exec("ALTER TABLE `{$assignments}` ADD COLUMN valid_from DATE NULL");
        $log[] = 'Spalte member_group_assignments.valid_from angelegt.';
    }

    $hasTable = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($history)
    )->fetchColumn();
    if ($hasTable === 0) {
        $pdo->exec("CREATE TABLE `{$history}` (
            history_id INT NOT NULL AUTO_INCREMENT,
            member_id INT NOT NULL,
            group_id INT NOT NULL,
            valid_from DATE NULL,
            valid_to DATE NOT NULL,
            PRIMARY KEY (history_id),
            KEY `{$prefix}idx_mgh_member` (member_id),
            KEY `{$prefix}idx_mgh_group_to` (group_id, valid_to),
            FOREIGN KEY (member_id) REFERENCES `{$members}`(member_id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES `{$groups}`(group_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'Tabelle member_group_history angelegt.';
    }

    return ['log' => $log, 'warnings' => []];
}
