<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/handlers/report_statistics.php';
require_once __DIR__ . '/../../private/helpers/report.php';

// ---- statisticsReportGroupSection -------------------------------------------

test('statisticsReportGroupSection baut je Terminart eine Spalte', function () {
    $section = statisticsReportGroupSection(gruppeMitTypen());

    assertSame('Jugend', $section['heading']);
    assertSame(
        ['Mitglied', 'Termine', 'Anwesend', 'Entschuldigt', 'Unentschuldigt', 'Quote',
         'Gesamtprobe', 'Auftritt'],
        $section['columns']
    );
});

test('statisticsReportGroupSection zeigt einen Strich statt null Prozent', function () {
    // Null Prozent von null Terminen ist keine Quote, sondern fehlende Daten.
    // Auf einem Nachweis liest sich "0 %" wie ein Vorwurf.
    $section = statisticsReportGroupSection(gruppeMitTypen());
    $zeile   = $section['rows'][0];

    // Spalte 6 ist die erste Terminart (Gesamtprobe, 10 Termine),
    // Spalte 7 die zweite (Auftritt, keine Termine).
    assertSame('80,0 %', $zeile[6]);
    assertSame('–',      $zeile[7]);
});

test('statisticsReportGroupSection gruppiert die Spaltenkoepfe', function () {
    $section = statisticsReportGroupSection(gruppeMitTypen());

    assertTrue(isset($section['column_groups']), 'Spaltengruppen erwartet');

    $span = 0;
    foreach ($section['column_groups'] as $group) {
        $span += $group['span'];
    }

    // Die Gruppenzeile muss genau ueber der Tabelle liegen. Deckt sie zu
    // wenige oder zu viele Spalten ab, verschiebt sich jede Ueberschrift.
    assertSame(count($section['columns']), $span);
});

test('statisticsReportGroupSection benennt die Terminartspalten als Quoten', function () {
    $section = statisticsReportGroupSection(gruppeMitTypen());
    $labels  = array_map(fn(array $g): string => $g['label'], $section['column_groups']);

    assertTrue(in_array('Quote je Terminart', $labels, true),
        'ohne diese Ueberschrift stehen die Terminartspalten unbeschriftet da');
});

// ---- renderReport: Vorabpruefung der Spaltengruppen -------------------------

test('renderReport wirft bei falscher Spannweite der Spaltengruppen', function () {
    // Die Pruefung liegt in der Vorabschleife, vor jeder Ausgabe und vor dem
    // ersten Zugriff auf $db/$database (Branding wird erst danach geladen) --
    // deshalb ohne Datenbank und ohne abgefangene Ausgabe pruefbar. $db und
    // $database bleiben null: Ein Fehler, der sie doch anfasst, wuerde als
    // TypeError durchschlagen statt als grosszuegig gruener Test.
    $report = [
        'title'    => 'Test',
        'period'   => 'Jahr 2026',
        'sections' => [[
            'columns'       => ['Mitglied', 'Termine'],
            'column_groups' => [
                ['label' => '', 'span' => 1],
                ['label' => 'Anwesenheit', 'span' => 5],
            ],
            'rows' => [],
        ]],
        'notes' => [],
    ];

    assertThrows(
        fn() => renderReport(null, null, $report),
        'Spannweite 6 gegen 2 Spalten muss abgelehnt werden'
    );
});

/** Eine Gruppe mit einer belegten und einer unbelegten Terminart. */
function gruppeMitTypen(): array
{
    return [
        'group_id'          => 2,
        'group_name'        => 'Jugend',
        'appointment_types' => [
            ['type_id' => 1, 'type_name' => 'Gesamtprobe'],
            ['type_id' => 3, 'type_name' => 'Auftritt'],
        ],
        'members' => [[
            'member_id'          => 5,
            'member_name'        => 'Bauer, Anna',
            'total_appointments' => 10,
            'attended'           => 8,
            'excused'            => 1,
            'unexcused_absences' => 1,
            'attendance_rate'    => 80.0,
            'by_type' => [
                ['type_id' => 1, 'type_name' => 'Gesamtprobe',
                 'total_appointments' => 10, 'attended' => 8, 'excused' => 1,
                 'unexcused_absences' => 1, 'attendance_rate' => 80.0],
                ['type_id' => 3, 'type_name' => 'Auftritt',
                 'total_appointments' => 0, 'attended' => 0, 'excused' => 0,
                 'unexcused_absences' => 0, 'attendance_rate' => 0.0],
            ],
        ]],
    ];
}

// ---- statisticsReportOrigin -------------------------------------------------

test('statisticsReportOrigin liest die Herkunft aus der Quelle', function () {
    // Seit 1.5.0 sagt checkin_source die Wahrheit: Ein genehmigter Antrag
    // traegt exception_request. Der frueher noetige Zaehler aus einem Join auf
    // exceptions entfaellt damit -- er war die Uebergangsloesung, solange
    // handleApprovedTimeCorrection() die Quelle unveraendert liess.
    assertSame(REPORT_ORIGIN_CORRECTED,
        statisticsReportOrigin('exception_request', '2031-03-04 19:55:00'));

    assertSame(REPORT_ORIGIN_MEASURED,
        statisticsReportOrigin('station_pin', '2031-03-04 19:58:00'));
    assertSame(REPORT_ORIGIN_MEASURED,
        statisticsReportOrigin('auto_checkin', '2031-03-04 19:58:00'));

    // Vom Admin eingetippt: eine Aussage, aber keine Messung.
    assertSame(REPORT_ORIGIN_BACKFILLED,
        statisticsReportOrigin('admin', '2031-03-04 20:05:00'));
});

test('statisticsReportOrigin nennt eine fehlende Uhrzeit nicht gemessen', function () {
    // Der Fall, den es vor 1.5.0 nicht geben konnte: kein Stempel, keine
    // Uhrzeit. Eine Quelle allein macht daraus keine Messung.
    assertSame(REPORT_ORIGIN_BACKFILLED, statisticsReportOrigin('admin', null));
    assertSame(REPORT_ORIGIN_BACKFILLED, statisticsReportOrigin('station_pin', null));
});

// ---- statisticsReportSummarySection: Puenktlichkeit und Zuverlaessigkeit ----

function puSummary(): array
{
    return ['total_appointments' => 22, 'total_present' => 15, 'total_excused' => 4,
            'total_unexcused' => 3, 'overall_average' => 68.2];
}

test('statisticsReportSummarySection bleibt ohne Kennzahlen wie bisher', function () {
    // Additiv: Ein aelterer Ausdruck darf durch 1.5.1 nicht falsch werden.
    assertSame(5, count(statisticsReportSummarySection(puSummary())['rows']));
    assertSame(5, count(statisticsReportSummarySection(
        puSummary(), ['enabled' => false], ['enabled' => false])['rows']));
});

test('statisticsReportSummarySection ergaenzt Puenktlichkeit mit Bezugsgroessen', function () {
    $p = ['enabled' => true, 'sufficient' => true, 'min_measurements' => 5,
          'measured_count' => 15, 'total_count' => 22, 'on_time_count' => 12, 'rate' => 80.0,
          'late_count' => 3, 'avg_late_minutes' => 7.3, 'self_reported_count' => 1];

    $rows = statisticsReportSummarySection(puSummary(), $p, ['enabled' => false])['rows'];

    assertTrue(in_array(['Pünktlichkeit', 'Pünktlich bei 12 von 15 gemessenen Ankünften (80,0 %)'], $rows, true));
    assertTrue(in_array(['Messabdeckung', 'Gemessen bei 15 von 22 Terminen'], $rows, true));
    assertTrue(in_array(['Verspätung', 'Wenn zu spät, dann im Schnitt 7,3 Minuten'], $rows, true));
});

test('statisticsReportSummarySection meldet zu wenige Messungen statt einer Quote', function () {
    $p = ['enabled' => true, 'sufficient' => false, 'min_measurements' => 5,
          'measured_count' => 3, 'total_count' => 22, 'on_time_count' => 3, 'rate' => null,
          'late_count' => 0, 'avg_late_minutes' => null, 'self_reported_count' => 0];

    $rows = statisticsReportSummarySection(puSummary(), $p, ['enabled' => false])['rows'];

    assertTrue(in_array(['Pünktlichkeit', 'Zu wenige Messungen (3 von mindestens 5)'], $rows, true));
    foreach ($rows as $row) {
        assertTrue($row[0] !== 'Verspätung', 'Ohne Quote auch keine Verspaetungszeile');
    }
});

test('statisticsReportSummarySection ergaenzt die Zuverlaessigkeit', function () {
    $r = ['enabled' => true, 'total' => 22, 'appeared' => 15, 'excused_in_time' => 4,
          'missed' => 3, 'rate' => 86.4];

    $rows = statisticsReportSummarySection(puSummary(), ['enabled' => false], $r)['rows'];

    assertTrue(in_array(['Zuverlässigkeit', 'Erschienen oder rechtzeitig abgemeldet: 19 von 22 (86,4 %)'], $rows, true));
});

test('statisticsReportSummarySection nennt ohne Termine keine fehlenden Messungen', function () {
    // "Zu wenige Messungen (0 von mindestens 5)" klingt nach einer
    // Erfassungsluecke, wo es schlicht keine Termine gab.
    $p = ['enabled' => true, 'sufficient' => false, 'min_measurements' => 5,
          'measured_count' => 0, 'total_count' => 0, 'on_time_count' => 0, 'rate' => null,
          'late_count' => 0, 'avg_late_minutes' => null, 'self_reported_count' => 0];

    $rows = statisticsReportSummarySection(puSummary(), $p, ['enabled' => false])['rows'];

    assertTrue(in_array(['Pünktlichkeit', 'Keine Termine im gewählten Zeitraum'], $rows, true));
    foreach ($rows as $row) {
        assertTrue($row[0] !== 'Messabdeckung', 'Ohne Termine keine Messabdeckung "0 von 0"');
    }
});
