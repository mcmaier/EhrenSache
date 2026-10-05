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
 * Sitzungssperre: parallele Aufrufe desselben Browsers.
 *
 * PHP sperrt die Sitzungsdatei von session_start() bis session_write_close()
 * bzw. Skriptende. Bis 1.20.0 hielt api.php die Sperre ueber den ganzen
 * Handler: Das Dashboard schickt mehrere Abrufe gleichzeitig, der Server
 * arbeitete sie nacheinander ab, und jeder wartende Abruf belegte einen
 * PHP-Prozess (auf der Demo gemessen: available_years wartete 1,2 s hinter
 * users). Seit dem Fix schliesst api.php die Sitzung direkt nach dem letzten
 * Schreibzugriff (last_activity).
 *
 * Kehrseite: Ein Schreibzugriff nach dem Schliessen geht still verloren. Die
 * statischen Tests unten halten fest, dass danach nichts mehr in $_SESSION
 * schreibt.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/source.php';
require_once __DIR__ . '/../../private/helpers/config_reader.php';

const SL_API      = __DIR__ . '/../../public/api/api.php';
const SL_HANDLERS = __DIR__ . '/../../private/handlers';

/** Meldet sich wie das Dashboard an: Sitzungscookie und CSRF-Token. */
function slLogin(string $role = 'admin'): array
{
    $cfg = testConfig();
    $res = apiRequest('POST', 'login', ['body' => [
        'email' => $cfg[$role]['email'], 'password' => $cfg[$role]['password'],
    ]]);
    assertStatus(200, $res, "Anmeldung als {$role} fehlgeschlagen");
    assertTrue(!empty($res['set_cookie']), 'Anmeldung lieferte kein Set-Cookie');
    assertTrue(!empty($res['body']['csrf_token']), 'Anmeldung lieferte kein CSRF-Token');

    return [explode(';', (string) $res['set_cookie'])[0], (string) $res['body']['csrf_token']];
}

/** Direkte Verbindung zur Datenbank der Instanz, die die Tests ansprechen. */
function slPdo(): PDO
{
    $cfg = configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'));
    $db  = $cfg['db'];

    return new PDO(
        'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

// --------------------------------------------
// Statisch: nach dem Schliessen schreibt niemand mehr
// --------------------------------------------

/** Schreibzugriffe auf die Sitzung, die nach session_write_close() verloren gingen. */
const SL_WRITE_PATTERN = '/\$_SESSION\s*\[[^\]]*\]\s*(?:=(?!=)|\.=|\+=|\-=)|unset\s*\(\s*\$_SESSION|session_(?:regenerate_id|destroy|unset|start)\s*\(/';

test('api.php schliesst die Sitzung genau einmal, im Sitzungszweig der Authentifizierung', function () {
    $code = sourceCode(SL_API);
    assertSame(1, preg_match_all('/session_write_close\s*\(/', $code),
        'session_write_close() muss genau einmal in api.php stehen');

    $close    = strpos($code, 'session_write_close(');
    $activity = strpos($code, "\$_SESSION['last_activity'] = time();");
    assertTrue($activity !== false, 'Stempel last_activity in api.php nicht gefunden');
    assertTrue($close > $activity,
        'session_write_close() steht vor dem Stempel last_activity — der Stempel ginge verloren');
});

test('api.php schreibt nach session_write_close() nicht mehr in die Sitzung', function () {
    $code  = sourceCode(SL_API);
    $close = strpos($code, 'session_write_close(');
    assertTrue($close !== false, 'session_write_close() fehlt in api.php');

    $rest = substr($code, $close + strlen('session_write_close('));
    $treffer = [];
    preg_match_all(SL_WRITE_PATTERN, $rest, $treffer);
    assertSame([], $treffer[0],
        'Schreibzugriff auf $_SESSION nach session_write_close(): ' . implode(', ', $treffer[0]));
});

test('Handler schreiben nie in die Sitzung (sie laufen nach dem Schliessen)', function () {
    $funde = [];
    foreach (projectFiles(SL_HANDLERS, 'php') as $datei) {
        foreach (sourceLines($datei) as $i => $zeile) {
            if (preg_match(SL_WRITE_PATTERN, $zeile)) {
                $funde[] = basename($datei) . ':' . ($i + 1);
            }
        }
    }
    assertSame([], $funde, 'Schreibzugriff auf die Sitzung in einem Handler: ' . implode(', ', $funde));
});

test('Sitzungsschreibende Helfer laufen nur vor der Authentifizierung', function () {
    // login(), logout() und generateCSRFToken() schreiben in die Sitzung. Sie
    // duerfen nur aus den oeffentlichen Endpunkten (Abschnitt 6) aufgerufen
    // werden, nie aus einem Handler oder nach dem Schliessen.
    $code  = sourceCode(SL_API);
    $close = strpos($code, 'session_write_close(');
    $rest  = substr($code, (int) $close);
    foreach (['login(', 'logout(', 'generateCSRFToken('] as $fn) {
        assertTrue(!preg_match('/(?<![\w>$])' . preg_quote($fn, '/') . '/', $rest),
            "{$fn}) wird nach session_write_close() aufgerufen");
        foreach (projectFiles(SL_HANDLERS, 'php') as $datei) {
            assertTrue(!preg_match('/(?<![\w>$])' . preg_quote($fn, '/') . '/', sourceCode($datei)),
                basename($datei) . " ruft {$fn}) auf — die Sitzung ist dort schon geschlossen");
        }
    }
});

if (!extension_loaded('curl')) {
    return;
}

// --------------------------------------------
// Verhalten über HTTP
// --------------------------------------------

test('Ein laufender Abruf blockiert keinen zweiten Abruf derselben Sitzung', function () {
    [$cookie] = slLogin();
    $pdo    = slPdo();
    $prefix = configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'))['db']['prefix'];
    $table  = $prefix . 'member_groups';

    // Abruf A haengt nachweisbar im Handler: Die Tabelle ist gesperrt, A wartet
    // auf MySQL — also hinter session_start(). Ohne Fix haelt A dabei die
    // Sitzungsdatei, und B kommt erst nach A durch.
    $pdo->exec("LOCK TABLES `{$table}` WRITE");
    $multi = curl_multi_init();
    $a     = curl_init(rtrim(testConfig()['base_url'], '/') . '/api/api.php?resource=member_groups');
    curl_setopt_array($a, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE         => $cookie,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    curl_multi_add_handle($multi, $a);

    try {
        $wartet = false;
        $frist  = microtime(true) + 10;
        while (microtime(true) < $frist) {
            curl_multi_exec($multi, $laufend);
            $wartet = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.processlist
                  WHERE state LIKE 'Waiting for table%' AND info LIKE '%{$table}%'"
            )->fetchColumn() > 0;
            if ($wartet) {
                break;
            }
            usleep(50000);
        }
        assertTrue($wartet, 'Vorbedingung: Abruf A erreichte die gesperrte Tabelle nicht');

        $start = microtime(true);
        $b     = apiRequest('GET', 'me', ['cookie' => $cookie]);
        $dauer = microtime(true) - $start;
    } catch (RuntimeException $e) {
        // apiRequest bricht nach 15 s ab: B hing hinter der Sitzungssperre.
        $b     = ['status' => 0, 'raw' => $e->getMessage()];
        $dauer = 15.0;
    } finally {
        $pdo->exec('UNLOCK TABLES');
        do {
            curl_multi_exec($multi, $laufend);
            curl_multi_select($multi, 0.1);
        } while ($laufend > 0);
        $statusA = (int) curl_getinfo($a, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $a);
        curl_multi_close($multi);
    }

    assertStatus(200, $b, 'Abruf B blieb hinter der Sitzungssperre von Abruf A haengen');
    assertTrue($dauer < 5, sprintf('Abruf B brauchte %.1f s — er wartete auf Abruf A', $dauer));
    assertSame(200, $statusA, 'Abruf A muss nach dem Entsperren regulaer antworten');
});

test('CSRF-Token der Anmeldung gilt nach geschlossenen Abrufen weiter', function () {
    [$cookie, $csrf] = slLogin();
    assertStatus(200, apiRequest('GET', 'member_groups', ['cookie' => $cookie]));
    assertStatus(200, apiRequest('GET', 'me', ['cookie' => $cookie]));

    // Untergruppe und Standardgruppe zugleich: Der Handler lehnt das mit 400 ab,
    // bevor er etwas schreibt. 403 hiesse, CSRF hat abgewiesen.
    $ungueltig = ['is_subgroup' => true, 'is_default' => true];
    $mit = apiRequest('POST', 'member_groups',
        ['cookie' => $cookie, 'body' => $ungueltig + ['csrf_token' => $csrf]]);
    assertStatus(400, $mit, 'Gueltiges CSRF-Token muss bis zur Validierung durchkommen');

    $ohne = apiRequest('POST', 'member_groups', ['cookie' => $cookie, 'body' => $ungueltig]);
    assertStatus(403, $ohne, 'Ohne CSRF-Token muss der Aufruf weiterhin scheitern');
});

test('Abmelden wirkt nach geschlossenen Abrufen', function () {
    [$cookie] = slLogin();
    assertStatus(200, apiRequest('GET', 'me', ['cookie' => $cookie]));
    assertStatus(200, apiRequest('POST', 'logout', ['cookie' => $cookie, 'body' => []]));
    assertStatus(401, apiRequest('GET', 'me', ['cookie' => $cookie]),
        'Nach dem Abmelden darf die Sitzung nicht mehr tragen');
});
