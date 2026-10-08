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
 * Mitglieder rechnen nur das eigene Mitglied (OI-98).
 *
 * attendanceAttachSummaries() liefert Nicht-Verwaltern nur own_attendance. Die
 * Soll-Menge und die Records aller anderen Mitglieder braucht es dafuer nicht --
 * bei 500 Mitgliedern und 600 Terminen im Jahr kostete das rund 260 ms je
 * Jahresabruf. Geprueft wird gegen SQLite mit einer Spionage-PDO: dieselben
 * Ergebnisse wie fuer den Verwalter, aber die Abfragen tragen den Filter auf
 * das eigene Mitglied.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/utils.php';
require_once __DIR__ . '/../../private/helpers/appointment_attendance.php';

if (!extension_loaded('pdo_sqlite')) {
    test('attendance_member_scope uebersprungen: pdo_sqlite fehlt', function () {
        throw new RuntimeException('php_pdo_sqlite aktivieren');
    });
    return;
}

if (!class_exists('AmsDatabase')) {
    final class AmsDatabase
    {
        public function table(string $name): string
        {
            return 'ams_' . $name;
        }
    }

    /** PDO, das jede vorbereitete Abfrage samt Parametern mitschreibt. */
    final class AmsSpyPdo extends PDO
    {
        /** @var array<int, string> */
        public array $prepared = [];

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->prepared[] = $query;

            return parent::prepare($query, $options);
        }
    }
}

/**
 * Gruppe 1 an Terminart 1. Mitglieder 1, 2, 3 in der Gruppe, 4 nicht.
 * Termine 10 (vergangen), 11 (vergangen), 12 (kuenftig).
 * Records: 10 -> 1 present, 2 excused; 11 -> 2 present, 3 present.
 */
function amsDb(): AmsSpyPdo
{
    $pdo = new AmsSpyPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', static fn () => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('TIMESTAMP', static fn ($d, $t) => $d . ' ' . $t, 2);

    $pdo->exec('CREATE TABLE ams_system_settings (setting_key TEXT, setting_value TEXT)');
    $pdo->exec("INSERT INTO ams_system_settings VALUES ('checkin_tolerance_hours', '2')");
    $pdo->exec('CREATE TABLE ams_members (member_id INTEGER PRIMARY KEY, active INTEGER)');
    $pdo->exec('CREATE TABLE ams_membership_dates (member_id INTEGER, start_date TEXT, end_date TEXT)');
    $pdo->exec('CREATE TABLE ams_member_group_assignments (member_id INTEGER, group_id INTEGER, valid_from TEXT)');
    $pdo->exec('CREATE TABLE ams_member_group_history (member_id INTEGER, group_id INTEGER, valid_from TEXT, valid_to TEXT)');
    $pdo->exec('CREATE TABLE ams_appointment_type_groups (type_id INTEGER, group_id INTEGER)');
    $pdo->exec('CREATE TABLE ams_appointments (appointment_id INTEGER PRIMARY KEY, type_id INTEGER, date TEXT, start_time TEXT)');
    $pdo->exec('CREATE TABLE ams_records (appointment_id INTEGER, member_id INTEGER, status TEXT)');

    $pdo->exec('INSERT INTO ams_members VALUES (1, 1), (2, 1), (3, 1), (4, 1)');
    $pdo->exec('INSERT INTO ams_member_group_assignments VALUES (1, 1, NULL), (2, 1, NULL), (3, 1, NULL)');
    $pdo->exec('INSERT INTO ams_appointment_type_groups VALUES (1, 1)');
    $pdo->exec("INSERT INTO ams_appointments VALUES (10, 1, '2020-03-01', '19:00:00'),
                                                    (11, 1, '2020-03-08', '19:00:00'),
                                                    (12, 1, '2999-01-01', '19:00:00')");
    $pdo->exec("INSERT INTO ams_records VALUES (10, 1, 'present'), (10, 2, 'excused'),
                                               (11, 2, 'present'), (11, 3, 'present')");

    return $pdo;
}

function amsAppointments(PDO $pdo): array
{
    return $pdo->query('SELECT appointment_id, type_id, date, start_time FROM ams_appointments ORDER BY appointment_id')
               ->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<int, mixed> appointment_id => own_attendance */
function amsOwn(array $rows): array
{
    $own = [];
    foreach ($rows as $row) {
        $own[(int) $row['appointment_id']] = $row['own_attendance'];
    }

    return $own;
}

test('Mitglied: own_attendance je Termin wie bisher', function () {
    $pdo  = amsDb();
    $db   = new AmsDatabase();
    $apts = amsAppointments($pdo);

    assertSame([10 => 'present', 11 => 'missing', 12 => null],
        amsOwn(attendanceAttachSummaries($pdo, $db, $apts, 1, false)));
    assertSame([10 => 'excused', 11 => 'present', 12 => null],
        amsOwn(attendanceAttachSummaries($pdo, $db, $apts, 2, false)));
    assertSame([10 => null, 11 => null, 12 => null],
        amsOwn(attendanceAttachSummaries($pdo, $db, $apts, 4, false)),
        'Nicht erwartetes Mitglied: kein eigener Status');
});

test('Mitglied: own_attendance stimmt mit der vollen Soll-Menge ueberein', function () {
    $pdo  = amsDb();
    $db   = new AmsDatabase();
    $apts = amsAppointments($pdo);

    $full = attendanceExpectedMemberIds($pdo, $db, [10, 11]);
    foreach ([1, 2, 3, 4] as $memberId) {
        $own = amsOwn(attendanceAttachSummaries($pdo, $db, $apts, $memberId, false));
        foreach ([10, 11] as $aid) {
            assertSame(isset($full[$aid][$memberId]), $own[$aid] !== null,
                "Mitglied {$memberId}, Termin {$aid}: Erwartung weicht von der vollen Soll-Menge ab");
        }
    }
});

test('Ohne verknuepftes Mitglied: alles null, keine Abfrage auf Soll-Menge und Records', function () {
    $pdo  = amsDb();
    $apts = amsAppointments($pdo);
    $pdo->prepared = [];

    assertSame([10 => null, 11 => null, 12 => null],
        amsOwn(attendanceAttachSummaries($pdo, new AmsDatabase(), $apts, null, false)));
    foreach ($pdo->prepared as $sql) {
        assertTrue(strpos($sql, 'ams_records') === false && strpos($sql, 'appointment_type_groups') === false,
            'Ohne Mitglied gibt es nichts zu zaehlen: ' . $sql);
    }
});

test('Mitglied: Soll-Menge und Records nur fuer das eigene Mitglied abgefragt', function () {
    $pdo  = amsDb();
    $apts = amsAppointments($pdo);
    $pdo->prepared = [];

    attendanceAttachSummaries($pdo, new AmsDatabase(), $apts, 2, false);

    $expected = array_values(array_filter($pdo->prepared, static fn ($s) => strpos($s, 'appointment_type_groups') !== false));
    $records  = array_values(array_filter($pdo->prepared, static fn ($s) => strpos($s, 'FROM ams_records') !== false));
    assertSame(1, count($expected), 'Genau eine Abfrage auf die Soll-Menge erwartet');
    assertSame(1, count($records), 'Genau eine Abfrage auf records erwartet');
    assertTrue(strpos($expected[0], 'mga.member_id = ?') !== false,
        'Die Soll-Menge muss auf das eigene Mitglied gefiltert sein: ' . $expected[0]);
    assertTrue(preg_match('/\bmember_id\s*=\s*\?/', $records[0]) === 1,
        'Die records-Abfrage muss auf das eigene Mitglied gefiltert sein: ' . $records[0]);
});

test('Verwalter: Zahlen ueber alle Mitglieder, ohne Mitgliedsfilter', function () {
    $pdo  = amsDb();
    $apts = amsAppointments($pdo);
    $pdo->prepared = [];

    $rows = attendanceAttachSummaries($pdo, new AmsDatabase(), $apts, 2, true);
    $by = [];
    foreach ($rows as $row) {
        $by[(int) $row['appointment_id']] = $row['attendance'];
    }
    assertSame(['expected' => 3, 'present' => 1, 'excused' => 1, 'missing' => 1], $by[10]);
    assertSame(['expected' => 3, 'present' => 2, 'excused' => 0, 'missing' => 1], $by[11]);
    assertSame(null, $by[12]);

    foreach ($pdo->prepared as $sql) {
        assertTrue(strpos($sql, 'mga.member_id = ?') === false,
            'Verwalter brauchen alle Mitglieder, kein Mitgliedsfilter: ' . $sql);
    }
});
