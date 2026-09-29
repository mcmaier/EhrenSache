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
// AKTIONSTABELLE (OI-17 Etappe 2)
// ============================================
// Knoepfe tragen keine Inline-Handler mehr, denn die Content-Security-Policy
// des Dashboards (script-src 'self') fuehrt sie nicht aus. Stattdessen:
//
//   <button data-action="save-member">            -> click
//   <select data-action-change="apply-filters">    -> change
//   <form data-action-submit="prevent-submit">     -> submit (immer preventDefault)
//
// Jedes Fachmodul meldet seine Aktionen am Dateiende an:
//
//   registerActions({
//       'save-member': () => saveMember(),
//       'open-member-modal': (el) => openMemberModal(el.dataset.id ? Number(el.dataset.id) : null),
//   });
//
// Die Tabelle ist eine Positivliste: Nur angemeldete Namen sind aufrufbar.
// Ein eingeschleustes data-action erreicht damit keine beliebige globale
// Funktion (verworfener Weg C der Spec 2026-09-29).
//
// Bewusst ohne Importe: tests/js/actions.test.mjs laedt das Modul unter Node.

const actions = new Map();

const ATTRIBUTE_BY_EVENT = {
    click: 'data-action',
    change: 'data-action-change',
    submit: 'data-action-submit',
};

/**
 * Meldet Aktionen an. Ein bereits vergebener Name wirft -- kein Modul
 * ueberschreibt still die Aktion eines anderen.
 *
 * @param {Object<string, function(Element, Event): *>} table
 */
export function registerActions(table) {
    for (const [name, fn] of Object.entries(table)) {
        if (typeof fn !== 'function') {
            throw new TypeError(`Aktion "${name}" ist keine Funktion`);
        }
        if (actions.has(name)) {
            throw new Error(`Aktion "${name}" ist bereits registriert`);
        }
        actions.set(name, fn);
    }
}

/**
 * Verteilt ein click-, change- oder submit-Ereignis an die Aktion des
 * naechstgelegenen Elements mit dem passenden Attribut. Exportiert fuer den
 * Node-Test; im Browser ruft ihn der Zuhoerer am document.
 *
 * @param {Event} event
 */
export function dispatchAction(event) {
    const attribute = ATTRIBUTE_BY_EVENT[event.type];
    const target = event.target;
    if (!attribute || !target || typeof target.closest !== 'function') {
        return;
    }

    const element = target.closest(`[${attribute}]`);
    if (!element) {
        return;
    }
    if (event.type === 'submit') {
        event.preventDefault();
    }

    const name = element.getAttribute(attribute);
    const action = actions.get(name);
    if (!action) {
        // Sichtbar statt still: Der Knopf tut nichts, die Konsole sagt warum.
        console.error(`Unbekannte Aktion: ${name}`);
        return;
    }
    action(element, event);
}

if (typeof document !== 'undefined') {
    for (const type of Object.keys(ATTRIBUTE_BY_EVENT)) {
        document.addEventListener(type, dispatchAction);
    }
}
