# FI-8: Kalender-Abo (ICS-Feed)

**Stand:** 2026-10-07 · **Status:** entworfen, freigegeben · **Zweig:** `feat/fi-8-ics-abo` ·
**Idee:** [FI-8](../../FEATURE-IDEAS.md#fi-8--kalender-abo-ics-feed)

## Ziel

Jedes Mitglied kann seine Vereinstermine in Telefon, Mail-Programm oder Cloud-Kalender abonnieren:
eine persönliche, mit eigenem Token geschützte Kalender-URL, nur lesend, nur die Termine der
eigenen Gruppen, mit der eigenen Rückmeldung im Titel.

## Ausgangslage

- Ort (`location`) und Ende (`end_time`) am Termin gibt es seit 1.10.0 (FI-23).
- Serientermine liegen seit 1.11.0 als einzelne Zeilen in `appointments` (mit `series_id`);
  Ausnahmen und Einzeländerungen sind dort schon aufgelöst.
- Welche Termine ein Mitglied sehen darf, bestimmt `appointmentGroupVisibility()`
  (`private/helpers/appointment_rules.php`); Terminliste und Einzelabruf nutzen sie.
- Das API-Token (`users.api_token`) liegt im Klartext und erlaubt Schreibzugriffe — für einen
  Link, der weitergegeben wird oder in einem Cloud-Kalender landet, ungeeignet.
- Die Anwendung setzt keine Zeitzone; Termine sind Ortszeit ohne Zonenangabe.
- Abrufe ohne Sitzung zählen in die Rate-Grenze für Unangemeldete (150/min je IP,
  `api.php` Abschnitt 6.2). Google Calendar ruft alle Feeds von wenigen eigenen IPs ab.

## Entscheidungen

| Frage | Entscheidung |
|---|---|
| Inhalt für Admin/Manager | Wie für Mitglieder: Termine der Gruppen des verknüpften Mitglieds. Konto ohne Mitglied → kein Feed |
| Detailtiefe | Termindaten plus eigene Rückmeldung; keine Daten anderer Personen |
| Zeitraum | heute − 3 Monate bis heute + 12 Monate |
| Zeitzone | fest `Europe/Berlin` mit eingebettetem `VTIMEZONE` |
| Abgesagte Termine | je Abo umschaltbar, **Standard: ausblenden** |
| Feature-Schalter | `calendar_feed`, setzt `appointments` voraus, **Standard: aus** |
| Aufbau | eigene Tabelle, Ressourcen in `api.php` (Variante A) |

Verworfen: Token als Spalte in `users` (B; kaum Unterschied, `users` wächst weiter), eigenes
Einstiegsskript `public/calendar.php` (C; Rate-Grenze, Wartungsflag und Schalter doppelt —
genau solche Zweitpfade sind früher auseinandergelaufen). Wiederholungsregel (`RRULE`) im Feed:
Einzeltermine sind robuster, weil Ausnahmen und Einzeländerungen schon aufgelöst sind.

## Datenmodell

Neue Tabelle `{PREFIX}calendar_feeds`:

| Spalte | Typ | Inhalt |
|---|---|---|
| `user_id` | `INT NOT NULL` | PK, FK auf `users(user_id)` `ON DELETE CASCADE` |
| `token_hash` | `CHAR(64) NOT NULL` | SHA-256 (hex) des Tokens, `UNIQUE` |
| `hide_declined` | `TINYINT(1) NOT NULL DEFAULT 1` | abgesagte Termine ausblenden |
| `created_at` | `DATETIME NOT NULL` | Erzeugung des aktuellen Tokens |
| `last_fetched_at` | `DATETIME NULL` | letzter Abruf, höchstens stündlich geschrieben |

- Token: 32 Zufallsbytes, hex (64 Zeichen). Gespeichert wird nur der Hash; der Klartext steht
  genau einmal in der Antwort auf das Erzeugen.
- Schema in `private/setup/ehrensache_db.sql` nachziehen. **Kein Migrationsschritt im
  Feature-Zweig** — den legt die Release-Sitzung an; dafür ein Eintrag in `OPEN-ITEMS.md`.

## API

### `calendar_feed` (angemeldet, Verwaltung des eigenen Abos)

Immer nur das eigene Konto; eine `user_id` von außen wird nicht angenommen.
Gerätekonto → `403`. Konto ohne verknüpftes Mitglied → `409` mit `code: "NO_MEMBER"`
(bei `GET` stattdessen `active: false, member_linked: false`, damit die Karte den Hinweis zeigen kann).

| Methode | Wirkung | Antwort |
|---|---|---|
| `GET` | Status | `{active, member_linked, hide_declined, created_at, last_fetched_at}` |
| `POST` | Token erzeugen oder ersetzen; `hide_declined` bleibt beim Ersetzen erhalten | `{url, webcal_url, created_at, hide_declined}` — einmalig |
| `PUT` | `{hide_declined: bool}` ändern, Link bleibt gleich | Status wie `GET`; ohne Abo `404` |
| `DELETE` | Abo widerrufen (Zeile löschen) | `{message}`; ohne Abo ebenfalls Erfolg |

Die URL baut der Server aus `BASE_URL` (`private/helpers/bootstrap.php`; `base_url` aus der
Konfiguration oder geraten), nicht das Frontend. `webcal_url` ist dieselbe URL mit Schema `webcal://`.

### `calendar` (öffentlich, Abschnitt 6 in `api.php`)

- URL: `…/api/calendar/<token>.ics`, per `RewriteRule` in **`public/.htaccess`** auf
  `api.php?resource=calendar&token=<token>`. Direkter Aufruf über den Query-Parameter
  funktioniert ebenso. Nicht in `public/api/.htaccess`: Eine eigene `RewriteEngine` dort ersetzte
  für alle API-Aufrufe die Regeln von `public/.htaccess`, darunter das Durchreichen des
  `Authorization`-Headers (beim Planen festgestellt).
- Keine Sitzung (`session_start()` entfällt für `calendar`), keine CORS-Header (der Feed braucht
  keinen Zugriff aus fremden Seiten; vgl. OI-126).
- Nur `GET` (und `HEAD`), sonst `405`.
- Token-Format prüfen (`^[0-9a-f]{64}$`), dann Hash nachschlagen, verknüpftes Konto und Mitglied
  laden.
- **`404` mit leerem Textkörper** bei: unbekanntem oder falsch geformtem Token, Konto
  `is_active = 0` oder `account_status <> 'active'`, kein verknüpftes Mitglied, Funktion
  `calendar_feed` abgeschaltet. Alle Fälle sehen gleich aus; beim Schalter bewusst nicht
  `FEATURE_DISABLED`.
- Wartungsmodus: bestehendes Wartungsflag greift vor allem anderen (`503`).
- `last_fetched_at` nur schreiben, wenn `NULL` oder älter als eine Stunde.

### Rate-Grenze

Ein Abruf mit **gültigem** Token zählt nicht in die Grenze für Unangemeldete; ein Abruf mit
ungültigem Token zählt wie bisher. So bleibt Durchprobieren gebremst, und gemeinsame Abruf-IPs
großer Kalenderanbieter laufen nicht voll. Umsetzung: Abschnitt 6.2 von `api.php` ermittelt
für `calendar` den Inhaber (`calendarFeedOwner()`); ein gültiger Inhaber gilt dort wie eine
Anmeldung, alles andere läuft durch den vorhandenen Zähler. Direkt danach endet der Abruf im
Handler — es gibt keinen zweiten Zählweg.

### Feature-Schalter

Eintrag in `FEATURES` (`private/helpers/features.php`):
`'calendar_feed' => ['setting' => 'calendar_feed_enabled', 'default' => '0',
'requires' => ['appointments'], 'resources' => ['calendar_feed']]`.
`calendar` steht **nicht** in `resources`, weil `api.php` sonst die JSON-Antwort
`FEATURE_DISABLED` liefert; der Handler prüft den Schalter selbst und antwortet `404`.
Einstellungsschlüssel ins Schema (`system_settings`, Default `0`).

### Demo-Modus

`private/helpers/demo_mode.php`: `calendar_feed` in `DEMO_WRITE_ALLOWED` mit `POST`, `PUT`,
`DELETE`; `calendar` in `DEMO_READ_ONLY`.

## Inhalt des Feeds

### Auswahl

- `appointmentGroupVisibility($db, $prefix, $memberId)`; `null` → leerer Kalender (gültiges
  `VCALENDAR` ohne `VEVENT`).
- `a.date BETWEEN heute − 3 Monate AND heute + 12 Monate`.
- `a.is_auto_created = 0`.
- Eigene Rückmeldung per `LEFT JOIN appointment_responses` auf `member_id`.
- `hide_declined = 1` → Termine mit eigener Rückmeldung `no` fehlen. `maybe` bleibt.
  Die Rückmeldung zählt nur, wenn die Terminart Rückmeldungen erlaubt (`responses_enabled`).

### Kalenderkopf

```
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//EhrenSache//Kalender-Abo//DE
CALSCALE:GREGORIAN
METHOD:PUBLISH
X-WR-CALNAME:<Organisationsname> – Termine
X-WR-TIMEZONE:Europe/Berlin
REFRESH-INTERVAL;VALUE=DURATION:PT1H
X-PUBLISHED-TTL:PT1H
BEGIN:VTIMEZONE … (fester Block Europe/Berlin, CET/CEST nach EU-Regel)
```

Organisationsname aus `system_settings` (`organization_name`); fehlt er, nur „Termine“.

### Je Termin

| Feld | Quelle |
|---|---|
| `UID` | `appointment-<id>@<Host der Basis-URL>` |
| `DTSTAMP` | Erzeugungszeitpunkt, UTC (`…Z`) |
| `DTSTART;TZID=Europe/Berlin` | `date` + `start_time` |
| `DTEND;TZID=Europe/Berlin` | `date` + `end_time`; Ende ≤ Beginn → Folgetag; ohne Ende entfällt das Feld |
| `SUMMARY` | Präfix der eigenen Rückmeldung + Titel: `✓ ` zugesagt, `✗ ` abgesagt, `? ` unsicher, sonst nichts |
| `LOCATION` | `location`, falls gesetzt |
| `CATEGORIES` | Name der Terminart, falls gesetzt — nie die Gruppenzuordnung |
| `DESCRIPTION` | Beschreibung; Leerzeile; `Terminart: …`; `Deine Rückmeldung: Zugesagt/Abgesagt/Unsicher` und ggf. `– <eigener Kommentar>` |

Gelöschte Termine fehlen beim nächsten Abruf; der Feed wird jedes Mal vollständig erzeugt,
`STATUS:CANCELLED` ist nicht nötig.

### Format

- RFC 5545: Text maskieren (`\\`, `\;`, `\,`, Zeilenumbruch → `\n`), Zeilen nach 75 Bytes falten
  (Fortsetzung mit führendem Leerzeichen, nie innerhalb eines UTF-8-Zeichens), Zeilenende CRLF.
- Header: `Content-Type: text/calendar; charset=utf-8`,
  `Content-Disposition: inline; filename="termine.ics"`, `Cache-Control: private, max-age=0`,
  `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`.

### Aufbau

- `private/helpers/ical.php` — reine Funktionen ohne Datenbank: Maskieren, Falten, `VTIMEZONE`,
  ein `VEVENT` aus einer Terminzeile, der ganze Kalender aus Kopfdaten und Zeilen.
- `private/handlers/calendar.php` — `handleCalendarFeed()` (Verwaltung) und
  `handleCalendarDownload()` (öffentlicher Abruf); Abfrage, Token-Prüfung, Header.

## Oberfläche

Karte **„Kalender-Abo“** in „Mein Profil“ (`public/index.html`), unter der Token-Karte, mit
`data-feature="calendar_feed"`.

| Zustand | Inhalt |
|---|---|
| Kein Mitglied verknüpft | Hinweis „Das Abo braucht ein verknüpftes Mitglied.“, kein Knopf |
| Kein Abo | Erklärsatz, Knopf „Abo-Link erzeugen“ |
| Direkt nach dem Erzeugen | Link schreibgeschützt, „Kopieren“, „In Kalender öffnen“ (`webcal://`); Warnung „Der Link wird nur jetzt angezeigt. Wer ihn hat, sieht deine Termine.“ |
| Abo aktiv | „Aktiv seit … · zuletzt abgerufen …“ bzw. „noch nie abgerufen“; Schalter „Abgesagte Termine nicht im Kalender anzeigen“; „Neuen Link erzeugen“ und „Abo beenden“, beide mit Rückfrage |

Aufklappbare Kurzhilfe: Google Calendar (Weboberfläche, „Weitere Kalender → Per URL“), iPhone
(Link antippen oder Einstellungen → Kalender → Accounts → Abonnierter Kalender), Outlook (Kalender
hinzufügen → Aus dem Internet). Hinweis: Google ruft nur alle paar Stunden ab, Änderungen kommen
verzögert an.

Technik:
- Neues Modul `public/js/modules/calendar_feed.js`, Aktionen über `data-action` /
  `data-action-change` und `registerActions()` am Modulende; nichts auf `window`.
- Laden beim Öffnen des Profils; `isFeatureOn('calendar_feed')` steuert, ob überhaupt abgerufen wird.
- CSS in vorhandenen Profilklassen bzw. `components/`, Farben nur über `variables.css`; neue
  Datei per `@import` in `main.css`.
- Einstellungsseite: Schalter „Kalender-Abo“ in `FEATURE_SWITCHES` (unter „Termine“) und
  `FEATURE_KEYS` (`settings.js`).

**Nicht im Umfang:** Check-in-App (dort keine Kontoseite), Verwalteransicht der Abos aller
Mitglieder, Benachrichtigung bei Terminänderung.

## Sicherheit und Datenschutz

- Feed enthält nur Termindaten und die eigene Rückmeldung, keine Namen anderer, keine
  Gruppenlisten. [OI-97](../../OPEN-ITEMS.md#oi-97--terminarten-kennen-keine-gruppengrenze) wird
  davon nicht berührt (nur der Name der Terminart geht hinaus); Vermerk im Eintrag.
- Das Token steht in der URL und damit in Zugriffsprotokollen und beim Kalenderanbieter —
  unvermeidbar, benannt in `DATENSCHUTZ.md`. Gegenmittel: Widerruf jederzeit, Anzeige des letzten
  Abrufs, nur Hash in der Datenbank.
- Mit dem Abo-Link ist kein Schreibzugriff möglich; das API-Token bleibt unberührt.
- Datenauskunft (`my_data`): Abo-Status (aktiv seit, zuletzt abgerufen, abgesagte ausblenden),
  ohne Hash.
- Kontolöschung: Eintrag entfällt per `CASCADE`. Kontodeaktivierung: Feed antwortet `404`.
- `DATENSCHUTZ.md`: Funktion standardmäßig aus; mit dem Abonnieren gehen Termindaten an den
  gewählten Kalenderanbieter.

## Tests

- **`ical_unit`** (neu): Maskierung; Faltung bei 75 Bytes mit Umlauten und Emoji an der Grenze;
  CRLF; Ende ≤ Beginn → Folgetag; ohne Ende kein `DTEND`; Präfixe je Rückmeldung; kein Präfix
  ohne Rückmeldung oder bei abgeschalteten Rückmeldungen; `VTIMEZONE` vorhanden; leerer Kalender
  gültig.
- **`calendar_feed_api`** (neu):
  - Erzeugen → Status aktiv; Feed abrufbar; Ersetzen → alter Link `404`, neuer `200`;
    `hide_declined` bleibt beim Ersetzen; Widerrufen → `404`
  - `PUT hide_declined`: abgesagter Termin verschwindet bzw. erscheint mit `✗`; `maybe` bleibt
  - Gruppengrenze: Termin der eigenen Gruppe enthalten, Termin einer fremden Gruppe nicht —
    zusätzlich als Mutationsprobe (Sichtbarkeitsbedingung entfernen → Test muss rot werden)
  - Zeitraumgrenzen; Auto-Termine fehlen
  - `404` bei: unbekanntem Token, falschem Format, deaktiviertem Konto, Funktion aus
  - Gerätekonto `403`, Konto ohne Mitglied `409`
  - Rate-Grenze: ungültige Abrufe zählen, gültige nicht
  - Header `Content-Type` und `Cache-Control`
- Vorhandene Wächter greifen ohne Zutun: `demo_mode`, `features_unit`, `actions_frontend`, `csp`,
  `api_doc_keys`, `assets`, `js_syntax`, `css_bundle_unit`.
- Browser: Karte in allen Zuständen (Browser-Pane bzw. Puppeteer); Feed einmal durch einen
  ICS-Prüfer und in Thunderbird oder Outlook über die lokale URL. Google erreicht `localhost`
  nicht — Prüfung dort erst auf der Demo.

## Dokumentation

- `API.md`: Abschnitte `calendar_feed` und `calendar`
- `CHANGELOG.md` unter `## [Unreleased]`
- `DATENSCHUTZ.md`, `README.md` (Funktionsliste), `docs/testplan.md`
- `docs/FEATURE-IDEAS.md`: FI-8 als umgesetzt (unveröffentlicht) markieren
- `docs/OPEN-ITEMS.md`: neuer Eintrag „Migrationsschritt für `calendar_feeds` fehlt“ (für die
  Release-Sitzung, Muster OI-122) und ein Satz bei OI-97
- Kein Versionssprung, kein Migrationsschritt, kein `?v=`-Sprung (Regel für Feature-Zweige)
