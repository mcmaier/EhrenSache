<?php
declare(strict_types=1);

/**
 * Statische Gegenproben zu Profilseite, Dashboards und Geraeteliste
 * (OI-68, OI-71, OI-29).
 *
 * Fuenf kleine Korrekturen, die eines gemeinsam haben: Sie sind wieder
 * verloren, sobald jemand die Stelle anfasst, ohne den Grund zu kennen.
 * Deshalb steht der Grund hier jeweils im Testnamen.
 */

$pdRoot = dirname(__DIR__, 2);
$pdHtml = (string) file_get_contents($pdRoot . '/public/index.html');
$pdProfile = (string) file_get_contents($pdRoot . '/public/js/modules/profile.js');
$pdMembers = (string) file_get_contents($pdRoot . '/public/js/modules/members.js');
$pdStats   = (string) file_get_contents($pdRoot . '/public/js/modules/statistics.js');
$pdDevices = (string) file_get_contents($pdRoot . '/public/js/modules/devices.js');

// ============================================
// OI-68 · Mein Profil
// ============================================

test('Die Selbstauskunft zaehlt im Dialog keine Datenarten mehr auf', function () use ($pdProfile) {
    // Die alte Liste nannte vier von zehn Datenarten. Fuer eine Auskunft nach
    // Art. 15 DSGVO ist eine zu kurze Aufzaehlung die unangenehmere Richtung:
    // Sie erweckt den Eindruck, es werde weniger gespeichert, als es der Fall
    // ist. Jede Aufzaehlung an dieser Stelle veraltet ausserdem mit dem
    // naechsten Feature, das Daten hinzufuegt.
    assertTrue(
        strpos($pdProfile, 'Stammdaten, Anwesenheiten, Ausnahmen, Gruppenzugeh') === false,
        'Der Bestaetigungsdialog zaehlt wieder einzelne Datenarten auf'
    );
    assertTrue(
        strpos($pdProfile, 'Die Datei enth') !== false,
        'Der Dialog nennt nicht mehr, was die Datei enthaelt'
    );
});

test('Das Format-Auswahlfeld sieht nicht mehr gesperrt aus', function () use ($pdHtml) {
    // Das <select> trug dieselbe Optik wie die echten Nur-Lese-Felder darueber
    // (E-Mail, Rolle), obwohl downloadMyData() seinen Wert sehr wohl liest.
    // Wer die CSV wollte, probierte es gar nicht erst.
    $start = strpos($pdHtml, 'id="profile_user_data_format"');
    assertTrue($start !== false, 'Das Format-Auswahlfeld fehlt');

    $zeile = substr($pdHtml, $start, 200);
    assertTrue(
        strpos($zeile, 'not-allowed') === false,
        'Das auswertbare Auswahlfeld traegt wieder die Optik gesperrter Felder'
    );
});

test('Die Token-Karte nennt die Check-in-App, nicht die Zeiterfassung', function () use ($pdHtml) {
    // Der Token ist der Zugang zur PWA; die Zeiterfassung ist nur eine ihrer
    // Funktionen und obendrein abschaltbar. Wer sie nicht nutzt, hielt den
    // Token fuer ueberfluessig.
    assertTrue(
        strpos($pdHtml, '<h3>API-Token für Zeiterfassung</h3>') === false,
        'Die Karte nennt wieder die Zeiterfassung'
    );
    assertTrue(
        strpos($pdHtml, 'API-Token für die Check-in-App') !== false,
        'Der Kartentitel fehlt'
    );
});

// ============================================
// OI-71 · Dashboards
// ============================================

test('Das Mitglieder-Dashboard zeigt die Zahl der inaktiven Mitglieder', function () use ($pdHtml) {
    assertTrue(
        strpos($pdHtml, 'id="statInactiveMembersCount"') !== false,
        'Die Kennzahl fehlt im Markup'
    );
});

test('Beide Mitgliederzahlen kommen aus dem Bestand, nicht aus der Filterauswahl', function () use ($pdMembers) {
    // Der entscheidende Punkt: Aus der gefilterten Liste gerechnet, zeigte die
    // Karte "Inaktive" genau dann 0, wenn das Haekchen daneben aus ist -- also
    // immer dann, wenn jemand die Frage ueberhaupt stellt.
    $start = strpos($pdMembers, 'function updateMemberStats');
    assertTrue($start !== false, 'updateMemberStats fehlt');

    $ende  = strpos($pdMembers, "\n}", $start);
    $block = substr($pdMembers, $start, $ende - $start);

    assertTrue(
        strpos($block, 'dataCache.members[currentYear]') !== false,
        'Die Kennzahlen rechnen wieder ueber die uebergebene (gefilterte) Liste'
    );
    assertTrue(
        strpos($block, 'statInactiveMembersCount') !== false,
        'Die Zahl der inaktiven Mitglieder wird nicht gesetzt'
    );
});

test('Die Statistik hat einen Knopf zum Zuruecksetzen der Filter', function () use ($pdHtml, $pdStats) {
    assertTrue(
        strpos($pdHtml, 'id="resetStatisticsFilter"') !== false,
        'Der Knopf fehlt im Markup'
    );
    assertTrue(
        strpos($pdStats, 'window.resetStatisticsFilter') !== false,
        'Der Handler ist nicht global verdrahtet — der onclick liefe ins Leere'
    );
});

test('Das Zuruecksetzen der Statistik laesst das Jahr stehen', function () use ($pdStats) {
    // Wie bei den anderen Ansichten meint "Filter zuruecksetzen" die
    // Filterleiste, nicht den Jahreswechsel (vgl. resetMemberFilter).
    $start = strpos($pdStats, 'export async function resetStatisticsFilters');
    assertTrue($start !== false, 'resetStatisticsFilters fehlt');

    $ende  = strpos($pdStats, "\n}", $start);
    $block = substr($pdStats, $start, $ende - $start);

    assertTrue(
        strpos($block, 'statisticYearFilter') === false,
        'Das Zuruecksetzen greift das Jahr an'
    );
});

// ============================================
// OI-29 · Geraeteliste
// ============================================

test('Die Blaetterung der Geraeteliste spricht das Element an, das es gibt', function () use ($pdDevices, $pdHtml) {
    // Der Code suchte "devicesPagination", das Markup heisst
    // "devicePagination". Folge: Ab dem 26. Geraet war die Liste
    // abgeschnitten und es gab keinen Weg zu den uebrigen.
    assertTrue(
        strpos($pdDevices, "getElementById('devicesPagination')") === false,
        'devices.js sucht wieder den Namen, den es im Markup nicht gibt'
    );
    assertTrue(
        strpos($pdHtml, 'id="devicePagination"') !== false,
        'Das Ziel der Blaetterung fehlt im Markup'
    );
});

test('showDeviceSection wertet seinen page-Parameter aus', function () use ($pdDevices) {
    $start = strpos($pdDevices, 'export async function showDeviceSection');
    assertTrue($start !== false, 'showDeviceSection fehlt');

    $ende  = strpos($pdDevices, "\n}", $start);
    $block = substr($pdDevices, $start, $ende - $start);

    assertTrue(
        strpos($block, 'renderDevices(allDevices, 1)') === false,
        'Der Parameter wird wieder verworfen — ein Sprung auf Seite 2 landet auf 1'
    );
});

test('Keine Handler mehr fuer Geraetefilter, die es im Markup nicht gibt', function () use ($pdDevices, $pdHtml) {
    foreach (['filterDeviceRole', 'filterDeviceStatus', 'resetDeviceFilters'] as $id) {
        $imMarkup = strpos($pdHtml, 'id="' . $id . '"') !== false;
        $imCode   = strpos($pdDevices, "'" . $id . "'") !== false;

        assertTrue(
            $imMarkup || !$imCode,
            "devices.js spricht {$id} an, im Markup gibt es das Element nicht"
        );
    }
});
