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

// ---- demoRequestAllowed: GET/HEAD -----------------------------------------

test('GET geht durch, wenn die Ressource in einer der drei Listen steht', function () {
    assertTrue(demoRequestAllowed('settings', 'GET'), 'settings GET (gesperrt, aber bekannt)');
    assertTrue(demoRequestAllowed('cleanup', 'GET'), 'cleanup GET (gesperrt, aber bekannt)');
    assertTrue(demoRequestAllowed('ping', 'GET'), 'ping GET (nur-lesend)');
    assertTrue(demoRequestAllowed('records', 'GET'), 'records GET (erlaubt)');
});

test('GET auf einer unbekannten Ressource sperrt', function () {
    assertSame(false, demoRequestAllowed('gibt_es_nicht', 'GET'), 'unbekannte Ressource GET');
});

test('HEAD verhaelt sich wie GET', function () {
    assertTrue(demoRequestAllowed('records', 'HEAD'), 'records HEAD (bekannt)');
    assertSame(false, demoRequestAllowed('gibt_es_nicht', 'HEAD'), 'unbekannte Ressource HEAD');
});

// ---- demoRequestAllowed: Schreibzugriffe ----------------------------------

test('Erlaubte Ressource nimmt ihre Methoden an', function () {
    assertTrue(demoRequestAllowed('records', 'POST'), 'records POST');
    assertTrue(demoRequestAllowed('records', 'PUT'), 'records PUT');
    assertTrue(demoRequestAllowed('records', 'DELETE'), 'records DELETE');
    assertTrue(demoRequestAllowed('station', 'POST'), 'station POST');
});

test('Erlaubte Ressource nimmt eine nicht gelistete Methode nicht an', function () {
    assertSame(false, demoRequestAllowed('station', 'DELETE'), 'station DELETE');
    assertSame(false, demoRequestAllowed('login', 'DELETE'), 'login DELETE');
});

test('Unbekannte Ressource ist schreibend gesperrt', function () {
    assertSame(false, demoRequestAllowed('gibt_es_nicht', 'POST'), 'unbekannt POST');
});

test('Kleinschreibung und unbekannte Methoden sperren, auch bei erlaubter Ressource', function () {
    // Fail-Safe-Richtung: nur exakt "POST"/"PUT"/"DELETE" (gross) oeffnen etwas.
    assertSame(false, demoRequestAllowed('records', 'post'), 'records post (klein)');
    assertSame(false, demoRequestAllowed('records', 'TRACE'), 'records TRACE');
});

// ---- Die drei Listen tragen die Last selbst -------------------------------

test('Jede gesperrte Ressource weist jeden Schreibzugriff ab', function () {
    foreach (DEMO_WRITE_DENIED as $r) {
        foreach (['POST', 'PUT', 'DELETE'] as $m) {
            assertSame(false, demoRequestAllowed($r, $m), "{$r} {$m}");
        }
    }
});

test('Jede erlaubte Ressource nimmt ihre gelisteten Methoden an', function () {
    foreach (DEMO_WRITE_ALLOWED as $r => $methoden) {
        foreach ($methoden as $m) {
            assertTrue(demoRequestAllowed($r, $m), "{$r} {$m}");
        }
    }
});

test('Keine Ressource steht in zwei Listen', function () {
    assertSame([], array_intersect(array_keys(DEMO_WRITE_ALLOWED), DEMO_WRITE_DENIED), 'erlaubt und gesperrt');
    assertSame([], array_intersect(array_keys(DEMO_WRITE_ALLOWED), DEMO_READ_ONLY), 'erlaubt und nur-lesend');
    assertSame([], array_intersect(DEMO_WRITE_DENIED, DEMO_READ_ONLY), 'gesperrt und nur-lesend');
});

// ---- demoModeActive --------------------------------------------------------

test('Ohne DEMO_MODE ist der Modus aus', function () {
    assertSame(false, demoModeActive());
});

test('Der Waechter ist ohne DEMO_MODE untaetig', function () {
    // Riefe der Waechter hier exit() auf, wuerde das seit der Absicherung in
    // tests/run.php (register_shutdown_function) den Testlauf mit einer
    // Fehlermeldung auf STDERR und Rueckgabewert 1 abbrechen. Vorher waere
    // es ein stiller, gruen aussehender Lauf gewesen.
    demoGuard('cleanup', 'POST');
    demoGuard('settings', 'POST');
    demoGuard('gibt_es_nicht', 'DELETE');
    assertTrue(true, 'Waechter hat den Lauf nicht abgebrochen');
});

// ---- demoGuard mit DEMO_MODE: der eigentliche Sperrpfad -------------------
//
// DEMO_MODE ist eine Konstante und demoGuard() ruft bei einer Sperre exit()
// - beides laesst sich nicht im laufenden Testprozess simulieren, ohne die
// Suite selbst zu beenden. Deshalb im Unterprozess.

// Hinweis: Die PHP-Codezeile fuer -r verwendet bewusst nur einfache
// Anfuehrungszeichen. escapeshellarg() von PHP unter Windows entfernt
// doppelte Anfuehrungszeichen aus dem Argument ersatzlos statt sie zu
// escapen (verifiziert) - mit doppelten Anfuehrungszeichen im Code wuerde
// der erzeugte PHP-Code kaputtgeschickt. var_export() liefert fuer einen
// Pfad ohne einfaches Anfuehrungszeichen ebenfalls eine einfach gequotete
// Zeichenkette, das passt zusammen.

test('Mit DEMO_MODE bricht der Waechter bei Gesperrtem ab', function () {
    $helfer = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', true); require " . var_export($helfer, true)
          . "; demoGuard('cleanup', 'POST'); echo 'NICHT ERREICHT';";

    $ausgabe = (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'
    );

    assertTrue(str_contains($ausgabe, '"demo":true'), 'Demo-Antwort fehlt: ' . $ausgabe);
    assertTrue(!str_contains($ausgabe, 'NICHT ERREICHT'), 'exit() hat nicht gegriffen');
});

test('Mit DEMO_MODE laesst der Waechter Erlaubtes durch', function () {
    $helfer = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', true); require " . var_export($helfer, true)
          . "; demoGuard('records', 'POST'); echo 'DURCHGELASSEN';";

    $ausgabe = (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'
    );

    assertTrue(str_contains($ausgabe, 'DURCHGELASSEN'), 'Erlaubtes wurde abgewiesen: ' . $ausgabe);
});
