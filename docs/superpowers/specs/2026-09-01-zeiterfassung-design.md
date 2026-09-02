# Design: Zeiterfassung für ehrenamtliche Arbeiten

**Datum:** 2026-09-01
**Status:** Entwurf, abgestimmt
**Betrifft:** EhrenSache — Erweiterung um Erfassung und Nachweis geleisteter Stunden
**Codebasis:** `dev` @ `5d8074d` (Stand nach Merge von `main`, 2026-09-01)

---

## 1. Ziel

Mitglieder sollen geleistete ehrenamtliche Arbeitszeit mit Anfang, Ende und Pause erfassen
können — sowohl bei bestehenden Terminen als auch bei Einsätzen ohne Termin. Die erfassten
Stunden dienen zwei Zwecken:

1. **Nachweis nach außen** — personenbezogener Stundennachweis für Ehrenamtskarte,
   Ehrungen und Bescheinigungen.
2. **Projekt- und Förderbezug** — Summen je Tätigkeitsart für Verwendungsnachweise
   gegenüber Fördergebern.

Wo der Verein es verlangt, wird der Beginn — und auf Wunsch auch das Ende — durch einen
Scan an einer TOTP-Station ortsbelegt, damit die ausgewiesenen Stunden nachprüfbar sind.

Nicht Ziel sind interne Lastverteilungsanalysen oder eine Arbeitszeitkontrolle.

## 2. Ausgangslage im Bestand

| Bereich | Ist-Zustand | Konsequenz |
|---|---|---|
| `records` | `UNIQUE(member_id, appointment_id)`, ein einzelnes `arrival_time`, kein Ende | Strukturell ungeeignet für Zeiträume; keine Erweiterung, sondern neue Entität |
| `appointments` | nur `date` + `start_time`, kein Ende | Termindauer ist unbekannt und bleibt es |
| `statistics.php` | rechnet Anwesenheitsquote und Pünktlichkeit gegen `start_time` | Kennt keinen Stundenbegriff |
| `exceptions` | Genehmigungs-Workflow vorhanden, aber zwingend an `appointment_id` gebunden | Als Vorbild geeignet, zur Wiederverwendung ungeeignet |
| Check-in-Wege | `auto_checkin`, `totp_checkin`, `device_auth`, Admin, Import | Alle münden in genau einen `records`-INSERT |
| TOTP-Stationen | `handleTotpCheckin` prüft einen 6-stelligen Code gegen **alle** aktiven Stationen (`role='device'`, `device_type='totp_location'`) und liefert bei Treffer deren Namen | Belegt den **Ort**, nicht die Identität — die steht schon durch Session oder Token fest (siehe E9) |
| Frontend-Cache | In-Memory `dataCache` in `public/js/modules/ui.js` (nicht `localStorage`, entgegen `CLAUDE.md`) | Neues Modul hängt sich an dasselbe Muster |
| Schema-Verwaltung | Idempotentes `private/setup/ehrensache_db.sql` **und** Migrationen unter `private/migrations/<quellversion>.php`, ausgeführt vom Update-Wizard `public/update/` | Jede Schemaänderung braucht **beide** Wege (siehe 4.5) |
| Versionierung | `version.json`, Tabelle `schema_version`, `CHANGELOG.md` | Feature muss Version anheben und im Changelog stehen |
| Token-Auth | `api.php` startet seit `3d1a30e` auch bei Bearer-Token eine Session und befüllt sie mit den Token-Daten | `isAdmin()` / `isAdminOrManager()` gelten auch für die PWA; die Rechtematrix in Abschnitt 8 ist ohne Sonderweg umsetzbar |

## 3. Getroffene Entscheidungen

| # | Entscheidung | Begründung |
|---|---|---|
| E1 | Eine gemeinsame Entität `work_sessions` mit **optionalem** `appointment_id` | Vermeidet zwei parallele Zeitwelten, die später zusammengeführt werden müssten |
| E2 | Zeitmodell „eine Zeile je Einsatz" mit `break_minutes` + `break_started_at` | Nachweise verlangen Dauer, nicht die Lage der Pausen. Segmente wären Aufwand ohne Gegenwert |
| E3 | Erfassung per Live-Timer **und** nachträglichem Formular | Timer liefert belastbare Zeitstempel, Formular fängt Vergessenes ab |
| E4 | Timer-Start mit Terminbezug erzeugt den `records`-Eintrag | Ein Vorgang für das Mitglied, zwei Sichten für die Auswertung; verhindert Doppelerfassung |
| E5 | Freigabe nur für `source = 'manual'` und für nachträgliche Änderungen | Hält die Managerlast klein und schafft einen Anreiz, den Timer zu benutzen |
| E6 | Feature hinter `system_settings.worktime_enabled` | EhrenSache ist ein Produkt für viele Vereine; die meisten brauchen keine Stundenerfassung |
| E7 | Alle Zeitstempel aus `NOW()` der Datenbank | Die Geräteuhr ist trivial manipulierbar; Client-Zeiten wären als Nachweis wertlos |
| E8 | Rolle `device` erhält keinen Zugriff | Eine Station kann keine Tätigkeitsart erfragen; Geräte-Timer erzeugten Karteileichen |
| E9 | TOTP am Timer ist ein **Ortsnachweis**, keine Authentifizierung | Die Identität steht durch Session oder Token bereits fest. TOTP belegt „war um T in Reichweite von Station X" — genau die Eigenschaft, die einen Verwendungsnachweis trägt |
| E10 | Der Nachweis wird **protokolliert**, nicht nur erzwungen | Die Tätigkeitsart wählt das Mitglied selbst; eine reine Sperre wäre durch Auswahl einer anderen Art umgehbar *und* unsichtbar. Festgehalten, welche Stunden ortsbelegt sind, wird die Umgehung sichtbar |
| E11 | Nachweispflicht je Tätigkeitsart, dreistufig (`none` / `start` / `start_end`) | Bandprobe im Vereinsheim kann Start und Ende belegen, ein mobiler Einsatz nur den Start. Global wäre entweder zu streng oder wertlos |
| E12 | Die Pause verlangt nie einen Nachweis | Eine Pause ist keine Anwesenheitsbehauptung; ein Scan-Zwang dafür wäre Schikane ohne Erkenntnisgewinn |

### Verworfene Alternativen

- **`records` um Ende und Pause erweitern.** Erfasst keine Arbeit außerhalb von Terminen, und
  `UNIQUE(member_id, appointment_id)` verhindert mehrere Blöcke pro Tag.
- **Sitzung mit Segmenttabelle.** Lückenlos belegbar, aber zweite Tabelle, komplexere
  Auswertungen und eine Segment-UI für Korrekturen — ohne konkreten Prüfer, der die Lage der
  Pausen sehen will, ist das Aufwand ohne Nutzen. Nachrüstbar, ohne Daten zu migrieren.
- **Append-only Ereignis-Log.** Beste Auditspur, aber jede Auswertung müsste Sitzungen zur
  Laufzeit rekonstruieren, und Korrekturen durch Manager wären praktisch unmöglich.
- **Offline-Queue in der PWA.** Erzeugt genau die Client-Zeitstempel, die E7 ausschließt.
  Wer offline war, trägt nach; der Nachtrag geht in die Freigabe.

## 4. Datenmodell

Alle Ergänzungen sind additiv. Von den bestehenden Tabellen wird ausschließlich
`records.checkin_source` um den Wert `timer` erweitert.

### 4.1 `activity_types`

Tätigkeitsarten, strukturell analog zu `appointment_types`. Bewusst **ohne** Gruppenbindung
— die wird erst gebraucht, wenn Tätigkeitsarten je Abteilung unterschiedlich sein sollen.

```sql
CREATE TABLE IF NOT EXISTS `{PREFIX}activity_types` (
  activity_id   INT PRIMARY KEY AUTO_INCREMENT,
  activity_name VARCHAR(100) NOT NULL,
  description   TEXT,
  color         VARCHAR(7) DEFAULT '#1F5FBF',
  is_default    BOOLEAN DEFAULT 0,
  is_active     BOOLEAN DEFAULT 1,
  verification  ENUM('none','start','start_end') NOT NULL DEFAULT 'none',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`verification` steuert den Ortsnachweis am Timer (E9–E12):

| Wert | Bedeutung |
|---|---|
| `none` | Kein Nachweis nötig. Ein trotzdem mitgesendeter Code wird gespeichert |
| `start` | Der Start verlangt einen gültigen TOTP-Code. Belegt den Startzeitpunkt, nicht die Dauer |
| `start_end` | Start **und** Stopp verlangen einen Code. Erst damit sind die Stunden selbst belegt |

Drei Zustände, keine ungültigen Kombinationen — „Ende pflichtig, Start nicht" gibt es nicht.

### 4.2 `work_sessions`

```sql
CREATE TABLE IF NOT EXISTS `{PREFIX}work_sessions` (
  session_id       INT PRIMARY KEY AUTO_INCREMENT,
  member_id        INT NOT NULL,
  activity_id      INT NOT NULL,
  appointment_id   INT DEFAULT NULL,
  start_time       DATETIME NOT NULL,
  end_time         DATETIME DEFAULT NULL,
  break_minutes    INT NOT NULL DEFAULT 0,
  break_started_at DATETIME DEFAULT NULL,
  note                VARCHAR(255) DEFAULT NULL,
  start_location_name VARCHAR(100) DEFAULT NULL,
  end_location_name   VARCHAR(100) DEFAULT NULL,
  status           ENUM('confirmed','submitted','rejected') NOT NULL DEFAULT 'submitted',
  source           ENUM('timer','manual','admin','import') NOT NULL DEFAULT 'manual',
  created_by       INT DEFAULT NULL,
  approved_by      INT DEFAULT NULL,
  approved_at      DATETIME DEFAULT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  active_member    INT AS (IF(end_time IS NULL, member_id, NULL)) VIRTUAL,
  UNIQUE KEY uq_running_session (active_member),
  KEY idx_member_start (member_id, start_time),
  KEY idx_appointment  (appointment_id),
  KEY idx_activity     (activity_id),
  KEY idx_status_start (status, start_time),
  CONSTRAINT `{PREFIX}ws_member_fk`   FOREIGN KEY (member_id)      REFERENCES `{PREFIX}members`(member_id)           ON DELETE CASCADE,
  CONSTRAINT `{PREFIX}ws_activity_fk` FOREIGN KEY (activity_id)    REFERENCES `{PREFIX}activity_types`(activity_id)  ON DELETE RESTRICT,
  CONSTRAINT `{PREFIX}ws_apt_fk`      FOREIGN KEY (appointment_id) REFERENCES `{PREFIX}appointments`(appointment_id) ON DELETE SET NULL,
  CONSTRAINT `{PREFIX}ws_creator_fk`  FOREIGN KEY (created_by)     REFERENCES `{PREFIX}users`(user_id)               ON DELETE SET NULL,
  CONSTRAINT `{PREFIX}ws_approver_fk` FOREIGN KEY (approved_by)    REFERENCES `{PREFIX}users`(user_id)               ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**`status` kennt kein `running`.** „Läuft" ist bereits durch `end_time IS NULL` ausgedrückt.
Beides parallel zu führen erzeugt zwei Wahrheiten, die auseinanderlaufen, sobald ein Codepfad
nur eine davon setzt. `status` beschreibt ausschließlich die Freigabe.

**Zählbar** ist ein Eintrag genau dann, wenn `status = 'confirmed' AND end_time IS NOT NULL`.

**Dauer** in Minuten: `TIMESTAMPDIFF(MINUTE, start_time, end_time) - break_minutes`.

**Ortsnachweis.** `start_location_name` und `end_location_name` halten den Namen der Station
fest, gegen die der TOTP-Code aufgelöst wurde — bewusst denormalisiert und **ohne**
Fremdschlüssel, damit der Nachweis die Löschung einer Station überlebt. Das ist dasselbe
Muster wie `records.location_name`. Ein zusätzliches Flag braucht es nicht: `IS NOT NULL`
heißt „ortsbelegt". Eine Sitzung gilt als **stundenbelegt**, wenn beide Felder gesetzt sind.

**Löschverhalten:** `activity_id` ist `ON DELETE RESTRICT`, weil bestätigte Nachweisstunden
sonst ihre Förderzuordnung verlören; Ausmustern läuft über `is_active = 0`. `created_by` ist
`ON DELETE SET NULL`, damit das Löschen eines Benutzerkontos keine geleisteten Stunden
mitnimmt. Ein gelöschter Termin macht die Stunden zu freien Einsätzen, vernichtet sie nicht.

**Nur eine laufende Sitzung pro Mitglied** lässt sich nicht per gewöhnlichem `UNIQUE`
erzwingen, weil mehrere `NULL` in einem Unique-Index erlaubt sind. Die generierte Spalte
`active_member` löst das. Ob MariaDB einen Unique-Index auf einer virtuellen Spalte in der
Zielversion akzeptiert, ist **vor der Umsetzung zu prüfen** (siehe R1). Rückfallweg:
`SELECT ... FOR UPDATE` auf die laufende Sitzung innerhalb der Start-Transaktion.

### 4.3 `work_session_log`

```sql
CREATE TABLE IF NOT EXISTS `{PREFIX}work_session_log` (
  log_id     INT PRIMARY KEY AUTO_INCREMENT,
  session_id INT NOT NULL,
  changed_by INT DEFAULT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  action     ENUM('create','update','approve','reject','delete') NOT NULL,
  changes    TEXT,
  KEY idx_session (session_id),
  KEY idx_changed (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`changes` enthält JSON der Form `{"feld": {"old": "...", "new": "..."}}`.

Bewusst **kein** Fremdschlüssel auf `session_id`: Eine Auditspur, die beim Löschen des
Datensatzes mitgelöscht wird, dokumentiert die Löschung nicht — und die ist der
interessanteste Vorgang. Der `delete`-Eintrag speichert den letzten Stand in `changes`.

### 4.4 Änderungen am Bestand

```sql
-- records.checkin_source um 'timer' erweitern
SET @has_timer = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = '{PREFIX}records'
      AND COLUMN_NAME  = 'checkin_source'
      AND COLUMN_TYPE LIKE '%timer%');
SET @prep_sql = IF(@has_timer = 0,
    'ALTER TABLE `{PREFIX}records` MODIFY `checkin_source` ENUM(''admin'',''user_totp'',''device_auth'',''auto_checkin'',''import'',''timer'') DEFAULT ''admin''',
    'SELECT 1');
PREPARE stmt FROM @prep_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

Das zweistufige `SET @prep_sql = IF(...)` vor `PREPARE` ist Pflicht, nicht Geschmack:
`PREPARE stmt FROM IF(...)` ist in MySQL kein gültiges Statement. Genau das wurde in
`223eb6a` und `02862be` im gesamten Setup-Skript korrigiert. Ebenso müssen Constraint- und
Indexnamen mit `{PREFIX}` in Backticks stehen.

Neue Einträge in `system_settings`:

| Schlüssel | Typ | Vorgabe | Bedeutung |
|---|---|---|---|
| `worktime_enabled` | boolean | `0` | Schaltet die gesamte Funktion frei |
| `worktime_max_session_hours` | number | `12` | Obergrenze, ab der eine laufende Sitzung automatisch geschlossen wird |
| `worktime_require_note` | boolean | `0` | Erzwingt eine Notiz beim Stoppen und bei manuellen Einträgen |

Die `category`-Spalte von `system_settings` ist ein ENUM
(`general`, `public`, `pagination`, `security`). Die drei Schlüssel werden der Kategorie
`general` zugeordnet; eine neue Kategorie würde eine ENUM-Änderung erfordern, die den Nutzen
nicht rechtfertigt.

### 4.5 Auslieferung: Neuinstallation und Bestand

Das Schema wird auf zwei Wegen ausgerollt, und **beide** müssen bedient werden:

| Weg | Datei | Zweck |
|---|---|---|
| Neuinstallation | `private/setup/ehrensache_db.sql` | Der Installer legt das vollständige Schema an |
| Bestandsinstallation | `private/migrations/1.1.3.php` mit `migrate_1_1_3(PDO $pdo, string $prefix, string $configPath): array` | Der Update-Wizard hebt eine bestehende Datenbank an |

Die Migrationsdatei ist nach der **Quellversion** benannt — `1.0.0.php` migriert von 1.0.0
nach 1.1.x. Die neue Migration heißt daher `1.1.3.php` und führt auf die Zielversion, die
`version.json` dann ausweist.

**Der Update-Wizard muss dafür erweitert werden.** `public/update/index.php` ruft in Schritt 3
fest verdrahtet `migrate_1_0_0()` auf. Für eine zweite Migration braucht es dort eine
Verkettung: aus der in `schema_version` gefundenen Version die Kette der anzuwendenden
Migrationsdateien bestimmen und der Reihe nach ausführen. Das ist Voraussetzung für dieses
Feature, nicht Teil davon — es sollte als eigener, vorgelagerter Schritt umgesetzt werden.

Zusätzlich zu pflegen: `version.json` (Minor-Sprung auf `1.2.0`, weil es eine neue Funktion
ohne Bruch ist), ein Eintrag in `CHANGELOG.md` und die Zielversion in `schema_version`.

## 5. API

Zwei neue Ressourcen im `switch` von `public/api/api.php`:

```php
case 'activity_types':
    handleActivityTypes($db, $database, $request_method, $id, $authUserRole);
    break;
case 'work_sessions':
    handleWorkSessions($db, $database, $request_method, $id,
                       $authUserId, $authUserRole, $authMemberId, $isTokenAuth);
    break;
```

Beide Handler prüfen als Erstes `isWorktimeEnabled()` und antworten sonst mit `404`.

### 5.1 `private/handlers/activity_types.php`

CRUD analog zu `private/handlers/appointment_types.php`. Lesen für alle authentifizierten
Rollen außer `device`, Schreiben nur Admin. `DELETE` schlägt durch den `RESTRICT`-Constraint
fehl, sobald Sitzungen daran hängen; der Handler fängt das ab und antwortet mit `409` samt
Hinweis auf `is_active = 0`.

### 5.2 `private/handlers/work_sessions.php`

Der Timer hat Zustandsübergänge. Statt vier zusätzlicher Ressourcen läuft das über ein
`action`-Feld im POST-Body — ein Endpunkt, ein Zustandsautomat an einer Stelle.

| Request | Wirkung |
|---|---|
| `POST` `{action:'start', activity_id, appointment_id?, totp_code?}` | INSERT mit `start_time = NOW()`, `source='timer'`, `status='confirmed'`, `end_time = NULL`; legt bei gesetztem Termin den `records`-Eintrag mit an. Ein mitgesendeter Code wird aufgelöst und in `start_location_name` festgehalten — auch wenn die Tätigkeitsart ihn nicht verlangt (E10) |
| `POST` `{action:'pause'}` | `break_started_at = NOW()` auf der laufenden Sitzung |
| `POST` `{action:'resume'}` | `break_minutes += TIMESTAMPDIFF(MINUTE, break_started_at, NOW())`, `break_started_at = NULL` |
| `POST` `{action:'stop', note?, totp_code?, force?}` | `end_time = NOW()`; beendet implizit eine laufende Pause. Code wird in `end_location_name` festgehalten |
| `POST` ohne `action` | Manueller Eintrag mit `start_time`, `end_time`, `break_minutes`, `activity_id`, `appointment_id?`, `note?`; `source='manual'`, `status='submitted'` |
| `GET` `?year=&month=&from_date=&to_date=&member_id=&activity_id=&appointment_id=&status=` | Liste; Filternamen bewusst identisch zu `records` |
| `GET` `?running=1` | Die eigene laufende Sitzung (Einstiegs-Call der PWA) |
| `GET /:id` | Einzelsatz |
| `PUT /:id` | Korrektur; ändert ein Mitglied den eigenen Eintrag, wird `status='submitted'` gesetzt (auch wenn er `confirmed` war). Manager- und Admin-Änderungen lassen den Status unverändert |
| `PUT /:id` `{action:'approve'}` | `status='confirmed'`, `approved_by`, `approved_at`; nur Manager/Admin |
| `PUT /:id` `{action:'reject'}` | `status='rejected'`; nur Manager/Admin |
| `DELETE /:id` | Nur Admin; schreibt zuvor `action='delete'` mit letztem Stand ins Log |

Jede mutierende Operation schreibt über `logSessionChange()` ins `work_session_log`.

**Ortsnachweis im Ablauf.** Der Handler liest `verification` der gewählten Tätigkeitsart:

| Situation | Antwort |
|---|---|
| `start` ohne Code bei `verification != 'none'` | `403` mit Hinweis auf die nachträgliche Erfassung. Kein `force`-Weg — ein unbelegter Start bei nachweispflichtiger Tätigkeit soll gar nicht erst als Timer laufen |
| `start` mit ungültigem oder abgelaufenem Code | `401`, analog zu `handleTotpCheckin` |
| `stop` ohne Code bei `verification = 'start_end'` | `409` mit dem Angebot `{action:'stop', force:true}` |
| `stop` mit `force:true` | Sitzung endet, `end_location_name` bleibt `NULL`, `status` fällt auf `submitted` |

Der `force`-Weg ist notwendig, nicht bequem: Ist die Station abgebaut oder endet der Einsatz
woanders, dürfte das Mitglied sonst nicht stoppen und säße bis zum automatischen Abschluss in
einer laufenden Sitzung fest. Die Freigabe fängt den Fall auf — der Mechanismus existiert
bereits.

**Konsistenz mit E4.** Ist der Start ortsbelegt, bekommt der miterzeugte `records`-Eintrag
`checkin_source = 'user_totp'` statt `'timer'`, dazu `location_name` — genau das, was
`handleAutoCheckin` mit `$checkinSource = 'user_totp'` ohnehin schreibt.

**Start als Transaktion.** Der Start schreibt in `work_sessions` und — bei gesetztem
`appointment_id` — in `records`. Existiert bereits ein Check-in für dieses Mitglied und
diesen Termin, bleibt er unangetastet: `INSERT ... ON DUPLICATE KEY UPDATE record_id =
record_id`. Ein Timer-Start um 09:15 darf eine um 09:00 erfasste Ankunft nicht überschreiben.

### 5.3 `private/helpers/worktime.php`

Kapselt, was sonst über Handler, Statistik und Export verstreut würde:

- `isWorktimeEnabled($db, $database)` — liest `system_settings.worktime_enabled`
- `getRunningSession($db, $database, $memberId)`
- `logSessionChange($db, $database, $sessionId, $userId, $action, array $changes)`
- `closeStaleSession($db, $database, $session)` — siehe 6.3
- `sessionDurationMinutes(array $session)` — eine Definition der Dauer für alle Aufrufer

Zusätzlich wandert die Auflösung eines TOTP-Codes gegen alle aktiven Stationen aus
`handleTotpCheckin` nach `private/helpers/totp.php` als
`resolveTotpLocation($db, $database, string $code): ?array`. `totp_checkin.php` und
`work_sessions.php` rufen dieselbe Funktion auf, statt die Schleife zu duplizieren.

## 6. Frontend

### 6.1 PWA (`public/checkin/`)

Die Timer-Oberfläche gehört dorthin, weil die PWA die App ist, die Mitglieder im Einsatz
dabeihaben. Ein Zustand, drei Knöpfe:

1. Tätigkeitsart wählen, optional Termin des Tages → **Start**
2. Laufende Dauer, **Pause** / **Weiter**
3. **Stopp**, optional mit Notiz

Verlangt die gewählte Tätigkeitsart einen Ortsnachweis, öffnet **Start** zuerst den
QR-Scanner. Dessen Bausteine sind vorhanden: `html5-qrcode`, `onQRScanned()` und der
NFC-Weg in `public/checkin/js/app.js` bedienen bereits den TOTP-Check-in — der Timer
verwendet denselben Scanner mit anderem Ziel-Endpunkt. Bei `start_end` fragt auch **Stopp**
danach, mit einem sichtbaren zweiten Weg „Ohne Nachweis beenden" (`force:true`), der die
Sitzung zur Freigabe schickt.

**Pause und Weiter fragen nie nach einem Code** (E12).

Der Zustand wird beim App-Start aus `GET work_sessions?running=1` geholt, nicht aus dem
Browser-Speicher. Damit überlebt er App-Neustart, Gerätewechsel und leeren Akku.

Ohne Netzverbindung sind die Knöpfe inaktiv, mit Hinweis auf den Nachtrag. Siehe E7.

### 6.2 Dashboard (`public/js/modules/worktime.js`)

- Eigene Sitzungen mit Jahres-, Tätigkeits- und Statusfilter
- Formular für manuelle Einträge und Korrekturen
- Für Manager: offene Freigaben, offene Sitzungen, Filter nach Mitglied und Gruppe
- Für Admin: Verwaltung der Tätigkeitsarten (in `management.js` einzuhängen)

Navigation und Modul werden nur geladen, wenn `worktime_enabled` gesetzt ist.

### 6.3 Vergessene Stopps

Eine Sitzung ohne `end_time`, deren `start_time` länger als `worktime_max_session_hours`
zurückliegt, wird beim nächsten API-Zugriff des betroffenen Mitglieds geschlossen:

- `end_time = start_time + worktime_max_session_hours`
- `status = 'submitted'`
- Log-Eintrag `action='update'` mit Vermerk `auto_closed`

Sie zählt damit nicht automatisch, sondern landet beim Mitglied zur Korrektur und beim
Manager zur Freigabe. Kein Cronjob nötig — eine vergessene Sitzung wird erst relevant, wenn
jemand wieder etwas tut. Zusätzlich sieht der Manager alle offenen Sitzungen in seiner
Ansicht, auch die noch nicht abgelaufenen.

### 6.4 Caching

`worktime.js` hängt sich an das bestehende In-Memory-Muster aus `public/js/modules/ui.js`:
`dataCache.workSessions[year]`, geprüft mit `isCacheValid('workSessions', year)`, verworfen
mit `invalidateCache('workSessions', year)` nach jeder Mutation.

Die **laufende** Sitzung wird nie gecacht. Ein zwischengespeicherter Timer-Zustand ist die
naheliegendste Fehlerquelle des Features.

## 7. Auswertung und Export

**Statistik.** `handleStatistics` bleibt in seiner bisherigen Logik unverändert.
Anwesenheitsquote und Pünktlichkeit sind eine andere Frage als geleistete Stunden; sie in
dieselbe Aggregation zu pressen macht beide unklarer. Stattdessen ein Parameter
`?include=worktime`, der je Mitglied `worked_minutes` und eine Aufschlüsselung nach
Tätigkeitsart ergänzt. Gezählt wird ausschließlich
`status = 'confirmed' AND end_time IS NOT NULL`.

**Export** (`private/handlers/export.php`), zwei neue Typen als CSV im vorhandenen Muster:

- `worktime_member` — Stundennachweis je Person und Jahr, aufgeschlüsselt nach Datum und
  Tätigkeitsart, mit Jahressumme. Grundlage für Ehrenamtskarte und Bescheinigung.
- `worktime_activity` — Summen je Tätigkeitsart und Zeitraum. Grundlage für den
  Verwendungsnachweis.

Beide weisen die Stunden **getrennt nach Nachweisgrad** aus: stundenbelegt (Start und Ende
mit Station), teilbelegt (nur Start) und unbelegt. Je Zeile stehen zusätzlich die
Stationsnamen. Das ist der eigentliche Gewinn gegenüber einer reinen Sperre — ein Fördergeber
sieht, welcher Teil der Summe belegt ist, statt eine Zahl ohne Qualitätsangabe zu bekommen.

Kein PDF: Das würde eine Bibliothek einschleppen, die das Projekt bisher bewusst nicht hat.

**Auskunft.** `private/handlers/my_data.php` muss die eigenen Sitzungen mit ausgeben, sonst
ist die DSGVO-Auskunft ab Tag eins unvollständig.

## 8. Rechte

| Rolle | eigene Sitzung | fremde Sitzung | Freigeben | Tätigkeitsarten |
|---|---|---|---|---|
| user | anlegen, lesen, ändern (jede Änderung entzieht die Bestätigung) | — | — | lesen |
| manager | wie user | lesen, ändern | ja | lesen |
| admin | wie manager | zusätzlich löschen | ja | verwalten |
| device | kein Zugriff | kein Zugriff | — | — |

**Änderungen durch das Mitglied entziehen die Bestätigung.** Ändert ein Mitglied den eigenen
Eintrag, wird `status` auf `submitted` gesetzt — unabhängig davon, ob er vorher `confirmed`
war. Der Eintrag zählt bis zur erneuten Freigabe nicht mehr in Statistik und Export.

Eine harte Schreibsperre für bestätigte Einträge wäre die naheliegende Alternative, ist aber
falsch: Wer den Timer 20 Minuten zu spät stoppt, hat einen bestätigten, aber falschen Eintrag
und könnte ihn nicht mehr korrigieren. Der Entzug der Bestätigung erreicht dasselbe Ziel —
eine Änderung bleibt nicht folgenlos — ohne diese Sackgasse.

Änderungen durch Manager und Admin lassen den Status unverändert; sie sind die freigebende
Instanz und müssen sich nicht selbst genehmigen.

**Manager sehen die Sitzungen aller Mitglieder**, nicht nur die ihrer eigenen Gruppen.

Ein früherer Entwurf dieses Abschnitts behauptete das Gegenteil und berief sich auf
`hasStatisticsGroupAccess()` in `private/handlers/statistics.php`. Das war falsch: Diese
Funktion liefert für jeden Admin **und** Manager `true` und begrenzt ausschließlich einfache
Nutzer; `getStatisticsGroups()` liefert Managern ebenso alle Gruppen. Eine Gruppengrenze für
Manager gibt es im Bestand nirgends — weder bei `records` noch bei `exceptions` noch in der
Statistik.

Sie allein für die Zeiterfassung einzuführen wäre inkonsistent und für Betreiber
überraschend. Wer sie will, sollte sie einheitlich für alle Ressourcen einführen — das ist ein
eigenes Vorhaben, kein Nebenprodukt dieses Features.

## 9. Randfälle

| Fall | Verhalten |
|---|---|
| Start, obwohl bereits eine Sitzung läuft | `409` mit Verweis auf die laufende Sitzung |
| `pause` ohne laufende Sitzung | `409` |
| `pause`, obwohl bereits pausiert | Idempotent: keine Änderung, `200` |
| `resume` ohne laufende Pause | Idempotent: keine Änderung, `200` |
| `stop` während einer laufenden Pause | Pause wird zuerst beendet und aufaddiert, dann `end_time` gesetzt |
| Manueller Eintrag mit `end_time <= start_time` | `400` |
| Manueller Eintrag mit `break_minutes >= Bruttodauer` | `400` |
| Manueller Eintrag in der Zukunft | `400` |
| `worktime_require_note = 1` und Notiz fehlt (Stopp oder manueller Eintrag) | `400` |
| `start` ohne Code bei `verification != 'none'` | `403`, Verweis auf nachträgliche Erfassung |
| `start` mit ungültigem oder abgelaufenem Code | `401` |
| `stop` ohne Code bei `verification = 'start_end'` | `409` mit Angebot `force:true` |
| `stop` mit `force:true` | endet unbelegt, `status='submitted'` |
| Keine TOTP-Station konfiguriert, Tätigkeitsart verlangt aber Nachweis | `409` mit Hinweis an den Admin; die Tätigkeitsart ist fehlkonfiguriert |
| Code gültig, aber Station zwischenzeitlich gelöscht | Der Name bleibt in der Sitzung erhalten (denormalisiert) |
| `verification` einer Tätigkeitsart wird nachträglich verschärft | Bestehende Sitzungen bleiben unverändert; die Regel gilt nur für neue Starts |
| Sitzung überschreitet `worktime_max_session_hours` | Automatischer Abschluss nach 6.3 |
| Tätigkeitsart auf `is_active = 0` gesetzt | Bestehende Sitzungen bleiben gültig; Auswahl nur noch für Admin sichtbar |
| Termin wird gelöscht | `appointment_id` wird `NULL`, Stunden bleiben erhalten |
| Mitglied wird gelöscht | Sitzungen werden mitgelöscht (`CASCADE`), Log-Einträge bleiben |

## 10. Umsetzungsreihenfolge

Vier Stufen, jede für sich lauffähig und testbar:

0. **Vorgelagert** — `public/update/index.php` von der fest verdrahteten `migrate_1_0_0()`
   auf eine Migrationskette umstellen (siehe 4.5). Ohne diesen Schritt erreicht das Feature
   keine Bestandsinstallation.
1. **Kern** — Schema (4.1–4.4) in Setup-Skript und Migration `1.1.3.php`, `worktime.php`,
   `activity_types.php`, `work_sessions.php`, Timer in der PWA **ohne** Ortsnachweis
   (`verification = 'none'`). Danach können Mitglieder Stunden erfassen; ausgewertet wird
   noch nichts.
2. **Ortsnachweis** — `resolveTotpLocation()` aus `totp_checkin.php` herauslösen,
   `verification` im Handler auswerten, QR-Scanner am Start und Stopp in der PWA,
   `force`-Weg. Setzt Stufe 1 voraus und ist für sich testbar.
3. **Auswertung** — `worktime.js` im Dashboard inklusive Freigabe-Ansicht, `?include=worktime`
   in der Statistik, die beiden Export-Typen mit Nachweisgrad, Ergänzung von `my_data.php`.

Die Ergänzungen an `DATENSCHUTZ.md` (Abschnitt 12) gehören zu Stufe 3, weil das Feature vor
der Freischaltung in einer echten Installation dokumentiert sein muss.

## 11. Testplan

Verifikation gegen das lokale XAMPP-System mit den Zugängen aus `test_credentials.md`.

**Schema und Auslieferung**
1. `ehrensache_db.sql` zweimal hintereinander einspielen — beide Läufe fehlerfrei
2. Unique-Index auf `active_member` gegen MySQL **und** MariaDB prüfen (R1)
3. Update-Wizard auf einer Datenbank im Stand 1.1.3 — neue Tabellen entstehen,
   `records.checkin_source` kennt `timer`, `schema_version` steht auf 1.2.0
4. Update-Wizard zweimal ausführen — zweiter Lauf ist folgenlos, keine Fehler
5. Update-Wizard auf einer Datenbank im Stand 1.0.0 — Kette 1.0.0 → 1.1.3 → 1.2.0 läuft durch

**Timer**
6. Start → Pause → Weiter → Stopp; Dauer stimmt mit der Wanduhr überein, Pause ist abgezogen
7. Zweiter Start bei laufender Sitzung → `409`
8. Browser während laufender Sitzung schließen, PWA neu öffnen → Timer läuft weiter
9. Browser während laufender **Pause** schließen, neu öffnen → Pause läuft weiter
10. Doppelklick auf Start (zwei parallele Requests) → genau eine Sitzung entsteht

**Terminbezug**
11. Timer-Start mit Termin ohne vorhandenen Check-in → `records`-Eintrag mit `checkin_source = 'timer'`
12. Timer-Start mit Termin **nach** einem Check-in um frühere Uhrzeit → `arrival_time` bleibt unverändert

**Freigabe**
13. Manueller Eintrag → `status = 'submitted'`, zählt nicht in Statistik und Export
14. Manager gibt frei → `status = 'confirmed'`, erscheint in Statistik und Export
15. Mitglied ändert eigenen **bestätigten** Eintrag → fällt auf `submitted` zurück, verschwindet aus Statistik und Export, Log-Eintrag entsteht
16. Manager ändert einen bestätigten Eintrag → bleibt `confirmed`, Log-Eintrag entsteht
17. Manueller Eintrag bei `worktime_require_note = 1` ohne Notiz → `400`

**Ortsnachweis**
18. Tätigkeitsart `verification='start'`: Start ohne Code → `403`; mit gültigem Code → `start_location_name` gesetzt
19. Erzeugter `records`-Eintrag trägt `checkin_source='user_totp'` und `location_name`
20. Tätigkeitsart `verification='none'`, Code trotzdem mitgesendet → Code wird gespeichert, kein Fehler
21. `verification='start_end'`: Stopp ohne Code → `409`; mit `force:true` → beendet, `status='submitted'`, `end_location_name` bleibt `NULL`
22. Abgelaufener Code (älter als zwei Zeitfenster) → `401`
23. `pause` und `resume` verlangen nie einen Code
24. Keine aktive TOTP-Station, Tätigkeitsart verlangt Nachweis → `409`
25. Export weist stundenbelegte, teilbelegte und unbelegte Stunden getrennt aus

**Rechte**
26. `user` ruft fremde Sitzung ab → `403`
27. `manager` ruft die Sitzung eines beliebigen Mitglieds ab → `200`, konsistent mit `records` und `statistics`
28. Gerätetoken (`device`) auf `work_sessions` → `403`
29. `worktime_enabled = 0` → beide Ressourcen antworten `404`, Navigation blendet aus

**Auswertung**
30. `statistics?include=worktime` — Summe stimmt mit der Einzelliste überein
31. `export?type=worktime_member` — CSV enthält nur `confirmed`, Jahressumme stimmt
32. `my_data` enthält die eigenen Sitzungen

Screenshots der Timer-Zustände nach `temporary_screenshots/`.

## 12. Datenschutz und rechtliche Hinweise

Eine Zeiterfassung mit Pausen ist deutlich sensibler als eine Anwesenheitsliste. Vor der
Inbetriebnahme sind zu ergänzen:

- `DATENSCHUTZ.md`: Zweckbindung (Nachweis und Förderverwendung), Rechtsgrundlage,
  Speicherdauer und Löschkonzept für `work_sessions` und `work_session_log`
- Hinweis für Betreiber, dass die Erfassung von Arbeits- und Pausenzeiten im Ehrenamt die
  Abgrenzung zum Beschäftigungsverhältnis berühren kann. Das ist keine Rechtsberatung; der
  Betreiber sollte es prüfen lassen, bevor das Feature scharf geschaltet wird.
- Die Auditspur `work_session_log` überlebt die Löschung einer Sitzung bewusst. Sie enthält
  personenbezogene Daten und braucht daher eine eigene, dokumentierte Löschfrist.

## 13. Nicht im Umfang

Stundenkonto mit Soll/Ist, Wochenübersicht mit Über- und Unterstunden, Erinnerungs-Mails,
Segmente je Sitzung, Gruppenbindung der Tätigkeitsarten, PDF-Export, Timer-Auslösung durch
IoT-Geräte, Offline-Erfassung, Härtung der TOTP-Secret-Ablage (R5).

Jedes davon ist ein plausibles Folgefeature. Keines ist nötig, damit ein Mitglied Stunden
erfasst und ein Verein sie nachweist.

### Vorgemerkt: TOTP-Stationen ohne Klartext-Secret

Nicht Teil dieses Vorhabens und bewusst nicht ausgeplant — hier nur festgehalten, damit die
Idee nicht verloren geht. Beides adressiert R5 und ist erst danach sinnvoll umzusetzen.

**Selbstregistrierung von Stationen.** Statt dass ein Admin das Secret in seinem Browser
erzeugt und angezeigt bekommt (`public/js/modules/devices.js:585`), erzeugt das Gerät es
selbst und meldet sich einmalig über eine Enrollment-Prozedur an. Danach ist das Secret in
der Oberfläche nicht mehr lesbar.

Eine Einschränkung, die man vorab kennen sollte: TOTP ist ein **symmetrisches** Verfahren.
Der Server muss das Secret kennen, um Codes zu prüfen — es kann das Gerät also nicht
dauerhaft nie verlassen. Erreichbar ist: einmalige Übertragung bei der Registrierung,
verschlüsselte Ablage, kein Rücklesen über die API oder die Geräte-Verwaltung. Das schließt
den Weg „Admin liest Secret ab und erzeugt Codes offline", nicht den Weg über einen
Datenbankzugriff. R5 wird dadurch kleiner, nicht null.

**PWA im Stations-Modus.** Ein vorhandenes Endgerät — etwa ein ausgemustertes Tablet im
Vereinsheim — meldet sich über einen eigenen PWA-Einstieg als TOTP-Station an und zeigt den
rotierenden Code als QR an, statt dedizierte Hardware zu erfordern. Passt zur
Selbstregistrierung: das Gerät erzeugt sein Secret bei der Anmeldung und berechnet den Code
danach lokal.

## 14. Offene Risiken

| # | Risiko | Umgang |
|---|---|---|
| R1 | Unique-Index auf der virtuellen Spalte `active_member` | **Teilweise geprüft, MariaDB 10.4.32.** Geprüft am 2026-09-01: Tabelle wird angelegt, eine zweite laufende Sitzung wird abgewiesen, nach dem Beenden ist eine neue möglich, andere Mitglieder unbetroffen. Geprüft am 2026-09-02: ein sauberer Serverneustart lässt die Konstruktion unbeschädigt — auch im Vergleich zu einer Kontrolltabelle ohne virtuelle Spalte. **Nicht geprüft:** Verhalten nach einem harten Abbruch. Genau dort trat am 2026-09-02 einmalig ein Verlust des AUTO_INCREMENT-Zählers auf (Fehler 1467), Ursache unbelegt — siehe `docs/OPEN-ITEMS.md`, OI-1. Der Rückfallweg `SELECT ... FOR UPDATE` bleibt dokumentiert |
| R2 | Serverzeitzone und Mitgliederwahrnehmung können abweichen | Alle Zeiten in der Serverzeitzone speichern und anzeigen, im Nachweis-Export ausweisen |
| R3 | Automatisch geschlossene Sitzungen erzeugen Managerarbeit | Bewusst gewählt: verlorene Stunden wiegen schwerer als eine zusätzliche Freigabe |
| R4 | Mitglieder ohne Smartphone können den Timer nicht nutzen | Manueller Eintrag im Dashboard und Nachtrag durch den Manager decken den Fall ab |
| R5 | Das TOTP-Secret liegt im Klartext in `users.totp_secret` und wird in der Geräte-Verwaltung angezeigt (`public/js/modules/devices.js:347`). Wer Admin- oder Manager-Zugriff hat, kann Codes offline erzeugen und Ortsnachweise fälschen | Wird **nicht** in diesem Feature gelöst. Für die bisherige Anwesenheitserfassung vertretbar, für einen Förder-Verwendungsnachweis eine echte Grenze — sie gehört in die Beschreibung dessen, was „belegt" aussagt |
| R6 | Ein Code gilt rund 90 Sekunden (`verify($code, null, 1)`, ein Zeitfenster Toleranz in beide Richtungen). Lange genug, um ihn per Screenshot an einen Abwesenden weiterzugeben | Toleranz `0` macht es strenger, aber anfällig für Uhrendrift auf dem Mitgliedsgerät. Bewusst unverändert gelassen; als Grenze dokumentiert |
| R7 | Die Nachweispflicht hängt an der Tätigkeitsart, die das Mitglied selbst wählt | Bewusst: die Kopplung ist Prozessführung, keine Sicherheitsmaßnahme (E10). Wer ausweicht, erzeugt unbelegte Stunden — und die sind im Export sichtbar |
