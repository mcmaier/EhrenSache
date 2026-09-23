<?php

/**
 * EhrenSache - Migration v1.12.2 → v1.13.0
 *
 * Keine Schemaänderung. 1.13.0 ist eine reine Oberflächenänderung
 * (Status-Chips statt Zählkarten, OI-86).
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_12_2(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.13.0 ändert nur die Oberfläche',
        ],
        'warnings' => [],
    ];
}
