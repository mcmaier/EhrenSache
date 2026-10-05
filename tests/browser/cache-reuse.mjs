/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Version im Pfad (OI-120): Der zweite Aufruf von Dashboard und Anmeldeseite
// darf fuer CSS und JS keine Anfrage an den Server schicken -- alles kommt aus
// dem Browser-Speicher. Dazu: jede geladene CSS/JS-Adresse traegt den
// Versionsabschnitt, keine Datei kommt unter zwei Adressen, keine
// Laufzeitfehler.
//
// Ohne Request-Interception: sie schaltet den Browser-Cache ab.
//
// Aufruf:  node tests/browser/cache-reuse.mjs
// Konfiguration aus tests/config.php (base_url, admin); ES_BASE_URL
// ueberschreibt base_url (Gegenprobe gegen einen alten Stand).

import puppeteer from 'puppeteer-core';
import { execFileSync } from 'node:child_process';
import { readdirSync, existsSync, readFileSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PHP  = process.env.PHP_BIN || 'C:/xampp/php/php.exe';
const VERSION = JSON.parse(readFileSync(join(ROOT, 'version.json'), 'utf8')).version;

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

const cfg  = config();
const BASE = (process.env.ES_BASE_URL || cfg.base_url).replace(/\/$/, '');
const failures = [];
const results  = [];

function check(name, ok, detail = '') {
    results.push(`${ok ? 'OK  ' : 'FAIL'} ${name}${detail ? ` -- ${detail}` : ''}`);
    if (!ok) failures.push(name);
}

const isAsset = url => /\/(css|js)\//.test(new URL(url).pathname);

/** Laedt url nach about:blank und sammelt alle CSS/JS-Antworten. */
async function load(page, url, ready) {
    const seen = [];
    const onResponse = r => { if (isAsset(r.url())) seen.push({ url: r.url(), cached: r.fromCache(), status: r.status() }); };
    page.on('response', onResponse);
    await page.goto('about:blank');
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
    if (ready) await page.waitForFunction(ready, { timeout: 20000 });
    page.off('response', onResponse);
    return seen;
}

const browser = await puppeteer.launch({ executablePath: chromePath(), headless: 'new' });
try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    page.on('dialog', d => d.dismiss());
    page.on('pageerror', e => failures.push(`Laufzeitfehler: ${e.message}`));

    // Anmeldeseite: erster und zweiter Aufruf
    const loginFirst  = await load(page, `${BASE}/login.html`);
    const loginSecond = await load(page, `${BASE}/login.html`);
    check('Login: alle CSS/JS-Adressen tragen die Version',
        loginFirst.length > 0 && loginFirst.every(r => r.url.includes(`/v${VERSION}/`)),
        loginFirst.filter(r => !r.url.includes(`/v${VERSION}/`)).map(r => r.url).join(', '));
    check('Login: zweiter Aufruf ohne CSS/JS-Anfrage an den Server',
        loginSecond.length > 0 && loginSecond.every(r => r.cached),
        `${loginSecond.filter(r => !r.cached).length} von ${loginSecond.length} vom Server`);

    // Anmelden
    await page.evaluate((email, pw) => {
        const p = document.querySelector('input[type=password]');
        const f = p.closest('form');
        f.querySelector('input[type=email]').value = email;
        p.value = pw;
        (f.querySelector('button[type=submit]') || [...f.querySelectorAll('button')].pop()).click();
    }, cfg.admin.email, cfg.admin.password);
    await page.waitForSelector('#dashboard.active', { timeout: 20000 });

    const ready = () => document.getElementById('dashboard')?.classList.contains('active');
    const dashFirst  = await load(page, `${BASE}/index.html`, ready);
    const dashSecond = await load(page, `${BASE}/index.html`, ready);

    check('Dashboard: alle CSS/JS-Adressen tragen die Version',
        dashFirst.length > 0 && dashFirst.every(r => r.url.includes(`/v${VERSION}/`)),
        dashFirst.filter(r => !r.url.includes(`/v${VERSION}/`)).map(r => r.url).join(', '));

    const paths = dashFirst.map(r => new URL(r.url).pathname.replace(/\/v[0-9][0-9.]*\//, '/'));
    const dupes = paths.filter((p, i) => paths.indexOf(p) !== i);
    check('Dashboard: keine Datei unter zwei Adressen', dupes.length === 0, dupes.join(', '));

    check('Dashboard: zweiter Aufruf ohne CSS/JS-Anfrage an den Server',
        dashSecond.length > 0 && dashSecond.every(r => r.cached),
        `${dashSecond.filter(r => !r.cached).length} von ${dashSecond.length} vom Server: `
        + dashSecond.filter(r => !r.cached).slice(0, 5).map(r => new URL(r.url).pathname).join(', '));
} finally {
    await browser.close();
}

console.log(results.join('\n'));
if (failures.length) {
    console.log(`\n${failures.length} Fehlschlag/Fehlschlaege:\n- ${failures.join('\n- ')}`);
    process.exit(1);
}
console.log('\nAlle Pruefungen bestanden.');
