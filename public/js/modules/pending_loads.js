/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// ============================================
// LAUFENDE ABRUFE
// Reference:
// import { sharedLoad } from './pending_loads.js'
// ============================================
//
// Bewusst importfrei, damit Node das Modul ohne Browser laden kann
// (tests/js/pending_loads.test.mjs).

// Laufende Abrufe je Schluessel ('members:2026', 'types' ...). Fragen zwei
// Stellen gleichzeitig denselben Bestand an -- etwa beim Jahreswechsel
// Filter-Reset und Bereichsaufbau --, teilen sie sich eine Anfrage.
const pendingLoads = new Map();

/**
 * Fuehrt fetcher() aus oder haengt sich an einen laufenden Abruf desselben
 * Schluessels. forceReload startet immer eine eigene Anfrage (nach einer
 * Aenderung darf keine aeltere Antwort zurueckkommen); spaetere Aufrufer ohne
 * forceReload haengen sich an diese.
 */
export function sharedLoad(key, forceReload, fetcher) {
    if (!forceReload && pendingLoads.has(key)) {
        return pendingLoads.get(key);
    }

    const promise = fetcher().finally(() => {
        if (pendingLoads.get(key) === promise) {
            pendingLoads.delete(key);
        }
    });
    pendingLoads.set(key, promise);
    return promise;
}

/**
 * Laufende Abrufe vergessen, damit sich nach invalidateCache() niemand mehr an
 * eine Anfrage von vor der Aenderung haengt. Ohne Schluessel: alle; ohne Jahr:
 * der globale Schluessel und jedes Jahr darunter.
 */
export function forgetPendingLoads(cacheKey = null, year = null) {
    for (const key of [...pendingLoads.keys()]) {
        if (cacheKey === null
            || key === (year !== null ? `${cacheKey}:${year}` : cacheKey)
            || (year === null && key.startsWith(`${cacheKey}:`))) {
            pendingLoads.delete(key);
        }
    }
}
