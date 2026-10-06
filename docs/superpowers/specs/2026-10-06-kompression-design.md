# Kompression: CSS, JS und HTML komprimiert ausliefern

**Datum:** 2026-10-06
**Status:** Umgesetzt auf feat/kompression (unveröffentlicht)
**Anlass:** [OI-120](../../OPEN-ITEMS.md#oi-120--das-dashboard-lädt-rund-45-einzeldateien),
Messung auf der Demo nach dem CSS-Bündel (1.22.0)
**Zielversion:** keine — Arbeit ohne Versionssprung, Eintrag unter `[Unreleased]`. **Keine
Schemaänderung, keine API-Änderung, kein Build-Schritt.**

---

## 1 Befund

- Messung Erstbesuch auf der Demo (2026-10-06, OI-120): Das Dashboard überträgt rund 870 KB
  (743 KB JS, 127 KB CSS-Bündel), bei „Fast 4G“ dauert das 6,2–7 s. Der Engpass ist die
  Datenmenge, nicht die Zahl der Anfragen: JS-Anfragen warten bis 3,6 s auf eine freie
  Verbindung, die Serverzeit je Datei liegt bei 33–51 ms.
- Der Hoster komprimiert keine Antwort (HTML, CSS, JS, API). Der Abschnitt COMPRESSION in
  `public/.htaccess` ist seit Projektbeginn auskommentiert.
- gzip, lokal gemessen: JS 699 → 177 KB, CSS-Bündel 129 → 30 KB. Geschätzte Ersparnis bei
  „Fast 4G“: 3–4 s.
- Die Check-in-App (`checkin/js/app.js` 263 KB) und die Station liegen unter `public/` und
  profitieren von derselben Regel — gerade die PWA wird im Mobilfunk genutzt.
- Im lokalen XAMPP sind `mod_deflate` und `mod_filter` nicht geladen (`httpd.conf` Z. 111, 118).

## 2 Ziel und Abgrenzung

**Ziel (entschieden):** Statische Textdateien (CSS, JS, HTML, SVG, Manifest) und das CSS-Bündel
kommen gzip-komprimiert an, sofern der Hoster `mod_deflate` lädt. Ohne das Modul bleibt alles wie
heute, nur das Bündel ist trotzdem komprimiert.

**Nicht Ziel:**
- **API-Antworten (`application/json`)** — entschieden: unkomprimiert. Antworten mit Geheimnissen
  (CSRF-Token, API-Token) neben Eingaben aus der Anfrage sind die Voraussetzung für BREACH.
- **Alle von PHP erzeugten Antworten** (`reset_password.php`, `verify_email.php`, `install/`,
  `update/`) — sie tragen Token, aus demselben Grund.
- Brotli (auf Shared Hosting selten), Bilder (schon komprimiert), eigene Kompressionsstufe.

**Verworfen:**
- Alle Dateien über PHP komprimiert ausliefern: 31 PHP-Aufrufe je Erstbesuch, trifft die
  1-s-Stufe beim Starten neuer FPM-Worker auf der Demo (OI-121).
- Vorkomprimierte `.gz`-Dateien im Repository: Build-Schritt mit erzeugten Dateien.

## 3 Umsetzung

### 3.1 `public/.htaccess`, Abschnitt COMPRESSION

Ersetzt den auskommentierten Block:

```apache
<IfModule mod_deflate.c>
    <IfModule mod_filter.c>
        AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript image/svg+xml application/manifest+json
    </IfModule>
    <FilesMatch "\.php$">
        SetEnv no-gzip 1
    </FilesMatch>
</IfModule>
```

- Die Typliste nennt **kein** `application/json` und kein `text/plain`.
- `no-gzip` für jede `.php`-Datei greift am Dateinamen, also auch bei `update/` (ausgeliefert über
  `index.php`) und bei `api.php`. `bundle.php` ist ebenfalls betroffen und komprimiert selbst
  (3.3); `mod_deflate` würde eine Antwort mit gesetztem `Content-Encoding` ohnehin nicht noch
  einmal komprimieren.
- Der Block gilt für alle Unterordner von `public/`, auch `checkin/` und `station/`.
- Kommentar im Block: Wirkung, BREACH-Begründung für JSON und PHP, Hinweis „ohne Modul
  wirkungslos“.

### 3.2 Revalidierung (304)

Apache 2.4 hängt bei komprimierten Antworten `-gzip` an das ETag. Kommt es als `If-None-Match`
zurück, passt es je nach Version nicht mehr; jede Revalidierung liefert dann 200 mit vollem
Inhalt statt 304 — betroffen sind alle `no-cache`-Dateien (HTML-Seiten, Check-in-App, Station).
Ein HTTP-Test (4.3) prüft das lokal. **Nur wenn er ohne Gegenmaßnahme 200 liefert**, kommt in
`<IfModule mod_headers.c>`:

```apache
RequestHeader edit "If-None-Match" '^"((.*)-gzip)"$' '"$1", "$2"'
```

Die Umsetzung hält das Ergebnis im Kommentar fest.

**Ergebnis (Umsetzung):** Mit Apache 2.4.58 lieferte die Revalidierung mit dem `-gzip`-ETag ohne
Gegenmaßnahme 200 statt 304. Die `RequestHeader edit`-Zeile steht deshalb in
`<IfModule mod_headers.c>`; der HTTP-Test liefert danach 304 für `/` und `/checkin/`.

### 3.3 `bundle.php` komprimiert selbst

- Reine Funktion in `private/helpers/css_bundle.php`:
  `cssBundleUseGzip(string $acceptEncoding, bool $zlib, bool $outputCompression): bool`.
  Wahr genau dann, wenn `Accept-Encoding` `gzip` mit einem q-Wert größer 0 (oder ohne q-Wert)
  nennt, `zlib` vorhanden ist (`function_exists('gzencode')`) und `zlib.output_compression` aus
  ist (sonst komprimiert PHP bereits selbst).
- `bundle.php` setzt immer `Vary: Accept-Encoding`; bei Kompression `Content-Encoding: gzip`
  und den Rumpf `gzencode($css, 6)`.
- Fehlerantworten (404, 503) bleiben unkomprimiert.

### 3.4 Lokale Umgebung

Mit Freigabe des Nutzers: In `C:\xampp\apache\conf\httpd.conf` die Zeilen
`LoadModule deflate_module …` (Z. 111) und `LoadModule filter_module …` (Z. 118) aktivieren,
vorher Sicherungskopie im Scratchpad; Apache neu starten. Die Umsetzung prüft vorher, dass
die Zeilen noch dort stehen.

## 4 Tests

### 4.1 Unit (`css_bundle_unit.php` erweitern)

`cssBundleUseGzip()`:

| Accept-Encoding | zlib | output_compression | Ergebnis |
|---|---|---|---|
| `gzip` | ja | aus | ja |
| `br, gzip` | ja | aus | ja |
| `gzip, deflate, br` | ja | aus | ja |
| `gzip;q=0` | ja | aus | nein |
| `` (leer) | ja | aus | nein |
| `deflate` | ja | aus | nein |
| `gzip` | nein | aus | nein |
| `gzip` | ja | an | nein |

### 4.2 Statisch (`assets.php`)

- COMPRESSION-Block steht in `<IfModule mod_deflate.c>`, `AddOutputFilterByType DEFLATE` nennt
  `text/html`, `text/css`, `text/javascript` und `application/javascript`.
- Die DEFLATE-Zeile nennt **kein** `json`.
- `<FilesMatch "\.php$">` mit `SetEnv no-gzip 1` steht im selben Block.
- Steht die `RequestHeader edit "If-None-Match"`-Zeile in der Datei, dann innerhalb von
  `<IfModule mod_headers.c>`.

### 4.3 HTTP (`asset_caching_http.php`)

Setzt das geladene Modul voraus; schlägt sonst mit „mod_deflate nicht geladen? (httpd.conf)“ fehl.
Anfragen mit `Accept-Encoding: gzip`, sofern nicht anders angegeben:

| Aufruf | Erwartet |
|---|---|
| `js/v<V>/modules/ui.js`, `/` (Dashboard), `checkin/js/app.js?v=<V>` | `content-encoding: gzip` |
| `css/v<V>/main.css` | gzip, `Vary` enthält `Accept-Encoding`, entpackt identisch mit `cssBundle('main')` |
| `css/v<V>/main.css` ohne `Accept-Encoding` | ohne `content-encoding`, identisch mit `cssBundle('main')` |
| `api/api.php?resource=ping`, `reset_password.php` | kein `content-encoding` |
| `/` erneut mit dem erhaltenen ETag als `If-None-Match` | 304 |

`acFetch()` bekommt dafür optionale Anfrage-Kopfzeilen; curl entpackt **nicht** selbst
(kein `CURLOPT_ENCODING`), damit der Test die echte Antwort sieht.

### 4.4 Mutationsproben

Jede neue Zusicherung per Mutationsprobe mit Sicherungskopie, nach dem Commit.

### 4.5 Browser

`cache-reuse.mjs`, `click-through.mjs`, `startup-chain.mjs` bleiben grün.

## 5 Messung nach dem Release

Skript der Messung vom 2026-10-06 (Erstbesuch ohne Anmeldung, frisches Profil, Cache aus, je
3 Läufe ungedrosselt und „Fast 4G“, Dashboard und Anmeldeseite). Erwartet: `content-encoding:
gzip` an CSS, JS und HTML, Dashboard bei „Fast 4G“ deutlich unter 6,2–7 s. Kommt keine
Kompression an, lädt der Hoster `mod_deflate` nicht — dann bleibt nur das komprimierte Bündel,
und das steht so in OI-120.

## 6 Doku und Rahmen

- CHANGELOG unter `[Unreleased]`.
- README, Systemvoraussetzungen: `mod_deflate` und `mod_filter` empfohlen, nicht Pflicht.
- `docs/DEMO.md`: Prüfschritt `content-encoding: gzip` an CSS/JS.
- OI-120: Vermerk.
- Eigener Worktree mit eigener Datenbank, kein Versionssprung. Neue Dateien tragen den
  Copyright-Header.
