# Design: Schnellinbetriebnahme des Kiosks per QR-Code

**Stand:** 2026-09-10 · **Bezug:** `dev` 1.4.1 · **Zielversion:** 1.5.0 (keine Schemaänderung)
**Status:** Design freigegeben am 2026-09-10, Umsetzung noch nicht begonnen

---

## 1 Abgleich mit `docs/OPEN-ITEMS.md`, `docs/DEMO.md` und der Stations-Spec

| Quelle | Inhalt | Verhältnis zu diesem Plan |
|---|---|---|
| `2026-09-04-station-pin-kiosk-design.md` E5 | Das TOTP-Secret verlässt den Server nie | **Unberührt.** Übertragen wird nur der API-Token des Geräts, nie ein Secret |
| Ebenda, Abschnitt 2.1 | Ein Kiosk-Token ist ohne Mitglieds-PIN wertlos; `api.php` lässt Kiosk-Token nur an `station` | **Trägt diesen Plan.** Ohne diesen Riegel wäre ein Token im QR-Bild nicht vertretbar |
| Ebenda E14 | Kiosk-Sperrung ist Sache des Tablets, nicht der Web-App | Unverändert. Die Reihenfolge „erst koppeln, dann anheften" wird dadurch zwingend |
| Bewusst entschieden: kein Offline-Betrieb der Station | Der Service Worker cacht nichts, er reicht durch (`service-worker.js:23`) | **Ermöglicht die Zusammenführung der QR-Bibliothek** (Abschnitt 5.1) |
| `docs/DEMO.md` Abschnitt 3 | Geräte-Token werden bei jedem stündlichen Reset neu gewürfelt | **Auslöser dieses Plans.** Aus „48 Zeichen abtippen" wird ein Scan |
| `docs/DEMO.md` Abschnitt „Was ein Besucher darf" | `users` ist schreibgeschützt, `regenerate_token` gesperrt | Passt: Der Besucher **liest** den Token des geseedeten Kiosks (`plan.php:1025`) als Admin und scannt. Keine Lockerung des Wächters nötig |
| OI-17 · keine CSP | Die Oberfläche nutzt Inline-Handler | Der neue Knopf folgt dem Bestandsmuster (`onclick` + `window.`-Registrierung), verschlechtert nichts |

---

## 2 Zielbild

Ein Verwalter öffnet im Dashboard das Kiosk-Gerät, drückt **„📱 QR-Code"** und hält das
Tablet vor den Bildschirm. Die Kamera-App des Tablets öffnet die Station, die sich
stillschweigend verbindet und ins Ruhebild geht. Danach „Zum Startbildschirm hinzufügen" —
fertig.

Heute steht an dieser Stelle ein 48-stelliger Hex-Token, der auf einem Tablet ohne Tastatur
abgetippt werden muss. In der öffentlichen Demo stündlich neu.

---

## 3 Entscheidungen

| # | Frage | Entscheidung | Begründung |
|---|---|---|---|
| Q1 | Was steht im QR? | **Der Token selbst, im URL-Fragment**: `…/station/#t=<token>` | Das Fragment wird vom Browser nie gesendet — es taucht in keinem Zugriffsprotokoll, keinem Referrer, keinem Reverse-Proxy-Log auf. Reine Frontend-Änderung: kein Endpunkt, keine Tabelle, keine Migration. Der Token ist ohne Mitglieds-PIN wertlos und steht im Gerätedialog ohnehin im Klartextfeld |
| Q2 | Einmal-Kopplungscode statt Token? | **Nein**, als Erweiterung zurückgestellt | Neue Spalte oder Tabelle, neuer unauthentifizierter Endpunkt, eigenes Rate-Limit, Ablauflogik, Tests — und in der Demo eine zusätzliche Freischaltung in `demo_mode.php`. Der Gewinn wäre gering, solange der Token daneben im Klartext lesbar ist |
| Q3 | Welche Gerätetypen? | **Nur `kiosk`** | `totp_location` hat gar keine PWA, in die man sich einbuchen könnte. `auth_device` nutzt die Check-in-PWA, deren Token-Login auskommentiert ist (`checkin/js/app.js:124`) — das ist eine eigene Baustelle |
| Q4 | QR-Bibliothek | **Eine vendored Kopie unter `public/js/vendor/qrcode.js`**, CDN aus `index.html` entfernen | Das Dashboard lädt heute `qrcodejs` von cdnjs (`index.html:2358`). In einer Vereinsinstallation ohne Internetzugang fällt der bestehende PWA-Quicklink dort still auf den Text-Fallback zurück. `qrcode-generator` liegt bereits vendored in der Station |
| Q5 | Zwei Kopien der Bibliothek? | **Nein, eine.** Die Station lädt `../js/vendor/qrcode.js` | Der Service Worker der Station cacht nichts (`service-worker.js:23`), Offline-Betrieb ist bewusst ausgeschlossen. Es gibt also keinen Grund, die Datei innerhalb des Stations-Verzeichnisses zu halten |
| Q6 | Aufnahmeweg in die Station | **Nur URL-Fragment.** Kein Scanner in der Station | Die Kamera-App des Tablets erledigt den Scan. Ein eigener Scanner wäre eine zweite Fremdbibliothek (~350 kB), Kamerarechte auf dem Kiosk und mehr Testfläche |
| Q7 | Umgang mit dem iPadOS-Speichercontainer | **Reihenfolge dokumentieren**, Scanner als OPEN-ITEM festhalten | Siehe Abschnitt 4.2. In der Demo läuft die Station meist im Browser-Tab, dort greift das Problem gar nicht |
| Q8 | Verhalten bei bestehender Kopplung | **Still überschreiben**, keine Rückfrage | Wer den QR vor das Tablet hält, steht physisch davor — dieselbe Schwelle wie beim heutigen Einstellungsdialog. Genau das macht das stündliche Neukoppeln in der Demo zu einem einzigen Scan |
| Q9 | Verhalten bei ungültigem Token im Hash | **Fehlermeldung im Einrichtungs-Bildschirm, gespeicherter Token bleibt erhalten** | Ehrlicher als ein stiller Rückfall auf den alten Token. Der Einrichtungs-Bildschirm ist genau für diesen Zustand da |
| Q10 | Prüft die Station das Token-Format? | **Nein**, nur trimmen, leer ablehnen, auf 255 Zeichen begrenzen | Eine Hex-Prüfung im Client bricht, sobald die Token-Erzeugung in `users.php:321` je geändert wird. Über gültig entscheidet der Server per `status`-Aufruf |

---

## 4 Ablauf

### 4.1 Der gute Fall

1. Dashboard → Geräte → Kiosk bearbeiten. Der Token-Bereich (`apiTokenGroup`) zeigt zusätzlich
   den Knopf **„📱 QR-Code"**.
2. Das Modal zeigt den QR-Code, darunter die Adresse im Klartext (zum Abtippen oder
   Verschicken) und einen Warnhinweis (Abschnitt 7).
3. Kamera-App des Tablets scannt → Browser öffnet
   `https://verein.example/ehrensache/station/#t=<token>`.
4. `init()` in `station/js/app.js` liest den Hash **vor** dem gespeicherten Token, ruft
   `connect(token)`, dieser prüft per `status`-Aufruf und speichert bei Erfolg.
5. `history.replaceState(null, '', location.pathname + location.search)` entfernt den Token
   aus Adresszeile und Verlaufseintrag.
6. `enterIdle()` — die Station zeigt Uhr und Stations-Code.
7. „Zum Startbildschirm hinzufügen".

### 4.2 Die Reihenfolge ist nicht beliebig

Unter **iPadOS** hat eine zum Home-Bildschirm hinzugefügte Web-App einen eigenen
Speichercontainer, getrennt von Safari. Ein nach der Installation gescannter QR landet in
Safari; die installierte Station sieht ihn nie. Unter **Android** teilt sich die installierte
PWA den Speicher mit Chrome, dort funktioniert auch das spätere Scannen.

Daraus folgt für `public/station/README.md`: **erst koppeln, dann installieren.** Für das
erneute Koppeln einer bereits installierten iPadOS-Station bleibt die manuelle Eingabe im
Einstellungsdialog (5 Sekunden auf die Uhr) der Weg.

### 4.3 Fehlerfälle

| Fall | Verhalten |
|---|---|
| Hash leer oder kein `t`-Parameter | Unverändertes Bestandsverhalten: gespeicherter Token wird geladen |
| Token ungültig, abgelaufen, Gerät deaktiviert | Hash wird trotzdem entfernt. Einrichtungs-Bildschirm mit der bestehenden übersetzten Meldung (`app.js:83–85`). Gespeicherter Token bleibt unangetastet |
| Token gehört zu keinem Kiosk | Bestehende Meldung „Token gehört nicht zu einer virtuellen Station." |
| Kein Netz | Bestehendes Verhalten von `connect()` |
| Kein `localStorage` (privater Modus) | `saveToken()` schluckt den Fehler bereits (`app.js:291`). Die Station läuft bis zum Neuladen, danach erneuter Scan nötig |

---

## 5 Änderungen im Einzelnen

### 5.1 Bibliothek zusammenführen

| Datei | Änderung |
|---|---|
| `public/station/js/qrcode.js` | **verschieben** nach `public/js/vendor/qrcode.js` (qrcode-generator 1.4.4, Kazuhiko Arase, MIT — Lizenzkopf bleibt unverändert erhalten) |
| `public/station/index.html:161` | `src="js/qrcode.js"` → `src="../js/vendor/qrcode.js"` |
| `public/index.html:2358` | CDN-Zeile ersetzen durch `<script src="./js/vendor/qrcode.js"></script>` — klassisches Script, kein Modul: die Bibliothek setzt das globale `qrcode` |

**Zum Lizenzkopf:** `public/js/vendor/qrcode.js` ist Fremdcode und bekommt **keinen**
EhrenSache-Copyright-Header. Die Konvention aus `CLAUDE.md` gilt für eigene Dateien; der
MIT-Kopf der Bibliothek bleibt unangetastet. Das Verzeichnis `vendor/` ist genau dafür da und
mit diesem Plan neu.

### 5.2 Dashboard

| Datei | Änderung |
|---|---|
| `public/js/modules/ui.js:817` | `showPWAQRCode(url)` wird zu `showQRModal({ title, url, hint, warning })`, exportiert. Umstellung auf die vendored API: `qrcode(0,'M')` → `addData(url)` → `make()` → `createSvgTag(5, 2)`. Der Zweig „QR-Code Library fehlt" entfällt |
| ebenda | **Bestandsfehler mitkorrigieren:** Das Modal wird heute nur beim ersten Aufruf befüllt (`if (!modal) { … innerHTML … }`). Mit zwei verschiedenen Aufrufern zeigt der zweite Aufruf sonst Titel und Adresse des ersten. Titel, Adressfeld, Hinweis und QR-Bild werden künftig bei **jedem** Aufruf neu gesetzt |
| `public/js/modules/ui.js:798` | `initPWAQuickAccess()` ruft `showQRModal({ title: '📱 Check-In App öffnen', … })` |
| `public/js/modules/devices.js:389` | In `toggleDeviceTypeFields()`: Sichtbarkeit des neuen Knopfes, Bedingung `deviceType === 'kiosk' && editing` |
| `public/js/modules/devices.js` | Neue exportierte Funktion `showDeviceQR()`: liest `device_token`, baut die Adresse, ruft `showQRModal()`. Leerer Token → `showToast(…, 'error')`, kein Modal |
| ebenda, Zeile ~623 | `window.showDeviceQR = showDeviceQR;` nach Bestandsmuster |
| `public/index.html:1764` | Knopf-Markup in `apiTokenGroup`, `id="deviceQRBtn"`, `style="display: none;"`, neben dem Kopieren-Knopf |
| `public/css/sections/sidebar.css:255 ff.` | `.qr-modal-content #qrcode svg { max-width: 100%; height: auto; }` ergänzen — die vendored Bibliothek liefert SVG statt `<canvas>` |

Adressbildung, identisch zum Bestandsmuster in `initPWAQuickAccess()`:

```js
const baseUrl = window.location.origin + window.location.pathname.replace('index.html', '');
const url     = baseUrl + 'station/#t=' + encodeURIComponent(token);
```

### 5.3 Station

| Datei | Änderung |
|---|---|
| `public/station/js/app.js` | Neue Funktion `tokenFromHash()`: `new URLSearchParams(location.hash.slice(1)).get('t')`, trimmen, leer → `null`, länger als 255 Zeichen → `null` |
| ebenda | Neue Funktion `clearHash()`: `history.replaceState(null, '', location.pathname + location.search)`, in `try/catch` |
| ebenda, `init()` ab Zeile 897 | Vor `loadToken()`: liegt ein Hash-Token vor, wird es probiert, der Hash **immer** entfernt und bei Erfolg `enterIdle()`, bei Fehler `showScreen('setup')` plus `showError('setupError', …)`. Ohne Hash-Token bleibt der Bestandspfad unverändert |

Ein Hash-Token gewinnt immer gegen ein gespeichertes (Q8). Bei Misserfolg wird der gespeicherte
Token **nicht** angefasst und **nicht** ersatzweise probiert (Q9).

### 5.3.1 Zwei Korrekturen aus der Verifikation am laufenden System

Beide Punkte widerlegen Annahmen, die weiter oben in dieser Spec standen. Sie sind hier
festgehalten, damit niemand sie erneut aufmacht.

**`api()` vergisst den Token bei jedem 401** (`public/station/js/app.js:160`, Commit `eda7f54`).
Die ursprüngliche Fassung dieses Abschnitts behauptete, `connect()` lasse `localStorage` bei
Misserfolg in Ruhe. Das stimmt für `connect()` selbst — aber `api('status')` darin ruft bei
einem 401 `forgetToken()` auf. Ein veralteter QR-Code hätte damit einen laufenden Kiosk
dauerhaft in die Einrichtung geworfen, also genau das Gegenteil von Q9. `init()` sichert den
bisherigen Token deshalb **vor** dem Versuch und stellt ihn bei Misserfolg wieder her. Die
Sonderbehandlung in `api()` bleibt unangetastet: Sie ist dort bewusst so gebaut (siehe der
`pinRejected`-Kommentar) und trägt eigene Tests.

**Es gibt doch einen `hashchange`-Zuhörer** (Commit `52aa80d`). Ursprünglich verworfen mit der
Begründung, er wäre „eine zweite Eintrittsstelle in denselben Zustand". Das Argument trägt
nicht: Ist die Station bereits offen und ändert sich nur das Fragment, ist das eine Navigation
im selben Dokument — `init()` läuft nicht, und es passiert **sichtbar nichts**. Der Zuhörer
ruft deshalb `window.location.reload()`, sobald ein Token im Fragment steht. Damit bleibt
`init()` der einzige Weg in den Zustand, statt einen zweiten zu schaffen. Eine Schleife ist
ausgeschlossen, weil `clearHash()` `replaceState` benutzt und das kein `hashchange` auslöst.

### 5.4 Dokumentation

| Datei | Änderung |
|---|---|
| `public/station/README.md` | Einrichtung Schritt 1 und 4: QR-Code statt Token abtippen; Reihenfolge „erst koppeln, dann zum Startbildschirm" mit iPadOS-Begründung; Abschnitt „Sicherheit" um den QR-Hinweis ergänzen |
| `docs/DEMO.md` Abschnitt 3 | „Was der Reset mit sich bringt": Neukopplung des Kiosks per Scan aus der Geräteverwaltung |
| `docs/OPEN-ITEMS.md` | Zwei neue Einträge: **Scanner in der Station** (zurückgestellt, iPadOS-Container als Begründung) und **Einmal-Kopplungscode** (zurückgestellt, Q2). Ferner unter „Bewusst entschieden": Token im Fragment statt im Query |
| `CHANGELOG.md` | Eintrag unter `## [Nicht veröffentlicht]` |
| `version.json` | **unverändert.** Der Bump gehört zum Release, nicht zu diesem Feature: Unter „Nicht veröffentlicht" steht bereits der komplette Demo-Modus, den ein Sprung auf 1.5.0 mitveröffentlichen würde |
| `API.md` | keine Änderung — kein neuer Endpunkt, kein geändertes Verhalten |
| `docs/testplan.md` | Neue Fälle, siehe Abschnitt 6.2 |

---

## 6 Tests

### 6.1 Automatisiert

Es ändert sich kein API-Verhalten, also keine neue Suite. `tests/suites/assets.php` bekommt zwei
Wächter, die genau das absichern, was hier repariert wird:

| Test | Prüft |
|---|---|
| „Jedes Script-Tag zeigt auf eine vorhandene Datei" | Für `public/index.html`, `login.html`, `checkin/index.html`, `station/index.html`: jedes relative `src="…"` (ohne `?v=`-Anteil, relativ zum Verzeichnis der HTML-Datei aufgelöst) existiert im Repository. Fängt den verschobenen `qrcode.js`-Pfad ab |
| „Dashboard und Station laden keine externen Skripte" | Kein `src="http…"` in `public/index.html` und `public/station/index.html`. **`public/checkin/index.html` bleibt ausgenommen** — dort kommt `html5-qrcode` weiterhin von unpkg (`checkin/index.html:505`), das ist eine eigene Baustelle und würde den Test sonst sofort rot färben |

### 6.2 Manuell (`docs/testplan.md`)

| # | Fall | Erwartung |
|---|---|---|
| QR-1 | Kiosk bearbeiten, „QR-Code" drücken | Modal mit QR und Adresse `…/station/#t=…` |
| QR-2 | `auth_device` oder `totp_location` bearbeiten | Kein QR-Knopf |
| QR-3 | Neues Gerät anlegen (noch nicht gespeichert) | Kein QR-Knopf — es gibt noch keinen Token |
| QR-4 | Scan auf jungfräulicher Station | Verbindet sich, Ruhebild, Adresszeile ohne `#` |
| QR-5 | Scan auf bereits gekoppelter Station | Überschreibt still, neuer Gerätename im Ruhebild |
| QR-6 | Scan mit manipuliertem Token | Einrichtungs-Bildschirm mit Fehlermeldung, Adresszeile ohne `#`; nach Neuladen ist die Station wieder mit dem alten Token verbunden |
| QR-7 | Nach dem Scan: Verlauf zurück | Kein Eintrag mit Token in der Adresszeile |
| QR-8 | Dashboard-Quicklink „PWA öffnen" nach QR-1 im selben Sitzungsfenster | Zeigt den Check-in-Link, **nicht** die Stations-Adresse (Regression zum Modal-Bestandsfehler) |
| QR-9 | Dashboard ohne Internetzugang, nur Server erreichbar | QR-Codes werden weiterhin erzeugt |
| QR-10 | Demo: Reset abwarten, als Admin Kiosk öffnen, neu scannen | Station läuft nach einem Scan wieder |

---

## 7 Sicherheitsabwägung

Ein QR-Code ist ein Bild, das jeder im Raum mitfotografieren kann. Was den Token trotzdem
tragbar macht:

- Der Kiosk-Token darf ausschließlich die Ressource `station` (und `version`) aufrufen —
  `api.php` verriegelt das, `handleStation()` prüft zusätzlich `device_type === 'kiosk'`.
- Ohne die PIN eines Mitglieds bewirkt er nichts. Das ist die tragende Annahme aus
  Abschnitt 2.1 der Stations-Spec und gilt hier unverändert.
- Er ist nur für Admins lesbar (`users.php:107–113`) und steht im Gerätedialog ohnehin
  im Klartextfeld.
- Das Fragment erreicht den Server nicht und wird nach der Übernahme aus Adresszeile und
  Verlauf entfernt.

Was wir trotzdem tun: Das Modal trägt den Hinweis
**„Dieser Code enthält den Zugang der Station. Nicht abfotografieren lassen, nicht auf einen
Beamer legen."** Wer den Verdacht hat, dass ein Code abfotografiert wurde, erzeugt in der
Geräteverwaltung einen neuen Token — der alte wird damit ungültig.

**Bewusst nicht gebaut:** eine Ablauffrist für den QR. Sie wäre nur mit dem
Einmal-Kopplungscode aus Q2 sinnvoll; der QR selbst ist nur so lange gültig wie der Token,
und dessen Lebensdauer wird bereits in der Geräteverwaltung verwaltet.

---

## 8 Nicht Gegenstand dieses Plans

- Einmal-Kopplungscode mit eigenem Endpunkt (Q2) — OPEN-ITEM
- Kamera-Scanner innerhalb der Station (Q6) — OPEN-ITEM
- QR-Inbetriebnahme für `auth_device` und `totp_location` (Q3)
- Die unpkg-Abhängigkeit der Check-in-PWA (`checkin/index.html:505`)
- Änderungen an Token-Erzeugung, Ablauffrist oder `regenerate_token`
- Jede Änderung an Datenbank, API oder Wächter der Demo

---

## 9 Aufwand

Ein Vormittag. Drei JS-Dateien (`ui.js`, `devices.js`, `station/js/app.js`), zwei HTML-Dateien
(`index.html`, `station/index.html`), eine CSS-Ergänzung, eine verschobene Datei, zwei neue
Tests und Dokumentation. Keine Migration, kein Schema, kein Endpunkt, kein Versionssprung.
