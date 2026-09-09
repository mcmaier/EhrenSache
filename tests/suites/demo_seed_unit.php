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
    $recs   = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
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
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');

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
    $recs   = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
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
    $recs    = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    $sources = array_unique(array_map(fn ($x) => $x['checkin_source'], $recs));
    sort($sources);
    assertSame(['admin', 'auto_checkin', 'station_pin', 'user_totp'], array_values($sources));
});

test('Station-Anwesenheiten tragen den Stationsnamen als Ort', function () {
    $r     = new DemoRandom(20260908);
    $m     = buildMembers($r, '2026-09-08');
    $appts = buildAppointments($r, '2026-09-08');
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
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
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    assertTrue(count($recs) > 1500, 'zu wenige Anwesenheiten: ' . count($recs));
});

test('buildRecords ist bei gleichem Saat reproduzierbar', function () {
    $r1 = new DemoRandom(20260908);
    $m1 = buildMembers($r1, '2026-09-08');
    $a1 = buildAppointments($r1, '2026-09-08');
    $x1 = buildRecords($r1, $m1['members'], $m1['assignments'], $m1['membership_dates'], $a1, buildAppointmentTypeGroups(), '2026-09-08');

    $r2 = new DemoRandom(20260908);
    $m2 = buildMembers($r2, '2026-09-08');
    $a2 = buildAppointments($r2, '2026-09-08');
    $x2 = buildRecords($r2, $m2['members'], $m2['assignments'], $m2['membership_dates'], $a2, buildAppointmentTypeGroups(), '2026-09-08');

    assertSame($x1, $x2);
});

test('buildRecords erzeugt keine Anwesenheit ausserhalb des Mitgliedschaftszeitraums', function () {
    // Das Mitgliedsprofil nennt das Eintrittsdatum. Steht in der
    // Anwesenheitsliste ein frueherer Termin, faellt das auf dem Screenshot auf.
    // Ueber mehrere Saaten geprueft: Beim Vorgabesaat allein waren es 14 Faelle,
    // ueber 50 Saaten 370.
    foreach ([20260908, 1, 42, 151] as $seed) {
        $r      = new DemoRandom($seed);
        $m      = buildMembers($r, '2026-09-08');
        $appts  = buildAppointments($r, '2026-09-08');
        $recs   = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');

        $period = [];
        foreach ($m['membership_dates'] as $d) {
            $period[$d['member_id']] = $d;
        }
        $dateOf = [];
        foreach ($appts as $a) {
            $dateOf[$a['appointment_id']] = $a['date'];
        }

        foreach ($recs as $rec) {
            $p    = $period[$rec['member_id']];
            $date = $dateOf[$rec['appointment_id']];
            assertTrue($date >= $p['start_date'], "Saat {$seed}: Mitglied {$rec['member_id']} am {$date}, Eintritt {$p['start_date']}");
            if ($p['end_date'] !== null) {
                assertTrue($date <= $p['end_date'], "Saat {$seed}: Mitglied {$rec['member_id']} am {$date}, Austritt {$p['end_date']}");
            }
        }
    }
});

// ---- Antraege ------------------------------------------------------------

test('buildExceptions liefert 25 Antraege', function () {
    $r     = new DemoRandom(20260908);
    $m     = buildMembers($r, '2026-09-08');
    $appts = buildAppointments($r, '2026-09-08');
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    assertSame(25, count(buildExceptions($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), $recs, '2026-09-08')));
});

test('buildExceptions enthaelt mindestens vier offene Antraege', function () {
    $r       = new DemoRandom(20260908);
    $m       = buildMembers($r, '2026-09-08');
    $appts   = buildAppointments($r, '2026-09-08');
    $recs    = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    $pending = array_filter(buildExceptions($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), $recs, '2026-09-08'), fn ($e) => $e['status'] === 'pending');
    assertTrue(count($pending) >= 4, 'zu wenige offene Antraege: ' . count($pending));
});

test('buildExceptions nutzt beide Antragsarten', function () {
    $r     = new DemoRandom(20260908);
    $m     = buildMembers($r, '2026-09-08');
    $appts = buildAppointments($r, '2026-09-08');
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    $kinds = array_unique(array_map(fn ($e) => $e['exception_type'], buildExceptions($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), $recs, '2026-09-08')));
    sort($kinds);
    assertSame(['absence', 'time_correction'], array_values($kinds));
});

test('Zeitkorrekturen tragen eine gewuenschte Ankunftszeit, Abwesenheiten nicht', function () {
    $r     = new DemoRandom(20260908);
    $m     = buildMembers($r, '2026-09-08');
    $appts = buildAppointments($r, '2026-09-08');
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    foreach (buildExceptions($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), $recs, '2026-09-08') as $e) {
        if ($e['exception_type'] === 'time_correction') {
            assertTrue($e['requested_arrival_time'] !== null, 'Zeitkorrektur ohne Zeit');
        } else {
            assertSame(null, $e['requested_arrival_time']);
        }
    }
});

test('entschiedene Antraege tragen Entscheider und Zeitpunkt, offene nicht', function () {
    $r     = new DemoRandom(20260908);
    $m     = buildMembers($r, '2026-09-08');
    $appts = buildAppointments($r, '2026-09-08');
    $recs  = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
    foreach (buildExceptions($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), $recs, '2026-09-08') as $e) {
        if ($e['status'] === 'pending') {
            assertSame(null, $e['approved_by']);
            assertSame(null, $e['approved_at']);
        } else {
            assertTrue($e['approved_by'] !== null, 'entschiedener Antrag ohne Entscheider');
            assertTrue($e['approved_at'] !== null, 'entschiedener Antrag ohne Zeitpunkt');
        }
    }
});

test('Antraege liegen im Mitgliedschaftszeitraum des Mitglieds', function () {
    // Gemessen vor der Behebung: 2 von 25 beim Vorgabesaat, 113 von 1250
    // ueber 50 Saaten. Ein Antrag fuer einen Termin, an dem das Mitglied noch
    // nicht im Verein war.
    foreach ([20260908, 1, 42, 151] as $seed) {
        $r      = new DemoRandom($seed);
        $m      = buildMembers($r, '2026-09-08');
        $appts  = buildAppointments($r, '2026-09-08');
        $recs   = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
        $exc    = buildExceptions($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), $recs, '2026-09-08');

        $period = [];
        foreach ($m['membership_dates'] as $d) {
            $period[$d['member_id']] = $d;
        }
        $dateOf = [];
        foreach ($appts as $a) {
            $dateOf[$a['appointment_id']] = $a['date'];
        }

        foreach ($exc as $e) {
            $p    = $period[$e['member_id']];
            $date = $dateOf[$e['appointment_id']];
            assertTrue($date >= $p['start_date'], "Saat {$seed}: Antrag am {$date}, Eintritt {$p['start_date']}");
            if ($p['end_date'] !== null) {
                assertTrue($date <= $p['end_date'], "Saat {$seed}: Antrag am {$date}, Austritt {$p['end_date']}");
            }
        }
    }
});

test('Entschuldigungen betreffen nur Termine ohne erfasste Anwesenheit', function () {
    // Gemessen vor der Behebung: 9 von 17 Entschuldigungen beim Vorgabesaat
    // betrafen Termine, an denen das Mitglied anwesend war.
    foreach ([20260908, 1, 42, 151] as $seed) {
        $r     = new DemoRandom($seed);
        $m     = buildMembers($r, '2026-09-08');
        $appts = buildAppointments($r, '2026-09-08');
        $recs  = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
        $exc   = buildExceptions($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), $recs, '2026-09-08');

        $present = [];
        foreach ($recs as $rec) {
            $present[$rec['member_id'] . '-' . $rec['appointment_id']] = true;
        }

        foreach ($exc as $e) {
            $key = $e['member_id'] . '-' . $e['appointment_id'];
            if ($e['exception_type'] === 'absence') {
                assertTrue(!isset($present[$key]), "Saat {$seed}: Entschuldigung trotz Anwesenheit ({$key})");
            } else {
                assertTrue(isset($present[$key]), "Saat {$seed}: Zeitkorrektur ohne Anwesenheit ({$key})");
            }
        }
    }
});

test('kein Mitglied stellt zweimal denselben Antrag', function () {
    foreach ([20260908, 1, 42, 151] as $seed) {
        $r     = new DemoRandom($seed);
        $m     = buildMembers($r, '2026-09-08');
        $appts = buildAppointments($r, '2026-09-08');
        $recs  = buildRecords($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), '2026-09-08');
        $exc   = buildExceptions($r, $m['members'], $m['assignments'], $m['membership_dates'], $appts, buildAppointmentTypeGroups(), $recs, '2026-09-08');

        $keys = array_map(fn ($e) => $e['member_id'] . '-' . $e['appointment_id'], $exc);
        assertSame(count($keys), count(array_unique($keys)), "Saat {$seed}: doppelter Antrag");
    }
});

// ---- Arbeitszeiten ---------------------------------------------------------
// activity_type_groups entscheidet, wer eine Taetigkeitsart ueberhaupt sieht
// und buchen darf (siehe activity_types.php und memberMayUseActivity() in
// work_sessions.php). Eine Taetigkeit ohne Gruppenbindung waere im Bestand
// vorhanden, aber in der Oberflaeche fuer niemanden erreichbar.

/**
 * Erzeugt einmalig den vollstaendigen Arbeitszeit-Bestand fuer einen Saat, samt
 * der Stammdaten, von denen er abhaengt. Buendelt den wiederkehrenden Aufbau
 * fuer die folgenden Tests.
 */
function demoBuildWorkSessionsBundle(int $seed, string $referenceDate = '2026-09-08', string $referenceTime = '12:00:00'): array
{
    $r              = new DemoRandom($seed);
    $m              = buildMembers($r, $referenceDate);
    $appts          = buildAppointments($r, $referenceDate);
    $typeGroups     = buildAppointmentTypeGroups();
    $activityGroups = buildActivityTypeGroups();
    $ws             = buildWorkSessions(
        $r,
        $m['members'],
        $m['assignments'],
        $m['membership_dates'],
        $appts,
        $typeGroups,
        $activityGroups,
        $referenceDate,
        $referenceTime
    );

    return [
        'members'         => $m['members'],
        'assignments'     => $m['assignments'],
        'membershipDates' => $m['membership_dates'],
        'appointments'    => $appts,
        'typeGroups'      => $typeGroups,
        'activityGroups'  => $activityGroups,
        'sessions'        => $ws['sessions'],
        'log'             => $ws['log'],
    ];
}

test('buildActivityTypeGroups bindet jede Taetigkeit an mindestens eine Gruppe und jede Gruppe an mindestens eine Taetigkeit', function () {
    $links = buildActivityTypeGroups();

    $groupsForActivity = [];
    $activitiesForGroup = [];
    foreach ($links as $l) {
        $groupsForActivity[$l['activity_id']][]  = $l['group_id'];
        $activitiesForGroup[$l['group_id']][]    = $l['activity_id'];
    }

    foreach (buildActivityTypes() as $a) {
        assertTrue(!empty($groupsForActivity[$a['activity_id']]), "Taetigkeit {$a['activity_id']} ohne Gruppe");
    }
    foreach (buildGroups() as $g) {
        assertTrue(!empty($activitiesForGroup[$g['group_id']]), "Gruppe {$g['group_id']} ohne Taetigkeit");
    }
});

test('buildWorkSessions liefert 120 Sitzungen', function () {
    $b = demoBuildWorkSessionsBundle(20260908);
    assertSame(120, count($b['sessions']));
});

test('buildWorkSessions verteilt den Status ueber den Zaehler: confirmed >= 100, submitted = 10, rejected = 4', function () {
    $b       = demoBuildWorkSessionsBundle(20260908);
    $counts  = ['confirmed' => 0, 'submitted' => 0, 'rejected' => 0];
    foreach ($b['sessions'] as $s) {
        $counts[$s['status']]++;
    }
    assertTrue($counts['confirmed'] >= 100, 'zu wenige confirmed: ' . $counts['confirmed']);
    assertSame(10, $counts['submitted']);
    assertSame(4, $counts['rejected']);
});

test('genau eine Sitzung hat kein end_time, und sie gehoert Mitglied 1', function () {
    $b       = demoBuildWorkSessionsBundle(20260908);
    $running = array_values(array_filter($b['sessions'], fn ($s) => $s['end_time'] === null));
    assertSame(1, count($running));
    assertSame(1, $running[0]['member_id']);
});

test('die laufende Sitzung nutzt eine Taetigkeit ohne Nachweispflicht', function () {
    // Eine Taetigkeit mit verification start/start_end verlangt beim Beenden
    // einen TOTP-Code. Eine dauerhaft offene Sitzung mit so einer Taetigkeit
    // bekommt weder die Testsuite noch ein Besucher der Demo wieder zu.
    $verificationOf = [];
    foreach (buildActivityTypes() as $a) {
        $verificationOf[$a['activity_id']] = $a['verification'];
    }
    foreach ([20260908, 1, 42, 151] as $seed) {
        $b       = demoBuildWorkSessionsBundle($seed);
        $open    = array_values(array_filter($b['sessions'], fn ($s) => $s['end_time'] === null))[0];
        assertSame('none', $verificationOf[$open['activity_id']], "Saat {$seed}");
    }
});

// Faengt eine "laufende" Sitzung, die in Wahrheit noch nicht begonnen hat: In
// der PWA zeigt das eine negative Laufzeit, und stop() (Ende vor Beginn)
// weist die Sitzung ab, sodass sie sich gar nicht beenden laesst. Ueber
// mehrere Uhrzeiten geprueft, weil der Fehler nur auftrat, wenn der
// Generator vor der gewuerfelten Uhrzeit (bis 18:45) lief.
test('die laufende Sitzung beginnt vor dem Bezugszeitpunkt, ueber mehrere Uhrzeiten', function () {
    foreach (['00:30:00', '08:00:00', '23:59:00'] as $referenceTime) {
        $b       = demoBuildWorkSessionsBundle(20260908, '2026-09-08', $referenceTime);
        $running = array_values(array_filter($b['sessions'], fn ($s) => $s['end_time'] === null))[0];
        $reference = strtotime('2026-09-08 ' . $referenceTime);
        assertTrue(
            strtotime($running['start_time']) < $reference,
            "Uhrzeit {$referenceTime}: start_time {$running['start_time']} liegt nicht vor dem Bezugszeitpunkt"
        );
    }
});

test('die laufende Sitzung stammt aus der Quelle timer', function () {
    $b       = demoBuildWorkSessionsBundle(20260908);
    $running = array_values(array_filter($b['sessions'], fn ($s) => $s['end_time'] === null))[0];
    assertSame('timer', $running['source']);
});

test('der create-Eintrag der laufenden Sitzung stimmt mit ihrem Beginn ueberein', function () {
    $b       = demoBuildWorkSessionsBundle(20260908);
    $running = array_values(array_filter($b['sessions'], fn ($s) => $s['end_time'] === null))[0];
    $create  = array_values(array_filter(
        $b['log'],
        fn ($e) => $e['session_id'] === $running['session_id'] && $e['action'] === 'create'
    ))[0];
    assertSame($running['start_time'], $create['changed_at']);
});

test('hoechstens eine laufende Sitzung je Mitglied', function () {
    // work_sessions.active_member ist eine generierte Spalte mit UNIQUE-Index:
    // if(end_time is null, member_id, NULL). Zwei offene Sitzungen desselben
    // Mitglieds liefen daher beim Schreiben in einen Constraint-Fehler. Der
    // Plan darf sie gar nicht erst erzeugen.
    $open = [];
    foreach (demoBuildWorkSessionsBundle(20260908)['sessions'] as $row) {
        if ($row['end_time'] === null) {
            $open[] = $row['member_id'];
        }
    }
    assertSame(count($open), count(array_unique($open)));
});

test('beendete Sitzungen enden nach ihrem Beginn', function () {
    $b = demoBuildWorkSessionsBundle(20260908);
    foreach ($b['sessions'] as $s) {
        if ($s['end_time'] === null) {
            continue;
        }
        assertTrue(strtotime($s['end_time']) > strtotime($s['start_time']), "Sitzung {$s['session_id']}: end_time nicht nach start_time");
    }
});

test('Sitzungen der Quelle station tragen den Stationsnamen', function () {
    $b = demoBuildWorkSessionsBundle(20260908);
    foreach ($b['sessions'] as $s) {
        if ($s['source'] !== 'station') {
            continue;
        }
        assertSame(DEMO_STATION_NAME, $s['start_location_name']);
        if ($s['end_time'] !== null) {
            assertSame(DEMO_STATION_NAME, $s['end_location_name']);
        } else {
            assertSame(null, $s['end_location_name']);
        }
    }
});

test('buildWorkSessions nutzt alle drei Quellen', function () {
    $b       = demoBuildWorkSessionsBundle(20260908);
    $sources = array_unique(array_map(fn ($s) => $s['source'], $b['sessions']));
    sort($sources);
    assertSame(['manual', 'station', 'timer'], array_values($sources));
});

test('jede Sitzung nutzt eine Taetigkeit, die zu einer Gruppe des Mitglieds passt', function () {
    foreach ([20260908, 1, 42, 151] as $seed) {
        $b = demoBuildWorkSessionsBundle($seed);

        $groupsOf = [];
        foreach ($b['assignments'] as $a) {
            $groupsOf[$a['member_id']][] = $a['group_id'];
        }
        $groupsForActivity = [];
        foreach ($b['activityGroups'] as $l) {
            $groupsForActivity[$l['activity_id']][] = $l['group_id'];
        }

        foreach ($b['sessions'] as $s) {
            $allowed = $groupsForActivity[$s['activity_id']] ?? [];
            $mine    = $groupsOf[$s['member_id']] ?? [];
            assertTrue(
                count(array_intersect($allowed, $mine)) > 0,
                "Saat {$seed}: Sitzung {$s['session_id']}, Mitglied {$s['member_id']} darf Taetigkeit {$s['activity_id']} nicht nutzen"
            );
        }
    }
});

test('jede Sitzung liegt im Mitgliedschaftszeitraum ihres Mitglieds', function () {
    foreach ([20260908, 1, 42, 151] as $seed) {
        $b = demoBuildWorkSessionsBundle($seed);

        $period = [];
        foreach ($b['membershipDates'] as $d) {
            $period[$d['member_id']] = $d;
        }

        foreach ($b['sessions'] as $s) {
            $p    = $period[$s['member_id']];
            $date = substr($s['start_time'], 0, 10);
            assertTrue($date >= $p['start_date'], "Saat {$seed}: Sitzung {$s['session_id']} am {$date} vor Eintritt {$p['start_date']}");
            if ($p['end_date'] !== null) {
                assertTrue($date <= $p['end_date'], "Saat {$seed}: Sitzung {$s['session_id']} am {$date} nach Austritt {$p['end_date']}");
            }
        }
    }
});

test('gesetzte appointment_id gehoert zu einem Termin, zu dem das Mitglied erwartet wurde', function () {
    foreach ([20260908, 1, 42, 151] as $seed) {
        $b = demoBuildWorkSessionsBundle($seed);

        $expected = demoExpectedPairs(
            $b['members'],
            $b['assignments'],
            $b['membershipDates'],
            $b['appointments'],
            $b['typeGroups'],
            '2026-09-08'
        );
        $expectedSet = [];
        foreach ($expected as $pair) {
            $expectedSet[$pair['member_id'] . '-' . $pair['appointment_id']] = true;
        }

        foreach ($b['sessions'] as $s) {
            if ($s['appointment_id'] === null) {
                continue;
            }
            $key = $s['member_id'] . '-' . $s['appointment_id'];
            assertTrue(isset($expectedSet[$key]), "Saat {$seed}: Sitzung {$s['session_id']} haengt an unerwartetem Termin");
        }
    }
});

test('jede Sitzung hat einen create-Eintrag, confirmed zusaetzlich approve, rejected zusaetzlich reject', function () {
    $b = demoBuildWorkSessionsBundle(20260908);

    $actionsBySession = [];
    foreach ($b['log'] as $entry) {
        $actionsBySession[$entry['session_id']][] = $entry['action'];
    }

    foreach ($b['sessions'] as $s) {
        $actions = $actionsBySession[$s['session_id']] ?? [];
        assertTrue(in_array('create', $actions, true), "Sitzung {$s['session_id']} ohne create-Eintrag");

        if ($s['status'] === 'confirmed' && $s['end_time'] !== null) {
            assertTrue(in_array('approve', $actions, true), "Sitzung {$s['session_id']} (confirmed) ohne approve-Eintrag");
        }
        if ($s['status'] === 'rejected') {
            assertTrue(in_array('reject', $actions, true), "Sitzung {$s['session_id']} (rejected) ohne reject-Eintrag");
            $reject = array_values(array_filter($b['log'], fn ($e) => $e['session_id'] === $s['session_id'] && $e['action'] === 'reject'))[0];
            assertSame('Doppelte Erfassung', $reject['changes']);
        }
        if ($s['end_time'] === null) {
            assertSame(['create'], $actions, "laufende Sitzung {$s['session_id']} hat mehr als nur create");
        }
    }
});

test('der Anteil der Sitzungen mit Terminbezug liegt zwischen 20% und 50%', function () {
    $b        = demoBuildWorkSessionsBundle(20260908);
    $withAppt = array_filter($b['sessions'], fn ($s) => $s['appointment_id'] !== null);
    $share    = count($withAppt) / count($b['sessions']);
    assertTrue($share >= 0.20 && $share <= 0.50, "Anteil mit Terminbezug: {$share}");
});

test('buildWorkSessions ist bei gleichem Saat reproduzierbar', function () {
    $a = demoBuildWorkSessionsBundle(20260908);
    $b = demoBuildWorkSessionsBundle(20260908);
    assertSame($a['sessions'], $b['sessions']);
    assertSame($a['log'], $b['log']);
});

// ---- Gesamtplan ----------------------------------------------------------
// buildDemoPlan() fuegt alle Bausteine zu einem Bestand zusammen. Die
// Reihenfolge der Aufrufe darin ist fest (siehe Kommentar ueber der Funktion) —
// hier wird nur das Gesamtergebnis geprueft, nicht die Reihenfolge selbst.

test('buildDemoPlan liefert genau die erwarteten Abschnitte', function () {
    $plan = buildDemoPlan(20260908, '2026-09-08');
    $keys = array_keys($plan);
    sort($keys);
    assertSame([
        'activity_type_groups', 'activity_types', 'appointment_type_groups', 'appointment_types',
        'appointments', 'exceptions', 'groups', 'member_group_assignments', 'members',
        'membership_dates', 'records', 'settings', 'users', 'work_session_log', 'work_sessions',
    ], $keys);
});

test('buildDemoPlan ist bei gleichem Saat und Stichtag vollstaendig reproduzierbar', function () {
    $a = buildDemoPlan(20260908, '2026-09-08');
    $b = buildDemoPlan(20260908, '2026-09-08');
    assertSame($a, $b);
});

test('buildDemoPlan liefert bei anderem Saat einen anderen Bestand', function () {
    $a = buildDemoPlan(1, '2026-09-08');
    $b = buildDemoPlan(2, '2026-09-08');
    assertTrue($a['records'] !== $b['records'], 'Anwesenheiten sind bei verschiedenen Saaten identisch');
});

test('buildSettings setzt Vereinsname, leeres Logo und aktivierte Arbeitszeit/Station-PIN', function () {
    $settings = buildDemoPlan(20260908, '2026-09-08')['settings'];
    assertSame(DEMO_ORG_NAME, $settings['organization_name']);
    assertSame('', $settings['organization_logo']);
    assertSame('1', $settings['worktime_enabled']);
    assertSame('1', $settings['station_pin_enabled']);
});

test('buildUsers liefert vier Konten und zwei Geraete', function () {
    $users   = buildDemoPlan(20260908, '2026-09-08')['users'];
    $regular = array_filter($users, fn ($u) => $u['role'] !== 'device');
    $devices = array_filter($users, fn ($u) => $u['role'] === 'device');
    assertSame(4, count($regular));
    assertSame(2, count($devices));
});

test('Testkonto und Demokonto haengen an verschiedenen Mitgliedern', function () {
    // Teilten sie sich ein Mitglied, beendete jeder Testlauf die laufende
    // Sitzung, die das PWA-Bild zeigen soll -- und die Timer-Tests scheiterten
    // an einer Sitzung, die sie nicht erwarten. Genau das war am 2026-09-09 der
    // Fall: 16 rote Tests nach dem ersten Generatorlauf.
    $plan     = buildDemoPlan(20260908, '2026-09-08');
    $verknuepft = array_values(array_filter($plan['users'], fn ($u) => $u['member_id'] !== null));

    assertSame(2, count($verknuepft), 'zwei Mitgliedskonten erwartet');
    assertTrue(
        $verknuepft[0]['member_id'] !== $verknuepft[1]['member_id'],
        'beide Konten haengen am selben Mitglied'
    );

    // Das Konto der Testsuite (tests/config.php, Rolle "user") ist user2@ und
    // darf kein Mitglied mit laufender Sitzung tragen.
    $test = array_values(array_filter($plan['users'], fn ($u) => $u['email'] === 'user2@musterhausen.example'))[0];
    foreach ($plan['work_sessions'] as $s) {
        if ($s['end_time'] === null) {
            assertTrue(
                $s['member_id'] !== $test['member_id'],
                'die laufende Sitzung gehoert dem Testkonto'
            );
        }
    }
});

test('buildUsers legt genau einen Kiosk mit dem Stationsnamen an', function () {
    $users  = buildDemoPlan(20260908, '2026-09-08')['users'];
    $kiosks = array_values(array_filter($users, fn ($u) => $u['device_type'] === 'kiosk'));
    assertSame(1, count($kiosks));
    assertSame(DEMO_STATION_NAME, $kiosks[0]['device_name']);
});

test('das Demokonto haengt an Mitglied 1, das Testkonto an einem anderen', function () {
    // user@ ist das Konto, mit dem die PWA fotografiert wird — es muss an dem
    // Mitglied haengen, das die laufende Sitzung traegt. user2@ gehoert der
    // Testsuite und darf das nicht.
    $users = buildDemoPlan(20260908, '2026-09-08')['users'];
    $mit   = [];
    foreach ($users as $u) {
        if ($u['role'] === 'user') {
            $mit[$u['email']] = $u['member_id'];
        }
    }
    assertSame(2, count($mit));
    assertSame(1, $mit['user@musterhausen.example']);
    assertSame(2, $mit['user2@musterhausen.example']);
});

test('alle Zeilen von users tragen dieselben Schluessel in derselben Reihenfolge', function () {
    // Die Schreibschicht leitet die Spaltenliste aus der ersten Zeile ab — eine
    // abweichende Zeile wuerde dort stillschweigend falsche Spalten erzeugen.
    $users        = buildDemoPlan(20260908, '2026-09-08')['users'];
    $expectedKeys = array_keys($users[0]);
    foreach ($users as $u) {
        assertSame($expectedKeys, array_keys($u), 'Schluessel weichen ab');
    }
});

test('Fremdschluessel im Gesamtplan zeigen ueberall auf vorhandene Zeilen', function () {
    foreach ([20260908, 1, 42] as $seed) {
        $plan = buildDemoPlan($seed, '2026-09-08');

        $memberIds      = array_column($plan['members'], 'member_id');
        $appointmentIds = array_column($plan['appointments'], 'appointment_id');
        $activityIds    = array_column($plan['activity_types'], 'activity_id');
        $groupIds       = array_column($plan['groups'], 'group_id');
        $sessionIds     = array_column($plan['work_sessions'], 'session_id');

        foreach (['records', 'exceptions', 'work_sessions', 'member_group_assignments'] as $section) {
            foreach ($plan[$section] as $row) {
                assertTrue(in_array($row['member_id'], $memberIds, true), "Saat {$seed}, {$section}: member_id {$row['member_id']} unbekannt");
            }
        }
        foreach (['records', 'exceptions'] as $section) {
            foreach ($plan[$section] as $row) {
                assertTrue(in_array($row['appointment_id'], $appointmentIds, true), "Saat {$seed}, {$section}: appointment_id {$row['appointment_id']} unbekannt");
            }
        }
        foreach ($plan['work_sessions'] as $row) {
            if ($row['appointment_id'] !== null) {
                assertTrue(in_array($row['appointment_id'], $appointmentIds, true), "Saat {$seed}: work_sessions.appointment_id {$row['appointment_id']} unbekannt");
            }
            assertTrue(in_array($row['activity_id'], $activityIds, true), "Saat {$seed}: work_sessions.activity_id {$row['activity_id']} unbekannt");
        }
        foreach (['member_group_assignments', 'appointment_type_groups', 'activity_type_groups'] as $section) {
            foreach ($plan[$section] as $row) {
                assertTrue(in_array($row['group_id'], $groupIds, true), "Saat {$seed}, {$section}: group_id {$row['group_id']} unbekannt");
            }
        }
        foreach ($plan['work_session_log'] as $row) {
            assertTrue(in_array($row['session_id'], $sessionIds, true), "Saat {$seed}: work_session_log.session_id {$row['session_id']} unbekannt");
        }
    }
});
