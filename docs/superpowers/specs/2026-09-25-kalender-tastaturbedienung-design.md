# Kalender per Tastatur bedienen — Design

**Vorgang:** OI-96 (mit OI-80 zusammengelegt) · entworfen am 2026-09-25 · Grundlage ist der Stand
nach 1.16.0 (`82d38b2`)

## Ziel

Ein Kalendertag **mit** Terminen ist heute nur mit der Maus zu öffnen. Das festgehaltene Popup und
alles darin — „Bearbeiten", „Anwesenheit", die Rückmeldezeile, die Anwesenheitszahlen — bleibt ohne
Maus unerreichbar. Leere Tage machen es seit OI-64 richtig vor.

Künftig: Der Tag ist anfahrbar, Enter oder Leertaste öffnet das Popup, der Fokus wandert hinein,
Tab läuft durch die Bedienelemente, Escape schließt und gibt den Fokus an den Tag zurück.

## Entscheidungen des Nutzers (2026-09-25)

| Frage | Entscheidung |
|---|---|
| Umfang | **Tag öffnen, Popup durchlaufen, Escape** — keine Pfeiltasten zwischen Kalendertagen |
| Verhalten des Popups | **Dialog mit Fokusfang**, nicht Panel |
| Fokusfang | **Als Helfer in `utils.js`**, OI-96 ist der erste Nutzer |
| Klick-Hörer | **Klicks im Popup nehmen es nicht mehr weg** |
| Termin-Blöcke | **`role="group"` statt Tab-Stopp** |
| Feiertagsname (OI-80) | **Mitnehmen**, dieselbe Funktion |

## Was der Umbau vorfindet

**Das Popup hängt an `document.body`** und ist `position: fixed` (`appointments.js:940`). Es steht
damit am Ende des DOM — ein Tab vom Tagesfeld führt **nicht** hinein, sondern zum nächsten Element
im Kalender. Die nativen Knöpfe darin sind erst nach dem gesamten Seiteninhalt erreichbar, ohne
jeden Zusammenhang mit ihrem Tag. **Es genügt deshalb nicht, dem Tag `tabindex` zu geben.**

**Beim Tag einhängen geht nicht.** `.calendar-day:hover` setzt `transform: scale(1.05)`
(`calendar.css:90-93`), und ein `transform` macht das Element zum Bezugsrahmen für
`position: fixed` — das Popup würde beim Überfahren springen und mitskalieren. Es bleibt an `body`,
die Fokusführung kommt aus dem JavaScript.

**Das Haus hat noch keinen Fokusfang.** `initModalEscHandler()` (`ui.js:1045`) hört Escape global,
`showConfirm()` (`ui.js:431`) setzt Fokus punktuell — aber nirgends wird Tab gehalten oder der
Fokus zurückgegeben. Der hier gebaute ist der erste.

**Ein festgehaltenes Popup entfernt sich beim nächsten Klick irgendwo** (`appointments.js:982`),
auch bei einem Klick hinein. Das fällt heute nicht auf, weil jeder Knopf darin ohnehin schließt.
Mit Fokusfang wäre es ein Widerspruch: Tab bleibt drin, ein Klick auf freie Fläche wirft hinaus.

## Die fünf Teile

### 1. Fokusfang-Helfer in `public/js/modules/utils.js`

Eine Funktion, die ein Element vorübergehend zum Dialog macht:

- merkt sich das zuletzt fokussierte Element
- setzt den Fokus auf das erste bedienbare Element darin; gibt es keines, auf das Element selbst
  (dann trägt es `tabindex="-1"`)
- hält Tab und Shift+Tab im Element: hinter dem letzten Element geht es zum ersten und umgekehrt
- ruft auf Escape den übergebenen Abschluss
- liefert eine Freigabe-Funktion, die Hörer entfernt und den Fokus zurückgibt

**Kein `inert`, kein Umbau bestehender Dialoge.** Beides wäre ein eigenes Vorhaben. Der Helfer
entsteht hier, weil ein Fokusfang keine Eigenart des Kalenders ist — aber er wird hier auch nur
einmal benutzt.

Die Liste der bedienbaren Elemente ist der übliche Selektor (`button`, `a[href]`, `input`,
`select`, `textarea`, `[tabindex]:not([tabindex="-1"])`), gefiltert auf sichtbare. **Sie wird bei
jedem Tab neu gelesen**, nicht einmal beim Öffnen: Die Rückmeldezeile erscheint je nach Rolle und
Termin, und die Knopfreihe hängt an `appointmentHasStarted()`.

### 2. Der Kalendertag mit Terminen (`createCalendarDay()`, Belegt-Zweig)

Bekommt, was der Leer-Zweig seit OI-64 hat:

- `role="button"`, `tabindex="0"`
- `keydown`: Enter und Leertaste öffnen das festgehaltene Popup (`preventDefault`, sonst rollt die
  Leertaste die Seite)
- `aria-haspopup="dialog"`

**`mouseenter`, `mouseleave` und `click` bleiben unverändert.** Das Überfahren gehört weiter der
Maus.

### 3. Der Feiertagsname im `aria-label` (Teil aus OI-80)

Das `aria-label` eines Tages nennt heute Uhrzeit, Terminart, Titel und Rückmeldungen — aber nicht
den Feiertag, den sehende Nutzer als Text im Tagesfeld lesen (`calendar-day--holiday`,
`holidaysOfYear()`). Der Name kommt **vorn** hinein, vor die Terminliste, sofern der Tag einer ist.

Das betrifft **beide** Zweige: den belegten und den leeren. Ein leerer Feiertag ist heute nur als
Zahl hörbar.

### 4. Das festgehaltene Popup wird ein Dialog

- `role="dialog"`, `aria-modal="true"`, `aria-label` mit dem Datum des Tages
- der Fokusfang greift beim Öffnen, die Freigabe beim Schließen — gleich auf welchem Weg (Escape,
  Klick daneben, Knopf im Popup)
- der Klick-Hörer prüft künftig, ob der Klick im Popup lag, und schließt nur sonst

**Das Überfahr-Popup (`fest === false`) bleibt unangetastet:** kein `role`, kein `aria-modal`, kein
Fokus, kein Fang. Es ist flüchtig und gehört der Maus. Das ist dieselbe Trennung, die OI-94 für die
Rückmeldezeile getroffen hat.

### 5. Die Termin-Blöcke bekommen einen Namen, keinen Tab-Stopp

Jeder `.calendar-event-block` wird `role="group"` mit einem `aria-label` aus Terminart, Uhrzeit und
Titel. Wer durch die Knöpfe tabbt, hört beim Betreten der Gruppe, zu welchem Termin sie gehört —
ohne zusätzlichen Tastendruck. Bei drei Terminen an einem Tag spart das drei Tab-Stopps gegenüber
fokussierbaren Blöcken.

**Folge: `.calendar-event-block:focus-visible` fällt weg**, samt der Zusicherung, die OI-94 dafür
angelegt hat. Die Regel entstand am Vormittag des 2026-09-25 als Vorleistung für genau dieses
Vorhaben — und wurde abgesichert, damit sie niemand versehentlich entfernt. Beim Entwerfen zeigte
sich, dass der bessere Weg ohne sie auskommt.

**Das ist kein Fehler der Vorleistung, sondern ihr Zweck:** Eine Vorbereitung wird erst dann auf
die Probe gestellt, wenn jemand darauf aufbaut. Sie stehen zu lassen wäre schlechter — eine Regel
ohne Wirkung und ein Test, der sie bewacht. Wer später Blöcke doch fokussierbar machen will (etwa
mit Pfeiltasten, siehe „Nicht in diesem Vorhaben"), legt beides neu an; das kostet weniger als eine
tote Regel, der man nicht ansieht, ob sie gebraucht wird.

## Tests

Neue Suite `tests/suites/calendar_keyboard_frontend.php`, statische Gegenproben:

- Der Belegt-Zweig von `createCalendarDay()` trägt `role`, `tabindex` und einen `keydown`-Hörer,
  der Enter **und** Leertaste behandelt.
- Der Fokusfang steht in `utils.js`, wird in `appointments.js` importiert und nur im Zweig
  `fest === true` benutzt — eine Gegenprobe stellt sicher, dass das Überfahr-Popup ihn nicht
  bekommt.
- Das Popup trägt `role="dialog"` und `aria-modal` nur im festgehaltenen Fall.
- Jeder Block trägt `role="group"` mit `aria-label`; kein `tabindex` am Block.
- Der Feiertagsname steht **vor** der Terminliste im `aria-label`, in beiden Zweigen.
- Der Klick-Hörer prüft die Herkunft des Klicks.
- `.calendar-event-block:focus-visible` ist aus `calendar.css` verschwunden, und die Zusicherung
  aus `type_accent_frontend.php` ebenfalls.

**Jede neue Zusicherung wird mit einer Mutation gegengeprüft** — erst den Code kaputtmachen, dann
sehen, dass der Test rot wird. In OI-94 blieben drei Zusicherungen grün, obwohl das Geprüfte
fehlte: eine wurde vom Kommentar darüber erfüllt, eine durch die eigene Verbesserung wirkungslos,
eine hing an der Reihenfolge zweier CSS-Blöcke. Siehe OI-107. **Auf Herkunft prüfen, nicht auf das
Vorkommen eines Namens.**

## Browserprüfung

Als **Admin** und als **einfaches Mitglied**, je: Mit Tab bis zu einem Tag mit Terminen; Enter
öffnet; Tab läuft durch alle Bedienelemente und kehrt zum ersten zurück; Shift+Tab rückwärts;
Escape schließt und der Fokus steht wieder auf dem Tag; ein Klick auf freie Fläche im Popup
schließt **nicht**; ein Klick daneben schon. Dazu ein Tag mit drei Terminen (Gruppennamen hörbar
getrennt), ein Feiertag mit und ohne Termine, und das Überfahr-Popup (darf keinen Fokus nehmen).

Konsole ohne neue Fehler.

## Nicht in diesem Vorhaben

- **Pfeiltasten zwischen Kalendertagen.** Das Raster wie einen Datumswähler zu bedienen ist ein
  eigenes Stück Arbeit mit eigenen Fragen — etwa, was am Monatsrand geschieht.
- **Pfeiltasten innerhalb eines Blocks** (Menüleisten-Muster). Ausdrücklich verworfen: ein
  Verhalten, das man erst lernen muss.
- **Der Umbau bestehender Dialoge** auf den neuen Helfer. `showConfirm()`, die Modale und
  `initModalEscHandler()` bleiben, wie sie sind. Ein eigener Vorgang, sobald der Helfer sich
  bewährt hat.
- **`inert` für den Hintergrund.** Der Fang reicht für die Tastatur; `inert` würde zusätzlich
  Mauszugriff und Vorlesereihenfolge sperren und gehört zu einer Entscheidung über alle Dialoge.
- **Die Check-in-App.** Sie hat ihren eigenen Kalender und ihre eigenen Regeln.

## Offene Frage, die beim Umbau anfällt

Der Klick-Hörer wird heute nach 10 ms registriert (`setTimeout`), damit der öffnende Klick ihn
nicht gleich wieder auslöst. Wird das Popup künftig per Tastatur geöffnet, gibt es keinen öffnenden
Klick — die Verzögerung ist dann unnötig, aber harmlos. **Beim Umbau prüfen**, ob die Prüfung auf
die Herkunft des Klicks (Teil 4) die Verzögerung überflüssig macht; wenn ja, entfällt sie mit einem
Vermerk, warum sie einmal nötig war.
