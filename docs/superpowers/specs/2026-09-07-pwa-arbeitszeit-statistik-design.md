# Design: Arbeitszeit in der PWA-Statistik

**Datum:** 2026-09-07
**Status:** Entworfen, nicht umgesetzt
**Betrifft:** `public/checkin/index.html`, `public/checkin/js/app.js`,
`public/checkin/css/style.css`, `tests/suites/worktime_frontend.php`, `docs/testplan.md`,
`docs/OPEN-ITEMS.md`, `CHANGELOG.md`, `public/checkin/README.md`
**Zielversion:** 1.3.2 (Vorschlag)
**Löst:** [OI-36](../../OPEN-ITEMS.md)

---

## Problem

Der Statistik-Tab der Check-in-PWA zeigt zwei Karten — Anwesenheitsquote und Terminzahl — und
die Übersicht nach Gruppen. Die seit 1.2.0 erfassten Stunden kommen darin nicht vor. Ein
Mitglied, das seine Arbeitszeit über die PWA erfasst, sieht dort seinen Verlauf, aber keine
Summe: „Wie viele Stunden habe ich dieses Jahr geleistet?" beantwortet die PWA nicht.

**Der Server liefert die Zahlen bereits.** `statistics` kennt den Parameter `include=worktime`
und hängt dann einen eigenen `worktime`-Block an (`statistics.php:153`, `worktimeStatistics()`
in `worktime.php:445`): Gesamtminuten, Sitzungszahl, Aufteilung nach Nachweisart und nach
Tätigkeit, für einen `user` auf das eigene Mitglied begrenzt. `loadStatistics()` in der PWA
fragt den Parameter schlicht nicht ab (`app.js:3080`). Auch das Dashboard nutzt den Block
nirgends; es rechnet seine Summen in `updateWorktimeStats()` selbst aus der Sitzungsliste. Der
Block hat damit heute keinen Abnehmer im Frontend.

**Die stille Lücke.** `worktimeStatistics()` summiert ausschließlich `confirmed` mit
`end_time` (Testplan AW-2). Wer gestern acht Stunden nachgetragen hat und heute „0:00 h" liest,
hält das für einen Fehler. Dasselbe gilt für abgelehnte Einträge: Sie zählen nie und sind im
Verlauf nur zu sehen, wer weit genug zurückscrollt. Mit den Korrektur- und Nachtragswegen aus
[OI-35](../../OPEN-ITEMS.md) werden beide Zustände häufiger, nicht seltener.

---

## Leitgedanke

**Die Ansicht beantwortet eine Frage: Wie viel habe ich dieses Jahr geleistet?**

Im Vordergrund steht die bestätigte Jahressumme — dieselbe Zahl, die der Verein am Jahresende
auswertet und die in den Verwendungsnachweis eingeht. Alles Weitere hat nur eine Aufgabe: zu
erklären, warum diese Zahl kleiner ist, als das Mitglied es im Kopf hat. Deshalb eine Fußnote
über Ausstehendes, und deshalb keine zweite große Zahl daneben: Die eine ist ein Ergebnis, die
andere eine Behauptung.

---

## Umfang

**Enthalten**

1. Ein Abschnitt „Arbeitszeit" im Statistik-Tab, zwischen Kartenraster und Gruppenübersicht
2. Bestätigte Jahressumme mit Sitzungszahl
3. Fußnote über eingereichte und abgelehnte Einträge, nur wenn es welche gibt
4. Aufschlüsselung nach Tätigkeit, absteigend nach Minuten
5. Statische Gegenproben und Schritte im manuellen Testplan

**Nicht enthalten**

- **Nachweisgrad (`by_proof`).** Der Zweck ist die Leistungsübersicht, nicht die
  Nachweisqualität. Solange [OI-37](../../OPEN-ITEMS.md) offen ist, wäre das Etikett
  „stundenbelegt" ohnehin angreifbar.
- **Laufende Sitzungen in der Fußnote.** Sie zählen zu Recht noch nicht und stehen sichtbar im
  Erfassen-Tab. Das Dashboard führt sie als eigene Kennzahl; auf einem Handybildschirm ist das
  eine Zeile zu viel.
- **Monats- oder Zeitraumwahl, Diagramme.** Die Statistikseite der PWA bleibt jahresbasiert.
- **Fremde Mitglieder für Admin und Manager.** Die PWA bleibt persönlich; `member_id` ist immer
  das eigene, wie heute.
- **Serveränderungen.** Keine. Weder Handler noch Helper noch Schema.

---

## Teil 1 — Sichtbarkeit

Der Block erscheint nur, wenn

```js
worktimeActivities.length > 0 && userData.member_id
```

Das ist dieselbe Bedingung, mit der der Verlauf entscheidet, ob er Arbeitszeitsitzungen
überhaupt abruft (`app.js:2074`), und dieselbe, an der `availableIntents()` hängt.
`initWorktime()` läuft `await`-gebunden beim Laden der Mitgliedsdaten (`app.js:868`) — die
Liste ist gesetzt, bevor der Statistik-Tab erreichbar ist.

Ist die Zeiterfassung abgeschaltet oder hat das Mitglied keine Tätigkeitsart, ändert sich am
Tab nichts: kein Block, kein `include`-Parameter, kein Zusatzabruf. Ein dauerhaftes „0:00 h"
für alle anderen wäre Rauschen.

---

## Teil 2 — Datenfluss

Bei offenem Gate holt `loadStatistics()` beides parallel (`Promise.all`), damit der Tab nicht
zweimal wartet:

| Abruf | Parameter | liefert |
|---|---|---|
| `statistics` | `member_id`, `year`, `include=worktime` | `worktime.summary.total_minutes`, `worktime.summary.sessions`, `worktime.members[0].by_activity` |
| `work_sessions` | `member_id`, `year` | Rohzeilen für die Fußnote |

Aus den Rohzeilen ergibt sich die Fußnote nach derselben Statusaufteilung, die
`updateWorktimeStats()` im Dashboard verwendet (`worktime.js:245`):

- **wartet:** `status === 'submitted' && end_time` — Summe der `duration_minutes` und Anzahl
- **abgelehnt:** `status === 'rejected'` — Anzahl

Der Jahresfilter der Sitzungsliste ist `YEAR(ws.start_time) = ?` (`work_sessions.php:153`),
der des Statistikblocks ein halboffener Bereich über denselben Zeitraum
(`worktimePeriodCondition()`). Für ein volles Kalenderjahr decken sich beide — Haupt- und
Fußnotenzahl beziehen sich auf denselben Bestand.

`worktime.members` ist bei gesetztem `member_id` einelementig oder leer; leer genau dann, wenn
im Jahr nichts bestätigt ist. Der Code liest `members[0]` defensiv.

Bei geschlossenem Gate bleibt der heutige eine Abruf ohne `include` unverändert.

**Warum zwei Abrufe und keine Servererweiterung.** Die Alternative wäre, `worktimeStatistics()`
um einen `pending`-Block zu ergänzen. Dagegen spricht der Zweitnutzen des Helpers: Er speist
den Verwendungsnachweis gegenüber Fördergebern, und dort haben unbestätigte Stunden nichts
verloren. Der Zusatz müsste optional werden — ein zweiter Schalter neben `include=worktime`,
der in `API.md`, in den Tests und im Kopf des nächsten Lesers mitzuführen wäre. Für eine
Fußnote in einer Handy-Ansicht ist das zu viel Apparat.

**Warum nicht alles aus `work_sessions` rechnen.** Bestätigte Summe und Tätigkeitsliste ließen
sich clientseitig genauso bilden; `duration_minutes` und `activity_name` stehen in jeder Zeile.
Dann entstünde die offizielle Kennzahl aber ein zweites Mal im Client. Im Erfassen-Tab der PWA
steht bereits der Kommentar, warum die dortige Zeitenliste entfernt wurde: „Zweimal dasselbe an
zwei Orten zu pflegen heisst, dass eines davon irgendwann abweicht." Die Zahl, die auch den
Nachweis rechnet, bleibt an ihrer Quelle.

---

## Teil 3 — Oberfläche

Neuer Abschnitt zwischen `.stats-cards` und der Gruppenübersicht, im vorhandenen
`.stats-section`-Muster (weiße Karte, 12 px Radius, vorhandener Schatten). Kein neues
CSS-Vokabular, nur die Feinheiten für Zahl und Fußnote.

```
┌────────────────────────────────┐
│ Arbeitszeit                    │
│                                │
│ 12:16 h                        │
│ bestätigt · 18 Sitzungen       │
│ ───────────────────────────    │
│ 4:15 h aus 2 Einträgen warten  │
│ auf Freigabe · 1 abgelehnt     │
│ ───────────────────────────    │
│ ● Vereinsheim          9:14 h  │
│ ● Bühnenaufbau         2:32 h  │
│ ● Notenarchiv          0:30 h  │
└────────────────────────────────┘
```

**Zahlenform.** `formatMinutes()` wie im Dashboard: `736` → `12:16 h` (`worktime.js:55`, „die
Form, in der Vereine über Stunden sprechen"). Die PWA ist ein eigenständiges Skript ohne
Zugriff auf die Dashboard-Module und bekommt eine gleichnamige lokale Funktion mit identischer
Regel. Weicht eine der beiden je ab, ist das ein Fehler.

**Fußnote.** Kleiner und gedämpft, durch eine Haarlinie von der Summe abgesetzt. Textregeln:

| Lage | Text |
|---|---|
| wartend > 0, abgelehnt = 0 | „4:15 h aus 2 Einträgen warten auf Freigabe" |
| wartend > 0, abgelehnt > 0 | „… warten auf Freigabe · 1 Eintrag abgelehnt" |
| wartend = 0, abgelehnt > 0 | „1 Eintrag abgelehnt" |
| beides 0 | Fußnote entfällt vollständig |

Einzahl und Mehrzahl werden unterschieden („1 Eintrag" / „2 Einträge"). Eine Zeile „nichts
offen" gibt es nicht — sie wäre Rauschen für den Normalfall.

**Tätigkeitsliste.** Absteigend nach Minuten, Farbpunkt und Name links, Dauer rechts. Die Farbe
liefert `by_activity` nicht mit; sie wird über die `activity_id` aus dem bereits geladenen
`worktimeActivities` nachgeschlagen, mit dem Fallback, den `activityDot()` ohnehin hat
(`app.js:2149`). Historische Sitzungen können Tätigkeiten aus einer inzwischen verlassenen
Gruppe enthalten — dann greift der Fallback. Dafür eine Server-Query zu ändern lohnt nicht.

---

## Teil 4 — Rand- und Fehlerfälle

| Lage | Verhalten |
|---|---|
| Jahr ohne bestätigte, aber mit offenen Stunden | „0:00 h", Fußnote erklärt es. Der Fall, wegen dem die Fußnote existiert |
| Jahr ohne jede Sitzung — weder bestätigt noch eingereicht noch abgelehnt | Statt Zahl und Liste eine ruhige Zeile „Keine Stunden in 2026". Kein leeres Gerüst |
| Zweiter Abruf schlägt fehl | Fußnote entfällt stillschweigend, Hauptzahl bleibt. Ein Nebenabruf darf die Seite nicht kippen |
| Erster Abruf schlägt fehl | Unverändert wie heute: Fehlertext im Ladebereich |
| Jahreswechsel über ‹ › | Beide Abrufe erneut |
| Abmelden | Der Block wird geleert |

**Zur Abmeldung.** Die Aufräumfunktion leert heute `historyList`, `attendanceListContent` und
`groupsList` (`app.js:776`). Der neue Block gehört in dieselbe Liste. Sonst sieht der nächste
Nutzer am selben Gerät die Stunden seines Vorgängers — genau der Fehlertyp, den
`tests/suites/worktime_frontend.php` bereits festhält.

---

## Prüfung

**Server.** Unverändert. `tests/suites/worktime_api.php` bleibt unberührt und deckt AW-2 und
AW-5 weiter ab.

**Statische Gegenproben** in `tests/suites/worktime_frontend.php`, im Stil der vorhandenen drei:

1. `loadStatistics()` schickt den `include`-Parameter — sonst bliebe `worktime` still `null`
   und der Block dauerhaft leer.
2. Der Zusatzabruf grenzt auf `member_id` **und** `year` ein. Ohne `member_id` liefert die
   Ressource einem Admin die Sitzungen aller Mitglieder; ohne `year` stimmt die Fußnote nicht
   zur Jahreszahl darüber.
3. Die Abmeldung leert den neuen Block.

**Manuell**, neu in `docs/testplan.md`:

| Fall | Erwartung |
|---|---|
| Mitglied ohne Tätigkeitsarten | Kein Arbeitszeit-Block, Statistik wie bisher |
| Jahr mit bestätigten Stunden | Summe deckt sich mit dem Arbeitszeitbericht desselben Jahres |
| Jahr mit 0 bestätigten, 1 eingereichten Eintrag | „0:00 h" plus Fußnote |
| Eintrag abgelehnt | Fußnote nennt ihn; er zählt nicht in die Summe |
| Jahreswechsel | Beide Zahlen wechseln mit |
| Abmelden, anderes Konto anmelden | Kein Rest des Vorgängers |

**Verifikation** gegen die lokale Instanz über `tests/lib/api.php` und die PWA im Browser.

---

## Auswirkungen auf andere Dokumente

| Dokument | Änderung |
|---|---|
| `docs/OPEN-ITEMS.md` | OI-36 auf erledigt setzen, mit Datum und Fundstelle |
| `docs/testplan.md` | Neue Zeilen im Arbeitszeit-Abschnitt |
| `CHANGELOG.md`, `version.json` | Gemeinsam pflegen |
| `API.md` | Keine. `include=worktime` ist seit 2026-09-07 dokumentiert |
| `public/checkin/README.md` | Zeile 66 nennt als Statistikinhalt „Anwesenheitsquote und letzte Einträge" — um die Arbeitszeit ergänzen |

---

## Offene Punkte

- **Reihenfolge gegenüber [OI-35](../../OPEN-ITEMS.md).** Diese Spec setzt OI-35 nicht voraus,
  gewinnt aber daran: Je mehr Wege es gibt, Zeiten nachzutragen und zu korrigieren, desto öfter
  erklärt die Fußnote etwas. Umgekehrt gilt dasselbe. Beide sind unabhängig umsetzbar.
- **[OI-37](../../OPEN-ITEMS.md) bleibt unberührt.** Der Nachweisgrad wird hier nicht angezeigt,
  also verschärft diese Änderung den dortigen Befund nicht — sie heilt ihn aber auch nicht.
