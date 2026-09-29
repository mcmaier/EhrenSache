/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Logik der Aktionstabelle (OI-17 Etappe 2). Aufruf ueber
// tests/suites/actions_unit.php oder direkt: node --test tests/js/actions.test.mjs
//
// Node hat kein DOM. Element und Ereignis sind hier Attrappen mit genau den
// Eigenschaften, die dispatchAction() benutzt: getAttribute, closest, type,
// target, preventDefault. Jeder Test registriert eigene Namen, denn die
// Tabelle ist modulweit und lebt ueber alle Tests hinweg.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { registerActions, dispatchAction } from '../../public/js/modules/actions.js';

function element(attrs, parent = null) {
    return {
        attrs,
        parent,
        getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; },
        closest(selector) {
            const name = selector.slice(1, -1);            // "[data-action]" -> "data-action"
            if (name in this.attrs) return this;
            return this.parent ? this.parent.closest(selector) : null;
        },
    };
}

function event(type, target) {
    return { type, target, prevented: false, preventDefault() { this.prevented = true; } };
}

function captureErrors(fn) {
    const original = console.error;
    const errors = [];
    console.error = (...args) => errors.push(args.join(' '));
    try { fn(); } finally { console.error = original; }
    return errors;
}

test('Klick ruft die registrierte Aktion mit Element und Ereignis', () => {
    const calls = [];
    registerActions({ 't1-click': (el, ev) => calls.push([el, ev]) });
    const el = element({ 'data-action': 't1-click' });
    const ev = event('click', el);
    dispatchAction(ev);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], el);
    assert.equal(calls[0][1], ev);
});

test('Klick auf ein inneres Element findet die Aktion am Vorfahren', () => {
    let seen = null;
    registerActions({ 't2-outer': (el) => { seen = el; } });
    const button = element({ 'data-action': 't2-outer' });
    const icon = element({}, button);
    dispatchAction(event('click', icon));
    assert.equal(seen, button);
});

test('change liest data-action-change, nicht data-action', () => {
    const calls = [];
    registerActions({ 't3-click': () => calls.push('click'), 't3-change': () => calls.push('change') });
    const el = element({ 'data-action': 't3-click', 'data-action-change': 't3-change' });
    dispatchAction(event('change', el));
    assert.deepEqual(calls, ['change']);
});

test('submit verhindert das Absenden immer, auch ohne bekannte Aktion', () => {
    const form = element({ 'data-action-submit': 't4-unbekannt' });
    const ev = event('submit', form);
    const errors = captureErrors(() => dispatchAction(ev));
    assert.equal(ev.prevented, true);
    assert.equal(errors.length, 1);
});

test('unbekannte Aktion ruft nichts und meldet sich per console.error', () => {
    const errors = captureErrors(() => dispatchAction(event('click', element({ 'data-action': 't5-fehlt' }))));
    assert.equal(errors.length, 1);
    assert.match(errors[0], /t5-fehlt/);
});

test('Klick ohne data-action tut nichts und meldet nichts', () => {
    const errors = captureErrors(() => dispatchAction(event('click', element({}))));
    assert.deepEqual(errors, []);
});

test('ein anderes Ereignis als click/change/submit wird ignoriert', () => {
    let called = false;
    registerActions({ 't7-input': () => { called = true; } });
    dispatchAction(event('input', element({ 'data-action': 't7-input' })));
    assert.equal(called, false);
});

test('doppelte Vergabe eines Namens wirft', () => {
    registerActions({ 't8-doppelt': () => {} });
    assert.throws(() => registerActions({ 't8-doppelt': () => {} }), /t8-doppelt/);
});

test('eine Registrierung, die keine Funktion ist, wirft', () => {
    assert.throws(() => registerActions({ 't9-kaputt': 'saveMember' }), /t9-kaputt/);
});

test('ein Ereignis ohne Element als Ziel wird ignoriert', () => {
    const errors = captureErrors(() => dispatchAction({ type: 'click', target: null, preventDefault() {} }));
    assert.deepEqual(errors, []);
});
