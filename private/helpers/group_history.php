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

/** Vortag eines Datums JJJJ-MM-TT. */
function groupHistoryDayBefore(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Ungültiges Datum: ' . $date);
    }

    return $parsed->modify('-1 day')->format('Y-m-d');
}

/**
 * Prueft groups_valid_from: Datum JJJJ-MM-TT, nicht nach $today.
 *
 * @param mixed $raw
 * @return ?string Fehlermeldung oder null, wenn gueltig
 */
function groupsCheckValidFrom($raw, string $today): ?string
{
    if (!is_string($raw)) {
        return 'groups_valid_from muss ein Datum sein (JJJJ-MM-TT)';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
        return 'groups_valid_from ist kein gültiges Datum (JJJJ-MM-TT)';
    }
    if ($raw < '1000-01-01') {
        return 'groups_valid_from ist kein gültiges Datum (Jahr vor 1000)';
    }
    if ($raw > $today) {
        return 'groups_valid_from darf nicht in der Zukunft liegen';
    }

    return null;
}

/**
 * Kuerzt bzw. loescht Verlaufseintraege einer Gruppe, die ab $date noch gelten
 * (Spec 2026-10-05, 4.1): beginnt der Eintrag nach dem Vortag, entfaellt er,
 * sonst endet er am Vortag. Gemeinsam fuer Hinzufuegen und Entfernen.
 *
 * @param array<int, array{history_id: int|string, group_id: int|string, valid_from: ?string, valid_to: string}> $history
 * @param array $plan  Plan aus groupsPlanChange(), wird ergaenzt
 */
function groupHistoryTrim(array $history, int $groupId, string $date, array &$plan): void
{
    $dayBefore = groupHistoryDayBefore($date);
    foreach ($history as $h) {
        if ((int) $h['group_id'] !== $groupId || $h['valid_to'] < $date) {
            continue;
        }
        if ($h['valid_from'] !== null && $h['valid_from'] > $dayBefore) {
            $plan['delete_history'][] = (int) $h['history_id'];
        } else {
            $plan['update_history'][] = ['history_id' => (int) $h['history_id'], 'valid_to' => $dayBefore];
        }
    }
}

/**
 * Plant eine Aenderung der Gruppen eines Mitglieds (Spec 2026-10-05, 4.1),
 * ohne Datenbank. Daten sind Strings JJJJ-MM-TT und werden als solche
 * verglichen.
 *
 * @param array<int, ?string> $current  heutige Zuordnungen: group_id => valid_from
 * @param array<int, array{history_id: int|string, group_id: int|string, valid_from: ?string, valid_to: string}> $history
 * @param array<int, int|string> $newGroupIds  bereits durch groupsWithParents() gelaufen
 * @param ?string $date  null = von Anfang an (nur beim Anlegen)
 * @return array{insert_current: array<int, array{group_id: int, valid_from: ?string}>,
 *               delete_current: array<int, int>,
 *               insert_history: array<int, array{group_id: int, valid_from: ?string, valid_to: string}>,
 *               update_history: array<int, array{history_id: int, valid_to: string}>,
 *               delete_history: array<int, int>}
 */
function groupsPlanChange(array $current, array $history, array $newGroupIds, ?string $date): array
{
    $plan = ['insert_current' => [], 'delete_current' => [], 'insert_history' => [],
             'update_history' => [], 'delete_history' => []];

    $new = [];
    foreach ($newGroupIds as $id) {
        $new[(int) $id] = true;
    }
    $dayBefore = $date === null ? null : groupHistoryDayBefore($date);

    foreach ($current as $groupId => $validFrom) {
        $groupId = (int) $groupId;
        if (isset($new[$groupId])) {
            continue;
        }
        if ($date === null) {
            throw new InvalidArgumentException('Datum null nur beim Anlegen');
        }
        $plan['delete_current'][] = $groupId;
        // Hat die Zuordnung vor dem Datum gegolten, wandert sie in den Verlauf;
        // begann sie erst am Datum oder spaeter, war es eine Korrektur.
        if ($validFrom === null || $validFrom < $date) {
            $plan['insert_history'][] = ['group_id' => $groupId, 'valid_from' => $validFrom, 'valid_to' => $dayBefore];
        }
        // Aeltere Verlaufseintraege derselben Gruppe duerfen sich nicht ueberlappen.
        groupHistoryTrim($history, $groupId, $date, $plan);
    }

    foreach (array_keys($new) as $groupId) {
        if (array_key_exists($groupId, $current)) {
            continue;
        }
        $plan['insert_current'][] = ['group_id' => $groupId, 'valid_from' => $date];

        if ($date === null) {
            // "Von Anfang an" verdraengt jeden Verlauf derselben Gruppe.
            foreach ($history as $h) {
                if ((int) $h['group_id'] === $groupId) {
                    $plan['delete_history'][] = (int) $h['history_id'];
                }
            }
        } else {
            groupHistoryTrim($history, $groupId, $date, $plan);
        }
    }

    return $plan;
}

/**
 * Fuehrt eine Gruppenaenderung fuer ein Mitglied aus (Spec 2026-10-05, 4.1).
 * $newGroupIds ist bereits durch groupsWithParents() gelaufen. Laeuft in der
 * Transaktion des Aufrufers oder in einer eigenen.
 *
 * @param array<int, int|string> $newGroupIds
 * @return bool ob sich die heutigen Zuordnungen geaendert haben
 */
function groupsApplyChange(PDO $db, $database, int $memberId, array $newGroupIds, ?string $date): bool
{
    $prefix = $database->table('');
    $own    = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        // Gleichzeitige Bearbeitung desselben Mitglieds: Zeile sperren, bevor der
        // Stand gelesen wird (Spec 4.1, Invariante keine Ueberlappung).
        $lock = $db->prepare("SELECT member_id FROM {$prefix}members WHERE member_id = ? FOR UPDATE");
        $lock->execute([$memberId]);

        $stmt = $db->prepare("SELECT group_id, valid_from FROM {$prefix}member_group_assignments WHERE member_id = ?");
        $stmt->execute([$memberId]);
        $current = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $current[(int) $row['group_id']] = $row['valid_from'];
        }
        $stmt = $db->prepare("SELECT history_id, group_id, valid_from, valid_to FROM {$prefix}member_group_history WHERE member_id = ?");
        $stmt->execute([$memberId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $plan = groupsPlanChange($current, $history, $newGroupIds, $date);

        $ins = $db->prepare("INSERT INTO {$prefix}member_group_history (member_id, group_id, valid_from, valid_to) VALUES (?, ?, ?, ?)");
        foreach ($plan['insert_history'] as $h) {
            $ins->execute([$memberId, $h['group_id'], $h['valid_from'], $h['valid_to']]);
        }
        $del = $db->prepare("DELETE FROM {$prefix}member_group_assignments WHERE member_id = ? AND group_id = ?");
        foreach ($plan['delete_current'] as $groupId) {
            $del->execute([$memberId, $groupId]);
        }
        $upd = $db->prepare("UPDATE {$prefix}member_group_history SET valid_to = ? WHERE history_id = ? AND member_id = ?");
        foreach ($plan['update_history'] as $h) {
            $upd->execute([$h['valid_to'], $h['history_id'], $memberId]);
        }
        $delH = $db->prepare("DELETE FROM {$prefix}member_group_history WHERE history_id = ? AND member_id = ?");
        foreach ($plan['delete_history'] as $historyId) {
            $delH->execute([$historyId, $memberId]);
        }
        $insC = $db->prepare("INSERT INTO {$prefix}member_group_assignments (member_id, group_id, valid_from) VALUES (?, ?, ?)");
        foreach ($plan['insert_current'] as $c) {
            $insC->execute([$memberId, $c['group_id'], $c['valid_from']]);
        }

        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own) {
            $db->rollBack();
        }
        throw $e;
    }

    return $plan['insert_current'] !== [] || $plan['delete_current'] !== [];
}
