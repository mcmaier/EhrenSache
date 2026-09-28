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
 * Quelltext fuer statische Gegenproben lesen — ohne Kommentare (OI-107).
 *
 * Die Suiten pruefen Code per str_contains/preg_match. Liest eine Zusicherung
 * den rohen Dateitext, erfuellt ein erklaerender Kommentar sie genauso wie der
 * Code: Am 2026-09-25 blieb die Suite gruen, obwohl die Farbpruefung
 * safeTypeColor() ersatzlos entfernt war — der Kommentar darueber nannte sie.
 *
 * Deshalb lesen die Suiten Quelltext nur ueber sourceCode(). Wer bewusst den
 * rohen Text braucht (Doku-Waechter, Kommentar an einer Fundstelle, erzeugte
 * Dateien), nimmt rawSource() und sagt damit, dass er es so meint.
 * tests/suites/source_lib.php verbietet file_get_contents in den Suiten.
 *
 * Alle Entferner erhalten die Zeilenstruktur: Ein Kommentar ueber mehrere
 * Zeilen hinterlaesst genauso viele Zeilenumbrueche. So bleiben Zeilennummern
 * in Fehlermeldungen richtig, und Ausschneider, die sich an "\n}" in Spalte 0
 * orientieren, finden dieselben Grenzen.
 */

/** Rohtext einer Datei, bewusst mit Kommentaren. */
function rawSource(string $path): string
{
    $content = @file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException("Datei nicht lesbar: {$path}");
    }

    return $content;
}

/**
 * Quelltext ohne Kommentare, Sprache nach Dateiendung. Endungen ohne eigene
 * Kommentarsyntax (json, md, txt …) kommen unveraendert zurueck.
 */
function sourceCode(string $path): string
{
    $content = rawSource($path);
    $base    = strtolower(basename($path));
    $ext     = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($base === '.htaccess') {
        return stripHashLineComments($content);
    }

    switch ($ext) {
        case 'js':
        case 'mjs':
            return stripJsComments($content);
        case 'css':
            return stripCssComments($content);
        case 'html':
            return stripHtmlComments($content);
        case 'php':
            return stripPhpComments($content);
        case 'sql':
            return stripSqlComments($content);
        default:
            return $content;
    }
}

/**
 * sourceCode() zeilenweise, Index 0 = Zeile 1. Ersatz fuer file(): Die
 * Entferner erhalten die Zeilenstruktur, die Zeilennummern stimmen also.
 *
 * @return string[]
 */
function sourceLines(string $path): array
{
    return preg_split('/\R/', sourceCode($path)) ?: [];
}

/**
 * Wie sourceCode(), aber eine fehlende Datei ergibt '' statt einer Ausnahme —
 * fuer Suiten, die das Fehlen selbst als Befund melden.
 */
function sourceCodeIfExists(string $path): string
{
    return is_file($path) ? sourceCode($path) : '';
}

/** Ersetzt einen Kommentar durch seine Zeilenumbrueche, sonst durch ein Leerzeichen. */
function sourceBlank(string $comment): string
{
    $breaks = (string) preg_replace('/[^\r\n]/', '', $comment);

    return $breaks === '' ? ' ' : $breaks;
}

/** Schluesselwoerter, nach denen ein / ein Regex-Literal beginnt (return /re/ …). */
const JS_REGEX_AFTER_WORD = [
    'return', 'typeof', 'case', 'do', 'else', 'in', 'of', 'new', 'delete',
    'void', 'throw', 'instanceof', 'yield', 'await',
];

/**
 * Beginnt an $i ein Regex-Literal — oder ist das / eine Division bzw. ein
 * Kommentaranfang?
 *
 * Wie ueblich entscheidet das vorige bedeutungstragende Zeichen: nach
 * ( , = : [ ! & | ? { } ; und Operatoren steht ein Regex, nach einem Wert eine
 * Division; nach einem Wort nur, wenn es eines aus JS_REGEX_AFTER_WORD ist.
 * Gesucht wird rueckwaerts in $js — Kommentare sollten dort also schon
 * entfernt sein (sourceCode() tut das).
 *
 * Die eine Stelle fuer diese Regel. Bis 2026-09-28 stand sie zweifach im
 * Projekt, und die zweite Fassung kannte die Schluesselwoerter nicht.
 */
function jsRegexStart(string $js, int $i): bool
{
    if (($js[$i] ?? '') !== '/' || ($js[$i + 1] ?? '') === '/' || ($js[$i + 1] ?? '') === '*') {
        return false;
    }
    $p = $i - 1;
    while ($p >= 0 && ctype_space($js[$p])) {
        $p--;
    }
    if ($p < 0) {
        return true;
    }
    if (strpos('(,=:[!&|?{};+-*%<>~^', $js[$p]) !== false) {
        return true;
    }
    if (preg_match('/[A-Za-z0-9_$]/', $js[$p]) !== 1) {
        return false;
    }
    $ende = $p;
    while ($p >= 0 && preg_match('/[A-Za-z0-9_$]/', $js[$p]) === 1) {
        $p--;
    }

    return in_array(substr($js, $p + 1, $ende - $p), JS_REGEX_AFTER_WORD, true);
}

/**
 * Position des schliessenden / eines Regex-Literals, das an $i beginnt
 * (ohne Flags). Ein / in einer Zeichenklasse [...] schliesst nicht. Endet die
 * Zeile vorher, zeigt das Ergebnis auf den Zeilenumbruch.
 */
function jsRegexEnd(string $js, int $i): int
{
    $n       = strlen($js);
    $inClass = false;
    for ($i++; $i < $n && $js[$i] !== "\n"; $i++) {
        $c = $js[$i];
        if ($c === '\\') {
            $i++;
        } elseif ($c === '[') {
            $inClass = true;
        } elseif ($c === ']') {
            $inClass = false;
        } elseif ($c === '/' && !$inClass) {
            return $i;
        }
    }

    return $i;
}

/**
 * JavaScript ohne // und /* *\/-Kommentare.
 *
 * Ein kleiner Tokenizer, kein Parser: Er kennt Zeichenketten in allen drei
 * Anfuehrungen, Template-Literale mit verschachtelten ${…}-Ausdruecken und
 * Regex-Literale — alles Stellen, an denen // oder /* kein Kommentar ist.
 * Ob ein / einen Regex beginnt, entscheidet wie ueblich das vorige
 * bedeutungstragende Zeichen.
 */
function stripJsComments(string $src): string
{
    $n     = strlen($src);
    $i     = 0;
    $out   = '';
    $depth = 0;       // Tiefe geschweifter Klammern
    $tpl   = [];      // Tiefe beim Betreten eines ${…} je offenem Template

    // Liest ein Template-Literal ab $i (hinter dem oeffnenden Backtick oder
    // der schliessenden } eines Ausdrucks) bis zum Ende oder bis ${.
    $scanTemplate = static function () use (&$i, &$out, &$tpl, &$depth, $src, $n): void {
        while ($i < $n) {
            $c = $src[$i];
            if ($c === '\\') {
                $out .= substr($src, $i, 2);
                $i += 2;
                continue;
            }
            if ($c === '`') {
                $out .= '`';
                $i++;

                return;
            }
            if ($c === '$' && ($src[$i + 1] ?? '') === '{') {
                $out .= '${';
                $i += 2;
                $tpl[] = $depth;
                $depth++;

                return;
            }
            $out .= $c;
            $i++;
        }
    };

    while ($i < $n) {
        $c = $src[$i];
        $d = $src[$i + 1] ?? '';

        if ($c === '/' && $d === '/') {
            $j = $i;
            while ($j < $n && $src[$j] !== "\n" && $src[$j] !== "\r") {
                $j++;
            }
            $i = $j;
            continue;
        }

        if ($c === '/' && $d === '*') {
            $end = strpos($src, '*/', $i + 2);
            $end = $end === false ? $n : $end + 2;
            $out .= sourceBlank(substr($src, $i, $end - $i));
            $i = $end;
            continue;
        }

        if ($c === '"' || $c === "'") {
            $j = $i + 1;
            while ($j < $n && $src[$j] !== $c && $src[$j] !== "\n") {
                $j += $src[$j] === '\\' ? 2 : 1;
            }
            $j = min($j + 1, $n);
            $out .= substr($src, $i, $j - $i);
            $i = $j;
            continue;
        }

        if ($c === '`') {
            $out .= '`';
            $i++;
            $scanTemplate();
            continue;
        }

        // Entschieden wird rueckwaerts in der bisherigen Ausgabe: Dort sind
        // Kommentare schon entfernt, und das / steht an ihrem Ende.
        if ($c === '/') {
            if (jsRegexStart($out . '/', strlen($out))) {
                $j = min(jsRegexEnd($src, $i) + 1, $n);
                while ($j < $n && ctype_alpha($src[$j])) {
                    $j++;
                }
                $out .= substr($src, $i, $j - $i);
                $i = $j;
                continue;
            }
        }

        if (preg_match('/[A-Za-z0-9_$]/', $c) === 1) {
            preg_match('/[A-Za-z0-9_$]+/A', $src, $m, 0, $i);
            $out .= $m[0];
            $i += strlen($m[0]);
            continue;
        }

        if ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($tpl !== [] && end($tpl) === $depth) {
                array_pop($tpl);
                $out .= '}';
                $i++;
                $scanTemplate();
                continue;
            }
        }

        $out .= $c;
        $i++;
    }

    return $out;
}

/** CSS ohne /* *\/-Kommentare; Zeichenketten bleiben unberuehrt. */
function stripCssComments(string $src): string
{
    $n   = strlen($src);
    $i   = 0;
    $out = '';
    while ($i < $n) {
        $c = $src[$i];
        if ($c === '/' && ($src[$i + 1] ?? '') === '*') {
            $end = strpos($src, '*/', $i + 2);
            $end = $end === false ? $n : $end + 2;
            $out .= sourceBlank(substr($src, $i, $end - $i));
            $i = $end;
            continue;
        }
        if ($c === '"' || $c === "'") {
            $j = $i + 1;
            while ($j < $n && $src[$j] !== $c && $src[$j] !== "\n") {
                $j += $src[$j] === '\\' ? 2 : 1;
            }
            $j = min($j + 1, $n);
            $out .= substr($src, $i, $j - $i);
            $i = $j;
            continue;
        }
        $out .= $c;
        $i++;
    }

    return $out;
}

/**
 * HTML ohne <!-- -->-Kommentare. Inline-Skripte (ohne src) verlieren
 * zusaetzlich ihre JS-Kommentare.
 */
function stripHtmlComments(string $src): string
{
    $src = (string) preg_replace_callback('/<!--.*?-->/s', static fn (array $m): string => sourceBlank($m[0]), $src);

    return (string) preg_replace_callback(
        '#(<script\b(?![^>]*\ssrc=)[^>]*>)(.*?)(</script>)#is',
        static fn (array $m): string => $m[1] . stripJsComments($m[2]) . $m[3],
        $src
    );
}

/** PHP ohne Kommentare, ueber den Tokenizer von PHP selbst. */
function stripPhpComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            // Ein Zeilenkommentar schliesst seinen Zeilenumbruch mit ein.
            $out .= sourceBlank($token[1]);
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/** SQL ohne "-- …"-Zeilenkommentare und /* *\/-Bloecke; Zeichenketten bleiben. */
function stripSqlComments(string $src): string
{
    $n   = strlen($src);
    $i   = 0;
    $out = '';
    while ($i < $n) {
        $c = $src[$i];
        if ($c === '-' && ($src[$i + 1] ?? '') === '-') {
            while ($i < $n && $src[$i] !== "\n" && $src[$i] !== "\r") {
                $i++;
            }
            continue;
        }
        if ($c === '/' && ($src[$i + 1] ?? '') === '*') {
            $end = strpos($src, '*/', $i + 2);
            $end = $end === false ? $n : $end + 2;
            $out .= sourceBlank(substr($src, $i, $end - $i));
            $i = $end;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $j = $i + 1;
            while ($j < $n && $src[$j] !== $c) {
                $j += $src[$j] === '\\' ? 2 : 1;
            }
            $j = min($j + 1, $n);
            $out .= substr($src, $i, $j - $i);
            $i = $j;
            continue;
        }
        $out .= $c;
        $i++;
    }

    return $out;
}

/** .htaccess ohne #-Kommentarzeilen; die Zeilen bleiben als Leerzeilen stehen. */
function stripHashLineComments(string $src): string
{
    return (string) preg_replace('/^[ \t]*#[^\r\n]*/m', '', $src);
}
