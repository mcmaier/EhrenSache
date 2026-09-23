# Design: Vom Kalender zur Anwesenheit, Anwesenheitsquote im Kalender

**Datum:** 2026-09-22
**Status:** Approved
**Zielversion:** nach dem Merge der Filter-Chips (`2026-09-22-filter-chips-design.md`), zwei Schritte
**Betrifft:** Dashboard — Terminverwaltung (`appointments.js`, Kalender und Terminliste),
Anwesenheit (`records.js`); Schritt 2 zusätzlich `private/handlers/appointments.php` (GET),
ein gemeinsamer Helfer für „erwartete Mitglieder“, `attendance_list.php`

## Problem

Im Kalender lässt sich ein Termin öffnen und bearbeiten (seit 1.11.0, OI-64). Wer wissen will,
**wer bei einem vergangenen Termin da war**, muss die Terminverwaltung verlassen, in die
Anwesenheit wechseln, das Jahr stellen und den Termin im Filter suchen. Einen Sprung zwischen
Bereichen des Dashboards gibt es bisher nirgends.

Außerdem zeigt der Kalender für vergangene Termine nichts über die Anwesenheit — nach vorn
zeigt er Rückmeldungen (FI-1), nach hinten ist er stumm.

## Entscheidungen

| Frage | Entscheidung | Verworfen |
|---|---|---|
| Ansatz | Ein Kalender, zwei Schritte: erst der Sprung, dann die Quote | Eigener Kalender im Anwesenheits-Tab (Dopplung) |
| Zweck | Ansehen **und** korrigieren — beides in der vorhandenen Anwesenheitsliste | Bearbeiten direkt im Kalender |
| Einstieg | Kalender-Popup und Aktionsspalte der Terminliste; nur begonnene/vergangene Termine; nur Admin/Manager | Nur Popup; auch künftige Termine |
| Rückweg | „← Zurück zu Termine“ in der Anwesenheit, zurück zum Kalendermonat des Termins | Nur über die Navigation |
| Sichtbarkeit der Quote | Admin/Manager: Zahlen je Termin; Nutzer: nur der eigene Status | Quote für alle; nur Verwalter |
| Darstellung im Tagesfeld | 3-px-Balken am unteren Rand, anteilig Anwesend/Entschuldigt/Fehlend, mehrere Termine summiert | Farbpunkt nach Schwelle; nur im Popup |
| Datenquelle der Quote | Zusatz `include=attendance` an `GET appointments`, eine Abfrage je Jahr | Je Termin `attendance_list` abrufen (N Anfragen) |
| „Erwartet“ | Dieselbe Regel wie Anwesenheitsliste und Statistik, als gemeinsamer Helfer | Eigene Näherung für den Kalender |

## Schritt 1 — Sprung zur Anwesenheitsliste (nur Oberfläche)

### Einstiegsfunktion in `records.js`

`export async function openAttendanceForAppointment(appointmentId, date)`:

1. Jahr aus `date` setzen (`setCurrentYear`, Jahresauswahl der Anwesenheit mitziehen).
2. Bereich `anwesenheit` anzeigen (dieselbe Funktion wie ein Klick in der Navigation,
   `showSection` in `ui.js`), Filteroptionen laden (`loadRecordFilters`).
3. Im Feld `#filterAppointment` den Termin wählen und denselben Weg gehen wie dessen
   `change`-Handler: Modus `ATTENDANCE_BY_APPOINTMENT`, Mitglied- und Terminartfilter sperren,
   `loadAttendanceList(appointmentId)`.
4. Rücksprungziel merken: `{ section: 'termine', date }`.

Die Funktion setzt nur den Terminfilter und dessen Modus. Sie ist unabhängig davon, wie die
Filterleiste nach dem Chip-Umbau aussieht; ein aktiver Status-Chip wird auf „Alle“ gestellt.

Steht der Termin nicht in `#filterAppointment` (anderes Jahr nicht geladen, Terminart gefiltert),
wird die Liste neu geladen, bevor gewählt wird. Findet er sich auch dann nicht, meldet ein Toast
„Termin nicht gefunden“ und die Ansicht bleibt in der Terminverwaltung.

### Einstiege in `appointments.js`

- **Kalender-Popup** (`showAppointmentPopup`, nur festgehaltenes Popup, nur `isAdminOrManager`):
  je Termin, dessen Beginn (`date` + `start_time`) in der Vergangenheit liegt, ein Knopf
  „Anwesenheit“ neben „Bearbeiten“. Er schließt das Popup und ruft die Einstiegsfunktion.
- **Terminliste** (`renderAppointments`, Aktionsspalte): dieselbe Bedingung, ein Symbolknopf
  (📋, `title`/`aria-label` „Anwesenheit anzeigen“) neben Bearbeiten/Löschen.

Beide Knöpfe tragen nur die ID; Datum und Titel kommen aus dem Cache, nie aus dem
`onclick`-String (Muster aus `deleteAppointment`).

**Vermerk zu OI-94 (23.09.2026):** Der Nachtrag zu OI-94 hält fest, dass „Bearbeiten“ im
festgehaltenen Popup zu nah an der Rückmeldezeile sitzt und als gelber Stift an den rechten
Rand wandern soll. Der Knopf „Anwesenheit“ wird jetzt daneben gesetzt, weil OI-94 niemandem
zugeteilt ist und auf „niedrig“ steht (abgestimmt mit der Release-Sitzung). Wird OI-94
umgesetzt, ziehen **beide** Knöpfe gemeinsam um.

### Rückweg

- Nach einem Sprung zeigt die Anwesenheit über der Liste „← Zurück zu Termine“.
- Klick: Bereich `termine`, Jahr wie gesprungen, und **zurück dorthin, wo der Sprung begann**
  (Entscheidung des Nutzers, 23.09.2026). Im Dashboard stehen Kalender und Liste untereinander
  im selben Bereich, es gibt keine Reiter — unterschieden wird daher:
  - Sprung aus dem Kalender-Popup: Kalender auf den Monat des Termins (`currentCalendarDate`),
    Ansicht am Kalender.
  - Sprung aus der Terminliste: Kalendermonat **unverändert** lassen und zur Zeile des Termins
    in der Liste rollen; ist sie auf der aktuellen Seite der Paginierung nicht zu finden, genügt
    der Kopf der Liste.
  Dafür trägt das Rücksprungziel neben dem Datum die Herkunft: `{ date, from: 'calendar' | 'list' }`.
- Der Knopf verschwindet, sobald in der Anwesenheit ein Filter geändert oder der Bereich über
  die Navigation verlassen wird (Rücksprungziel verwerfen).

## Schritt 2 — Anwesenheitsquote im Kalender

### Gemeinsamer Helfer „erwartete Mitglieder“

Die Regel, wer zu einem Termin erwartet wird, steht heute in `attendance_list.php` (Gruppen der
Terminart über `appointment_type_groups` × `member_group_assignments`, Aktivzeitraum über
`getMemberActivityWhere` in `member_activity.php`) und — in eigener Form — in der Statistik
(`private/helpers/attendance.php`). Sie wird als Helfer herausgezogen, etwa
`private/helpers/appointment_attendance.php`:

- `attendanceCountsForAppointments(PDO $db, string $prefix, int $year, ?int $memberId): array` —
  liefert je begonnenem Termin des Jahres `{expected, present, excused}`; mit `$memberId` nur den
  eigenen Status. Eine gruppierte Abfrage für das ganze Jahr, keine Schleife je Termin.
- `attendance_list.php` nutzt für seine Zählung denselben Helfer bzw. dieselbe WHERE-Bildung, damit
  Kalender und Liste nie verschiedene Zahlen zeigen.
- **Pflicht beim Umbau:** `attendance_list?appointment_id` liefert je Mitglied weiter
  `pending_exceptions` (offene Anträge, seit 1.12.0, `25150f7`) — die PWA-Liste und OI-87 im
  Dashboard bauen darauf. Der Test „attendance_list traegt offene Antraege je Mitglied“ in
  `tests/suites/responses_api.php` muss grün bleiben.
- **Reihenfolge mit OI-87** (abgestimmt mit der Sitzung „Testfunde und Priorisierung“, 22.09.):
  Filter-Chips, dann OI-87, dann Schritt 1, dann Schritt 2 — damit der Umbau auf dem Stand mit
  offenen Anträgen im Dashboard aufsetzt. Schritt 1 setzt auf OI-87 auf (getrennte Funktionen in
  `records.js`, gemeinsam nur die Imports).

Status aus `records.status`: `present` = anwesend, `excused` = entschuldigt (genehmigte
Abwesenheit). `missing = expected − present − excused`, nie negativ (Records von nicht mehr
erwarteten Mitgliedern zählen nicht mit).

### API

`GET appointments?year=…&include=attendance` (nur Listenabruf; Einzelabruf unverändert):

- **Admin/Manager:** Jeder Termin, dessen Beginn vergangen ist, trägt
  `attendance: {expected, present, excused, missing}`. Künftige Termine: `attendance: null`.
- **Nutzer:** stattdessen `own_attendance: 'present' | 'excused' | 'missing' | null` —
  `null`, wenn der Termin nicht begonnen hat oder das Mitglied nicht erwartet war. Keine
  Zählungen über andere.
- Ohne `include=attendance` bleibt die Antwort unverändert (keine Mehrkosten für andere Aufrufer).
- `API.md` wird ergänzt.

### Stand der Umsetzung — Schritt 2a ist fertig (23.09.2026)

Der Server ist gebaut; was oben unter „Gemeinsamer Helfer“ und „API“ steht, war die Planung.
Abweichungen, die für Schritt 2b gelten:

- Der Helfer heißt `private/helpers/appointment_attendance.php` und bietet
  `attendanceAttachSummaries()` (hängt die Zahlen an eine bereits geladene Terminliste),
  dazu `attendanceExpectedMemberIds()`, `attendanceCounts()`, `attendanceStatusOf()` und
  `attendanceHasStarted()`. Ein `attendanceCountsForAppointments(… int $year …)` gibt es nicht —
  gezählt wird über die Termine, die der Abruf ohnehin geladen hat.
- **`attendance_list.php` wurde bewusst nicht umgebaut.** Die Spec sah vor, dass es denselben
  Helfer nutzt; das hätte `pending_exceptions` und (seit OI-87) `self_approval_blocked` angefasst,
  auf denen PWA und Dashboard aufbauen. Stattdessen nutzt `responses.php` den gemeinsamen Helfer,
  und ein Test in `calendar_attendance_api.php` vergleicht die Zahlen beider Wege über alle
  Termine — sie dürfen nie auseinanderlaufen.
- Der Zusatz `include=attendance` wirkt nur mit Zeitraum (`year` oder `from_date`/`to_date`);
  ohne Zeitraum wird er stillschweigend ignoriert, der Einzelabruf (`?id=`) kennt ihn nicht.
- Vorgemerkt: `attendanceHasStarted()` vergleicht gegen die PHP-Uhr, während `stationNow()`
  (OI-60) die Datenbankuhr vorschreibt; der Parameter `?string $now` ist vorbereitet. Vor der
  Anzeige im Kalender einmal das Lastverhalten mit großem Bestand messen — gezählt wird über
  alle begonnenen Termine des Zeitraums, nicht nur über die mit Rückmeldung.

### Kalender

- **Tagesfeld** vergangener Tage mit Terminen:
  - Admin/Manager: Balken 3 px am unteren Rand, volle Breite, Segmente anteilig
    Anwesend (`--success-color`), Entschuldigt (`--warning-color`), Fehlend (`--danger-color`);
    mehrere Termine am Tag werden summiert. Der Feiertagsname rückt darüber.
  - Nutzer: ein Punkt unten links in der Farbe des eigenen Status (bei mehreren Terminen am Tag:
    der schlechteste Status).
  - Kein Balken, wenn `expected = 0`.
- **Popup** je Termin eine Zeile: Admin/Manager „Anwesend 18 · Entschuldigt 4 · Fehlend 3“
  plus Knopf „Anwesenheit“ (Schritt 1); Nutzer „Du warst anwesend / entschuldigt / nicht da“.
- Aria-Label des Tagesfelds nennt die Zahlen bzw. den eigenen Status.
- Die Zahlen kommen mit dem Terminabruf des Jahres (`dataCache.appointments[year]`); kein eigener
  Cache. Nach einer Änderung in der Anwesenheit wird der Terminabruf des Jahres invalidiert.

## Nicht in diesem Vorhaben

- Anwesenheit im Kalender bearbeiten (nur über den Sprung).
- Ein zweiter Kalender im Anwesenheits-Tab.
- Check-in-PWA und Station.
- Sprünge zwischen anderen Bereichen (Mitglied → Statistik usw.) — die Einstiegsfunktion ist kein
  allgemeiner Router.

## Prüfung

| Suite | Inhalt |
|---|---|
| API (neu oder in `appointments_visibility_api`) | `include=attendance`: Zahlen für Admin stimmen mit `attendance_list` desselben Termins überein (anwesend, entschuldigt, erwartet); künftige Termine `null`; Nutzer bekommt nur `own_attendance`, keine `attendance`; ohne `include` unverändert |
| Unit/API für den Helfer | Aktivzeitraum (Mitglied erst ab Mitte des Jahres aktiv), Mitglied in zwei Gruppen einmal gezählt, `missing` nie negativ |
| Frontend statisch | Einstiegsfunktion exportiert; Knöpfe nur für vergangene Termine und Admin/Manager; Rückweg-Knopf; Balken und Nutzerpunkt; Farben über Variablen |
| Browser | Sprung aus Popup und Liste, Rückweg auf den richtigen Monat, Korrektur in der Liste wirkt nach Rücksprung im Balken; Nutzersicht ohne Zahlen |

`docs/testplan.md` bekommt einen Abschnitt; `CHANGELOG.md` je Schritt einen Eintrag.

## Reihenfolge

1. Merge der Filter-Chips abwarten (`records.js`, `appointments.js` sind beidseitig betroffen).
2. Schritt 1 als eigener Plan, eigenes Release möglich.
3. Schritt 2 als eigener Plan; der Helfer zuerst, mit Abgleich gegen `attendance_list` und die
   Statistik, bevor der Kalender ihn anzeigt.
