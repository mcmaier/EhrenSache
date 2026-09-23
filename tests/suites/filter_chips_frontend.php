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

test('Der Zuruecksetzen-Knopf bleibt sichtbar und wird nur ausgegraut', function () use ($fcRoot, $fcHtml) {
    assertSame(0, preg_match('/class="btn-reset-filter"[^>]*\shidden/', $fcHtml),
        'Der Knopf wird nicht mehr ausgeblendet, sondern ausgegraut');

    assertSame(7, preg_match_all('/class="btn-reset-filter"[^>]*\sdisabled/', $fcHtml),
        'Alle sieben Zuruecksetzen-Knoepfe starten ausgegraut');

    $buttons = (string) file_get_contents($fcRoot . '/public/css/components/buttons.css');
    assertTrue(str_contains($buttons, '.btn-reset-filter:disabled'),
        'Der ausgegraute Zustand braucht eine eigene Regel');

    foreach (['appointments', 'exceptions', 'members', 'records', 'statistics', 'users', 'worktime'] as $modul) {
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
    // Geraete haben keinen weiteren Filter -- die Basis fuer die Zaehlung ist
    // der ganze Bestand, nicht eine bereits gefilterte Liste.
    assertTrue(str_contains($js, 'countChips(allDevices, CHIPS_DEVICES)'), 'Geraete: Chips muessen auf dem ganzen Bestand zaehlen');
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

test('Statistik: Zaehlwerte als Chips, Quoten als Karten', function () use ($fcRoot, $fcHtml) {
    $bereich = fcBereich($fcHtml, 'statistik', 'mitglieder');

    assertTrue(str_contains($bereich, 'id="statisticsChips"'), 'Chip-Container fehlt');
    foreach (['statTotalAppointments', 'statTotalPresent', 'statTotalExcused', 'statTotalUnexcused'] as $id) {
        assertSame(0, substr_count($fcHtml, $id), $id . ' muss entfallen');
    }
    foreach (['statOverallAverage', 'statPunctualityCard', 'statReliabilityCard'] as $id) {
        assertTrue(str_contains($bereich, 'id="' . $id . '"'), $id . ' muss als Karte bleiben');
    }
    assertTrue(str_contains($bereich, 'stats-grid--kpi'), 'Die Quotenkarten brauchen ein eigenes Raster, sonst dehnt sich eine einzelne Karte');

    $js = fcModul($fcRoot, 'statistics');
    assertTrue(str_contains($js, 'CHIPS_STATISTICS'), 'statistics.js nutzt den Chipsatz nicht');
    // An den Aufruf gebunden: sonst bliebe die Pruefung gruen, wenn die
    // Statistik-Chips klickbar wuerden und anderswo ein static: true steht.
    assertTrue(preg_match('/statisticsChips[\s\S]{0,200}static:\s*true/', $js) === 1,
        'Die Statistik-Chips muessen static sein');
});

test('Verwaltungstabellen haben Anzeige-Chipzeilen', function () use ($fcRoot, $fcHtml) {
    foreach (['groupChipsRow', 'typeChipsRow', 'activityChipsRow'] as $id) {
        assertTrue(str_contains($fcHtml, 'id="' . $id . '"'), $id . ' fehlt im Markup');
    }

    $mgmt = fcModul($fcRoot, 'management');
    assertTrue(str_contains($mgmt, 'groupChips('), 'management.js zeichnet die Gruppen-Chips nicht');
    assertTrue(str_contains($mgmt, 'CHIPS_APPOINTMENT_TYPES'), 'management.js zeichnet die Terminart-Chips nicht');
    assertSame(0, substr_count($mgmt, 'filterByChip'), 'Die Verwaltungstabellen filtern nicht');

    $wt = fcModul($fcRoot, 'worktime');
    assertTrue(str_contains($wt, 'CHIPS_ACTIVITY_TYPES'), 'worktime.js zeichnet die Taetigkeits-Chips nicht');
});
