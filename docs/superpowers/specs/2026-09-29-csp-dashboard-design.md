# Content-Security-Policy für das Dashboard — Design

**Vorgang:** OI-17 Etappe 2 (schließt OI-112 mit) · entworfen am 2026-09-28/29 · Grundlage ist der
Stand nach 1.17.1 (`b068b78`)

## Ziel

Seit Etappe 1 (1.17.0) tragen Anmeldung, Check-in-App und virtuelle Station eine
Content-Security-Policy mit `script-src 'self'`. Das **Dashboard** hat keine — ausgerechnet die
Oberfläche, auf der Verwalter und Administratoren arbeiten und auf der die Lücke aus
GHSA-fj3f-3q3v-w274 wirkte. Die Maskierung ist seit 1.17.0 verlässlicher, aber sie ist die einzige
Schranke: Rutscht ein Wert an ihr vorbei, läuft er als Skript.

Künftig: Das Dashboard und die beiden öffentlichen PHP-Seiten liefern eine CSP aus, die
eingeschleustes Skript nicht ausführt. Dafür müssen alle Inline-Handler aus dem Dashboard weichen.

## Entscheidungen des Nutzers (2026-09-28/29)

| Frage | Entscheidung |
|---|---|
| Umfang | **Dashboard (`index.html` + alle Module) und `reset_password.php`, `verify_email.php`** — nicht `install/`, `update/` |
| Prüfung | **Automatischer Klickdurchgang** unter scharfer CSP, dazu statischer Abgleich |
| Ort des Klickdurchgangs | **Im Repository unter `tests/browser/`**, nur Entwicklungswerkzeug |
| Mechanismus | **Ansatz A: zentrale Aktionstabelle** mit delegierten Zuhörern |

Verworfen:
- **B — nach jedem Rendern binden** (`querySelectorAll(…).forEach(addEventListener)`): Jede der rund
  60 Render-Stellen müsste daran denken. Genau diese Art Vergessen hat das Projekt zuletzt dreimal
  eingeholt (OI-107, dreifach kopierte Maskierung).
- **C — generischer Verteiler auf globale Funktionen** (`data-action="saveMember"` →
  `window[name]()`): Jede eingeschleuste `data-action` könnte jede globale Funktion aufrufen, etwa
  `deleteUser`. Das höhlt aus, was die CSP bringen soll.

## Was der Umbau vorfindet

Gezählt am 2026-09-29 auf `b068b78`:

| Befund | Umfang |
|---|---|
| Inline-Handler in `index.html` | 109 (98 `onclick`, 10 `onchange`, 1 `onsubmit`) — fast alle parameterlos: `closeXModal()`, `saveX()`, `switchImportTab('records')` |
| Inline-Handler in JS-Vorlagen | 115 in 13 Dateien: `records` 21, `members` 16, `exceptions` 15, `appointments` 15, `responses` 11, `users` 10, `devices` 10, `worktime` 6, `management` 6, `import_export` 2, `ui` 1, `auth` 1, `app.js` 1 |
| Inline-`<script>` | 1, am Ende von `index.html` (Installationsprüfung per `fetch('./api/api.php?resource=ping')`) |
| `window.*`-Exporte | 135, die meisten nur, damit Inline-Handler die Funktion finden |
| Öffentliche PHP-Seiten | **kein** `<script>`, nur `<style>`-Blöcke für das Branding |

Das Dashboard lädt keine `data:`- oder `blob:`-Bilder (Downloads per `createObjectURL` sind
Navigationen, der QR-Code ist Inline-SVG), keine Fremdquellen und nutzt kein `eval`.

## Mechanismus

**Neues Modul `public/js/modules/actions.js`:**

- `registerActions(tabelle)` — ein Fachmodul meldet seine Aktionen an:
  `registerActions({ 'member-save': saveMember, 'member-modal-close': closeMemberModal })`.
  Ein bereits vergebener Name wirft einen Fehler; kein Modul überschreibt still das eines anderen.
- Beim ersten Import installiert das Modul je einen Zuhörer am `document` für `click`, `change`
  und `submit` — einmal für die ganze Sitzung, gültig auch für Markup, das später per `innerHTML`
  entsteht.

**Drei Attribute, je Ereignis eines** — das Ereignis hängt nicht am Elementtyp:

| Attribut | Ereignis | ersetzt |
|---|---|---|
| `data-action` | `click` | `onclick` |
| `data-action-change` | `change` | `onchange` |
| `data-action-submit` | `submit`, **immer** mit `preventDefault()` | `onsubmit="return false;"` |

**Aufruf:** Die Funktion erhält `(element, event)`. Argumente stehen als `data-*` am Element
(`data-id="${Number(id)}"`) und werden in der Funktion gelesen. `this.value` wird `element.value`,
`this.parentElement` wird `element.parentElement`.

**Unbekannte Aktion:** Nichts wird ausgeführt, `console.error` meldet den Namen (produktiv erlaubt,
`assets.php`). Der Knopf tut sichtbar nichts — der Fehler steht in der Konsole statt still verloren
zu gehen.

**Namen:** `bereich-verb`, z. B. `member-save`, `records-page`, `calendar-open-appointment`.
Nur als Literal im Markup, **nie** zusammengesetzt (`data-action="${x}"` ist verboten), sonst ist
der statische Abgleich blind.

**`window.*`-Exporte:** Die nur für Inline-Handler bestehenden entfallen. Wo ein anderes Modul eine
Funktion wirklich aufruft (etwa `window.ImportLogs`), bleibt der Export oder wird ein `import`.

## Sonderfälle

| Stelle | Heute | Danach |
|---|---|---|
| Kalender-Popup (`appointments.js`, 5×) | zwei Anweisungen: Popup entfernen, dann Modal öffnen | eine Aktion, die beides tut |
| Mitgliedszeiträume (`updateMembershipDate`, 3×) | `this.value` | `element.value`, Feld und ID als `data-*` |
| Toast schließen (`ui.js`) | `this.parentElement.remove()` | Aktion `toast-close` |
| Hover in zwei Listen (`management.js`, `members.js`, je ein Paar `onmouseover`/`onmouseout`) | setzt `style.background` | CSS-Regel mit `:hover`, kein Skript |
| Fehlerseite bei Umleitungsschleife (`app.js`) | `sessionStorage.clear(); location.reload()` | Aktion `app-reset-reload` |
| Formular mit `onsubmit="return false;"` | Inline | `data-action-submit` ohne eigene Funktion, nur `preventDefault()` |
| Inline-Script in `index.html` | `<script>` am Dateiende | `public/js/install-check.js`, per `<script src>` an derselben Stelle, läuft wie bisher vor dem Modulsystem |

**Unverändert:** Inline-`style`-Attribute (`style-src 'unsafe-inline'` bleibt die bewusste Ausnahme
wie in Etappe 1) und Zuweisungen über `element.style.…` (von einer CSP nicht erfasst).

## Richtlinien

**Dashboard (`index.html`)** — wie `login.html`:

```
default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self';
object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'
```

**Öffentliche PHP-Seiten (`reset_password.php`, `verify_email.php`)** — strenger, weil sie kein
Skript brauchen:

```
default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self';
object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'
```

`script-src 'none'` heißt: Käme dort wieder etwas an der Maskierung vorbei, liefe trotzdem kein
Skript — genau die Stelle, an der 1.17.0 eine Lücke ohne Anmeldung geschlossen hat.

**Ort:** `public/.htaccess`, je ein eigener `<Files>`-Abschnitt (`index.html`; die beiden
PHP-Seiten), **nicht** auf Verzeichnisebene — sonst griffe die Richtlinie auch in `install/` und
`update/`, die nicht dafür vorbereitet sind.

**Zu belegen beim Umbau:** Das Dashboard wird als `/` aufgerufen. Ob Apache den
`<Files "index.html">`-Abschnitt auch über `DirectoryIndex` anwendet, zeigt der HTTP-Test in
`csp.php`. Greift er nicht, wird die Kopfzeile auf den Aufruf über `DirectoryIndex` ausgeweitet und
die gewählte Form hier nachgetragen.

## Tests

1. **`tests/suites/csp.php`, erweitert.** Geprüfte Oberflächen zusätzlich `index.html`, alle Module
   unter `public/js/modules/`, `public/js/app.js`, `reset_password.php`, `verify_email.php`. Regeln
   wie in Etappe 1 (kein `on…=`, kein Inline-`<script>`, keine `javascript:`-URL); für die
   PHP-Seiten zusätzlich „kein `<script>` überhaupt“. Die Gegenprobe „Dashboard ohne CSP“ wird
   umgedreht: `/` muss genau die Richtlinie aus `.htaccess` ausliefern, ebenso die PHP-Seiten.
2. **Abgleich Markup ↔ Aktionstabelle (neu, statisch).** Jeder Wert von `data-action`,
   `data-action-change`, `data-action-submit` in `index.html` und den Vorlagen steht in einem
   `registerActions()`-Aufruf; jede registrierte Aktion wird irgendwo im Markup benutzt; kein
   zusammengesetzter Aktionsname.
3. **Logik der Aktionstabelle (Node, `node --test`, wie `tests/js/filter_chips.test.mjs`).**
   Doppelvergabe wirft; unbekannte Aktion ruft nichts und meldet sich; Aufruf mit
   `(element, event)`; `data-action-submit` ruft `preventDefault()`.
4. **Klickdurchgang `tests/browser/` (Puppeteer, eigener Aufruf, nicht Teil von
   `php tests/run.php`).** Als Admin unter scharfer CSP jede Sektion öffnen, jeden sichtbaren
   `data-action`-Knopf auslösen; speichernde Aktionen werden geöffnet, nicht abgeschickt. Gemeldet
   werden CSP-Verstöße (`securitypolicyviolation`), Laufzeitfehler und nicht gefundene Aktionen.
   Gegenprobe: Ein eingeschleuster `onclick` muss blockiert und gemeldet werden. Voraussetzung nur
   lokal: Node und Chrome. `tests/` ist bereits `export-ignore`.
5. **Bestehende Zusicherungen anpassen.** Mindestens sieben Suiten prüfen heute `onclick`-Strings
   oder die globale Erreichbarkeit eines Handlers (`window.x = x`): `arrival_frontend`,
   `calendar_attendance_frontend`, `filter_chips_frontend`, `profile_dashboard_frontend`,
   `subgroups_frontend`, `responses_frontend`, `module_imports`. Die vollständige Liste ermittelt
   der Umsetzungsplan per Suche nach `onclick`, `onchange` und `window.`. Sie prüfen künftig, dass die Aktion registriert und im Markup
   gesetzt ist. **Jede angepasste Zusicherung wird mit einer Mutation gegengeprüft** (OI-107).

## Reihenfolge

Jeder Schritt ein eigener Commit mit grüner Suite. Bis Schritt 5 funktionieren alte und neue
Handler nebeneinander — jeder Zwischenstand ist benutzbar und releasefähig.

1. **Grundlage:** `actions.js` mit Node-Test, Abgleich-Test (anfangs leer, also grün),
   Klickdurchgang ohne CSP als Messlatte für den alten Zustand.
2. **Kleine Fälle:** `ui.js` (Toast), `auth.js`, `app.js`, `import_export.js`; `install-check.js`;
   Hover als CSS.
3. **Module samt ihrem `index.html`-Teil**, damit Knopf und Aktion im selben Commit wandern:
   `management`, `worktime`, `devices`, `users`, `responses`, `members`, `exceptions`, `records`
   (21, dazu Paginierung), zuletzt `appointments` mit dem Kalender-Popup.
4. **Zusicherungen** im Commit des Moduls, dessen Handler sie prüfen.
5. **CSP scharf:** Kopfzeilen für `index.html` und die PHP-Seiten, `csp.php` erweitern,
   Klickdurchgang unter CSP.
6. **Übrige `window.*`-Exporte abräumen**, soweit sie nur Inline-Handlern dienten.

**Aufwand:** zwei bis drei Tage, etwa die Hälfte für Schritt 3. Der manuelle Durchgang durch
`docs/testplan.md` entfällt weitgehend; ein kurzer Blick auf die Hauptabläufe vor dem Release
bleibt sinnvoll.

**Arbeitsort:** eigener Worktree mit eigener Datenbank (rund 60 Dateien werden angefasst).
Kein Versionssprung; Eintrag unter `## [Unreleased]`, das Release macht die Release-Sitzung.

## Dokumentation

- `CHANGELOG.md`: Abschnitt „Sicherheit“ unter `[Unreleased]`.
- `docs/OPEN-ITEMS.md`: OI-17 erledigt, OI-112 mit geschlossen (es gibt keine `on…`-Attribute mehr).
- `CLAUDE.md`: Abschnitt Sicherheit „CSP für alle Oberflächen außer `install/`, `update/`“;
  unter Konventionen die Regel „Knöpfe nur über `data-action` und `registerActions()`, kein
  `on…=`, kein zusammengesetzter Aktionsname“.
- `README.md`: Hinweis „Dashboard bewusst ohne CSP“ streichen.
- `docs/DEMO.md` und OI-44: Das gespeicherte XSS zwischen zwei Resets wird durch die CSP
  entschärft — beide Stellen bekommen den Satz dazu.

## Nicht in diesem Vorhaben

- `install/` und `update/` — nach Gebrauch per `.htaccess` gesperrt; der Update-Pfad hat eigene
  Regeln (Syntax nur bis PHP 8.0).
- Inline-`style` abschaffen — `style-src 'unsafe-inline'` bleibt die bewusste Ausnahme.
- OI-111 (`showToast()` maskiert nicht selbst) und OI-113 (Senken-Wächter folgt `join()` nicht) —
  eigene Vorgänge; die CSP mildert ihre Folgen, behebt sie aber nicht.
