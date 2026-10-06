# OI-124 · Rückmeldungen bei Terminverlegung zurücksetzen — Design

**Stand:** 2026-10-06 · **Bezug:** `docs/OPEN-ITEMS.md` OI-124, Terminrückmeldung (FI-1, 1.7.0,
Spec `2026-09-14-terminrueckmeldung-design.md`)

## 1 · Problem

`PUT appointments` und `PUT appointment_series` („dieser und alle folgenden“) fassen
`appointment_responses` nicht an. Eine Zusage für „Dienstag 19 Uhr“ gilt nach einer Verlegung
stillschweigend weiter; die Besetzungsübersicht zeigt Zusagen, die niemand für den neuen
Zeitpunkt gegeben hat.

## 2 · Entscheidungen

| # | Frage | Entscheidung |
|---|---|---|
| E1 | Was wird zurückgesetzt? | Nur Rückmeldungen mit Status `yes` und `maybe`. **Absagen (`no`) bleiben** — sie hängen oft an einem Entschuldigungsantrag und sind nach einer Verlegung meist weiter richtig. |
| E2 | Wann wird gefragt? | Nur wenn sich **Datum oder Beginn** tatsächlich ändern (`appointmentFieldChanged`). Ende, Ort, Titel, Beschreibung, Terminart lösen nichts aus. |
| E3 | Wo? | Überall, wo diese Felder geändert werden können: Dashboard Einzeltermin, Dashboard „dieser und alle folgenden“, Check-in-App. |
| E4 | Wer fragt? | **Der Server** (Ansatz A). Ohne Entscheidung des Clients antwortet er mit 409; der Client fragt den Nutzer und wiederholt die Anfrage mit `reset_responses`. |

Verworfen: Rückfrage rein im Client (Regel dreifach, Zahl kann veralten, für Serien ein eigener
Zählaufruf nötig); stilles Zurücksetzen ohne Rückfrage (widerspricht dem Wunsch).

## 3 · Server

### 3.1 Helfer in `private/helpers/responses.php`

- `responsesCountResettable(PDO $db, string $prefix, array $appointmentIds): array` —
  liefert `['appointments' => int, 'responses' => int]`: Anzahl der Termine mit mindestens einer
  Rückmeldung `yes`/`maybe` und Anzahl dieser Rückmeldungen. Leere Liste → beides 0.
- `responsesReset($db, string $prefix, array $appointmentIds): int` — löscht die Rückmeldungen
  `yes`/`maybe` dieser Termine und gibt die Anzahl zurück. Läuft innerhalb der Transaktion des
  Aufrufers. **Beim Planen geprüft:** `responseExcuseAction()` liefert für einen alten Status
  `yes`/`maybe` und neuen Status `null` nur `none` oder `keep`, nie `delete`. Ein ausnahmsweise
  verknüpfter Antrag bleibt also stehen wie beim heutigen `DELETE appointment_responses`; ein
  schlichtes `DELETE … WHERE status IN ('yes','maybe')` genügt.
- `responsesResetFlag(array $body)` liest das Feld (siehe 3.2), `responsesAffectedBody()` baut den
  409-Körper.

### 3.2 Parameter `reset_responses`

Im JSON-Körper von `PUT appointments` und `PUT appointment_series`.

| Wert | Wirkung |
|---|---|
| fehlt | Ändern sich Datum/Beginn und gibt es rücksetzbare Rückmeldungen → **409**, nichts geschrieben. Sonst normal. |
| `true` | Änderung und Zurücksetzen in **einer Transaktion**. |
| `false` | Verhalten wie bis heute: Rückmeldungen bleiben. |
| anderer Typ | **400** „reset_responses muss true oder false sein“. |

409-Körper:

```json
{
  "code": "responses_affected",
  "message": "Für den Termin liegen Zusagen vor, Datum oder Beginn ändern sich",
  "appointments": 1,
  "responses": 12
}
```

Der Dubletten-409 (`appointmentConflictBody`) trägt kein `code`; Clients unterscheiden über
`code === 'responses_affected'`. Die Dublettenprüfung läuft **vorher** — eine Anfrage, die an
einer Dublette scheitern würde, fragt nicht erst nach den Rückmeldungen.

Erfolgsantworten enthalten zusätzlich `responses_reset: <int>` (0, wenn nichts zurückgesetzt
wurde).

### 3.3 `PUT appointments` (Einzeltermin)

Reihenfolge: Validierung → Dublettenprüfung → **Rückmeldungsprüfung** → Update.
`date` oder `start_time` gelten als geändert, wenn `appointmentFieldChanged()` für das
mitgeschickte Feld gegen den Bestand `true` liefert. Update, Seriendatums-Ausfall (FI-7) und
`responsesReset()` laufen in einer Transaktion (bisher ohne Transaktion).

### 3.4 `PUT appointment_series` („dieser und alle folgenden“)

Das Datum ändert sich hier nie, nur der Beginn. Die Schleife in `seriesHandleUpdateFollowing()`
sammelt die IDs der Termine, die tatsächlich aktualisiert werden **und** deren wirksamer Beginn
sich ändert (abgelöste — Konflikt, `has_data`, ungültige Zeit — zählen nicht). Nach der Schleife,
noch in der Transaktion:

- `reset_responses` fehlt und `responsesCountResettable()` > 0 → **Rollback**, 409 mit der Zahl
  der Termine und Rückmeldungen.
- `true` → `responsesReset()`, Commit.
- `false` → Commit wie heute.

Der Kommentar in der Schleife („Eine Rückmeldung … darf sich mit der Serie weiterbewegen“) bleibt
gültig: Die Verschiebung selbst wird nicht verhindert, es wird nur gefragt, was mit den Zusagen
geschieht. Der Kommentar wird um den Verweis auf OI-124 ergänzt.

### 3.5 Nicht im Umfang

- Split der Serienregel: Termine mit Daten bleiben dort abgelöst stehen, statt verschoben zu
  werden — kein Verlegungsfall.
- Absagen und Anträge (E1), Benachrichtigung (FI-6). Betroffene sehen den Termin über „Offene
  Punkte“ (FI-17) wieder als unbeantwortet — das ist bis FI-6 der einzige Hinweis.
- Kein Schema, keine Migration, kein Feature-Schalter (gehört zur Terminrückmeldung; ist sie
  abgeschaltet, gibt es keine Rückmeldungen und damit nie eine 409).

## 4 · Oberflächen

### 4.1 Dashboard (`public/js/modules/appointments.js`, `saveAppointment()`)

Antwortet `PUT appointments` oder `PUT appointment_series` mit `code: 'responses_affected'`, fragt
`showChoice()`:

> Für diesen Termin liegen 12 Zusagen bzw. „unsicher“ vor, Datum oder Beginn ändern sich.
> Sollen diese Rückmeldungen zurückgesetzt werden? Absagen bleiben bestehen.

Serie: „Bei 7 Terminen liegen 43 Zusagen bzw. ‚unsicher‘ vor …“.
Optionen: „Zurücksetzen“ (`true`), „Beibehalten“ (`false`); Schließen bricht ab, der Dialog
bleibt offen. Danach dieselbe Anfrage mit `reset_responses`. Die 409 darf nicht als Fehler-Toast
erscheinen — zu prüfen, wie `apiCall()` 409 behandelt, und dort den Fall durchreichen.

Toast nach Erfolg nennt `responses_reset`, wenn > 0. Cache: zusätzlich zu den bisherigen
Schlüsseln die, die Rückmeldungen anzeigen (Kalender-Zusammenfassung, offene Punkte).

### 4.2 Check-in-App (`public/checkin/js/app.js`, `submitAppointmentForm()`)

Gleiche Logik mit dem Bestätigungsdialog der App; die Frage erscheint über dem offenen
Termin-Dialog, Abbrechen lässt die Eingaben stehen.

Beide Clients: keine Inline-Handler (CSP), Aktionen über `data-action`/`registerActions()` bzw.
das vorhandene Muster der App.

## 5 · Tests

API-Suite (neu oder in der vorhandenen Termin-/Rückmeldungs-Suite), je für Einzeltermin und
Serie:

1. Datum/Beginn geändert, Zusagen vorhanden, kein Flag → 409 `responses_affected` mit korrekten
   Zahlen; **danach ist der Termin unverändert** und alle Rückmeldungen sind noch da.
2. `true` → Termin geändert, `yes`/`maybe` weg, `no` bleibt samt Antrag, `responses_reset` stimmt.
3. `false` → Termin geändert, alle Rückmeldungen bleiben.
4. Nur Titel/Ort/Ende geändert, Zusagen vorhanden, kein Flag → 200, nichts zurückgesetzt.
5. Datum geändert, nur Absagen vorhanden → 200 ohne Rückfrage.
6. `reset_responses: "ja"` → 400.
7. Dublette und Zusagen zugleich → Dubletten-409 (ohne `code`).
8. Serie: abgelöste Termine (Konflikt, Anwesenheit) zählen nicht mit und behalten ihre Zusagen.

Frontend-Wächter: Beide Clients behandeln `responses_affected` (Quelltextprüfung über
`tests/lib/source.php`). Mutationsprobe nach dem Commit: Prüfung im Server auskommentieren →
Test 1 muss rot werden.

## 6 · Doku

- `API.md`: Parameter `reset_responses`, 409 `responses_affected`, Feld `responses_reset` bei
  `appointments` und `appointment_series`.
- `CHANGELOG.md` unter `[Unreleased]`: Funktion plus Hinweis auf die API-Änderung (Skripte, die
  Termine verlegen, bekommen ohne Flag eine 409).
- `docs/OPEN-ITEMS.md`: OI-124 als erledigt.
- `docs/testplan.md`: Fall im Abschnitt Termine.
