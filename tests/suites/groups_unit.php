<?php
/**
 * Regeln der Untergruppen: Bezeichnung und Sortierung.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/groups.php';

test('groupSubgroupLabel: leer, null und Leerraum ergeben die Vorgabe', function () {
    assertSame('Untergruppe', groupSubgroupLabel(null));
    assertSame('Untergruppe', groupSubgroupLabel(''));
    assertSame('Untergruppe', groupSubgroupLabel('   '));
});

test('groupSubgroupLabel: trimmt und behaelt das Wort', function () {
    assertSame('Register', groupSubgroupLabel('  Register '));
    assertSame('Mannschaft', groupSubgroupLabel('Mannschaft'));
});

test('groupSubgroupLabel: kuerzt auf 30 Zeichen', function () {
    $lang = str_repeat('A', 50);
    assertSame(30, mb_strlen(groupSubgroupLabel($lang)));
});

test('groupSubgroupLabel: entfernt Steuerzeichen und Zeilenumbrueche', function () {
    assertSame('Register', groupSubgroupLabel("Regi\nster"));
    assertSame('Register', groupSubgroupLabel("Register\t"));
});

test('groupSubgroupLabel: ungueltiges UTF-8 ergibt die Vorgabe', function () {
    assertSame('Untergruppe', groupSubgroupLabel("Kaputt\xFFUTF8"));
});

test('groupSubgroupLabel: escaped kein HTML, das macht die Oberflaeche', function () {
    assertSame('<b>Register</b>', groupSubgroupLabel('<b>Register</b>'));
});

test('groupSortCompare: sort_order entscheidet vor dem Namen', function () {
    $a = ['group_name' => 'Zither', 'sort_order' => 10];
    $b = ['group_name' => 'Althorn', 'sort_order' => 20];
    assertTrue(groupSortCompare($a, $b) < 0, 'kleinere sort_order zuerst');
});

test('groupSortCompare: gleiche sort_order entscheidet alphabetisch', function () {
    $a = ['group_name' => 'Trompete', 'sort_order' => 0];
    $b = ['group_name' => 'Klarinette', 'sort_order' => 0];
    assertTrue(groupSortCompare($a, $b) > 0, 'Klarinette vor Trompete');
});

test('groupSortCompare: fehlende sort_order zaehlt als 0', function () {
    $a = ['group_name' => 'Flöte'];
    $b = ['group_name' => 'Flöte', 'sort_order' => 5];
    assertTrue(groupSortCompare($a, $b) < 0, 'ohne Angabe wie 0');
});

// Zwei Faelle zu sort_order als Zeichenkette, bewusst getrennt: der erste haelt
// die Absicht fest (numerisch statt alphabetisch vergleichen), der zweite
// schuetzt den (int)-Cast. Fuer wohlgeformte numerische Strings wie '10'/'9'
// vergleicht PHPs <=> bereits numerisch -- der Cast waere dort nicht noetig,
// faellt also beim ersten Fall nicht auf. Erst bei nicht-numerischem Inhalt
// wie 'abc' greift der Cast tatsaechlich ein.
test('groupSortCompare: sort_order als Zeichenkette (wie von PDO geliefert)', function () {
    $a = ['group_name' => 'Flöte', 'sort_order' => '10'];
    $b = ['group_name' => 'Flöte', 'sort_order' => '9'];
    assertTrue(groupSortCompare($a, $b) > 0, 'numerisch, nicht alphabetisch: 10 nach 9');
});

test('groupSortCompare: nicht-numerische sort_order zaehlt als 0', function () {
    $a = ['group_name' => 'Flöte', 'sort_order' => 'abc'];
    $b = ['group_name' => 'Flöte', 'sort_order' => 5];
    assertTrue(groupSortCompare($a, $b) < 0, "'abc' zaehlt wie 0 und steht vor 5");
});

test('groupsSortForDisplay: sortiert eine Liste nach derselben Regel', function () {
    $liste = [
        ['group_name' => 'Schlagzeug', 'sort_order' => 90],
        ['group_name' => 'Klarinette', 'sort_order' => 20],
        ['group_name' => 'Flöte',      'sort_order' => 10],
    ];
    $namen = array_column(groupsSortForDisplay($liste), 'group_name');
    assertSame(['Flöte', 'Klarinette', 'Schlagzeug'], $namen);
});

test('groupsSortForDisplay: negative Werte stehen vorn', function () {
    $liste = [
        ['group_name' => 'Zweite', 'sort_order' => 0],
        ['group_name' => 'Erste',  'sort_order' => -5],
    ];
    $namen = array_column(groupsSortForDisplay($liste), 'group_name');
    assertSame(['Erste', 'Zweite'], $namen);
});
