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

/**
 * Statische Gegenproben an Dashboard und PWA fuer die offenen Punkte (FI-17).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);

test('Dashboard: Profil laedt die offenen Punkte', function () use ($root) {
    $html    = (string) file_get_contents($root . '/public/index.html');
    $profile = (string) file_get_contents($root . '/public/js/modules/profile.js');
    $module  = (string) @file_get_contents($root . '/public/js/modules/open_items.js');

    assertTrue(str_contains($html, 'id="openItemsCard"'), 'Karte #openItemsCard fehlt in index.html');
    $profil = strpos($html, 'id="profil"');
    $karte  = strpos($html, 'id="openItemsCard"');
    $konto  = strpos($html, 'Account-Informationen');
    assertTrue($profil !== false, 'id="profil" fehlt in index.html');
    assertTrue($karte !== false, 'id="openItemsCard" fehlt in index.html');
    assertTrue($konto !== false, 'Account-Informationen fehlt in index.html');
    assertTrue($profil < $karte && $karte < $konto, 'Die Karte steht nicht als erste Karte in #profil');

    assertTrue(str_contains($profile, 'loadOpenItems('), 'loadProfile() ruft loadOpenItems() nicht auf');
    assertTrue(str_contains($module, "apiCall('my_open_items'"), 'open_items.js fragt my_open_items nicht ab');
    assertTrue(str_contains($module, 'escapeHtml(item.title)'), 'open_items.js maskiert den Titel nicht');
    assertTrue(str_contains($module, 'escapeHtml(item.activity_name)'), 'open_items.js maskiert die Taetigkeit nicht');
    assertTrue(str_contains($module, 'Array.isArray(data.items)'), 'open_items.js prueft die Antwortform nicht ab');
    assertTrue(str_contains($module, 'Nichts offen'), 'Leerer Zustand fehlt');
});

test('Dashboard: Karte laedt nach, wenn der Rueckmeldungsdialog etwas geaendert hat', function () use ($root) {
    $responses = (string) file_get_contents($root . '/public/js/modules/responses.js');
    $module    = (string) @file_get_contents($root . '/public/js/modules/open_items.js');
    assertTrue(str_contains($responses, "new CustomEvent('responses:changed')"), 'responses.js meldet keine Aenderung');
    assertTrue(str_contains($module, "'responses:changed'"), 'open_items.js hoert nicht auf responses:changed');
});

test('PWA: Block steht oben im Tab Erfassen und ist anfangs ausgeblendet', function () use ($root) {
    $html    = (string) file_get_contents($root . '/public/checkin/index.html');
    $capture = strpos($html, 'data-tab="capture">');
    $block   = strpos($html, 'id="openItemsBlock"');
    $chooser = strpos($html, 'id="captureChooser"');
    assertTrue($capture !== false && $block !== false && $chooser !== false, '#openItemsBlock, Tab oder Absichtswahl fehlt');
    assertTrue($capture < $block && $block < $chooser, 'Block steht nicht vor der Absichtswahl');
    assertTrue(preg_match('/<details[^>]*id="openItemsBlock"[^>]*\bhidden\b/', $html) === 1,
        'Block ist nicht anfangs ausgeblendet');
});

test('PWA: Block laedt my_open_items, maskiert und blendet ohne Punkte aus', function () use ($root) {
    $js = (string) file_get_contents($root . '/public/checkin/js/app.js');
    $start = strpos($js, 'async function loadOpenItems(');
    assertTrue($start !== false, 'loadOpenItems() fehlt in app.js');
    $body = substr($js, $start, (int) strpos($js, "\n}", $start) - $start);
    assertTrue(str_contains($body, "apiCall('my_open_items'"), 'loadOpenItems() fragt my_open_items nicht ab');
    assertTrue(str_contains($body, 'block.hidden = true'), 'Ohne Punkte wird der Block nicht ausgeblendet');
    assertTrue(str_contains($body, 'Array.isArray('), 'Fehlerantworten werden nicht abgefangen');
    assertTrue(str_contains($body, 'openItemsSeq'), 'loadOpenItems() schuetzt sich nicht gegen verspaetete Antworten');

    $render = strpos($js, 'function openItemHtml(');
    assertTrue($render !== false, 'openItemHtml() fehlt');
    $renderBody = substr($js, $render, (int) strpos($js, "\n}", $render) - $render);
    assertTrue(str_contains($renderBody, 'escapeHtml(item.title)'), 'Titel wird nicht maskiert');
    assertTrue(str_contains($renderBody, 'escapeHtml(item.activity_name)'), 'Taetigkeit wird nicht maskiert');

    $when = strpos($js, 'function openItemsWhen(');
    assertTrue($when !== false, 'openItemsWhen() fehlt');
    $whenBody = substr($js, $when, (int) strpos($js, "\n}", $when) - $when);
    assertTrue(str_contains($whenBody, 'escapeHtml('), 'openItemsWhen() maskiert die Zeit nicht');

    assertTrue(str_contains($body, 'openItemsLastOpen'), 'loadOpenItems() merkt sich den Klappzustand nicht');
});

test('PWA: offene Punkte werden beim Zurueckkehren und beim Betreten von Erfassen nachgeladen', function () use ($root) {
    $js = (string) file_get_contents($root . '/public/checkin/js/app.js');
    $enter = strpos($js, 'function enterCaptureTab(');
    $enterBody = substr($js, $enter, (int) strpos($js, "\n}", $enter) - $enter);
    assertTrue(str_contains($enterBody, 'loadOpenItems('), 'enterCaptureTab() laedt die offenen Punkte nicht');
    assertTrue(preg_match('/visibilitychange[\s\S]{0,600}loadOpenItems\(/', $js) === 1,
        'visibilitychange laedt die offenen Punkte nicht nach');
    assertTrue(preg_match('/visibilitychange[\s\S]{0,600}data-tab="capture"/', $js) === 1,
        'visibilitychange laedt auch nach, wenn ein anderer Tab aktiv ist');
});

test('PWA: offene Punkte laden nach einem Antrag und nach dem Beenden der Arbeitszeit nach', function () use ($root) {
    $js = (string) file_get_contents($root . '/public/checkin/js/app.js');

    $submit = strpos($js, 'async function submitException(');
    assertTrue($submit !== false, 'submitException() fehlt');
    $submitBody = substr($js, $submit, (int) strpos($js, "\n}", $submit) - $submit);
    assertTrue(str_contains($submitBody, 'loadOpenItems('), 'submitException() laedt die offenen Punkte nicht nach');

    $stop = strpos($js, "action: 'stop', note");
    assertTrue($stop !== false, 'Die Funktion zum Beenden der Arbeitszeit fehlt');
    $stopFnStart = strrpos(substr($js, 0, $stop), 'function ');
    $stopBody = substr($js, $stopFnStart, (int) strpos($js, "\n}", $stop) - $stopFnStart);
    assertTrue(str_contains($stopBody, 'loadOpenItems('), 'Das Beenden der Arbeitszeit laedt die offenen Punkte nicht nach');
});

test('PWA: der Klappzustand des Blocks wird bei Abmeldung zurueckgesetzt', function () use ($root) {
    $js = (string) file_get_contents($root . '/public/checkin/js/app.js');
    $reset = strpos($js, 'function resetSessionState(');
    assertTrue($reset !== false, 'resetSessionState() fehlt');
    $resetBody = substr($js, $reset, (int) strpos($js, "\n}", $reset) - $reset);
    assertTrue(str_contains($resetBody, 'openItemsSeq++'), 'resetSessionState() verwirft eine unterwegs befindliche Antwort nicht');
    assertTrue(str_contains($resetBody, "openItemsBlock.hidden = true"), 'resetSessionState() blendet den Block nicht aus');
});

test('PWA: Verlauf laedt auch abgelehnte Antraege', function () use ($root) {
    $js = (string) file_get_contents($root . '/public/checkin/js/app.js');
    $start = strpos($js, 'async function loadHistory(');
    assertTrue($start !== false, 'loadHistory() fehlt');
    $body = substr($js, $start, (int) strpos($js, "\nfunction ", $start) - $start);
    assertTrue(str_contains($body, "status: 'rejected'"), 'loadHistory() laedt keine abgelehnten Antraege');
    assertTrue(str_contains($body, 'approved_at'), 'Das Fenster wird nicht ueber approved_at bestimmt');
    assertTrue(str_contains($body, '14'), 'Das Fenster von 14 Tagen fehlt');
});

test('PWA: Zaehler am Tab Termine zaehlt nur die naechsten 14 Tage', function () use ($root) {
    $js = (string) file_get_contents($root . '/public/checkin/js/app.js');
    $start = strpos($js, 'function updateResponsesBadge(');
    assertTrue($start !== false, 'updateResponsesBadge() fehlt');
    $body = substr($js, $start, (int) strpos($js, "\n}", $start) - $start);
    assertTrue(str_contains($body, 'OPEN_ITEMS_RESPONSE_DAYS'),
        'updateResponsesBadge() nutzt den 14-Tage-Horizont nicht');
});

test('PWA: Verlauf behaelt offene Eintraege auch hinter den letzten 20', function () use ($root) {
    $js = (string) file_get_contents($root . '/public/checkin/js/app.js');
    $start = strpos($js, 'async function loadHistory(');
    assertTrue($start !== false, 'loadHistory() fehlt');
    $body = substr($js, $start, (int) strpos($js, "\nfunction ", $start) - $start);
    assertTrue(str_contains($body, 'isOpenHistoryEntry'),
        'loadHistory() nutzt isOpenHistoryEntry() nicht');
    assertTrue(str_contains($body, 'combined.slice(20)') || str_contains($body, 'pinnedBeyond'),
        'loadHistory() rendert weiterhin nur die ersten 20 Eintraege ohne offene Ausnahme');
    assertTrue(!preg_match('/renderHistory\(\s*combined\.slice\(0,\s*20\)\s*\)/', $body),
        'loadHistory() rendert unveraendert nur combined.slice(0, 20)');
});
