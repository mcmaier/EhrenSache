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
 * Die Kommentar-Entferner aus tests/lib/source.php (OI-107) und die Regel,
 * dass Suiten Quelltext nur ueber sourceCode()/rawSource() lesen.
 *
 * Ein Entferner, der zu viel wegnimmt, liesse Zusicherungen rot werden, die
 * gruen sein muessten — das faellt auf. Einer, der zu wenig wegnimmt, liesse
 * wieder Kommentare Zusicherungen erfuellen — das faellt nicht auf. Deshalb
 * prueft diese Suite beide Richtungen an den Stellen, an denen // und /*
 * kein Kommentar sind.
 */

$slRoot = dirname(__DIR__, 2);

// ---------------------------------------------------------------------------
// JavaScript
// ---------------------------------------------------------------------------

test('JS: Zeilen- und Blockkommentare verschwinden, Zeilen bleiben', function () {
    $src = "a(); // safeTypeColor(x)\n/* safeTypeColor(\n y) */\nb();\r\n// c\r\nd();";
    $out = stripJsComments($src);

    assertTrue(!str_contains($out, 'safeTypeColor'), 'Kommentarinhalt ist stehen geblieben');
    assertSame(substr_count($src, "\n"), substr_count($out, "\n"), 'Zeilenzahl hat sich geaendert');
    assertTrue(str_contains($out, "a(); \n") && str_contains($out, 'b();') && str_contains($out, 'd();'), 'Code ging verloren');
    assertTrue(str_contains($out, "\r\n"), 'CRLF-Zeilenenden gingen verloren');
});

test('JS: // und /* in Zeichenketten bleiben stehen', function () {
    $src = <<<'JS'
const a = 'http://x.test/*nicht*/';
const b = "//auch nicht";
const c = 'it\'s // kein Kommentar';
JS;
    assertSame($src, stripJsComments($src));
});

test('JS: Template-Literale mit verschachtelten Ausdruecken', function () {
    $src = <<<'JS'
const t = `a // b ${ x ? `c /* d */ ${ {k: 1}.k }` : '//e' } f /* g */`; // weg
JS;
    $out = stripJsComments($src);
    assertSame('const t = `a // b ${ x ? `c /* d */ ${ {k: 1}.k }` : \'//e\' } f /* g */`; ', $out);
});

test('JS: Regex-Literale werden nicht als Kommentar gelesen', function () {
    $src = <<<'JS'
const r = /^https?:\/\//i.test(u); // weg
const s = x.replace(/[/*]+/g, '');
if (/\/\*/.test(v)) { return /a\/\/b/; }
JS;
    $out = stripJsComments($src);
    assertTrue(str_contains($out, '/^https?:\/\//i.test(u);'), 'Regex mit // wurde beschnitten');
    assertTrue(str_contains($out, '/[/*]+/g'), 'Regex mit /* in einer Zeichenklasse wurde beschnitten');
    assertTrue(str_contains($out, 'return /a\/\/b/;'), 'Regex nach return wurde beschnitten');
    assertTrue(!str_contains($out, 'weg'), 'Kommentar hinter einem Regex blieb stehen');
});

test('jsRegexStart/jsRegexEnd: die eine Regel fuer Regex-Literale', function () {
    // Eine Fassung fuer alle Scanner (stripJsComments, html_sinks_frontend,
    // weitere Suiten). Bis 2026-09-28 gab es eine zweite ohne die
    // Schluesselwoerter -- nach "return" galt ein / dort als Division.
    $faelle = [
        ['x = /a/g;',            4, true,  'nach ='],
        ['f(/a/)',               2, true,  'nach ('],
        ['return /a/.test(s)',   7, true,  'nach return'],
        ['typeof /a/',           7, true,  'nach typeof'],
        ['a / b',                2, false, 'nach Bezeichner: Division'],
        ['(x) / 2',              4, false, 'nach ): Division'],
        ['n / 2',                2, false, 'nach Wort, das kein Schluesselwort ist'],
        ['x = a // c',           6, false, 'Zeilenkommentar'],
        ['x = /* c */',          4, false, 'Blockkommentar'],
        ['/a/',                  0, true,  'am Anfang'],
    ];
    foreach ($faelle as [$js, $i, $erwartet, $grund]) {
        assertSame($erwartet, jsRegexStart($js, $i), "jsRegexStart('{$js}', {$i}): {$grund}");
    }

    $js = 'x = /[/"]+\\//g;';
    assertSame(strlen($js) - 3, jsRegexEnd($js, 4), 'Ende: / in der Zeichenklasse und maskiertes \\/ schliessen nicht');
});

test('JS: Division bleibt Division', function () {
    $src = "const q = a / b / c; // weg\nconst h = (x) / 2 /* weg */;";
    assertSame("const q = a / b / c; \nconst h = (x) / 2  ;", stripJsComments($src));
});

// ---------------------------------------------------------------------------
// Die anderen Sprachen
// ---------------------------------------------------------------------------

test('CSS: Blockkommentare weg, Zeichenketten bleiben', function () {
    $src = "a { /* border: none; */ color: red; }\nb::after { content: '/* bleibt */'; }";
    $out = stripCssComments($src);
    assertTrue(!str_contains($out, 'border: none'), 'Kommentar blieb stehen');
    assertTrue(str_contains($out, "content: '/* bleibt */'"), 'Zeichenkette wurde beschnitten');
});

test('HTML: Kommentare weg, Inline-Skripte ohne JS-Kommentare', function () {
    $src = "<!-- <button onclick=\"x()\"> -->\n<p>a</p>\n<script>\n// weg\nrun();\n</script>\n<script src=\"a.js\">// bleibt</script>";
    $out = stripHtmlComments($src);
    assertTrue(!str_contains($out, 'onclick'), 'HTML-Kommentar blieb stehen');
    assertTrue(!str_contains($out, 'weg') && str_contains($out, 'run();'), 'Inline-Skript falsch bereinigt');
    assertTrue(str_contains($out, '// bleibt'), 'Skript mit src wurde angefasst');
    assertSame(substr_count($src, "\n"), substr_count($out, "\n"), 'Zeilenzahl hat sich geaendert');
});

test('PHP: Kommentare weg ueber den Tokenizer, Zeichenketten bleiben', function () {
    $src = "<?php\n// requireAdmin();\n/** @see requireAdmin() */\n\$a = '// requireAdmin()'; # weg\nfoo();\n";
    $out = stripPhpComments($src);
    assertSame(1, substr_count($out, 'requireAdmin'), 'Nur die Zeichenkette darf requireAdmin enthalten');
    assertTrue(!str_contains($out, 'weg') && str_contains($out, 'foo();'), 'PHP falsch bereinigt');
    assertSame(substr_count($src, "\n"), substr_count($out, "\n"), 'Zeilenzahl hat sich geaendert');
});

test('SQL und .htaccess: Kommentare weg', function () {
    $sql = "-- DROP TABLE x;\nCREATE TABLE a (b VARCHAR(5) DEFAULT '--');\n/* weg */";
    $out = stripSqlComments($sql);
    assertTrue(!str_contains($out, 'DROP') && !str_contains($out, 'weg'), 'SQL-Kommentar blieb stehen');
    assertTrue(str_contains($out, "DEFAULT '--'"), 'SQL-Zeichenkette wurde beschnitten');

    $ht  = "# Header set X \"y\"\n  # auch weg\nHeader set Z \"#bleibt\"\n";
    $out = stripHashLineComments($ht);
    assertTrue(!str_contains($out, 'X ') && !str_contains($out, 'auch weg'), '.htaccess-Kommentar blieb stehen');
    assertTrue(str_contains($out, 'Header set Z "#bleibt"'), '.htaccess-Direktive wurde beschnitten');
});

test('sourceCode waehlt die Sprache nach der Endung', function () use ($slRoot) {
    $js = sourceCode($slRoot . '/public/js/modules/utils.js');
    assertTrue(str_contains($js, 'export function safeTypeColor'), 'utils.js ohne Code gelesen');
    assertTrue(!preg_match('~^\s*//~m', $js), 'utils.js enthaelt noch Zeilenkommentare');

    $raw = rawSource($slRoot . '/public/js/modules/utils.js');
    assertTrue(preg_match('~^\s*(//|/\*|\*)~m', $raw) === 1, 'rawSource hat Kommentare entfernt');

    assertSame(rawSource($slRoot . '/version.json'), sourceCode($slRoot . '/version.json'), 'JSON wurde veraendert');
});

// ---------------------------------------------------------------------------
// Die Regel
// ---------------------------------------------------------------------------

test('Suiten lesen Dateien nur ueber sourceCode() oder rawSource()', function () use ($slRoot) {
    // Ein direktes Lesen liest Kommentare mit — genau der Weg, auf dem ein
    // Kommentar eine Zusicherung erfuellte (OI-107). Wer den rohen Text
    // wirklich braucht, sagt es mit rawSource(); zeilenweise gibt es
    // sourceLines().
    //
    // Bis 2026-09-28 stand hier nur file_get_contents. file() las in vier
    // Suiten weiter am Entferner vorbei, eine davon mit einem selbstgebauten
    // Filter, der nur ganzzeilige Kommentare kannte — dieselbe Falle durch
    // eine andere Tuer. Deshalb jetzt jeder lesende Zugriff: file(),
    // readfile(), SplFileObject und fopen() mit Lesemodus.
    $lesend = '/(?<![\w$>:])(?:file_get_contents|file|readfile)\s*\('
        . '|\bnew\s+\\\\?SplFileObject\b'
        . '|(?<![\w$>:])fopen\s*\([^,]+,\s*[\'"][r]/';

    $verstoesse = [];
    foreach (glob($slRoot . '/tests/suites/*.php') ?: [] as $datei) {
        $code = stripPhpComments(rawSource($datei));
        foreach (preg_split('/\R/', $code) ?: [] as $i => $zeile) {
            if (preg_match($lesend, $zeile) === 1) {
                $verstoesse[] = basename($datei) . ':' . ($i + 1) . ': ' . trim($zeile);
            }
        }
    }

    assertTrue($verstoesse === [], "Lesender Dateizugriff an sourceCode()/rawSource() vorbei:\n  " . implode("\n  ", $verstoesse));
});
