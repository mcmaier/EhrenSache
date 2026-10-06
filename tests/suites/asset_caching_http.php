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
require_once __DIR__ . '/../../private/helpers/css_bundle.php';

/**
 * Version im Pfad (OI-120, OI-74): was der Server tatsaechlich ausliefert.
 *
 * Dashboard und Anmeldeseite laden CSS und JS ueber css/v<Version>/… und
 * js/v<Version>/…; public/.htaccess bildet das auf die echten Dateien ab und
 * gibt solchen Antworten ein Jahr Cache. Die statische Gegenprobe in
 * assets.php sieht nur die Regel -- ob Apache sie so anwendet (Rewrite,
 * Umgebungsvariable nach dem internen Umschreiben, Header-Reihenfolge), zeigt
 * nur eine echte Anfrage. Spec docs/superpowers/specs/2026-10-05-version-im-pfad-design.md.
 */

$acBase    = rtrim(testConfig()['base_url'], '/');
$acVersion = json_decode((string) sourceCode(dirname(__DIR__, 2) . '/version.json'), true)['version'];

/**
 * GET ohne Weiterleitung; Kopfzeilen kleingeschrieben. curl entpackt nicht
 * selbst (kein CURLOPT_ENCODING): die Tests sehen die Antwort, wie sie kommt.
 *
 * @param list<string> $requestHeaders z. B. ['Accept-Encoding: gzip']
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function acFetch(string $url, array $requestHeaders = []): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    // Pfad unveraendert schicken: Die Faelle unten pruefen gerade, was Apache
    // aus kodierten Abschnitten macht -- curl soll nichts vorab bereinigen.
    curl_setopt($ch, CURLOPT_PATH_AS_IS, true);
    if ($requestHeaders !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
    }
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headers): int {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }

        return strlen($line);
    });

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP-Anfrage fehlgeschlagen: {$err}");
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
}

test('Versionierter Pfad: main.css kommt mit langem Caching', function () use ($acBase, $acVersion) {
    $r = acFetch("{$acBase}/css/v{$acVersion}/main.css");
    assertSame(200, $r['status'], 'css/v…/main.css nicht erreichbar -- greift die Rewrite-Regel?');
    assertTrue(str_starts_with($r['headers']['content-type'] ?? '', 'text/css'),
        'Falscher Inhaltstyp: ' . ($r['headers']['content-type'] ?? '-'));
    assertTrue(str_contains($r['headers']['cache-control'] ?? '', 'immutable'),
        'Kein langes Caching: ' . ($r['headers']['cache-control'] ?? '-'));
});

test('Versionierter Pfad: ein Modul kommt mit langem Caching', function () use ($acBase, $acVersion) {
    $r = acFetch("{$acBase}/js/v{$acVersion}/modules/ui.js");
    assertSame(200, $r['status'], 'js/v…/modules/ui.js nicht erreichbar');
    assertTrue(str_contains($r['headers']['content-type'] ?? '', 'javascript'),
        'Falscher Inhaltstyp: ' . ($r['headers']['content-type'] ?? '-'));
    assertTrue(str_contains($r['headers']['cache-control'] ?? '', 'immutable'),
        'Kein langes Caching: ' . ($r['headers']['cache-control'] ?? '-'));
});

test('Ohne Version bleibt es bei no-cache', function () use ($acBase) {
    // js/vendor/ beginnt mit "v", ist aber kein Versionsabschnitt.
    foreach (['/js/app.js', '/js/vendor/qrcode.js', '/css/main.css'] as $path) {
        $r = acFetch($acBase . $path);
        assertSame(200, $r['status'], "{$path} nicht erreichbar");
        $cc = $r['headers']['cache-control'] ?? '';
        assertTrue(str_contains($cc, 'no-cache') && !str_contains($cc, 'immutable'),
            "{$path}: erwartet no-cache, bekommen: {$cc}");
    }
});

test('Die Seite selbst bleibt no-cache', function () use ($acBase) {
    // /index.html leitet per 301 auf das Verzeichnis um -- daher das Verzeichnis.
    $r = acFetch($acBase . '/');
    assertSame(200, $r['status'], 'Dashboard-Seite nicht erreichbar');
    assertTrue(str_contains($r['headers']['cache-control'] ?? '', 'no-cache'),
        'Die Seite traegt die Version und muss revalidiert werden: ' . ($r['headers']['cache-control'] ?? '-'));
});

test('Versionierter Pfad zu einer fehlenden Datei ergibt 404', function () use ($acBase) {
    assertSame(404, acFetch($acBase . '/js/v9.9.9/gibtsnicht.js')['status']);
});

test('Kodierte Abschnitte im versionierten Pfad bekommen kein langes Caching', function () use ($acBase, $acVersion) {
    // %{REQUEST_URI} ist bereits dekodiert, das interne Umschreiben dekodiert
    // ein zweites Mal: Aus %252e%252e wurde so "..", aus %2561 ein "a". Mit der
    // frueheren, weiten Regel lieferte js/v…/%252e%252e/login.html die
    // Anmeldeseite mit einem Jahr Cache aus -- eine Seite, die nie veralten darf.
    //
    // Erwartet: 404 (oder 400/403). Der index.html-Fall endet vorher in der
    // allgemeinen Umleitung "index.html -> Verzeichnis" (301 auf .../%252e%252e/,
    // dort 404) -- auch das ist in Ordnung, solange kein langes Caching dranhaengt.
    $paths = [
        "/js/v{$acVersion}/%252e%252e/login.html",
        "/css/v{$acVersion}/%252e%252e/index.html",
        "/js/v{$acVersion}/%2561pp.js",
    ];
    $verstoesse = [];
    foreach ($paths as $path) {
        $r  = acFetch($acBase . $path);
        $cc = $r['headers']['cache-control'] ?? '';
        if (!in_array($r['status'], [301, 400, 403, 404], true) || str_contains($cc, 'immutable')) {
            $verstoesse[] = "{$path}: Status {$r['status']}, Cache-Control: " . ($cc === '' ? '-' : $cc);
        }
    }
    assertTrue($verstoesse === [], "Kodierter Pfad falsch abgebildet:\n  " . implode("\n  ", $verstoesse));
});

test('CSS-Buendel: main.css und login.css kommen als eine Antwort', function () use ($acBase, $acVersion) {
    // OI-120 Weg 1. Der Inhalt muss exakt dem Buendel entsprechen -- damit
    // faellt auch auf, wenn Apache die statische main.css mit @import liefert.
    foreach (['main', 'login'] as $entry) {
        $r = acFetch("{$acBase}/css/v{$acVersion}/{$entry}.css");
        assertSame(200, $r['status'], "{$entry}.css: Status {$r['status']}");
        assertTrue(str_starts_with($r['headers']['content-type'] ?? '', 'text/css'),
            "{$entry}.css: Inhaltstyp " . ($r['headers']['content-type'] ?? '-'));
        assertTrue(str_contains($r['headers']['cache-control'] ?? '', 'immutable'),
            "{$entry}.css: kein langes Caching: " . ($r['headers']['cache-control'] ?? '-'));
        assertTrue(!str_contains($r['body'], '@import'), "{$entry}.css: @import in der Antwort -- greift die Buendel-Regel?");
        assertSame(cssBundle($entry), $r['body'], "{$entry}.css: Antwort weicht vom Buendel ab");
    }
});

test('CSS-Buendel: eine fremde Version bekommt kein langes Caching', function () use ($acBase) {
    $r  = acFetch("{$acBase}/css/v0.0.1/main.css");
    $cc = $r['headers']['cache-control'] ?? '';
    assertSame(200, $r['status'], 'Alte Seite nach Update braucht trotzdem CSS');
    assertTrue(str_contains($cc, 'no-cache') && !str_contains($cc, 'immutable'), "Bekommen: {$cc}");
});

test('CSS-Buendel: die Einzeldateien bleiben statisch erreichbar', function () use ($acBase, $acVersion) {
    $r = acFetch("{$acBase}/css/main.css");
    assertSame(200, $r['status']);
    assertTrue(str_contains($r['body'], '@import'), 'css/main.css ohne Version muss die Quelldatei sein');
    assertTrue(str_contains($r['headers']['cache-control'] ?? '', 'no-cache'), 'css/main.css ohne Version: no-cache');

    $r = acFetch("{$acBase}/css/v{$acVersion}/components/buttons.css");
    assertSame(200, $r['status']);
    assertTrue(str_contains($r['headers']['cache-control'] ?? '', 'immutable'), 'Versionierte Einzeldatei: immutable');
});

test('CSS-Buendel: kein fremder Einstieg ueber den Parameter', function () use ($acBase) {
    foreach (['../../private/config/config', 'print', '', 'main.css'] as $entry) {
        $r = acFetch("{$acBase}/css/bundle.php?entry=" . rawurlencode($entry));
        assertSame(404, $r['status'], "entry={$entry}: Status {$r['status']}");
    }
});

test('CSS-Buendel: ein Array als Einstieg ist ein 404 ohne Fehlertext', function () use ($acBase) {
    // ?entry[]=x ist ein Array; ohne Absicherung gaebe das Warning (und bei
    // display_errors den Serverpfad) in den Rumpf.
    $r = acFetch("{$acBase}/css/bundle.php?entry[]=x");
    assertSame(404, $r['status'], "Status {$r['status']}");
    assertTrue(!stripos($r['body'], 'warning') && !str_contains($r['body'], 'bundle.php'),
        "Fehlertext im Rumpf: {$r['body']}");
});

test('Kompression: das CSS-Buendel kommt mit gzip, wenn der Browser es annimmt', function () use ($acBase, $acVersion) {
    $r = acFetch("{$acBase}/css/v{$acVersion}/main.css", ['Accept-Encoding: gzip']);
    assertSame(200, $r['status']);
    assertSame('gzip', $r['headers']['content-encoding'] ?? '', 'Buendel nicht komprimiert');
    assertTrue(stripos($r['headers']['vary'] ?? '', 'accept-encoding') !== false,
        'Vary: Accept-Encoding fehlt: ' . ($r['headers']['vary'] ?? '-'));
    $entpackt = @gzdecode($r['body']);
    assertTrue($entpackt !== false, 'Rumpf ist kein gzip');
    assertSame(cssBundle('main'), $entpackt, 'Entpacktes Buendel weicht ab');
});

test('Kompression: ohne Accept-Encoding kommt das Buendel unkomprimiert', function () use ($acBase, $acVersion) {
    $r = acFetch("{$acBase}/css/v{$acVersion}/main.css");
    assertSame(200, $r['status']);
    assertTrue(!isset($r['headers']['content-encoding']), 'Unerwartet komprimiert: ' . ($r['headers']['content-encoding'] ?? ''));
    assertTrue(stripos($r['headers']['vary'] ?? '', 'accept-encoding') !== false, 'Vary fehlt auch hier');
    assertSame(cssBundle('main'), $r['body']);
});

test('Kompression: JS, Dashboard-Seite und Check-in-App kommen mit gzip', function () use ($acBase, $acVersion) {
    // Setzt mod_deflate + mod_filter im lokalen Apache voraus (httpd.conf).
    $pfade = [
        "/js/v{$acVersion}/modules/ui.js",
        '/',
        "/checkin/js/app.js?v={$acVersion}",
        '/station/',
    ];
    foreach ($pfade as $p) {
        $r = acFetch($acBase . $p, ['Accept-Encoding: gzip']);
        assertSame(200, $r['status'], "{$p}: Status {$r['status']}");
        assertSame('gzip', $r['headers']['content-encoding'] ?? '',
            "{$p}: nicht komprimiert -- mod_deflate nicht geladen? (httpd.conf, LoadModule deflate_module und filter_module)");
        assertTrue(@gzdecode($r['body']) !== false, "{$p}: Rumpf ist kein gzip");
    }
});

test('Kompression: API und PHP-Seiten bleiben unkomprimiert (BREACH)', function () use ($acBase) {
    foreach (['/api/api.php?resource=ping', '/reset_password.php'] as $p) {
        $r = acFetch($acBase . $p, ['Accept-Encoding: gzip']);
        assertSame(200, $r['status'], "{$p}: Status {$r['status']}");
        assertTrue(!isset($r['headers']['content-encoding']),
            "{$p}: komprimiert (" . ($r['headers']['content-encoding'] ?? '') . ") -- no-gzip fuer .php greift nicht oder json steht in der Typliste");
    }
});

test('Kompression: Revalidierung der Seite mit dem gzip-ETag ergibt 304', function () use ($acBase) {
    // Apache 2.4 haengt an komprimierte Antworten "-gzip" an das ETag. Passt es
    // als If-None-Match nicht mehr, liefert jede Revalidierung 200 mit vollem
    // Inhalt -- fuer alle no-cache-Dateien (HTML, Check-in-App, Station).
    foreach (['/', '/checkin/', '/station/'] as $p) {
        $erst = acFetch($acBase . $p, ['Accept-Encoding: gzip']);
        $etag = $erst['headers']['etag'] ?? '';
        assertTrue($etag !== '', "{$p}: kein ETag");
        $zweit = acFetch($acBase . $p, ['Accept-Encoding: gzip', "If-None-Match: {$etag}"]);
        assertSame(304, $zweit['status'], "{$p}: Revalidierung mit {$etag} ergibt {$zweit['status']} statt 304");
    }
});
