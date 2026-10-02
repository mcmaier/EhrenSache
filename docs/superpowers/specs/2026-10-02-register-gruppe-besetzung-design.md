# Register gehören zu Gruppen; Besetzung in der Gliederung

**Datum:** 2026-10-02 (überarbeitet am selben Tag nach dem Befund aus der Umsetzung, Abschnitt 1.1)
**Status:** Entwurf
**Ersetzt:** Abschnitte 4 und 5 von `2026-10-01-register-statistik-besetzung-design.md` (Statistik nach
Untergruppe, Besetzungsübersicht). Abschnitt 3 dort (gemeinsame Soll-Menge, Aktivität am
Termindatum) gilt unverändert weiter.
**Anlass:** Praxistest des Zweigs `feat/register-statistik` am 2026-10-02
**Kippt teilweise:** `2026-09-16-untergruppen-gliederung-design.md`, Abschnitt 3.1 „Markierung statt
Hierarchie“ — Register bekommen Gruppen, **ohne** Vererbung (Abschnitt 2 unten)
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
Trompete …) —, und das System kennt die Verbindung zwischen beiden nicht.

Die Spec zu 1.8.0 hat eine Zuordnung Register → Gruppe bewusst weggelassen, mit der Begründung,
die Folgefragen stelle „heute niemand“. Der Praxistest stellt sie.

### 1.1 Befund aus der Umsetzung

Die erste Fassung dieser Spec ließ ein Register zu **genau einer** Gruppe gehören und nahm
Registermitglieder automatisch in diese Gruppe auf. Die Umstellung auf den Demodaten zeigte: Jedes
Register hat Mitglieder aus Aktiven **und** Jugend (Klarinetten 4 + 1, Flügelhorn 3 + 2, Trompete
4 + 2, Tenorhorn 3 + 2) — Jungmusiker spielen im Register mit. Mit genau einer Gruppe hätte der
Server Jugendliche in „Aktive“ aufgenommen. Deshalb gehört ein Register jetzt zu **mehreren**
Gruppen, und die Regel ist abgeschwächt (Abschnitte 3 und 4).

## 2 Entscheidungen (Nutzer, 2026-10-02)

| Frage | Entscheidung |
|---|---|
| Wie hängen Register und Gruppen zusammen? | **Ein Register gehört zu einer oder mehreren gewöhnlichen Gruppen, ohne Vererbung.** Erwartet wird weiterhin nur über die Terminart |
| Muss ein Registermitglied in einer Gruppe des Registers stehen? | **Ja, mindestens in einer.** Bei genau einer Gruppe ergänzt der Server sie; bei mehreren warnt er und speichert |
| Bestehende Register bei der Umstellung | **Nicht ableiten.** Alle Register stehen danach ohne Gruppe; der Admin ordnet zu |
| Mitglieder ohne „Namen sichtbar“ | **Keine Aufteilung nach Register**, nur der Gesamtbalken wie heute |
| Verwalter in der Check-in-App | **Dieselbe gegliederte Liste wie im Dashboard**, mit Namen |
| Zuklappen | **Nur in Rückmeldungslisten**, alle Abschnitte zugeklappt, „Alle aufklappen“; Anwesenheitslisten bleiben offen |

Verworfen: ein Schalter „nach Register einteilen“ an der Terminart (überdeckt das fehlende
Datenmodell nur), ein Schalter an der Gruppe ohne Zuordnung der Register, Register in der Terminart
gar nicht wählbar (nimmt die Registerprobe), genau eine Gruppe je Register (Abschnitt 1.1),
Ableiten der Gruppen bei der Umstellung (jedes Register enthält auch ein Vorstandsmitglied — keine
Zählregel trennt das von einem Jugendlichen).

## 3 Datenmodell

Neue Tabelle `subgroup_parents`:

```sql
CREATE TABLE IF NOT EXISTS `{PREFIX}subgroup_parents` (
  subgroup_id INT NOT NULL,
  group_id    INT NOT NULL,
  PRIMARY KEY (subgroup_id, group_id),
  FOREIGN KEY (subgroup_id) REFERENCES `{PREFIX}member_groups`(group_id) ON DELETE CASCADE,
  FOREIGN KEY (group_id)    REFERENCES `{PREFIX}member_groups`(group_id) ON DELETE CASCADE
)
```

- `subgroup_id` muss `is_subgroup = 1` haben, `group_id` muss `is_subgroup = 0` haben. Genau zwei
  Ebenen. Verstöße weist `member_groups` POST/PUT mit 400 ab.
- `is_subgroup` bleibt: Ein Register ohne Gruppe ist weiterhin eine Untergruppe — es gliedert in
  Listen, deren Terminart es direkt zugeordnet ist, rechnet aber nicht (Abschnitt 5).
- Eine Untergruppe, die zur gewöhnlichen Gruppe wird, verliert ihre Zuordnungen. Eine gewöhnliche
  Gruppe, die Register hat, darf nicht zur Untergruppe werden (400).
- API: `member_groups` liest und schreibt `parent_group_ids` (Liste von IDs).
- Begriffe in dieser Spec: **Register S**, seine **Gruppen P(S)**.

## 4 Mitgliedschaftsregel

Gilt auf dem Server für jeden Schreibweg der Zuordnungen — `members` POST und PUT
(`private/handlers/members.php`), CSV-Import (`private/handlers/import.php`) — und beim Ändern der
Gruppen eines Registers (`member_groups` POST/PUT):

1. **Prüfung je Register S in der Gruppenliste eines Mitglieds:** Steht das Mitglied in keiner
   Gruppe aus P(S), dann
   - bei **genau einer** Gruppe: Der Server ergänzt sie (`added_groups`);
   - bei **mehreren**: Der Server speichert unverändert und meldet eine Warnung
     (`group_warnings`, z. B. „steht in Klarinetten, aber in keiner seiner Gruppen: Aktive,
     Jugend“);
   - bei **keiner** (Register ohne Gruppe): nichts.
2. **Ändern der Gruppen eines Registers:** Für jedes Mitglied von S, das in keiner der neuen
   Gruppen steht, gilt dasselbe — genau eine Gruppe: ergänzen; mehrere: Warnung. Niemand wird
   aus einer Gruppe entfernt.
3. **Meldung statt Stille:** Die Antworten tragen `added_groups: [{member_id, group_id}]` und
   `group_warnings: [{member_id, subgroup_id}]` (genaue Form im Plan). Die Oberfläche zeigt beides
   als Hinweis.
4. **Dialog:** Wird ein Register angehakt, das genau eine Gruppe hat, hakt der Dialog die Gruppe mit
   an. Hat es mehrere und keine davon ist angehakt, zeigt er einen Hinweis neben dem Register.
   Wird eine Gruppe abgewählt, wählt der Dialog die Register ab, die danach in keiner ihrer
   Gruppen mehr angehakt wären. Bequemlichkeit — maßgeblich ist der Server.

## 5 Wo die Registersicht gilt

**Grundregel:** Die Registersicht gilt für einen Termin, wenn seine Terminart einer Gruppe
zugeordnet ist, die Register hat, oder direkt einem Register. Eine Vorstandssitzung erfüllt keins
von beiden (die Vorstandschaft hat keine Register).

### 5.1 Statistik

Tabelle eines Registers S mit mindestens einer Gruppe (ersetzt Spec 2026-10-01, Abschnitt 4.1 und
4.2):

- **Termine:** Paare der Soll-Menge mit Mitglied ∈ S, deren Termin über S oder über eine Gruppe aus
  P(S) kommt. Nicht mehr: Termine über andere Gruppen der Mitglieder (Vorstandssitzung) und über
  ein anderes Register (Registerprobe Flügelhorn bei einem Doppelspieler).
- **Spalten:** die Terminarten von S und von allen Gruppen aus P(S), sortiert wie bisher.
- **Zeilen:** die Mitglieder von S mit mindestens einem Paar im Bereich.
- **Register ohne Gruppe:** keine Tabelle.
- **Kopfzahlen, Pünktlichkeit, Zuverlässigkeit** mit Filter auf S: über denselben Bereich.
- **Antwort:** Jeder Eintrag in `statistics[]` trägt `parent_group_names` (Liste, leer bei
  gewöhnlichen Gruppen).
- **Unterzeile:** „<Oberbegriff> von <Gruppen>: Termine von <Gruppen> und eigene Termine“, Gruppen
  mit Komma verbunden, z. B. „Register von Aktive, Jugend: Termine von Aktive, Jugend und eigene
  Termine“. Ohne Beugung, weil Oberbegriff und Gruppennamen frei wählbar sind; die Unterzeile aus
  dem ersten Durchgang entfällt.

Umsetzung: `expectedPairsScopeSql()` verlangt für Untergruppen zusätzlich, dass `via_group_id`
gleich S ist oder in `subgroup_parents` zu S steht; `attendanceSubgroupTypes()` liefert die
Terminarten von S und P(S). Gewöhnliche Gruppen bleiben unverändert.

### 5.2 Gliederung in Rückmeldung und Anwesenheitsliste

- Als Abschnitte erscheinen nur Register, von denen mindestens eine Gruppe der Terminart zugeordnet
  ist, und ein direkt zugeordnetes Register. Ein Vorstandsmitglied, das Trompete spielt, erzeugt
  auf der Liste einer Vorstandssitzung keinen Abschnitt „Trompete“.
- Gibt es für einen Termin kein solches Register, wird die Stufe „Register“ nicht angeboten (folgt
  aus der vorhandenen Logik des Umschalters).
- Erwartete Mitglieder ohne passendes Register stehen im Sammelabschnitt „Ohne <Oberbegriff>“.
- Umsetzung: `groupsAttachToMembers()` hängt nur diese Register als `subgroups` an.

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
    anderer — genaue Felder im Plan).
  - Mitglieder mit „Namen sichtbar“: dieselbe Liste, nur Status.
  - Mitglieder ohne „Namen sichtbar“: nur der Gesamtbalken, keine Registerzeilen.
- **Anwesenheitslisten:** bleiben offen (Entscheidung aus 1.8.0), übernehmen aber 5.2.
- **Technik:** Kopfzeile als gemeinsame Funktion in `public/js/modules/grouping.js` für
  Verwalter-Tabelle und Namensliste; gleich gehaltene Fassung in `public/checkin/js/app.js`.
  Aktionen über `data-action` (CSP).

## 7 Gruppenverwaltung und Terminart

- **Gruppendialog:** Bei gesetztem Häkchen „Untergruppe“ erscheint „gehört zu“ als
  Mehrfachauswahl (Checkboxen) mit allen gewöhnlichen Gruppen; keine Auswahl heißt „ohne Gruppe“.
- **Gruppenliste:** Register eingerückt unter jeder ihrer Gruppen (ein Register mit zwei Gruppen
  steht zweimal, als Verweis); Register ohne Gruppe am Ende mit deutlichem Hinweis „ohne Gruppe —
  bitte zuordnen; bis dahin keine Registerstatistik“.
- **Terminart, Gruppenauswahl:** Register eingerückt unter ihren Gruppen (bei mehreren Gruppen
  unter der ersten, mit den übrigen als Unterzeile), Unterzeile „nur für eigene Termine, z. B.
  Registerprobe“. Aktive und Trompete zusammen bleiben erlaubt.

## 8 Umstellung bestehender Installationen

- Neue Funktion (eigene Datei unter `private/helpers/`, PHP-8.0-Syntax, weil der Update-Pfad sie
  lädt): `subgroupParentMigrate(PDO $pdo, string $prefix): array{log, warnings}`.
  1. Tabelle `subgroup_parents` anlegen, falls sie fehlt.
  2. **Nichts ableiten.** Für jedes Register ohne Gruppe eine Warnung „Register … ohne Gruppe —
     bitte in der Gruppenverwaltung zuordnen“.
- **Den Schritt in der Migrationskette legt die Release-Sitzung an** und ruft dort diese Funktion
  auf. Der Zweig liefert die Funktion mit Tests, `private/setup/ehrensache_db.sql` mit der neuen
  Tabelle und eine Notiz für die Release-Sitzung (OPEN-ITEMS und Abschlussbericht). Das
  Changelog nennt, dass Register nach dem Update einmal ihren Gruppen zugeordnet werden müssen.
- Testdatenbanken: Die Worktree-Kopie `ehrensache_reg` bekommt die Tabelle über die Funktion. Die
  Hauptdatenbank `ehrensache` braucht sie vor dem Merge — nur mit Freigabe des Nutzers.

## 9 Prüfung

- **Datenmodell:** Zuordnung nur Untergruppe → gewöhnliche Gruppe (400 sonst); Löschen einer Gruppe
  entfernt ihre Zuordnungen (CASCADE); Untergruppe → gewöhnlich entfernt Zuordnungen; Gruppe mit
  Registern → Untergruppe 400; Umstellungsfunktion: Tabelle anlegen, wiederholbar, Warnungen.
- **Mitgliedschaftsregel:** über `members` POST, PUT, CSV-Import und Ändern der Gruppen eines
  Registers — genau eine Gruppe: ergänzt + gemeldet; mehrere: unverändert + Warnung; keine:
  nichts.
- **Statistik:** Welt aus `statistics_subgroups_api` erweitern — gewöhnliche Gruppen G und J
  („Jugend“) und V („Vorstand“), Register R mit Gruppen G und J, Register R2 ohne Gruppe, Register
  R3 mit Gruppe G und eigener Registerprobe; ein Jugendmitglied in R (nur J, nicht G); C zusätzlich
  in V und R3. Geprüft: keine Vorstandsspalte in R, J-Termine zählen für das Jugendmitglied in R,
  R2 ohne Tabelle, Registerprobe von R3 zählt nicht in R, Pünktlichkeit und Zuverlässigkeit über
  denselben Bereich, `parent_group_names`.
- **Gliederung:** Stufe „Register“ fehlt bei einem Termin ohne Registersicht; nur Register der
  Gruppen der Terminart erscheinen; Sammelabschnitt.
- **Oberfläche:** Wächter für Kopfzeile, Klappen über `data-action`, „Alle aufklappen“, Filter
  klappt auf, kein Block „Besetzung“ mehr, Mehrfachauswahl im Gruppendialog.
- **Server:** Mitglied ohne „Namen sichtbar“ bekommt keine `members`; Verwalter bekommt `members` in
  `?upcoming=1`; `staffing` steht in keiner JSON-Antwort mehr.
- **Mutationsproben** an den neuen Bedingungen; **Gleichheitsprüfung** gegen die Momentaufnahme —
  für gewöhnliche Gruppen keine unerwartete Abweichung (die Umstellung ergänzt niemanden);
  **Sichtprüfung** Dashboard und App bei 320 px; Gesamtlauf und Klickdurchgang.

## 10 Doku

- `API.md`: `parent_group_ids` an `member_groups`, `added_groups`/`group_warnings` an `members`,
  `member_groups` und am Import, `staffing` entfällt, `members` für Verwalter in `?upcoming=1`,
  Registerbereich der Statistik, `parent_group_names`.
- `CHANGELOG.md` `[Unreleased]`: Einträge aus dem ersten Durchgang anpassen; Hinweis, dass Register
  nach dem Update einmal ihren Gruppen zugeordnet werden müssen.
- `docs/OPEN-ITEMS.md`: OI-70 präzisieren; der Nachtrag zu OI-97 entfällt (Spalten nur noch von S
  und P(S)); Notiz zur Migration für die Release-Sitzung.
- `docs/FEATURE-IDEAS.md` FI-14, `docs/testplan.md` Abschnitt 26.
- `docs/project_history.md`: Eintrag „Register gehören zu Gruppen, ohne Vererbung“ — die
  Entscheidung „Markierung statt Hierarchie“ aus 1.8.0 ist damit teilweise gekippt, mit Anlass und
  dem Befund aus 1.1.
