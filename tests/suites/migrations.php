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
