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

/** Festes Testmanifest — unabhängig vom echten, damit die Tests nicht mitwandern. */
function fixtureManifest(): array
{
    return [
        ['from' => '1.0.0', 'to' => '1.1.3', 'file' => 'a.php', 'function' => 'migrate_a'],
        ['from' => '1.1.3', 'to' => '1.2.0', 'file' => 'b.php', 'function' => 'migrate_b'],
        ['from' => '1.2.0', 'to' => '1.3.0', 'file' => 'c.php', 'function' => 'migrate_c'],
    ];
}

test('resolveMigrationChain liefert alle Schritte von der aeltesten Version', function () {
    $chain = resolveMigrationChain('1.0.0', '1.3.0', fixtureManifest());
    assertSame(['a.php', 'b.php', 'c.php'], array_column($chain, 'file'));
});

test('resolveMigrationChain liefert nur die noch fehlenden Schritte', function () {
    $chain = resolveMigrationChain('1.1.3', '1.3.0', fixtureManifest());
    assertSame(['b.php', 'c.php'], array_column($chain, 'file'));
});

test('resolveMigrationChain haelt bei der Zielversion an', function () {
    $chain = resolveMigrationChain('1.0.0', '1.2.0', fixtureManifest());
    assertSame(['a.php', 'b.php'], array_column($chain, 'file'));
});

test('resolveMigrationChain liefert nichts, wenn schon aktuell', function () {
    assertSame([], resolveMigrationChain('1.3.0', '1.3.0', fixtureManifest()));
});

test('resolveMigrationChain liefert nichts, wenn die DB neuer ist als der Code', function () {
    assertSame([], resolveMigrationChain('1.4.0', '1.3.0', fixtureManifest()));
});

test('resolveMigrationChain wirft bei einer Luecke im Manifest', function () {
    assertThrows(function () {
        // 1.0.5 kommt in keinem 'from' vor
        resolveMigrationChain('1.0.5', '1.3.0', fixtureManifest());
    });
});

test('resolveMigrationChain wirft bei einem Schritt, der nicht vorwaerts fuehrt', function () {
    $broken = [
        ['from' => '1.0.0', 'to' => '1.0.0', 'file' => 'loop.php', 'function' => 'migrate_loop'],
    ];
    assertThrows(function () use ($broken) {
        resolveMigrationChain('1.0.0', '1.3.0', $broken);
    });
});

test('Das echte Manifest ist lueckenlos verkettet', function () {
    $manifest = loadMigrationManifest(__DIR__ . '/../../private/migrations/manifest.php');

    for ($i = 1; $i < count($manifest); $i++) {
        assertSame(
            $manifest[$i - 1]['to'],
            $manifest[$i]['from'],
            "Schritt {$i}: 'from' passt nicht zum 'to' des Vorgaengers"
        );
    }
});

test('Das Manifest endet bei der Version aus version.json', function () {
    $manifest = loadMigrationManifest(__DIR__ . '/../../private/migrations/manifest.php');
    $version  = json_decode(file_get_contents(__DIR__ . '/../../version.json'), true);

    assertSame(
        $version['version'],
        $manifest[count($manifest) - 1]['to'],
        'Letzter Migrationsschritt und version.json muessen dieselbe Version nennen'
    );
});
