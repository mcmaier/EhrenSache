<?php
declare(strict_types=1);

/**
 * Demo-Modus: Der Wächter und seine drei Listen.
 *
 * Der alte demo-Branch schützte über zwölf harte exit-Aufrufe in
 * Handler-Rümpfen. Als danach neun Ressourcen dazukamen, bemerkte das
 * niemand. Diese Suite ist die Antwort darauf: Sie prüft nicht nur, dass
 * die bekannten Sperren greifen, sondern dass überhaupt keine Ressource
 * unbedacht bleibt.
 */

require_once __DIR__ . '/../../private/helpers/demo_mode.php';

// ---- demoWriteAllowed ------------------------------------------------------

test('GET geht durch, auch bei gesperrten Ressourcen', function () {
    assertTrue(demoWriteAllowed('settings', 'GET'), 'settings GET');
    assertTrue(demoWriteAllowed('cleanup', 'GET'), 'cleanup GET');
    assertTrue(demoWriteAllowed('gibt_es_nicht', 'GET'), 'unbekannte Ressource GET');
});

test('Erlaubte Ressource nimmt ihre Methoden an', function () {
    assertTrue(demoWriteAllowed('records', 'POST'), 'records POST');
    assertTrue(demoWriteAllowed('records', 'PUT'), 'records PUT');
    assertTrue(demoWriteAllowed('records', 'DELETE'), 'records DELETE');
    assertTrue(demoWriteAllowed('station', 'POST'), 'station POST');
});

test('Erlaubte Ressource nimmt eine nicht gelistete Methode nicht an', function () {
    assertSame(false, demoWriteAllowed('station', 'DELETE'), 'station DELETE');
    assertSame(false, demoWriteAllowed('login', 'DELETE'), 'login DELETE');
});

test('Gesperrte Ressource weist jeden Schreibzugriff ab', function () {
    foreach (['change_password', 'change_pin', 'cleanup', 'regenerate_token', 'settings'] as $r) {
        foreach (['POST', 'PUT', 'DELETE'] as $m) {
            assertSame(false, demoWriteAllowed($r, $m), "{$r} {$m}");
        }
    }
});

test('Unbekannte Ressource ist schreibend gesperrt', function () {
    assertSame(false, demoWriteAllowed('gibt_es_nicht', 'POST'), 'unbekannt POST');
});

// ---- demoModeActive --------------------------------------------------------

test('Ohne DEMO_MODE ist der Modus aus', function () {
    assertSame(false, demoModeActive());
});

test('Der Waechter ist ohne DEMO_MODE untaetig', function () {
    // Riefe der Waechter hier exit() auf, braeche der gesamte Testlauf ab.
    // Dass die Suite weiterlaeuft, ist der Beweis.
    demoGuard('cleanup', 'POST');
    demoGuard('settings', 'POST');
    demoGuard('gibt_es_nicht', 'DELETE');
    assertTrue(true, 'Waechter hat den Lauf nicht abgebrochen');
});
