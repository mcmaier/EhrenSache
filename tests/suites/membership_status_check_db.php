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
 * Bestandsprüfung membershipStatusCheck() gegen MariaDB (OI-130, Migration 1.23.0 → 1.23.1).
 *
 * Arbeitet ausschliesslich mit eigenen Wegwerf-Tabellen (Praefix mstc_ plus
 * Zufallsteil), die der Test anlegt und am Ende wieder entfernt. Ohne
 * pdo_mysql oder Konfiguration wird still uebersprungen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/config_reader.php';
require_once __DIR__ . '/../../private/helpers/membership_status_check.php';

$mscPdo = null;
if (extension_loaded('pdo_mysql') && is_file(__DIR__ . '/../../private/config/config.php')) {
    try {
        $mscCfg  = configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'));
        $mscDb   = $mscCfg['db'];
        $mscHost = $mscDb['host'];
        $mscPort = '3306';
        if (str_contains($mscHost, ':')) {
            [$mscHost, $mscPort] = explode(':', $mscHost, 2);
        }
        $mscPdo = new PDO(
            "mysql:host={$mscHost};port={$mscPort};dbname={$mscDb['name']};charset=utf8mb4",
            $mscDb['user'],
            $mscDb['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $e) {
        $mscPdo = null;
    }
}

/** Legt members und membership_dates in Minimalform unter dem Präfix an. */
function mscCreateTables(PDO $pdo, string $prefix): void
{
    $pdo->exec("CREATE TABLE `{$prefix}members` (
        member_id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL, surname VARCHAR(100) NOT NULL,
        member_number VARCHAR(50) DEFAULT NULL, active TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE `{$prefix}membership_dates` (
        membership_date_id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL, start_date DATE NOT NULL, end_date DATE DEFAULT NULL,
        status ENUM('active','inactive') DEFAULT 'active'
    ) ENGINE=InnoDB");
}

function mscDropTables(PDO $pdo, string $prefix): void
{
    $pdo->exec("DROP TABLE IF EXISTS `{$prefix}membership_dates`, `{$prefix}members`");
}

/** Mitglied mit Zeiträumen anlegen; $periods: Liste [start, end|null, status]. */
function mscMember(PDO $pdo, string $prefix, string $name, ?string $number, int $active, array $periods): void
{
    $pdo->prepare("INSERT INTO `{$prefix}members` (name, surname, member_number, active) VALUES (?, 'Test', ?, ?)")
        ->execute([$name, $number, $active]);
    $id = (int) $pdo->lastInsertId();
    foreach ($periods as [$start, $end, $status]) {
        $pdo->prepare("INSERT INTO `{$prefix}membership_dates` (member_id, start_date, end_date, status) VALUES (?, ?, ?, ?)")
            ->execute([$id, $start, $end, $status]);
    }
}

if ($mscPdo !== null) {
    test('membershipStatusCheck meldet nur aktive Mitglieder mit beendetem Inaktiv-Zeitraum ohne Aktiv-Zeitraum', function () use ($mscPdo) {
        $prefix = 'mstc_' . bin2hex(random_bytes(3)) . '_';
        try {
            mscCreateTables($mscPdo, $prefix);
            // Betroffen: aktiv, beendeter Inaktiv-Zeitraum, kein Aktiv-Zeitraum
            mscMember($mscPdo, $prefix, 'Betroffen', 'M100', 1, [['2025-01-01', '2025-12-31', 'inactive']]);
            // Nicht betroffen: zusaetzlich ein Aktiv-Zeitraum
            mscMember($mscPdo, $prefix, 'MitAktiv', 'M101', 1, [['2024-01-01', '2024-12-31', 'active'], ['2025-01-01', '2025-06-30', 'inactive']]);
            // Nicht betroffen: Stammdaten inaktiv
            mscMember($mscPdo, $prefix, 'Ausgetreten', 'M102', 0, [['2025-01-01', '2025-12-31', 'inactive']]);
            // Nicht betroffen: Inaktiv-Zeitraum ohne Ende (ab X inaktiv, neue Bedeutung stimmt)
            mscMember($mscPdo, $prefix, 'Offen', 'M103', 1, [['2025-10-01', null, 'inactive']]);
            // Nicht betroffen: gar keine Zeitraeume
            mscMember($mscPdo, $prefix, 'Ohne', 'M104', 1, []);

            $before = $mscPdo->query("SELECT COUNT(*), SUM(status = 'inactive') FROM `{$prefix}membership_dates`")->fetch(PDO::FETCH_NUM);
            $result = membershipStatusCheck($mscPdo, $prefix);

            assertSame(1, count($result['warnings']), 'genau eine Warnung erwartet');
            $w = $result['warnings'][0];
            assertTrue(str_contains($w, 'Betroffen Test (M100)'), "betroffenes Mitglied fehlt: {$w}");
            assertTrue(str_contains($w, '1 Mitglied(er)'), "Anzahl fehlt: {$w}");
            foreach (['MitAktiv', 'Ausgetreten', 'Offen', 'Ohne'] as $nicht) {
                assertTrue(!str_contains($w, $nicht), "{$nicht} darf nicht gemeldet werden: {$w}");
            }

            // Keine Datenaenderung, wiederholbar mit gleichem Ergebnis
            $after = $mscPdo->query("SELECT COUNT(*), SUM(status = 'inactive') FROM `{$prefix}membership_dates`")->fetch(PDO::FETCH_NUM);
            assertSame($before, $after, 'membership_dates wurde veraendert');
            assertSame($result, membershipStatusCheck($mscPdo, $prefix), 'zweiter Lauf liefert ein anderes Ergebnis');
        } finally {
            mscDropTables($mscPdo, $prefix);
        }
    });

    test('membershipStatusCheck ohne Betroffene: keine Warnung, Protokolleintrag', function () use ($mscPdo) {
        $prefix = 'mstc_' . bin2hex(random_bytes(3)) . '_';
        try {
            mscCreateTables($mscPdo, $prefix);
            mscMember($mscPdo, $prefix, 'Normal', 'M200', 1, [['2025-01-01', null, 'active']]);
            $result = membershipStatusCheck($mscPdo, $prefix);
            assertSame([], $result['warnings']);
            assertSame(1, count($result['log']));
        } finally {
            mscDropTables($mscPdo, $prefix);
        }
    });

    test('membershipStatusCheck nennt hoechstens die Listengrenze, Rest als Zahl; ohne Nummer die ID', function () use ($mscPdo) {
        $prefix = 'mstc_' . bin2hex(random_bytes(3)) . '_';
        try {
            mscCreateTables($mscPdo, $prefix);
            $total = MEMBERSHIP_STATUS_CHECK_LIST_LIMIT + 3;
            for ($i = 0; $i < $total; $i++) {
                mscMember($mscPdo, $prefix, sprintf('N%03d', $i), null, 1, [['2025-01-01', '2025-03-31', 'inactive']]);
            }
            $w = membershipStatusCheck($mscPdo, $prefix)['warnings'][0];
            assertTrue(str_contains($w, "{$total} Mitglied(er)"), "Gesamtzahl fehlt: {$w}");
            assertTrue(str_contains($w, 'und 3 weitere'), "Rest fehlt: {$w}");
            assertTrue((bool) preg_match('/N000 Test \(ID \d+\)/', $w), "ID statt Nummer fehlt: {$w}");
        } finally {
            mscDropTables($mscPdo, $prefix);
        }
    });

    test('membershipStatusCheck ohne Tabellen: Warnung, kein Abbruch', function () use ($mscPdo) {
        $prefix = 'mstc_' . bin2hex(random_bytes(3)) . '_';
        $result = membershipStatusCheck($mscPdo, $prefix);
        assertSame(1, count($result['warnings']));
        assertTrue(str_contains($result['warnings'][0], 'übersprungen'));
    });
}
