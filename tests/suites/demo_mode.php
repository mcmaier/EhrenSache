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

// ---- Die Listen wörtlich gegen die Spezifikation -------------------------

test('Die Erlaubnisliste entspricht der Spezifikation', function () {
    // Fest verdrahtet, mit Absicht: Die Schleifen weiter oben pruefen, dass
    // demoRequestAllowed() die Listen richtig liest. Erst dieser Anker haelt
    // fest, was in den Listen stehen darf. Wer eine Ressource ergaenzt oder
    // verschiebt, muss hier mit anfassen -- und trifft die Entscheidung damit
    // sichtbar, statt sie nebenbei zu machen.
    assertSame([
        'login'             => ['POST'],
        'logout'            => ['POST'],
        'auth'              => ['POST'],
        'members'           => ['POST', 'PUT', 'DELETE'],
        'appointments'      => ['POST', 'PUT', 'DELETE'],
        'records'           => ['POST', 'PUT', 'DELETE'],
        'exceptions'        => ['POST', 'PUT', 'DELETE'],
        'work_sessions'     => ['POST', 'PUT', 'DELETE'],
        'activity_types'    => ['POST', 'PUT', 'DELETE'],
        'membership_dates'  => ['POST', 'PUT', 'DELETE'],
        'member_groups'     => ['POST', 'PUT', 'DELETE'],
        'appointment_types' => ['POST', 'PUT', 'DELETE'],
        'auto_checkin'      => ['POST'],
        'totp_checkin'      => ['POST'],
        'station'           => ['POST'],
    ], DEMO_WRITE_ALLOWED);
});

test('Die Sperrliste entspricht der Spezifikation', function () {
    assertSame([
        'change_password', 'change_pin', 'users', 'activate_user', 'user_status',
        'register', 'password_reset_request', 'settings', 'upload-logo',
        'import', 'cleanup', 'regenerate_token',
    ], DEMO_WRITE_DENIED);
});

test('Die Nur-Lesend-Liste entspricht der Spezifikation', function () {
    assertSame([
        'ping', 'appearance', 'me', 'version', 'session_info', 'my_data',
        'statistics', 'available_years', 'attendance_list', 'import_logs', 'export',
    ], DEMO_READ_ONLY);
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
// Anfuehrungszeichen. escapeshellarg() von PHP unter Windows ersetzt
// doppelte Anfuehrungszeichen im Argument durch ein Leerzeichen statt sie zu
// escapen (verifiziert) - mit doppelten Anfuehrungszeichen im Code wuerde
// der erzeugte PHP-Code kaputtgeschickt. var_export() liefert fuer einen
// Pfad ohne einfaches Anfuehrungszeichen ebenfalls eine einfach gequotete
// Zeichenkette, das passt zusammen.

/**
 * Fuehrt PHP-Code in einem Unterprozess aus und liefert dessen Ausgabe.
 *
 * Ist shell_exec() per disable_functions gesperrt, liefert die Funktion
 * selbst null - ohne diesen Fang wuerde jeder Aufrufer mit einer leeren
 * Ausgabe weiterrechnen und an einer irrefuehrenden Meldung scheitern
 * ("Demo-Antwort fehlt: "), die einen Fehler im getesteten Code vortaeuscht,
 * obwohl in Wahrheit die PHP-Umgebung den Unterprozess-Mechanismus verbietet.
 */
function runPhpCodeInSubprocess(string $code): string
{
    if (!function_exists('shell_exec')) {
        throw new RuntimeException(
            'shell_exec() ist per disable_functions gesperrt - die '
            . 'Unterprozess-Tests fuer den Waechter koennen in dieser '
            . 'PHP-Umgebung nicht laufen (kein Fehler im getesteten Code).'
        );
    }

    return (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1'
    );
}

test('Mit DEMO_MODE bricht der Waechter bei Gesperrtem ab', function () {
    $helfer = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', true); require " . var_export($helfer, true)
          . "; demoGuard('cleanup', 'POST'); echo 'NICHT ERREICHT';";

    $ausgabe = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($ausgabe, '"demo":true'), 'Demo-Antwort fehlt: ' . $ausgabe);
    assertTrue(!str_contains($ausgabe, 'NICHT ERREICHT'), 'exit() hat nicht gegriffen');
});

test('Mit DEMO_MODE laesst der Waechter Erlaubtes durch', function () {
    $helfer = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', true); require " . var_export($helfer, true)
          . "; demoGuard('records', 'POST'); echo 'DURCHGELASSEN';";

    $ausgabe = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($ausgabe, 'DURCHGELASSEN'), 'Erlaubtes wurde abgewiesen: ' . $ausgabe);
});

// ---- demoModeActive faellt zur sicheren Seite -----------------------------
//
// DEMO_MODE ist eine Konstante; jeder Wert braucht seinen eigenen
// Unterprozess, sonst kollidiert er mit einer bereits definierten Konstante
// aus einem frueheren Test.

test('DEMO_MODE als Integer 1 zeigt den Waechter aktiv', function () {
    $helfer = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', 1); require " . var_export($helfer, true)
          . "; echo demoModeActive() ? 'AKTIV' : 'INAKTIV';";

    $ausgabe = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($ausgabe, 'AKTIV'), "DEMO_MODE=1 (int) muss aktiv sein: " . $ausgabe);
});

test("DEMO_MODE als Zeichenkette 'false' zeigt den Waechter aktiv", function () {
    $helfer = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', 'false'); require " . var_export($helfer, true)
          . "; echo demoModeActive() ? 'AKTIV' : 'INAKTIV';";

    $ausgabe = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($ausgabe, 'AKTIV'), "DEMO_MODE='false' (String) muss aktiv sein: " . $ausgabe);
});

test('DEMO_MODE als Boolean false zeigt den Waechter untaetig', function () {
    $helfer = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', false); require " . var_export($helfer, true)
          . "; echo demoModeActive() ? 'AKTIV' : 'INAKTIV';";

    $ausgabe = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($ausgabe, 'INAKTIV'), 'DEMO_MODE=false (bool) muss untaetig sein: ' . $ausgabe);
});

// ---- Vollständigkeit gegen api.php -----------------------------------------

/**
 * Sammelt jede Ressource, die api.php kennt: die Fälle des Routers und die
 * öffentlichen Endpunkte, die schon davor mit exit() aussteigen.
 */
function demoTestRessourcenAusApi(): array
{
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/public/api/api.php');

    preg_match_all('/case\s+\'([a-z0-9_\-]+)\'\s*:/i', $src, $faelle);
    preg_match_all('/\$resource\s*===\s*\'([a-z0-9_\-]+)\'/', $src, $frueh);

    $alle = array_unique(array_merge($faelle[1], $frueh[1]));
    sort($alle);

    return $alle;
}

test('Die Ressourcen lassen sich aus api.php lesen', function () {
    $gefunden = demoTestRessourcenAusApi();

    // Ohne diese Untergrenze wuerde eine kaputte Regex die naechste Pruefung
    // leer durchlaufen lassen -- sie waere gruen, ohne etwas geprueft zu haben.
    assertTrue(
        count($gefunden) >= 30,
        'Nur ' . count($gefunden) . ' Ressourcen gefunden — die Regex passt nicht mehr zu api.php'
    );
});

test('Jede Ressource aus api.php steht in genau einer Liste', function () {
    foreach (demoTestRessourcenAusApi() as $r) {
        $treffer = (int) isset(DEMO_WRITE_ALLOWED[$r])
                 + (int) in_array($r, DEMO_WRITE_DENIED, true)
                 + (int) in_array($r, DEMO_READ_ONLY, true);

        assertSame(
            1,
            $treffer,
            "Ressource '{$r}' steht in {$treffer} der drei Listen statt in genau einer. "
            . 'Neu hinzugekommen? Dann in demo_mode.php entscheiden: schreibend erlaubt, '
            . 'schreibend gesperrt, oder nur lesend.'
        );
    }
});

test('Keine Liste nennt eine Ressource, die es nicht mehr gibt', function () {
    $vorhanden = demoTestRessourcenAusApi();

    $gelistet = array_merge(
        array_keys(DEMO_WRITE_ALLOWED),
        DEMO_WRITE_DENIED,
        DEMO_READ_ONLY
    );

    foreach ($gelistet as $r) {
        assertTrue(
            in_array($r, $vorhanden, true),
            "Liste nennt '{$r}', api.php kennt die Ressource nicht (mehr)"
        );
    }
});
