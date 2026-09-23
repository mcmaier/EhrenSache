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

test('assertSame akzeptiert identische Werte', function () {
    assertSame(3, 3);
    assertSame('a', 'a');
});

test('assertSame unterscheidet Typen', function () {
    assertThrows(function () {
        assertSame(3, '3');
    });
});

test('assertTrue lehnt truthy Werte ab, die nicht true sind', function () {
    assertThrows(function () {
        assertTrue(1);
    });
});

test('assertThrows scheitert, wenn nichts geworfen wird', function () {
    assertThrows(function () {
        assertThrows(function () {
            // wirft absichtlich nichts
        });
    });
});
