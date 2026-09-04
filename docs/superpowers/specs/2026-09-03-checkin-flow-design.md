# PWA Check-in Flow — Zurück-Weg, Terminauswahl-Gewichtung, Vorabanzeige

**Datum:** 2026-09-03 · **Zielversion:** 1.2.5 · **Branch:** `dev`
**Status:** entworfen, nicht umgesetzt

---

## 1 Ausgangslage

Vier Beobachtungen zum Anwesenheits-Check-in in `public/checkin/`, alle am selben Bildschirm:

1. Der „← Zurück"-Pfeil über den Ansichten „Anwesenheit" und „Arbeitszeit" stört optisch und
   ist redundant: Ein Klick auf den aktiven „Erfassen"-Tab löst `enterCaptureTab()` aus, das bei
   mehreren Absichten ohnehin zur Auswahl zurückspringt
   ([app.js:2856](../../../public/checkin/js/app.js)). Der Pfeil dupliziert also einen Weg, der
   bereits existiert — nur unentdeckt.
2. In der Anwesenheits-Ansicht steht die Terminauswahl (seit 1.2.4, Task 8 des vorigen Plans)
   über den Scan-Buttons und wirkt dadurch wie ein Pflichtfeld, obwohl sie ausdrücklich optional
   ist.
3. Die Auswahl bleibt bis zum Scan unkommentiert: Ob ein passender Termin überhaupt existiert,
   erfährt das Mitglied erst aus der Serverantwort — nach dem Scan, nicht davor.
4. Der „Nachträgliche Antrag" verlangt zwingend einen bestehenden Termin
   (`exceptions.appointment_id NOT NULL`,
   [ehrensache_db.sql:40](../../../private/setup/ehrensache_db.sql)). Fehlt einer, bleibt nur der
   Weg außerhalb der App.

## 2 Vier Entscheidungen

### 2.1 Zurück-Pfeil: ersatzlos entfernen

Kein Ersatz, keine zusätzliche Kennzeichnung des bestehenden Wegs. Ein erneuter Klick auf den
aktiven Tab als Rücksprung ist auf Mobilgeräten ein geläufiges Muster; die zusätzliche
Erklärfläche würde eine ohnehin schon volle Ansicht weiter verdichten.

### 2.2 Terminauswahl ans Ende

Reihenfolge in Ansicht 2 („Anwesenheit"): Scan-Button, NFC (falls unterstützt), manueller Code,
Trenner „oder", Antrag stellen, **danach** — abgesetzt — die optionale Terminauswahl. Ordnet sie
als das ein, was sie ist: eine Ausnahme für den Sonderfall, nicht die Haupthandlung.

### 2.3 Vorabanzeige des passenden Termins — informativ, nicht blockierend

Keine neue Serveranfrage. `loadCheckinAppointments()` lädt beim Anmelden bereits alle heutigen,
für das Mitglied zugelassenen Termine (`checkinAppointments`,
[app.js:404](../../../public/checkin/js/app.js), serverseitig gruppengefiltert über
`GET appointments?member_id=…`). Eine neue Funktion sucht darin den zeitlich nächsten innerhalb
von `clientSettings.checkin_tolerance_hours` — dieselbe Regel wie serverseitig
([auto_checkin.php](../../../private/handlers/auto_checkin.php)), nur ohne die
Standard-Gruppen-Priorisierung bei mehreren Kandidaten im selben Fenster. Für einen Hinweis ist
das ausreichend; die verbindliche Entscheidung trifft ohnehin der Server.

Bei Treffer: Die Terminauswahl wird auf diesen Termin vorbelegt, darüber erscheint ein schmaler
Banner „📍 Du checkst ein für: *Probe*, 19:00 Uhr". Wählt das Mitglied danach von Hand einen
anderen Termin, folgt der Banner dieser Wahl — er zeigt immer den Stand der Auswahl, nicht nur
den ersten automatischen Treffer. Ohne Treffer bleibt der Banner verborgen; der bestehende
Hinweistext unter der Auswahl (aus 1.2.4) erklärt weiterhin, was ohne Auswahl passiert.

**Vorbelegt wird nur, solange die Auswahl leer ist.** Eine bereits getroffene Wahl — automatisch
oder von Hand — bleibt bei einem erneuten Aufruf der Ansicht bestehen; nur der Banner wird
aufgefrischt. Ohne diese Regel würde ein zufällig fortschreitendes „jetzt" eine bewusste
Mitgliederwahl stillschweigend überschreiben.

**Auslöser der Neuberechnung:** jedes Mal, wenn Ansicht 2 sichtbar wird
(`showCaptureView('attendance')`), sowie nach einem erfolgreichen Check-in, wenn
`loadCheckinAppointments()` die Liste ohnehin neu lädt
([app.js:1408](../../../public/checkin/js/app.js), aus Task 9). Kein Timer — die Zeitfenster sind
stundengroß, eine kontinuierliche Neubewertung wäre Aufwand ohne Nutzen.

**Bewusst nicht:** Scan, NFC und manueller Code werden nie gesperrt, unabhängig vom Ergebnis der
Vorabanzeige. Der Server bleibt die einzige verbindliche Instanz — er kennt zusätzlich
`checkin_auto_create_appointment`, das der Client dafür nicht auswerten muss.

### 2.4 Antrag bleibt unverändert

Keine Erweiterung um eine Terminanlage. Begründung, damit sie nicht als vergessene Lücke
wiederkehrt:

Der Live-Fall — „ich bin jetzt da, kein Termin passt" — ist bereits gelöst, und zwar *in* diesem
Dokument nicht neu, sondern seit der Migration auf 1.2.4: Ist `checkin_auto_create_appointment`
aktiv, entsteht beim Scan ein markierter Termin; ist sie es nicht, kommt die Meldung „Bitte beim
Vorstand melden". Der Antrag deckt einen anderen Fall ab — den *rückwirkenden* Nachtrag für einen
verpassten Check-in, typischerweise Tage später gestellt.

Eine Terminanlage im Antrag würde nur den seltenen Schnittfall lösen — rückwirkender Antrag
*und* fehlender Termin gleichzeitig — und dabei das Risiko einführen, das in der Fragestellung
selbst genannt wurde: Mehrere Mitglieder, die unabhängig voneinander denselben fehlenden Termin
melden, bevor ein Admin reagiert, erzeugen mehrere Termine für ein Ereignis. Das widerspricht der
mit `is_auto_created` und OI-20 erst kürzlich getroffenen Linie, Terminanlage außerhalb einer
geprüften, konfigurierbaren Automatik nicht unkontrolliert zuzulassen. Der schmalere Nutzen
rechtfertigt dieses Risiko nicht.

## 3 Umsetzung im Detail

### 3.1 `public/checkin/index.html`

- Zeile 179 (`captureAttendance`) und Zeile 223 (`captureWorktime`) — je eine Zeile
  `<button class="capture-back" hidden>← Zurück</button>` entfernen.
- Neuer Block direkt nach `<div id="captureAttendance" hidden>`:
  ```html
  <div class="checkin-suggestion" id="checkinSuggestion" hidden></div>
  ```
- Der bestehende `form-group`-Block mit `checkinAppointment` (Zeilen 181–187) wandert an das
  Ende von Ansicht 2, hinter den schließenden `</div>` des `action-buttons`-Blocks und vor den
  schließenden `</div>` von `captureAttendance`. IDs, `required`-Status und Hinweistext bleiben
  unverändert — reine Verschiebung im Markup.

### 3.2 `public/checkin/js/app.js`

**Entfernen:**
- Funktion `updateCaptureBackVisible()` ([app.js:2818](../../../public/checkin/js/app.js))
- ihre drei Aufrufe in `setCheckinUIState()` (Fälle `IDLE` und `QR_SCANNING`) und in
  `showCaptureView()`
- der `.capture-back`-Listener-Block in `initCaptureTab()`

**Neu**, neben `renderCheckinAppointmentOptions()`:

```javascript
/**
 * Zeitlich naechster Termin im Toleranzfenster, oder null.
 *
 * Spiegelt die serverseitige Regel aus auto_checkin.php, ohne die
 * Standard-Gruppen-Prioritaet bei mehreren Kandidaten im selben Fenster —
 * fuer einen Hinweis ausreichend, verbindlich bleibt ohnehin der Server.
 */
function findClosestCheckinAppointment() {
    const toleranceHours = parseInt(clientSettings.checkin_tolerance_hours, 10) || 2;
    const toleranceMs = toleranceHours * 60 * 60 * 1000;
    const now = Date.now();

    let closest = null;
    let closestDiff = Infinity;

    for (const apt of checkinAppointments) {
        const diff = Math.abs(new Date(`${apt.date}T${apt.start_time}`).getTime() - now);
        if (diff <= toleranceMs && diff < closestDiff) {
            closest = apt;
            closestDiff = diff;
        }
    }

    return closest;
}

/**
 * Zeigt oder verbirgt den Banner ueber den Buttons, passend zum aktuellen
 * Stand der Terminauswahl — nicht nur zum ersten automatischen Treffer.
 */
function renderCheckinSuggestion() {
    const banner = document.getElementById('checkinSuggestion');
    const select = document.getElementById('checkinAppointment');
    if (!banner || !select) return;

    const chosen = checkinAppointments.find(
        apt => String(apt.appointment_id) === select.value
    );

    if (chosen) {
        banner.textContent =
            `📍 Du checkst ein für: ${chosen.title}, ${chosen.start_time.substring(0, 5)} Uhr`;
        banner.hidden = false;
    } else {
        banner.hidden = true;
    }
}
```

**Anpassen** in `renderCheckinAppointmentOptions()`: nach dem Befüllen der Optionen, nur wenn
`select.value` leer ist, mit `findClosestCheckinAppointment()` vorbelegen; danach immer
`renderCheckinSuggestion()` aufrufen.

**Neuer Listener**, dort registriert, wo die PWA ihre `change`-Handler initialisiert: auf
`#checkinAppointment` → `renderCheckinSuggestion()`.

**Neuer Aufruf** in `showCaptureView()`, im Zweig `view === 'attendance'`:
`renderCheckinSuggestion()` — die Liste selbst wird nicht neu geladen, nur der Banner an die
aktuelle Auswahl und das fortgeschrittene „jetzt" angeglichen.

### 3.3 `public/checkin/css/style.css`

- Regel `.capture-back { … }` ([style.css:2033](../../../public/checkin/css/style.css))
  entfernen.
- In der kombinierten `[hidden]`-Regel `.capture-back[hidden]` aus der Selektorliste entfernen,
  `.capture-chooser[hidden]` und `.capture-tile[hidden]` bleiben.
- Neue Regel, im Stil von `.field-hint-proof` ([style.css:2092](../../../public/checkin/css/style.css))
  aber in Primärfarbe statt Warnfarbe, da eine Bestätigung und keine Bedingung:
  ```css
  .checkin-suggestion {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 10px 12px;
      margin-bottom: 12px;
      border-radius: 6px;
      font-size: 14px;
      color: var(--primary-dark);
      background: rgba(31, 95, 191, 0.08);
      border-left: 3px solid var(--primary-color);
  }

  .checkin-suggestion[hidden] {
      display: none;
  }
  ```

## 4 Testbarkeit

Reines Frontend, keine neue Serverlogik, kein neuer Endpunkt — der PHP-Testharness deckt das
nicht ab. Manuelle Prüfung, neu in `docs/testplan.md`:

| Fall | Erwartung |
|---|---|
| Erfassen-Tab erneut antippen, während Ansicht 2 oder 3 offen ist | Zurück zur Absichtswahl (bei zwei Absichten) |
| Anwesenheits-Ansicht öffnen, Termin im Toleranzfenster vorhanden | Banner erscheint, Auswahl vorbelegt |
| Anwesenheits-Ansicht öffnen, kein Termin im Fenster | Kein Banner, Auswahl leer |
| Anderen Termin von Hand wählen | Banner wechselt auf die neue Auswahl |
| Tab verlassen und zurückkehren, nachdem von Hand gewählt wurde | Auswahl bleibt erhalten, wird nicht überschrieben |
| Erfolgreicher Check-in | Liste und Banner aktualisieren sich mit dem neuen Stand |
| Antragsdialog öffnen | Unverändert zu 1.2.4, verlangt weiterhin einen bestehenden Termin |

## 5 Aufwand

| Bereich | Dateien | ~Zeilen | Zeit |
|---|---|---|---|
| Zurück-Pfeil entfernen (Markup, JS, CSS) | 3 | −25 | 1 h |
| Terminauswahl verschieben | 1 | 0 (Verschiebung) | 0,5 h |
| Vorabanzeige (zwei Funktionen, ein Listener, ein Hook) | 2 | 60 | 2,5 h |
| CSS für den Banner | 1 | 20 | 0,5 h |
| `docs/testplan.md` | 1 | 10 | 0,5 h |
| **Summe** | **5** | **~115** | **~5 h** |

Kein Datenbankeingriff, keine Migration, keine Versionssprung-Pflicht für Backend-Kompatibilität
— reine Oberflächenänderung. Ob `version.json` trotzdem gehoben wird, ist eine Frage der
Release-Konvention, nicht der Umsetzung.

## 6 Bewusst nicht enthalten

- **Terminanlage im Antrag** — siehe 2.4.
- **Serverseitige Bestätigung vor dem Scan** (ein neuer Endpunkt, der die Vorabprüfung
  authoritativ beantwortet). Der Aufwand stünde in keinem Verhältnis zum Nutzen einer bloßen
  Anzeige; die vorhandenen, bereits geladenen Daten reichen für einen Hinweis.
- **Sperren von Scan/NFC/Code ohne Treffer** — verworfen, siehe 2.3.
