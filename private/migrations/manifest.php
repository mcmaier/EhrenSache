<?php
/**
 * EhrenSache - Migrationsmanifest
 *
 * Beim Hinzufügen einer Migration ist dies die einzige Datei, die geändert wird:
 * einen Schritt anhängen, dessen 'from' der 'to' des Vorgängers entspricht.
 *
 * from     Version, auf der die Migration aufsetzt
 * to       Version, auf die sie führt
 * file     Dateiname unterhalb von private/migrations/
 * function Funktion in dieser Datei, Signatur:
 *          fn(PDO $pdo, string $prefix, string $configPath): array{log: string[], warnings: string[]}
 */
declare(strict_types=1);

return [
    [
        'from'     => '1.0.0',
        'to'       => '1.1.3',
        'file'     => '1.0.0.php',
        'function' => 'migrate_1_0_0',
    ],
    [
        'from'     => '1.1.3',
        'to'       => '1.2.0',
        'file'     => '1.1.3.php',
        'function' => 'migrate_1_1_3',
    ],
];
