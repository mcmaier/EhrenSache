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

// Ankerwert: haelt einen konkreten Folgewert fest. Ohne diesen Test liefe eine
// Aenderung der Generatorkonstanten (Multiplikator, Inkrement, Maske) gruen
// durch die Suite, obwohl damit jeder bereits erstellte Demo-Screenshot nicht
// mehr reproduzierbar waere.
test('DemoRandom::int liefert fuer Saat 20260908 den festgehaltenen Wert', function () {
    $r = new DemoRandom(20260908);
    assertSame(905, $r->int(0, 999));
});

// Faengt eine Rueckkehr zu "next() % n" (oder eine aequivalente Ziehung aus den
// unteren Bits) ab: Bei einem Zweierpotenz-Bereich wie 0..3 wiederholt sich die
// Folge dann starr mit Periode 4 und enthaelt nie zwei gleiche Werte in Folge.
test('DemoRandom::int(0,3) zeigt kein starres Wiederholungsmuster', function () {
    $r    = new DemoRandom(20260908);
    $seq  = [];
    for ($i = 0; $i < 40; $i++) {
        $seq[] = $r->int(0, 3);
    }
    $hasAdjacentRepeat = false;
    for ($i = 1; $i < count($seq); $i++) {
        if ($seq[$i] === $seq[$i - 1]) {
            $hasAdjacentRepeat = true;
            break;
        }
    }
    assertTrue($hasAdjacentRepeat, 'Folge wirkt wie eine starre Zyklusfolge der Periode 4: ' . implode('', $seq));
});

test('DemoRandom::int(0,4) verteilt ueber 10000 Ziehungen annaehernd gleich', function () {
    $r      = new DemoRandom(20260908);
    $counts = array_fill(0, 5, 0);
    for ($i = 0; $i < 10000; $i++) {
        $counts[$r->int(0, 4)]++;
    }
    foreach ($counts as $value => $count) {
        assertTrue($count >= 1800 && $count <= 2200, "Wert {$value} kam {$count}x vor, erwartet 1800..2200");
    }
});

test('DemoRandom::pick auf leerer Liste wirft', function () {
    assertThrows(fn () => (new DemoRandom(1))->pick([]));
});
