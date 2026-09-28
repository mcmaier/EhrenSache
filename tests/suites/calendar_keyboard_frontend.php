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
