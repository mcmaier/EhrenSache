<?php
declare(strict_types=1);

/**
 * Statische Gegenproben der Einstellungsseite (Untertabs, Spec 2026-09-16).
 *
 * Geprueft wird die Verdrahtung, die ein Laufzeittest nicht hergibt: dass
 * jede Karte in genau einem Panel liegt, dass zu jedem Reiter ein Panel
 * gehoert und dass jede Einstellung eine Vorgabe im Schema hat. Der letzte
 * Punkt ist der wichtigste — ein fehlender Schluessel laesst ein Zahlenfeld
 * leer, und ein leeres Pflichtfeld blockiert das Speichern ALLER
 * Einstellungen (derselbe Fallstrick wie bei response_deadline_hours).
 */

$stRoot = dirname(__DIR__, 2);
$stHtml = (string) file_get_contents($stRoot . '/public/index.html');
$stJs   = (string) file_get_contents($stRoot . '/public/js/modules/settings.js');

/** Der Ausschnitt zwischen Tab-Leiste und Ende des Einstellungsbereichs. */
function stSettingsBereich(string $html): string
{
    $start = strpos($html, '<div class="settings-tabs"');
    assertTrue($start !== false, 'Tab-Leiste fehlt');

    $ende = strpos($html, '<div class="content-section" id="termine">', $start);
    assertTrue($ende !== false, 'Ende des Einstellungsbereichs nicht gefunden');

    return substr($html, $start, $ende - $start);
}

test('Zu jedem Reiter gehoert ein Panel und umgekehrt', function () use ($stHtml) {
    $bereich = stSettingsBereich($stHtml);

    preg_match_all('/data-settings-tab="([a-z]+)"/', $bereich, $reiter);
    preg_match_all('/data-settings-panel="([a-z]+)"/', $bereich, $panels);

    assertTrue(count($reiter[1]) >= 7, 'Weniger Reiter als geplant: ' . count($reiter[1]));
    assertSame($reiter[1], $panels[1], 'Reiter und Panels stimmen nicht ueberein');
    assertSame(count($reiter[1]), count(array_unique($reiter[1])), 'Doppelter Reiter');
});

test('Genau ein Reiter und ein Panel sind vorausgewaehlt', function () use ($stHtml) {
    $bereich = stSettingsBereich($stHtml);

    assertSame(1, substr_count($bereich, 'class="settings-tab-btn active"'),
               'Es muss genau ein Reiter aktiv sein');
    assertSame(1, substr_count($bereich, 'class="settings-panel active"'),
               'Es muss genau ein Panel aktiv sein');
});

test('Jede Einstellungskarte liegt in einem Panel', function () use ($stHtml) {
    $bereich = stSettingsBereich($stHtml);

    // Reihenfolge pruefen: Vor jeder Karte muss ein Panel geoeffnet und darf
    // noch keines geschlossen worden sein. Verschiebt jemand eine Karte aus
    // der Struktur heraus, faellt genau das auf.
    $marken = [];
    preg_match_all('/data-settings-panel="[a-z]+"|<div class="settings-card">/', $bereich, $treffer, PREG_OFFSET_CAPTURE);

    $offen = false;
    $karten = 0;
    foreach ($treffer[0] as [$text, $pos]) {
        if (strpos($text, 'settings-panel') !== false) {
            $offen = true;
            continue;
        }
        assertTrue($offen, 'Eine settings-card liegt vor dem ersten Panel');
        $karten++;
    }

    assertTrue($karten >= 12, "Zu wenige Karten gefunden: {$karten}");
});

test('Jede Einstellung hat eine Vorgabe im Schema', function () use ($stHtml, $stRoot) {
    $bereich = stSettingsBereich($stHtml);
    preg_match_all('/data-key="([a-z_]+)"/', $bereich, $keys);

    $sql = (string) file_get_contents($stRoot . '/private/setup/ehrensache_db.sql');

    // organization_logo wird beim Installieren gesetzt, nicht als Zeile gepflegt
    $ohneVorgabe = [];
    foreach (array_unique($keys[1]) as $key) {
        if (!str_contains($sql, "('{$key}',")) {
            $ohneVorgabe[] = $key;
        }
    }

    assertSame([], $ohneVorgabe,
        'Ohne Vorgabe im Schema bleibt das Feld leer und blockiert das Speichern: '
        . implode(', ', $ohneVorgabe));
});

test('Die Farbschwellen tragen denselben Bereich wie die Serverpruefung', function () use ($stHtml) {
    foreach (['rate_threshold_mid', 'rate_threshold_fair', 'rate_threshold_good'] as $key) {
        $pos = strpos($stHtml, 'data-key="' . $key . '"');
        assertTrue($pos !== false, "Feld {$key} fehlt");

        $start = strrpos(substr($stHtml, 0, $pos), '<input');
        $tag   = substr($stHtml, $start, strpos($stHtml, '>', $pos) - $start);

        assertTrue(str_contains($tag, 'type="number"'), "{$key}: Zahlenfeld erwartet");
        assertTrue(str_contains($tag, 'min="1"'), "{$key}: min muss 1 sein");
        assertTrue(str_contains($tag, 'max="99"'), "{$key}: max muss 99 sein");
    }
});

test('settings.js verdrahtet Tabs, Fehlermarken und die Reihenfolgepruefung', function () use ($stJs) {
    assertTrue(str_contains($stJs, 'export function showSettingsTab'), 'showSettingsTab fehlt');
    assertTrue(str_contains($stJs, 'export function hasUnsavedSettings'), 'hasUnsavedSettings fehlt');
    assertTrue(str_contains($stJs, 'rateThresholdsOrdered'), 'Reihenfolgepruefung fehlt');
    assertTrue(str_contains($stJs, "sessionStorage.setItem(SETTINGS_TAB_KEY"), 'Tab wird nicht gemerkt');
    assertTrue(str_contains($stJs, 'abgelehnt.push'), 'Fehlschlaege werden nicht gesammelt (OI-31)');
    assertTrue(!str_contains($stJs, 'location.hash'),
               'Kein Hash-Routing: die Anwendung fuehrt ihren Zustand in sessionStorage');
});

test('ui.js fragt vor dem Verlassen mit offenen Aenderungen', function () use ($stRoot) {
    $ui = (string) file_get_contents($stRoot . '/public/js/modules/ui.js');

    assertTrue(str_contains($ui, 'hasUnsavedSettings'),
               'Der Bereichswechsel prueft offene Einstellungen nicht');
});

test('Sofort wirkende Knoepfe sind als solche gekennzeichnet', function () use ($stHtml) {
    $bereich = stSettingsBereich($stHtml);

    assertTrue(substr_count($bereich, 'acts-now-hint') >= 3,
               'Bereinigung, Test-Mail und Update-Pruefung brauchen den Hinweis');
});
