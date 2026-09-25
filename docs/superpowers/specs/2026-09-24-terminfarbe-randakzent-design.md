# Terminfarbe als Randakzent statt Badge — Design

**Vorgang:** OI-94 · entworfen am 2026-09-24 · Grundlage ist der Stand nach 1.15.0 (`fbfc73b`)

## Ziel

Die Terminart steht heute im Dashboard als farbiges Schildchen in einer eigenen Spalte. Die
Check-in-App zeigt dieselbe Information seit jeher als farbigen linken Rand der Karte. Das
Dashboard zieht nach: Farbstreifen am linken Rand, Name der Terminart als Unterzeile, Schildchen
entfällt. Die Zeile gewinnt Platz, und mehrere Termine eines Tages gruppieren sich im
Kalender-Popup sichtbar.

## Entscheidungen des Nutzers (24.09.2026)

| Frage | Entscheidung |
|---|---|
| Wo bleibt der Name der Terminart? | **Unterzeile beim Termin**, Spalte „Terminart“ entfällt |
| Wie trennen sich mehrere Termine im Popup? | **Randstreifen und abgesetzter Block** je Termin |
| Rückmeldezeile als Knopf erkennbar? | **Dezenter Hinweis** — gepunktete Unterlinie und Linkfarbe, nur im angeklickten Popup |
| Farbe für Termine ohne Terminart | **Grau aus `variables.css`**, statt heute drei verschiedener Werte |
| Umfang | **Alle drei Ansichten in einem Zug** |
| Tastaturbedienung (OI-96) | **Struktur vorbereiten**, Bedienung bleibt OI-96 |

## Die drei Ansichten

### 1. Terminliste (`appointments.js`, `renderAppointments()`, ~Z. 300–310)

- Spalte „Terminart“ entfällt, samt Kopfzelle in `public/index.html`.
- Der Name rückt in die Unterzeile: `Auftritt · 26.9.2026, 19:00–22:00`. Fehlt die Terminart,
  entfällt der Namensteil ersatzlos — kein Wort „Allgemein“, kein Bindestrich.
- Die erste Zelle trägt den Streifen.

### 2. Anwesenheitsliste (`records.js`, `createAppointmentTypeBadge()`, ~Z. 1528–1548)

- Dieselbe Behandlung — aber die Funktion wird **aufgeteilt, nicht umgebaut**. Beim Planen kam
  heraus, dass sie **vier** Aufrufer hat, nicht drei: `records.js:1393` setzt das Schildchen in ein
  **Formularfeld** (Terminart des gewählten Termins beim Erfassen). Dort wäre ein Randstreifen
  sinnlos. Neu entsteht `appointmentTypeAccent()` für die beiden Listen;
  `createAppointmentTypeBadge()` bleibt für das Formularfeld erhalten und nutzt künftig
  ebenfalls `safeTypeColor()`.
- **Nicht anfassen:** die Statuszelle aus OI-89 (`attendanceStatusCell()`, `.attendance-upcoming`).
  Grauer Text und Farbstreifen müssen nebeneinander lesbar bleiben — das ist beim Browsertest zu
  prüfen, nicht durch Änderung an OI-89 zu lösen.

### 3. Kalender-Popup (`appointments.js`, `showAppointmentPopup()`, ~Z. 880–900)

- Jeder Termin wird ein Block mit Farbstreifen links und leicht abgesetztem Hintergrund.
- Die Reihenfolge im Block bleibt: Zeit, Titel, Unterzeile (Terminart · Ort), Rückmeldezeile,
  Anwesenheitszeile (1.14.0), Knopfreihe.
- Die **Rückmeldezeile** bekommt im angeklickten Popup (`fest === true`) eine gepunktete
  Unterlinie und Linkfarbe. Im Überfahr-Popup bleibt sie unverändert schlichter Text.

## Technische Festlegungen

**Streifen als `box-shadow`, nicht als `border-left`.** Auf `<tr>` greift `border-left` nur mit
`border-collapse: collapse` und kollidiert mit Zebrastreifung und Überfahr-Zustand. Stattdessen
`box-shadow: inset 4px 0 0 var(--type-color)` auf der ersten Zelle.

**Farbe als CSS-Variable, nicht als Inline-Stil.** Das Element trägt `style="--type-color: …"`,
das Aussehen liegt vollständig im Stylesheet. Damit steht der Wert aus der Datenbank an genau
einer Stelle im Markup.

**Farbprüfung an eine Stelle.** Heute steht `/^#[0-9a-f]{3,8}$/i` dreimal einzeln:
`appointments.js:305`, `appointments.js:888`, `records.js:1832`. Sie wandert als
`safeTypeColor(color)` nach `public/js/modules/utils.js` und liefert bei ungültiger oder fehlender
Farbe die neue Ersatzfarbe. **Das ist keine Kosmetik:** Ohne Inhaltssicherheitsrichtlinie (OI-17)
ist diese Prüfung die einzige Schranke gegen eingeschleustes Markup aus dem Farbfeld der
Terminart — drei Kopien sind drei Gelegenheiten, eine zu vergessen.

**Ersatzfarbe.** Neuer Eintrag in `variables.css` (Vorschlag `--type-color-none`, Wert wie
`--text-light`, `#7f8c8d`). Ersetzt `#667eea` (Dashboard, zweimal) und `#95a5a6` („Allgemein“).
`#1F5FBF` in der Check-in-App bleibt vorerst — siehe „Nicht in diesem Vorhaben“.

**Struktur für OI-96.** Die Popup-Blöcke bekommen ein eigenes Element je Termin mit klarer Grenze;
der Fokusrahmen wird im Stylesheet schon angelegt. `tabindex`, Enter/Space, Escape und die
Fokusrückgabe bleiben OI-96.

## Tests

- Statische Gegenproben in einer neuen Suite `tests/suites/type_accent_frontend.php`: Streifen
  und Unterzeile in allen drei Ansichten, kein `type-badge` mehr übrig, Farbe nur über die
  Variable, `safeTypeColor()` wird aus `utils.js` importiert und an allen drei Stellen benutzt.
- Eine Gegenprobe, dass die Farbprüfung greift: eine ungültige Farbe (`red; background:url(x)`)
  muss zur Ersatzfarbe führen.
- `module_imports` deckt den neuen Import mit ab.
- **Keine** bestehende Suite prüft auf `type-badge` (geprüft am 24.09.). Mitzuziehen sind
  dagegen die Suiten, die die drei geänderten Funktionen prüfen — vor dem Umbau ermitteln:
  `grep -rln "createAppointmentTypeBadge\|showAppointmentPopup\|renderAppointments" tests/suites/`.

## Browserprüfung

Admin und einfaches Mitglied, je: Terminliste mit und ohne Terminart, Anwesenheitsliste mit einem
Termin im Zustand „Kommend“ (grauer Text neben Farbstreifen), Popup mit drei Terminen an einem Tag,
Popup überfahren gegen angeklickt (Rückmeldezeile), ein Termin mit ungültiger Farbe in der
Datenbank. Dazu die Tabelle auf schmalem Fenster — die Spalte entfällt, die übrigen dürfen nicht
springen.

## Nicht in diesem Vorhaben

- **Tastaturbedienung** der Kalendertage und des Popups — OI-96, ausdrücklich danach.
- **Die Check-in-App.** Sie macht es bereits so und war das Vorbild. Ihre Ersatzfarbe `#1F5FBF`
  bleibt vorerst; eine gemeinsame Farbe über beide Anwendungen hinweg wäre ein eigener Schritt,
  weil die PWA ihre Farben nicht aus `variables.css` bezieht.
- **Der Zustand „Kommend“** aus OI-89 wird nicht verändert.
- **Sortierung nach Terminart.** Sie entfällt mit der Spalte. Der Filter „Terminart“ über der
  Liste bleibt und deckt den Bedarf ab.

## Offene Entscheidung, die beim Umbau anfällt

Die Entscheidung zur Rückmeldezeile **kippt eine frühere**: Der Kommentar in `calendar.css`
(~Z. 308) hält fest, dass Überfahr- und angeklickte Fassung absichtlich gleich aussehen — eine
Korrektur aus FI-1, weil der Unterschied damals als Fehler gemeldet wurde. Die neue Entscheidung
hebt das für das angeklickte Popup auf, bewusst leise (gepunktete Linie statt Knopfoptik).

**Das gehört nach `docs/project_history.md`**, nicht nur in diese Spec — sonst bekommt in zwei
Jahren jemand denselben Fehlerbericht und baut es zurück. Festzuhalten ist: was 2026 entschieden
wurde, warum es jetzt anders ist (ohne Maus war die Zeile nicht als bedienbar erkennbar), und dass
der Unterschied absichtlich klein bleibt.
