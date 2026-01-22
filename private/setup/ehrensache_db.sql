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

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `exceptions`
--

CREATE TABLE `exceptions` (
  `exception_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `exception_type` enum('absence','time_correction') NOT NULL,
  `reason` text NOT NULL,
  `requested_arrival_time` datetime DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `members`
--

CREATE TABLE `members` (
  `member_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `surname` varchar(100) NOT NULL,
  `member_number` varchar(50) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `membership_dates`
--

CREATE TABLE `membership_dates` (
  `membership_date_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `records`
--

CREATE TABLE `records` (
  `record_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `arrival_time` datetime NOT NULL,
  `status` enum('present','excused') DEFAULT 'present',
  `checkin_source` enum('admin','user_totp','device_auth','auto_checkin','import') DEFAULT 'admin',
  `source_device` varchar(100) DEFAULT NULL,
  `location_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


ALTER TABLE users 
    ADD CONSTRAINT check_device_email 
    CHECK (
        (role = 'device' AND email IS NULL) OR 
        (role != 'device' AND email IS NOT NULL)
    );

--
-- Indizes für die Tabelle `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_date` (`date`);

--
-- Indizes für die Tabelle `exceptions`
--
ALTER TABLE `exceptions`
  ADD PRIMARY KEY (`exception_id`),
  ADD KEY `member_id` (`member_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `idx_status` (`status`);

--
-- Indizes für die Tabelle `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`member_id`);

--
-- Indizes für die Tabelle `membership_dates`
--
ALTER TABLE `membership_dates`
  ADD PRIMARY KEY (`membership_date_id`),
  ADD KEY `idx_member_dates` (`member_id`,`start_date`);

--
-- Indizes für die Tabelle `records`
--
ALTER TABLE `records`
  ADD PRIMARY KEY (`record_id`),
  ADD UNIQUE KEY `unique_member_appointment` (`member_id`,`appointment_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `idx_arrival` (`arrival_time`);

--
-- Indizes für die Tabelle `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `api_token` (`api_token`),
  ADD KEY `member_id` (`member_id`),
  ADD FOREIGN KEY (`pending_member_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL;

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `exceptions`
--
ALTER TABLE `exceptions`
  MODIFY `exception_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `members`
--
ALTER TABLE `members`
  MODIFY `member_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `membership_dates`
--
ALTER TABLE `membership_dates`
  MODIFY `membership_date_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `records`
--
ALTER TABLE `records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints der Tabelle `exceptions`
--
ALTER TABLE `exceptions`
  ADD CONSTRAINT `exceptions_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exceptions_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exceptions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exceptions_ibfk_4` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints der Tabelle `membership_dates`
--
ALTER TABLE `membership_dates`
  ADD CONSTRAINT `membership_dates_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `records`
--
ALTER TABLE `records`
  ADD CONSTRAINT `records_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `records_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE;

--
-- Constraints der Tabelle `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_member_id1` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL;

-- Neue Tabelle: Benutzergruppen
CREATE TABLE member_groups (
  group_id INT PRIMARY KEY AUTO_INCREMENT,
  group_name VARCHAR(100) NOT NULL,
  description TEXT,
  is_default BOOLEAN DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- M:N-Tabelle: Member <-> Groups
CREATE TABLE member_group_assignments (
  member_id INT NOT NULL,
  group_id INT NOT NULL,
  PRIMARY KEY (member_id, group_id),
  FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES member_groups(group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Neue Tabelle: Terminarten
CREATE TABLE appointment_types (
  type_id INT PRIMARY KEY AUTO_INCREMENT,
  type_name VARCHAR(100) NOT NULL,
  description TEXT,
  is_default BOOLEAN DEFAULT 0,
  color VARCHAR(7) DEFAULT '#667eea',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- M:N-Tabelle: Terminarten <-> Gruppen (welche Gruppen sind betroffen)
CREATE TABLE appointment_type_groups (
  type_id INT NOT NULL,
  group_id INT NOT NULL,
  PRIMARY KEY (type_id, group_id),
  FOREIGN KEY (type_id) REFERENCES appointment_types(type_id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES member_groups(group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Appointments erweitern
ALTER TABLE appointments 
ADD COLUMN type_id INT DEFAULT NULL AFTER title,
ADD FOREIGN KEY (type_id) REFERENCES appointment_types(type_id) ON DELETE SET NULL;

ALTER TABLE records ADD INDEX idx_member_year (member_id, arrival_time);
ALTER TABLE records ADD INDEX idx_year (arrival_time);
ALTER TABLE appointments ADD INDEX idx_year (date);
ALTER TABLE member_group_assignments ADD INDEX idx_member (member_id);
ALTER TABLE member_group_assignments ADD INDEX idx_group (group_id);

-- Rate Limiting
CREATE TABLE rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(64) NOT NULL,
    action VARCHAR(50) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_action (identifier, action),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email-Verifikation für neue Registrierungen
CREATE TABLE email_verification_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Passwort-Reset Tokens
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(50) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `setting_type` ENUM('text', 'number', 'color', 'boolean') DEFAULT 'text',
  `category` ENUM('general', 'appearance', 'pagination', 'security') DEFAULT 'general',
  `description` VARCHAR(255),
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT,
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard-Werte einfügen
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `category`, `description`) VALUES
('organization_name', 'EhrenSache', 'text', 'general', 'Name der Organisation'),
('primary_color', '#1F5FBF', 'color', 'appearance', 'Primärfarbe'),
('secondary_color', '#4CAF50', 'color', 'appearance', 'Sekundärfarbe'),
('background_color', '#f8f9fa', 'color', 'appearance', 'Hintergrundfarbe'), 
('pagination_limit', '25', 'number', 'pagination', 'Einträge pro Seite');

-- Mail-Settings in system_settings
INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description) VALUES
('mail_enabled', '0', 'boolean', 'general', 'E-Mail-Versand aktiviert'),
('mail_from_email', 'noreply@ehrenzeit.de', 'text', 'general', 'Absender E-Mail-Adresse'),
('mail_from_name', 'EhrenSache System', 'text', 'general', 'Absender Name'),
('mail_registration_enabled', '1', 'boolean', 'general', 'Registrierungs-Mails senden'),
('mail_password_reset_enabled', '1', 'boolean', 'general', 'Passwort-Reset-Mails senden'),
('mail_activation_enabled', '1', 'boolean', 'general', 'Aktivierungs-Mails senden'),
('smtp_configured', '0', 'boolean', 'general', 'SMTP-Server konfiguriert');


CREATE OR REPLACE VIEW v_users_extended AS
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
    
FROM users u
LEFT JOIN members m_active ON u.member_id = m_active.member_id
LEFT JOIN members m_pending ON u.pending_member_id = m_pending.member_id;

/*
-- Trigger für INSERT
CREATE TRIGGER check_device_email_insert
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    -- Geräte dürfen KEINE Email haben
    IF NEW.role = 'device' AND NEW.email IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Geräte dürfen keine Email-Adresse haben';
    END IF;
    
    -- Normale User MÜSSEN Email haben
    IF NEW.role != 'device' AND NEW.email IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Benutzer benötigen eine Email-Adresse';
    END IF;
END;

-- Trigger für UPDATE
CREATE TRIGGER check_device_email_update
BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    -- Geräte dürfen KEINE Email haben
    IF NEW.role = 'device' AND NEW.email IS NOT NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Geräte dürfen keine Email-Adresse haben';
    END IF;
    
    -- Normale User MÜSSEN Email haben
    IF NEW.role != 'device' AND NEW.email IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Benutzer benötigen eine Email-Adresse';
    END IF;
END;
*/

/* Beispieldaten*/
/*
-- Initiale Daten
INSERT INTO member_groups (group_name, description, is_default) VALUES
('Alle Mitglieder', 'Standard-Gruppe für alle', 1),
('Vorstandschaft', 'Vorstandsmitglieder', 0);

INSERT INTO appointment_types (type_name, description, is_default, color) VALUES
('Probe', 'Reguläre Probe', 1, '#27ae60'),
('Vorstandschaftssitzung', 'Nur Vorstand', 0, '#3498db'),
('Sonstiges', 'Sonstige Termine', 0, '#95a5a6');

-- Verknüpfe Standard-Terminart mit "Alle Mitglieder"
INSERT INTO appointment_type_groups (type_id, group_id)
SELECT 1, 1; -- Probe -> Alle

-- Verknüpfe Vorstandssitzung mit "Vorstandschaft"
INSERT INTO appointment_type_groups (type_id, group_id)
SELECT 2, 2; -- Vorstandschaftssitzung -> Vorstandschaft
*/
