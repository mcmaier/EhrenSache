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

/**
 * Regeln der offenen Punkte (FI-17) ohne Datenbank: Frist, Fenster für
 * Ablehnungen, Sortierung und Zählung. Das Zusammensetzen aus der Datenbank
 * prüft tests/suites/open_items_api.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/responses.php';
require_once __DIR__ . '/../../private/helpers/open_items.php';

test('openItems: Rueckmeldung ist offen, solange Frist laeuft und niemand geantwortet hat', function () {
    $now = '2026-09-22 12:00:00';
    assertTrue(openItemsResponseIsOpen('2026-09-25', '19:30:00', '2026-09-24 19:30:00', false, $now));
    assertTrue(!openItemsResponseIsOpen('2026-09-25', '19:30:00', '2026-09-24 19:30:00', true, $now),
        'Beantwortet ist nicht offen');
    assertTrue(!openItemsResponseIsOpen('2026-09-23', '19:30:00', '2026-09-22 11:59:59', false, $now),
        'Abgelaufene Frist ist nicht offen');
    assertTrue(!openItemsResponseIsOpen('2026-09-22', '11:00:00', '2026-09-22 13:00:00', false, $now),
        'Begonnener Termin ist nicht offen');
    assertTrue(openItemsResponseIsOpen('2026-09-23', '19:30:00', '2026-09-22 12:00:00', false, $now),
        'Frist genau jetzt zaehlt noch als laufend, wie der Zaehler der PWA');
});

test('openItems: Ablehnungen bleiben 14 Tage sichtbar', function () {
    assertSame('2026-09-08 12:00:00', openItemsRejectedSince('2026-09-22 12:00:00'));
});

test('openItems: Rueckmeldungshorizont liegt 14 Tage voraus', function () {
    assertSame('2026-10-06', openItemsResponseHorizon('2026-09-22 12:00:00'));
});

test('openItems: Sortierung Rueckmeldung vor wartend vor abgelehnt', function () {
    $items = [
        ['kind' => 'exception', 'state' => 'rejected', 'decided_at' => '2026-09-10 08:00:00'],
        ['kind' => 'work_session', 'state' => 'pending', 'start_time' => '2026-09-20 10:00:00'],
        ['kind' => 'response', 'state' => 'open', 'deadline' => '2026-09-26 19:00:00'],
        ['kind' => 'exception', 'state' => 'rejected', 'decided_at' => '2026-09-19 08:00:00'],
        ['kind' => 'exception', 'state' => 'pending', 'date' => '2026-09-18', 'start_time' => '19:00:00'],
        ['kind' => 'response', 'state' => 'open', 'deadline' => '2026-09-24 19:30:00'],
    ];
    $sorted = openItemsSort($items);

    assertSame('2026-09-24 19:30:00', $sorted[0]['deadline'], 'Dringendste Frist zuerst');
    assertSame('2026-09-26 19:00:00', $sorted[1]['deadline']);
    assertSame('exception', $sorted[2]['kind'], 'Wartende nach Datum aufsteigend: 18.09. vor 20.09.');
    assertSame('work_session', $sorted[3]['kind']);
    assertSame('2026-09-19 08:00:00', $sorted[4]['decided_at'], 'Juengste Ablehnung zuerst');
    assertSame('2026-09-10 08:00:00', $sorted[5]['decided_at']);
});

test('openItems: Zaehlung je Zustand', function () {
    assertSame(['open' => 0, 'pending' => 0, 'rejected' => 0], openItemsCounts([]));
    assertSame(['open' => 1, 'pending' => 2, 'rejected' => 1], openItemsCounts([
        ['state' => 'open'], ['state' => 'pending'], ['state' => 'pending'], ['state' => 'rejected'],
    ]));
});
