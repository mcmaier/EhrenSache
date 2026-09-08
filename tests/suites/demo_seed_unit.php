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

// ---- Stammdaten ----------------------------------------------------------

test('buildGroups liefert die vier Gruppen mit fortlaufenden IDs', function () {
    $groups = buildGroups();
    assertSame(4, count($groups));
    assertSame(1, $groups[0]['group_id']);
    assertSame('Aktive', $groups[0]['group_name']);
    assertSame(4, $groups[3]['group_id']);
    assertSame('Ehrenmitglieder', $groups[3]['group_name']);
});

test('buildGroups markiert genau eine Gruppe als Vorgabe', function () {
    $defaults = array_filter(buildGroups(), fn ($g) => $g['is_default'] === 1);
    assertSame(1, count($defaults));
});

test('buildAppointmentTypes liefert vier Arten mit Farbe', function () {
    $types = buildAppointmentTypes();
    assertSame(4, count($types));
    assertSame('Gesamtprobe', $types[0]['type_name']);
    foreach ($types as $t) {
        assertTrue(preg_match('/^#[0-9A-Fa-f]{6}$/', $t['color']) === 1, "Farbe fehlerhaft: {$t['color']}");
    }
});

test('buildAppointmentTypeGroups bindet die Vorstandssitzung nur an die Vorstandschaft', function () {
    $links = buildAppointmentTypeGroups();
    $board = array_values(array_filter($links, fn ($l) => $l['type_id'] === 4));
    assertSame(1, count($board));
    assertSame(3, $board[0]['group_id']);
});

test('buildAppointmentTypeGroups bindet die Registerprobe an Aktive und Jugend', function () {
    $links   = buildAppointmentTypeGroups();
    $section = array_map(fn ($l) => $l['group_id'], array_filter($links, fn ($l) => $l['type_id'] === 2));
    sort($section);
    assertSame([1, 2], array_values($section));
});

test('buildActivityTypes liefert sechs Taetigkeiten mit gueltigem Nachweisgrad', function () {
    $acts = buildActivityTypes();
    assertSame(6, count($acts));
    foreach ($acts as $a) {
        assertTrue(in_array($a['verification'], ['none', 'start', 'start_end'], true), "unbekannt: {$a['verification']}");
    }
});

test('buildActivityTypes enthaelt mindestens je einen Nachweisgrad', function () {
    $grades = array_unique(array_map(fn ($a) => $a['verification'], buildActivityTypes()));
    sort($grades);
    assertSame(['none', 'start', 'start_end'], array_values($grades));
});

// ---- Mitglieder ----------------------------------------------------------
// validateStationPin ist die echte Pruefung aus dem Produktivcode. Wenn der
// Generator eine PIN erzeugt, die sie ablehnt, koennte sich das Mitglied an der
// Station nicht anmelden — und niemand faende es vor dem Fototermin heraus.
require_once __DIR__ . '/../../private/helpers/station.php';

test('buildMembers liefert 40 Mitglieder mit fortlaufender Nummer', function () {
    $m = buildMembers(new DemoRandom(20260908));
    assertSame(40, count($m['members']));
    assertSame('M001', $m['members'][0]['member_number']);
    assertSame('M040', $m['members'][39]['member_number']);
});

test('buildMembers vergibt eindeutige Mitgliedsnummern und IDs', function () {
    $m       = buildMembers(new DemoRandom(20260908));
    $numbers = array_map(fn ($x) => $x['member_number'], $m['members']);
    $ids     = array_map(fn ($x) => $x['member_id'], $m['members']);
    assertSame(40, count(array_unique($numbers)));
    assertSame(40, count(array_unique($ids)));
});

test('buildMembers setzt genau drei Mitglieder inaktiv', function () {
    $m        = buildMembers(new DemoRandom(20260908));
    $inactive = array_filter($m['members'], fn ($x) => $x['active'] === 0);
    assertSame(3, count($inactive));
});

test('buildMembers gibt jedem Mitglied genau einen Mitgliedschaftszeitraum', function () {
    $m = buildMembers(new DemoRandom(20260908));
    assertSame(40, count($m['membership_dates']));
});

test('buildMembers beendet den Zeitraum genau bei den inaktiven Mitgliedern', function () {
    $m      = buildMembers(new DemoRandom(20260908));
    $closed = array_filter($m['membership_dates'], fn ($d) => $d['end_date'] !== null);
    assertSame(3, count($closed));
    foreach ($closed as $d) {
        assertSame('inactive', $d['status']);
    }
});

test('buildMembers ordnet jedes Mitglied mindestens einer Gruppe zu', function () {
    $m      = buildMembers(new DemoRandom(20260908));
    $byMemb = [];
    foreach ($m['assignments'] as $a) {
        $byMemb[$a['member_id']] = true;
    }
    assertSame(40, count($byMemb));
});

test('buildMembers haelt die Gruppenstaerken ein', function () {
    $m     = buildMembers(new DemoRandom(20260908));
    $count = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($m['assignments'] as $a) {
        $count[$a['group_id']]++;
    }
    assertSame(28, $count[1], 'Aktive');
    assertSame(8, $count[2], 'Jugend');
    assertSame(6, $count[3], 'Vorstandschaft');
    assertSame(4, $count[4], 'Ehrenmitglieder');
});

test('buildMembers vergibt genau 15 PINs im Klartext', function () {
    $m       = buildMembers(new DemoRandom(20260908));
    $withPin = array_filter($m['members'], fn ($x) => $x['pin'] !== null);
    assertSame(15, count($withPin));
});

test('jede erzeugte PIN besteht validateStationPin', function () {
    $m = buildMembers(new DemoRandom(20260908));
    foreach ($m['members'] as $x) {
        if ($x['pin'] === null) {
            continue;
        }
        assertSame(null, validateStationPin($x['pin'], 4), "PIN {$x['pin']} abgelehnt");
    }
});

test('buildMembers ist bei gleichem Saat reproduzierbar', function () {
    $a = buildMembers(new DemoRandom(20260908));
    $b = buildMembers(new DemoRandom(20260908));
    assertSame($a, $b);
});

// start_date und end_date wurden unabhaengig gezogen und konnten invertiert sein
// (z. B. Saat 151, Mitglied 19: start 2025-11-01, end 2025-08-13). Der Vorgabesaat
// 20260908 zeigt den Fehler nicht -- deshalb hier ueber viele Saaten pruefen statt
// nur ueber den einen Vorgabewert.
test('buildMembers: end_date liegt fuer alle Saaten 1..300 nach start_date und nicht nach dem Stichtag', function () {
    $referenceDate = '2026-09-08';
    for ($seed = 1; $seed <= 300; $seed++) {
        $m = buildMembers(new DemoRandom($seed), $referenceDate);
        foreach ($m['membership_dates'] as $d) {
            if ($d['end_date'] === null) {
                continue;
            }
            assertTrue(
                strtotime($d['end_date']) > strtotime($d['start_date']),
                "Saat {$seed}, Mitglied {$d['member_id']}: end_date {$d['end_date']} liegt nicht nach start_date {$d['start_date']}"
            );
            assertTrue(
                strtotime($d['end_date']) <= strtotime($referenceDate),
                "Saat {$seed}, Mitglied {$d['member_id']}: end_date {$d['end_date']} liegt nach dem Stichtag {$referenceDate}"
            );
        }
    }
});

test('demoShiftDate wirft bei ungueltigem Datum statt still auf die Systemuhr zurueckzufallen', function () {
    assertThrows(fn () => demoShiftDate('kein-datum', 5));
});

// ---- Termine -------------------------------------------------------------

test('buildAppointments deckt zwoelf Monate rueckwaerts und vier Wochen vorwaerts ab', function () {
    $appts = buildAppointments(new DemoRandom(20260908), '2026-09-08');
    $dates = array_map(fn ($a) => $a['date'], $appts);
    sort($dates);
    assertTrue($dates[0] >= '2025-09-08', "frühester Termin {$dates[0]} liegt vor dem Fenster");
    assertTrue(end($dates) <= '2026-10-06', 'spätester Termin liegt hinter dem Fenster');
});

test('buildAppointments liefert zwischen 80 und 110 Termine', function () {
    $count = count(buildAppointments(new DemoRandom(20260908), '2026-09-08'));
    assertTrue($count >= 80 && $count <= 110, "unerwartete Menge: {$count}");
});

test('buildAppointments legt genau vier Termine in die Zukunft', function () {
    $appts  = buildAppointments(new DemoRandom(20260908), '2026-09-08');
    $future = array_filter($appts, fn ($a) => $a['date'] > '2026-09-08');
    assertSame(4, count($future));
});

test('buildAppointments nutzt alle vier Terminarten', function () {
    $types = array_unique(array_map(fn ($a) => $a['type_id'], buildAppointments(new DemoRandom(20260908), '2026-09-08')));
    sort($types);
    assertSame([1, 2, 3, 4], array_values($types));
});

test('Gesamtproben liegen freitags um 20:00', function () {
    $appts = buildAppointments(new DemoRandom(20260908), '2026-09-08');
    foreach ($appts as $a) {
        if ($a['type_id'] !== 1) {
            continue;
        }
        assertSame('20:00:00', $a['start_time']);
        assertSame('5', date('N', strtotime($a['date'])), "{$a['date']} ist kein Freitag");
    }
});

test('buildAppointments vergibt eindeutige, fortlaufende IDs', function () {
    $appts = buildAppointments(new DemoRandom(20260908), '2026-09-08');
    $ids   = array_map(fn ($a) => $a['appointment_id'], $appts);
    assertSame(count($ids), count(array_unique($ids)));
    assertSame(1, min($ids));
});

test('buildAppointments ist bei gleichem Saat reproduzierbar', function () {
    $a = buildAppointments(new DemoRandom(20260908), '2026-09-08');
    $b = buildAppointments(new DemoRandom(20260908), '2026-09-08');
    assertSame($a, $b);
});

test('Auftrittstitel passen zum Monat des Termins', function () {
    // Ein "Adventsstaendchen" im Juli faellt auf dem Screenshot der
    // Terminuebersicht sofort auf. Der Titel muss aus dem Datum folgen,
    // nicht aus der Position in einer Liste.
    foreach ([20260908, 1, 77, 4711] as $seed) {
        foreach (buildAppointments(new DemoRandom($seed), '2026-09-08') as $a) {
            if ($a['type_id'] !== 3) {
                continue;
            }
            $month = (int) date('n', strtotime($a['date']));
            assertSame(DEMO_PERFORMANCE_TITLES[$month], $a['title'], "{$a['date']} traegt '{$a['title']}'");
        }
    }
});

// ---- Anwesenheiten -------------------------------------------------------

test('buildRecords erzeugt nur Eintraege zu vergangenen Terminen', function () {
    $r      = new DemoRandom(20260908);
    $m      = buildMembers($r, '2026-09-08');
    $appts  = buildAppointments($r, '2026-09-08');
    $recs   = buildRecords($r, $m['members'], $m['assignments'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    $byId   = [];
    foreach ($appts as $a) {
        $byId[$a['appointment_id']] = $a['date'];
    }
    foreach ($recs as $rec) {
        assertTrue($byId[$rec['appointment_id']] <= '2026-09-08', 'Anwesenheit an einem Zukunftstermin');
    }
});

test('buildRecords erzeugt keine Anwesenheit ausserhalb der Gruppenbindung', function () {
    $r     = new DemoRandom(20260908);
    $m     = buildMembers($r, '2026-09-08');
    $appts = buildAppointments($r, '2026-09-08');
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $appts, buildAppointmentTypeGroups(), '2026-09-08');

    $groupsOf = [];
    foreach ($m['assignments'] as $a) {
        $groupsOf[$a['member_id']][] = $a['group_id'];
    }
    $typeOf = [];
    foreach ($appts as $a) {
        $typeOf[$a['appointment_id']] = $a['type_id'];
    }
    $groupsForType = [];
    foreach (buildAppointmentTypeGroups() as $l) {
        $groupsForType[$l['type_id']][] = $l['group_id'];
    }

    foreach ($recs as $rec) {
        $allowed = $groupsForType[$typeOf[$rec['appointment_id']]];
        $mine    = $groupsOf[$rec['member_id']];
        assertTrue(count(array_intersect($allowed, $mine)) > 0, "Mitglied {$rec['member_id']} gehoert nicht zum Termin");
    }
});

test('buildRecords streut die Ankunftszeit um den Terminbeginn', function () {
    $r      = new DemoRandom(20260908);
    $m      = buildMembers($r, '2026-09-08');
    $appts  = buildAppointments($r, '2026-09-08');
    $recs   = buildRecords($r, $m['members'], $m['assignments'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    $startOf = [];
    foreach ($appts as $a) {
        $startOf[$a['appointment_id']] = $a['date'] . ' ' . $a['start_time'];
    }
    $offsets = [];
    foreach ($recs as $rec) {
        $offsets[] = (strtotime($rec['arrival_time']) - strtotime($startOf[$rec['appointment_id']])) / 60;
    }
    assertTrue(min($offsets) <= -5, 'niemand kommt zu frueh — Streuung fehlt');
    assertTrue(max($offsets) >= 15, 'niemand kommt spaet — Streuung fehlt');
    assertTrue(max($offsets) <= 40, 'Ausreisser ueber 40 Minuten');
});

test('buildRecords nutzt vier verschiedene Check-in-Quellen', function () {
    $r       = new DemoRandom(20260908);
    $m       = buildMembers($r, '2026-09-08');
    $appts   = buildAppointments($r, '2026-09-08');
    $recs    = buildRecords($r, $m['members'], $m['assignments'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    $sources = array_unique(array_map(fn ($x) => $x['checkin_source'], $recs));
    sort($sources);
    assertSame(['admin', 'auto_checkin', 'station_pin', 'user_totp'], array_values($sources));
});

test('Station-Anwesenheiten tragen den Stationsnamen als Ort', function () {
    $r     = new DemoRandom(20260908);
    $m     = buildMembers($r, '2026-09-08');
    $appts = buildAppointments($r, '2026-09-08');
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    foreach ($recs as $rec) {
        if ($rec['checkin_source'] === 'station_pin') {
            assertSame(DEMO_STATION_NAME, $rec['location_name']);
        }
    }
});

test('buildRecords erzeugt genug Eintraege fuer eine aussagekraeftige Statistik', function () {
    $r     = new DemoRandom(20260908);
    $m     = buildMembers($r, '2026-09-08');
    $appts = buildAppointments($r, '2026-09-08');
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    assertTrue(count($recs) > 1500, 'zu wenige Anwesenheiten: ' . count($recs));
});
