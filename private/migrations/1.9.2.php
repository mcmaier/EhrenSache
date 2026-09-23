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
 * EhrenSache - Migration v1.9.2 → v1.9.3
 *
 * Keine Schemaänderung. 1.9.3 korrigiert die Rechteprüfung beim Abruf der
 * Terminliste (private/handlers/appointments.php).
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_9_2(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.9.3 korrigiert eine Rechteprüfung',
        ],
        'warnings' => [],
    ];
}
