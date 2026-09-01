<?php
/**
 * EhrenSache - Testrunner
 *
 * Aufruf:
 *   php tests/run.php            alle Suites
 *   php tests/run.php migrations nur tests/suites/migrations.php
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/harness.php';

$suiteArg = $argv[1] ?? null;
$files    = glob(__DIR__ . '/suites/*.php') ?: [];
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file, '.php');
    if ($suiteArg !== null && $name !== $suiteArg) {
        continue;
    }
    echo "Suite: {$name}\n";
    require $file;
    $ran++;
}

if ($ran === 0) {
    fwrite(STDERR, 'Keine Suite gefunden' . ($suiteArg !== null ? " für '{$suiteArg}'" : '') . "\n");
    exit(2);
}

exit(harnessSummary());
