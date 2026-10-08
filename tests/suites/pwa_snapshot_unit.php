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
 * "Letzter Stand" der Check-in-App (public/checkin/js/snapshot.js), ausgefuehrt
 * unter Node (OI-43, Stufe 2). Fehlt Node, meldet die Suite das deutlich und
 * prueft nichts -- das Harness kennt kein "uebersprungen" (Muster: pending_loads_unit).
 */

$snapRoot = dirname(__DIR__, 2);

test('Letzter Stand besteht die Node-Tests', function () use ($snapRoot) {
    exec('node --version 2>&1', $version, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "  HINWEIS  node nicht gefunden - pwa_snapshot_unit prueft nichts\n");
        return;
    }

    $test = $snapRoot . '/tests/js/pwa_snapshot.test.mjs';
    exec('node --test ' . escapeshellarg($test) . ' 2>&1', $out, $rc);

    assertSame(0, $rc, "node --test meldet Fehler:\n" . implode("\n", $out));
});
