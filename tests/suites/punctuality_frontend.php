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

/** Rumpf einer Funktion aus statistics.js bis zur schliessenden Klammer in Spalte 1. */
function puRumpf(string $puRoot, string $kopf): string
{
    $js    = (string) file_get_contents($puRoot . '/public/js/modules/statistics.js');
    $start = strpos($js, $kopf);
    assertTrue($start !== false, $kopf . ' fehlt');

    return substr($js, $start, strpos($js, "\n}", $start) - $start);
}

test('Eine abgeschaltete Kennzahl erscheint gar nicht erst als Chip', function () use ($puRoot) {
    // Seit 1.13.0 sind beide Quoten Anzeige-Chips statt Kachel mit hidden:
    // Sie werden schon aus dem Chipsatz genommen, wenn enabled false ist.
    $html = (string) file_get_contents($puRoot . '/public/index.html');
    foreach (['statPunctualityCard', 'statReliabilityCard'] as $alt) {
        assertSame(0, substr_count($html, $alt), $alt . ' muss mit den Kennzahlkarten entfallen');
    }

    $body = puRumpf($puRoot, 'function renderStatisticsChips(');
    foreach (['punctuality', 'reliability'] as $key) {
        assertTrue(strpos($body, "def.key === '{$key}'") !== false,
            "{$key} wird nicht auf enabled geprueft");
    }
    assertTrue(substr_count($body, '.enabled === true') === 2,
        'Beide Quoten muessen ohne enabled aus dem Chipsatz fallen');
});

test('Die Quoten tragen keine Farbskala', function () use ($puRoot) {
    // Spec 8 / OI-55: Die Skala einer Quote wird einmal entschieden, fuer
    // Anwesenheit und Puenktlichkeit gemeinsam -- nicht hier nebenbei.
    // renderStatisticsChips() gehoert mit in die Schleife: Dort laufen die
    // Werte beider Quoten zusammen, und eine Farbklasse waere dort genauso
    // leicht eingefuegt wie in den beiden Bauteilen darunter.
    foreach (['function punctualityChip(', 'function reliabilityChip(',
              'function renderStatisticsChips('] as $kopf) {
        assertTrue(strpos(puRumpf($puRoot, $kopf), 'rate-') === false,
            'Farbklasse in ' . $kopf . ' -- das ist OI-55');
    }
});

test('Beide Pfade von renderStatistics zeichnen die Chips', function () use ($puRoot) {
    // Der Leerpfad kehrt frueh zurueck. Fehlte der Aufruf dort, blieben nach
    // einem Filterwechsel die Werte der vorherigen Auswahl stehen.
    $js    = (string) file_get_contents($puRoot . '/public/js/modules/statistics.js');
    $start = strpos($js, 'export async function renderStatistics(');
    // Bis zur Schleife ueber die Gruppen -- dort beginnt der Tabellenaufbau,
    // und beide Aufrufe muessen davor stehen.
    $ende  = strpos($js, 'statsData.statistics.forEach', $start);
    assertTrue($start !== false && $ende !== false, 'renderStatistics() nicht auffindbar');
    $body  = substr($js, $start, $ende - $start);

    assertSame(2, substr_count($body, 'renderStatisticsChips('),
        'renderStatisticsChips() muss im Leerpfad und im Normalpfad stehen');
});

test('Der Chip nennt die Mindestzahl aus der Serverantwort', function () use ($puRoot) {
    $js = (string) file_get_contents($puRoot . '/public/js/modules/statistics.js');

    assertTrue(strpos($js, 'min_measurements') !== false,
        'Die Mindestzahl muss aus der Antwort kommen, nicht als 5 im Skript stehen');
    assertSame(5, PUNCTUALITY_MIN_MEASUREMENTS);
});

test('Durchschnitt und Quoten nutzen dasselbe Zahlenformat', function () use ($puRoot) {
    // "77.2%" neben "21,3 %". Seit der zweiten Sichtung steht der
    // Durchschnitt je Gruppe, die Quoten in der Kopfzeile -- dasselbe
    // Zahlenformat muessen sie trotzdem tragen.
    $js = (string) file_get_contents($puRoot . '/public/js/modules/statistics.js');

    assertSame(0, preg_match("/(overall_average|groupAverage\([^)]*\))\s*\+\s*'%'/", $js),
        'Der Durchschnitt nutzt noch Punkt und kein Leerzeichen');
    assertSame(1, preg_match('/formatGerman\(\s*groupAverage\(/', $js),
        'Der Durchschnitt je Gruppe muss ueber formatGerman() laufen');
});

test('Ohne Termine nennt der Puenktlichkeits-Chip keine fehlenden Messungen', function () use ($puRoot) {
    $body = puRumpf($puRoot, 'function punctualityChip(');

    $ohneTermine = strpos($body, 'punctuality.total_count === 0');
    $zuWenige    = strpos($body, 'punctuality.sufficient');
    assertTrue($ohneTermine !== false, 'Der Fall ohne Termine fehlt');
    assertTrue($zuWenige !== false && $ohneTermine < $zuWenige,
        'Der Fall ohne Termine muss vor "Zu wenige Messungen" stehen');
});
