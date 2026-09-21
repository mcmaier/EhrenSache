<?php
declare(strict_types=1);

/**
 * Statische Gegenproben der Oberflaeche fuer Terminserien, Feiertage und das
 * Anlegen im Kalender (FI-7, FI-16, OI-64).
 *
 * Das Dashboard hat keinen eigenen JS-Testlauf; pruefbar bleibt die
 * Verdrahtung zwischen HTML, Modul und Serverantwort.
 */

$sfRoot = dirname(__DIR__, 2);

function sfFile(string $root, string $rel): string
{
    $src = file_get_contents($root . '/' . $rel);
    assertTrue($src !== false, "{$rel} fehlt");

    return (string) $src;
}

// ---- Bausteine ------------------------------------------------------------------

test('date_checklist.js exportiert renderDateChecklist und haelt Daten nicht im DOM', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/date_checklist.js');
    assertTrue(str_contains($js, 'export function renderDateChecklist('));
    assertTrue(str_contains($js, 'getDeselected'));
    assertTrue(!str_contains($js, 'data-suggestion'), 'kein JSON in data-Attributen');
    assertTrue(str_contains($js, 'escapeHtml('), 'Beschriftungen werden escaped');
});

test('Die Import-Vorschau nutzt die Datumsliste statt JSON im data-Attribut', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/import_export.js');
    assertTrue(str_contains($js, "from './date_checklist.js'"));
    assertTrue(!str_contains($js, 'data-suggestion'));
    assertTrue(str_contains($js, 'getSelectedItems()'));
});

test('showChoice ist exportiert und das Modal existiert', function () use ($sfRoot) {
    assertTrue(str_contains(sfFile($sfRoot, 'public/js/modules/ui.js'), 'export function showChoice('));
    $html = sfFile($sfRoot, 'public/index.html');
    foreach (['id="choiceModal"', 'id="choiceTitle"', 'id="choiceMessage"', 'id="choiceButtons"'] as $needle) {
        assertTrue(str_contains($html, $needle), "{$needle} fehlt");
    }
});

test('Der Cache kennt die Feiertage je Jahr', function () use ($sfRoot) {
    assertTrue((bool) preg_match('/holidays:\s*\{\}/', sfFile($sfRoot, 'public/js/modules/ui.js')));
});

// ---- Kalender (OI-64, FI-16) ------------------------------------------------------

test('Leerer Kalendertag: Anlegen nur fuer Admin/Manager und nur im laufenden Monat', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue((bool) preg_match('/!isOtherMonth\s*&&\s*isAdminOrManager/', $js), 'Bedingung fehlt');
    assertTrue(str_contains($js, 'calendar-day--can-create'));
    assertTrue(str_contains($js, 'openAppointmentModal(null, { date: dateStr })'));
});

test('Popup: Bearbeiten und "+ Termin an diesem Tag" fuer Admin/Manager', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue(str_contains($js, 'calendar-event-edit'));
    assertTrue(str_contains($js, '+ Termin an diesem Tag'));
});

test('Feiertage werden je Jahr ueber die Ressource holidays geladen', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue(str_contains($js, "apiCall('holidays'"));
    assertTrue(str_contains($js, 'calendar-day--holiday'));
    assertTrue(str_contains(sfFile($sfRoot, 'public/css/variables.css'), '--calendar-holiday'));
});

test('Einstellung Bundesland: alle 16 Laender plus "nur bundesweit"', function () use ($sfRoot) {
    $html = sfFile($sfRoot, 'public/index.html');
    assertTrue(str_contains($html, 'data-key="holiday_region"'));
    require_once $sfRoot . '/private/helpers/holidays.php';
    foreach (array_keys(HOLIDAY_REGIONS) as $code) {
        assertTrue(str_contains($html, "<option value=\"{$code}\">"), "{$code} fehlt");
    }
    assertTrue(str_contains($html, '<option value="">nur bundesweite Feiertage</option>'));
    assertTrue(str_contains(sfFile($sfRoot, 'private/setup/ehrensache_db.sql'), "('holiday_region',"));
});

test('Nach Wechsel des Bundeslands wird der Feiertags-Cache geleert', function () use ($sfRoot) {
    assertTrue(str_contains(sfFile($sfRoot, 'public/js/modules/settings.js'), 'dataCache.holidays'));
});

test('Terminliste kennzeichnet Serientermine', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue(str_contains($js, 'Teil einer Serie'));
    assertTrue(str_contains($js, 'Aus einer Serie, einzeln geändert'));
});
