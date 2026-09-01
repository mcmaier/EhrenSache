<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/migrations.php';

test('latestSchemaVersion liefert die höchste Version', function () {
    assertSame('1.1.3', latestSchemaVersion(['1.0.0', '1.1.3']));
});

test('latestSchemaVersion ist unabhängig von der Reihenfolge', function () {
    assertSame('1.1.3', latestSchemaVersion(['1.1.3', '1.0.0']));
});

test('latestSchemaVersion vergleicht numerisch, nicht als Text', function () {
    // Der eigentliche Grund fuer diese Funktion: '1.9.0' ist kleiner als '1.10.0',
    // eine String-Sortierung wuerde das Gegenteil behaupten.
    assertSame('1.10.0', latestSchemaVersion(['1.9.0', '1.10.0']));
});

test('latestSchemaVersion liefert null bei leerer Liste', function () {
    assertSame(null, latestSchemaVersion([]));
});

test('latestSchemaVersion ignoriert leere und nicht-String-Werte', function () {
    assertSame('1.1.3', latestSchemaVersion(['', '1.1.3', null, 42]));
});

test('normalizeDetectedVersion bildet 1.1.x auf 1.1.3 ab', function () {
    assertSame('1.1.3', normalizeDetectedVersion('1.1.x'));
});

test('normalizeDetectedVersion laesst konkrete Versionen unveraendert', function () {
    assertSame('1.0.0', normalizeDetectedVersion('1.0.0'));
    assertSame('1.2.0', normalizeDetectedVersion('1.2.0'));
});

test('normalizeDetectedVersion lehnt unbekannt ab', function () {
    assertThrows(function () {
        normalizeDetectedVersion('unbekannt');
    });
});

test('normalizeDetectedVersion lehnt Leerstring ab', function () {
    assertThrows(function () {
        normalizeDetectedVersion('');
    });
});

test('loadMigrationManifest liefert wohlgeformte Schritte', function () {
    $manifest = loadMigrationManifest(__DIR__ . '/../../private/migrations/manifest.php');

    assertTrue(count($manifest) >= 1, 'Manifest darf nicht leer sein');

    foreach ($manifest as $i => $step) {
        foreach (['from', 'to', 'file', 'function'] as $key) {
            assertTrue(
                isset($step[$key]) && is_string($step[$key]) && $step[$key] !== '',
                "Schritt {$i}: Feld '{$key}' fehlt oder ist leer"
            );
        }
        assertTrue(
            version_compare($step['to'], $step['from'], '>'),
            "Schritt {$i}: 'to' muss groesser als 'from' sein"
        );
        assertTrue(
            file_exists(__DIR__ . '/../../private/migrations/' . $step['file']),
            "Schritt {$i}: Datei {$step['file']} existiert nicht"
        );
    }
});

test('loadMigrationManifest wirft bei fehlender Datei', function () {
    assertThrows(function () {
        loadMigrationManifest(__DIR__ . '/gibt-es-nicht.php');
    });
});

test('Manifest enthaelt den bestehenden Schritt ab 1.0.0', function () {
    $manifest = loadMigrationManifest(__DIR__ . '/../../private/migrations/manifest.php');
    $froms    = array_column($manifest, 'from');
    assertTrue(in_array('1.0.0', $froms, true), 'Schritt ab 1.0.0 fehlt');
});
