<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/update_swap.php';

/** Verzeichnisbaum aus [relativer Pfad => Inhalt]. */
function swapTree(array $dateien): string
{
    $root = sys_get_temp_dir() . '/es_swap_' . uniqid();
    mkdir($root);
    foreach ($dateien as $rel => $inhalt) {
        @mkdir(dirname("{$root}/{$rel}"), 0777, true);
        file_put_contents("{$root}/{$rel}", $inhalt);
    }
    return $root;
}

test('Plan kopiert Paketdateien, aber nie geschuetzte', function () {
    $paket   = swapTree([
        'version.json'                      => '{}',
        'private/config/config_example.php' => '<?php return [];',
        'private/config/config.php'         => '<?php // boese',
        'public/update/.htaccess'           => 'Require all denied',
    ]);
    $install = swapTree(['version.json' => '{}']);

    $plan = updateBuildPlan($paket, $install);

    assertSame(['private/config/config_example.php', 'version.json'], $plan['copy']);

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('Plan loescht veraltete PHP- und JS-Dateien in betretenen Verzeichnissen', function () {
    $paket   = swapTree(['private/handlers/a.php' => 'neu', 'public/js/modules/b.js' => 'neu']);
    $install = swapTree([
        'private/handlers/a.php'   => 'alt',
        'private/handlers/alt.php' => 'alt',
        'public/js/modules/b.js'   => 'alt',
        'public/js/modules/alt.js' => 'alt',
    ]);

    assertSame(['private/handlers/alt.php', 'public/js/modules/alt.js'], updateBuildPlan($paket, $install)['delete']);

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('Plan loescht keine anderen Dateiarten', function () {
    // Vereine legen z. B. eine Verifizierungsdatei fuer Suchmaschinen in public/ ab.
    $paket   = swapTree(['public/index.html' => 'neu', 'public/css/main.css' => 'neu']);
    $install = swapTree(['public/google1234.html' => 'x', 'public/css/alt.css' => 'x']);

    assertSame([], updateBuildPlan($paket, $install)['delete']);

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('Plan betritt keine Verzeichnisse ohne Paketeintrag und loescht nie Verzeichnisse', function () {
    $paket   = swapTree(['private/handlers/a.php' => 'neu']);
    $install = swapTree([
        'private/demo/seed.php'         => 'x',
        'private/handlers/ordner/x.php' => 'x',
        'docs/beispiel.php'             => 'x',
    ]);

    assertSame([], updateBuildPlan($paket, $install)['delete']);

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('Plan loescht nichts auf oberster Ebene', function () {
    $paket   = swapTree(['version.json' => '{}']);
    $install = swapTree(['version.json' => '{}', 'eigenes.php' => '<?php']);

    assertSame([], updateBuildPlan($paket, $install)['delete']);

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('Plan respektiert export-ignore der Installation', function () {
    $paket   = swapTree(['private/migrations/manifest.php' => '<?php return [];']);
    $install = swapTree([
        '.gitattributes'                => "private/migrations/_*  export-ignore\nCLAUDE.md export-ignore\n",
        'private/migrations/_probe.php' => '<?php',
    ]);

    assertSame([], updateBuildPlan($paket, $install)['delete']);

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('Plan loescht in private/config nichts', function () {
    $paket   = swapTree(['private/config/config_example.php' => '<?php return [];']);
    $install = swapTree(['private/config/eigen.php' => '<?php']);

    assertSame([], updateBuildPlan($paket, $install)['delete']);

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('updateMatchesExportIgnore folgt den gitattributes-Regeln', function () {
    assertSame(true, updateMatchesExportIgnore('docs/a.md', 'docs/'));
    assertSame(true, updateMatchesExportIgnore('CLAUDE.md', 'CLAUDE.md'));
    assertSame(true, updateMatchesExportIgnore('x/CLAUDE.md', 'CLAUDE.md'));
    assertSame(true, updateMatchesExportIgnore('private/migrations/_a.php', 'private/migrations/_*'));
    assertSame(false, updateMatchesExportIgnore('private/migrations/a.php', 'private/migrations/_*'));
    assertSame(false, updateMatchesExportIgnore('documents/a.md', 'docs/'));
});

test('Preflight meldet ein Hindernis, bevor etwas getauscht wird', function () {
    // Eine DATEI liegt dort, wo das Paket ein Verzeichnis braucht -- ein
    // Schreibhindernis, das sich auch unter Windows ohne Rechtevergabe baut.
    $paket   = swapTree(['private/neu/datei.php' => 'neu']);
    $install = swapTree(['private/neu' => 'ich bin eine Datei']);

    $plan   = updateBuildPlan($paket, $install);
    $fehler = updatePreflight($plan, $install, $install . '/private/backup/probe');

    assertTrue($fehler !== [] && strpos(implode(' ', $fehler), 'private/neu') !== false, implode(' | ', $fehler));
    assertSame('ich bin eine Datei', file_get_contents("{$install}/private/neu"));

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('Preflight ohne Hindernis meldet nichts', function () {
    $paket   = swapTree(['private/handlers/a.php' => 'neu']);
    $install = swapTree(['private/handlers/a.php' => 'alt']);

    assertSame([], updatePreflight(updateBuildPlan($paket, $install), $install, $install . '/private/backup/probe'));

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('Sicherung, Tausch und Rueckweg stellen den Ausgangszustand wieder her', function () {
    $paket   = swapTree(['private/handlers/a.php' => 'neu', 'private/handlers/neu.php' => 'neu']);
    $install = swapTree(['private/handlers/a.php' => 'alt', 'private/handlers/weg.php' => 'weg']);
    $backup  = $install . '/private/backup/probe';

    $plan = updateBuildPlan($paket, $install);
    updateBackup($plan, $install, $backup, ['from' => '1.0.0', 'to' => '1.0.1', 'db_version' => '1.0.0', 'package_root' => $paket]);
    updateApply($plan, $paket, $install);

    assertSame('neu', file_get_contents("{$install}/private/handlers/a.php"));
    assertSame(true, is_file("{$install}/private/handlers/neu.php"));
    assertSame(false, is_file("{$install}/private/handlers/weg.php"));

    assertSame([], updateRollback($backup, $install));

    assertSame('alt', file_get_contents("{$install}/private/handlers/a.php"));
    assertSame('weg', file_get_contents("{$install}/private/handlers/weg.php"));
    assertSame(false, is_file("{$install}/private/handlers/neu.php"));

    updateRemoveTree($paket);
    updateRemoveTree($install);
});

test('Rueckweg weist unsichere Pfade in plan.json ab', function () {
    // Verschachtelt, damit ein Ausbruch -- sollte die Pruefung versagen -- im
    // Temp-Verzeichnis landet und nicht darueber.
    $huelle  = swapTree([]);
    $install = $huelle . '/a/b/install';
    mkdir($install . '/private/handlers', 0777, true);
    file_put_contents($install . '/private/handlers/a.php', 'alt');
    $backup = $huelle . '/a/b/backup';
    mkdir($backup . '/files', 0777, true);
    file_put_contents($backup . '/files/boese.php', '<?php');
    file_put_contents($backup . '/plan.json', json_encode([
        'format' => 1, 'from' => '1.0.0', 'to' => '1.0.1', 'db_version' => '1.0.0', 'package_root' => '/x',
        'overwritten' => ['../../boese.php'], 'created' => [], 'deleted' => [],
    ]));

    $fehler = updateRollback($backup, $install);

    assertTrue($fehler !== [], 'Unsicherer Pfad wurde akzeptiert');
    assertSame(false, is_file($huelle . '/a/boese.php'), 'Rueckweg hat ausserhalb der Installation geschrieben');

    updateRemoveTree($huelle);
});
