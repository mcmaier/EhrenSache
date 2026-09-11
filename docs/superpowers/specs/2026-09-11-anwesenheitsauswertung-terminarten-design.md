# Anwesenheitsauswertung über alle Terminarten einer Gruppe

**Datum:** 2026-09-11
**Status:** entworfen
**Schließt:** OI-48
**Betrifft:** `private/helpers/attendance.php` (neu), `private/handlers/statistics.php`,
`private/handlers/report_statistics.php`, `public/js/modules/statistics.js`,
`public/index.html`, `tests/suites/statistics_unit.php` (neu), `tests/suites/report_api.php`

---

## 1 Ausgangslage

`calculateGroupStatistics()` ermittelt die Terminart einer Gruppe aus der M:N-Tabelle
`appointment_type_groups` mit **`fetch()`** — einer Zeile — und filtert die gesamte folgende
Auswertung auf `WHERE a.type_id = ?`.

Eine Gruppe hängt aber regelmäßig an mehreren Terminarten. Im Demo-Bestand:

| Gruppe | verknüpfte Terminarten | gezählt |
|---|---|---|
| Aktive | Gesamtprobe, Registerprobe, Auftritt | nur eine |
| Jugend | Gesamtprobe, Registerprobe, Auftritt | nur eine |
| Vorstandschaft | Vorstandssitzung | vollständig |

Für 2026 sind das **37 gezählte gegenüber 61 erfassten Terminen** — knapp 40 % bleiben
unsichtbar. Die Abfrage trägt kein `ORDER BY`; welche Terminart gewinnt, entscheidet die
Datenbank.

**Die ursprüngliche Absicht war nie „eine Terminart je Gruppe".** `docs/project_history.md`,
Abschnitt 4:

> Register/Abteilungen werden über die vorhandenen `member_groups` an der Standard-Terminart
> abgebildet; gegen die dabei entstehende Doppelzählung wird **zusätzlich nach `type_id`
> ausgewertet**.

Eine Auswertungseinheit war also als **Gruppe × Terminart** gedacht. Genau deshalb trägt das
Ergebnis ein Feld `appointment_type_id`, und genau deshalb entdoppelt die Summenbildung in
`buildStatisticsResult()` danach. Das `fetch()` hat aus dieser Absicht versehentlich eine
Einschränkung gemacht.

**Warum es so lange unbemerkt blieb:** Das Schema liefert keine Terminarten und Gruppen mit;
sie entstehen im Betrieb. Wer nur die Standard-Terminart nutzt, sieht den Fehler nie. Er trifft
die Vereine, die das System ernsthaft ausbauen.

**Wie er auffiel:** Beim Bau des Anwesenheitsberichts (1.5.0) stand die Spalte „Entschuldigt"
für jedes Mitglied und jedes Jahr auf null. Der einzige verwertbare `excused`-Eintrag im Bestand
hängt an einer Registerprobe — einer Terminart, die für die Gruppe des Mitglieds nicht
ausgewertet wurde.

## 2 Ziel

Die Anwesenheitsauswertung berücksichtigt **alle** Terminarten einer Gruppe, weist sie einzeln
aus und zählt einen Termin je Mitglied genau einmal.

---

## 3 Entscheidungen

### 3.1 Gruppensumme **und** Aufschlüsselung je Terminart

Eine Zeile beantwortet zwei Fragen zugleich: die Gesamtquote des Mitglieds in dieser Gruppe und
seine Quote je Terminart.

Die reine Gruppensumme allein wäre irreführend: Häufige Terminarten dominieren sie. Wer 37
Proben besucht und 6 Auftritte verpasst, stünde fast so gut da wie jemand, der überall war —
obwohl das für einen Verein zwei verschiedene Dinge sind. Die reine Aufschlüsselung ohne Summe
wiederum lässt die einfache Frage „wie zuverlässig ist diese Person" unbeantwortet.

### 3.2 Die Aufschlüsselung steht je Mitglied, nicht je Gruppe

Die Mitgliederzeile trägt neben der Gesamtquote je Terminart eine weitere Quote. Eine
Aufschlüsselung nur auf Gruppenebene zeigte, dass die Registerproben schlecht besucht sind, aber
nicht, von wem — und das ist die Frage, wegen der jemand die Statistik öffnet.

Die Alternative, je Mitglied eine Zeile pro Terminart auszugeben, wurde verworfen: Bei 33
Mitgliedern und drei Terminarten sind das 132 Zeilen statt 33, und der Vergleich zwischen
Mitgliedern — der eigentliche Zweck der Tabelle — wird mühsam.

### 3.3 Die Kopfzahlen entdoppeln je Mitglied

Erreicht ein Termin ein Mitglied über zwei Gruppen, zählt er in den Kopfzahlen **einmal**.

Heute werden die Kopfzahlen über Gruppen aufsummiert. Solange sich die Terminarten zweier
Gruppen nicht überschneiden, geht das auf; im Bestand vom 2026-09-10 ist das der Fall (sechs
Mitglieder in je zwei Gruppen, alle in „Aktive + Vorstandschaft", deren Terminarten disjunkt
sind). Das Modell erlaubt die Überschneidung aber — „Aktive" und „Jugend" teilen sich drei
Terminarten, es ist bloß niemand in beiden.

Eine Falle, die scharf ist und gerade nicht auslöst, ist keine Grundlage für Kennzahlen.

**Folge, die benannt gehört:** Die Summe der Gruppentabellen kann dann größer sein als die
Kopfzahl. Das ist kein Widerspruch, sondern der Unterschied zwischen „je Gruppe" und „je
Person" — und es passiert nur bei überlappenden Terminarten.

### 3.4 Der Zahlensprung wird im Changelog benannt, sonst nichts

Die Korrektur verändert jede bestehende Quote in jeder Installation, in unvorhersehbare
Richtung — je nachdem, wie diszipliniert bei den bisher ignorierten Terminarten erfasst wurde.

Ein ausführlicher Changelog-Eintrag erklärt, dass sich Quoten ändern, warum die alten Zahlen zu
eng gefasst waren und wie die neuen zu lesen sind. **Keine** Anzeige in der Oberfläche, **keine**
Übergangszeit mit beiden Werten. Die alte Zahl beruht auf einem Fehler; sie weiter auszuweisen
verlängerte seine Lebensdauer und verdoppelte die Rechenwege.

### 3.5 Die Fachlogik wandert in einen Helper — und wird zweigeteilt

Es gibt heute **keine Unit-Tests für die Anwesenheitsrechnung**. Die Suiten kennen
`worktime_unit`, `station_unit` und `cleanup_unit`; die Statistik wird ausschließlich über HTTP
geprüft. Genau die Logik, die sich als brüchig erwiesen hat, ist die einzige Fachlogik im
Projekt ohne direkt prüfbare Schnittstelle — weil sie in einem Handler steckt, der sie nur als
JSON herauslässt.

Deshalb wandert sie nach `private/helpers/attendance.php`, dem Muster von `worktime.php`
folgend, und wird dort **in zwei Sorten Funktionen getrennt**:

- **holende** Funktionen führen SQL aus und geben rohe Zeilen zurück,
- **formende** Funktionen nehmen Zeilen entgegen und geben die Ergebnisstruktur zurück — ohne
  Datenbank, ohne HTTP, ohne Datenbestand.

Die formenden Funktionen tragen die Logik, an der dieser Fehler hing. Sie sind mit
handgeschriebenen Zeilen prüfbar, auch für Fälle, die in keinem Demo-Bestand vorkommen.

---

## 4 Architektur

### 4.1 `private/helpers/attendance.php` (neu)

```php
// --- holend (DB) ---
attendanceGroupTypes($db, $database, int $groupId): array
attendanceFetchGroupRows($db, $database, int $groupId, int $year,
                         ?int $memberId, ?int $appointmentTypeId): array
attendanceFetchMemberTotals($db, $database, array $groupIds, int $year,
                            ?int $memberId, ?int $appointmentTypeId): array
attendanceDistinctAppointmentCount($db, $database, array $groupIds, int $year,
                                   ?int $appointmentTypeId): int
attendanceActiveMemberCount($db, $database, array $groupIds, int $year, ?int $memberId): int
attendanceGroupName($db, $database, int $groupId): ?string

// --- formend (rein, ohne DB) ---
attendanceBuildGroup(int $groupId, string $groupName, array $types, array $rows): array
attendanceBuildSummary(array $memberTotals, int $appointmentCount, int $memberCount): array
```

`attendanceActiveMemberCount()` ist die heutige `getActiveMemberCount()`, unverändert
übernommen und umbenannt.

### 4.2 `private/handlers/statistics.php` (geändert)

`calculateGroupStatistics()` und `getActiveMemberCount()` **entfallen** — ihre Arbeit liegt im
Helper.

`buildStatisticsResult()` bleibt und wird dünn:

1. Gruppen auflösen (`getStatisticsGroups()` / `hasStatisticsGroupAccess()`),
2. je Gruppe `attendanceGroupTypes()` + `attendanceFetchGroupRows()` + `attendanceBuildGroup()`,
3. Kopfzahlen über `attendanceFetchMemberTotals()` + `attendanceBuildSummary()`,
4. Ergebnis zurückgeben.

**Die Signatur bleibt unverändert.** Damit ändert sich für `report_statistics.php` keine
Aufrufstelle.

`getStatisticsGroups()` und `hasStatisticsGroupAccess()` **bleiben im Handler**. Sie beantworten
„wer darf was sehen", nicht „wie lauten die Zahlen" — dieselbe Trennlinie, die schon für
`buildStatisticsResult()` gilt: *die Funktion rechnet, sie autorisiert nicht.*

### 4.3 Antwortstruktur

Je Gruppe ersetzt `appointment_types[]` die bisherigen Einzelfelder `appointment_type_id` und
`appointment_type_name`. Je Mitglied kommt `by_type[]` dazu, und `excused` bleibt (seit 1.5.0
vorhanden).

```json
{
  "group_id": 1,
  "group_name": "Aktive",
  "appointment_types": [
    { "type_id": 1, "type_name": "Gesamtprobe" },
    { "type_id": 2, "type_name": "Registerprobe" },
    { "type_id": 3, "type_name": "Auftritt" }
  ],
  "members": [
    {
      "member_id": 5,
      "member_name": "Bauer, Anna",
      "total_appointments": 61,
      "attended": 48,
      "excused": 2,
      "unexcused_absences": 11,
      "attendance_rate": 78.7,
      "by_type": [
        { "type_id": 1, "type_name": "Gesamtprobe",
          "total_appointments": 37, "attended": 32, "excused": 0,
          "unexcused_absences": 5, "attendance_rate": 86.5 },
        { "type_id": 2, "type_name": "Registerprobe",
          "total_appointments": 18, "attended": 11, "excused": 2,
          "unexcused_absences": 5, "attendance_rate": 61.1 },
        { "type_id": 3, "type_name": "Auftritt",
          "total_appointments": 6, "attended": 5, "excused": 0,
          "unexcused_absences": 1, "attendance_rate": 83.3 }
      ]
    }
  ]
}
```

`by_type` enthält **jede** Terminart der Gruppe, auch solche ohne Termine im Jahr — dann mit
Nullwerten. Sonst verschwände eine Terminart je nach Jahr aus der Tabelle, und die Spalten
wechselten mit dem Jahresfilter.

Die Reihenfolge in `appointment_types` und `by_type` ist **identisch** (nach `type_name`
sortiert), damit die Oberfläche Spalten und Werte ohne Nachschlagen zuordnen kann.

**Die Check-in-PWA bleibt unangetastet.** Sie liest `group_name` und die Summenfelder je
Mitglied; die bleiben alle erhalten. Sie wird durch die Korrektur nebenbei richtig, statt wie
heute versehentlich nur eine Terminart abzubilden.

---

## 5 Die Abfragen

### 5.1 Terminarten einer Gruppe

```sql
SELECT atg.type_id, at.type_name
FROM {PREFIX}appointment_type_groups atg
LEFT JOIN {PREFIX}appointment_types at ON at.type_id = atg.type_id
WHERE atg.group_id = ?
ORDER BY at.type_name, atg.type_id
```

`LEFT JOIN`, damit eine verwaiste `type_id` die Zeile nicht schluckt; `type_name` ist dann
`null` und wird als „ohne Terminart" ausgegeben.

### 5.2 Je Mitglied und Terminart innerhalb einer Gruppe

```sql
SELECT m.member_id, m.name, m.surname, a.type_id,
       COUNT(a.appointment_id)                                    AS total,
       SUM(CASE WHEN r.status = 'present' THEN 1 ELSE 0 END)      AS attended,
       SUM(CASE WHEN r.appointment_id IS NULL THEN 1 ELSE 0 END)  AS unexcused
FROM {PREFIX}appointments a
JOIN {PREFIX}appointment_type_groups atg
     ON atg.type_id = a.type_id AND atg.group_id = ?
JOIN {PREFIX}member_group_assignments mga ON mga.group_id = atg.group_id
JOIN {PREFIX}members m ON m.member_id = mga.member_id AND <Aktivitätsbedingung>
LEFT JOIN {PREFIX}records r
     ON r.appointment_id = a.appointment_id AND r.member_id = m.member_id
WHERE YEAR(a.date) = ?
  AND a.date <= DATE_ADD(CURDATE(), INTERVAL 2 HOUR)
  [AND m.member_id = ?]
  [AND a.type_id = ?]
GROUP BY m.member_id, a.type_id
ORDER BY m.surname, m.name, a.type_id
```

Der Join auf `appointment_type_groups` ist an die feste `group_id` gebunden — dadurch entsteht
kein Fächer, jeder Termin trifft jedes Mitglied der Gruppe genau einmal.

`<Aktivitätsbedingung>` ist `getMemberActivityWhereYear($year, 'm')` aus
`private/helpers/member_activity.php`, unverändert wie heute.

Die beiden eckig geklammerten Bedingungen kommen nur dazu, wenn `memberId` beziehungsweise
`appointmentTypeId` gesetzt sind.

**`excused` wird hier nicht abgefragt, sondern als `total − attended − unexcused` gerechnet** —
eine Quelle, wie seit 1.5.0. Die Richtung ist umgekehrt zu 5.3, und das hat einen Grund: Hier
ist „kein Eintrag vorhanden" direkt zählbar (`r.appointment_id IS NULL`), dort ist es das nicht,
weil über `DISTINCT` gezählt wird und ein fehlender Eintrag keine Termin-ID beisteuert.

**Gruppensummen je Mitglied entstehen durch Addition der Typzeilen in PHP** — kein zweites SQL.
Das ist Addition, keine Aggregationslogik.

### 5.3 Kopfzahlen: je Mitglied entdoppelt

```sql
SELECT m.member_id,
       COUNT(DISTINCT a.appointment_id) AS total,
       COUNT(DISTINCT CASE WHEN r.status = 'present' THEN a.appointment_id END) AS attended,
       COUNT(DISTINCT CASE WHEN r.status = 'excused' THEN a.appointment_id END) AS excused
FROM {PREFIX}appointments a
JOIN {PREFIX}appointment_type_groups atg ON atg.type_id = a.type_id
JOIN {PREFIX}member_group_assignments mga
     ON mga.group_id = atg.group_id AND mga.group_id IN (…)
JOIN {PREFIX}members m ON m.member_id = mga.member_id AND <Aktivitätsbedingung>
LEFT JOIN {PREFIX}records r
     ON r.appointment_id = a.appointment_id AND r.member_id = m.member_id
WHERE YEAR(a.date) = ?
  AND a.date <= DATE_ADD(CURDATE(), INTERVAL 2 HOUR)
  [AND m.member_id = ?]
  [AND a.type_id = ?]
GROUP BY m.member_id
```

Das `DISTINCT` ist die Entdopplung aus 3.3: Erreicht ein Termin ein Mitglied über zwei Gruppen,
zählt er einmal.

`unexcused` wird hier nicht abgefragt, sondern als `total − attended − excused` gerechnet.
Umgekehrt zu 5.2, siehe die Begründung dort.

**Beide Wege müssen dasselbe Ergebnis liefern**, solange sich keine Terminarten überschneiden.
Tun sie es nicht, ist einer von beiden falsch — das prüft ein Testfall in 7.1.

Daraus bildet `attendanceBuildSummary()`:

| Kennzahl | Berechnung |
|---|---|
| `total_appointments` | eigene Abfrage, `COUNT(DISTINCT a.appointment_id)` über dieselben Bedingungen ohne Mitgliedsjoin |
| `total_members` | `attendanceActiveMemberCount()`, unverändert |
| `total_present` | Summe von `attended` über alle Mitgliedszeilen |
| `total_excused` | Summe von `excused` |
| `total_unexcused` | Summe von `total − attended − excused` |
| `overall_average` | `total_present / Σ total × 100`, auf eine Nachkommastelle |

`Σ total` ersetzt das heutige `$totalPossible` und ist die ehrlichere Bezugsgröße: die Summe
der Termine, die die Mitglieder tatsächlich betrafen — jeden einmal.

### 5.4 Damit wird eine Grundsatzentscheidung abgelöst

`docs/project_history.md`, Abschnitt 5, hält fest:

> Der Gesamtdurchschnitt wird **pro Gruppe** gebildet (`groupAppointments × groupMembers`) und
> aufaddiert — **nie global**, weil jede Gruppe unterschiedlich viele Termine und Mitglieder hat.

Diese Spec ersetzt den Mechanismus: Die Bezugsgröße entsteht **je Mitglied**, nicht je Gruppe.
Das gehört ausdrücklich benannt, sonst sieht es aus wie ein Versehen.

**Der Grund, aus dem die alte Regel entstand, bleibt gültig** — sie warnte vor
`totalAppointments × totalMembers`, einem globalen Produkt, das Gruppen mit vielen und wenigen
Terminen gleich behandelt und dadurch falsch rechnet. Vor dieser Falle schützt die neue
Rechnung ebenfalls: Sie bildet kein Produkt, sondern summiert, was jedes Mitglied tatsächlich
an Terminen hatte.

Die Rechnung je Mitglied ist **feiner** als die je Gruppe, nicht gröber. Sie löst zusätzlich
ein Problem, das die alte Regel nicht kannte: ein Mitglied in zwei Gruppen mit überlappenden
Terminarten. `groupAppointments × groupMembers` zählt dessen Termine zweimal, `Σ total` einmal.

**Vorgesehen für `docs/project_history.md`:** Sobald diese Spec umgesetzt ist, bekommt Abschnitt
5 einen Nachtrag — die alte Regel bleibt mit Datum stehen, daneben die Ablösung mit Begründung.
Nicht vorher: `project_history.md` beschreibt, warum das System **so gebaut ist**, nicht, was
geplant war. Bei Widerspruch gilt dort der Code.

---

## 6 Oberfläche

### 6.1 Dashboard (`public/js/modules/statistics.js`)

Die Tabelle je Gruppe bekommt Spalten:

`Mitglied | Termine | Anwesend | Entschuldigt | Unentschuldigt | Quote | <Terminart 1> | <Terminart 2> | …`

Die Terminart-Spalten zeigen **nur die Quote**. Die absoluten Zahlen stehen im
`title`-Attribut (`"32 von 37"`), damit die Zeile lesbar bleibt.

Der Fortschrittsbalken bleibt der **Gesamtquote** vorbehalten; die Terminart-Spalten sind Text.
Sieben Balken nebeneinander wären kein Informationsgewinn, sondern ein Muster.

Die Tabelle steht bereits in `.statistics-table-wrapper`. Der Wrapper bekommt
`overflow-x: auto`, falls er es nicht schon hat — bei vielen Terminarten wird die Tabelle breiter
als der Inhaltsbereich, und waagerechtes Scrollen der Tabelle ist besser als der ganzen Seite.

### 6.2 Anwesenheitsbericht (`private/handlers/report_statistics.php`)

`statisticsReportGroupSection()` bekommt dieselben Spalten.

**Die Fußnote „Ausgewertet wurden je Gruppe die Termine einer Terminart" entfällt ersatzlos.**
Sie dokumentiert den Fehler; mit der Korrektur wäre sie falsch. Damit entfällt auch die
Notwendigkeit, `appointment_type_name` je Gruppe als Einzelwert zu führen.

`handleStatisticsReport()` sammelt die Terminarten für die Terminliste künftig aus
`appointment_types[]` statt aus `appointment_type_id` — dieselbe Regel wie bisher: Die Liste
deckt genau die Terminarten ab, über die auch die Quoten gerechnet sind.

**Druckbreite:** Der Bericht läuft im Querformat. Drei zusätzliche Spalten passen; bei sehr
vielen Terminarten wird es eng. Das wird beobachtet, nicht vorab gelöst — eine Begrenzung
einzubauen, bevor jemand sie braucht, hieße raten.

---

## 7 Tests

### 7.1 Neu: `tests/suites/statistics_unit.php`

Prüft die **formenden** Funktionen mit handgeschriebenen Zeilen, ohne Datenbank. Das ist der
eigentliche Zweck des Umbaus.

| Fall | Erwartung |
|---|---|
| Mitglied mit Zeilen zu drei Terminarten | Summen stimmen, `by_type` hat drei Einträge in Typreihenfolge |
| Terminart ohne Termine im Jahr | Eintrag vorhanden, alle Werte 0, Quote 0 |
| Gruppe ohne Terminart | leeres `members`, kein Fehler |
| Keine Zeilen | `members` leer (heutiges Verhalten) |
| Quote bei `total = 0` | 0, keine Division durch null |
| `by_type`-Reihenfolge folgt der Typenliste | auch wenn die Zeilen anders sortiert hereinkommen |
| Kopfzahlen: `unexcused` aus `total − attended − excused` | nie negativ |
| **Gegenprobe der beiden Rechenwege:** dieselben Zeilen ohne Überschneidung durch 5.2 und 5.3 | Gruppensumme und Kopfzahl sind gleich |

**Korrektur am 2026-09-11 beim Schreiben des Umsetzungsplans:** Hier stand ursprünglich auch
„Mitglied über zwei Gruppen mit gemeinsamer Terminart → Termin zählt einmal". Das lässt sich
**nicht** als Unit-Test prüfen: Die Entdopplung geschieht im SQL über `COUNT(DISTINCT …)`, die
formende Funktion summiert nur bereits entdoppelte Zeilen. Ein Unit-Test hätte dort geprüft,
dass Addition addiert.

Der Fall gehört deshalb als **HTTP-Test** in die Suite — mit einer zweiten Gruppe, die
testweise an eine vorhandene Terminart gehängt wird, und einem Mitglied in beiden. Siehe 7.2.

Die Gegenprobe am Ende ist die wichtigste: `excused` und `unexcused` werden an den beiden
Stellen in entgegengesetzter Richtung hergeleitet (5.2 gegen 5.3). Solange sich keine
Terminarten überschneiden, müssen beide dasselbe ergeben. Weichen sie ab, ist einer der beiden
Wege falsch — und ohne diesen Test fiele es niemandem auf.

### 7.2 Erweitert: `tests/suites/report_api.php`

- Der Bericht zeigt je Terminart eine Spalte.
- Die bestehende Prüfung „die Terminliste deckt genau die gezählten Termine ab" muss weiter
  aufgehen — sie ist jetzt der Beweis, dass Liste und Quote dieselbe Terminmenge benutzen.
- Die Fußnote zur ausgewerteten Terminart darf **nicht mehr** erscheinen.
- **Die Entdopplung je Mitglied** (aus 3.3): Der Test legt eine zweite Gruppe an, hängt sie an
  eine Terminart, die das Testmitglied über seine bestehende Gruppe bereits erreicht, und weist
  das Mitglied zu. Danach muss die Kopfzahl `total_appointments` des Mitglieds **unverändert**
  sein — der Termin zählt einmal, obwohl er es jetzt über zwei Wege erreicht. Aufräumen im
  `finally`, nach dem Muster des Maskierungstests in derselben Suite.

  Das ist der einzige Weg, die Entdopplung zu belegen: Sie geschieht im SQL, nicht in der
  formenden Funktion.

### 7.3 Bestandsschutz

Alle übrigen Suiten müssen unverändert grün bleiben. `worktime_api` ist unberührt.

**Zu erwarten ist, dass sich Zahlen in Tests ändern**, die Quoten gegen feste Werte prüfen.
Solche Tests werden angepasst — aber erst, nachdem die neue Zahl von Hand gegen die Datenbank
nachgerechnet wurde. Ein Test, der an die neue Ausgabe angeglichen wird, ohne dass jemand die
neue Ausgabe geprüft hat, bestätigt nur sich selbst.

---

## 8 Doku und Version

- `API.md`: Antwortstruktur der Statistik, neue Felder, Wegfall von `appointment_type_id` und
  `appointment_type_name`; der Warnhinweis auf OI-48 entfällt.
- `CHANGELOG.md`: ausführlich unter `[Nicht veröffentlicht]` — **Quoten ändern sich**, warum,
  in welche Richtung, und wie die neue Zahl zu lesen ist.
- `docs/OPEN-ITEMS.md`: OI-48 als erledigt markieren, mit Datum. OI-52 (nur ganze Jahre) bleibt
  offen und ist davon unberührt.
- `docs/testplan.md`: Abschnitt 13 um die Terminart-Spalten erweitern.
- `docs/project_history.md`: Abschnitt 5 bekommt den Nachtrag aus 5.4 — **erst nach der
  Umsetzung**, nicht mit der Spec.
- `version.json`: **unverändert.** Der Versionssprung ist ein eigener Release-Vorgang.

**Was zu diesem Release-Vorgang gehört** — hier notiert, weil es zwischen den Vorhaben
durchzufallen droht und auch diese Arbeit beim Ausliefern trifft:

1. `version.json` erhöhen,
2. die sechs `?v=`-Querys in den vier HTML-Dateien nachziehen — darüber kommt der Stand über den
   Browser-Cache bei den Mitgliedern an,
3. **einen Manifest-Eintrag anlegen, auch ohne Schemaänderung.**

Punkt 3 ist nicht offensichtlich: `public/update/index.php` liest die Zielversion aus
`version.json` und lässt `resolveMigrationChain()` die Kette von der erkannten Datenbankversion
dorthin laufen. Fehlt ein Eintrag mit passendem `from`, bricht der Update-Assistent ab —
*„Keine Migration ab Version 1.4.1 vorhanden."* Eine Installation käme dann nicht mehr auf den
neuen Stand, obwohl sich am Schema nichts geändert hat.

Das Projekt hat dafür bereits ein Vorbild: `private/migrations/1.4.0.php` ist genau so eine
leere Migration — 25 Zeilen, kein DDL, mit dem Kommentar, warum sie trotzdem existiert.

Die Datei heißt nach ihrer **Ausgangsversion**, und ihr `from` ist das `to` des letzten
Manifest-Eintrags zum Zeitpunkt der Umsetzung. Heute wäre das `1.4.1.php`.

*(Befund aus der parallelen Arbeit an OI-51, dort am 2026-09-11 aufgeklärt.)*

---

## 9 Bewusst weggelassen

| Verworfen | Grund |
|---|---|
| Eine Zeile je Gruppe × Terminart | Beantwortet „wie zuverlässig ist diese Person" nicht mehr; die Tabelle vervierfacht sich |
| Aufschlüsselung nur auf Gruppenebene | Zeigt, dass Registerproben schlecht besucht sind, aber nicht von wem |
| Alles in einer Abfrage über alle Gruppen, Aggregation in PHP | Widerspricht der Grundsatzentscheidung „die Datenbank aggregiert" (`project_history`, Abschnitt 5) und ist schwerer zu prüfen |
| Hinweisband in der Statistik-Sektion zum Zahlensprung | Zustand je Nutzer speichern, eine Anzeige mehr, die später wieder entfernt werden will |
| Alte und neue Quote nebeneinander | Die alte Zahl beruht auf einem Fehler; sie weiter auszuweisen verlängert seine Lebensdauer |
| Balken je Terminart | Sieben Balken nebeneinander sind ein Muster, kein Informationsgewinn |
| Begrenzung der Spaltenzahl im Druckbericht | Raten, bevor jemand das Problem hat |
| Gruppen ohne Terminart anzeigen | Heutiges Verhalten (sie entfallen) bleibt; eine Gruppe ohne Terminart hat keine Anwesenheit, über die sich reden ließe |
