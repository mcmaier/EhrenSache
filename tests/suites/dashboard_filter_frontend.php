<?php
declare(strict_types=1);

/**
 * Statische Gegenproben der Filterleisten (Spec 2026-09-17).
 *
 * Geprueft wird die Verdrahtung, die ein Laufzeittest nicht hergibt: dass
 * jedes Filterfeld im Markup eine Entsprechung im Modul hat, dass die alten
 * Bedienelemente aus den Kennzahlkarten verschwunden sind und dass der
 * Kalender seine Daten nicht mehr am Cache vorbei zieht.
 *
 * Die Pruefung auf show_inactive_members ist die wichtigste: Im Markup stand
 * ein auskommentierter Block mit derselben ID. Wird er entkommentiert statt
 * ersetzt, existiert die ID zweimal und getElementById() trifft das falsche
 * Element -- ein Fehler, den man im Browser nicht sieht, weil die Checkbox
 * trotzdem erscheint.
 */

$dfRoot = dirname(__DIR__, 2);
$dfHtml = (string) file_get_contents($dfRoot . '/public/index.html');
$dfJs   = (string) file_get_contents($dfRoot . '/public/js/modules/appointments.js');

/** Schneidet einen Bereich zwischen zwei Abschnitts-IDs aus. */
function dfBereich(string $html, string $vonId, string $bisId): string
{
    $start = strpos($html, 'id="' . $vonId . '"');
    assertTrue($start !== false, 'Bereich ' . $vonId . ' nicht gefunden');

    $ende = strpos($html, 'id="' . $bisId . '"', $start);
    assertTrue($ende !== false, 'Bereich ' . $bisId . ' nicht gefunden');

    return substr($html, $start, $ende - $start);
}

test('Terminverwaltung hat genau eine Filterleiste', function () use ($dfHtml) {
    $bereich = dfBereich($dfHtml, 'termine', 'anwesenheit');

    assertSame(1, substr_count($bereich, '<div class="filter-bar">'),
               'Die Terminverwaltung braucht genau eine filter-bar');
});

test('Die drei Filterelemente stehen im Markup', function () use ($dfHtml) {
    $bereich = dfBereich($dfHtml, 'termine', 'anwesenheit');

    foreach (['filterAppointmentType', 'filterAppointmentOrigin', 'resetAppointmentFilter'] as $id) {
        assertTrue(str_contains($bereich, 'id="' . $id . '"'), $id . ' fehlt im Markup');
    }
});

test('Der Herkunftsfilter kennt drei Zustaende', function () use ($dfHtml) {
    $bereich = dfBereich($dfHtml, 'termine', 'anwesenheit');

    foreach (['value="auto"', 'value="manual"'] as $wert) {
        assertTrue(str_contains($bereich, $wert), 'Option ' . $wert . ' fehlt');
    }
});

test('Der Herkunftsfilter ist Managern vorbehalten', function () use ($dfHtml) {
    $bereich = dfBereich($dfHtml, 'termine', 'anwesenheit');

    $pos = strpos($bereich, 'id="filterAppointmentOrigin"');
    assertTrue($pos !== false, 'Herkunftsfilter fehlt');

    // Die umschliessende form-group traegt die Rollenmarkierung. 400 Zeichen
    // zurueck decken das oeffnende div samt label ab.
    $davor = substr($bereich, max(0, $pos - 400), min($pos, 400));
    assertTrue(str_contains($davor, 'data-role="manager"'),
               'Der Herkunftsfilter braucht data-role="manager"');
});

test('Die alte Auto-Checkbox ist verschwunden', function () use ($dfHtml) {
    assertSame(0, substr_count($dfHtml, 'appointmentAutoFilter'),
               'appointmentAutoFilter darf nicht mehr vorkommen');
});

test('show_inactive_members existiert genau einmal', function () use ($dfHtml) {
    assertSame(1, substr_count($dfHtml, 'show_inactive_members'),
               'Doppelte ID: der auskommentierte Block muss ERSETZT, nicht ergaenzt werden');
});

test('Die Inaktiv-Checkbox steht in der Filterleiste', function () use ($dfHtml) {
    $bereich = dfBereich($dfHtml, 'mitglieder', 'benutzer');

    $leiste = strpos($bereich, '<div class="filter-bar">');
    $box    = strpos($bereich, 'show_inactive_members');

    assertTrue($leiste !== false, 'Filterleiste der Mitglieder fehlt');
    assertTrue($box !== false, 'Inaktiv-Checkbox fehlt');
    assertTrue($box > $leiste, 'Die Checkbox muss in der Filterleiste stehen, nicht in der Karte');
});

test('Die Inaktiv-Karte behaelt ihre Zahl', function () use ($dfHtml) {
    $bereich = dfBereich($dfHtml, 'mitglieder', 'benutzer');

    assertTrue(str_contains($bereich, 'id="statInactiveMembersCount"'),
               'Die Kennzahl der inaktiven Mitglieder darf nicht entfallen');
});

test('Der Kalender liest den Cache nicht mehr direkt', function () use ($dfJs) {
    // Alles ab renderCalendar() bis vor showAppointmentPopup() -- darin liegen
    // renderCalendar(), createCalendarDay() und die Rueckmeldungszeile.
    $start = strpos($dfJs, 'function renderCalendar(');
    assertTrue($start !== false, 'renderCalendar() nicht gefunden');

    $ende = strpos($dfJs, 'function showAppointmentPopup(', $start);
    assertTrue($ende !== false, 'showAppointmentPopup() nicht gefunden');

    $block = substr($dfJs, $start, $ende - $start);
    assertSame(0, substr_count($block, 'dataCache.appointments'),
               'renderCalendar/createCalendarDay duerfen nicht auf dataCache zugreifen');
});

test('Jede Filter-ID aus dem Markup kommt im Modul vor', function () use ($dfJs) {
    foreach (['filterAppointmentType', 'filterAppointmentOrigin', 'resetAppointmentFilter'] as $id) {
        assertTrue(str_contains($dfJs, $id), $id . ' wird im Modul nicht verwendet');
    }
});

/** Rumpf einer Funktion bis zur naechsten Funktionsdefinition auf oberster Ebene. */
function dfFunktion(string $js, string $name): string
{
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');

    if (preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }
    return substr($js, $start);
}

test('Mitglieder-Kennzahlen folgen dem Gruppenfilter', function () use ($dfRoot) {
    $js    = (string) file_get_contents($dfRoot . '/public/js/modules/members.js');
    $rumpf = dfFunktion($js, 'updateMemberStats');

    assertTrue(str_contains($rumpf, 'filterMemberGroup'),
               'updateMemberStats() muss den Gruppenfilter beruecksichtigen');
    // OI-71: Der Schalter "Inaktive anzeigen" darf die Karten NICHT beeinflussen,
    // sonst zeigt "Inaktive" genau dann 0, wenn der Haken aus ist.
    assertSame(0, substr_count($rumpf, 'show_inactive_members'),
               'Die Kennzahlen duerfen nicht vom Inaktiv-Schalter abhaengen (OI-71)');
});

test('Arbeitszeit-Kennzahlen zaehlen nur den gefilterten Stand', function () use ($dfRoot) {
    $js    = (string) file_get_contents($dfRoot . '/public/js/modules/worktime.js');
    $rumpf = dfFunktion($js, 'updateWorktimeStats');

    assertSame(0, preg_match('/\ball\s*\./', $rumpf),
               'updateWorktimeStats() darf nicht mehr auf den Gesamtbestand (all) zugreifen');
});

/**
 * Der Zweig fuer die leere Liste einer Render-Funktion: vom Test auf die leere
 * Liste bis zum ersten return. Genau dort sprang die Funktion bis OI-84 an der
 * Paginierung vorbei.
 */
function dfLeerzweig(string $body): string
{
    $start = strpos($body, 'length === 0');
    assertTrue($start !== false, 'Kein Zweig fuer die leere Liste gefunden');

    $ende = strpos($body, 'return;', $start);
    assertTrue($ende !== false, 'Der Zweig fuer die leere Liste kehrt nicht zurueck');

    return substr($body, $start, $ende - $start);
}

foreach ([
    ['records.js',    'renderRecords',    'recordsPagination',    'allFilteredRecords'],
    ['members.js',    'renderMembers',    'membersPagination',    'allFilteredMembers'],
    ['exceptions.js', 'renderExceptions', 'exceptionsPagination', 'allFilteredExceptions'],
] as [$datei, $funktion, $container, $liste]) {
    test("Leeres Filterergebnis raeumt die Paginierung ab: {$funktion}() (OI-84)",
        function () use ($dfRoot, $datei, $funktion, $container, $liste) {
            $js = (string) file_get_contents($dfRoot . '/public/js/modules/' . $datei);
            $leer = dfLeerzweig(dfFunktion($js, $funktion));

            // Sonst bleibt „Zeige 1–25 von 196“ unter der Leermeldung stehen.
            // stripos: der Aufruf render…Pagination() traegt die ID im Namen
            assertTrue(stripos($leer, $container) !== false,
                "{$funktion}() leert #{$container} bei leerer Liste nicht");

            // Sonst rendert ein Klick auf die stehengebliebenen Seitenknoepfe
            // die alte Liste unter dem neuen Filter
            assertTrue(preg_match('/' . $liste . '\s*=\s*\[\]/', $leer) === 1,
                "{$funktion}() setzt {$liste} bei leerer Liste nicht zurueck");
        });
}

test('Leere Mitgliederliste meldet nur ohne Verwalterrolle ein fehlendes Profil (OI-84)',
    function () use ($dfRoot) {
        $js = (string) file_get_contents($dfRoot . '/public/js/modules/members.js');
        $leer = dfLeerzweig(dfFunktion($js, 'renderMembers'));

        // Ein Admin mit Filter ohne Treffer hat kein Profil zu verknuepfen
        assertTrue(strpos($leer, 'isAdminOrManager') !== false,
            'Die Leermeldung der Mitgliederliste unterscheidet die Rolle nicht');
    });
