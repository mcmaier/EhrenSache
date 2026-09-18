<?php
declare(strict_types=1);

/**
 * Eingabefelder fuer Ort und Ende (FI-23) in Dashboard und PWA.
 *
 * Geprueft wird die Verdrahtung: Jedes Feld im Markup wird beim Speichern
 * mitgeschickt und beim Bearbeiten vorbelegt.
 */

$adRoot = dirname(__DIR__, 2);

function adfDatei(string $rel): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . $rel);
}

test('Dashboard-Dialog hat Ende und Ort mit Vorschlagsliste', function () {
    $html = adfDatei('/public/index.html');
    foreach (['id="appointment_end_time"', 'id="appointment_location"', 'list="appointmentLocations"',
              '<datalist id="appointmentLocations">'] as $teil) {
        assertTrue(str_contains($html, $teil), "Im Dashboard-Dialog fehlt: {$teil}");
    }
});

test('Dashboard speichert und laedt Ende und Ort', function () {
    $js = adfDatei('/public/js/modules/appointments.js');
    foreach (["getElementById('appointment_end_time')", "getElementById('appointment_location')",
              'end_time:', 'location:', 'formatTimeRange(', "locations: 1"] as $teil) {
        assertTrue(str_contains($js, $teil), "In appointments.js fehlt: {$teil}");
    }
});

test('PWA-Dialog hat Ende und Ort mit Vorschlagsliste', function () {
    $html = adfDatei('/public/checkin/index.html');
    foreach (['id="appointmentEndTime"', 'id="appointmentLocation"', 'list="appointmentLocationList"',
              '<datalist id="appointmentLocationList">'] as $teil) {
        assertTrue(str_contains($html, $teil), "Im PWA-Dialog fehlt: {$teil}");
    }
});

test('PWA speichert und laedt Ende und Ort', function () {
    $js = adfDatei('/public/checkin/js/app.js');
    foreach (["getElementById('appointmentEndTime')", "getElementById('appointmentLocation')",
              'fillPwaLocationSuggestions('] as $teil) {
        assertTrue(str_contains($js, $teil), "In checkin/js/app.js fehlt: {$teil}");
    }
});
