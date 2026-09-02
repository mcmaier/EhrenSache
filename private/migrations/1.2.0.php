<?php

/**
 * EhrenSache - Migration v1.2.0 → v1.2.1
 *
 * Änderungen:
 * - Neue Tabelle: activity_type_groups (Tätigkeitsart ↔ Mitgliedergruppe)
 * - Bestehende Tätigkeitsarten werden ALLEN Gruppen zugeordnet
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_2_0(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    $exists = static function (string $table) use ($pdo): bool {
        return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->rowCount();
    };

    // ----------------------------------------------------------------
    // Schritt 1: Zuordnungstabelle
    // ----------------------------------------------------------------
    $had = $exists($prefix . 'activity_type_groups');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}activity_type_groups` (
            activity_id INT NOT NULL,
            group_id    INT NOT NULL,
            PRIMARY KEY (activity_id, group_id),
            CONSTRAINT `{$prefix}atg_activity_fk` FOREIGN KEY (activity_id)
                REFERENCES `{$prefix}activity_types`(activity_id) ON DELETE CASCADE,
            CONSTRAINT `{$prefix}atg_group_fk` FOREIGN KEY (group_id)
                REFERENCES `{$prefix}member_groups`(group_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = $had
        ? "Tabelle <code>{$prefix}activity_type_groups</code> existiert bereits – übersprungen"
        : "Tabelle <code>{$prefix}activity_type_groups</code> angelegt";

    // ----------------------------------------------------------------
    // Schritt 2: Bestand allen Gruppen zuordnen
    //
    // Jede andere Vorbelegung liesse in bestehenden Installationen
    // Taetigkeiten verschwinden, bis ein Administrator eingreift.
    // INSERT IGNORE macht den Schritt wiederholbar.
    // ----------------------------------------------------------------
    $inserted = $pdo->exec("
        INSERT IGNORE INTO `{$prefix}activity_type_groups` (activity_id, group_id)
        SELECT a.activity_id, g.group_id
        FROM `{$prefix}activity_types` a
        CROSS JOIN `{$prefix}member_groups` g
    ");
    $log[] = "{$inserted} Zuordnung(en) für bestehende Tätigkeitsarten angelegt";

    if ($inserted === 0 && !$had) {
        $warn[] = "Keine Zuordnungen angelegt – es gibt noch keine Tätigkeitsarten oder Gruppen";
    }

    return ['log' => $log, 'warnings' => $warn];
}
