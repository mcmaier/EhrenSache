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
    $schalter = implode('|', array_map(fn ($f) => preg_quote($f['setting'], '/'), FEATURES));
    $muster   = "/(?:systemSetting|worktimeSetting)\s*\([^;]*'(?:{$schalter})'/";
    $funde    = [];
    foreach (projectFiles(FU_ROOT . '/private', 'php') as $datei) {
        if (preg_match('#/private/(migrations|setup|demo)/#', $datei)
            || str_ends_with($datei, '/private/helpers/features.php')) {
            continue;
        }
        foreach (sourceLines($datei) as $i => $zeile) {
            if (preg_match($muster, $zeile)) {
                $funde[] = basename($datei) . ':' . ($i + 1);
            }
        }
    }
    assertSame([], $funde, 'Direkter Lesezugriff statt isFeatureEnabled(): ' . implode(', ', $funde));
});

test('api.php sperrt Funktionen zentral nach der CSRF-Pruefung und vor dem Routing', function () {
    $src    = apiRoutesFromSource()['src'];
    $sperre = strpos($src, 'featureForResource($resource)');
    $csrf   = strpos($src, 'validateCSRFToken(');
    $router = strpos($src, 'switch($resource) {');
    assertTrue($sperre !== false, 'Zentrale Sperre featureForResource($resource) fehlt in api.php');
    assertTrue($csrf !== false && $sperre > $csrf, 'Sperre muss nach der CSRF-Pruefung stehen');
    assertTrue($sperre < $router, 'Sperre muss vor switch($resource) stehen');
    assertTrue(str_contains($src, "require_once '../../private/helpers/features.php';"),
        'api.php muss features.php laden');
});
