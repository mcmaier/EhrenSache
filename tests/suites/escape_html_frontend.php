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
 * escapeHtml() maskiert auch Anfuehrungszeichen — jede Fassung.
 *
 * Die Funktion gab es dreimal (utils.js, checkin/js/app.js, worktime.js), jede
 * nach demselben Muster: div.textContent setzen, div.innerHTML zurueckgeben.
 * Das behandelt & < >, aber nicht " und '. Im Attribut-Kontext
 * (title="${escapeHtml(feld)}") reicht das nicht — ein " bricht aus. Das
 * Dashboard hat keine CSP (OI-17 Etappe 2), dort ist die Maskierung die
 * einzige Schranke.
 *
 * tests/suites/html_sinks_frontend.php prueft, DASS Freitext maskiert eine
 * HTML-Senke erreicht. Diese Suite prueft, dass die Maskierung dafuer
 * ausreicht — ohne sie koennte eine spaetere "Vereinfachung" auf
 * textContent/innerHTML zurueckfallen, ohne dass etwas rot wird.
 *
 * Gesucht wird nach dem Muster, nicht nach festen Pfaden: Jede Fassung von
 * escapeHtml unter public/ wird geprueft, und der alte Textknoten-Weg gilt
 * unter jedem Namen als Fund. Eine vierte Kopie fliegt damit ebenfalls auf.
 *
 * Geprueft wird die Wirkung, nicht die Schreibweise: Die Ersetzungskette wird
 * aus dem Quelltext gelesen und hier nachgerechnet. Eine Kette, die & zuletzt
 * ersetzt, maskiert die eigenen Entities doppelt — "<" ergaebe "&amp;lt;" und
 * faellt damit auf.
 */

$ehRoot = dirname(__DIR__, 2);

/** Zeichen, die jede Fassung zu einer Entity machen muss. */
const EH_REQUIRED = ['&', '<', '>', '"', '\''];

/**
 * Der alte Weg: einen Textknoten setzen und dessen innerHTML zurueckgeben.
 * Unter jedem Namen ein Fund — der Browser kodiert dort " und ' nicht.
 */
const EH_NODE_PATTERN = '/\.textContent\s*=.{0,400}?\breturn\s+[\w$]+(?:\.\w+)*\.innerHTML/s';

/** Alle .js-Dateien unter public/, ohne vendor/. */
function ehJsFiles(string $root): array
{
    return array_values(array_filter(
        projectFiles($root . '/public', 'js'),
        static fn(string $pfad): bool => strpos($pfad, '/vendor/') === false
    ));
}

/**
 * Ausdruck oder Block ab $i. Steht dort eine {, wird bis zur passenden }
 * gelesen, sonst bis zum ; auf Tiefe 0. Zeichenketten, Template-Literale und
 * Regex-Literale werden uebersprungen.
 */
function ehSpan(string $js, int $i): string
{
    $n      = strlen($js);
    $start  = $i;
    $braced = ($js[$i] ?? '') === '{';
    $depth  = 0;
    while ($i < $n) {
        $c = $js[$i];
        if ($c === '"' || $c === '\'' || $c === '`') {
            $i++;
            while ($i < $n && $js[$i] !== $c) {
                $i += $js[$i] === '\\' ? 2 : 1;
            }
        } elseif ($c === '/' && ($js[$i + 1] ?? '') !== '/' && ($js[$i + 1] ?? '') !== '*') {
            // Regex-Literal: hier immer eines, denn eine Division steht in
            // diesen Rumpfen nicht.
            $i++;
            while ($i < $n && $js[$i] !== '/' && $js[$i] !== "\n") {
                $i += $js[$i] === '\\' ? 2 : 1;
            }
        } elseif ($c === '{' || $c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === '}' || $c === ')' || $c === ']') {
            $depth--;
            if ($braced && $depth === 0) {
                return substr($js, $start, $i - $start + 1);
            }
            if ($depth < 0) {
                return substr($js, $start, $i - $start);
            }
        } elseif ($c === ';' && $depth === 0 && !$braced) {
            return substr($js, $start, $i - $start);
        }
        $i++;
    }

    return substr($js, $start);
}

/**
 * Fassungen von escapeHtml in einer Datei als [Name, Zeile, Rumpf].
 * Erfasst function-Deklarationen (auch export) und Zuweisungen an const/let/var.
 */
function ehDefinitions(string $js): array
{
    $defs = [];
    $re = '/(?:export\s+)?(?:async\s+)?function\s+(escapeHtml[\w$]*)\s*\([^)]*\)\s*(?=\{)'
        . '|(?:const|let|var)\s+(escapeHtml[\w$]*)\s*=\s*(?:(?:async\s+)?(?:function\s*)?'
        . '(?:\([^)]*\)|[\w$]+)\s*(?:=>\s*)?)/';
    if (preg_match_all($re, $js, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $idx => [$match, $off]) {
            $name  = $m[1][$idx][0] !== '' ? $m[1][$idx][0] : $m[2][$idx][0];
            $start = $off + strlen($match);
            $defs[] = [$name, substr_count($js, "\n", 0, $off) + 1, ehSpan($js, $start)];
        }
    }

    return $defs;
}

/** Ein JS-Zeichenliteral aus dem Quelltext in sein Zeichen. */
function ehUnescape(string $raw): ?string
{
    if ($raw === '' || $raw[0] !== '\\') {
        return $raw;
    }
    $c = substr($raw, 1);
    // Steuerzeichen-Kuerzel (\n, ', …) werden nicht aufgeloest: eine
    // Kette, die sie braucht, gilt als nicht verstanden und faellt auf.
    return preg_match('/^[A-Za-z0-9]$/', $c) === 1 ? null : $c;
}

/**
 * Ersetzungskette eines Rumpfs in Quelltextreihenfolge als [[Zeichen, Ersatz], …].
 * null, sobald ein .replace(…) nicht als Ersetzung eines einzelnen Zeichens
 * lesbar ist — dann meldet der Test "nicht verstanden" statt stillschweigend
 * gruen zu bleiben.
 */
function ehReplacements(string $body): ?array
{
    $treffer = [];
    // .replace(/X/g, 'Ersatz')
    $reRegex = '~\.replace\s*\(\s*/(\\\\.|[^/])/([gimsuy]*)\s*,\s*([\'"])((?:\\\\.|[^\'"])*)\3\s*\)~';
    // .replaceAll('X', 'Ersatz')
    $rePlain = '~\.replaceAll\s*\(\s*([\'"])(\\\\.|[^\'"])\1\s*,\s*([\'"])((?:\\\\.|[^\'"])*)\3\s*\)~';

    if (preg_match_all($reRegex, $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $satz) {
            if (strpos($satz[2][0], 'g') === false) {
                return null;   // ohne /g nur der erste Treffer
            }
            $treffer[$satz[0][1]] = [ehUnescape($satz[1][0]), ehUnescape($satz[4][0])];
        }
    }
    if (preg_match_all($rePlain, $body, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $satz) {
            $treffer[$satz[0][1]] = [ehUnescape($satz[2][0]), ehUnescape($satz[4][0])];
        }
    }

    // Jedes .replace im Rumpf muss gelesen worden sein.
    if (preg_match_all('/\.replace(?:All)?\s*\(/', $body, $alle) !== count($treffer)) {
        return null;
    }
    foreach ($treffer as [$zeichen, $ersatz]) {
        if ($zeichen === null || $ersatz === null || strlen($zeichen) !== 1) {
            return null;
        }
    }
    ksort($treffer);

    return array_values($treffer);
}

/** Die Kette auf einen Text anwenden — dieselbe Reihenfolge wie im Browser. */
function ehApply(array $chain, string $text): string
{
    foreach ($chain as [$zeichen, $ersatz]) {
        $text = str_replace($zeichen, $ersatz, $text);
    }

    return $text;
}

/** Befunde einer einzelnen Fassung. @return string[] */
function ehFindings(string $label, string $body): array
{
    if (preg_match(EH_NODE_PATTERN, $body) === 1) {
        return ["{$label}: maskiert ueber einen Textknoten (textContent/innerHTML) — " .
            'dabei bleiben " und \' unmaskiert'];
    }

    $chain = ehReplacements($body);
    if ($chain === null || $chain === []) {
        return ["{$label}: keine lesbare Ersetzungskette gefunden — " .
            'erwartet .replace(/&/g, \'&amp;\') fuer & < > " \' (Werkzeug erweitern, ' .
            'wenn die Maskierung bewusst anders gebaut ist)'];
    }

    $funde = [];
    if ($chain[0][0] !== '&') {
        $funde[] = "{$label}: & wird nicht zuerst ersetzt (zuerst: '{$chain[0][0]}') — " .
            'die eigenen Entities werden dadurch doppelt maskiert';
    }
    foreach (EH_REQUIRED as $zeichen) {
        $ergebnis = ehApply($chain, $zeichen);
        if (preg_match('/^&[A-Za-z0-9#]+;$/', $ergebnis) !== 1) {
            $funde[] = "{$label}: '{$zeichen}' wird zu '{$ergebnis}' statt zu einer Entity";
        }
    }

    // Gegenprobe im Attribut-Kontext: nichts darf den Wert verlassen koennen.
    $probe = ehApply($chain, '" onmouseover=\'x\' <b>&');
    if (preg_match('/[<>"\']/', $probe) === 1) {
        $funde[] = "{$label}: Angriffsprobe bleibt ausbrechbar: '{$probe}'";
    }

    return $funde;
}

// ---------------------------------------------------------------------------

test('Werkzeug: erkennt Textknoten-Maskierung, fehlende Quotes und falsche Reihenfolge', function () {
    $alt = <<<'JS'
function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}
JS;
    $ohneQuotes = <<<'JS'
export function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
JS;
    $ampZuletzt = <<<'JS'
const escapeHtml = (value) => {
    return String(value ?? '').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;').replace(/&/g, '&amp;');
};
JS;
    $gut = <<<'JS'
function escapeHtml(value) {
    if (!value) return '';
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
JS;
    $fremd = <<<'JS'
const escapeHtml = (value) => htmlEntities(value);
JS;

    $proben = [];
    foreach (['alt' => $alt, 'ohneQuotes' => $ohneQuotes, 'ampZuletzt' => $ampZuletzt,
              'gut' => $gut, 'fremd' => $fremd] as $name => $js) {
        $defs = ehDefinitions($js);
        assertSame(1, count($defs), "{$name}: Fassung nicht erkannt");
        $proben[$name] = ehFindings($name, $defs[0][2]);
    }

    assertTrue(count($proben['alt']) === 1 && str_contains($proben['alt'][0], 'Textknoten'),
        'Textknoten-Muster nicht erkannt: ' . implode(' | ', $proben['alt']));
    assertTrue(str_contains(implode(' ', $proben['ohneQuotes']), '\'"\' wird zu'),
        'Fehlende Quote-Maskierung nicht erkannt: ' . implode(' | ', $proben['ohneQuotes']));
    assertTrue(str_contains(implode(' ', $proben['ampZuletzt']), 'nicht zuerst'),
        'Falsche Reihenfolge nicht erkannt: ' . implode(' | ', $proben['ampZuletzt']));
    assertSame([], $proben['gut'], 'Richtige Fassung faelschlich gemeldet');
    assertTrue(count($proben['fremd']) === 1 && str_contains($proben['fremd'][0], 'keine lesbare'),
        'Unverstandene Fassung nicht gemeldet: ' . implode(' | ', $proben['fremd']));

    // Die Kette selbst muss doppelt maskieren, wenn & zuletzt kommt — sonst
    // pruefte die Reihenfolge nur die Schreibweise.
    $kette = ehReplacements($ampZuletzt);
    assertSame('&amp;lt;', ehApply($kette, '<'), 'Nachrechnen der Kette stimmt nicht');
});

test('Der Suchlauf findet die bekannten Fassungen von escapeHtml', function () use ($ehRoot) {
    $gefunden = [];
    foreach (ehJsFiles($ehRoot) as $datei) {
        foreach (ehDefinitions(sourceCode($datei)) as [$name, , ]) {
            $gefunden[] = substr(str_replace('\\', '/', $datei), strlen(str_replace('\\', '/', $ehRoot)) + 1);
        }
    }
    sort($gefunden);

    // Waechst die Liste, ist eine weitere Kopie entstanden — sie wird vom Test
    // darunter mitgeprueft. Schrumpft sie, greift der Suchlauf nicht mehr.
    assertSame([
        'public/checkin/js/app.js',
        'public/js/modules/utils.js',
        'public/js/modules/worktime.js',
    ], $gefunden);
});

test('Jede Fassung von escapeHtml maskiert & < > " und \'', function () use ($ehRoot) {
    $funde = [];
    foreach (ehJsFiles($ehRoot) as $datei) {
        $js  = sourceCode($datei);
        $rel = substr(str_replace('\\', '/', $datei), strlen(str_replace('\\', '/', $ehRoot)) + 1);
        foreach (ehDefinitions($js) as [$name, $zeile, $body]) {
            $funde = array_merge($funde, ehFindings("{$rel}:{$zeile} {$name}()", $body));
        }
    }

    assertSame([], $funde, "Unzureichende Maskierung:\n  " . implode("\n  ", $funde));
});

test('Kein Modul maskiert HTML noch ueber einen Textknoten', function () use ($ehRoot) {
    $funde = [];
    foreach (ehJsFiles($ehRoot) as $datei) {
        $js  = sourceCode($datei);
        $rel = substr(str_replace('\\', '/', $datei), strlen(str_replace('\\', '/', $ehRoot)) + 1);
        if (preg_match(EH_NODE_PATTERN, $js, $m, PREG_OFFSET_CAPTURE) === 1) {
            $funde[] = "{$rel}:" . (substr_count($js, "\n", 0, $m[0][1]) + 1)
                . ': textContent setzen und innerHTML zurueckgeben maskiert " und \' nicht';
        }
    }

    assertSame([], $funde, "Textknoten-Maskierung, unter welchem Namen auch immer:\n  "
        . implode("\n  ", $funde));
});
