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

/**
 * Ladeanzeige und Timeout der API-Wrapper (OI-85): statische Gegenproben.
 *
 * Entschieden am 2026-09-22: schmaler Balken oben, erst nach 300 ms, blockiert
 * nichts; 20 s Timeout, keiner fuer cleanup und update_check; beim Speichern
 * die Meldung "Ob gespeichert wurde, ist unklar"; Dashboard und Check-in-PWA,
 * die Station bleibt aussen vor.
 *
 * Laufzeitverhalten (Balken sichtbar, Meldung erscheint) prueft der Testplan
 * mit kuenstlich verzoegerter Antwort.
 */

$alRoot   = dirname(__DIR__, 2);
$alApi    = (string) file_get_contents($alRoot . '/public/js/modules/api.js');
$alPwa    = (string) file_get_contents($alRoot . '/public/checkin/js/app.js');
$alSet    = (string) file_get_contents($alRoot . '/public/js/modules/settings.js');
$alMain   = (string) file_get_contents($alRoot . '/public/css/main.css');
$alPwaCss = (string) file_get_contents($alRoot . '/public/checkin/css/style.css');

const AL_UNCLEAR = 'Ob gespeichert wurde, ist unklar';

foreach (['Dashboard' => 'alApi', 'PWA' => 'alPwa'] as $wo => $var) {
    test("{$wo}: Timeout ueber AbortController, 20 s", function () use (&$alApi, &$alPwa, $var) {
        $js = $var === 'alApi' ? $alApi : $alPwa;
        assertTrue(str_contains($js, 'new AbortController()'), 'Ohne AbortController wartet der Wrapper unbegrenzt');
        assertTrue(preg_match('/API_TIMEOUT_MS\s*=\s*20000\b/', $js) === 1, 'Timeout ist nicht 20 s');
    });

    test("{$wo}: Anzeige erst nach 300 ms, gezaehlt statt geschaltet", function () use (&$alApi, &$alPwa, $var) {
        $js = $var === 'alApi' ? $alApi : $alPwa;
        assertTrue(preg_match('/LOADING_DELAY_MS\s*=\s*300\b/', $js) === 1, 'Verzoegerung ist nicht 300 ms');
        // Ein Schalter blendete die Anzeige mit der ersten fertigen Antwort
        // aus, waehrend die zweite noch laeuft.
        assertTrue(str_contains($js, 'openRequests'), 'Die Anzeige haengt nicht an einem Zaehler offener Anfragen');
        assertTrue(preg_match('/finally\s*\{[^}]*loadingEnd\(\)/s', $js) === 1,
            'loadingEnd() steht nicht im finally -- ein Fehlerweg liesse den Balken stehen');
    });

    test("{$wo}: Timeout beim Speichern meldet ein unklares Ergebnis", function () use (&$alApi, &$alPwa, $var) {
        $js = $var === 'alApi' ? $alApi : $alPwa;
        assertTrue(str_contains($js, AL_UNCLEAR),
            'Ein Timeout bricht nur das Warten ab -- "fehlgeschlagen" verleitet zum doppelten Absenden');
        assertTrue(str_contains($js, "'AbortError'"), 'Der Timeout wird nicht vom Verbindungsfehler unterschieden');
    });
}

test('Dashboard: cleanup und update_check ohne Timeout', function () use ($alSet) {
    assertTrue(preg_match("/apiCall\\('cleanup',[^;]*timeout:\\s*0/s", $alSet) === 1,
        'Die Loeschfristen duerfen legitim laenger als 20 s laufen');
    assertTrue(preg_match("/apiCall\\('update_check', 'POST'[^;]*timeout:\\s*0/s", $alSet) === 1,
        'Das Holen des Update-Pakets darf legitim laenger als 20 s laufen');
});

test('PWA: ein Timeout zeigt keinen Offline-Hinweis', function () use ($alPwa) {
    $start = strpos($alPwa, 'async function apiCall(');
    $rumpf = substr($alPwa, $start, (strpos($alPwa, "\n}", $start) ?: $start + 5000) - $start);
    $catch = substr($rumpf, (int) strrpos($rumpf, 'catch (error)'));
    $abort = strpos($catch, "'AbortError'");
    $offline = strpos($catch, 'showOfflineIndicator()');
    assertTrue($abort !== false && $offline !== false && $abort < $offline,
        'Beim Timeout ist das Netz da -- der Offline-Hinweis gehoert nur zum Verbindungsfehler');
});

test('Balken: eigene Komponente im Dashboard, Regel in der PWA', function () use ($alMain, $alPwaCss, $alRoot) {
    assertTrue(is_file($alRoot . '/public/css/components/loading.css'), 'components/loading.css fehlt');
    assertTrue(str_contains($alMain, "components/loading.css"), 'main.css bindet loading.css nicht ein');
    assertTrue(str_contains($alPwaCss, '.api-loading-bar'), 'Die PWA hat keine Regel fuer den Balken');
});
