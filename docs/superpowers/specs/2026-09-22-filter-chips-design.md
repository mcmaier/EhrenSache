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

## Version

Minor-Sprung auf **1.13.0**: `version.json`, `CHANGELOG.md`, `?v=` in allen vier Einstiegen und
ein leerer Migrationsschritt (Vorbild `private/migrations/1.6.0.php`), weil
`tests/suites/migrations.php` eine Kette bis zur aktuellen Version verlangt. OI-86 wird mit dem
Merge als erledigt markiert.
