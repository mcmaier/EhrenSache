<?php

/**
 * EhrenSache - Migration v1.9.1 → v1.9.2
 *
 * Keine Schemaänderung. 1.9.2 ordnet die Filterleisten im Dashboard neu
 * (Terminart- und Herkunftsfilter in der Terminverwaltung, Kalender filtert
 * mit) — reine Oberfläche.
 *
 * Der Schritt existiert trotzdem: Die Kette in manifest.php muss lückenlos bis
 * zur Version aus version.json führen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

function migrate_1_9_1(PDO $pdo, string $prefix, string $configPath): array
{
    return [
        'log' => [
            'Keine Datenbankänderung nötig – 1.9.2 ändert nur die Oberfläche',
        ],
        'warnings' => [],
    ];
}
