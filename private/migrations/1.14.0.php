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
 * EhrenSache - Migration v1.14.0 → v1.14.1
 *
 * Keine Schemaänderung. 1.14.1 korrigiert die globale Rate-Grenze, die in der
 * Sitzung statt in der Datenbank zählte, und lässt fremde Einzelsätze wie nicht
 * vorhandene antworten. Die Tabelle rate_limits existiert seit 1.0.0.
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_14_0(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.14.1 ändert nur Programmlogik',
        ],
        'warnings' => [],
    ];
}
