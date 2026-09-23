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

test('renderFilterChips beachtet den chipweisen static-Zusatz (def.static)', function () use ($fcRoot) {
    // Arbeitszeit stellt einen anzeigenden Chip ("Stunden") neben klickbare
    // Status-Chips in derselben Zeile -- das geht nur, wenn die Elementwahl
    // sowohl options.static als auch das einzelne def.static beruecksichtigt.
    // Ein Ruecksprung auf das alte "isStatic" allein soll hier durchfallen.
    $fc = fcModul($fcRoot, 'filter_chips');
    assertSame(1, preg_match('/istAnzeige\s*=\s*isStatic\s*\|\|\s*def\.static\s*===\s*true/', $fc),
        'Die Elementwahl muss isStatic UND das einzelne def.static beruecksichtigen');
    assertSame(1, preg_match('/createElement\(\s*istAnzeige\s*\?\s*\'span\'\s*:\s*\'button\'\s*\)/', $fc),
        'Der Elementtyp muss von der gemischten Flag (istAnzeige) abhaengen');
    assertSame(1, preg_match('/if\s*\(\s*istAnzeige\s*\)\s*\{\s*\n\s*chip\.classList\.add\(\'filter-chip--static\'\)/', $fc),
        'Die --static-Klasse und der Klick-/Aria-Zweig muessen an derselben istAnzeige-Verzweigung haengen');
});

test('Der Zuruecksetzen-Knopf teilt den Stil nicht mehr mit btn-cancel', function () use ($fcRoot) {
    $buttons = (string) file_get_contents($fcRoot . '/public/css/components/buttons.css');
    assertSame(0, preg_match('/\.btn-cancel\s*,\s*\.btn-reset-filter/', $buttons),
        '.btn-reset-filter haengt noch am grauen Stil von .btn-cancel');
    $forms = (string) file_get_contents($fcRoot . '/public/css/components/forms.css');
    assertTrue(str_contains($forms, '.btn-reset-filter[hidden]'),
        'Ohne .btn-reset-filter[hidden] schluege ein spaeteres display das hidden');
});

test('Der Zuruecksetzen-Knopf bleibt sichtbar und wird nur ausgegraut', function () use ($fcRoot, $fcHtml) {
    assertSame(0, preg_match('/class="btn-reset-filter"[^>]*\shidden/', $fcHtml),
        'Der Knopf wird nicht mehr ausgeblendet, sondern ausgegraut');

    assertSame(8, preg_match_all('/class="btn-reset-filter"[^>]*\sdisabled/', $fcHtml),
        'Alle acht Zuruecksetzen-Knoepfe starten ausgegraut');

    $buttons = (string) file_get_contents($fcRoot . '/public/css/components/buttons.css');
    assertTrue(str_contains($buttons, '.btn-reset-filter:disabled'),
        'Der ausgegraute Zustand braucht eine eigene Regel');

    foreach (['appointments', 'devices', 'exceptions', 'members', 'records', 'statistics', 'users', 'worktime'] as $modul) {
        $js = fcModul($fcRoot, $modul);
        assertTrue(str_contains($js, 'setResetEnabled'), $modul . '.js nutzt setResetEnabled nicht');
        assertSame(0, substr_count($js, 'setResetVisible'), $modul . '.js kennt noch den alten Namen');
    }
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
    assertTrue(str_contains($js, 'setResetEnabled'), 'Zuruecksetzen-Ansteuerung fehlt');
    assertTrue(str_contains($js, 'countChips(base, CHIPS_EXCEPTIONS)'), 'Antraege: Chips muessen auf der Basisliste zaehlen');
});

test('Arbeitszeit: Stunden stehen als Chip in der Kopfzeile', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'zeiterfassung', 'import-logs');

    assertTrue(str_contains($bereich, 'id="worktimeStatusChips"'), 'Chip-Container fehlt');
    assertTrue(str_contains($bereich, 'stats-grid stats-grid--chips'), 'Kopfzeile ohne stats-grid--chips');
    foreach (['statWorktimeTotal', 'statWorktimePending', 'statWorktimeOpen',
              'filterWorktimeStatus', 'stats-grid--chips-lead'] as $alt) {
        assertSame(0, substr_count($fcHtml, $alt), $alt . ' muss entfallen');
    }

    assertTrue(str_contains($bereich, 'id="resetWorktimeFilter"'), 'Zuruecksetzen-Knopf fehlt');
    assertSame(0, substr_count($fcHtml, 'onclick="resetWorktimeFilter'),
        'resetWorktimeFilter darf nicht mehr inline verdrahtet sein');

    $css = (string) file_get_contents($fcRoot . '/public/css/components/filter-chips.css');
    assertSame(0, substr_count($css, 'stats-grid--chips-lead'), 'Die Sonderspalte muss aus dem CSS entfallen');

    $js = fcModul($fcRoot, 'worktime');
    assertTrue(str_contains($js, 'countChips(base, CHIPS_WORKTIME)'), 'Arbeitszeit: Chips muessen auf der Basisliste zaehlen');
    assertTrue(str_contains($js, 'formatMinutes'), 'Die Stundensumme muss weiter formatiert werden');

    // Die Beschriftung ist kurz ("Stunden"), damit die Kopfzeile einzeilig bleibt
    // (Sichtprüfung 23.09.2026) -- die Erklaerung muss dafuer im Tooltip stehen.
    // [^}]* statt .*? mit /s, damit ein spaeterer, unbeteiligter title: im selben
    // Objektliteral nicht versehentlich mit ueber das Chip-Ende hinweg matcht.
    assertTrue((bool) preg_match("/key:\s*'hours'[^}]*?title:/", $js),
        'Der Stundenchip braucht einen Tooltip, weil die Beschriftung verkuerzt ist');
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
    assertTrue(str_contains($js, 'Keine Benutzer für diese Auswahl'), 'Leere Benutzerliste braucht einen Hinweis');
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

test('Geraete: Chips statt Karten', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'geraete', 'verwaltung');
    assertTrue(str_contains($bereich, 'id="deviceStatusChips"'), 'Chip-Container fehlt');
    foreach (['statActiveDevices', 'statInactiveDevices'] as $id) {
        assertSame(0, substr_count($fcHtml, $id), $id . ' muss entfallen');
    }
    $js = fcModul($fcRoot, 'devices');
    assertTrue(str_contains($js, 'CHIPS_DEVICES'), 'devices.js nutzt den Chipsatz nicht');
    // Seit der Typfilter da ist (Sichtung 23.09.2026) zaehlen die Chips auf
    // der schon nach Typ gefilterten Liste -- facettiert wie ueberall sonst.
    assertTrue(str_contains($js, 'countChips(base, CHIPS_DEVICES)'), 'Geraete: Chips muessen auf der nach Typ gefilterten Liste zaehlen');
    assertTrue(str_contains($js, 'Keine Geräte für diese Auswahl'), 'Leere Geraeteliste braucht einen Hinweis');
    assertTrue(str_contains($js, 'Number(device.is_active) === 1'), 'Badge und Chip muessen is_active gleich auswerten');
});

test('Termine: statische Zeit-Chips statt Karten', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'termine', 'anwesenheit');
    assertTrue(str_contains($bereich, 'id="appointmentTimeChips"'), 'Chip-Container fehlt');
    foreach (['statPastAppointments', 'statUpcomingAppointments'] as $id) {
        assertSame(0, substr_count($fcHtml, $id), $id . ' muss entfallen');
    }
    $js = fcModul($fcRoot, 'appointments');
    assertTrue(str_contains($js, 'appointmentTimeChips('), 'appointments.js nutzt die Zeit-Chips nicht');
    // Reine Anzeige: der Kalender darf nicht nach Vergangen/Kommend filtern
    assertTrue(preg_match('/static:\s*true/', $js) === 1, 'Die Termin-Chips muessen static sein');
    assertSame(0, substr_count($js, 'filterByChip'), 'Termine filtern nicht nach Chip');
});

test('Anwesenheit: Chips in allen drei Modi', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'anwesenheit', 'antraege');
    assertTrue(str_contains($bereich, 'id="recordStatusChips"'), 'Chip-Container fehlt');
    foreach (['statTotalRecords', 'statMissingRecords'] as $id) {
        assertSame(0, substr_count($fcHtml, $id), $id . ' (samt Title) muss entfallen');
    }
    $js = fcModul($fcRoot, 'records');
    foreach (['CHIPS_RECORDS_ALL', 'CHIPS_RECORDS_LIST', 'resolveActiveChip'] as $n) {
        assertTrue(str_contains($js, $n), 'records.js nutzt ' . $n . ' nicht');
    }
    assertSame(0, substr_count($js, 'updateRecordStats'), 'updateRecordStats muss entfallen');
    assertTrue(str_contains($js, 'countChips(base, defs)'), 'Anwesenheit: Chips muessen auf der vollen Liste des Modus zaehlen');
});

test('Anwesenheitsliste: Zwischenspeicher haelt die volle Liste', function () use ($fcRoot) {
    // Sonst filtert ein Chip-Wechsel eine schon gefilterte Liste weiter und
    // "Alle" zeigt nicht mehr alle.
    $js = fcModul($fcRoot, 'records');
    $start = strpos($js, 'function renderAttendanceList(');
    assertTrue($start !== false, 'renderAttendanceList() fehlt');
    $rumpf = substr($js, $start, 400);
    assertTrue(preg_match('/_lastAttendanceData\s*=\s*attendanceData\s*;/', $rumpf) === 1,
        '_lastAttendanceData muss die ungefilterte Liste speichern');
});

test('Der Jahresfilter ist ueberall einzeilig und ohne Inline-Styles', function () use ($fcRoot, $fcHtml, $fcCss) {
    $jahr = (string) @file_get_contents($fcRoot . '/public/css/components/year-filter.css');
    assertTrue($jahr !== '', 'css/components/year-filter.css fehlt');

    $main = (string) file_get_contents($fcRoot . '/public/css/main.css');
    assertTrue(str_contains($main, 'components/year-filter.css'), 'main.css importiert year-filter.css nicht');

    $ohneKommentare = (string) preg_replace('#/\*.*?\*/#s', '', $jahr);
    assertSame(0, preg_match('/#[0-9a-fA-F]{3,8}\b/', $ohneKommentare),
        'year-filter.css enthaelt eine Hex-Farbe');

    // Bleibt die Chip-Leiste verborgen (Mitglieder als "user"), gibt sie ihre
    // Grid-Spalte frei -- ohne eine feste Spalte wuerde sich die Jahresauswahl
    // dann ueber das ganze Grid dehnen.
    assertSame(1, preg_match('/\.stats-grid--chips[^{]*\.year-filter[^{]*\{[^}]*grid-column\s*:\s*-2\s*\/\s*-1/s', $fcCss),
        'Die Jahresauswahl muss fest in der letzten Spalte stehen, sonst dehnt sie sich bei verborgenen Chips');

    // Gleiche Begruendung wie bei filter-chips.css weiter oben: responsive.css
    // stapelt die Kopfzeile auf schmalen Bildschirmen und muss spaeter kommen.
    $posJahr       = strpos($main, 'components/year-filter.css');
    $posResponsive = strpos($main, 'responsive.css');
    assertTrue($posJahr !== false && $posResponsive !== false && $posJahr < $posResponsive,
        'year-filter.css muss vor responsive.css importiert werden');

    // Die alte Bauform: Karte mit Ueberschrift und sechsfach wiederholtem Inline-Style.
    assertSame(0, substr_count($fcHtml, '<h3>Jahr filtern</h3>'),
        'Die Ueberschrift "Jahr filtern" gehoert nicht mehr ins Markup');

    foreach (['appointmentYearFilter', 'recordYearFilter', 'exceptionYearFilter',
              'memberYearFilter', 'worktimeYearFilter', 'statisticYearFilter'] as $id) {
        $pos = strpos($fcHtml, 'id="' . $id . '"');
        assertTrue($pos !== false, $id . ' fehlt im Markup');

        // 320 Zeichen davor decken den umschliessenden Container samt Label ab
        $davor = substr($fcHtml, max(0, $pos - 320), min($pos, 320));
        assertTrue(str_contains($davor, 'class="year-filter"'),
            $id . ' steht nicht in einer .year-filter');

        $zeile = substr($fcHtml, $pos, 200);
        assertSame(0, substr_count($zeile, 'style="'),
            $id . ' traegt noch einen Inline-Style');
    }
});

test('Die Filterleiste stellt die Beschriftung neben das Feld', function () use ($fcRoot) {
    $forms = (string) file_get_contents($fcRoot . '/public/css/components/forms.css');

    $start = strpos($forms, '.filter-bar .form-group {');
    assertTrue($start !== false, '.filter-bar .form-group fehlt');
    $block = substr($forms, $start, strpos($forms, '}', $start) - $start);

    assertTrue(str_contains($block, 'display: flex'), 'Die Filtergruppe muss eine Flex-Zeile sein');
    assertTrue(str_contains($block, 'align-items: center'), 'Label und Feld muessen auf einer Linie stehen');

    // 44px war die alte Hoehe von Feld und Knopf
    assertSame(0, preg_match('/\.btn-reset-filter\s*\{[^}]*height:\s*44px/', $forms),
        'Der Zuruecksetzen-Knopf ist noch 44px hoch');

    // updateUIForRole() darf data-role-Elemente nicht hart auf "block" schalten --
    // das bricht die Flex-Zeile der Filterleiste (form-group mit data-role="manager").
    $ui = fcModul($fcRoot, 'ui');
    assertSame(0, preg_match("/style\.display\s*=\s*is(Admin|AdminOrManager)\s*\?\s*'block'/", $ui),
        'updateUIForRole darf display nicht hart auf block setzen -- das bricht die Flex-Zeile der Filterleiste');

    // Gestapelt (Task 2) liegt flex in der Hauptachse -- ohne feste Hoehe
    // faellt das Feld auf Textzeilenhoehe zusammen (Regression bei 375px).
    $resp = (string) file_get_contents($fcRoot . '/public/css/responsive.css');
    assertTrue(preg_match('/\.filter-bar \.form-group select[^}]*flex:\s*none/s', $resp) === 1,
        'Auf schmalen Bildschirmen braucht das Feld eine feste Hoehe, sonst faellt es auf Textzeilenhoehe zusammen');
});

test('Statistik nutzt denselben Kopf wie die uebrigen Ansichten', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'statistik', 'mitglieder');

    assertTrue(str_contains($bereich, 'class="year-filter"'), 'Statistik ohne einzeilige Jahresauswahl');
    assertTrue(str_contains($bereich, '<div class="filter-bar">'), 'Statistik ohne gemeinsame Filterleiste');
    assertTrue(str_contains($bereich, 'id="resetStatisticsFilter"'), 'Zuruecksetzen fehlt');

    // Der Sonderbau entfaellt vollstaendig
    foreach (['stats-header', 'filter-card', 'filter-grid', 'year-select', 'year-card'] as $alt) {
        assertSame(0, substr_count($fcHtml, $alt), $alt . ' muss aus dem Markup entfallen');
    }

    $css = (string) file_get_contents($fcRoot . '/public/css/sections/content.css');
    foreach (['.stats-header', '.filter-card', '.filter-grid', '.year-card', '.year-select'] as $regel) {
        assertSame(0, substr_count($css, $regel), $regel . ' muss aus content.css entfallen');
    }

    $js = fcModul($fcRoot, 'statistics');
    assertSame(0, substr_count($js, 'filter-grid'), 'statistics.js kennt die Grid-Klassen noch');
    assertTrue(str_contains($js, 'setResetEnabled'), 'Zuruecksetzen wird nicht mehr ueber die alte Funktion angesteuert');
});

test('Statistik: auch die Quoten stehen als Chips', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'statistik', 'mitglieder');

    assertTrue(str_contains($bereich, 'id="statisticsChips"'), 'Chip-Container fehlt');
    foreach (['statTotalAppointments', 'statTotalPresent', 'statTotalExcused', 'statTotalUnexcused'] as $id) {
        assertSame(0, substr_count($fcHtml, $id), $id . ' muss entfallen');
    }
    // Die drei Kennzahlkarten entfallen samt Raster und Erklaertext.
    foreach (['statOverallAverage', 'statPunctualityCard', 'statReliabilityCard',
              'statPunctualityDetail', 'stats-grid--kpi'] as $alt) {
        assertSame(0, substr_count($fcHtml, $alt), $alt . ' muss entfallen');
    }

    $js = fcModul($fcRoot, 'statistics');
    assertTrue(str_contains($js, 'CHIPS_STATISTICS'), 'statistics.js nutzt den Chipsatz nicht');
    // Der Erklaertext der frueheren Karte steht jetzt im Tooltip des Chips.
    // "title:" allein schluege auch bei einem beliebigen anderen Objektfeld
    // an -- gepruefft wird deshalb die Verdrahtung: ein aus den Erklaertexten
    // angereicherter Chipsatz geht in renderFilterChips.
    assertSame(1, preg_match('/title:\s*titel\[def\.key\]/', $js),
        'statistics.js reicht die Erklaertexte nicht als Chip-Tooltip durch');
    assertSame(1, preg_match('/titel\.punctuality\s*=/', $js),
        'Der Erklaertext der Puenktlichkeit wird nicht gesetzt');
    assertSame(1, preg_match('/titel\.reliability\s*=/', $js),
        'Der Erklaertext der Zuverlaessigkeit wird nicht gesetzt');
    // Gegenstueck in der Komponente: ohne diese Zeile erreicht def.title den Chip nicht.
    $fc = fcModul($fcRoot, 'filter_chips');
    assertSame(1, preg_match('/chip\.title\s*=\s*def\.title/', $fc),
        'renderFilterChips setzt den Tooltip nicht auf dem Chip');

    // An den Aufruf gebunden: sonst bliebe die Pruefung gruen, wenn die
    // Statistik-Chips klickbar wuerden und anderswo ein static: true steht.
    assertTrue(preg_match('/statisticsChips[\s\S]{0,200}static:\s*true/', $js) === 1,
        'Die Statistik-Chips muessen static sein');

    $cards = (string) file_get_contents($fcRoot . '/public/css/components/cards.css');
    assertSame(0, substr_count($cards, 'stats-grid--kpi'), '.stats-grid--kpi muss aus cards.css entfallen');
});

test('Statistik: die Kopfzeile fuehrt sechs Chips, der Durchschnitt steht je Gruppe', function () use ($fcRoot, $fcHtml) {
    // Sieben Chips waren dem Nutzer zu viel (Sichtung 23.09.2026): Der
    // Durchschnitt wandert unter die Gruppenueberschrift, wo er ohnehin
    // aussagekraeftiger ist -- er gilt dann fuer genau diese Gruppe.
    $fc    = fcModul($fcRoot, 'filter_chips');
    $start = strpos($fc, 'export const CHIPS_STATISTICS');
    assertTrue($start !== false, 'CHIPS_STATISTICS fehlt');
    $satz = substr($fc, $start, strpos($fc, ']', $start) - $start);

    assertSame(0, substr_count($satz, "'average'"),
        'Der Durchschnitt gehoert nicht mehr in den Kopf-Chipsatz');
    assertSame(6, substr_count($satz, 'key:'), 'Die Kopfzeile fuehrt sechs Chips');

    // Der Rumpf: je Gruppe ein eigener Container, den statistics.js fuellt.
    $js = fcModul($fcRoot, 'statistics');
    assertTrue(str_contains($js, 'data-group-chips='),
        'Der Gruppenblock traegt keinen Container fuer die Durchschnitts-Chipzeile');
    assertSame(1, preg_match('/querySelector\(\s*`\[data-group-chips="\$\{[^}]+\}"\]`\s*\)/', $js),
        'statistics.js holt den Gruppen-Container nicht ueber data-group-chips');
    assertSame(1, preg_match('/renderFilterChips\([\s\S]{0,400}CHIPS_GROUP_AVERAGE/', $js),
        'Die Gruppen-Chipzeile wird nicht ueber renderFilterChips gezeichnet');
    assertSame(1, preg_match('/formatGerman\(\s*groupAverage\(/', $js),
        'Der Gruppendurchschnitt muss ueber formatGerman() laufen');

    // Der Durchschnitt je Gruppe folgt derselben Definition wie
    // attendanceBuildSummary(): anwesend geteilt durch moegliche Paare.
    assertSame(1, preg_match('/function groupAverage\(/', $js), 'groupAverage() fehlt');

    // Innerhalb des Gruppenblocks traegt der Container keine zweite Karte.
    $fcCss2 = (string) file_get_contents($fcRoot . '/public/css/components/filter-chips.css');
    assertTrue(str_contains($fcCss2, '.statistics-group > .filter-chips'),
        'Die Chipzeile im Gruppenblock braucht eine eigene, flache Darstellung');
});

test('Statistik: der Grund fuer "–" steht auch sichtbar, nicht nur im Tooltip', function () use ($fcRoot, $fcHtml) {
    // Ein title ist auf Tastatur und Touch nicht erreichbar (Sichtung
    // 23.09.2026). Zeigt eine Quote "–", muss der Grund als Text erscheinen.
    assertTrue(str_contains($fcHtml, 'id="statisticsChipsHint"'),
        'Der Hinweistext unter der Chipzeile fehlt im Markup');

    $fcCss2 = (string) file_get_contents($fcRoot . '/public/css/components/filter-chips.css');
    assertTrue(str_contains($fcCss2, '.filter-chips__hint'),
        '.filter-chips__hint fehlt in filter-chips.css');
    assertSame(1, preg_match('/\.filter-chip\[title\][^{]*\{[^}]*cursor:\s*help/s', $fcCss2),
        'Ein Chip mit Tooltip muss das auch am Zeiger zeigen');

    $js = fcModul($fcRoot, 'statistics');
    assertTrue(str_contains($js, 'statisticsChipsHint'),
        'statistics.js fuellt den Hinweistext nicht');
    // Nur im Fall "–": im Normalfall genuegt der Tooltip.
    assertSame(1, preg_match("/wert\s*===\s*'–'/u", $js),
        'Der Hinweis muss an den Fall "–" gebunden sein, nicht dauerhaft stehen');
});

test('Statistik: der Gruppenfilter raeumt leere Optgroups ohne TypeError auf', function () use ($fcRoot) {
    // Der Zweig laeuft nur fuer einfache Mitglieder und nur bei eingerichteten
    // Untergruppen -- ein Laufzeitfehler dort faellt lange nicht auf.
    $js = fcModul($fcRoot, 'statistics');

    // Positiv gepruefft: ein <optgroup> hat keine options-Eigenschaft, gezaehlt
    // werden muss ueber querySelectorAll. Eine reine Gegenprobe auf
    // "optgroup.options" schluege selbst dann gruen an, wenn die Zeile ganz
    // fehlte -- und der Kommentar darueber nennt den alten Ausdruck ohnehin.
    assertSame(1, preg_match('/optgroup\.querySelectorAll\(\s*[\'"]option[\'"]\s*\)\.length\s*===\s*0/', $js),
        'Leere Optgroups muessen ueber querySelectorAll(\'option\') erkannt werden');

    $ohneKommentare = (string) preg_replace('#//[^\n]*#', '', $js);
    assertSame(0, substr_count($ohneKommentare, 'optgroup.options'),
        'Ein <optgroup> hat keine options-Eigenschaft -- das warf fuer einfache Nutzer einen TypeError');
});

test('Verwaltungstabellen filtern nach Chip', function () use ($fcRoot, $fcHtml) {
    foreach (['groupChipsRow', 'typeChipsRow', 'activityChipsRow'] as $id) {
        assertTrue(str_contains($fcHtml, 'id="' . $id . '"'), $id . ' fehlt im Markup');
    }

    $mgmt = fcModul($fcRoot, 'management');
    assertTrue(str_contains($mgmt, 'filterByChip'), 'management.js filtert nicht nach Chip');
    assertSame(0, preg_match('/groupChipsRow[\s\S]{0,300}static:\s*true/', $mgmt),
        'Die Gruppen-Chips sind klickbar, nicht static');
    assertSame(0, preg_match('/typeChipsRow[\s\S]{0,300}static:\s*true/', $mgmt),
        'Die Terminarten-Chips sind klickbar, nicht static');

    $wt = fcModul($fcRoot, 'worktime');
    assertSame(0, preg_match('/activityChipsRow[\s\S]{0,300}static:\s*true/', $wt),
        'Die Taetigkeits-Chips sind klickbar, nicht static');
});

test('Geraete haben eine Filterleiste nach Typ', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'geraete', 'verwaltung');

    assertTrue(str_contains($bereich, 'id="filterDeviceType"'), 'Typfilter fehlt');
    assertTrue(str_contains($bereich, '<div class="filter-bar">'), 'Geraete ohne Filterleiste');
    assertTrue(str_contains($bereich, 'id="resetDeviceFilter"'), 'Zuruecksetzen fehlt');
    foreach (['totp_location', 'auth_device', 'kiosk'] as $typ) {
        assertTrue(str_contains($bereich, 'value="' . $typ . '"'), 'Typ ' . $typ . ' fehlt');
    }

    $js = fcModul($fcRoot, 'devices');
    assertTrue(str_contains($js, 'filterDeviceType'), 'devices.js liest den Typfilter nicht');
    assertTrue(str_contains($js, 'setResetEnabled'), 'devices.js schaltet den Knopf nicht');
});

test('Anwesenheit: Verwalterfilter werden ueber data-role ausgeblendet', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'anwesenheit', 'antraege');

    // Ohne data-role am Wrapper blieb die leere form-group mit min-width: 200px
    // stehen -- als Luecke in der Filterleiste (Sichtung 23.09.2026).
    foreach (['filterAppointment', 'filterMember'] as $feld) {
        $pos = strpos($bereich, 'id="' . $feld . '"');
        assertTrue($pos !== false, $feld . ' fehlt');

        $davor = substr($bereich, max(0, $pos - 260), min($pos, 260));
        assertTrue(str_contains($davor, 'data-role="manager"'),
            $feld . ' braucht data-role am umgebenden form-group');
    }

    $ui = fcModul($fcRoot, 'ui');
    assertSame(0, substr_count($ui, "label[for=\"filterMember\"]"),
        'ui.js darf Feld und Beschriftung nicht mehr einzeln ausblenden');

    $rec = fcModul($fcRoot, 'records');
    assertSame(0, substr_count($rec, 'appointmentFilterGroup'),
        'records.js darf den Terminfilter nicht mehr selbst ausblenden');

    $jahr = (string) file_get_contents($fcRoot . '/public/css/components/year-filter.css');
    assertTrue(str_contains($jahr, 'font-weight: 500'),
        'Die Jahresbeschriftung muss so fett sein wie die uebrigen Filterbeschriftungen');
});
