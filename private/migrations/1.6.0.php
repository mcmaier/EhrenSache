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
 * EhrenSache - Migration v1.6.0 → v1.6.1
 *
 * Keine Schemaänderung. 1.6.1 sichert den direkten Sprung von jeder älteren
 * Version ab: version.json nennt die Anforderungen (requires), Installer,
 * Update-Assistent und Updater prüfen sie.
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_6_0(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.6.1 prüft die Anforderungen aus version.json',
        ],
        'warnings' => [],
    ];
}
