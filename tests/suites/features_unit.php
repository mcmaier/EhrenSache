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
 * Feature-Schalter (OI-62): die Liste FEATURES und ihre Wächter.
 *
 * Muster wie tests/suites/demo_mode.php: Die Liste ist wörtlich festgehalten,
 * damit jede Änderung eine sichtbare Entscheidung ist, und gegen api.php und
 * das Schema abgeglichen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/source.php';
require_once __DIR__ . '/../lib/api_routes.php';
require_once __DIR__ . '/../../private/helpers/features.php';

const FU_ROOT = __DIR__ . '/../..';

test('FEATURES ist wörtlich festgehalten', function () {
    assertSame([
        'appointments' => ['setting' => 'appointments_enabled', 'default' => '1', 'requires' => [],
                           'resources' => ['appointments', 'appointment_series', 'appointment_types', 'appointment_responses', 'holidays']],
        'attendance'   => ['setting' => 'attendance_enabled',   'default' => '1', 'requires' => ['appointments'],
                           'resources' => ['records', 'exceptions', 'attendance_list', 'auto_checkin', 'totp_checkin']],
        'worktime'     => ['setting' => 'worktime_enabled',     'default' => '0', 'requires' => [], 'resources' => ['activity_types', 'work_sessions']],
        'station_pin'  => ['setting' => 'station_pin_enabled',  'default' => '0', 'requires' => [], 'resources' => ['change_pin']],
        'punctuality'  => ['setting' => 'punctuality_enabled',  'default' => '0', 'requires' => ['attendance'], 'resources' => []],
        'reliability'  => ['setting' => 'reliability_enabled',  'default' => '0', 'requires' => ['attendance'], 'resources' => []],
    ], FEATURES);
});

test('Jeder Schalter steht mit demselben Default im Schema', function () {
    $sql = sourceCode(FU_ROOT . '/private/setup/ehrensache_db.sql');
    foreach (FEATURES as $key => $f) {
        $pattern = "/\('" . preg_quote($f['setting'], '/') . "'\s*,\s*'([^']*)'/";
        assertTrue((bool) preg_match($pattern, $sql, $m),
            "{$f['setting']} ({$key}) fehlt im INSERT von ehrensache_db.sql");
        assertSame($f['default'], $m[1], "Default von {$f['setting']} weicht vom Schema ab");
    }
});

test('Keine Ressource gehört zu zwei Funktionen', function () {
    $alle = [];
    foreach (FEATURES as $f) {
        $alle = array_merge($alle, $f['resources']);
    }
    assertSame(count($alle), count(array_unique($alle)), 'Doppelte Ressource in FEATURES');
});

test('Jede eingetragene Ressource wird in api.php geroutet', function () {
    $cases = apiRoutesFromSource()['cases'];
    assertTrue(count($cases) >= 25, 'Auslese von api.php liefert zu wenige case-Zweige');
    foreach (FEATURES as $key => $f) {
        foreach ($f['resources'] as $r) {
            assertTrue(in_array($r, $cases, true), "{$r} ({$key}) ist in api.php nicht geroutet");
        }
    }
});

test('featureForResource ordnet zu, sonst null', function () {
    assertSame('worktime', featureForResource('activity_types'));
    assertSame('worktime', featureForResource('work_sessions'));
    assertSame('station_pin', featureForResource('change_pin'));
    assertSame(null, featureForResource('members'));
    assertSame(null, featureForResource('station'));
});

test('isFeatureEnabled wirft bei unbekanntem Schlüssel', function () {
    assertThrows(fn () => isFeatureEnabled(null, null, 'gibt_es_nicht'),
        'Ein Tippfehler im Schlüssel muss auffallen, nicht still false liefern');
});

test('Außerhalb von features.php liest niemand einen Schalter direkt', function () {
    // Gesucht wird der WORTLAUT des Schalternamens, nicht ein bestimmter Aufruf:
    // so fällt auch ein mehrzeiliger systemSetting(...) oder ein SQL mit
    // setting_key = 'worktime_enabled' auf. Jeder legitime Fund steht mit Grund
    // in der Liste, alles andere muss über isFeatureEnabled() laufen.
    $erlaubt = [
        'private/handlers/station.php' => ['Antwortschlüssel worktime_enabled und attendance_enabled in status/identify — keine Lesung des Schalters'],
    ];
    $namen   = implode('|', array_map(fn ($f) => preg_quote($f['setting'], '/'), FEATURES));
    $muster  = "/['\"](?:{$namen})['\"]/";
    $funde   = [];
    $ausnahmen = [];
    $root    = str_replace("\\", "/", (string) realpath(FU_ROOT));
    $dateien = array_merge(projectFiles($root . '/private', 'php'), projectFiles($root . '/public', 'php'));
    foreach ($dateien as $datei) {
        $rel = ltrim(substr($datei, strlen($root)), '/');
        if (preg_match('#^private/(migrations|setup|demo)/#', $rel) || $rel === 'private/helpers/features.php') {
            continue;
        }
        if (!preg_match($muster, sourceCode($datei))) {
            continue;
        }
        if (isset($erlaubt[$rel])) {
            $ausnahmen[$rel] = true;
        } else {
            $funde[] = $rel;
        }
    }
    assertSame([], $funde, 'Schalter wörtlich außerhalb von features.php, statt isFeatureEnabled(): ' . implode(', ', $funde));
    foreach (array_keys($erlaubt) as $rel) {
        assertTrue(isset($ausnahmen[$rel]), "{$rel} steht in der Ausnahmeliste, enthält aber keinen Schalternamen mehr — Eintrag streichen");
    }
});

test('api.php sperrt Funktionen zentral nach der CSRF-Pruefung und vor dem Routing', function () {
    $src    = apiRoutesFromSource()['src'];
    $sperre = strpos($src, 'featureForResource($resource)');
    $csrf   = strpos($src, 'validateCSRFToken(');
    $router = strpos($src, 'switch($resource) {');
    assertTrue($sperre !== false, 'Zentrale Sperre featureForResource($resource) fehlt in api.php');
    assertTrue($csrf !== false && $sperre > $csrf, 'Sperre muss nach der CSRF-Pruefung stehen');
    assertTrue($sperre < $router, 'Sperre muss vor switch($resource) stehen');
    assertTrue((bool) preg_match('/^\$\w+\s*=\s*featureForResource\(\$resource\)/m', $src),
        'Die Sperre muss auf oberster Ebene stehen (ohne Einrückung), nicht im CSRF-if-Block, der nur Sitzungen trifft');
    assertTrue(str_contains($src, "require_once '../../private/helpers/features.php';"),
        'api.php muss features.php laden');
});

test('Jedes data-feature im Dashboard nennt eine bekannte Funktion', function () {
    preg_match_all('/data-feature="([^"]*)"/', sourceCode(FU_ROOT . '/public/index.html'), $m);
    assertTrue(in_array('worktime', $m[1], true), 'Der Menuepunkt Zeiterfassung muss data-feature="worktime" tragen');
    foreach ($m[1] as $key) {
        assertTrue(isset(FEATURES[$key]), "data-feature=\"{$key}\" ist keine Funktion aus FEATURES");
    }
});

test('Keine Oberflaeche erkennt eine Funktion mehr an einem 404', function () {
    $dateien = array_merge(projectFiles(FU_ROOT . '/public/js', 'js'), [FU_ROOT . '/public/checkin/js/app.js']);
    $funde = [];
    foreach ($dateien as $datei) {
        $code = sourceCode($datei);
        if (preg_match('/activity_types[^;]*silentStatuses\s*:\s*\[\s*404/', $code)) {
            $funde[] = basename($datei);
        }
    }
    assertSame([], $funde, 'Erkennung der Zeiterfassung per 404 statt features: ' . implode(', ', $funde));
});

test('Dashboard fuellt features aus me', function () {
    $api = sourceCode(FU_ROOT . '/public/js/modules/api.js');
    assertTrue(str_contains($api, 'setFeatures(user?.features'), 'setCurrentUser() muss setFeatures(user?.features …) aufrufen');
    assertTrue(str_contains($api, 'applyFeatureVisibility()'), 'setCurrentUser() muss applyFeatureVisibility() aufrufen');
    $pin = sourceCode(FU_ROOT . '/public/js/modules/members.js') . sourceCode(FU_ROOT . '/public/js/modules/profile.js');
    assertTrue(!str_contains($pin, 'station_pin_enabled'), 'members.js/profile.js lesen station_pin_enabled noch aus scope=client');
});

test('PWA prueft features.worktime vor activity_types und kennt FEATURE_DISABLED', function () {
    $pwa = sourceCode(FU_ROOT . '/public/checkin/js/app.js');
    assertTrue((bool) preg_match('/features\?\.worktime/', $pwa), 'initWorktime() muss userData?.features?.worktime pruefen');
    assertTrue(str_contains($pwa, "'FEATURE_DISABLED'"), 'apiCall() der PWA muss FEATURE_DISABLED unterscheiden');
});

// ---- Etappe 2: Voraussetzungen (requires) ------------------------------------

/** Alle Schalter mit eigener Einstellung „an“. */
function fuAlleAn(): array
{
    return array_fill_keys(array_keys(FEATURES), true);
}

test('requires nennt nur bekannte Funktionen und bildet keinen Ring', function () {
    foreach (FEATURES as $key => $f) {
        assertTrue(is_array($f['requires'] ?? null), "{$key}: Feld requires fehlt");
        foreach ($f['requires'] as $r) {
            assertTrue(isset(FEATURES[$r]), "{$key} setzt die unbekannte Funktion {$r} voraus");
        }
    }
    foreach (array_keys(FEATURES) as $start) {
        $todo = [[$start, [$start]]];
        while ($todo !== []) {
            [$k, $pfad] = array_pop($todo);
            foreach (FEATURES[$k]['requires'] as $r) {
                assertTrue(!in_array($r, $pfad, true), 'Ring in requires: ' . implode(' -> ', [...$pfad, $r]));
                $todo[] = [$r, [...$pfad, $r]];
            }
        }
    }
});

test('resolveFeatures: alles an bleibt an', function () {
    assertSame(fuAlleAn(), resolveFeatures(fuAlleAn()));
});

test('resolveFeatures: Terminplanung aus nimmt Anwesenheit, Pünktlichkeit und Zuverlässigkeit mit', function () {
    assertSame([
        'appointments' => false, 'attendance' => false, 'worktime' => true,
        'station_pin'  => true,  'punctuality' => false, 'reliability' => false,
    ], resolveFeatures(['appointments' => false] + fuAlleAn()));
});

test('resolveFeatures: Anwesenheit aus lässt die Terminplanung an', function () {
    assertSame([
        'appointments' => true, 'attendance' => false, 'worktime' => true,
        'station_pin'  => true, 'punctuality' => false, 'reliability' => false,
    ], resolveFeatures(['attendance' => false] + fuAlleAn()));
});

test('resolveFeatures: fehlender Schlüssel gilt als aus, Zeiterfassung und PIN bleiben unberührt', function () {
    assertSame(array_fill_keys(array_keys(FEATURES), false), resolveFeatures([]));
    assertSame([
        'appointments' => false, 'attendance' => false, 'worktime' => true,
        'station_pin'  => true,  'punctuality' => false, 'reliability' => false,
    ], resolveFeatures(['worktime' => true, 'station_pin' => true, 'punctuality' => true]));
});

test('featureForResource kennt Termin- und Anwesenheitsressourcen', function () {
    assertSame('appointments', featureForResource('appointments'));
    assertSame('appointments', featureForResource('holidays'));
    assertSame('appointments', featureForResource('appointment_responses'));
    assertSame('attendance', featureForResource('records'));
    assertSame('attendance', featureForResource('totp_checkin'));
    assertSame(null, featureForResource('statistics'));
    assertSame(null, featureForResource('statistics_report'));
    assertSame(null, featureForResource('my_data'));
    assertSame(null, featureForResource('available_years'));
});

test('featureSettingsWithDefaults ergaenzt fehlende Schalter mit ihrem Default', function () {
    $rows = featureSettingsWithDefaults([
        ['setting_key' => 'worktime_enabled',  'setting_value' => '1'],
        ['setting_key' => 'organization_name', 'setting_value' => 'Verein'],
    ]);
    $map = array_column($rows, 'setting_value', 'setting_key');
    assertSame('1', $map['worktime_enabled'], 'Eine vorhandene Zeile bleibt unveraendert');
    assertSame('Verein', $map['organization_name']);
    foreach (FEATURES as $f) {
        assertTrue(array_key_exists($f['setting'], $map), "{$f['setting']} fehlt");
    }
    assertSame('1', $map['appointments_enabled']);
    assertSame('1', $map['attendance_enabled']);
    assertSame('0', $map['station_pin_enabled']);
    $keys = array_column($rows, 'setting_key');
    $sortiert = $keys;
    sort($sortiert);
    assertSame($sortiert, $keys, 'Wie die Abfrage nach setting_key sortiert');
});

// ---- Etappe 2: Oberflächen ----------------------------------------------------

/** Rumpf einer JS-Funktion ab ihrer Signatur, per Klammerzählung (Kommentare sind schon entfernt). */
function fuBody(string $src, string $signature): string
{
    $start = strpos($src, $signature);
    assertTrue($start !== false, "Funktion '{$signature}' nicht gefunden");
    $open  = strpos($src, '{', $start + strlen($signature));
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}' && --$depth === 0) {
            return substr($src, $open, $i - $open + 1);
        }
    }
    throw new RuntimeException("Rumpf von '{$signature}' nicht abgeschlossen");
}

/** Steht vor jedem Vorkommen von $call im zugehörigen if die Prüfung $check? */
function fuGuarded(string $body, string $call, string $check): bool
{
    $offset = 0;
    $found  = false;
    while (($pos = strpos($body, $call, $offset)) !== false) {
        $found = true;
        $if = strrpos(substr($body, 0, $pos), 'if');
        if ($if === false || !str_contains(substr($body, $if, $pos - $if), $check)) {
            return false;
        }
        $offset = $pos + strlen($call);
    }
    return $found;
}

test('Dashboard: Menuepunkte, Terminarten und Terminfelder tragen data-feature', function () {
    $html = sourceCode(FU_ROOT . '/public/index.html');
    foreach (['termine' => 'appointments', 'anwesenheit' => 'attendance',
              'antraege' => 'attendance', 'statistik' => 'attendance'] as $section => $key) {
        assertTrue((bool) preg_match('/<li class="nav-item" data-section="' . $section . '" data-feature="' . $key . '"/', $html),
            "Menuepunkt {$section} braucht data-feature=\"{$key}\"");
    }
    assertTrue((bool) preg_match('/data-feature="appointments"[^>]*>\s*<div[^>]*>\s*<h2[^>]*>📅 Terminarten/u', $html),
        'Block Terminarten in der Verwaltung braucht data-feature="appointments"');
    assertTrue((bool) preg_match('/data-feature="appointments"[^>]*>\s*<label for="workSessionAppointment"/', $html),
        'Terminauswahl im Dialog der Arbeitszeit braucht data-feature="appointments"');
    assertTrue((bool) preg_match('/data-feature="appointments"[^>]*>\s*<label>Passende Terminarten/u', $html),
        'Terminarten im Dialog der Taetigkeitsart brauchen data-feature="appointments"');
});

test('Dashboard: Startabrufe in loadAllData haengen an den Schaltern', function () {
    $ui   = sourceCode(FU_ROOT . '/public/js/modules/ui.js');
    $body = fuBody($ui, 'function loadAllData(');
    foreach ([['loadAppointments()', "isFeatureOn('appointments')"], ['loadTypes()', "isFeatureOn('appointments')"],
              ['loadRecords()', "isFeatureOn('attendance')"], ['loadExceptions()', "isFeatureOn('attendance')"]] as [$call, $check]) {
        assertTrue(fuGuarded($body, $call, $check), "{$call} in loadAllData() ohne {$check}");
    }
    assertTrue(str_contains($ui, "import { isFeatureOn } from './features.js';"), 'ui.js importiert isFeatureOn nicht');
});

test('Dashboard: Kalender und Rueckmeldetabelle ohne Anwesenheitswerte, wenn die Anwesenheit aus ist', function () {
    $js = sourceCode(FU_ROOT . '/public/js/modules/appointments.js');
    assertTrue(str_contains(fuBody($js, 'function loadAppointments('),
        "isFeatureOn('attendance') ? { year: year, include: 'attendance' } : { year: year }"),
        'loadAppointments() fragt include=attendance ohne Bedingung ab');
    foreach (['function attendanceTotals(', 'function worstOwnStatus(', 'function attendanceLineHtml('] as $sig) {
        assertTrue(str_contains(fuBody($js, $sig), "isFeatureOn('attendance')"), "{$sig}) zeichnet Anwesenheit ohne Schalter");
    }
    assertTrue(str_contains(fuBody($js, 'function renderAppointments('), "isFeatureOn('attendance') && appointmentHasStarted(apt)"),
        'Listenknopf „Anwesenheit anzeigen“ ohne Schalter');
    assertTrue(str_contains(fuBody($js, 'function showAppointmentPopup('), "appointmentHasStarted(apt) && isFeatureOn('attendance')"),
        'Popup-Knopf „Anwesenheit“ ohne Schalter');
    $resp = sourceCode(FU_ROOT . '/public/js/modules/responses.js');
    assertTrue(str_contains(fuBody($resp, 'function managerTableHtml('), "data.started && isFeatureOn('attendance')"),
        'Rueckmeldetabelle zeigt die Spalte Anwesenheit ohne Schalter');
});

test('Dashboard: Zeiterfassung und Verwaltung fragen ohne Terminplanung keine Termine ab', function () {
    $wt = sourceCode(FU_ROOT . '/public/js/modules/worktime.js');
    assertTrue(str_contains(fuBody($wt, 'function fillWorkSessionAppointments('), "if (!isFeatureOn('appointments'))"),
        'fillWorkSessionAppointments() laedt Termine ohne Schalter');
    assertTrue(fuGuarded(fuBody($wt, 'function saveWorkSession('), 'body.appointment_id =', "isFeatureOn('appointments')"),
        'saveWorkSession() sendet appointment_id ohne Schalter');
    assertTrue(fuGuarded(fuBody($wt, 'function openActivityTypeModal('), 'loadTypes()', "isFeatureOn('appointments')"),
        'openActivityTypeModal() laedt Terminarten ohne Schalter');
    assertTrue(fuGuarded(fuBody($wt, 'function saveActivityType('), 'body.appointment_type_ids =', "isFeatureOn('appointments')"),
        'saveActivityType() sendet appointment_type_ids ohne Schalter -- die gespeicherte Eingrenzung ginge verloren');
    $mg = sourceCode(FU_ROOT . '/public/js/modules/management.js');
    assertTrue(fuGuarded(fuBody($mg, 'function showGroupSection('), 'loadTypes(', "isFeatureOn('appointments')"),
        'showGroupSection() laedt Terminarten ohne Schalter');
});

test('Einstellungen: Schalter fuer Terminplanung und Anwesenheit, gestuft', function () {
    $html  = sourceCode(FU_ROOT . '/public/index.html');
    $panel = strpos($html, 'data-settings-panel="termine"');
    $a     = strpos($html, 'data-key="appointments_enabled"');
    $b     = strpos($html, 'data-key="attendance_enabled"');
    $c     = strpos($html, 'data-key="checkin_auto_create_appointment"');
    assertTrue($panel !== false && $a !== false && $b !== false && $a > $panel && $b > $a && $c > $b,
        'Die beiden Schalter gehoeren als erste Karte in den Tab „Termine & Anwesenheit“');

    $js = sourceCode(FU_ROOT . '/public/js/modules/settings.js');
    assertTrue((bool) preg_match("/appointments_enabled:\s*\[[^\]]*'attendance_enabled'[^\]]*'holiday_region'[^\]]*'response_deadline_hours'/", $js),
        'FEATURE_SWITCHES: Terminplanung sperrt Anwesenheitsschalter, Feiertage und Rueckmeldefrist');
    assertTrue((bool) preg_match("/attendance_enabled:\s*\[[^\]]*'checkin_auto_create_appointment'[^\]]*'checkin_tolerance_hours'[^\]]*'punctuality_enabled'[^\]]*'reliability_enabled'[^\]]*'rate_threshold_good'/", $js),
        'FEATURE_SWITCHES: Anwesenheit sperrt Check-in-Fenster, Terminanlage, Kennzahlen und Quotenschwellen');
    $pA = strpos($js, 'appointments_enabled:');
    $pB = strpos($js, 'attendance_enabled:');
    $pC = strpos($js, 'punctuality_enabled:');
    assertTrue($pA !== false && $pB > $pA && $pC > $pB, 'Die Reihenfolge in FEATURE_SWITCHES traegt die Stufung');
    assertTrue(str_contains(fuBody($js, 'function applyFeatureSwitchState('), 'gesperrt.has(schalter)'),
        'applyFeatureSwitchState() wertet einen gesperrten Schalter nicht als aus');

    assertTrue((bool) preg_match('/const FEATURE_KEYS = \[([^\]]*)\]/', $js, $m), 'FEATURE_KEYS fehlt');
    foreach (FEATURES as $f) {
        assertTrue(str_contains($m[1], "'{$f['setting']}'"), "FEATURE_KEYS fehlt {$f['setting']}");
    }
});

test('PWA: Anmeldung liest me vor den Terminen und laedt sie nur mit eingeschalteter Funktion', function () {
    $pwa = sourceCode(FU_ROOT . '/public/checkin/js/app.js');
    assertTrue(str_contains($pwa, 'function pwaFeatureOn('), 'pwaFeatureOn() fehlt');
    // Seit OI-121 laufen Anmeldung und Auto-Login ueber startSession(me.data).
    foreach (['async function handleLogin(', 'async function checkAutoLogin('] as $sig) {
        $body  = fuBody($pwa, $sig);
        $me    = strpos($body, "apiCall('me')");
        $start = strpos($body, 'startSession(');
        assertTrue($me !== false && $start !== false && $me < $start, "{$sig}) startet die Sitzung nicht nach me");
    }
    $session = fuBody($pwa, 'async function startSession(');
    $user    = strpos($session, 'userData = meData');
    $termine = strpos($session, 'fetchCheckinAppointments()');
    assertTrue($user !== false && $termine !== false && $user < $termine,
        'startSession() muss userData (features) vor den Terminen setzen');
    assertTrue((bool) preg_match("/pwaFeatureOn\('attendance'\)\s*\?\s*fetchCheckinAppointments\(\)/", $session),
        'startSession() laedt Check-in-Termine ohne Schalter');
    assertTrue((bool) preg_match("/pwaFeatureOn\('appointments'\)\s*\?\s*loadAppointmentTypes\(\)/", $session),
        'startSession() laedt Terminarten ohne Schalter');
});

test('PWA: Erfassen, Termine, Verlauf, Statistik und Liste folgen den Schaltern', function () {
    $pwa  = sourceCode(FU_ROOT . '/public/checkin/js/app.js');
    $html = sourceCode(FU_ROOT . '/public/checkin/index.html');
    assertTrue(str_contains(fuBody($pwa, 'function availableIntents('), "pwaFeatureOn('attendance')"), 'Absicht Anwesenheit ohne Schalter');
    assertTrue((bool) preg_match("/none:\s*'captureNone'/", $pwa), 'CAPTURE_VIEWS kennt die Ansicht none nicht');
    assertTrue(str_contains(fuBody($pwa, 'function enterCaptureTab('), "'none'"), 'Ohne Absicht muss der Hinweis erscheinen');
    assertTrue(str_contains($html, 'id="captureNone"') && str_contains($html, 'Hier ist nichts zum Erfassen freigeschaltet'),
        'Hinweis „Hier ist nichts zum Erfassen freigeschaltet“ fehlt');
    assertTrue(str_contains(fuBody($pwa, 'function initResponsesTab('), "pwaFeatureOn('appointments')"), 'Tab Termine ohne Schalter');
    assertTrue(fuGuarded(fuBody($pwa, 'function loadHistory('), "apiCall('records'", "pwaFeatureOn('attendance')"), 'Verlauf fragt records ohne Schalter ab');
    $tabs = fuBody($pwa, 'function applyPwaFeatureTabs(');
    foreach (['responses', 'history', 'stats'] as $tab) {
        assertTrue(str_contains($tabs, "data-tab=\"{$tab}\""), "applyPwaFeatureTabs() behandelt den Tab {$tab} nicht");
    }
    assertTrue(str_contains(fuBody($pwa, 'function initTabs('), 'applyPwaFeatureTabs()'), 'initTabs() ruft applyPwaFeatureTabs() nicht');
    assertTrue(str_contains(fuBody($pwa, 'function initAttendanceList('), "pwaFeatureOn('attendance')"), 'Anwesenheitsliste ohne Schalter');
    assertTrue(str_contains(fuBody($pwa, 'function loadStatistics('), "pwaFeatureOn('attendance')"), 'Statistik ohne Schalter');
    assertTrue(str_contains($html, 'id="statsAttendanceCards"') && str_contains($html, 'id="statsGroupsSection"'),
        'Statistik: Quote und Gruppenuebersicht brauchen eine id zum Ausblenden');
});

test('PWA: Zeiterfassung ohne Terminbezug, wenn die Terminplanung aus ist', function () {
    $pwa  = sourceCode(FU_ROOT . '/public/checkin/js/app.js');
    $html = sourceCode(FU_ROOT . '/public/checkin/index.html');
    assertTrue(str_contains(fuBody($pwa, 'function loadWorktimeAppointments('), "pwaFeatureOn('appointments')"),
        'loadWorktimeAppointments() fragt Termine ohne Schalter ab');
    assertTrue(fuGuarded(fuBody($pwa, 'function saveWorkSession('), 'body.appointment_id =', "pwaFeatureOn('appointments')"),
        'saveWorkSession() sendet appointment_id ohne Schalter');
    assertTrue(str_contains($html, 'id="worktimeAppointmentGroup"') && str_contains($html, 'id="workSessionAppointmentGroup"'),
        'Terminfelder der Arbeitszeit brauchen eine id zum Ausblenden');
    assertTrue(str_contains(fuBody($pwa, 'function applyPwaFeatureTabs('), 'workSessionAppointmentGroup'),
        'applyPwaFeatureTabs() blendet die Terminfelder nicht aus');
});

test('Station blendet den Check-in aus, wenn die Anwesenheit aus ist', function () {
    $js   = sourceCode(FU_ROOT . '/public/station/js/app.js');
    $html = sourceCode(FU_ROOT . '/public/station/index.html');
    assertTrue(str_contains(fuBody($js, 'function applyStatus('), 'attendance_enabled'), 'applyStatus() wertet attendance_enabled nicht aus');
    assertTrue(str_contains(fuBody($js, 'function renderAction('), "\$('attendanceCard').hidden"), 'renderAction() blendet die Anwesenheitskarte nicht aus');
    assertTrue(str_contains($html, 'id="attendanceCard"'), 'Die Anwesenheitskarte braucht eine id');
    assertSame(2, substr_count($html, 'An dieser Station ist keine Funktion freigeschaltet.'),
        'Der Hinweis gehoert ins Ruhebild und in die Aktionsansicht');
});

test('PWA: Entschuldigen und Zurueckziehen brauchen die Anwesenheit', function () {
    $pwa = sourceCode(FU_ROOT . '/public/checkin/js/app.js');
    foreach (['function excuseSectionHtml(', 'function excuseChipHtml(', 'async function submitExcuse(', 'function withdrawExcuse('] as $sig) {
        assertTrue(str_contains(fuBody($pwa, $sig), "pwaFeatureOn('attendance')"), "{$sig}) ohne Schalter Anwesenheit");
    }
});
