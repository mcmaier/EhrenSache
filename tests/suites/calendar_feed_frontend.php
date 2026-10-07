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
              'calendarFeedUrl', 'calendarFeedWebcal', 'calendarFeedStatus', 'calendarFeedHideDeclined', 'calendarFeedHideDeclinedLabel', 'calendarFeedRenew'] as $id) {
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

test('Demo-Betrieb: Hinweis statt Knoepfen', function () {
    $html = sourceCode(CFF_ROOT . '/public/index.html');
    assertTrue((bool) preg_match('/<p class="[^"]*\bhidden\b[^"]*" id="calendarFeedDemo">\s*Im Demo-Betrieb nicht verfügbar\.\s*<\/p>/u', $html),
        'Hinweis #calendarFeedDemo (versteckt, „Im Demo-Betrieb nicht verfügbar.“) fehlt');
    $js = sourceCode(CFF_ROOT . '/public/js/modules/calendar_feed.js');
    assertTrue(strpos($js, "sessionStorage.getItem('theme-demo')") !== false, 'Modul liest das Demo-Merkmal aus theme.js nicht');
    assertTrue(strpos($js, "byId('calendarFeedDemo')") !== false, 'Modul schaltet den Demo-Hinweis nicht');
    assertTrue((bool) preg_match("/byId\('calendarFeedInactive'\)\.classList\.toggle\('hidden',\s*demo\s*\|\|/", $js),
        'Knopf „Abo-Link erzeugen“ bleibt im Demo-Betrieb sichtbar');
});

// ---- Rueckmelde-Link in der Check-in-App (Entscheidung 2026-10-07) ----------

/** Rumpf einer JS-Funktion bis zur naechsten Top-Level-Funktion. */
function cffFunctionBody(string $js, string $name): string
{
    $start = strpos($js, "function {$name}(");
    assertTrue($start !== false, "Funktion {$name} fehlt");
    $next = preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 10)
        ? $m[0][1] : strlen($js);

    return substr($js, $start, $next - $start);
}

test('PWA: #rueckmeldung=<id> wird gelesen und nur als Zahl angenommen', function () {
    $js = sourceCode(CFF_ROOT . '/public/checkin/js/app.js');
    $parse = cffFunctionBody($js, 'parseResponseDeepLink');
    assertTrue(strpos($parse, '/^#rueckmeldung=(\d+)$/') !== false, 'Muster ^#rueckmeldung=(\d+)$ fehlt');
    assertTrue((bool) preg_match('/let pendingResponseLink = parseResponseDeepLink\(window\.location\.hash\);/', $js),
        'Fragment wird beim Laden nicht gelesen');
});

test('PWA: Offene Punkte und Rueckmelde-Link oeffnen die Karte ueber dieselbe Funktion', function () {
    $js = sourceCode(CFF_ROOT . '/public/checkin/js/app.js');

    $card = cffFunctionBody($js, 'openResponseCard');
    assertTrue(strpos($card, 'responsesExpanded.add(') !== false, 'Karte wird nicht aufgeklappt');
    assertTrue(strpos($card, 'data-tab="responses"') !== false, 'Termine-Tab wird nicht geoeffnet');
    assertTrue(strpos($card, 'await loadResponses()') !== false, 'Karte wird nicht nach dem Laden gesucht');
    assertTrue(strpos($card, 'scrollIntoView(') !== false, 'Karte wird nicht in den Blick geholt');

    $click = cffFunctionBody($js, 'onOpenItemsClick');
    assertTrue(strpos($click, 'openResponseCard(') !== false, 'Offene Punkte nutzen openResponseCard() nicht');
    assertTrue(strpos($click, 'responsesExpanded.add(') === false, 'Offene Punkte haben eine eigene Kopie der Logik');

    $link = cffFunctionBody($js, 'openPendingResponseLink');
    assertTrue(strpos($link, 'openResponseCard(') !== false, 'Rueckmelde-Link oeffnet die Karte nicht');
    assertTrue(strpos($link, "pwaFeatureOn('appointments')") !== false, 'Rueckmelde-Link prueft die Terminplanung nicht');
    assertTrue(strpos($link, 'history.replaceState(null, \'\', window.location.pathname + window.location.search)') !== false,
        'Fragment wird nicht entfernt');
});

test('PWA: Rueckmelde-Link greift nach dem Start (beide Anmeldewege) und bei hashchange', function () {
    $js = sourceCode(CFF_ROOT . '/public/checkin/js/app.js');

    // startSession() ist der gemeinsame Abschluss von gespeichertem Token und
    // Anmeldeformular; der Aufruf muss nach initTabs() stehen, das die
    // Rueckmeldungen zuruecksetzt.
    $start = cffFunctionBody($js, 'startSession');
    assertTrue((bool) preg_match('/initTabs\(\);[\s\S]*openPendingResponseLink\(\);/', $start),
        'startSession() oeffnet den Rueckmelde-Link nicht nach initTabs()');

    assertTrue((bool) preg_match("/addEventListener\('hashchange'/", $js), 'Kein hashchange-Zuhoerer');
});
