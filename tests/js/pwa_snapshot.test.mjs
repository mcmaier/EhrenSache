/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Logiktests fuer den "Letzten Stand" der Check-in-App (OI-43, Stufe 2).
// snapshot.js ist ein klassisches Skript (kein Modul) und wird deshalb in
// einem eigenen vm-Kontext mit nachgebautem localStorage ausgefuehrt.
// Aufruf ueber tests/suites/pwa_snapshot_unit.php oder direkt:
// node --test tests/js/pwa_snapshot.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SRC = readFileSync(new URL('../../public/checkin/js/snapshot.js', import.meta.url), 'utf8');
const EXPORTS = ['readSnapshot', 'clearSnapshot', 'saveSnapshotPart', 'discardForeignSnapshot',
    'snapshotAppointment', 'upcomingSnapshotAppointments', 'snapshotResponseText',
    'snapshotStamp', 'localIsoDate', 'dropSnapshotPart', 'historyFromList', 'renderSnapshotView'];

/** Minimaler DOM-Ersatz: Elemente mit Kindern, Klassenselektoren '.x' durch Abstieg. */
class FakeEl {
    constructor(tag) { this.tag = tag; this.className = ''; this.textContent = ''; this.children = []; }
    appendChild(c) { this.children.push(c); return c; }
    replaceChildren(...c) { this.children = c; }
    hasClass(sel) { return this.className.split(/\s+/).includes(sel.slice(1)); }
    querySelectorAll(sel) {
        const out = [];
        for (const c of this.children) {
            if (c.hasClass(sel)) out.push(c);
            out.push(...c.querySelectorAll(sel));
        }
        return out;
    }
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
}
const fakeDocument = { createElement: tag => new FakeEl(tag) };
const el = (className, text, children = []) => {
    const e = new FakeEl('div');
    e.className = className;
    e.textContent = text ?? '';
    children.forEach(c => e.appendChild(c));
    return e;
};
/** Alle Texte unterhalb von root, tief, in Reihenfolge. */
const texts = root => [root.textContent, ...root.children.flatMap(texts)].filter(Boolean);

/** Laedt snapshot.js mit einem localStorage aus $initial; throwOnSet simuliert vollen Speicher. */
function load(initial = {}, { throwOnSet = false } = {}) {
    const store = new Map(Object.entries(initial));
    const localStorage = {
        getItem: k => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => { if (throwOnSet) throw new Error('QuotaExceededError'); store.set(k, String(v)); },
        removeItem: k => { store.delete(k); },
    };
    const ctx = vm.createContext({ localStorage, document: fakeDocument });
    vm.runInContext(`${SRC}\n;globalThis.__api = { ${EXPORTS.join(', ')} };`, ctx);
    return { api: ctx.__api, store };
}

// Objekte aus dem vm-Kontext haben einen fremden Object.prototype.
const plain = x => JSON.parse(JSON.stringify(x));
const TOKEN = { api_token: 'dG9rZW4=' };

test('ohne gespeicherten Token wird nichts geschrieben', () => {
    const { api, store } = load();
    api.saveSnapshotPart('appointments', [{ title: 'Probe' }], 2, { organization: 'V', member: 'A' });
    assert.equal(store.has('offline_snapshot'), false);
});

test('Teile werden getrennt geschrieben, saved_at ist der juengere', () => {
    const { api } = load(TOKEN);
    api.saveSnapshotPart('appointments', [{ title: 'Probe' }], 2, { organization: 'V', member: 'A' },
        new Date('2026-10-08T10:00:00Z'));
    api.saveSnapshotPart('history', [{ title: 'Anwesend' }], 2, { organization: 'V', member: 'A' },
        new Date('2026-10-08T12:00:00Z'));
    const snap = plain(api.readSnapshot());
    assert.equal(snap.member_id, 2);
    assert.equal(snap.appointments.saved_at, '2026-10-08T10:00:00.000Z');
    assert.equal(snap.history.saved_at, '2026-10-08T12:00:00.000Z');
    assert.equal(snap.saved_at, '2026-10-08T12:00:00.000Z');
    assert.deepEqual(snap.appointments.items, [{ title: 'Probe' }]);
    assert.deepEqual(snap.header, { organization: 'V', member: 'A' });
});

test('ein anderes Mitglied ersetzt den ganzen Schnappschuss', () => {
    const { api } = load(TOKEN);
    api.saveSnapshotPart('appointments', [{ title: 'Alt' }], 2, {});
    api.saveSnapshotPart('history', [{ title: 'Neu' }], 3, {});
    const snap = plain(api.readSnapshot());
    assert.equal(snap.member_id, 3);
    assert.equal(snap.appointments, null);
});

test('unbekannte Version und kaputtes JSON gelten als kein Schnappschuss', () => {
    assert.equal(load({ offline_snapshot: '{"version":99,"member_id":2}' }).api.readSnapshot(), null);
    assert.equal(load({ offline_snapshot: '{kaputt' }).api.readSnapshot(), null);
});

test('discardForeignSnapshot verwirft nur fremde Mitglieder, auch bei Zahl gegen Text', () => {
    const { api, store } = load(TOKEN);
    api.saveSnapshotPart('history', [], 2, {});
    api.discardForeignSnapshot('2');
    assert.equal(store.has('offline_snapshot'), true);
    api.discardForeignSnapshot(5);
    assert.equal(store.has('offline_snapshot'), false);
});

test('clearSnapshot loescht', () => {
    const { api, store } = load(TOKEN);
    api.saveSnapshotPart('history', [], 2, {});
    api.clearSnapshot();
    assert.equal(store.has('offline_snapshot'), false);
});

test('voller Speicher wird geschluckt', () => {
    const { api } = load(TOKEN, { throwOnSet: true });
    assert.doesNotThrow(() => api.saveSnapshotPart('history', [], 2, {}));
});

test('snapshotAppointment bildet Rueckmeldung und Info-Termine ab', () => {
    const { api } = load();
    const base = { date: '2026-10-17 00:00:00', start_time: '19:00:00', end_time: '21:00:00',
        title: 'Probe', location: 'Saal' };
    assert.deepEqual(plain(api.snapshotAppointment({ appointment: { ...base, responses_enabled: 1 }, own: { status: 'yes' } })),
        { date: '2026-10-17', start_time: '19:00:00', end_time: '21:00:00', title: 'Probe', location: 'Saal', response: 'yes' });
    assert.equal(api.snapshotAppointment({ appointment: { ...base, responses_enabled: 1 }, own: null }).response, null);
    assert.equal(api.snapshotAppointment({ appointment: { ...base, responses_enabled: 0 } }).response, 'info');
});

test('vergangene Termine fallen bei der Anzeige weg', () => {
    const { api } = load();
    const items = [{ date: '2026-10-07' }, { date: '2026-10-08' }, { date: '2026-10-09' }];
    assert.deepEqual(plain(api.upcomingSnapshotAppointments(items, '2026-10-08')),
        [{ date: '2026-10-08' }, { date: '2026-10-09' }]);
});

test('Rueckmeldetexte', () => {
    const { api } = load();
    assert.equal(api.snapshotResponseText('yes'), 'Zugesagt');
    assert.equal(api.snapshotResponseText('no'), 'Abgesagt');
    assert.equal(api.snapshotResponseText('maybe'), 'Unsicher');
    assert.equal(api.snapshotResponseText(null), 'Keine Rückmeldung');
    assert.equal(api.snapshotResponseText('info'), '');
});

test('snapshotStamp nennt Datum und Uhrzeit in Ortszeit', () => {
    const { api } = load();
    const stamp = api.snapshotStamp(new Date(2026, 9, 8, 18, 42).toISOString());
    assert.match(stamp, /08\.10\./);
    assert.match(stamp, /18:42/);
    assert.equal(api.snapshotStamp('kein Datum'), '');
});

test('localIsoDate liefert das Ortsdatum', () => {
    const { api } = load();
    assert.equal(api.localIsoDate(new Date(2026, 0, 5, 23, 59)), '2026-01-05');
});

test('dropSnapshotPart: ohne Token oder Schnappschuss passiert nichts', () => {
    const a = load();
    a.api.dropSnapshotPart('appointments');
    assert.equal(a.store.has('offline_snapshot'), false);
    const b = load(TOKEN);
    b.api.dropSnapshotPart('appointments');
    assert.equal(b.store.has('offline_snapshot'), false);
});

test('dropSnapshotPart setzt die Abschaltmarke, saved_at bleibt vom anderen Teil', () => {
    const { api } = load(TOKEN);
    api.saveSnapshotPart('appointments', [{ title: 'Probe' }], 2, {}, new Date('2026-10-08T10:00:00Z'));
    api.saveSnapshotPart('history', [], 2, {}, new Date('2026-10-08T09:00:00Z'));
    api.dropSnapshotPart('appointments');
    const snap = plain(api.readSnapshot());
    assert.deepEqual(snap.appointments, { off: true });
    assert.equal(snap.history.saved_at, '2026-10-08T09:00:00.000Z');
    // Ein spaeteres Schreiben des anderen Teils laesst die Marke unberuehrt und rechnet ohne sie.
    api.saveSnapshotPart('history', [], 2, {}, new Date('2026-10-08T11:00:00Z'));
    const again = plain(api.readSnapshot());
    assert.deepEqual(again.appointments, { off: true });
    assert.equal(again.saved_at, '2026-10-08T11:00:00.000Z');
});

test('dropSnapshotPart schluckt vollen Speicher', () => {
    const { api } = load({ ...TOKEN, offline_snapshot: JSON.stringify({ version: 1, member_id: 2, appointments: null, history: null }) },
        { throwOnSet: true });
    assert.doesNotThrow(() => api.dropSnapshotPart('appointments'));
});

test('snapshotAppointment uebernimmt die eigene Entschuldigung wie excuseChipHtml', () => {
    const { api } = load();
    const apt = { date: '2026-10-17', start_time: '19:00:00', title: 'Probe', responses_enabled: 0 };
    const text = item => api.snapshotAppointment({ appointment: apt, ...item }).absence;
    assert.equal(text({ own_absence: { status: 'pending' } }), '⏳ Entschuldigung beantragt');
    assert.equal(text({ own_absence: { status: 'approved' } }), '✗ entschuldigt');
    assert.equal(text({ own_absence: { status: 'rejected' } }), 'Entschuldigung abgelehnt');
    assert.equal(text({ started: true }), 'hat begonnen');
    assert.equal(text({}), '');
});

test('historyFromList liest Titel, Zeit, Meta und Status und ignoriert Aktionen', () => {
    const { api } = load();
    const item = el('history-item', '', [
        el('history-title', ' Probe '), el('history-when', 'Do. 08.10.'),
        el('history-meta', 'Saal'), el('history-actions', 'Bearbeiten'),
        el('response-chip', 'Zugesagt')
    ]);
    const list = el('history-list', '', [item, el('history-item', '', [el('history-title', 'Zweiter')])]);
    assert.deepEqual(plain(api.historyFromList(list)), [
        { title: 'Probe', when: 'Do. 08.10.', meta: 'Saal', status: 'Zugesagt' },
        { title: 'Zweiter', when: '', meta: '', status: '' }
    ]);
    assert.deepEqual(plain(api.historyFromList(null)), []);
});

const NOW = new Date(2026, 9, 8, 12, 0);
const apt = (date, title, response = 'yes') => ({ date, start_time: '19:00:00', end_time: null, title, location: '', response });
function view(snap) {
    const { api } = load();
    const root = el('', '');
    const stamp = el('', '');
    api.renderSnapshotView(root, stamp, snap, NOW);
    return { root, stamp, all: texts(root) };
}
const base = { version: 1, member_id: 2, header: { organization: 'Verein', member: 'Anna' },
    saved_at: new Date(2026, 9, 8, 10, 0).toISOString() };

test('Ansicht: Leiste nennt "ohne Verbindung", vergangene Termine fehlen', () => {
    const v = view({ ...base,
        appointments: { saved_at: base.saved_at, items: [apt('2026-10-07', 'Gestern'), apt('2026-10-09', 'Morgen')] },
        history: { saved_at: base.saved_at, items: [] } });
    assert.match(v.stamp.textContent, /ohne Verbindung/);
    assert.ok(v.all.includes('Morgen'));
    assert.ok(!v.all.includes('Gestern'));
    assert.ok(v.all.includes('Kein Verlauf im letzten Stand.'));
});

test('Ansicht: leere Termine, fehlender Teil, abgeschalteter Teil', () => {
    const empty = view({ ...base, appointments: { saved_at: base.saved_at, items: [] }, history: null });
    assert.ok(empty.all.includes('Keine kommenden Termine im letzten Stand.'));
    assert.ok(empty.all.includes('Nicht geladen, solange Verbindung bestand.'));

    const off = view({ ...base, appointments: { off: true }, history: { saved_at: base.saved_at, items: [] } });
    assert.ok(!off.all.includes('Kommende Termine'));
    assert.ok(off.all.includes('Verlauf'));

    const broken = view({ ...base, appointments: { saved_at: base.saved_at, items: 'kaputt' }, history: null });
    assert.ok(broken.all.includes('Keine kommenden Termine im letzten Stand.'));
});

test('Ansicht: Abschnittsstand nur bei mehr als einer Stunde Abstand', () => {
    const at = (h, m) => new Date(2026, 9, 8, h, m).toISOString();
    const close = view({ ...base, appointments: { saved_at: at(10, 0), items: [] }, history: { saved_at: at(10, 59), items: [] } });
    assert.ok(!close.all.some(t => t.startsWith('Stand ')));
    const far = view({ ...base, appointments: { saved_at: at(8, 0), items: [] }, history: { saved_at: at(10, 0), items: [] } });
    assert.equal(far.all.filter(t => t.startsWith('Stand ')).length, 2);
});

test('Ansicht: abgeschalteter Teil zaehlt nicht fuer die Abschnittsstaende', () => {
    const v = view({ ...base, appointments: { off: true }, history: { saved_at: base.saved_at, items: [] } });
    assert.ok(!v.all.some(t => t.startsWith('Stand ')));
});

test('Ansicht: Entschuldigung erscheint als Zeile', () => {
    const v = view({ ...base, appointments: { saved_at: base.saved_at,
        items: [{ ...apt('2026-10-09', 'Konzert', 'info'), absence: '✗ entschuldigt' }] }, history: null });
    assert.ok(v.all.includes('✗ entschuldigt'));
});
