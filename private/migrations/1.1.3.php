<?php

/**
 * EhrenSache - Migration v1.1.3 → v1.2.0
 *
 * Änderungen:
 * - Neue Tabellen: activity_types, work_sessions, work_session_log
 * - records.checkin_source um den Wert 'timer' erweitert
 * - system_settings: worktime_enabled, worktime_max_session_hours, worktime_require_note
 *
 * Der Versionsstempel wird vom Aufrufer gesetzt (public/update/index.php).
 */

function migrate_1_1_3(PDO $pdo, string $prefix, string $configPath): array
{
    $log  = [];
    $warn = [];

    /** Meldet, ob eine Tabelle bereits existiert — damit das Protokoll die Wahrheit sagt. */
    $exists = static function (string $table) use ($pdo): bool {
        return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->rowCount();
    };

    // ----------------------------------------------------------------
    // Schritt 1: Tätigkeitsarten
    // ----------------------------------------------------------------
    $had_activity_types = $exists($prefix . 'activity_types');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}activity_types` (
            activity_id   INT PRIMARY KEY AUTO_INCREMENT,
            activity_name VARCHAR(100) NOT NULL,
            description   TEXT,
            color         VARCHAR(7) DEFAULT '#1F5FBF',
            is_default    BOOLEAN DEFAULT 0,
            is_active     BOOLEAN DEFAULT 1,
            verification  ENUM('none','start','start_end') NOT NULL DEFAULT 'none',
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = $had_activity_types
        ? "Tabelle <code>{$prefix}activity_types</code> existiert bereits – übersprungen"
        : "Tabelle <code>{$prefix}activity_types</code> angelegt";

    // ----------------------------------------------------------------
    // Schritt 2: Arbeitssitzungen
    // ----------------------------------------------------------------
    $had_work_sessions = $exists($prefix . 'work_sessions');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}work_sessions` (
            session_id          INT PRIMARY KEY AUTO_INCREMENT,
            member_id           INT NOT NULL,
            activity_id         INT NOT NULL,
            appointment_id      INT DEFAULT NULL,
            start_time          DATETIME NOT NULL,
            end_time            DATETIME DEFAULT NULL,
            break_minutes       INT NOT NULL DEFAULT 0,
            break_started_at    DATETIME DEFAULT NULL,
            note                VARCHAR(255) DEFAULT NULL,
            start_location_name VARCHAR(100) DEFAULT NULL,
            end_location_name   VARCHAR(100) DEFAULT NULL,
            status              ENUM('confirmed','submitted','rejected') NOT NULL DEFAULT 'submitted',
            source              ENUM('timer','manual','admin','import') NOT NULL DEFAULT 'manual',
            created_by          INT DEFAULT NULL,
            approved_by         INT DEFAULT NULL,
            approved_at         DATETIME DEFAULT NULL,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            active_member       INT AS (IF(end_time IS NULL, member_id, NULL)) VIRTUAL,
            UNIQUE KEY `{$prefix}uq_running_session` (active_member),
            KEY `{$prefix}idx_ws_member_start` (member_id, start_time),
            KEY `{$prefix}idx_ws_appointment` (appointment_id),
            KEY `{$prefix}idx_ws_activity` (activity_id),
            KEY `{$prefix}idx_ws_status_start` (status, start_time),
            CONSTRAINT `{$prefix}ws_member_fk`   FOREIGN KEY (member_id)      REFERENCES `{$prefix}members`(member_id)           ON DELETE CASCADE,
            CONSTRAINT `{$prefix}ws_activity_fk` FOREIGN KEY (activity_id)    REFERENCES `{$prefix}activity_types`(activity_id)  ON DELETE RESTRICT,
            CONSTRAINT `{$prefix}ws_apt_fk`      FOREIGN KEY (appointment_id) REFERENCES `{$prefix}appointments`(appointment_id) ON DELETE SET NULL,
            CONSTRAINT `{$prefix}ws_creator_fk`  FOREIGN KEY (created_by)     REFERENCES `{$prefix}users`(user_id)               ON DELETE SET NULL,
            CONSTRAINT `{$prefix}ws_approver_fk` FOREIGN KEY (approved_by)    REFERENCES `{$prefix}users`(user_id)               ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = $had_work_sessions
        ? "Tabelle <code>{$prefix}work_sessions</code> existiert bereits – übersprungen"
        : "Tabelle <code>{$prefix}work_sessions</code> angelegt";

    // ----------------------------------------------------------------
    // Schritt 3: Auditspur
    // ----------------------------------------------------------------
    $had_work_session_log = $exists($prefix . 'work_session_log');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}work_session_log` (
            log_id     INT PRIMARY KEY AUTO_INCREMENT,
            session_id INT NOT NULL,
            changed_by INT DEFAULT NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            action     ENUM('create','update','approve','reject','delete') NOT NULL,
            changes    TEXT,
            KEY `{$prefix}idx_wsl_session` (session_id),
            KEY `{$prefix}idx_wsl_changed` (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $log[] = $had_work_session_log
        ? "Tabelle <code>{$prefix}work_session_log</code> existiert bereits – übersprungen"
        : "Tabelle <code>{$prefix}work_session_log</code> angelegt";

    // ----------------------------------------------------------------
    // Schritt 4: records.checkin_source um 'timer' erweitern
    // ----------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'checkin_source'
    ");
    $stmt->execute([$prefix . 'records']);
    $columnType = (string) $stmt->fetchColumn();

    if ($columnType === '') {
        $warn[] = "Spalte <code>checkin_source</code> in <code>{$prefix}records</code> nicht gefunden";
    } elseif (strpos($columnType, 'timer') !== false) {
        $log[] = "<code>checkin_source</code> kennt <code>timer</code> bereits – übersprungen";
    } else {
        $pdo->exec("
            ALTER TABLE `{$prefix}records`
            MODIFY `checkin_source`
            ENUM('admin','user_totp','device_auth','auto_checkin','import','timer') DEFAULT 'admin'
        ");
        $log[] = "<code>checkin_source</code> um <code>timer</code> erweitert";
    }

    // ----------------------------------------------------------------
    // Schritt 5: Systemeinstellungen
    // ----------------------------------------------------------------
    $settings = [
        ['worktime_enabled', '0', 'boolean', 'general', 'Zeiterfassung aktiviert'],
        ['worktime_max_session_hours', '12', 'number', 'general', 'Obergrenze in Stunden, ab der eine laufende Sitzung automatisch beendet wird'],
        ['worktime_require_note', '0', 'boolean', 'general', 'Notiz beim Stoppen und bei manuellen Einträgen erzwingen'],
    ];
    $insert = $pdo->prepare("
        INSERT IGNORE INTO `{$prefix}system_settings`
        (`setting_key`, `setting_value`, `setting_type`, `category`, `description`)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($settings as $s) {
        $insert->execute($s);
    }
    $log[] = "Systemeinstellungen der Zeiterfassung ergänzt (Feature ist standardmäßig <strong>aus</strong>)";

    return ['log' => $log, 'warnings' => $warn];
}
