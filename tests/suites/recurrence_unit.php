<?php
/**
 * Wiederholungsregeln der Terminserien, ohne Datenbank (FI-7).
 *
 * Spec: docs/superpowers/specs/2026-09-18-terminserien-feiertage-design.md
 *
 * Alle erwarteten Daten sind am Kalender nachgezaehlt: 01.10.2026 ist ein
 * Donnerstag, 01.01.2027 ein Freitag, 01.02.2027 ein Montag, 29.02.2028 ein
 * Dienstag.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/recurrence.php';

// ---- parseRrule / buildRrule -------------------------------------------------

test('parseRrule liest eine Wochenregel und sortiert die Tage', function () {
    assertSame(['freq' => 'WEEKLY', 'interval' => 2, 'byday' => ['TU', 'TH']],
        parseRrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=TH,TU'));
});

test('parseRrule: fehlendes INTERVAL gilt als 1', function () {
    assertSame(['freq' => 'WEEKLY', 'interval' => 1, 'byday' => ['FR']],
        parseRrule('FREQ=WEEKLY;BYDAY=FR'));
});

test('parseRrule liest eine Monatsregel nach Position', function () {
    assertSame(['freq' => 'MONTHLY', 'interval' => 1, 'byday' => ['-1WE']],
        parseRrule('FREQ=MONTHLY;BYDAY=-1WE'));
    assertSame(['freq' => 'MONTHLY', 'interval' => 1, 'byday' => ['2TU']],
        parseRrule('FREQ=MONTHLY;INTERVAL=1;BYDAY=2TU'));
});

test('parseRrule lehnt alles ausserhalb der Teilmenge ab', function () {
    $bad = [
        '', 'FREQ=DAILY', 'FREQ=YEARLY;BYDAY=MO', 'FREQ=WEEKLY', 'FREQ=WEEKLY;BYDAY=',
        'FREQ=WEEKLY;INTERVAL=5;BYDAY=MO', 'FREQ=WEEKLY;INTERVAL=0;BYDAY=MO',
        'FREQ=WEEKLY;INTERVAL=x;BYDAY=MO', 'FREQ=WEEKLY;BYDAY=MO,MO', 'FREQ=WEEKLY;BYDAY=XX',
        'FREQ=WEEKLY;BYDAY=1MO', 'FREQ=WEEKLY;BYDAY=MO;UNTIL=20270101',
        'FREQ=WEEKLY;BYDAY=MO;COUNT=3', 'FREQ=MONTHLY;BYDAY=5TU', 'FREQ=MONTHLY;BYDAY=TU',
        'FREQ=MONTHLY;BYDAY=1TU,3TU', 'FREQ=MONTHLY;INTERVAL=2;BYDAY=1TU',
        'FREQ=MONTHLY;BYMONTHDAY=15', 'FREQ=WEEKLY;FREQ=WEEKLY;BYDAY=MO', 'FREQ',
    ];
    foreach ($bad as $rule) {
        assertThrows(fn () => parseRrule($rule), "nicht abgelehnt: '{$rule}'");
    }
});

test('buildRrule schreibt kanonisch und ist verlustfrei', function () {
    assertSame('FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,TH',
        buildRrule(['freq' => 'WEEKLY', 'interval' => 1, 'byday' => ['TH', 'MO']]));
    foreach (['FREQ=WEEKLY;INTERVAL=3;BYDAY=SA', 'FREQ=MONTHLY;INTERVAL=1;BYDAY=-1WE'] as $rule) {
        assertSame($rule, buildRrule(parseRrule($rule)));
    }
    assertThrows(fn () => buildRrule(['freq' => 'DAILY', 'interval' => 1, 'byday' => []]));
});

test('buildRrule lehnt eine falsche Struktur ab, statt einen TypeError zu werfen', function () {
    assertThrows(fn () => buildRrule(['freq' => 'WEEKLY', 'byday' => 'MO']));
});

// ---- expandOccurrences: woechentlich ------------------------------------------

test('Woechentlich dienstags', function () {
    assertSame(['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27'],
        expandOccurrences(parseRrule('FREQ=WEEKLY;BYDAY=TU'), '2026-10-06', '2026-10-27'));
});

test('Mehrere Tage, Beginn mitten in der Woche', function () {
    assertSame(['2026-10-08', '2026-10-13', '2026-10-15'],
        expandOccurrences(parseRrule('FREQ=WEEKLY;BYDAY=TU,TH'), '2026-10-07', '2026-10-16'));
});

test('Alle zwei Wochen, gezaehlt ab der Woche des Beginns', function () {
    assertSame(['2026-10-10', '2026-10-24', '2026-11-07'],
        expandOccurrences(parseRrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=SA'), '2026-10-05', '2026-11-07'));
});

test('Alle zwei Wochen: Beginn am Sonntag, Montag der Beginnwoche liegt davor', function () {
    assertSame(['2026-10-19', '2026-11-02'],
        expandOccurrences(parseRrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO'), '2026-10-11', '2026-11-02'));
});

test('exdates fallen heraus', function () {
    assertSame(['2026-10-06', '2026-10-20', '2026-10-27'],
        expandOccurrences(parseRrule('FREQ=WEEKLY;BYDAY=TU'), '2026-10-06', '2026-10-27', ['2026-10-13']));
});

test('Serie fortsetzen: der Anker bindet den Wochentakt an den urspruenglichen Beginn', function () {
    assertSame(['2026-11-07', '2026-11-21'],
        expandOccurrences(parseRrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=SA'), '2026-10-31', '2026-11-30', [], '2026-10-05'));
});

test('Ohne Anker bindet sich der Wochentakt an den Beginn selbst', function () {
    assertSame(['2026-10-31', '2026-11-14', '2026-11-28'],
        expandOccurrences(parseRrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=SA'), '2026-10-31', '2026-11-30'));
});

test('Ein Anker nach dem Beginn wird abgelehnt', function () {
    assertThrows(fn () => expandOccurrences(
        parseRrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=SA'), '2026-10-31', '2026-11-30', [], '2026-11-01'
    ));
});

test('Zeitumstellung verschiebt keinen Tag', function () {
    $previousTz = date_default_timezone_get();
    date_default_timezone_set('Europe/Berlin');
    try {
        assertSame(['2026-10-18', '2026-10-25', '2026-11-01'],
            expandOccurrences(parseRrule('FREQ=WEEKLY;BYDAY=SU'), '2026-10-18', '2026-11-01'));
        assertSame(['2027-03-21', '2027-03-28', '2027-04-04'],
            expandOccurrences(parseRrule('FREQ=WEEKLY;BYDAY=SU'), '2027-03-21', '2027-04-04'));
    } finally {
        date_default_timezone_set($previousTz);
    }
});

// ---- expandOccurrences: monatlich ---------------------------------------------

test('Jeden ersten Freitag, ueber den Jahreswechsel', function () {
    assertSame(['2026-10-02', '2026-11-06', '2026-12-04', '2027-01-01'],
        expandOccurrences(parseRrule('FREQ=MONTHLY;BYDAY=1FR'), '2026-10-01', '2027-01-31'));
});

test('Jeden letzten Mittwoch', function () {
    assertSame(['2026-10-28', '2026-11-25', '2026-12-30'],
        expandOccurrences(parseRrule('FREQ=MONTHLY;BYDAY=-1WE'), '2026-10-01', '2026-12-31'));
});

test('Monatstermin vor dem Beginn faellt weg', function () {
    assertSame(['2026-11-06'],
        expandOccurrences(parseRrule('FREQ=MONTHLY;BYDAY=1FR'), '2026-10-03', '2026-11-30'));
});

test('Vierter Montag und letzter Dienstag im Schaltjahr', function () {
    assertSame(['2027-02-22'],
        expandOccurrences(parseRrule('FREQ=MONTHLY;BYDAY=4MO'), '2027-02-01', '2027-02-28'));
    assertSame(['2028-02-29'],
        expandOccurrences(parseRrule('FREQ=MONTHLY;BYDAY=-1TU'), '2028-02-01', '2028-02-29'));
});

// ---- Grenzen -------------------------------------------------------------------

test('Ende vor Beginn ergibt nichts', function () {
    assertSame([], expandOccurrences(parseRrule('FREQ=WEEKLY;BYDAY=TU'), '2026-10-06', '2026-10-05'));
});

test('expandOccurrences lehnt eine unbekannte Frequenz ab', function () {
    assertThrows(fn () => expandOccurrences(
        ['freq' => 'DAILY', 'interval' => 1, 'byday' => ['MO']], '2026-10-06', '2026-10-27'
    ));
});

test('Ungueltige Daten werden abgelehnt', function () {
    assertThrows(fn () => expandOccurrences(parseRrule('FREQ=WEEKLY;BYDAY=TU'), '2026-02-30', '2026-03-31'));
    assertThrows(fn () => expandOccurrences(parseRrule('FREQ=WEEKLY;BYDAY=TU'), '06.10.2026', '2026-10-27'));
    assertSame(false, seriesIsValidDate('2026-13-01'));
    assertSame(true, seriesIsValidDate('2028-02-29'));
});

test('seriesMaxUntil: zwoelf Monate, am Monatsende gekappt', function () {
    assertSame('2027-10-06', seriesMaxUntil('2026-10-06'));
    assertSame('2029-02-28', seriesMaxUntil('2028-02-29'));
});

test('seriesAddDays rechnet ueber Monats- und Jahresgrenzen', function () {
    assertSame('2027-01-01', seriesAddDays('2026-12-31', 1));
    assertSame('2026-02-28', seriesAddDays('2026-03-01', -1));
});
