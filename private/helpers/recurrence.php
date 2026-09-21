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
 * Wiederholungsregeln der Terminserien (FI-7), ohne Datenbank.
 *
 * Erlaubt ist nur eine Teilmenge von RFC 5545:
 *   FREQ=WEEKLY;INTERVAL=1..4;BYDAY=MO,TU,...
 *   FREQ=MONTHLY;INTERVAL=1;BYDAY=<1..4|-1><Tag>
 * Das Ende steht nicht in der Regel, sondern als eigene Spalte (until).
 *
 * Gerechnet wird ausschliesslich mit Kalenderdaten in UTC -- keine Uhrzeit,
 * daher kann die Zeitumstellung keinen Tag verschieben.
 */
declare(strict_types=1);

const SERIES_MAX_MONTHS = 12;
const RRULE_WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

/**
 * @return array{freq: string, interval: int, byday: string[]}
 * @throws InvalidArgumentException
 */
function parseRrule(string $rrule): array
{
    $parts = [];
    foreach (explode(';', trim($rrule)) as $chunk) {
        if ($chunk === '') {
            continue;
        }
        // Die rohe Eingabe steht bewusst NICHT in der Meldung: Sie landet
        // unveraendert in einem Toast (innerHTML) und waere damit ein Weg fuer
        // reflektiertes Markup/Skript im eigenen Text der Meldung.
        $kv = explode('=', $chunk, 2);
        if (count($kv) !== 2) {
            throw new InvalidArgumentException('Ungültiger Regelteil');
        }
        $key = strtoupper(trim($kv[0]));
        if (array_key_exists($key, $parts)) {
            throw new InvalidArgumentException('Regelteil doppelt');
        }
        $parts[$key] = strtoupper(trim($kv[1]));
    }

    $unknown = array_diff(array_keys($parts), ['FREQ', 'INTERVAL', 'BYDAY']);
    if ($unknown) {
        throw new InvalidArgumentException('Nicht unterstützter Regelteil');
    }

    $interval = 1;
    if (array_key_exists('INTERVAL', $parts)) {
        if (!preg_match('/^[1-9]\d*$/', $parts['INTERVAL'])) {
            throw new InvalidArgumentException('Der Abstand muss eine positive ganze Zahl sein');
        }
        $interval = (int) $parts['INTERVAL'];
    }

    $byday = ($parts['BYDAY'] ?? '') === '' ? [] : explode(',', $parts['BYDAY']);
    $freq  = $parts['FREQ'] ?? '';

    if ($freq === 'WEEKLY') {
        if ($interval > 4) {
            throw new InvalidArgumentException('Der Abstand liegt zwischen 1 und 4 Wochen');
        }
        if ($byday === []) {
            throw new InvalidArgumentException('Mindestens ein Wochentag ist nötig');
        }
        foreach ($byday as $day) {
            if (!in_array($day, RRULE_WEEKDAYS, true)) {
                throw new InvalidArgumentException('Unbekannter Wochentag');
            }
        }
        if (count(array_unique($byday)) !== count($byday)) {
            throw new InvalidArgumentException('Ein Wochentag ist doppelt angegeben');
        }
        usort($byday, fn (string $a, string $b): int
            => array_search($a, RRULE_WEEKDAYS, true) <=> array_search($b, RRULE_WEEKDAYS, true));

        return ['freq' => 'WEEKLY', 'interval' => $interval, 'byday' => $byday];
    }

    if ($freq === 'MONTHLY') {
        if ($interval !== 1) {
            throw new InvalidArgumentException('Monatliche Serien haben keinen Abstand');
        }
        if (count($byday) !== 1 || !preg_match('/^(-1|[1-4])(MO|TU|WE|TH|FR|SA|SU)$/', $byday[0])) {
            throw new InvalidArgumentException('Monatlich braucht genau einen Tag mit Position 1 bis 4 oder -1');
        }

        return ['freq' => 'MONTHLY', 'interval' => 1, 'byday' => [$byday[0]]];
    }

    throw new InvalidArgumentException('Das Muster muss wöchentlich oder monatlich sein');
}

/** Kanonische Schreibweise; prueft dabei ueber parseRrule(). */
function buildRrule(array $rule): string
{
    $freq     = $rule['freq'] ?? '';
    $interval = $rule['interval'] ?? 1;
    $byday    = $rule['byday'] ?? [];

    if (!is_string($freq) || !is_int($interval) || !is_array($byday)) {
        throw new InvalidArgumentException('Ungültige Regelstruktur');
    }
    foreach ($byday as $day) {
        if (!is_string($day)) {
            throw new InvalidArgumentException('Ungültiger Wochentag in der Regel');
        }
    }

    $raw   = sprintf('FREQ=%s;INTERVAL=%d;BYDAY=%s', $freq, $interval, implode(',', $byday));
    $canon = parseRrule($raw);

    return sprintf('FREQ=%s;INTERVAL=%d;BYDAY=%s', $canon['freq'], $canon['interval'], implode(',', $canon['byday']));
}

function seriesIsValidDate(string $date): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

    return $d !== false && $d->format('Y-m-d') === $date;
}

/** @throws InvalidArgumentException */
function seriesDate(string $date): DateTimeImmutable
{
    if (!seriesIsValidDate($date)) {
        throw new InvalidArgumentException("Ungültiges Datum: {$date}");
    }

    return DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
}

function seriesAddDays(string $date, int $days): string
{
    return seriesDate($date)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/** Spaetestes zulaessiges Ende: gleicher Tag zwoelf Monate spaeter, am Monatsende gekappt. */
function seriesMaxUntil(string $from): string
{
    $d = seriesDate($from);
    $firstOfTarget = $d->modify('first day of this month')->modify('+' . SERIES_MAX_MONTHS . ' months');
    $day = min((int) $d->format('j'), (int) $firstOfTarget->format('t'));

    return $firstOfTarget->format('Y-m-') . sprintf('%02d', $day);
}

/**
 * Alle Termine der Regel in [startDate, until], aufsteigend, ohne exdates.
 *
 * $anchorDate bindet bei woechentlichen Regeln mit INTERVAL > 1 den Wochentakt an die
 * Woche eines frueheren Datums, statt an $startDate selbst -- noetig, wenn eine Serie ueber
 * "Serie fortsetzen" mit einem neuen $startDate (= altes until + 1 Tag) weiterlaeuft: ohne
 * Anker wuerde sich der Zwei-/Drei-/Vierwochenrhythmus am neuen Beginn statt am urspruenglichen
 * Serienbeginn ausrichten und dadurch verschieben. $anchorDate muss <= $startDate sein und wird
 * bei monatlichen Regeln ignoriert. Ohne Angabe verhaelt sich die Funktion wie zuvor: der
 * Wochentakt bindet sich an $startDate.
 *
 * @param string[] $exdates
 * @return string[]
 * @throws InvalidArgumentException bei unbekannter Frequenz oder einem Anker nach $startDate
 */
function expandOccurrences(array $rule, string $startDate, string $until, array $exdates = [], ?string $anchorDate = null): array
{
    $start = seriesDate($startDate);
    $end   = seriesDate($until);
    if ($end < $start) {
        return [];
    }

    if (!in_array($rule['freq'] ?? null, ['WEEKLY', 'MONTHLY'], true)) {
        throw new InvalidArgumentException('Unbekannte Frequenz: ' . (string) ($rule['freq'] ?? ''));
    }

    $anchor = $anchorDate !== null ? seriesDate($anchorDate) : $start;
    if ($anchor > $start) {
        throw new InvalidArgumentException('Der Anker darf nicht nach dem Beginn liegen');
    }

    $skip = array_flip($exdates);
    $out  = [];

    if ($rule['freq'] === 'WEEKLY') {
        $offsets   = array_map(fn (string $d): int => (int) array_search($d, RRULE_WEEKDAYS, true), $rule['byday']);
        $weekStart = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
        $step      = '+' . (7 * (int) $rule['interval']) . ' days';

        for ($week = $weekStart; $week <= $end; $week = $week->modify($step)) {
            foreach ($offsets as $offset) {
                $day = $week->modify("+{$offset} days");
                if ($day < $start || $day > $end) {
                    continue;
                }
                $key = $day->format('Y-m-d');
                if (!isset($skip[$key])) {
                    $out[] = $key;
                }
            }
        }

        return $out;
    }

    preg_match('/^(-1|[1-4])([A-Z]{2})$/', $rule['byday'][0], $m);
    $position   = (int) $m[1];
    $isoWeekday = (int) array_search($m[2], RRULE_WEEKDAYS, true) + 1;

    for ($month = $start->modify('first day of this month'); $month <= $end; $month = $month->modify('first day of next month')) {
        $day = seriesMonthlyPosition($month, $position, $isoWeekday);
        if ($day === null || $day < $start || $day > $end) {
            continue;
        }
        $key = $day->format('Y-m-d');
        if (!isset($skip[$key])) {
            $out[] = $key;
        }
    }

    return $out;
}

/** n-ter (1..4) oder letzter (-1) Wochentag eines Monats; $isoWeekday 1 = Montag. */
function seriesMonthlyPosition(DateTimeImmutable $firstOfMonth, int $position, int $isoWeekday): ?DateTimeImmutable
{
    if ($position === -1) {
        $last = $firstOfMonth->modify('last day of this month');
        $back = ((int) $last->format('N') - $isoWeekday + 7) % 7;

        return $last->modify("-{$back} days");
    }

    $ahead = ($isoWeekday - (int) $firstOfMonth->format('N') + 7) % 7;
    $day   = $firstOfMonth->modify('+' . ($ahead + 7 * ($position - 1)) . ' days');

    return $day->format('m') === $firstOfMonth->format('m') ? $day : null;
}
