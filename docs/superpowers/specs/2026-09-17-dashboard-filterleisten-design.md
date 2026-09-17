# Design: Filterleisten im Dashboard vereinheitlichen

**Datum:** 2026-09-17
**Status:** Approved
**Betrifft:** Terminverwaltung, Mitgliederverwaltung, Statistik — reine Oberfläche, kein Backend

## Problem

Drei Beobachtungen aus dem Betrieb, die dieselbe Wurzel haben:

1. **Die Terminverwaltung lässt sich nicht nach Terminart filtern.** Sie ist der einzige
   Listenbereich ohne `div.filter-bar`.
2. **Die Checkbox „Nur automatisch erzeugte anzeigen" sitzt in einer Kennzahlkarte**
   (`index.html`, Bereich `#termine`) — ein Bedienelement in einer Anzeigefläche.
3. **In der Mitgliederverwaltung belegt dieselbe Bauweise eine volle Karte** für eine selten
   genutzte Funktion: Zahl plus Checkbox „Anzeigen".

Dazu kommt ein vierter Punkt aus derselben Sichtung: **In der Statistik klebt „Filter
zurücksetzen" ohne Abstand unter den Dropdowns** und steht als einziger Reset-Knopf des
Projekts in einer eigenen Zeile.

Die gemeinsame Frage lautet: *Wo gehört eine Filtereinstellung hin, und was genau begrenzt
sie?* Sie ist im Bestand dreimal verschieden beantwortet worden.

### Herkunft der Auto-Checkbox

Die Checkbox stammt aus OI-20. Dort wurde entschieden, Auto-Termine „erst sichtbar zu machen,
dann zu sehen, ob es reicht". Die Funktion bleibt nötig — nur ihr Platz war eine
Verlegenheitslösung.

### Vorgeschichte in der Mitgliederverwaltung

Commit `9ba1b3a` („fix: minor design changes", 2026-09-04) hat die Checkbox
`show_inactive_members` **aus** der Filterleiste **in** die Kennzahlkarte verschoben. Der alte
Block steht seither auskommentiert im Markup, die dafür geschriebene CSS-Regel
`.filter-bar .form-group:has(input[type="checkbox"])` (`components/forms.css`) ist tot.

Der Grund von damals ist geklärt: Die Filterleiste der Mitglieder enthielt nur ein einziges
Feld und wirkte dadurch leer. Mit Gruppenfilter **und** Checkbox stehen künftig zwei Elemente
darin — der Einwand entfällt.

## Entscheidungen

| Frage | Entscheidung | Begründung |
|---|---|---|
| Aufbau des Terminkopfs | Kennzahlkarten unverändert, neue `filter-bar` darunter | Wie Anwesenheit, Anträge, Zeiterfassung |
| Jahresfilter | **bleibt als Karte oben rechts** | Bewusste Konvention über alle Module; nicht Teil dieses Vorhabens |
| Auto-Filter | Checkbox wird **Select mit drei Zuständen** | „Nur von Hand angelegte" fehlt heute und ist die Richtung, die OI-20 für die Bestandsprüfung braucht |
| Kalender | **filtert mit** | Der Jahresfilter wirkt über `setCalendarToYear()` bereits auf den Kalender; der Auto-Filter ist die einzige Ausnahme |
| Kennzahlkarten | zählen den gefilterten Ausschnitt | Wie heute, und mit dem mitfilternden Kalender widerspruchsfrei |
| Inaktive Mitglieder | Checkbox in die Filterleiste, Karte behält nur die Zahl | Gleiche Regel wie bei den Terminen; Kennzahlgitter behält „eine Karte, eine Zahl" |
| Statistik-Reset | Filterkarte wird Flex-Container, Knopf rechts unten bündig | Knopf steht dann projektweit an derselben Stelle relativ zu den Feldern |

**Verworfen:** Den Jahresfilter mit in die Filterleiste zu nehmen. Das hätte den sechsfach
wiederholten Kartenblock aufgelöst, widerspricht aber der Konvention, dass er in jedem Modul
an derselben Stelle steht.

**Verworfen:** Herausgefilterte Termine im Kalender blass darzustellen statt auszublenden.
Freundlicher, fügt aber Logik hinzu, statt vorhandene aufzuräumen — und wirft eine Frage auf,
die es heute nicht gibt: Der Farbrand eines Tages stammt vom *ersten* Termin; welche Farbe
bekäme ein Tag, an dem nur herausgefilterte Termine liegen? Bleibt als möglicher Ausbau, falls
sich zeigt, dass der Überblick fehlt.

## Änderungen

### 1. Terminverwaltung — Markup (`public/index.html`, Bereich `#termine`)

Die Karte „Automatisch erzeugte" entfällt. Das Kennzahlgitter behält drei Karten: „Vergangene
Termine", „Kommende Termine", „Jahr filtern".

Darunter, **vor** `#appointmentsPagination`, eine neue Leiste im Aufbau der übrigen Bereiche:

```html
<div class="filter-bar">
    <div class="form-group">
        <label for="filterAppointmentType">Terminart:</label>
        <select id="filterAppointmentType">
            <option value="">Alle Terminarten</option>
        </select>
    </div>
    <div class="form-group" data-role="manager">
        <label for="filterAppointmentOrigin">Herkunft:</label>
        <select id="filterAppointmentOrigin">
            <option value="">Alle</option>
            <option value="auto">Nur automatisch erzeugte</option>
            <option value="manual">Nur von Hand angelegte</option>
        </select>
    </div>
    <button id="resetAppointmentFilter" class="btn-reset-filter">Filter zurücksetzen</button>
</div>
```

`data-role="manager"` am Herkunftsfeld folgt `filterWorktimeMember` in der Zeiterfassung. Der
Terminart-Filter ist für alle Rollen sichtbar — ein Nutzer sieht ohnehin nur Termine seiner
Gruppen und profitiert von der Eingrenzung.

Der Inline-`onchange` der alten Checkbox entfällt ersatzlos; die Verdrahtung erfolgt wie in
`records.js` über `addEventListener`.

### 2. Terminverwaltung — Modul (`public/js/modules/appointments.js`)

**Befüllung.** Neue Funktion `fillAppointmentFilters(forceReload = false)`, die den
Terminart-Select aus `loadTypes()` aufbaut. Sie übernimmt die Gruppenprüfung aus
`records.js` (`filterAptType`): Für Nutzer ohne Manager-Rechte werden Terminarten ohne
Gruppenzuordnung und solche ohne Schnittmenge mit den eigenen Gruppen ausgelassen. Die
aktuelle Auswahl wird vor dem Neuaufbau gesichert und danach wiederhergestellt, sofern sie
noch existiert.

Aufgerufen wird sie am Anfang von `showAppointmentSection()`, wie `loadRecordFilters()` in
`showRecordsSection()`. **Neu aufgebaut wird die Liste nur bei `forceReload` oder solange der
Select außer der Vorgabe keine Einträge hat.** Ohne diese Bedingung liefe die Befüllung bei
jedem Filterwechsel erneut — `showAppointmentSection()` ist zugleich der Einstiegspunkt der
Filter-Listener.

**Filterung.** `showAppointmentSection()` liest beide Werte weiterhin aus dem DOM — das ist
notwendig, weil `loadYearDependentData()` (`ui.js`) die Funktion ohne Parameter aufruft:

```js
const typ     = document.getElementById('filterAppointmentType')?.value || '';
const herkunft = document.getElementById('filterAppointmentOrigin')?.value || '';

let gefiltert = appointmentData || [];

if (typ) {
    gefiltert = gefiltert.filter(a => String(a.type_id) === typ);
}
if (herkunft === 'auto') {
    gefiltert = gefiltert.filter(a => Number(a.is_auto_created) === 1);
} else if (herkunft === 'manual') {
    gefiltert = gefiltert.filter(a => Number(a.is_auto_created) !== 1);
}
```

Die Filterung bleibt clientseitig auf dem Jahres-Cache. Der vorhandene Serverparameter
`?type_id=` in `appointments.php` bleibt ungenutzt: Die Termine eines Jahres liegen ohnehin
vollständig im Cache, ein Serverabruf je Filterwechsel wäre ein Rückschritt.

**Kalender.** `renderAppointments()` legt die gefilterte Liste in einer Modulvariablen
`calendarAppointments` ab; `renderCalendar()` liest sie und reicht sie an `createCalendarDay()`
weiter. Beide greifen anschließend **nicht mehr** auf `dataCache.appointments[currentYear].data`
zu.

Eine Modulvariable und nicht bloß ein Parameter, weil `renderCalendar()` an drei Stellen ohne
Argumente aufgerufen wird — neben `renderAppointments()` auch aus `previousMonth()` und
`nextMonth()`, die beim Monatsblättern keinen Zugriff auf die gefilterte Liste haben. Ein
Parameter allein würde dort `undefined` liefern und den Kalender beim Blättern leeren.

Das ist der einzige strukturelle Eingriff des Vorhabens: Der Kalender hängt heute am globalen
Cache und ist dadurch von der Filterung abgekoppelt; danach hängt er an einer Variablen, die
genau eine Stelle setzt.

`showAppointmentPopup()` bekommt die Tagesliste bereits als Parameter aus `createCalendarDay()`
und filtert dadurch automatisch mit — dort ist kein Eingriff nötig.

**Zurücksetzen.** Neue Funktion `resetAppointmentFilter()`: setzt beide Selects auf `''` und
ruft `showAppointmentSection(false, 1)`. Die Rückkehr auf Seite 1 entspricht dem Verhalten von
`resetRecordFilter()`.

**Jahreswechsel.** Der Terminart-Filter bleibt erhalten. `ui.js` setzt beim Jahreswechsel nur
für `anwesenheit` den Filter zurück, und das zu Recht: Dort wird nach einem **konkreten
Termin** gefiltert, den es im anderen Jahr nicht gibt. Terminarten sind jahresunabhängig — an
`initAllYearFilters()` ändert sich nichts.

**Nebenbei:** `updateAppointmentStats(0)` wird zu `updateAppointmentStats([])`. Der heutige
Aufruf im Zweig ohne Daten würde `0.forEach` auslösen; erreichbar ist er derzeit nicht, aber er
ist ein Stolperstein für jede Änderung an der Ladefunktion.

### 3. Mitgliederverwaltung (`public/index.html`, Bereich `#mitglieder`)

Die Karte „Inaktive Mitglieder" behält Überschrift und `#statInactiveMembersCount`; das
`<label>` mit der Checkbox entfällt dort.

Der auskommentierte Block in der Filterleiste wird **ersetzt, nicht ergänzt** — andernfalls
existiert `show_inactive_members` zweimal im Dokument und `getElementById()` trifft das falsche
Element. Aufbau nach heutigem Stand der Leiste:

```html
<div class="form-group">
    <label>
        <input type="checkbox" id="show_inactive_members">
        Inaktive anzeigen
    </label>
</div>
```

`members.js` bleibt unverändert — die Logik greift bereits über `getElementById` und ist vom
Ort im Markup unabhängig.

**CSS:** `.filter-bar .form-group:has(input[type="checkbox"])` und `.filter-bar .checkbox-label`
(`components/forms.css`) werden dadurch erstmals wirksam. Beide sind zu prüfen und
gegebenenfalls nachzuziehen; `.checkbox-label` setzt heute feste 16×16 Pixel, was für ein
`<label>` statt der Box gedacht gewesen sein dürfte.

### 4. Statistik (`public/css/sections/content.css`)

`.filter-card` wird zum Flex-Container:

```css
.filter-card {
    display: flex;
    gap: var(--spacing-lg);
    align-items: flex-end;
    flex-wrap: wrap;
}
.filter-card .filter-grid { flex: 1; }
```

Das innere Grid bleibt unverändert, der Reset-Knopf rückt als letztes Flex-Kind nach rechts und
sitzt unten bündig — dasselbe Bild wie `.filter-bar`. Kein Markup-Umbau. Auf schmalen
Bildschirmen bricht der Knopf per `flex-wrap` um; das ist in `responsive.css` gegenzuprüfen.

Die Jahreskarte daneben wird nicht angefasst.

## Prüfung

### Neue Suite `tests/suites/dashboard_filter_frontend.php`

Statische Gegenproben nach dem Vorbild von `settings_tabs_frontend.php`:

1. Der Bereich `#termine` enthält genau eine `div.filter-bar` mit `filterAppointmentType`,
   `filterAppointmentOrigin` und `resetAppointmentFilter`.
2. `appointmentAutoFilter` kommt in `index.html` nicht mehr vor — verhindert, dass die alte
   Karte unbemerkt zurückkehrt.
3. `show_inactive_members` kommt in `index.html` **genau einmal** vor. Das ist die Absicherung
   gegen die doppelte ID, wenn jemand den auskommentierten Block entkommentiert, statt ihn zu
   ersetzen.
4. `createCalendarDay` und `renderCalendar` enthalten in `appointments.js` keinen Zugriff auf
   `dataCache.appointments` — der Beleg, dass der Kalender seine Daten als Parameter bekommt.
5. Jede der drei neuen IDs hat sowohl im Markup als auch im Modul eine Entsprechung.

### Manuelle Gegenprobe (`docs/testplan.md`)

Was statische Tests nicht abdecken:

- Terminart wählen → Tabelle, Kalender und Kennzahlkarten zeigen denselben Ausschnitt
- Herkunft „Nur von Hand angelegte" → automatisch erzeugte Termine verschwinden aus beiden
  Ansichten
- Jahr wechseln → Terminart-Filter bleibt erhalten, Kalender springt ins neue Jahr
- „Filter zurücksetzen" → beide Selects leer, Seite 1, vollständige Liste
- Als Rolle `user` anmelden → Herkunftsfeld unsichtbar, Terminartliste auf eigene Gruppen
  beschränkt
- Mitglieder: „Inaktive anzeigen" wirkt wie zuvor, Karte zeigt weiter die richtige Zahl
- Statistik: Reset-Knopf steht rechts neben den Feldern, auch bei ausgeblendetem
  Mitgliedsfilter und auf schmalem Bildschirm

## Version und Auslieferung

**Ziel: 1.9.2** — reine Oberflächenkorrekturen, keine Schema-, keine API-Änderung. `version.json`
und `CHANGELOG.md` gemeinsam pflegen, `?v=` in `public/index.html` nachziehen, damit die
`assets`-Suite grün bleibt.

**Korrektur vom 2026-09-17.** Hier stand zunächst, ohne Versionssprung sähen Bestands-
installationen eine Filterleiste ohne passendes CSS. Das trifft nicht zu, und zwar aus zwei
unabhängigen Gründen:

- `public/.htaccess` setzt für `.css`, `.js` und `.html` `Cache-Control: no-cache,
  must-revalidate`. Der Browser revalidiert bei jedem Abruf; geänderte Dateien kommen frisch.
- Selbst ohne diesen Header hülfe der `?v=` nicht: `index.html` bindet einzig
  `css/main.css?v=<version>` ein, und `main.css` lädt alles Weitere per `@import url(...)`
  **ohne** Parameter. Die beiden hier geänderten Dateien — `components/forms.css` und
  `sections/content.css` — tragen also keinen. Die JS-Module tragen überhaupt keinen.

Der Versionssprung erfolgt deshalb aus Release-Disziplin, nicht als technische Voraussetzung.
Dass der Cache-Bust an der `@import`-Kette wirkungslos verpufft, ist ein eigener Befund und
gehört als **OI-74** nach `docs/OPEN-ITEMS.md` — nicht in dieses Vorhaben, weil er alle
Installationen ohne `mod_headers` betrifft und eine eigene Antwort braucht.

## Dokumentation

| Datei | Änderung |
|---|---|
| `docs/testplan.md` | Prüfschritte oben |
| `CHANGELOG.md` | Eintrag 1.9.2 |
| `docs/OPEN-ITEMS.md` · OI-20 | Der Filter hat drei Zustände; „nur von Hand angelegte" ist die Richtung, die der dort angekündigten Bestandsprüfung fehlte |
| `docs/OPEN-ITEMS.md` · OI-73 (neu) | `.filter-grid:has(#statMemberFilterGroup[style*="display: none"])` in `sections/content.css` hängt an der exakten Schreibweise eines Inline-Styles — ein Leerzeichen anders, und die Spaltenaufteilung greift nicht mehr |
| `docs/OPEN-ITEMS.md` · OI-74 (neu) | Der `?v=`-Cache-Bust erreicht nur `main.css`; die per `@import` geladenen Dateien und sämtliche JS-Module tragen keinen Parameter. Ohne `mod_headers` fehlt damit jeder Cache-Bust |

`API.md` bleibt unberührt.

## Nicht in diesem Vorhaben

- **Position des Jahresfilters** — bewusste Konvention über alle Module. Die sechsfach
  wiederholten Inline-Styles dürfen später zu einer CSS-Klasse werden; die Position bleibt.
- **Ort und Ende am Termin** — eigener Eintrag FI-23, braucht Migration und berührt die
  Auswertung.
- **Kommende Termine im PWA-Tab „Termine"** — eigene Spec. Der Tab zeigt heute nur Termine mit
  `responses_enabled = 1` und blendet sich ganz aus, wenn keine vorliegen; das berührt
  `private/helpers/responses.php` und damit das Backend.
- **Der `?type_id=`-Parameter in `appointments.php`** bleibt ungenutzt und undokumentiert wie
  bisher.
