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

    // Der Streifen gehoert an die ERSTE Zelle der Zeile -- nur dort liegt er am
    // linken Rand. Geprueft am Anfang des Zeilen-Templates, nicht irgendwo darin.
    assertTrue((bool) preg_match('/innerHTML\s*=\s*`\s*<td[^>]*type-accent/', $body),
        'Die Klasse type-accent muss auf der ersten Zelle der Zeile sitzen');

    // Der Name der Terminart steht in der Unterzeile, unmittelbar vor dem Datum
    // -- ohne diese Gegenprobe waere auch eine Umsetzung ganz ohne Namen gruen.
    assertTrue(str_contains($body, 'type-accent-name'),
        'Der Name der Terminart fehlt in der Unterzeile');
    assertTrue((bool) preg_match('/\$\{typeName\}\$\{[A-Za-z]*[Dd]ate[A-Za-z]*\}/', $body),
        'Der Name der Terminart muss der Datumsausgabe unmittelbar vorangehen');

    // Der Span umschliesst NUR den Namen. Der Trenner ist Satzzeichen und steht
    // mit geschuetztem Leerzeichen davor am Datum, damit er beim Umbruch
    // schmaler Spalten mit dem Datum wandert statt am Namen haengen zu bleiben.
    // Tasks 3 und 4 erben die Klasse ("Terminart · Ort", Name am Zeilenende) --
    // mit dem Trenner im Span waere sie dort unbrauchbar.
    assertTrue((bool) preg_match('/<span class="type-accent-name">\$\{escapeHtml\(apt\.type_name\)\}<\/span>/', $body),
        'Der Span darf nur den Namen umschliessen -- der Trenner gehoert nach aussen');
    assertTrue(str_contains($body, '·&nbsp;'),
        'Der Trenner muss per geschuetztem Leerzeichen am Datum kleben');

    // Das Schildchen der Terminart muss weg. Gezaehlt statt gesucht: type-badge
    // ist projektweit das allgemeine Schildchen, und genau EINES bleibt hier
    // zulaessig -- "automatisch angelegt" am Titel, ein anderes Ding als die
    // Terminart. Jedes zweite ist der Rueckfall, gleich wie geschrieben
    // (mehrzeilig, ueber eine Zwischenvariable oder ueber ${typeName}).
    assertSame(1, substr_count($body, 'type-badge'),
        'Im Rumpf darf genau ein Schildchen stehen: "automatisch angelegt". '
        . 'Ein zweites bedeutet, dass die Terminart wieder als Schildchen gerendert wird');

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
        'Der Streifen gehoert als inset-Schatten auf die erste Zelle -- ein Rahmen an der Zeile kostet Layoutbreite und schoebe die Spalte gegen den Tabellenkopf');
    assertTrue((bool) preg_match('/\.type-accent\s*\{[^}]*var\(--type-color/', $css),
        'Die Farbe muss aus der Variablen kommen');

    // Die Regel fuer den Namen darf nicht wirkungslos werden: Das umschliessende
    // <small> vererbt --text-light. Steht dieselbe Farbe in der Klasse und kein
    // eigenes Gewicht, ist der Name von der Datumsangabe daneben nicht zu
    // unterscheiden -- und er ist der Textersatz des Streifens.
    assertTrue((bool) preg_match('/\.type-accent-name\s*\{([^}]*)\}/', $css, $nameRule),
        'Die Klasse fuer den Namen fehlt im Stylesheet');
    assertTrue(!str_contains($nameRule[1], '--text-light'),
        'var(--text-light) erbt der Name ohnehin -- die Regel waere wirkungslos');
    assertTrue((bool) preg_match('/font-weight:/', $nameRule[1]),
        'Ohne eigenes Gewicht hebt sich der Name nicht vom Datum ab');
});

// ============================================================
// Anwesenheitsliste (records.js) -- Task 3
// ============================================================

/** Die beiden Listen in records.js, die den Randakzent tragen. */
function taRecordLists(): array
{
    return [
        // Signatur => [Klartext, colspan der Leermeldung]
        //
        // Der colspan ist die Spaltenzahl der BREITESTEN Rolle: In der
        // Erfassungsliste sieht ein einfaches Mitglied keine Aktionsspalte, die
        // Leermeldung wird aber nur einmal geschrieben. Zu klein waere sie
        // sichtbar falsch (die Meldung endet vor dem rechten Rand), zu gross
        // dehnt kein Browser die Tabelle.
        'export async function renderRecords(' => [
            'Erfassungsliste (alle Anwesenheiten)',
            // Termin, Mitglied, Ankunft, Status, Quelle, Aktionen
            6,
        ],
        'function renderMemberAttendanceList(' => [
            'Mitgliedsansicht (Termine eines Mitglieds)',
            // Termin, Ankunft, Status, Quelle, Aktionen
            5,
        ],
    ];
}

test('Die Anwesenheitsliste nutzt den Randakzent, das Formularfeld das Schildchen', function () use ($taRoot) {
    $js = taFile($taRoot, 'public/js/modules/records.js');

    $accent = taFunctionBody($js, 'function appointmentTypeAccent(');
    assertTrue(str_contains($accent, 'safeTypeColor('), 'Die gemeinsame Farbpruefung wird nicht benutzt');
    assertTrue(str_contains($accent, '--type-color'), 'Die Farbe muss als CSS-Variable herauskommen');

    // Ohne diese Gegenprobe waere auch eine Fassung gruen, die den Namen gar
    // nicht mitliefert -- der Name ist der Textersatz des Streifens fuer
    // Farbenblinde und ersetzt die entfallene Spalte.
    assertTrue(str_contains($accent, 'escapeHtml('),
        'Der Name der Terminart kommt aus der Datenbank und muss maskiert werden (keine CSP, OI-17)');

    // Das Formularfeld beim Erfassen behaelt bewusst ein Schildchen.
    assertTrue(str_contains($js, 'createAppointmentTypeBadge('),
        'Die Badge-Funktion bleibt fuer das Formularfeld erhalten');
    assertTrue((bool) preg_match('/typeBadge\.innerHTML = await createAppointmentTypeBadge\(/', $js),
        'Der Aufrufer im Formular darf nicht auf den Randakzent umgestellt werden');
});

test('Die Badge-Funktion nutzt ebenfalls die gemeinsame Farbpruefung', function () use ($taRoot) {
    $js = taFile($taRoot, 'public/js/modules/records.js');
    $body = taFunctionBody($js, 'function createAppointmentTypeBadge(');
    assertTrue(str_contains($body, 'safeTypeColor('),
        'Auch das verbliebene Schildchen darf die Pruefung nicht selbst mitbringen');

    // Frueher hiess eine LOKALE Variable im Rumpf ebenfalls safeTypeColor und
    // ueberschattete damit die importierte Funktion. Bliebe sie stehen, waere
    // der Aufruf oben entweder ein Syntaxfehler oder -- schlimmer -- stiller
    // Unsinn: Die Zeile daneben sieht dann gepruefte Farben, wo keine sind.
    assertTrue(!preg_match('/(?:const|let|var)\s+safeTypeColor\b/', $js),
        'records.js darf keine eigene Variable safeTypeColor mehr fuehren -- sie verdeckt die importierte Funktion');
});

test('Beide Listen in records.js rendern die Terminart als Randakzent', function () use ($taRoot) {
    $js = taFile($taRoot, 'public/js/modules/records.js');

    assertTrue((bool) preg_match('/import \{[^}]*safeTypeColor[^}]*\} from .\.\/utils\.js./', $js),
        'safeTypeColor muss aus utils.js importiert sein');

    foreach (taRecordLists() as $signature => [$klartext, $colspan]) {
        $body = taFunctionBody($js, $signature);

        assertTrue(str_contains($body, 'appointmentTypeAccent('),
            "{$klartext}: Die Liste holt den Randakzent nicht -- die blosse Existenz der Hilfsfunktion genuegt nicht");
        assertTrue(!str_contains($body, 'createAppointmentTypeBadge('),
            "{$klartext}: Die Liste haengt noch am Schildchen");

        // Der Streifen gehoert an die ERSTE Zelle der Zeile -- nur dort liegt er
        // am linken Rand. Geprueft am Anfang des Zeilen-Templates, nicht
        // irgendwo darin.
        assertTrue((bool) preg_match('/innerHTML\s*=\s*`\s*<td class="type-accent" style="\$\{\w+\.style\}"/', $body),
            "{$klartext}: Die erste Zelle traegt Klasse und Farbvariable nicht -- der Streifen liegt nur am linken Rand, wenn er an der ERSTEN Zelle haengt");

        // Die Farbe geht als Variable ins Markup, das Aussehen steht im
        // Stylesheet. Ein fertiger Stil im Markup hiesse, den Datenbankwert an
        // mehreren Stellen einzusetzen -- jede davon eine eigene Luecke.
        assertTrue(!preg_match('/background:\s*\$\{[A-Za-z]*[Tt]ype[A-Za-z]*\}/', $body),
            "{$klartext}: Die Terminfarbe darf nicht als fertiger Stil ins Markup");

        // Das Schildchen der Terminart muss weg. Gezaehlt statt gesucht: In
        // diesen beiden Ruempfen ist type-badge projektweit nur die Terminart
        // gewesen -- jedes verbliebene ist der Rueckfall, gleich wie es
        // geschrieben steht.
        assertSame(0, substr_count($body, 'type-badge'),
            "{$klartext}: Kein Schildchen der Terminart mehr in der Zeile");

        // Der Name der Terminart steht in der Unterzeile, unmittelbar vor dem
        // Datum -- ohne diese Gegenprobe waere auch eine Umsetzung ganz ohne
        // Namen gruen.
        assertTrue((bool) preg_match('/\$\{typeName\}\$\{[A-Za-z]*[Dd]ate[A-Za-z]*\}/', $body),
            "{$klartext}: Der Name der Terminart muss der Datumsausgabe unmittelbar vorangehen");

        // Der Span umschliesst NUR den Namen. Der Trenner ist Satzzeichen und
        // klebt per geschuetztem Leerzeichen am Datum, damit er beim Umbruch
        // schmaler Spalten mit dem Datum wandert statt am Namen haengen zu
        // bleiben.
        assertTrue((bool) preg_match('/<span class="type-accent-name">\$\{[^}]+\}<\/span> ·&nbsp;/', $body),
            "{$klartext}: Der Span darf nur den Namen umschliessen, der Trenner gehoert mit geschuetztem Leerzeichen nach aussen");

        assertTrue(str_contains($body, "colspan=\"{$colspan}\""),
            "{$klartext}: Die Leermeldung muss auf {$colspan} Spalten schrumpfen -- mit der Terminart entfaellt eine");
    }
});

test('Die Spalte Terminart ist aus der Anwesenheitsliste entfernt -- an allen drei Stellen', function () use ($taRoot) {
    // Den Kopf dieser Tabelle bauen DREI Stellen, und zwei davon ueberschreiben
    // die dritte. Wer nur eine aendert, bekommt die Spalte im Betrieb zurueck --
    // die Koepfe stehen dann gegen die Zellen versetzt, und zwar erst nach dem
    // naechsten Login oder Moduswechsel, also lange nach dem Umbau.
    $html = taFile($taRoot, 'public/index.html');

    // Ausschnitt an festen Markern aufspannen statt an Zeichenabstaenden.
    $pos = strpos($html, 'id="recordsTableBody"');
    assertTrue($pos !== false, 'Die Anwesenheitsliste fehlt in index.html');
    $start = strrpos(substr($html, 0, $pos), '<table');
    assertTrue($start !== false, 'Anfang der Anwesenheitstabelle nicht gefunden');
    $end = strpos($html, '</table>', $pos);
    assertTrue($end !== false, 'Ende der Anwesenheitstabelle nicht gefunden');
    $block = substr($html, $start, $end - $start);

    assertTrue(!str_contains($block, '<th>Terminart</th>'),
        'index.html: Die Spalte entfaellt -- der Name steht jetzt in der Unterzeile');
    // Termin, Mitglied, Ankunftszeit, Status, Quelle, Aktionen
    assertSame(6, substr_count($block, '<th>'),
        'index.html: Die Anwesenheitsliste hat sechs Spalten, nicht sieben');
    assertTrue(str_contains($block, 'colspan="6"'),
        'index.html: Die Ladezeile muss auf sechs Spalten schrumpfen');

    // updateTableHeaders() in ui.js baut das thead nach jedem Login neu auf.
    $ui = taFile($taRoot, 'public/js/modules/ui.js');
    $uiStart = strpos($ui, "id: 'recordsTableBody'");
    assertTrue($uiStart !== false, 'recordsTableBody fehlt in updateTableHeaders()');
    $uiEntry = substr($ui, $uiStart, strpos($ui, ']', $uiStart) - $uiStart);
    assertTrue(!str_contains($uiEntry, 'Terminart'),
        'updateTableHeaders() setzt die Spalte Terminart wieder ins thead der Anwesenheitsliste');

    // updateTableHeader() in records.js baut denselben Kopf bei JEDEM
    // Moduswechsel neu -- es ist die Stelle, die im Betrieb zuletzt schreibt.
    // Die Spalte heisst dort "Typ", nicht "Terminart"; eine Suche nach
    // "Terminart" ginge hier ins Leere.
    $records = taFile($taRoot, 'public/js/modules/records.js');
    $header = taFunctionBody($records, 'function updateTableHeader(');
    assertTrue(!str_contains($header, '<th>Typ</th>'),
        'updateTableHeader() setzt die Spalte Typ wieder ins thead -- versetzt gegen die Zellen aus renderRecords()');
});
