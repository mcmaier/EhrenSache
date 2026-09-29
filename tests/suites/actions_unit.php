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
 * Logik der Aktionstabelle (OI-17 Etappe 2), ausgefuehrt unter Node.
 *
 * public/js/modules/actions.js ist bewusst importfrei, damit Node es ohne
 * Browser laden kann. Fehlt Node, meldet die Suite das deutlich und prueft
 * nichts -- das Harness kennt kein "uebersprungen" (Muster: filter_chips_unit).
 */

$auRoot = dirname(__DIR__, 2);

test('Aktionstabelle besteht die Node-Tests', function () use ($auRoot) {
    exec('node --version 2>&1', $version, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "  HINWEIS  node nicht gefunden - actions_unit prueft nichts\n");
        return;
    }

    $test = $auRoot . '/tests/js/actions.test.mjs';
    exec('node --test ' . escapeshellarg($test) . ' 2>&1', $out, $rc);

    assertSame(0, $rc, "node --test meldet Fehler:\n" . implode("\n", $out));
});
