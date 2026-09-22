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
