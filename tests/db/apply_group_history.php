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
 * Wendet groupHistoryMigrate() auf die Datenbank aus private/config/config.php
 * an -- fuer Test- und Entwicklungsdatenbanken, bis die Release-Sitzung den
 * Migrationsschritt anlegt. Aufruf: php tests/db/apply_group_history.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/config_reader.php';
require_once __DIR__ . '/../../private/helpers/group_history.php';

$cfg = configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'));
$db  = $cfg['db'];

// host kann "name:port" sein
$host = $db['host'];
$port = '3306';
if (str_contains($host, ':')) {
    [$host, $port] = explode(':', $host, 2);
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db['name']};charset=utf8mb4",
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "Datenbank: {$db['name']} (Praefix {$db['prefix']})\n";
$result = groupHistoryMigrate($pdo, $db['prefix']);
foreach ($result['log'] as $line) {
    echo "LOG  $line\n";
}
foreach ($result['warnings'] as $line) {
    echo "WARN $line\n";
}
echo count($result['log']) . " Protokollzeilen, " . count($result['warnings']) . " Hinweise.\n";
