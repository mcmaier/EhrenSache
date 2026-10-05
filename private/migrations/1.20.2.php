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
 * EhrenSache - Migration v1.20.2 → v1.21.0
 *
 * Gruppenzugehörigkeit mit Zeitraum (OI-115): Spalte
 * member_group_assignments.valid_from und Tabelle member_group_history. Die
 * Arbeit macht groupHistoryMigrate() — sie legt beides an, falls es fehlt, und
 * verändert keine Daten: Bestehende Zuordnungen gelten danach „von Anfang an“
 * (valid_from NULL), der Verlauf beginnt leer.
 *
 * Wiederholbar.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers/group_history.php';

function migrate_1_20_2(PDO $pdo, string $prefix, string $configPath): array
{
    return groupHistoryMigrate($pdo, $prefix);
}
