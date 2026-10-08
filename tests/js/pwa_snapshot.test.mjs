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
    'snapshotStamp', 'localIsoDate'];

/** Laedt snapshot.js mit einem localStorage aus $initial; throwOnSet simuliert vollen Speicher. */
function load(initial = {}, { throwOnSet = false } = {}) {
    const store = new Map(Object.entries(initial));
    const localStorage = {
        getItem: k => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => { if (throwOnSet) throw new Error('QuotaExceededError'); store.set(k, String(v)); },
        removeItem: k => { store.delete(k); },
    };
    const ctx = vm.createContext({ localStorage });
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
