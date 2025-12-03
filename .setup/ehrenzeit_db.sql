-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Erstellungszeit: 03. Dez 2025 um 21:30
-- Server-Version: 10.4.32-MariaDB
-- PHP-Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Datenbank: `ehrenzeit_db`
--
CREATE DATABASE IF NOT EXISTS `ehrenzeit_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `ehrenzeit_db`;

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
-- Tabellenstruktur für Tabelle `device_configs`
--

CREATE TABLE `device_configs` (
  `device_config_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `device_type` enum('qr_location','rfid','nfc','fingerprint','keypad') NOT NULL,
  `device_name` varchar(100) NOT NULL,
  `location_secret` varchar(64) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
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
  `status` enum('active','expired','pending') DEFAULT 'active',
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
  `status` enum('present','absent','excused') DEFAULT 'present',
  `checkin_source` enum('admin','user_token','device_token','qr_verified','auto_checkin') DEFAULT 'admin',
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
  `is_active` tinyint(1) DEFAULT 1,
  `member_id` int(11) DEFAULT NULL,
  `api_token` varchar(64) DEFAULT NULL,
  `api_token_expires_at` datetime DEFAULT NULL,
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
-- Indizes für die Tabelle `device_configs`
--
ALTER TABLE `device_configs`
  ADD PRIMARY KEY (`device_config_id`),
  ADD KEY `idx_user_device` (`user_id`,`device_type`);

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
-- AUTO_INCREMENT für Tabelle `device_configs`
--
ALTER TABLE `device_configs`
  MODIFY `device_config_id` int(11) NOT NULL AUTO_INCREMENT;

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
-- Constraints der Tabelle `device_configs`
--
ALTER TABLE `device_configs`
  ADD CONSTRAINT `device_configs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

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
COMMIT;
