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
        'worktime'    => ['setting' => 'worktime_enabled',    'default' => '0', 'resources' => ['activity_types', 'work_sessions']],
        'station_pin' => ['setting' => 'station_pin_enabled', 'default' => '0', 'resources' => ['change_pin']],
        'punctuality' => ['setting' => 'punctuality_enabled', 'default' => '0', 'resources' => []],
        'reliability' => ['setting' => 'reliability_enabled', 'default' => '0', 'resources' => []],
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
        'private/handlers/station.php' => ['Antwortschlüssel worktime_enabled in status/identify — keine Lesung des Schalters'],
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
