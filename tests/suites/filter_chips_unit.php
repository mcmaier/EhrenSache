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
 * Logiktests der Status-Chips (Spec 2026-09-22), ausgefuehrt unter Node.
 *
 * Die Zaehl- und Filterlogik liegt in public/js/modules/filter_chips.js und
 * ist bewusst importfrei, damit Node sie ohne Browser laden kann. Fehlt Node
 * auf dem Rechner, meldet die Suite das deutlich und prueft nichts -- das
 * Harness kennt kein "uebersprungen".
 */

$fcRoot = dirname(__DIR__, 2);

test('Chip-Logik besteht die Node-Tests', function () use ($fcRoot) {
    exec('node --version 2>&1', $version, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "  HINWEIS  node nicht gefunden - filter_chips_unit prueft nichts\n");
        return;
    }

    $test = $fcRoot . '/tests/js/filter_chips.test.mjs';
    exec('node --test ' . escapeshellarg($test) . ' 2>&1', $out, $rc);

    assertSame(0, $rc, "node --test meldet Fehler:\n" . implode("\n", $out));
});
