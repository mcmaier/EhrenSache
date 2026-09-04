<?php

/**
 * EhrenSache - Migration v1.2.5 → v1.3.0
 *
 * Änderungen:
 * - members.pin_hash, members.pin_updated_at (Stations-PIN, nur als Hash)
 * - users.device_type um 'kiosk' erweitert (virtuelle Station)
 * - records.checkin_source um 'station_pin' erweitert
 * - work_sessions.source um 'station' erweitert
 * - Einstellungen station_pin_enabled, station_pin_min_length
 *
 * Hintergrund: Spec docs/superpowers/specs/2026-09-04-station-pin-kiosk-design.md.
 * Kein Unique-Index auf member_number — ein Altbestand mit Dubletten soll das
 * Update nicht blockieren. Dubletten werden als Warnung gemeldet; die Station
 * lehnt eine mehrdeutige Nummer zur Laufzeit ab.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_2_5(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    // ---- members: PIN-Spalten ----
    $hasPin = $pdo->query("SHOW COLUMNS FROM `{$prefix}members` LIKE 'pin_hash'")->fetch();
    if ($hasPin === false) {
        $pdo->exec("ALTER TABLE `{$prefix}members`
                    ADD COLUMN `pin_hash` VARCHAR(255) NULL AFTER `member_number`,
                    ADD COLUMN `pin_updated_at` DATETIME NULL AFTER `pin_hash`");
        $log[] = 'Spalten <code>members.pin_hash</code> und <code>members.pin_updated_at</code> angelegt';
    } else {
        $log[] = 'Spalte <code>members.pin_hash</code> existiert bereits – unverändert';
    }

    // ---- Enums erweitern (MODIFY ist idempotent) ----
    $pdo->exec("ALTER TABLE `{$prefix}users`
                MODIFY `device_type` ENUM('totp_location','auth_device','kiosk') DEFAULT NULL");
    $log[] = 'Gerätetyp <code>kiosk</code> (virtuelle Station) verfügbar';

    $pdo->exec("ALTER TABLE `{$prefix}records`
                MODIFY `checkin_source` ENUM('admin','user_totp','device_auth','auto_checkin',
                                             'import','timer','station_pin') DEFAULT 'admin'");
    $log[] = 'Check-in-Quelle <code>station_pin</code> verfügbar';

    $pdo->exec("ALTER TABLE `{$prefix}work_sessions`
                MODIFY `source` ENUM('timer','manual','admin','import','station')
                NOT NULL DEFAULT 'manual'");
    $log[] = 'Sitzungsquelle <code>station</code> verfügbar';

    // ---- Einstellungen ----
    $insert = $pdo->prepare("
        INSERT IGNORE INTO `{$prefix}system_settings`
            (setting_key, setting_value, setting_type, category, description)
        VALUES (?, ?, ?, 'general', ?)
    ");
    $insert->execute(['station_pin_enabled', '0', 'boolean',
                      'Anmeldung mit Mitgliedsnummer und PIN an einer Station erlauben']);
    $insert->execute(['station_pin_min_length', '4', 'number',
                      'Mindestlänge der Stations-PIN (4 bis 8 Ziffern)']);
    $log[] = 'Einstellungen <code>station_pin_enabled</code> (aus) und '
           . '<code>station_pin_min_length</code> (4) angelegt';

    // ---- Dubletten der Mitgliedsnummer melden ----
    $dupes = $pdo->query("SELECT member_number, COUNT(*) AS n
                          FROM `{$prefix}members`
                          WHERE member_number IS NOT NULL AND member_number <> ''
                          GROUP BY member_number HAVING n > 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dupes as $d) {
        $warn[] = 'Mitgliedsnummer <code>' . htmlspecialchars((string) $d['member_number'], ENT_QUOTES)
                . '</code> ist ' . (int) $d['n'] . '-mal vergeben. Die Stations-Anmeldung lehnt '
                . 'diese Nummer ab, bis sie eindeutig ist';
    }

    return ['log' => $log, 'warnings' => $warn];
}
