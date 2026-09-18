# Design: Kommende Termine in der PWA, mit Ort und Ende (FI-23)

**Datum:** 2026-09-18
**Status:** Approved
**Zielversion:** 1.10.0
**Betrifft:** Schema `appointments`, `private/handlers/appointments.php`,
`private/handlers/appointment_responses.php`, `private/helpers/responses.php`, Dashboard
(`appointments.js`), Check-in-PWA (`checkin/js/app.js`), CSV-Export und -Import, Demo-Daten

## Problem

**Der Tab „Termine" der Check-in-PWA zeigt keine Termine, sondern Rückmeldungsanfragen.**
`responsesFetchUpcomingIds()` (`helpers/responses.php`) verlangt `t.responses_enabled = 1`, und
`loadResponses()` blendet den Tab ganz aus, wenn die Liste leer ist. Ein Verein, der die
Terminrückmeldung nicht nutzt, hat in der PWA damit keine Terminübersicht. Wer wissen will,
wann die nächste Probe ist, muss ins Dashboard.

**Ein Termin kennt weder Ort noch Ende.** `{PREFIX}appointments` trägt Titel, Beschreibung,
Datum und Startzeit. Wo ein Auftritt stattfindet, steht bestenfalls im Fließtext. Das ist FI-23
aus `docs/FEATURE-IDEAS.md` — und genau die Information, die eine Terminliste in der PWA zeigen
sollte.

Beides gehört zusammen: Die Liste ohne Ort ist halb so nützlich, die Felder ohne Liste fast
unsichtbar.

## Entscheidungen

| Frage | Entscheidung | Begründung |
|---|---|---|
| Ort speichern | Freitextspalte `location`, Vorschläge aus bisher verwendeten Orten | Keine Stammdatenpflege nötig; FI-3 (GPS) kann eine Ortstabelle später aus den Werten ableiten |
| Ende erfassen | Uhrzeit `end_time`; vor dem Beginn = Folgetag | Liest sich wie ein Kalender, entspricht ICS `DTEND` für FI-8 |
| Ende auswerten | **nein** — rein informativ | Festlegung aus FI-23; hält Statistik, Pünktlichkeit und Berichte unberührt |
| PWA-Terminanlage | bekommt beide Felder, optional | Beide Anlagewege bleiben gleichwertig |
| Umfang der Liste | ohne Rückmeldung: heute bis +8 Wochen; mit Rückmeldung: alle kommenden (wie bisher, höchstens 50) | „Was steht demnächst an" ist eine Zeitfrage; eine offene Rückmeldung darf nicht aus dem Fenster fallen |
| Jahreswechsel | Datumsbereich statt Jahresfilter | Am 20.12. reicht das Fenster in den Februar — Januartermine erscheinen von selbst |
| Anordnung | **chronologisch**, Monatsüberschriften, Karten anfangs zugeklappt | siehe unten |
| Sichtbarkeit am Tag selbst | bis Tagesende | Auf dem Weg zum Konzert will man den Ort noch nachsehen |
| Datenquelle | `appointment_responses?upcoming=1` erweitern, mit Schalter `with_info=1` | Sichtbarkeitsregel bleibt an einer Stelle; siehe „Verworfen" |

**Warum chronologisch und nicht „Rückmeldung offen" zuerst.** Die heutige Liste stellt
unbeantwortete Termine nach oben. Mit der neuen Liste würde das kippen: Lange vorausgeplante
Veranstaltungen, für die noch gar keine Zusage möglich ist, stünden dauerhaft oben und
verdrängten die Proben dieser Woche. Zugeklappte Karten halten die chronologische Liste trotzdem
kurz.

**Verworfen:**

- *Zwei Abschnitte („Rückmeldung offen" / „Demnächst")* — aus dem oben genannten Grund.
- *Agenda mit Rückmeldung nur per Aufklappen und eigener Wochengliederung* — als Ganzes
  verworfen, Zuklappen und Gliederung daraus übernommen (Monate statt Wochen).
- *Ortstabelle als Stammdaten* — eine Verwaltungsseite, die ein Verein pflegen müsste, bevor er
  einen Ort eintragen kann.
- *Dauer statt Endzeit* — jede Anzeige und der ICS-Feed müssten das Ende erst ausrechnen.
- *Datenquelle `appointments?from_date=…`* — zwei Abrufe, Admins bekämen dort alle Termine
  statt der eigenen, und die aktive Mitgliedschaft prüft dieser Endpunkt nicht.
- *Neue Ressource `upcoming_appointments`* — dieselbe Logik ein zweites Mal, plus Demo-Modus,
  API-Abschnitt und Tests.

## Teil 1 — Ort und Ende am Termin (FI-23)

### Schema

Migration `private/migrations/1.9.3.php` (1.9.3 → 1.10.0), idempotent:

```sql
ALTER TABLE {PREFIX}appointments ADD COLUMN location VARCHAR(200) NULL AFTER description;
ALTER TABLE {PREFIX}appointments ADD COLUMN end_time TIME NULL AFTER start_time;
```

Je Spalte vorher über `information_schema.COLUMNS` prüfen, ob sie schon existiert. Nachziehen in
`private/setup/ehrensache_db.sql`. Kein Index — gesucht wird nach Ort nicht, gruppiert nur für
die Vorschlagsliste über eine kleine Menge.

Bestandstermine und automatisch erzeugte Termine bleiben ohne Ort und Ende. Der leere Fall ist
der Normalfall und muss überall sauber aussehen.

### API — `appointments.php`

**POST und PUT** nehmen `location` und `end_time` an; beide stehen in `$allowedFields`. PUT
bleibt ein Teil-Update: Ein fehlender Schlüssel lässt den Wert unberührt, ein expliziter
`null`- oder Leerwert löscht ihn.

| Feld | Regel | Fehler |
|---|---|---|
| `location` | getrimmt; leer → `NULL`; höchstens 200 Zeichen | `400` bei mehr als 200 |
| `end_time` | `HH:MM` oder `HH:MM:SS`; leer → `NULL`; vor dem Beginn = Folgetag | `400` bei ungültigem Format; `400`, wenn es **gleich** dem Beginn ist |

Die Prüfung „gleich dem Beginn" vergleicht mit dem **wirksamen** Beginn: bei PUT ohne
`start_time` mit dem gespeicherten. Eine Dauer von null Minuten ist fast immer ein Tippfehler.

Dublettenprüfung und Reimport-Erkennung (Datum, Uhrzeit, Terminart) bleiben unberührt — weder
Ort noch Ende fließen ein.

**Neuer Parameter `GET appointments?locations=1`** — nur für Admin und Manager, sonst `403`:

```sql
SELECT location, COUNT(*) AS n
FROM {PREFIX}appointments
WHERE location IS NOT NULL
  AND date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
GROUP BY location
ORDER BY n DESC, location
LIMIT 50
```

Antwort: ein Array von Zeichenketten. Vom Server, weil der Dashboard-Cache nur das laufende Jahr
hält — im Januar wäre eine clientseitige Liste leer. Der Parameter wird vor dem Listenzweig
ausgewertet und kehrt sofort zurück.

Der Listenabruf liefert die neuen Spalten über `SELECT a.*` ohne weiteres Zutun.

### Eingabe

**Dashboard-Dialog** (`index.html`, `#appointmentModal`; `appointments.js`):

- „Ende" (`type="time"`, optional) neben „Startzeit"
- „Ort" (`type="text"`, `maxlength="200"`, optional) mit `list="appointmentLocations"` und einem
  `<datalist id="appointmentLocations">`, befüllt beim Öffnen des Dialogs aus
  `appointments?locations=1`
- `loadAppointmentData()` und `saveAppointment()` lesen und schreiben beide Felder

**PWA-Terminanlage** (`checkin/index.html`, `#appointmentModal`; `submitAppointmentForm()`):
dieselben zwei Felder, dieselbe Vorschlagsliste. Beim Bearbeiten werden sie aus dem geladenen
Termin vorbelegt.

### Anzeige im Dashboard

- **Terminliste** (`renderAppointments()`): Zeitzeile „22.09.2026, 19:30–22:00"; ohne Ende wie
  bisher „22.09.2026, 19:30". Darunter „📍 Stadthalle", wenn ein Ort gesetzt ist. Ort per
  `escapeHtml()`.
- **Kalender-Popup** (`showAppointmentPopup()`): dieselbe Zeitangabe, Ort als eigene Zeile.

Ein Ende vor dem Beginn wird ohne Zusatz angezeigt („20:00–01:00") — aus den Zahlen ist der
Folgetag erkennbar.

**Nicht** in Auswahllisten (Check-in-Terminwahl, Anträge, Arbeitszeit, Anwesenheitsfilter):
Dort wird ausgewählt, nicht gelesen.

### CSV

- **Export** (`export.php`, Termine): `end_time` und `location` als **letzte** Spalten, damit
  bestehende Auswertungen, die nach Position lesen, nicht verrutschen. Beide Werte laufen durch
  die Formelentschärfung wie alle Freitexte (OI-59).
- **Import** (`import.php`): Beide Spalten optional. Sind sie im Kopf vorhanden, gelten dieselben
  Regeln wie in der API; ein ungültiger Wert ist ein Zeilenfehler, keine Abbruchursache. Ältere
  Dateien ohne die Spalten laufen unverändert.

## Teil 2 — Terminliste in der PWA

### Server — `appointment_responses?upcoming=1`

**Neuer Schalter `with_info=1`.** Nur mit ihm enthält die Antwort Termine ohne Rückmeldung. Ein
PWA-Tab, der vor dem Update geöffnet wurde, hält alten Code im Speicher, schickt den Schalter
nicht und bekommt weiter nur Rückmeldetermine — sonst stellte er Infotermine als Karten mit
Knöpfen dar, deren Druck ins Leere liefe.

**Neuer Helfer `responsesFetchUpcomingInfoIds()`** in `helpers/responses.php`, neben
`responsesFetchUpcomingIds()` und nach demselben Muster: dieselben Joins über
`appointment_type_groups`, `member_group_assignments` und `getMemberActivityWhere()`, aber

- `COALESCE(t.responses_enabled, 0) = 0`
- `a.date BETWEEN DATE(:now) AND DATE(:now) + INTERVAL 56 DAY`

Das Fenster steht als Konstante `UPCOMING_INFO_DAYS = 56` in `helpers/responses.php`.

**Sichtbarkeit bis Tagesende.** `responsesFetchUpcomingIds()` filtert heute
`CONCAT(a.date, ' ', a.start_time) > :now` — ein Termin verschwindet mit seinem Beginn. Künftig
genügt `a.date >= DATE(:now)`. Begonnene Rückmeldetermine tragen im Payload bereits
`started: true`; der Server nimmt nach Beginn keine eigene Rückmeldung an
(`appointment_responses.php`, PUT) und bleibt dabei.

**Payload:**

- Rückmeldeeinträge: wie bisher `responsesPayload()`, das Objekt `appointment` zusätzlich mit
  `end_time`, `location`, `description` und `responses_enabled: true`.
  `responsesFetchAppointment()` liest die drei Spalten mit.
- Infoeinträge: schlank, ohne Zählung und Namen:

```json
{
  "appointment": {
    "appointment_id": 42, "title": "Gesamtprobe", "date": "2026-09-22",
    "start_time": "19:30:00", "end_time": "22:00:00", "location": "Probelokal",
    "description": null, "type_id": 1, "type_name": "Gesamtprobe", "color": "#667eea",
    "responses_enabled": false
  }
}
```

Die Antwort ist nach Datum und Beginn sortiert. Auch Admin und Manager bekommen weiter die
Sicht des eigenen Mitglieds.

### PWA — `checkin/js/app.js`

- `loadResponses()` fragt mit `{ upcoming: 1, with_info: 1 }`.
- **Sortierung:** chronologisch nach Datum und Beginn. Die bisherige Regel „unbeantwortete
  zuerst" entfällt.
- **Monatsüberschriften** vor dem ersten Termin jedes Monats; außerhalb des laufenden Jahres mit
  Jahreszahl („Mai 2027").
- **Zugeklappte Karte:** Titel; Tag, Beginn–Ende (Jahr nur außerhalb des laufenden Jahres); Ort,
  wenn gesetzt; bei Rückmeldeterminen rechts ein Chip mit dem Stand — „Rückmeldung offen",
  „zugesagt", „unsicher", „abgesagt", „Frist abgelaufen", „hat begonnen". Es gilt der erste
  zutreffende: eigene Antwort vorhanden → deren Stand; sonst begonnen → „hat begonnen"; sonst
  Frist abgelaufen → „Frist abgelaufen"; sonst „Rückmeldung offen". Wer zugesagt hat, sieht das
  also auch, während der Termin läuft. Der orange Ring (`.is-open`) bleibt: offen, Frist nicht
  abgelaufen, nicht begonnen.
- **Aufklappbar** nur, wenn es etwas zu zeigen gibt: Rückmeldetermin, oder Infotermin mit
  Beschreibung. Sonst kein Pfeil.
- **Aufgeklappt:** Terminart, Beschreibung; bei Rückmeldeterminen darunter der bisherige Inhalt
  der Karte (Knöpfe, Frist, Zählung, Bemerkung, „Wer hat geantwortet?"). Begonnene Termine zeigen
  keine Knöpfe.
- **Aufklappzustand** in einem Set `responsesExpanded`, nach dem Muster von
  `responsesOpenComments`: übersteht jeden Neuaufbau durch `renderResponses()`, wird in
  `resetResponsesTab()` geleert. Anfangs ist alles zugeklappt.
- **Badge:** zählt Rückmeldetermine ohne eigene Antwort, die **noch nicht begonnen** haben.
- **Tab** wird nur noch ausgeblendet, wenn die Liste ganz leer ist. Leerer Zustand: „Keine
  Termine in den nächsten acht Wochen."

Beschreibung und Ort sind Freitext aus der Datenbank und laufen durch `escapeHtml()`; Farben
weiter nur per Hex-Whitelist.

## Prüfung

| Suite | prüft |
|---|---|
| `migrations` (vorhanden) | Kette bis 1.10.0; neue Migration auf einer Datenbank mit bereits vorhandenen Spalten folgenlos |
| **neu** `appointment_details_api.php` | Anlegen mit Ort und Ende; Teil-Update ohne die Felder lässt sie stehen; `null` löscht; `400` bei Ende gleich Beginn (auch per PUT gegen gespeicherten Beginn), bei ungültigem Ende und bei Ort über 200 Zeichen; `locations=1` liefert eingetragenen Ort für Admin, `403` für `user` |
| `responses_api.php` (erweitern) | mit `with_info=1`: Infotermin in 3 Wochen ja, in 10 Wochen nein; Rückmeldetermin in 10 Wochen ja; ohne `with_info` keine Infotermine; Infotermin einer fremden Gruppe nie; heutiger, bereits begonnener Termin enthalten mit `started: true` |
| `export_import.php` (erweitern) | Export enthält `end_time` und `location` als letzte Spalten; Import mit den Spalten übernimmt sie; Import einer Datei ohne sie läuft |
| **neu** `pwa_termine_frontend.php` | statisch: `loadResponses()` schickt `with_info`; Badge-Filter berücksichtigt `started`; die Tab-Ausblendung hängt an der Gesamtliste |

**Sichtprüfung** in PWA und Dashboard, als `user` und als Admin: Termin über Mitternacht
(20:00–01:00), Termin ohne Ort und Ende, Rückmeldetermin in mehr als acht Wochen, ein heute
begonnener Termin, und ein Datum Mitte Dezember, damit das Fenster ins Folgejahr reicht.

## Dokumentation und Auslieferung

- `API.md`: Felder `location` und `end_time` bei Terminen samt Regeln; Parameter `locations=1`;
  Schalter `with_info=1` bei `appointment_responses`. Das bestehende Antwortbeispiel der
  Terminliste nennt `end_time` und `location` schon heute, obwohl es sie nicht gab — es wird an
  die tatsächliche Antwort angeglichen.
- `docs/testplan.md`, `CHANGELOG.md`, `private/setup/ehrensache_db.sql`.
- `docs/FEATURE-IDEAS.md`: FI-23 als umgesetzt in 1.10.0 markieren.
- **Demo-Daten** (`private/demo/plan.php`): Proben und Auftritte bekommen Ort und Ende, damit die
  Funktion auf dem Demo-Server sichtbar ist.
- Version **1.10.0**, Migrationsschritt `1.9.3.php`, `?v=` aller vier Einstiege, `SECURITY.md`
  („Aktuell veröffentlicht").

**Reihenfolge der Umsetzung:** erst Teil 1 vollständig und grün, dann Teil 2. Jeder Teil ist für
sich lauffähig.

## Nicht in diesem Vorhaben

- Auswertung des Endes (FI-23: rein informativ)
- Ort und Ende in Auswahllisten
- Ortstabelle und Koordinaten (FI-3)
- Kalender-Abo (FI-8) — profitiert aber unmittelbar von den Feldern
- Terminliste im Dashboard als eigene Ansicht für Mitglieder
