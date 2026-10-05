/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Startkette (OI-121): zaehlt Wartestufen, statt Zeit zu messen.
//
// Jede API-Antwort wird um DELAY ms verzoegert. Wer Stufe fuer Stufe laedt,
// braucht ein Vielfaches davon. Gemessen wird der Mehraufwand gegenueber
// einem Lauf ohne Verzoegerung (Seitenaufbau, Servertempo fallen heraus):
// zwei Stufen bleiben unter LIMIT, drei nicht. Dazu: einmalige Wiederholung bei 503 (nur GET),
// Abmeldung nur bei 401/403, Ladeanzeige statt Anmeldemaske.
//
// Aufruf:  node tests/browser/startup-chain.mjs
// Konfiguration aus tests/config.php (base_url, admin, user); ES_BASE_URL
// ueberschreibt base_url (Gegenprobe gegen einen alten Stand).

import puppeteer from 'puppeteer-core';
import { execFileSync } from 'node:child_process';
import { readdirSync, existsSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT  = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PHP   = process.env.PHP_BIN || 'C:/xampp/php/php.exe';
const DELAY = 500;
const LIMIT = 2.5 * DELAY;

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

// Steuerung der Abfangschicht: delay je API-Antwort, rule liefert eine
// gefaelschte Antwort oder null (dann geht die Anfrage durch).
let delay = 0;
let rule  = () => null;
const hits = [];

async function newPage(browser) {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    await page.setBypassServiceWorker(true);
    await page.setRequestInterception(true);
    page.on('dialog', d => d.dismiss());
    page.on('pageerror', e => failures.push(`Laufzeitfehler: ${e.message}`));
    page.on('request', req => {
        const url = req.url();
        if (!url.includes('/api/api.php')) {
            req.continue();
            return;
        }
        const resource = new URL(url).searchParams.get('resource');
        const method   = req.method();
        hits.push({ resource, method });
        const fake = rule(resource, method);
        setTimeout(() => (fake ? req.respond(fake) : req.continue()), delay);
    });
    return page;
}

/** Zeit vom Navigationsbeginn bis cond() wahr ist, in ms. */
async function timeUntil(page, url, cond) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    const reached = await page.waitForFunction(cond, { polling: 20, timeout: 20000 }).then(() => true, () => false);
    return reached ? page.evaluate(() => performance.now()) : Infinity;
}

const html503 = { status: 503, contentType: 'text/html', body: 'Service Unavailable' };

const browser = await puppeteer.launch({ executablePath: chromePath(), headless: 'new' });
try {
    // ---------------------------------------------------------------
    // Check-in-App
    // ---------------------------------------------------------------
    const pwa = await newPage(browser);
    await pwa.goto(`${BASE}/checkin/`, { waitUntil: 'networkidle2' });
    await pwa.waitForSelector('#loginScreen.active', { timeout: 10000 });
    await pwa.type('#emailInput', cfg.user.email);
    await pwa.type('#passwordInput', cfg.user.password);
    await pwa.click('#loginForm button[type=submit]');
    await pwa.waitForSelector('#mainScreen.active', { timeout: 20000 });

    // Stufen: Ladeanzeige, dann Hauptbildschirm in hoechstens zwei Stufen.
    const pwaMain = () => document.getElementById('mainScreen')?.classList.contains('active');
    const pwaBase = await timeUntil(pwa, `${BASE}/checkin/`, pwaMain);
    delay = DELAY;
    await pwa.goto(`${BASE}/checkin/`, { waitUntil: 'domcontentloaded' });
    const startShown = await pwa.evaluate(() =>
        document.getElementById('startScreen')?.classList.contains('active')
        && !document.getElementById('loginScreen')?.classList.contains('active'));
    check('PWA: Ladeanzeige statt Anmeldemaske beim Start', startShown);

    const pwaMs = await timeUntil(pwa, `${BASE}/checkin/`, pwaMain) - (Number.isFinite(pwaBase) ? pwaBase : 0);
    check('PWA: Hauptbildschirm nach hoechstens zwei Stufen', pwaMs < LIMIT,
        `+${Math.round(pwaMs)} ms bei ${DELAY} ms je Antwort, Grenze +${LIMIT} ms`);
    delay = 0;

    // GET wird nach 503 einmal wiederholt (PWA, klassisches Skript: apiCall global).
    let served = 0;
    rule = (res) => (res === 'version' && served++ === 0 ? html503 : null);
    hits.length = 0;
    const pwaRetry = await pwa.evaluate(async () => (await apiCall('version')).success);
    check('PWA: GET nach 503 einmal wiederholt',
        pwaRetry === true && hits.filter(h => h.resource === 'version').length === 2,
        `Anfragen: ${hits.filter(h => h.resource === 'version').length}`);

    // POST wird nie wiederholt.
    rule = (res, method) => (res === 'exceptions' && method === 'POST' ? html503 : null);
    hits.length = 0;
    await pwa.evaluate(async () => apiCall('exceptions', 'POST', {}));
    check('PWA: POST nach 503 nicht wiederholt',
        hits.filter(h => h.resource === 'exceptions' && h.method === 'POST').length === 1);

    // me mit 503: Token bleibt, "Erneut versuchen" erscheint und hilft.
    rule = (res) => (res === 'me' ? html503 : null);
    await pwa.goto(`${BASE}/checkin/`, { waitUntil: 'domcontentloaded' });
    await pwa.waitForSelector('#startRetryBtn:not([hidden])', { timeout: 20000 }).catch(() => {});
    const after503 = await pwa.evaluate(() => ({
        token: localStorage.getItem('api_token') !== null,
        retry: !document.getElementById('startRetryBtn')?.hidden,
        login: document.getElementById('loginScreen')?.classList.contains('active')
    }));
    check('PWA: me mit 503 meldet nicht ab', after503.token && after503.retry && !after503.login,
        JSON.stringify(after503));
    rule = () => null;
    await pwa.click('#startRetryBtn').catch(() => {});
    const recovered = await pwa.waitForSelector('#mainScreen.active', { timeout: 20000 }).then(() => true, () => false);
    check('PWA: "Erneut versuchen" startet die App', recovered);

    // me mit 401: Token weg, Anmeldemaske.
    rule = (res) => (res === 'me'
        ? { status: 401, contentType: 'application/json', body: JSON.stringify({ message: 'Invalid or inactive API token' }) }
        : null);
    await pwa.goto(`${BASE}/checkin/`, { waitUntil: 'domcontentloaded' });
    const loggedOut = await pwa.waitForSelector('#loginScreen.active', { timeout: 20000 }).then(() => true, () => false);
    const tokenGone = await pwa.evaluate(() => localStorage.getItem('api_token') === null);
    check('PWA: me mit 401 fuehrt zur Anmeldemaske', loggedOut && tokenGone);
    rule = () => null;
    await pwa.close();

    // ---------------------------------------------------------------
    // Dashboard
    // ---------------------------------------------------------------
    const dash = await newPage(browser);
    await dash.goto(`${BASE}/login.html`, { waitUntil: 'networkidle2' });
    await dash.evaluate((email, pw) => {
        const p = document.querySelector('input[type=password]');
        const f = p.closest('form');
        f.querySelector('input[type=email]').value = email;
        p.value = pw;
        (f.querySelector('button[type=submit]') || [...f.querySelectorAll('button')].pop()).click();
    }, cfg.admin.email, cfg.admin.password);
    await dash.waitForSelector('#dashboard.active', { timeout: 20000 });
    await dash.evaluate(() => sessionStorage.setItem('currentSection', 'profil'));

    const dashReady = () => document.getElementById('dashboard')?.classList.contains('active')
              && (document.getElementById('profile_email')?.value || '') !== '';
    const dashBase = await timeUntil(dash, `${BASE}/index.html`, dashReady);
    delay = DELAY;
    const dashMs = await timeUntil(dash, `${BASE}/index.html`, dashReady) - (Number.isFinite(dashBase) ? dashBase : 0);
    check('Dashboard: Profil nach hoechstens zwei Stufen', dashMs < LIMIT,
        `+${Math.round(dashMs)} ms bei ${DELAY} ms je Antwort, Grenze +${LIMIT} ms`);
    delay = 0;

    await dash.waitForNetworkIdle({ idleTime: 800, timeout: 20000 }).catch(() => {});

    served = 0;
    rule = (res) => (res === 'version' && served++ === 0 ? html503 : null);
    hits.length = 0;
    const dashRetry = await dash.evaluate(async () => {
        const { apiCall } = await import(new URL('js/modules/api.js', location.href).href);
        const r = await apiCall('version');
        return r?.success === true;
    });
    check('Dashboard: GET nach 503 einmal wiederholt',
        dashRetry && hits.filter(h => h.resource === 'version').length === 2,
        `Anfragen: ${hits.filter(h => h.resource === 'version').length}`);

    rule = (res, method) => (res === 'records' && method === 'POST' ? html503 : null);
    hits.length = 0;
    await dash.evaluate(async () => {
        const { apiCall } = await import(new URL('js/modules/api.js', location.href).href);
        await apiCall('records', 'POST', {});
    });
    check('Dashboard: POST nach 503 nicht wiederholt',
        hits.filter(h => h.resource === 'records' && h.method === 'POST').length === 1);
    rule = () => null;
    await dash.close();
} finally {
    await browser.close();
}

console.log(results.join('\n'));
if (failures.length) {
    console.log(`\n${failures.length} Fehlschlag/Fehlschlaege:\n- ${failures.join('\n- ')}`);
    process.exit(1);
}
console.log('\nAlle Pruefungen bestanden.');
