/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `appointments`
--

CREATE TABLE IF NOT EXISTS `{PREFIX}appointments` (
  `appointment_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`appointment_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `exceptions`
--

CREATE TABLE IF NOT EXISTS `{PREFIX}exceptions` (
  `exception_id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `exception_type` enum('absence','time_correction') NOT NULL,
  `reason` text NOT NULL,
  `requested_arrival_time` datetime DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`exception_id`),
  KEY `member_id` (`member_id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `members`
--

CREATE TABLE IF NOT EXISTS `{PREFIX}members` (
  `member_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `surname` varchar(100) NOT NULL,
  `member_number` varchar(50) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `membership_dates`
--

CREATE TABLE IF NOT EXISTS `{PREFIX}membership_dates` (
  `membership_date_id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`membership_date_id`),
  KEY `idx_member_dates` (`member_id`,`start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `records`
--

CREATE TABLE IF NOT EXISTS `{PREFIX}records` (
  `record_id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `arrival_time` datetime NOT NULL,
  `status` enum('present','excused') DEFAULT 'present',
  `checkin_source` enum('admin','user_totp','device_auth','auto_checkin','import') DEFAULT 'admin',
  `source_device` varchar(100) DEFAULT NULL,
  `location_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`record_id`),
  UNIQUE KEY `unique_member_appointment` (`member_id`,`appointment_id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `idx_arrival` (`arrival_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `users`
--

CREATE TABLE IF NOT EXISTS `{PREFIX}users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NULL,
  `name` varchar(100) NULL,
  `device_name` varchar(100) NULL,
  `email_verified` TINYINT(1) DEFAULT 0,
  `account_status` enum('pending', 'active', 'suspended') DEFAULT 'pending',
  `password_hash` varchar(255) NULL,
  `role` enum('admin','manager','user','device') DEFAULT 'user',
  `device_type` enum('totp_location','auth_device') DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `member_id` int(11) DEFAULT NULL,
  `pending_member_id` INT(11) DEFAULT NULL,
  `api_token` varchar(64) DEFAULT NULL,
  `api_token_expires_at` datetime DEFAULT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `api_token` (`api_token`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Check Constraint nur hinzufügen wenn nicht vorhanden
SET @check_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = '{PREFIX}users'
    AND CONSTRAINT_NAME = '{PREFIX}check_device_email');

SET @sql = IF(@check_exists = 0,
    'ALTER TABLE `{PREFIX}users` ADD CONSTRAINT {PREFIX}check_device_email CHECK ((role = ''device'' AND email IS NULL) OR (role != ''device'' AND email IS NOT NULL))',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Indizes für die Tabelle `users` bereits in CREATE TABLE definiert
-- Nur pending_member_id FK muss separat hinzugefügt werden (wegen Reihenfolge)
--
SET @sql = 'ALTER TABLE `{PREFIX}users` ADD CONSTRAINT `{PREFIX}users_ibfk_pending` FOREIGN KEY (`pending_member_id`) REFERENCES `{PREFIX}members` (`member_id`) ON DELETE SET NULL';
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}users' AND CONSTRAINT_NAME = '{PREFIX}users_ibfk_pending');
PREPARE stmt FROM IF(@exists = 0, @sql, 'SELECT 1');
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

--
-- Constraints der Tabelle `appointments`
-- (Constraint wird nur hinzugefügt wenn nicht vorhanden, sonst stillschweigend übersprungen)
--
SET @sql = 'ALTER TABLE `{PREFIX}appointments` ADD CONSTRAINT `{PREFIX}appointments_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `{PREFIX}users` (`user_id`) ON DELETE SET NULL';
SET @constraint_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = '{PREFIX}appointments'
    AND CONSTRAINT_NAME = '{PREFIX}appointments_ibfk_1');
PREPARE stmt FROM IF(@constraint_exists = 0, @sql, 'SELECT 1');
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

--
-- Constraints der Tabelle `exceptions`
--
SET @sql1 = 'ALTER TABLE `{PREFIX}exceptions` ADD CONSTRAINT `{PREFIX}exceptions_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `{PREFIX}members` (`member_id`) ON DELETE CASCADE';
SET @exists1 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}exceptions' AND CONSTRAINT_NAME = '{PREFIX}exceptions_ibfk_1');
PREPARE stmt1 FROM IF(@exists1 = 0, @sql1, 'SELECT 1');
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @sql2 = 'ALTER TABLE `{PREFIX}exceptions` ADD CONSTRAINT `{PREFIX}exceptions_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `{PREFIX}appointments` (`appointment_id`) ON DELETE CASCADE';
SET @exists2 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}exceptions' AND CONSTRAINT_NAME = '{PREFIX}exceptions_ibfk_2');
PREPARE stmt2 FROM IF(@exists2 = 0, @sql2, 'SELECT 1');
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

SET @sql3 = 'ALTER TABLE `{PREFIX}exceptions` ADD CONSTRAINT `{PREFIX}exceptions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `{PREFIX}users` (`user_id`) ON DELETE CASCADE';
SET @exists3 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}exceptions' AND CONSTRAINT_NAME = '{PREFIX}exceptions_ibfk_3');
PREPARE stmt3 FROM IF(@exists3 = 0, @sql3, 'SELECT 1');
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

SET @sql4 = 'ALTER TABLE `{PREFIX}exceptions` ADD CONSTRAINT `{PREFIX}exceptions_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `{PREFIX}users` (`user_id`) ON DELETE SET NULL';
SET @exists4 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}exceptions' AND CONSTRAINT_NAME = '{PREFIX}exceptions_ibfk_4');
PREPARE stmt4 FROM IF(@exists4 = 0, @sql4, 'SELECT 1');
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

--
-- Constraints der Tabelle `membership_dates`
--
SET @sql = 'ALTER TABLE `{PREFIX}membership_dates` ADD CONSTRAINT `{PREFIX}membership_dates_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `{PREFIX}members` (`member_id`) ON DELETE CASCADE';
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}membership_dates' AND CONSTRAINT_NAME = '{PREFIX}membership_dates_ibfk_1');
PREPARE stmt FROM IF(@exists = 0, @sql, 'SELECT 1');
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

--
-- Constraints der Tabelle `records`
--
SET @sql1 = 'ALTER TABLE `{PREFIX}records` ADD CONSTRAINT `{PREFIX}records_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `{PREFIX}members` (`member_id`) ON DELETE CASCADE';
SET @exists1 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}records' AND CONSTRAINT_NAME = '{PREFIX}records_ibfk_1');
PREPARE stmt1 FROM IF(@exists1 = 0, @sql1, 'SELECT 1');
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @sql2 = 'ALTER TABLE `{PREFIX}records` ADD CONSTRAINT `{PREFIX}records_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `{PREFIX}appointments` (`appointment_id`) ON DELETE CASCADE';
SET @exists2 = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}records' AND CONSTRAINT_NAME = '{PREFIX}records_ibfk_2');
PREPARE stmt2 FROM IF(@exists2 = 0, @sql2, 'SELECT 1');
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

--
-- Constraints der Tabelle `users`
--
SET @sql = 'ALTER TABLE `{PREFIX}users` ADD CONSTRAINT `{PREFIX}users_ibfk_member_id1` FOREIGN KEY (`member_id`) REFERENCES `{PREFIX}members` (`member_id`) ON DELETE SET NULL';
SET @exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}users' AND CONSTRAINT_NAME = '{PREFIX}users_ibfk_member_id1');
PREPARE stmt FROM IF(@exists = 0, @sql, 'SELECT 1');
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Neue Tabelle: Benutzergruppen
CREATE TABLE  IF NOT EXISTS `{PREFIX}member_groups` (
  group_id INT PRIMARY KEY AUTO_INCREMENT,
  group_name VARCHAR(100) NOT NULL,
  description TEXT,
  is_default BOOLEAN DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- M:N-Tabelle: Member <-> Groups
CREATE TABLE IF NOT EXISTS `{PREFIX}member_group_assignments` (
  member_id INT NOT NULL,
  group_id INT NOT NULL,
  PRIMARY KEY (member_id, group_id),
  FOREIGN KEY (member_id) REFERENCES `{PREFIX}members`(member_id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES `{PREFIX}member_groups`(group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Neue Tabelle: Terminarten
CREATE TABLE IF NOT EXISTS `{PREFIX}appointment_types` (
  type_id INT PRIMARY KEY AUTO_INCREMENT,
  type_name VARCHAR(100) NOT NULL,
  description TEXT,
  is_default BOOLEAN DEFAULT 0,
  color VARCHAR(7) DEFAULT '#667eea',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- M:N-Tabelle: Terminarten <-> Gruppen (welche Gruppen sind betroffen)
CREATE TABLE IF NOT EXISTS `{PREFIX}appointment_type_groups` (
  type_id INT NOT NULL,
  group_id INT NOT NULL,
  PRIMARY KEY (type_id, group_id),
  FOREIGN KEY (type_id) REFERENCES `{PREFIX}appointment_types`(type_id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES `{PREFIX}member_groups`(group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Appointments erweitern
-- Spalte type_id nur hinzufügen wenn nicht vorhanden
SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = '{PREFIX}appointments' 
    AND COLUMN_NAME = 'type_id');

SET @sql_column = IF(@column_exists = 0,
    'ALTER TABLE `{PREFIX}appointments` ADD COLUMN type_id INT DEFAULT NULL AFTER title',
    'SELECT 1');
PREPARE stmt FROM @sql_column;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Foreign Key nur hinzufügen wenn nicht vorhanden
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = '{PREFIX}appointments'
    AND CONSTRAINT_NAME = '{PREFIX}appointments_type_fk');

SET @sql_fk = IF(@fk_exists = 0,
    'ALTER TABLE `{PREFIX}appointments` ADD CONSTRAINT `{PREFIX}appointments_type_fk` FOREIGN KEY (type_id) REFERENCES `{PREFIX}appointment_types`(type_id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Zusätzliche Indizes nur hinzufügen wenn nicht vorhanden
SET @idx1 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}records' AND INDEX_NAME = '{PREFIX}idx_member_year');
PREPARE stmt FROM IF(@idx1 = 0, 'ALTER TABLE `{PREFIX}records` ADD INDEX {PREFIX}idx_member_year (member_id, arrival_time)', 'SELECT 1');
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx2 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}records' AND INDEX_NAME = '{PREFIX}idx_year');
PREPARE stmt FROM IF(@idx2 = 0, 'ALTER TABLE `{PREFIX}records` ADD INDEX {PREFIX}idx_year (arrival_time)', 'SELECT 1');
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx3 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}appointments' AND INDEX_NAME = '{PREFIX}idx_year');
PREPARE stmt FROM IF(@idx3 = 0, 'ALTER TABLE `{PREFIX}appointments` ADD INDEX {PREFIX}idx_year (date)', 'SELECT 1');
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx4 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}member_group_assignments' AND INDEX_NAME = '{PREFIX}idx_member');
PREPARE stmt FROM IF(@idx4 = 0, 'ALTER TABLE `{PREFIX}member_group_assignments` ADD INDEX {PREFIX}idx_member (member_id)', 'SELECT 1');
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx5 = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}member_group_assignments' AND INDEX_NAME = '{PREFIX}idx_group');
PREPARE stmt FROM IF(@idx5 = 0, 'ALTER TABLE `{PREFIX}member_group_assignments` ADD INDEX {PREFIX}idx_group (group_id)', 'SELECT 1');
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Rate Limiting
CREATE TABLE IF NOT EXISTS `{PREFIX}rate_limits` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(64) NOT NULL,
    action VARCHAR(50) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_action (identifier, action),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email-Verifikation für neue Registrierungen
CREATE TABLE IF NOT EXISTS `{PREFIX}email_verification_tokens` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES `{PREFIX}users`(user_id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Passwort-Reset Tokens
CREATE TABLE IF NOT EXISTS `{PREFIX}password_reset_tokens` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES `{PREFIX}users`(user_id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{PREFIX}system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(50) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `setting_type` ENUM('text', 'number', 'color', 'boolean') DEFAULT 'text',
  `category` ENUM('general', 'public', 'pagination', 'security') DEFAULT 'general',
  `description` VARCHAR(255),
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT,
  FOREIGN KEY (`updated_by`) REFERENCES `{PREFIX}users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}import_logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `import_type` ENUM('members', 'records', 'appointments') NOT NULL,
  `filename` VARCHAR(255),
  `total_rows` INT DEFAULT 0,
  `successful_rows` INT DEFAULT 0,
  `failed_rows` INT DEFAULT 0,
  `errors` TEXT,  -- JSON mit Fehlerdetails
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `{PREFIX}users`(`user_id`) ON DELETE CASCADE,
  INDEX idx_user_date (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Foreign Key
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = '{PREFIX}import_logs'
    AND CONSTRAINT_NAME = '{PREFIX}import_logs_user_fk');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `{PREFIX}import_logs` ADD CONSTRAINT `{PREFIX}import_logs_user_fk` FOREIGN KEY (`user_id`) REFERENCES `{PREFIX}users`(`user_id`) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Standard-Werte einfügen (nur wenn noch nicht vorhanden)
INSERT IGNORE INTO `{PREFIX}system_settings` (`setting_key`, `setting_value`, `setting_type`, `category`, `description`) VALUES
('organization_name', 'EhrenSache', 'text', 'public', 'Name der Organisation'),
('privacy_policy_url', '', 'text', 'public', 'Link zur Datenschutzerklärung'),
('primary_color', '#1F5FBF', 'color', 'public', 'Primärfarbe'),
('secondary_color', '#4CAF50', 'color', 'public', 'Sekundärfarbe'),
('background_color', '#f8f9fa', 'color', 'public', 'Hintergrundfarbe'), 
('organization_logo', 'assets/logo-default.png', 'text', 'public', 'Logo-Pfad (relativ zu /public/)'),
('pagination_limit', '25', 'number', 'pagination', 'Einträge pro Seite'),
('mail_enabled', '0', 'boolean', 'general', 'E-Mail-Versand aktiviert'),
('mail_from_email', 'noreply@ehrensache.de', 'text', 'general', 'Absender E-Mail-Adresse'),
('mail_from_name', 'EhrenSache System', 'text', 'general', 'Absender Name'),
('mail_registration_enabled', '1', 'boolean', 'general', 'Registrierungs-Mails senden'),
('mail_password_reset_enabled', '1', 'boolean', 'general', 'Passwort-Reset-Mails senden'),
('mail_activation_enabled', '1', 'boolean', 'general', 'Aktivierungs-Mails senden'),
('smtp_configured', '0', 'boolean', 'general', 'SMTP-Server konfiguriert');


CREATE OR REPLACE VIEW `{PREFIX}v_users_extended` AS
SELECT 
    u.user_id,
    u.email,
    u.name AS user_name,
    u.device_name,
    u.email_verified,
    u.account_status,
    u.role,
    u.device_type,
    u.is_active,
    u.member_id,
    u.pending_member_id,
    u.created_at,
    u.api_token,
    u.api_token_expires_at,
    
    -- Display Name (User-Name oder Geräte-Name)
    COALESCE(u.name, u.device_name, 'Unbenannt') AS display_name,
    
    -- Aktiver Member
    m_active.member_number,
    m_active.name AS member_name,
    m_active.surname AS member_surname,
    
    -- Pending Member
    m_pending.member_number AS pending_member_number,
    m_pending.name AS pending_member_name,
    m_pending.surname AS pending_member_surname,
    
    -- Status-Text
    CASE 
        -- GERÄTE
        WHEN u.role = 'device' AND u.is_active = 1 THEN 
            CONCAT('🔧 Gerät aktiv (', COALESCE(u.device_name, 'Unbenannt'), ')')
        WHEN u.role = 'device' AND u.is_active = 0 THEN 
            '🔧 Gerät deaktiviert'
            
        -- NORMALE USER
        WHEN u.role != 'device' AND u.account_status = 'pending' AND u.email_verified = 0 THEN 
            '📧 Email-Bestätigung ausstehend'
        WHEN u.role != 'device' AND u.account_status = 'pending' AND u.email_verified = 1 AND u.pending_member_id IS NULL THEN 
            '⏳ Wartet auf Member-Verknüpfung'
        WHEN u.role != 'device' AND u.account_status = 'pending' AND u.email_verified = 1 AND u.pending_member_id IS NOT NULL THEN 
            CONCAT('⏳ Bereit zur Aktivierung (→ ', m_pending.member_number, ')')
        WHEN u.role != 'device' AND u.account_status = 'active' AND u.member_id IS NOT NULL THEN 
            CONCAT('✓ Aktiv (', m_active.member_number, ')')
        WHEN u.role != 'device' AND u.account_status = 'active' AND u.member_id IS NULL THEN 
            '✓ Aktiv (kein Member)'
        WHEN u.role != 'device' AND u.account_status = 'suspended' THEN 
            '🚫 Gesperrt'
        ELSE 'Unbekannt'
    END AS status_text,
    
    -- Role-Name
    CASE u.role
        WHEN 'admin' THEN 'Administrator'
        WHEN 'manager' THEN 'Manager'
        WHEN 'user' THEN 'Benutzer'
        WHEN 'device' THEN CASE u.device_type
            WHEN 'totp_location' THEN 'TOTP-Station'
            WHEN 'auth_device' THEN 'Auth-Gerät'
            ELSE 'Gerät'
        END
        ELSE 'Unbekannt'
    END AS role_name
    
FROM `{PREFIX}users` u
LEFT JOIN `{PREFIX}members` m_active ON u.member_id = m_active.member_id
LEFT JOIN `{PREFIX}members` m_pending ON u.pending_member_id = m_pending.member_id;

