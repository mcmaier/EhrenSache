<?php
declare(strict_types=1);

/**
 * Statische Gegenproben der Status-Chips (Spec 2026-09-22, OI-86).
 *
 * Geprueft wird, was ein Blick in den Browser nicht verlaesslich zeigt: dass
 * die Komponente nur Tokens nutzt (sonst erreicht das Branding sie nicht),
 * dass entfallene Elemente wirklich weg sind und dass jede Ansicht ihren
 * Chip-Container hat und ihn im Modul fuellt.
 */

$fcRoot = dirname(__DIR__, 2);
$fcHtml = (string) file_get_contents($fcRoot . '/public/index.html');
$fcCss  = (string) @file_get_contents($fcRoot . '/public/css/components/filter-chips.css');

/** Bereich zwischen zwei Abschnitts-IDs (wie dfBereich in dashboard_filter_frontend). */
function fcBereich(string $html, string $vonId, string $bisId): string
{
    $start = strpos($html, 'id="' . $vonId . '"');
    assertTrue($start !== false, 'Bereich ' . $vonId . ' nicht gefunden');
    $ende = strpos($html, 'id="' . $bisId . '"', $start);
    assertTrue($ende !== false, 'Bereich ' . $bisId . ' nicht gefunden');
    return substr($html, $start, $ende - $start);
}

function fcModul(string $root, string $name): string
{
    return (string) file_get_contents($root . '/public/js/modules/' . $name . '.js');
}

test('Die Chip-Komponente existiert und wird geladen', function () use ($fcRoot, $fcCss) {
    assertTrue($fcCss !== '', 'css/components/filter-chips.css fehlt');
    $main = (string) file_get_contents($fcRoot . '/public/css/main.css');
    assertTrue(str_contains($main, 'components/filter-chips.css'), 'main.css importiert filter-chips.css nicht');

    // responsive.css muss NACH filter-chips.css importiert werden: Nur so
    // gewinnt dessen .stats-grid{grid-template-columns:1fr} auf schmalen
    // Bildschirmen gegen .stats-grid--chips aus dieser Komponente (gleiche
    // Spezifitaet, spaetere Regel gewinnt in der Kaskade).
    $posChips = strpos($main, 'components/filter-chips.css');
    $posResponsive = strpos($main, 'responsive.css');
    assertTrue($posChips !== false && $posResponsive !== false && $posChips < $posResponsive,
        'filter-chips.css muss vor responsive.css importiert werden, sonst gewinnt auf schmalen ' .
        'Bildschirmen nicht die einspaltige .stats-grid-Regel aus responsive.css');
});

test('Die Chip-Komponente nutzt nur Tokens, keine Hex-Farben', function () use ($fcCss) {
    // Kommentare ausblenden, dort duerfen Beispiele stehen
    $ohneKommentare = (string) preg_replace('#/\*.*?\*/#s', '', $fcCss);
    assertSame(0, preg_match('/#[0-9a-fA-F]{3,8}\b/', $ohneKommentare),
        'filter-chips.css enthaelt eine Hex-Farbe -- Farben nur ueber variables.css');
    // rgb()/rgba()/hsl()/hsla() sind ebenso hart codierte Farben wie Hex --
    // erlaubt sind nur Tokens und color-mix() darueber. "srgb" (color-mix(in
    // srgb, ...)) schlaegt nicht an, weil kein "(" auf "rgb" folgt.
    assertSame(0, preg_match('/\b(rgba?|hsla?)\(/i', $ohneKommentare),
        'filter-chips.css enthaelt eine rgb()/hsl()-Farbe -- nur Tokens/color-mix() erlaubt');
    // Lookbehind: --bg-white ist ein Token und soll nicht anschlagen.
    // Lookahead: white-space (CSS-Eigenschaft) ist keine Farbe und soll ebenfalls nicht anschlagen.
    // transparent, currentColor, inherit sind keine festen Farben und bleiben erlaubt.
    assertSame(0, preg_match('/(?<![-\w])(white|black|gray|grey|red|blue|green|orange|yellow|lightgray|darkgray)\b(?!-)/', $ohneKommentare),
        'filter-chips.css enthaelt eine benannte Farbe');
});

test('Alle Varianten der Spec sind definiert', function () use ($fcCss) {
    foreach (['--pending', '--ok', '--danger', '--info', '--static'] as $v) {
        assertTrue(str_contains($fcCss, '.filter-chip' . $v), 'Variante .filter-chip' . $v . ' fehlt');
    }
});

test('Der Zuruecksetzen-Knopf teilt den Stil nicht mehr mit btn-cancel', function () use ($fcRoot) {
    $buttons = (string) file_get_contents($fcRoot . '/public/css/components/buttons.css');
    assertSame(0, preg_match('/\.btn-cancel\s*,\s*\.btn-reset-filter/', $buttons),
        '.btn-reset-filter haengt noch am grauen Stil von .btn-cancel');
    $forms = (string) file_get_contents($fcRoot . '/public/css/components/forms.css');
    assertTrue(str_contains($forms, '.btn-reset-filter[hidden]'),
        'Ohne .btn-reset-filter[hidden] schluege ein spaeteres display das hidden');
});

test('Ein verborgener Chip-Container bleibt verborgen', function () use ($fcCss) {
    assertTrue(str_contains($fcCss, '.filter-chips[hidden]'),
        'Ohne .filter-chips[hidden] schluege display:flex das hidden');
});

test('Antraege: Chips statt Zaehlkarten und Status-Auswahl', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'antraege', 'statistik');
    assertTrue(str_contains($bereich, 'id="exceptionStatusChips"'), 'Chip-Container fehlt');
    assertTrue(str_contains($bereich, 'stats-grid stats-grid--chips'), 'Kopfzeile ohne stats-grid--chips');
    foreach (['statPendingExceptions', 'statApprovedExceptions', 'filterExceptionStatus'] as $id) {
        assertSame(0, substr_count($fcHtml, $id), $id . ' muss entfallen');
    }
    assertTrue(str_contains($bereich, 'id="exceptionYearFilter"'), 'Jahresfilter muss bleiben');

    $js = fcModul($fcRoot, 'exceptions');
    assertTrue(str_contains($js, 'CHIPS_EXCEPTIONS'), 'exceptions.js nutzt den Chipsatz nicht');
    assertSame(0, substr_count($js, 'filterExceptionStatus'), 'exceptions.js liest noch das alte Auswahlfeld');
    assertTrue(str_contains($js, 'setResetVisible'), 'Zuruecksetzen-Sichtbarkeit fehlt');
    assertTrue(str_contains($js, 'countChips(base, CHIPS_EXCEPTIONS)'), 'Antraege: Chips muessen auf der Basisliste zaehlen');
});

test('Arbeitszeit: Chips, Summenkarte bleibt', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'zeiterfassung', 'import-logs');
    assertTrue(str_contains($bereich, 'id="worktimeStatusChips"'), 'Chip-Container fehlt');
    assertTrue(str_contains($bereich, 'stats-grid stats-grid--chips-lead'), 'Kopfzeile ohne stats-grid--chips-lead');
    assertTrue(str_contains($bereich, 'id="statWorktimeTotal"'), '"Bestaetigte Stunden" muss bleiben');
    foreach (['statWorktimePending', 'statWorktimeOpen', 'filterWorktimeStatus'] as $id) {
        assertSame(0, substr_count($fcHtml, $id), $id . ' muss entfallen');
    }
    assertTrue(str_contains($bereich, 'id="resetWorktimeFilter"'), 'Zuruecksetzen braucht eine ID');
    assertSame(0, substr_count($bereich, 'onclick="resetWorktimeFilter'), 'Zuruecksetzen ist noch inline verdrahtet');

    $js = fcModul($fcRoot, 'worktime');
    assertTrue(str_contains($js, 'CHIPS_WORKTIME'), 'worktime.js nutzt den Chipsatz nicht');
    assertSame(0, substr_count($js, 'filterWorktimeStatus'), 'worktime.js liest noch das alte Auswahlfeld');
    assertTrue(str_contains($js, 'updateWorktimeStats(base)'), 'Die Summe darf dem Chip nicht folgen -- updateWorktimeStats bekommt die Basisliste');
    assertTrue(str_contains($js, 'countChips(base, CHIPS_WORKTIME)'), 'Arbeitszeit: Chips muessen auf der Basisliste zaehlen');
});

test('Mitglieder: Chips statt Karten und Inaktiv-Schalter', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'mitglieder', 'benutzer');
    assertTrue(str_contains($bereich, 'id="memberStatusChips"'), 'Chip-Container fehlt');
    assertTrue(str_contains($bereich, 'stats-grid stats-grid--chips'), 'Kopfzeile ohne stats-grid--chips');
    foreach (['statActiveMembersCount', 'statInactiveMembersCount', 'show_inactive_members'] as $id) {
        assertSame(0, substr_count($fcHtml, $id), $id . ' muss entfallen');
    }

    $js = fcModul($fcRoot, 'members');
    assertTrue(str_contains($js, 'CHIPS_MEMBERS'), 'members.js nutzt den Chipsatz nicht');
    // Vorgabe "Aktiv" entspricht dem frueheren "Inaktive anzeigen" = aus
    assertTrue(preg_match("/let\s+memberStatusChip\s*=\s*'active'/", $js) === 1,
        'Vorgabe des Mitglieder-Chips muss "active" sein');
});

test('Benutzer: Chips statt bunter Pillenknoepfe', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'benutzer', 'geraete');
    assertTrue(str_contains($bereich, 'id="userStatusChips"'), 'Chip-Container fehlt');
    foreach (['userStatusFilter', 'filter-btn', 'count-all', 'count-pending', 'setUserStatusFilter'] as $alt) {
        assertSame(0, substr_count($fcHtml, $alt), $alt . ' muss aus index.html entfallen');
    }
    assertSame(0, substr_count($bereich, 'onchange="applyUserFilters()"'),
        'userRoleFilter war doppelt verdrahtet (inline und addEventListener)');

    $css = (string) file_get_contents($fcRoot . '/public/css/sections/content.css');
    assertSame(0, preg_match('/\.filter-btn\b/', $css), '.filter-btn-Stile muessen entfallen');

    $js = fcModul($fcRoot, 'users');
    assertTrue(str_contains($js, 'CHIPS_USERS'), 'users.js nutzt den Chipsatz nicht');
    assertSame(0, substr_count($js, 'setUserStatusFilter'), 'setUserStatusFilter muss entfallen');
    assertTrue(str_contains($js, 'countChips(base, CHIPS_USERS)'), 'Benutzer: Chips muessen auf der Basisliste zaehlen');
});

test('Mitglieder: Chips zaehlen den Jahresbestand, nicht die Chip-Auswahl (OI-71)', function () use ($fcRoot) {
    $js = fcModul($fcRoot, 'members');
    $start = strpos($js, 'export async function showMemberSection(');
    assertTrue($start !== false, 'showMemberSection() fehlt');
    $ende = strpos($js, "\n}", $start);
    $rumpf = substr($js, $start, $ende - $start);
    // Reine Positionspruefung (countChips vor filterByChip) reicht nicht --
    // countChips(filterByChip(base, ...), ...) stuende auch davor. Deshalb
    // direkt den erwarteten Aufruf verlangen: Chips zaehlen auf der
    // Basisliste, nicht auf der Chip-Auswahl.
    assertTrue(str_contains($rumpf, 'countChips(base, CHIPS_MEMBERS)'),
        'Chips muessen auf der Basisliste zaehlen, nicht auf der Chip-Auswahl (OI-71)');
    $gruppe = strpos($rumpf, 'group_ids_array.includes(');
    assertTrue($gruppe !== false && $gruppe < strpos($rumpf, 'countChips('),
        'Der Gruppenfilter muss vor dem Zaehlen greifen');
});
