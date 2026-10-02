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

/** responsesStaffing(): Besetzung je Untergruppe (Spec 2026-10-01, 5.1). */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/groups.php';
require_once __DIR__ . '/../../private/helpers/responses.php';

function rstSub(int $id, string $name, int $sort): array
{
    return ['group_id' => $id, 'group_name' => $name, 'sort_order' => $sort];
}

test('responsesStaffing: Zaehlung je Untergruppe und Abschlusszeile', function () {
    $kla = rstSub(10, 'Klarinette', 1);
    $sax = rstSub(11, 'Saxophon', 2);
    $members = [
        ['member_id' => 1, 'subgroups' => [$kla]],
        ['member_id' => 2, 'subgroups' => [$kla]],
        ['member_id' => 3, 'subgroups' => [$kla, $sax]],
        ['member_id' => 4, 'subgroups' => [$sax]],
        ['member_id' => 5, 'subgroups' => []],
    ];
    $status = [1 => 'yes', 2 => 'maybe', 3 => 'yes', 5 => 'no'];

    assertSame([
        ['group_id' => 10, 'name' => 'Klarinette', 'expected' => 3, 'yes' => 2, 'maybe' => 1, 'no' => 0, 'open' => 0, 'shared' => 1],
        ['group_id' => 11, 'name' => 'Saxophon',   'expected' => 2, 'yes' => 1, 'maybe' => 0, 'no' => 0, 'open' => 1, 'shared' => 1],
        ['group_id' => null, 'name' => null,       'expected' => 1, 'yes' => 0, 'maybe' => 0, 'no' => 1, 'open' => 0, 'shared' => 0],
    ], responsesStaffing($members, $status));
});

test('responsesStaffing: sortiert nach sort_order, dann Name', function () {
    $members = [
        ['member_id' => 1, 'subgroups' => [rstSub(20, 'Trompete', 2)]],
        ['member_id' => 2, 'subgroups' => [rstSub(21, 'Horn', 1)]],
        ['member_id' => 3, 'subgroups' => [rstSub(22, 'Flöte', 1)]],
    ];
    $names = array_column(responsesStaffing($members, []), 'name');
    assertSame(['Flöte', 'Horn', 'Trompete'], $names);
});

test('responsesStaffing: ohne Untergruppen ein leeres Array, ohne Abschlusszeile', function () {
    $members = [['member_id' => 1, 'subgroups' => []], ['member_id' => 2]];
    assertSame([], responsesStaffing($members, [1 => 'yes']));
});

test('responsesStaffing: unbekannter Status zaehlt als offen', function () {
    $members = [['member_id' => 1, 'subgroups' => [rstSub(30, 'Tuba', 0)]]];
    $row = responsesStaffing($members, [1 => 'irgendwas'])[0];
    assertSame(1, $row['open']);
    assertSame(0, $row['yes'] + $row['maybe'] + $row['no']);
});
