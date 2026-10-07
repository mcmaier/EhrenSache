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
 * Kalender-Abo (FI-8): ICS-Erzeugung ohne Datenbank.
 */
require_once __DIR__ . '/../../private/helpers/ical.php';

function icRow(array $over = []): array
{
    return array_merge([
        'appointment_id'    => 42,
        'title'             => 'Probe',
        'description'       => null,
        'location'          => null,
        'date'              => '2026-11-12',
        'start_time'        => '19:30:00',
        'end_time'          => '21:30:00',
        'type_name'         => 'Gesamtprobe',
        'responses_enabled' => 1,
        'response_status'   => null,
        'response_comment'  => null,
    ], $over);
}

/** Entfaltet eine ICS-Ausgabe (RFC 5545, 3.1). */
function icUnfold(string $ics): string
{
    return str_replace("\r\n ", '', $ics);
}

/** @return string[] entfaltete Zeilen des ersten VEVENT */
function icEventLines(array $row): array
{
    return icalBuildEvent($row, 'verein.example', '20261007T120000Z');
}

function icLine(array $lines, string $name): ?string
{
    foreach ($lines as $l) {
        if (strpos($l, $name . ':') === 0 || strpos($l, $name . ';') === 0) {
            return $l;
        }
    }

    return null;
}

test('icalEscapeText maskiert Backslash, Semikolon, Komma und Zeilenumbruch', function () {
    assertSame('a\\\\b\\;c\\,d\\ne\\nf', icalEscapeText("a\\b;c,d\r\ne\nf"));
});

test('icalFoldLine laesst kurze Zeilen unveraendert', function () {
    $line = 'SUMMARY:' . str_repeat('x', 67);
    assertSame(75, strlen($line));
    assertSame($line, icalFoldLine($line));
});

test('icalFoldLine faltet nach 75 Bytes und zerschneidet keine Umlaute', function () {
    $line = 'DESCRIPTION:' . str_repeat('ä', 80);
    $folded = icalFoldLine($line);
    foreach (explode("\r\n", $folded) as $i => $physisch) {
        assertTrue(strlen($physisch) <= 75, "Zeile {$i} hat " . strlen($physisch) . ' Bytes');
        assertTrue(preg_match('//u', $physisch) === 1, "Zeile {$i} ist kein gueltiges UTF-8");
        if ($i > 0) {
            assertSame(' ', $physisch[0], "Folgezeile {$i} beginnt nicht mit Leerzeichen");
        }
    }
    assertSame($line, icUnfold($folded));
});

test('icalFoldLine schiebt ein Emoji an der Grenze ganz in die Folgezeile', function () {
    $line = 'SUMMARY:' . str_repeat('x', 65) . '🎺yyy';
    $teile = explode("\r\n", icalFoldLine($line));
    assertSame('SUMMARY:' . str_repeat('x', 65), $teile[0]);
    assertSame(' 🎺yyy', $teile[1]);
});

test('VEVENT: UID, DTSTAMP, DTSTART und DTEND mit Zeitzone', function () {
    $l = icEventLines(icRow());
    assertSame('UID:appointment-42@verein.example', icLine($l, 'UID'));
    assertSame('DTSTAMP:20261007T120000Z', icLine($l, 'DTSTAMP'));
    assertSame('DTSTART;TZID=Europe/Berlin:20261112T193000', icLine($l, 'DTSTART'));
    assertSame('DTEND;TZID=Europe/Berlin:20261112T213000', icLine($l, 'DTEND'));
    assertSame('BEGIN:VEVENT', $l[0]);
    assertSame('END:VEVENT', $l[count($l) - 1]);
});

test('VEVENT: Zeiten ohne Sekunden werden ergaenzt', function () {
    $l = icEventLines(icRow(['start_time' => '19:30', 'end_time' => '21:00']));
    assertSame('DTSTART;TZID=Europe/Berlin:20261112T193000', icLine($l, 'DTSTART'));
    assertSame('DTEND;TZID=Europe/Berlin:20261112T210000', icLine($l, 'DTEND'));
});

test('VEVENT: Ende vor oder gleich Beginn liegt am Folgetag', function () {
    $l = icEventLines(icRow(['date' => '2026-12-31', 'start_time' => '20:00:00', 'end_time' => '01:00:00']));
    assertSame('DTEND;TZID=Europe/Berlin:20270101T010000', icLine($l, 'DTEND'));
    $l = icEventLines(icRow(['start_time' => '20:00:00', 'end_time' => '20:00:00']));
    assertSame('DTEND;TZID=Europe/Berlin:20261113T200000', icLine($l, 'DTEND'));
});

test('VEVENT: ohne Ende kein DTEND', function () {
    assertSame(null, icLine(icEventLines(icRow(['end_time' => null])), 'DTEND'));
    assertSame(null, icLine(icEventLines(icRow(['end_time' => ''])), 'DTEND'));
});

test('VEVENT: Praefix der eigenen Rueckmeldung', function () {
    assertSame('SUMMARY:✓ Probe', icLine(icEventLines(icRow(['response_status' => 'yes'])), 'SUMMARY'));
    assertSame('SUMMARY:✗ Probe', icLine(icEventLines(icRow(['response_status' => 'no'])), 'SUMMARY'));
    assertSame('SUMMARY:? Probe', icLine(icEventLines(icRow(['response_status' => 'maybe'])), 'SUMMARY'));
    assertSame('SUMMARY:Probe', icLine(icEventLines(icRow()), 'SUMMARY'));
});

test('VEVENT: kein Praefix, wenn die Terminart keine Rueckmeldungen kennt', function () {
    $l = icEventLines(icRow(['response_status' => 'yes', 'responses_enabled' => 0]));
    assertSame('SUMMARY:Probe', icLine($l, 'SUMMARY'));
    assertTrue(strpos((string) icLine($l, 'DESCRIPTION'), 'Rückmeldung') === false,
        'Rueckmeldung darf ohne responses_enabled nicht in der Beschreibung stehen');
});

test('VEVENT: LOCATION und CATEGORIES nur, wenn gesetzt', function () {
    $l = icEventLines(icRow(['location' => 'Probenlokal, Saal 2']));
    assertSame('LOCATION:Probenlokal\\, Saal 2', icLine($l, 'LOCATION'));
    assertSame('CATEGORIES:Gesamtprobe', icLine($l, 'CATEGORIES'));
    $l = icEventLines(icRow(['location' => null, 'type_name' => null]));
    assertSame(null, icLine($l, 'LOCATION'));
    assertSame(null, icLine($l, 'CATEGORIES'));
});

test('VEVENT: DESCRIPTION aus Beschreibung, Terminart und eigener Rueckmeldung', function () {
    $l = icEventLines(icRow([
        'description'      => "Noten mitbringen",
        'response_status'  => 'no',
        'response_comment' => 'Urlaub',
    ]));
    assertSame('DESCRIPTION:Noten mitbringen\\n\\nTerminart: Gesamtprobe\\nDeine Rückmeldung: Abgesagt – Urlaub',
        icLine($l, 'DESCRIPTION'));
});

test('VEVENT: ohne Beschreibung, Terminart und Rueckmeldung keine DESCRIPTION', function () {
    assertSame(null, icLine(icEventLines(icRow(['type_name' => null])), 'DESCRIPTION'));
});

test('VCALENDAR: Kopf, VTIMEZONE, CRLF und abschliessendes CRLF', function () {
    $ics = icalBuildCalendar('MV Beispiel – Termine', [icRow()], 'verein.example',
        new DateTimeImmutable('2026-10-07 12:00:00', new DateTimeZone('UTC')));
    assertSame(0, preg_match("/(?<!\r)\n/", $ics), 'Zeilenenden muessen CRLF sein');
    assertTrue(substr($ics, -2) === "\r\n", 'Ausgabe endet nicht mit CRLF');
    $u = icUnfold($ics);
    foreach (['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//EhrenSache//Kalender-Abo//DE',
              'METHOD:PUBLISH', 'X-WR-CALNAME:MV Beispiel – Termine', 'X-WR-TIMEZONE:Europe/Berlin',
              'BEGIN:VTIMEZONE', 'TZID:Europe/Berlin', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
              'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:VTIMEZONE',
              'DTSTAMP:20261007T120000Z', 'END:VCALENDAR',
              'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'DTSTART:19700329T020000',
              'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'DTSTART:19701025T030000'] as $erwartet) {
        assertTrue(strpos($u, $erwartet . "\r\n") !== false, "Zeile fehlt: {$erwartet}");
    }
    assertSame(1, substr_count($u, 'BEGIN:VEVENT'));
});

test('VCALENDAR: ohne Termine gueltig und ohne VEVENT', function () {
    $ics = icalBuildCalendar('Termine', [], 'verein.example', new DateTimeImmutable('now', new DateTimeZone('UTC')));
    assertTrue(strpos($ics, 'BEGIN:VCALENDAR') === 0);
    assertSame(0, substr_count($ics, 'BEGIN:VEVENT'));
    assertTrue(strpos($ics, "END:VCALENDAR\r\n") !== false);
});

test('VCALENDAR: Kalendername wird maskiert', function () {
    $ics = icalBuildCalendar('A, B; C', [], 'verein.example', new DateTimeImmutable('now', new DateTimeZone('UTC')));
    assertTrue(strpos($ics, "X-WR-CALNAME:A\\, B\\; C\r\n") !== false);
});

test('icalFoldLine: ungueltiges UTF-8 liefert gueltigen, gefalteten Text', function () {
    $folded = icalFoldLine('DESCRIPTION:' . str_repeat('a', 80) . "\xE4");
    assertTrue($folded !== '', 'Ausgabe ist leer');
    assertTrue(preg_match('//u', $folded) === 1, 'Ausgabe ist kein gueltiges UTF-8');
    foreach (explode("\r\n", $folded) as $i => $physisch) {
        assertTrue(strlen($physisch) <= 75, "Zeile {$i} hat " . strlen($physisch) . ' Bytes');
    }
});

test('icalEscapeText: ungueltiges UTF-8 wird bereinigt', function () {
    assertTrue(preg_match('//u', icalEscapeText("a\xE4b")) === 1);
});

test('icalEscapeText: Steuerzeichen entfernt, Tabulator bleibt', function () {
    assertSame("ab\tc", icalEscapeText("a\x01b\tc"));
});

test('icalLocalDateTime: Uhrzeiten werden normalisiert', function () {
    assertSame('20261112T073000', icalLocalDateTime('2026-11-12', '7:30'));
    assertSame('20261112T193000', icalLocalDateTime('2026-11-12', '19:30:00.000000'));
    assertSame('20261112T090000', icalLocalDateTime('2026-11-12', '9:00:00'));
    assertThrows(function () {
        icalLocalDateTime('2026-11-12', 'abc');
    });
});

test('icalFoldLine: Zeile mit 76 Bytes wird gefaltet', function () {
    $line = 'SUMMARY:' . str_repeat('x', 68);
    assertSame(76, strlen($line));
    $teile = explode("\r\n", icalFoldLine($line));
    assertSame(2, count($teile));
    assertSame(75, strlen($teile[0]));
});

test('VEVENT: CATEGORIES maskiert Komma', function () {
    assertSame('CATEGORIES:Sport\, Spiel', icLine(icEventLines(icRow(['type_name' => 'Sport, Spiel'])), 'CATEGORIES'));
});

test('VEVENT: ohne responses_enabled auch fuer no und maybe kein Praefix', function () {
    foreach (['no', 'maybe'] as $st) {
        $l = icEventLines(icRow(['response_status' => $st, 'responses_enabled' => 0]));
        assertSame('SUMMARY:Probe', icLine($l, 'SUMMARY'));
    }
});
