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

/** Gruppenzugehoerigkeit mit Zeitraum (Spec 2026-10-05): Bausteine ohne Datenbank. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/source.php';
require_once __DIR__ . '/../../private/helpers/group_history.php';

$ghRoot = dirname(__DIR__, 2);

test('Schema: Neuinstallation kennt valid_from und member_group_history', function () use ($ghRoot) {
    $sql = (string) sourceCode($ghRoot . '/private/setup/ehrensache_db.sql');
    assertTrue(str_contains($sql, 'CREATE TABLE IF NOT EXISTS `{PREFIX}member_group_history`'), 'Tabelle member_group_history fehlt im Setup');
    assertTrue(preg_match('/member_group_assignments` \(\s*member_id INT NOT NULL,\s*group_id INT NOT NULL,\s*valid_from DATE NULL,/', $sql) === 1,
        'member_group_assignments hat keine Spalte valid_from');
    assertTrue(str_contains($sql, 'valid_to DATE NOT NULL'), 'valid_to fehlt');
});

test('Update-Pfad: group_history.php steht in der Syntaxliste', function () use ($ghRoot) {
    $list = (string) sourceCode($ghRoot . '/tests/suites/update_path_syntax.php');
    assertTrue(str_contains($list, "'private/helpers/group_history.php'"), 'group_history.php fehlt in update_path_syntax.php');
});

test('groupHistoryMigrate ist definiert', function () {
    assertTrue(function_exists('groupHistoryMigrate'), 'groupHistoryMigrate() fehlt');
});

function ghEmptyPlan(): array
{
    return ['insert_current' => [], 'delete_current' => [], 'insert_history' => [],
            'update_history' => [], 'delete_history' => []];
}

test('groupsPlanChange: unveraenderte Gruppen bleiben unberuehrt', function () {
    assertSame(ghEmptyPlan(), groupsPlanChange([1 => null, 2 => '2026-03-01'], [], [2, 1], '2026-06-01'));
});

test('groupsPlanChange: Entfernen schreibt Verlauf bis zum Vortag', function () {
    $plan = groupsPlanChange([1 => null, 2 => '2025-01-01'], [], [], '2026-06-01');
    assertSame([1, 2], $plan['delete_current']);
    assertSame([
        ['group_id' => 1, 'valid_from' => null,         'valid_to' => '2026-05-31'],
        ['group_id' => 2, 'valid_from' => '2025-01-01', 'valid_to' => '2026-05-31'],
    ], $plan['insert_history']);
});

test('groupsPlanChange: Entfernen am oder vor dem Beginn ist eine Korrektur ohne Verlauf', function () {
    $plan = groupsPlanChange([1 => '2026-06-01', 2 => '2026-06-10'], [], [], '2026-06-01');
    assertSame([1, 2], $plan['delete_current']);
    assertSame([], $plan['insert_history']);
});

test('groupsPlanChange: Hinzufuegen beginnt am Datum', function () {
    $plan = groupsPlanChange([], [], [5], '2026-06-01');
    assertSame([['group_id' => 5, 'valid_from' => '2026-06-01']], $plan['insert_current']);
});

test('groupsPlanChange: Anlegen (Datum null) beginnt von Anfang an', function () {
    $plan = groupsPlanChange([], [], [5, 6], null);
    assertSame([['group_id' => 5, 'valid_from' => null], ['group_id' => 6, 'valid_from' => null]], $plan['insert_current']);
});

test('groupsPlanChange: Wiederhinzufuegen kuerzt einen ueberlappenden Verlauf', function () {
    $history = [
        ['history_id' => 7, 'group_id' => 5, 'valid_from' => null,         'valid_to' => '2026-08-31'],
        ['history_id' => 8, 'group_id' => 5, 'valid_from' => '2026-07-01', 'valid_to' => '2026-07-31'],
        ['history_id' => 9, 'group_id' => 5, 'valid_from' => '2025-01-01', 'valid_to' => '2025-12-31'],
        ['history_id' => 10, 'group_id' => 6, 'valid_from' => null,        'valid_to' => '2026-08-31'],
    ];
    $plan = groupsPlanChange([], $history, [5], '2026-06-01');
    assertSame([['history_id' => 7, 'valid_to' => '2026-05-31']], $plan['update_history'], 'Eintrag 7 endet am Vortag');
    assertSame([8], $plan['delete_history'], 'Eintrag 8 beginnt nach dem Datum und entfaellt');
    // 9 endet vorher, 10 ist eine andere Gruppe: unberuehrt
});

test('groupsPlanChange: Grenzfall hin und zurueck am selben Tag (Spec 7.1)', function () {
    $first = groupsPlanChange([1 => null], [], [], '2026-06-01');
    assertSame([['group_id' => 1, 'valid_from' => null, 'valid_to' => '2026-05-31']], $first['insert_history']);
    $second = groupsPlanChange([], [['history_id' => 3, 'group_id' => 1, 'valid_from' => null, 'valid_to' => '2026-05-31']], [1], '2026-06-01');
    assertSame([['group_id' => 1, 'valid_from' => '2026-06-01']], $second['insert_current']);
    assertSame([], $second['update_history']);
    assertSame([], $second['delete_history']);
});

test('groupsPlanChange: Jahresgrenze beim Vortag', function () {
    $plan = groupsPlanChange([1 => null], [], [], '2026-01-01');
    assertSame('2025-12-31', $plan['insert_history'][0]['valid_to']);
});

test('groupsCheckValidFrom: gueltig, Zukunft, Unsinn', function () {
    assertSame(null, groupsCheckValidFrom('2026-06-01', '2026-10-05'));
    assertSame(null, groupsCheckValidFrom('2026-10-05', '2026-10-05'), 'heute ist erlaubt');
    assertTrue(groupsCheckValidFrom('2026-10-06', '2026-10-05') !== null, 'morgen ist nicht erlaubt');
    assertTrue(groupsCheckValidFrom('2026-02-30', '2026-10-05') !== null, '30. Februar');
    assertTrue(groupsCheckValidFrom('01.06.2026', '2026-10-05') !== null, 'deutsches Format');
    assertTrue(groupsCheckValidFrom(20260601, '2026-10-05') !== null, 'Zahl');
    assertTrue(groupsCheckValidFrom(null, '2026-10-05') !== null, 'null');
});
