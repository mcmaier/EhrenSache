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
 * Legt die Tabelle des Kalender-Abos (FI-8) an, falls sie fehlt.
 *
 * Fuer die Release-Sitzung: Der Migrationsschritt ruft diese Funktion auf und
 * gibt log/warnings zurueck (Muster groupHistoryMigrate(), OI-122). Wiederholbar,
 * veraendert keine Daten. Die Einstellungszeile calendar_feed_enabled braucht es
 * nicht -- ohne Zeile gilt der Default aus FEATURES (aus).
 *
 * Steht im Update-Pfad: nur Syntax bis PHP 8.0.
 *
 * @return array{log: string[], warnings: string[]}
 */
function calendarFeedMigrate(PDO $pdo, string $prefix): array
{
    $log = [];
    $table = $prefix . 'calendar_feeds';

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['log' => ["{$table} vorhanden"], 'warnings' => []];
    }

    $pdo->exec("CREATE TABLE `{$table}` (
        `user_id` int(11) NOT NULL,
        `token_hash` char(64) NOT NULL,
        `hide_declined` tinyint(1) NOT NULL DEFAULT 1,
        `created_at` datetime NOT NULL,
        `last_fetched_at` datetime DEFAULT NULL,
        PRIMARY KEY (`user_id`),
        UNIQUE KEY `token_hash` (`token_hash`),
        CONSTRAINT `{$prefix}calfeed_user_fk` FOREIGN KEY (`user_id`)
            REFERENCES `{$prefix}users` (`user_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = "{$table} angelegt";

    return ['log' => $log, 'warnings' => []];
}
