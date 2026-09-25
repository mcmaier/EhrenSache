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
 * Statische Gegenproben: Terminfarbe als Randakzent statt Badge (OI-94).
 *
 * Spec: docs/superpowers/specs/2026-09-24-terminfarbe-randakzent-design.md
 */

$taRoot = dirname(__DIR__, 2);

function taFile(string $root, string $rel): string
{
    $path = $root . '/' . $rel;
    assertTrue(is_file($path), "{$rel} fehlt");

    return (string) file_get_contents($path);
}

/** Rumpf einer JS-Funktion ab ihrer Signatur bis zur schliessenden Klammer in Spalte 0. */
function taFunctionBody(string $js, string $signature): string
{
    assertSame(1, substr_count($js, $signature), "{$signature} kommt nicht genau einmal vor");
    $start = strpos($js, $signature);
    $next = preg_match('/\n\}/', $js, $m, PREG_OFFSET_CAPTURE, $start) ? $m[0][1] : strlen($js);

    return substr($js, $start, $next - $start);
}

test('safeTypeColor prueft Farben mit einer auf ^# verankerten Hex-Whitelist gueltiger Laenge', function () use ($taRoot) {
    $js = taFile($taRoot, 'public/js/modules/utils.js');
    $body = taFunctionBody($js, 'export function safeTypeColor(');

    // Nicht auf den genauen Wortlaut pruefen (bricht bei jeder harmlosen
    // Umformatierung), sondern das Regex-Literal aus dem Rumpf herausloesen
    // und mit echten Werten befeuern -- das prueft die Absicht: eine auf ^#
    // verankerte, nur mit $ abgeschlossene Hex-Whitelist.
    assertTrue((bool) preg_match('/\/\^#[^\/]+\/i/', $body, $m),
        'Whitelist-Regex nicht gefunden (erwartet: auf ^# verankert, mit $ abgeschlossen, Hexstellen)');
    // JS-Regex-Literal (/.../i) als PCRE-Pattern weiterverwenden -- Zeichen-
    // klassen und Quantoren sind zwischen JS und PCRE hier identisch.
    $phpPattern = '~' . substr($m[0], 1, -2) . '~i';

    // Gueltige CSS-Hex-Notation: 3, 4, 6 oder 8 Stellen.
    foreach (['#abc', '#abcd', '#aabbcc', '#aabbccdd'] as $valid) {
        assertTrue((bool) preg_match($phpPattern, $valid), "{$valid} muss die Whitelist bestehen");
    }
    // Ungueltige CSS-Hex-Laengen (5, 7) und Nicht-Hex-Werte muessen durchfallen --
    // sonst haelt der Browser den Wert fuer sicher, verwirft ihn aber wortlos als
    // ungueltiges CSS und der Termin steht ganz ohne Streifen da (schlechter als
    // die graue Ersatzfarbe).
    foreach (['#abcde', '#abcdefa', '', 'abc', '#gggggg', 'red; background:url(x)'] as $invalid) {
        assertTrue(!preg_match($phpPattern, $invalid), "{$invalid} darf die Whitelist nicht bestehen");
    }

    assertTrue(str_contains($body, 'var(--type-color-none)') || str_contains($body, "'--type-color-none'")
        || str_contains($body, 'type-color-none'),
        'Ungueltige oder fehlende Farbe muss auf die gemeinsame Ersatzfarbe fallen');
});

test('Die Ersatzfarbe steht in variables.css', function () use ($taRoot) {
    $css = taFile($taRoot, 'public/css/variables.css');
    assertTrue((bool) preg_match('/--type-color-none:\s*[^;]+;/', $css),
        'Ersatzfarbe fehlt als Variable');
});

test('Keine hart codierte Ersatzfarbe mehr bei der Terminart', function () use ($taRoot) {
    // #667eea steht in beiden Dateien ausschliesslich als Ersatzfarbe der
    // Terminart (appointments.js:305 und :888, records.js:1832) -- dort darf
    // es dateiweit verschwinden.
    foreach (['public/js/modules/appointments.js', 'public/js/modules/records.js'] as $rel) {
        $js = taFile($taRoot, $rel);
        assertTrue(!str_contains($js, '#667eea'), "{$rel}: #667eea muss der Variablen weichen");
    }

    // #95a5a6 traegt dagegen zwei fremde Dinge: das Schildchen "automatisch
    // angelegt" (appointments.js:277) und die Erfassungsmethode "Auto"
    // (records.js:1054). Beide bleiben -- geprueft wird nur die Terminart.
    $records = taFile($taRoot, 'public/js/modules/records.js');
    $badge = taFunctionBody($records, 'function createAppointmentTypeBadge(');
    assertTrue(!str_contains($badge, '#95a5a6'),
        'Das Schildchen der Terminart muss die gemeinsame Ersatzfarbe nutzen');
});

test('Die Terminliste traegt den Streifen und den Namen in der Unterzeile', function () use ($taRoot) {
    $js = taFile($taRoot, 'public/js/modules/appointments.js');
    $body = taFunctionBody($js, 'async function renderAppointments(');

    assertTrue(str_contains($body, 'safeTypeColor('), 'Die gemeinsame Farbpruefung wird nicht benutzt');
    assertTrue(str_contains($body, '--type-color:'), 'Die Farbe muss als CSS-Variable gesetzt werden, nicht als fertiger Stil');
    assertTrue(str_contains($body, 'type-accent'), 'Die Zelle traegt die Klasse fuer den Streifen nicht');
    // Das Schildchen der Terminart muss weg -- die Klasse type-badge selbst
    // bleibt im Rumpf: sie ist projektweit das allgemeine Schildchen und
    // traegt hier noch "automatisch angelegt", ein anderes Ding. Geprueft
    // wird deshalb, dass kein Schildchen mehr die Terminart traegt und die
    // eigene Farbpruefung der gemeinsamen gewichen ist.
    assertTrue(!preg_match('/type-badge[^\n]*type_name/', $body),
        'Das Schildchen muss aus der Terminliste verschwinden');
    assertTrue(!str_contains($body, 'safeAptColor'),
        'Die eigene Farbpruefung muss safeTypeColor() weichen');
    assertTrue((bool) preg_match('/import \{[^}]*safeTypeColor[^}]*\} from .\.\/utils\.js./', $js),
        'safeTypeColor muss aus utils.js importiert sein');
});

test('Die Kopfzelle Terminart ist aus der Terminliste entfernt', function () use ($taRoot) {
    $html = taFile($taRoot, 'public/index.html');

    // Ausschnitt an festen Markern aufspannen statt an Zeichenabstaenden:
    // vom <table> der Terminliste bis zu ihrem </table>. So liegen Kopfzeile
    // und Ladezeile sicher drin, und kein <th>Terminart</th> einer anderen
    // Tabelle kann hineinrutschen.
    $pos = strpos($html, 'id="appointmentsTableBody"');
    assertTrue($pos !== false, 'Die Terminliste fehlt in index.html');
    $start = strrpos(substr($html, 0, $pos), '<table');
    assertTrue($start !== false, 'Anfang der Terminlisten-Tabelle nicht gefunden');
    $end = strpos($html, '</table>', $pos);
    assertTrue($end !== false, 'Ende der Terminlisten-Tabelle nicht gefunden');
    $block = substr($html, $start, $end - $start);

    assertTrue(!str_contains($block, '<th>Terminart</th>'),
        'Die Spalte entfaellt -- der Name steht jetzt in der Unterzeile');
    assertTrue(str_contains($block, 'colspan="4"'),
        'Die Ladezeile muss auf vier Spalten schrumpfen');

    // updateTableHeaders() in ui.js baut das thead nach jedem Login neu auf und
    // ueberschreibt den Kopf aus index.html. Ohne dieselbe Aenderung dort
    // taucht die Spalte im Betrieb wieder auf und die Koepfe stehen gegen die
    // Zellen aus appointments.js versetzt.
    $ui = taFile($taRoot, 'public/js/modules/ui.js');
    $uiStart = strpos($ui, "id: 'appointmentsTableBody'");
    assertTrue($uiStart !== false, 'appointmentsTableBody fehlt in updateTableHeaders()');
    $uiEntry = substr($ui, $uiStart, strpos($ui, ']', $uiStart) - $uiStart);
    assertTrue(!str_contains($uiEntry, 'Terminart'),
        'updateTableHeaders() setzt die Spalte Terminart wieder ins thead der Terminliste');
});

test('Der Streifen liegt im Stylesheet, nicht im Markup', function () use ($taRoot) {
    $css = taFile($taRoot, 'public/css/components/tables.css');
    assertTrue((bool) preg_match('/\.type-accent\s*\{[^}]*box-shadow:\s*inset/', $css),
        'Der Streifen gehoert als inset-Schatten auf die erste Zelle -- border-left auf tr vertraegt sich nicht mit Zebrastreifung');
    assertTrue((bool) preg_match('/\.type-accent\s*\{[^}]*var\(--type-color/', $css),
        'Die Farbe muss aus der Variablen kommen');
});
