# Systemeinstellungen in Untertabs, Farbschwellen konfigurierbar

**Datum:** 2026-09-16
**Status:** Umgesetzt in 1.9.0 (Branch `feat/1.9.0-einstellungen`)
**Erledigt damit:** [OI-31](../../OPEN-ITEMS.md#oi-31--settingsjs-prüft-put-ergebnisse-nicht) (Restarbeit),
[OI-55](../../OPEN-ITEMS.md#oi-55--farbschwellen-der-anwesenheitsquote-sind-fest-verdrahtet) (global, nicht je Terminart)
**Bereitet vor:** [OI-62](../../OPEN-ITEMS.md#oi-62--feature-schalter-ohne-gemeinsame-prüfstelle) — diese Spec legt
das Muster fest, an dem die spätere Prüfstelle andockt, baut sie aber nicht
**Zielversion:** **1.9.0**, Migration `1.8.0.php` (1.8.0 → 1.9.0)
**Voraussetzung:** Das parallel entstandene Vorhaben **Untergruppen** (`2026-09-16-untergruppen-gliederung-design.md`,
Zielversion 1.8.0, Migration `1.7.0.php`) ist auf `dev` gemergt, einschließlich seines Manifest-Eintrags
1.7.0 → 1.8.0. Vorher wird der Eintrag dieses Vorhabens nicht angehängt. Abgestimmt am 2026-09-16:
getrennte Migrationen statt einer gemeinsamen, Reihenfolge Untergruppen zuerst.

---

## 1 Ausgangslage

Die Seite „Systemeinstellungen“ ist eine einzige Spalte aus zwölf Karten
([`public/index.html:241`](../../../public/index.html)), gewachsen in der Reihenfolge, in der die
Funktionen entstanden sind. „Paginierung“ steht zwischen Erscheinungsbild und
Anwesenheitserfassung, der Link zur Datenschutzerklärung unter „Organisation“, die Löschfristen
sechs Karten weiter unten. Mit jeder Funktion aus `FEATURE-IDEAS.md` wird die Spalte länger.

Drei Eigenschaften der heutigen Seite sind für den Umbau wichtig:

- **Speichern ist bereits sparsam.** `saveAllSettings()`
  ([`settings.js:207`](../../../public/js/modules/settings.js)) vergleicht jeden Wert mit dem
  geladenen Stand und schickt nur Abweichungen. Ein „Dirty-Tracking“ muss nicht erfunden werden.
- **OI-31 ist halb erledigt.** Die Schleife prüft inzwischen `result.success`, verlässt sich bei
  einem Fehlschlag aber stumm mit `return` — nachdem frühere Schlüssel schon gespeichert sind.
  Der Nutzer sieht den Fehler-Toast des einen Feldes und erfährt nicht, dass die anderen
  durchgingen. Auf einer Seite ist das lästig; mit Tabs liegt das abgelehnte Feld womöglich auf
  einem Tab, den niemand ansieht.
- **Jede Einstellung wird einzeln per `PUT` geschrieben.** Es gibt keinen Sammel-Endpunkt. Eine
  Regel über mehrere Schlüssel hinweg kann der Server deshalb nicht erzwingen.

Dazu kommt der zweite Teil dieser Spec. `rateBand()`
([`statistics.js:202`](../../../public/js/modules/statistics.js)) färbt Anwesenheitsquoten nach
vier festen Bändern (40/60/80). Das ist ein Urteil darüber, was gute Anwesenheit ist, und es
fällt je Verein verschieden aus (OI-55). **Beim Nachlesen gefunden, in OI-55 nicht vermerkt:**
Die Check-in-PWA färbt dieselbe Zahl nach einer **anderen** Skala — drei Bänder bei 50/75 mit
eigenen Hex-Werten ([`checkin/js/app.js:4272`](../../../public/checkin/js/app.js)). Dasselbe
Mitglied mit 55 % erscheint im Dashboard gelb und in der PWA orange.

---

## 2 Ziel

- Die Systemeinstellungen sind in **sieben Untertabs** gegliedert; sichtbar ist immer nur ein Tab.
- Jeder Funktionsbereich hat **einen** Tab, dessen erste Karte seinen Ein/Aus-Schalter trägt.
  Damit steht der Platz, an dem OI-62 später ansetzt, ohne die Seite erneut umzubauen.
- Ein fehlgeschlagenes `PUT` wird vom erfolgreichen unterschieden, das betroffene Feld und sein
  Tab werden markiert (OI-31 abgeschlossen).
- Die drei Farbschwellen der Anwesenheitsquote sind eine Systemeinstellung mit den heutigen
  Werten als Vorgabe. Dashboard **und** PWA lesen dieselben Werte.
- Keine Funktion ändert ihr Verhalten. Wer nichts umstellt, sieht nach dem Update dieselben
  Felder mit denselben Werten, nur anders sortiert.

---

## 3 Entscheidungen

### 3.1 Untertabs, nicht Seitenleisten-Einträge und nicht Sprungmarken

Die Tab-Leiste sitzt im Inhaltsbereich unter der Überschrift „Systemeinstellungen“, der
Speichern-Knopf bleibt daneben im Kopf stehen.

**Verworfen:** jede Gruppe als eigener Punkt in der System-Navigation — aus fünf Einträgen
würden elf, und die Seitenleiste ist im Querformat ohnehin knapp (OI-53). **Ebenfalls
verworfen:** eine Seite mit Sprungmarken oder aufklappbaren Bereichen; das löst das Scrollen nur
halb und lässt die Seite weiter unbegrenzt wachsen.

### 3.2 Ein Tab je Funktionsbereich, Schalter zuerst — das Muster für OI-62

Verbindlich für jede künftige Funktion mit eigenem Schalter:

1. Die Funktion bekommt **einen** Tab (oder eine Karte in einem thematisch passenden Tab, wenn
   sie zu klein für einen eigenen ist).
2. Die **erste Karte** dieses Tabs trägt die Beschreibung der Funktion und ihren
   Ein/Aus-Schalter.
3. Die abhängigen Parameter stehen darunter und werden ausgegraut, solange der Schalter aus ist
   (`disabled` am Eingabefeld, nicht bloß blasse Farbe — sonst wird ein Wert gespeichert, den
   niemand liest).
4. Der Datenschutzhinweis bleibt **beim Schalter**, nicht in einem Sammeltab.

**Verworfen: ein Tab „Funktionen“, der alle Schalter sammelt.** Das sieht aufgeräumt aus, trennt
aber jeden Schalter von seinen Parametern und seiner Warnung. Bei Pünktlichkeit und
Zeiterfassung — den beiden Schaltern, vor deren Aktivierung `DATENSCHUTZ.md` zu lesen ist — wäre
das genau der falsche Ort.

**Nicht Teil dieser Spec:** die gemeinsame Prüfstelle aus OI-62 (Registrierung der Funktionen,
403 statt bloßem Verstecken, Schalter für Terminplanung und Anwesenheitserfassung). Das ist
Backend-Arbeit mit Migration und eigener Spec. OI-62 empfiehlt beides in einem Zug, „sonst wird
die Einstellungsseite zweimal angefasst“. **Dem wird hier bewusst nicht gefolgt:** Das zweite
Anfassen kostet, wenn das Muster aus 3.2 steht, eine zusätzliche Karte in einem vorhandenen Tab.
Das Zusammenlegen würde dagegen einen reinen Frontend-Umbau an eine offene Backend-Frage binden.

### 3.3 Speichern bleibt global, Fehler werden je Feld und Tab gemeldet

Ein Knopf speichert alles, auch was auf anderen Tabs geändert wurde. Ein Speichern je Tab wäre
die naheliegende Alternative, verlangt aber vom Nutzer zu wissen, wo er überall etwas geändert
hat.

Neu ist die Behandlung danach:

- Die Schleife bricht bei einem Fehlschlag **nicht** ab, sondern arbeitet die übrigen Schlüssel
  ab und sammelt die gescheiterten ein.
- Der Abschluss-Toast nennt beides: „3 gespeichert, 1 abgelehnt“.
- Jedes abgelehnte Feld bekommt die vorhandene Klasse `invalid`, sein Tab einen Punkt, und die
  Ansicht springt auf den ersten Tab mit einem Fehler.
- `hasUnsavedChanges` bleibt gesetzt, solange etwas offen ist.

Damit ist OI-31 erledigt.

### 3.4 Der aktive Tab lebt in `sessionStorage`, keine Adress-Navigation

Beim ersten Vorschlag am 2026-09-15 standen Deep-Links wie `#einstellungen/datenschutz` in
Aussicht. **Das wird gestrichen.** Die Anwendung kennt keine Adress-Navigation: Der aktive
Bereich steht in `sessionStorage['currentSection']`
([`ui.js:747`](../../../public/js/modules/ui.js)), `location.hash` wird nirgends ausgewertet.
Hash-Routing nur für die Einstellungen einzuführen hieße, zwei Zustandsmodelle nebeneinander zu
betreiben.

Stattdessen: Der zuletzt gewählte Tab steht in `sessionStorage['settingsTab']` und wird beim
Betreten des Bereichs wiederhergestellt. Für Verweise aus der Oberfläche exportiert
`settings.js` eine Funktion `showSettingsTab(key)`.

### 3.5 Beim Verlassen mit offenen Änderungen wird gefragt

Heute geht ein ungespeicherter Wert beim Wechsel des Bereichs kommentarlos verloren. Mit Tabs
wird das wahrscheinlicher, weil weniger sichtbar ist. `settings.js` exportiert
`hasUnsavedSettings()`; der Navigationswechsel in `ui.js` fragt über das vorhandene
`showConfirm()` nach, bevor er den Bereich verlässt. Ein Tabwechsel **innerhalb** der
Einstellungen fragt nicht — die Werte bleiben im Formular stehen und werden beim Speichern
mitgeschickt.

### 3.6 Sofort wirkende Knöpfe werden gekennzeichnet

„Alte Daten löschen“, „Test senden“, „Auf Updates prüfen“, Logo-Upload und der SMTP-Dialog
wirken ohne Speichern. Sie bekommen eine einheitliche Kennzeichnung („wirkt sofort“) direkt am
Knopf. Die Bereinigung weist zusätzlich darauf hin, wenn die angezeigten Fristen von den
gespeicherten abweichen.

**Korrektur beim Umsetzen (2026-09-16):** Der letzte Satz dieser Entscheidung war falsch. Er
behauptete, die Bereinigung liefe „mit den alten Werten“. Tatsächlich liest `performCleanup()`
die Zahlen aus den **Formularfeldern** — gelöscht wird also nach einem Wert, der nirgends
hinterlegt ist. Das ist der gefährlichere Fall, und genau so steht der Hinweis jetzt im
Bestätigungsdialog.

### 3.7 Farbschwellen: drei Zahlen, global, mit Vorgabe wie heute

Neue Schlüssel in `system_settings`, alle `number`/`general`:

| Schlüssel | Vorgabe | Bedeutung |
|---|---|---|
| `rate_threshold_mid` | `40` | ab hier Orange statt Rot |
| `rate_threshold_fair` | `60` | ab hier Gelb |
| `rate_threshold_good` | `80` | ab hier Grün |

**Global, nicht je Terminart** (Entscheidung vom 2026-09-16). Schwellen je Terminart wären
fachlich verteidigbar — für einen Auftritt gilt eine andere Erwartung als für eine
Registerprobe —, kosten aber eine Zuordnungstabelle samt Pflegeoberfläche statt dreier Zahlen.
Wenn ein Verein den Bedarf meldet, ist das eine eigene Spec; die Schlüssel hier bleiben dann als
Vorgabewert bestehen.

**Die Farben selbst bleiben fest.** Rot für „schlecht“ ist eine Konvention, an der zu drehen
mehr schadet als nützt (so schon in OI-55).

**Gültigkeit.** Jeder Wert ist eine ganze Zahl von 1 bis 99. Die Reihenfolge
`mid < fair < good` kann der Server nicht erzwingen, weil jeder Schlüssel einzeln geschrieben
wird — ein Zwischenstand verletzt die Regel zwangsläufig. Deshalb zweistufig:

- **Oberfläche:** prüft die Reihenfolge vor dem Absenden, markiert die betroffenen Felder und
  speichert gar nichts, solange sie verletzt ist.
- **Leser:** `rateBands()` sortiert die drei gelesenen Werte aufsteigend und fällt bei fehlendem
  oder unbrauchbarem Wert auf 40/60/80 zurück. Ein von Hand in der Datenbank verdrehter Wert
  ergibt damit nie eine unsinnige Färbung.

### 3.8 Die Schwellen kommen über den Statistik-Payload, nicht über einen neuen Lesepfad

`loadSystemSettings()` ist Admins vorbehalten, die Statistik sehen alle Rollen. Statt die drei
Schlüssel in die Whitelist von `settings?scope=client` aufzunehmen, liefert der
Statistik-Endpunkt sie als Block `rate_bands` mit — dem Muster von `punctualityBlocks()`
folgend, das den Zustand seiner Schalter ebenfalls im Payload mitschickt
([`punctuality.php:373`](../../../private/helpers/punctuality.php)). Dashboard und PWA rufen
diesen Endpunkt ohnehin auf; es entsteht keine zusätzliche Anfrage.

### 3.9 Die PWA übernimmt dieselben vier Bänder

Die eigene Skala der PWA (50/75, drei Hex-Farben) entfällt. Sie nutzt `rate_bands` aus dem
Statistik-Payload und vier CSS-Klassen in ihrem eigenen `css/style.css` — die PWA erbt die
Stylesheets des Dashboards nicht.

---

## 4 Die sieben Tabs

| Tab | Karten (Reihenfolge) | Herkunft |
|---|---|---|
| **Allgemein** | Organisation, Erscheinungsbild, Paginierung, Bezeichnung der Untergruppen | ohne Datenschutz-URL; die Untergruppen-Karte kommt aus 1.8.0 |
| **Termine & Anwesenheit** | Anwesenheitserfassung · Terminrückmeldungen · Pünktlichkeit und Zuverlässigkeit · **Bewertung der Anwesenheitsquote** (neu) | zusammengezogen |
| **Zeiterfassung** | Zeiterfassung | unverändert |
| **Stationen** | Stations-Anmeldung (PIN) | unverändert |
| **Datenschutz** | **Datenschutzerklärung (URL)** · Löschfristen und Bereinigung | URL kommt aus „Organisation“ |
| **E-Mail** | E-Mail-Einstellungen mit SMTP und Test-Mail | unverändert |
| **System** | Updates | unverändert |

Zwei Zuordnungen, die auch anders ausfallen könnten:

- **Die Farbschwellen stehen bei „Termine & Anwesenheit“, nicht bei „Erscheinungsbild“.** Sie
  sehen nach Darstellung aus, sind aber ein Urteil über Anwesenheit — und gehören neben die
  beiden anderen Karten, die Verhalten bewerten. „Erscheinungsbild“ bleibt Branding.
- **Die Datenschutz-URL wandert zu „Datenschutz“.** Sie steht heute bei der Organisation, weil
  sie bei der Registrierung verlinkt wird. Wer sie sucht, sucht sie beim Datenschutz.

Aus dem Untergruppen-Vorhaben kommt in 1.8.0 die Karte mit `subgroup_label` („Bezeichnung der
Untergruppen“) dazu. Sie wird dort flach wie bisher gebaut und hier nach **Allgemein**
verschoben — es ist eine Benennung, keine Funktion mit Schalter. Abgestimmt am 2026-09-16; jene
Spec fasst `settings.js` nur um dieses eine Textfeld an und den Einstellungsbereich in
`index.html` gar nicht.

---

## 5 Umsetzung

### 5.1 `public/index.html`

- Zwischen Kopf und `.settings-container` eine Tab-Leiste:
  `<div class="settings-tabs">` mit sieben `<button class="settings-tab-btn" data-settings-tab="…">`.
- Jeder Tab ein Panel `<div class="settings-panel" data-settings-panel="…">`, das die
  vorhandenen `.settings-card`-Blöcke **unverändert** aufnimmt. Die Karten werden verschoben,
  nicht neu geschrieben — jedes `data-key`, jede `id` und jeder Hinweistext bleibt, damit die
  bestehenden Frontend-Suiten weiter greifen.
- Neue Karte „📊 Bewertung der Anwesenheitsquote“ mit drei Zahlenfeldern
  (`min="1" max="99" step="1"`) und einer Zeile, die die vier Bänder in den echten Farben
  vorführt.
- `?v=` an allen Asset-Links auf 1.9.0 (erzwungen von `tests/suites/assets.php`).

### 5.2 CSS

Neuer Block in [`public/css/sections/settings.css`](../../../public/css/sections/settings.css)
für `.settings-tabs`, `.settings-tab-btn`, `.settings-panel` sowie den Fehler- und
Änderungspunkt am Tab. Anleihe bei `.nav-tab-btn`
([`sidebar.css:81`](../../../public/css/sections/sidebar.css)), aber eigene Klassen — die
Leisten stehen an verschiedenen Orten und sollen sich unabhängig ändern lassen.

Schmale Fenster: Die Leiste scrollt waagerecht (`overflow-x: auto`), Trefferflächen mindestens
44 px, kein Umbruch in zwei Zeilen. Gegenproben bei 390x844 und 844x390 (siehe OI-53).

### 5.3 `public/js/modules/settings.js`

- `setupSettingsTabs()`: Klick schaltet um, merkt den Tab in `sessionStorage['settingsTab']`,
  `showSettingsTab(key)` exportiert dasselbe für Aufrufe von außen.
- `renderSystemSettings()` stellt den gemerkten Tab wieder her (Rückfall: erster Tab).
- `saveAllSettings()`: Schleife sammelt Fehlschläge statt abzubrechen (3.3); Zahlenfelder
  speichern den **geprüften** Wert statt `input.value` — heute wird nach der Prüfung der
  ungetrimmte Rohstring geschickt (Teilbefund aus OI-21; die Frage Stunden/Minuten bleibt dort
  offen und wird hier nicht angefasst).
- Reihenfolge-Prüfung der drei Schwellen vor dem Absenden.
- `hasUnsavedSettings()` exportiert den Zustand für `ui.js` (3.5).
- Abhängige Felder ausgrauen, wenn ihr Schalter aus ist (3.2, Punkt 3).

### 5.4 Backend

- [`private/handlers/settings.php`](../../../private/handlers/settings.php): `PUT` validiert die
  drei neuen Schlüssel wie `response_deadline_hours` — ganze Zahl 1–99, normalisiert
  gespeichert, sonst 400 mit deutscher Meldung.
- Neuer Helfer `rateBands($db, $database): array{mid:int, fair:int, good:int}` in
  [`private/helpers/utils.php`](../../../private/helpers/utils.php): liest, klammert, sortiert,
  fällt auf 40/60/80 zurück (3.7).
- [`private/handlers/statistics.php`](../../../private/handlers/statistics.php) hängt
  `'rate_bands' => rateBands(...)` an die Antwort — auf derselben Ebene wie `punctuality` und
  `reliability`.

### 5.5 Anzeige

- `rateBand(rate, bands)` in `statistics.js` nimmt die Bänder als Parameter; der Aufrufer reicht
  `statsData.rate_bands` durch, mit denselben Vorgaben als Rückfall.
- `checkin/js/app.js`: Die drei Hex-Werte weichen den vier Klassen; `checkin/css/style.css`
  bekommt sie. Damit gilt eine Skala für beide Oberflächen (3.9).
- Die Kommentare in `statistics.css` und `statistics.js`, die 40/60/80 als feste Werte
  beschreiben, werden nachgezogen.

### 5.6 Datenbank

- Migration `private/migrations/1.8.0.php` mit `migrate_1_8_0`: drei `INSERT IGNORE` auf
  `system_settings`. Idempotent, keine Schemaänderung. Ein bereits gesetzter Wert bleibt.
- Manifest-Eintrag `1.8.0 → 1.9.0`, angehängt **nach** dem Eintrag 1.7.0 → 1.8.0 der
  Untergruppen. Liegt der noch nicht auf `dev`, wird hier nichts angehängt.
- `private/setup/ehrensache_db.sql` um dieselben drei Zeilen ergänzen — sonst startet eine
  Neuinstallation mit leeren Zahlenfeldern, und ein leeres Pflichtfeld blockiert das Speichern
  **aller** Einstellungen (derselbe Fallstrick wie bei `response_deadline_hours`).

---

## 6 Tests

Neue Suite `tests/suites/settings_tabs_frontend.php` nach dem Muster von
`responses_frontend.php`:

1. Jede `.settings-card` liegt in genau einem Panel — keine Karte fällt beim Verschieben aus der
   Struktur.
2. Zu jedem Tab-Knopf gibt es ein Panel und umgekehrt.
3. Jedes `data-key` im HTML hat eine Vorgabe in `ehrensache_db.sql` (fängt 5.6 ab).
4. Die drei Schwellenfelder tragen denselben Bereich wie die Serverprüfung.

Erweitert:

- `tests/suites/statistics_unit.php`: `rateBands()` klammert, sortiert und fällt zurück.
- `tests/suites/migrations.php`: Kettenlauf bis 1.9.0, Schlüssel vorhanden, zweiter Lauf
  unschädlich.
- `tests/suites/assets.php` greift automatisch, sobald `version.json` steht.

Manuell nach `docs/testplan.md`: Tab wechseln und wiederfinden, Änderung auf Tab A speichern
während Tab B sichtbar ist, absichtlich abgelehnter Wert (Schwellen verdreht) mit Sprung auf den
richtigen Tab, Bereich verlassen mit offener Änderung, Statistik und PWA mit verschobenen
Schwellen nebeneinander.

---

## 7 Dokumentation

- `API.md`: `rate_bands` im Statistik-Abschnitt, die drei Schlüssel bei `settings`.
- `CHANGELOG.md` und `version.json` auf 1.9.0.
- `docs/OPEN-ITEMS.md`: OI-31 und OI-55 als erledigt mit Datum; bei OI-62 vermerken, dass das
  Muster steht und nur noch die Prüfstelle fehlt; bei OI-21 den erledigten Teilbefund notieren.
- `docs/testplan.md`: Abschnitt „Einstellungen“ um die Tabs erweitern.

---

## 8 Nicht Teil dieser Spec

- **OI-62** vollständig: Registrierung der Funktionen, 403 für abgeschaltete Ressourcen,
  Schalter für Terminplanung und Anwesenheitserfassung (eigene Spec, direkt danach).
- **Schwellen je Terminart** (3.7).
- **OI-21** Stunden → Minuten beim Zuordnungsfenster; wartet weiter auf einen gemeldeten Bedarf.
- **Der Nutzerbereich.** „Mein Profil“ wird zu „Mein Konto“ mit eigenen Tabs, sobald es Inhalt
  dafür gibt. Auslöser ist inzwischen eher
  [FI-17](../../FEATURE-IDEAS.md#fi-17--offene-punkte-unter-mein-konto) — eine Sammelkarte
  offener Punkte füllt einen Tab von selbst — als
  [FI-8](../../FEATURE-IDEAS.md#fi-8--kalender-abo-ics-feed), das nur eine Zeile zu den Zugängen
  legt. Leere Tabs werden nicht auf Vorrat gebaut.
- **Zwei Kleinigkeiten im Profil**, dabei aufgefallen und hier nur notiert: Die Karte heißt
  „API-Token für Zeiterfassung“, der Text darunter spricht von der Check-in-App; die
  Formatauswahl bei „Meine Daten“ sieht gesperrt aus, ist aber bedienbar.

---

## 9 Reihenfolge

1. Tab-Gerüst in HTML und CSS, Karten unverändert verschieben — danach muss die Seite sich
   genau wie vorher verhalten.
2. `settings.js`: Tabwechsel, gemerkter Tab, Ausgrauen abhängiger Felder.
3. OI-31: Fehlersammlung, Teilerfolg im Toast, Sprung auf den fehlerhaften Tab.
4. Warnung beim Verlassen, Kennzeichnung der sofort wirkenden Knöpfe.
5. Farbschwellen: Migration, Schema, Serverprüfung, `rateBands()`, Payload.
6. Anzeige in Dashboard und PWA auf eine Skala ziehen.
7. Tests, Dokumentation, Versionssprung.

Schritt 1 bis 4 ist reines Frontend und für sich lauffähig; Schritt 5 und 6 hängen an der
Migration. Wenn der Umbau geteilt werden soll, ist genau dort die Naht.
