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

/** Ableitung der Gruppe eines Registers (Spec 2026-10-02, Abschnitt 8). */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/subgroup_parent.php';

test('subgroupParentInfer: eindeutige gemeinsame Gruppe wird zugeordnet', function () {
    // Register 10: Mitglieder 1 und 2, beide in Gruppe 100 (dazu 2 in 200).
    $membersBySubgroup = [10 => [1, 2]];
    $ordinaryByMember  = [1 => [100], 2 => [100, 200]];
    assertSame([10 => 100], subgroupParentInfer($membersBySubgroup, $ordinaryByMember));
});

test('subgroupParentInfer: mehrdeutig oder ohne gemeinsame Gruppe bleibt offen', function () {
    // Register 11: beide in 100 UND 200 -> zwei gemeinsame Gruppen, mehrdeutig.
    // Register 12: 1 in 100, 3 in 300 -> keine gemeinsame Gruppe.
    $membersBySubgroup = [11 => [1, 2], 12 => [1, 3]];
    $ordinaryByMember  = [1 => [100, 200], 2 => [100, 200], 3 => [300]];
    assertSame([], subgroupParentInfer($membersBySubgroup, $ordinaryByMember));
});

test('subgroupParentInfer: Register ohne Mitglieder bleibt offen', function () {
    assertSame([], subgroupParentInfer([13 => []], []));
});

test('subgroupParentInfer: Mitglied ohne gewoehnliche Gruppe verhindert die Zuordnung', function () {
    assertSame([], subgroupParentInfer([14 => [1, 4]], [1 => [100]]));
});
