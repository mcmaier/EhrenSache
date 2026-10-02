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
 * Register gehoeren zu einer Gruppe (Spec 2026-10-02-register-gruppe-besetzung,
 * Abschnitte 3, 4 und 8).
 *
 * Diese Datei laedt spaeter der Update-Pfad: nur Syntax bis PHP 8.0
 * (tests/suites/update_path_syntax.php). Den Schritt in der Migrationskette
 * legt die Release-Sitzung an und ruft dort subgroupParentMigrate() auf.
 */
declare(strict_types=1);

/**
 * Gruppe je Register ableiten, wo sie eindeutig ist: Stehen alle Mitglieder
 * eines Registers in genau einer gemeinsamen gewoehnlichen Gruppe, gehoert das
 * Register dieser Gruppe. Leeres Register, Mitglied ohne gewoehnliche Gruppe,
 * keine oder mehrere gemeinsame Gruppen: keine Zuordnung.
 *
 * @param array<int, array<int, int>> $membersBySubgroup subgroup_id => [member_id, ...]
 * @param array<int, array<int, int>> $ordinaryByMember  member_id => [gewoehnliche group_id, ...]
 * @return array<int, int> subgroup_id => parent_group_id
 */
function subgroupParentInfer(array $membersBySubgroup, array $ordinaryByMember): array
{
    $result = [];
    foreach ($membersBySubgroup as $subgroupId => $memberIds) {
        if ($memberIds === []) {
            continue;
        }
        $common = null;
        foreach ($memberIds as $memberId) {
            $groups = $ordinaryByMember[$memberId] ?? [];
            $common = $common === null ? $groups : array_values(array_intersect($common, $groups));
            if ($common === []) {
                break;
            }
        }
        if ($common !== null && count($common) === 1) {
            $result[(int) $subgroupId] = (int) $common[0];
        }
    }
    ksort($result);

    return $result;
}

/**
 * Umstellung einer Installation: Spalte anlegen, Gruppen ableiten,
 * Mitgliedschaftsregel anwenden. Wiederholbar (legt nichts doppelt an).
 *
 * @return array{log: string[], warnings: string[]}
 */
function subgroupParentMigrate(PDO $pdo, string $prefix): array
{
    $log      = [];
    $warnings = [];
    $groups   = $prefix . 'member_groups';
    $assign   = $prefix . 'member_group_assignments';

    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($groups) . "
            AND COLUMN_NAME = 'parent_group_id'"
    )->fetchColumn();

    if ($exists === 0) {
        $pdo->exec("ALTER TABLE `{$groups}` ADD COLUMN parent_group_id INT NULL DEFAULT NULL AFTER sort_order");
        $pdo->exec("ALTER TABLE `{$groups}` ADD CONSTRAINT `fk_{$prefix}member_groups_parent`
                    FOREIGN KEY (parent_group_id) REFERENCES `{$groups}`(group_id) ON DELETE SET NULL");
        $log[] = 'Spalte parent_group_id an member_groups angelegt.';
    }

    // Register ohne Gruppe mit ihren Mitgliedern
    $membersBySubgroup = [];
    foreach ($pdo->query("SELECT group_id FROM `{$groups}` WHERE is_subgroup = 1 AND parent_group_id IS NULL")
                 ->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $membersBySubgroup[(int) $sid] = [];
    }
    if ($membersBySubgroup !== []) {
        $rows = $pdo->query("SELECT a.group_id, a.member_id FROM `{$assign}` a
                              JOIN `{$groups}` g ON g.group_id = a.group_id
                             WHERE g.is_subgroup = 1 AND g.parent_group_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $membersBySubgroup[(int) $r['group_id']][] = (int) $r['member_id'];
        }
    }

    $ordinaryByMember = [];
    $rows = $pdo->query("SELECT a.member_id, a.group_id FROM `{$assign}` a
                          JOIN `{$groups}` g ON g.group_id = a.group_id
                         WHERE g.is_subgroup = 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $ordinaryByMember[(int) $r['member_id']][] = (int) $r['group_id'];
    }

    $update  = $pdo->prepare("UPDATE `{$groups}` SET parent_group_id = ? WHERE group_id = ?");
    $inferred = subgroupParentInfer($membersBySubgroup, $ordinaryByMember);
    foreach ($inferred as $sid => $pid) {
        $update->execute([$pid, $sid]);
        $log[] = "Register {$sid} der Gruppe {$pid} zugeordnet.";
    }
    foreach (array_keys($membersBySubgroup) as $sid) {
        if (!isset($inferred[$sid])) {
            $warnings[] = "Register {$sid} ohne Gruppe -- bitte in der Gruppenverwaltung zuordnen.";
        }
    }

    // Mitgliedschaftsregel: Registermitglieder stehen auch in der Gruppe
    $missing = $pdo->query("SELECT a.member_id, g.parent_group_id
                              FROM `{$assign}` a
                              JOIN `{$groups}` g ON g.group_id = a.group_id
                             WHERE g.parent_group_id IS NOT NULL
                               AND NOT EXISTS (SELECT 1 FROM `{$assign}` p
                                                WHERE p.member_id = a.member_id
                                                  AND p.group_id = g.parent_group_id)")->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare("INSERT IGNORE INTO `{$assign}` (member_id, group_id) VALUES (?, ?)");
    foreach ($missing as $m) {
        $insert->execute([(int) $m['member_id'], (int) $m['parent_group_id']]);
        $log[] = "Mitglied {$m['member_id']} zusaetzlich Gruppe {$m['parent_group_id']} zugeordnet.";
    }

    return ['log' => $log, 'warnings' => $warnings];
}
