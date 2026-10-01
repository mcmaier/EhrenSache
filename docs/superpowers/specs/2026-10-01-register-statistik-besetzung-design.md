# Register in Statistik und Besetzung

**Datum:** 2026-10-01
**Status:** Entwurf
**Setzt um:** [OI-70](../../OPEN-ITEMS.md#oi-70--statistik-nach-untergruppe-rechnet-nicht) (Statistik
nach Untergruppe) und den offenen Rest von
[FI-14](../../FEATURE-IDEAS.md#fi-14--untergruppen-register-und-besetzungsübersicht)
(Besetzungsübersicht); behebt nebenbei die Jahresregel der Statistik (Abschnitt 3)
**Baut auf:** `2026-09-16-untergruppen-gliederung-design.md` (Untergruppen, 1.8.0),
`2026-09-14-terminrueckmeldung-design.md` (Rückmeldung, 1.7.0),
`2026-09-22-kalender-anwesenheit-design.md` (erwartete Mitglieder je Termin, 1.14.0)
**Zielversion:** keine — Feature-Arbeit ohne Versionssprung, Eintrag unter `[Unreleased]`
(Regel für parallele Sitzungen in `CLAUDE.md`). Keine Migration, keine Schemaänderung.

---

## 1 Ausgangslage

Seit 1.8.0 lässt sich eine Gruppe als Untergruppe markieren (`member_groups.is_subgroup`),
im Musikverein das Register. Listen gliedern danach, die Statistik nicht.

**Die Statistik rechnet ausschließlich über die Kette Terminart → zugeordnete Gruppen →
Mitglieder** ([`attendance.php`](../../../private/helpers/attendance.php),
[`punctuality.php`](../../../private/helpers/punctuality.php)). Je Gruppe entsteht eine Tabelle
mit ihren Mitgliedern und genau den Terminarten, die dieser Gruppe zugeordnet sind; die
Kopfzahlen fassen alle Gruppen zusammen und entdoppeln über `COUNT(DISTINCT appointment_id)`.

Am Bestand geprüft am 2026-10-01 (Testdatenbank: Register Klarinetten, Flügelhorn, Trompete,
Tenorhorn; Terminart „Registerprobe“ nur der Trompete zugeordnet):

1. **Ein Register ohne eigene Terminart bleibt leer.** Seit 1.8.0 erklärt ein Hinweis das.
2. **Ein Register mit eigener Terminart zeigt nur diese.** Wer „Trompete“ filtert, sieht die
   Quote der Registerprobe — gelesen wird sie leicht als Zuverlässigkeit des Registers, obwohl
   Gesamtproben und Auftritte (über „Aktive“) fehlen.
3. **Der Notbehelf ist brüchig.** Ordnet man ein Register zusätzlich der Gesamtprobe zu, bekommt
   es eine Tabelle über die Gesamtproben, und die Kopfzahlen bleiben dank der Entdopplung
   richtig. Aber es ändert, **wer erwartet wird**: Ein Registermitglied, das nicht bei „Aktive“
   steht, erscheint dann in Anwesenheitsliste, Rückmeldung und offenen Punkten. Und jede neue
   Terminart muss je Register von Hand nachgezogen werden.
4. **Doppelspieler** (Mitglied in zwei Registern) gibt es in der Testdatenbank nicht — die
   Frage war ungeprüft, nicht gelöst.

**Nebenfund — die Jahresregel.** Die Statistik prüft die Aktivität eines Mitglieds mit
`getMemberActivityWhereYear()`: „irgendwann im Jahr aktiv“. Anwesenheitsliste, Kalender und
Rückmeldung prüfen je Termindatum (`getMemberActivityWhere('m', 'a.date')`). Wer unterm Jahr
eintritt, bekommt in der Statistik deshalb jeden Termin vor seinem Eintritt als unentschuldigt
angerechnet. Belegt an der Testdatenbank:

| Mitglied | Eintritt | Soll 2025 laut Statistik | Soll je Termindatum | anwesend | Quote heute | richtig |
|---|---|---|---|---|---|---|
| #6 | 01.12.2025 | 20 | 5 | 1 | 5 % | 20 % |

Betroffen sind Quote, Kopfzahlen, Pünktlichkeit (Messabdeckung), Zuverlässigkeit und der
Anwesenheitsbericht (`statisticsReportAppointments()` in
[`report_statistics.php`](../../../private/handlers/report_statistics.php)) — jede Stelle mit
einer eigenen Kopie derselben Joins.

---

## 2 Entscheidungen (Nutzer, 2026-10-01)

| Frage | Entscheidung |
|---|---|
| Jahresregel | **Teil dieser Spec**, nicht vorab und nicht vertagt |
| Wofür gilt die Rechnung über Mitglieder? | **Nur für Untergruppen.** Gewöhnliche Gruppen legen fest, wer erwartet wird, und ihre Tabelle zeigt ihre Terminarten — unverändert. Untergruppen beschreiben, wer jemand ist, und ihre Tabelle zeigt alle Termine ihrer Mitglieder |
| Welche Termine zählen für ein Register? | Alle, zu denen seine Mitglieder erwartet werden (entschieden schon am 2026-09-16, OI-70) |
| „3 von 6“ — woher die 6? | **Zahl der zu diesem Termin erwarteten Mitglieder des Registers.** Keine gepflegte Mindestbesetzung |
| Doppelspieler in der Besetzung | **In jedem Register voll gezählt, gekennzeichnet.** Gesamtzahl bleibt entdoppelt |
| Wo erscheint die Besetzung? | Rückmeldungsdialog und Verwalter-Tabelle im Dashboard, **Check-in-App**, Druck |
| Technischer Weg | **Eine gemeinsame Soll-Menge** für alle Rechnungen statt eines zweiten Rechenwegs |

---

## 3 Die Soll-Menge

### 3.1 Regel

Ein Paar *(Mitglied m, Termin a)* wird **erwartet**, wenn

- die Terminart von a einer Gruppe g zugeordnet ist (`appointment_type_groups`),
- m in g steht (`member_group_assignments`),
- m **am Termindatum** aktiv ist (`getMemberActivityWhere('m', 'a.date')`),
- und — nur für die Statistik — a begonnen hat (`attendanceStartedSql()`).

Das ist wortgleich die Regel, nach der Anwesenheitsliste, Kalender (`attendanceExpectedMemberIds()`,
[`appointment_attendance.php`](../../../private/helpers/appointment_attendance.php)) und
Rückmeldung heute schon zählen. Die Statistik schließt sich ihr an; es gibt danach **eine**
Antwort auf die Frage, wer zu welchem Termin erwartet wird.

### 3.2 Ein Baustein

Neuer Helfer `private/helpers/expected_pairs.php` (`declare(strict_types=1)`, Copyright-Kopf).
Er erzeugt die Menge als SQL-Baustein — eine abgeleitete Tabelle mit den Spalten

```
ep(member_id, appointment_id, type_id, via_group_id)
```

samt Parametern. Eine Zeile je Weg: Erreicht ein Termin ein Mitglied über zwei Gruppen,
entstehen zwei Zeilen mit verschiedenem `via_group_id`. Wer entdoppeln will, zählt
`DISTINCT appointment_id` (Kopfzahlen) oder filtert auf eine Gruppe (Gruppentabelle) — wie
heute.

Filter, die der Baustein selbst anwendet, damit die Menge klein bleibt: Jahr, optional
Terminart, optional Mitglied, optional „nur begonnene Termine“ mit Vorlaufstunden, optional
eine Liste von Termin-IDs (für `attendanceExpectedMemberIds()`).

Die genaue Signatur legt der Umsetzungsplan fest; Vorgabe ist, dass jede der unten genannten
Abfragen ihre Joins Terminart → Gruppe → Mitglied → Aktivität **nicht** mehr selbst schreibt.

### 3.3 Wer den Baustein nutzt

| Stelle | heute | danach |
|---|---|---|
| `attendanceFetchGroupRows()` | eigene Joins, Jahresregel | Baustein |
| `attendanceFetchMemberTotals()` | eigene Joins, Jahresregel | Baustein |
| `attendanceDistinctAppointmentCount()` | eigene Joins (ohne Mitglieder) | Baustein — zählt dadurch nur Termine, zu denen im Bereich jemand erwartet wird (siehe 3.5) |
| `punctualityScope()` (Pünktlichkeit, Zuverlässigkeit) | eigene Joins, Jahresregel | Baustein |
| `statisticsReportAppointments()` | eigene Joins, Jahresregel | Baustein |
| `attendanceExpectedMemberIds()` | eigene Joins, Datumsregel | Baustein (gleiche Regel, eine Stelle) |

### 3.4 Was sich bewusst nicht ändert

- **Auswahllisten** bleiben bei `getMemberActivityWhereYear()`: `members.php` (Zeile 57) und
  `attendance_list.php` (Zeile 177) fragen „darf ich dieses Mitglied im Jahr auswählen“. Dort
  ist „irgendwann im Jahr aktiv“ richtig.
- **Die Kennzahl „Mitglieder“** (`attendanceActiveMemberCount()`) bleibt „im Jahr aktiv“. Sie ist
  eine Kopfzahl, kein Soll.

### 3.5 Folgen für bestehende Zahlen

- Quoten von Mitgliedern mit Ein- oder Austritt im Jahr steigen auf ihren richtigen Wert, ebenso
  die Gruppen- und Gesamtquoten, in denen sie stecken. Pünktlichkeit (Messabdeckung) und
  Zuverlässigkeit folgen.
- `total_appointments` der Kopfzahlen kann sinken, wenn ein Termin im Bereich liegt, zu dem
  niemand (mehr) erwartet wird — etwa eine Terminart einer Gruppe, deren einziges Mitglied vor
  dem Termin ausgetreten ist. Das ist gewollt: Gezählt werden Termine, über die gerechnet wird.
  Belegt wird es in der Gleichheitsprüfung (Abschnitt 6.2); tritt es in der Testdatenbank nicht
  auf, steht es als Testfall in der Suite.
- Alle übrigen Zahlen bleiben gleich (Abschnitt 6.2).
- Der Changelog benennt die Korrektur ausdrücklich: „Quoten von Mitgliedern, die im Jahr ein-
  oder ausgetreten sind, waren zu niedrig.“

---

## 4 Statistik nach Untergruppe (OI-70)

### 4.1 Bereich

| Gruppe | Bereich in der Soll-Menge |
|---|---|
| gewöhnliche Gruppe G | Paare mit `via_group_id = G` — unverändert |
| Untergruppe S | Paare, deren Mitglied in S steht — gleich, über welche Gruppe der Termin kommt |

Eine Terminart, die S selbst zugeordnet ist (Registerprobe), gehört damit automatisch zum
Bereich von S: Ihre Paare kommen über `via_group_id = S`, und ihre Mitglieder stehen in S.

Ohne Gruppenfilter rechnet die Statistik über alle Gruppen, die der Rolle zugänglich sind
(`getStatisticsGroups()`), wie heute. Da jedes Paar eines Untergruppen-Bereichs auch über eine
Gruppe kommt, ändern die Untergruppen an den Kopfzahlen ohne Filter nichts.

### 4.2 Tabelle einer Untergruppe

- **Zeilen:** die Mitglieder von S mit mindestens einem Paar im Bereich. Ein Doppelspieler steht
  in beiden Registertabellen — wie heute jemand, der in „Aktive“ und „Jugend“ steht.
- **Spalten (`appointment_types`):** alle Terminarten aller Gruppen, in denen mindestens ein
  Mitglied von S steht, einschließlich S selbst; sortiert wie heute (`type_name`, `type_id`).
  Die Liste hängt **nicht** vom Jahr ab, damit die Spalten beim Jahreswechsel nicht springen
  (Regel aus `attendanceBuildGroup()`, OI-48). Ein Mitglied, das eine Terminart nicht hat
  (Jugend-Termin bei einem Aktiven), bekommt dort 0 von 0 — dieselbe Darstellung wie heute für
  Terminarten ohne Termin im Jahr.
- **Keine Spalte, keine Tabelle:** Hat keine dieser Gruppen eine Terminart, entfällt die Tabelle
  wie heute bei Gruppen ohne Terminart.
- **Terminartfilter:** wie heute; führt keine Gruppe eines Mitglieds die Terminart, entfällt die
  Tabelle.
- `attendanceBuildGroup()` bleibt unverändert; ihre Vertragsprüfungen gelten auch hier (jede
  Zeile gehört zu einer Spalte, keine doppelte Kombination Mitglied × Terminart).

### 4.3 Antwort

Jeder Eintrag in `statistics[]` bekommt das Feld `is_subgroup` (bool). Sonst bleibt die Form
gleich. Reihenfolge: zuerst die gewöhnlichen Gruppen wie bisher, danach die Untergruppen nach
`sort_order`, dann Name (`groupSortCompare()` aus
[`groups.php`](../../../private/helpers/groups.php)).

Mit Untergruppen-Filter rechnen Kopfzahlen, Pünktlichkeit und Zuverlässigkeit über den Bereich
von S, entdoppelt wie heute.

### 4.4 Oberfläche

- Der erklärende Hinweis aus 1.8.0 („Untergruppe ohne Terminarten“) in
  [`statistics.js`](../../../public/js/modules/statistics.js) entfällt.
- Eine Untergruppen-Tabelle trägt im Kopf die Unterzeile „Alle Termine der Mitglieder dieses
  Registers“, mit dem eingestellten Oberbegriff (`subgroup_label`) statt „Registers“. Grund: Die
  Tabelle kommt anders zustande als eine Gruppentabelle, und das soll man sehen.
- Der Anwesenheitsbericht (Druck) übernimmt dieselbe Unterzeile.
- Rechte: unverändert. `hasStatisticsGroupAccess()` gilt für Untergruppen wie für Gruppen; ein
  Mitglied sieht in seiner Registertabelle nur die eigene Zeile, weil `member_id` für `user`
  erzwungen wird.

### 4.5 Bekannte Grenze, nur dokumentiert

Die Gruppenzugehörigkeit hat keine Zeitachse. Wer im Juni von Klarinette zu Saxophon wechselt,
zählt das ganze Jahr bei Saxophon. Das betrifft alle Gruppen, nicht nur Register, und ist nicht
Teil dieser Spec. Es wird als offener Eintrag in `OPEN-ITEMS.md` festgehalten.

---

## 5 Besetzungsübersicht (FI-14)

### 5.1 Daten

Die Einzelantwort von `appointment_responses` (Rückmeldung zu einem Termin) und die Liste
`?upcoming=1&with_info=1`, aus der die Check-in-App ihre Karten baut, bekommen für **Verwalter**
ein Feld `staffing`:

```json
"staffing": [
  {"group_id": 5720, "name": "Klarinetten", "expected": 6, "yes": 3, "maybe": 1, "no": 1, "open": 1, "shared": 1},
  {"group_id": null, "name": null, "expected": 4, "yes": 2, "maybe": 0, "no": 0, "open": 2, "shared": 0}
]
```

- Eine Zeile je Untergruppe, in der mindestens ein erwartetes Mitglied steht, nach `sort_order`,
  dann Name.
- `expected` = erwartete Mitglieder dieses Termins in dieser Untergruppe (Entscheidung „3 von 6“);
  `yes`/`maybe`/`no`/`open` = deren Rückmeldungen, `open` = ohne Antwort.
- `shared` = wie viele davon zusätzlich in einer anderen Untergruppe erwartet werden.
- Abschlusszeile mit `group_id: null` für erwartete Mitglieder ohne Untergruppe. Den Namen setzt
  die Oberfläche („Ohne Register“ mit dem eingestellten Wort), wie in der Gliederung seit 1.8.0.
- Gibt es unter den erwarteten Mitgliedern **keine** Untergruppe, ist `staffing` ein leeres Array.
- **Mitglieder bekommen das Feld nicht** — auch nicht mit sichtbaren Namen. Sie sehen die
  Zählzeilen je Abschnitt wie bisher in der Namensliste.
- Berechnet in PHP aus den Daten, die der Handler ohnehin hat (erwartete Mitglieder mit ihren
  Untergruppen aus `groupsAttachToMembers()`, Rückmeldungen). Keine zusätzliche Abfrage je
  Mitglied. Eine reine Funktion `responsesStaffing()` in
  [`responses.php`](../../../private/helpers/responses.php) — unit-testbar.

Ob die Listenantwort für Verwalter heute schon `members` mit `subgroups` je Termin trägt, prüft
der Umsetzungsplan am Code; falls nicht, wird `staffing` dort aus denselben Quellen gebildet,
**ohne** die Namensliste für Verwalter in die Listenantwort zu holen.

### 5.2 Anzeige

**Eine Quelle, drei Anzeigen.** Dashboard, App und Druck lesen `staffing` und rechnen nicht
nach. Die App führt bei der Gliederung bereits eine absichtlich gleich gehaltene Kopie der
Dashboard-Funktionen; diese Kopie soll nicht wachsen.

- **Dashboard, Rückmeldungsdialog** ([`responses.js`](../../../public/js/modules/responses.js)):
  Block „Besetzung“ zwischen Ampelbalken und Verwalter-Tabelle, eine Zeile je Eintrag:

  ```
  Klarinetten   3 von 6   · 1 unsicher · 1 Absage · 1 offen   (1 auch in anderem Register)
  Ohne Register 2 von 4   · 2 offen
  ```

  Nullwerte entfallen in der Aufzählung; der Klammerzusatz nur bei `shared > 0`, mit dem
  eingestellten Oberbegriff. Der Block erscheint nur bei nicht leerem `staffing`.
- **Dashboard, Verwalter-Tabelle:** Die Abschnittszeilen lauten „Klarinetten · 3 von 6
  zugesagt“ statt „Klarinetten · 6“ — auf **jeder** Gliederungsstufe (auch „Gruppe“), damit die
  Zeile überall dasselbe bedeutet. Diese Zahl ist eine Eigenschaft des Abschnitts, nicht nur des
  Registers; die Tabelle rechnet sie deshalb selbst, und zwar aus **allen** Mitgliedern des
  Abschnitts, unabhängig vom Filter „Keine Antwort“. Sonst stünde bei aktivem Filter „0 von 2
  zugesagt“ über einem Register, in dem vier von sechs zugesagt haben.
- **Check-in-App, Rückmeldungskarte, nur Verwalter**
  ([`app.js`](../../../public/checkin/js/app.js)): derselbe Block über der Namensliste im
  aufgeklappten Bereich. Die Karte bleibt im Überblick kompakt.
- **Druck der Rückmeldungen** (`responsesRenderPrint()`): der Block am Kopf des Blatts. Die
  Gliederung des Blatts nach Terminart-Gruppe bleibt (Entscheidung aus 1.8.0, Spec-Abschnitt 10).

Alles über `escapeHtml()`/`textContent`, keine Inline-Handler (CSP seit 1.18.0).

### 5.3 Nicht enthalten

- Anwesenheit je Register nach Terminbeginn (wäre FI-2 je Register)
- Mindestbesetzung, Warnfarben, Kurzform in der Terminliste
- Hauptregister je Mitglied

---

## 6 Prüfung

### 6.1 Neue Suite `statistics_subgroups_api`

Eigene Testwelt, aufgeräumt in `finally` (Testrückstände sind ein bekanntes Muster). Aufbau:

- Gruppe „Aktive“ mit Terminart „Gesamtprobe“, Register R mit eigener Terminart „Registerprobe“,
  zweites Register R2 ohne Terminart.
- Mitglieder: A (Aktive + R), B (nur R — wird nur zur Registerprobe erwartet), C (Aktive + R +
  R2, Doppelspieler), D (Aktive + R, Eintritt mitten im Jahr), E (Aktive, ausgetreten mitten im
  Jahr).
- Termine vor und nach den Ein-/Austrittsdaten, Anwesenheiten und Entschuldigungen gemischt.

Geprüft werden genaue Zahlen, nicht nur Formen:

- Gruppentabelle „Aktive“: nur Gesamtprobe, D und E nur mit den Terminen ihres Aktivzeitraums.
- Registertabelle R: Spalten Gesamtprobe und Registerprobe; B nur mit Registerprobe; D ohne
  Termine vor Eintritt.
- Registertabelle R2: Spalten Gesamtprobe und Registerprobe (über C), Zeile nur C.
- Kopfzahlen mit Filter R: entdoppelt, Soll = Summe der Paare.
- Kopfzahlen ohne Filter: unverändert durch die Untergruppen (gleich der Summe über die
  gewöhnlichen Gruppen, entdoppelt).
- Pünktlichkeit und Zuverlässigkeit mit Filter R über denselben Bereich.
- `is_subgroup` und Reihenfolge der Einträge.
- Rolle `user` (Mitglied A): Registertabelle mit genau einer Zeile; fremdes Register → 403.

### 6.2 Gleichheitsprüfung gegen den Bestand

`tests/db/verify_statistics_parity.php`: Vor dem Umbau wird für jedes Jahr der Testdatenbank und
jede Gruppe (sowie ohne Filter) die Statistikantwort als Momentaufnahme gesichert. Nach dem
Umbau vergleicht das Skript und meldet jede Abweichung. Erlaubt sind nur Abweichungen bei
Mitgliedern mit Ein- oder Austritt im betreffenden Jahr und bei den Kopf- und Gruppenzahlen, in
denen sie stecken, sowie die neuen Untergruppen-Tabellen. Jede andere Abweichung ist ein Fehler.

### 6.3 Besetzung

- Unit-Test `responsesStaffing()`: Zählung, Doppelspieler (`shared`), Abschlusszeile, Sortierung,
  leeres Array ohne Untergruppen.
- HTTP-Test: Verwalter bekommt `staffing` in Einzel- und Listenantwort; Mitglied nicht, auch
  nicht bei `names_visible`.
- Frontend-Wächter: Dashboard und App lesen `staffing`, zählen Rückmeldungen für den Block nicht
  selbst.
- Klickdurchgang (`tests/browser/click-through.mjs`) erreicht den Block im Dialog.

### 6.4 Mutationsproben

An den Bedingungen, die die Aussage tragen — Aktivität am Termindatum statt im Jahr,
Mitgliedschaft in S statt `via_group_id = S`, Entdopplung der Kopfzahlen, Sperre von `staffing`
für Mitglieder: jede einzeln umdrehen und zeigen, dass ein Test rot wird.

### 6.5 Laufzeit

Statistikanfrage (ohne Filter, mit Gruppen- und mit Untergruppen-Filter) sowie
`appointments?include=attendance` vor und nach dem Umbau auf der Testdatenbank messen und im
Umsetzungsprotokoll festhalten (Vorbehalt aus OI-98). Eine spürbare Verschlechterung ist ein
Befund, kein Grund zum Weiterbauen ohne Rückfrage.

---

## 7 Doku und Auslieferung

- `API.md`: Abschnitt `statistics` — Bedeutung der Untergruppen-Tabellen, Feld `is_subgroup`,
  Aktivität am Termindatum; Abschnitt `appointment_responses` — Feld `staffing`. Der Wächter
  `api_doc_keys` muss die neuen Schlüssel finden.
- `CHANGELOG.md` unter `[Unreleased]`: Neu (Registerstatistik, Besetzung), Behoben (Jahresregel,
  mit Hinweis auf geänderte Quoten).
- `OPEN-ITEMS.md`: OI-70 erledigt; neuer Eintrag „Statistik zählte Termine vor Eintritt“ als
  gefunden und behoben; neuer offener Eintrag „Gruppenzugehörigkeit ohne Zeitachse“.
- `FEATURE-IDEAS.md`: FI-14 umgesetzt (Besetzungsübersicht ohne Mindestbesetzung).
- `docs/testplan.md`: Abschnitt Register (Statistik, Besetzung in Dialog, App, Druck).
- Keine Migration, kein Schema, kein `version.json`, kein `?v=`-Sprung. Arbeit in eigenem
  Worktree mit eigener Datenbankkopie.
