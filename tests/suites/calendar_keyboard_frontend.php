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
 * Ein Stueck Quelltext ab $von, gelesen bis dorthin, wo es endet: bis zu einer
 * schliessenden Klammer, fuer die es keine offene gibt -- und, wenn
 * $semikolonEndet gilt, auch bis zum Semikolon auf Klammerbilanz 0.
 *
 * Gemeinsamer Kern von ckAusdruck() und ckBlockRumpf(). Ein Block endet NICHT
 * am Semikolon: Seine Anweisungen tragen selbst welche.
 *
 * Ein Muster wie /setAttribute\(\s*.aria-label.\s*,([^;]+);/ traegt hier
 * NICHT, am 2026-09-25 bestaetigt: Die Zuweisung im Belegt-Zweig ist eine
 * Verkettung ueber acht Zeilen, deren eingebettete Rueckruffunktion ein
 * eigenes Semikolon enthaelt. [^;]+ bricht dort ab, und die Zusicherung prueft
 * dann nur noch die kuerzere zweite Zuweisung -- die Haelfte dessen, was sie
 * zu pruefen behauptet, ohne rot zu werden.
 *
 * Zeichenketten, Template-Literale und Regex-Literale werden uebersprungen --
 * nur dort duerfen Klammern und Semikola die Bilanz nicht beruehren. Ob ein /
 * ein Literal beginnt, entscheidet jsRegexStart() rueckwaerts im Text -- der
 * ist kommentarfrei, weil ckFile() ueber sourceCode() liest.
 */
function ckLiesBis(string $js, int $von, bool $semikolonEndet): string
{
    $tiefe = 0;
    $n = strlen($js);

    for ($i = $von; $i < $n; $i++) {
        $c = $js[$i];

        if ($c === '\'' || $c === '"' || $c === '`') {
            $i = ckStringEnde($js, $i);
            continue;
        }

        if ($c === '/' && jsRegexStart($js, $i)) {
            // jsRegexEnd() zeigt auf das schliessende / OHNE Flags. Die Flags
            // liest die Schleife danach als Bezeichner weiter -- sie aendern
            // die Klammerbilanz nicht.
            $i = jsRegexEnd($js, $i);
            continue;
        }

        if (preg_match('/[A-Za-z0-9_$]+/A', $js, $m, 0, $i) === 1) {
            $i += strlen($m[0]) - 1;
            continue;
        }

        if ($c === '(' || $c === '[' || $c === '{') {
            $tiefe++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            if ($tiefe === 0) {
                return substr($js, $von, $i - $von);
            }
            $tiefe--;
        } elseif ($semikolonEndet && $c === ';' && $tiefe === 0) {
            return substr($js, $von, $i - $von);
        }

    }
    assertTrue(false, "Gelesenes Stueck ab Position {$von} endet nicht");

    return '';
}

/** Ein Ausdruck ab $von -- bis zum Semikolon oder zur fremden Klammer. */
function ckAusdruck(string $js, int $von): string
{
    return ckLiesBis($js, $von, true);
}

/** Der Rumpf eines Blocks ab der Position HINTER seiner oeffnenden Klammer. */
function ckBlockRumpf(string $js, int $von): string
{
    return ckLiesBis($js, $von, false);
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


// ============================================================
// Task 4 der Spec: Das festgehaltene Popup wird ein Dialog
// ============================================================

/** Der Rumpf von showAppointmentPopup(). */
function ckPopupRumpf(string $root): string
{
    return ckFunctionBody(ckFile($root, 'public/js/modules/appointments.js'),
        'function showAppointmentPopup(');
}

/** Name des angelegten Popup-Elements -- ausgelesen, nicht angenommen. */
function ckPopupVar(string $body): string
{
    assertTrue((bool) preg_match('/(?:const|let)\s+(\w+)\s*=\s*document\.createElement\(/', $body, $m),
        'showAppointmentPopup() legt kein Element an');

    return $m[1];
}

/** Rumpf einer im Popup-Rumpf erklaerten Funktion, ueber ihren Namen gefunden. */
function ckInnereFunktion(string $body, string $name): string
{
    $n = preg_quote($name, '/');
    assertTrue((bool) preg_match('/function\s+' . $n . '\s*\([^)]*\)\s*\{/', $body, $m, PREG_OFFSET_CAPTURE),
        "Die Funktion {$name}() ist in showAppointmentPopup() nicht erklaert");

    return ckBlockRumpf($body, $m[0][1] + strlen($m[0][0]));
}

/** Text hinter der fruehen Rueckkehr "if (!fest) { return; }". */
function ckNachFestRueckkehr(string $body): string
{
    assertTrue((bool) preg_match('/if\s*\(\s*!\s*fest\s*\)\s*\{\s*return\s*;\s*\}/', $body, $m,
        PREG_OFFSET_CAPTURE),
        'Ohne die fruehe Rueckkehr "if (!fest) { return; }" laesst sich nicht unterscheiden, was nur'
        . ' fuer das festgehaltene Popup laeuft -- die Gegenprobe fuer das Ueberfahr-Popup haette dann'
        . ' keinen Sinn');

    return substr($body, $m[0][1] + strlen($m[0][0]));
}

/**
 * Eine Maske ueber den Rumpf: true an jeder Stelle, die NUR fuer das
 * festgehaltene Popup laeuft.
 *
 * Das sind die Rumpfe aller "if (fest)"-Bloecke und alles hinter der fruehen
 * Rueckkehr. Eine Maske statt zweier Textstuecke, weil sich Bereiche sonst
 * ueberlappen koennten -- gezaehlte Vorkommen waeren dann doppelt gezaehlt und
 * die Gegenprobe meldete gruen oder rot, je nach Anordnung.
 */
function ckFestMaske(string $body): array
{
    $laenge = strlen($body);
    $maske = array_fill(0, $laenge + 1, false);

    $nach = ckNachFestRueckkehr($body);
    for ($i = $laenge - strlen($nach); $i < $laenge; $i++) {
        $maske[$i] = true;
    }

    // Ein Ternaer im Template-Literal ("${fest && isAdminOrManager ? ...}") ist
    // damit nicht gemeint und wird nicht getroffen: Verlangt ist die oeffnende
    // Klammer eines Blocks.
    $bloecke = 0;
    $ab = 0;
    while (preg_match('/if\s*\(\s*fest\s*\)\s*\{/', $body, $m, PREG_OFFSET_CAPTURE, $ab)) {
        $auf = $m[0][1] + strlen($m[0][0]);
        $rumpf = ckBlockRumpf($body, $auf);
        for ($i = $auf; $i < $auf + strlen($rumpf); $i++) {
            $maske[$i] = true;
        }
        $bloecke++;
        $ab = $auf;
    }
    assertTrue($bloecke > 0, 'Kein "if (fest)"-Block in showAppointmentPopup()');

    return $maske;
}

/**
 * Jedes Vorkommen von $muster liegt im Bereich, der nur fuer das festgehaltene
 * Popup laeuft -- und es gibt mindestens eines.
 *
 * Beides gehoert zusammen: Ohne die untere Grenze waere die Zusicherung von
 * einer geloeschten Zeile erfuellt, ohne die Maske von einer Zeile, die das
 * fluechtige Ueberfahr-Popup mitnimmt.
 */
function ckNurBeiFest(string $body, array $maske, string $muster, string $was): void
{
    $treffer = preg_match_all($muster, $body, $alle, PREG_OFFSET_CAPTURE);
    assertTrue($treffer > 0, "{$was}: kein Vorkommen in showAppointmentPopup()");

    foreach ($alle[0] as $t) {
        assertTrue($maske[$t[1]],
            "{$was} steht ausserhalb des Zweiges fuer das festgehaltene Popup -- das fluechtige"
            . ' Ueberfahr-Popup bekaeme es mit: ' . trim((string) preg_replace('/\s+/', ' ', $t[0])));
    }
}

/**
 * Der Aufruf des Fokusfangs: [Name der Freigabe, Name der Schliessfunktion].
 *
 * Beide Namen werden ausgelesen statt angenommen -- eine Umbenennung ist keine
 * Verschlechterung.
 */
function ckFangAufruf(string $body, string $popupVar): array
{
    assertTrue((bool) preg_match('/(?:const|let)\s+(\w+)\s*=\s*trapFocus\(\s*'
        . preg_quote($popupVar, '/') . '\s*,\s*(?:\(\s*\)\s*=>\s*)?(\w+)\s*\(/', $body, $m),
        'Der Fokusfang muss auf das Popup gelegt und seine Freigabe behalten werden -- ohne die'
        . ' Rueckgabe in einer Variablen kann sie niemand rufen, und der Fang haelt Tab weiter');

    return [$m[1], $m[2]];
}

test('Nur das festgehaltene Popup ist ein Dialog', function () use ($ckRoot) {
    $body = ckPopupRumpf($ckRoot);
    $maske = ckFestMaske($body);

    // Ein aria-modal am fluechtigen Ueberfahr-Popup waere schaedlich, nicht
    // bloss unnoetig: Ein Vorlesegeraet blendet dann alles ausserhalb aus,
    // waehrend der Nutzer nur mit der Maus ueber den Kalender wandert.
    ckNurBeiFest($body, $maske, '/setAttribute\(\s*([\'"])role\1\s*,\s*([\'"])dialog\2\s*\)/',
        'role="dialog"');
    ckNurBeiFest($body, $maske, '/setAttribute\(\s*([\'"])aria-modal\1\s*,/', 'aria-modal');
    ckNurBeiFest($body, $maske, '/setAttribute\(\s*([\'"])aria-label\1\s*,/',
        'Der Vorlesetext des Dialogs');
});

test('Der Vorlesetext des Dialogs nennt das Datum in deutscher Schreibweise', function () use ($ckRoot) {
    $body = ckPopupRumpf($ckRoot);

    // Geprueft auf die HERKUNFT des Wertes: Verlangt wird der Name, der aus
    // toLocaleDateString('de-DE', ...) kommt. Eine Zusicherung auf die
    // Zeichenkette "de-DE" allein waere von der Kopfzeile darueber schon
    // erfuellt, ohne dass der Vorlesetext davon etwas hat.
    $namen = [];
    $ab = 0;
    while (preg_match('/(?:const|let)\s+(\w+)\s*=\s*/', $body, $m, PREG_OFFSET_CAPTURE, $ab)) {
        $von = $m[0][1] + strlen($m[0][0]);
        $wert = ckAusdruck($body, $von);
        if (str_contains($wert, "toLocaleDateString('de-DE'")) {
            $namen[] = $m[1][0];
        }
        $ab = $von + max(1, strlen($wert));
    }
    assertSame(1, count($namen),
        'In showAppointmentPopup() muss genau ein Wert aus toLocaleDateString(\'de-DE\', ...) kommen,'
        . ' damit klar ist, welcher gemeint ist; gefunden: ' . count($namen));

    $texte = ckAriaLabelTexte($body);
    assertSame(1, count($texte),
        'Genau eine aria-label-Zuweisung erwartet (die des Dialogs); gefunden: ' . count($texte));

    assertTrue(str_contains($texte[0], $namen[0]),
        "Der Vorlesetext des Dialogs muss das deutsch geschriebene Datum aus \"{$namen[0]}\" nennen: "
        . trim((string) preg_replace('/\s+/', ' ', $texte[0])));

    // appointments[0].date und tagDatum sind ISO. "2026-09-21" liest ein
    // Vorlesegeraet als Zahlenfolge vor -- genau das soll der Dialog nicht tun.
    assertTrue(!preg_match('/appointments\s*\[\s*0\s*\]\s*\.date|\btagDatum\b/', $texte[0]),
        'Der Vorlesetext des Dialogs darf das ISO-Datum nicht nennen: '
        . trim((string) preg_replace('/\s+/', ' ', $texte[0])));
});

test('trapFocus kommt aus utils.js und greift nur beim festgehaltenen Popup', function () use ($ckRoot) {
    $js = ckFile($ckRoot, 'public/js/modules/appointments.js');
    assertTrue((bool) preg_match('/import\s*\{[^}]*\btrapFocus\b[^}]*\}\s*from\s*[\'"][^\'"]*utils\.js[\'"]/',
        $js),
        'trapFocus muss aus utils.js importiert werden -- ein eigener Fang im Kalender waere der zweite');

    $body = ckFunctionBody($js, 'function showAppointmentPopup(');
    $maske = ckFestMaske($body);

    // Der Fang am fluechtigen Popup waere kein Schoenheitsfehler: Er zoege den
    // Fokus beim blossen Ueberfahren aus dem Feld, in dem der Nutzer gerade
    // tippt, und hielte Tab in einem Popup, das beim mouseleave verschwindet.
    ckNurBeiFest($body, $maske, '/\btrapFocus\s*\(/', 'Der Fokusfang');

    ckFangAufruf($body, ckPopupVar($body));
});

test('Der Klick-Hoerer prueft die Herkunft des Klicks', function () use ($ckRoot) {
    $body = ckPopupRumpf($ckRoot);
    $popupVar = ckPopupVar($body);
    [, $schliessen] = ckFangAufruf($body, $popupVar);

    assertTrue((bool) preg_match('/document\.addEventListener\(\s*([\'"])click\1\s*,\s*(\w+)\s*\)/',
        $body, $m),
        'Kein Dokument-Klick-Hoerer -- ein Klick daneben liesse das Popup stehen');
    $hoerer = ckInnereFunktion($body, $m[2]);

    // Der Zweig wird aufgespannt: Bedingung und Rumpf gemeinsam gelesen. Ein
    // blosses str_contains($hoerer, 'contains') liesse sich mit einer
    // umgedrehten Pruefung erfuellen, die genau das Gegenteil tut.
    assertTrue((bool) preg_match('/if\s*\(\s*' . preg_quote($popupVar, '/')
        . '\.contains\(\s*(\w+)\.target\s*\)\s*\)\s*\{([^}]*)\}/', $hoerer, $z),
        'Der Klick-Hoerer prueft nicht, ob der Klick IM Popup lag -- mit Fokusfang ist das ein'
        . ' Widerspruch: Tab bleibt drin, ein Klick auf freie Flaeche wuerfe hinaus');
    assertTrue(str_contains($z[2], 'return'),
        'Ein Klick im Popup muss den Hoerer verlassen, ohne zu schliessen: ' . trim($z[2]));
    assertTrue(!str_contains($z[2], $schliessen),
        "Ein Klick im Popup darf nicht schliessen -- {$schliessen}() steht im falschen Zweig: "
        . trim($z[2]));

    assertTrue((bool) preg_match('/\b' . preg_quote($schliessen, '/') . '\s*\(/', $hoerer),
        "Der Klick-Hoerer schliesst auf keinem Weg -- {$schliessen}() wird nicht gerufen");
});

test('Eine gemeinsame Schliessfunktion, ueber die alle Wege hinaus laufen', function () use ($ckRoot) {
    $body = ckPopupRumpf($ckRoot);
    $popupVar = ckPopupVar($body);
    [$freigabe, $schliessen] = ckFangAufruf($body, $popupVar);
    $rumpf = ckInnereFunktion($body, $schliessen);

    // Was die Schliessfunktion tun muss: den Fang loesen, das Popup wegnehmen
    // und den Dokument-Hoerer abmelden. Fehlt das Loesen, haelt der Fang nach
    // dem Schliessen weiter Tab; fehlt das Abmelden, bleibt ein Hoerer auf
    // document zurueck.
    assertTrue((bool) preg_match('/\b' . preg_quote($freigabe, '/') . '\s*\(/', $rumpf),
        "{$schliessen}() gibt den Fokusfang nicht frei -- {$freigabe}() wird dort nicht gerufen");
    assertTrue((bool) preg_match('/\b' . preg_quote($popupVar, '/') . '\.remove\(\s*\)/', $rumpf),
        "{$schliessen}() nimmt das Popup nicht weg");
    assertTrue((bool) preg_match('/removeEventListener\(\s*([\'"])click\1/', $rumpf),
        "{$schliessen}() meldet den Dokument-Klick-Hoerer nicht ab");

    // Und: NUR sie. Ein zweiter Weg, der das Popup wegnimmt oder den Fang
    // loest, ist genau der Fehler, den eine gemeinsame Funktion verhindern
    // soll -- er laesst den Fang irgendwann haengen oder gibt ihn doppelt frei.
    // Gezaehlt wird im Zweig fuer das festgehaltene Popup; oldPopup.remove()
    // weiter oben traegt einen anderen Namen und liegt ausserhalb.
    $nachFest = ckNachFestRueckkehr($body);
    foreach ([
        preg_quote($popupVar, '/') . '\.remove\(\s*\)' => 'Das Popup wird weggenommen',
        preg_quote($freigabe, '/') . '\s*\(' => 'Der Fokusfang wird freigegeben',
    ] as $muster => $was) {
        $imZweig = preg_match_all('/' . $muster . '/', $nachFest);
        $inFunktion = preg_match_all('/' . $muster . '/', $rumpf);
        assertSame($inFunktion, $imZweig,
            "{$was} auch ausserhalb von {$schliessen}(): {$imZweig} Vorkommen im Zweig fuer das"
            . " festgehaltene Popup, davon {$inFunktion} in der gemeinsamen Schliessfunktion");
    }

    // Escape laeuft ueber denselben Weg -- und ist der einzige, der den Fokus
    // an den Kalendertag zurueckgibt. Auf jedem anderen Weg hat etwas anderes
    // die Fuehrung: Ein Klick hat sein Ziel selbst gewaehlt, ein Knopf im Popup
    // hat ein Modal geoeffnet. Deshalb wird hier der WAHRE Wert verlangt, nicht
    // bloss ein Aufruf.
    assertTrue((bool) preg_match('/trapFocus\([^;]*?\b' . preg_quote($schliessen, '/')
        . '\(\s*true\s*\)/s', $body),
        "Escape muss {$schliessen}(true) rufen -- ohne die Fokusrueckgabe stuende der Fokus danach"
        . ' auf body statt am Kalendertag');
});


// ============================================================
// Task 5 der Spec: Die Termin-Bloecke bekommen einen Namen, keinen Tab-Stopp
// ============================================================

/**
 * Das oeffnende Tag des Termin-Blocks im Markup von showAppointmentPopup().
 *
 * Gesucht wird von der Klasse aus RUECKWAERTS bis zum "<", nicht ab
 * '<div class="calendar-event-block"': Damit haengt keine Zusicherung an der
 * Reihenfolge der Attribute -- role, aria-label und style duerfen in jeder
 * Folge stehen.
 *
 * Ein eingesetzter Ausdruck ${…} wird uebersprungen. Ohne das waere ein ">"
 * darin (ein Vergleich, eine Pfeilfunktion) das vermeintliche Ende des Tags:
 * Der abgeschnittene Rest fiele aus der Pruefung, und eine Zusicherung auf ein
 * fehlendes Attribut meldete rot, obwohl es dasteht -- oder eine Gegenprobe auf
 * tabindex bliebe gruen, obwohl es dahinter steht.
 */
function ckBlockTag(string $body): string
{
    $marke = strpos($body, 'calendar-event-block');
    assertTrue($marke !== false, 'Der Block je Termin fehlt im Markup des Popups');
    $von = strrpos(substr($body, 0, $marke), '<');
    assertTrue($von !== false, 'Vor der Klasse calendar-event-block steht kein oeffnendes Tag');

    $n = strlen($body);
    for ($i = (int) $von; $i < $n; $i++) {
        if ($body[$i] === '$' && ($body[$i + 1] ?? '') === '{') {
            $tiefe = 1;
            for ($i += 2; $i < $n && $tiefe > 0; $i++) {
                if ($body[$i] === '{') {
                    $tiefe++;
                } elseif ($body[$i] === '}') {
                    $tiefe--;
                }
            }
            $i--;
            continue;
        }
        if ($body[$i] === '>') {
            return substr($body, (int) $von, $i - (int) $von + 1);
        }
    }
    assertTrue(false, 'Das oeffnende Tag des Termin-Blocks endet nicht');

    return '';
}

/** Der eingesetzte Ausdruck im aria-label des Termin-Blocks. */
function ckBlockLabelAusdruck(string $tag): string
{
    assertTrue((bool) preg_match('/\saria-label="\$\{([^}]*)\}"/', $tag, $m),
        'Der Termin-Block traegt keinen eingesetzten Vorlesetext -- eine Gruppe ohne Namen nennt beim'
        . ' Betreten nichts und ist damit nutzlos: ' . trim((string) preg_replace('/\s+/', ' ', $tag)));

    return $m[1];
}

test('Der Termin-Block ist eine Gruppe, deren Name aus dem Termin kommt', function () use ($ckRoot) {
    $body = ckPopupRumpf($ckRoot);
    $tag = ckBlockTag($body);

    assertTrue((bool) preg_match('/\srole="group"/', $tag),
        'Der Termin-Block traegt kein role="group" -- wer durch die Knoepfe tabbt, hoert dann'
        . ' "Bearbeiten, Anwesenheit, Bearbeiten, Anwesenheit", ohne zu wissen, zu welchem Termin sie'
        . ' gehoeren: ' . trim((string) preg_replace('/\s+/', ' ', $tag)));

    // Maskierung ist Pflicht, der Wert landet in einem Attribut. Verlangt wird
    // ausdruecklich escapeHtml() -- dieselbe Funktion wie ueberall sonst. Sie
    // erfasst seit 1.17.0 auch " und '. Ein zweiter, eigener Weg fuer
    // Attributwerte war im Haus die Ursache einer Luecke; genau das soll hier
    // nicht wieder entstehen.
    $ausdruck = ckBlockLabelAusdruck($tag);
    assertTrue((bool) preg_match('/^\s*escapeHtml\(\s*([\w$]+)\s*\)\s*$/', $ausdruck, $m),
        'Der Name des Blocks muss durch escapeHtml() laufen -- er steht in einem Attributwert: '
        . trim($ausdruck));

    // Aufgeloest bis zur Deklaration: Sonst prueft die Zusicherung nur, DASS
    // etwas maskiert wird, nicht WAS. Ein aria-label="${escapeHtml('Termin')}"
    // waere sonst gruen und sagte nichts ueber den Termin.
    $n = preg_quote($m[1], '/');
    $treffer = preg_match_all('/(?:const|let|var)\s+' . $n . '\s*=\s*/', $body, $alle, PREG_OFFSET_CAPTURE);
    assertSame(1, $treffer,
        "Der Name des Blocks steht in \"{$m[1]}\"; dafuer muss es in showAppointmentPopup() genau eine"
        . " Deklaration geben, gefunden: {$treffer}");
    $text = ckAusdruck($body, $alle[0][0][1] + strlen($alle[0][0][0]));

    // Terminart, Uhrzeit, Titel -- und in dieser Reihenfolge. Die Uhrzeit muss
    // aus derselben Funktion kommen wie die sichtbare Zeile darunter: Zwei
    // Formatierungen desselben Wertes koennen auseinanderlaufen, und gehoert
    // haette man dann etwas anderes als gesehen.
    $marken = [
        'apt.type_name' => 'die Terminart',
        'formatTimeRange(apt.start_time' => 'die Uhrzeit aus derselben Funktion wie die sichtbare Zeile',
        'apt.title' => 'der Titel',
    ];
    $kurz = trim((string) preg_replace('/\s+/', ' ', $text));
    $vorher = -1;
    $vorname = '';
    foreach ($marken as $marke => $was) {
        $pos = strpos($text, $marke);
        assertTrue($pos !== false,
            "Im Namen des Blocks fehlt {$was} (\"{$marke}\"): {$kurz}");
        assertTrue($pos > $vorher,
            "Im Namen des Blocks steht \"{$marke}\" vor \"{$vorname}\" -- die Reihenfolge ist"
            . " Terminart, Uhrzeit, Titel: {$kurz}");
        $vorher = (int) $pos;
        $vorname = $marke;
    }

    // Fehlt die Terminart, entfaellt sie ersatzlos. Ohne diese Zusicherung
    // stuende bei einem Termin ohne Art ein "null, 20:00-22:00, Probe" oder ein
    // fuehrendes Komma im Vorlesetext.
    assertTrue((bool) preg_match('/\bfilter\(\s*Boolean\s*\)|\bfilter\([^)]*=>/', $text),
        'Die Teile des Namens muessen gefiltert werden -- ohne das liest ein Termin ohne Terminart'
        . " ein \"null\" oder ein fuehrendes Komma vor: {$kurz}");
});

test('Der Termin-Block bekommt keinen Tab-Stopp', function () use ($ckRoot) {
    // Eigener Test, nicht angehaengt: Das ist die Entscheidung des Tasks, und
    // sie darf nicht hinter einer fremden roten Zusicherung verschwinden.
    //
    // Eine Gruppe nennt ihren Namen beim Betreten, ohne eigenen Tastendruck.
    // Ein fokussierbarer Block kostete bei drei Terminen an einem Tag drei
    // zusaetzliche Tab-Stopps und brachte nichts dazu -- der Name steht im
    // aria-label, das Vorlesegeraet liest ihn ohnehin.
    $tag = ckBlockTag(ckPopupRumpf($ckRoot));

    assertTrue(!preg_match('/\btabindex\b/', $tag),
        'Der Termin-Block darf kein tabindex tragen -- eine Gruppe nennt ihren Namen ohne eigenen'
        . ' Tab-Stopp: ' . trim((string) preg_replace('/\s+/', ' ', $tag)));
});

test('Der Fokusrahmen am Termin-Block ist fort, samt seiner Zusicherung', function () use ($ckRoot) {
    // Die Regel entstand am Vormittag des 2026-09-25 in OI-94 als Vorleistung
    // fuer genau dieses Vorhaben -- und wurde eigens abgesichert, damit sie
    // niemand versehentlich entfernt. Der hier gewaehlte Weg kommt ohne sie aus:
    // Ohne tabindex kann :focus-visible am Block nie greifen. Sie stehen zu
    // lassen waere schlechter -- eine Regel ohne Wirkung und ein Test, der sie
    // bewacht.
    assertTrue(!str_contains(ckFile($ckRoot, 'public/css/components/calendar.css'),
        '.calendar-event-block:focus-visible'),
        'Die Regel .calendar-event-block:focus-visible ist wieder da -- der Block hat kein tabindex,'
        . ' sie kann nie greifen');

    // Und die Zusicherung, die sie bewachte. Ohne diese Haelfte holte der
    // naechste Lauf die Regel zurueck: Der alte Test verlangte sie, und wer ihn
    // rot sieht, setzt eher die Regel wieder ein als ihn zu loeschen.
    //
    // Gelesen wird ueber ckFile(), also OHNE Kommentare: Der Vermerk, der dort
    // an der Stelle des entfallenen Tests steht, darf diese Zusicherung nicht
    // erfuellen -- genau die Verwechslung von Kommentar und Code ist OI-107.
    assertTrue(!str_contains(ckFile($ckRoot, 'tests/suites/type_accent_frontend.php'), 'focus-visible'),
        'type_accent_frontend.php bewacht den Fokusrahmen weiter -- die Zusicherung aus OI-94 gehoert'
        . ' mit der Regel weg');
});
