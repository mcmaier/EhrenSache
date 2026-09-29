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
// Zwischenablage brauchen, werden uebersprungen (SKIP). Ein geoeffneter
// Dialog wird danach mit Escape geschlossen.
//
// Fehler (Exit-Code 1): CSP-Verstoss, Laufzeitfehler, "Unbekannte Aktion".
// Nur Auskunft: welche Aktionen ausgeloest, uebersprungen, nie gesehen wurden.
//
// Aufruf:  node tests/browser/click-through.mjs [--section=mitglieder]
// Konfiguration aus tests/config.php (base_url, admin). PHP_BIN und CHROME_BIN
// sind per Umgebungsvariable ueberschreibbar.

import puppeteer from 'puppeteer-core';
import { execFileSync } from 'node:child_process';
import { readdirSync, existsSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PHP = process.env.PHP_BIN || 'C:/xampp/php/php.exe';
const onlySection = (process.argv.find(a => a.startsWith('--section=')) || '').split('=')[1] || null;

// Veraendert Daten, laedt herunter, druckt, greift nach aussen oder in die
// Zwischenablage -- hier nie ausloesen.
const SKIP = /^(delete-|save-|execute-|perform-|regenerate-|generate-|quick-|approve-|reject-|send-|set-member-|set-own-|withdraw-|create-|analyze-|export-|download-|print-|check-for-|copy-|remove-membership|add-membership|toggle-responses-lock|import-logs-delete|app-reset-reload|auth-reload|calendar-new-appointment)/;

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

    // Alle sichtbaren, noch nicht versuchten Aktionen ausloesen.
    async function sweep(label) {
        // Geoeffnete Dialoge bleiben zwischen den Runden offen, damit ihre Knoepfe
        // in der naechsten Runde gefunden werden; erst am Ende wird geschlossen.
        for (let round = 0; round < 5; round++) {
            const candidates = await page.$$eval('[data-action], [data-action-change]', els => els
                .filter(e => e.offsetParent !== null || e.getClientRects().length > 0)
                .map((e, i) => ({
                    i,
                    name: e.getAttribute('data-action') || e.getAttribute('data-action-change'),
                    kind: e.hasAttribute('data-action') ? 'click' : 'change',
                })));
            // close-* zuletzt, sonst schliesst es den Dialog vor dessen uebrigen Knoepfen
            const fresh = candidates.filter(c => !triggered.has(c.name) && !skipped.has(c.name))
                .sort((a, b) => Number(a.name.startsWith('close-')) - Number(b.name.startsWith('close-')));
            if (fresh.length === 0) break;
            for (const c of fresh) {
                if (triggered.has(c.name) || skipped.has(c.name)) continue;
                if (SKIP.test(c.name)) { skipped.add(c.name); continue; }
                const ok = await page.evaluate(({ name, kind }) => {
                    const sel = kind === 'click' ? `[data-action="${name}"]` : `[data-action-change="${name}"]`;
                    const el = [...document.querySelectorAll(sel)].find(e => e.offsetParent !== null || e.getClientRects().length > 0);
                    if (!el) return false;
                    if (kind === 'click') el.click();
                    else el.dispatchEvent(new Event('change', { bubbles: true }));
                    return true;
                }, c);
                if (!ok) continue;
                triggered.add(c.name);
                await wait(400);
                // Neu sichtbar gewordene Knoepfe (etwa im Dialog) in der naechsten Runde
            }
        }
        for (let i = 0; i < 2; i++) {
            await page.keyboard.press('Escape');
            await wait(300);
        }
        void label;
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

        // Kalender: das festgehaltene Popup eines belegten Tages oeffnen
        if (section === 'termine') {
            const opened = await page.evaluate(() => {
                const day = document.querySelector('.calendar-day.has-event');
                if (!day) return false;
                day.click();
                return true;
            });
            if (opened) { await wait(500); await sweep('kalender-popup'); }
        }
        await page.keyboard.press('Escape');
        await wait(300);
    }

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

    console.log(`Ausgeloest: ${triggered.size}  Uebersprungen: ${skipped.size}`);
    console.log(`  ausgeloest: ${[...triggered].sort().join(', ')}`);
    console.log(`  uebersprungen: ${[...skipped].sort().join(', ')}`);
} finally {
    await browser.close();
}

if (problems.length) {
    console.error(`\n${problems.length} Problem(e):`);
    for (const p of [...new Set(problems)]) console.error(`  ${p}`);
    process.exit(1);
}
console.log('\nOK');
