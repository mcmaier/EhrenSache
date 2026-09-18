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
 * Gesetzliche Feiertage in Deutschland (FI-16), berechnet statt importiert.
 *
 * Bewusst OHNE ext-calendar (easter_days()): die Erweiterung fehlt auf einem
 * Teil der Hostings und steht nicht in requires von version.json.
 *
 * Nicht erzeugt werden Feiertage, die nur fuer Teile eines Landes gelten
 * (Mariae Himmelfahrt in Bayern, Augsburger Friedensfest, Fronleichnam in
 * Teilen Sachsens und Thueringens) sowie einmalige (Reformationstag 2017
 * bundesweit, 8.5.2025 in Berlin). Der Hinweis an der Einstellung sagt das.
 */
declare(strict_types=1);

const HOLIDAY_REGIONS = [
    'BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg',
    'HB' => 'Bremen', 'HH' => 'Hamburg', 'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern',
    'NI' => 'Niedersachsen', 'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz',
    'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein',
    'TH' => 'Thüringen',
];

/** Ostersonntag im gregorianischen Kalender (Gauss, Form nach Meeus/Jones/Butcher). */
function easterSunday(int $year): string
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day   = (($h + $l - 7 * $m + 114) % 31) + 1;

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** @return array<string, string> Datum => Name, aufsteigend */
function holidaysForYear(int $year, ?string $region): array
{
    $utc    = new DateTimeZone('UTC');
    $easter = new DateTimeImmutable(easterSunday($year), $utc);
    $e      = fn (int $offset): string => $easter->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    $f      = fn (string $monthDay): string => "{$year}-{$monthDay}";

    $list = [];
    $add  = function (string $date, string $name) use (&$list): void {
        $list[$date] = isset($list[$date]) ? $list[$date] . ' / ' . $name : $name;
    };

    $add($f('01-01'), 'Neujahr');
    $add($e(-2), 'Karfreitag');
    $add($e(1), 'Ostermontag');
    $add($f('05-01'), 'Tag der Arbeit');
    $add($e(39), 'Christi Himmelfahrt');
    $add($e(50), 'Pfingstmontag');
    $add($f('10-03'), 'Tag der Deutschen Einheit');
    $add($f('12-25'), '1. Weihnachtstag');
    $add($f('12-26'), '2. Weihnachtstag');

    $r  = ($region !== null && array_key_exists($region, HOLIDAY_REGIONS)) ? $region : null;
    $in = fn (array $regions): bool => $r !== null && in_array($r, $regions, true);

    if ($in(['BW', 'BY', 'ST'])) {
        $add($f('01-06'), 'Heilige Drei Könige');
    }
    if (($r === 'BE' && $year >= 2019) || ($r === 'MV' && $year >= 2023)) {
        $add($f('03-08'), 'Internationaler Frauentag');
    }
    if ($r === 'BB') {
        $add($e(0), 'Ostersonntag');
        $add($e(49), 'Pfingstsonntag');
    }
    if ($in(['BW', 'BY', 'HE', 'NW', 'RP', 'SL'])) {
        $add($e(60), 'Fronleichnam');
    }
    if ($r === 'SL') {
        $add($f('08-15'), 'Mariä Himmelfahrt');
    }
    if ($r === 'TH' && $year >= 2019) {
        $add($f('09-20'), 'Weltkindertag');
    }
    if ($in(['BB', 'MV', 'SN', 'ST', 'TH']) || ($in(['HB', 'HH', 'NI', 'SH']) && $year >= 2018)) {
        $add($f('10-31'), 'Reformationstag');
    }
    if ($in(['BW', 'BY', 'NW', 'RP', 'SL'])) {
        $add($f('11-01'), 'Allerheiligen');
    }
    if ($r === 'SN') {
        // Mittwoch vor dem 23. November (strikt davor).
        $nov23 = new DateTimeImmutable("{$year}-11-23", $utc);
        $back  = ((int) $nov23->format('N') - 3 + 7) % 7;
        $add($nov23->modify('-' . ($back === 0 ? 7 : $back) . ' days')->format('Y-m-d'), 'Buß- und Bettag');
    }

    ksort($list);

    return $list;
}

/** @return array<string, string> Feiertage in [from, to], aufsteigend */
function holidaysBetween(string $from, string $to, ?string $region): array
{
    $out = [];
    for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
        foreach (holidaysForYear($year, $region) as $date => $name) {
            if ($date >= $from && $date <= $to) {
                $out[$date] = $name;
            }
        }
    }

    return $out;
}
