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
 * EhrenSache - Migration v1.11.3 → v1.12.0
 *
 * Keine Schemaänderung. 1.12.0 bringt Entschuldigen und Antragsentscheidung
 * in der Check-in-PWA, einen neu gegliederten Verlauf und Korrekturen an
 * Import (OI-75) und Zeiterfassungstabelle (OI-10). Neue Felder liefern die
 * Handler aus vorhandenen Tabellen.
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_11_3(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.12.0 erweitert Oberfläche und Handler',
        ],
        'warnings' => [],
    ];
}
