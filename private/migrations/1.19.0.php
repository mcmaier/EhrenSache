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
 * EhrenSache - Migration v1.19.0 → v1.20.0
 *
 * Register gehören jetzt zu einer oder mehreren Gruppen (OI-70, Rest von FI-14):
 * neue Tabelle subgroup_parents. Die eigentliche Arbeit macht
 * subgroupParentMigrate() — sie legt die Tabelle an, falls sie fehlt, und meldet
 * jedes Register ohne Gruppe als Warnung. Abgeleitet wird nichts: Welche Gruppe
 * ein Register trägt, entscheidet der Verein in der Gruppenverwaltung.
 *
 * Wiederholbar; auf einer Installation, die die Tabelle schon hat, bleibt es bei
 * den Warnungen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers/subgroup_parent.php';

function migrate_1_19_0(PDO $pdo, string $prefix, string $configPath): array
{
    return subgroupParentMigrate($pdo, $prefix);
}
