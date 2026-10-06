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

require_once __DIR__ . '/../../private/helpers/css_bundle.php';

/**
 * CSS-Bündel (OI-120 Weg 1): Dashboard und Anmeldeseite laden ihr CSS als eine
 * Antwort. cssBundle() ersetzt @import url('…'); rekursiv durch den Inhalt.
 * Spec docs/superpowers/specs/2026-10-06-css-buendel-design.md.
 */

$cbCssRoot = dirname(__DIR__, 2) . '/public/css';

/** Legt ein Verzeichnis mit Testdateien an; Rückgabe: Pfad. */
function cbTree(array $files): string
{
    $root = sys_get_temp_dir() . '/es_css_bundle_' . bin2hex(random_bytes(6));
    foreach ($files as $rel => $content) {
        $path = $root . '/' . $rel;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }
    return $root;
}

function cbRemove(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    // Eigene Rekursion: ein RecursiveDirectoryIterator in Suiten verbietet source_lib.
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $pfad = $dir . '/' . $name;
        is_dir($pfad) ? cbRemove($pfad) : unlink($pfad);
    }
    rmdir($dir);
}

/** Pfade aus den Abschnittsmarken des Bündels, in Reihenfolge. */
function cbSections(string $css): array
{
    preg_match_all('#^/\* ---- (\S+) ---- \*/$#m', $css, $m);
    return $m[1];
}

/** Importpfade einer Datei in Reihenfolge (nur die eigene Ebene). */
function cbImports(string $file): array
{
    preg_match_all("#^\s*@import\s+url\(\s*['\"]([^'\"]+)['\"]\s*\)\s*;#m", rawSource($file), $m);
    return $m[1];
}

test('main: jede importierte Datei genau einmal, in der Reihenfolge von main.css', function () use ($cbCssRoot) {
    $erwartet = cbImports($cbCssRoot . '/main.css');
    assertTrue(count($erwartet) >= 20, 'main.css hat weniger Importe als erwartet: ' . count($erwartet));
    assertSame($erwartet, cbSections(cssBundle('main')));
});

test('login: beide Importe in der Reihenfolge von login.css', function () use ($cbCssRoot) {
    assertSame(cbImports($cbCssRoot . '/login.css'), cbSections(cssBundle('login')));
});

test('Im Bündel steht kein @import mehr', function () {
    foreach (array_keys(CSS_BUNDLE_ENTRIES) as $entry) {
        assertTrue(!str_contains(cssBundle($entry), '@import'), "{$entry}: @import im Bündel");
    }
});

test('Bestand: main und login lösen sich ohne Fehlerkommentar auf', function () {
    // Wächter für neue CSS-Dateien: ein falsch geschriebener Import fiele sonst
    // erst im Browser auf (fehlende Stile, keine Fehlermeldung).
    foreach (array_keys(CSS_BUNDLE_ENTRIES) as $entry) {
        $css = cssBundle($entry);
        assertTrue(!str_contains($css, 'css-bundle:'),
            "{$entry}: " . implode(' | ', preg_match_all('#/\* css-bundle:[^*]*\*/#', $css, $m) ? $m[0] : []));
    }
});

test('Bündel enthält den Inhalt der Quellen unverändert', function () use ($cbCssRoot) {
    $css = cssBundle('main');
    foreach (cbImports($cbCssRoot . '/main.css') as $rel) {
        // rtrim: das Bündel normalisiert nur das Dateiende auf einen Zeilenumbruch.
        assertTrue(str_contains($css, rtrim(rawSource($cbCssRoot . '/' . $rel))),
            "Inhalt von {$rel} fehlt oder ist verändert");
    }
});

test('Unbekannter Einstieg wird abgewiesen', function () {
    assertThrows(fn () => cssBundle('print'), 'print ist kein Einstieg');
    assertThrows(fn () => cssBundle('../main'), 'Pfad als Einstieg');
});

test('Verschachtelte Importe werden relativ zur importierenden Datei aufgelöst', function () {
    $root = cbTree([
        'main.css'          => "@import url('a/b.css');\n.main{}\n",
        'a/b.css'           => "@import url('c.css');\n.b{}\n",
        'a/c.css'           => ".c{}\n",
    ]);
    try {
        $css = cssBundle('main', $root);
        assertSame(['a/b.css', 'a/c.css'], cbSections($css));
        assertTrue(strpos($css, '.c{}') < strpos($css, '.b{}') && strpos($css, '.b{}') < strpos($css, '.main{}'),
            'Reihenfolge der Kaskade verändert');
    } finally {
        cbRemove($root);
    }
});

test('Fehlender, äußerer, absoluter und doppelter Import ergibt Fehlerkommentar, Rest bleibt', function () {
    $root = cbTree([
        'main.css'  => "@import url('fehlt.css');\n@import url('../aussen.css');\n@import url('/etc/x.css');\n"
                     . "@import url('ok.css');\n@import url('ok.css');\n@import url('kein.txt');\n.main{}\n",
        'ok.css'    => ".ok{}\n",
        'kein.txt'  => ".txt{}\n",
    ]);
    file_put_contents(dirname($root) . '/aussen.css', '.aussen{}');
    try {
        $css = cssBundle('main', $root);
        assertSame(5, substr_count($css, '/* css-bundle:'), "Erwartet 5 Fehlerkommentare:\n{$css}");
        assertSame(1, substr_count($css, '.ok{}'), 'ok.css nicht genau einmal');
        assertTrue(str_contains($css, '.main{}'), 'Rest der Datei fehlt');
        assertTrue(!str_contains($css, '.aussen{}') && !str_contains($css, '.txt{}'), 'Fremde Datei eingebunden');
        assertTrue(!str_contains($css, '@import'), '@import übrig');
    } finally {
        @unlink(dirname($root) . '/aussen.css');
        cbRemove($root);
    }
});

test('Fehlerkommentar kann nicht aus dem Kommentar ausbrechen', function () {
    $root = cbTree(['main.css' => "@import url('x*/body{}/*.css');\n"]);
    try {
        $css = cssBundle('main', $root);
        assertSame(1, substr_count($css, '*/'), "Kommentar aufgebrochen:\n{$css}");
    } finally {
        cbRemove($root);
    }
});

test('cssBundleCurrentVersion liest version.json', function () {
    $v = json_decode(rawSource(dirname(__DIR__, 2) . '/version.json'), true)['version'];
    assertSame($v, cssBundleCurrentVersion());
});

test('Importe werden mit LF und CRLF aufgelöst', function () {
    // Der Windows-Bestand hat CRLF; ein $ vor \r liesse die Importe stehen.
    foreach (["\n", "\r\n"] as $eol) {
        $label = $eol === "\n" ? 'LF' : 'CRLF';
        $root = cbTree([
            'main.css' => "@import url('a.css');{$eol}@import url('b.css');{$eol}.main{}{$eol}",
            'a.css'    => '.a{}',
            'b.css'    => '.b{}',
        ]);
        try {
            $css = cssBundle('main', $root);
            assertSame(['a.css', 'b.css'], cbSections($css), "{$label}: Abschnitte");
            assertTrue(!str_contains($css, '@import'), "{$label}: @import im Ergebnis");
        } finally {
            cbRemove($root);
        }
    }
});

test('cssBundleCacheControl: immutable nur für aktuelle Version ohne Fehlerkommentar', function () {
    assertTrue(str_contains(cssBundleCacheControl('.a{}', true), 'immutable'), 'aktuell + sauber');
    $fehler = cssBundleCacheControl("/* css-bundle: x nicht eingebunden (Datei fehlt) */\n.a{}", true);
    assertTrue(str_contains($fehler, 'no-cache') && !str_contains($fehler, 'immutable'), "aktuell + Fehler: {$fehler}");
    $alt = cssBundleCacheControl('.a{}', false);
    assertTrue(str_contains($alt, 'no-cache') && !str_contains($alt, 'immutable'), "alt: {$alt}");
});

test('BOM am Dateianfang wird entfernt (Einstieg und eingefügte Datei)', function () {
    $root = cbTree([
        'main.css' => "\xEF\xBB\xBF@import url('a.css');\n.main{}\n",
        'a.css'    => "\xEF\xBB\xBF.a{}\n",
    ]);
    try {
        $css = cssBundle('main', $root);
        assertTrue(!str_contains($css, "\xEF\xBB\xBF"), 'BOM im Ergebnis');
        assertSame(['a.css'], cbSections($css), 'Import hinter BOM nicht aufgelöst');
    } finally {
        cbRemove($root);
    }
});

test('Sterne im Pfad öffnen oder schließen keinen Kommentar', function () {
    $root = cbTree([
        'main.css'          => "@import url('x**//body{color:red}/**.css');\n.main{}\n",
    ]);
    try {
        $css = cssBundle('main', $root);
        assertTrue(substr_count($css, '/*') >= 1, 'Fehlerkommentar fehlt');
        assertSame(substr_count($css, '/*'), substr_count($css, '*/'), "Kommentar unausgewogen:\n{$css}");
    } finally {
        cbRemove($root);
    }
});

test('Auskommentierte Importe werden nicht eingefügt', function () {
    $root = cbTree([
        'main.css' => "/*\n@import url('a.css');\n*/\n.main{}\n",
        'a.css'    => ".a{}\n",
    ]);
    try {
        $css = cssBundle('main', $root);
        assertTrue(!str_contains($css, '.a{}'), 'a.css eingefügt');
        assertTrue(str_contains($css, "@import url('a.css');"), 'Kommentar verändert');
        assertSame([], cbSections($css));
    } finally {
        cbRemove($root);
    }
});

test('Übrige Importformen ergeben Fehlerkommentar statt @import', function () {
    foreach (["@import url('a.css') screen;", "@import 'a.css';", '@import url(a.css);', '@import "a.css" print;'] as $zeile) {
        $root = cbTree(['main.css' => "{$zeile}\n.main{}\n", 'a.css' => ".a{}\n"]);
        try {
            $css = cssBundle('main', $root);
            assertTrue(!str_contains($css, '.a{}'), "{$zeile}: eingefügt");
            assertTrue(!str_contains($css, '@import'), "{$zeile}: @import im Ergebnis");
            assertSame(1, substr_count($css, '/* css-bundle:'), "{$zeile}: kein Fehlerkommentar:\n{$css}");
            assertTrue(str_contains($css, '.main{}'), "{$zeile}: Rest fehlt");
        } finally {
            cbRemove($root);
        }
    }
});

test('Nicht lesbare Datei wirft statt ein leeres Bündel zu liefern', function () {
    // Ein Verzeichnis namens main.css: realpath() findet es, lesen lässt es sich nicht.
    $root = cbTree(['x/.keep' => '']);
    mkdir($root . '/main.css');
    try {
        assertThrows(fn () => cssBundle('main', $root), 'Verzeichnis als Einstieg');
    } finally {
        cbRemove($root);
    }
});
