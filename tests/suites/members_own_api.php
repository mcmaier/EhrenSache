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

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * GET members&id als user liefert das eigene Mitglied mit Aktivitaetsflag (OI-91).
 *
 * Die Anwesenheitsverwaltung fuellt ihre Mitgliedsauswahl nur mit Mitgliedern,
 * die im Zeitraum aktiv sind (is_active_in_period), und setzt member_id als
 * Wert. Der Einzelabruf fuer user lieferte beides nicht -- die Auswahl blieb
 * leer, der Modus "Anwesenheit eines Mitglieds" war fuer user unerreichbar.
 *
 * Nur lesend; laeuft deshalb auch gegen eine geteilte Datenbank.
 */

test('OI-91: der Einzelabruf fuer user traegt member_id und is_active_in_period', function () {
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Test-user hat kein verknuepftes Mitglied -- Test nicht durchfuehrbar');

    $year = (int) date('Y');
    $res = apiRequest('GET', 'members', ['token' => apiToken('user'),
        'query' => ['id' => $memberId, 'year' => $year]]);
    assertStatus(200, $res);

    assertSame($memberId, $res['body']['member_id'] ?? null, 'member_id fehlt oder ist nicht das eigene Mitglied');
    assertTrue(in_array($res['body']['is_active_in_period'] ?? null, [0, 1], true),
        'is_active_in_period fehlt oder ist keine Zahl 0/1: ' . $res['raw']);
    assertTrue(in_array($res['body']['active'] ?? null, [0, 1], true), 'active fehlt');

    // Dieselbe Regel wie im Listenabruf der Verwalter -- sonst zeigt die
    // Auswahl fuer user einen anderen Stand als fuer den Admin.
    $list = apiRequest('GET', 'members', ['token' => apiToken('admin'),
        'query' => ['year' => $year, 'include_inactive' => 'true']]);
    assertStatus(200, $list);
    $row = null;
    foreach ($list['body'] as $m) {
        if ((int) $m['member_id'] === $memberId) {
            $row = $m;
        }
    }
    assertTrue($row !== null, 'Mitglied fehlt in der Admin-Liste');
    assertSame((int) $row['is_active_in_period'], $res['body']['is_active_in_period'],
        'user und Admin sehen fuer dasselbe Jahr einen anderen Aktivstatus');
});

test('OI-91: eine fremde id liefert weiterhin nur das eigene Mitglied', function () {
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Test-user hat kein verknuepftes Mitglied -- Test nicht durchfuehrbar');

    $res = apiRequest('GET', 'members', ['token' => apiToken('user'),
        'query' => ['id' => $memberId + 100000]]);
    assertStatus(200, $res);
    assertSame($memberId, $res['body']['member_id'] ?? null, 'Fremde id liefert nicht das eigene Mitglied');
    assertTrue(!empty($res['body']['warning']), 'Hinweis auf die ignorierte id fehlt');
    assertTrue(!array_key_exists('pin_updated_at', $res['body']) && !array_key_exists('created_at', $res['body']),
        'user bekommt Verwaltungsfelder');
});
