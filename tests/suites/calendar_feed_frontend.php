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
 * Kalender-Abo (FI-8): Markup, Modul und Einbindung -- statisch.
 * Das Verhalten im Browser prueft der Klickdurchgang (tests/browser).
 */
require_once __DIR__ . '/../lib/source.php';

const CFF_ROOT = __DIR__ . '/../..';

test('Profil: Karte mit data-feature und allen Zustaenden', function () {
    $html = sourceCode(CFF_ROOT . '/public/index.html');
    assertTrue((bool) preg_match('/id="calendarFeedCard"[^>]*data-feature="calendar_feed"|data-feature="calendar_feed"[^>]*id="calendarFeedCard"/', $html),
        'Karte calendarFeedCard mit data-feature="calendar_feed" fehlt');
    foreach (['calendarFeedNoMember', 'calendarFeedInactive', 'calendarFeedFresh', 'calendarFeedActive',
              'calendarFeedUrl', 'calendarFeedWebcal', 'calendarFeedStatus', 'calendarFeedHideDeclined'] as $id) {
        assertTrue(strpos($html, 'id="' . $id . '"') !== false, "Element #{$id} fehlt");
    }
    foreach (['calendar-feed-create', 'calendar-feed-renew', 'calendar-feed-end', 'calendar-feed-copy'] as $a) {
        assertTrue(strpos($html, 'data-action="' . $a . '"') !== false, "Knopf {$a} fehlt");
    }
    assertTrue(strpos($html, 'data-action-change="calendar-feed-hide-declined"') !== false, 'Schalter hide_declined fehlt');
    $start = (int) strpos($html, 'id="calendarFeedCard"');
    $ende  = strpos($html, 'id="einstellungen"', $start);
    assertTrue($ende !== false, 'Ende der Profilseite nicht gefunden');
    $karte = substr($html, $start, $ende - $start);
    assertTrue(strpos($karte, 'style="') === false, 'Neue Karte nutzt style-Attribute statt CSS-Klassen');
});

test('Modul: registriert alle Aktionen, schreibt kein HTML', function () {
    $js = sourceCode(CFF_ROOT . '/public/js/modules/calendar_feed.js');
    foreach (['calendar-feed-create', 'calendar-feed-renew', 'calendar-feed-end', 'calendar-feed-copy', 'calendar-feed-hide-declined'] as $a) {
        assertTrue(strpos($js, "'{$a}'") !== false, "Aktion {$a} nicht registriert");
    }
    assertTrue(strpos($js, 'innerHTML') === false, 'calendar_feed.js schreibt innerHTML');
    assertTrue(strpos($js, "isFeatureOn('calendar_feed')") !== false, 'Modul fragt den Schalter nicht ab');
});

test('Profil laedt die Karte', function () {
    $js = sourceCode(CFF_ROOT . '/public/js/modules/profile.js');
    assertTrue((bool) preg_match("#import\s*\{\s*loadCalendarFeedCard\s*\}\s*from\s*'\./calendar_feed\.js'#", $js), 'Import fehlt');
    assertTrue(strpos($js, 'loadCalendarFeedCard()') !== false, 'Aufruf fehlt');
});

test('CSS: eigene Datei per @import in main.css', function () {
    $css = sourceCode(CFF_ROOT . '/public/css/main.css');
    assertTrue(strpos($css, "@import url('components/calendar-feed.css');") !== false, '@import fehlt');
});
