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
 * Gesetzliche Feiertage, berechnet statt importiert (FI-16).
 *
 * Spec: docs/superpowers/specs/2026-09-18-terminserien-feiertage-design.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/holidays.php';

test('Ostersonntag 2024 bis 2030', function () {
    $expected = [2024 => '2024-03-31', 2025 => '2025-04-20', 2026 => '2026-04-05', 2027 => '2027-03-28',
                 2028 => '2028-04-16', 2029 => '2029-04-01', 2030 => '2030-04-21'];
    foreach ($expected as $year => $date) {
        assertSame($date, easterSunday($year), "Ostern {$year}");
    }
});

test('Ohne Bundesland genau die elf bundesweiten Feiertage', function () {
    assertSame([
        '2026-01-01' => 'Neujahr',
        '2026-04-03' => 'Karfreitag',
        '2026-04-05' => 'Ostersonntag',
        '2026-04-06' => 'Ostermontag',
        '2026-05-01' => 'Tag der Arbeit',
        '2026-05-14' => 'Christi Himmelfahrt',
        '2026-05-24' => 'Pfingstsonntag',
        '2026-05-25' => 'Pfingstmontag',
        '2026-10-03' => 'Tag der Deutschen Einheit',
        '2026-12-25' => '1. Weihnachtstag',
        '2026-12-26' => '2. Weihnachtstag',
    ], holidaysBetween('2026-01-01', '2026-12-31', null));
});

test('Unbekanntes oder leeres Kuerzel verhaelt sich wie kein Bundesland', function () {
    $bund = holidaysBetween('2026-01-01', '2026-12-31', null);
    assertSame($bund, holidaysBetween('2026-01-01', '2026-12-31', 'XX'));
    assertSame($bund, holidaysBetween('2026-01-01', '2026-12-31', ''));
});

test('Baden-Wuerttemberg: Drei Koenige, Fronleichnam, Allerheiligen, kein Reformationstag', function () {
    $bw = holidaysBetween('2026-01-01', '2026-12-31', 'BW');
    assertSame('Heilige Drei Könige', $bw['2026-01-06'] ?? null);
    assertSame('Fronleichnam', $bw['2026-06-04'] ?? null);
    assertSame('Allerheiligen', $bw['2026-11-01'] ?? null);
    assertTrue(!isset($bw['2026-10-31']));
});

test('Sachsen: Reformationstag und Buss- und Bettag, kein Fronleichnam', function () {
    $sn = holidaysBetween('2026-01-01', '2026-12-31', 'SN');
    assertSame('Reformationstag', $sn['2026-10-31'] ?? null);
    assertSame('Buß- und Bettag', $sn['2026-11-18'] ?? null);
    assertTrue(!isset($sn['2026-06-04']));
    // 23.11.2022 war ein Mittwoch -- der Buss- und Bettag liegt dann eine Woche davor.
    assertSame('Buß- und Bettag', holidaysBetween('2022-11-01', '2022-11-30', 'SN')['2022-11-16'] ?? null);
});

test('Einfuehrungsjahre werden beachtet', function () {
    assertTrue(!isset(holidaysBetween('2017-10-31', '2017-10-31', 'NI')['2017-10-31']), 'NI erst ab 2018');
    assertTrue(isset(holidaysBetween('2018-10-31', '2018-10-31', 'NI')['2018-10-31']));
    assertTrue(!isset(holidaysBetween('2018-03-08', '2018-03-08', 'BE')['2018-03-08']), 'BE erst ab 2019');
    assertTrue(isset(holidaysBetween('2019-03-08', '2019-03-08', 'BE')['2019-03-08']));
    assertTrue(!isset(holidaysBetween('2022-03-08', '2022-03-08', 'MV')['2022-03-08']), 'MV erst ab 2023');
    assertTrue(isset(holidaysBetween('2023-03-08', '2023-03-08', 'MV')['2023-03-08']));
});

test('Thueringen: Weltkindertag; Oster- und Pfingstsonntag ueberall, z.B. Bayern', function () {
    assertSame('Weltkindertag', holidaysBetween('2026-09-20', '2026-09-20', 'TH')['2026-09-20'] ?? null);
    $by = holidaysBetween('2026-01-01', '2026-12-31', 'BY');
    assertSame('Ostersonntag', $by['2026-04-05'] ?? null);
    assertSame('Pfingstsonntag', $by['2026-05-24'] ?? null);
});

test('Saarland: Mariae Himmelfahrt; Bayern bewusst nicht', function () {
    assertTrue(isset(holidaysBetween('2026-08-15', '2026-08-15', 'SL')['2026-08-15']));
    assertTrue(!isset(holidaysBetween('2026-08-15', '2026-08-15', 'BY')['2026-08-15']));
});

test('Zeitraum ueber den Jahreswechsel', function () {
    assertSame(['2026-12-25' => '1. Weihnachtstag', '2026-12-26' => '2. Weihnachtstag', '2027-01-01' => 'Neujahr'],
        holidaysBetween('2026-12-20', '2027-01-10', null));
});

test('Zwei Feiertage am selben Tag werden zusammengefasst', function () {
    // 2008 fiel Christi Himmelfahrt auf den 1. Mai.
    assertSame('Tag der Arbeit / Christi Himmelfahrt', holidaysBetween('2008-05-01', '2008-05-01', null)['2008-05-01'] ?? null);
});

test('Die Laenderliste hat 16 Eintraege', function () {
    assertSame(16, count(HOLIDAY_REGIONS));
});
