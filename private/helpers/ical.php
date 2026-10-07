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

declare(strict_types=1);

/**
 * ICS-Erzeugung fuer das Kalender-Abo (FI-8), RFC 5545.
 *
 * Reine Funktionen ohne Datenbank -- tests/suites/ical_unit.php prueft sie
 * direkt. Keine Bibliothek: ICS ist Textausgabe.
 *
 * Zeitzone fest Europe/Berlin (Spec 2026-10-07, Entscheidung): Die Termine
 * stehen als Ortszeit ohne Zone in der Datenbank. Der VTIMEZONE-Block ist
 * fester Text nach der EU-Regel (letzter Sonntag im Maerz/Oktober), damit die
 * Ausgabe nicht von der Zeitzonen-Datenbank des Servers abhaengt.
 */

const ICAL_TZID = 'Europe/Berlin';

const ICAL_VTIMEZONE = [
    'BEGIN:VTIMEZONE',
    'TZID:Europe/Berlin',
    'X-LIC-LOCATION:Europe/Berlin',
    'BEGIN:DAYLIGHT',
    'TZOFFSETFROM:+0100',
    'TZOFFSETTO:+0200',
    'TZNAME:CEST',
    'DTSTART:19700329T020000',
    'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
    'END:DAYLIGHT',
    'BEGIN:STANDARD',
    'TZOFFSETFROM:+0200',
    'TZOFFSETTO:+0100',
    'TZNAME:CET',
    'DTSTART:19701025T030000',
    'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
    'END:STANDARD',
    'END:VTIMEZONE',
];

/** Praefix im Titel und Wort in der Beschreibung je eigener Rueckmeldung. */
const ICAL_RESPONSE_LABELS = [
    'yes'   => ['✓ ', 'Zugesagt'],
    'no'    => ['✗ ', 'Abgesagt'],
    'maybe' => ['? ', 'Unsicher'],
];

/** Maskiert einen TEXT-Wert (RFC 5545, 3.3.11). */
function icalEscapeText(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $text);
}

/**
 * Faltet eine Inhaltszeile nach 75 Bytes (RFC 5545, 3.1). Folgezeilen beginnen
 * mit einem Leerzeichen, das mitzaehlt. Geschnitten wird nur zwischen
 * Unicode-Zeichen, nie innerhalb einer UTF-8-Folge.
 */
function icalFoldLine(string $line): string
{
    if (strlen($line) <= 75) {
        return $line;
    }

    $teile   = [];
    $aktuell = '';
    $grenze  = 75;
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $zeichen) {
        if (strlen($aktuell) + strlen($zeichen) > $grenze) {
            $teile[] = $aktuell;
            $aktuell = '';
            $grenze  = 74;
        }
        $aktuell .= $zeichen;
    }
    $teile[] = $aktuell;

    return implode("\r\n ", $teile);
}

/** 'Y-m-d' + 'H:i[:s]' -> 'YmdTHis' */
function icalLocalDateTime(string $date, string $time): string
{
    $time = strlen($time) === 5 ? $time . ':00' : substr($time, 0, 8);

    return str_replace('-', '', $date) . 'T' . str_replace(':', '', $time);
}

/**
 * Inhaltszeilen (ungefaltet) eines VEVENT aus einer Terminzeile.
 *
 * @param array<string, mixed> $row  Spalten siehe Plan/Handler
 * @return string[]
 */
function icalBuildEvent(array $row, string $uidHost, string $dtstamp): array
{
    $date  = (string) $row['date'];
    $start = (string) $row['start_time'];
    $end   = isset($row['end_time']) && $row['end_time'] !== '' ? (string) $row['end_time'] : null;

    $status = null;
    if ((int) ($row['responses_enabled'] ?? 0) === 1 && isset(ICAL_RESPONSE_LABELS[$row['response_status'] ?? ''])) {
        $status = ICAL_RESPONSE_LABELS[$row['response_status']];
    }

    $lines = [
        'BEGIN:VEVENT',
        'UID:appointment-' . (int) $row['appointment_id'] . '@' . $uidHost,
        'DTSTAMP:' . $dtstamp,
        'DTSTART;TZID=' . ICAL_TZID . ':' . icalLocalDateTime($date, $start),
    ];

    if ($end !== null) {
        $endDate = $date;
        if (icalLocalDateTime($date, $end) <= icalLocalDateTime($date, $start)) {
            $endDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        }
        $lines[] = 'DTEND;TZID=' . ICAL_TZID . ':' . icalLocalDateTime($endDate, $end);
    }

    $lines[] = 'SUMMARY:' . icalEscapeText(($status[0] ?? '') . (string) $row['title']);

    $location = trim((string) ($row['location'] ?? ''));
    if ($location !== '') {
        $lines[] = 'LOCATION:' . icalEscapeText($location);
    }

    $typeName = trim((string) ($row['type_name'] ?? ''));
    if ($typeName !== '') {
        $lines[] = 'CATEGORIES:' . icalEscapeText($typeName);
    }

    $absaetze = [];
    $beschreibung = trim((string) ($row['description'] ?? ''));
    if ($beschreibung !== '') {
        $absaetze[] = $beschreibung;
    }
    $meta = [];
    if ($typeName !== '') {
        $meta[] = 'Terminart: ' . $typeName;
    }
    if ($status !== null) {
        $kommentar = trim((string) ($row['response_comment'] ?? ''));
        $meta[] = 'Deine Rückmeldung: ' . $status[1] . ($kommentar !== '' ? ' – ' . $kommentar : '');
    }
    if ($meta !== []) {
        $absaetze[] = implode("\n", $meta);
    }
    if ($absaetze !== []) {
        $lines[] = 'DESCRIPTION:' . icalEscapeText(implode("\n\n", $absaetze));
    }

    $lines[] = 'END:VEVENT';

    return $lines;
}

/**
 * Der ganze Kalender, gefaltet, mit CRLF und abschliessendem CRLF.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function icalBuildCalendar(string $calName, array $rows, string $uidHost, DateTimeImmutable $now): string
{
    $dtstamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//EhrenSache//Kalender-Abo//DE',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . icalEscapeText($calName),
        'X-WR-TIMEZONE:' . ICAL_TZID,
        'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        'X-PUBLISHED-TTL:PT1H',
    ];
    foreach (ICAL_VTIMEZONE as $l) {
        $lines[] = $l;
    }
    foreach ($rows as $row) {
        foreach (icalBuildEvent($row, $uidHost, $dtstamp) as $l) {
            $lines[] = $l;
        }
    }
    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", array_map('icalFoldLine', $lines)) . "\r\n";
}
