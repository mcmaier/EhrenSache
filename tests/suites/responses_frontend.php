<?php
declare(strict_types=1);

/**
 * Statische Gegenproben der Oberflaeche fuer die Terminrueckmeldung.
 *
 * Dashboard und PWA haben keinen eigenen JS-Testlauf; pruefbar bleibt die
 * Verdrahtung zwischen HTML, Modul und Serverantwort.
 */

$rsRoot = dirname(__DIR__, 2);

/** Das <input …>-Tag, das $needle enthaelt. */
function rsfTag(string $html, string $needle): string
{
    $pos = strpos($html, $needle);
    assertTrue($pos !== false, "{$needle} fehlt");
    $start = strrpos(substr($html, 0, $pos), '<');
    $end   = strpos($html, '>', $pos);

    return substr($html, $start, $end - $start + 1);
}

test('Einstellungskarte: Frist mit demselben Bereich wie der Server', function () use ($rsRoot) {
    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    $tag  = rsfTag($html, 'data-key="response_deadline_hours"');

    assertTrue(str_contains($tag, 'type="number"'), 'Zahlenfeld erwartet');
    assertTrue(str_contains($tag, 'min="0"'), 'min muss 0 sein wie responseHoursFromRaw()');
    assertTrue(str_contains($tag, 'max="720"'), 'max muss 720 sein wie RESPONSE_DEADLINE_MAX_HOURS');
});

test('Die Frist ist fuer Neuinstallationen angelegt', function () use ($rsRoot) {
    // Ein fehlender Schluessel laesst das Zahlenfeld leer, und settings.js
    // blockiert dann das Speichern ALLER Einstellungen.
    $sql = (string) file_get_contents($rsRoot . '/private/setup/ehrensache_db.sql');
    assertTrue(str_contains($sql, "('response_deadline_hours',"), 'Schluessel fehlt im Insert-Block');
});
