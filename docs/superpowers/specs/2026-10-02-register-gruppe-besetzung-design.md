# Register gehören zu einer Gruppe; Besetzung in der Gliederung

**Datum:** 2026-10-02
**Status:** Entwurf
**Ersetzt:** Abschnitte 4 und 5 von `2026-10-01-register-statistik-besetzung-design.md` (Statistik nach
Untergruppe, Besetzungsübersicht). Abschnitt 3 dort (gemeinsame Soll-Menge, Aktivität am
Termindatum) gilt unverändert weiter.
**Anlass:** Praxistest des Zweigs `feat/register-statistik` am 2026-10-02
**Kippt teilweise:** `2026-09-16-untergruppen-gliederung-design.md`, Abschnitt 3.1 „Markierung statt
Hierarchie“ — Register bekommen eine Gruppe, **ohne** Vererbung (Abschnitt 2 unten)
**Zielversion:** keine — Feature-Arbeit ohne Versionssprung. **Schemaänderung ja**, der
Migrationsschritt kommt von der Release-Sitzung (Abschnitt 8).

---

## 1 Befund aus dem Praxistest

1. **Die textuelle Besetzung ist doppelt und unübersichtlich.** Dialog und App zeigen einen eigenen
   Block „Besetzung“ und darunter die nach Register gegliederte Liste, die dieselben Zahlen je
   Abschnitt schon trägt.
2. **Die Registerstatistik rechnet über zu viel.** In den Demodaten stehen alle sechs Mitglieder der
   Vorstandschaft auch in einem Register. Die Registertabelle bekommt deshalb eine Spalte
   „Vorstandssitzung“, und die Sitzungen zählen in die Registerquote. Ebenso bietet eine
   Vorstandssitzung mit Rückmeldung die Gliederung „Register“ an.

Beide Punkte haben dieselbe Wurzel: Gruppen spielen zwei Rollen — **Zielgruppe** (wer wird
erwartet: Aktive, Jugend, Vorstandschaft) und **Einteilung** (welches Register: Klarinetten,
Trompete …) —, und das System kennt die Verbindung zwischen beiden nicht. Es weiß nicht, dass die
Klarinetten eine Einteilung der Aktiven sind und nicht der Vorstandschaft.

Die Spec zu 1.8.0 hat eine Zuordnung Register → Gruppe bewusst weggelassen, mit der Begründung,
die Folgefragen stelle „heute niemand“. Der Praxistest stellt sie.

## 2 Entscheidungen (Nutzer, 2026-10-02)

| Frage | Entscheidung |
|---|---|
| Wie hängen Register und Gruppen zusammen? | **Ein Register gehört zu genau einer gewöhnlichen Gruppe, ohne Vererbung.** Erwartet wird weiterhin nur über die Terminart |
| Muss ein Registermitglied in der Gruppe des Registers stehen? | **Ja, der Server sorgt dafür** |
| Bestehende Register bei der Umstellung | **Ableiten, wo eindeutig; sonst ohne Gruppe mit Hinweis** |
| Mitglieder ohne „Namen sichtbar“ | **Keine Aufteilung nach Register**, nur der Gesamtbalken wie heute |
| Verwalter in der Check-in-App | **Dieselbe gegliederte Liste wie im Dashboard**, mit Namen |
| Zuklappen | **Nur in Rückmeldungslisten**, alle Abschnitte zugeklappt, „Alle aufklappen“; Anwesenheitslisten bleiben offen |

Verworfen: ein Schalter „nach Register einteilen“ an der Terminart (überdeckt das fehlende
Datenmodell nur), ein Schalter an der Gruppe ohne Zuordnung der Register (trennt die Register
verschiedener Gruppen nicht), Register in der Terminart gar nicht wählbar (nimmt die Registerprobe).

## 3 Datenmodell

`member_groups.parent_group_id INT NULL`, Fremdschlüssel auf `member_groups(group_id)` mit
`ON DELETE SET NULL`.

- Gesetzt nur bei `is_subgroup = 1`; die Obergruppe muss `is_subgroup = 0` haben. Genau zwei
  Ebenen, keine Register von Registern. Verstöße weist `member_groups` POST/PUT mit 400 ab.
- `is_subgroup` bleibt: Ein Register ohne Gruppe (nach der Umstellung, Abschnitt 8) ist weiterhin
  eine Untergruppe — es gliedert, rechnet aber nicht (Abschnitt 5).
- Begriffe in dieser Spec: **Register S**, seine **Gruppe P** (`parent_group_id`).

## 4 Mitgliedschaftsregel

Gilt auf dem Server für jeden Schreibweg der Zuordnungen — `members` POST und PUT
(`private/handlers/members.php`) und der CSV-Import (`private/handlers/import.php`) — und beim
Zuweisen einer Gruppe an ein Register (`member_groups` PUT):

1. **Normalisierung der Gruppenliste eines Mitglieds:** Für jedes Register S mit Gruppe P in der
   Liste wird P ergänzt. Das Register gewinnt: Eine Liste, die S enthält und P nicht, führt zu S und
   P. Wer aus P austreten soll, muss auch aus dessen Registern genommen werden.
2. **Nachträgliche Zuordnung:** Bekommt ein Register eine Gruppe (oder eine andere), werden alle
   seine Mitglieder in die neue Gruppe aufgenommen. Aus der alten Gruppe wird niemand entfernt.
3. **Meldung statt Stille:** Die Antwort nennt jede Ergänzung, z. B.
   `"added_groups": [{"member_id": 12, "group_id": 1}]` (genaue Form im Plan). Die Oberfläche zeigt
   sie als Hinweis („Max wurde zusätzlich Aktive zugeordnet“).
4. **Dialog:** Wird im Mitgliederdialog eine Gruppe abgewählt, wählt der Dialog deren Register mit
   ab; wird ein Register gewählt, wählt er die Gruppe mit an. Das ist Bequemlichkeit — maßgeblich
   bleibt die Regel auf dem Server.

## 5 Wo die Registersicht gilt

**Grundregel:** Die Registersicht gilt für einen Termin, wenn seine Terminart einer Gruppe P mit
Registern zugeordnet ist oder direkt einem Register S mit Gruppe. Eine Vorstandssitzung erfüllt
keins von beiden.

### 5.1 Statistik

Tabelle eines Registers S mit Gruppe P (ersetzt Spec 2026-10-01, Abschnitt 4.1 und 4.2):

- **Termine:** Paare der Soll-Menge mit Mitglied ∈ S, deren Termin über P **oder** über S kommt
  (`via_group_id IN (P, S)`). Nicht mehr: Termine über andere Gruppen der Mitglieder
  (Vorstandssitzung) und über ein anderes Register derselben Gruppe (Registerprobe Flügelhorn bei
  einem Doppelspieler).
- **Spalten:** die Terminarten von P und von S, sortiert wie bisher.
- **Zeilen:** die Mitglieder von S mit mindestens einem Paar im Bereich.
- **Register ohne Gruppe:** keine Tabelle.
- **Kopfzahlen, Pünktlichkeit, Zuverlässigkeit** mit Filter auf S: über denselben Bereich.
- **Unterzeile:** „<Oberbegriff> von <Gruppenname>: Termine von <Gruppenname> und eigene Termine“,
  z. B. „Register von Aktive: Termine von Aktive und eigene Termine“. Ohne Beugung, weil
  Oberbegriff und Gruppenname frei wählbar sind; die Unterzeile aus dem ersten Durchgang
  („<Oberbegriff>: alle Termine der Mitglieder“) entfällt.

Umsetzung: `expectedPairsScopeSql()` verlangt für Untergruppen zusätzlich
`via_group_id IN (S, parent_group_id(S))`; `attendanceSubgroupTypes()` liefert die Terminarten von
P und S. Gewöhnliche Gruppen bleiben unverändert.

### 5.2 Gliederung in Rückmeldung und Anwesenheitsliste

- Die Stufe „Register“ wird nur angeboten, wenn für den Termin die Registersicht gilt.
- Als Abschnitte erscheinen nur Register, deren Gruppe der Terminart zugeordnet ist, und ein direkt
  zugeordnetes Register. Ein Vorstandsmitglied, das Trompete spielt, erzeugt auf der Liste einer
  Vorstandssitzung keinen Abschnitt „Trompete“.
- Erwartete Mitglieder ohne passendes Register stehen im Sammelabschnitt „Ohne <Oberbegriff>“.
- Umsetzung: `groupsAttachToMembers()` bekommt die Register, die für den Termin gelten, und hängt
  nur diese als `subgroups` an.

### 5.3 Druck

Die Besetzung im Druck der Rückmeldungen folgt denselben Abschnitten (5.2).

## 6 Zugeklappte Abschnitte mit Balken

Ersetzt Spec 2026-10-01, Abschnitt 5.2.

- **Kopfzeile je Abschnitt** als Knopf (`aria-expanded`, Tastatur):
  `▸ Klarinetten  [Balken]  2 von 5 · 1 unsicher · 2 offen`. Balken in den Farben des
  Ampelbalkens (Zusage, unsicher, Absage, offen), Breiten aus den Zahlen.
- **Ausgangszustand zugeklappt.** Aufgeklappt zeigt der Abschnitt die Namen (Namensliste) bzw. die
  Tabellenzeilen (Verwalter-Tabelle). „Alle aufklappen / Alle zuklappen“ über der Liste.
- **Stufen:** „Register“ und „Gruppe“. „Alphabetisch“ bleibt flach und offen.
- **Filter „Keine Antwort“:** klappt alle Abschnitte auf; die Zahlen der Kopfzeile kommen weiter aus
  allen Mitgliedern des Abschnitts.
- **Doppelspieler:** Hinweis „n Mitglied(er) stehen in mehreren Abschnitten“ bleibt.
- **Entfällt:** der eigene Block „Besetzung“ in Dialog und App-Karte; das Feld `staffing` in den
  JSON-Antworten. `responsesStaffing()` bleibt für den Druck.
- **Wer sieht was:**
  - Verwalter, Dashboard und App: gegliederte Liste mit allen Namen. `?upcoming=1` liefert
    Verwaltern dafür künftig `members` (mit `groups`/`subgroups`, ohne Bemerkungen und Anträge
    anderer, wie im Dashboard für Verwalter — genaue Felder im Plan).
  - Mitglieder mit „Namen sichtbar“: dieselbe Liste, nur Status.
  - Mitglieder ohne „Namen sichtbar“: nur der Gesamtbalken, keine Registerzeilen.
- **Anwesenheitslisten:** bleiben offen (Entscheidung aus 1.8.0), übernehmen aber 5.2.
- **Technik:** Kopfzeile als gemeinsame Funktion in `public/js/modules/grouping.js` für
  Verwalter-Tabelle und Namensliste; gleich gehaltene Fassung in `public/checkin/js/app.js`.
  Aktionen über `data-action` (CSP).

## 7 Gruppenverwaltung und Terminart

- **Gruppendialog:** Bei gesetztem Häkchen „Untergruppe“ erscheint „gehört zu“ mit allen
  gewöhnlichen Gruppen; leer heißt „ohne Gruppe“.
- **Gruppenliste:** Register eingerückt unter ihrer Gruppe; Register ohne Gruppe am Ende mit
  Hinweis „bitte zuordnen“.
- **Terminart, Gruppenauswahl:** Register eingerückt unter ihrer Gruppe, Unterzeile „nur für
  Termine dieses Registers, z. B. Registerprobe“. Aktive und Trompete zusammen bleiben erlaubt
  (Trompete ist in Aktive enthalten).

## 8 Umstellung bestehender Installationen

- Neue Funktion (eigene Datei unter `private/helpers/`, PHP-8.0-Syntax, weil der Update-Pfad sie
  lädt) mit der Signatur einer Migration: `fn(PDO $pdo, string $prefix): array{log, warnings}`.
  1. Spalte `parent_group_id` anlegen, falls sie fehlt, samt Fremdschlüssel.
  2. Für jedes Register ohne Gruppe: Stehen alle seine Mitglieder in genau einer gemeinsamen
     gewöhnlichen Gruppe, diese zuordnen und protokollieren. Ein Register ohne Mitglieder bleibt
     ohne Gruppe.
  3. Für jedes zugeordnete Register die Mitgliedschaftsregel anwenden (Abschnitt 4.2) und jede
     Ergänzung protokollieren.
- **Den Schritt in der Migrationskette legt die Release-Sitzung an** (Regel für parallele
  Sitzungen) und ruft dort diese Funktion auf. Der Zweig liefert die Funktion mit Unit-Tests,
  `private/setup/ehrensache_db.sql` mit der neuen Spalte und eine Notiz für die Release-Sitzung
  (OPEN-ITEMS und Abschlussbericht).
- Testdatenbanken: Die Worktree-Kopie `ehrensache_reg` bekommt die Spalte über die Funktion. Die
  Hauptdatenbank `ehrensache` braucht sie vor dem Merge — nur mit Freigabe des Nutzers.

## 9 Prüfung

- **Datenmodell:** Obergruppe nur für Untergruppen und nur auf gewöhnliche Gruppen (400 sonst);
  `ON DELETE SET NULL`; Mitgliedschaftsregel über `members` POST, PUT, CSV-Import und
  nachträgliche Zuordnung, jeweils mit Meldung; Umstellungsfunktion als Unit-Test (eindeutig,
  mehrdeutig, leeres Register, Ergänzungen).
- **Statistik:** Welt aus `statistics_subgroups_api` erweitern — R gehört zu G, R2 ohne Gruppe,
  dazu eine Gruppe „Vorstand“ mit eigener Terminart, in der C steht, und ein zweites Register R3
  von G mit eigener Registerprobe, in dem C auch steht. Geprüft: keine Vorstandsspalte in R, R2 ohne
  Tabelle, Registerprobe von R3 zählt nicht in R, Pünktlichkeit und Zuverlässigkeit über denselben
  Bereich, Kopfzahlen.
- **Gliederung:** Stufe „Register“ fehlt bei einem Termin ohne Registersicht; nur Register der
  Gruppen der Terminart erscheinen; Sammelabschnitt.
- **Oberfläche:** Wächter für Kopfzeile, Klappen über `data-action`, „Alle aufklappen“, Filter
  klappt auf, kein Block „Besetzung“ mehr, kein Zählen ohne Abschnittsschlüssel.
- **Server:** Mitglied ohne „Namen sichtbar“ bekommt keine `members`; Verwalter bekommt `members` in
  `?upcoming=1`; `staffing` steht in keiner JSON-Antwort mehr.
- **Mutationsproben** an den neuen Bedingungen; **Gleichheitsprüfung** gegen die Momentaufnahme —
  für gewöhnliche Gruppen keine unerwartete Abweichung; **Sichtprüfung** Dashboard und App bei
  320 px; Gesamtlauf und Klickdurchgang.

## 10 Doku

- `API.md`: `parent_group_id` an `member_groups`, Meldung der Ergänzungen an `members` und
  `member_groups`, `staffing` entfällt, `members` für Verwalter in `?upcoming=1`, Registerbereich
  der Statistik.
- `CHANGELOG.md` `[Unreleased]`: Einträge aus dem ersten Durchgang anpassen.
- `docs/OPEN-ITEMS.md`: OI-70 präzisieren; der Nachtrag zu OI-97 entfällt (Spalten nur noch von P
  und S); Notiz zur Migration für die Release-Sitzung.
- `docs/FEATURE-IDEAS.md` FI-14, `docs/testplan.md` Abschnitt 26.
- `docs/project_history.md`: Eintrag „Register gehören zu einer Gruppe, ohne Vererbung“ — die
  Entscheidung „Markierung statt Hierarchie“ aus 1.8.0 ist damit teilweise gekippt, mit Anlass.
