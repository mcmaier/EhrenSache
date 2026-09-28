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
 * Seit 2026-09-28 fragt der Waechter zusaetzlich, WOHIN ein Wert geht: ob er in
 * einem Attributwert oder im Elementinhalt landet (hsAttributeAt()). Fuer ein
 * style-Attribut gilt damit eine strengere Regel als fuer Elementinhalt --
 * dort genuegt Maskierung nicht, der Wert braucht eine Formatpruefung, weil
 * dort CSS steht und nicht HTML.
 *
 * Grenzen, bewusst:
 * - Nur Template-Interpolationen ${…} werden als roh erkannt, nicht
 *   String-Verkettung mit +.
 * - Variablen werden innerhalb derselben Datei aufgeloest, nicht ueber
 *   Funktionsparameter hinweg.
 * - hsDefinitions() kennt const/let/var und Zuweisung, nicht
 *   Objekteigenschaften, Destrukturierung oder den Parameter einer Pfeilfunktion
 *   in map(m => …).
 * - hsIsRawField() erkennt einen Rueckfall auf ein Literal (feld || 'x'), nicht
 *   eine Kette ueber ein zweites Feld (feld || anderesFeld || 'x').
 * - Die strengere Attributregel gilt bisher nur fuer style. Ein on…-Attribut
 *   ist derselbe Fall -- dort steht JavaScript, und Maskierung ist auch da die
 *   falsche Schranke --, braucht aber einen Begriff von "das kann nur eine Zahl
 *   oder ein Schluesselwort sein"; rund siebzig Einsetzungen im Dashboard
 *   haengen daran. Die eigentliche Abhilfe ist dort die Inhaltssicherheits-
 *   richtlinie fuer das Dashboard (OI-17, Etappe 2).
 * - Geltungsbereich sind die JS-Dateien aus hsScannedFiles(). Serverseitig
 *   gerendertes HTML (reset_password.php, verify_email.php, branding.php,
 *   email_templates/) sieht dieser Waechter nicht.
 *
 * Das Werkzeug ersetzt keine Durchsicht, es haelt den erreichten Stand fest.
 */

$hsRoot = dirname(__DIR__, 2);

/**
 * Felder, deren Inhalt der Waechter nicht als fest im Code stehend ansieht.
 *
 * Der Grossteil sind Freitextfelder aus der Datenbank, die ein Nutzer mit
 * Schreibrecht frei befuellt. message, hint und error kommen aus Serverantworten
 * und standen bis 2026-09-28 nicht in dieser Liste: Sie sind zwar meist fester
 * Text aus einem Handler, tragen aber auch weitergegebene Ausnahmetexte, und
 * deren Inhalt bestimmt nicht der Code.
 */
const HS_FIELDS = 'name|surname|title|description|comment|reason|type_name|group_name'
    . '|activity_name|note|location|member_number|email|organization_name|subgroup_name|device_name|filename|label'
    . '|message|hint|error';

// Ob ein / ein Regex-Literal beginnt, entscheidet jsRegexStart() aus tests/lib/source.php.
// Bis 2026-09-28 stand hier eine eigene Fassung (hsRegexStart/hsRegexEnd), die die
// Schluesselwoerter nicht kannte, nach denen ein Regex folgen darf (return /re/).
// Ohne die Unterscheidung gilt das " in .replace(/"/g, '&quot;') als Beginn einer
// Zeichenkette, und die Scanner laufen aus dem Takt.

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
        if (jsRegexStart($js, $i)) {
            $i = jsRegexEnd($js, $i);
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

/**
 * Nur die aeussersten ${…} eines Templates, als [Inhalt, Position des $].
 *
 * Fuer die Attributfrage zaehlt allein diese Ebene: Eine verschachtelte
 * Einsetzung steht nicht im Markup, sondern im Ausdruck darueber.
 */
function hsTopLevelInterpolations(string $expr): array
{
    $out = [];
    $pos = 0;
    while (($p = strpos($expr, '${', $pos)) !== false) {
        $end   = hsExpressionEnd($expr, $p + 2);
        $out[] = [substr($expr, $p + 2, $end - $p - 2), $p];
        $pos   = $end + 1;
    }

    return $out;
}

/**
 * Attributname, in dessen Wert die Stelle $pos eines Markup-Templates liegt --
 * oder null, wenn dort Elementinhalt steht.
 *
 * Diese Unterscheidung fehlte dem Waechter bis 2026-09-28: Er fragte nur, OB
 * maskiert wird, nie WOHIN der Wert geht. Solange jeder Wert durch
 * escapeHtml() laeuft, traegt das -- seit die Maskierung auch Anfuehrungszeichen
 * erfasst, ist ein Attribut so sicher wie Elementinhalt. Der Unterschied
 * zaehlt bei jedem Wert, der gar nicht maskiert wird: In einem style-Attribut
 * ist Maskierung die falsche Schranke, weil dort CSS steht und nicht HTML.
 *
 * Grenzen: Ein Attributwert ohne Anfuehrungen (style=${x}) wird erkannt, ein
 * Attribut, dessen Name selbst aus einer Einsetzung kommt (${attr}="x"), nicht.
 * Ueber Templategrenzen hinweg (`<i style="` + naechstes Template) ebenfalls
 * nicht -- im Projekt kommt das nicht vor.
 */
function hsAttributeAt(string $template, int $pos): ?string
{
    // Einsetzungen im Vorlauf sind undurchsichtig: Ein " in ${x ? "a" : "b"}
    // gehoert nicht zum Markup und darf die Anfuehrungen nicht mitzaehlen.
    $prefix = '';
    for ($i = 0; $i < $pos;) {
        if ($template[$i] === '$' && ($template[$i + 1] ?? '') === '{') {
            $prefix .= 'X';
            $i       = hsExpressionEnd($template, $i + 2) + 1;
            continue;
        }
        $prefix .= $template[$i];
        $i++;
    }

    $inTag     = false;
    $quote     = '';
    $attr      = null;
    $wantValue = false;   // direkt hinter einem = , Wert noch nicht begonnen
    $unquoted  = false;   // Wert ohne Anfuehrungen, endet am Leerzeichen
    $n         = strlen($prefix);
    for ($i = 0; $i < $n; $i++) {
        $c = $prefix[$i];
        if ($quote !== '') {
            if ($c === $quote) {
                $quote = '';
                $attr  = null;
            }
            continue;
        }
        if (!$inTag) {
            $inTag = $c === '<';
            continue;
        }
        if ($c === '>') {
            $inTag = false;
            $attr  = null;
            $wantValue = $unquoted = false;
        } elseif ($c === '=' && !$unquoted) {
            $attr      = preg_match('/([A-Za-z_:][-\w:.]*)\s*$/', substr($prefix, 0, $i), $m) === 1
                ? strtolower($m[1]) : null;
            $wantValue = true;
        } elseif ($wantValue && ($c === '"' || $c === "'")) {
            $quote     = $c;
            $wantValue = false;
        } elseif ($wantValue && !ctype_space($c)) {
            $wantValue = false;
            $unquoted  = true;
        } elseif ($unquoted && ctype_space($c)) {
            $unquoted = false;
            $attr     = null;
        }
    }

    return $inTag && ($quote !== '' || $wantValue || $unquoted) ? $attr : null;
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
        if (jsRegexStart($js, $i)) {
            $i = jsRegexEnd($js, $i);
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

/**
 * Jedes Template-Literal einer Datei als [Text, Position] -- auch die
 * verschachtelten.
 *
 * hsMarkupTemplates() haelt bei einem Markup-Template an und steigt nicht
 * hinein. Fuer die Feldfrage genuegt das, weil hsInterpolations() auch
 * verschachtelte ${…} einsammelt. Die Attributfrage braucht dagegen jede Ebene
 * fuer sich: Ein Wert in `<div style="${x}">` innerhalb eines .map() steht auf
 * der aeusseren Ebene nur als ganzer map()-Aufruf da.
 */
function hsAllTemplates(string $js, int $base = 0): array
{
    $out = [];
    $n   = strlen($js);
    for ($i = 0; $i < $n; $i++) {
        $c = $js[$i];
        if (jsRegexStart($js, $i)) {
            $i = jsRegexEnd($js, $i);
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
        $out[]    = [$template, $base + $i];
        $pos      = 0;
        while (($p = strpos($template, '${', $pos)) !== false) {
            $close = hsExpressionEnd($template, $p + 2);
            $inner = substr($template, $p + 2, $close - $p - 2);
            $out   = array_merge($out, hsAllTemplates($inner, $base + $i + $p + 2));
            $pos   = $close + 1;
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

/**
 * Ist der Ausdruck als Wert in einem style-Attribut unbedenklich?
 *
 * Unbedenklich heisst hier: sein Format ist geprueft oder er kann gar nichts
 * anderes sein als eine Zahl. Maskierung genuegt ausdruecklich NICHT -- ein
 * maskiertes "red; background:url(x)" steht weiterhin im Attribut, nur
 * unschaedlich verstuemmelt, und die eigentliche Aussage (das ist keine Farbe)
 * bliebe ungeprueft.
 */
function hsIsSafeStyleValue(string $expr): bool
{
    $e = trim($expr);

    // Die gemeinsamen Farbhelfer: utils.js (Dashboard) und die Fassung der
    // Check-in-App, die ohne Modulimport auskommen muss.
    if (preg_match('/^(?:safeTypeColor|safeHexColor)\s*\(/', $e) === 1) {
        return true;
    }

    // Eine Zahl bleibt eine Zahl -- Rechnung oder ausdrueckliche Wandlung.
    if (preg_match('/^(?:Number|parseInt|parseFloat)\s*\(|\.toFixed\s*\(/', $e) === 1) {
        return true;
    }

    // Reines Literal.
    return preg_match('/^(?:\'[^\']*\'|"[^"]*"|-?[\d.]+)$/', $e) === 1;
}

/**
 * Einsetzungen in einem style-Attribut, deren Wert nicht geprueft ist, als
 * [Zeile, Ausdruck].
 *
 * Ein einzelner Bezeichner wird ueber seine Definitionen aufgeloest, damit
 * "const typeColor = safeTypeColor(apt.color)" traegt. Nicht aufgeloest werden
 * Objekteigenschaften (accent.style) -- dafuer gibt es $hsStyleAllowed.
 */
function hsUncheckedStyleValues(string $js, string $template, int $at): array
{
    $out = [];
    foreach (hsTopLevelInterpolations($template) as [$content, $pos]) {
        if (hsAttributeAt($template, $pos) !== 'style' || hsIsSafeStyleValue($content)) {
            continue;
        }
        if (preg_match('/^\s*([A-Za-z_$][\w$]*)\s*$/', $content, $m) === 1) {
            $defs = hsDefinitions($js, $m[1], $at);
            $ungeprueft = array_filter($defs, static fn (string $d): bool => !hsIsSafeStyleValue($d));
            if ($defs !== [] && $ungeprueft === []) {
                continue;
            }
        }
        $out[] = [substr_count($js, "\n", 0, $at + $pos) + 1, trim($content)];
    }

    return $out;
}

/** Die Dateien, die beide Waechter durchsehen. */
function hsScannedFiles(string $root): array
{
    return array_merge(
        glob($root . '/public/js/modules/*.js') ?: [],
        [$root . '/public/js/app.js', $root . '/public/js/login.js', $root . '/public/js/theme.js',
         $root . '/public/checkin/js/app.js', $root . '/public/station/js/app.js']
    );
}

/** @return string[] Funde "datei:zeile: ausdruck" */
function hsStyleFindings(string $root): array
{
    $funde = [];
    foreach (hsScannedFiles($root) as $file) {
        $js  = sourceCode($file);
        $rel = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root)) + 1);
        foreach (hsAllTemplates($js) as [$template, $at]) {
            foreach (hsUncheckedStyleValues($js, $template, $at) as [$line, $expr]) {
                $funde[] = "{$rel}:{$line}: {$expr}";
            }
        }
    }

    return array_values(array_unique($funde));
}

/** @return string[] Funde "datei:zeile: feld" */
function hsFindings(string $root): array
{
    $funde = [];
    $files = hsScannedFiles($root);
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

test('Werkzeug: unterscheidet Attributwert von Elementinhalt', function () {
    $t = '`<i style="background: ${a}" title="${b}">${c}</i><b data-x=${d} class="k">${e}</b>`';
    $orte = [];
    foreach (hsTopLevelInterpolations($t) as [$inhalt, $pos]) {
        $orte[trim($inhalt)] = hsAttributeAt($t, $pos) ?? '(inhalt)';
    }

    assertSame([
        'a' => 'style',
        'b' => 'title',
        'c' => '(inhalt)',
        'd' => 'data-x',     // Wert ohne Anfuehrungen zaehlt auch als Attribut
        'e' => '(inhalt)',   // nach dem schliessenden > wieder Inhalt
    ], $orte);
});

test('Werkzeug: ein Anfuehrungszeichen in einer Einsetzung verschiebt den Attributort nicht', function () {
    // Der Vorlauf wird um jede Einsetzung gekuerzt. Ohne das gaelte das " in
    // ${x ? "a" : "b"} als Ende des style-Attributs, und ${c} stuende
    // scheinbar im Elementinhalt.
    $t = '`<i style="${x ? "a" : "b"}; color: ${c}">t</i>`';
    $orte = [];
    foreach (hsTopLevelInterpolations($t) as [$inhalt, $pos]) {
        $orte[] = hsAttributeAt($t, $pos);
    }

    assertSame(['style', 'style'], $orte);
});

test('Werkzeug: im style-Attribut genuegt Maskierung nicht, im Inhalt schon', function () {
    $js = <<<'JS'
function render(a, t) {
    const geprueft = safeTypeColor(a.color);
    const roh = a.color;
    box.innerHTML = `<i style="background: ${escapeHtml(a.color)}">${escapeHtml(a.activity_name)}</i>`;
    ok.innerHTML = `<i style="background: ${geprueft}"></i>`;
    ok2.innerHTML = `<i style="width: ${(t.rate * 100).toFixed(1)}%"></i>`;
    ok3.innerHTML = `<i title="${escapeHtml(a.color)}"></i>`;
    bad.innerHTML = `<i style="background: ${roh}"></i>`;
    list.innerHTML = `<ul>${t.rows.map(r => `<li style="background: ${r.color}"></li>`).join('')}</ul>`;
}
JS;
    $funde = [];
    foreach (hsAllTemplates($js) as [$template, $at]) {
        foreach (hsUncheckedStyleValues($js, $template, $at) as [$line, $expr]) {
            $funde[] = "{$line}: {$expr}";
        }
    }
    sort($funde);

    // Zeile 4: maskiert, aber nicht auf Farbe geprueft -- genau die Bauart, die
    // der Waechter vorher nicht sah. Zeile 8 ueber die Variable roh. Zeile 9
    // steckt in einem Template innerhalb eines .map(); ohne den Abstieg in jede
    // Ebene faellt sie still heraus. Die geprueften Zeilen 5 und 6 und das
    // title-Attribut in Zeile 7 fehlen zu Recht.
    assertSame(['4: escapeHtml(a.color)', '8: roh', '9: r.color'], $funde);
});

/**
 * Einsetzungen in style-Attributen, die ohne Farbhelfer auskommen. Wie bei
 * $hsPreEscaped nennt jede Ausnahme ihre Quelle, und der Test prueft sie mit.
 *
 * Der Schluessel ist der Ausdruck, nicht Datei und Zeile: Eine Ausnahme gilt
 * damit projektweit. Das ist vertretbar, solange die Namen so besonders sind
 * wie hier -- bei einem Alltagsnamen waere eine Datei mitzunennen.
 */
$hsStyleAllowed = [
    'accent.style' => ['public/js/modules/records.js',
        '/style:\s*`--type-color: \$\{safeTypeColor\(/',
        'appointmentTypeAccent() baut style bereits mit safeTypeColor()'],
    'source.color' => ['public/js/modules/records.js',
        '/const source = sources\[record\.checkin_source\] \|\| sources\[\'none\'\];/',
        'source kommt aus der festen Tabelle sources in getSourceBadge(), kein Freitext'],
    'member.attendance_rate' => ['private/helpers/attendance.php',
        '/function attendanceRate\(int \$attended, int \$total\): float/',
        'attendanceRate() liefert float, der Server rechnet die Quote'],
    't.attendance_rate' => ['private/helpers/attendance.php',
        '/function attendanceRate\(int \$attended, int \$total\): float/',
        'attendanceRate() liefert float, der Server rechnet die Quote'],
];

test('Ausnahmen: erlaubte style-Werte sind an ihrer Quelle wirklich geprueft', function () use ($hsRoot, $hsStyleAllowed) {
    foreach ($hsStyleAllowed as $ausdruck => [$datei, $muster, $grund]) {
        assertTrue(preg_match($muster, sourceCode($hsRoot . '/' . $datei)) === 1,
            "{$ausdruck}: {$grund} — gilt nicht mehr, die Ausnahme ist zu streichen");
    }
});

test('Kein style-Attribut erhaelt einen ungepruefeten Wert', function () use ($hsRoot, $hsStyleAllowed) {
    $funde = array_values(array_filter(
        hsStyleFindings($hsRoot),
        static fn (string $f): bool => !isset($hsStyleAllowed[substr($f, strrpos($f, ': ') + 2)])
    ));

    assertTrue($funde === [],
        "Wert ohne Formatpruefung in einem style-Attribut (safeTypeColor()/safeHexColor() statt escapeHtml()):\n  "
        . implode("\n  ", $funde));
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
