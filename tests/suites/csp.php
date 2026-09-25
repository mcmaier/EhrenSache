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
 * Content-Security-Policy, Etappe 1 von OI-17: Anmeldung, Check-in-PWA und
 * virtuelle Station.
 *
 * Eine CSP ohne 'unsafe-inline' legt jeden Inline-Handler still — nicht mit
 * einem Fehler, sondern mit einem Knopf, der nichts tut. Diese Suite haelt
 * deshalb zweierlei fest:
 *
 * 1. Die drei Oberflaechen enthalten keinen Inline-Code, der unter der
 *    Richtlinie ausfiele (Handler-Attribute, Inline-Skripte, javascript:-URLs).
 * 2. Die Richtlinie ist gesetzt, wirkt nur dort, wo sie hingehoert, und
 *    verzichtet in script-src auf jede Aufweichung.
 *
 * Kommentarzeilen zaehlen nicht als Fund, Code-Zeilen mit angehaengtem
 * Kommentar dagegen schon: Lieber ein Fehlalarm als ein Waechter, den ein
 * erklaerender Kommentar gruen haelt (OI-107).
 *
 * Das Dashboard (index.html) ist Etappe 2 und hier bewusst nicht erfasst.
 */

$cspRoot = dirname(__DIR__, 2);

/** Die Dateien der Etappe 1, deren Markup oder Code unter die CSP faellt. */
$cspSurfaces = [
    'Anmeldung' => [
        'html' => ['/public/login.html'],
        'js'   => ['/public/js/login.js', '/public/js/theme.js', '/public/js/config.js'],
    ],
    'Check-in-PWA' => [
        'html' => ['/public/checkin/index.html'],
        'js'   => ['/public/checkin/js/app.js', '/public/checkin/service-worker.js'],
    ],
    'Station' => [
        'html' => ['/public/station/index.html'],
        'js'   => ['/public/station/js/app.js', '/public/station/service-worker.js'],
    ],
];

/** Ereignisnamen, deren on…-Attribut eine CSP ohne 'unsafe-inline' blockiert. */
const CSP_EVENT_PATTERN = '(?:click|dblclick|change|input|submit|reset|invalid|select|toggle'
    . '|key(?:down|up|press)|mouse[a-z]+|pointer[a-z]+|touch[a-z]+|focus(?:in|out)?|blur'
    . '|load|error|abort|scroll|wheel|drag[a-z]*|drop|contextmenu|copy|cut|paste'
    . '|play|pause|ended|animation[a-z]+|transition[a-z]+)';

/**
 * HTML ohne Kommentare — ein auskommentierter Knopf ist kein Inline-Code. Die
 * Zeilenumbrueche bleiben stehen, damit die Fundstellen ihre Zeilennummer behalten.
 */
function cspStripHtmlComments(string $html): string
{
    return (string) preg_replace_callback(
        '/<!--.*?-->/s',
        static fn (array $m): string => str_repeat("
", substr_count($m[0], "
")),
        $html
    );
}

/**
 * Zeilen, die ein Muster treffen und keine reinen Kommentarzeilen sind.
 *
 * @return array<int, string> Zeilennummer => Zeile
 */
function cspCodeLinesMatching(string $source, string $pattern): array
{
    $hits = [];
    foreach (preg_split('/\R/', $source) ?: [] as $i => $line) {
        $trim = ltrim($line);
        if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '/*')) {
            continue;
        }
        if (preg_match($pattern, $line) === 1) {
            $hits[$i + 1] = trim($line);
        }
    }

    return $hits;
}

/** @param array<int, string> $hits */
function cspFormatHits(string $file, array $hits): string
{
    $out = [];
    foreach ($hits as $no => $line) {
        $out[] = "{$file}:{$no}: " . mb_substr($line, 0, 110);
    }

    return implode("\n  ", $out);
}

/**
 * Die gesetzten CSP-Kopfzeilen einer .htaccess, jeweils mit dem umgebenden
 * <Files>-Abschnitt (null = gilt fuer das ganze Verzeichnis).
 *
 * @return array<int, array{files: ?string, policy: string, directive: string}>
 */
function cspHeadersIn(string $htaccess): array
{
    $found = [];
    $files = null;
    foreach (preg_split('/\R/', $htaccess) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^<Files(?:Match)?\s+"?([^">]+)"?\s*>$/i', $line, $m) === 1) {
            $files = $m[1];
            continue;
        }
        if (preg_match('#^</Files(?:Match)?>$#i', $line) === 1) {
            $files = null;
            continue;
        }
        if (preg_match('/^Header\s+(?:always\s+)?set\s+(Content-Security-Policy(?:-Report-Only)?)\s+"([^"]+)"$/i', $line, $m) === 1) {
            $found[] = ['files' => $files, 'directive' => $m[1], 'policy' => $m[2]];
        }
    }

    return $found;
}

/** @return array<string, array<int, string>> Direktive => Quellen */
function cspParse(string $policy): array
{
    $out = [];
    foreach (explode(';', $policy) as $part) {
        $tokens = preg_split('/\s+/', trim($part)) ?: [];
        if ($tokens === [] || $tokens[0] === '') {
            continue;
        }
        $out[strtolower(array_shift($tokens))] = $tokens;
    }

    return $out;
}

/** Wo die Richtlinie der jeweiligen Oberflaeche steht. */
$cspLocations = [
    'Anmeldung'    => ['htaccess' => '/public/.htaccess',         'files' => 'login.html', 'url' => '/login.html'],
    'Check-in-PWA' => ['htaccess' => '/public/checkin/.htaccess', 'files' => null,         'url' => '/checkin/'],
    'Station'      => ['htaccess' => '/public/station/.htaccess', 'files' => null,         'url' => '/station/'],
];

/** Die eine CSP-Kopfzeile einer Oberflaeche, oder ein Testfehler. */
function cspPolicyFor(string $root, array $loc, string $label): array
{
    $path = $root . $loc['htaccess'];
    assertTrue(is_file($path), "{$label}: {$loc['htaccess']} fehlt");

    $headers = array_values(array_filter(
        cspHeadersIn((string) file_get_contents($path)),
        static fn (array $h): bool => $h['files'] === $loc['files']
    ));
    assertSame(1, count($headers), "{$label}: erwartet genau eine CSP-Kopfzeile in {$loc['htaccess']}"
        . ($loc['files'] !== null ? " im Abschnitt <Files \"{$loc['files']}\">" : ' auf Verzeichnisebene'));

    return $headers[0];
}

// ---------------------------------------------------------------------------
// 1. Kein Inline-Code
// ---------------------------------------------------------------------------

test('CSP Etappe 1: keine Inline-Handler in HTML und JS-Templates', function () use ($cspRoot, $cspSurfaces) {
    // Das Muster verlangt ein Anfuehrungszeichen hinter dem "=": Es trifft
    // onclick="…" im Markup und in Template-Strings, nicht aber die erlaubte
    // Zuweisung el.onclick = fn.
    $pattern = '/(?<![\w.-])on' . CSP_EVENT_PATTERN . '\s*=\s*["\'`]/i';

    $fehler = [];
    foreach ($cspSurfaces as $label => $files) {
        foreach (array_merge($files['html'], $files['js']) as $rel) {
            $src = (string) file_get_contents($cspRoot . $rel);
            if (str_ends_with($rel, '.html')) {
                $src = cspStripHtmlComments($src);
            }
            $hits = cspCodeLinesMatching($src, $pattern);
            if ($hits !== []) {
                $fehler[] = cspFormatHits($rel, $hits);
            }
        }
    }

    assertTrue($fehler === [], "Inline-Handler fallen unter der CSP still aus:\n  " . implode("\n  ", $fehler));
});

test('CSP Etappe 1: kein setAttribute mit on…-Handler', function () use ($cspRoot, $cspSurfaces) {
    $pattern = '/setAttribute\(\s*["\']on/i';

    $fehler = [];
    foreach ($cspSurfaces as $files) {
        foreach ($files['js'] as $rel) {
            $hits = cspCodeLinesMatching((string) file_get_contents($cspRoot . $rel), $pattern);
            if ($hits !== []) {
                $fehler[] = cspFormatHits($rel, $hits);
            }
        }
    }

    assertTrue($fehler === [], "Handler per setAttribute sind Inline-Code:\n  " . implode("\n  ", $fehler));
});

test('CSP Etappe 1: keine Inline-Skripte', function () use ($cspRoot, $cspSurfaces) {
    $fehler = [];
    foreach ($cspSurfaces as $files) {
        foreach ($files['html'] as $rel) {
            $html = cspStripHtmlComments((string) file_get_contents($cspRoot . $rel));
            preg_match_all('/<script\b[^>]*>/i', $html, $m);
            foreach ($m[0] as $tag) {
                // JSON-Daten waeren erlaubt, ausfuehrbarer Code nur mit src.
                if (stripos($tag, ' src=') === false && stripos($tag, 'application/json') === false) {
                    $fehler[] = "{$rel}: {$tag}";
                }
            }
        }
    }

    assertTrue($fehler === [], "Inline-Skripte blockiert die CSP:\n  " . implode("\n  ", $fehler));
});

test('CSP Etappe 1: keine javascript:-URLs', function () use ($cspRoot, $cspSurfaces) {
    $pattern = '/(?:href|src|action)\s*=\s*["\'`]?\s*javascript:/i';

    $fehler = [];
    foreach ($cspSurfaces as $files) {
        foreach (array_merge($files['html'], $files['js']) as $rel) {
            $src = (string) file_get_contents($cspRoot . $rel);
            if (str_ends_with($rel, '.html')) {
                $src = cspStripHtmlComments($src);
            }
            $hits = cspCodeLinesMatching($src, $pattern);
            if ($hits !== []) {
                $fehler[] = cspFormatHits($rel, $hits);
            }
        }
    }

    assertTrue($fehler === [], "javascript:-URLs blockiert die CSP:\n  " . implode("\n  ", $fehler));
});

// ---------------------------------------------------------------------------
// 2. Die Richtlinie
// ---------------------------------------------------------------------------

test('CSP Etappe 1: jede Oberflaeche hat genau eine scharfe Richtlinie', function () use ($cspRoot, $cspLocations) {
    foreach ($cspLocations as $label => $loc) {
        $header = cspPolicyFor($cspRoot, $loc, $label);

        // Report-Only schuetzt nicht. Als Zwischenstand beim Testen erlaubt,
        // ausgeliefert wird die scharfe Fassung.
        assertSame('Content-Security-Policy', $header['directive'], "{$label}: Richtlinie steht nur auf Report-Only");

        $csp = cspParse($header['policy']);

        assertSame(["'self'"], $csp['default-src'] ?? null, "{$label}: default-src muss 'self' sein");
        assertSame(["'self'"], $csp['script-src'] ?? null,
            "{$label}: script-src muss genau 'self' sein — jede weitere Quelle weicht den XSS-Schutz auf");
        assertSame(["'none'"], $csp['object-src'] ?? null, "{$label}: object-src muss 'none' sein");
        assertSame(["'self'"], $csp['base-uri'] ?? null, "{$label}: base-uri muss 'self' sein");
        assertSame(["'none'"], $csp['frame-ancestors'] ?? null, "{$label}: frame-ancestors muss 'none' sein");
        assertSame(["'self'"], $csp['form-action'] ?? null, "{$label}: form-action muss 'self' sein");

        foreach ($csp as $name => $sources) {
            foreach ($sources as $source) {
                assertTrue(!in_array($source, ['*', 'http:', 'https:'], true),
                    "{$label}: {$name} erlaubt beliebige Quellen ({$source})");
                assertTrue($source !== "'unsafe-eval'", "{$label}: {$name} erlaubt 'unsafe-eval'");
                if ($name !== 'style-src') {
                    assertTrue($source !== "'unsafe-inline'",
                        "{$label}: 'unsafe-inline' ist nur in style-src als bewusste Ausnahme zulaessig");
                }
            }
        }
    }
});

test('CSP Etappe 1: die Richtlinie der Anmeldung gilt nicht fuer das Dashboard', function () use ($cspRoot) {
    // index.html traegt noch ueber hundert Inline-Handler (Etappe 2). Eine CSP
    // auf Verzeichnisebene in public/.htaccess wuerde sie still lahmlegen.
    $headers = cspHeadersIn((string) file_get_contents($cspRoot . '/public/.htaccess'));
    foreach ($headers as $h) {
        assertSame('login.html', $h['files'],
            'public/.htaccess setzt eine CSP ausserhalb von <Files "login.html"> — das trifft auch das Dashboard');
    }
});

// Dass keine Oberflaeche Skripte von aussen laedt, prueft tests/suites/assets.php.

test('CSP Etappe 1: der Server liefert die Richtlinie aus', function () use ($cspRoot, $cspLocations) {
    // Die Kopfzeile in der .htaccess nuetzt nichts, wenn mod_headers fehlt
    // oder der <Files>-Abschnitt den Aufruf nicht trifft.
    $base = rtrim(testConfig()['base_url'], '/');

    foreach ($cspLocations as $label => $loc) {
        $expected = cspPolicyFor($cspRoot, $loc, $label)['policy'];

        $got = null;
        $ch  = curl_init($base . $loc['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$got): int {
            if (stripos($header, 'Content-Security-Policy:') === 0) {
                $got = trim(substr($header, strlen('Content-Security-Policy:')));
            }

            return strlen($header);
        });
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        assertTrue($ok !== false, "{$label}: {$loc['url']} nicht erreichbar");
        assertSame(200, $status, "{$label}: {$loc['url']} liefert HTTP {$status}");
        assertSame($expected, $got, "{$label}: {$loc['url']} liefert nicht die CSP aus {$loc['htaccess']}");
    }

    // Gegenprobe: das Dashboard bleibt bis Etappe 2 ohne Richtlinie.
    $got = null;
    $ch  = curl_init($base . '/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$got): int {
        if (stripos($header, 'Content-Security-Policy') === 0) {
            $got = trim($header);
        }

        return strlen($header);
    });
    curl_exec($ch);
    curl_close($ch);

    assertSame(null, $got, 'Das Dashboard erhaelt eine CSP, obwohl es noch Inline-Handler traegt (Etappe 2)');
});
