/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Logiktests fuer das Zusammenlegen laufender Abrufe. Aufruf ueber
// tests/suites/pending_loads_unit.php oder direkt: node --test tests/js/pending_loads.test.mjs
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { sharedLoad, forgetPendingLoads } from '../../public/js/modules/pending_loads.js';

/** Abruf, der erst auf release() hin antwortet und seine Aufrufe zaehlt. */
function deferredFetcher(value) {
    const f = () => {
        f.calls++;
        return new Promise(resolve => { f.release = () => resolve(value); });
    };
    f.calls = 0;
    return f;
}

beforeEach(() => forgetPendingLoads());

test('gleichzeitige Abrufe desselben Schluessels teilen sich eine Anfrage', async () => {
    const f = deferredFetcher(['a']);
    const p1 = sharedLoad('members:2025', false, f);
    const p2 = sharedLoad('members:2025', false, f);
    assert.equal(f.calls, 1, 'Der zweite Aufruf hat eine eigene Anfrage gestellt');
    f.release();
    assert.deepEqual(await p1, ['a']);
    assert.deepEqual(await p2, ['a']);
});

test('verschiedene Jahre werden nicht zusammengelegt', async () => {
    const f = deferredFetcher([]);
    sharedLoad('members:2025', false, f);
    sharedLoad('members:2026', false, f);
    assert.equal(f.calls, 2);
    f.release();
});

test('forceReload stellt immer eine eigene Anfrage', async () => {
    const alt = deferredFetcher('alt');
    const neu = deferredFetcher('neu');
    sharedLoad('types', false, alt);
    const p = sharedLoad('types', true, neu);
    assert.equal(neu.calls, 1, 'forceReload hat sich an die alte Anfrage gehaengt');
    neu.release();
    alt.release();
    assert.equal(await p, 'neu');
});

test('nach forceReload haengen sich spaetere Aufrufe an die neue Anfrage', async () => {
    const alt = deferredFetcher('alt');
    const neu = deferredFetcher('neu');
    sharedLoad('types', false, alt);
    sharedLoad('types', true, neu);
    const p = sharedLoad('types', false, deferredFetcher('dritte'));
    neu.release();
    alt.release();
    assert.equal(await p, 'neu');
});

test('nach Abschluss startet der naechste Aufruf eine neue Anfrage', async () => {
    const f = deferredFetcher('x');
    const p = sharedLoad('groups', false, f);
    f.release();
    await p;
    sharedLoad('groups', false, f);
    assert.equal(f.calls, 2, 'Ein abgeschlossener Abruf blieb haengen');
    f.release();
});

test('ein fehlgeschlagener Abruf blockiert den Schluessel nicht', async () => {
    const kaputt = () => Promise.reject(new Error('Netz weg'));
    await assert.rejects(sharedLoad('records:2026', false, kaputt));
    const f = deferredFetcher('ok');
    const p = sharedLoad('records:2026', false, f);
    assert.equal(f.calls, 1);
    f.release();
    assert.equal(await p, 'ok');
});

test('forgetPendingLoads(key) vergisst den Schluessel ueber alle Jahre, nicht andere', async () => {
    const f = deferredFetcher(null);
    sharedLoad('appointments:2025', false, f);
    sharedLoad('appointments:2026', false, f);
    sharedLoad('appointmentsX', false, f);
    sharedLoad('records:2026', false, f);
    forgetPendingLoads('appointments');
    sharedLoad('appointments:2025', false, f);
    sharedLoad('appointments:2026', false, f);
    assert.equal(f.calls, 6, 'Die Jahre von appointments wurden nicht vergessen');
    sharedLoad('appointmentsX', false, f);
    sharedLoad('records:2026', false, f);
    assert.equal(f.calls, 6, 'Fremde Schluessel wurden mit vergessen');
    f.release();
});

test('forgetPendingLoads(key, year) vergisst nur dieses Jahr', async () => {
    const f = deferredFetcher(null);
    sharedLoad('records:2025', false, f);
    sharedLoad('records:2026', false, f);
    forgetPendingLoads('records', 2026);
    sharedLoad('records:2026', false, f);
    sharedLoad('records:2025', false, f);
    assert.equal(f.calls, 3);
    f.release();
});

test('forgetPendingLoads fuer einen globalen Schluessel', async () => {
    const f = deferredFetcher(null);
    sharedLoad('userData', false, f);
    forgetPendingLoads('userData');
    sharedLoad('userData', false, f);
    assert.equal(f.calls, 2);
    f.release();
});
