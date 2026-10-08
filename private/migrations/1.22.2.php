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
 * EhrenSache - Migration v1.22.2 → v1.23.0
 *
 * Kalender-Abo (FI-8, OI-128): Tabelle calendar_feeds. Die Arbeit macht
 * calendarFeedMigrate() — sie legt die Tabelle an, falls sie fehlt, und
 * verändert keine Daten. Die Einstellungszeile calendar_feed_enabled braucht es
 * nicht: Ohne Zeile gilt der Default aus FEATURES (aus).
 *
 * Wiederholbar.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers/calendar_feed_schema.php';

function migrate_1_22_2(PDO $pdo, string $prefix, string $configPath): array
{
    return calendarFeedMigrate($pdo, $prefix);
}
