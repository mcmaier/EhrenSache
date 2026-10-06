# OI-125: Kommende Termine in der Check-in-App bearbeiten und löschen

**Stand:** 2026-10-06 · **Status:** entworfen, freigegeben · **Vorgänger:** OI-123 (Termin anlegen
im Tab „Termine“)

## Ziel

Admin und Manager können einen kommenden Termin, den sie in der App falsch angelegt haben, dort
auch korrigieren oder löschen. Mit OI-123 ist das Anlegen im Tab „Termine“ möglich; ohne
Bearbeiten und Löschen fehlt der Rückweg, und bei „nur Terminplanung“ gibt es in der App heute gar
keinen.

## Ausgangslage

- Bearbeiten gibt es in der App nur in der Anwesenheitsliste — also nur für Termine im Fenster um
  jetzt (± Check-in-Toleranz) und nur mit eingeschalteter Anwesenheit.
- Löschen gibt es in der App nicht.
- `PUT appointments` ersetzt nur mitgeschickte Felder (seit OI-69). Der App-Dialog hat kein Feld
  „Beschreibung“; eine im Dashboard gepflegte Beschreibung bleibt beim Speichern aus der App stehen.
- Ändert ein `PUT` Datum oder Beginn eines Termins mit Rückmeldungen, antwortet der Server mit
  `409 responses_affected`; `submitAppointmentForm()` fragt dann nach und wiederholt mit
  `reset_responses` (OI-124). Das gilt für jeden Bearbeiten-Weg der App.
- Serientermine: `PUT` löst einen geänderten Termin aus der Serie, `DELETE` trägt seinen Tag als
  Ausnahme in die Serie ein. Beides macht der Server selbst.
- `DELETE appointments` löscht endgültig und ohne Papierkorb mit: Erfassungen (`records`),
  Rückmeldungen (`appointment_responses`) und Anträge aller Arten (`exceptions`)
  (`private/handlers/appointments.php`, Fall `DELETE`).
- Die Karten im Tab „Termine“ tragen `item.started` (Termin hat begonnen). Infotermine ohne
  Beschreibung sind heute nicht aufklappbar.

## Entscheidungen

1. **Nur kommende Termine:** Bearbeiten und Löschen gibt es nur für Termine, die noch nicht begonnen
   haben (`item.started === false`).
2. **Nur Admin und Manager** (`isPwaManager()`).
3. **Bearbeiten in der aufgeklappten Karte**, Löschen **im Bearbeiten-Dialog** — zwei Schritte bis
   zum Löschen, kein Löschknopf direkt auf der Karte.
4. **Keine Löschung mit Erfassungen in der App:** Gibt es schon Erfassungen (möglich im
   Check-in-Fenster vor Beginn), verweist die App auf das Dashboard.
5. **Rückfrage nennt die Folgen** in Zahlen (Rückmeldungen, Anträge).
6. **Serientermine:** Die App löscht nur diesen einen Termin. „Dieser und alle folgenden“ bleibt im
   Dashboard.
7. **Zählungen vom Server** über einen lesenden Zusatz zu `GET appointments?id=` (Variante 1 aus
   dem Brainstorming); `DELETE` bleibt unverändert.
8. **Durchsetzung nur in der App.** Der Server prüft „nur bis Beginn“ und „nicht bei Erfassungen“
   nicht: Verwalter dürfen im Dashboard ohnehin jeden Termin löschen; eine Serverregel brächte keine
   Sicherheit, nur eine zweite Stelle mit denselben Regeln.

## Server

### `GET appointments?id=X&dependents=1`

Zusätzlich zum bisherigen Termin-Objekt ein Feld:

```json
"dependents": { "records": 0, "responses": 12, "exceptions": 3 }
```

- Gezählt wird genau, was `DELETE` mitlöscht: alle `records`, alle `appointment_responses`, alle
  `exceptions` (jede Art) mit dieser `appointment_id`.
- Nur Admin und Manager. Andere Rollen mit `dependents=1` erhalten **403**; ohne den Parameter
  bleibt der Abruf für alle Rollen unverändert.
- Ohne `id` hat der Parameter keine Wirkung.
- Abschnitt in `API.md` (Ressource `appointments`, GET).

Keine neue Ressource, also kein Eintrag in `private/helpers/demo_mode.php` nötig: Lesezugriffe sind
im Demo-Modus frei, und `appointments` hat `DELETE` bereits in `DEMO_WRITE_ALLOWED`.

## App (`public/checkin/`)

### Karte im Tab „Termine“

- Für Verwalter und `!item.started` wird **jede** Karte aufklappbar (auch Infotermine ohne
  Beschreibung). In der aufgeklappten Karte steht unten eine Zeile mit dem Knopf
  „✎ Bearbeiten“ (`data-appointment-id`). Mitglieder sehen die Karten unverändert.
- Der Klick wird über den bestehenden delegierten Handler der Liste (`onResponsesClick`)
  ausgewertet, nicht über eigene Bindungen je Karte.

### Termin-Dialog

- „Bearbeiten“ aus dem Tab „Termine“ öffnet den bestehenden Dialog mit dem Titel „Termin
  bearbeiten“, befüllt aus `GET appointments?id=X`. `appointmentModalOrigin = 'responses'`,
  `appointmentModalOpener` = der Plus-Knopf `#btnAddAppointment`: Die Karte mit dem
  Bearbeiten-Knopf wird nach dem Speichern oder Löschen neu gezeichnet, ihr Knopf existiert dann
  nicht mehr; der Plus-Knopf steht fest im Kopf des Tabs.
- Die bestehende Bearbeiten-Funktion der Anwesenheitsliste liest die id aus deren Auswahl. Für den
  Tab „Termine“ wird das Befüllen aus der id in eine Funktion herausgezogen, die beide Wege nutzen.
- Neuer Knopf **„Löschen“** im Dialog, nur sichtbar beim Bearbeiten aus dem Tab „Termine“
  (`hidden` beim Anlegen und in der Anwesenheitsliste).
- Speichern: Der bestehende Weg in `submitAppointmentForm()` bleibt (inklusive OI-124-Rückfrage und
  Speichern-Sperre). Im Zweig `'responses'` lautet die Erfolgsmeldung bei einer Änderung „Termin
  aktualisiert“; ist der Termin danach nicht mehr in der Liste, kommt der bekannte Hinweis
  „erscheint nicht in deiner Liste (…)“.

### Löschen

1. Hat der Termin inzwischen begonnen (Datum und Beginn aus dem Dialog-Bestand gegen jetzt)? →
   Hinweis im Dialog, nichts wird gelöscht.
2. `GET appointments?id=X&dependents=1`. Scheitert der Abruf → Fehlermeldung im Dialog, nichts wird
   gelöscht.
3. `records > 0` → Hinweis im Dialog: „Zu diesem Termin gibt es schon Erfassungen – bitte im
   Dashboard löschen.“ Nichts wird gelöscht.
4. Sonst Rückfrage im Bestätigungsdialog:
   - „Termin „<Titel>“ (<Tag, Datum · Uhrzeit>) löschen?“
   - bei Zählungen > 0: „Dabei gehen <n> Rückmeldungen und <m> Anträge verloren.“
     Einzahl/Mehrzahl beachten („1 Rückmeldung“, „1 Antrag“), Teile mit 0 entfallen. „Anträge“, nicht
     „Entschuldigungen“: `dependents.exceptions` zählt alle Arten, die `DELETE` mitlöscht, auch
     Zeitkorrekturen.
   - bei Serienterminen (`series_id` gesetzt): „Nur dieser Termin, die Serie bleibt bestehen.“
5. Bestätigt → `DELETE appointments?id=X`. Erfolg → Termin-Dialog schließen, `loadResponses()`,
   Meldung „Termin gelöscht“, Fokus auf den Plus-Knopf. Fehler → Meldung im Termin-Dialog, er bleibt
   offen.

Der Löschen-Knopf ist während Schritt 2–5 gesperrt (wie Speichern).

### Bestätigungsdialog

`#confirmDeleteModal` dient heute nur dem Löschen von Anträgen und hat einen festen Text. Er wird
verallgemeinert: Text und Aktion werden beim Öffnen übergeben (z. B.
`openConfirmDeleteModal({ text, onConfirm })`); das Löschen von Anträgen nutzt denselben Weg.
Er liegt im DOM nach `#appointmentModal` und erscheint deshalb darüber.

## Fehlerfälle

- Termin zwischen Öffnen und Löschen von anderer Seite gelöscht → `GET`/`DELETE` liefert 404 →
  Meldung „Termin nicht mehr vorhanden“, Dialog schließen, Liste neu laden.
- Ohne Netz → Fehlermeldung, nichts geändert (wie Speichern).

## Tests

- **API** (neue Fälle in einer bestehenden Suite zu `appointments` oder eigene Suite):
  - Zählungen stimmen nach Anlegen von Rückmeldung, Antrag und Erfassung zu einem Testtermin;
  - Rolle `user` mit `dependents=1` → 403;
  - ohne Parameter keine `dependents` im Ergebnis;
  - Testdaten werden aufgeräumt.
- **Statisch** (`tests/suites/responses_pwa_frontend.php`): Bearbeiten nur für Verwalter und
  `!item.started`; Löschen-Knopf nur bei Herkunft `'responses'` im Bearbeiten-Modus; Löschen prüft
  `records` vor der Rückfrage; Bestätigungsdialog übernimmt Text und Aktion; Mutationsproben.
- **Browser** (Puppeteer gegen die Worktree-Instanz): Bearbeiten speichern; Löschen ohne und mit
  Rückmeldungen (Text der Rückfrage); Fall mit Erfassung verweist aufs Dashboard; Mitglied sieht
  weder Bearbeiten noch Löschen; begonnener Termin ohne Bearbeiten.
- `docs/testplan.md`: Fälle PWA-TL-20 ff.

## Doku

`API.md`, CHANGELOG unter `[Unreleased]` (Neu), Testplan, `docs/OPEN-ITEMS.md` (OI-125).

## Nicht Teil davon

- Löschen über die Anwesenheitsliste.
- „Dieser und alle folgenden“ für Serien.
- Serverseitige Regeln für „nur bis Beginn“ oder „nicht bei Erfassungen“.
- Bearbeiten der Beschreibung in der App.
