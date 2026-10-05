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
 * GET ohne Weiterleitung; Kopfzeilen kleingeschrieben.
 *
 * @return array{status: int, headers: array<string, string>}
 */
function acFetch(string $url): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headers): int {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }

        return strlen($line);
    });

    if (curl_exec($ch) === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("HTTP-Anfrage fehlgeschlagen: {$err}");
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'headers' => $headers];
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
