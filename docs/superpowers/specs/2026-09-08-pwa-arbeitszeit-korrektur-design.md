# Design: Arbeitszeit aus der PWA korrigieren und nachtragen

**Datum:** 2026-09-08
**Status:** Entworfen, nicht umgesetzt
**Betrifft:** `private/handlers/work_sessions.php`, `public/checkin/index.html`,
`public/checkin/js/app.js`, `public/checkin/css/style.css`,
`tests/suites/worktime_api.php`, `tests/suites/worktime_frontend.php`,
`docs/testplan.md`, `API.md`, `docs/OPEN-ITEMS.md`, `CHANGELOG.md`,
`public/checkin/README.md`
**Zielversion:** 1.3.2 (Vorschlag)
**Löst:** [OI-35](../../OPEN-ITEMS.md) und [OI-37](../../OPEN-ITEMS.md)

---

## Problem

Der Verlauf-Tab der Check-in-PWA führt Arbeitszeitsitzungen mit, aber ohne jede Aktion:
`addWorkSessionToHistory()` rendert Datum, Tätigkeit, Dauer, Notiz und Status — keinen Knopf
(`app.js:2217`). Wer einen Vertipper bemerkt, muss ans Dashboard. Wer das Anstempeln ganz
vergessen hat, ebenso. Die PWA ist das Gerät, mit dem die Zeit erfasst wurde, und bleibt
hinter beidem zurück.

**Serverseitig ist beides fertig.** `workSessionUpdate()` lässt den Eigentümer seine eigene
Sitzung ändern und setzt den Status dabei auf `submitted` zurück — eine Änderung entzieht die
Bestätigung und verlangt eine neue Freigabe (`work_sessions.php:767`). `POST work_sessions`
**ohne** `action` legt über `workSessionCreateManual()` eine vollständige Sitzung an
(`work_sessions.php:561`), Status `submitted`, `source = manual`. Zu bauen ist die Oberfläche.

**Der Nachweis lügt mit.** `workSessionUpdate()` schreibt die Zeiten neu, lässt
`start_location_name` und `end_location_name` aber unberührt (`work_sessions.php:770`). Wer um
10:00 mit TOTP startet, um 11:00 mit TOTP stoppt und den Start danach auf 06:00 zieht, hat fünf
Stunden mit dem Etikett „stundenbelegt“ — nachgewiesen ist eine. Der Nachweisgrad wird aus
genau diesen beiden Feldern abgeleitet (`worktimeProofExpression()`, `worktime.php:405`). Genau
dieser Fall — zu spät angestempelt — ist der häufigste Grund für eine Korrektur. Ein
Korrekturweg auf jedem Handy vervielfacht ihn, solange der Befund offen ist.

---

## Leitgedanke

**Ein Ortsnachweis gilt für den gestempelten Zeitpunkt, nicht für den behaupteten.**

Deshalb kommen Oberfläche und Nachweisregel zusammen: Wer eine Zeit verschiebt, verliert das
Etikett für diese Zeit. Das ist die einzige Lesart, die vor einem Fördergeber hält — und
zugleich das Signal, das dem freigebenden Manager heute fehlt.

---

## Umfang

**Enthalten**

1. Ortsnachweis fällt mit der Zeit, auf die er sich bezieht (Serverteil, OI-37)
2. Ein Modal in der PWA für Korrektur **und** Nachtrag, mit zwei Einstiegen
3. Hinweis auf die Folgen **vor** dem Speichern
4. Server- und Frontend-Gegenproben, manuelle Fälle im Testplan

**Nicht enthalten — bewusst entschieden**

- **Begründungspflicht.** In der Notiz verschmutzte sie den Verwendungsnachweis, in den sie
  eingeht; in der Auditspur bliebe sie unsichtbar, weil die heute nirgends angezeigt wird.
  Der Nachweisgrad, der bei einer Zeitkorrektur sichtbar fällt, ist das Signal für diesen
  Wurf. Die sichtbare Auditspur samt `reason` ist als [OI-38](../../OPEN-ITEMS.md) abgelegt.
- **Rückwirkende Frist.** Es gibt heute keine, weder für Nachtrag noch für Korrektur. Eine
  feste Grenze erzeugt eine Sackgasse — fällt der Fehler zu spät auf, kann ihn niemand mehr
  heilen, auch der Manager nicht. Eine einstellbare Grenze kostet Schlüssel, Standardwert,
  Migration, Einstellungsfeld, Doku und Tests, ohne dass ein Verein sie verlangt hätte. Die
  erneute Freigabe ist die Kontrolle. Kommt der Bedarf, ist `worktime_correction_days` analog
  `checkin_tolerance_hours` der Weg.
- **Statusbeschränkung.** `submitted`, `confirmed` und `rejected` bleiben korrigierbar, wie im
  Dashboard. Wer eine berechtigte Ablehnung nicht korrigieren darf, legt stattdessen eine
  zweite Sitzung für dieselbe Arbeit an — zwei Datensätze sind schlechter als eine Korrektur
  mit Auditspur.
- **Laufende Sitzungen.** Ein `PUT` darauf antwortet `409`, solange kein `end_time` mitkommt.
  Wer zu spät angestempelt hat, stoppt zuerst und korrigiert danach. Ein Sonderweg wäre
  gefährlich: Eine zurückverlegte Startzeit kann die Sitzung sofort überfällig machen
  (`worktime_max_session_hours`), der nächste Zugriff kappt sie dann auf Start plus Obergrenze
  — das Mitglied verlöre genau die Zeit, die es nachtragen wollte.
- **Fremde Mitglieder.** Die PWA bleibt persönlich.

---

## Teil 1 — Der Ortsnachweis folgt der Zeit

In `workSessionUpdate()` wird beim Schreiben zusätzlich geprüft, ob sich die Zeiten geändert
haben:

- `start_time` geändert → `start_location_name = NULL`
- `end_time` geändert → `end_location_name = NULL`

Verglichen wird auf `Y-m-d H:i:s`-normalisierten Werten, damit `08:00` und `08:00:00` nicht als
Änderung gelten. Der Nachweisgrad rutscht dadurch von `hours` auf `start` oder `none` — ohne
weiteres Zutun, weil er in Statistik, Export und Dashboard aus genau diesen Feldern abgeleitet
wird.

**Das gilt für alle Rollen.** Auch ein Manager, der eine fremde Sitzung korrigiert, verliert
den Nachweis für die verschobene Zeit: Er wird durch die Verschiebung sachlich falsch,
unabhängig davon, wer sie vornimmt. Der Preis ist, dass ein Manager einen offensichtlichen
Vertipper nicht mehr heilen kann, ohne das Etikett zu opfern. Das ist die richtige Richtung —
das Etikett behauptet etwas über einen Zeitpunkt, den es nach der Korrektur nicht mehr gibt.

**Keine Toleranz für Minutenkorrekturen.** Eine Schwelle erzeugt einen Sonderfall, den später
niemand erklären kann.

Der Wegfall landet ohne Zusatzcode in der Auditspur: `sessionChangeSet()` vergleicht alle
Spalten des Datensatzes, die genullten Ortsfelder erscheinen dort als `old`/`new`.

---

## Teil 2 — Ein Formular, zwei Einstiege

Ein Modal im vorhandenen Muster der PWA (`.modal` / `.modal-content` / `.form-group` /
`.modal-buttons`, sichtbar über `classList.add('active')` — wie `#exceptionModal`,
`index.html:390`).

| Feld | Typ | Pflicht |
|---|---|---|
| Tätigkeit | `select` aus `worktimeActivities` | ja |
| Beginn | `datetime-local` | ja |
| Ende | `datetime-local` | ja |
| Pause in Minuten | `number`, `min="0"` | nein, Standard 0 |
| Termin | `select`, erste Option „Kein Termin“ | nein |
| Notiz | `text`, `maxlength="255"` | abhängig von `worktime_require_note` |

**Warum `datetime-local` und nicht Datum plus zwei Uhrzeiten.** Ein Datumsfeld mit zwei
Uhrzeiten wäre auf dem Handy weniger Tipparbeit, bräuchte für Sitzungen über Mitternacht aber
einen Schalter „Ende am Folgetag“. Steht der versehentlich an, ist der Eintrag stillschweigend
24 Stunden zu lang — und 24 Stunden zu viel fallen niemandem auf, der nur die Uhrzeiten liest.
Vergisst man ihn beim echten Nachtdienst, kommt immerhin eine saubere Fehlermeldung. Ein
Zustand, dessen Fehlbedienung in die eine Richtung laut und in die andere leise ist, gehört
nicht in ein Formular, das Stunden für einen Fördergeber erzeugt. Dazu kommt Parität: Das
Dashboard nutzt `datetime-local` für dieselbe Ressource.

**Einstiege**

- **Verlauf:** Knopf „Korrigieren“ am Eintrag einer Arbeitszeitsitzung — nur bei beendeten.
  Die Eigentümerfrage stellt sich nicht: Der Verlauf ruft `work_sessions` mit dem eigenen
  `member_id` ab und zeigt ausschließlich eigene Sitzungen. Titel des Modals „Zeit
  korrigieren“, Speichern schickt `PUT`.
- **Arbeitszeit-Tab:** Knopf „Zeit nachtragen“ unter „Start“, nur sichtbar, wenn keine Sitzung
  läuft. Titel „Zeit nachtragen“, Speichern schickt `POST` ohne `action`.

**Hinweiszeile unter den Feldern**

- Nachtrag: „Nachträglich erfasste Zeiten gelten erst nach Freigabe durch einen Manager.“
  (Wortlaut wie im Dashboard.)
- Korrektur: zusätzlich „Wird Beginn oder Ende geändert, entfällt der Ortsnachweis dieser
  Zeit.“ Vor dem Speichern, nicht als Überraschung danach.

---

## Teil 3 — Datenfluss

**Öffnen.** Aus dem Verlauf werden die Werte aus dem bereits geladenen Sitzungsobjekt
übernommen — kein zusätzlicher `GET`. Die Tätigkeitsliste kommt aus `worktimeActivities`, die
Terminliste über die vorhandene `renderWorktimeAppointmentOptions()` (`app.js:2927`). Beim
Nachtrag starten die Felder leer, Pause auf `0`.

**Speichern.** Der volle Feldsatz geht raus: `activity_id`, `start_time`, `end_time`,
`break_minutes`, `note`, `appointment_id`. Beim `PUT` bedeutet ein leeres Terminfeld bewusst
„Zuordnung lösen“ — das Formular zeigt den aktuellen Stand, also ist die Abwesenheit einer
Auswahl eine Aussage. Der Server entscheidet über den Status; die PWA setzt ihn nie selbst.

**Danach.** Modal schließen, `loadHistory()` (`app.js:2053`) und `loadWorktimeState()`
(`app.js:2611`) neu laufen lassen. Der Statistik-Tab lädt bei jedem Wechsel ohnehin neu; die
PWA hält keinen Cache.

---

## Teil 4 — Rand- und Fehlerfälle

| Lage | Verhalten |
|---|---|
| Tätigkeit der alten Sitzung liegt nicht mehr in den eigenen Gruppen | Die Option wird der Auswahl hinzugefügt und vorausgewählt — sonst springt sie still auf eine fremde Tätigkeit, und das Speichern schriebe eine falsche |
| `worktime_require_note` aktiv, Notiz leer | Server antwortet `400` mit `errors[]`; das Modal gibt diese Liste aus |
| Ende vor Beginn, Zeiten in der Zukunft, Pause ≥ Bruttodauer | Dieselbe Behandlung — die Prüfung gehört in `validateManualSession()` (`worktime.php:54`) und wird nicht im Client nachgebaut |
| Sitzung läuft beim Speichern noch (Wettlauf) | `409` → „Erst beenden, dann korrigieren“ |
| Kein Zugriff auf die Zeiterfassung | Weder Knopf noch Modal, Gate wie beim Statistikblock (`worktimeActivities.length > 0`) |
| Abmelden | Modal schließen, Felder leeren — die Aufräumliste in `resetSessionState()` (`app.js:761`) wächst um die neuen IDs |

**Zur Fehlerausgabe.** `apiCall()` liefert im Fehlerfall die vollständige Serverantwort als
`data` zurück. Das Modal liest daraus `errors[]` und zeigt die Liste; die nackte Meldung
„Validation failed“ allein wäre für ein Mitglied wertlos.

---

## Prüfung

**Server**, neue Fälle in `tests/suites/worktime_api.php`:

| Fall | Erwartung |
|---|---|
| Sitzung mit beiden Ortsnachweisen, `start_time` ändern | `start_location_name` ist `NULL`, `end_location_name` unverändert |
| Dieselbe Sitzung, `end_time` ändern | umgekehrt |
| Nur Notiz oder Tätigkeit ändern | beide Nachweise bleiben |
| Startzeit identisch mitschicken (`08:00` gegen `08:00:00`) | kein Wegfall |
| Nach der Korrektur | Auditspur enthält den Wegfall als `old`/`new` |

**Frontend**, statische Gegenproben in `tests/suites/worktime_frontend.php` im Stil der
vorhandenen: Die im JS verwendeten Modal-IDs existieren in `public/checkin/index.html`; der
`PUT` auf `work_sessions` trägt eine `id`; `resetSessionState()` räumt die neuen Felder ab.

**Manuell**, neu in `docs/testplan.md`: Korrektur einer bestätigten Sitzung fällt auf „wartet
auf Freigabe“ zurück und verliert den Nachweisgrad; Nachtrag erscheint im Verlauf; Tätigkeit
aus einer verlassenen Gruppe bleibt vorausgewählt; leere Pflichtnotiz zeigt die Servermeldung;
Abmelden hinterlässt kein offenes Modal.

**Sichtprüfung** über direkte Renderaufrufe in der laufenden PWA
(`http://localhost/EhrenSache/public/checkin/`), wie bei OI-36 erprobt.

---

## Auswirkungen auf andere Dokumente

| Dokument | Änderung |
|---|---|
| `docs/OPEN-ITEMS.md` | OI-35 und OI-37 auf erledigt setzen, mit Datum und Fundstelle |
| `API.md` | Abschnitt *Nachtrag, Korrektur, Löschung* um den Wegfall des Ortsnachweises ergänzen — das ist eine Verhaltensänderung der Ressource |
| `docs/testplan.md` | Neue Zeilen bei den Arbeitszeit-Fällen |
| `CHANGELOG.md`, `version.json` | Gemeinsam pflegen |
| `public/checkin/README.md` | Korrigieren und Nachtragen in der Funktionsliste nennen |

---

## Offene Punkte

- **[OI-3](../../OPEN-ITEMS.md) bleibt offen.** Ein Manager, der seine eigene Sitzung
  korrigiert, behält `confirmed` — er ist die freigebende Instanz und genehmigt sich selbst.
  Diese Spec ändert daran nichts; sie fügt nur einen weiteren Ort hinzu, an dem es auffällt.
- **[OI-38](../../OPEN-ITEMS.md) ist die Fortsetzung.** Erst wenn die Auditspur in der
  Freigabeliste sichtbar ist, sieht der Manager, *was* geändert wurde. Bis dahin trägt allein
  der gefallene Nachweisgrad das Signal.
- **Bestandsdaten.** Ob bereits korrigierte Sitzungen nachträglich herabgestuft werden, bleibt
  offen. Ermitteln ließen sie sich über `work_session_log` (Änderungssätze mit `start_time`
  oder `end_time`), solange die Auditspur nicht schon gelöscht ist.
