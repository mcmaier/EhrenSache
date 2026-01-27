/**
 * EhrenSache Demo Reset Script
 * 
 * Dieses Skript setzt die Datenbank auf Demo-Zustand zurück:
 * - Löscht alle Daten (TRUNCATE statt DROP)
 * - Füllt mit realistischen Demo-Daten
 * - Erstellt Demo-User mit bekannten Credentials
 * 
 * ACHTUNG: Alle bestehenden Daten werden gelöscht!
 */

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1. ALLE DATEN LÖSCHEN (Tabellen behalten)
-- ============================================

TRUNCATE TABLE password_reset_tokens;
TRUNCATE TABLE email_verification_tokens;
TRUNCATE TABLE rate_limits;
TRUNCATE TABLE appointment_type_groups;
TRUNCATE TABLE appointment_types;
TRUNCATE TABLE member_group_assignments;
TRUNCATE TABLE member_groups;
TRUNCATE TABLE records;
TRUNCATE TABLE exceptions;
TRUNCATE TABLE appointments;
TRUNCATE TABLE membership_dates;
TRUNCATE TABLE members;
TRUNCATE TABLE users;

-- System Settings zurücksetzen (nur Werte updaten, nicht löschen)
UPDATE system_settings SET 
    setting_value = 'Demo Mein Verein',
    updated_by = NULL,
    updated_at = CURRENT_TIMESTAMP
WHERE setting_key = 'organization_name';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- 2. DEMO-DATEN EINFÜGEN
-- ============================================

-- MITGLIEDER (20 Personen für realistischen Demo)
-- ============================================
INSERT INTO members (member_id, name, surname, member_number, active, created_at) VALUES
(1, 'Max', 'Mustermann', 'MV-001', 1, '2024-01-15 10:00:00'),
(2, 'Anna', 'Schmidt', 'MV-002', 1, '2024-01-15 10:05:00'),
(3, 'Peter', 'Müller', 'MV-003', 1, '2024-01-15 10:10:00'),
(4, 'Laura', 'Weber', 'MV-004', 1, '2024-01-20 14:00:00'),
(5, 'Thomas', 'Wagner', 'MV-005', 1, '2024-01-20 14:05:00'),
(6, 'Julia', 'Fischer', 'MV-006', 1, '2024-02-01 09:00:00'),
(7, 'Michael', 'Becker', 'MV-007', 1, '2024-02-01 09:05:00'),
(8, 'Sarah', 'Hoffmann', 'MV-008', 1, '2024-02-10 11:00:00'),
(9, 'Daniel', 'Schulz', 'MV-009', 1, '2024-02-10 11:05:00'),
(10, 'Lisa', 'Koch', 'MV-010', 1, '2024-02-15 15:00:00'),
(11, 'Stefan', 'Bauer', 'MV-011', 1, '2024-03-01 10:00:00'),
(12, 'Katharina', 'Wolf', 'MV-012', 1, '2024-03-01 10:05:00'),
(13, 'Christian', 'Schröder', 'MV-013', 1, '2024-03-05 14:00:00'),
(14, 'Nina', 'Neumann', 'MV-014', 1, '2024-03-05 14:05:00'),
(15, 'Andreas', 'Schwarz', 'MV-015', 1, '2024-03-10 09:00:00'),
(16, 'Jennifer', 'Zimmermann', 'MV-016', 1, '2024-03-10 09:05:00'),
(17, 'Markus', 'Braun', 'MV-017', 1, '2024-03-15 11:00:00'),
(18, 'Sandra', 'Krüger', 'MV-018', 1, '2024-03-15 11:05:00'),
(19, 'Oliver', 'Hofmann', 'MV-019', 1, '2024-03-20 13:00:00'),
(20, 'Claudia', 'Hartmann', 'MV-020', 1, '2024-03-20 13:05:00');

-- MEMBERSHIP DATES (alle aktiv)
-- ============================================
INSERT INTO membership_dates (member_id, start_date, end_date, status) VALUES
(1, '2024-01-01', NULL, 'active'),
(2, '2024-01-01', NULL, 'active'),
(3, '2024-01-01', NULL, 'active'),
(4, '2024-01-15', NULL, 'active'),
(5, '2024-01-15', NULL, 'active'),
(6, '2024-02-01', NULL, 'active'),
(7, '2024-02-01', NULL, 'active'),
(8, '2024-02-10', NULL, 'active'),
(9, '2024-02-10', NULL, 'active'),
(10, '2024-02-15', NULL, 'active'),
(11, '2024-03-01', NULL, 'active'),
(12, '2024-03-01', NULL, 'active'),
(13, '2024-03-05', NULL, 'active'),
(14, '2024-03-05', NULL, 'active'),
(15, '2024-03-10', NULL, 'active'),
(16, '2024-03-10', NULL, 'active'),
(17, '2024-03-15', NULL, 'active'),
(18, '2024-03-15', NULL, 'active'),
(19, '2024-03-20', NULL, 'active'),
(20, '2024-03-20', NULL, 'active');

-- USERS (Demo-Accounts mit bekannten Passwörtern)
-- ============================================
-- Passwort für alle: "demo2025" (bcrypt Hash)
-- Hash generiert mit: password_hash('demo2025', PASSWORD_DEFAULT)
INSERT INTO users (user_id, email, name, password_hash, role, is_active, member_id, email_verified, account_status, api_token, api_token_expires_at, created_at) VALUES
(1, 'admin@demo.ehrensache.app', 'Demo Admin', '$2y$10$k4coR6fk9L1pEpLrg/VKr.9OkR8t1mRwx8nvjiaVuXA0DhFh0.mg.', 'admin', 1, 1, 1, 'active', 'qVQM39vQDou6Jxtd',DATE_ADD(CURDATE(), INTERVAL 7 DAY),'2024-01-15 10:00:00'),
(2, 'manager@demo.ehrensache.app', 'Demo Manager', '$2y$10$k4coR6fk9L1pEpLrg/VKr.9OkR8t1mRwx8nvjiaVuXA0DhFh0.mg.', 'manager', 1, 2, 1, 'active', 'E8v1tz0duJXBgk7w',DATE_ADD(CURDATE(), INTERVAL 7 DAY),'2024-01-15 10:05:00'),
(3, 'user@demo.ehrensache.app', 'Demo User', '$2y$10$k4coR6fk9L1pEpLrg/VKr.9OkR8t1mRwx8nvjiaVuXA0DhFh0.mg.', 'user', 1, 3, 1, 'active','PPgPQIFlA0R7xkdX' ,DATE_ADD(CURDATE(), INTERVAL 7 DAY),'2024-01-15 10:10:00');

INSERT INTO users(user_id, device_name, role, device_type, is_active, totp_secret, created_at) VALUES
(4, 'CheckIn Station Eingang', 'device', 'totp_location', 1, 'ABCDEFGH34567','2024-01-15 10:10:00');

-- GRUPPEN
-- ============================================
INSERT INTO member_groups (group_id, group_name, description, is_default, created_at) VALUES
(1, 'Alle Mitglieder', 'Standard-Gruppe für alle aktiven Mitglieder', 1, '2024-01-15 09:00:00'),
(2, 'Vorstandschaft', 'Vereinsvorstand und Funktionsträger', 0, '2024-01-15 09:05:00'),
(3, 'Jugendgruppe', 'Jugendliche bis 18 Jahre', 0, '2024-01-15 09:10:00');

-- GRUPPENZUORDNUNGEN
-- ============================================
-- Alle in "Alle Mitglieder"
INSERT INTO member_group_assignments (member_id, group_id)
SELECT member_id, 1 FROM members WHERE active = 1;

-- Vorstandschaft (Members 1-5)
INSERT INTO member_group_assignments (member_id, group_id) VALUES
(1, 2), (2, 2), (3, 2), (4, 2), (5, 2);

-- Jugendgruppe (Members 16-20)
INSERT INTO member_group_assignments (member_id, group_id) VALUES
(16, 3), (17, 3), (18, 3), (19, 3), (20, 3);


-- TERMINARTEN
-- ============================================
INSERT INTO appointment_types (type_id, type_name, description, is_default, color, created_at) VALUES
(1, 'Probe', 'Reguläre Vereinsprobe', 1, '#27ae60', '2024-01-15 09:00:00'),
(2, 'Vorstandssitzung', 'Sitzung der Vorstandschaft', 0, '#3498db', '2024-01-15 09:05:00'),
(3, 'Jugendprobe', 'Probe der Jugendgruppe', 0, '#e67e22', '2024-01-15 09:10:00'),
(4, 'Auftritt', 'Öffentlicher Auftritt', 0, '#9b59b6', '2024-01-15 09:15:00');

-- TERMINARTEN <-> GRUPPEN
-- ============================================
INSERT INTO appointment_type_groups (type_id, group_id) VALUES
(1, 1), -- Probe -> Alle Mitglieder
(2, 2), -- Vorstandssitzung -> Vorstandschaft
(3, 3), -- Jugendprobe -> Jugendgruppe
(4, 1); -- Auftritt -> Alle Mitglieder

-- TERMINE (letzte 3 Monate + kommende 2 Wochen)
-- ============================================
-- Vergangene Termine
INSERT INTO appointments (appointment_id, title, type_id, description, date, start_time, created_by, created_at) VALUES
-- Januar 2025
(1, 'Neujahrskonzert', 4, 'Öffentliches Neujahrskonzert', '2025-01-06', '17:00:00', 1, '2025-12-20 10:00:00'),
(2, 'Probe', 1, 'Erste Probe im neuen Jahr', '2025-01-13', '19:00:00', 1, '2025-01-10 10:00:00'),
(3, 'Probe', 1, 'Reguläre Montags-Probe', '2025-01-20', '19:00:00', 1, '2025-01-17 10:00:00'),

-- November 2025
(4, 'Probe', 1, 'Reguläre Montags-Probe', '2025-11-04', '19:00:00', 1, '2025-11-01 10:00:00'),
(5, 'Probe', 1, 'Reguläre Montags-Probe', '2025-11-11', '19:00:00', 1, '2025-11-08 10:00:00'),
(6, 'Vorstandssitzung', 2, 'Jahresplanung 2026', '2025-11-15', '19:30:00', 1, '2025-11-10 14:00:00'),
(7, 'Probe', 1, 'Reguläre Montags-Probe', '2025-11-18', '19:00:00', 1, '2025-11-15 10:00:00'),
(8, 'Probe', 1, 'Reguläre Montags-Probe', '2025-11-25', '19:00:00', 1, '2025-11-22 10:00:00'),

-- Dezember 2025
(9, 'Probe', 1, 'Reguläre Montags-Probe', '2025-12-02', '19:00:00', 1, '2025-11-29 10:00:00'),
(10, 'Probe', 1, 'Reguläre Montags-Probe', '2025-12-09', '19:00:00', 1, '2025-12-06 10:00:00'),
(11, 'Weihnachtsmarkt Auftritt', 4, 'Auftritt auf dem Weihnachtsmarkt', '2025-12-15', '15:00:00', 1, '2025-12-01 10:00:00'),

-- Aktuelle/Kommende Termine (letzte Woche + nächste 2 Wochen ab heute)
(12, 'Probe', 1, 'Reguläre Montags-Probe', DATE_SUB(CURDATE(), INTERVAL 3 DAY), '19:00:00', 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(13, 'Jugendprobe', 3, 'Probe der Jugendgruppe', CURDATE(), '17:00:00', 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(14, 'Vorstandssitzung', 2, 'Quartalsplanung', DATE_ADD(CURDATE(), INTERVAL 2 DAY), '19:30:00', 1, NOW()),
(15, 'Probe', 1, 'Reguläre Montags-Probe', DATE_ADD(CURDATE(), INTERVAL 5 DAY), '19:00:00', 1, NOW()),
(16, 'Konzert Vorbereitung', 4, 'Probe für Frühlingskonzert', DATE_ADD(CURDATE(), INTERVAL 10 DAY), '14:00:00', 1, NOW()),
(17, 'Probe', 1, 'Reguläre Montags-Probe', DATE_ADD(CURDATE(), INTERVAL 12 DAY), '19:00:00', 1, NOW());

-- ANWESENHEITEN (Records) - Realistische Verteilung
-- ============================================
-- Termin 1 (Nov 4): Hohe Anwesenheit (16/20)
INSERT INTO records (member_id, appointment_id, arrival_time, status, checkin_source, created_at) VALUES
(1, 1, '2025-11-04 18:55:00', 'present', 'admin', '2025-11-04 18:55:00'),
(2, 1, '2025-11-04 19:02:00', 'present', 'user_totp', '2025-11-04 19:02:00'),
(3, 1, '2025-11-04 19:05:00', 'present', 'auth_device', '2025-11-04 19:05:00'),
(4, 1, '2025-11-04 18:58:00', 'present', 'user_totp', '2025-11-04 18:58:00'),
(6, 1, '2025-11-04 19:01:00', 'present', 'admin', '2025-11-04 19:01:00'),
(7, 1, '2025-11-04 19:03:00', 'present', 'user_totp', '2025-11-04 19:03:00'),
(8, 1, '2025-11-04 19:07:00', 'present', 'user_totp', '2025-11-04 19:07:00'),
(9, 1, '2025-11-04 18:59:00', 'present', 'user_totp', '2025-11-04 18:59:00'),
(10, 1, '2025-11-04 19:04:00', 'present', 'admin', '2025-11-04 19:04:00'),
(11, 1, '2025-11-04 19:06:00', 'present', 'user_totp', '2025-11-04 19:06:00'),
(13, 1, '2025-11-04 19:02:00', 'present', 'user_totp', '2025-11-04 19:02:00'),
(14, 1, '2025-11-04 19:08:00', 'present', 'user_totp', '2025-11-04 19:08:00'),
(15, 1, '2025-11-04 18:57:00', 'present', 'user_totp', '2025-11-04 18:57:00'),
(17, 1, '2025-11-04 19:01:00', 'present', 'user_totp', '2025-11-04 19:01:00'),
(18, 1, '2025-11-04 19:05:00', 'present', 'user_totp', '2025-11-04 19:05:00'),
(19, 1, '2025-11-04 19:09:00', 'present', 'user_totp', '2025-11-04 19:09:00');

-- Termin 2 (Nov 11): Mittlere Anwesenheit (13/20)
INSERT INTO records (member_id, appointment_id, arrival_time, status, checkin_source, created_at) VALUES
(1, 2, '2025-11-11 18:56:00', 'present', 'admin', '2025-11-11 18:56:00'),
(2, 2, '2025-11-11 19:03:00', 'present', 'user_totp', '2025-11-11 19:03:00'),
(3, 2, '2025-11-11 19:06:00', 'present', 'user_totp', '2025-11-11 19:06:00'),
(6, 2, '2025-11-11 19:02:00', 'present', 'user_totp', '2025-11-11 19:02:00'),
(7, 2, '2025-11-11 19:04:00', 'present', 'user_totp', '2025-11-11 19:04:00'),
(9, 2, '2025-11-11 19:00:00', 'present', 'user_totp', '2025-11-11 19:00:00'),
(10, 2, '2025-11-11 19:05:00', 'present', 'user_totp', '2025-11-11 19:05:00'),
(11, 2, '2025-11-11 19:07:00', 'present', 'user_totp', '2025-11-11 19:07:00'),
(13, 2, '2025-11-11 19:03:00', 'present', 'user_totp', '2025-11-11 19:03:00'),
(15, 2, '2025-11-11 18:58:00', 'present', 'user_totp', '2025-11-11 18:58:00'),
(17, 2, '2025-11-11 19:02:00', 'present', 'user_totp', '2025-11-11 19:02:00'),
(18, 2, '2025-11-11 19:06:00', 'present', 'user_totp', '2025-11-11 19:06:00'),
(19, 2, '2025-11-11 19:08:00', 'present', 'user_totp', '2025-11-11 19:08:00');

-- Termin 3 (Nov 15 Vorstandssitzung): Nur Vorstand (5/5)
INSERT INTO records (member_id, appointment_id, arrival_time, status, checkin_source, created_at) VALUES
(1, 3, '2025-11-15 19:25:00', 'present', 'admin', '2025-11-15 19:25:00'),
(2, 3, '2025-11-15 19:28:00', 'present', 'user_totp', '2025-11-15 19:28:00'),
(3, 3, '2025-11-15 19:31:00', 'present', 'user_totp', '2025-11-15 19:31:00'),
(4, 3, '2025-11-15 19:27:00', 'present', 'user_totp', '2025-11-15 19:27:00'),
(5, 3, '2025-11-15 19:33:00', 'present', 'user_totp', '2025-11-15 19:33:00');

-- Weitere Termine mit variabler Anwesenheit (vereinfacht)
-- Termin 4-12: Je 10-15 zufällige Anwesenheiten
INSERT INTO records (member_id, appointment_id, arrival_time, status, checkin_source) 
SELECT 
    m.member_id,
    a.appointment_id,
    CONCAT(a.date, ' ', TIME_FORMAT(ADDTIME(a.start_time, SEC_TO_TIME(FLOOR(RAND() * 600) - 300)), '%H:%i:%s')),
    'present',
    'user_totp'
FROM appointments a
CROSS JOIN members m
WHERE a.appointment_id BETWEEN 4 AND 12
  AND m.member_id <= 15  -- Nur erste 15 Members
  AND RAND() < 0.7;      -- 70% Anwesenheit

-- Letzter Termin (13): Aktuelle Woche
INSERT INTO records (member_id, appointment_id, arrival_time, status, checkin_source) 
SELECT 
    m.member_id,
    13,
    DATE_SUB(CURDATE(), INTERVAL 3 DAY) + INTERVAL FLOOR(RAND() * 600) SECOND,
    'present',
    'user_totp'
FROM members m
WHERE m.member_id <= 18
  AND RAND() < 0.8;

-- AUSNAHMEN (Exceptions)
-- ============================================
INSERT INTO exceptions (member_id, appointment_id, exception_type, reason, status, created_by, created_at) VALUES
-- Genehmigt
(5, 2, 'absence', 'Urlaub', 'approved', 1, '2025-11-09 10:00:00'),
(12, 2, 'absence', 'Krank', 'approved', 1, '2025-11-10 14:00:00'),
(16, 4, 'absence', 'Schulveranstaltung', 'approved', 1, '2025-11-17 09:00:00'),
-- Pending
(8, 15, 'absence', 'Geschäftstermin', 'pending', 2, NOW()),
(14, 16, 'absence', 'Familienfeier', 'pending', 3, NOW());

-- ============================================
-- 3. DEMO-INFO AUSGEBEN
-- ============================================
SELECT '===========================================' AS '';
SELECT 'Demo-Datenbank erfolgreich zurückgesetzt!' AS 'STATUS';
SELECT '===========================================' AS '';
SELECT '' AS '';
SELECT 'DEMO-ZUGÄNGE:' AS '';
SELECT '-------------' AS '';
SELECT 'Admin:   admin@demo.ehrensache.de   | Passwort: demo2025' AS '';
SELECT 'Manager: manager@demo.ehrensache.de | Passwort: demo2025' AS '';
SELECT 'User:    user@demo.ehrensache.de    | Passwort: demo2025' AS '';
SELECT '' AS '';
SELECT 'STATISTIK:' AS '';
SELECT '---------' AS '';
SELECT CONCAT('Mitglieder:  ', COUNT(*)) AS '' FROM members;
SELECT CONCAT('Termine:     ', COUNT(*)) AS '' FROM appointments;
SELECT CONCAT('Anwesenheiten: ', COUNT(*)) AS '' FROM records;
SELECT CONCAT('Gruppen:     ', COUNT(*)) AS '' FROM member_groups;
SELECT CONCAT('Ausnahmen:   ', COUNT(*)) AS '' FROM exceptions;
SELECT '' AS '';
SELECT '===========================================' AS '';