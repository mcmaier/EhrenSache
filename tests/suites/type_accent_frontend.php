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

/**
 * Eine Tabelle aus index.html, aufgespannt an festen Markern statt an
 * Zeichenabstaenden: vom <table> um das genannte tbody bis zu dessen
 * </table>. So liegen Kopf- und Ladezeile sicher drin, und keine Zelle einer
 * anderen Tabelle kann hineinrutschen.
 */
function taTableBlock(string $html, string $bodyId): string
{
    $pos = strpos($html, 'id="' . $bodyId . '"');
    assertTrue($pos !== false, "{$bodyId} fehlt in index.html");
    $start = strrpos(substr($html, 0, $pos), '<table');
    assertTrue($start !== false, "Anfang der Tabelle um {$bodyId} nicht gefunden");
    $end = strpos($html, '</table>', $pos);
    assertTrue($end !== false, "Ende der Tabelle um {$bodyId} nicht gefunden");

    return substr($html, $start, $end - $start);
}

/** Kopfzellen einer Tabelle zaehlen -- auch solche mit Attributen (<th class="...">). */
function taCountTh(string $markup): int
{
    return preg_match_all('/<th[\s>]/', $markup);
}

/**
 * Die vier Kopfzeilen aus updateTableHeader() in Quelltext-Reihenfolge, je mit
 * erwarteter Spaltenzahl und Klartext. Die Reihenfolge wird ueber einen Marker
 * mitgeprueft, damit ein Umsortieren der Zweige nicht stillschweigend die
 * Zuordnung verschiebt.
 */
function taRecordHeads(string $records): array
{
    $header = taFunctionBody($records, 'function updateTableHeader(');
    assertSame(4, preg_match_all("/thead\.innerHTML = '([^']*)'/", $header, $m),
        'updateTableHeader() fuehrt vier Kopfzeilen: member, appointment, all/Verwalter, all/Mitglied');

    $erwartet = [
        ['<th>Termin</th><th>Ankunft</th>', 5,
            'Modus member: Termin, Ankunft, Status, Quelle, Aktionen'],
        ['<th>Mitglied</th><th>Ankunft</th>', 5,
            'Modus appointment: Mitglied, Ankunft, Status, Quelle, Aktionen (hatte nie eine Terminart)'],
        ['<th>Termin</th><th>Mitglied</th>', 6,
            'Modus all, Verwalter: Termin, Mitglied, Ankunft, Status, Quelle, Aktionen'],
        ['<th>Termin</th><th>Mitglied</th>', 5,
            'Modus all, einfaches Mitglied: dieselben Spalten ohne Aktionen'],
    ];

    $heads = [];
    foreach ($erwartet as $i => [$marker, $spalten, $klartext]) {
        assertTrue(str_starts_with($m[1][$i], $marker),
            "updateTableHeader(), {$klartext}: Kopfzeile {$i} passt nicht zum erwarteten Zweig");
        assertSame($spalten, taCountTh($m[1][$i]),
            "updateTableHeader(), {$klartext}: erwartet {$spalten} Spalten");
        $heads[] = [$m[1][$i], $klartext];
    }

    return $heads;
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

    // management.js ist die VIERTE Kopie, die die Spec nicht kannte (sie nennt
    // nur drei Stellen). Dateiweit darf #667eea hier NICHT verschwinden: Es ist
    // an zwei weiteren Stellen die Vorgabefarbe des Farbwaehlers fuer eine neue
    // Terminart -- eine Produktentscheidung, keine Ersatzfarbe, und ein
    // <input type="color"> nimmt ohnehin nur einen echten Hexwert, keine
    // CSS-Variable. Geprueft wird deshalb allein die Anzeige.
    $management = taFile($taRoot, 'public/js/modules/management.js');
    $uebersicht = taFunctionBody($management, 'export async function renderTypeGroupOverview(');
    assertTrue(!str_contains($uebersicht, '#667eea'),
        'renderTypeGroupOverview(): Die Farbkachel muss die gemeinsame Ersatzfarbe nutzen');


    // #95a5a6 traegt dagegen zwei fremde Dinge: das Schildchen "automatisch
    // angelegt" (appointments.js:277) und die Erfassungsmethode "Auto"
    // (records.js:1054). Beide bleiben -- geprueft wird nur die Terminart.
    $records = taFile($taRoot, 'public/js/modules/records.js');
    $badge = taFunctionBody($records, 'function createAppointmentTypeBadge(');
    assertTrue(!str_contains($badge, '#95a5a6'),
        'Das Schildchen der Terminart muss die gemeinsame Ersatzfarbe nutzen');
});

test('Die Farbkachel der Terminartenverwaltung nutzt die gemeinsame Pruefung', function () use ($taRoot) {
    // Vierte Kopie der Farbpruefung, die die Spec nicht kannte -- sie nennt nur
    // appointments.js (zweimal) und records.js. Sie war zudem loseer als
    // safeTypeColor(): Sie nahm auch #abcde an, was der Browser wortlos
    // verwirft; die Kachel blieb dann farblos statt grau.
    $management = taFile($taRoot, 'public/js/modules/management.js');
    assertTrue((bool) preg_match('/import \{[^}]*safeTypeColor[^}]*\} from .\.\/utils\.js./', $management),
        'management.js muss safeTypeColor aus utils.js importieren');

    $uebersicht = taFunctionBody($management, 'export async function renderTypeGroupOverview(');
    assertTrue(str_contains($uebersicht, 'safeTypeColor('),
        'Die Farbkachel der Terminartenverwaltung muss die gemeinsame Pruefung nutzen');
    // Sie bleibt eine Kachel -- umgestellt wird die Pruefung, nicht die Anzeige.
    assertTrue(str_contains($uebersicht, 'background: ${safeColor}'),
        'Die Farbkachel behaelt ihre Darstellung -- nur die Pruefung wandert');
});

test('Die Hex-Whitelist steht nur noch an den bekannten Stellen', function () use ($taRoot) {
    // Der Sinn der Zentralisierung (OI-94) ist, dass es keine eigene Kopie der
    // Pruefung mehr gibt, die man beim Nachschaerfen vergisst -- management.js
    // war genau so eine, und die Spec kannte sie nicht. Gegen eine fuenfte hilft
    // nur, das ganze Modulverzeichnis zu lesen.
    //
    // Geprueft wird die Liste GENAU, nicht bloss auf "nicht mehr geworden":
    // Bis Task 3 stand hier appointments.js, weil showAppointmentPopup() die
    // letzte Kopie trug -- eine Zusicherung auf leer waere damals rot und damit
    // wirkungslos gewesen. Task 4 hat sie aufgeloest, seither ist die Liste leer
    // und jede neue Kopie faellt beim naechsten Lauf auf.
    //
    // Die Check-in-PWA (public/checkin/) hat bewusst eigene Farben und bleibt
    // aussen vor, siehe "Nicht in diesem Vorhaben" in der Spec.
    $bekannt = [];

    $kopien = [];
    foreach (glob($taRoot . '/public/js/modules/*.js') as $pfad) {
        if (basename($pfad) === 'utils.js') {
            continue;
        }
        if (preg_match('/\/\^#\[0-9a-f\]/i', (string) file_get_contents($pfad))) {
            $kopien[] = 'public/js/modules/' . basename($pfad);
        }
    }
    sort($kopien);

    assertSame($bekannt, $kopien,
        "Eigene Hex-Whitelist statt safeTypeColor() aus utils.js.\n"
        . '  erwartet: ' . (implode(', ', $bekannt) ?: '(keine)') . "\n"
        . '  gefunden: ' . (implode(', ', $kopien) ?: '(keine)') . "\n"
        . '  Ist eine Datei dazugekommen: safeTypeColor() aus utils.js benutzen.');
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

    // Genau eine Deklaration im style-Attribut, also genau ein Doppelpunkt
    // (eingesetzte Werte vorher heraus). Beim Mutationstest zu Task 4 fiel auf,
    // dass ein angehaengtes "background: ${apt.color};" hier gruen blieb: Der
    // Datenbankwert stuende dann ein zweites Mal im Markup, diesmal ungeprueft,
    // und ohne CSP (OI-17) ist safeTypeColor() die einzige Schranke. Die
    // Anwesenheitsliste hatte diese Zaehlung schon, die Terminliste nicht.
    assertTrue((bool) preg_match('/innerHTML\s*=\s*`\s*<td[^>]*style="([^"]*)"/', $body, $styleAttr),
        'Die erste Zelle der Zeile traegt kein style-Attribut mit der Farbvariablen');
    $ohneWerte = preg_replace('/\$\{[^}]*\}/', 'X', $styleAttr[1]);
    assertSame(1, substr_count($ohneWerte, ':'),
        'In das style-Attribut der ersten Zelle gehoert genau eine Deklaration');

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

    $block = taTableBlock($html, 'appointmentsTableBody');

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
        // Signatur => [Klartext, Konstante mit der Spaltenzahl der Leermeldung]
        //
        // Die Zahl steht als Konstante im Code, nicht als Literal in der Zeile:
        // Ein Kommentar daneben veraltet, eine Konstante nicht. Welchen Wert sie
        // tragen muss, prueft der Test "Kopf und Zellen ... uebereinander" gegen
        // die tatsaechlichen Kopfzeilen.
        'export async function renderRecords(' => [
            'Erfassungsliste (alle Anwesenheiten)',
            'RECORDS_LIST_COLSPAN',
        ],
        'function renderMemberAttendanceList(' => [
            'Mitgliedsansicht (Termine eines Mitglieds)',
            'MEMBER_ATTENDANCE_COLSPAN',
        ],
    ];
}

test('Die Anwesenheitsliste nutzt den Randakzent, das Formularfeld das Schildchen', function () use ($taRoot) {
    $js = taFile($taRoot, 'public/js/modules/records.js');

    $accent = taFunctionBody($js, 'function appointmentTypeAccent(');
    assertTrue(str_contains($accent, 'safeTypeColor('), 'Die gemeinsame Farbpruefung wird nicht benutzt');

    // Die Deklaration fuer die erste Zelle wird HIER gebaut, nicht mehr im
    // Zeilen-Template -- also gehoert die Pruefung hierher. Sie im Rumpf der
    // Liste zu suchen war wirkungslos: Dort steht seit der Aufteilung gar
    // kein CSS mehr, ein zusaetzliches "background: ..." waere dort nie
    // aufgefallen.
    assertTrue((bool) preg_match('/style:\s*`([^`]*)`/', $accent, $styleTpl),
        'Die Hilfsfunktion liefert die Deklaration fuer die erste Zelle nicht als Vorlage');
    assertTrue((bool) preg_match('/^--type-color:\s*\$\{safeTypeColor\(/', trim($styleTpl[1])),
        'Die Variable --type-color muss ihren Wert unmittelbar aus safeTypeColor() beziehen');
    // Genau eine Deklaration, also genau ein Doppelpunkt. Ohne diese Zaehlung
    // bliebe ein angehaengtes "background: ${type.color};" gruen -- der
    // Datenbankwert stuende dann ein zweites Mal im style-Attribut, diesmal
    // ungeprueft, und ohne CSP (OI-17) ist die Pruefung die einzige Schranke.
    // Die eingesetzten Werte fallen vorher heraus: Ein Ternaer darin bringt
    // einen eigenen Doppelpunkt mit, der nichts mit CSS zu tun hat.
    $ohneWerte = preg_replace('/\$\{[^}]*\}/', 'X', $styleTpl[1]);
    assertSame(1, substr_count($ohneWerte, ':'),
        'In das style-Attribut gehoert genau eine Deklaration -- jede weitere waere eine zweite Stelle, an der ein Datenbankwert ins Markup laeuft');

    // Der Name ist der Textersatz des Streifens fuer Farbenblinde und ersetzt
    // die entfallene Spalte. Geprueft wird nicht, DASS escapeHtml irgendwo im
    // Rumpf steht, sondern dass der Name durch den Aufruf laeuft -- sonst
    // bliebe eine Fassung gruen, die an anderer Stelle maskiert und den Namen
    // selbst roh durchreicht.
    assertTrue((bool) preg_match('/name:\s*[^,]*escapeHtml\(type\.type_name\)/', $accent),
        'Der Name der Terminart kommt aus der Datenbank und muss durch escapeHtml() laufen (keine CSP, OI-17)');

    // Das Formularfeld beim Erfassen behaelt bewusst ein Schildchen.
    assertTrue(str_contains($js, 'createAppointmentTypeBadge('),
        'Die Badge-Funktion bleibt fuer das Formularfeld erhalten');
    // Das await davor ist wirkungslos -- die Funktion ist nicht async -- und
    // Altlast aus der Zeit vor OI-94. Es steht hier bewusst als OPTION: Diese
    // Zusicherung soll den Aufrufer am Randakzent hindern, nicht eine Warze
    // zementieren, die niemand verlangt hat.
    assertTrue((bool) preg_match('/typeBadge\.innerHTML = (?:await )?createAppointmentTypeBadge\(/', $js),
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

    foreach (taRecordLists() as $signature => [$klartext, $konstante]) {
        $body = taFunctionBody($js, $signature);

        assertTrue(str_contains($body, 'appointmentTypeAccent('),
            "{$klartext}: Die Liste holt den Randakzent nicht -- die blosse Existenz der Hilfsfunktion genuegt nicht");
        assertTrue(!str_contains($body, 'createAppointmentTypeBadge('),
            "{$klartext}: Die Liste haengt noch am Schildchen");

        // Der Streifen gehoert an die ERSTE Zelle der Zeile -- nur dort liegt er
        // am linken Rand. Geprueft am Anfang des Zeilen-Templates, nicht
        // irgendwo darin. Klasse und Stil werden getrennt zugesichert und
        // tolerieren weitere Attribute: Eine zweite, harmlose Klasse darf nicht
        // "Klasse fehlt" melden und den Naechsten an die falsche Stelle
        // schicken.
        assertTrue((bool) preg_match('/innerHTML\s*=\s*`\s*<td[^>]*type-accent/', $body),
            "{$klartext}: Die erste Zelle der Zeile traegt die Klasse type-accent nicht -- der Streifen liegt nur am linken Rand, wenn er an der ERSTEN Zelle haengt");
        assertTrue((bool) preg_match('/innerHTML\s*=\s*`\s*<td[^>]*style="\$\{\w+\.style\}"/', $body),
            "{$klartext}: Die erste Zelle der Zeile bekommt die Farbvariable nicht aus dem Randakzent");

        // Dass in diese Deklaration nichts ausser der Variablen geraet, prueft
        // der Test der Hilfsfunktion -- dort wird sie gebaut.

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

        assertTrue(str_contains($body, "colspan=\"\${{$konstante}}\""),
            "{$klartext}: Die Leermeldung muss ihre Spaltenzahl aus {$konstante} nehmen, nicht als Zahl in der Zeile tragen");
    }
});

test('Die Spalte Terminart ist aus der Anwesenheitsliste entfernt -- an allen drei Stellen', function () use ($taRoot) {
    // Den Kopf dieser Tabelle bauen DREI Stellen, und zwei davon ueberschreiben
    // die dritte. Wer nur eine aendert, bekommt die Spalte im Betrieb zurueck --
    // die Koepfe stehen dann gegen die Zellen versetzt, und zwar erst nach dem
    // naechsten Login oder Moduswechsel, also lange nach dem Umbau.
    $html = taFile($taRoot, 'public/index.html');
    $block = taTableBlock($html, 'recordsTableBody');

    // Gezaehlt, nicht nach einem Wort gesucht: Die Spalte kann auch unter
    // anderem Namen zurueckkommen ("Art", "Kategorie"). Die Zahl der Koepfe
    // ist das, was gegen die Zellen stehen muss.
    assertTrue(!preg_match('/<th[^>]*>(Terminart|Typ|Art)<\/th>/', $block),
        'index.html: Die Spalte entfaellt -- der Name steht jetzt in der Unterzeile');
    // Termin, Mitglied, Ankunftszeit, Status, Quelle, Aktionen
    assertSame(6, taCountTh($block),
        'index.html: Die Anwesenheitsliste hat sechs Spalten, nicht sieben');
    assertTrue(str_contains($block, 'colspan="6"'),
        'index.html: Die Ladezeile muss auf sechs Spalten schrumpfen');

    // updateTableHeaders() in ui.js baut das thead nach jedem Login neu auf.
    $ui = taFile($taRoot, 'public/js/modules/ui.js');
    $uiStart = strpos($ui, "id: 'recordsTableBody'");
    assertTrue($uiStart !== false, 'recordsTableBody fehlt in updateTableHeaders()');
    // Die schliessende Klammer gehoert in den Ausschnitt -- die Titelliste wird
    // gleich als vollstaendiges [...] gelesen.
    $uiEntry = substr($ui, $uiStart, strpos($ui, ']', $uiStart) - $uiStart + 1);
    assertTrue((bool) preg_match('/headers:\s*\[([^\]]*)\]/', $uiEntry, $uiHeaders),
        'Der Eintrag fuer recordsTableBody fuehrt keine Titelliste');
    assertTrue(!preg_match("/'(Terminart|Typ|Art)'/", $uiHeaders[1]),
        'updateTableHeaders() setzt die Spalte Terminart wieder ins thead der Anwesenheitsliste');
    // Fuenf Titel; "Aktionen" haengt updateTableHeaders() nur fuer Verwalter an.
    // Ohne diese Zahl bliebe ein umbenannter Rueckfall wie 'Art' unbemerkt.
    assertSame(5, preg_match_all("/'[^']*'/", $uiHeaders[1]),
        'updateTableHeaders() fuehrt fuenf feste Titel fuer die Anwesenheitsliste');

    // updateTableHeader() in records.js baut denselben Kopf bei JEDEM
    // Moduswechsel neu -- es ist die Stelle, die im Betrieb zuletzt schreibt.
    // Die Spalte heisst dort "Typ", nicht "Terminart"; eine Suche nach
    // "Terminart" ginge hier ins Leere, eine nach "Typ" an 'Art' vorbei.
    $records = taFile($taRoot, 'public/js/modules/records.js');
    foreach (taRecordHeads($records) as [$kopf, $klartext]) {
        assertTrue(!preg_match('/<th[^>]*>(Terminart|Typ|Art)<\/th>/', $kopf),
            "updateTableHeader(), {$klartext}: Die Terminart darf nicht als Spalte zurueckkommen -- sie stuende versetzt gegen die Zellen");
    }
});

test('Kopf und Zellen der Anwesenheitsliste stehen Spalte fuer Spalte uebereinander', function () use ($taRoot) {
    // Der colspan der Leermeldung allein sagt nichts darueber, ob die gezeichnete
    // Zeile zum Kopf passt: Ein zusaetzliches oder fehlendes <td> im
    // Zeilen-Template verschiebt jede Spalte dahinter, ohne dass eine der
    // bisherigen Zusicherungen anschlaegt. Deshalb hier Zellen GEGEN Koepfe,
    // beides aus dem Quelltext gezaehlt.
    $js = taFile($taRoot, 'public/js/modules/records.js');
    $heads = taRecordHeads($js);
    $spalten = fn (int $i) => taCountTh($heads[$i][0]);

    // Die drei colspan-Konstanten sind genau dann richtig, wenn sie die
    // Spaltenzahl ihres Modus tragen. Ohne diese Bindung koennte eine Kopfzeile
    // wachsen und die Leermeldung zurueckbleiben -- sie endete dann sichtbar
    // vor dem rechten Rand, und keine andere Zusicherung merkte es.
    $konstanten = [
        ['RECORDS_LIST_COLSPAN', 2, 'Modus all (breiteste Rolle: Verwalter)'],
        ['MEMBER_ATTENDANCE_COLSPAN', 0, 'Modus member'],
        ['ATTENDANCE_LIST_COLSPAN', 1, 'Modus appointment'],
    ];
    foreach ($konstanten as [$name, $kopf, $klartext]) {
        assertTrue((bool) preg_match('/const ' . $name . ' = (\d+);/', $js, $wert),
            "{$name} fehlt -- die Spaltenzahl gehoert als Konstante in den Code, nicht als Zahl in die Zeile");
        assertSame($spalten($kopf), (int) $wert[1],
            "{$name} passt nicht zu den Kopfzeilen fuer {$klartext}");
    }

    // Erfassungsliste: fuenf Zellen im Template, die Aktionszelle kommt aus
    // einer eigenen Variablen und nur fuer Verwalter dazu.
    $alle = taFunctionBody($js, 'export async function renderRecords(');
    assertTrue((bool) preg_match('/tr\.innerHTML = `([^`]*)`/', $alle, $zeile),
        'Erfassungsliste: Zeilen-Template nicht gefunden');
    assertTrue((bool) preg_match('/const actionsHtml = isAdminOrManager \? `([^`]*)`/', $alle, $aktionen),
        'Erfassungsliste: Aktionszelle nicht gefunden');
    assertSame(1, substr_count($aktionen[1], '<td'),
        'Erfassungsliste: Die Aktionsspalte ist genau eine Zelle');

    assertSame($spalten(2), substr_count($zeile[1], '<td') + 1,
        'Erfassungsliste, Verwalter: Zellen der Zeile (mit Aktionszelle) und Koepfe stehen versetzt');
    assertSame($spalten(3), substr_count($zeile[1], '<td'),
        'Erfassungsliste, einfaches Mitglied: Zellen der Zeile und Koepfe stehen versetzt');

    // Mitgliedsansicht: Hier liegt die Aktionszelle IM Template, die
    // Knopfvarianten liefern nur deren Inhalt -- sonst zaehlte die Zeile falsch.
    $mitglied = taFunctionBody($js, 'function renderMemberAttendanceList(');
    assertTrue((bool) preg_match('/tr\.innerHTML = `([^`]*)`/', $mitglied, $mZeile),
        'Mitgliedsansicht: Zeilen-Template nicht gefunden');
    assertTrue(preg_match_all('/actionsHtml = `([^`]*)`/', $mitglied, $mAkt) > 0,
        'Mitgliedsansicht: Knopfreihe nicht gefunden');
    foreach ($mAkt[1] as $fragment) {
        assertTrue(!str_contains($fragment, '<td'),
            'Mitgliedsansicht: Die Knopfreihe darf keine eigene Zelle mitbringen -- sonst zaehlt die Zeile eine Spalte zu viel');
    }
    assertSame($spalten(0), substr_count($mZeile[1], '<td'),
        'Mitgliedsansicht: Zellen der Zeile und Koepfe stehen versetzt');
});

// ============================================================
// Kalender-Popup (appointments.js) -- Task 4
// ============================================================

/**
 * Alle Regeln eines Stylesheets als [Selektor, Rumpf]. Verschachtelte Bloecke
 * (@media) fallen dabei heraus, ihre inneren Regeln bleiben erhalten -- fuer
 * die Frage "welcher Selektor traegt welche Deklaration" genuegt das.
 *
 * Kommentare fallen vorher heraus: Sie stehen ueber der Regel und landeten
 * sonst in deren Selektor. Ein Kommentar, der eine Klasse bloss ERWAEHNT,
 * haette die Regel dann so aussehen lassen, als betreffe sie diese Klasse.
 */
function taCssRules(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER);

    return array_map(fn ($r) => [trim($r[1]), $r[2]], $m);
}

test('Jeder Termin im Popup ist ein eigener Block mit Streifen', function () use ($taRoot) {
    $js = taFile($taRoot, 'public/js/modules/appointments.js');
    $body = taFunctionBody($js, 'function showAppointmentPopup(');

    assertTrue(str_contains($body, 'safeTypeColor('), 'Die gemeinsame Farbpruefung wird nicht benutzt');
    assertTrue(str_contains($body, '--type-color:'), 'Die Farbe muss als CSS-Variable gesetzt werden, nicht als fertiger Stil');
    assertTrue(str_contains($body, 'calendar-event-block'), 'Der Block je Termin fehlt');

    // Klasse und Farbvariable gehoeren an DASSELBE Element -- ein Streifen an
    // einem anderen Knoten als dem Block traegt die Farbe nicht. Tolerant
    // formuliert (weitere Attribute, weitere Klassen), damit eine harmlose
    // Ergaenzung nicht "Block fehlt" meldet und den Naechsten falsch leitet.
    assertTrue((bool) preg_match('/<div[^>]*class="[^"]*calendar-event-block[^"]*"[^>]*style="([^"]*)"/', $body, $styleAttr),
        'Der Block traegt die Farbvariable nicht im eigenen style-Attribut');

    // Genau eine Deklaration, also genau ein Doppelpunkt (eingesetzte Werte
    // vorher heraus, ein Ternaer darin braechte einen eigenen mit). Die
    // Zaehlung steht VOR der Herkunftspruefung, damit ein angehaengtes
    // "background: ${apt.color};" die Meldung bekommt, die es verdient: Der
    // Datenbankwert stuende dann ein zweites Mal im style-Attribut, diesmal
    // ungeprueft, und ohne CSP (OI-17) ist die Pruefung die einzige Schranke.
    $ohneWerte = preg_replace('/\$\{[^}]*\}/', 'X', $styleAttr[1]);
    assertSame(1, substr_count($ohneWerte, ':'),
        'In das style-Attribut des Blocks gehoert genau eine Deklaration');

    // Die Farbe kommt aus safeTypeColor() -- entweder direkt oder ueber eine
    // lokale Variable, die den Aufruf haelt. Wie die heisst, ist gleichgueltig:
    // Ein Muster, das auf "typeColor" besteht, meldete bei einer Umbenennung
    // "kommt nicht aus safeTypeColor()", obwohl sie genau das tut -- und
    // schickte den Naechsten an die falsche Stelle.
    assertTrue((bool) preg_match('/^--type-color:\s*\$\{([^}]+)\};?$/', trim($styleAttr[1]), $wert),
        'Der Wert von --type-color muss vollstaendig aus einem eingesetzten Ausdruck bestehen');
    $ausdruck = trim($wert[1]);
    assertTrue(str_starts_with($ausdruck, 'safeTypeColor(')
        || (bool) preg_match('/(?:const|let|var)\s+' . preg_quote($ausdruck, '/') . '\s*=\s*safeTypeColor\(/', $body),
        "Die Variable --type-color muss ihren Wert aus safeTypeColor() beziehen -- \"{$ausdruck}\" kommt nicht von dort");

    // Das Schildchen muss verschwinden -- im Rumpf, in der ganzen Datei und im
    // Stylesheet. Bliebe die Regel stehen, faende der Naechste eine Klasse ohne
    // Benutzer und baute sie gutglaeubig wieder ein.
    assertTrue(!str_contains($js, 'calendar-type-badge'),
        'Das Schildchen muss aus dem Popup verschwinden');
    assertTrue(!str_contains(taFile($taRoot, 'public/css/components/calendar.css'), 'calendar-type-badge'),
        'Die Regel des Schildchens hat keinen Benutzer mehr und gehoert entfernt');

    // Keine Farbe mehr im Markup: Das Popup trug seine Nebenzeilen mit
    // "color: #7f8c8d" inline. Farben stehen im Projekt nur in variables.css --
    // und ein Hexwert im Rumpf ist genau das Muster, aus dem die vierte Kopie
    // der Farbpruefung entstanden ist.
    assertTrue(!preg_match('/#[0-9a-f]{3,8}\b/i', $body),
        'Im Popup darf kein Hexwert mehr stehen -- Farben kommen aus variables.css');

    assertTrue((bool) preg_match('/import \{[^}]*safeTypeColor[^}]*\} from .\.\/utils\.js./', $js),
        'safeTypeColor muss aus utils.js importiert sein');
});

test('Der Name der Terminart steht im Popup in der Unterzeile beim Ort', function () use ($taRoot) {
    $js = taFile($taRoot, 'public/js/modules/appointments.js');
    $body = taFunctionBody($js, 'function showAppointmentPopup(');

    // Der Name ist der Textersatz des Streifens -- ohne diese Gegenprobe waere
    // auch eine Umsetzung gruen, die das Schildchen ersatzlos streicht.
    assertTrue(str_contains($body, 'type-accent-name'),
        'Der Name der Terminart fehlt in der Unterzeile');
    assertTrue((bool) preg_match('/<span class="type-accent-name">\$\{escapeHtml\(apt\.type_name\)\}<\/span>/', $body),
        'Der Span darf nur den Namen umschliessen -- der Trenner gehoert nach aussen');
    assertTrue(str_contains($body, "\u{b7}&nbsp;"),
        'Der Trenner muss per geschuetztem Leerzeichen am folgenden Text kleben');
    // Name und Ort stehen in DERSELBEN Zeile, unmittelbar hintereinander --
    // sonst stuende der Name zwar irgendwo, aber nicht in der Unterzeile.
    assertTrue((bool) preg_match('/\$\{typeName\}\$\{[A-Za-z]*[Ll]ocation[A-Za-z]*\}/', $body),
        'Der Name der Terminart muss dem Ort in der Unterzeile unmittelbar vorangehen');

    // Reihenfolge im Block: Zeit, Titel, Unterzeile, Rueckmeldung, Anwesenheit,
    // Knopfreihe. Die letzten drei sichert auch calendar_attendance_frontend --
    // hier steht der ganze Block, damit die Unterzeile nicht unbemerkt unter
    // die Knoepfe rutscht.
    $marken = [
        'calendar-event-time'   => 'Die Zeit fehlt im Block',
        'escapeHtml(apt.title)' => 'Der Titel fehlt im Block',
        'calendar-event-sub'    => 'Die Unterzeile fehlt im Block',
        'calendarResponseLineHtml(apt, fest)' => 'Die Rueckmeldezeile fehlt im Block',
        'attendanceLineHtml('   => 'Die Anwesenheitszeile fehlt im Block',
        'window.openAppointmentModal(${Number(apt.appointment_id)})' => 'Die Knopfreihe fehlt im Block',
    ];
    $vorher = -1;
    $vorname = '';
    foreach ($marken as $marke => $fehlt) {
        $pos = strpos($body, $marke);
        assertTrue($pos !== false, $fehlt);
        assertTrue($pos > $vorher,
            "Im Block steht \"{$marke}\" vor \"{$vorname}\" -- die Reihenfolge ist Zeit, Titel, Unterzeile, Rueckmeldung, Anwesenheit, Knopfreihe");
        $vorher = $pos;
        $vorname = $marke;
    }
});

test('Der Fokusrahmen am Popup-Block ist fuer OI-96 schon angelegt', function () use ($taRoot) {
    // Eigener Test, nicht angehaengt: Er darf nicht hinter einer fremden roten
    // Zusicherung verschwinden.
    //
    // Die Spec verlangt ihn ausdruecklich ("der Fokusrahmen wird im Stylesheet
    // schon angelegt"). Heute ist er wirkungslos -- es gibt noch kein
    // fokussierbares Element, tabindex und Tastenbedienung kommen mit OI-96 --
    // und genau deshalb braucht er eine Zusicherung: Beim naechsten Aufraeumen
    // saehe er wie eine Regel ohne Benutzer aus, und OI-96 begaenne mit einer
    // Ueberraschung statt mit der halben Vorleistung.
    $css = (string) preg_replace('~/\*.*?\*/~s', '',
        taFile($taRoot, 'public/css/components/calendar.css'));

    assertTrue((bool) preg_match('/\.calendar-event-block:focus-visible\s*\{([^}]*)\}/', $css, $fokus),
        'Der Fokusrahmen am Termin-Block fehlt -- er gehoert zur Vorleistung fuer OI-96');
    assertTrue((bool) preg_match('/outline:\s*[^;]*\bvar\(--/', $fokus[1]),
        'Der Fokusrahmen braucht eine sichtbare outline aus einer Farbvariablen');

    // Die Bedienung selbst bleibt OI-96 -- kein tabindex, kein keydown am
    // Block. Ohne diese Gegenprobe waere auch ein halbfertiger Vorgriff gruen,
    // der fokussierbar macht, aber weder Enter noch Escape beantwortet.
    $js = taFile($taRoot, 'public/js/modules/appointments.js');
    $body = taFunctionBody($js, 'function showAppointmentPopup(');
    assertTrue(!str_contains($body, 'tabindex') && !str_contains($body, "'keydown'"),
        'Tastaturbedienung des Popups ist OI-96, nicht dieser Vorgang -- hier wird nur die Struktur angelegt');
});

test('Die Rueckmeldezeile ist nur im festgehaltenen Popup als bedienbar erkennbar', function () use ($taRoot) {
    $css = taFile($taRoot, 'public/css/components/calendar.css');

    // Kommentare vorher heraus: Sie stehen ueber den Regeln, nennen Klassen im
    // Fliesstext und wuerden sonst als Selektor oder als Regelinhalt gelesen.
    $cssPur = (string) preg_replace('~/\*.*?\*/~s', '', $css);

    assertTrue((bool) preg_match('/\.calendar-event-block\s*\{[^}]*var\(--type-color/', $cssPur),
        'Der Block traegt den Streifen nicht');

    // Die Rueckmeldezeile im Popup braucht GENAU EINE Regel. Als zwei Bloecke
    // untereinander (Ruecknahme oben, Linie unten) haengt das Ergebnis an der
    // Quelltextreihenfolge: Vertauscht man sie, nimmt "border: none" die
    // Unterkante wieder weg, die Linie verschwindet vollstaendig -- und beide
    // Regeln fuer sich gelesen sind weiter in Ordnung, die Suite bliebe gruen.
    assertSame(1, preg_match_all('/\.calendar-event-responses \.response-summary-btn\s*\{([^}]*)\}/', $cssPur, $btnRegeln),
        'Die Rueckmeldezeile im Popup gehoert in genau eine Regel -- zwei uebereinander lassen sich vertauschen, und die Linie verschwindet wortlos');
    $btnRegel = $btnRegeln[1][0];

    assertTrue((bool) preg_match('/border-bottom:[^;]*dotted/', $btnRegel),
        'Die gepunktete Linie fehlt -- ohne Maus war die Zeile nicht als bedienbar erkennbar');
    // Sie bleibt ein leiser Hinweis: kein dritter Knopf neben "Bearbeiten" und
    // "Anwesenheit". Die Ruecknahme der Knopfoptik aus buttons.css gilt weiter.
    assertTrue((bool) preg_match('/background:\s*none/', $btnRegel),
        'Die Knopfoptik muss weiterhin zurueckgesetzt sein');

    // Und auch innerhalb der Regel zaehlt die Reihenfolge: Die Kurzform
    // "border: none" nimmt die Unterkante mit, sie muss VOR der Linie stehen.
    $reset = strpos($btnRegel, 'border:');
    $linie = strpos($btnRegel, 'border-bottom:');
    assertTrue($reset !== false,
        'Die Ruecknahme des Rahmens aus buttons.css fehlt -- die Zeile saehe wieder wie ein Knopf aus');
    assertTrue($reset < $linie,
        '"border: none" steht hinter "border-bottom" und nimmt die gepunktete Linie wieder weg');

    // Die Ueberfahr-Fassung bleibt schlichter Text. Geprueft ueber ALLE Regeln,
    // die ihren Selektor nennen: Die gemeinsame Regel weiter oben fasst beide
    // Fassungen zusammen, und eine Deklaration DORT traefe auch den Hover-Fall
    // -- genau der Unterschied, den FI-1 seinerzeit als Fehler gemeldet bekam.
    $textRegeln = 0;
    foreach (taCssRules($cssPur) as [$selektor, $regel]) {
        if (!str_contains($selektor, 'response-summary-text')) {
            continue;
        }
        $textRegeln++;
        assertTrue(!str_contains($regel, 'border-bottom') && !str_contains($regel, 'dotted'),
            "Die Regel \"{$selektor}\" gaebe auch der Hover-Fassung eine Linie -- dort bleibt es schlichter Text");
    }
    assertTrue($textRegeln > 0, 'Die nicht bedienbare Fassung im Hover-Popup muss unveraendert bleiben');

    // Und im Code darf der Knopf nur im festgehaltenen Popup entstehen -- sonst
    // traefe die neue Regel auch das Hover-Popup, gleich was das Stylesheet sagt.
    $js = taFile($taRoot, 'public/js/modules/appointments.js');
    $zeile = taFunctionBody($js, 'function calendarResponseLineHtml(');
    // Geprueft wird die Absicht, nicht der Wortlaut: Die Bedingung muss fest
    // UND-verknuepfen. "(r.expected || isAdminOrManager) && fest" ist dieselbe
    // Aussage wie "fest && (...)"; ein Muster, das auf der Operandenreihenfolge
    // besteht, meldete dort "haengt nicht mehr an fest" und waere schlicht
    // falsch. Den genauen Wortlaut sichert responses_frontend ohnehin.
    assertTrue((bool) preg_match('/\bclickable\s*=\s*([^;]+);/', $zeile, $bedingung),
        'calendarResponseLineHtml() bildet keine Bedingung clickable');
    assertTrue((bool) preg_match('/\bfest\b/', $bedingung[1]) && str_contains($bedingung[1], '&&'),
        'Die Klickbarkeit haengt nicht mehr (UND-verknuepft) an fest -- die gepunktete Linie'
        . ' erschiene dann auch beim Ueberfahren: ' . trim($bedingung[1]));
    $text = strpos($zeile, 'response-summary-text');
    $knopf = strpos($zeile, 'response-summary-btn');
    assertTrue($text !== false && $knopf !== false, 'Beide Fassungen der Rueckmeldezeile muessen vorkommen');
    assertTrue($text < $knopf,
        'Die Text-Fassung gehoert in den Zweig !clickable, der Knopf dahinter');
});
