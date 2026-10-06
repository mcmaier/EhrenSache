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
 * Rueckmeldungen beim Verlegen eines Termins zuruecksetzen (OI-124), ohne Datenbank.
 *
 * Spec: docs/superpowers/specs/2026-10-06-oi-124-rueckmeldungen-zuruecksetzen-design.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/responses.php';

test('responsesResetFlag: fehlendes Feld heisst nicht angegeben', function () {
    assertSame(['value' => null, 'error' => false], responsesResetFlag([]));
    assertSame(['value' => null, 'error' => false], responsesResetFlag(['title' => 'X']));
});

test('responsesResetFlag: true und false werden uebernommen', function () {
    assertSame(['value' => true,  'error' => false], responsesResetFlag(['reset_responses' => true]));
    assertSame(['value' => false, 'error' => false], responsesResetFlag(['reset_responses' => false]));
});

test('responsesResetFlag: alles ausser true und false ist ein Fehler', function () {
    foreach (['ja', 'true', 1, 0, null, [], 1.0] as $falsch) {
        assertSame(true, responsesResetFlag(['reset_responses' => $falsch])['error'],
            'Wert ' . var_export($falsch, true) . ' muss abgewiesen werden');
    }
});

test('responsesAffectedBody: Code und Zahlen fuer die Rueckfrage', function () {
    $body = responsesAffectedBody(['appointments' => 3, 'responses' => 17]);
    assertSame('responses_affected', $body['code']);
    assertSame(3, $body['appointments']);
    assertSame(17, $body['responses']);
    assertTrue(is_string($body['message']) && $body['message'] !== '', 'message fehlt');
});
