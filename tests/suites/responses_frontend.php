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

test('saveAllSettings meldet eine serverseitige Ablehnung nicht als gespeichert', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/settings.js');

    // Die Funktion wird schon vorher als Event-Listener referenziert
    // ("saveBtn.addEventListener('click', saveAllSettings)") -- die Definition
    // beginnt erst bei "function saveAllSettings".
    $start = strpos($js, 'function saveAllSettings');
    assertTrue($start !== false, 'saveAllSettings() fehlt');
    $ende  = strpos($js, 'function ', $start + strlen('function saveAllSettings'));
    assertTrue($ende !== false, 'Ende von saveAllSettings() nicht gefunden');
    $body  = substr($js, $start, $ende - $start);

    assertTrue(str_contains($body, '.success'),
        'saveAllSettings() muss result.success (oder !result) pruefen, bevor es Erfolg meldet');
});

test('Terminart-Modal fuehrt die vier Rueckmeldungs-Felder', function () use ($rsRoot) {
    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    foreach (['type_responses_enabled', 'type_responses_names_visible',
              'type_responses_require_excuse', 'type_response_deadline_hours'] as $id) {
        assertTrue(str_contains($html, "id=\"{$id}\""), "Feld {$id} fehlt im Terminart-Modal");
    }

    $tag = rsfTag($html, 'id="type_response_deadline_hours"');
    assertTrue(str_contains($tag, 'min="0"') && str_contains($tag, 'max="720"'), 'Bereich wie der Server');
});

test('management.js schickt die vier Felder beim Speichern', function () use ($rsRoot) {
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/management.js');
    foreach (['responses_enabled:', 'responses_names_visible:', 'responses_require_excuse:', 'response_deadline_hours:'] as $key) {
        assertTrue(str_contains($js, $key), "{$key} fehlt in saveType()");
    }
});

test('Terminliste hat die Spalte Rueckmeldung und passende colspan', function () use ($rsRoot) {
    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    assertTrue(str_contains($html, '<th>Rückmeldung</th>'), 'Spaltenkopf fehlt');

    $js = (string) file_get_contents($rsRoot . '/public/js/modules/appointments.js');
    assertTrue(!str_contains($js, 'colspan="4"'), 'appointments.js rendert noch vier Spalten');
});

test('Rueckmeldungs-Modal ist eingebunden', function () use ($rsRoot) {
    $html = (string) file_get_contents($rsRoot . '/public/index.html');
    assertTrue(str_contains($html, 'id="responsesModal"'), 'Modal fehlt');
    assertTrue(str_contains($html, 'src="./js/modules/responses.js"'), 'Modul nicht geladen');

    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');
    foreach (['openResponsesModal', 'closeResponsesModal', 'setOwnResponse', 'setMemberResponse'] as $fn) {
        assertTrue(str_contains($js, "window.{$fn} = {$fn}"), "{$fn} ist nicht global erreichbar");
    }
});

test('responses.js maskiert Namen und Bemerkungen', function () use ($rsRoot) {
    // Keine CSP (OI-17): Bemerkungen sind freie Eingaben von Mitgliedern.
    $js = (string) file_get_contents($rsRoot . '/public/js/modules/responses.js');
    assertTrue(str_contains($js, 'escapeHtml(m.comment'), 'Bemerkung wird nicht maskiert');
    assertTrue(str_contains($js, 'escapeHtml(m.surname'), 'Name wird nicht maskiert');
});
