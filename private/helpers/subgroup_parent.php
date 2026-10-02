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
 * Register gehoeren zu einer oder mehreren Gruppen (Spec 2026-10-02-register-gruppe-besetzung,
 * Abschnitte 3 und 8).
 *
 * Diese Datei laedt spaeter der Update-Pfad: nur Syntax bis PHP 8.0
 * (tests/suites/update_path_syntax.php). Den Schritt in der Migrationskette
 * legt die Release-Sitzung an und ruft dort subgroupParentMigrate() auf.
 */
declare(strict_types=1);

/**
 * Umstellung einer Installation: Tabelle subgroup_parents anlegen, falls sie
 * fehlt, und jedes Register ohne Gruppe melden. Leitet nichts ab und ergaenzt
 * niemanden. Wiederholbar (legt nichts doppelt an).
 *
 * @return array{log: string[], warnings: string[]}
 */
function subgroupParentMigrate(PDO $pdo, string $prefix): array
{
    $log      = [];
    $warnings = [];
    $groups   = $prefix . 'member_groups';
    $parents  = $prefix . 'subgroup_parents';

    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($parents)
    )->fetchColumn();

    if ($exists === 0) {
        $pdo->exec("CREATE TABLE `{$parents}` (
            subgroup_id INT NOT NULL,
            group_id INT NOT NULL,
            PRIMARY KEY (subgroup_id, group_id),
            FOREIGN KEY (subgroup_id) REFERENCES `{$groups}`(group_id) ON DELETE CASCADE,
            FOREIGN KEY (group_id) REFERENCES `{$groups}`(group_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $log[] = 'Tabelle subgroup_parents angelegt.';
    }

    $rows = $pdo->query("SELECT g.group_id, g.group_name FROM `{$groups}` g
                          WHERE g.is_subgroup = 1
                            AND NOT EXISTS (SELECT 1 FROM `{$parents}` p WHERE p.subgroup_id = g.group_id)
                          ORDER BY g.group_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $warnings[] = "Register {$r['group_name']} ({$r['group_id']}) ohne Gruppe -- bitte in der Gruppenverwaltung zuordnen.";
    }

    return ['log' => $log, 'warnings' => $warnings];
}
