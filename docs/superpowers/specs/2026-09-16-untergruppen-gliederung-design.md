# Untergruppen: Listen nach Register, Mannschaft oder Stimme gliedern

**Datum:** 2026-09-16
**Status:** Entwurf
**Setzt um:** [FI-14](../../FEATURE-IDEAS.md#fi-14--untergruppen-register-und-besetzungsübersicht)
teilweise — Variante B (Gruppenart), **ohne** Besetzungsübersicht
**Baut auf:** `2026-09-14-terminrueckmeldung-design.md` (Namenslisten je Termin, 1.7.0)
**Zielversion:** **1.8.0**, Migration `1.7.0.php` (1.7.0 → 1.8.0)

---

## 1 Ausgangslage

Mitglieder stehen in mehreren Gruppen — `member_group_assignments` ist eine M:N-Tabelle. Im
Verein des Auftraggebers sind das zwei verschiedene Dinge: die Zugehörigkeit (Aktive, Jugend),
an der die Terminarten hängen, und das Register (Klarinette, Trompete, Flügelhorn, Tenorhorn).

Die Listen zeigen davon nur das Erste, und zwar aus einem handfesten Grund:

- [`attendance_list.php`](../../../private/handlers/attendance_list.php) sammelt die Gruppennamen
  mit `GROUP_CONCAT(...)` über einen Join, den `WHERE mga.group_id IN (<Gruppen der Terminart>)`
  einschränkt. Die Register fallen damit schon in der Abfrage heraus.
- Die PWA gruppiert danach mit der **ganzen Zeichenkette** als Schlüssel
  ([`app.js`](../../../public/checkin/js/app.js), `renderAttendanceList()`). Haben alle
  „Aktive“, entsteht genau ein Abschnitt.
- Die Anwesenheitsliste im Dashboard
  ([`records.js`](../../../public/js/modules/records.js), `renderAttendanceList()`) gruppiert
  gar nicht, sie sortiert nach Nachname.
- Die Namenslisten der Terminrückmeldung nehmen die Gruppe aus
  `appointment_type_groups` ([`responses.php`](../../../private/helpers/responses.php),
  `responsesFetchExpected()`) — also wieder Aktive und Jugend.

Register lassen sich heute also anlegen und zuordnen, aber nirgends sehen. Für die Frage, die
beim Abhaken einer Anwesenheitsliste und vor einem Auftritt zählt — wer fehlt im Register? — ist
die Liste damit unbrauchbar.

---

## 2 Ziel

- Eine Gruppe kann als **Untergruppe** markiert werden. Sie gliedert Listen, ohne sonst ihre
  Bedeutung zu ändern.
- Anwesenheitslisten (Dashboard und PWA) und die Namenslisten der Terminrückmeldung lassen sich
  zwischen **alphabetisch**, **nach Gruppe** und **nach Untergruppe** umschalten.
- Die Reihenfolge der Abschnitte ist pflegbar — Partiturreihenfolge statt Alphabet.
- Der Oberbegriff („Untergruppe“) ist frei wählbar, damit ein Musikverein „Register“ und ein
  Sportverein „Mannschaft“ lesen kann.
- Ohne einen einzigen gesetzten Haken verhält sich die Anwendung wie bisher.

---

## 3 Entscheidungen

### 3.1 Markierung statt Hierarchie (FI-14 Variante B)

`member_groups` bekommt ein Ja/Nein-Feld, keine `parent_group_id`. Eine Untergruppe bleibt eine
gewöhnliche Gruppe: sie kann einer Terminart zugeordnet werden, taucht in Filtern und
Statistiken auf, die Sichtbarkeitsprüfungen ändern sich nicht. Die Markierung steuert
ausschließlich, ob die Gruppe als Gliederungsebene angeboten wird.

Damit entfallen die Folgefragen der echten Hierarchie (Vererbung von Terminarten, Sichtbarkeit
über Ebenen, Statistik über Ober- und Untergruppe), die FI-14 aufzählt und die heute niemand
stellt.

Ein Ja/Nein statt der in FI-14 angedachten `group_kind`-Aufzählung: mehr als zwei Werte braucht
niemand, und ein Häkchen ist ehrlicher zu bedienen als eine Auswahlliste mit zwei Einträgen.

### 3.2 Mehrfachzugehörigkeit wird gezeigt, nicht aufgelöst

Wer Klarinette **und** Saxophon spielt, erscheint in beiden Abschnitten. Die Alternativen — ein
Hauptregister je Mitglied, stilles Einsortieren in das erste Register, ein Sammelabschnitt
„Mehrere“ — kosten entweder Pflege oder verschweigen jemanden dort, wo er gesucht wird. Der
Registerführer Saxophon soll den Doppelspieler sehen.

Folge: Die Summe der Abschnitte ist größer als die Mitgliederzahl. Das wird ausgewiesen (6.4),
nicht kaschiert. Die entdoppelten Kennzahlen der Rückmeldung (`responseSummary()`) bleiben
unberührt.

### 3.3 Der Oberbegriff ist eine Einstellung

„Register“ ist Musikvereinssprache. Das Wort steht in `system_settings` unter `subgroup_label`,
Vorgabe `Untergruppe`, und wird in Überschriften, Umschalter und Verwaltung eingesetzt. In den
Abschnittsüberschriften selbst steht ohnehin der Gruppenname („Klarinette“); der Oberbegriff
erscheint nur im Umschalter, im Sammelabschnitt „Ohne <Wort>“ und in der Verwaltung.

### 3.4 Drei Stufen, keine Verschachtelung

Der Umschalter kennt **Alphabetisch · Gruppe · Untergruppe**. Die Stufe „Gruppe“ erhält, was die
PWA-Anwesenheitsliste heute tut (Trennung nach Aktive/Jugend bei einem Termin für beide), statt
sie zu ersetzen. Zwei verschachtelte Ebenen („Aktive › Klarinette“) wurden verworfen: auf dem
Telefon wird das unübersichtlich, und bei einem Termin für nur eine Gruppe ist die obere Ebene
leer.

### 3.5 Gruppiert wird in der Oberfläche

Der Server liefert je Mitglied seine Zugehörigkeiten; Abschnitte baut die Oberfläche. So
wechselt der Umschalter ohne Serveraufruf, und die Doppelnennung entsteht beim Anzeigen statt in
einer Antwort, die dasselbe Mitglied mehrfach führt.

Die Regeln, die Fachlogik sind — welche Gruppen zählen, wie wird sortiert, wie heißt der
Oberbegriff —, bleiben in PHP und sind dort testbar (7). Das Aufteilen einer flachen Liste in
Abschnitte ist wenig Code und steht zweimal: einmal im Dashboard-Modul, einmal in der PWA. Das
ist dasselbe Muster, das 1.7.0 für die Rückmeldungen gewählt hat — die PWA ist ein klassisches
Skript ohne Modulsystem.

---

## 4 Datenmodell

### 4.1 Zwei Spalten an `member_groups`

| Spalte | Typ | Bedeutung |
|---|---|---|
| `is_subgroup` | `TINYINT(1) NOT NULL DEFAULT 0` | Gruppe gliedert Listen |
| `sort_order` | `INT NOT NULL DEFAULT 0` | Reihenfolge; bei Gleichstand entscheidet `group_name` |

`sort_order` gilt für alle Gruppen, nicht nur für Untergruppen — eine zweite Sortierregel für
dieselbe Tabelle wäre schwer zu erklären. Ohne Pflege stehen alle auf 0, und es bleibt
alphabetisch.

### 4.2 Eine Einstellung

`system_settings`: `subgroup_label`, Vorgabe `Untergruppe`, per `INSERT IGNORE` gesetzt.

### 4.3 Migration

`private/migrations/1.7.0.php` mit `migrate_1_7_0()`, Manifest-Eintrag 1.7.0 → 1.8.0. Beide
Spalten idempotent ergänzen (Muster aus `1.6.1.php`: `information_schema` prüfen, dann `ALTER`),
die Einstellung per `INSERT IGNORE`. `private/setup/ehrensache_db.sql` wird nachgezogen; die
Schemakonvergenz-Prüfung hält beide Wege zusammen.

Keine Datenänderung an bestehenden Gruppen: nach der Migration ist keine Gruppe eine
Untergruppe, und jede Liste sieht aus wie vorher.

---

## 5 API

### 5.1 `attendance_list`

Je Mitglied treten zwei Listen an die Stelle der heutigen Zeichenkette `groups`:

```json
{
  "member_id": 42,
  "surname": "Muster", "name": "Anna",
  "groups":    [{ "group_id": 2, "group_name": "Aktive",     "sort_order": 0 }],
  "subgroups": [{ "group_id": 9, "group_name": "Klarinette", "sort_order": 20 },
                { "group_id": 11, "group_name": "Saxophon",  "sort_order": 30 }]
}
```

- `groups`: die Gruppen des Mitglieds, **die zum Termin gehören** — dieselbe Bedeutung wie die
  bisherige Zeichenkette, nur strukturiert.
- `subgroups`: **alle** als Untergruppe markierten Gruppen des Mitglieds, unabhängig vom Termin.
  Eigene, ungefilterte Abfrage — genau hier fehlten die Register bisher.

Die Zeichenkette `groups` entfällt. Einziger Verbraucher ist die PWA-Anwesenheitsliste, die in
diesem Vorhaben ohnehin neu gebaut wird.

### 5.2 `appointment_responses`

Die erwarteten Mitglieder (`responsesFetchExpected()`) tragen dieselben zwei Felder. Sie
unterliegen denselben Sichtbarkeitsregeln wie die Namen selbst: Mitglieder sehen sie nur, wenn
die Terminart `responses_names_visible` gesetzt hat, Admin und Manager immer. Ohne Namen keine
Zugehörigkeiten.

`responsesDedupeExpected()` bleibt, wie es ist: ein Mitglied kommt in der Antwort genau einmal
vor, mit beiden Listen.

### 5.3 `settings`

Neuer Schlüssel `subgroup_label`. Beim Schreiben normalisiert und geprüft (6.6): getrimmt,
höchstens 30 Zeichen, leer bedeutet Vorgabe. Ein zu langer Wert wird mit `400` abgewiesen — wie
`response_deadline_hours` es vormacht.

---

## 6 Oberfläche

### 6.1 Der Umschalter

Eine Segmentleiste über der Liste mit drei Stufen: **Alphabetisch · Gruppe · <Untergruppe>**, die
dritte beschriftet mit dem eingestellten Wort. Er erscheint an vier Stellen:

| Ort | heute | mit dieser Änderung |
|---|---|---|
| Dashboard, Anwesenheit → Termin → Anwesenheitsliste | flach nach Nachname | Umschalter, Vorgabe „Untergruppe“, sonst alphabetisch |
| PWA, Termine-Tab → Anwesenheitsliste | ein Abschnitt je Gruppen-Zeichenkette | Umschalter, Vorgabe „Untergruppe“, sonst „Gruppe“ |
| Dashboard, Rückmeldungs-Modal, „Wer hat geantwortet?“ | nach Terminart-Gruppe | Umschalter, Vorgabe „Untergruppe“, sonst „Gruppe“ |
| PWA, Termine-Tab, „Wer hat geantwortet?“ | nach Terminart-Gruppe | Umschalter, Vorgabe „Untergruppe“, sonst „Gruppe“ |

Die Stufe „Untergruppe“ wird nur angeboten, wenn mindestens ein Mitglied der gezeigten Liste
einer Untergruppe angehört. Sonst hat der Umschalter zwei Stufen.

### 6.2 Vorgabe und Gedächtnis

Gibt es Untergruppen in der Liste, steht der Umschalter auf „Untergruppe“. Sonst bleibt es beim
heutigen Bild (Tabelle in 6.1). Die Wahl wird im Browser gemerkt, je Listenart, nicht je Termin:
`es_grouping_attendance` und `es_grouping_responses`, Werte `alpha`, `group`, `subgroup`.

Das Dashboard nutzt `localStorage` bisher nur für den Redirect-Schutz; diese Ausweitung ist
bewusst und bleibt auf die zwei Anzeigeschlüssel beschränkt. Ein unlesbarer oder unbekannter
Wert wird wie „nicht gesetzt“ behandelt, jeder Zugriff ist in `try/catch` gefasst.

### 6.3 Die Abschnitte

Überschrift ist der Gruppenname mit der Anzahl darin. Reihenfolge: `sort_order`, bei Gleichstand
`group_name`. Wer in keiner Untergruppe steht, landet im Abschnitt „Ohne <Wort>“ am Ende.
Innerhalb eines Abschnitts wird wie bisher nach Nachname, Vorname sortiert.

Im Dashboard ist die Anwesenheitsliste eine Tabelle: der Abschnittskopf wird eine Zeile über die
volle Breite — dasselbe Muster, das die Rückmeldungs-Tabelle mit `response-group-row` schon
verwendet.

Die Abschnitte bleiben aufgeklappt. Beim Abhaken darf niemand hinter einem zugeklappten Titel
verschwinden; das unterscheidet diese Listen von den Detailbereichen der PWA aus 1.7.0.

### 6.4 Doppelnennung sichtbar machen

Über den Abschnitten steht weiterhin die echte Kopfzahl. Erscheint mindestens ein Mitglied
mehrfach, kommt eine Zeile dazu: „3 Mitglieder stehen in mehreren Abschnitten.“ Die Regel gilt
für jede Stufe, nicht nur für Untergruppen — auch „Gruppe“ zeigt jemanden zweimal, der bei einem
Termin für Aktive und Jugend in beiden steht. Die Ampel-Summen der Rückmeldung bleiben
unberührt; sie kommen entdoppelt vom Server.

### 6.5 Altlast in der PWA

`renderAttendanceList()` setzt Namen heute unescaped in das HTML. Ohne CSP ist Escaping im
Projekt Pflicht. Da diese Darstellung neu gebaut wird, laufen Namen und Gruppennamen dort durch
`escapeHtml()` — kein eigenes Vorhaben, sondern die Stelle, an der ohnehin gearbeitet wird.

### 6.6 Verwaltung und Einstellungen

Der Gruppendialog bekommt zwei Felder: ein Häkchen „Gliedert Listen als <Wort>“ und ein
Zahlenfeld „Reihenfolge“ mit dem Hinweis, dass 0 alphabetisch bedeutet. Die Gruppenliste zeigt
beides und sortiert nach `sort_order` — die gepflegte Reihenfolge ist dort zu sehen, wo sie
eingestellt wird.

In den Einstellungen: ein Textfeld „Bezeichnung der Untergruppen“, Vorgabe „Untergruppe“, mit
Vorschlägen per Datalist (Register, Mannschaft, Stimme, Abteilung, Altersklasse). Der Wert wird
beim Anzeigen escaped.

---

## 7 Tests

Die Fachregeln wandern in einen neuen Helfer `private/helpers/groups.php`, damit sie nicht in
Abfragen verschwinden:

- `groupSubgroupLabel(?string $raw): string` — trimmen, Steuerzeichen entfernen, auf 30 Zeichen
  begrenzen, leer → `Untergruppe`.
- `groupSortCompare(array $a, array $b): int` — `sort_order`, dann `group_name`.
- `groupsSortForDisplay(array $groups): array`.

**Unit** (`tests/suites/groups_unit.php`): der Helfer, einschließlich leer, nur Leerzeichen,
überlang, HTML im Wort, gleiche `sort_order`, negative Werte.

**API** (Erweiterung von `attendance_list`- und `responses_api`-Prüfungen):

- eine Untergruppe, die **nicht** zur Terminart gehört, steht in `subgroups` — der eigentliche
  Fehler dieses Vorhabens;
- ein Mitglied in zwei Untergruppen bekommt beide, und kommt trotzdem nur einmal in der Antwort vor;
- eine nicht markierte Gruppe taucht in `subgroups` nicht auf;
- `appointment_responses` liefert die Felder nur mit Namensfreigabe bzw. für Verwalter;
- `settings` weist ein zu langes Wort mit `400` ab und macht aus Leerraum die Vorgabe.

**Migration:** Kettenprüfung, zweimaliges Ausführen, Schemakonvergenz gegen `ehrensache_db.sql`,
Updater-Durchstich.

**Frontend statisch** (Muster des Projekts): Umschalter in `index.html` und im PWA-Markup, die
drei Stufen benannt, `escapeHtml` in den neuen Renderpfaden, Speicherschlüssel gesetzt.

**Manueller Testplan** (`docs/testplan.md`, neuer Abschnitt): ohne Untergruppen unverändert;
Doppelspieler steht in zwei Abschnitten und die Hinweiszeile erscheint; `sort_order` ändert die
Reihenfolge; geändertes Wort schlägt in Umschalter, Sammelabschnitt und Verwaltung durch;
Umschalterwahl überlebt das Neuladen; Mitglied ohne Untergruppe landet im Sammelabschnitt.

Dass statische Prüfungen Laufzeitfehler in der Oberfläche nicht fangen, hat 1.7.0 gezeigt — die
manuellen Fälle sind deshalb Teil der Umsetzung, nicht Beiwerk.

---

## 8 Dokumentation und Demo

- `API.md`: `attendance_list` und `appointment_responses` mit den beiden Listen, neuer
  Einstellungsschlüssel `subgroup_label`.
- `CHANGELOG.md` und `version.json` auf **1.8.0**, `?v=` an den Assets nachziehen.
- `FEATURE-IDEAS.md`: FI-14 auf „teilweise umgesetzt“ setzen und auf das Verbliebene eindampfen
  (Besetzungsübersicht mit Sollstärke).
- `CLAUDE.md`: neuer Helfer in der Projektstruktur.
- **Demo-Generator:** Register anlegen (Flöte, Klarinette, Trompete, Tenorhorn, Schlagzeug),
  Mitglieder darauf verteilen — ein Teil bewusst in zwei Registern —, `sort_order` in
  Partiturreihenfolge setzen. `DEMO_MIN_SCHEMA` auf 1.8.0. Der Demo-Server steht ohnehin noch
  auf dem Stand vor 1.7.0; beide Schritte fallen zusammen.
- `DATENSCHUTZ.md` bleibt unberührt: Gruppenzugehörigkeit ist bereits erfasst, es kommt keine
  neue Datenart hinzu.

---

## 9 Reihenfolge

1. Migration, Schema, Einstellung, Helfer `groups.php` mit Unit-Tests.
2. API-Felder in `attendance_list` und `appointment_responses`, API-Tests.
3. Verwaltung: Gruppendialog und Einstellungsfeld.
4. Dashboard: Anwesenheitsliste und Rückmeldungs-Namensliste.
5. PWA: Anwesenheitsliste (mit Escaping) und Namensliste.
6. Demo-Generator.
7. Dokumentation, Testplan, Version.

Schritt 1 und 2 tragen alles Übrige; 4 und 5 sind unabhängig voneinander.

---

## 10 Bewusst weggelassen

- **Besetzungsübersicht und Sollstärke** („Klarinette 3 von 6“). Das ist der Kern von FI-14 und
  bleibt es. Dieses Vorhaben liefert die Gliederung, nicht die Auswertung.
- **Druckbericht der Besetzung.** Bleibt nach Terminart-Gruppe gegliedert — eine bewusste
  Inkonsistenz zum Modal, später ein Zweizeiler.
- **Mitgliederliste der Verwaltung, Statistik, Filterleisten.** Eine Statistik „nach
  Untergruppe“ hätte eigene Fragen (zählt ein Doppelspieler zweimal?) und gehört nicht hierher.
- **Hierarchie und Vererbung** (FI-14 Variante C) sowie ein **Hauptregister je Mitglied**. Die
  Doppelnennung ist die gewählte Antwort.
- **Zuklappbare Abschnitte** in den Anwesenheitslisten.
