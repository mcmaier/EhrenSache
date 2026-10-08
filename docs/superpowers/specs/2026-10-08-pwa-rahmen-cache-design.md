# Check-in-PWA: App-Rahmen im Service Worker (OI-43, Stufe 1)

**Stand:** 2026-10-08 · **Bezug:** [OI-43](../../OPEN-ITEMS.md#oi-43--offline-betrieb-der-check-in-pwa)

## Ziel

Die Check-in-App lädt ihren Rahmen (HTML, CSS, JS, Icons) aus einem Speicher des Service
Workers. Ohne Netz erscheint der vorhandene Startbildschirm „Server nicht erreichbar.“ statt
der Fehlerseite des Browsers; bei schlechtem Netz wartet nur noch der API-Abruf.

Daten werden **nicht** zwischengespeichert (Stufe 2), Schreibaktionen ohne Netz gibt es nicht
(Stufe 3, verworfen). Die virtuelle Station bleibt ohne Speicher.

## Entscheidungen

| Frage | Entscheidung | Verworfen |
|---|---|---|
| Ladestrategie | Alles aus dem Speicher; neue Version kommt über einen neuen Service Worker, dessen Speichername die Version aus `version.json` trägt | Netz zuerst mit Rückfall (wartet bei schlechtem Netz), Speicher mit Hintergrundaktualisierung (alte Seite und neue Dateien können sich mischen) |
| Neue Version in laufender App | Hinweisleiste „Neue Version verfügbar – Neu laden“; erst der Tipp aktiviert | Automatisch beim Zurückkehren (unterbricht Eingaben), nur beim nächsten Start (bei tagelang offener App zu spät) |

## Service Worker (`public/checkin/service-worker.js`)

- `const VERSION = '<Version>'`; Speichername `checkin-<VERSION>`.
- Vorladeliste, **relativ zum Service Worker**, nie mit führendem `/` (daran scheiterte die
  Zwischenspeicherung 2025):
  `index.html`, `css/style.css?v=<VERSION>`, `js/app.js?v=<VERSION>`, `manifest.json`,
  `icon-192.png`, `icon-512.png`, `icon-maskable-192.png`, `icon-maskable-512.png`,
  `apple-touch-icon.png`, `../js/vendor/html5-qrcode.min.js`, `../assets/logo-default.png`.
  `./` steht nicht in der Liste: Ein Seitenaufruf auf `./` bekommt im `fetch`-Handler die gespeicherte `index.html`.
  Die `?v=`-Einträge entstehen aus `VERSION` und treffen damit genau die Anfragen aus
  `index.html`.
- **install:** alles vorladen (`cache.addAll`). Scheitert eine Datei, scheitert die
  Installation; der bisherige Stand bleibt aktiv. Kein unbedingtes `skipWaiting()`.
  Ausnahme: Existiert noch kein Speicher `checkin-*` (Erstinstallation oder Umstieg vom
  Durchreich-Worker bis 1.22.x), ruft `install` `skipWaiting()` — es gibt keinen alten Stand,
  der mit dem neuen durcheinandergeraten könnte.
- **activate:** alle Speicher `checkin-*` außer dem eigenen löschen, dann `clients.claim()`.
- **fetch:** Nur GET-Anfragen, deren URL in der Vorladeliste steht, werden aus dem Speicher
  beantwortet. Ein Seitenaufruf (`request.mode === 'navigate'`) auf `./` oder `index.html`
  bekommt die gespeicherte `index.html`. Alles andere — insbesondere `../api/` — ruft
  `respondWith` nicht auf und geht unverändert ans Netz. Das bisherige
  `respondWith(fetch(event.request))` entfällt.
- **message `SKIP_WAITING`:** ruft `skipWaiting()`.

## Hinweisleiste (`index.html`, `css/style.css`, `js/app.js`)

- Verstecktes Element mit Text „Neue Version verfügbar“ und Knopf „Neu laden“, verdrahtet per
  `addEventListener` (CSP, kein Inline-Handler). Farben über die vorhandenen Variablen.
- Nach `register()`: Wartet bereits ein Worker (`registration.waiting`) und gibt es einen
  aktiven Controller, Leiste zeigen. Sonst auf `updatefound` hören und die Leiste zeigen,
  sobald der neue Worker `installed` ist **und** `navigator.serviceWorker.controller`
  existiert — bei der Erstinstallation also nie.
- Knopf: `registration.waiting.postMessage({type: 'SKIP_WAITING'})`. Auf `controllerchange`
  einmal `location.reload()`; ein Merker verhindert eine Schleife.
- Der vorhandene `visibilitychange`-Hörer ruft zusätzlich `registration.update()` auf, damit
  eine neue Version auch bei tagelang offener App gefunden wird.

## Tests

- **Statische Suite `tests/suites/pwa_cache_frontend.php`:**
  - `VERSION` im Service Worker entspricht `version.json`.
  - Jede lokale Datei, die `public/checkin/index.html` per `href`/`src` lädt (ohne externe
    URLs), steht genau so in der Vorladeliste; jeder Eintrag der Liste außer `./` wird von
    `index.html` geladen oder ist ein Manifest-Icon.
  - Kein Eintrag beginnt mit `/`; keiner verweist auf `api/`.
  - `skipWaiting()` steht nicht unbedingt in `install`.
  - Die Hinweisleiste existiert in `index.html` und ist in `app.js` verdrahtet.
- **Lokales Puppeteer-Skript `tests/browser/pwa-offline.mjs`** (nicht Teil von
  `tests/run.php`, Muster `ics-check.mjs`): App öffnen und anmelden, offline schalten, neu
  laden → Startbildschirm mit „Server nicht erreichbar.“; online, neue Version simulieren →
  Leiste erscheint, Tipp lädt neu und der neue Speicher ist aktiv.

## Dokumentation

- `CLAUDE.md`, Konventionen „Versionssprung“: `VERSION` in `public/checkin/service-worker.js`
  gehört dazu (der Test meldet sie).
- `CLAUDE.md`, Testing: Im Feature-Zweig bleibt die Version gleich, der Service Worker liefert
  geänderte Check-in-Dateien aus dem Speicher — „Update on reload“ bzw. „Bypass for network“
  in den Entwicklerwerkzeugen.
- `public/checkin/README.md`: Abschnitt Offline-Verhalten.
- `docs/OPEN-ITEMS.md`, OI-43: Stufe 1 als umgesetzt vermerken.
- `CHANGELOG.md` unter `[Unreleased]`.

## Nicht Teil dieses Schritts

- Daten zwischenspeichern (Stufe 2), Schreib-Warteschlange (Stufe 3, verworfen).
- Station.
- Absolutes `start_url` im Manifest — stimmt bei der vorgesehenen Installation mit Web-Root
  auf `public/`.
- Versionssprung: kein `version.json`-Sprung im Feature-Zweig; `VERSION` steht auf der
  aktuellen Version und wird mit dem nächsten Release angehoben.
