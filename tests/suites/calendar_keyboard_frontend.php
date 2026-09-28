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
 * Statische Gegenproben: Kalender per Tastatur bedienen (OI-96, mit OI-80).
 *
 * Spec: docs/superpowers/specs/2026-09-25-kalender-tastaturbedienung-design.md
 */

$ckRoot = dirname(__DIR__, 2);

/**
 * Quelltext einer Projektdatei, bereits ohne Kommentare.
 *
 * Liest ueber sourceCode() aus tests/lib/source.php (OI-107): Wer die Datei
 * roh einliest, bekommt die Kommentare mit -- und eine Zusicherung, die einen
 * Namen sucht, ist dann schon vom erklaerenden Kommentar darueber erfuellt.
 * Genau das ist im Projekt mehrfach passiert. tests/suites/source_lib.php
 * wacht darueber.
 */
function ckFile(string $root, string $rel): string
{
    $path = $root . '/' . $rel;
    assertTrue(is_file($path), "{$rel} fehlt");

    return sourceCode($path);
}

/** Rumpf einer JS-Funktion ab ihrer Signatur bis zur schliessenden Klammer in Spalte 0. */
function ckFunctionBody(string $js, string $signature): string
{
    assertSame(1, substr_count($js, $signature), "{$signature} kommt nicht genau einmal vor");
    $start = strpos($js, $signature);
    $next = preg_match('/\n\}/', $js, $m, PREG_OFFSET_CAPTURE, $start) ? $m[0][1] : strlen($js);

    return substr($js, $start, $next - $start);
}


/**
 * Der Rumpf von trapFocus ohne Kommentare, dazu der Name seines ersten
 * Parameters (des Dialogs). Der Name wird ausgelesen statt angenommen: Eine
 * Umbenennung ist keine Verschlechterung und soll nicht "Zusicherung verletzt"
 * melden.
 */
function ckTrapFocus(string $root): array
{
    $roh = ckFunctionBody(ckFile($root, 'public/js/modules/utils.js'), 'export function trapFocus(');
    assertTrue((bool) preg_match('/^export function trapFocus\(\s*(\w+)\s*,/', $roh, $sig),
        'trapFocus muss den Dialog als ersten Parameter nehmen');

    return [$roh, $sig[1]];
}

/**
 * Teilt den Rumpf am keydown-Hoerer: [was beim Oeffnen laeuft, der Hoerer].
 *
 * Ohne diese Trennung laesst sich nicht unterscheiden, was EINMAL beim Oeffnen
 * geschieht und was BEI JEDEM Tastendruck -- und genau daran haengt der Fang.
 * Der Hoerer wird ueber den Namen gefunden, den addEventListener uebergibt,
 * nicht ueber eine angenommene Schreibweise.
 */
function ckAmHoerer(string $body): array
{
    assertTrue((bool) preg_match('/addEventListener\(\s*\'keydown\'\s*,\s*(\w+)\s*\)/', $body, $m),
        'Der Fang haengt keinen keydown-Hoerer an');
    $name = preg_quote($m[1], '/');
    assertTrue((bool) preg_match('/function\s+' . $name . '\s*\(|(?:const|let|var)\s+' . $name . '\s*=/',
        $body, $d, PREG_OFFSET_CAPTURE),
        "Der Hoerer {$m[1]} wird nicht innerhalb von trapFocus erklaert");

    return [substr($body, 0, $d[0][1]), substr($body, $d[0][1])];
}

/** Name der Funktion, die die bedienbaren Elemente liefert. */
function ckListenFunktion(string $body): string
{
    assertTrue(substr_count($body, 'querySelectorAll') >= 1, 'Kein Selektor fuer bedienbare Elemente');
    assertTrue((bool) preg_match('/(?:const|let)\s+(\w+)\s*=\s*\(\s*\)\s*=>[^;]*querySelectorAll/s', $body, $m),
        'Die Liste muss aus einer Funktion kommen, nicht aus einer einmal gesetzten Konstanten');

    return $m[1];
}

test('trapFocus steht in utils.js und haelt Tab, Escape und die Rueckgabe', function () use ($ckRoot) {
    [$body] = ckTrapFocus($ckRoot);

    assertTrue(str_contains($body, "'Escape'"), 'Escape wird nicht behandelt');
    assertTrue(str_contains($body, "'Tab'"), 'Tab wird nicht behandelt');
    assertTrue(str_contains($body, 'shiftKey'), 'Shift+Tab fehlt -- rueckwaerts bliebe der Fang offen');

    // Das zuvor fokussierte Element muss gemerkt UND in der Freigabe wieder
    // angefahren werden -- geprueft auf die Herkunft des Wertes, nicht auf das
    // Vorkommen eines Namens (OI-107). Ein blosses
    // str_contains($body, 'activeElement') genuegt dafuer NICHT: Der Name steht
    // auch in den Tab-Vergleichen. Nachgestellt am 2026-09-25 -- die
    // Fokusrueckgabe ersatzlos entfernt (Merker und Wiederanfahren, fuenf
    // Zeilen), und die Suite blieb gruen. Genau dann faellt der Fang auf, wenn
    // es darauf ankommt: nach Escape stuende der Fokus auf body statt am Tag.
    assertTrue((bool) preg_match('/(?:const|let|var)\s+(\w+)\s*=\s*document\.activeElement/', $body, $merker),
        'Das zuvor fokussierte Element muss gemerkt werden, sonst gibt es keine Rueckgabe');

    // Am return aufgeteilt: Was danach steht, ist die Freigabe. Nur dort darf
    // das Wiederanfahren zaehlen -- im Rumpf davor waere es das Setzen des
    // Anfangsfokus und sagte nichts ueber die Rueckgabe.
    $teile = preg_split('/return\s+function|return\s*\(\s*\)\s*=>/', $body);
    assertSame(2, count($teile),
        'trapFocus muss eine Freigabe-Funktion liefern, sonst bleibt der Hoerer haengen');
    $freigabe = $teile[1];

    assertTrue((bool) preg_match('/\b' . preg_quote($merker[1], '/') . '\s*\.focus\(\)/', $freigabe),
        "Die Freigabe muss den Fokus auf das gemerkte Element zurueckgeben -- \"{$merker[1]}\" wird dort nicht angefahren");
    assertTrue(str_contains($freigabe, 'removeEventListener'),
        'Die Freigabe muss den Hoerer entfernen, sonst haelt der Fang nach dem Schliessen weiter Tab');
});

test('Der Dialog selbst wird anfahrbar -- unbedingt, nicht nur im Rueckfall', function () use ($ckRoot) {
    [$body, $el] = ckTrapFocus($ckRoot);
    [$beimOeffnen] = ckAmHoerer($body);

    // Im Browser nachgestellt: Ein Klick auf nicht bedienbare Flaeche IM Dialog
    // setzt document.activeElement auf BODY. Der Hoerer haengt am Dialog,
    // bekommt die Taste danach nicht mehr zu sehen -- Escape verpufft, Tab wird
    // nicht gehalten, und der Dialog ist per Tastatur nicht mehr erreichbar.
    // Das trifft OI-96 unmittelbar: Ein Klick auf freie Flaeche im Popup soll es
    // ausdruecklich NICHT schliessen, wer danach Escape druecken will, saesse
    // fest. tabindex="-1" am Dialog faengt den Klick auf.
    //
    // Geprueft wird die EINRUECKUNG: vier Leerzeichen heisst Rumpfebene von
    // trapFocus, acht hiessen innerhalb eines Zweiges. Genau das ist der
    // Unterschied -- im Rueckfallzweig allein (kein Bedienelement vorhanden)
    // stand es schon, und dort greift es fuer den Klickfall nicht.
    assertTrue((bool) preg_match('/(?:^|\n)    ' . preg_quote($el, '/')
        . '\.setAttribute\(\s*\'tabindex\'\s*,\s*\'-1\'\s*\)/', $beimOeffnen),
        'Der Dialog braucht tabindex="-1" auf Rumpfebene, nicht in einem Zweig -- sonst ist der Fang'
        . ' nach einem Klick auf freie Flaeche darin tot');
});

test('Der Fokus wandert beim Oeffnen in den Dialog', function () use ($ckRoot) {
    [$body, $el] = ckTrapFocus($ckRoot);
    $liste = preg_quote(ckListenFunktion($body), '/');
    [$beimOeffnen] = ckAmHoerer($body);

    // Ohne diese Zusicherung liess sich der Anfangsfokus ersatzlos entfernen
    // (zehn Zeilen) und die Suite blieb gruen -- der Fang haette dann nichts
    // gefangen: Der Fokus stuende weiter auf dem Kalendertag, Tab liefe an der
    // Knopfreihe vorbei in den Rest der Seite.
    assertTrue((bool) preg_match('/(?:const|let)\s+(\w+)\s*=\s*' . $liste . '\(\)\s*\[\s*0\s*\]/', $beimOeffnen, $m),
        'Der Anfangsfokus muss das erste bedienbare Element aus der Liste holen');
    assertTrue((bool) preg_match('/\b' . preg_quote($m[1], '/') . '\.focus\(\)/', $beimOeffnen),
        "Das erste bedienbare Element wird nicht angefahren -- \"{$m[1]}\" bekommt kein focus()");

    // Der Rueckfall: Ein Dialog ohne Bedienelement muss den Fokus selbst nehmen,
    // sonst laeuft Escape ins Leere. Liess sich ebenfalls entfernen, ohne rot zu
    // werden.
    assertTrue((bool) preg_match('/\b' . preg_quote($el, '/') . '\.focus\(\)/', $beimOeffnen),
        'Ohne Bedienelement muss der Dialog selbst den Fokus nehmen, sonst laeuft Escape ins Leere');
});

test('Die Liste der bedienbaren Elemente wird bei jedem Tab neu gelesen', function () use ($ckRoot) {
    [$body] = ckTrapFocus($ckRoot);

    // Neu gelesen, nicht einmal beim Oeffnen. Die urspruengliche Begruendung
    // der Spec -- die Rueckmeldezeile haenge an der Rolle, die Knopfreihe an
    // appointmentHasStarted() -- traegt zur Laufzeit NICHT: Beides steht beim
    // Bauen des Markups fest, und jedes Bedienelement ruft .remove() statt neu
    // zu zeichnen (Nachtrag vom 2026-09-25 in der Spec). Die Zusicherung bleibt
    // trotzdem richtig -- das Neulesen kostet nichts und macht den Helfer fuer
    // Aufrufer belastbar, deren Inhalt sich aendert.
    $liste = ckListenFunktion($body);

    // Eine Pfeilfunktion allein genuegt dafuer NICHT: Man kann sie einmal rufen
    // und das Ergebnis einfrieren -- die Zusicherung blieb gruen, obwohl genau
    // die gepruefte Eigenschaft fehlte. Verlangt wird deshalb der Aufruf IM
    // Hoerer und hinter der Tab-Pruefung: Dort wird die Liste gebraucht, und nur
    // dort ist sie zum Zeitpunkt des Tastendrucks gelesen.
    [, $hoerer] = ckAmHoerer($body);
    $tab = strpos($hoerer, "'Tab'");
    assertTrue($tab !== false, "Der Hoerer prueft nicht auf 'Tab'");
    assertTrue((bool) preg_match('/\b' . preg_quote($liste, '/') . '\s*\(\s*\)/', substr($hoerer, $tab)),
        "Die Liste muss bei jedem Tab neu gelesen werden -- \"{$liste}()\" wird hinter der Tab-Pruefung nicht gerufen");
});

test('Die Umlenkung springt in der richtigen Richtung', function () use ($ckRoot) {
    [$body, $el] = ckTrapFocus($ckRoot);
    $liste = preg_quote(ckListenFunktion($body), '/');
    [, $hoerer] = ckAmHoerer($body);

    assertTrue((bool) preg_match('/(?:const|let)\s+(\w+)\s*=\s*' . $liste . '\(\)/', $hoerer, $m),
        'Der Hoerer liest die bedienbaren Elemente nicht in eine eigene Liste');
    $l = preg_quote($m[1], '/');

    assertTrue((bool) preg_match('/(?:const|let)\s+(\w+)\s*=\s*' . $l . '\s*\[\s*0\s*\]/', $hoerer, $mAnfang),
        'Der Hoerer bestimmt das erste bedienbare Element nicht aus der Liste');
    $anfang = $mAnfang[1];
    assertTrue((bool) preg_match('/(?:const|let)\s+(\w+)\s*=\s*' . $l . '\s*\[\s*' . $l . '\.length\s*-\s*1\s*\]/',
        $hoerer, $mEnde),
        'Der Hoerer bestimmt das letzte bedienbare Element nicht aus der Liste');
    $ende = $mEnde[1];

    // Rueckwaerts am Anfang muss ans ENDE springen, vorwaerts am Ende an den
    // ANFANG. Vertauscht liefe der Fang rueckwaerts -- Tab sprang ans Ende,
    // Shift+Tab an den Anfang -- und das blieb gruen, weil beide Namen im Rumpf
    // ohnehin vorkommen. Bedingung UND Sprungziel werden deshalb gemeinsam
    // gelesen.
    //
    // Der Zweig wird an e.shiftKey aufgespannt: alles bis zur oeffnenden
    // Klammer ist seine Bedingung, das Folgende sein Rumpf. Damit haengt die
    // Zusicherung nicht an der Schreibweise -- ob die beiden Faelle mit ||,
    // mit includes([...]) oder mit some() verbunden sind, ist gleichgueltig;
    // geprueft wird, dass beide Vergleiche in DERSELBEN Bedingung stehen.
    $bedingungRumpf = '/\(\s*%se\.shiftKey\b([^{]*)\{([^}]*)\}/s';

    assertTrue((bool) preg_match(sprintf($bedingungRumpf, ''), $hoerer, $rueckwaerts),
        'Kein Zweig fuer Shift+Tab');
    [, $bedingungRw, $rumpfRw] = $rueckwaerts;

    assertTrue((bool) preg_match('/\b' . preg_quote($anfang, '/') . '\b/', $bedingungRw),
        'Der Shift+Tab-Zweig prueft nicht auf das erste bedienbare Element: ' . trim($bedingungRw));

    // Der Dialog selbst gehoert rueckwaerts in denselben Zweig. Ohne diesen
    // Fall ist der Fang nach einem Klick auf freie Flaeche darin nur halb
    // geheilt: Escape und Tab gehen wieder (siehe den Test zu tabindex="-1"),
    // Shift+Tab aber laeuft nativ auf das Element VOR dem Dialog in der
    // Dokumentreihenfolge -- beim Kalender-Popup, das am Ende von body haengt,
    // also in den Rest der Seite. Ein Fang, der in einer Richtung haelt und in
    // der anderen nicht, ist schlimmer als keiner, weil man sich auf ihn
    // verlaesst.
    //
    // Vorwaerts braucht es die Entsprechung NICHT: Nativ fuehrt Tab von einem
    // Container in dessen ersten bedienbaren Nachfahren -- Nachfahren folgen in
    // der Dokumentreihenfolge unmittelbar --, also genau dorthin, wohin der
    // Fang ihn setzen wuerde. Und gibt es keinen, hat die Pruefung auf eine
    // leere Liste vorher schon gehalten. Deshalb steht hier bewusst keine
    // spiegelbildliche Zusicherung fuer den Vorwaertszweig.
    assertTrue((bool) preg_match('/\b' . preg_quote($el, '/') . '\b/', $bedingungRw),
        'Der Shift+Tab-Zweig muss AUCH den Dialog selbst als "am Anfang" behandeln -- sonst'
        . ' verlaesst Shift+Tab nach einem Klick auf freie Flaeche den Fang: ' . trim($bedingungRw));

    assertTrue((bool) preg_match('/\b' . preg_quote($ende, '/') . '\.focus\(\)/', $rumpfRw),
        'Shift+Tab am Anfang muss ans Ende springen, nicht nach: ' . trim($rumpfRw));

    assertTrue((bool) preg_match(sprintf($bedingungRumpf, '!\s*'), $hoerer, $vorwaerts),
        'Kein Zweig fuer Tab vorwaerts');
    [, $bedingungVw, $rumpfVw] = $vorwaerts;

    assertTrue((bool) preg_match('/\b' . preg_quote($ende, '/') . '\b/', $bedingungVw),
        'Der Tab-Zweig prueft nicht auf das letzte bedienbare Element: ' . trim($bedingungVw));
    assertTrue((bool) preg_match('/\b' . preg_quote($anfang, '/') . '\.focus\(\)/', $rumpfVw),
        'Tab am Ende muss an den Anfang springen, nicht nach: ' . trim($rumpfVw));
});

/**
 * Ende einer Zeichenkette ab der Position ihres oeffnenden Anfuehrungszeichens.
 *
 * Genuegt fuer createCalendarDay(): Dort steckt kein Template-Literal im
 * eingebetteten Ausdruck eines anderen. Ein Template wird als Ganzes
 * uebersprungen -- die Klammern in seinen Ausdruecken sind ohnehin paarig, und
 * die Anfuehrungszeichen darin (etwa padStart(2, '0')) duerfen die Bilanz
 * nicht verwirren.
 *
 * ' und " enden wie in JavaScript am Zeilenende: Verliest sich der Leser doch
 * einmal, bricht er dann laut ab, statt stillschweigend halbe Dateien zu
 * ueberspringen.
 */
function ckStringEnde(string $js, int $auf): int
{
    $quote = $js[$auf];
    $n = strlen($js);
    for ($i = $auf + 1; $i < $n; $i++) {
        if ($js[$i] === '\\') {
            $i++;
            continue;
        }
        if ($js[$i] === $quote) {
            return $i;
        }
        if ($quote !== '`' && ($js[$i] === "\n" || $js[$i] === "\r")) {
            break;
        }
    }
    assertTrue(false, "Nicht geschlossene Zeichenkette ab Position {$auf}");

    return $n;
}

/**
 * Darf an dieser Stelle ein Regex-Literal beginnen, oder ist das / eine
 * Division?
 *
 * Dieselbe Entscheidungsregel wie stripJsComments() in tests/lib/source.php:
 * das letzte bedeutungstragende Zeichen entscheidet ('a' steht fuer Wert oder
 * Bezeichner), dazu die Woerter, hinter denen ein Wert erwartet wird. Die Regel
 * ist bewusst identisch und NICHT neu erfunden -- der zentrale Entferner ist
 * gegen einen echten Parser abgeglichen.
 *
 * Sauber wiederverwenden liesse sie sich nur, wenn source.php sie als eigene
 * Funktion anbietet; dort steckt sie heute im Rumpf von stripJsComments(). Ob
 * der Helfer dorthin gehoert, ist eine offene Frage an die Sitzung, die
 * source.php verantwortet.
 */
function ckRegexErlaubt(string $prev, string $word): bool
{
    static $regexAfterWord = [
        'return', 'typeof', 'case', 'do', 'else', 'in', 'of', 'new', 'delete',
        'void', 'throw', 'instanceof', 'yield', 'await',
    ];

    return $prev === '' || strpos('(,=:[!&|?{};+-*%<>~^', $prev) !== false
        || ($prev === 'a' && in_array($word, $regexAfterWord, true));
}

/**
 * Letztes Zeichen eines Regex-Literals ab seinem oeffnenden / -- Flags
 * eingeschlossen, eine Zeichenklasse [/] nicht als Ende missverstanden.
 *
 * Ohne diese Kenntnis verliest sich der Leser an genau den Stellen, um die es
 * im Haus gerade geht: escapeHtml() besteht aus fuenf .replace(/…/g, …). Ein
 * Literal wie /['"]/g liess ihn in die Anfuehrungszeichen laufen, ein
 * /\(/g kippte die Klammerbilanz -- am 2026-09-28 beide nachgestellt.
 */
function ckRegexEnde(string $js, int $auf): int
{
    $n = strlen($js);
    $j = $auf + 1;
    $inClass = false;
    while ($j < $n && $js[$j] !== "\n") {
        $ch = $js[$j];
        if ($ch === '\\') {
            $j += 2;
            continue;
        }
        if ($ch === '[') {
            $inClass = true;
        } elseif ($ch === ']') {
            $inClass = false;
        } elseif ($ch === '/' && !$inClass) {
            break;
        }
        $j++;
    }
    assertTrue($j < $n && $js[$j] === '/', "Nicht geschlossenes Regex-Literal ab Position {$auf}");

    $j++;
    while ($j < $n && ctype_alpha($js[$j])) {
        $j++;
    }

    return $j - 1;
}

/**
 * Ein Ausdruck ab $von, gelesen bis dorthin, wo er endet: bis zum Semikolon
 * auf Klammerbilanz 0 oder bis zu einer schliessenden Klammer, fuer die es
 * keine offene gibt -- der des umgebenden Aufrufs.
 *
 * Ein Muster wie /setAttribute\(\s*.aria-label.\s*,([^;]+);/ traegt hier
 * NICHT, am 2026-09-25 bestaetigt: Die Zuweisung im Belegt-Zweig ist eine
 * Verkettung ueber acht Zeilen, deren eingebettete Rueckruffunktion ein
 * eigenes Semikolon enthaelt. [^;]+ bricht dort ab, und die Zusicherung prueft
 * dann nur noch die kuerzere zweite Zuweisung -- die Haelfte dessen, was sie
 * zu pruefen behauptet, ohne rot zu werden.
 *
 * Zeichenketten, Template-Literale und Regex-Literale werden uebersprungen --
 * nur dort duerfen Klammern und Semikola die Bilanz nicht beruehren. $prev und
 * $word werden dafuer mitgefuehrt wie in stripJsComments().
 */
function ckAusdruck(string $js, int $von): string
{
    $tiefe = 0;
    $n = strlen($js);
    $prev = '';
    $word = '';

    for ($i = $von; $i < $n; $i++) {
        $c = $js[$i];

        if ($c === '\'' || $c === '"' || $c === '`') {
            $i = ckStringEnde($js, $i);
            $prev = 'a';
            $word = '';
            continue;
        }

        if ($c === '/' && ckRegexErlaubt($prev, $word)) {
            $i = ckRegexEnde($js, $i);
            $prev = 'a';
            $word = '';
            continue;
        }

        if (preg_match('/[A-Za-z0-9_$]+/A', $js, $m, 0, $i) === 1) {
            $i += strlen($m[0]) - 1;
            $prev = 'a';
            $word = $m[0];
            continue;
        }

        if ($c === '(' || $c === '[' || $c === '{') {
            $tiefe++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            if ($tiefe === 0) {
                return substr($js, $von, $i - $von);
            }
            $tiefe--;
        } elseif ($c === ';' && $tiefe === 0) {
            return substr($js, $von, $i - $von);
        }

        if (!ctype_space($c)) {
            $prev = $c;
            $word = '';
        }
    }
    assertTrue(false, "Ausdruck ab Position {$von} endet nicht");

    return '';
}

/**
 * Die Vorlesetexte aller aria-label-Zuweisungen eines Rumpfes, aufgeloest.
 *
 * Steht hinter dem Komma nur ein Name, wird dessen Deklaration eingesetzt: Der
 * Leer-Zweig legt seinen Text erst in eine Konstante und uebergibt dann diese.
 * Ohne das Aufloesen suchte die Zusicherung "holidayName" im Wort
 * "createLabel" -- und waere fuer diesen Zweig blind.
 */
function ckAriaLabelTexte(string $body): array
{
    $texte = [];
    $ab = 0;
    while (preg_match('/setAttribute\(\s*([\'"])aria-label\1\s*,\s*/', $body, $m, PREG_OFFSET_CAPTURE, $ab)) {
        $hinterKomma = $m[0][1] + strlen($m[0][0]);
        $text = ckAusdruck($body, $hinterKomma);
        $ab = $hinterKomma + max(1, strlen($text));

        // Laeuft der Leser ueber das schliessende ) seines eigenen Aufrufs
        // hinaus, verschluckt er den folgenden Code -- und eine Zusicherung,
        // die darin irgendwo ihren Namen findet, bleibt gruen. Ein zweites
        // setAttribute( im gelesenen Text kann nur so hineingeraten sein.
        assertTrue(!str_contains($text, 'setAttribute('),
            'Der Leser ist ueber das Ende der aria-label-Zuweisung hinausgelaufen'
            . ' -- gelesen bis: ' . trim((string) preg_replace('/\s+/', ' ', substr($text, -120))));

        if (preg_match('/^\s*(\w+)\s*$/', $text, $name)) {
            $n = preg_quote($name[1], '/');

            // GENAU eine Deklaration, nicht mindestens eine: Bei zwei
            // gleichnamigen nimmt der Leser die erste, auch wenn die zweite
            // den Geltungsbereich beherrscht. Eine tote Deklaration mit
            // Feiertag oben und eine abgeschattete ohne darunter liessen die
            // Zusicherung gruen, obwohl der Fehler da ist. Heute nicht
            // ausloesbar -- aber der Leser darf nicht raten muessen.
            $treffer = preg_match_all('/(?:const|let|var)\s+' . $n . '\s*=\s*/', $body, $alle,
                PREG_OFFSET_CAPTURE);
            assertSame(1, $treffer,
                "Der Vorlesetext steht in \"{$name[1]}\"; dafuer muss es im Rumpf genau eine"
                . " Deklaration geben, gefunden: {$treffer}");

            $text = ckAusdruck($body, $alle[0][0][1] + strlen($alle[0][0][0]));
        }

        $texte[] = $text;
    }

    return $texte;
}

/**
 * Der Belegt-Zweig von createCalendarDay(), von has-event bis zum else-if.
 *
 * Abgegrenzt wird am "} else if" auf VIER Leerzeichen -- der Einrueckung des
 * Zweiges selbst. Ein blosses strpos($body, '} else if') schneidet an der
 * falschen Stelle: Im Zweig steckt bei der Anwesenheitsanzeige ein zweites
 * "} else if" auf acht Leerzeichen, und alles danach -- Vorlesetext und
 * Hoerer -- fiele aus der Pruefung heraus. Die Zusicherungen meldeten dann
 * rot, obwohl der Code stimmt.
 */
function ckBelegtZweig(string $body): string
{
    $von = strpos($body, "classList.add('has-event')");
    assertTrue($von !== false, 'Der Belegt-Zweig ist nicht am has-event zu finden');
    $zweig = substr($body, (int) $von);

    $bis = strpos($zweig, "\n    } else if");
    assertTrue($bis !== false, 'Das Ende des Belegt-Zweiges ist nicht zu finden');

    return substr($zweig, 0, (int) $bis);
}

test('Ein Tag mit Terminen ist anfahrbar und oeffnet per Enter und Leertaste', function () use ($ckRoot) {
    $js = ckFile($ckRoot, 'public/js/modules/appointments.js');
    $body = ckFunctionBody($js, 'function createCalendarDay(');
    $belegt = ckBelegtZweig($body);

    assertTrue((bool) preg_match('/setAttribute\(\s*.role.\s*,\s*.button.\s*\)/', $belegt),
        'Der belegte Tag traegt keine Rolle -- ein Vorlesegeraet kuendigt ihn nicht als bedienbar an');
    assertTrue((bool) preg_match('/setAttribute\(\s*.tabindex.\s*,\s*.0.\s*\)/', $belegt),
        'Ohne tabindex ist der Tag mit Tab nicht erreichbar');
    assertTrue(str_contains($belegt, 'aria-haspopup'),
        'aria-haspopup sagt vorab, dass sich ein Dialog oeffnet');

    // Enter, Leertaste und preventDefault muessen IM keydown-Hoerer stehen,
    // nicht irgendwo im Zweig. Ohne diese Eingrenzung erfuellte der
    // Klick-Hoerer darueber die Zusicherung mit -- er ruft ebenfalls
    // showAppointmentPopup, und stopPropagation steht dort auch.
    assertTrue((bool) preg_match('/addEventListener\(\s*\'keydown\'\s*,\s*\(?\s*(\w+)\s*\)?\s*=>\s*\{/',
        $belegt, $m, PREG_OFFSET_CAPTURE),
        'Kein keydown-Hoerer im Belegt-Zweig -- das Popup bleibt ohne Maus unerreichbar');
    $e = preg_quote($m[1][0], '/');
    $hoerer = ckAusdruck($belegt, $m[0][1] + strlen($m[0][0]));

    assertTrue((bool) preg_match('/' . $e . '\.key === \'Enter\'[^)]*\|\|[^)]*' . $e . '\.key === \' \'/', $hoerer),
        'Enter und Leertaste muessen beide oeffnen -- der Leer-Zweig macht es seit OI-64 so vor');
    assertTrue(str_contains($hoerer, 'preventDefault'),
        'Ohne preventDefault rollt die Leertaste die Seite, waehrend sich das Popup oeffnet');
    assertTrue(str_contains($hoerer, 'showAppointmentPopup'),
        'Der keydown-Hoerer oeffnet das Popup nicht');
});

test('Der Feiertagsname steht vorn im Vorlesetext, in beiden Zweigen', function () use ($ckRoot) {
    $js = ckFile($ckRoot, 'public/js/modules/appointments.js');
    $body = ckFunctionBody($js, 'function createCalendarDay(');

    // Auf VIER Leerzeichen, also auf Rumpfebene: Im Feiertags-Block stuende
    // die Deklaration auf acht. Und weil dieser Block VOR dem classList.add
    // liegt, bliebe eine Suche nach dem blossen Namen im Textstueck davor auch
    // dann gruen, wenn holidayName wieder im Block gefangen waere -- also
    // genau bei der Verschlechterung, die sie verhindern soll. Nur die
    // Einrueckung unterscheidet die beiden Faelle.
    $vorFeiertag = substr($body, 0, (int) strpos($body, "classList.add('calendar-day--holiday')"));
    assertTrue((bool) preg_match('/(?:^|\n)    let\s+holidayName\b/', $vorFeiertag),
        'holidayName muss vor dem Feiertags-Block auf Rumpfebene mit let deklariert sein, damit beide'
        . ' Zweige ihn lesen');

    // Ein aria-label ueberschreibt den sichtbaren Text vollstaendig -- ohne den
    // Namen darin ist der Feiertag fuer Vorlesegeraete verschwunden, obwohl er
    // als span im Tagesfeld steht. Genau zwei Stellen setzen eines: der
    // Belegt-Zweig und der Leer-Zweig fuer Verwalter. Ein leerer Feiertag ohne
    // Verwalterrolle bekommt keines und liest den span -- dort ist nichts zu
    // tun.
    $texte = ckAriaLabelTexte($body);
    assertSame(2, count($texte),
        'Es muessen genau zwei aria-label-Zuweisungen sein (Belegt- und Leer-Zweig); gefunden: '
        . count($texte));

    foreach ($texte as $i => $text) {
        assertTrue(str_contains($text, 'holidayName'),
            'Der ' . ($i + 1) . '. Vorlesetext nennt den Feiertag nicht -- das aria-label ueberschreibt'
            . ' den sichtbaren Namen, er ist damit unhoerbar: '
            . trim((string) preg_replace('/\s+/', ' ', $text)));
    }

    // Vorn heisst: vor dem, was den Zweig ausmacht. Die Zuweisungen werden
    // dafuer an ihrem Inhalt unterschieden, nicht an ihrer Reihenfolge.
    $belegtText = '';
    $leerText = '';
    foreach ($texte as $text) {
        if (str_contains($text, 'dayAppointments')) {
            $belegtText = $text;
        } elseif (str_contains($text, 'Neuen Termin')) {
            $leerText = $text;
        }
    }
    assertTrue($belegtText !== '', 'Kein Vorlesetext, der die Termine des Tages auflistet');
    assertTrue($leerText !== '', 'Kein Vorlesetext fuer den leeren Tag');

    assertTrue(strpos($belegtText, 'holidayName') < strpos($belegtText, 'dayAppointments'),
        'Der Feiertagsname muss VOR der Terminliste stehen, sonst kommt er erst nach allen Terminen');
    assertTrue(strpos($leerText, 'holidayName') < strpos($leerText, 'Neuen Termin'),
        'Der Feiertagsname muss VOR der Aufforderung stehen');
});
