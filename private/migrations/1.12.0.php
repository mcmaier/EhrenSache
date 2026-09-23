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
 * EhrenSache - Migration v1.12.0 → v1.12.1
 *
 * Keine Schemaänderung. 1.12.1 bringt Ladeanzeige und Timeout der API-Wrapper
 * (OI-85) und korrigiert, wann und mit welchen Zeilenenden Installer und
 * Update-Assistent ihre Sperrdatei schreiben.
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_12_0(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.12.1 korrigiert Oberfläche und Update-Assistent',
        ],
        'warnings' => [],
    ];
}
