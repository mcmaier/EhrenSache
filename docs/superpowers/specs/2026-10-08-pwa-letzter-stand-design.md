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
  `response` (`yes` | `no` | `maybe` | `null`) — aus dem Ergebnis von `loadResponses()`
  (`appointment_responses?upcoming=1&with_info=1`), nachdem es erfolgreich geladen ist.
- `history.items`: je Eintrag `date` (Sortierdatum), `kind` (`record` | `exception` |
  `session`), `title`, `detail`, `status` — aus der Liste, die `loadHistory()` gerade anzeigt
  (nach Zusammenführen und Kürzen), nachdem sie erfolgreich geladen ist.
- Jeder Teil hat sein eigenes `saved_at`; der Kopf-Zeitstempel ist der jüngere der beiden. Die
  Anzeige nennt je Abschnitt seinen eigenen Stand, wenn sie sich um mehr als eine Stunde
  unterscheiden.
- Geschrieben wird teilweise: Ein Laden des Termine-Tabs ersetzt nur `appointments`, ein Laden des
  Verlaufs nur `history`. Fehlt ein gespeicherter Token, wird nichts geschrieben.
- Schreibfehler (`localStorage` voll oder gesperrt, privates Fenster) werden geschluckt; die App
  läuft ohne Schnappschuss weiter.
- Die Zuordnung der Felder aus den API-Antworten erfolgt in einer Funktion je Teil
  (`snapshotAppointments(list)`, `snapshotHistory(entries)`), damit die Anzeige nicht von der
  Struktur der API abhängt.

## Löschen

Der Schnappschuss wird überall gelöscht, wo heute `localStorage.removeItem('api_token')` steht:
Abmelden, abgelehnter Token beim Auto-Login (401/403), Konto ohne verknüpftes Mitglied,
„Mit anderem Konto anmelden“. Eine Hilfsfunktion `forgetSavedLogin()` löscht beides; die
bisherigen Einzelaufrufe gehen über sie.

Nach erfolgreichem `me` wird ein Schnappschuss mit anderer `member_id` verworfen. Ein Schnappschuss
mit unbekannter `version` wird beim Lesen ignoriert.

## Anzeige

- **Startbildschirm:** Bei „Server nicht erreichbar.“ und „Der Server antwortet nicht.“ erscheint
  zusätzlich der Knopf „Letzten Stand ansehen“ mit Datum und Uhrzeit des Stands (z. B. „Letzten
  Stand ansehen (Do 08.10., 18:42)“), sofern ein lesbarer Schnappschuss existiert. Nicht bei der
  Sperre „Konto ohne Mitglied“ und nicht bei „Die App konnte nicht starten.“
- **Ansicht „Letzter Stand“** (eigener Bildschirm `#snapshotScreen` neben `start`, `login`,
  `main`):
  - Leiste oben: „Stand von Do 08.10., 18:42 – ohne Verbindung“.
  - Kopf: Vereinsname, Mitgliedsname.
  - Abschnitt „Kommende Termine“: Datum, Uhrzeit (bis Ende), Titel, Ort, eigene Rückmeldung als
    Text („Zugesagt“, „Abgesagt“, „Unsicher“, „Keine Rückmeldung“). Termine, deren Datum vor heute
    liegt, fallen bei der Anzeige weg. Leer: „Keine kommenden Termine im letzten Stand.“
  - Abschnitt „Verlauf“: Datum, Titel, Detail, Status. Leer: „Kein Verlauf im letzten Stand.“
  - Fehlt ein Teil ganz (nie geladen), steht dort „Nicht geladen, solange Verbindung bestand.“
  - Knopf „Erneut verbinden“: startet `checkAutoLogin()` wie „Erneut versuchen“.
  - Keine Tabs, keine Aktionen, keine Links.
- Alle Texte aus dem Schnappschuss laufen durch `escapeHtml()` oder werden per `textContent`
  gesetzt.
- Verdrahtung per `addEventListener` (CSP, keine Inline-Handler).

## Was sich nicht ändert

- Hauptansicht, Tabs und alle Schreibwege. Verliert eine offene App die Verbindung, behält sie wie
  bisher die geladenen Daten.
- Statistik, offene Punkte, Anwesenheitsliste: kein Schnappschuss.
- Service Worker: unverändert (die neue Ansicht ist Teil von `index.html`, `style.css`,
  `app.js`, die er schon vorhält).

## Tests

- **Statische Suite `tests/suites/pwa_snapshot_frontend.php`:**
  - `forgetSavedLogin()` existiert und löscht `api_token` und `offline_snapshot`; außerhalb von
    ihr steht kein `removeItem('api_token')` mehr.
  - Der Schreibpfad prüft den gespeicherten Token, bevor er schreibt.
  - `loadResponses()` und `loadHistory()` rufen den jeweiligen Schreibpfad auf.
  - `#snapshotScreen`, der Knopf auf dem Startbildschirm und „Erneut verbinden“ existieren in
    `index.html` und sind in `app.js` verdrahtet.
  - Die Renderfunktion der Ansicht setzt Inhalte nur über `escapeHtml()` oder `textContent`.
- **`tests/browser/pwa-offline.mjs` erweitern:** angemeldet Termine- und Verlauf-Tab öffnen,
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
