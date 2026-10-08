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
    './',
    `css/style.css?v=${VERSION}`,
    `js/app.js?v=${VERSION}`,
    `js/snapshot.js?v=${VERSION}`,
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
const SCOPE_URL = new URL('./', self.location).href;
// Nur zum Vergleich: Apache leitet index.html auf ./ um, gespeichert ist ./ .
const INDEX_URL = new URL('index.html', self.location).href;

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        // Gibt es noch keinen eigenen Speicher, laeuft hier die
        // Erstinstallation oder der Umstieg vom Durchreich-Worker bis 1.22.x.
        // Dann gibt es keinen alten Rahmen, der mit dem neuen durcheinander-
        // geraten koennte, und der neue Worker uebernimmt sofort. Loescht der
        // Browser die Speicher (Speicherdruck, "Websitedaten loeschen"),
        // uebernimmt die naechste neue Version ebenfalls sofort, ohne
        // Hinweisleiste — hinnehmbar.
        const hadCache = (await caches.keys()).some(key => key.startsWith(CACHE_PREFIX));

        // Gleiche VERSION kommt nur ausserhalb eines Releases vor (Feature-Zweig,
        // dev); ein Release zieht VERSION immer mit (Test). Dann oeffnet der neue
        // Worker denselben Speicher wie der aktive und darf ihn bei einem
        // Fehler nicht loeschen.
        const existed = await caches.has(CACHE_NAME);

        try {
            // cache: 'reload' umgeht den HTTP-Cache des Browsers: Der Rahmen
            // soll aus derselben Auslieferung stammen wie diese Datei.
            const requests = SHELL.map(path => new Request(path, { cache: 'reload' }));
            // Erst alles holen, dann schreiben: Ein Fehler hinterlaesst nichts
            // Halbes.
            const responses = await Promise.all(requests.map(async (request) => {
                let response = await fetch(request);
                if (!response.ok) {
                    throw new Error(`${request.url}: ${response.status}`);
                }
                // Apache leitet index.html per 301 auf ./ um. Eine umgeleitete
                // Antwort verweigert der Browser fuer Seitenaufrufe (Netzwerk-
                // fehler statt Seite), auch online. Darum neu verpacken — so
                // macht es auch Workbox.
                if (response.redirected) {
                    response = new Response(await response.blob(), {
                        status: response.status,
                        statusText: response.statusText,
                        headers: response.headers,
                    });
                }
                return response;
            }));
            const cache = await caches.open(CACHE_NAME);
            await Promise.all(requests.map((request, i) => cache.put(request, responses[i])));
        } catch (error) {
            // Kein halber Speicher: Er liesse die naechste Installation
            // glauben, es gebe schon einen Rahmen.
            if (!existed) {
                await caches.delete(CACHE_NAME);
            }
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
        // (#rueckmeldung=...) fuehren alle auf dieselbe Seite. Chrome reicht
        // bei Seitenaufrufen das Fragment in request.url mit; der Rueckmelde-
        // Link wird deshalb erst nach dem Entfernen erkannt — sonst zeigt er
        // offline die Fehlerseite (gefunden mit tests/browser/pwa-offline.mjs).
        const url = new URL(request.url);
        url.search = '';
        url.hash = '';
        if (url.href !== SCOPE_URL && url.href !== INDEX_URL) {
            return;
        }
        key = SCOPE_URL;
    } else if (SHELL_URLS.has(request.url)) {
        key = request.url;
    } else {
        // Nicht beantworten: Der Browser holt es wie ohne Service Worker.
        return;
    }

    event.respondWith((async () => {
        try {
            const cache = await caches.open(CACHE_NAME);
            const cached = await cache.match(key);
            if (cached) {
                return cached;
            }
        } catch (error) {
            // Speicher nicht lesbar: wie ohne Service Worker.
        }
        return fetch(request);
    })());
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
