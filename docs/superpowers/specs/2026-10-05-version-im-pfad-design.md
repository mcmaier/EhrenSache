# Version im Pfad: Dashboard und Login dürfen gecacht werden

**Datum:** 2026-10-05
**Status:** Entwurf, mit dem Nutzer abschnittsweise abgestimmt
**Anlass:** [OI-120](../../OPEN-ITEMS.md#oi-120--das-dashboard-lädt-rund-45-einzeldateien) (Weg 2),
erledigt nebenbei [OI-74](../../OPEN-ITEMS.md#oi-74--der-cache-bust-erreicht-nur-einen-teil-der-dateien)
**Zielversion:** keine — Arbeit ohne Versionssprung, Eintrag unter `[Unreleased]`. **Keine
Schemaänderung, keine API-Änderung, kein Build-Schritt.**

---

## 1 Befund

- Das Dashboard lädt 21 CSS-Dateien (per `@import` aus `main.css`) und rund 28 JS-Dateien
  (ES-Module, 18 davon zusätzlich als eigener `<script type="module">` in `index.html`). Die
  Anmeldeseite lädt `login.css`, `theme.js`, `login.js` und deren Importe.
- `public/.htaccess` liefert CSS, JS und HTML mit `Cache-Control: no-cache, must-revalidate` aus.
  Der Browser fragt deshalb bei **jedem** Aufruf jede Datei nach; unverändert antwortet der Server
  mit 304, aber jede Nachfrage ist eine Anfrage an den Hoster. Auf Shared Hosting mit knapper
  Grenze gleichzeitiger Prozesse (Demo: etwa 12) führt das zu `503` (OI-120).
- Grund für `no-cache` ist OI-74: Nur `main.css` und wenige Skripte tragen `?v=<Version>`; die
  per `@import` und `import` nachgeladenen Dateien erben den Query nicht. Ohne Nachfrage käme ein
  Update nicht an. Ein Query an jeder Importzeile wäre fehleranfällig und erzeugte doppelte
  Modulinstanzen, sobald eine Stelle ihn nicht trägt.
- Die Dashboard-CSS verweist mit `url()` auf keine Datei außerhalb von `css/`, und alle
  JS-Importe sind relativ (`./`, `../`).

## 2 Ziel und Abgrenzung

**Ziel (entschieden):** Wiederkehrende Nutzer — der Normalfall in einem Verein — fragen beim
Öffnen von Dashboard und Anmeldeseite **keine** CSS- oder JS-Datei mehr beim Server nach. Nach
einem Update kommen trotzdem alle Dateien neu an.

**Nicht Ziel:** der Erstbesuch und der erste Aufruf nach einem Update laden weiterhin alle Dateien
einzeln (Bündeln, OI-120 Weg 1, bleibt offen). Check-in-App und Station behalten ihr Verfahren
(`?v=`, eine Handvoll Dateien, die Check-in-App mit eigenem Service Worker).

**Verworfen:** `?v=` an jede Importzeile (rund 200 Stellen, Doppelinstanzen bei einer vergessenen);
Service Worker fürs Dashboard (neues Konzept, überdimensioniert); Import-Map (scheitert an der
CSP `script-src 'self'`, OI-120).

## 3 Umsetzung

### 3.1 Versionierte Pfade

In `public/index.html` und `public/login.html` verweist **jede** lokale Datei unter `css/` und
`js/` über einen Versionsabschnitt direkt hinter dem Ordner:

| Heute | Neu |
|---|---|
| `css/main.css?v=1.20.1` | `css/v1.20.1/main.css` |
| `css/login.css?v=1.20.1` | `css/v1.20.1/login.css` |
| `js/theme.js` | `js/v1.20.1/theme.js` |
| `./js/modules/ui.js` | `./js/v1.20.1/modules/ui.js` |
| `./js/vendor/qrcode.js` | `./js/v1.20.1/vendor/qrcode.js` |
| `./js/install-check.js` | `./js/v1.20.1/install-check.js` |

Relative Importe erben den Abschnitt: `./ui.js` aus `js/v1.20.1/app.js` lädt
`js/v1.20.1/modules/ui.js`, `@import url('components/buttons.css')` aus `css/v1.20.1/main.css`
lädt `css/v1.20.1/components/buttons.css`. Im Code ändert sich dafür nichts.

**Jede** lokale Referenz in beiden Seiten muss versioniert sein. Ein einziger
`<script type="module">` ohne Abschnitt lüde dasselbe Modul unter zweiter Adresse — mit eigenem
Zustand. Der Test in 4.1 verbietet das.

`favicon.ico` und Bilder sind nicht betroffen.

### 3.2 Rewrite

`public/.htaccess` bildet `^(css|js)/v[0-9][0-9.]*/(.+)$` intern auf `$1/$2` ab — keine
Weiterleitung, die Dateien bleiben, wo sie sind. Das Muster ist eng (`v`, eine Ziffer, dann nur
Ziffern und Punkte), damit kein echter Ordnername getroffen wird. Die Regel steht vor allen
Regeln, die ein `[L]` auf solche Pfade setzen könnten, und funktioniert unabhängig davon, ob die
Installation im Wurzelverzeichnis oder in einem Unterordner liegt (lokal:
`/EhrenSache/public/`). Eine nicht vorhandene Datei unter versioniertem Pfad ergibt 404.

`mod_rewrite` setzt das Projekt bereits voraus (`RewriteEngine On` in `public/.htaccess`).

### 3.3 Caching

Nur Antworten auf einen versionierten Pfad bekommen
`Cache-Control: public, max-age=31536000, immutable`. Die Markierung läuft über eine
Umgebungsvariable der Rewrite-Regel (`[E=…]`; nach dem internen Umschreiben heißt sie unter
Umständen `REDIRECT_…`), nicht über Dateinamen. Welche Form zuverlässig bis zur
`Header`-Direktive gelangt, prüft die Umsetzung per `curl -I` und legt es im Kommentar fest.

Alles andere bleibt `no-cache, must-revalidate`: die HTML-Seiten (sie tragen die Version),
unversionierte Aufrufe, Check-in-App und Station.

Ohne `mod_headers` fehlt das lange Caching, der Cache-Bust über den Pfad wirkt aber trotzdem für
alle Dateien — besser als heute (OI-74).

Der Kommentarblock „CACHING“ in `public/.htaccess` wird auf das neue Verfahren umgeschrieben.

### 3.4 Bewusst hingenommen

- Wer während eines Updates das alte Dashboard offen hat und danach ein spät geladenes Modul
  nachlädt (`import('./features.js')`), bekommt die neue Datei unter dem alten Pfad — derselbe
  Fall wie heute, nur für offene Tabs im Moment des Updates.
- In `index.html` steht die Version danach rund 25-mal. Ein Versionssprung ist trotzdem ein
  Suchen und Ersetzen; der Test meldet jede vergessene Stelle.

## 4 Tests

### 4.1 Statisch (`tests/suites/assets.php` erweitern)

- In `index.html` und `login.html` trägt jede lokale `href`/`src` unter `css/` oder `js/` den
  Abschnitt `v<Version>`, und die Version stimmt mit `version.json` überein.
- Dort gibt es kein `?v=` mehr und keine lokale `css/`- oder `js/`-Referenz ohne Abschnitt.
- Check-in-App und Station prüft der bestehende Test weiter über `?v=`.
- `public/.htaccess` enthält die Rewrite-Regel und die Caching-Regel für versionierte Pfade.
- Jede Zusicherung per Mutationsprobe geprüft.

### 4.2 HTTP gegen die lokale Instanz

In einer bestehenden HTTP-Suite (oder einer neuen, falls keine passt):

- `css/v<Version>/main.css` → 200, `text/css`, `Cache-Control` enthält `immutable`.
- `js/v<Version>/modules/ui.js` → 200, JavaScript-Inhaltstyp, `immutable`.
- `js/app.js` (ohne Version) → 200, `no-cache`.
- `index.html` → `no-cache`.
- `js/v9.9.9/gibtsnicht.js` → 404.

### 4.3 Browser

- `tests/browser/click-through.mjs` und `tests/browser/startup-chain.mjs` bleiben grün.
- Zweiter Aufruf des Dashboards in derselben Browsersitzung: **keine** Anfrage für CSS oder JS
  erreicht den Server (Netzwerkliste; aus dem Speicher geladene Dateien zählen nicht).
  Ausgenommen sind Anfragen, die das Skript per Request-Interception selbst erzeugt — die Prüfung
  läuft ohne Interception.

## 5 Doku und Betrieb

- **OI-120:** Vermerk „Weg 2 als Version im Pfad umgesetzt (Spec 2026-10-05-version-im-pfad);
  der Erstbesuch lädt weiter einzeln, Bündeln bleibt offen“.
- **OI-74:** erledigt.
- **`docs/DEMO.md`:** Nach einem Update ist für versionierte Dateien kein „Purge“ bei Cloudflare
  mehr nötig (neuer Pfad = neue Adresse), für die HTML-Seiten weiterhin. Die bestehende
  Cache-Regel (Edge TTL für css/js) bleibt sinnvoll.
- **Release-Ablauf:** Beim Versionssprung wird der Abschnitt `v<Version>` in `index.html` und
  `login.html` mit ersetzt — wo heute `?v=` steht. `CLAUDE.md` erwähnt das im Abschnitt
  Konventionen beim Versionssprung.
- **CHANGELOG:** Eintrag unter `[Unreleased]`.

## 6 Rahmen

- Eigener Worktree mit eigener Datenbank, kein Versionssprung. Der Pfadabschnitt trägt die
  aktuelle Version aus `version.json`; den Sprung macht die Release-Sitzung.
- Neue Testdateien tragen den Copyright-Header.
