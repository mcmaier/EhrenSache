/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Service Worker der Check-in-PWA: nur für die Installierbarkeit. Kein Cache —
// jede Anfrage geht ans Netz.
//
// Eine Zwischenspeicherung war einmal gebaut und wurde am 2025-12-08 mit
// ffe4690 stillgelegt, dem Umbau auf eine Installation mit Web-Root auf
// public/. Danach zeigten die absoluten Pfade der Vorabladeliste
// ('/index.html', '/css/style.css', '/js/app.js') nicht mehr auf die PWA,
// sondern auf das Dashboard, und '/manifest.json' gab es dort gar nicht.
//
// Der abgeschaltete Code stand seither auskommentiert in dieser Datei,
// zusammen mit CACHE_NAME und urlsToCache — zwei Konstanten, die nach
// Bedeutung aussahen und keine hatten. Am 2026-09-09 entfernt; die Historie
// hält sie fest.
//
// Ob und wie die PWA offline arbeiten soll, ist offen: OI-43 in
// docs/OPEN-ITEMS.md. Die virtuelle Station hat sich bewusst dagegen
// entschieden, siehe public/station/service-worker.js.

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});
