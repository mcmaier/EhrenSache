# Statistik als Druckbericht, Berichte für die Rolle `user`

**Datum:** 2026-09-10
**Status:** entworfen
**Betrifft:** `private/helpers/report.php` (neu), `private/handlers/report_statistics.php` (neu),
`private/handlers/export.php`, `private/handlers/statistics.php`, `public/api/api.php`,
`public/js/modules/statistics.js`, `public/js/modules/worktime.js`, `public/index.html`

---

## 1 Ausgangslage

Seit 1.2.2 gibt es einen Druckbericht für die Arbeitszeit: `?resource=export&type=…&format=html`
liefert eine eigenständige HTML-Seite mit Vereinslogo, Kopfzeile, Tabelle, Summenblock und
Fußnoten. Gerendert wird sie von `renderWorktimeReport()` in `export.php`.

Zwei Lücken bleiben:

1. **Die Anwesenheitsstatistik hat keinen Bericht.** Sie existiert nur als Bildschirmansicht.
   Wer sie für eine Mitgliederversammlung oder einen Fördergeber braucht, druckt das Dashboard —
   mit Navigationsleiste, Filterkarten und Fortschrittsbalken.
2. **Berichte sind admin/manager vorbehalten.** `handleExport()` beginnt mit
   `requireAdminOrManager()`. Ein Mitglied kann seine eigenen Stunden und Anwesenheiten
   auf dem Bildschirm sehen, aber nicht als Nachweis ausdrucken — genau dann, wenn es einen
   braucht: Ehrenamtskarte, Bescheinigung, Nachweis gegenüber dem Arbeitgeber.

## 2 Ziel

- Die Anwesenheitsstatistik bekommt denselben Druckbericht wie die Arbeitszeit.
- Die Rolle `user` darf zwei Berichte über **sich selbst** erzeugen: den Stundennachweis
  und den Anwesenheitsbericht.

## 3 Entscheidungen

### 3.1 Der Statistikbericht bleibt jahresbasiert

Die Statistik rechnet durchgehend über `YEAR(a.date) = ?`; die Mitgliedschaftszeiträume kommen
über `getMemberActivityWhereYear()` dazu. Ein freier Zeitraum wie beim Arbeitszeitbericht würde
`calculateGroupStatistics()`, die Terminzählung und die Aktivitätsprüfung umbauen — ein Eingriff
in die Zahlen, die der Verein seit Jahren kennt, für einen Bedarf, den niemand geäußert hat.

**Der Bericht übernimmt die Filter der Bildschirmansicht: Jahr, Gruppe, Mitglied.** Damit können
Bericht und Bildschirm sich nicht widersprechen. Der freie Zeitraum wird als offener Punkt
notiert, nicht gebaut.

### 3.2 Ein Renderer für beide Berichte, in einem eigenen Helper

`renderWorktimeReport()` kann heute genau eine Haupttabelle und einen Summenblock. Die Statistik
ist nach Gruppen gegliedert; bei „Alle Gruppen" sind das mehrere Tabellen. Statt einer flachen
Tabelle mit Wiederholspalte „Gruppe" (auf Papier sperrig) oder eines Zwangs zur Einzelgruppe
(mehrfaches Drucken für die Jahresübersicht) wird der Renderer auf eine **Abschnittsliste**
gehoben.

Er zieht dabei nach `private/helpers/report.php` um — dorthin, wo `worktime.php` und `station.php`
bereits stehen. `export.php` hat 684 Zeilen; Statistikbericht samt Terminliste hätten es Richtung
950 gebracht, mit CSV-Erzeugung, HTML-Ausgabe und zwei Fachdomänen nebeneinander.

### 3.3 Die Statistik-Aggregation wird herausgelöst

`handleStatistics()` gibt sein Ergebnis **direkt als JSON aus**; die Summenbildung über alle
Gruppen — inklusive der Feinheiten „Termine nur einmal je Terminart zählen" und
„mögliche Anwesenheiten = Termine × Mitglieder" — liegt als Schleife mitten in der Funktion.

Ein Berichtshandler könnte sie nicht aufrufen, sondern müsste sie nachbauen. Dann rechnen
Bildschirm und Papier dasselbe an zwei Stellen, und die erste Änderung an einer davon lässt sie
auseinanderlaufen. Die Aggregation wird deshalb zu `buildStatisticsResult()` und von beiden
benutzt.

Das ist der einzige Eingriff in bestehende Rechenwege. Die SQL-Abfragen selbst bleiben unberührt.

### 3.4 Kein CSV für die Rolle `user`

Der Datenexport der eigenen Person ist über `?resource=my_data&format=csv` bereits gelöst
(Stammdaten, Gruppen, Anwesenheiten, Arbeitszeiten). Ein zweiter CSV-Pfad wäre eine Dublette mit
eigener Rechteprüfung. Das Backlog verlangt einen Bericht zum Ansehen und Drucken — genau das
bekommt die Rolle.

### 3.5 Kein CSV für den Statistikbericht

Anwesenheitsdaten gibt `?resource=export&type=records` bereits als CSV heraus. Der
Statistikbericht ist eine Papierform, keine zweite Datenschnittstelle.

### 3.6 Die Statistik bekommt keinen Berichtsdialog

Der Arbeitszeitbericht braucht einen Dialog, weil er Parameter kennt, die die Sektion nicht hat:
freier Zeitraum, Berichtsart. Die Statistik-Sektion trägt ihre Parameter bereits in der
Filterleiste — Jahr, Gruppe, Mitglied. Ein Dialog würde sie ein zweites Mal abfragen und die
Frage aufwerfen, welche der beiden Auswahlen gilt.

**Der Knopf öffnet den Bericht mit der aktuellen Filterauswahl.** Was auf dem Bildschirm steht,
kommt aus dem Drucker.

### 3.7 Ankunftszeiten werden nach Herkunft gekennzeichnet

Der Bericht druckt in der Terminliste eine Ankunftszeit. `records.arrival_time` ist aber nicht
durchgehend eine Messung:

| `checkin_source` | Woher die Zeit stammt | Messung? |
|---|---|---|
| `station_pin`, `device_auth`, `user_totp` | Serverzeit bei der Authentifizierung | ja, belastbar |
| `auto_checkin` | vom Client mitgeschickt (`auto_checkin.php`, `new DateTime($data->arrival_time)`) | ja, aber fremde Uhr |
| `admin` ohne mitgegebene Zeit | **die Startzeit des Termins** (`records.php`, Fallback-Kette) | nein — konstruiert pünktlich |
| `import` | aus der CSV | unbekannte Güte |
| `timer` | historisch `NOW()`; erzeugt seit 1.2.3 keine Records mehr | nein |

Wer eine Anwesenheitsliste abhakt, erzeugt damit eine Ankunftszeit, die exakt der Startzeit
entspricht. Diese Uhrzeit ununterschieden auf einen Nachweis zu drucken, behauptet eine Messung,
die nie stattgefunden hat. Der Bericht kennzeichnet die Herkunft deshalb — dasselbe Prinzip, mit
dem der Stundennachweis *stundenbelegt / teilbelegt / unbelegt* ausweist.

**Das ist keine Vorwegnahme der Pünktlichkeitsmetrik.** Die Kennzeichnung ist hier nötig, weil
der Bericht Uhrzeiten druckt. Die Metrik selbst — Pünktlichkeits- und Zuverlässigkeitsquote samt
Migration, Einstellung und Anzeige in der Statistik-Sektion — folgt als **eigene Spec** direkt im
Anschluss (Abschnitt 11).

---

## 4 Architektur

### 4.1 `private/helpers/report.php` (neu)

```php
reportEscape($value): string
renderReport(array $report): void   // gibt aus und beendet die Anfrage
```

Berichtsstruktur:

```php
[
  'title'    => string,              // z. B. 'Anwesenheitsbericht'
  'period'   => string,              // z. B. 'Jahr 2026'
  'sections' => [                    // mindestens einer
    [
      'heading' => ?string,          // null = ohne Überschrift (erster Abschnitt)
      'class'   => ?string,          // zusätzliche Tabellenklasse, z. B. 'report-summary'
      'columns' => array<int,string>,
      'rows'    => array<int,array<int,string>>,
      'empty'   => string,           // Text, wenn 'rows' leer ist
    ],
  ],
  'notes'    => array<int,string>,   // Fußnoten
]
```

`renderReport()` übernimmt unverändert aus `renderWorktimeReport()`: `<base href="../">`,
`css/print.css`, Vereinslogo und Organisationsname aus `getBrandingSettings()`, den
Demo-Hinweis über `demoModeActive()`, „Erstellt am", die Fußnotenliste und `exit()`.
Kein JavaScript, kein `window.print()` beim Laden — der Bericht soll vor dem Drucken gelesen
werden können.

**Maskierung:** Jeder Wert in `columns`, `rows`, `heading`, `title`, `period`, `empty` und
`notes` läuft durch `reportEscape()`. In die Berichte fließen freie Nutzereingaben aus Rollen
unterhalb von `admin` (Notizen und Ortsnamen aus der PWA, Termintitel). Ohne Maskierung wäre das
ein gespeichertes XSS in genau der Ansicht, die ein Administrator zum Prüfen öffnet. Das Projekt
hat keine CSP (OI-17); die Maskierung ist die einzige Verteidigungslinie.

### 4.2 `private/handlers/export.php` (geändert)

- `renderWorktimeReport()` entfällt. Die drei Arbeitszeitberichte bauen `sections` =
  [Haupttabelle] bzw. [Haupttabelle, Summentabelle mit `'class' => 'report-summary'` und
  `'heading' => 'Summen'`] und rufen `renderReport()`.
  **Die Ausgabe bleibt visuell identisch** — dieselben Klassen, dieselbe Reihenfolge.
- `exportEscape()` entfällt; alle Aufrufstellen wechseln auf `reportEscape()`. Kein Alias —
  zwei Namen für dieselbe Maskierung sind genau die Unschärfe, an der später der eine Aufruf
  vergessen wird.
- `handleExport()` bekommt die Signatur
  `($db, $database, $request_method, $authUserRole, $authMemberId)`; `api.php` reicht
  `$authMemberId` durch.
- `requireAdminOrManager()` am Anfang weicht einer typabhängigen Prüfung (Abschnitt 5).

### 4.3 `private/handlers/statistics.php` (geändert)

```php
buildStatisticsResult($db, $database, int $year, ?int $groupId, ?int $memberId,
                      ?int $appointmentTypeId, string $role, ?int $authMemberId): array
```

Enthält den heutigen Rumpf von `handleStatistics()` ab der Gruppenauflösung: Zugriffsprüfung
über `hasStatisticsGroupAccess()`, Gruppenliste über `getStatisticsGroups()`, die Schleife über
`calculateGroupStatistics()`, die Summenbildung und `getActiveMemberCount()`. Rückgabe ist das
Array, das heute `json_encode()` erreicht — Schlüssel `warning`, `year`, `worktime`, `summary`,
`statistics`.

`buildStatisticsResult()` erhält die **bereits aufgelöste** `memberId` und prüft sie nicht gegen
die Rolle. Das Erzwingen der eigenen Person bleibt Sache des jeweiligen Handlers — dort, wo auch
die `warning`-Regel und das Ausgabeformat entschieden werden. Die Funktion rechnet, sie
autorisiert nicht; `$role` und `$authMemberId` gehen nur in `hasStatisticsGroupAccess()` und
`getStatisticsGroups()` ein.

**Eine kleine, bewusste Abweichung von „die Antwort bleibt gleich":** Ohne `year`-Parameter
lieferte `handleStatistics()` bisher `"year":"2026"` als **String**, weil `date('Y')` einen
String zurückgibt. Mit dem Typparameter `int $year` wäre daraus eine stillschweigende
Umwandlung an der Funktionsgrenze geworden. Stattdessen castet der Handler sichtbar selbst; die
Antwort trägt in diesem Fall künftig `"year":2026`. Kein Aufrufer liest das Feld (geprüft über
`public/` und `tests/`), und `API.md` beschreibt `year` nur als Anfrageparameter — gehört aber
in den Changelog.

`handleStatistics()` behält die Rechteregel für Nicht-Manager (fremde `member_id` wird ignoriert,
`warning` gesetzt), ruft `buildStatisticsResult()` und gibt dessen Ergebnis als JSON aus.
Der `worktime`-Block bleibt an `handleStatistics()` gebunden — er hängt am Parameter
`include=worktime` und gehört nicht in den Anwesenheitsbericht.

### 4.4 `private/handlers/report_statistics.php` (neu)

```php
handleStatisticsReport($db, $database, $request_method, $authUserRole, $authMemberId): void
```

Nur `GET`, nur HTML. Liest `year` (Vorgabe: laufendes Jahr), `group_id`, `member_id`.
Für Nicht-Manager wird `member_id` auf `$authMemberId` gesetzt, der Parameter aus der Anfrage
ignoriert. Holt die Daten über `buildStatisticsResult()`, ergänzt bei genau einem Mitglied die
Terminliste (Abschnitt 6.3) und ruft `renderReport()`.

### 4.5 `public/api/api.php` (geändert)

- `require_once '../../private/handlers/report_statistics.php';` im Include-Block
- `case 'statistics_report': handleStatisticsReport($db, $database, $request_method, $authUserRole, $authMemberId); break;`
- `case 'export':` reicht zusätzlich `$authMemberId` durch

---

## 5 Rechte

| Ressource / Typ | admin | manager | user | device |
|---|---|---|---|---|
| `export&type=members`, `appointments`, `records` | ja | ja | **nein** | nein |
| `export&type=worktime_activity`, `worktime_appointment` | ja | ja | **nein** | nein |
| `export&type=worktime_member` | ja | ja | **ja**, eigene Person, nur `format=html` | nein |
| `statistics_report` | ja | ja | **ja**, eigene Person | nein |

**Durchsetzung:**

- Der `member_id`-Parameter wird für `user` **ignoriert, nicht validiert** — dieselbe Linie wie
  `handleStatistics()` heute. Eine Fehlermeldung „fremde ID" wäre ein Orakel darüber, welche IDs
  existieren.
- Bei `worktime_member` und `user` ist `format=html` **Pflicht**, nicht Vorgabe. Jeder andere
  Wert — auch der fehlende, denn `exportFormat()` fällt auf CSV zurück — wird mit 403
  beantwortet, nicht still zu HTML umgebogen: Der Aufrufer soll wissen, dass er nicht bekommt,
  was er angefordert hat. Die Oberfläche setzt den Parameter für diese Rolle immer.
- Ein `user` ohne verknüpftes Mitglied erhält 403 mit Klartext
  („Kein Mitglied mit diesem Benutzer verknüpft") — dieselbe Formulierung wie `my_data`.
- Die Gruppenprüfung des Statistikberichts läuft unverändert über `hasStatisticsGroupAccess()`.
- `requireWorktimeEnabled()` gilt für den Stundennachweis weiterhin für alle Rollen.

**Nicht geändert:** Manager sehen alle Datensätze ohne Gruppengrenze — konsistent zu records,
exceptions, statistics und work_sessions.

---

## 6 Inhalt des Statistikberichts

**Titel:** „Anwesenheitsbericht" · **Zeitraum:** „Jahr 2026"
(bei Gruppenfilter zusätzlich der Gruppenname in der Kopfzeile über `period`)

### 6.1 Erster Abschnitt: Kennzahlen (immer)

`heading` bleibt `null` — der Abschnitt steht unmittelbar unter der Kopfzeile und braucht keine
zweite Überschrift; `class` ist `report-summary`. Zweispaltig, Kennwert und Wert, aus `summary`:
Termine gesamt · Anwesend · Entschuldigt · Unentschuldigt · Durchschnittliche Anwesenheitsquote.

### 6.2 Je Gruppe ein Abschnitt (Überschrift = Gruppenname)

| Mitglied | Termine | Anwesend | Entschuldigt | Unentschuldigt | Quote |

`Entschuldigt` wird als `total_appointments − attended − unexcused_absences` gerechnet — dieselbe
Formel, die die Gesamtsumme heute verwendet. Die Quote steht als Text („87,0 %"); der
Fortschrittsbalken der Bildschirmansicht ist eine Bildschirmform.

Leerer Abschnitt: „Für dieses Jahr sind in dieser Gruppe keine Termine erfasst."

### 6.3 Abschnitt „Termine im Einzelnen" (nur bei genau einem Mitglied)

Für die Rolle `user` immer vorhanden, für admin/manager nur bei gesetztem Mitgliedsfilter.
Erst diese Liste macht den Bericht zum Nachweis statt zur Kennzahl.

| Datum | Termin | Terminart | Status | Ankunft | Herkunft |

- Grundlage sind dieselben Termine, die `calculateGroupStatistics()` zählt: Termine der
  Terminarten der Gruppen des Mitglieds, im Jahr, mit
  `a.date <= DATE_ADD(CURDATE(), INTERVAL 2 HOUR)`, begrenzt auf die Mitgliedschaftszeiträume
  über `getMemberActivityWhereYear()`.
- Status: `present` → „Anwesend", `excused` → „Entschuldigt", kein Eintrag → „Unentschuldigt".
- Ankunft: Uhrzeit aus `records.arrival_time`. **Leer bei `excused` und ohne Eintrag** — eine
  Ankunftszeit für jemanden, der nicht da war, ist keine Angabe, sondern ein Artefakt.
- Herkunft (siehe 3.7), drei Werte:

  | Wert | Bedingung |
  |---|---|
  | `gemessen` | `checkin_source` in `station_pin`, `device_auth`, `user_totp`, `auto_checkin` |
  | `korrigiert` | zum Paar Mitglied/Termin existiert eine **genehmigte** `time_correction` |
  | `nachgetragen` | alles Übrige: `admin`, `import`, `timer` |

  `korrigiert` schlägt `gemessen`: Eine genehmigte Zeitkorrektur überschreibt `arrival_time`,
  lässt `checkin_source` aber unverändert (`handleApprovedTimeCorrection()` in
  `private/helpers/utils.php`). Ohne diese Vorrangregel trüge eine korrigierte Zeit weiterhin
  das Etikett der ursprünglichen Messung. Ermittelt über einen `LEFT JOIN` auf `exceptions`
  mit `exception_type = 'time_correction' AND status = 'approved'`.
- **Kein Ortsname.** `records.location_name` ist freie Eingabe und gehört nicht auf einen
  Anwesenheitsnachweis.
- Sortierung: Datum, dann Startzeit.
- Ist ein Mitglied in mehreren Gruppen, erscheint jeder Termin genau einmal (`DISTINCT` über
  `appointment_id`).

### 6.4 Fußnoten

- „Gezählt werden nur Termine, die zum Zeitpunkt der Erstellung bereits begonnen haben."
- „Termine ohne Eintrag gelten als unentschuldigt."
- „Automatisch erzeugte Check-ins zählen wie erfasste Anwesenheiten." (OI-20)
- „Der Bericht berücksichtigt die Zeiträume der Mitgliedschaft."
- „gemessen: Die Ankunftszeit wurde bei der Anmeldung an einer Station oder in der App
  aufgezeichnet."
- „korrigiert: Die Ankunftszeit wurde auf Antrag geändert und genehmigt."
- „nachgetragen: Die Ankunftszeit wurde von Hand erfasst oder eingelesen; sie entspricht
  gegebenenfalls der Startzeit des Termins und ist dann keine Messung."

---

## 7 Oberfläche

### 7.1 Statistik-Sektion

`public/index.html`: Die Kopfzeile bekommt

```html
<div class="header-actions">
  <button class="btn-secondary" id="btnStatisticsReport">📄 Bericht</button>
</div>
```

ohne `data-role` — sichtbar für alle Rollen.

`public/js/modules/statistics.js`: `openStatisticsReport()` liest `statisticYearFilter`,
`statGroup` und `statMember`, baut
`?resource=statistics_report&year=…[&group_id=…][&member_id=…]` und öffnet die Adresse mit
`window.open(url, '_blank', 'noopener')` — wie der Arbeitszeitbericht. Kein `fetch`: Der Bericht
ist eine Seite, kein Datensatz.

### 7.2 Zeiterfassungs-Sektion

`public/index.html`: `data-role="manager"` entfällt an `#btnWorktimeReport`.

`public/js/modules/worktime.js`, `openWorktimeReportModal()` — für `user`:

- `#reportType` auf `worktime_member` gesetzt und ausgeblendet (die anderen beiden Berichtsarten
  aggregieren über alle Personen)
- `#reportMemberGroup` ausgeblendet (die Person steht fest)
- `#btnWorktimeReportCsv` ausgeblendet
- Dialogtitel „Mein Stundennachweis"

`runWorktimeReport()` setzt für `user` kein `member_id` — der Server erzwingt es ohnehin, und ein
mitgeschickter Parameter würde vortäuschen, er wäre wirksam.

---

## 8 Fehlerbehandlung

Der Bericht wird über einen Seitenaufruf geholt, nicht über `fetch`. Fehler erscheinen daher als
JSON in einem neuen Tab. Das ist die bekannte, in `docs/OPEN-ITEMS.md` dokumentierte
Einschränkung des Berichtszugangs; sie wird hier nicht gelöst, aber auch nicht verschärft:

- Zeitraum- und Parameterfehler fängt die Oberfläche vorab ab (bestehende Prüfung im Dialog).
- Ein 403 ist für `user` nur durch Manipulation der Adresse erreichbar.
- Läuft die Session ab, während der Dialog offen steht, erscheint die Anmeldeaufforderung als
  JSON im neuen Tab — unverändert zum heutigen Verhalten.

Die Druckansicht darf **nicht** aus einem Blob kommen: Die Seite setzt `<base href="../">`, damit
`css/print.css` und das Vereinslogo laden. Unter einer `blob:`-URL greift diese Basis nicht.

---

## 9 Tests

Neue Suite `tests/suites/report_api.php`:

| Prüfung | Erwartung |
|---|---|
| `user` holt `export&type=worktime_member&format=html` | 200, HTML, enthält den eigenen Namen |
| `user` setzt fremde `member_id` | 200, Bericht enthält **nicht** den fremden Namen |
| `user` holt `export&type=worktime_member` ohne `format` | 403 |
| `user` holt `worktime_activity`, `worktime_appointment`, `records`, `members` | je 403 |
| `user` holt `statistics_report` | 200, nur eigene Zeilen |
| `user` setzt fremde `member_id` am `statistics_report` | 200, fremder Name fehlt |
| `device` holt beide Berichte | je 403 |
| Notiz mit `<script>alert(1)</script>` im Stundennachweis | erscheint maskiert, nicht als Markup |
| `admin` holt beide Berichte | 200 |
| Record mit `checkin_source = 'admin'` in der Terminliste | Herkunft „nachgetragen" |
| Record mit `checkin_source = 'station_pin'` | Herkunft „gemessen" |
| Record mit `station_pin` **und** genehmigter Zeitkorrektur | Herkunft „korrigiert", nicht „gemessen" |
| Termin mit Status `excused` | Spalte Ankunft leer |
| Summenprobe: `statistics`-JSON gegen `statistics_report` | gleiche Werte für Termine, Anwesend, Unentschuldigt, Quote |

Bestehende Suiten müssen unverändert grün bleiben — insbesondere `worktime_api`, die die drei
Arbeitszeitberichte bereits prüft. Das ist die Absicherung dafür, dass der Umbau des Renderers
die Ausgabe nicht verändert hat.

Manuell (`docs/testplan.md`): Druckbild in Chrome und Firefox, Bericht mit Logo und ohne, Bericht
auf der Demo-Installation (Hinweisblock muss erscheinen).

---

## 10 Doku und Version

- `API.md`: Ressource `statistics_report`, geänderte Rechte der Exporttypen
- `CHANGELOG.md` und `version.json`: **1.4.1 → 1.5.0** — zwei neue Funktionen, keine Bruchstelle.
  Keine Migration: Das Schema bleibt unberührt.
- `docs/testplan.md`: Abschnitt Berichte um Statistik und Nutzerrolle erweitern
- `docs/OPEN-ITEMS.md`: freier Zeitraum für den Statistikbericht als bewusst verschoben

---

## 11 Anschluss: Pünktlichkeit und Zuverlässigkeit

`CLAUDE.md` und `README.md` beschreiben das Projekt als Auswertung von Anwesenheit **und
Pünktlichkeit**. Eingelöst wird das bisher nirgends: `arrival_time` wird erfasst, aber außer zur
Terminzuordnung nicht verwendet. Das ist eine offene Zusage, kein Fehler dieses Berichts — sie
wird in einer **eigenen Spec direkt im Anschluss** eingelöst. Festgehalten sind hier nur die
Vorentscheidungen, damit die Berichts-Spec nicht später umgedeutet werden muss:

- **Herkunft wird dauerhaft gespeichert**, nicht heuristisch abgeleitet: eine Spalte
  `arrival_measured` in `records`, von jedem Schreibpfad gesetzt, per Migration rückwirkend
  befüllt. Die Heuristik aus 6.3 ist die Übergangslösung, bis diese Migration steht.
- **Zwei getrennte Kennzahlen, niemals multipliziert.** Ein zusammengerechneter „Score" verbirgt,
  was tatsächlich passiert ist.
  - *Pünktlichkeit* nur über gemessene Ankünfte: Quote der Ankünfte innerhalb einer Karenz plus
    **Median** der Verspätung — der Mittelwert kippt bei einem einzigen Ausreißer. Immer mit
    Bezugsgröße; unter fünf Messungen keine Quote, sondern „zu wenige Messungen".
  - *Zuverlässigkeit* über alle Termine, mit drei Ausgängen: **erschienen**, **abgemeldet**
    (Ausnahme vor Terminbeginn angelegt, ablesbar an `exceptions.created_at`), **ausgefallen**.
    Quote = (erschienen + abgemeldet) / Termine. Die heutige Anwesenheitsquote bestraft eine
    rechtzeitige Absage wie unentschuldigtes Fehlen; das ist der Punkt, den sie verfehlt.
- **Die Karenz bekommt eine eigene Einstellung**, Vorgabe 5 Minuten. `checkin_tolerance_hours`
  wird **nicht** wiederverwendet: Die zwei Stunden dort sind das Fenster für die
  *Terminzuordnung*. Wer 90 Minuten zu spät kommt, wird korrekt zugeordnet und ist trotzdem zu
  spät.
- **Vor der Umsetzung** gehört eine personenbezogene Verhaltenskennzahl nach `DATENSCHUTZ.md` —
  Zweck, Aufbewahrung, Sichtbarkeit für andere Rollen.

Der Bericht bekommt die Kennzahlen dann als zusätzliche Zeilen im ersten Abschnitt. Das ist
additiv: Ein älterer Ausdruck wird dadurch nicht falsch.

---

## 12 Bewusst weggelassen

| Verworfen | Grund |
|---|---|
| Freier Zeitraum für den Statistikbericht | Umbau der Statistik-SQL inklusive Mitgliedschaftszeiträume; Bedarf nicht geäußert |
| CSV des Statistikberichts | `export&type=records` liefert Anwesenheitsdaten bereits als CSV |
| CSV für die Rolle `user` | `my_data` deckt den Selbstexport ab |
| Summen nach Tätigkeit und nach Termin für `user` | Aggregate über alle Personen; zwei weitere Pfade, die einzeln abgesichert werden müssten |
| Berichtszugang in der PWA | Das Dashboard ist für einen Druckvorgang der richtige Ort |
| Terminliste bei mehreren Personen | Ein Jahresbericht des ganzen Vereins würde dreißig Seiten und mehr |
| Eigener Bereich „Meine Nachweise" im Profil | Dritter Ort für eine Funktion, die zu den Zahlen gehört |
