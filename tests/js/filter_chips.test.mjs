/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Logiktests der Status-Chips (Spec 2026-09-22). Aufruf ueber
// tests/suites/filter_chips_unit.php oder direkt: node --test tests/js/filter_chips.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    countChips, filterByChip, resolveActiveChip, localTodayIso, appointmentTimeChips,
    CHIPS_EXCEPTIONS, CHIPS_WORKTIME, CHIPS_MEMBERS, CHIPS_USERS, CHIPS_DEVICES,
    CHIPS_RECORDS_ALL, CHIPS_RECORDS_LIST, CHIPS_RECORDS_MEMBER, CHIPS_STATISTICS, attendanceState,
    groupChips, CHIPS_APPOINTMENT_TYPES, CHIPS_ACTIVITY_TYPES, setResetEnabled
} from '../../public/js/modules/filter_chips.js';

/** Summe aller Chips ausser "Alle" muss "Alle" ergeben (gegenseitig ausschliessend, vollstaendig). */
function assertPartition(defs, items, name) {
    const counts = countChips(items, defs);
    const sum = defs.filter(d => d.match).reduce((s, d) => s + counts[d.key], 0);
    assert.equal(sum, counts.all, `${name}: Chips ergeben zusammen nicht "Alle"`);
    for (const item of items) {
        const hits = defs.filter(d => d.match && d.match(item)).length;
        assert.equal(hits, 1, `${name}: Eintrag ${JSON.stringify(item)} passt auf ${hits} Chips`);
    }
}

test('countChips zaehlt je Chip, "Alle" ist die Laenge', () => {
    const items = [{ status: 'pending' }, { status: 'pending' }, { status: 'approved' }];
    assert.deepEqual(countChips(items, CHIPS_EXCEPTIONS), { all: 3, pending: 2, approved: 1, rejected: 0 });
});

test('filterByChip filtert nach aktivem Chip, "Alle" laesst alles durch', () => {
    const items = [{ status: 'pending' }, { status: 'rejected' }];
    assert.deepEqual(filterByChip(items, CHIPS_EXCEPTIONS, 'rejected'), [{ status: 'rejected' }]);
    assert.equal(filterByChip(items, CHIPS_EXCEPTIONS, 'all').length, 2);
});

test('filterByChip mit unbekanntem Schluessel laesst alles durch', () => {
    assert.equal(filterByChip([{ status: 'x' }], CHIPS_EXCEPTIONS, 'gibtsnicht').length, 1);
});

test('resolveActiveChip faellt auf den Ersatz zurueck, wenn der Chip im Satz fehlt', () => {
    assert.equal(resolveActiveChip(CHIPS_RECORDS_ALL, 'missing', 'all'), 'all');
    assert.equal(resolveActiveChip(CHIPS_RECORDS_LIST, 'missing', 'all'), 'missing');
});

test('Antraege: Partition', () => {
    assertPartition(CHIPS_EXCEPTIONS,
        [{ status: 'pending' }, { status: 'approved' }, { status: 'rejected' }], 'Antraege');
});

test('Arbeitszeit: laufende Sitzung zaehlt nur unter "Laeuft", nicht unter "Wartet"', () => {
    const items = [
        { status: 'submitted', end_time: null },
        { status: 'submitted', end_time: '2026-09-01 12:00:00' },
        { status: 'confirmed', end_time: '2026-09-01 12:00:00' },
        { status: 'rejected',  end_time: '2026-09-01 12:00:00' },
    ];
    assert.deepEqual(countChips(items, CHIPS_WORKTIME),
        { all: 4, running: 1, submitted: 1, confirmed: 1, rejected: 1 });
    assertPartition(CHIPS_WORKTIME, items, 'Arbeitszeit');
});

test('Einzelne Chips koennen anzeigend sein, auch wenn der Satz klickbar ist', () => {
    const defs = [...CHIPS_WORKTIME, { key: 'hours', label: 'Bestätigte Stunden', static: true }];
    assert.equal(defs.filter(d => d.static).length, 1);
    // Der Anzeige-Chip hat kein match und faellt damit aus der Partition heraus
    assert.ok(!defs.find(d => d.key === 'hours').match);
});

test('Mitglieder: Aktiv/Inaktiv, auch mit Zahl oder Text aus der API', () => {
    const items = [{ is_active_in_period: true }, { is_active_in_period: 1 }, { is_active_in_period: '0' }, { is_active_in_period: false }];
    assert.deepEqual(countChips(items, CHIPS_MEMBERS), { all: 4, active: 2, inactive: 2 });
    assertPartition(CHIPS_MEMBERS, items, 'Mitglieder');
});

test('Benutzer: Partition', () => {
    assertPartition(CHIPS_USERS,
        [{ account_status: 'pending' }, { account_status: 'active' }, { account_status: 'suspended' }], 'Benutzer');
});

test('Geraete: "1" als Text zaehlt als aktiv', () => {
    const items = [{ is_active: 1 }, { is_active: '1' }, { is_active: true }, { is_active: 0 }, { is_active: '0' }];
    assert.deepEqual(countChips(items, CHIPS_DEVICES), { all: 5, active: 3, inactive: 2 });
    assertPartition(CHIPS_DEVICES, items, 'Geraete');
});

test('Anwesenheit: "Fehlend" nur im Listensatz, null zaehlt als fehlend', () => {
    assert.ok(!CHIPS_RECORDS_ALL.some(d => d.key === 'missing'));
    const items = [{ status: 'present' }, { status: 'excused' }, { status: null }];
    assert.deepEqual(countChips(items, CHIPS_RECORDS_LIST), { all: 3, present: 1, excused: 1, missing: 1, upcoming: 0 });
    assertPartition(CHIPS_RECORDS_LIST, items, 'Anwesenheitsliste');
    assertPartition(CHIPS_RECORDS_ALL, [{ status: 'present' }, { status: 'excused' }], 'Anwesenheit');
});

test('Termine: heute zaehlt als kommend, gestern als vergangen', () => {
    const defs = appointmentTimeChips('2026-09-22');
    const items = [{ date: '2026-09-21' }, { date: '2026-09-22' }, { date: '2026-12-01' }];
    assert.deepEqual(countChips(items, defs), { all: 3, past: 1, upcoming: 2 });
    assertPartition(defs, items, 'Termine');
    // "Alle" ist hervorgehoben, damit die Zeile nicht durchgehend grau wirkt
    // (Spec-Nachtrag 23.09.2026, zweite Sichtung).
    assert.deepEqual(defs.map(d => d.variant), ['info', undefined, undefined]);
});

test('localTodayIso nutzt die lokale Zeit', () => {
    assert.equal(localTodayIso(new Date(2026, 0, 5, 23, 30)), '2026-01-05');
});

test('Statistik-Chips sind reine Anzeige und bilden keine Partition', () => {
    // Sechs statt sieben: "Durchschnitt" steht seit der zweiten Sichtung
    // (23.09.2026) je Gruppe im Rumpf, nicht mehr in der Kopfzeile.
    assert.equal(CHIPS_STATISTICS.length, 6);
    assert.ok(CHIPS_STATISTICS.every(d => !d.match), 'Statistik-Chips duerfen nicht filtern');
    assert.deepEqual(CHIPS_STATISTICS.map(d => d.key),
        ['appointments', 'present', 'excused', 'unexcused', 'punctuality', 'reliability']);
    assert.deepEqual(CHIPS_STATISTICS.map(d => d.variant),
        [undefined, 'ok', 'pending', 'danger', 'info', 'info']);
    assert.ok(!CHIPS_STATISTICS.some(d => d.key === 'average'),
        'Der Durchschnitt gehoert nicht mehr in die Kopfzeile');
});

test('Gruppen: Haupt- und Untergruppen, Wort aus den Einstellungen', () => {
    const defs = groupChips('Register');
    assert.deepEqual(defs.map(d => d.label), ['Alle', 'Hauptgruppen', 'Register']);
    // 'ok' passend zum gruenen Abzeichen derselben Zeile in der Tabelle (Spec).
    assert.equal(defs.find(d => d.key === 'sub').variant, 'ok');
    const items = [{ is_subgroup: 1 }, { is_subgroup: '1' }, { is_subgroup: 0 }, { is_subgroup: null }];
    assert.deepEqual(countChips(items, defs), { all: 4, main: 2, sub: 2 });
    assertPartition(defs, items, 'Gruppen');
});

test('Terminarten: mit und ohne Rueckmeldung', () => {
    const items = [
        { responses_enabled: 1 }, { responses_enabled: '1' }, { responses_enabled: '0' },
        { responses_enabled: 0 }, { responses_enabled: null },
    ];
    assert.deepEqual(countChips(items, CHIPS_APPOINTMENT_TYPES), { all: 5, responses: 2, plain: 3 });
    assertPartition(CHIPS_APPOINTMENT_TYPES, items, 'Terminarten');
});

test('Taetigkeitsarten: aktiv und ausgemustert', () => {
    const items = [{ is_active: 1 }, { is_active: '1' }, { is_active: 0 }, { is_active: null }];
    assert.deepEqual(countChips(items, CHIPS_ACTIVITY_TYPES), { all: 4, active: 2, inactive: 2 });
    assertPartition(CHIPS_ACTIVITY_TYPES, items, 'Taetigkeitsarten');
});

test('setResetEnabled graut den Knopf aus, statt ihn zu verstecken', () => {
    const knopf = { disabled: false, hidden: false };
    setResetEnabled(knopf, false);
    assert.equal(knopf.disabled, true, 'Ruhezustand: ausgegraut');
    assert.equal(knopf.hidden, false, 'Der Knopf darf nicht verschwinden');
    setResetEnabled(knopf, true);
    assert.equal(knopf.disabled, false);
});

test('setResetEnabled vertraegt ein fehlendes Element', () => {
    setResetEnabled(null, true);
});

// ---- OI-89: "Kommend" statt "Fehlend" ---------------------------------------

test('attendanceState: ohne Eintrag vor Beginn "Kommend", danach "Fehlend"', () => {
    assert.equal(attendanceState({ status: null, appointment_started: 0 }), 'upcoming');
    assert.equal(attendanceState({ status: null, appointment_started: 1 }), 'missing');
    assert.equal(attendanceState({ status: null, appointment_started: '0' }), 'upcoming', 'Zahl als Text aus der API');
    // Eine vorab genehmigte Entschuldigung bleibt "Entschuldigt".
    assert.equal(attendanceState({ status: 'excused', appointment_started: 0 }), 'excused');
    assert.equal(attendanceState({ status: 'present', appointment_started: 1 }), 'present');
    // Ohne Kennzeichen (aeltere Antwort) wie bisher: fehlend.
    assert.equal(attendanceState({ status: null }), 'missing');
});

test('Terminansicht: "Kommend" ist ein Chip der Partition, "Alle" blendet nichts aus', () => {
    const items = [
        { status: 'excused', appointment_started: 0 },
        { status: null, appointment_started: 0 },
        { status: null, appointment_started: 0 },
    ];
    assert.deepEqual(countChips(items, CHIPS_RECORDS_LIST),
        { all: 3, present: 0, excused: 1, missing: 0, upcoming: 2 });
    assertPartition(CHIPS_RECORDS_LIST, items, 'Terminansicht kommend');
    assertPartition(CHIPS_RECORDS_LIST, [
        { status: 'present', appointment_started: 1 }, { status: null, appointment_started: 1 },
    ], 'Terminansicht begonnen');
});

test('Mitgliedsansicht: "Alle" und Status nur begonnene, "Kommend" alle kommenden', () => {
    const items = [
        { status: 'present', appointment_started: 1 },
        { status: null,      appointment_started: 1 },
        { status: 'excused', appointment_started: 1 },
        { status: 'excused', appointment_started: 0 },
        { status: null,      appointment_started: 0 },
        { status: null,      appointment_started: 0 },
    ];
    assert.deepEqual(countChips(items, CHIPS_RECORDS_MEMBER),
        { all: 3, present: 1, excused: 1, missing: 1, upcoming: 3 });
    assert.equal(filterByChip(items, CHIPS_RECORDS_MEMBER, 'all').length, 3);
    assert.equal(filterByChip(items, CHIPS_RECORDS_MEMBER, 'upcoming').length, 3,
        'Auch die vorab entschuldigten kommenden Termine stehen unter "Kommend"');

    // Die Status-Chips teilen die begonnenen restlos auf.
    const begonnen = items.filter(r => Number(r.appointment_started) !== 0);
    const status = CHIPS_RECORDS_MEMBER.filter(d => ['present', 'excused', 'missing'].includes(d.key));
    for (const r of begonnen) {
        assert.equal(status.filter(d => d.match(r)).length, 1, JSON.stringify(r));
    }
    for (const r of items.filter(r => Number(r.appointment_started) === 0)) {
        assert.equal(status.filter(d => d.match(r)).length, 0, 'Kommende zaehlen unter keinem Status: ' + JSON.stringify(r));
    }
});

test('"Kommend" ist neutral gefaerbt und in beiden Listensaetzen gleich beschriftet', () => {
    for (const defs of [CHIPS_RECORDS_LIST, CHIPS_RECORDS_MEMBER]) {
        const chip = defs.find(d => d.key === 'upcoming');
        assert.ok(chip, 'Chip "Kommend" fehlt');
        assert.equal(chip.label, 'Kommend');
        assert.equal(chip.variant, undefined);
    }
    assert.ok(!CHIPS_RECORDS_ALL.some(d => d.key === 'upcoming'));
});
