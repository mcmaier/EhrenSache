# Design: Terminserien, Feiertage und Anlegen im Kalender (FI-7, FI-16, OI-64)

**Datum:** 2026-09-18
**Status:** Approved
**Zielversion:** 1.11.0 — setzt 1.10.0 voraus (FI-23: `location`, `end_time`)
**Betrifft:** Schema (`appointment_series`, `appointments`, `system_settings`),
`private/handlers/appointments.php`, neue Handler `appointment_series.php` und `holidays.php`,
neue Helfer `recurrence.php` und `holidays.php`, `private/helpers/demo_mode.php`, Dashboard
(`appointments.js`, Termin-Dialog in `index.html`, Einstellungen), neues Modul
`date_checklist.js`, Import-Vorschau (`import_export.js`), `API.md`

## Problem

**Wiederkehrende Proben werden einzeln angelegt.** Die wöchentliche Probe ist der Normalfall
eines Musikvereins; heute legt jemand rund vierzig Termine im Jahr von Hand an, jeweils über
Knopf und Datumsfeld (FI-7).

**Der Kalender kennt keine Feiertage.** Wer eine Serie anlegt, legt ohne diese Kenntnis Proben
auf Karfreitag und Fronleichnam und räumt sie hinterher von Hand ab (FI-16).

**Im Kalender lässt sich kein Termin anlegen.** `createCalendarDay()` hängt an einen leeren Tag
keinen Handler; ein Klick tut nichts (OI-64).

## Entscheidungen

| Frage | Entscheidung | Begründung |
|---|---|---|
| Speicherform | Serie als Regel **und** echte Einzeltermine mit `series_id` | `records`, `exceptions`, `appointment_responses` und `work_sessions` zeigen auf eine `appointment_id`; eine berechnete Serie hätte keine |
| Ende einer Serie | Immer mit Enddatum, höchstens 12 Monate; „Serie fortsetzen" verschiebt es | Produktivsysteme haben keinen Cron (gleiche Grenze wie FI-6); Verlängern beim Lesen hieße Schreiben im GET. „Bis Ende Juli" entspricht der Saisonplanung |
| Ausfälle | Löschen wie bisher, Datum in `exdates` der Serie | Kein `is_cancelled` — das träfe Listen, Statistik, Berichte, Check-in, Station, PWA und Rückmeldungen und verdoppelte das Vorhaben |
| Serienaktionen und erfasste Daten | Termine mit Daten werden von Serienaktionen nie gelöscht, sondern abgelöst (`is_detached`) und gemeldet | Erfasste Anwesenheit ist die Primärdatei. Einzellöschen bleibt wie heute |
| Bearbeiten | „Nur dieser" / „Dieser und alle folgenden" — kein „Alle" | „Alle" änderte vergangene Termine mit Anwesenheit und Pünktlichkeit; es liefe ohnehin auf „nur künftige" hinaus |
| Muster | Wöchentlich (alle 1–4 Wochen, ein oder mehrere Wochentage); monatlich nach Position (1.–4. oder letzter Wochentag) | Deckt Probe, Jugendprobe, Vorstandssitzung, Stammtisch. „Jeden 15." braucht kaum jemand und wirft die Frage nach dem 31. auf; täglich und jährlich entfallen |
| Regelformat | Teilmenge von RFC 5545 (`FREQ`, `INTERVAL`, `BYDAY`), Ende als eigene Spalte | Direkt verwendbar für FI-8; `until` als DATE ist sortier- und filterbar |
| Gruppen | Keine Zuordnung an der Serie | Gruppen hängen an der Terminart (`appointment_type_groups`); die Serie hat eine Terminart |
| Anlegen | Vorschau vor dem Speichern, vom Server berechnet | Feiertage und Kollisionen sieht man vorher, nicht hinterher. Server-Probelauf, damit Vorschau und Ergebnis nicht auseinanderlaufen |
| Kollisionen | Werden in Vorschau und beim Schreiben ausgelassen und gemeldet | Die bestehende Dublettenprüfung (±Toleranz, gleiche Terminart) gilt unverändert |
| Feiertage | Berechnet (Gauß-Osterformel), eigener Helfer, **ohne** `ext-calendar` | Keine Datenquelle, kein Import, keine neue Anforderung in `version.json` |
| Bundesland | Eine Einstellung je Installation, leer = nur bundesweite Feiertage | Eine Zuordnung je Mitglied wäre unverhältnismäßig |
| Feiertage anzeigen | In der Serienvorschau **und** im Kalender | Die Berechnung existiert ohnehin; ein Einzeltermin auf dem 3. Oktober fällt sofort auf |
| Serie verwalten | Im Dialog jedes Serientermins, keine eigene Serienliste | Ein Verein hat zwei bis fünf Serien; der Kalender zeigt sie ohnehin |
| Kalenderklick | Leerer Tag öffnet den Anlegen-Dialog; belegter Tag behält das Popup, erweitert um Aktionsknöpfe; kein Kontextmenü | Ein Bedienmuster statt zwei; Rechtsklick ist auf dem Telefon nicht auffindbar |
| Architektur | Eigene Tabelle und Ressource `appointment_series` | `appointments.php` bleibt bei Einzelterminen; die kniffligen Teile liegen in reinen, testbaren Helfern |

## Teil 1 — Datenmodell

### Schema

Neue Tabelle `{PREFIX}appointment_series`:

| Spalte | Typ | Zweck |
|---|---|---|
| `series_id` | `INT NOT NULL AUTO_INCREMENT`, PK | |
| `rrule` | `VARCHAR(100) NOT NULL` | nur `FREQ`, `INTERVAL`, `BYDAY` — z. B. `FREQ=WEEKLY;INTERVAL=2;BYDAY=TU,TH`, `FREQ=MONTHLY;INTERVAL=1;BYDAY=-1WE` |
| `start_date` | `DATE NOT NULL` | erster möglicher Termin (DTSTART) |
| `until` | `DATE NOT NULL` | letzter möglicher Termin, einschließlich |
| `exdates` | `TEXT NULL` | JSON-Array von `YYYY-MM-DD`, sortiert, ohne Doppel |
| `title` | `VARCHAR(200) NOT NULL` | Vorlage |
| `type_id` | `INT NULL`, FK → `appointment_types` `ON DELETE SET NULL` | Vorlage |
| `description` | `TEXT NULL` | Vorlage |
| `start_time` | `TIME NOT NULL` | Vorlage |
| `end_time` | `TIME NULL` | Vorlage (Regeln aus 1.10.0) |
| `location` | `VARCHAR(200) NULL` | Vorlage (Regeln aus 1.10.0) |
| `created_by` | `INT NULL`, FK → `users` `ON DELETE SET NULL` | |
| `created_at` | `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` | |

`{PREFIX}appointments` erhält:

```sql
ALTER TABLE {PREFIX}appointments ADD COLUMN series_id INT NULL AFTER type_id;
ALTER TABLE {PREFIX}appointments ADD COLUMN is_detached TINYINT(1) NOT NULL DEFAULT 0 AFTER series_id;
-- Index auf series_id, FK → appointment_series ON DELETE SET NULL
```

`{PREFIX}system_settings` erhält `holiday_region` (Typ `text`, Default leer, Kategorie `general` — die ENUM kennt keine eigene; einsortiert im Untertab der
Termineinstellungen). Zulässig: leer oder eines der 16 Länderkürzel `BW BY BE BB HB HH HE MV NI
NW RP SL SN ST SH TH`.

### Migration

`private/migrations/1.10.0.php` mit `migrate_1_10_0()`, Manifest-Eintrag `1.10.0 → 1.11.0`.
Idempotent: Tabelle, Spalten, Index und Fremdschlüssel nur, wenn sie fehlen; Einstellung per
`INSERT IGNORE`. `private/setup/ehrensache_db.sql` wird nachgezogen.

### Bedeutung der Felder am Einzeltermin

- `series_id` gesetzt, `is_detached = 0`: Der Termin folgt der Serie.
- `is_detached = 1`: sichtbar Teil der Serie (Symbol, Kasten im Dialog), aber von Serienaktionen
  ausgenommen. Gesetzt durch Bearbeiten mit „Nur dieser" und automatisch, wenn eine Serienaktion
  einen Termin mit Daten stehen lassen muss.
- `series_id = NULL`: gewöhnlicher Einzeltermin wie bisher.

**„Termin mit Daten"** heißt: mindestens ein Eintrag in `records`, `appointment_responses`,
`exceptions` oder `work_sessions` mit dieser `appointment_id`. Einmal definiert in
`appointmentHasData()`.

## Teil 2 — Rechenhelfer

### `private/helpers/recurrence.php`

Rein, ohne Datenbank, `declare(strict_types=1)`.

- `parseRrule(string $rrule): array` — liefert `['freq' => 'WEEKLY'|'MONTHLY', 'interval' =>
  int, 'byday' => [...]]`. Wirft `InvalidArgumentException` bei allem außerhalb der Teilmenge:
  - WEEKLY: `INTERVAL` 1–4, `BYDAY` eine Liste aus `MO TU WE TH FR SA SU`, mindestens ein Tag,
    keine Doppel.
  - MONTHLY: `INTERVAL` genau 1, `BYDAY` genau ein Eintrag mit Position `1`–`4` oder `-1`,
    z. B. `2TU`, `-1WE`.
  - Fehlt `INTERVAL`, gilt 1. Unbekannte Schlüssel (`UNTIL`, `COUNT`, `BYMONTHDAY` …) werden
    abgelehnt.
- `buildRrule(array $rule): string` — kanonische Schreibweise (Schlüsselfolge `FREQ`,
  `INTERVAL`, `BYDAY`; Wochentage in Wochenfolge). `parseRrule(buildRrule($r)) == $r`.
- `expandOccurrences(array $rule, string $startDate, string $until, array $exdates = []):
  array` — alle Daten `YYYY-MM-DD` im Bereich `[startDate, until]`, aufsteigend, ohne
  `exdates`. Rechnung ausschließlich mit Kalenderdaten über `DateTimeImmutable` (keine Uhrzeit,
  daher unempfindlich gegen die Zeitumstellung).
  - WEEKLY mit Abstand n: Wochen werden ab der Woche von `startDate` gezählt (Montag als
    Wochenbeginn); getroffen werden die gewählten Tage in jeder n-ten Woche, soweit ≥ `startDate`.
  - MONTHLY: in jedem Monat ab dem Monat von `startDate` der n-te bzw. letzte gewählte
    Wochentag, soweit ≥ `startDate`.
- `SERIES_MAX_MONTHS = 12` — Konstante für die Grenze.

### `private/helpers/holidays.php`

Rein, ohne Datenbank, **ohne** `ext-calendar`.

- `easterSunday(int $year): string` — Gauß/Meeus, gregorianisch.
- `holidaysBetween(string $from, string $to, ?string $region): array` — `['YYYY-MM-DD' =>
  'Name']`, aufsteigend. Leeres oder unbekanntes `$region` → nur bundesweite.

Bundesweit: Neujahr (1.1.), Karfreitag (O−2), Ostermontag (O+1), Tag der Arbeit (1.5.),
Christi Himmelfahrt (O+39), Pfingstmontag (O+50), Tag der Deutschen Einheit (3.10.),
1. und 2. Weihnachtstag (25./26.12.).

Regional:

| Feiertag | Datum | Länder | seit |
|---|---|---|---|
| Heilige Drei Könige | 6.1. | BW, BY, ST | |
| Internationaler Frauentag | 8.3. | BE (2019), MV (2023) | je Land |
| Ostersonntag | O | BB | |
| Pfingstsonntag | O+49 | BB | |
| Fronleichnam | O+60 | BW, BY, HE, NW, RP, SL | |
| Mariä Himmelfahrt | 15.8. | SL | |
| Weltkindertag | 20.9. | TH | 2019 |
| Reformationstag | 31.10. | BB, MV, SN, ST, TH; HB, HH, NI, SH ab 2018 | je Land |
| Allerheiligen | 1.11. | BW, BY, NW, RP, SL | |
| Buß- und Bettag | Mittwoch vor dem 23.11. | SN | |

**Bewusst nicht erzeugt** (Hinweis an der Einstellung): Mariä Himmelfahrt in Bayern (nur in
Gemeinden mit überwiegend katholischer Bevölkerung), Augsburger Friedensfest (nur Stadt
Augsburg), Fronleichnam in Teilen Sachsens und Thüringens, einmalige Feiertage (z. B. Reformationstag 2017
bundesweit, 8.5.2025 in Berlin). Der Hinweis nennt diese Fälle ausdrücklich und empfiehlt, betroffene Daten in der
Vorschau von Hand abzuwählen.

### Aus `appointments.php` herausgelöst

- `findAppointmentConflict(PDO $db, string $prefix, string $date, string $time, ?int $typeId,
  ?int $excludeId = null, ?float $toleranceHours = null): ?array` — die bisher doppelt
  vorhandene Dublettenprüfung aus POST und PUT, unverändert in der Logik. Liefert den
  kollidierenden Termin (`appointment_id`, `title`, `date`, `start_time`) oder `null`.
- `appointmentHasData(PDO $db, string $prefix, int $appointmentId): bool`.

Beide liegen in einem Helfer, den `appointments.php` und `appointment_series.php` gemeinsam
laden (Arbeitstitel `private/helpers/appointments.php`). POST und PUT von `appointments` rufen
`findAppointmentConflict()` statt der eingebetteten Abfrage; ihre Antworten (409 mit `conflict`
und `hint`) bleiben bytegleich.

## Teil 3 — API

### Ressource `appointment_series` (neu)

Alle Aufrufe `requireAdminOrManager()`; einfacher Nutzer und Gerät → `403`.

Gemeinsamer Anfragekörper für Anlegen und Split („Seriendefinition"):

```json
{
  "rrule": "FREQ=WEEKLY;INTERVAL=1;BYDAY=TU",
  "start_date": "2026-10-06", "until": "2027-07-27",
  "exdates": ["2026-12-29"],
  "title": "Gesamtprobe", "type_id": 3, "description": null,
  "start_time": "19:30", "end_time": "22:00", "location": "Probelokal"
}
```

| Aufruf | Wirkung |
|---|---|
| `GET ?id=X` | Serie mit `rrule`, `start_date`, `until`, `exdates`, Vorlage, `appointment_count`, `detached_count` |
| `POST ?preview=1` | Probelauf, schreibt nichts. Antwort: `{"occurrences": [{"date", "holiday": "Name"\|null, "conflict": {...}\|null, "excluded": bool}], "count"}` — Daten aus `expandOccurrences()` **ohne** `exdates`, damit die Oberfläche abgewählte Tage anzeigen kann; abgewählte tragen `excluded: true`. Jeder gesetzte `preview`-Wert außer `0` gilt als Vorschau |
| `POST` | Anlegen in **einer Transaktion**: Serienzeile, dann je Datum aus `expandOccurrences(…, exdates)` ein Termin. Kollisionen werden erneut geprüft, ausgelassen und ins `exdates` der Serie übernommen. Antwort `201` `{"series_id", "created": n, "skipped": [{"date", "reason": "conflict", "conflict": {...}}]}` |
| `PUT ?id=X` `{"from_date", …Vorlagenfelder}` | „Dieser und alle folgenden" **ohne** Regeländerung: aktualisiert die Vorlage der Serie und alle Termine mit `date >= from_date`, `is_detached = 0` an Ort und Stelle (IDs bleiben). Geprüft wird je Termin mit seinen wirksamen Werten (gesendet, sonst der Stand des Termins). Unverändert bleiben, abgelöst und gemeldet werden Termine, deren Änderung eine Kollision erzeugen würde (`conflict`), deren Ende dann dem Beginn gliche (`invalid_time`) oder für die schon Anwesenheit erfasst ist und deren Beginn oder Terminart sich ändern würde (`has_data` — schützt Anwesenheit und Pünktlichkeit; Rückmeldungen und Entschuldigungen ziehen dagegen mit, damit die Serie auch künftige, schon zugesagte Termine verschieben kann). Antwort `{"updated", "detached": [{appointment_id, date, reason, conflict?}]}` |
| `POST ?id=X&action=split` (+`preview=1`) `{"from_date", …Seriendefinition}` | „Dieser und alle folgenden" **mit** Regeländerung: alte Serie endet am Vortag von `from_date` (wie DELETE unten), neue Serie ab `from_date` (wie POST). Eine Transaktion. Antwort wie POST, ergänzt um `removed` und `detached` |
| `POST ?id=X&action=extend` (+`preview=1`) `{"until"}` | „Serie fortsetzen": neues `until` > altes, höchstens 12 Monate über das alte hinaus; erzeugt die Termine im Bereich (altes `until`, neues `until`] nach der gespeicherten Regel und Vorlage. Vorschau wie bei POST. Eine Transaktion |
| `DELETE ?id=X&from=DATE` | „Ab hier beenden": `until` = Vortag; Termine mit `date >= from`, `is_detached = 0`: ohne Daten gelöscht (wie Einzellöschen), mit Daten abgelöst. Hat die Serie danach keinen Termin mehr, wird die Serienzeile gelöscht. Ist `from` der Serienbeginn, endet die Serie ganz: Die Serienzeile wird immer gelöscht, verbleibende abgelöste Termine werden zu gewöhnlichen Einzelterminen. Antwort `{"removed", "detached": [...], "series_deleted": bool}` |

Vorschau und Schreiben benutzen denselben Code; `preview=1` unterscheidet sich nur darin, dass
nichts geschrieben wird.

**Validierung** (`400`, deutsche Meldung):
- `rrule` außerhalb der Teilmenge (`parseRrule()` wirft)
- `start_date`/`until` kein gültiges Datum; `until < start_date`; `until` mehr als 12 Monate
  nach `start_date` (bei `extend`: nach dem alten `until`)
- Regel ergibt im Zeitraum keinen einzigen Termin
- Vorlagenfelder wie bei `appointments` (Titel Pflicht, `start_time` Pflicht, `end_time` und
  `location` nach den Regeln aus 1.10.0)
- `from_date` außerhalb `[start_date, until]` der Serie
- `exdates` mit ungültigen Daten

Unbekannte `id` → `404`.

### Ressource `holidays` (neu)

`GET ?from=YYYY-MM-DD&to=YYYY-MM-DD` → `{"region": "BY"|null, "holidays": {"2026-12-25":
"1. Weihnachtstag", …}}` für das eingestellte Land. Jede angemeldete Rolle außer Gerät darf
lesen. Bereich höchstens 400 Tage, sonst `400`.

### Änderungen an `appointments`

- **GET** liefert zusätzlich `series_id` und `is_detached`.
- **PUT** auf einen Termin mit `series_id` setzt `is_detached = 1` (mit der ersten tatsächlichen
  Änderung; ein PUT ohne Felder ändert nichts).
- **DELETE** eines Termins mit `series_id` trägt vorher dessen Datum in `exdates` der Serie ein.
  Hat die Serie danach keinen Termin mehr, bleibt die Serienzeile trotzdem stehen — sie wird nur
  von `appointment_series` DELETE entfernt. (Grund: Ein versehentliches Löschen des letzten
  Termins soll „Serie fortsetzen" nicht unmöglich machen.)

### Registrierung

`api.php` (`require_once` + `case`), `demo_mode.php`: `appointment_series` mit `POST`, `PUT`,
`DELETE` in `DEMO_WRITE_ALLOWED` wie `appointments`; `holidays` in die Liste der reinen
Leseressourcen. `API.md`: je ein Abschnitt, `appointments` um die zwei Felder ergänzt.

## Teil 4 — Oberfläche

### Kalender (`appointments.js`)

- **Leerer Tag** (nur Admin und Manager): Klick → `openAppointmentModal(null, { date })` mit
  vorbelegtem Datum. Beim Überfahren Zeiger und dezentes „+". Einfache Nutzer: unverändert.
- **Tag mit Terminen**: `showAppointmentPopup()` bekommt für Admin und Manager je Termin
  „Bearbeiten" (öffnet den Dialog) und unten „+ Termin an diesem Tag".
- **Feiertage**: Name klein im Tagesfeld, Klasse `calendar-day--holiday`; Farbe als neue
  Variable in `variables.css`. Laden über `holidays` je Jahr, Ablage in `dataCache.holidays[year]`
  (TTL wie die übrigen Schlüssel). Nach Änderung der Einstellung `holiday_region` wird der
  Schlüssel invalidiert.

### Termin-Dialog — Anlegen

Bereich „Wiederholen" unter den Terminfeldern, nur im Anlegen-Modus, zugeklappt hinter einem
Häkchen:
- Muster: wöchentlich / monatlich
- Wöchentlich: „alle [1–4] Wochen", Wochentage als Auswahlchips; der Tag des gewählten Datums
  vorausgewählt
- Monatlich: Position (1., 2., 3., 4., letzter) und Wochentag, aus dem Datum vorbelegt
- „Bis": Pflicht, `max` = Datum + 12 Monate, Hinweis auf die Grenze

Ist „Wiederholen" aktiv, heißt der Speichern-Knopf „Vorschau". Er ruft `POST
appointment_series?preview=1` und zeigt darunter die Datumsliste (siehe `date_checklist.js`):
Feiertage mit Namen und abgewählt, Kollisionen gesperrt mit Titel und Uhrzeit des bestehenden
Termins, Zähler „N von M ausgewählt". Der Knopf wird zu „N Termine anlegen"; abgewählte Daten
gehen als `exdates` mit. Ändert sich danach ein Feld, verfällt die Vorschau.

### Termin-Dialog — Serientermin bearbeiten

- **Kasten oben**: „Teil der Serie: jeden Di · 19:30 · bis 27.07.2027" (Regel in Klartext aus
  `rrule`), Aktionen **„Serie fortsetzen …"** (Enddatum wählen → Vorschau → anlegen) und
  **„Regel ändern …"** (öffnet „Wiederholen" vorbelegt → Vorschau → Split ab diesem Termin).
- **Speichern**: Auswahl „Nur dieser" / „Dieser und alle folgenden". Ersteres → `PUT
  appointments` (setzt `is_detached`), Letzteres → `PUT appointment_series` mit `from_date` =
  Datum dieses Termins und den geänderten Feldern. Wird im Dialog das **Datum** geändert, gibt es
  keine Auswahl — ein verschobener Termin ist immer „nur dieser".
- **Löschen**: dieselbe Auswahl. „Dieser und alle folgenden" → `DELETE appointment_series?from=`.
- **Abgelöster Termin**: Kasten „Von der Serie abgelöst", keine Auswahl, keine Serienaktionen;
  er verhält sich wie ein Einzeltermin.
- Nach jeder Serienaktion eine Meldung mit geänderten, übersprungenen und abgelösten Terminen.

### Terminliste

Serientermine tragen ein Symbol neben dem Titel (`title`-Attribut „Teil einer Serie";
abgelöste: „Aus einer Serie, einzeln geändert").

### Gemeinsames Bauteil `public/js/modules/date_checklist.js` (neu)

`renderDateChecklist(container, items, options)` mit `items = [{date, label?, note?, checked,
disabled}]`; liefert ein Objekt mit `getSelected()` und `getDeselected()` und aktualisiert den
Zähler selbst. Daten werden ausschließlich über Objekte gehalten, nicht über `data-`-Attribute
mit JSON. Die Import-Vorschau (`displaySuggestions()` / `createSelectedAppointments()` in
`import_export.js`) stellt auf das Bauteil um; ihr Anlegeverhalten (einzelne POSTs) bleibt in
diesem Vorhaben unverändert.

### Cache

Serienaktionen invalidieren `appointments` für **alle** Jahre zwischen `start_date` (bzw.
`from_date`) und `until` — eine Serie kann über den Jahreswechsel reichen.

### Einstellungen

Im Untertab der Termineinstellungen: Auswahl „Feiertage nach Bundesland" (leer = „nur
bundesweit", danach die 16 Länder mit Namen) samt dem Hinweis auf die nicht erzeugten Fälle.

### Unberührt

PWA, Station, Statistik, Pünktlichkeit, Druckberichte, Rückmeldungen: Serientermine sind
gewöhnliche Termine.

## Prüfung

| Suite | Inhalt |
|---|---|
| `recurrence_unit` (neu) | wöchentlich mit Abstand 1–4 und mehreren Tagen; monatlich 1.–4. und letzter; Monate mit vier und fünf Vorkommen; Schaltjahr; Zeitraum über die Zeitumstellung; `exdates`; 12-Monats-Grenze; abgelehnte Regeln (`UNTIL`, `COUNT`, `INTERVAL=5`, `BYDAY=5TU`, leeres `BYDAY`); `parseRrule(buildRrule())` ist verlustfrei |
| `holidays_unit` (neu) | Ostersonntag 2024–2030 gegen bekannte Daten; bundesweite Liste; Stichproben: Fronleichnam BW, Reformationstag SN und NI (vor/ab 2018), Frauentag BE (vor/ab 2019) und MV (vor/ab 2023), Weltkindertag TH, Buß- und Bettag SN (2026: 18.11.); ohne Land nur bundesweit; unbekanntes Kürzel wie leer |
| `appointment_series_api` (neu) | Vorschau schreibt nichts; Anlegen erzeugt N Termine mit `series_id`; Kollision übersprungen und in `exdates`; `exdates` werden nicht erzeugt; PUT „folgende" aktualisiert an Ort und Stelle und behält IDs, lässt abgelöste aus; Split löscht folgende ohne Daten, löst solche mit Daten ab, neue Serie ab `from_date`; extend erzeugt nur den neuen Bereich; DELETE ab Datum; letzte Termin weg → Serienzeile weg; Einzel-PUT setzt `is_detached`; Einzel-DELETE füllt `exdates`; Validierungsfälle (400); unbekannte `id` (404); einfacher Nutzer (403); `holidays` für Nutzer lesbar, für Gerät nicht |
| `appointments` bestehend (`partial_update_api`, `delete_id_api` u. a.) | weiterhin grün; 409-Antwort bei Dubletten unverändert |
| `appointment_series_frontend` (neu) | Quelltextprüfung wie in den bestehenden `*_frontend`-Suites: Klickhandler auf leerem Tag nur für Admin/Manager, Popup-Knöpfe, Bereich „Wiederholen", Auswahl „Nur dieser"/„folgende", Import nutzt `date_checklist.js` |
| `demo_mode` | beide neuen Ressourcen registriert (erzwingt die Suite ohnehin) |
| `migrations` | Kette bis 1.11.0; Migration auf bereits migrierter Datenbank folgenlos |

`docs/testplan.md` erhält einen Abschnitt für die Bedienung im Browser (Serie anlegen mit
Feiertag und Kollision, folgende ändern, Regel ändern, fortsetzen, ab hier beenden, Kalenderklick
leer/belegt als Admin und als Nutzer, Feiertagsanzeige nach Bundesland).

## Dokumentation und Auslieferung

- `version.json` → 1.11.0, `CHANGELOG.md`
- `API.md`: `appointment_series`, `holidays`, Felder an `appointments`
- `docs/OPEN-ITEMS.md`: OI-64 als erledigt
- `docs/FEATURE-IDEAS.md`: FI-7 und FI-16 (Feiertagsteil) als umgesetzt; Ferien verbleiben bei FI-18

## Nicht in diesem Vorhaben

- Ferien und ICS-Import (FI-18)
- ICS-Abo (FI-8) — profitiert aber: `rrule` plus `until` ergibt direkt `RRULE`
- `is_cancelled` / sichtbar abgesagte Termine; „erfasste Anwesenheit nie hart löschen" als
  allgemeine Regel für alle Termine wäre ein eigenes Thema
- Kollisionen schon in der Vorschau des Record-Imports
- Eigene Serienübersicht
- „Alle" einschließlich vergangener Termine
- Tägliche, jährliche und „jeden n-ten Tag im Monat"-Regeln
- Einen bestehenden Einzeltermin nachträglich in eine Serie umwandeln
- Demo-Generator auf Serien umstellen
