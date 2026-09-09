# Design: Drei Korrekturen an der Oberfläche der Check-in-PWA

**Datum:** 2026-09-08
**Status:** Entworfen, nicht umgesetzt
**Betrifft:** `public/checkin/css/style.css`, `public/checkin/js/app.js`
**Zielversion:** 1.4.0 (Vorschlag) — der unveröffentlichte Block enthält neue Funktionen, das ist per SemVer ein MINOR-Sprung, kein Patch

---

## Problem

Drei Beobachtungen aus dem Gebrauch der PWA, alle rein an der Oberfläche:

1. **Der Knopf „Zeit nachtragen“ ist kaum zu sehen.** `.action-button` setzt `border: none`;
   `.action-button.secondary` überschreibt nur den Hintergrund auf `var(--button-bg)` — also
   `#f5f5f5` auf weißer Karte. Der Hover-Zustand setzt seit jeher `border-color`, was ohne
   Rahmen wirkungslos bleibt: Die Klasse **will** ein Umriss-Knopf sein und hat den Rahmen nie
   bekommen. Betroffen sind fünf Knöpfe, nicht nur der neue.
2. **Der Knopf „Korrigieren“ steht am Datum statt rechts.** Im selben Verlauf sitzt der
   Löschen-Knopf eines Antrags rechts oben und ist rot abgesetzt
   (`.history-item .delete-btn`, `position: absolute; top: 10px; right: 10px`). Zwei Knöpfe
   gleicher Bedeutungsebene, zwei verschiedene Plätze.
3. **„Zeitkorrektur“ meint hier die Ankunftszeit.** Ein Anwesenheitsantrag erscheint als
   „Wartet auf Freigabe - Zeitkorrektur“. In derselben Liste stehen seit 1.3.x
   Arbeitszeit-Einträge, die ebenfalls auf Freigabe warten — das Wort ist dort mit einer
   Korrektur der *Arbeitszeit* zu verwechseln.

---

## Leitgedanke

**Gleiche Bedeutung, gleiche Form.** Der Verlauf mischt drei Datenarten; was in ihm gleich
aussieht, soll gleich funktionieren, und was verschieden ist, soll verschieden aussehen — nicht
umgekehrt.

---

## Teil 1 — Der sekundäre Knopf bekommt seinen Rahmen

`.action-button.secondary` wird zum Umriss-Knopf: `border: 2px solid var(--primary-color)`,
`color: var(--primary-color)`, Hintergrund `var(--card-bg)`. Der Hover-Zustand kehrt sich um —
gefüllt in der Hausfarbe, weiße Schrift — statt eines `border-color`, das ins Leere lief.

Der Innenabstand sinkt dabei von `14px 20px` auf `12px 18px`. `box-sizing: border-box` gilt
zwar global (`style.css:32`), greift hier aber nicht: Die Knöpfe haben keine feste Höhe,
sondern wachsen aus ihrem Inhalt — der Rahmen käme also oben drauf. Gemessen: 64 px statt 60 px
neben dem gefüllten Knopf. Mit dem angepassten Innenabstand stehen beide wieder gleich hoch.

**Das gilt für alle fünf Knöpfe der Klasse** — „Manuell eingeben“ (zweimal), „Scan beenden“,
„Nachträglicher Antrag“, „Zeit nachtragen“ und „Ohne Nachweis beenden“. Die Alternative, nur
den neuen Knopf zu ändern, wurde verworfen: Sie ließe vier Knöpfe derselben Bedeutungsebene
blass zurück und den eigentlichen Fehler im Bestand.

**Nicht gefüllt-blau.** „Start“ ist die Hauptaktion dieser Ansicht; zwei gefüllte Knöpfe
nebeneinander konkurrieren um denselben Blick, und der Nachtrag ist der seltenere Weg.

---

## Teil 2 — „Korrigieren“ zieht nach rechts oben

`.history-correct-btn` übernimmt die Form von `.history-item .delete-btn`: absolut positioniert,
`top: 10px; right: 10px`, gleiche Maße und Schriftgröße. Unterschied ist allein die Farbe —
blauer Farbton aus der Hausfarbe (`rgba(31, 95, 191, …)` über `var(--primary-color)`) statt des
roten. Blau, weil Korrigieren keine zerstörende Handlung ist; Rot bleibt dem Löschen
vorbehalten.

Damit der Text nicht unter den Knopf läuft, bekommt der Eintrag Platz auf der rechten Seite —
wie `.history-item.has-delete` es mit `padding-right: 80px` vormacht. „✎ Korrigieren“ ist
breiter als „🗑️ Löschen“, deshalb eine eigene Klasse `.history-item.has-correct` mit
`padding-right: 100px`.

Im JavaScript wandert der Knopf aus der Zeile `<div class="time">` heraus und wird zum
Geschwister der übrigen Zeilen; der Eintrag erhält zusätzlich die Klasse `has-correct`.

---

## Teil 3 — Das Wort „Zeitkorrektur“ verschwindet

Ein Antrag im Verlauf wird künftig so beschriftet:

| Art | `pending` | `approved` | `rejected` |
|---|---|---|---|
| `time_correction` | Antrag wartet auf Freigabe | Antrag bestätigt | Antrag abgelehnt |
| `absence` | Entschuldigung wartet auf Freigabe | Entschuldigung bestätigt | Entschuldigung abgelehnt |

„Entschuldigung“ bleibt, weil das Wort eindeutig ist. „Zeitkorrektur“ entfällt, weil es genau
die Verwechslung erzeugt, um die es geht.

**Der Satz wird gebaut, nicht zusammengesetzt.** `FREIGABE_STATUS` liefert „Wartet auf Freigabe“
mit großem W — ein Anhängen an „Antrag“ ergäbe „Antrag Wartet auf Freigabe“. Eine eigene kleine
Funktion `exceptionHistoryLabel(exception)` bildet die Tabelle oben ab; `FREIGABE_STATUS` bleibt
unangetastet, weil Arbeitszeitsitzungen es weiterhin für sich allein verwenden.

**Bewusst hingenommen:** Das Dashboard nennt `time_correction` weiterhin „Zeitkorrektur“ — in
den Filtern, in `utils.js` und in den Auswahlfeldern. Das ist kein Widerspruch, sondern eine
Auslassung: Die PWA sagt weniger, nicht etwas anderes. Den Begriff auch dort zu ändern, wäre
ein eigener Vorgang mit Wirkung auf Filter und Auswertung — hier nicht enthalten.

---

## Nicht enthalten

- Keine Änderung an `FREIGABE_STATUS`, an den Statuswerten der Datenbank oder an der API
- Keine Umbenennung von `time_correction` im Dashboard
- Kein neues Markup: alle drei Änderungen kommen mit den vorhandenen Elementen aus

---

## Prüfung

Am Server ändert sich nichts; die vorhandenen Suiten müssen unberührt grün bleiben
(`assets` als Wächter über die Versions-Querys, `worktime_frontend` über die Verdrahtung).

Neu kommt eine statische Gegenprobe in `tests/suites/worktime_frontend.php` dazu: Das Wort
`Zeitkorrektur` darf in `public/checkin/js/app.js` nicht mehr vorkommen. Das ist der einzige
der drei Punkte, der sich statisch überhaupt festhalten lässt — und zugleich der, bei dem ein
späteres Zurückrutschen am wenigsten auffiele.

Die beiden sichtbaren Änderungen werden im Browser geprüft: berechnete Rahmenstärke und Farbe
des sekundären Knopfes, sowie Position und Farbe des Korrigieren-Knopfes im gerenderten
Verlaufseintrag, jeweils über direkte Renderaufrufe ohne Anmeldung.
