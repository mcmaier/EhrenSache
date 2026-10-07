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
declare(strict_types=1);

/**
 * Legt calendar_feeds in der Datenbank aus private/config/config.php an (FI-8).
 * Fuer Testdatenbanken, solange der Migrationsschritt fehlt (Release-Sitzung).
 *
 *   php tests/db/apply_calendar_feed.php
 */
require_once __DIR__ . '/../../private/helpers/config_reader.php';
require_once __DIR__ . '/../../private/helpers/calendar_feed_schema.php';

$cfg = configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'));
$db  = $cfg['db'];
$pdo = new PDO(
    'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$result = calendarFeedMigrate($pdo, $db['prefix']);
echo "Datenbank {$db['name']}:\n";
foreach ($result['log'] as $zeile) {
    echo "  {$zeile}\n";
}
foreach ($result['warnings'] as $zeile) {
    echo "  WARNUNG: {$zeile}\n";
}
