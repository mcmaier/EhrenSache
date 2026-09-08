<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/demo/plan.php';

// ---- DemoRandom ----------------------------------------------------------
// Eigener Generator statt mt_rand: Die Folge muss unabhaengig davon sein, ob
// anderer Code zwischendurch aus dem globalen Zufall zieht.

test('DemoRandom liefert bei gleichem Saat dieselbe Folge', function () {
    $a = new DemoRandom(4711);
    $b = new DemoRandom(4711);
    $seqA = [$a->int(0, 999), $a->int(0, 999), $a->int(0, 999)];
    $seqB = [$b->int(0, 999), $b->int(0, 999), $b->int(0, 999)];
    assertSame($seqA, $seqB);
});

test('DemoRandom liefert bei anderem Saat eine andere Folge', function () {
    $a = new DemoRandom(1);
    $b = new DemoRandom(2);
    assertTrue($a->int(0, 999999) !== $b->int(0, 999999));
});

test('DemoRandom::int haelt die Grenzen ein', function () {
    $r = new DemoRandom(99);
    for ($i = 0; $i < 500; $i++) {
        $v = $r->int(3, 7);
        assertTrue($v >= 3 && $v <= 7, "Wert {$v} ausserhalb 3..7");
    }
});

test('DemoRandom::int mit min === max liefert genau diesen Wert', function () {
    $r = new DemoRandom(5);
    assertSame(42, $r->int(42, 42));
});

test('DemoRandom::pick waehlt aus der Liste', function () {
    $r    = new DemoRandom(7);
    $list = ['a', 'b', 'c'];
    for ($i = 0; $i < 50; $i++) {
        assertTrue(in_array($r->pick($list), $list, true));
    }
});

test('DemoRandom::chance(1.0) ist immer wahr, chance(0.0) immer falsch', function () {
    $r = new DemoRandom(13);
    for ($i = 0; $i < 50; $i++) {
        assertSame(true, $r->chance(1.0));
        assertSame(false, $r->chance(0.0));
    }
});
