<?php

/**
 * EhrenSache - Migration v1.11.0 → v1.11.1
 *
 * Keine Schemaänderung. 1.11.1 korrigiert das Anlegen von Terminen im
 * Listen-Tab der Check-in-PWA (public/checkin/js/app.js).
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_11_0(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.11.1 korrigiert die Check-in-PWA',
        ],
        'warnings' => [],
    ];
}
