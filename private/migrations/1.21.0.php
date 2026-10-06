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
 * EhrenSache - Migration v1.21.0 → v1.22.0
 *
 * Keine Schemaänderung. 1.22.0 bringt Termine anlegen, bearbeiten und löschen in der
 * Check-in-App (OI-123, OI-125), die Rückfrage beim Verlegen (OI-124) und das CSS-Bündel
 * (OI-120) — ohne Änderung an Tabellen.
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_21_0(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.22.0 kommt ohne Schemaänderung aus',
        ],
        'warnings' => [],
    ];
}
