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
 * - v_users_extended aus dem Schema neu erstellt (role_name kennt 'kiosk')
 *
 * Hintergrund: Spec docs/superpowers/specs/2026-09-04-station-pin-kiosk-design.md.
 * Kein Unique-Index auf member_number — ein Altbestand mit Dubletten soll das
 * Update nicht blockieren. Dubletten werden als Warnung gemeldet; die Station
 * lehnt eine mehrdeutige Nummer zur Laufzeit ab.
 *
 * Die drei MODIFY-Statements sind einzeln gegen information_schema.COLUMNS
 * abgesichert: fehlt eine Spalte, wird eine Warnung protokolliert statt die
 * Migration abzubrechen und die Datenbank halb migriert zurückzulassen.
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_2_5(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    // ---- members: PIN-Spalten (einzeln geprüft, damit ein halb angewendeter
    //      Stand repariert werden kann) ----
    $hasPinHash = $pdo->query("SHOW COLUMNS FROM `{$prefix}members` LIKE 'pin_hash'")->fetch();
    if ($hasPinHash === false) {
        $pdo->exec("ALTER TABLE `{$prefix}members`
                    ADD COLUMN `pin_hash` VARCHAR(255) NULL AFTER `member_number`");
        $log[] = 'Spalte <code>members.pin_hash</code> angelegt';
    } else {
        $log[] = 'Spalte <code>members.pin_hash</code> existiert bereits – unverändert';
    }

    $hasPinUpdatedAt = $pdo->query("SHOW COLUMNS FROM `{$prefix}members` LIKE 'pin_updated_at'")->fetch();
    if ($hasPinUpdatedAt === false) {
        $pdo->exec("ALTER TABLE `{$prefix}members`
                    ADD COLUMN `pin_updated_at` DATETIME NULL AFTER `pin_hash`");
        $log[] = 'Spalte <code>members.pin_updated_at</code> angelegt';
    } else {
        $log[] = 'Spalte <code>members.pin_updated_at</code> existiert bereits – unverändert';
    }

    // ---- users.device_type um 'kiosk' erweitern ----
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'device_type'
    ");
    $stmt->execute([$prefix . 'users']);
    $columnType = (string) $stmt->fetchColumn();

    if ($columnType === '') {
        $warn[] = "Spalte <code>device_type</code> in <code>{$prefix}users</code> nicht gefunden";
    } elseif (strpos($columnType, 'kiosk') !== false) {
        $log[] = 'Gerätetyp <code>kiosk</code> ist bereits verfügbar – übersprungen';
    } else {
        $pdo->exec("ALTER TABLE `{$prefix}users`
                    MODIFY `device_type` ENUM('totp_location','auth_device','kiosk') DEFAULT NULL");
        $log[] = 'Gerätetyp <code>kiosk</code> (virtuelle Station) verfügbar';
    }

    // ---- records.checkin_source um 'station_pin' erweitern ----
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'checkin_source'
    ");
    $stmt->execute([$prefix . 'records']);
    $columnType = (string) $stmt->fetchColumn();

    if ($columnType === '') {
        $warn[] = "Spalte <code>checkin_source</code> in <code>{$prefix}records</code> nicht gefunden";
    } elseif (strpos($columnType, 'station_pin') !== false) {
        $log[] = 'Check-in-Quelle <code>station_pin</code> ist bereits verfügbar – übersprungen';
    } else {
        $pdo->exec("ALTER TABLE `{$prefix}records`
                    MODIFY `checkin_source` ENUM('admin','user_totp','device_auth','auto_checkin',
                                                 'import','timer','station_pin') DEFAULT 'admin'");
        $log[] = 'Check-in-Quelle <code>station_pin</code> verfügbar';
    }

    // ---- work_sessions.source um 'station' erweitern ----
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'source'
    ");
    $stmt->execute([$prefix . 'work_sessions']);
    $columnType = (string) $stmt->fetchColumn();

    if ($columnType === '') {
        $warn[] = "Spalte <code>source</code> in <code>{$prefix}work_sessions</code> nicht gefunden";
    } elseif (strpos($columnType, 'station') !== false) {
        $log[] = 'Sitzungsquelle <code>station</code> ist bereits verfügbar – übersprungen';
    } else {
        $pdo->exec("ALTER TABLE `{$prefix}work_sessions`
                    MODIFY `source` ENUM('timer','manual','admin','import','station')
                    NOT NULL DEFAULT 'manual'");
        $log[] = 'Sitzungsquelle <code>station</code> verfügbar';
    }

    // ---- Einstellungen ----
    $read = $pdo->prepare("SELECT 1 FROM `{$prefix}system_settings` WHERE setting_key = ?");
    $insert = $pdo->prepare("
        INSERT IGNORE INTO `{$prefix}system_settings`
            (setting_key, setting_value, setting_type, category, description)
        VALUES (?, ?, ?, 'general', ?)
    ");

    $settings = [
        ['station_pin_enabled', '0', 'boolean',
            'Anmeldung mit Mitgliedsnummer und PIN an einer Station erlauben'],
        ['station_pin_min_length', '4', 'number',
            'Mindestlänge der Stations-PIN (4 bis 8 Ziffern)'],
    ];
    foreach ($settings as [$key, $value, $type, $description]) {
        $read->execute([$key]);
        $existed = $read->fetch() !== false;

        $insert->execute([$key, $value, $type, $description]);

        $log[] = $existed
            ? "Einstellung <code>{$key}</code> existiert bereits – unverändert"
            : "Einstellung <code>{$key}</code> angelegt";
    }

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

    // ----------------------------------------------------------------
    // View neu erstellen mit Prefix
    // View-Definition direkt aus ehrensache_db.sql lesen (wie migrate_1_0_0()),
    // damit role_name auch nach einem Wizard-Update den neuen Gerätetyp
    // 'kiosk' als 'Virtuelle Station' anzeigt statt im ELSE-Zweig 'Gerät'.
    //
    // Extraktion erst prüfen, dann droppen: fehlt die Datei oder passt das
    // Muster nicht, bleibt die bestehende (funktionierende) View unangetastet.
    // ----------------------------------------------------------------
    $sqlFile = __DIR__ . '/../setup/ehrensache_db.sql';
    if (!file_exists($sqlFile)) {
        $warn[] = 'ehrensache_db.sql nicht gefunden – View konnte nicht aus Schema gelesen werden';
    } else {
        $schema = file_get_contents($sqlFile);
        if (preg_match('/(CREATE OR REPLACE VIEW\s+`\{PREFIX\}v_users_extended`.*?;)\s*$/ms', $schema, $m)) {
            $viewSql = str_replace('{PREFIX}', $prefix, $m[1]);
            $pdo->exec("DROP VIEW IF EXISTS `{$prefix}v_users_extended`");
            $pdo->exec($viewSql);
            $log[] = "View <code>{$prefix}v_users_extended</code> aus Schema erstellt";
        } else {
            $warn[] = 'View-Definition nicht in ehrensache_db.sql gefunden – bitte manuell prüfen';
        }
    }

    return ['log' => $log, 'warnings' => $warn];
}
