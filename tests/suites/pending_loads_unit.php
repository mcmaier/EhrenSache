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
declare(strict_types=1);

/**
 * Zusammenlegen laufender Abrufe (public/js/modules/pending_loads.js), ausgefuehrt
 * unter Node. Das Modul ist bewusst importfrei, damit Node es ohne Browser laden
 * kann. Fehlt Node, meldet die Suite das deutlich und prueft nichts -- das Harness
 * kennt kein "uebersprungen" (Muster: actions_unit).
 */

$plRoot = dirname(__DIR__, 2);

test('Laufende Abrufe bestehen die Node-Tests', function () use ($plRoot) {
    exec('node --version 2>&1', $version, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "  HINWEIS  node nicht gefunden - pending_loads_unit prueft nichts\n");
        return;
    }

    $test = $plRoot . '/tests/js/pending_loads.test.mjs';
    exec('node --test ' . escapeshellarg($test) . ' 2>&1', $out, $rc);

    assertSame(0, $rc, "node --test meldet Fehler:\n" . implode("\n", $out));
});
