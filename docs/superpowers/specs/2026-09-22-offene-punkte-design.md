# Design: Offene Punkte unter „Mein Konto" (FI-17)

**Datum:** 2026-09-22
**Status:** Approved
**Zielversion:** nächste Minor-Version nach 1.12.1 (vergibt die Release-Sitzung)
**Betrifft:** neue Ressource `my_open_items` (`private/handlers/my_open_items.php`,
`private/helpers/open_items.php`), `public/api/api.php`, `private/helpers/demo_mode.php`,
Dashboard (`public/index.html`, `public/js/modules/profile.js`, neues Modul
`public/js/modules/open_items.js`), Check-in-PWA (`public/checkin/index.html`,
`public/checkin/js/app.js`), `API.md`

## Problem

EhrenSache ist eine Holschuld. Was das System gerade von einem Mitglied will — eine
Rückmeldung, deren Frist läuft, ein Antrag, über den noch nicht entschieden ist, eine
Arbeitszeit, die abgelehnt wurde —, steht verstreut in drei Bereichen. Wer nicht jeden
davon einzeln durchsieht, erfährt nichts.

FI-6 (Benachrichtigungen) würde das mit Versand lösen, hängt aber an einer Frage, die das
Projekt nicht entscheiden kann: ob die Installation eines Vereins einen Aufgabenplaner hat.
FI-17 löst denselben Bedarf als gebündelte Holschuld — ohne Versand, ohne Cron, ohne
Einwilligung — und liefert die Abfrage, auf der FI-6 später aufsetzt.

## Entscheidungen

| Frage | Entscheidung | Begründung |
|---|---|---|
| Ort | Dashboard **und** PWA | Mitglieder sind überwiegend in der PWA; das Dashboard landet nach der Anmeldung ohnehin auf „Mein Profil" |
| Inhalt | nur systemerzeugte Punkte, **kein** Hinweistext eines Admins | Ein adressierter Text wäre der Anfang eines Postfachs (FEATURE-IDEAS, „Nicht auf dieser Liste"); ein globaler Text kann später dazukommen |
| Abgelehnte Einträge | sichtbar **14 Tage ab Entscheidung** (`approved_at`) | Kein Schema, kein Schreibweg; ein „Gesehen"-Zustand machte aus der Leseansicht eine mit Zustand |
| Bedienung | Antippen springt an die zuständige Stelle | Alle Aktionen laufen über vorhandene, geprüfte Wege; keine dritte Kopie der Rückmeldelogik |
| Ort in der PWA | oben im Tab „Erfassen", einklappbar, ohne Punkte ausgeblendet | Start-Tab, den jeder sieht; das Erfassen rutscht nicht nach unten, wenn nichts offen ist |
| Datenbeschaffung | eigene Ressource `my_open_items`, serverseitig ermittelt | Eine Regelstelle statt zwei Kopien in zwei Clients; eine Anfrage statt vier; Grundlage für FI-6 |

`approved_at` wird von beiden Entscheidungswegen auch bei einer Ablehnung gesetzt
(`exceptions.php` beim Statuswechsel weg von `pending`, `work_sessions.php` bei
`approve`/`reject`); im Testbestand trägt jeder abgelehnte Eintrag den Zeitstempel.

## Server

### Helfer — `private/helpers/open_items.php`

```php
function openItemsForMember($db, $database, int $memberId, string $now): array
```

Liefert die Punkte eines Mitglieds, bereits sortiert, und die Zählung. Der Handler ist nur eine
Hülle darum; FI-6 ruft dieselbe Funktion später auf. `$now` wird hereingereicht, damit Tests die
Zeit festlegen können — wie bei `responsesFetchUpcomingIds()`.

Konstante `OPEN_ITEMS_REJECTED_DAYS = 14`.

### Welche Punkte

| `kind` | `state` | Bedingung | Quelle |
|---|---|---|---|
| `response` | `open` | Terminart mit `responses_enabled = 1`, Mitglied über seine Gruppen erwartet und im Zeitraum aktiv, Termin nicht begonnen, **Frist noch nicht abgelaufen**, keine eigene Antwort | `responsesFetchUpcomingIds()`, Frist über `responseDeadlineHours()` und `responseDeadline()`, Beginn über `responseHasStarted()` |
| `exception` | `pending` | eigener Antrag (`absence` oder `time_correction`), `status = 'pending'` | `exceptions` |
| `exception` | `rejected` | `status = 'rejected'`, `approved_at >= $now - 14 Tage` | `exceptions` |
| `work_session` | `pending` | `status = 'submitted'` **und** `end_time IS NOT NULL` | `work_sessions`, nur wenn `isWorktimeEnabled()` |
| `work_session` | `rejected` | `status = 'rejected'`, `approved_at >= $now - 14 Tage` | ebenso |

„Unsicher" (`maybe`) ist eine Antwort und macht eine Rückmeldung nicht offen. Nach Fristablauf
nimmt der Server eine Antwort noch als „kurzfristig" an, die Übersicht fordert aber nicht mehr
dazu auf — dieselbe Regel wie der Zähler am PWA-Tab „Termine" (`updateResponsesBadge()`,
seit 1.12.0). Laufende Arbeitszeiten sind keine offenen Punkte, sie werden noch erfasst.

### Ressource — `GET ?resource=my_open_items`

- Nur `GET`; jede andere Methode → `405`.
- Rolle `device` → `403`. Admin, Manager und User erhalten ausschließlich die Punkte **ihres
  eigenen** verknüpften Mitglieds (`$authMemberId`). Für Manager ist das keine Arbeitsliste.
- Ohne verknüpftes Mitglied → `200` mit `{"member": false, "items": [], "counts": {…0}}`. Die
  Oberflächen blenden dann alles aus.
- Demo-Modus: Eintrag in `DEMO_READ_ONLY` (`private/helpers/demo_mode.php`).

Antwort:

```json
{ "member": true,
  "items": [
    { "kind": "response", "state": "open", "appointment_id": 812,
      "title": "Gesamtprobe", "date": "2026-09-25", "start_time": "19:30:00",
      "deadline": "2026-09-24 19:30:00" },
    { "kind": "exception", "state": "rejected", "id": 57, "exception_type": "absence",
      "appointment_id": 790, "title": "Registerprobe", "date": "2026-09-18",
      "start_time": "19:00:00", "decided_at": "2026-09-19 08:12:00" },
    { "kind": "work_session", "state": "pending", "id": 1402,
      "activity_name": "Notenarchiv", "start_time": "2026-09-20 10:00:00",
      "duration_minutes": 135 } ],
  "counts": { "open": 1, "pending": 1, "rejected": 1 } }
```

Felder je Art:

- `response`: `appointment_id`, `title`, `date`, `start_time`, `deadline`
- `exception`: `id`, `exception_type`, `appointment_id`, `title`, `date`, `start_time`, bei
  `rejected` zusätzlich `decided_at`
- `work_session`: `id`, `activity_name`, `start_time`, `duration_minutes`, bei `rejected`
  zusätzlich `decided_at`

**Keine Freitexte:** Begründung, Ablehnungsgrund und Notiz sind nicht enthalten. Die Übersicht
verweist nur, gelesen wird an der Zielstelle.

**Sortierung:** `response` nach `deadline` aufsteigend, dann alle `pending` nach Datum
aufsteigend, dann alle `rejected` nach `decided_at` absteigend.

## Oberflächen

### Dashboard — Karte „Offene Punkte"

- Erste Karte in `#profil` (`public/index.html`), gefüllt über ein neues Modul
  `public/js/modules/open_items.js`; `loadProfile()` ruft es auf. Ein Aufruf von
  `my_open_items`.
- Je Punkt eine Zeile, z. B.:
  - „Gesamtprobe · Do 25.09. 19:30", darunter „Rückmeldung bis Mi 24.09. 19:30"
  - „Entschuldigung · Registerprobe 18.09." mit Chip „wartet" oder „abgelehnt"
  - „Arbeitszeit · Notenarchiv 20.09. · 2:15 h" mit Chip
- Chips über die vorhandenen Klassen `status-badge status-pending` / `status-rejected`; keine
  neuen Farben.
- Sprünge:
  - `response` → `openResponsesModal(appointment_id)`; die eigene Zeile ist dort ohne
    Entsperren bedienbar.
  - `exception` → Bereich `antraege`.
  - `work_session` → Bereich `zeiterfassung`.
- Nichts offen: Die Karte bleibt und sagt „Nichts offen ✓".
- `member: false`: Die Karte entfällt.

### PWA — Block oben im Tab „Erfassen"

- Oberhalb der Absichtswahl, als `<details>`.
- Ausgeblendet, wenn `items` leer ist.
- Kopfzeile mit Zählung, z. B. „2 offene Punkte · 1 abgelehnt".
- Beim Laden aufgeklappt nur, wenn `counts.open > 0`; sonst nur die Kopfzeile.
- Sprünge:
  - `response` → Tab „Termine", Karte mit passendem `data-appointment-id` aufklappen und
    hinscrollen.
  - `exception` und `work_session` → Tab „Verlauf".

### PWA — Verlauf zeigt abgelehnte Anträge

`loadHistory()` lädt Anträge heute nur mit `status=pending`; ein Sprung zu einem abgelehnten
Antrag liefe ins Leere. Der Verlauf lädt zusätzlich `status=rejected` und zeigt davon die
Einträge, deren `approved_at` höchstens 14 Tage zurückliegt, mit dem Chip „abgelehnt".
Abgelehnte Arbeitszeiten zeigt der Verlauf bereits.

### Neu laden

- beim Start bzw. nach der Anmeldung;
- nach eigenen Aktionen, die einen Punkt erledigen: Rückmeldung gesetzt oder zurückgezogen,
  Antrag gestellt oder zurückgezogen, Arbeitszeit beendet oder korrigiert;
- in der PWA zusätzlich bei `visibilitychange` zurück in den Vordergrund (Regel aus OI-67,
  Variante 1).

### Maskierung

Titel und Tätigkeitsnamen laufen durch `escapeHtml()`. Freitexte kommen in der Antwort nicht vor.

## Prüfung

- `tests/suites/open_items_api.php` — eigene Gruppe, Terminart und Termine im Jahr 2031 je
  Test, am Ende abgeräumt; Fristen relativ zu „jetzt", damit der Test zu jeder Tageszeit trägt
  (Lehre aus `5035a35`). Fälle:
  - offene Rückmeldung erscheint; mit abgelaufener Frist, mit eigener Antwort (auch „unsicher")
    und nach Beginn erscheint sie nicht;
  - Terminart ohne Rückmeldung erscheint nicht;
  - wartender Antrag erscheint; abgelehnter erscheint, nach 15 Tagen nicht mehr;
  - wartende beendete Arbeitszeit erscheint, laufende nicht; bei ausgeschalteter Arbeitszeit
    keine Arbeitszeitpunkte;
  - Benutzer ohne Mitglied → `200`, `member: false`; Gerät → `403`; `POST` → `405`;
  - Manager sieht nur die Punkte seines eigenen Mitglieds;
  - Sortierung und `counts`.
- `tests/suites/open_items_frontend.php` (statisch): Profil lädt `my_open_items`; PWA-Block
  ausgeblendet ohne Punkte; `escapeHtml` an Titel und Tätigkeit; Verlauf lädt `rejected`;
  `visibilitychange` lädt nach.
- `tests/suites/demo_mode.php` bleibt grün (Eintrag in `DEMO_READ_ONLY`).
- Im Browser über eine Prüfseite oder mit Token; wo eine Anmeldung mit Passwort nötig wäre,
  prüft der Nutzer.

## Dokumentation und Auslieferung

- `API.md`: Abschnitt `my_open_items` mit einer gegen die Testinstanz abgerufenen Antwort.
- `CHANGELOG.md`: `[Unreleased]` → „Neu".
- `docs/testplan.md`: Einträge für Karte, PWA-Block, Sprünge und Verlauf.
- `docs/FEATURE-IDEAS.md`: FI-17 als umgesetzt markieren; bei FI-6 vermerken, dass
  `openItemsForMember()` die Quelle ist.
- Keine Migration, keine Schemaänderung, kein neuer Konfigurationsschalter.

## Nicht in diesem Vorhaben

- Hinweistext eines Admins und Nachrichten an einzelne Mitglieder.
- Arbeitsliste für Manager mit fremden offenen Freigaben.
- „Gesehen"-Zustand für Ablehnungen.
- Versand per E-Mail oder Push (FI-6).
- Rückmeldung direkt aus der Übersicht.
- Umstellung des Zählers am PWA-Tab „Termine" auf `my_open_items`. Die Fristregel steht damit
  bewusst an zwei Stellen: im Server-Helfer und in `updateResponsesBadge()`.

## Risiken

- **Konflikte beim Merge** in `public/checkin/js/app.js` und `public/index.html`, falls parallel
  daran gearbeitet wird (angekündigt: Bedienkonzept für Filter). Eingriffe in `app.js` bleiben
  auf Block, Verlauf und Nachladepunkte beschränkt; vor dem Merge Abstimmung mit der
  Release-Sitzung.
- **Zwei Stellen für die Fristregel** (siehe oben). Laufen sie auseinander, zeigen Übersicht und
  Zähler verschiedene Zahlen.
