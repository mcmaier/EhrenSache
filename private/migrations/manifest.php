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
    [
        'from'     => '1.2.0',
        'to'       => '1.2.1',
        'file'     => '1.2.0.php',
        'function' => 'migrate_1_2_0',
    ],
    [
        'from'     => '1.2.1',
        'to'       => '1.2.2',
        'file'     => '1.2.1.php',
        'function' => 'migrate_1_2_1',
    ],
    [
        'from'     => '1.2.2',
        'to'       => '1.2.3',
        'file'     => '1.2.2.php',
        'function' => 'migrate_1_2_2',
    ],
    [
        'from'     => '1.2.3',
        'to'       => '1.2.4',
        'file'     => '1.2.3.php',
        'function' => 'migrate_1_2_3',
    ],
    [
        'from'     => '1.2.4',
        'to'       => '1.2.5',
        'file'     => '1.2.4.php',
        'function' => 'migrate_1_2_4',
    ],
    [
        'from'     => '1.2.5',
        'to'       => '1.3.0',
        'file'     => '1.2.5.php',
        'function' => 'migrate_1_2_5',
    ],
    [
        'from'     => '1.3.0',
        'to'       => '1.3.1',
        'file'     => '1.3.0.php',
        'function' => 'migrate_1_3_0',
    ],
    [
        'from'     => '1.3.1',
        'to'       => '1.4.0',
        'file'     => '1.3.1.php',
        'function' => 'migrate_1_3_1',
    ],
    [
        'from'     => '1.4.0',
        'to'       => '1.4.1',
        'file'     => '1.4.0.php',
        'function' => 'migrate_1_4_0',
    ],
    [
        'from'     => '1.4.1',
        'to'       => '1.5.0',
        'file'     => '1.4.1.php',
        'function' => 'migrate_1_4_1',
    ],
    [
        'from'     => '1.5.0',
        'to'       => '1.5.1',
        'file'     => '1.5.0.php',
        'function' => 'migrate_1_5_0',
    ],
    [
        'from'     => '1.5.1',
        'to'       => '1.6.0',
        'file'     => '1.5.1.php',
        'function' => 'migrate_1_5_1',
    ],
    [
        'from'     => '1.6.0',
        'to'       => '1.6.1',
        'file'     => '1.6.0.php',
        'function' => 'migrate_1_6_0',
    ],
    [
        'from'     => '1.6.1',
        'to'       => '1.7.0',
        'file'     => '1.6.1.php',
        'function' => 'migrate_1_6_1',
    ],
    [
        'from'     => '1.7.0',
        'to'       => '1.8.0',
        'file'     => '1.7.0.php',
        'function' => 'migrate_1_7_0',
    ],
    [
        'from'     => '1.8.0',
        'to'       => '1.9.0',
        'file'     => '1.8.0.php',
        'function' => 'migrate_1_8_0',
    ],
    [
        'from'     => '1.9.0',
        'to'       => '1.9.1',
        'file'     => '1.9.0.php',
        'function' => 'migrate_1_9_0',
    ],
    [
        'from'     => '1.9.1',
        'to'       => '1.9.2',
        'file'     => '1.9.1.php',
        'function' => 'migrate_1_9_1',
    ],
    [
        'from'     => '1.9.2',
        'to'       => '1.9.3',
        'file'     => '1.9.2.php',
        'function' => 'migrate_1_9_2',
    ],
    [
        'from'     => '1.9.3',
        'to'       => '1.10.0',
        'file'     => '1.9.3.php',
        'function' => 'migrate_1_9_3',
    ],
    [
        'from'     => '1.10.0',
        'to'       => '1.11.0',
        'file'     => '1.10.0.php',
        'function' => 'migrate_1_10_0',
    ],
    [
        'from'     => '1.11.0',
        'to'       => '1.11.1',
        'file'     => '1.11.0.php',
        'function' => 'migrate_1_11_0',
    ],
    [
        'from'     => '1.11.1',
        'to'       => '1.11.2',
        'file'     => '1.11.1.php',
        'function' => 'migrate_1_11_1',
    ],
    [
        'from'     => '1.11.2',
        'to'       => '1.11.3',
        'file'     => '1.11.2.php',
        'function' => 'migrate_1_11_2',
    ],
    [
        'from'     => '1.11.3',
        'to'       => '1.12.0',
        'file'     => '1.11.3.php',
        'function' => 'migrate_1_11_3',
    ],
];
