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

test('Beide Kacheln sind vorhanden und zunaechst verborgen', function () use ($puRoot) {
    $html = (string) file_get_contents($puRoot . '/public/index.html');

    foreach (['statPunctualityCard', 'statReliabilityCard'] as $id) {
        $pos = strpos($html, "id=\"{$id}\"");
        assertTrue($pos !== false, "Kachel {$id} fehlt");

        $tagEnde = strpos($html, '>', $pos);
        assertTrue(strpos(substr($html, $pos, $tagEnde - $pos), 'hidden') !== false,
            "{$id} muss ohne Serverantwort verborgen sein");
    }
});

test('Die Kacheln tragen keine Farbskala', function () use ($puRoot) {
    // Spec 8 / OI-55: Die Skala einer Quote wird einmal entschieden, fuer
    // Anwesenheit und Puenktlichkeit gemeinsam -- nicht hier nebenbei.
    $js    = (string) file_get_contents($puRoot . '/public/js/modules/statistics.js');
    $start = strpos($js, 'function updateBehaviorStats(');
    assertTrue($start !== false, 'updateBehaviorStats() fehlt');
    $body  = substr($js, $start, strpos($js, "\n}", $start) - $start);

    assertTrue(strpos($body, 'rate-') === false, 'Farbklasse in den Kacheln -- das ist OI-55');
});

test('Beide Pfade von renderStatistics befuellen die Kacheln', function () use ($puRoot) {
    // Der Leerpfad kehrt frueh zurueck. Fehlte der Aufruf dort, blieben nach
    // einem Filterwechsel die Werte der vorherigen Auswahl stehen.
    $js    = (string) file_get_contents($puRoot . '/public/js/modules/statistics.js');
    $start = strpos($js, 'export async function renderStatistics(');
    // Bis zur Schleife ueber die Gruppen -- dort beginnt der Tabellenaufbau,
    // und beide Aufrufe muessen davor stehen.
    $ende  = strpos($js, 'statsData.statistics.forEach', $start);
    assertTrue($start !== false && $ende !== false, 'renderStatistics() nicht auffindbar');
    $body  = substr($js, $start, $ende - $start);

    assertSame(2, substr_count($body, 'updateBehaviorStats('),
        'updateBehaviorStats() muss im Leerpfad und im Normalpfad stehen');
});

test('Die Kachel nennt die Mindestzahl aus der Serverantwort', function () use ($puRoot) {
    $js = (string) file_get_contents($puRoot . '/public/js/modules/statistics.js');

    assertTrue(strpos($js, 'min_measurements') !== false,
        'Die Mindestzahl muss aus der Antwort kommen, nicht als 5 im Skript stehen');
    assertSame(5, PUNCTUALITY_MIN_MEASUREMENTS);
});

test('Durchschnitt und neue Kacheln nutzen dasselbe Zahlenformat', function () use ($puRoot) {
    // "77.2%" neben "21,3 %" in derselben Kachelreihe.
    $js = (string) file_get_contents($puRoot . '/public/js/modules/statistics.js');

    assertTrue(strpos($js, "summary.overall_average + '%'") === false,
        'statOverallAverage nutzt noch Punkt und kein Leerzeichen');
    assertTrue(strpos($js, 'formatGerman(summary.overall_average)') !== false,
        'statOverallAverage muss ueber formatGerman() laufen');
});

test('Ohne Termine nennt die Puenktlichkeitskachel keine fehlenden Messungen', function () use ($puRoot) {
    $js    = (string) file_get_contents($puRoot . '/public/js/modules/statistics.js');
    $start = strpos($js, 'function updateBehaviorStats(');
    assertTrue($start !== false, 'updateBehaviorStats() fehlt');
    $body  = substr($js, $start, strpos($js, "\n}", $start) - $start);

    assertTrue(strpos($body, 'punctuality.total_count === 0') !== false,
        'Der Fall ohne Termine muss vor "Zu wenige Messungen" stehen');
});
