# CSS-Bündel: Dashboard und Anmeldeseite laden eine CSS-Datei

**Datum:** 2026-10-06
**Status:** Umgesetzt auf feat/css-buendel (unveröffentlicht)
**Anlass:** [OI-120](../../OPEN-ITEMS.md#oi-120--das-dashboard-lädt-rund-45-einzeldateien) (Weg 1, Bündeln),
Folge von `2026-10-05-version-im-pfad-design.md`
**Zielversion:** keine — Arbeit ohne Versionssprung, Eintrag unter `[Unreleased]`. **Keine
Schemaänderung, keine API-Änderung, kein Build-Schritt.**

---

## 1 Befund

- Seit 1.21.0 fragen wiederkehrende Nutzer keine CSS- oder JS-Datei mehr nach (Version im Pfad,
  `immutable`). Der **Erstbesuch** und der erste Aufruf nach einem Update laden weiter alle
  Dateien einzeln: 22 CSS (`main.css` plus 21 `@import`) und 24 JS-Module, dazu Anmeldeseite
  `login.css` plus 2 `@import`.
- Die CSS-Importe bilden eine eigene serielle Stufe: Erst wenn `main.css` da ist, starten die 21
  Importe; das Layout steht erst danach.
- Der akute Anlass (503-Welle auf der Demo) ist seit „DNS only“ behoben (`docs/DEMO.md`): Direkt
  spricht der Browser HTTP/1.1 mit höchstens sechs Verbindungen. Die Grenze des Hosters trifft
  aber weiter jeden Verein, dessen Hoster selbst HTTP/2 spricht oder der ein CDN mit kaltem Cache
  vorschaltet.
- Keine CSS-Datei enthält `url()` außer in `@import`, keine `@charset` oder `@layer`. Reines
  Verketten ändert die Wirkung nicht.
- JS-Module sauber zu bündeln braucht einen Bundler (Build-Schritt, verworfener Weg laut
  `docs/project_history.md`). Deshalb hier nur CSS; ob JS-Bündeln sich lohnt, klärt die Messung in
  Abschnitt 5.

## 2 Ziel und Abgrenzung

**Ziel (entschieden):** Dashboard und Anmeldeseite laden ihr CSS als **eine** Antwort, ohne
erzeugte Datei im Repository und ohne Build-Werkzeug. Die Quellen bleiben unverändert und
einzeln bearbeitbar.

**Nicht Ziel:** JS bündeln, Minifizieren, eigene Kompression. Check-in-App, Station und
`print.css` bleiben, wie sie sind.

**Verworfen:**
- Erzeugte Bündeldatei im Repository (`css/main.bundle.css` plus Gleichheitstest): jede
  CSS-Änderung muss neu erzeugt werden, Konflikte im Bündel bei parallelen Sitzungen, doppelte
  Diffs.
- Bündel nur im Release-Paket: Der Updater zieht das GitHub-Archiv; Entwicklung und Produktion
  liefen auseinander, die Update-Quelle müsste umgebaut werden.

## 3 Umsetzung

### 3.1 Adresse

Die Seiten zeigen bereits auf `css/v<Version>/main.css` bzw. `css/v<Version>/login.css`; am HTML
ändert sich nichts. In `public/.htaccess` steht **vor** der allgemeinen Versionsregel eine engere
Regel, die genau diese beiden Pfade intern auf `css/bundle.php?entry=main|login` umschreibt
(absolut über `%{REQUEST_URI}`, ohne `RewriteBase`, wie die bestehende Regel). Alle anderen
versionierten Dateien laufen weiter über die bisherige Regel und bleiben statisch.

Die Regel greift nur, wenn `css/bundle.php` existiert (`RewriteCond … -f`, Pfad aus
`%{REQUEST_FILENAME}`); sonst fällt die Anfrage auf die allgemeine Versionsregel durch und bekommt
die statische `main.css` mit ihren `@import` (siehe 3.5). Reihenfolge der Bedingungen: Pfad, `-f`,
dann `REQUEST_URI` zuletzt, weil `%1` in der Regel die zuletzt geprüfte Bedingung meint (URL-Präfix);
den Einstieg liefert `$1` der Regel.

Unversioniertes `css/main.css` bleibt die statische Datei mit `@import` und funktioniert
unverändert.

### 3.2 Logik

- `private/helpers/css_bundle.php`: reine Funktion
  `cssBundle(string $entry, ?string $root = null): string` (Copyright-Header,
  `declare(strict_types=1)`). `$root` ist standardmäßig `public/css/`; der Parameter dient nur den
  Tests.
- `public/css/bundle.php`: dünner Einstieg — Version über `cssBundleCurrentVersion()` aus
  `css_bundle.php` lesen (nicht `getVersion()` aus `version.php`: das gibt selbst aus und ruft
  `exit`), das Bündel bauen, dann erst Kopfzeilen setzen. Keine Datenbank, kein `config.php`,
  kein Bootstrap. `entry` nur als Zeichenkette (`?entry[]=x` ist 404 ohne Warning).

### 3.3 Zusammenfügen

- Jede Zeile der Form `@import url('…');` wird rekursiv durch den Inhalt der Datei ersetzt,
  relativ zur importierenden Datei aufgelöst. Die Reihenfolge bleibt erhalten, die Kaskade ist
  identisch.
- Vor jedem eingefügten Abschnitt steht `/* ---- <Pfad relativ zu css/> ---- */`.
- Nur die Einstiege `main` und `login` sind erlaubt; alles andere ergibt 404.
- Jeder aufgelöste Pfad muss nach `realpath()` unter `$root` liegen und auf `.css` enden. Jede
  Datei wird höchstens einmal eingefügt.
- Ein nicht auflösbarer Import (fehlt, außerhalb, doppelt) wird durch einen CSS-Kommentar mit dem
  Grund ersetzt; der Rest wird ausgeliefert. Der Bestandswächter (4.1) stellt sicher, dass das im
  Bestand nie vorkommt. Der Kommentartext enthält weder `*` noch `@`, kann den Kommentar also
  nicht verlassen.
- Importe in CSS-Kommentaren bleiben unberührt. Jede andere `@import`-Form (ohne `url()`, ohne
  Anführungszeichen, mit Medienabfrage) wird nicht eingefügt, sondern durch den Fehlerkommentar
  „Form nicht unterstützt“ ersetzt — mit Medienabfrage ginge beim Einfügen die Bedingung verloren.
- Eine BOM am Anfang einer Datei wird entfernt.

### 3.4 Caching und Kopfzeilen

- `Content-Type: text/css; charset=utf-8`.
- Stimmt die Version im angeforderten Pfad mit `version.json` überein:
  `Cache-Control: public, max-age=31536000, immutable`.
- Sonst (z. B. eine alte Seite fordert nach einem Update die alte Version an): `no-cache`. So
  landet neuer Inhalt nie für ein Jahr unter einer alten Adresse.
- Die Version liest das Skript aus dem ursprünglichen Pfad (`REQUEST_URI`), nicht aus einem
  Query-Parameter.
- Fehler werden nicht gecacht: Enthält das Bündel einen Fehlerkommentar, gilt `no-cache` auch für
  die aktuelle Version (`cssBundleCacheControl()`). Lässt sich das Bündel gar nicht bauen (Datei
  nicht lesbar), antwortet das Skript 503, `text/plain`, `Cache-Control: no-store`, Rumpf nur
  „CSS bundle unavailable“ (kein Pfad, keine Meldung).

### 3.5 Updater

`public/.htaccess` kommt in `UPDATE_APPLY_LAST` (`private/helpers/update_swap.php`) **vor** die
beiden HTML-Seiten. Sonst zeigt die neue Regel kurz auf ein `bundle.php`, das noch nicht da ist.
Wie bei OI-120 wirkt das erst ab dem übernächsten Update (der installierte Updater führt den
Tausch aus); beim ausliefernden Update schreibt der alte Updater `public/.htaccess` früh und
`css/bundle.php` spät. Diese Lücke von wenigen Sekunden fängt die `-f`-Bedingung (3.1) ab: ohne
`bundle.php` lädt die Seite die Einzeldateien statt 404 zu bekommen. Purge bei
Cloudflare bleibt Pflicht. `update_swap.php` bleibt bei PHP-8.0-Syntax.

## 4 Tests

### 4.1 Unit (`tests/suites/css_bundle_unit.php`, neu)

- Bündel `main` enthält jede der 21 importierten Dateien genau einmal, in der Reihenfolge von
  `main.css`; Bündel `login` beide Importe.
- Im Ergebnis steht kein `@import`.
- Unbekannter Einstieg wird abgewiesen.
- Pfade außerhalb von `$root` (`../`, absolut) und doppelte Importe werden abgewiesen
  (Fehlerkommentar), mit Testdateien in einem Scratch-Verzeichnis als `$root`.
- Fehlender Import erzeugt den Fehlerkommentar und bricht nicht ab.
- `cssBundleCacheControl()`: aktuell und sauber `immutable`; aktuell mit Fehlerkommentar und alte
  Version `no-cache`.
- BOM in Einstieg und eingefügter Datei fehlt im Ergebnis.
- Sterne im Pfad (`x**//body{…}/**.css`): im Ergebnis gleich viele `/*` wie `*/`.
- Auskommentierter Import wird nicht eingefügt, der Kommentar bleibt.
- `@import url('a.css') screen;`, `@import 'a.css';`, `@import url(a.css);`: Fehlerkommentar,
  kein `@import` im Ergebnis.
- **Bestandswächter:** `main` und `login` lösen sich ohne einen einzigen Fehlerkommentar auf.

### 4.2 Statisch

- `assets.php`: Die Rewrite-Regel für die beiden Einstiege steht in `public/.htaccess` vor der
  allgemeinen Versionsregel, mit `-f`-Bedingung auf `bundle.php` und der Bedingungsreihenfolge
  Pfad, `-f`, `REQUEST_URI`.
- `update_swap_unit.php`: `UPDATE_APPLY_LAST` nennt `public/.htaccess` vor den HTML-Seiten.
- `update_path_syntax.php` deckt `update_swap.php` weiter ab.

### 4.3 HTTP (`asset_caching_http.php` erweitern)

| Aufruf | Erwartet |
|---|---|
| `css/v<Version>/main.css` | 200, `text/css`, `immutable`, kein `@import`, Merkmal aus `responsive.css` |
| `css/v<Version>/login.css` | 200, `text/css`, `immutable`, Merkmal aus `components/demo-banner.css` |
| `css/v0.0.1/main.css` | 200, `no-cache` |
| `css/main.css` | statisch, enthält `@import`, `no-cache` |
| `css/v<Version>/components/buttons.css` | statisch, `immutable` |
| `css/bundle.php?entry=../../private/config/config` | kein 200 mit Inhalt |
| `css/bundle.php?entry[]=x` | 404, Rumpf ohne „Warning“ und ohne Pfad |

Von Hand (nicht automatisiert, weil es die Dateien umbenennt): ohne `css/bundle.php` liefert
`css/v<Version>/main.css` 200 mit der statischen Datei; ohne `login.css` antwortet `bundle.php`
503 mit `no-store`.

### 4.4 Mutationsproben

Jede neue Zusicherung per Mutationsprobe mit Sicherungskopie.

### 4.5 Browser

- `click-through.mjs` und `startup-chain.mjs` bleiben grün.
- `cache-reuse.mjs`: Erstbesuch eine CSS-Anfrage statt 22 (Dashboard) bzw. eine statt 3
  (Anmeldeseite), zweiter Aufruf weiter 0.
- Bildschirmfotos von Dashboard und Anmeldeseite zum Sichtvergleich mit dem Stand vorher.

## 5 Messung nach dem Release

Auf der Demo, Erstbesuch mit leerem Cache, je dreimal ohne Drosselung und mit „Fast 4G“,
Dashboard und Anmeldeseite:

- Zeit bis zum fertigen Layout (erstes Darstellen mit allen Stilen).
- Zeit bis zum letzten geladenen JS-Modul.
- Anteil der 24 JS-Anfragen daran (Warten auf eine Verbindung gegenüber Übertragung).
- Ob die PHP-Antwort komprimiert ankommt (`content-encoding`).

Ergebnis: Empfehlung für oder gegen JS-Bündeln. Die Entscheidung trifft der Nutzer, weil sie die
Leitplanke „kein Build-Step“ berührt.

## 6 Doku und Rahmen

- OI-120: Vermerk CSS-Bündel; Messergebnis aus Abschnitt 5 nachtragen.
- CHANGELOG unter `[Unreleased]`.
- Kommentarblock „CACHING“ in `public/.htaccess`.
- `CLAUDE.md`, Abschnitt Caching: Neue CSS-Dateien von Dashboard und Anmeldeseite nur per
  `@import` in `main.css` bzw. `login.css` einbinden.
- Eigener Worktree mit eigener Datenbank, kein Versionssprung. Neue Dateien tragen den
  Copyright-Header.
