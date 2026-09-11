<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/handlers/report_statistics.php';

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
