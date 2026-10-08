/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// App-Rahmen der Check-in-PWA (OI-43, Stufe 1) im Browser pruefen.
//
// Ablauf in einem frischen Profil: Check-in-App oeffnen und als user anmelden,
// warten bis der Service Worker die Seite steuert; Speicher checkin-<Version>
// muss existieren, und die Erstinstallation darf kein Neuladen ausloesen.
// Offline schalten und neu laden (./, index.html, Rueckmelde-Link): Die Seite
// kommt aus dem Speicher, der Startbildschirm meldet "Server nicht erreichbar.".
// Wieder online, service-worker.js voruebergehend mit anderer VERSION
// ueberschreiben und neu laden: Die Hinweisleiste erscheint, der Knopf laedt
// genau einmal neu, danach gibt es nur noch den neuen Speicher.
// service-worker.js wird im finally zurueckgeschrieben.
//
// Aufruf:  node tests/browser/pwa-offline.mjs
// Konfiguration aus tests/config.php (base_url, user); ES_BASE_URL
// ueberschreibt base_url. Laeuft gegen die Installation, deren Dateien in
// diesem Arbeitsbaum liegen — base_url muss auf denselben Baum zeigen.

import puppeteer from 'puppeteer-core';
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const PHP  = process.env.PHP_BIN || 'C:/xampp/php/php.exe';
const SW   = join(ROOT, 'public', 'checkin', 'service-worker.js');
const PROBE_VERSION = '0.0.0-pwa-offline';

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

const cfg     = config();
const BASE    = (process.env.ES_BASE_URL || cfg.base_url).replace(/\/$/, '');
const VERSION = JSON.parse(readFileSync(join(ROOT, 'version.json'), 'utf8')).version;

const failures = [];
function check(name, ok, detail = '') {
    console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}${detail ? ` -- ${detail}` : ''}`);
    if (!ok) failures.push(name);
}

const sleep     = ms => new Promise(r => setTimeout(r, ms));
const cacheKeys = page => page.evaluate(async () => caches.keys());
const startText = page => page.evaluate(() => document.getElementById('startStatus')?.textContent || '(leer)');

// Wartet auf die Offline-Meldung des Startbildschirms; liefert den Text.
async function offlineStartShown(page) {
    await page.waitForFunction(
        () => (document.getElementById('startStatus')?.textContent || '').includes('nicht erreichbar'),
        { timeout: 20000 }).catch(() => {});
    return startText(page);
}

const original = readFileSync(SW, 'utf8');
if (!original.includes(`const VERSION = '${VERSION}'`)) {
    console.error(`Abbruch: service-worker.js enthaelt nicht "const VERSION = '${VERSION}'". `
        + 'Rest eines abgebrochenen Laufs? Dann: git checkout public/checkin/service-worker.js');
    process.exit(1);
}
const restore = () => writeFileSync(SW, original);
for (const sig of ['SIGINT', 'SIGTERM']) {
    process.once(sig, () => { restore(); process.exit(130); });
}
const browser  = await puppeteer.launch({ executablePath: chromePath(), headless: 'new' });

try {
    const page = await browser.newPage();
    page.on('dialog', d => d.dismiss());

    let navCount = 0;
    page.on('framenavigated', f => { if (f === page.mainFrame()) navCount++; });

    // 1. Anmelden, Service Worker uebernimmt
    await page.goto(`${BASE}/checkin/`, { waitUntil: 'networkidle2' });
    await page.waitForSelector('#loginScreen.active', { timeout: 10000 });
    await page.type('#emailInput', cfg.user.email);
    await page.type('#passwordInput', cfg.user.password);
    await page.click('#loginForm button[type=submit]');
    await page.waitForSelector('#mainScreen.active', { timeout: 20000 });
    navCount = 0;
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null, { timeout: 15000 });
    await sleep(3000);
    check('Erstinstallation: kein automatisches Neuladen', navCount === 0, `${navCount} Navigation(en)`);

    const keys1 = await cacheKeys(page);
    check(`Speicher checkin-${VERSION} angelegt`, keys1.includes(`checkin-${VERSION}`), keys1.join(', '));
    check('Hinweisleiste nach Erstinstallation versteckt',
        await page.$eval('#updateBanner', el => el.hidden));

    // Schnappschuss (Stufe 2): Termine- und Verlauf-Tab laden, danach liegt er im Speicher
    const responsesTab = await page.$('.tab-button[data-tab="responses"]:not([hidden])');
    if (responsesTab) {
        await responsesTab.click();
        await page.waitForSelector('#responsesList', { timeout: 15000 });
        await sleep(1500);
    }
    await page.click('.tab-button[data-tab="history"]');
    await page.waitForSelector('#historyList .history-item', { timeout: 15000 });
    await sleep(500);
    check('Schnappschuss geschrieben', await page.evaluate(() => {
        const s = JSON.parse(localStorage.getItem('offline_snapshot') || 'null');
        return !!(s && s.history && s.history.items.length > 0);
    }));

    // 2. Offline: Rahmen aus dem Speicher, App meldet fehlenden Server
    await page.setOfflineMode(true);
    await page.reload({ waitUntil: 'domcontentloaded' });
    const offlineText = await offlineStartShown(page);
    check('offline: Startbildschirm "Server nicht erreichbar."', offlineText.includes('nicht erreichbar'), offlineText);
    check('offline: Stylesheet aus dem Speicher geladen', await page.evaluate(() =>
        [...document.styleSheets].some(s => (s.href || '').includes('css/style.css') && s.cssRules.length > 0)));
    check('offline: Token bleibt gespeichert',
        await page.evaluate(() => localStorage.getItem('api_token') !== null));

    // Letzter Stand aus dem Schnappschuss
    check('offline: Knopf „Letzten Stand ansehen“ sichtbar', await page.$eval('#startSnapshotBtn',
        el => !el.hidden && el.textContent.includes('Letzten Stand')));
    await page.click('#startSnapshotBtn');
    await page.waitForSelector('#snapshotScreen.active', { timeout: 5000 });
    check('Letzter Stand: Leiste nennt „ohne Verbindung“', await page.$eval('#snapshotStamp',
        el => el.textContent.includes('ohne Verbindung')));
    const sections = await page.$$eval('#snapshotContent .snapshot-section', ss => ss.map(s => ({
        title: s.querySelector('h3')?.textContent.trim() || '',
        entries: s.querySelectorAll('.snapshot-entry').length,
    })));
    const verlauf = sections.find(s => s.title === 'Verlauf');
    check('Letzter Stand: Verlauf mit Einträgen', !!verlauf && verlauf.entries > 0, JSON.stringify(sections));
    if (responsesTab) {
        const termine = sections.find(s => s.title === 'Kommende Termine');
        check('Letzter Stand: Kommende Termine vorhanden', !!termine, JSON.stringify(sections));
    }
    check('Letzter Stand: keine Knöpfe außer „Erneut verbinden“', await page.$$eval('#snapshotScreen button',
        b => b.length === 1 && b[0].dataset.action === 'snapshot-reconnect'));

    // Weitere Einstiege: index.html wird von Apache auf ./ umgeleitet, ein
    // Rueckmelde-Link traegt ein Fragment. Beide muessen aus dem Speicher kommen.
    for (const [label, url] of [['index.html', `${BASE}/checkin/index.html`],
                                ['Rückmelde-Link', `${BASE}/checkin/#rueckmeldung=1`]]) {
        let error = '';
        try {
            await page.goto(url, { waitUntil: 'domcontentloaded' });
        } catch (e) {
            error = e.message;
        }
        const text   = error ? '' : await offlineStartShown(page);
        const hasDom = !error && await page.evaluate(() =>
            !!document.getElementById('startScreen') && !!document.getElementById('startStatus'));
        check(`offline: ${label} lädt die App`, hasDom && text.includes('nicht erreichbar'), error || text);
    }
    await page.setOfflineMode(false);

    // 3. Neue Version: Leiste erscheint, Knopf uebernimmt
    const probe = original.replace(/const VERSION = '[^']+'/, `const VERSION = '${PROBE_VERSION}'`);
    if (probe === original) throw new Error('VERSION in service-worker.js nicht ersetzt (Muster trifft nichts)');
    writeFileSync(SW, probe);
    await page.goto(`${BASE}/checkin/`, { waitUntil: 'networkidle2' });
    const bannerShown = await page.waitForSelector('#updateBanner:not([hidden])', { timeout: 20000 })
        .then(() => true, () => false);
    check('neue Version: Hinweisleiste erscheint', bannerShown);

    if (bannerShown) {
        navCount = 0;
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }),
            page.click('#updateReloadBtn'),
        ]);
        await sleep(3000);
        check('nach dem Knopf: genau ein Neuladen', navCount === 1, `${navCount} Navigation(en)`);
        const keys2 = await cacheKeys(page);
        check('nach dem Knopf: nur der neue Speicher',
            keys2.includes(`checkin-${PROBE_VERSION}`) && !keys2.includes(`checkin-${VERSION}`), keys2.join(', '));
        check('nach dem Knopf: Leiste wieder versteckt',
            await page.$eval('#updateBanner', el => el.hidden));
    }

    // 4. Abmelden loescht Token und Schnappschuss
    await page.waitForSelector('#mainScreen.active', { timeout: 15000 });
    await page.click('#logoutBtn');
    await page.waitForSelector('#pwaConfirmYes', { visible: true, timeout: 5000 });
    await page.click('#pwaConfirmYes');
    await page.waitForSelector('#loginScreen.active', { timeout: 15000 });
    check('Abmelden: Token und Schnappschuss gelöscht', await page.evaluate(() =>
        localStorage.getItem('api_token') === null && localStorage.getItem('offline_snapshot') === null));
} finally {
    restore();
    await browser.close();
}

console.log(failures.length ? `\n${failures.length} Pruefung(en) fehlgeschlagen` : '\nAlle Pruefungen bestanden');
process.exit(failures.length ? 1 : 0);
