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
