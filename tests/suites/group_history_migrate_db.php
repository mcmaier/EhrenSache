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
 * Umstellungsfunktion groupHistoryMigrate() gegen MariaDB (Spec 2026-10-05).
 *
 * Arbeitet ausschliesslich mit eigenen Wegwerf-Tabellen (Praefix ghmt_ plus
 * Zufallsteil), die der Test anlegt und am Ende wieder entfernt. Ohne
 * pdo_mysql oder Konfiguration wird still uebersprungen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/config_reader.php';
require_once __DIR__ . '/../../private/helpers/group_history.php';

$ghmPdo = null;
if (extension_loaded('pdo_mysql') && is_file(__DIR__ . '/../../private/config/config.php')) {
    try {
        $ghmCfg  = configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'));
        $ghmDb   = $ghmCfg['db'];
        $ghmHost = $ghmDb['host'];
        $ghmPort = '3306';
        if (str_contains($ghmHost, ':')) {
            [$ghmHost, $ghmPort] = explode(':', $ghmHost, 2);
        }
        $ghmPdo = new PDO(
            "mysql:host={$ghmHost};port={$ghmPort};dbname={$ghmDb['name']};charset=utf8mb4",
            $ghmDb['user'],
            $ghmDb['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $e) {
        $ghmPdo = null;
    }
}

if ($ghmPdo !== null) {
    test('groupHistoryMigrate: legt Spalte und Tabelle an, wiederholbar, Daten unveraendert', function () use ($ghmPdo) {
        $prefix = 'ghmt_' . bin2hex(random_bytes(3)) . '_';
        $tables = ['member_group_history', 'member_group_assignments', 'member_groups', 'members'];
        try {
            $ghmPdo->exec("CREATE TABLE `{$prefix}members` (member_id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB");
            $ghmPdo->exec("CREATE TABLE `{$prefix}member_groups` (group_id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB");
            $ghmPdo->exec("CREATE TABLE `{$prefix}member_group_assignments` (
                member_id INT NOT NULL, group_id INT NOT NULL,
                PRIMARY KEY (member_id, group_id),
                FOREIGN KEY (member_id) REFERENCES `{$prefix}members`(member_id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES `{$prefix}member_groups`(group_id) ON DELETE CASCADE
            ) ENGINE=InnoDB");
            $ghmPdo->exec("INSERT INTO `{$prefix}members` () VALUES ()");
            $ghmPdo->exec("INSERT INTO `{$prefix}member_groups` () VALUES ()");
            $ghmPdo->exec("INSERT INTO `{$prefix}member_group_assignments` (member_id, group_id) VALUES (1, 1)");

            $first  = groupHistoryMigrate($ghmPdo, $prefix);
            $second = groupHistoryMigrate($ghmPdo, $prefix);
            assertSame(2, count($first['log']), 'erster Lauf: 2 Protokollzeilen');
            assertSame(0, count($second['log']), 'zweiter Lauf: keine Protokollzeilen');

            $row = $ghmPdo->query("SELECT member_id, group_id, valid_from FROM `{$prefix}member_group_assignments`")
                          ->fetchAll(PDO::FETCH_ASSOC);
            assertSame(1, count($row), 'Zuordnung bleibt erhalten');
            assertTrue((int) $row[0]['member_id'] === 1 && (int) $row[0]['group_id'] === 1, 'Zuordnung unveraendert');
            assertTrue($row[0]['valid_from'] === null, 'valid_from der vorhandenen Zeile ist NULL');

            $cols = $ghmPdo->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $ghmPdo->quote($prefix . 'member_group_history')
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach (['history_id', 'member_id', 'group_id', 'valid_from', 'valid_to'] as $col) {
                assertTrue(in_array($col, $cols, true), "Spalte {$col} fehlt in member_group_history");
            }
        } finally {
            foreach ($tables as $t) {
                $name = $prefix . $t;
                // Nur eigene Wegwerf-Tabellen entfernen
                if (str_starts_with($name, 'ghmt_')) {
                    $ghmPdo->exec("DROP TABLE IF EXISTS `{$name}`");
                }
            }
        }
    });
}
