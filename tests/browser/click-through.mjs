/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Klickdurchgang durch das Dashboard (OI-17 Etappe 2).
//
// Meldet sich als Admin an, oeffnet jede Sektion und loest jeden sichtbaren
// Knopf mit data-action / data-action-change aus. Aktionen, die Daten
// veraendern, etwas herunterladen, drucken, nach aussen greifen oder die
// Zwischenablage brauchen, werden uebersprungen (SKIP). Nach jeder Sektion
// werden offene Dialoge geschlossen (Escape, notfalls der Abbrechen-Knopf).
//
// Je Aktionsname wird einmal mit und einmal ohne data-id ausgeloest: Die
// Bearbeiten-Variante eines Dialogs zeigt oft Knoepfe, die der Neu-Dialog nicht hat.
//
// Untertabs, die abgedeckt sind: .settings-tab-btn, .tab-btn und [role="tab"]
// (siehe TABS), dazu das Kalender-Popup eines belegten Tages. NICHT abgedeckt:
// Umschalter anderer Bauart (etwa Ansichts-Schalter ohne diese Klassen, Filter-Chips).
//
// Fehler (Exit-Code 1): CSP-Verstoss, Laufzeitfehler, "Unbekannte Aktion".
// Nur Auskunft (kein Fehler): welche Aktionen ausgeloest, uebersprungen und
// "registriert, aber nie gesehen" wurden. Nie gesehen heisst: im ganzen Lauf in
// keinem DOM aufgetaucht (Ausgeloest und Uebersprungen zaehlen als gesehen);
// manche brauchen bestimmte Daten. data-action-submit-Formulare werden nur
// als gesehen gezaehlt, nicht abgeschickt.
//
// Aufruf:  node tests/browser/click-through.mjs [--section=mitglieder]
// Konfiguration aus tests/config.php (base_url, admin). PHP_BIN und CHROME_BIN
// sind per Umgebungsvariable ueberschreibbar.

import puppeteer from 'puppeteer-core';
import { execFileSync } from 'node:child_process';
import { readdirSync, existsSync, readFileSync, statSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PHP = process.env.PHP_BIN || 'C:/xampp/php/php.exe';
const onlySection = (process.argv.find(a => a.startsWith('--section=')) || '').split('=')[1] || null;

// Veraendert Daten, laedt herunter, druckt, greift nach aussen oder in die
// Zwischenablage -- hier nie ausloesen.
const SKIP = /^(delete-|save-|execute-|perform-|regenerate-|generate-|quick-|approve-|reject-|send-|set-member-|set-own-|withdraw-|create-|analyze-|export-|download-|print-|check-for-|copy-|remove-membership|add-membership|toggle-responses-lock|import-logs-delete|app-reset-reload|auth-reload)/;
// Bewusst NICHT in SKIP: calendar-new-appointment (oeffnet nur den Neu-Dialog, gespeichert
// wird erst ueber save-appointment) und set-attendance-grouping (nur Ansichtsgruppierung).
// quick-create-* legt Anwesenheitssaetze an und faellt unter "quick-".

// Aktionen, die beim Durchgang ans Ende gehoeren, sonst schliessen sie den
// Dialog vor dessen uebrigen Knoepfen.
const CLOSING = /^(close|cancel|hide|dismiss)-/;

// Alle registerActions({ ... })-Namen aus public/js (gleiche Form wie
// tests/suites/actions_frontend.php: Block ab Spalte 0, ein 'name': je Zeile).
function registeredActions() {
    const names = new Set();
    const walk = dir => {
        for (const f of readdirSync(dir)) {
            const full = join(dir, f);
            if (statSync(full).isDirectory()) walk(full);
            else if (f.endsWith('.js')) {
                const src = readFileSync(full, 'utf8');
                for (const b of src.matchAll(/^registerActions\(\{\r?\n([\s\S]*?)^\}\);/gm)) {
                    for (const k of b[1].matchAll(/^\s+'([^']+)':/gm)) names.add(k[1]);
                }
            }
        }
    };
    walk(join(ROOT, 'public', 'js'));
    return names;
}

const wait = ms => new Promise(r => setTimeout(r, ms));

function config() {
    const php = `echo json_encode(require '${ROOT.replace(/\\/g, '/')}/tests/config.php');`;
    return JSON.parse(execFileSync(PHP, ['-r', php], { encoding: 'utf8' }));
}

function chromePath() {
    if (process.env.CHROME_BIN) return process.env.CHROME_BIN;
    const cache = join(process.env.USERPROFILE || process.env.HOME, '.cache', 'puppeteer', 'chrome');
    for (const v of (existsSync(cache) ? readdirSync(cache) : []).sort().reverse()) {
        const exe = join(cache, v, 'chrome-win64', 'chrome.exe');
        if (existsSync(exe)) return exe;
    }
    const installed = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
    if (existsSync(installed)) return installed;
    throw new Error('Kein Chrome gefunden: CHROME_BIN setzen oder "npx @puppeteer/browsers install chrome@stable"');
}

const cfg = config();
const BASE = cfg.base_url.replace(/\/$/, '');
const problems = [];
const triggered = new Set();
const skipped = new Set();
const done = new Set();      // Schluessel kind|name|id bzw. -, schon behandelt
const seen = new Set();      // Aktionsnamen, die irgendwann im DOM standen
const notes = [];            // Hinweise auf nicht erreichte Schritte (Auskunft, kein Fehler)

const browser = await puppeteer.launch({ executablePath: chromePath(), headless: 'new' });
try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    page.on('dialog', d => d.dismiss());
    page.on('pageerror', e => problems.push(`Laufzeitfehler: ${e.message}`));
    page.on('console', m => {
        const t = m.text();
        if (/Unbekannte Aktion/.test(t)) problems.push(t);
        else if (/Content Security Policy|Refused to/.test(t)) problems.push(`CSP: ${t}`);
    });
    await page.evaluateOnNewDocument(() => {
        window.__csp = [];
        // Jeden Aktionsnamen merken, der irgendwann im DOM steht (auch kurzlebig).
        window.__seenActions = new Set();
        const ATTRS = ['data-action', 'data-action-change', 'data-action-submit'];
        const SEL = ATTRS.map(a => `[${a}]`).join(',');
        const scan = root => {
            if (root.nodeType !== 1) return;
            for (const e of [root, ...root.querySelectorAll(SEL)])
                for (const a of ATTRS) if (e.hasAttribute(a)) window.__seenActions.add(e.getAttribute(a));
        };
        window.__scanActions = () => scan(document.documentElement);
        new MutationObserver(ms => {
            for (const m of ms) {
                m.addedNodes.forEach(scan);
                if (m.type === 'attributes') scan(m.target);
            }
        }).observe(document, { subtree: true, childList: true, attributes: true, attributeFilter: ATTRS });
        document.addEventListener('securitypolicyviolation', e =>
            window.__csp.push(`${e.effectiveDirective} ${e.blockedURI || '(inline)'} @${e.sourceFile}:${e.lineNumber}`));
    });

    // Anmeldung
    await page.goto(`${BASE}/login.html`, { waitUntil: 'networkidle2' });
    await page.evaluate((email, pw) => {
        const p = document.querySelector('input[type=password]');
        const f = p.closest('form');
        f.querySelector('input[type=email]').value = email;
        p.value = pw;
        (f.querySelector('button[type=submit]') || [...f.querySelectorAll('button')].pop()).click();
    }, cfg.admin.email, cfg.admin.password);
    await page.waitForSelector('.nav-item[data-section]', { timeout: 20000 });
    await wait(1500);

    const cspHeader = (await page.goto(`${BASE}/`, { waitUntil: 'networkidle2' })).headers()['content-security-policy'] || null;
    await page.waitForSelector('.nav-item[data-section]', { timeout: 20000 });
    await wait(1000);

    const sections = await page.$$eval('.nav-item[data-section]', els => els.map(e => e.dataset.section));

    // Offene Dialoge schliessen: Escape, bis keiner mehr aktiv ist; sonst den
    // Abbrechen-Knopf des noch offenen Dialogs (Bestaetigung, Begruendung ...).
    async function closeDialogs() {
        const open = () => page.evaluate(() => !!document.querySelector('.modal.active'));
        for (let i = 0; i < 5 && await open(); i++) {
            await page.keyboard.press('Escape');
            await wait(300);
        }
        if (!await open()) return;
        await page.evaluate(() => {
            const m = document.querySelector('.modal.active');
            const btn = m.querySelector('#confirmCancel, #reasonCancel, [id$="Cancel"], [data-action^="close-"], [data-action^="cancel-"]');
            if (btn) btn.click();
        });
        await wait(300);
        if (await open()) problems.push('Dialog liess sich nicht schliessen (Escape und Abbrechen wirkungslos)');
    }

    // Alle sichtbaren, noch nicht versuchten Aktionen ausloesen.
    async function sweep(label) {
        // Geoeffnete Dialoge bleiben zwischen den Runden offen, damit ihre Knoepfe
        // in der naechsten Runde gefunden werden; erst am Ende wird geschlossen.
        for (let round = 0; round < 200; round++) {
            const candidates = await page.$$eval('[data-action], [data-action-change]', els => els
                .filter(e => e.offsetParent !== null || e.getClientRects().length > 0)
                .map(e => ({
                    name: e.getAttribute('data-action') || e.getAttribute('data-action-change'),
                    kind: e.hasAttribute('data-action') ? 'click' : 'change',
                    withId: e.hasAttribute('data-id'),
                })));
            const keyOf = c => `${c.kind}|${c.name}|${c.withId ? 'id' : '-'}`;
            // Schliessende Aktionen zuletzt, sonst schliessen sie den Dialog vor dessen uebrigen Knoepfen
            const fresh = candidates.filter(c => !done.has(keyOf(c)))
                .sort((a, b) => Number(CLOSING.test(a.name)) - Number(CLOSING.test(b.name)));
            if (fresh.length === 0) break;
            let clicked = false;
            for (const c of fresh) {
                const key = keyOf(c);
                if (done.has(key)) continue;
                if (SKIP.test(c.name)) { done.add(key); skipped.add(c.name); continue; }
                const ok = await page.evaluate(({ name, kind, withId }) => {
                    const attr = kind === 'click' ? 'data-action' : 'data-action-change';
                    const el = [...document.querySelectorAll(`[${attr}="${name}"]`)]
                        .find(e => e.hasAttribute('data-id') === withId && (e.offsetParent !== null || e.getClientRects().length > 0));
                    if (!el) return false;
                    if (kind === 'click') el.click();
                    else el.dispatchEvent(new Event('change', { bubbles: true }));
                    return true;
                }, c);
                if (!ok) continue;
                done.add(key);
                triggered.add(c.name);
                clicked = true;
                // Nachgeladene Inhalte (etwa Zeitraeume im Mitglieder-Dialog) abwarten
                await wait(300);
                await page.waitForNetworkIdle({ idleTime: 300, timeout: 4000 }).catch(() => {});
                // Neu sichtbar gewordene Knoepfe (etwa im Dialog) erst ansehen, bevor es weitergeht
                break;
            }
            if (!clicked) break;
        }
        await closeDialogs();
        void label;
    }

    // Kalender-Popup mit der gesuchten Aktion oeffnen: alle belegten Tage des angezeigten
    // Monats, dann Vormonat, dann Folgemonat (gedeckelt). Am Ende steht der Kalender wieder
    // im Ausgangsmonat, sofern nichts gefunden wurde; bei Erfolg bleibt der Fundmonat.
    async function openPopupWith(name) {
        const nav = dir => page.evaluate(d => document.querySelector(`[data-action="${d}"]`)?.click(), dir)
            .then(() => wait(700));
        const tryMonth = async () => {
            const count = await page.evaluate(() => document.querySelectorAll('.calendar-day.has-event').length);
            for (let i = 0; i < Math.min(count, 40); i++) {
                const found = await page.evaluate((idx, n) => {
                    document.querySelector('.calendar-event-popup')?.remove();
                    const day = document.querySelectorAll('.calendar-day.has-event')[idx];
                    if (!day) return false;
                    day.click();
                    return !!document.querySelector(`.calendar-event-popup [data-action="${n}"]`);
                }, i, name);
                if (found) return true;
            }
            return false;
        };
        if (await tryMonth()) return true;
        await nav('previous-month');
        if (await tryMonth()) return true;
        await nav('next-month'); await nav('next-month');
        if (await tryMonth()) return true;
        await nav('previous-month');
        return false;
    }

    for (const section of sections) {
        if (onlySection && section !== onlySection) continue;
        await page.evaluate(s => document.querySelector(`.nav-item[data-section="${s}"]`).click(), section);
        await wait(1500);
        await sweep(section);

        // Untertabs (Einstellungen, Import/Export ...): jeden oeffnen und dort erneut ausloesen
        const TABS = '.settings-tab-btn, .tab-btn, [role="tab"]';
        const tabCount = await page.evaluate(sel => [...document.querySelectorAll(sel)]
            .filter(e => e.offsetParent !== null || e.getClientRects().length > 0).length, TABS);
        for (let t = 0; t < tabCount; t++) {
            const clicked = await page.evaluate((sel, n) => {
                const el = [...document.querySelectorAll(sel)]
                    .filter(e => e.offsetParent !== null || e.getClientRects().length > 0)[n];
                if (!el) return false;
                el.click();
                return true;
            }, TABS, t);
            if (!clicked) break;
            await wait(500);
            await sweep(`${section}-tab${t}`);
        }

        // Anwesenheitsmodi: Termin- bzw. Mitglieds-Filter setzen (change-Listener per
        // addEventListener, daher kein data-action), dort erneut ausloesen, zuruecksetzen.
        if (section === 'anwesenheit') {
            for (const id of ['filterAppointment', 'filterMember']) {
                const set = await page.evaluate(sel => {
                    const s = document.getElementById(sel);
                    if (!s || s.disabled) return false;
                    const opt = [...s.options].find(o => o.value !== '');
                    if (!opt) return false;
                    s.value = opt.value;
                    s.dispatchEvent(new Event('change', { bubbles: true }));
                    return true;
                }, id);
                if (!set) { notes.push(`Filter #${id} nicht setzbar (keine Option oder gesperrt)`); continue; }
                await wait(500);
                await page.waitForNetworkIdle({ idleTime: 300, timeout: 4000 }).catch(() => {});
                await sweep(`anwesenheit-${id}`);
                // Zuruecksetzen, sonst ist der andere Filter gesperrt
                await page.evaluate(sel => {
                    const s = document.getElementById(sel);
                    s.value = '';
                    s.dispatchEvent(new Event('change', { bubbles: true }));
                }, id);
                await wait(500);
                await page.waitForNetworkIdle({ idleTime: 300, timeout: 4000 }).catch(() => {});
            }
        }

        // Kalender-Popup: jede Popup-Aktion einzeln, das Popup wird je Aktion neu geoeffnet
        // (die Aktion schliesst es). Sprung zur Anwesenheit zuletzt, er verlaesst die Sektion.
        if (section === 'termine') {
            for (const name of ['calendar-open-responses', 'calendar-open-appointment', 'calendar-new-appointment', 'calendar-jump-to-attendance']) {
                if (!await openPopupWith(name)) { notes.push(`Kalender-Popup mit ${name} nicht gefunden (Monat -1..+1)`); continue; }
                await page.evaluate(n => document.querySelector(`.calendar-event-popup [data-action="${n}"]`).click(), name);
                triggered.add(name);
                done.add(`click|${name}|id`); done.add(`click|${name}|-`);
                await wait(500);
                await page.waitForNetworkIdle({ idleTime: 300, timeout: 4000 }).catch(() => {});
                // Folgeansicht (Dialog bzw. Anwesenheit mit Zurueck-Knopf) durchgehen
                await sweep(`popup-${name}`);
                if (name === 'calendar-jump-to-attendance') {
                    // Zurueck in den Kalender, falls der Zurueck-Knopf ihn nicht schon zeigte
                    await page.evaluate(() => document.querySelector('.nav-item[data-section="termine"]').click());
                    await wait(1000);
                }
            }
            await page.evaluate(() => document.querySelector('[data-action="go-to-today"]')?.click());
        }
        await closeDialogs();
    }

    await page.evaluate(() => window.__scanActions());
    for (const n of await page.evaluate(() => [...window.__seenActions])) seen.add(n);

    const cspViolations = await page.evaluate(() => window.__csp);
    for (const v of cspViolations) problems.push(`CSP-Verstoss: ${v}`);

    // Gegenprobe: Ein eingeschleuster onclick muss unter CSP blockiert werden.
    const probe = await page.evaluate(async () => {
        window.__probeRan = false;
        const before = window.__csp.length;
        const holder = document.createElement('div');
        holder.innerHTML = '<button id="csp-probe" onclick="window.__probeRan = true">p</button>';
        document.body.appendChild(holder);
        document.getElementById('csp-probe').click();
        await new Promise(r => setTimeout(r, 200));
        holder.remove();
        return { ran: window.__probeRan, reported: window.__csp.length > before };
    });

    console.log(`CSP-Kopfzeile auf /: ${cspHeader ? 'ja' : 'NEIN'}`);
    console.log(`Gegenprobe onclick: ${probe.ran ? 'AUSGEFUEHRT' : 'blockiert'}${probe.reported ? ', gemeldet' : ''}`);
    if (cspHeader && (probe.ran || !probe.reported)) problems.push('Gegenprobe: eingeschleuster onclick lief trotz CSP');
    if (!cspHeader && !probe.ran) problems.push('Gegenprobe: ohne CSP muss der eingeschleuste onclick laufen');

    // Ausgeloeste und uebersprungene Aktionen standen im DOM und zaehlen als gesehen.
    const neverSeen = [...registeredActions()].filter(n => !seen.has(n) && !triggered.has(n) && !skipped.has(n)).sort();
    // Im DOM gesehen, aber weder ausgeloest noch bewusst uebersprungen (etwa versteckt bis zu einem Sprung).
    const reg = registeredActions();
    const seenNotTriggered = [...seen].filter(n => reg.has(n) && !triggered.has(n) && !skipped.has(n)).sort();
    console.log(`Ausgeloest: ${triggered.size}  Uebersprungen: ${skipped.size}  Gesehen, aber nie ausgeloest: ${seenNotTriggered.length}  Registriert, aber nie gesehen: ${neverSeen.length}`);
    for (const n of notes) console.log(`  Hinweis: ${n}`);
    console.log(`  ausgeloest: ${[...triggered].sort().join(', ')}`);
    console.log(`  uebersprungen: ${[...skipped].sort().join(', ')}`);
    console.log(`  gesehen, aber nie ausgeloest (${seenNotTriggered.length}): ${seenNotTriggered.join(', ') || '-'}`);
    console.log(`  registriert, aber nie gesehen (${neverSeen.length}): ${neverSeen.join(', ') || '-'}`);
    if (onlySection) console.log('  (mit --section ist diese Liste erwartungsgemaess laenger)');
} finally {
    await browser.close();
}

if (problems.length) {
    console.error(`\n${problems.length} Problem(e):`);
    for (const p of [...new Set(problems)]) console.error(`  ${p}`);
    process.exit(1);
}
console.log('\nOK');
