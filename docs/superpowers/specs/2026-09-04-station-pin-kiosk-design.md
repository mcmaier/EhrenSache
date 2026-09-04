# Design: Stations-Anmeldung mit PIN, Kiosk-PWA und Gerätetyp „Station"

**Stand:** 2026-09-04 · **Bezug:** `dev` 1.2.5 · **Zielversion:** 1.3.0 (Schemaänderung)
**Status:** Design freigegeben am 2026-09-04, Umsetzung noch nicht begonnen

---

## 1 Abgleich mit `docs/OPEN-ITEMS.md` und `docs/FEATURE-IDEAS.md`

| Quelle | Inhalt | Verhältnis zu diesem Plan |
|---|---|---|
| FI-5 · PIN-Anmeldung am Auth-Gerät | PIN im Profil, Stempeluhr-Prinzip, zwei Schritte (Kennung + PIN), Sperre je Mitglied, Hash-Speicherung | **Kern dieses Plans.** Alle vier „vorher zu klären"-Punkte werden unten entschieden |
| FI-4 · Registrierungsprozess für Auth-Geräte | Drei Verfahren (TOTP, NFC, Biometrie), erst eines auswählen | **Teilweise.** Gebaut wird das Verfahren „PIN, serverseitig geprüft". Der Registrierungsweg der virtuellen Station wird definiert. NFC und Biometrie bleiben ausdrücklich draußen |
| OI-6 · TOTP-Secret im Klartext | Lösungswege: Selbstregistrierung, „PWA im Stations-Modus" | **Stations-Modus wird gebaut**, aber so, dass das Secret den Server **nie verlässt**: Der Server liefert den aktuellen Code, nicht das Secret. Die verschlüsselte Ablage in der DB bleibt offen (siehe Abschnitt 9) |
| OI-7 · Gültigkeitsfenster | Bewusst ±1 Fenster | Unverändert. Die Kiosk-Anzeige nutzt dieselbe `TOTP`-Klasse |
| FI-3 · GPS-Check-in | Beweiswert und Quellenkennzeichnung gemeinsam mit FI-4/FI-5 festlegen | Die neue Quellenkennzeichnung (`station_pin`, `station`) folgt dem dort geforderten Muster „kenntlich machen, nicht überhöhen". GPS selbst wird nicht gebaut |
| Bewusst entschieden: kein Offline-Betrieb der PWA | Client-Zeitstempel sind wertlos | Übernommen: Der Kiosk zeigt ohne Verbindung keinen Code und nimmt keine Stempel an |
| Bewusst entschieden: kein `force` beim Timer-Start | — | Übernommen |
| Manager sehen alle Datensätze | — | Nicht berührt |

**Reihenfolge laut FEATURE-IDEAS** („5. FI-4, dann FI-5, dann FI-3"): Der Plan hält sie ein — Gerätetyp und Registrierung (FI-4-Anteil) kommen in Phase 1, PIN (FI-5) in Phase 2, GPS bleibt liegen.

---

## 2 Zielbild

Ein ausgemustertes Tablet hängt im Proberaum. Es zeigt Uhrzeit und den rotierenden
Stations-Code als QR und als Ziffern — wer die Check-in-PWA auf dem Handy hat, scannt wie
bisher. Wer kein Handy dabei hat oder keine App will, tippt seine Mitgliedsnummer und PIN
ein und stempelt: Anwesenheit oder Arbeitszeit (Start, Pause, Ende). Nach dem Stempeln kehrt
das Tablet von selbst zum Ruhebild zurück.

Für den Verein: ein Gerät statt ESP32-Bastelei, ein Registrierungsweg, ein Ort für beide
Erfassungsarten.

### 2.1 Zwei Vertrauensmodelle, die sauber getrennt bleiben

| Modell | Wer prüft die Identität | Heute vorhanden | In diesem Plan |
|---|---|---|---|
| **Gerät bürgt** (`device_auth`) | Das Gerät (Fingerabdruck, Karte) — der Server glaubt der `member_number` | ja, `auto_checkin` mit Geräte-Token | unverändert, nur Anwesenheit |
| **Server prüft** (neu: `station_pin`) | Der Server gegen `pin_hash` — das Gerät ist nur Tastatur und Bildschirm | nein | neu, Anwesenheit **und** Arbeitszeit |

Diese Trennung ist der Grund, warum es einen eigenen Endpunkt `station` gibt und nicht
einfach `auto_checkin` um ein PIN-Feld wächst: Ein kompromittiertes Geräte-Token darf beim
PIN-Weg **nicht** genügen, um für beliebige Mitglieder zu stempeln.

---

## 3 Entscheidungen

Alle Entscheidungen sind am 2026-09-04 bestätigt worden. E3, E8, E9 und E10 waren als
Rückfragen gestellt und wurden mit „ja" beantwortet (Protokoll in Abschnitt 11).

| # | Frage | Entscheidung | Begründung |
|---|---|---|---|
| E1 | Wo liegt die PIN? | **`members.pin_hash`**, nicht `users` | Der Sinn von FI-5 sind Mitglieder **ohne** Konto. Ein Mitglied mit Konto ändert die PIN im Profil (`profile.js`), ein Mitglied ohne Konto bekommt sie vom Verwalter |
| E2 | Welche Kennung? | **`members.member_number`**, keine neue Spalte | Existiert, wird in Listen und Export bereits als Mitgliedsnummer geführt, Eindeutigkeit wird beim Anlegen schon geprüft (`members.php:260`). Migration meldet Altbestand-Duplikate als Warnung; die Station lehnt eine mehrdeutige Nummer ab |
| E3 | Rein numerisch? | **Nein, alphanumerisch erlaubt**, Kiosk-Tastatur zeigt Ziffernblock mit Umschalter | `member_number` ist `varchar(50)` und in Beispieldaten „M123". Eine Pflicht auf Ziffern wäre ein Bruch mit Bestandsdaten |
| E4 | Gerätetyp | **Neuer Wert `kiosk` in `users.device_type`** („Virtuelle Station") | Ein Tablet ist beides: TOTP-Anzeige und PIN-Eingabe. Zwei Gerätekonten für ein Gerät wären Verwaltungslast. `totp_location` und `auth_device` bleiben für Hardware |
| E5 | TOTP-Anzeige: Secret aufs Tablet? | **Nein.** Server liefert aktuellen und nächsten Code per API | Erfüllt OI-6 im Geist („nicht rücklesbar") ohne Verschlüsselungsinfrastruktur. Kosten: ein Request je 30 s je Kiosk; bei 150/min Rate-Limit unkritisch |
| E6 | PIN-Übertragung je Aktion oder Ticket? | **Je Aktion** (Nummer + PIN in jedem POST), Kiosk hält beides nur im Speicher und verwirft nach Ruhezeit | Kein neuer Tabellen- oder Signaturmechanismus. Der Preis — die PIN geht zwei- bis dreimal statt einmal über die Leitung — ist bei HTTPS bedeutungslos |
| E7 | Quellenkennzeichnung | `records.checkin_source` + `station_pin` · `work_sessions.source` + `station` | Verlangt in FI-3/FI-5: Die Quelle muss in jeder Auswertung als das erkennbar sein, was sie ist |
| E8 | Zählt ein Kiosk-Stempel als Ortsnachweis? | **Ja**: `start_location_name` / `end_location_name` = Gerätename des Kiosks | Wer am Kiosk tippt, war am Kiosk. Schwach ist nur die *Identität* (PIN weitergebbar), nicht der *Ort*. Damit sind nachweispflichtige Tätigkeitsarten am Kiosk startbar. `DATENSCHUTZ.md` 10.7 bekommt den Zusatz, dass PIN-Stempel delegierbar sind |
| E9 | Terminwahl am Kiosk | **Serverseitige Auswahl** wie `auto_checkin`, keine Terminliste | Ein Kiosk soll in drei Tipps fertig sein. Die Client-Terminwahl aus 1.2.4 bleibt der Handy-PWA vorbehalten. Kein passender Termin → Meldung, kein Stempel |
| E10 | Mitgliederliste zum Antippen statt Nummer? | **Nein** | Namen aller Mitglieder auf einem öffentlich stehenden Bildschirm sind ein Datenschutzthema ohne Not. Nummer + PIN in einem Request verhindert zudem das Ausprobieren von Nummern |
| E11 | PIN-Regeln | 4–8 Ziffern, Mindestlänge konfigurierbar (`station_pin_min_length`, Standard 4); abgelehnt: alle Ziffern gleich, auf- oder absteigende Folge | Vier Stellen sind Stempeluhr-Standard; die Sperre trägt die Sicherheit, nicht die Länge |
| E12 | Sperre | 5 Fehlversuche je Mitglied → 15 Minuten; 30 Fehlversuche je Kiosk → 15 Minuten; Antwort immer „Nummer oder PIN falsch" | Wiederverwendung von `RateLimiter::check()` nach dem Muster `canAttemptLogin()`. Die Kiosk-Sperre stoppt Nummern-Durchprobieren, die Mitgliedssperre den Angriff auf eine Person |
| E13 | Feature-Schalter | `station_pin_enabled` (Standard 0) in `system_settings`, Abschnitt Einstellungen | Ein Verein ohne Kiosk soll das PIN-Feld im Profil nicht sehen. Analog `worktime_enabled` |
| E14 | Kiosk-Sperrung des Tablets | **Nicht gebaut**, dokumentiert (Android App-Pinning, iPadOS „Geführter Zugriff") | Eine Web-App kann den Browser nicht einsperren. Was wir liefern: Vollbild, Wake Lock, kein Navigationspfad heraus |
| E15 | NFC, Fingerabdruck, GPS | **Nicht in diesem Plan** | FI-4 sagt es selbst: alle drei gleichzeitig heißt, keines fertig zu bekommen |

---

## 4 Datenmodell

Migration `private/migrations/1.2.5.php` mit `migrate_1_2_5()`, Manifest-Eintrag
`1.2.5 → 1.3.0`, Schema in `private/setup/ehrensache_db.sql` nachziehen.

```sql
ALTER TABLE {PREFIX}members
  ADD COLUMN pin_hash       VARCHAR(255) NULL AFTER member_number,
  ADD COLUMN pin_updated_at DATETIME     NULL AFTER pin_hash;

ALTER TABLE {PREFIX}users
  MODIFY device_type ENUM('totp_location','auth_device','kiosk') DEFAULT NULL;

ALTER TABLE {PREFIX}records
  MODIFY checkin_source ENUM('admin','user_totp','device_auth','auto_checkin',
                             'import','timer','station_pin') DEFAULT 'admin';

ALTER TABLE {PREFIX}work_sessions
  MODIFY source ENUM('timer','manual','admin','import','station') NOT NULL DEFAULT 'manual';

INSERT IGNORE INTO {PREFIX}system_settings (setting_key, setting_value) VALUES
  ('station_pin_enabled', '0'),
  ('station_pin_min_length', '4');
```

Zusätzlich in der Migration: `SELECT member_number, COUNT(*) … HAVING COUNT(*) > 1` → je
Duplikat eine Zeile in `warnings`. Kein Unique-Index, damit ein Altbestand das Update nicht
blockiert; die Eindeutigkeit erzwingt der Members-Handler beim Anlegen und künftig auch beim
Bearbeiten (heute nur beim Anlegen — kleine Lücke, die dabei geschlossen wird).

`work_session_log.user_id` und `work_sessions.created_by` nehmen die `user_id` des
Kiosk-Gerätekontos auf — der FK auf `users` passt, weil Geräte dort liegen.

---

## 5 API: Ressource `station`

Datei `private/handlers/station.php`, `case 'station'` in `api.php`, Abschnitt in `API.md`.
Nur Geräte-Token (`isDevice()` **und** `device_type = 'kiosk'`), sonst 403. Keine CSRF-Prüfung
nötig (Token-Auth). Steuerung über `?action=`.

| Methode | action | Body | Antwort | Zweck |
|---|---|---|---|---|
| GET | `status` | — | `device_name`, `totp_enabled`, `pin_enabled`, `pin_min_length`, `server_time`, `worktime_enabled` | Beim Start und alle 5 Minuten; erkennt Abschaltung des Features |
| GET | `totp` | — | `code`, `next_code`, `valid_until` (Unix), `period` | Nur wenn `totp_secret` gesetzt. Kiosk holt beim Ablauf den nächsten |
| POST | `identify` | `member_number`, `pin` | `member` (Vorname, Nachname), `checkin_candidate` (Termin oder null, bereits eingecheckt?), `running_session` (oder null), `activities` (erlaubte, aktive Tätigkeitsarten mit `verification`) | Erste Prüfung, liefert den Bildschirm „Was möchtest du tun?" |
| POST | `checkin` | `member_number`, `pin` | wie `auto_checkin` | Anwesenheit; Quelle `station_pin`, `source_device` = Kiosk-Name, `location_name` = Kiosk-Name |
| POST | `work_start` | `member_number`, `pin`, `activity_id` | Sitzung | Quelle `station`, `start_location_name` = Kiosk-Name |
| POST | `work_pause` / `work_resume` / `work_stop` | `member_number`, `pin` | Sitzung | `end_location_name` = Kiosk-Name beim Stop |

**Fehlerbilder:** 401 „Nummer oder PIN falsch" (auch bei unbekannter oder mehrdeutiger
Nummer, bei fehlender PIN, bei inaktivem Mitglied) · 423 „Gesperrt, N Minuten" · 409 wenn
`station_pin_enabled = 0` · 404 „Kein passender Termin" bei `checkin`.

**PIN-Prüfung in einer Funktion** `stationAuthenticate($db, $database, $deviceId, $number,
$pin): int|null` — wird von jeder Aktion aufgerufen. Reihenfolge: Kiosk-Sperre prüfen →
Mitglied laden (`member_number` eindeutig, `active = 1`, `pin_hash IS NOT NULL`) →
Mitgliedssperre prüfen → `password_verify` → bei Erfolg beide Zähler zurücksetzen.
Der zeitliche Ablauf ist bewusst gleich lang, egal an welcher Stufe es scheitert (immer
`password_verify` gegen einen Dummy-Hash laufen lassen).

### 5.1 Wiederverwendung statt Kopie — nötige Refactorings

- **`auto_checkin.php`:** `handleAutoCheckin()` liest `php://input` und entscheidet über
  `isDevice()`. Herauslösen: `performCheckin($db, $database, int $memberId, DateTime
  $arrival, string $source, array $sourceInfo, ?int $appointmentId): array` — Terminsuche,
  Toleranz, Auto-Anlage, Duplikatprüfung. `handleAutoCheckin()` und `totp_checkin.php`
  rufen es auf; `station.php` ebenfalls.
- **`work_sessions.php`:** `workSessionStart/Pause/Resume/Stop()` bekommen einen optionalen
  Parameter `array $override = []` mit den Schlüsseln `source` und `location`. Ist
  `location` gesetzt, entfällt die TOTP-Auflösung und der Ortsnachweis gilt als erbracht (E8).
  Die Sperre „Devices cannot access work sessions" am Ressourceneinstieg bleibt — der Kiosk
  kommt nur über `station` hinein.
- **`totp.php`:** `resolveTotpLocation()` und `countTotpLocations()` auf
  `device_type IN ('totp_location','kiosk')` erweitern. Neue Funktion
  `totpCodesForDevice(string $secret): array{code, next_code, valid_until}`.

### 5.2 PIN setzen

| Wer | Wo | Endpunkt | Regel |
|---|---|---|---|
| Mitglied mit Konto | Profil (`profile.js`) | `POST change_pin` — neuer Handler nach Vorbild `change_password.php` | Aktuelles Passwort + neue PIN; nur wenn `member_id` verknüpft und Feature an |
| Admin/Manager | Mitglied bearbeiten (`members.js`) | `PUT members&id=…` mit Feld `pin` (setzen) oder `pin: null` (löschen) | Nie zurücklesbar; die Antwort enthält nur `pin_set: true/false`. `GET members` liefert `has_pin` |

Beide Wege laufen durch eine gemeinsame Validierung `validateStationPin(string $pin, int
$minLength): ?string` (Fehlertext oder null) in `private/helpers/station.php` — als Unit-Test
prüfbar, ohne Datenbank.

---

## 6 Kiosk-PWA `public/station/`

Eigenständig, getrennt von `public/checkin/` (anderer Nutzer, anderer Bildschirm, anderer
Service-Worker-Scope). Struktur wie die Check-in-PWA: `index.html`, `manifest.json`
(`scope: /station/`, `display: fullscreen`, `orientation: landscape`), `service-worker.js`
(nur App-Shell cachen, nie API-Antworten), `css/style.css`, `js/app.js`. Kein Framework,
QR-Erzeugung mit einer kleinen eingebetteten Bibliothek (wie die Check-in-PWA den Scanner
einbettet; keine CDN-Abhängigkeit im Betrieb).

### 6.1 Bildschirme

```
[Einrichtung]  einmalig: API-Basis wird wie in config.js erkannt, Geräte-Token eingeben
      │        → GET station&action=status → Token in localStorage
      ▼
[Ruhebild]     Uhr, Vereinslogo/Farben (appearance-Endpunkt), TOTP-QR + Ziffern mit
      │        Restlaufbalken (wenn aktiviert), Schaltfläche „Stempeln" (wenn PIN aktiviert)
      ▼
[Nummer]       Ziffernblock, Umschalter auf Buchstaben (E3), Abbrechen
      ▼
[PIN]          Ziffernblock, maskiert, → POST identify
      ▼
[Aktion]       „Hallo Anna" · Anwesenheit: „Probe 19:30 — einchecken" oder „bereits
      │        eingecheckt" oder „kein Termin" · Arbeitszeit: läuft seit 18:02 → Pause/Ende,
      │        sonst Tätigkeitsart wählen (Standard vorausgewählt) → Start
      ▼
[Bestätigung]  3 s, dann Ruhebild. Nummer und PIN werden aus dem Speicher gelöscht.
```

Ruhezeit-Rückfall: 30 s ohne Berührung → Ruhebild (Wert in den Kiosk-Einstellungen,
localStorage). Fehler des Servers werden als Klartext angezeigt, nie als JSON.

### 6.2 Technik

- **Wake Lock API** hält den Bildschirm an; Rückfall auf Hinweis „Bildschirm-Timeout im
  Tablet deaktivieren", wenn nicht verfügbar.
- **Verbindungsverlust:** Ruhebild zeigt „Keine Verbindung", kein Code, kein Stempeln.
- **Token-Ablauf:** 401 → zurück zur Einrichtung mit Hinweis.
- **Einstellungen im Kiosk** (Geste: 5 s auf die Uhr drücken, dann Geräte-Token erneut
  eingeben als Schutz): Ruhezeit, Token wechseln, App neu laden.
- **HTTPS** ist Voraussetzung für Installation und Wake Lock — in die README.

### 6.3 Was die Check-in-PWA (Handy) davon merkt

Nichts. Der QR-Inhalt bleibt `CHECKIN:123456` (`onQRScanned`, `app.js:1653`).

---

## 7 Dashboard-Änderungen

| Modul | Änderung |
|---|---|
| `devices.js` | Dritter Gerätetyp „Virtuelle Station (Kiosk)"; Schalter „zeigt Stations-Code" (erzeugt/löscht `totp_secret`); Anzeige des Secrets für Kiosks **entfällt** — es wird dort nie gebraucht (Teilerledigung OI-6: für Kiosks). Token-Anzeige wie heute für die Einrichtung |
| `members.js` | Spalte/Badge „PIN gesetzt"; im Bearbeiten-Modal Felder „PIN setzen" / „PIN löschen", nur wenn Feature an |
| `profile.js` | Abschnitt „Stations-PIN", nur wenn Feature an und Mitglied verknüpft |
| `settings.js` | Schalter `station_pin_enabled`, Zahl `station_pin_min_length` |
| `records.js`, `worktime.js`, Statistik, Export | Quellen `station_pin` und `station` mit Label „Station (PIN)" in Filtern, Badges, CSV |
| `index.html` | Link zur Kiosk-PWA in der Geräteverwaltung |

---

## 8 Sicherheit im Überblick

- PIN nur als `password_hash()`; kein Endpunkt liefert sie je zurück; Export enthält sie nicht;
  Selbstauskunft (`my_data`) meldet nur „PIN gesetzt: ja/nein, seit …".
- Sperren nach E12; einheitliche Fehlermeldung; konstante Prüfdauer.
- Geräte-Token des Kiosks erlaubt **ausschließlich** `station`, `appearance`, `ping`,
  `version` — der bestehende Weg `auto_checkin` mit `device_auth` bleibt für `auth_device`
  und `totp_location`, nicht für `kiosk`. Damit ist ein gestohlenes Kiosk-Token ohne PIN
  eines Mitglieds wertlos.
- Secret verlässt den Server nicht (E5). Bleibt: Klartext in der DB, lesbar für Admins mit
  DB-Zugang — unverändert OI-6, Abschnitt 9.
- Kiosk-PWA speichert nur Token und Ruhezeit; Nummer und PIN nie persistent.
- Rate-Limit `api_request` (150/min je IP+User) gilt auch für den Kiosk; das Polling alle
  30 s liegt weit darunter.

---

## 9 Was bewusst offen bleibt

- **OI-6, verschlüsselte Ablage:** Wäre ein eigener Schritt (Schlüssel in `config.php`,
  Installer und Updater schreiben ihn, Umschlüsselung im Update). Dieser Plan reduziert die
  Angriffsfläche (kein Secret mehr auf Tablets, keine Anzeige für Kiosks), löst OI-6 aber
  nicht. Eintrag in OPEN-ITEMS entsprechend aktualisieren, nicht schließen.
- **QR-Pairing des Kiosks** (Admin zeigt QR mit Token, Tablet scannt): Komfort, kein Muss.
  Erst wenn ein Verein über die Token-Eingabe stolpert.
- **Geräte-bürgender Weg für Arbeitszeit** (ESP32 mit Karte startet Timer): Der Endpunkt
  `station` ist dafür vorbereitet (später `auth_method: device` für `auth_device`), wird aber
  nicht gebaut. FI-4 bleibt für NFC/Biometrie offen.
- **Terminwahl am Kiosk** (E9): nachrüstbar, `identify` liefert heute schon den Kandidaten.

---

## 10 Umsetzung in Phasen

Jede Phase ist für sich mergefähig und testbar. Reihenfolge folgt FEATURE-IDEAS (FI-4 vor FI-5).

### Phase 1 — Gerätetyp `kiosk` und Code-Auslieferung (FI-4-Anteil, OI-6-Teil)
1. Migration 1.2.5 → 1.3.0 (nur Enum-Erweiterungen und Settings; die PIN-Spalten gleich mit,
   damit es **eine** Migration bleibt), Schema-SQL, `version.json`, `CHANGELOG.md`.
2. `totp.php`: Kiosk in Auflösung aufnehmen, `totpCodesForDevice()`.
3. `station.php` mit `status` und `totp`; `api.php`-Case; Token-Einschränkung für Kiosks.
4. `devices.js`: dritter Typ, Secret-Anzeige für Kiosks weglassen.
5. Tests: `tests/suites/station_api` (status/totp mit Kiosk-Token, 403 mit Nutzer-Token,
   403 für `auto_checkin` mit Kiosk-Token), Migrationstest um 1.2.5 ergänzen.
6. `API.md` Abschnitt Station, `README.md` Gerätetypen.

### Phase 2 — PIN (FI-5)
1. `private/helpers/station.php`: `validateStationPin()`, `stationAuthenticate()`.
2. `members.php`: Feld `pin` (setzen/löschen), `has_pin` in GET, Eindeutigkeit von
   `member_number` auch beim Bearbeiten.
3. `change_pin.php` + Profil-Abschnitt.
4. `settings.php` / `settings.js`: beide Schlüssel.
5. `station.php`: `identify`. Refactoring `performCheckin()` in `auto_checkin.php`, dann
   `checkin`. Refactoring `$override` in `work_sessions.php`, dann `work_start/pause/resume/stop`.
6. Quellen in Oberfläche, Export, Statistik.
7. Tests: `tests/suites/station_unit` (PIN-Regeln, Sperrlogik gegen Stub), `station_api`
   erweitern (falsche PIN → 401, 6. Versuch → 423, Erfolg → Record mit `station_pin`,
   Start/Stop → Sitzung mit `source = station` und beiden Ortsnamen). **Kein Lösch-Endpunkt
   im Testlauf**, dedizierte Testmitglieder anlegen.
8. `DATENSCHUTZ.md`: PIN als Datum (Hash, Löschfrist mit Mitglied), 10.7 um Delegierbarkeit
   ergänzen; `docs/testplan.md` Abschnitt Station.

### Phase 3 — Kiosk-PWA
1. `public/station/` nach Abschnitt 6, Copyright-Header, `.htaccess`-Prüfung (Scope).
2. Manuelle Verifikation am Tablet und im Browser-Vollbild; Screenshots nach
   `temporary_screenshots/`.
3. `public/station/README.md` (Einrichtung, App-Pinning/Geführter Zugriff, HTTPS, Wake Lock).
4. `docs/FEATURE-IDEAS.md`: FI-5 als umgesetzt austragen, FI-4 auf NFC/Biometrie eingrenzen;
   `docs/OPEN-ITEMS.md`: OI-6 aktualisieren.

**Aufwandsschätzung** (nach dem Schema in FEATURE-IDEAS): Phase 1 **S–M**, Phase 2 **M**,
Phase 3 **M**. Zusammen die größte Erweiterung seit der Zeiterfassung.

---

## 11 Entscheidungsprotokoll

| Datum | Frage | Antwort |
|---|---|---|
| 2026-09-04 | E3 Mitgliedsnummer alphanumerisch belassen | ja |
| 2026-09-04 | E8 PIN-Stempel am Kiosk zählt als Ortsnachweis | ja |
| 2026-09-04 | E9 Terminwahl am Kiosk dem Server überlassen | ja |
| 2026-09-04 | E10 keine Namensliste am Kiosk | ja |
| 2026-09-04 | Ablage dieses Entwurfs als Spec im Repository | ja |

Nächster Schritt: Umsetzungsplan unter `docs/superpowers/plans/` (nicht im Repository).
