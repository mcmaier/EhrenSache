# Design: Status-Chips statt Zählkarten und Status-Auswahlfelder

**Datum:** 2026-09-22
**Status:** Approved
**Betrifft:** Dashboard — Termine, Anwesenheit, Anträge, Arbeitszeit, Mitglieder, Benutzer,
Geräte. Reine Oberfläche, kein Backend. Löst [OI-86](../../OPEN-ITEMS.md) ab.

## Problem

Die Benutzerverwaltung filtert den Account-Status über farbige Pillenknöpfe mit Zähler
(`#userStatusFilter`, `.filter-btn` in `css/sections/content.css`). Alle anderen Ansichten
filtern seit 1.9.2 über Auswahlfelder in der `filter-bar` und zeigen Zähler getrennt davon in
Kennzahlkarten (`stat-card` im `stats-grid`).

Daraus ergeben sich drei Mängel:

1. **Die Pillenknöpfe sind die einzige Ausnahme** und hart codiert (`#ffc107`, `#28a745`,
   `#dc3545`, `#007bff`, `white`, `#ddd`). Branding-Farben erreichen sie nicht, das Blau des
   aktiven „Alle“ kommt sonst nirgends vor.
2. **Zähler und Filter stehen doppelt.** Anträge zeigen „Ausstehende Anträge“ als Karte und
   filtern denselben Status in einem Auswahlfeld darunter; Arbeitszeit ebenso mit „Wartet auf
   Freigabe“. Die Karte ist Anzeige, das Auswahlfeld Bedienung — für ein und dieselbe Frage.
3. **Der Knopf „Filter zurücksetzen“ ist das lauteste Element der Leiste.** `.btn-reset-filter`
   teilt den Stil mit `.btn-cancel` (`css/components/buttons.css`): vollflächig `gray`/`white`,
   44 px hoch — obwohl er am seltensten gebraucht wird.

Das Kriterium aus der Sichtung der Pillenknöpfe war: Die Farben tragen Bedeutung, und jeder Knopf
zeigt die Anzahl. Dieses Muster wird deshalb nicht zurückgebaut, sondern auf alle Ansichten mit
festem Statussatz ausgedehnt. Die Zählkarten mit Statuszählern werden dadurch redundant und
entfallen.

## Entscheidungen

| Frage | Entscheidung | Verworfen |
|---|---|---|
| Wo sitzt der Statusfilter? | Chipreihe im `stats-grid`, links neben der Jahreskarte | Chips in der `filter-bar` neben den Auswahlfeldern (Zähler stünden doppelt zu den Karten) |
| Was wird aus den Zählkarten? | Statuszähler entfallen, die Zahl steht im Chip | Klickbare Kennzahlkarten (bricht mit 1.9.2, Benutzer haben keine Karten, „Abgelehnt“ bräuchte Platz) |
| Auch Auswahllisten als Chips? | Nein, Stammdatenlisten (Terminart, Termin, Mitglied, Tätigkeit, Gruppe, Rolle, Typ, Herkunft) bleiben Auswahlfelder | Alles als Chip-Menüs (eigene Menükomponente, kaum schlanker) |
| Jahresfilter | Bleibt als Karte oben rechts, unverändert | — (feste Konvention, nicht erneut vorschlagen) |
| Mehrfachauswahl | Nein, immer genau ein aktiver Chip | „Ausstehend + Abgelehnt“ gleichzeitig |
| Termine: Zeit-Chips auf den Kalender? | Chips sind dort **reine Anzeige**, ohne Filterfunktion | Nur Tabelle filtern; Kalender ausblenden; Kalender abblassen |
| Zurücksetzen als Chip? | Nein — Chip ist ein Zustand, Zurücksetzen eine Aktion und betrifft auch die Auswahlfelder | Chip am Ende der Chipreihe |

Diese Spec ersetzt für Statuszähler die Regel aus der Filterleisten-Spec vom 2026-09-17
(„Kennzahlkarten folgen überall der Auswahl“): Wo der Zähler im Chip steht, gibt es keine
Karte mehr, die folgen könnte. Für die verbleibenden Karten („Bestätigte Stunden“, Statistik)
gilt die Regel unverändert.

## Komponente

Neue Datei `public/css/components/filter-chips.css`, Vorbild `.response-chip`
(`css/components/badges.css`).

- **`.filter-chips`** — Container. Im `stats-grid` belegt er die Breite links neben der
  Jahreskarte; in Ansichten ohne Jahresbezug (Benutzer, Geräte) die volle Breite. Umbrechend
  (`flex-wrap`), damit fünf Chips auf schmalen Bildschirmen nicht überlaufen.
- **`.filter-chip`** — `<button type="button">` mit `aria-pressed="true|false"`. Aufbau:
  Beschriftung plus Zähler `.filter-chip__count`. Der Status steht immer als Wort im Chip, nie
  nur als Farbe. Keine Emojis (die heutigen „⏳ ✓ 🚫“ entfallen).
- **Varianten** über vorhandene Tokens, getönt per `color-mix()` wie `.response-chip--*`:

  | Variante | Token | Verwendung |
  |---|---|---|
  | `--pending` | `--warning-color` | Ausstehend, Wartet, Entschuldigt |
  | `--ok` | `--success-color` | Genehmigt, Bestätigt, Aktiv, Anwesend |
  | `--danger` | `--danger-color` | Abgelehnt, Gesperrt, Fehlend |
  | `--info` | `--primary-color` | Läuft (Arbeitszeit) |
  | ohne | `--text-light`, `--border-color` | Alle, Inaktiv, Vergangen, Kommend |

  Ruhend: blasser Grund, Text in der Akzentfarbe. Aktiv: kräftigerer Grund und Rand in der
  Akzentfarbe. Der aktive neutrale Chip („Alle“) nutzt `--primary-color`, folgt damit dem
  Branding.
- **`.filter-chip--static`** — reine Anzeige, als `<span>` statt `<button>`: gleiche Form, aber
  ohne Rand, ohne Hover, ohne `cursor: pointer`, kein `aria-pressed`. Muss sich sichtbar von
  klickbaren Chips unterscheiden.
- In `filter-chips.css` stehen **keine Hex-Werte**, nur Tokens aus `variables.css`. Damit ist
  die Komponente für einen späteren Dark Mode vorbereitet.

### Zurücksetzen-Knopf

- `.btn-reset-filter` wird von `.btn-cancel` getrennt und zum Ghost-Knopf: Text in
  `--primary-color`, kein Grund, blasser Grund bei Hover, Höhe gleich den Auswahlfeldern.
  Tokens statt `gray`/`white`.
- **Sichtbar nur bei Abweichung:** Er erscheint, sobald ein Chip oder ein Auswahlfeld der
  Ansicht von der Vorgabe abweicht. Das Jahr zählt nicht mit — das Zurücksetzen lässt das Jahr
  wie bisher stehen. Als letztes Element der Leiste verschiebt sein Ein- und Ausblenden nichts.
- Er setzt Chips **und** Auswahlfelder auf ihre Vorgabe.
- Geräte haben keinen Zurücksetzen-Knopf: „Alle“ ist dort das Zurücksetzen.
- Die Statistik behält ihren Knopf und bekommt über die gemeinsame Klasse den neuen Stil.

## Verhalten

- **Genau ein Chip aktiv.** Vorgabe ist „Alle“, bei den Mitgliedern „Aktiv“ (entspricht dem
  heutigen Verhalten „Inaktive anzeigen“ = aus).
- **Zählregel (facettiert):** Jeder Zähler berücksichtigt das Jahr und alle übrigen Filter der
  Ansicht, **nicht** aber den Statusfilter selbst. Sonst stünden alle anderen Chips auf 0,
  sobald einer aktiv ist. Das entspricht der Entscheidung aus OI-71 (1.9.1), dass Aktiv/Inaktiv
  aus dem Gesamtbestand des Jahres gezählt wird.
- **Chips schließen sich gegenseitig aus.** Die Summe der Status-Chips ergibt „Alle“.
- **Ein gemeinsamer JS-Baustein** im importfreien Modul `public/js/modules/filter_chips.js`
  (damit Node ihn ohne Browser für die Tests laden kann):
  `renderFilterChips(container, defs, counts, activeKey, onChange, options)`, mit
  Chip-Definitionen `defs = [{ key, label, variant?, match? }]` und getrennt dazu berechneten
  Zählern `counts` (aus `countChips`). Die Module liefern nur Definition und Basisliste; Rendern,
  `aria-pressed` und Klickbehandlung liegen einmal im Baustein. Die Zählregel als reine Funktion
  (Liste, Chip-Definitionen → Zähler je Schlüssel), damit sie testbar ist.

## Umfang je Ansicht

| Ansicht | Chips | Es entfällt | Es bleibt |
|---|---|---|---|
| Termine | Alle · Vergangen · Kommend — **`--static`, keine Filterfunktion** | Karten „Vergangene/Kommende Termine“ | Terminart, Herkunft, Kalender unverändert |
| Anwesenheit | Alle · Anwesend · Entschuldigt; bei gewähltem Termin oder Mitglied zusätzlich **Fehlend** | Karten `statTotalRecords`/`statMissingRecords` samt modusabhängiger Titel in `updateRecordStats()` | Terminart, Termin, Mitglied, Gruppierungsleiste |
| Anträge | Alle · Ausstehend · Genehmigt · Abgelehnt | Karten `statPendingExceptions`/`statApprovedExceptions`, Auswahlfeld `filterExceptionStatus` | Typ |
| Arbeitszeit | Alle · Läuft · Wartet · Bestätigt · Abgelehnt | Karten `statWorktimePending`/`statWorktimeOpen`, Auswahlfeld `filterWorktimeStatus` | Karte „Bestätigte Stunden“ (Summe, kein Zähler), Tätigkeit, Mitglied |
| Mitglieder | Alle · **Aktiv** (Vorgabe) · Inaktiv | Karten `statActiveMembersCount`/`statInactiveMembersCount`, Checkbox `show_inactive_members` | Gruppe |
| Benutzer | Alle · Ausstehend · Aktiv · Gesperrt (volle Breite) | `.filter-btn`, `.pending`, `.active-status`, `.suspended` samt hart codierter Farben | Rolle |
| Geräte | Alle · Aktiv · Inaktiv (volle Breite) | Karten „Aktive/Inaktive Geräte“ | — |

Die Jahreskarte bleibt in jeder Ansicht mit Jahresbezug oben rechts. Die Statistik bleibt bis
auf den Zurücksetzen-Stil unberührt — ihre Kennzahlen sind Ergebnis, kein Filter.

### Sonderfälle

- **„Läuft“ (Arbeitszeit)** ist kein Datenbankstatus, sondern „Ende fehlt“; eine laufende
  Sitzung trägt `status = 'submitted'`. Sie zählt nur unter „Läuft“, nicht zusätzlich unter
  „Wartet“. „Wartet“ heißt also: eingereicht **und** beendet.
- **„Fehlend“ (Anwesenheit)** gibt es nur in den Modi `ATTENDANCE_BY_APPOINTMENT` und
  `ATTENDANCE_BY_MEMBER` (`RecordMode` in `records.js`). Wechselt die Ansicht zurück zu
  `ALL_RECORDS`, während „Fehlend“ aktiv ist, springt die Auswahl auf „Alle“.
- **Rollen:** Chips und Zähler rechnen über das, was die Rolle ohnehin sieht. Ein `user` sieht
  in Anträgen und Arbeitszeit nur eigene Einträge, die Zähler also auch.

## Nicht Teil dieses Vorhabens

- **OI-79** (neues Serienjahr fehlt in der Jahresauswahl, `availableYears` wird nach
  Serienaktionen nicht invalidiert) — eigene kleine Korrektur.
- Check-in-PWA und Station.
- Mehrfachauswahl von Status.
- Dark Mode fürs Dashboard (die Komponente ist nur darauf vorbereitet).

## Tests

- **Statische Suite** nach dem Muster von `assets`:
  - `filter-chips.css` enthält keine Hex-Farben.
  - Die entfallenen IDs und Klassen (Tabelle oben) stehen nicht mehr in `public/index.html`
    bzw. `content.css`.
  - Jede Ansicht aus der Tabelle hat einen Chip-Container.
- **Unit:** die Zählregel als reine Funktion — facettiert, gegenseitig ausschließend, Sonderfall
  „Läuft“ gegen „Wartet“.
- **Manuell:** neuer Abschnitt in `docs/testplan.md` je Ansicht, geprüft als Admin **und** als
  `user`. Dabei insbesondere: Zähler bei gesetzten Auswahlfeldern, Zurücksetzen erscheint und
  verschwindet, Anwesenheit beim Moduswechsel mit aktivem „Fehlend“, Branding-Farbe am aktiven
  „Alle“.

## Nachtrag 2026-09-23: Kompakter Kopf, Statistik und Gruppen

Aus der Sichtprüfung der ersten Runde: Der Kopfbereich trägt zu dick auf und sieht von Ansicht
zu Ansicht verschieden aus. Drei Ursachen, alle älter als dieses Vorhaben:

1. Der Jahresfilter ist eine zweizeilige `stat-card` mit Überschrift „Jahr filtern“ und
   sechsfach wiederholten Inline-Styles am `<select>`.
2. Die Statistik baut ihren Kopf anders: `stats-header` mit `filter-card` statt `filter-bar`,
   dadurch ein breiterer Jahresfilter und Beschriftungen über den Feldern.
3. Die Filterleiste stellt ihre Beschriftungen über die Felder, Feldhöhe 44 px.

**Entschieden (Variante B von drei):** Zwei Zeilen, beide flacher. Alles in einer einzigen Zeile
wurde verworfen — sobald in einem Auswahlfeld ein Wert steht, wäre nicht mehr erkennbar, zu
welchem Filter es gehört (die Anwesenheit hat drei ähnliche Listen).

### Kopfbereich

- **Zeile 1:** Chipzeile links, Jahresfilter rechts. Neue Komponente `.year-filter` — „Jahr:“
  und Auswahlfeld **in einer Zeile**, etwa 150 px breit. Sie ersetzt die sechs `stat-card`-Blöcke
  samt Inline-Styles und den abweichenden Container der Statistik. Die Stelle oben rechts bleibt
  (Konvention seit 1.9.2), nur die Bauform wird flach und einheitlich.
- **Zeile 2:** `filter-bar` mit Beschriftungen **neben** statt über den Feldern, Feldhöhe 36 px
  statt 44. Die `filter-card` der Statistik wird zur gewöhnlichen `filter-bar`; `stats-header`,
  `filter-card`, `filter-grid` und `.year-select` entfallen.
- **Wirkung:** Kopf von etwa 150 auf etwa 95 px, bei den Mitgliedern von 120 auf 85. Gemessen bei
  1280 px nach der Umsetzung: 169 → 108 px in fünf Ansichten, Arbeitszeit 212 → 125, Statistik
  242 → 110.
- **Zurücksetzen:** Auch die Statistik blendet den Knopf aus, solange kein Filter abweicht. Damit
  gilt die Regel überall gleich, und der Sondersatz dazu im CHANGELOG entfällt wieder.
  Eine für einfache Nutzer mit genau einer Gruppe **automatisch vorgewählte** Gruppe zählt dabei
  nicht als Abweichung; das Zurücksetzen stellt sie wieder her.

**Beim Bauen dazugekommen** (die Spec hielt es zuvor nicht fest):

- **Beschriftungen im Kopf nutzen `--text-medium`.** Mit `--text-light` lag der Kontrast bei etwa
  3,4:1 und damit unter WCAG AA. Das betrifft `.year-filter label`, die Beschriftungen der
  `filter-bar` und die Überschrift der flachen Kennzahl.
- **Die Kennzahl in der Chipzeile der Arbeitszeit ist flach** (`.stats-grid--chips-lead
  .stat-card`: kleineres Polster, 12 px Überschrift, 20 px Zahl). Sonst wäre diese Zeile mit 95 px
  fast doppelt so hoch wie in den übrigen Ansichten.
- **Die Quotenkarten der Statistik haben ein eigenes Raster** `.stats-grid--kpi` mit fester
  Kachelbreite. Ohne das zieht sich „Durchschnitt“ als einzige sichtbare Karte über die ganze
  Zeile. Ebenso tragen die Chipzeilen der Verwaltungstabellen innerhalb `.data-table` keine
  zweite Karte (`.data-table > .filter-chips--standalone`).

### Statistik

Vier **Anzeige-Chips** (`--static`) in Zeile 1: Termine (neutral), Anwesend (`--ok`),
Entschuldigt (`--pending`), Unentschuldigt (`--danger`). Sie ersetzen die gleichnamigen Karten.
Durchschnitt, Pünktlichkeit und Zuverlässigkeit **bleiben Karten** — sie sind das Ergebnis der
Ansicht, keine Zähler. Die beiden Quotenkarten bleiben wie bisher abschaltbar (`hidden`).

Anders als sonst bilden diese Chips **keine Partition**: „Termine“ zählt Termine, die übrigen
zählen Anwesenheitsdatensätze. Es gibt deshalb auch keinen Chip „Alle“.

### Gruppen, Terminarten, Tätigkeitsarten

Je eine **Anzeige-Chipzeile über der zugehörigen Tabelle**. Kein Filter, kein Jahr — diese
Ansichten haben beides nicht.

| Tabelle | Chips |
|---|---|
| Benutzergruppen | Alle · Hauptgruppen · Untergruppen (Wort aus `subgroupLabel()`, z. B. „Register“) |
| Terminarten | Alle · mit Rückmeldung · ohne Rückmeldung (`responses_enabled`) |
| Tätigkeitsarten | Alle · Aktiv · Ausgemustert (`is_active`) |

Diese drei Sätze sind Partitionen und werden wie die übrigen im Node-Test geprüft.

### Nicht Teil des Nachtrags

- Keine Filterfunktion in Statistik, Gruppen, Terminarten und Tätigkeitsarten — die Chips dort
  zählen nur. *(Für Gruppen, Terminarten und Tätigkeitsarten am 23.09. gekippt, siehe unten.)*
- Die Quotenkarten der Statistik bleiben unangetastet. *(Am 23.09. gekippt, siehe unten.)*
- Der Jahresfilter wandert nicht an eine andere Stelle.

## Nachtrag 2026-09-23, zweite Sichtung: Kennzahlen als Chips, Verwaltung filtert

Aus der Sichtprüfung des kompakten Kopfes. Acht Beobachtungen, davon fünf kleine und drei
grundsätzliche; die grundsätzlichen wurden entschieden und kehren zwei Punkte der Liste oben um.

### Kennzahlen werden Chips

- **Statistik:** Auch Durchschnitt, Pünktlichkeit und Zuverlässigkeit werden **Anzeige-Chips** mit
  ihrem Prozentwert. Der bisherige Erklärtext der Karte (`.stat-detail`, z. B. „Pünktlich bei 468
  von 1221 gemessenen Ankünften · …“) wandert in den **Tooltip** des Chips. Die Kartenzeile
  entfällt ganz, mit ihr `.stats-grid--kpi` aus dem ersten Nachtrag.
  Abgeschaltete Kennzahlen erscheinen gar nicht erst als Chip; bei zu wenigen Messungen zeigt der
  Chip wie bisher „–“ und die Begründung im Tooltip.
- **Arbeitszeit:** „Bestätigte Stunden“ wird ein Anzeige-Chip in derselben Zeile („Bestätigte
  Stunden 214:42 h“). Damit entfallen die Sonderspalte `.stats-grid--chips-lead` und die flache
  Kennzahlkarte aus dem ersten Nachtrag; der Kopf ist dort so hoch wie überall.
- In beiden Ansichten stehen damit **anzeigende und klickbare Chips nebeneinander**. Sie sind am
  Rand unterscheidbar: klickbare haben einen, anzeigende nicht.
- **`renderFilterChips` bekommt `def.title`** für den Tooltip; der Wert eines Chips darf ein Text
  sein („214:42 h“, „65,4 %“), nicht nur eine Zahl.

### Zurücksetzen bleibt sichtbar

Der Knopf verschwindet nicht mehr, sondern steht dauerhaft und ist im Ruhezustand **ausgegraut und
nicht bedienbar** (`disabled`). Grund: Beim Ein- und Ausblenden sprangen die Auswahlfelder daneben.
Das kehrt die Entscheidung des ersten Nachtrags um; `setResetVisible` heißt künftig
`setResetEnabled`. Betrifft alle Ansichten mit Filterleiste.

### Verwaltung filtert

Die Chipzeilen über Benutzergruppen, Terminarten und Tätigkeitsarten werden **klickbar** und
filtern ihre Tabelle — dasselbe Muster wie bei den Geräten, mit „Alle“ als Vorgabe und ohne
eigenen Zurücksetzen-Knopf. Sie verlieren damit `--static`.

### Geräte bekommen eine Filterleiste

Unter den Status-Chips steht künftig eine `filter-bar` mit dem Auswahlfeld **Typ**
(Alle · Standortgerät (TOTP) · Biometrie-Gerät · Station (Kiosk), aus `device_type`) und einem
Zurücksetzen-Knopf. Damit ist die Ansicht so aufgebaut wie die übrigen Listen.

### Kleinigkeiten

- **„Jahr:“** bekommt Schriftgröße und Farbe der übrigen Filterbeschriftungen (14 px,
  `--text-medium`) statt 13 px.
- **Termine:** Der Chip „Alle“ wird farblich hervorgehoben (`--info`), damit die Zeile nicht
  durchgehend grau ist.
- **Gruppen:** Der Chip für Untergruppen nutzt `--ok`, passend zum grünen Abzeichen derselben
  Zeile in der Tabelle.

### Geklärt, keine Änderung

Die vielen Einträge in den Import-Protokollen sind **Testrückstände**, kein Datenfehler: 43
Einträge `termine.csv` mit je einer Zeile, Zeitstempel exakt auf den Testläufen vom 22. und
23.09., erzeugt von `tests/suites/import_series_api.php`, das seine Protokollzeilen nicht
aufräumt. Vermerkt bei den Testrückständen in OI-90.

## Nachtrag 2026-09-23, dritte Sichtung: Durchschnitt je Gruppe

Sieben Chips sind für eine Kopfzeile zu viel. **Entschieden:**

- **„Durchschnitt“ verlässt den Kopf** und steht je Gruppe als eigene Anzeige-Chipzeile unter
  der Gruppenüberschrift („Durchschnitt 63,1 %“). Dort ist er aussagekräftiger, weil er für
  genau diese Gruppe gilt. Der Kopf führt noch **sechs** Chips: vier Zählwerte sowie
  Pünktlichkeit und Zuverlässigkeit.
- **Kein Serverweg dafür.** Die Zahl wird im Browser aus den Mitgliederzeilen gerechnet, die die
  API ohnehin liefert, und folgt derselben Definition wie `summary.overall_average`: anwesende
  geteilt durch mögliche Mitglied-Termin-Paare (`attendanceRate()`), **nicht** der Mittelwert
  der Mitgliederquoten. Ohne Paare steht „0 %“, wie es die Kopfzahl zuvor auch tat.
- **Der Tooltip reicht nicht allein.** Ein `title` ist auf Tastatur und Touch nicht erreichbar.
  Zeigt eine Quote „–“, erscheint die Begründung deshalb **zusätzlich als sichtbarer Text**
  unter der Chipzeile (`.filter-chips__hint`). Im Normalfall bleibt die Zeile leer — dort
  erklärt der Tooltip nur eine Zahl, die auch ohne ihn lesbar ist. Chips mit Erklärung tragen
  `cursor: help`.

## Version

Minor-Sprung auf **1.13.0**: `version.json`, `CHANGELOG.md`, `?v=` in allen vier Einstiegen und
ein leerer Migrationsschritt (Vorbild `private/migrations/1.6.0.php`), weil
`tests/suites/migrations.php` eine Kette bis zur aktuellen Version verlangt. OI-86 wird mit dem
Merge als erledigt markiert.
