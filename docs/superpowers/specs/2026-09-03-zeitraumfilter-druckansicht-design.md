# Design: Zeitraumfilter und Druckansicht für die Arbeitszeitauswertung

**Datum:** 2026-09-03
**Status:** Draft
**Betrifft:** `private/helpers/worktime.php`, `private/handlers/export.php`,
`private/handlers/statistics.php`, `public/js/modules/worktime.js`, `public/index.html`,
neue Datei `public/css/print.css`, `API.md`, `docs/OPEN-ITEMS.md`
**Keine Schemaänderung, keine Migration.**

---

## Problem

Arbeitsstunden werden im Vereinsalltag **monatlich** gebraucht — für die Abrechnung der
Aufwandsentschädigung, für den Bericht in der Vorstandssitzung, für die Zwischenmeldung an
einen Fördergeber. Die Auswertung kennt aber nur das Jahr.

Die Zeitraumgrenze steht heute an vier Stellen, alle nach demselben Muster
`YEAR(ws.start_time) = ?`:

| Stelle | Funktion |
|---|---|
| `private/helpers/worktime.php:298` | `worktimeStatistics()` |
| `private/helpers/worktime.php:399` | `worktimeByActivity()` |
| `private/helpers/worktime.php:432` | `worktimeByAppointment()` |
| `private/handlers/export.php:203` | `exportWorktimeMember()` (eigene WHERE-Konstruktion) |

Wer einen Monat braucht, exportiert das Jahr und filtert in einer Tabellenkalkulation nach.
Das ist nicht bloß unbequem: Die Summenzeile des CSV wird dabei ungültig, ohne dass es
auffällt, weil sie über dem ungefilterten Datenbestand gebildet wurde.

### Der zweite, unausgesprochene Teil des Problems

Der Stundennachweis ist ein **Dokument, das jemandem vorgelegt wird** — der
Ehrenamtskarten-Stelle, dem Fördergeber, dem Kassenprüfer. Ausgeliefert wird dafür ein CSV
mit einem `SUMMEN`-Block mitten in der Datei (`export.php:255`). Das ist weder sauberes CSV
(ein zweiter Header in derselben Datei bricht jeden Importer) noch ein vorzeigbares
Dokument. Der Empfänger bekommt eine Datei, die erst formatiert werden muss, bevor sie
gelesen werden kann.

---

## Leitgedanke

**Ein Bericht hat einen Zeitraum, kein Jahr.** Das Jahr ist die Vorbelegung, nicht die
Struktur.

**Das Dokument entsteht im Browser, nicht auf dem Server.** Der Druckdialog ist der
PDF-Erzeuger, den jedes Betriebssystem bereits mitbringt. Das Projekt liefert die Seite,
nicht das Dateiformat.

---

## Umfang

**Enthalten**

1. Zeitraumparameter `from`/`to` in den drei Auswertungs-Helpern und den drei
   Arbeitszeit-Exporten
2. Berichtsdialog im Dashboard mit Zeitraumwahl und Schnellwahl-Vorbelegungen
3. Druckansicht (`format=html`) für die drei Arbeitszeit-Berichte
4. `public/css/print.css` — das Projekt hat bisher **keinerlei** Print-Stylesheet
5. Zugang zum Termin-Export, der heute im Backend existiert, aber keinen Knopf hat

**Bewusst nicht enthalten**

| Punkt | Begründung |
|---|---|
| Zeitraumfilter für die Sitzungs**liste** | Der Cache ist jahresbasiert (`dataCache.workSessions[year]`, `ui.js`). Ein Zeitraum als Ladeparameter bräuchte ein anderes Cache-Modell für genau eine Sektion. Der Zeitraum ist ein Berichtsparameter, kein Listenfilter. |
| Serverseitige PDF-Erzeugung | Siehe `docs/OPEN-ITEMS.md`, Zeile 327. Die Begründung trägt weiterhin; die Druckansicht deckt den Bedarf. |
| Anteilige Aufteilung von Sitzungen über die Zeitraumgrenze | Siehe Entscheidung E2. |
| Zeitraum für `members`, `appointments`, `records` | Eigener Fachbereich mit eigener Zeitsemantik (`date` statt `start_time`). Nachrüstbar nach demselben Muster. |
| Zeitraum in der Statistik-Oberfläche | Der Helper bekommt den Parameter, die Statistikseite nutzt ihn zunächst nicht. Sonst wächst der Umfang um die gesamte Anwesenheitsstatistik, die dieselbe Jahresannahme trägt. |

---

## Teil 1 — Zeitraum

### Parameterform

`from` und `to` als Datum `YYYY-MM-DD`, beide **einschließend**. Kein `month`-Parameter.

Ein Monat ist ein Sonderfall eines Zeitraums. `month=9` deckt Quartal, Förderzeitraum und
Saison nicht ab — bei Musikvereinen läuft das Vereinsjahr regelmäßig von September bis Juni.
Ein Monatsparameter müsste dafür später erneut aufgemacht werden; `from`/`to` nicht.

Die Monatswahl ist damit reine **Vorbelegung zweier Felder in der Oberfläche**, keine eigene
Schnittstelle.

### Auflösung der Parameter

Eine gemeinsame Hilfsfunktion in `private/helpers/worktime.php`, damit die Regel an genau
einer Stelle steht:

```php
/**
 * Löst die Zeitraumparameter einer Auswertung auf.
 *
 * Vorrang hat from/to; fehlen beide, gilt das Jahr. Fehlt eines von beiden,
 * begrenzt das Jahr die offene Seite — ein einseitig offener Zeitraum würde
 * sonst stillschweigend den gesamten Datenbestand ziehen.
 *
 * @return array{from: string, to: string, label: string, slug: string}
 */
function worktimeResolvePeriod(?string $from, ?string $to, ?int $year): array
```

Rückgabe:

- `from`, `to` als `YYYY-MM-DD` für die Abfrage
- `label` als lesbare Beschriftung für Bericht und Summenzeile
  (`Januar 2026`, `01.02.2026 – 31.03.2026`, `Jahr 2026`)
- `slug` für den Dateinamen (`2026-01`, `2026-02-01_2026-03-31`, `2026`)

Der `label` ist nicht Kosmetik: Ohne ihn ist ein gespeichertes Monats-CSV von einem
Jahres-CSV nicht mehr zu unterscheiden.

**Validierung.** Ungültiges Datum, `to` vor `from` oder ein Zeitraum über mehr als 24 Monate
führen zu `400` mit einer benannten Meldung. Die Obergrenze verhindert, dass ein
versehentliches `from=1970-01-01` den vollständigen Bestand in eine Druckansicht rendert.

### SQL

Das Muster `YEAR(ws.start_time) = ?` wird an allen vier Stellen ersetzt durch:

```sql
ws.start_time >= ? AND ws.start_time < DATE_ADD(?, INTERVAL 1 DAY)
```

Halboffen statt `BETWEEN`: `BETWEEN '2026-01-01' AND '2026-01-31'` schneidet alles ab, was am
31. nach `00:00:00` beginnt — ein Fehler, der bei einer Jahresgrenze fast nie auffällt und
bei einer Monatsgrenze jeden Monat einmal zuschlägt.

Beide Grenzen bleiben indextauglich, weil die Spalte unverändert auf der linken Seite steht.
Das ist gegenüber `YEAR(ws.start_time)` sogar eine Verbesserung.

### Signaturen

```php
function worktimeStatistics($db, $database, array $period, ?int $memberId = null): array
function worktimeByActivity($db, $database, array $period): array
function worktimeByAppointment($db, $database, array $period): array
```

`$period` ist der Rückgabewert von `worktimeResolvePeriod()`. Ein Array statt zweier Strings,
damit `label` und `slug` mitgeführt werden und nicht an jeder Aufrufstelle erneut gebildet
werden.

Die drei Aufrufer sind vollständig bekannt und werden mitgezogen:

- `private/handlers/statistics.php:156`
- `private/handlers/export.php:278`
- `private/handlers/export.php:316`

`statistics.php` liest weiterhin `year` und reicht `worktimeResolvePeriod(null, null, $year)`
durch — das Verhalten der Statistikseite ändert sich nicht.

### Rückwärtskompatibilität

`?year=2026` allein bleibt gültig und liefert exakt das bisherige Ergebnis. Bestehende
Lesezeichen, Geräte-Aufrufe und die PWA brechen nicht.

---

## Teil 2 — Druckansicht

### Weg der Auslieferung

Über den bestehenden Export-Endpunkt, mit `format`:

```
?resource=export&type=worktime_member&from=2026-01-01&to=2026-01-31&format=html
```

`format=csv` bleibt der Standard.

**Warum nicht eine eigene Datei in `public/`.** Der Präzedenzfall wären
`public/reset_password.php` und `public/verify_email.php`, die bereits serverseitig HTML mit
Branding rendern. Eine dritte solche Datei müsste Authentifizierung, Rollenprüfung und
Rate Limiting neu aufbauen — alles Dinge, die `api.php` vor dem Handler bereits erledigt und
die `handleExport()` mit `requireAdminOrManager()` (`export.php:23`) abschließt. Eine neue
öffentlich erreichbare PHP-Datei mit Datenzugriff ist zusätzliche Angriffsfläche ohne Gewinn.

### Sicherheit — der einzige wirklich neue Risikopunkt

CSV ist gegenüber eingeschleustem Markup gleichgültig. HTML ist es nicht.

In den Bericht fließen **freie Nutzereingaben**: `ws.note`, `ws.start_location_name`,
`ws.end_location_name`, `at.activity_name`, `a.title`, Mitgliedsnamen. Notizen und
Ortsnamen stammen aus der PWA und aus der Nacherfassung, also aus Rollen unterhalb von
`admin`. Ohne Maskierung wäre das ein gespeichertes XSS, das genau in der Ansicht zündet,
die ein Administrator zum Prüfen öffnet.

**Verbindlich:** Jeder Wert im HTML-Bericht läuft durch
`htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`. Umgesetzt über eine lokale Hilfsfunktion, damit
kein Feld beim Ergänzen vergessen wird — nicht durch Maskieren an jeder einzelnen
Ausgabestelle.

Zusätzlich: Der Bericht enthält keinerlei JavaScript. Der Druck wird vom Nutzer über den
Browserdialog ausgelöst, nicht über `window.print()` beim Laden. Eine Seite, die sich beim
Öffnen selbst in einen Dialog wirft, ist in einer Berichtsansicht ein Ärgernis und
verhindert das Prüfen vor dem Drucken.

### Aufbau der Seite

```
+----------------------------------------------+
| [Logo]              Stundennachweis          |
|                     Januar 2026              |
|                     Erstellt am 03.09.2026   |
+----------------------------------------------+
| Tabelle (dieselben Spalten wie das CSV)      |
+----------------------------------------------+
| Summen je Person                             |
+----------------------------------------------+
| Fußnote: Zuordnungsregel + Nachweisgrade     |
+----------------------------------------------+
```

Logo und Vereinsname über das vorhandene `getBrandingSettings()` /
`getBrandingLogo()` (`private/helpers/branding.php`). Der Helfer liefert einen **relativen**
Pfad (`assets/logo-default.png`, `uploads/…`); aus `/api/api.php` heraus zeigt er ins Leere.
Der Bericht setzt deshalb `<base href="../">` im Kopf. Fehlt das Logo, entfällt der Block —
kein gebrochenes Bild im gedruckten Dokument.

### Die Fußnote ist Pflichtbestandteil

Zwei Angaben, die ohne Erklärung zur Rückfrage beim Empfänger führen:

1. **Zuordnungsregel:** „Sitzungen sind dem Zeitraum ihres Beginns zugeordnet."
2. **Nachweisgrade:** `stundenbelegt` / `teilbelegt` / `unbelegt` sind projekteigene Begriffe
   (`worktimeProofLabel()`, `export.php:181`). Ein Fördergeber kennt sie nicht. Die Fußnote
   erklärt sie in einem Satz je Grad.

Ohne 1. behauptet der Bericht eine Genauigkeit, die er nicht hat. Ohne 2. ist die Spalte
`proof` für den Empfänger Rauschen.

### `public/css/print.css`

Neue Datei, eingebunden nur von der Berichtsansicht — **nicht** vom Dashboard. Ein
Print-Stylesheet für `index.html` mit Sidebar, Filterleisten und Modals wäre ein eigener
Umfang mit eigenen Fallstricken und wird hier nicht angefasst.

Inhalt: Seitenränder über `@page`, `thead { display: table-header-group; }` für die
Kopfzeilenwiederholung auf Folgeseiten, `tr { break-inside: avoid; }`, Schwarz auf Weiß
statt Themenfarben. Einsortiert direkt unter `public/css/`, nicht in `components/` oder
`sections/` — es ist kein Baustein der Anwendungsoberfläche.

### Zugang in der Oberfläche

Die beiden Knöpfe in `public/index.html:1018-1019` werden zu **einem** Knopf „Bericht", der
ein Modal öffnet:

| Feld | Inhalt |
|---|---|
| Berichtsart | Stundennachweis / Nach Tätigkeit / Nach Termin |
| Zeitraum | Von / Bis, vorbelegt mit dem laufenden Jahr |
| Schnellwahl | Dieser Monat · Letzter Monat · Laufendes Jahr |
| Mitglied | nur bei Stundennachweis, aus dem bestehenden Filter |
| Ausgabe | Druckansicht öffnen · CSV herunterladen |

Damit erhält der Termin-Bericht nebenbei seinen Zugang: `exportWorktimeAppointment()`
existiert in `export.php:312` und in `API.md`, ist aber an keiner Stelle der Oberfläche
erreichbar — in `worktime.js:677` sind nur `member` und `activity` ans `window`-Objekt
gehängt. Er ist heute nur per Hand getippter URL zu erreichen.

---

## Entscheidungen

| # | Entscheidung | Begründung |
|---|---|---|
| E1 | `from`/`to` statt `month` | Ein Monat ist ein Sonderfall. `month` deckt Quartal und Vereinsjahr nicht ab und müsste später ersetzt werden. |
| E2 | Sitzungen zählen **nach Startzeit**, ohne Aufteilung an der Grenze | Die Summe über zwölf Monate bleibt gleich der Jahressumme, und die Einzelzeile stimmt mit der Summenzeile überein. Eine anteilige Aufteilung wäre exakter, würde aber im Nachweis eine Sitzung in zwei Zeilen mit je einer nirgends belegten Teilzeit zerlegen. Die Regel steht in der Fußnote. |
| E3 | Zeitraum ist Berichtsparameter, nicht Listenfilter | Der Cache ist jahresbasiert; ein Zeitraum als Ladeparameter bräuchte ein Sondermodell für eine Sektion. |
| E4 | Druckansicht über `api.php`, nicht als eigene Datei | Auth, Rollenprüfung und Rate Limiting existieren dort bereits. Keine neue öffentliche PHP-Datei mit Datenzugriff. |
| E5 | Kein `window.print()` beim Laden | Der Nutzer soll den Bericht vor dem Drucken prüfen können. |
| E6 | `docs/OPEN-ITEMS.md:327` bleibt bestehen | Die Bibliotheksbegründung trägt weiter. Der Eintrag wird um den Verweis auf die Druckansicht ergänzt, damit die Frage nicht erneut aufgemacht wird. |

---

## Auswirkungen auf andere Dokumente

| Datei | Änderung |
|---|---|
| `API.md` | `from`, `to`, `format` beim Abschnitt `export`; Hinweis auf die Fortgeltung von `year` |
| `docs/OPEN-ITEMS.md` | Zeile 327 um den Verweis auf die Druckansicht ergänzen |
| `docs/testplan.md` | Abschnitt Arbeitszeit um Zeitraum und Druckansicht erweitern |
| `version.json`, `CHANGELOG.md` | gemeinsam auf `1.2.2` (keine Migration nötig) |
| `private/setup/ehrensache_db.sql` | **unverändert** — keine Schemaänderung |

---

## Prüfung

**Automatisiert** — `tests/suites/worktime_unit.php` deckt heute `sessionDurationMinutes()`
und `validateManualSession()` ab; die Auswertungsfunktionen sind ungetestet. Neu für
`worktimeResolvePeriod()`, weil es reine Logik ohne Datenbank ist:

- `year` allein ergibt den 1. Januar bis 31. Dezember
- `from`/`to` haben Vorrang vor `year`
- nur `from` gesetzt, `year` begrenzt das Ende
- `to` vor `from` wird abgewiesen
- Zeitraum über 24 Monate wird abgewiesen
- `label` und `slug` für Monat, freien Zeitraum und Jahr

`tests/suites/worktime_api.php` ergänzen: Export mit Zeitraum liefert `200`, Export mit
`to` vor `from` liefert `400`, `format=html` liefert `Content-Type: text/html`.

**Manuell** — der entscheidende Fall ist die Grenze: eine Sitzung am 31.01. 22:00 bis 01.02.
02:00 anlegen und prüfen, dass sie im Januarbericht vollständig erscheint und im
Februarbericht gar nicht, und dass die Summe beider Monate der Summe des Zeitraums
01.01.–28.02. entspricht.

Für die Maskierung: eine Sitzung mit `<script>alert(1)</script>` als Notiz anlegen und die
Druckansicht öffnen. Erwartet wird der Text, nicht die Ausführung.

Für die Druckansicht: Seitenumbruch mit mehr als einer Seite Inhalt prüfen — Kopfzeile muss
sich wiederholen, keine Zeile darf über den Umbruch zerrissen werden.

---

## Offene Punkte

1. **Unterschrift.** Ein Nachweis für die Ehrenamtskarte verlangt regelmäßig eine
   Unterschrift des Vorstands. Ein Feld „Ort, Datum, Unterschrift" am Fuß wäre billig — es
   ist aber eine fachliche Aussage darüber, wofür dieses Dokument gilt, und gehört
   entschieden, nicht nebenbei eingebaut.
2. **Nur bestätigte Sitzungen.** Alle Auswertungen filtern hart auf
   `status = 'confirmed'`. Für einen Monatsbericht in der Vorstandssitzung könnte gerade der
   offene Stand interessant sein. Vorschlag: unverändert lassen, aber die Anzahl noch nicht
   freigegebener Sitzungen im Zeitraum als Hinweiszeile im Bericht ausweisen — sonst wirkt
   der Bericht vollständig, obwohl er es nicht ist.
