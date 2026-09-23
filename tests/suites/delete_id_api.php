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
 * DELETE ohne Wirkung darf nicht wie ein Erfolg aussehen (OI-56).
 *
 * Ein DELETE ohne `id` fuehrte in den meisten Handlern
 * `DELETE FROM <tabelle> WHERE <spalte> = NULL` aus. Das trifft keine Zeile --
 * `= NULL` ist in SQL nie wahr --, aber `PDOStatement::execute()` liefert
 * trotzdem `true`, und der Handler antwortete mit 200 und einer
 * Erfolgsmeldung. Ein Client kann so nicht erkennen, dass sein Aufruf
 * wirkungslos war.
 *
 * Aufgefallen beim Schreiben der Suite `arrival_api` (1.5.0): Ein Testtermin
 * verschwand nicht, obwohl der Aufruf `200 "Appointment deleted"` meldete.
 * Ursache war dort ein Fehler im Test -- `apiRequest()` kennt keinen Schluessel
 * `id`, die Angabe gehoert in `query` --, aber der Handler haette es sagen
 * muessen.
 *
 * Die Suite prueft die Klasse, nicht den Einzelfall: Jede Ressource mit einem
 * DELETE-Zweig kommt vor, auch die vier, die 2026-09-16 bereits korrigiert
 * wurden. Kein Datensatz wird dabei angelegt -- es geht ausschliesslich um
 * Antworten auf Aufrufe, die nichts treffen duerfen.
 */

/** Ressourcen mit einem DELETE-Zweig ueber eine einzelne id. */
const DELETE_ID_RESOURCES = [
    'appointments',
    'records',
    'users',
    'exceptions',
    'activity_types',
    'appointment_types',
    'member_groups',
    'membership_dates',
];

/** Eine id, die es sicher nicht gibt -- hoch genug, um keinen Bestand zu treffen. */
const DELETE_ID_UNBEKANNT = 999888777;

foreach (DELETE_ID_RESOURCES as $resource) {
    test("DELETE {$resource} ohne id meldet 400 statt Erfolg", function () use ($resource) {
        $res = apiRequest('DELETE', $resource, ['token' => apiToken('admin')]);

        assertStatus(400, $res);
    });

    test("DELETE {$resource} mit unbekannter id meldet 404 statt Erfolg", function () use ($resource) {
        $res = apiRequest('DELETE', $resource, [
            'token' => apiToken('admin'),
            'query' => ['id' => DELETE_ID_UNBEKANNT],
        ]);

        assertStatus(404, $res);
    });
}

test('records: die Massenloeschung ueber member_id bleibt ohne id moeglich', function () {
    // Der Zweig kennt zwei Betriebsarten. Eine Pruefung auf id allein wuerde
    // die zweite abwuergen -- OI-56 nennt das ausdruecklich als Fallstrick.
    // Geloescht wird hier nichts: Die Mitgliedsnummer gibt es nicht, die
    // Antwort muss aber die der Massenloeschung sein (0 Treffer), nicht 400.
    $res = apiRequest('DELETE', 'records', [
        'token' => apiToken('admin'),
        'query' => ['member_id' => DELETE_ID_UNBEKANNT],
    ]);

    assertStatus(200, $res);
    assertSame(0, (int) ($res['body']['deleted_count'] ?? -1),
        'Die Massenloeschung muss antworten, wie viele Zeilen sie getroffen hat');
});
