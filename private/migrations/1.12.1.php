<?php

/**
 * EhrenSache - Migration v1.12.1 → v1.12.2
 *
 * Keine Schemaänderung. 1.12.2 korrigiert drei Stellen in der Oberfläche des
 * Dashboards (Statistik, Geräteliste, Tätigkeitsarten).
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_12_1(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.12.2 korrigiert die Oberfläche',
        ],
        'warnings' => [],
    ];
}
