# Gruppenzugehörigkeit mit Zeitraum (OI-115)

**Stand:** 2026-10-05 · **Status:** abgestimmt mit dem Nutzer, noch nicht umgesetzt
**Bezug:** [OI-115](../../OPEN-ITEMS.md#oi-115--gruppenzugehörigkeit-ohne-zeitachse),
Vorläufer `2026-10-01-register-statistik-besetzung-design.md` (Abschnitt 4.5) und
`2026-10-02-register-gruppe-besetzung-design.md`

## 1. Problem

`member_group_assignments` kennt keinen Zeitraum. Ausgewertet wird immer die **heutige**
Zuordnung, auch für vergangene Termine. Wer im Juni von „Jugend“ zu „Aktive“ wechselt,

- zählt für die Aktive-Proben von Januar bis Mai als unentschuldigt gefehlt,
- verschwindet mit seinen Jugendproben aus der Statistik der Jugend,
- steht in Anwesenheitsliste und Rückmeldung vergangener Termine in der falschen Gruppe.

Das ist dieselbe Fehlerklasse wie OI-114 (Aktivität am Termindatum), nur für die Gruppe. Seit
1.20.0 gilt die Aktivität je Termindatum; für die Gruppenzugehörigkeit fehlt das Gegenstück.

## 2. Entscheidungen des Nutzers (2026-10-05)

| # | Frage | Entscheidung |
|---|---|---|
| E1 | Pflege eines Wechsels | **Datum beim Speichern:** Häkchen bleiben, beim Bearbeiten erscheint „Änderung gilt ab“ (Vorgabe heute); nur lesbarer Verlauf, keine Bearbeitung des Verlaufs |
| E2 | Import bei bestehenden Mitgliedern | **Vergleichen, gültig ab Importtag;** kein Datumsfeld, keine CSV-Spalte |
| E3 | Datum in der Zukunft | **Nicht erlaubt** — nur heute oder Vergangenheit |
| E4 | Technischer Weg | **Heutiger Stand bleibt in `member_group_assignments`, beendete Zuordnungen in eigener Verlaufstabelle** |
| E5 | Statistik für Rolle `user` | Ehemalige Gruppen sind sichtbar (nur die eigene Zeile, wie bisher) |

Grundregel ohne eigene Frage: **Ein neu angelegtes Mitglied bekommt Zuordnungen „von Anfang an“**
(`valid_from` NULL); begrenzt wird es allein durch die Aktiv-Zeiträume. Sonst stünde ein heute
nachgetragenes Mitglied mit Eintritt 2020 vor heute in keiner Gruppe.

## 3. Datenmodell

### 3.1 Schema

```sql
-- bestehende Tabelle, eine Spalte mehr
ALTER TABLE {PREFIX}member_group_assignments
    ADD COLUMN valid_from DATE NULL;           -- NULL = von Anfang an

-- neu: beendete Zuordnungen
CREATE TABLE {PREFIX}member_group_history (
    history_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    member_id  INT NOT NULL,
    group_id   INT NOT NULL,
    valid_from DATE NULL,                      -- NULL = von Anfang an
    valid_to   DATE NOT NULL,                  -- letzter Tag der Zugehörigkeit
    KEY {PREFIX}idx_mgh_member (member_id),
    KEY {PREFIX}idx_mgh_group_to (group_id, valid_to),
    FOREIGN KEY (member_id) REFERENCES {PREFIX}members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (group_id)  REFERENCES {PREFIX}member_groups(group_id) ON DELETE CASCADE
);
```

Primärschlüssel und Fremdschlüssel von `member_group_assignments` bleiben unverändert. Eine Zeile
dort bedeutet weiterhin „gehört heute dazu“.

**Invariante:** Je Mitglied und Gruppe überlappen sich die Zeiträume aus beiden Tabellen nicht.
Ein Verlaufseintrag endet immer vor dem `valid_from` einer heutigen Zuordnung derselben Gruppe.

### 3.2 Umstellung bestehender Installationen

`groupHistoryMigrate(PDO $pdo, string $prefix): array{log, warnings}` in
`private/helpers/group_history.php` (PHP-8.0-Syntax, Eintrag in
`tests/suites/update_path_syntax.php`):

- fügt `valid_from` hinzu, falls sie fehlt (`information_schema.COLUMNS`),
- legt `member_group_history` an, falls sie fehlt,
- ist wiederholbar und verändert keine Daten: Alle bestehenden Zuordnungen behalten
  `valid_from` NULL. Bis zum ersten Wechsel ist das Verhalten identisch zu vorher.

Den Schritt in `private/migrations/` legt die Release-Sitzung an (Regel für parallele Sitzungen).
Der Zweig zieht `private/setup/ehrensache_db.sql` nach und liefert
`tests/db/apply_group_history.php` für Testdatenbanken.

## 4. Schreiben

### 4.1 Ein Baustein für alle Schreibwege

Aufgeteilt in einen reinen Planer und einen Ausführer (beide in `private/helpers/group_history.php`):

- `groupsPlanChange(array $current, array $history, array $newGroupIds, ?string $date): array` —
  rechnet ohne Datenbank aus, welche Zeilen anzulegen, zu löschen und zu kürzen sind
  (Unit-Test ohne Datenbank).
- `groupsApplyChange(PDO $db, $database, int $memberId, array $newGroupIds, ?string $date): bool` —
  liest den Stand, ruft den Planer, schreibt; Rückgabe: ob sich etwas geändert hat.
 `$newGroupIds` ist bereits durch `groupsWithParents()`
(Mitgliedschaftsregel) gelaufen. `$date` NULL bedeutet „von Anfang an“ und ist nur beim Anlegen
erlaubt. Je Gruppe gilt:

| Fall | Wirkung |
|---|---|
| unverändert (vorher und nachher angehakt) | Zeile bleibt unberührt, `valid_from` ändert sich nicht |
| entfernt, `valid_from` NULL oder `< $date` | Verlaufseintrag `(valid_from, $date − 1 Tag)`, Zeile in `member_group_assignments` gelöscht |
| entfernt, `valid_from >= $date` | **Korrektur:** Zeile gelöscht, **kein** Verlaufseintrag (die Zuordnung hat nie gegolten) |
| hinzugefügt | neue Zeile mit `valid_from = $date`; Verlaufseinträge derselben Gruppe mit `valid_to >= $date` werden auf `$date − 1 Tag` gekürzt, ein dadurch leerer Eintrag (`valid_to < valid_from`) wird gelöscht |

**Kürzung des Verlaufs (`groupHistoryTrim()`):** Beim Hinzufügen **und** beim Entfernen werden
Verlaufseinträge derselben Gruppe mit `valid_to >= $date` auf `$date − 1 Tag` gekürzt bzw.
gelöscht, wenn sie erst ab `$date` beginnen. Sonst bliebe nach Grenzfall 7.1 und einer späteren
rückwirkenden Entfernung ein Verlaufseintrag stehen, der über das neue Ende hinausreicht
(Review-Befund 2026-10-05). `$date` null mit einer Entfernung ist ein Programmierfehler
(`InvalidArgumentException`). `groups_valid_from` verlangt zusätzlich ein Jahr ab 1000.

Durch die Mitgliedschaftsregel ergänzte Gruppen bekommen dasselbe `$date`. Ergänzt
`groupsApplySubgroupRule()` (Register bekommt eine Gruppe) Mitglieder, übernimmt die neue
Zuordnung das `valid_from` der Registerzuordnung. Alles läuft in einer
Transaktion mit dem übrigen Speichern des Mitglieds.

### 4.2 Wer welches Datum setzt

| Schreibweg | `$date` |
|---|---|
| Mitglied anlegen (Dialog, `POST members`) | NULL |
| Mitglied bearbeiten (Dialog, `PUT members`) | `groups_valid_from` aus dem Request, fehlt es: heute |
| CSV-Import, neues Mitglied | NULL |
| CSV-Import, bestehendes Mitglied | heute; **Vergleich statt Löschen** (heute: `DELETE` aller Zuordnungen, siehe `private/handlers/import.php`); die Antwort zählt in `group_changes`, bei wie vielen bestehenden Mitgliedern sich Gruppen geändert haben |

`groups_valid_from` muss ein gültiges Datum `YYYY-MM-DD` sein und darf nicht nach heute liegen,
sonst 422 mit Fehlermeldung. Ohne Änderung an den Gruppen wird es ignoriert.

### 4.3 Löschen

Mitglied oder Gruppe löschen entfernt die Verlaufszeilen über `ON DELETE CASCADE`. Die Statistik
vergangener Jahre verliert damit eine gelöschte Gruppe — wie heute schon.

## 5. Lesen

### 5.1 Baustein

In `private/helpers/group_history.php`:

- `groupAssignmentsSql($database): string` — Teilabfrage mit den Spalten
  `member_id, group_id, valid_from, valid_to`, als `UNION ALL` aus `member_group_assignments`
  (`valid_to` NULL) und `member_group_history`.
- `groupAssignmentActiveOn(string $alias, string $dateExpr): string` — die einzige Stelle mit der
  Bedingung `({a}.valid_from IS NULL OR {a}.valid_from <= {d}) AND ({a}.valid_to IS NULL OR {a}.valid_to >= {d})`.

### 5.2 Mit Stichtag Termindatum

| Stelle | Wirkung |
|---|---|
| `expectedPairsSql()` und Register-Zweig `expectedPairsScopeSql()` (`expected_pairs.php`) | Statistik, Druckbericht, Kalender, Kopfzahlen: bis zum Wechsel in der alten, danach in der neuen Gruppe |
| `responsesFetchExpected()` (`responses.php`) | Rückmeldedialog und Druck eines Termins |
| `attendance_list.php`, Modus je Termin | Anwesenheitsliste vergangener Termine |
| `attendance_list.php`, Modus je Mitglied | Termine des Jahres, zu denen das Mitglied am Termindatum über eine Gruppe gehörte — auch wenn es heute in keiner Gruppe mehr steht (bisher Abbruch ohne Termine) |
| `attendanceActiveMemberCount()` (`attendance.php`) | Mitgliederzahl einer Gruppe im Jahr: Zeitraum überschneidet das Jahr **und** aktiv |

### 5.3 Bleiben beim heutigen Stand

Kommende Termine in Rückmeldung und App (`responsesFetchUpcomingIds()`,
`responsesFetchUpcomingInfo()` — ohne Zukunftsdaten ist der heutige Stand dort richtig), Check-in
(`auto_checkin.php`), Station, Zeiterfassung (`worktime.php`, `work_sessions.php`), Export,
Gruppenanzeige im Mitgliederdialog, Kopfzeile des Mitgliedsmodus in `attendance_list.php`
(Gruppennamen von heute), Registerzuordnung der Anwesenheitsliste (`groupsAttachToMembers()`),
Terminregeln
(`appointment_rules.php`).

### 5.4 Statistikzugriff der Rolle `user` (E5)

`getStatisticsGroups()` und `hasStatisticsGroupAccess()` (`statistics.php`) berücksichtigen
heutige **und** ehemalige Gruppen. Sichtbar ist dort wie bisher nur die eigene Zeile.

### 5.5 Laufzeit

Die Vereinigung wird in jeder Soll-Menge mitgerechnet. Messung gegen den Demo-Bestand vor und
nach der Änderung (Statistik, Rückmeldedialog, Anwesenheitsliste); Erwartung: im Rahmen der
bisherigen 95–181 ms.

## 6. Oberfläche

### 6.1 Mitgliederdialog, Bearbeiten

- Feld **„Änderung gilt ab“** (`<input type="date">`, Vorgabe und `max` heute) unter der
  Gruppenliste. Sichtbar **nur**, wenn sich mindestens ein Häkchen gegenüber dem geladenen Stand
  unterscheidet; wird wieder ausgeblendet, sobald alles zurückgesetzt ist. Beim Anlegen nie.
- Vorschau darunter, z. B. „Jugend endet am 31.05.2026 · Aktive ab 01.06.2026“, berechnet aus
  geladenem und aktuellem Stand und dem Datum.
- Verlauf: Gibt es Verlaufseinträge oder heutige Zuordnungen mit `valid_from`, zeigen die
  Häkchen den Zusatz „seit TT.MM.JJJJ“ und darunter steht eine nur lesbare Zeile
  „Bisher: Jugend bis 31.05.2026, …“. Sonst bleibt der Dialog wie heute.
- Daten aus `GET members?id=`: die Einträge in `groups` bekommen `valid_from` (Datum oder null),
  neu ist `group_history` (Liste `{group_id, group_name, valid_from, valid_to}`, neueste zuerst).
- Aktionen über `data-action`/`registerActions()` (CSP), keine Inline-Handler, Maskierung mit
  `escapeHtml`.
- Cache: `saveMember()` verwirft schon heute `appointments` sowie über
  `invalidateMemberDependents()` `members`, `records` und `exceptions` **aller** Jahre — das deckt
  rückwirkende Änderungen ab. Ein Wächtertest hält das fest. Die Statistik hat keinen
  Cache-Schlüssel und lädt ohnehin neu.

### 6.2 Import

Dialog unverändert. Hatte ein bestehendes Mitglied neue oder entfallene Gruppen, nennt die
Ergebnisanzeige „Gruppenänderungen gelten ab heute“.

### 6.3 Statistik, Anwesenheit, Rückmeldung, App

Keine neuen Bedienelemente. Wer in einem Jahr gewechselt hat, steht in beiden Gruppentabellen
dieses Jahres, jeweils mit den Terminen seines Zeitraums.

## 7. Grenzfälle

1. **Am selben Tag hin und zurück:** Häkchen weg (speichern), wieder dran (speichern), beide
   Male Datum heute → die erste Speicherung schreibt einen Verlaufseintrag bis gestern, die
   zweite kürzt ihn nicht (endet vor heute) und legt die Zuordnung ab heute neu an. Ergebnis:
   Lücke von null Tagen, Verlaufseintrag + heutige Zuordnung ab heute. Fachlich korrekt
   (durchgehende Zugehörigkeit), Anzeige „seit heute“. *Bewusst akzeptiert;* kein Zusammenführen.
2. **Rückwirkende Korrektur einer neuen Zuordnung:** Gruppe am 10.05. ergänzt, am 20.05. mit
   Datum 01.05. entfernt → Korrektur ohne Verlaufseintrag (4.1, dritte Zeile).
3. **Austritt während der Gruppenzugehörigkeit:** Gruppenzeitraum und Aktiv-Zeiträume werden
   geschnitten; gezählt wird nur, wo beide gelten.
4. **Register:** `expectedPairsScopeSql()` prüft die Registerzugehörigkeit am Termindatum, die
   Zuordnung Register → Gruppe (`subgroup_parents`) bleibt ohne Zeitraum.
5. **Demo-Generator:** schreibt keinen Verlauf; `member_group_history` steht in `DEMO_TABLES`
   (vor `member_groups` geleert). `DEMO_MIN_SCHEMA` hebt die Release-Sitzung an.

## 8. Tests

- `tests/suites/group_history_unit.php` — `groupsPlanChange()` ohne Datenbank: Entfernen
  mit Verlauf, Korrektur ohne Verlauf, Wiederhinzufügen kürzt Überlappung, Unverändertes bleibt,
  Regel setzt dasselbe Datum, Grenzfall 7.1.
- `tests/suites/group_history_api.php` — `PUT members` mit `groups_valid_from` (200; 422 bei
  Zukunft und ungültigem Format), `GET members?id=` mit `groups[].valid_from`/`group_history`, Import
  vergleicht statt zu löschen, neues Mitglied mit NULL, Statistikzugriff auf ehemalige Gruppe.
- Statistik/Rückmeldung/Anwesenheit mit Wechsel zum 01.06.: Mitglied in beiden Tabellen, A zählt
  nur Termine bis 31.05., B nur ab 01.06., keine unentschuldigten B-Termine vor dem Wechsel;
  Rückmeldung und Anwesenheitsliste eines Märztermins zeigen es unter A.
- Gegenprobe ohne Verlauf: `tests/db/verify_statistics_parity.php` gegen die Hauptdatenbank,
  identische Zahlen.
- Frontend-Wächter (`group_history_frontend.php`): Datumsfeld nur beim Bearbeiten und nur bei
  geänderten Häkchen, `max` heute, `saveMember()` verwirft weiter alle Jahre, keine Inline-Handler.
- Mutationsproben je Kernregel (4.1 vier Fälle, 5.1 Bedingung, 5.4 Zugriff).
- `tests/db/verify_schema_convergence.php` ist bis zum Migrationsschritt der Release-Sitzung rot
  (erwartet, wie bei 1.20.0).

## 9. Lieferung

- Zweig `feat/gruppen-zeitraum`, Worktree unter `C:\xampp\htdocs\EhrenSache-zeitraum`,
  Datenbankkopie `ehrensache_zr`.
- Kein Versionssprung, kein Migrationsschritt, kein `?v=`-Sprung; Changelog unter
  `## [Unreleased]`.
- Doku: `API.md` (`members`: `groups_valid_from`, `groups[].valid_from`, `group_history`; `import`: `group_changes`), Testplan
  (neuer Abschnitt), OPEN-ITEMS (OI-115 erledigt, neuer Eintrag für den Migrationsschritt),
  `project_history.md` (Gruppenzugehörigkeit bekommt eine Zeitachse).
- Hauptdatenbank `ehrensache` braucht Spalte und Tabelle vor dem Merge nach `dev` — nur mit
  Freigabe des Nutzers, per `tests/db/apply_group_history.php`.

## 10. Nicht Teil dieses Vorhabens

- Bearbeiten oder Löschen einzelner Verlaufseinträge (verworfen, E1).
- Geplante Wechsel in der Zukunft (verworfen, E3).
- Datumsfeld oder CSV-Spalte im Import (verworfen, E2).
- Zeitraum für die Zuordnung Register → Gruppe (`subgroup_parents`).
