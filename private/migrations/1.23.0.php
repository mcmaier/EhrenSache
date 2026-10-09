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
 * EhrenSache - Migration v1.23.0 → v1.23.1
 *
 * Keine Schemaänderung. Seit 1.23.1 zählt der Status der Mitgliedschaftszeiträume
 * (OI-130). membershipStatusCheck() meldet Mitglieder, bei denen ein beendeter
 * Zeitraum „Inaktiv“ vermutlich „war aktiv von … bis …“ meinte, als Warnung —
 * es verändert keine Daten (Entscheidung 2026-10-08, Variante b).
 *
 * Wiederholbar.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers/membership_status_check.php';

function migrate_1_23_0(PDO $pdo, string $prefix, string $configPath): array
{
    return membershipStatusCheck($pdo, $prefix);
}
