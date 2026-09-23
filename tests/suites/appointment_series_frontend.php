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

test('Leerer Kalendertag: ein offenes Popup schliesst sich vor dem Anlegen', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue((bool) preg_match(
        '/document\.querySelector\(\'\.calendar-event-popup\'\)\?\.remove\(\);\s*openAppointmentModal\(null, \{ date: dateStr \}\);/',
        $js
    ), 'Ein festgehaltenes Popup wird vor dem Anlegen nicht entfernt');
});

// ---- Termin-Dialog (FI-7) ---------------------------------------------------------

test('Dialog: Bereich Wiederholen mit Muster, Abstand, Wochentagen, Position und Ende', function () use ($sfRoot) {
    $html = sfFile($sfRoot, 'public/index.html');
    foreach (['id="appointmentRepeatGroup"', 'id="appointment_repeat"', 'id="appointment_repeat_freq"',
              'id="appointment_repeat_interval"', 'id="appointment_repeat_days"', 'id="appointment_repeat_pos"',
              'id="appointment_repeat_weekday"', 'id="appointment_repeat_until"', 'id="appointmentSeriesPreview"',
              'id="appointmentSeriesBox"', 'id="appointmentSaveBtn"'] as $needle) {
        assertTrue(str_contains($html, $needle), "{$needle} fehlt");
    }
    foreach (['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as $day) {
        assertTrue(str_contains($html, "value=\"{$day}\""), "Wochentag {$day} fehlt");
    }
    foreach (['1', '2', '3', '4'] as $n) {
        assertTrue(str_contains($html, "<option value=\"{$n}\">"), "Abstand/Position {$n} fehlt");
    }
    assertTrue(str_contains($html, '<option value="-1">letzten</option>'));
});

test('Dialog: Vorschau und Anlegen gehen an appointment_series', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue(str_contains($js, "from './date_checklist.js'"));
    assertTrue(str_contains($js, "apiCall('appointment_series', 'POST'"));
    assertTrue(str_contains($js, 'preview: 1'));
    assertTrue(str_contains($js, "action: 'split'"));
    assertTrue(str_contains($js, "action: 'extend'"));
    assertTrue(str_contains($js, 'getDeselected()'));
});

test('Dialog: Serientermin fragt nach "Nur dieser" oder "Dieser und alle folgenden"', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue(str_contains($js, 'showChoice('));
    assertTrue(str_contains($js, "'Nur dieser'"));
    assertTrue(str_contains($js, "'Dieser und alle folgenden'"));
    assertTrue(str_contains($js, "apiCall('appointment_series', 'PUT'"));
    assertTrue(str_contains($js, "apiCall('appointment_series', 'DELETE'"));
});

test('Serienaktionen leeren den Termin-Cache aller betroffenen Jahre', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue(str_contains($js, 'async function invalidateSeriesYears('));
    assertTrue((bool) preg_match("/invalidateCache\('appointments',\s*y\)/", $js));
});

test('Die Regel wird im Dialog in Klartext beschrieben', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue(str_contains($js, 'function describeRrule('));
    assertTrue(str_contains($js, 'Teil der Serie:'));
    assertTrue(str_contains($js, 'Von der Serie'));
});

// ---- Herkunftsfilter: Serientermine ein-/ausblenden -------------------------------

test('Der Herkunftsfilter bietet "Nur Serientermine" und "Ohne Serientermine" an', function () use ($sfRoot) {
    $html = sfFile($sfRoot, 'public/index.html');
    assertTrue(str_contains($html, '<option value="series">Nur Serientermine</option>'),
               'Option value="series" fehlt im Herkunftsfilter');
    assertTrue(str_contains($html, '<option value="single">Ohne Serientermine</option>'),
               'Option value="single" fehlt im Herkunftsfilter');
});

test('Der Herkunftsfilter filtert series/single ueber series_id', function () use ($sfRoot) {
    $js = sfFile($sfRoot, 'public/js/modules/appointments.js');
    assertTrue((bool) preg_match(
        "/herkunft === 'series'\\).*?filter\\(a => a\\.series_id/s",
        $js
    ), '"series" muss ueber a.series_id filtern');
    assertTrue((bool) preg_match(
        "/herkunft === 'single'\\).*?filter\\(a => a\\.series_id/s",
        $js
    ), '"single" muss ueber a.series_id filtern');
});
