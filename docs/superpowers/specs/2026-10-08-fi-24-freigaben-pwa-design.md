# FI-24: Freigaben in der Check-in-App

**Stand:** 2026-10-08 · **Status:** entworfen, freigegeben · **Zweig:** `feat/fi-24-freigaben` ·
**Idee:** [FI-24](../../FEATURE-IDEAS.md#fi-24--freigaben-in-der-check-in-app)

## Ziel

Admin und Manager sehen in der Check-in-App **alle** offenen Anträge (Entschuldigungen und
Zeitanträge) und entscheiden sie — unabhängig davon, ob gerade ein Termin im Check-in-Fenster
liegt. Heute geht das in der App nur im Tab „Liste“ zum gerade gewählten Termin, und wählbar sind
nur Termine im Fenster (`checkin_tolerance_hours`).

## Ausgangslage

- `GET exceptions?status=pending` liefert Verwaltern schon alle offenen Anträge mit Mitglied
  (`name`, `surname`) und Termin (`appointment_title`, `appointment_date`,
  `appointment_start_time`, `appointment_type_name`). Die in FI-24 vermutete Lücke „ohne
  Terminbezug“ besteht nicht.
- Die Selbstgenehmigungsregel (OI-87) steckt in `otherActiveApproverExists()`
  (`private/helpers/utils.php`); die App bekommt sie heute nur über `attendance_list`
  (`self_approval_blocked`) und wendet sie in `attendanceRequestsHtml()` an.
- Entscheidung: `handleRequestDecision()` → `PUT exceptions&id=…` mit `status`. Ablehnen fragt
  nach; offline sind die Knöpfe gesperrt.
- `records` hat je Mitglied und Termin höchstens eine Zeile (`unique_member_appointment`).
- Der Tab „Liste“ ist für Verwalter mit eingeschalteter Anwesenheit immer sichtbar
  (`initAttendanceList()`); nur seine Terminauswahl ist auf das Fenster begrenzt.

## Entscheidungen

| Frage | Entscheidung |
|---|---|
| Umfang | Nur FI-24. [OI-39](../../OPEN-ITEMS.md#oi-39--freigaben-liegen-an-zwei-orten) (Dashboard, Anträge und Arbeitszeiten zusammen) bleibt ein eigenes Vorhaben |
| Antragsarten | Entschuldigungen und Zeitanträge. Arbeitszeiten bleiben im Dashboard (Entscheidung 2026-09-23) |
| Zeitraum | Alle offenen Anträge, geteilt in „Kommende Termine“ (chronologisch) und „Vergangene Termine“ (neueste zuerst) |
| Platz | Umschalter oben im Tab „Liste“: „Anwesenheit \| Offene Anträge (n)“ — kein sechster Tab |
| Server | Vorhandenen Abruf `GET exceptions` erweitern (Ansatz A), keine neue Ressource |

Verworfen: neue Ressource `pending_requests` (zweite Lesestelle für dieselben Daten, Pflege in
API.md und Demo-Listen); Abruf je Termin über `attendance_list` (viele Anfragen, nur für einzelne
Termine).

## Server: `GET exceptions` (Liste)

Für **Admin und Manager** trägt jede Zeile zusätzlich:

| Feld | Inhalt |
|---|---|
| `self_decision_blocked` | `true`, wenn der Antrag dem Mitglied des anfragenden Kontos gehört **und** `otherActiveApproverExists()` wahr ist; sonst `false`. Konto ohne Mitglied → immer `false` |
| `recorded_arrival_time` | `arrival_time` aus `records` für Mitglied und Termin des Antrags, sonst `null` |
| `recorded_status` | `status` aus `records` (`present`/`excused`), sonst `null` |

- Umsetzung per `LEFT JOIN records r ON r.member_id = e.member_id AND r.appointment_id =
  e.appointment_id`; `otherActiveApproverExists()` einmal je Abruf, nicht je Zeile.
- Für Mitglieder (Rolle `user`) bleibt die Antwort **unverändert** — die Felder fehlen.
- Bestehende Felder, Filter (`status`, `type`, `member_id`, `year`) und Sortierung bleiben.
  Die App sortiert selbst.
- Der Einzelabruf (`?id=`) bleibt unverändert.
- `API.md`: Abschnitt „Ausnahmen abrufen“ um die drei Felder ergänzen (nur Verwalter).

## Oberfläche: Tab „Liste“

### Umschalter

- Oben im Tab, über der Terminauswahl: zwei Knöpfe „Anwesenheit“ und „Offene Anträge (n)“,
  Gestaltung wie die vorhandenen Umschalter (`list-grouping`), `aria-pressed`.
- Die Zahl kommt aus demselben Abruf `exceptions?status=pending`, geladen beim Öffnen des Tabs
  und nach jeder Entscheidung. Ohne offene Anträge steht „Offene Anträge“ ohne Zahl.
- „Anwesenheit“ zeigt die bisherige Ansicht (Terminauswahl, Knöpfe, Gruppierung, Liste)
  unverändert. „Offene Anträge“ blendet diese aus und zeigt die neue Ansicht.
- Beim Öffnen des Tabs ist „Anwesenheit“ gewählt; die Wahl wird nicht dauerhaft gespeichert.

### Ansicht „Offene Anträge“

- Zwei Abschnitte, jeweils nur wenn befüllt: **Kommende Termine** (Termin heute oder später,
  nach Datum und Beginn aufsteigend) und **Vergangene Termine** (absteigend). „Heute“ zählt als
  kommend.
- Je Termin eine Kopfzeile: Datum, Beginn, Titel, Terminart. Darunter je Antrag: Name des
  Mitglieds und die Antragszeile.
- **Antragszeile** — dieselbe wie heute in der Liste, herausgelöst als gemeinsame Funktion:
  - zugeklappt nur die Art: „Entschuldigung“ bzw. „Zeitantrag 19:45 Uhr“ — die Begründung kann
    Gesundheitliches enthalten;
  - aufgeklappt die Begründung, bei Zeitanträgen zusätzlich „erfasst: 19:52 Uhr“,
    „entschuldigt“ oder „keine Erfassung“ (aus `recorded_*`);
  - Knöpfe „Genehmigen“ / „Ablehnen“; Ablehnen mit Rückfrage; offline gesperrt;
  - bei `self_decision_blocked` statt der Knöpfe der bekannte Hinweis „Eigener Antrag – bitte im
    Dashboard von jemand anderem entscheiden lassen.“
- Leer: „Keine offenen Anträge.“ Ladefehler: Hinweis über `showMessage`, Ansicht bleibt bedienbar.
- Alle Texte aus Serverdaten maskiert (`escapeHtml`); keine Inline-Handler (CSP der App).

### Entscheidung

- Weiter über `handleRequestDecision()`. Danach lädt die **gerade sichtbare** Ansicht neu
  (Liste wie bisher bzw. offene Anträge) und die Zahl am Umschalter wird aktualisiert.
- Die Liste nutzt künftig ebenfalls die gemeinsame Antragszeile; dort kommt das Flag weiter aus
  `attendance_list` (`self_approval_blocked`), das Verhalten bleibt gleich.

### Sichtbarkeit

- Nur für Verwalter mit eingeschalteter Anwesenheit — dieselbe Bedingung wie der Tab
  (`isPwaManager() && pwaFeatureOn('attendance')`). Ohne Anwesenheit gibt es keine Anträge
  (`exceptions` hängt am Schalter `attendance`).
- Manager sehen alle Anträge ohne Gruppengrenze (bestehende Regel; Gruppenleiter wäre FI-15).

## Nicht im Umfang

Arbeitszeitfreigaben, Dashboard-Änderungen (OI-39), Push oder Badge am Tab, Gruppengrenze für
Manager, dauerhafte Speicherung der Umschalterwahl.

## Tests

- **HTTP (`tests/suites/`, neue Suite oder Erweiterung einer exceptions-Suite):**
  - Verwalter erhält `self_decision_blocked`, `recorded_arrival_time`, `recorded_status`.
  - Eigener Antrag eines Verwalters: `true` bei zweitem aktivem Verwalter, `false` ohne
    (Testkonten direkt in der DB, Aufräumen in `finally`).
  - Zeitantrag mit und ohne Erfassung liefert die richtige Ankunft bzw. `null`.
  - Rolle `user`: Felder fehlen, sonst unverändert.
  - Mutationsprobe auf die Flag-Berechnung.
- **Statisch (`tests/suites/…_frontend.php`):** Umschalter im Markup, gemeinsame Antragszeile von
  beiden Ansichten genutzt, Abschnitte „Kommende/Vergangene Termine“, Maskierung, keine
  Inline-Handler; vorhandene Wächter (`csp`, `js_syntax`, `pwa_escaping_frontend`,
  `html_sinks_frontend`, `assets`) grün.
- **Browser:** Puppeteer-Durchgang (Umschalter, Abschnitte, Genehmigen, Zahl sinkt) und Sichtprüfung
  in der Browser-Pane, auch in 375 px Breite.

## Dokumentation

`API.md` (Zusatzfelder), `public/checkin/README.md` (Abschnitt Verwalter), `CHANGELOG.md` unter
`## [Unreleased]`, `docs/testplan.md` (neue Fälle), `docs/FEATURE-IDEAS.md` (FI-24 als umgesetzt,
unveröffentlicht), `docs/OPEN-ITEMS.md` (OI-39: Vermerk, dass FI-24 den Abruf erweitert hat und
OI-39 darauf aufsetzen kann). Kein Versionssprung, kein Migrationsschritt.

## Abstimmung

Eine parallele Sitzung arbeitet an OI-43 (Zweig `feat/oi-43-rahmen`, Service Worker der
Check-in-App). Beide berühren `public/checkin/`; vor dem Merge den Stand von `dev` holen und
Konflikte in `app.js`/`index.html` gezielt auflösen.
