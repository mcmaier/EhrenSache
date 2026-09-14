<?php
declare(strict_types=1);

/**
 * Statische Gegenproben der Oberflaeche fuer Puenktlichkeit und Zuverlaessigkeit.
 *
 * Dashboard ist Vanilla-JS ohne eigenen Testlauf; pruefbar bleibt die
 * Verdrahtung zwischen HTML, Modul und Serverantwort.
 */

require_once __DIR__ . '/../../private/helpers/punctuality.php';

$puRoot = dirname(__DIR__, 2);

test('Die Einstellungskarte fuehrt alle drei Schluessel', function () use ($puRoot) {
    $html = (string) file_get_contents($puRoot . '/public/index.html');

    foreach (['punctuality_enabled', 'reliability_enabled', 'punctuality_grace_minutes'] as $key) {
        assertTrue(strpos($html, "data-key=\"{$key}\"") !== false, "Feld fuer {$key} fehlt");
    }
});

test('Die Karenz erlaubt negative Werte und denselben Bereich wie der Server', function () use ($puRoot) {
    $html  = (string) file_get_contents($puRoot . '/public/index.html');
    $start = strpos($html, 'data-key="punctuality_grace_minutes"');
    assertTrue($start !== false, 'Karenzfeld fehlt');

    $tagStart = strrpos(substr($html, 0, $start), '<input');
    $tagEnde  = strpos($html, '>', $start);
    $tag      = substr($html, $tagStart, $tagEnde - $tagStart);

    assertTrue(strpos($tag, 'min="-60"') !== false, 'min muss -60 sein wie in punctualityGraceFromSetting()');
    assertTrue(strpos($tag, 'max="60"') !== false,  'max muss 60 sein wie in punctualityGraceFromSetting()');
});

test('Jeder Schluessel der Karte ist fuer Neuinstallationen angelegt', function () use ($puRoot) {
    // Ein leeres Zahlenfeld blockiert in settings.js das Speichern ALLER
    // Einstellungen. Fehlt ein Schluessel im Schema, ist die Einstellungsseite
    // einer frischen Installation unbenutzbar.
    $sql = (string) file_get_contents($puRoot . '/private/setup/ehrensache_db.sql');

    foreach (['punctuality_enabled', 'reliability_enabled', 'punctuality_grace_minutes'] as $key) {
        assertTrue(strpos($sql, "('{$key}',") !== false, "{$key} fehlt im Insert-Block des Schemas");
    }
});
