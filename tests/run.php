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

/**
 * Absicherung gegen stille Abbrüche: Ruft eine Suite exit() (z. B. über eine
 * Funktion, die im Produktivcode ebenso exit() ruft), beendet das den ganzen
 * Prozess mit Rückgabewert 0 — die Zusammenfassung und alle alphabetisch
 * späteren Suiten werden übersprungen, ohne dass das von außen auffällt.
 *
 * register_shutdown_function laeuft auch nach einem solchen exit(). Wird der
 * Merker $reachedEnd bis dahin nicht gesetzt, war der Lauf nicht regulär zu
 * Ende gekommen — dann meldet dieser Handler den Abbruch auf STDERR und
 * ruft selbst exit() mit einem Wert ungleich 0. Ein exit() innerhalb einer
 * Shutdown-Function ueberschreibt den zuvor gesetzten Rueckgabewert des
 * Prozesses (verifiziert).
 */
$reachedEnd = false;
register_shutdown_function(function () use (&$reachedEnd) {
    if ($reachedEnd) {
        return;
    }
    fwrite(STDERR, "FEHLER: Testlauf wurde vorzeitig beendet (z. B. durch exit() oder einen Fatal Error in einer Suite) - Zusammenfassung nicht erreicht.\n");
    exit(1);
});

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
    $reachedEnd = true;
    fwrite(STDERR, 'Keine Suite gefunden' . ($suiteArg !== null ? " für '{$suiteArg}'" : '') . "\n");
    exit(2);
}

$code = harnessSummary();
$reachedEnd = true;
exit($code);
