# Check-in-PWA: „Letzter Stand“ ohne Verbindung (OI-43, Stufe 2)

**Stand:** 2026-10-08 · **Bezug:** [OI-43](../../OPEN-ITEMS.md#oi-43--offline-betrieb-der-check-in-pwa),
Vorgänger: `2026-10-08-pwa-rahmen-cache-design.md` (Stufe 1)

## Ziel

Startet die Check-in-App ohne Verbindung, kann das Mitglied seine kommenden Termine und seinen
Verlauf so ansehen, wie es sie zuletzt geladen hat — klar als alter Stand gekennzeichnet und ohne
jede Aktion.

## Entscheidungen

| Frage | Entscheidung | Verworfen |
|---|---|---|
| Weg | Eigene Nur-Lese-Ansicht „Letzter Stand“ aus einem Schnappschuss, den die App selbst schreibt | API-Antworten im Service Worker zwischenspeichern (volle Oberfläche, an der jede Aktion scheitert; Speicher kennt keine Konten); Offline-Start in die Hauptansicht (viel Code über viele Ladefunktionen und zwei Startwege) |
| Ablage | `localStorage`, Schlüssel `offline_snapshot`, JSON | IndexedDB (unnötig für wenige KB), Cache des Service Workers |
| Wann geschrieben | Nur bei gespeichertem Token („Angemeldet bleiben“) | Immer — ohne Token zeigt die App offline die Anmeldemaske, der Schnappschuss nützte nichts |

## Schnappschuss

```json
{
  "version": 1,
  "member_id": 2,
  "saved_at": "2026-10-08T18:42:10+02:00",
  "header": { "organization": "Musikverein Musterhausen", "member": "Anna Bauer" },
  "appointments": { "saved_at": "...", "items": [ ... ] },
  "history": { "saved_at": "...", "items": [ ... ] }
}
```

- `appointments.items`: je Termin `date`, `start_time`, `end_time`, `title`, `location`,
  `response` (`yes` | `no` | `maybe` | `null`; `'info'` für einen Termin ohne Rückmeldung, dort
  zeigt die Ansicht keine Rückmeldezeile) und `absence` — der Stand der eigenen Entschuldigung
  mit denselben Texten wie die Chips der App („⏳ Entschuldigung beantragt“, „✗ entschuldigt“,
  „Entschuldigung abgelehnt“, „hat begonnen“; sonst leer). Quelle ist der von `loadResponses()`
  gehaltene Stand (`appointment_responses?upcoming=1&with_info=1`). Geschrieben wird nach dem
  erfolgreichen Laden und ebenso, wenn das Mitglied eine Rückmeldung ändert
  (`submitResponse()` → `saveAppointmentsSnapshot()`).
- `history.items`: je Eintrag `title`, `when`, `meta`, `status` als Text, so wie ihn die gerade
  angezeigte Liste trägt (`historyFromList()` liest `.history-title`, `.history-when`,
  `.history-meta` und `.response-chip` aus dem DOM, nach Zusammenführen und Kürzen). Geschrieben
  wird nach dem erfolgreichen Laden in `loadHistory()`. `loadHistory()` prüft nach jedem
  `await` die Sitzungsgeneration und nutzt die zu Beginn gemerkte `member_id`: Eine späte
  Antwort nach Abmelden oder Kontowechsel wird weder angezeigt noch gespeichert.
- Ist die Funktion Termine im Verein ausgeschaltet, wird der Teil als `{ "off": true }` markiert
  (`dropSnapshotPart()`); die Ansicht lässt den Abschnitt dann ganz weg, statt „Nicht geladen“
  zu melden oder einen alten Stand zu zeigen.
- Jeder Teil hat sein eigenes `saved_at`; der Kopf-Zeitstempel ist der jüngere der beiden. Die
  Anzeige nennt je Abschnitt seinen eigenen Stand, wenn sie sich um mehr als eine Stunde
  unterscheiden.
- Geschrieben wird teilweise: Ein Laden des Termine-Tabs ersetzt nur `appointments`, ein Laden des
  Verlaufs nur `history`. Fehlt ein gespeicherter Token, wird nichts geschrieben.
- Schreibfehler (`localStorage` voll oder gesperrt, privates Fenster) werden geschluckt; die App
  läuft ohne Schnappschuss weiter.
- Die Zuordnung der Felder eines Termins aus der API-Antwort erfolgt in `snapshotAppointment()`,
  damit die Anzeige nicht von der Struktur der API abhängt; der Verlauf wird als Text aus der
  Liste übernommen. Die gesamte Logik liegt in `public/checkin/js/snapshot.js`, einem
  klassischen Skript, das vor `app.js` geladen wird.
- Bekannte Grenze: Der Verlauf wird erst geschrieben, wenn das Mitglied den Tab „Verlauf“
  geöffnet hat (oder ein Check-in ihn auffrischt); sonst zeigt die Ansicht dort „Nicht geladen,
  solange Verbindung bestand.“
- Abgeschaltete Funktionen hinterlassen keinen Teil: Ist die Terminplanung aus (`startSession`) oder
  gibt es weder Anwesenheit noch Arbeitszeit (Verlauf-Tab ausgeblendet), setzt
  `dropSnapshotPart(part, memberId)` die Marke `{ off: true }`; die Ansicht lässt den Abschnitt weg,
  und ein Schnappschuss ohne geladenen Teil wird nicht angeboten (`snapshotHasContent`).

## Löschen

Der Schnappschuss wird überall gelöscht, wo heute `localStorage.removeItem('api_token')` steht:
Abmelden, abgelehnter Token beim Auto-Login (401/403), Konto ohne verknüpftes Mitglied,
„Mit anderem Konto anmelden“. Eine Hilfsfunktion `forgetSavedLogin()` löscht beides; die
bisherigen Einzelaufrufe gehen über sie.

Nach erfolgreichem `me` wird ein Schnappschuss mit anderer `member_id` verworfen. Ein Schnappschuss
mit unbekannter `version` wird beim Lesen ignoriert. Lässt sich ein Schnappschuss nicht darstellen
(Fehler beim Aufbau der Ansicht), wird er gelöscht und der Startbildschirm meldet „Kein letzter
Stand vorhanden.“

## Anzeige

- **Startbildschirm:** Bei „Server nicht erreichbar.“ und „Der Server antwortet nicht.“ erscheint
  zusätzlich der Knopf „Letzten Stand ansehen“ mit Datum und Uhrzeit des Stands (z. B. „Letzten
  Stand ansehen (Do. 08.10., 18:42)“), sofern ein lesbarer Schnappschuss existiert. Nicht bei der
  Sperre „Konto ohne Mitglied“ und nicht bei „Die App konnte nicht starten.“
- **Ansicht „Letzter Stand“** (eigener Bildschirm `#snapshotScreen` neben `start`, `login`,
  `main`):
  - Leiste oben: „Stand von Do. 08.10., 18:42 – ohne Verbindung“.
  - Kopf: Vereinsname, Mitgliedsname.
  - Abschnitt „Kommende Termine“: Datum, Uhrzeit (bis Ende), Titel, Ort, eigene Rückmeldung als
    Text („Zugesagt“, „Abgesagt“, „Unsicher“, „Keine Rückmeldung“; bei Terminen ohne Rückmeldung
    entfällt die Zeile) sowie der Stand einer Entschuldigung. Termine, deren Datum vor heute
    liegt, fallen bei der Anzeige weg. Leer: „Keine kommenden Termine im letzten Stand.“
  - Abschnitt „Verlauf“: Titel, Zeitpunkt, Detail, Status (als Text aus dem Schnappschuss). Leer: „Kein Verlauf im letzten Stand.“
  - Fehlt ein Teil ganz (nie geladen), steht dort „Nicht geladen, solange Verbindung bestand.“
  - Knopf „Erneut verbinden“: startet `checkAutoLogin()` wie „Erneut versuchen“.
  - Keine Tabs, keine Aktionen, keine Links.
- Alle Texte aus dem Schnappschuss werden per `textContent` gesetzt, nie über `innerHTML`.
- Verdrahtung per `addEventListener` (CSP, keine Inline-Handler).

## Was sich nicht ändert

- Hauptansicht, Tabs und alle Schreibwege. Verliert eine offene App die Verbindung, behält sie wie
  bisher die geladenen Daten.
- Statistik, offene Punkte, Anwesenheitsliste: kein Schnappschuss.
- Service Worker: nur die Vorhalteliste wächst um `js/snapshot.js?v=${VERSION}`; die neue Ansicht
  selbst ist Teil von `index.html`, `style.css`, `app.js`, die er schon vorhält.

## Tests

- **Logik (Node) `tests/js/pwa_snapshot.test.mjs`**, eingebunden über
  `tests/suites/pwa_snapshot_unit.php`: Speichern und Ersetzen der Teile, Verwerfen fremder
  Schnappschüsse, Ablaufregeln der Anzeige.
- **Statische Suite `tests/suites/pwa_snapshot_frontend.php`:**
  - `forgetSavedLogin()` existiert und löscht `api_token` und `offline_snapshot`; außerhalb von
    ihr steht kein `removeItem('api_token')` mehr.
  - Der Schreibpfad prüft den gespeicherten Token, bevor er schreibt.
  - `loadResponses()` und `loadHistory()` rufen den jeweiligen Schreibpfad auf.
  - `#snapshotScreen`, der Knopf auf dem Startbildschirm und „Erneut verbinden“ existieren in
    `index.html` und sind in `app.js` verdrahtet.
  - Die Renderfunktion der Ansicht setzt Inhalte nur über `textContent`, kein `innerHTML`.
- **`tests/browser/pwa-offline.mjs`:** angemeldet Termine- und Verlauf-Tab öffnen,
  offline neu laden, „Letzten Stand ansehen“ → beide Abschnitte mit Einträgen und Stand-Leiste;
  online abmelden → `localStorage` enthält weder `api_token` noch `offline_snapshot`.

## Dokumentation

- `docs/OPEN-ITEMS.md`, OI-43: Stufe 2 als umgesetzt vermerken.
- `public/checkin/README.md`, „Ohne Verbindung“: Knopf „Letzten Stand ansehen“.
- `DATENSCHUTZ.md`, Abschnitt 4 („Technische Daten“): was die Check-in-App auf dem Telefon
  ablegt (Token bei „Angemeldet bleiben“, Schnappschuss mit eigenen Terminen und Verlauf) und
  wann es gelöscht wird.
- `CHANGELOG.md` unter `[Unreleased]`, Rubrik wie vorhanden.

## Nicht Teil dieses Schritts

- Daten anderer Mitglieder, Statistik, offene Punkte.
- Schreiben ohne Verbindung (Stufe 3, verworfen).
- Kein Versionssprung im Feature-Zweig.
