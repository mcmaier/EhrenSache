<?php

/**
 * EhrenSache - Migration v1.11.2 → v1.11.3
 *
 * Keine Schemaänderung. 1.11.3 schließt eine Lücke in der Check-in-PWA:
 * Termintitel und Terminart im Verlauf werden maskiert (public/checkin/js/app.js).
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_11_2(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.11.3 korrigiert die Check-in-PWA',
        ],
        'warnings' => [],
    ];
}
