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
 * EhrenSache - Migration v1.11.1 → v1.11.2
 *
 * Keine Schemaänderung. 1.11.2 schließt eine Lücke in der Gruppengrenze
 * (Anträge und Arbeitszeiten, private/handlers/) und korrigiert drei Fehler
 * aus dem Test vom 21.09.2026 (OI-82 bis OI-84).
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_11_1(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.11.2 korrigiert Handler und Oberfläche',
        ],
        'warnings' => [],
    ];
}
