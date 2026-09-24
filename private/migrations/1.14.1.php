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
 * EhrenSache - Migration v1.14.1 → v1.15.0
 *
 * Keine Schemaänderung. 1.15.0 führt den Zustand „Kommend“ ein und vereinheitlicht
 * die Grenze, ab der ein Termin als begonnen gilt — beides rechnet aus vorhandenen
 * Tabellen und der bestehenden Einstellung checkin_tolerance_hours.
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_14_1(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.15.0 rechnet aus vorhandenen Tabellen',
        ],
        'warnings' => [],
    ];
}
