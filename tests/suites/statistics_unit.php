<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/attendance.php';

// ---- attendanceBuildGroup ---------------------------------------------------

test('attendanceBuildGroup summiert ueber die Terminarten', function () {
    $types = [
        ['type_id' => 1, 'type_name' => 'Gesamtprobe'],
        ['type_id' => 2, 'type_name' => 'Registerprobe'],
    ];
    $rows = [
        ['member_id' => 5, 'name' => 'Anna', 'surname' => 'Bauer',
         'type_id' => 1, 'total' => 10, 'attended' => 8, 'unexcused' => 1],
        ['member_id' => 5, 'name' => 'Anna', 'surname' => 'Bauer',
         'type_id' => 2, 'total' => 4,  'attended' => 1, 'unexcused' => 3],
    ];

    $group = attendanceBuildGroup(7, 'Aktive', $types, $rows);

    assertSame(7, $group['group_id']);
    assertSame('Aktive', $group['group_name']);
    // Ein Mitglied aus zwei Typzeilen -- nicht zwei Mitglieder.
    assertSame(1, count($group['members']));
});

test('attendanceBuildGroup bildet Gesamt- und Typwerte', function () {
    $types = [
        ['type_id' => 1, 'type_name' => 'Gesamtprobe'],
        ['type_id' => 2, 'type_name' => 'Registerprobe'],
    ];
    $rows = [
        ['member_id' => 5, 'name' => 'Anna', 'surname' => 'Bauer',
         'type_id' => 1, 'total' => 10, 'attended' => 8, 'unexcused' => 1],
        ['member_id' => 5, 'name' => 'Anna', 'surname' => 'Bauer',
         'type_id' => 2, 'total' => 4,  'attended' => 1, 'unexcused' => 3],
    ];

    $member = attendanceBuildGroup(7, 'Aktive', $types, $rows)['members'][0];

    assertSame('Bauer, Anna', $member['member_name']);
    assertSame(14, $member['total_appointments']);
    assertSame(9,  $member['attended']);
    assertSame(4,  $member['unexcused_absences']);
    // excused = total - attended - unexcused, je Typ gerechnet und summiert
    assertSame(1,  $member['excused']);
    assertSame(64.3, $member['attendance_rate']);

    assertSame(2, count($member['by_type']));
    assertSame('Gesamtprobe', $member['by_type'][0]['type_name']);
    assertSame(80.0, $member['by_type'][0]['attendance_rate']);
    assertSame(25.0, $member['by_type'][1]['attendance_rate']);
});

test('attendanceBuildGroup fuellt eine Terminart ohne Termine mit Nullen', function () {
    $types = [
        ['type_id' => 1, 'type_name' => 'Gesamtprobe'],
        ['type_id' => 9, 'type_name' => 'Auftritt'],
    ];
    $rows = [
        ['member_id' => 5, 'name' => 'Anna', 'surname' => 'Bauer',
         'type_id' => 1, 'total' => 10, 'attended' => 8, 'unexcused' => 2],
    ];

    $member = attendanceBuildGroup(7, 'Aktive', $types, $rows)['members'][0];

    assertSame(2, count($member['by_type']));
    assertSame(9, $member['by_type'][1]['type_id']);
    assertSame(0, $member['by_type'][1]['total_appointments']);
    assertSame(0, $member['by_type'][1]['attended']);
    assertSame(0.0, $member['by_type'][1]['attendance_rate']);
});

test('attendanceBuildGroup haelt die Reihenfolge der Typenliste', function () {
    $types = [
        ['type_id' => 3, 'type_name' => 'Auftritt'],
        ['type_id' => 1, 'type_name' => 'Gesamtprobe'],
    ];
    // Zeilen absichtlich in anderer Reihenfolge als die Typenliste
    $rows = [
        ['member_id' => 5, 'name' => 'Anna', 'surname' => 'Bauer',
         'type_id' => 1, 'total' => 10, 'attended' => 5, 'unexcused' => 5],
        ['member_id' => 5, 'name' => 'Anna', 'surname' => 'Bauer',
         'type_id' => 3, 'total' => 2,  'attended' => 2, 'unexcused' => 0],
    ];

    $member = attendanceBuildGroup(7, 'Aktive', $types, $rows)['members'][0];

    assertSame(3, $member['by_type'][0]['type_id']);
    assertSame(1, $member['by_type'][1]['type_id']);
});

test('attendanceBuildGroup ohne Zeilen liefert keine Mitglieder', function () {
    $group = attendanceBuildGroup(7, 'Aktive', [['type_id' => 1, 'type_name' => 'Probe']], []);
    assertSame([], $group['members']);
});

test('attendanceBuildGroup ohne Terminart liefert keine Mitglieder', function () {
    $group = attendanceBuildGroup(7, 'Ehrenmitglieder', [], []);
    assertSame([], $group['members']);
    assertSame([], $group['appointment_types']);
});

test('attendanceBuildGroup teilt nicht durch null', function () {
    $types = [['type_id' => 1, 'type_name' => 'Probe']];
    $rows  = [['member_id' => 5, 'name' => 'Anna', 'surname' => 'Bauer',
               'type_id' => 1, 'total' => 0, 'attended' => 0, 'unexcused' => 0]];

    $member = attendanceBuildGroup(7, 'Aktive', $types, $rows)['members'][0];

    assertSame(0.0, $member['attendance_rate']);
    assertSame(0.0, $member['by_type'][0]['attendance_rate']);
});

test('attendanceBuildGroup haelt mehrere Mitglieder in Zeilenreihenfolge', function () {
    $types = [['type_id' => 1, 'type_name' => 'Probe']];
    $rows  = [
        ['member_id' => 2, 'name' => 'Anna',  'surname' => 'Bauer',
         'type_id' => 1, 'total' => 4, 'attended' => 4, 'unexcused' => 0],
        ['member_id' => 9, 'name' => 'Georg', 'surname' => 'Kaiser',
         'type_id' => 1, 'total' => 4, 'attended' => 2, 'unexcused' => 2],
    ];

    $members = attendanceBuildGroup(7, 'Aktive', $types, $rows)['members'];

    assertSame(2, count($members));
    assertSame('Bauer, Anna',   $members[0]['member_name']);
    assertSame('Kaiser, Georg', $members[1]['member_name']);
});

// ---- attendanceBuildSummary -------------------------------------------------

test('attendanceBuildSummary summiert die Mitgliedszeilen', function () {
    $memberTotals = [
        ['member_id' => 2, 'total' => 10, 'attended' => 8, 'excused' => 1],
        ['member_id' => 9, 'total' => 10, 'attended' => 5, 'excused' => 0],
    ];

    $summary = attendanceBuildSummary($memberTotals, 10, 2);

    assertSame(10, $summary['total_appointments']);
    assertSame(2,  $summary['total_members']);
    assertSame(13, $summary['total_present']);
    assertSame(1,  $summary['total_excused']);
    // unexcused = total - attended - excused, je Mitglied
    assertSame(6,  $summary['total_unexcused']);
    assertSame(65.0, $summary['overall_average']);
});

test('attendanceBuildSummary laesst unexcused nicht negativ werden', function () {
    $memberTotals = [['member_id' => 2, 'total' => 3, 'attended' => 3, 'excused' => 2]];

    $summary = attendanceBuildSummary($memberTotals, 3, 1);

    assertSame(0, $summary['total_unexcused']);
});

test('attendanceBuildSummary ohne Mitglieder teilt nicht durch null', function () {
    $summary = attendanceBuildSummary([], 0, 0);

    assertSame(0, $summary['total_appointments']);
    assertSame(0, $summary['total_present']);
    assertSame(0.0, $summary['overall_average']);
});
