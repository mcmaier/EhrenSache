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
 * Eine Regel "Termin hat begonnen" (OI-89): Startzeit minus Check-in-Vorlauf.
 *
 * Die PHP-Fassung (attendanceHasStarted) und die SQL-Fassung
 * (attendanceStartedSql) muessen beim selben Zeitpunkt umschlagen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/appointment_attendance.php';
require_once __DIR__ . '/../../private/helpers/attendance.php';

// ---- attendanceHasStarted ---------------------------------------------------

test('attendanceHasStarted: ohne Vorlauf zaehlt die Startzeit', function () {
    $apt = ['date' => '2026-10-01', 'start_time' => '19:00:00'];

    assertSame(false, attendanceHasStarted($apt, 0, '2026-10-01 18:59:59'));
    assertSame(true, attendanceHasStarted($apt, 0, '2026-10-01 19:00:00'));
});

test('attendanceHasStarted: der Vorlauf oeffnet das Fenster frueher', function () {
    $apt = ['date' => '2026-10-01', 'start_time' => '19:00:00'];

    assertSame(false, attendanceHasStarted($apt, 2, '2026-10-01 16:59:59'),
        'Vor dem Check-in-Fenster ist der Termin kommend');
    assertSame(true, attendanceHasStarted($apt, 2, '2026-10-01 17:00:00'),
        'Mit Beginn des Check-in-Fensters gilt der Termin als begonnen');
});

test('attendanceHasStarted: der Vorlauf reicht ueber Mitternacht zurueck', function () {
    $apt = ['date' => '2026-10-02', 'start_time' => '01:00:00'];

    assertSame(true, attendanceHasStarted($apt, 2, '2026-10-01 23:00:00'));
    assertSame(false, attendanceHasStarted($apt, 2, '2026-10-01 22:59:59'));
});

test('attendanceHasStarted: ein Termin von heute Abend hat morgens nicht begonnen', function () {
    // Der alte Datums-Cutoff der Statistik hielt ihn ab Mitternacht fuer begonnen.
    $apt = ['date' => '2026-10-01', 'start_time' => '19:00:00'];

    assertSame(false, attendanceHasStarted($apt, 2, '2026-10-01 08:00:00'));
});

test('attendanceHasStarted: ohne Datum nie begonnen', function () {
    assertSame(false, attendanceHasStarted(['date' => '', 'start_time' => '19:00:00'], 2, '2026-10-01 20:00:00'));
});

// ---- attendanceStartedSql ---------------------------------------------------

test('attendanceStartedSql: Startzeitpunkt gegen die Datenbankuhr, Vorlauf in Stunden', function () {
    assertSame('TIMESTAMP(a.date, a.start_time) <= NOW() + INTERVAL 2 HOUR', attendanceStartedSql(2));
    assertSame('TIMESTAMP(x.date, x.start_time) <= NOW() + INTERVAL 0 HOUR', attendanceStartedSql(0, 'x'));
});

test('attendanceStartedSql: Alias wird nicht ungeprueft ins SQL uebernommen', function () {
    $threw = false;
    try {
        attendanceStartedSql(2, 'a; DROP TABLE x');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrue($threw, 'Ungueltiger Alias muss abgelehnt werden');
});

test('Der alte Datums-Cutoff ist entfernt', function () {
    assertSame(false, defined('ATTENDANCE_STARTED_CUTOFF_SQL'),
        'ATTENDANCE_STARTED_CUTOFF_SQL rechnete nur nach Datum -- eine zweite Regel neben attendanceStartedSql()');

    $root = dirname(__DIR__, 2);
    foreach (['private/helpers/attendance.php', 'private/helpers/punctuality.php',
              'private/handlers/report_statistics.php'] as $file) {
        $src = (string) file_get_contents($root . '/' . $file);
        assertSame(0, substr_count($src, 'ATTENDANCE_STARTED_CUTOFF_SQL'), $file . ' nutzt noch den alten Cutoff');
        assertTrue(str_contains($src, 'attendanceStartedSql('), $file . ' nutzt die gemeinsame Regel nicht');
    }
});
