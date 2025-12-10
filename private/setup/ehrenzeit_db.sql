-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Erstellungszeit: 03. Dez 2025 um 21:30
-- Server-Version: 10.4.32-MariaDB
-- PHP-Version: 8.2.12

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
  `checkin_source` enum('admin','user_totp','device_auth','auto_checkin') DEFAULT 'admin',
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
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','user','device') DEFAULT 'user',
  `device_type` enum('totp_location','auth_device') DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `member_id` int(11) DEFAULT NULL,
  `api_token` varchar(64) DEFAULT NULL,
  `api_token_expires_at` datetime DEFAULT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indizes der exportierten Tabellen
--

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
  ADD KEY `member_id` (`member_id`);

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
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL;

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

/* Beispieldaten*/
/*
ALTER TABLE users 
ADD COLUMN totp_secret varchar(64) DEFAULT NULL,

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
