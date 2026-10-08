/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Service Worker der Check-in-PWA: haelt den App-Rahmen vor (OI-43, Stufe 1).
//
// Im Speicher liegt nur der Rahmen — HTML, CSS, JS, Icons. Daten nicht: Jeder
// API-Abruf geht unberuehrt ans Netz. Ohne Verbindung erscheint damit der
// Startbildschirm der App mit "Server nicht erreichbar." statt der Fehlerseite
// des Browsers.
//
// VERSION wird beim Versionssprung mitgezogen (tests/suites/pwa_cache_frontend.php
// meldet es). Eine neue VERSION ist ein neuer Service Worker mit neuem
// Speicher; er wartet, bis das Mitglied ihn ueber die Hinweisleiste der App
// uebernimmt — ein Neuladen mitten in einer Eingabe waere schlimmer als ein
// Rahmen, der einen Tag aelter ist.
//
// Alle Pfade sind relativ zu dieser Datei. Bis 2025 standen hier absolute
// Pfade ('/index.html'), die nach dem Umbau auf die Web-Root public/ ins
// Dashboard zeigten; cache.addAll() scheiterte, und die Zwischenspeicherung
// wurde abgeschaltet (ffe4690).

const VERSION = '1.22.2';
const CACHE_PREFIX = 'checkin-';
const CACHE_NAME = CACHE_PREFIX + VERSION;

const SHELL = [
    'index.html',
    `css/style.css?v=${VERSION}`,
    `js/app.js?v=${VERSION}`,
    'manifest.json',
    'icon-192.png',
    'icon-512.png',
    'icon-maskable-192.png',
    'icon-maskable-512.png',
    'apple-touch-icon.png',
    '../js/vendor/html5-qrcode.min.js',
    '../assets/logo-default.png',
];

const SHELL_URLS = new Set(SHELL.map(path => new URL(path, self.location).href));
const INDEX_URL = new URL('index.html', self.location).href;
const SCOPE_URL = new URL('./', self.location).href;

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        // Gibt es noch keinen eigenen Speicher, laeuft hier die
        // Erstinstallation oder der Umstieg vom Durchreich-Worker bis 1.22.x.
        // Dann gibt es keinen alten Rahmen, der mit dem neuen durcheinander-
        // geraten koennte, und der neue Worker uebernimmt sofort.
        const hadCache = (await caches.keys()).some(key => key.startsWith(CACHE_PREFIX));

        try {
            const cache = await caches.open(CACHE_NAME);
            // cache: 'reload' umgeht den HTTP-Cache des Browsers: Der Rahmen
            // soll aus derselben Auslieferung stammen wie diese Datei.
            await cache.addAll(SHELL.map(path => new Request(path, { cache: 'reload' })));
        } catch (error) {
            // Kein halber Speicher: Er liesse die naechste Installation
            // glauben, es gebe schon einen Rahmen.
            await caches.delete(CACHE_NAME);
            throw error;
        }

        if (!hadCache) {
            await self.skipWaiting();
        }
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME) {
                await caches.delete(key);
            }
        }
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    let key;
    if (request.mode === 'navigate') {
        // Startadresse aus dem Manifest, Verzeichnisaufruf und Rueckmelde-Link
        // (#rueckmeldung=..., das Fragment kommt hier nicht an) fuehren alle
        // auf dieselbe Seite.
        const url = new URL(request.url);
        url.search = '';
        if (url.href !== SCOPE_URL && url.href !== INDEX_URL) {
            return;
        }
        key = INDEX_URL;
    } else if (SHELL_URLS.has(request.url)) {
        key = request.url;
    } else {
        // Nicht beantworten: Der Browser holt es wie ohne Service Worker.
        return;
    }

    event.respondWith((async () => {
        const cache = await caches.open(CACHE_NAME);
        return (await cache.match(key)) || fetch(request);
    })());
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
