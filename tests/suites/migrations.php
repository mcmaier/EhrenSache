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

test('Migration 1.2.5 definiert migrate_1_2_5', function () {
    $file = __DIR__ . '/../../private/migrations/1.2.5.php';
    assertTrue(file_exists($file), '1.2.5.php fehlt');
    require_once $file;
    assertTrue(function_exists('migrate_1_2_5'), 'migrate_1_2_5() fehlt');
});

test('Zu jedem Manifest-Eintrag existiert die Migrationsfunktion', function () {
    // Die Datei wird bereits weiter oben geprueft; hier geht es um die
    // Funktion darin. Fehlt sie, faellt das heute erst beim Update auf --
    // nach dem Dateitausch, auf der Maschine des Vereins.
    $manifest = loadMigrationManifest(__DIR__ . '/../../private/migrations/manifest.php');

    foreach ($manifest as $step) {
        require_once __DIR__ . '/../../private/migrations/' . $step['file'];
        assertTrue(
            function_exists($step['function']),
            "Funktion {$step['function']}() fehlt in {$step['file']}"
        );
    }
});

test('Migration 1.2.3 liest die Toleranz aus dem Text der config.php', function () {
    require_once __DIR__ . '/../../private/migrations/1.2.3.php';

    $vorlage = (string) file_get_contents(__DIR__ . '/../fixtures/config_legacy_1_5_1.php');
    $pfad    = sys_get_temp_dir() . '/es_tol_' . uniqid() . '.php';
    $faelle  = [
        "define('AUTO_CHECKIN_TOLERANCE_HOURS', 2);"         => [2, false],
        "define('AUTO_CHECKIN_TOLERANCE_HOURS', 5);"         => [5, false],
        "define('AUTO_CHECKIN_TOLERANCE_HOURS', '3');"       => [3, false],
        "define('AUTO_CHECKIN_TOLERANCE_HOURS', 12);"        => [2, true],
        "define('AUTO_CHECKIN_TOLERANCE_HOURS', getTol());"  => [2, true],
        "// define('AUTO_CHECKIN_TOLERANCE_HOURS', 5);"      => [2, false],
    ];

    foreach ($faelle as $zeile => [$wert, $warnung]) {
        file_put_contents($pfad, str_replace("define('AUTO_CHECKIN_TOLERANCE_HOURS', 2);", $zeile, $vorlage));
        [$ergebnis, $hinweis] = migrate_1_2_3_tolerance($pfad);
        assertSame($wert, $ergebnis, "Fall: {$zeile}");
        assertSame($warnung, $hinweis !== null, "Warnung, Fall: {$zeile}");
    }
    unlink($pfad);

    assertSame([2, null], migrate_1_2_3_tolerance(sys_get_temp_dir() . '/gibt-es-nicht-' . uniqid() . '.php'));
});

test('Keine Migration bindet die config.php ein', function () {
    // Ein Einbinden der alten Klassenform definiert im Assistenten class Database
    // und braeche den Direktsprung von alten Versionen, sobald der Assistent
    // database.php laedt (Spec 2026-09-14-direktsprung-requires-design.md, 3.4).
    $verstoesse = [];
    foreach (glob(__DIR__ . '/../../private/migrations/*.php') ?: [] as $datei) {
        $quelle = (string) file_get_contents($datei);
        if (preg_match('/\b(require|require_once|include|include_once)\b[^;]*\$configPath/', $quelle)) {
            $verstoesse[] = basename($datei);
        }
    }
    assertSame([], $verstoesse, 'Migrationen binden $configPath ein');
});
