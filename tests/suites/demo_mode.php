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

test('Leere Ressource ist gesperrt (Grundlage fuer den Array-Schutz in api.php)', function () {
    // api.php faengt ?resource[]=x vor dem Waechter mit is_string() ab und
    // reicht in diesem Fall '' statt eines Arrays weiter (siehe Abschnitt 3).
    // '' steht in keiner der drei Listen und muss deshalb wie jede unbekannte
    // Ressource sowohl lesend als auch schreibend gesperrt sein.
    assertSame(false, demoRequestAllowed('', 'GET'), "leere Ressource GET");
    assertSame(false, demoRequestAllowed('', 'POST'), "leere Ressource POST");
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
    foreach (DEMO_WRITE_ALLOWED as $r => $methods) {
        foreach ($methods as $m) {
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
        'statistics', 'statistics_report', 'available_years', 'attendance_list',
        'import_logs', 'export',
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
    $helperFile = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', true); require " . var_export($helperFile, true)
          . "; demoGuard('cleanup', 'POST'); echo 'NICHT ERREICHT';";

    $output = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($output, '"demo":true'), 'Demo-Antwort fehlt: ' . $output);
    assertTrue(!str_contains($output, 'NICHT ERREICHT'), 'exit() hat nicht gegriffen');
});

test('Mit DEMO_MODE laesst der Waechter Erlaubtes durch', function () {
    $helperFile = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', true); require " . var_export($helperFile, true)
          . "; demoGuard('records', 'POST'); echo 'DURCHGELASSEN';";

    $output = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($output, 'DURCHGELASSEN'), 'Erlaubtes wurde abgewiesen: ' . $output);
});

// ---- demoModeActive faellt zur sicheren Seite -----------------------------
//
// DEMO_MODE ist eine Konstante; jeder Wert braucht seinen eigenen
// Unterprozess, sonst kollidiert er mit einer bereits definierten Konstante
// aus einem frueheren Test.

test('DEMO_MODE als Integer 1 zeigt den Waechter aktiv', function () {
    $helperFile = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', 1); require " . var_export($helperFile, true)
          . "; echo demoModeActive() ? 'AKTIV' : 'INAKTIV';";

    $output = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($output, 'AKTIV'), "DEMO_MODE=1 (int) muss aktiv sein: " . $output);
});

test("DEMO_MODE als Zeichenkette 'false' zeigt den Waechter aktiv", function () {
    $helperFile = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', 'false'); require " . var_export($helperFile, true)
          . "; echo demoModeActive() ? 'AKTIV' : 'INAKTIV';";

    $output = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($output, 'AKTIV'), "DEMO_MODE='false' (String) muss aktiv sein: " . $output);
});

test('DEMO_MODE als Boolean false zeigt den Waechter untaetig', function () {
    $helperFile = __DIR__ . '/../../private/helpers/demo_mode.php';
    $code = "define('DEMO_MODE', false); require " . var_export($helperFile, true)
          . "; echo demoModeActive() ? 'AKTIV' : 'INAKTIV';";

    $output = runPhpCodeInSubprocess($code);

    assertTrue(str_contains($output, 'INAKTIV'), 'DEMO_MODE=false (bool) muss untaetig sein: ' . $output);
});

// ---- Vollständigkeit gegen api.php -----------------------------------------

/**
 * Liest api.php einmal und liefert getrennt zurueck, was der Ressourcen-
 * Router (der `switch($resource)`-Block) und was die fruehen Ausstiege davor
 * (die oeffentlichen Endpunkte, die schon vor der Authentifizierung mit
 * exit() aussteigen) an Ressourcennamen enthalten.
 *
 * Die Trennung ist notwendig, nicht nur schoen: Die case-Regex liest sonst
 * den ganzen Dateitext und findet auch Treffer in fremden switch-Bloecken,
 * z. B. einem `switch($request_method) { case 'GET': ... }` irgendwo in
 * api.php. Ein solcher Treffer waere gar keine Ressource, wuerde aber als
 * "steht in 0 von 3 Listen" gemeldet und dazu einladen, ihn wie eine
 * vergessene Ressource in eine der Listen einzutragen -- der Weg, auf dem
 * Muell in die Listen geraet. Deshalb schneidet diese Funktion bei der Zeile
 * `switch($resource) {` durch: Die case-Regex laeuft nur ueber das, was
 * danach kommt, die $resource===-Regex nur ueber das, was davor kommt.
 * Fehlt die Trennzeile (api.php wurde umgebaut), wird das eine Ausnahme statt
 * eines stillen "durchsucht eben alles".
 *
 * Nicht erkannt werden bewusst: doppelte Anfuehrungszeichen bei case/===
 * werden zwar erkannt (siehe Regex unten), aber nicht: match($resource) {
 * 'name' => ... }, in_array($resource, [...]), lockeres $resource == 'x',
 * Yoda-Schreibweise ('x' === $resource) und Routing-Tabellen (Arrays statt
 * switch/if). Das ist tragbar, weil der Waechter zur geschlossenen Seite
 * faellt: unbekannt heisst gesperrt. Eine so uebersehene Ressource verliert
 * damit Funktion in der Demo, oeffnet aber keine Luecke.
 *
 * Liefert seit der vierten Pruefung (Abschnitt "Vollstaendigkeit gegen
 * api.php" -> "Die Stellung des Waechters") auch den ungeschnittenen
 * Dateitext mit ('src') - dort wird geprueft, ob demoGuard() vor dem ersten
 * fruehen Ausstieg aufgerufen wird. Ein zweites file_get_contents() dafuer
 * waere nur eine unnoetige zweite Wahrheit ueber denselben Dateiinhalt.
 *
 * @return array{cases: string[], early: string[], src: string}
 */
function demoTestResourcesFromApi(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/public/api/api.php');

    $marker = 'switch($resource) {';
    $cut = strpos($src, $marker);

    if ($cut === false) {
        throw new RuntimeException(
            "Trennzeile '{$marker}' nicht in api.php gefunden - "
            . 'demoTestResourcesFromApi() kann den Ressourcen-Router nicht mehr '
            . 'von den fruehen Ausstiegen trennen. api.php wurde vermutlich '
            . 'umgebaut; die Suche in dieser Funktion muss nachziehen.'
        );
    }

    $earlyExitsSrc = substr($src, 0, $cut);
    $routerSrc = substr($src, $cut);

    preg_match_all('/\$resource\s*===\s*[\'"]([a-z0-9_\-]+)[\'"]/i', $earlyExitsSrc, $early);
    preg_match_all('/case\s+[\'"]([a-z0-9_\-]+)[\'"]\s*:/i', $routerSrc, $cases);

    $cache = [
        'cases' => array_values(array_unique($cases[1])),
        'early' => array_values(array_unique($early[1])),
        'src'   => $src,
    ];

    return $cache;
}

/** Alle Ressourcen aus api.php, Router und fruehe Ausstiege zusammen, sortiert. */
function demoTestAllResourcesFromApi(): array
{
    $found = demoTestResourcesFromApi();
    $all = array_unique(array_merge($found['cases'], $found['early']));
    sort($all);

    return $all;
}

test('Der Ressourcen-Router liefert genuegend Treffer', function () {
    $found = demoTestResourcesFromApi();

    // Stand heute (09/2026): 38 Ressourcen insgesamt (vereinigt und
    // dedupliziert), davon 30 Rohtreffer aus dem switch($resource) und 9
    // Rohtreffer aus den fruehen Ausstiegen (einer davon, 'import', deckt
    // sich mit einem case im switch und faellt bei der Vereinigung raus).
    // Je eine eigene Untergrenze mit Puffer nach unten, damit ein Ausfall
    // der jeweils ANDEREN Regex nicht unbemerkt bleibt (siehe Docblock oben)
    // und ein legitimer Abbau von Ressourcen nicht faelschlich als
    // Regex-Bruch gemeldet wird.
    assertTrue(
        count($found['cases']) >= 25,
        'Nur ' . count($found['cases']) . ' Ressourcen aus dem switch($resource) '
        . 'gefunden (erwartet mindestens 25) - entweder wurden neun oder mehr '
        . 'Ressourcen legitim abgebaut, oder die case-Regex in '
        . 'demoTestResourcesFromApi() passt nicht mehr zu api.php.'
    );
});

test('Die fruehen Ausstiege liefern genuegend Treffer', function () {
    $found = demoTestResourcesFromApi();

    assertTrue(
        count($found['early']) >= 6,
        'Nur ' . count($found['early']) . ' Ressourcen aus den fruehen Ausstiegen '
        . 'gefunden (erwartet mindestens 6) - entweder wurden mehrere fruehe '
        . 'Ausstiege legitim abgebaut, oder die $resource===-Regex in '
        . 'demoTestResourcesFromApi() passt nicht mehr zu api.php (z. B. weil '
        . 'die Anfuehrungszeichen vereinheitlicht wurden).'
    );
});

test("api.php sichert \$_GET['resource'] mit is_string() ab", function () {
    // Ohne diese Absicherung ist $resource bei ?resource[]=x ein Array.
    // demoGuard() verlangt string als ersten Parameter (kein
    // declare(strict_types=1) in api.php aendert daran nichts -- Array nach
    // string ist auch im schwachen Modus keine gueltige Umwandlung) und
    // bricht dann mit einem TypeError samt Serverpfad im Fehlerrumpf ab.
    // Ein reiner strpos()-Treffer auf 'is_string(' waere zu schwach (koennte
    // aus jedem anderen Zusammenhang stammen) -- deshalb die konkrete
    // Fundstelle.
    $src = demoTestResourcesFromApi()['src'];

    assertTrue(
        str_contains($src, "is_string(\$_GET['resource']"),
        "api.php sichert \$_GET['resource'] nicht mehr mit is_string() ab - "
        . "?resource[]=x waere wieder ein Array und wuerde demoGuard() mit "
        . "einem TypeError abschiessen (Argument #1 muss string sein), "
        . "inklusive Pfadpreisgabe im Fehlerrumpf. Siehe Abschnitt 3 in api.php."
    );
});

test('Keine Ressource aus api.php fehlt in den drei Listen', function () {
    $missing = [];

    foreach (demoTestAllResourcesFromApi() as $r) {
        $hits = (int) isset(DEMO_WRITE_ALLOWED[$r])
              + (int) in_array($r, DEMO_WRITE_DENIED, true)
              + (int) in_array($r, DEMO_READ_ONLY, true);

        if ($hits === 0) {
            $missing[] = $r;
        }
    }

    assertSame(
        [],
        $missing,
        'Ressource(n) in keiner der drei Listen: ' . implode(', ', $missing) . '. '
        . 'Neu hinzugekommen? Dann in demo_mode.php entscheiden: schreibend '
        . 'erlaubt, schreibend gesperrt, oder nur lesend. Oder der Fund stammt '
        . 'gar nicht aus dem Ressourcen-Router - dann die Suche in '
        . 'demoTestResourcesFromApi() eingrenzen.'
    );
});

test('Keine Ressource aus api.php steht in mehr als einer Liste', function () {
    $duplicated = [];

    foreach (demoTestAllResourcesFromApi() as $r) {
        $hits = (int) isset(DEMO_WRITE_ALLOWED[$r])
              + (int) in_array($r, DEMO_WRITE_DENIED, true)
              + (int) in_array($r, DEMO_READ_ONLY, true);

        if ($hits > 1) {
            $duplicated[] = "{$r} ({$hits}x)";
        }
    }

    assertSame(
        [],
        $duplicated,
        'Ressource(n) in mehr als einer Liste zugleich: ' . implode(', ', $duplicated) . '. '
        . 'Doppelnennung in demo_mode.php entfernen.'
    );
});

test('Keine Liste nennt eine Ressource, die es nicht mehr gibt', function () {
    $found = demoTestAllResourcesFromApi();

    $listed = array_merge(
        array_keys(DEMO_WRITE_ALLOWED),
        DEMO_WRITE_DENIED,
        DEMO_READ_ONLY
    );

    $stale = [];
    foreach ($listed as $r) {
        if (!in_array($r, $found, true)) {
            $stale[] = $r;
        }
    }

    assertSame(
        [],
        $stale,
        "Liste(n) nennen Ressource(n), die api.php nicht (mehr) kennt: " . implode(', ', $stale)
    );
});

// ---- Die Stellung des Waechters --------------------------------------------
//
// Die bisherigen Pruefungen belegen nur, dass jede Ressource in einer der
// drei Listen einsortiert ist - nicht, dass demoGuard() ueberhaupt im
// Anfrageweg haengt. Ein demoGuard(), der nirgends aufgerufen wird, oder der
// erst NACH einem fruehen Ausstieg steht, waere unsichtbar: alle bisherigen
// Pruefungen blieben gruen, obwohl z. B. register und password_reset_request
// - beide mit Absicht in DEMO_WRITE_DENIED, weil sie Mail an fremde Adressen
// verschicken - den Waechter dann nie erreichen wuerden.
//
// Eine fruehere Fassung dieser Pruefung suchte mit strpos($src, 'demoGuard(')
// im rohen Dateitext. Das belegt nur, dass die Zeichenfolge irgendwo steht -
// nicht, dass ein Aufruf tatsaechlich ausgefuehrt wird. Vier von fuenf
// realistischen Mutationen blieben damit gruen: der Aufruf auskommentiert,
// in ein totes if(...) { ... } gekapselt, hinter einen fruehen Ausstieg
// verschoben (waehrend oben ein Kommentar "demoGuard(" liegenblieb), oder mit
// fest verdrahteten Argumenten wie demoGuard('ping', 'GET'). Nur das
// vollstaendige Entfernen des Aufrufs fiel auf.
//
// token_get_all() statt strpos() faengt alle fuenf:
//   - Kommentare sind eigene Tokens (T_COMMENT, T_DOC_COMMENT) und werden
//     uebersprungen. Ein auskommentierter Aufruf zerfaellt dadurch nie in die
//     Tokenfolge "demoGuard", "(", "$resource", ... - er ist fuer die
//     Pruefung unsichtbar, genau wie fuer den PHP-Interpreter.
//   - Die geschweifte-Klammer-Tiefe wird mitgezaehlt; nur ein Aufruf auf
//     Tiefe 0 (nicht in einem if/for/function/... verschachtelt) zaehlt. Ein
//     Aufruf in einem toten if-Block liegt auf Tiefe > 0 und faellt raus.
//   - Es wird geprueft, dass die beiden Argumente Variablen sind ($resource,
//     $request_method) und keine fest verdrahteten Literale.
//   - K2: Bezugspunkt ist nicht mehr nur der ERSTE fruehe Ausstieg, sondern
//     JEDES "$resource === '...'" in der Datei. Ein frueher Ausstieg, der vor
//     Zeile 178 eingefuegt wird, faellt damit ebenfalls auf - vorher waere
//     die Pruefung daran vorbeigelaufen, weil sie nur den ersten Treffer
//     kannte.
//
// K1 - der Anker bleibt lueckenhaft, wie schon der Docblock von
// demoTestResourcesFromApi() festhaelt: er erkennt nur "$resource === '...'"
// (mit einem $resource-Token unmittelbar vor ===). Ein frueher Ausstieg ueber
// in_array($resource, [...]), match($resource) { ... }, lockeres
// $resource == 'x' oder Yoda-Schreibweise ('x' === $resource) bleibt
// unerkannt. Das ist tragbar, weil der Waechter zur geschlossenen Seite
// faellt: unbekannt heisst gesperrt, ein so uebersehener fruehe Ausstieg
// oeffnet also keine Luecke, sondern bliebe hoechstens unbemerkt strenger als
// noetig.

/** Naechster Tokenindex ab (und ggf. einschliesslich) $from, der kein T_WHITESPACE ist. */
function demoTestNextNonWhitespace(array $tokens, int $from): int
{
    $j = $from;
    while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
        $j++;
    }

    return $j;
}

/**
 * Wertet den Tokenstrom von api.php aus (siehe Erlaeuterung oben).
 *
 * @return array{callFound: bool, callIndex: int|null, anchorIndices: int[], callBeforeAllAnchors: bool}
 */
function demoTestGuardTokenPosition(): array
{
    $src = demoTestResourcesFromApi()['src'];
    $tokens = token_get_all($src);
    $count = count($tokens);

    $depth = 0;
    $callIndex = null;
    $anchorIndices = [];

    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];

        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            continue; // Kommentare zaehlen nicht als Code
        }

        // Oeffnende geschweifte Klammer: der normale Block-Fall ('{' als
        // Rohtoken) UND die komplexe String-Interpolation "{$var}" /
        // "${var}" - deren OEFFNENDE Klammer ist ein eigenes benanntes Token
        // (T_CURLY_OPEN bzw. T_DOLLAR_OPEN_CURLY_BRACES), deren SCHLIESSENDE
        // aber ein ganz normales rohes '}' ist. Ohne diesen Fall zaehlt jede
        // Stelle wie "{$prefix}" in einem String (in api.php mehrfach, z. B.
        // "SHOW TABLES LIKE '{$prefix}users'") die Tiefe einseitig herunter,
        // und jeder danach folgende echte Aufruf auf Tiefe 0 wuerde
        // faelschlich als "nicht auf oberster Ebene" verworfen.
        if ($t === '{' || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
            $depth++;
            continue;
        }
        if ($t === '}') {
            $depth--;
            continue;
        }

        // Anker: ein $resource-Token, direkt (ueber Whitespace hinweg) gefolgt von ===
        if (is_array($t) && $t[0] === T_VARIABLE && $t[1] === '$resource') {
            $j = demoTestNextNonWhitespace($tokens, $i + 1);
            if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_IS_IDENTICAL) {
                $anchorIndices[] = $i;
            }
        }

        // Wirksamer Aufruf: demoGuard($resource, $request_method) auf Tiefe 0
        if ($callIndex === null && $depth === 0 && is_array($t) && $t[0] === T_STRING && $t[1] === 'demoGuard') {
            $j = demoTestNextNonWhitespace($tokens, $i + 1);

            if (($tokens[$j] ?? null) === '(') {
                $j = demoTestNextNonWhitespace($tokens, $j + 1);

                if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_VARIABLE && $tokens[$j][1] === '$resource') {
                    $j = demoTestNextNonWhitespace($tokens, $j + 1);

                    if (($tokens[$j] ?? null) === ',') {
                        $j = demoTestNextNonWhitespace($tokens, $j + 1);

                        if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_VARIABLE && $tokens[$j][1] === '$request_method') {
                            $callIndex = $i;
                        }
                    }
                }
            }
        }
    }

    return [
        'callFound'            => $callIndex !== null,
        'callIndex'            => $callIndex,
        'anchorIndices'        => $anchorIndices,
        'callBeforeAllAnchors' => $callIndex !== null && $anchorIndices !== [] && $callIndex < min($anchorIndices),
    ];
}

test('Der Waechter wird tatsaechlich aufgerufen (Tokenpruefung, nicht Textsuche)', function () {
    $pos = demoTestGuardTokenPosition();

    assertTrue(
        $pos['callFound'],
        'Im Tokenstrom von api.php findet sich kein wirksamer Aufruf '
        . 'demoGuard($resource, $request_method) auf oberster Ebene. Entweder '
        . 'fehlt der Aufruf ganz, er ist auskommentiert, in einen toten Zweig '
        . '(z. B. if (...) { ... }) gekapselt, oder die Argumente sind nicht '
        . 'mehr die Variablen $resource und $request_method (z. B. fest '
        . "verdrahtet wie demoGuard('ping', 'GET')). In jedem dieser Faelle "
        . 'wirkt der Waechter im Demo-Modus nicht, obwohl er fertig '
        . 'implementiert und fuer sich getestet ist.'
    );
});

test('Der Waechter steht vor JEDEM "$resource === ..."-Vergleich', function () {
    $pos = demoTestGuardTokenPosition();

    if (!$pos['callFound']) {
        return; // bereits im vorigen Test gemeldet, hier nicht doppelt fehlschlagen
    }

    assertTrue(
        $pos['anchorIndices'] !== [],
        'Kein "$resource === ...\'-Vergleich in api.php gefunden - der '
        . 'Bezugspunkt fuer die Reihenfolge fehlt. api.php wurde vermutlich '
        . 'umgebaut; demoTestGuardTokenPosition() muss nachziehen.'
    );

    if ($pos['anchorIndices'] === []) {
        return;
    }

    assertTrue(
        $pos['callBeforeAllAnchors'],
        'demoGuard(...) steht in api.php NICHT vor allen "$resource === ...\'"'
        . '-Vergleichen (Aufruf-Tokenindex ' . $pos['callIndex'] . ', fruehester '
        . 'Vergleich bei Index ' . min($pos['anchorIndices']) . '). Abschnitt 6 '
        . 'beendet register und password_reset_request mit exit(), bevor das '
        . 'Routing in Abschnitt 10 erreicht wird - beide verschicken Mail an '
        . 'fremde Adressen und sind deshalb in DEMO_WRITE_DENIED gelistet. '
        . 'Steht der Waechter erst nach einem fruehen Ausstieg, laeuft dieser '
        . 'ungeschuetzt an ihm vorbei, obwohl die Listen ihn korrekt als '
        . 'gesperrt fuehren. Der Aufruf gehoert zwischen Abschnitt 5 (Rate '
        . 'Limiting) und Abschnitt 6 (oeffentliche Endpoints).'
    );
});
