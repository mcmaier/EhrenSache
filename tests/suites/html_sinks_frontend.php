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
 * Freitextfelder erreichen eine HTML-Senke nur maskiert (OI-107).
 *
 * Die bisherigen Waechter pruefen str_contains($rumpf, 'escapeHtml(feld)') —
 * gruen, sobald EINE Stelle maskiert, auch wenn eine zweite denselben Wert roh
 * ausgibt. Eine Mutationsprobe am 2026-09-25 liess 44 von 67 einzeln
 * entfernten Maskierungen unbemerkt. Dieser Waechter prueft statt des Namens
 * die Wirkung: Er geht von jeder HTML-Senke aus und verfolgt eingesetzte
 * Variablen bis zu ihrer Definition, auch ueber mehrere Stufen
 * (feld -> memberNumber -> memberInfo -> tr.innerHTML).
 *
 * Senken: Zuweisung an innerHTML/outerHTML, insertAdjacentHTML(), showToast()
 * (setzt per innerHTML, ui.js) und das return einer Funktion, deren Name auf
 * Html endet (Konvention fuer Markup-Bausteine).
 *
 * Grenzen, bewusst: Nur Template-Interpolationen ${…} werden als roh erkannt,
 * nicht String-Verkettung mit +; Variablen werden innerhalb derselben Datei
 * aufgeloest, nicht ueber Funktionsparameter hinweg. Das Werkzeug ersetzt
 * keine Durchsicht, es haelt den erreichten Stand fest.
 */

$hsRoot = dirname(__DIR__, 2);

/** Freitextfelder aus der Datenbank, die ein Nutzer mit Schreibrecht frei befuellt. */
const HS_FIELDS = 'name|surname|title|description|comment|reason|type_name|group_name'
    . '|activity_name|note|location|member_number|email|organization_name|subgroup_name|device_name|filename|label';

/**
 * Beginnt an $i ein Regex-Literal? Wie ueblich entscheidet das vorige
 * bedeutungstragende Zeichen: nach ( , = : [ ! & | ? { } ; und Verwandten
 * steht ein Regex, nach einem Wert eine Division.
 *
 * Ohne diese Unterscheidung gilt das " in .replace(/"/g, '&quot;') als Beginn
 * einer Zeichenkette. Die Scanner laufen dann aus dem Takt: Sinnabschnitte
 * reichen ueber hunderte Zeilen (falsche Funde), und ein Markup-Template im
 * verschluckten Bereich wird gar nicht mehr gesehen (stille Luecke).
 */
function hsRegexStart(string $js, int $i): bool
{
    if (($js[$i] ?? '') !== '/' || ($js[$i + 1] ?? '') === '/' || ($js[$i + 1] ?? '') === '*') {
        return false;
    }
    for ($p = $i - 1; $p >= 0; $p--) {
        if (!ctype_space($js[$p])) {
            return strpos('(,=:[!&|?{};+-*%<>~^', $js[$p]) !== false;
        }
    }

    return true;
}

/** Position des schliessenden / eines Regex-Literals, das an $i beginnt. */
function hsRegexEnd(string $js, int $i): int
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
 * Ende eines Ausdrucks ab $i: das erste ; , ) ] } auf Tiefe 0 (bzw. das
 * schliessende Gegenstueck einer offenen Klammer). Zeichenketten,
 * Regex-Literale und Template-Literale mit ${…} werden uebersprungen.
 */
function hsExpressionEnd(string $js, int $i, bool $stopAtComma = false, bool $block = false): int
{
    $n     = strlen($js);
    $depth = 0;
    while ($i < $n) {
        $c = $js[$i];
        if (hsRegexStart($js, $i)) {
            $i = hsRegexEnd($js, $i);
        } elseif ($c === '"' || $c === "'") {
            $i++;
            while ($i < $n && $js[$i] !== $c) {
                $i += $js[$i] === '\\' ? 2 : 1;
            }
        } elseif ($c === '`') {
            $i = hsTemplateEnd($js, $i);
        } elseif ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            if ($depth === 0) {
                return $i;
            }
            $depth--;
        } elseif ($depth === 0 && ((!$block && $c === ';') || ($stopAtComma && $c === ','))) {
            return $i;
        }
        $i++;
    }

    return $n;
}

/** Position des schliessenden Backticks zum oeffnenden an $i. */
function hsTemplateEnd(string $js, int $i): int
{
    $n = strlen($js);
    $i++;
    while ($i < $n) {
        $c = $js[$i];
        if ($c === '\\') {
            $i += 2;
            continue;
        }
        if ($c === '`') {
            return $i;
        }
        if ($c === '$' && ($js[$i + 1] ?? '') === '{') {
            $i = hsExpressionEnd($js, $i + 2);   // steht auf der schliessenden }
        }
        $i++;
    }

    return $n;
}

/** Inhalte aller ${…} in einem Ausdruck, auch verschachtelte. */
function hsInterpolations(string $expr): array
{
    $out = [];
    $pos = 0;
    while (($p = strpos($expr, '${', $pos)) !== false) {
        $end   = hsExpressionEnd($expr, $p + 2);
        $out[] = substr($expr, $p + 2, $end - $p - 2);
        $pos   = $p + 2;
    }

    return $out;
}

/** Ist der Inhalt einer Interpolation ein Freitextfeld ohne Maskierung? */
function hsIsRawField(string $content): bool
{
    // Ein Feld, wahlweise mit Methodenaufruf (.trim()), Rueckfallwert (|| '')
    // und in String(…) gehuellt — innen oder aussen vom Rueckfallwert.
    $lit      = '(?:\'[^\']*\'|"[^"]*"|`[^`$]*`|-?\d+)';
    $fallback = '(?:\s*(?:\|\||\?\?)\s*' . $lit . ')?';
    $field    = '[\w$]+(?:\??\.[\w$]+)*\??\.(?:\w+_)?(?:' . HS_FIELDS . ')\b(?:\??\.\w+\(\))*';
    $raw      = '(?:String\(\s*)?' . $field . $fallback . '\s*\)?' . $fallback;
    // Oder als Zweig eines Ternaers: cond ? feld : 'x' bzw. cond ? 'x' : feld
    $cond     = '[^?`]+?';

    return preg_match(
        '/^\s*(?:' . $raw
        . '|' . $cond . '\?\s*' . $raw . '\s*:\s*' . $lit
        . '|' . $cond . '\?\s*' . $lit . '\s*:\s*' . $raw
        . ')\s*$/',
        $content
    ) === 1;
}

/** Template-Text ohne die Inhalte seiner ${…} — nur das, was woertlich dasteht. */
function hsStaticText(string $template): string
{
    $out = '';
    $pos = 0;
    while (($p = strpos($template, '${', $pos)) !== false) {
        $out .= substr($template, $pos, $p - $pos);
        $pos  = hsExpressionEnd($template, $p + 2) + 1;
    }

    return $out . substr($template, $pos);
}

/**
 * Template-Literale, die Markup bauen (woertlicher Text mit <tag), als
 * [Zeile, Text, Position]. Ein Template ohne Markup wird in seine ${…}
 * hinein weiterverfolgt, denn dort kann ein Markup-Template stecken.
 */
function hsMarkupTemplates(string $js, int $base = 0, string $whole = ''): array
{
    $whole = $whole === '' ? $js : $whole;
    $out   = [];
    $n     = strlen($js);
    for ($i = 0; $i < $n; $i++) {
        $c = $js[$i];
        if (hsRegexStart($js, $i)) {
            $i = hsRegexEnd($js, $i);
            continue;
        }
        if ($c === '"' || $c === "'") {
            $i++;
            while ($i < $n && $js[$i] !== $c && $js[$i] !== "\n") {
                $i += $js[$i] === '\\' ? 2 : 1;
            }
            continue;
        }
        if ($c !== '`') {
            continue;
        }
        $end      = hsTemplateEnd($js, $i);
        $template = substr($js, $i, $end - $i + 1);
        if (preg_match('/<[a-zA-Z\/]/', hsStaticText($template)) === 1) {
            $at    = $base + $i;
            $out[] = [substr_count($whole, "\n", 0, $at) + 1, $template, $at];
        } else {
            $pos = 0;
            while (($p = strpos($template, '${', $pos)) !== false) {
                $close = hsExpressionEnd($template, $p + 2);
                $inner = substr($template, $p + 2, $close - $p - 2);
                $out   = array_merge($out, hsMarkupTemplates($inner, $base + $i + $p + 2, $whole));
                $pos   = $close + 1;
            }
        }
        $i = $end;
    }

    return $out;
}

/** Bezeichner, die ein Ausdruck roh interpoliert: ${name}. */
function hsInterpolatedIdentifiers(string $expr): array
{
    $ids = [];
    foreach (hsInterpolations($expr) as $content) {
        if (preg_match('/^\s*([A-Za-z_$][\w$]*)\s*$/', $content, $m) === 1) {
            $ids[] = $m[1];
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Alle Ausdruecke, die einer Variablen vor $before zugewiesen werden:
 * const/let/var x = …, x = …, x += …. Gesucht wird im Rumpf der umgebenden
 * Funktion (ab dem letzten "function " in Spalte 0 oder eingerueckt).
 */
function hsDefinitions(string $js, string $name, int $before): array
{
    $scopeStart = 0;
    if (preg_match_all('/^[ \t]*(?:export\s+)?(?:async\s+)?function\s/m', substr($js, 0, $before), $m, PREG_OFFSET_CAPTURE)) {
        $scopeStart = (int) end($m[0])[1];
    }
    $scope = substr($js, $scopeStart, $before - $scopeStart);

    $defs = [];
    $re = '/(?:\b(?:const|let|var)\s+' . preg_quote($name, '/') . '\s*=(?!=)'
        // Zuweisung nur mit Leerzeichen vor dem "=" (Stil des Projekts): sonst
        // gaelte ein HTML-Attribut wie colspan="${colspan}" als Zuweisung.
        . '|(?<![\w$.\-])' . preg_quote($name, '/') . '\s+\+?=(?!=))/';
    if (preg_match_all($re, $scope, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$match, $off]) {
            $start  = $scopeStart + $off + strlen($match);
            $end    = hsExpressionEnd($js, $start);
            $defs[] = substr($js, $start, $end - $start);
        }
    }

    return $defs;
}

/**
 * Rohe Freitextfelder, die einen Ausdruck erreichen — direkt oder ueber
 * interpolierte Variablen, deren Definitionen rekursiv mitgeprueft werden.
 */
function hsRawFieldsReaching(string $js, string $expr, int $at, array $seen = []): array
{
    $raw = [];
    foreach (hsInterpolations($expr) as $content) {
        if (hsIsRawField($content)) {
            $raw[] = trim($content);
        }
    }
    foreach (hsInterpolatedIdentifiers($expr) as $id) {
        if (isset($seen[$id]) || count($seen) > 6) {
            continue;
        }
        foreach (hsDefinitions($js, $id, $at) as $def) {
            foreach (hsRawFieldsReaching($js, $def, $at, $seen + [$id => true]) as $r) {
                $raw[] = "{$r} (ueber {$id})";
            }
        }
    }

    return $raw;
}

/** Alle HTML-Senken einer Datei als [Zeile, Ausdruck, Position]. */
function hsSinks(string $js): array
{
    $sinks = [];
    $patterns = [
        '/\.(?:inner|outer)HTML\s*\+?=(?!=)/',
        '/\binsertAdjacentHTML\s*\(\s*[^,]+,/',
        '/\bshowToast\s*\(/',
    ];
    foreach ($patterns as $re) {
        if (preg_match_all($re, $js, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$match, $off]) {
                $start   = $off + strlen($match);
                $end     = hsExpressionEnd($js, $start, str_contains($match, 'showToast'));
                $sinks[] = [substr_count($js, "\n", 0, $off) + 1, substr($js, $start, $end - $start), $off];
            }
        }
    }

    // Jedes Template, das Markup baut -- gleich, wohin es danach geht
    foreach (hsMarkupTemplates($js) as $template) {
        $sinks[] = $template;
    }

    // return in Funktionen, deren Name auf Html endet
    if (preg_match_all('/function\s+\w*Html\s*\([^)]*\)\s*\{/', $js, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$match, $off]) {
            $bodyStart = $off + strlen($match);
            $bodyEnd   = hsExpressionEnd($js, $bodyStart, false, true);
            $body      = substr($js, $bodyStart, $bodyEnd - $bodyStart);
            if (preg_match_all('/\breturn\b/', $body, $r, PREG_OFFSET_CAPTURE)) {
                foreach ($r[0] as [, $roff]) {
                    $start   = $bodyStart + $roff + 6;
                    $end     = hsExpressionEnd($js, $start);
                    $sinks[] = [substr_count($js, "\n", 0, $start) + 1, substr($js, $start, $end - $start), $start];
                }
            }
        }
    }

    return $sinks;
}

/** @return string[] Funde "datei:zeile: feld" */
function hsFindings(string $root): array
{
    $funde = [];
    $files = array_merge(
        glob($root . '/public/js/modules/*.js') ?: [],
        [$root . '/public/js/app.js', $root . '/public/js/login.js', $root . '/public/js/theme.js',
         $root . '/public/checkin/js/app.js', $root . '/public/station/js/app.js']
    );
    foreach ($files as $file) {
        $js  = sourceCode($file);
        $rel = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root)) + 1);
        foreach (hsSinks($js) as [$line, $expr, $at]) {
            foreach (array_unique(hsRawFieldsReaching($js, $expr, $at)) as $feld) {
                $funde[] = "{$rel}:{$line}: {$feld}";
            }
        }
    }

    return $funde;
}

// ---------------------------------------------------------------------------

test('Werkzeug: erkennt ein rohes Feld direkt und ueber mehrere Variablen', function () {
    $js = <<<'JS'
function renderUsers(user) {
    const nr = user.member_number ? ` (${user.member_number})` : '';
    const info = `<div>${escapeHtml(user.name)} ${nr}</div>`;
    tr.innerHTML = `<td>${info}</td>`;
    box.innerHTML = `<b>${apt.title}</b>`;
    showToast(`Gruppe "${g.group_name}" fehlt`, 'warning');
    ok.innerHTML = `<b>${escapeHtml(apt.title)}</b>`;
    el.textContent = `${apt.title}`;
}
function cardHtml(item) {
    return `<p>${item.description}</p>`;
}
JS;
    $funde = [];
    foreach (hsSinks($js) as [$line, $expr, $at]) {
        foreach (hsRawFieldsReaching($js, $expr, $at) as $f) {
            $funde[] = "{$line}: {$f}";
        }
    }
    $funde = array_values(array_unique($funde));
    sort($funde);

    // Zeile 3 ist das Markup-Template von info, das ueber nr die rohe Nummer
    // enthaelt; Zeile 4 ist die innerHTML-Senke dahinter. Die maskierte Zeile 7
    // und die textContent-Zeile 8 fehlen zu Recht.
    assertSame([
        '11: item.description',
        '3: user.member_number (ueber nr)',
        '4: user.member_number (ueber nr) (ueber info)',
        '5: apt.title',
        '6: g.group_name',
    ], $funde);
});

test('Werkzeug: ein Regex mit Anfuehrungszeichen bringt den Scanner nicht aus dem Takt', function () {
    // Die Maskierung ersetzt " und ' per Regex-Literal. Galten die Zeichen
    // darin als Beginn einer Zeichenkette, verschob sich alles danach: Die
    // Senke in Zeile 5 wurde der Zeile 2 zugeschlagen und doppelt gemeldet.
    //
    // Zeile 8 prueft die andere Haelfte: Dort steht das Regex-Literal vor
    // einem Markup-Template. Ohne Regex-Erkennung verschluckt die vermeintliche
    // Zeichenkette den Backtick, das Template wird nicht mehr gesehen, und das
    // rohe Feld darin faellt still heraus — gruen, obwohl nichts geprueft wurde.
    $js = <<<'JS'
function escapeHtml(value) {
    return String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
function render(apt) {
    box.innerHTML = `<b>${apt.title}</b>`;
}
function label(apt, raw) {
    const s = raw.replace(/'/g, '&#039;') + `<i>${apt.description}</i>`;
    return s;
}
JS;
    $funde = [];
    foreach (hsSinks($js) as [$line, $expr, $at]) {
        foreach (hsRawFieldsReaching($js, $expr, $at) as $f) {
            $funde[] = "{$line}: {$f}";
        }
    }
    $funde = array_values(array_unique($funde));
    sort($funde);

    assertSame(['5: apt.title', '8: apt.description'], $funde);
});

/**
 * Felder, die schon an ihrer Quelle maskiert sind. Jede Ausnahme nennt die
 * Quelle, und der Test prueft sie mit — faellt dort die Maskierung weg, faellt
 * auch die Ausnahme.
 */
$hsPreEscaped = [
    'accent.name' => ['public/js/modules/records.js', '/name:\s*type\s*\?\s*escapeHtml\(type\.type_name\)/',
        'appointmentTypeAccent() liefert name bereits maskiert'],
    // Kein Freitext: die Beschriftung stammt aus einer festen Tabelle im Code,
    // nachgeschlagen ueber checkin_source mit Rueckfall auf 'none'.
    'source.label' => ['public/js/modules/records.js',
        '/const source = sources\[record\.checkin_source\] \|\| sources\[\'none\'\];/',
        'source kommt aus der festen Tabelle sources in getSourceBadge()'],
];

test('Ausnahmen: vorab maskierte Felder sind an ihrer Quelle wirklich maskiert', function () use ($hsRoot, $hsPreEscaped) {
    foreach ($hsPreEscaped as $feld => [$datei, $muster, $grund]) {
        assertTrue(preg_match($muster, sourceCode($hsRoot . '/' . $datei)) === 1,
            "{$feld}: {$grund} — gilt nicht mehr, die Ausnahme ist zu streichen");
    }
});

test('Keine HTML-Senke erreicht ein Freitextfeld unmaskiert', function () use ($hsRoot, $hsPreEscaped) {
    $funde = array_values(array_filter(
        hsFindings($hsRoot),
        static fn (string $f): bool => preg_match('/: (' . implode('|', array_map(
            static fn (string $k): string => preg_quote($k, '/'), array_keys($hsPreEscaped))) . ')\b/', $f) !== 1
    ));

    assertTrue($funde === [],
        "Freitext ohne escapeHtml() in einer HTML-Senke (innerHTML, showToast, …Html()):\n  "
        . implode("\n  ", $funde));
});
