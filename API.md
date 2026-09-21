# EhrenSache API-Dokumentation

>[!NOTE]
> Dokumentation noch in Arbeit

## Übersicht

Die EhrenSache REST API ermöglicht die Verwaltung von Mitgliedern, Terminen, Anwesenheitserfassung und Ausnahmen für ehrenamtliche Organisationen.

**Base URL:** `https://meine-domain.de/api/api.php`

## Authentifizierung

Die API unterstützt zwei Authentifizierungsmethoden:

### Session-basiert (Web-Dashboard)
Nach erfolgreicher Anmeldung wird ein Session-Cookie gesetzt. Alle nachfolgenden Requests nutzen diesen Cookie automatisch.

### Token-basiert (IoT-Geräte, PWA)
Für API-Token wird der Token im Header übergeben:

```http
Authorization: Bearer YOUR_API_TOKEN
```

Alternative Header-Varianten:
```http
X-API-Key: YOUR_API_TOKEN
```

Query-Parameter (nicht empfohlen):
```
?api_token=YOUR_API_TOKEN
```

### Die beiden Methoden schließen einander aus

**Sobald ein Token mitgeschickt wird, wird die Session gar nicht erst gestartet.** `api.php`
prüft `if (!$apiToken) session_start()` — ein vorhandener Token-Header hat also Vorrang, und
zwar bevor irgendjemand nachsieht, ob er gültig ist.

Daraus folgt ein Fallstrick, der leicht zu übersehen ist:

> **Einen leeren `Authorization`-Header niemals mitsenden.**

Ein Header wie `Authorization: Bearer null` oder `Bearer ` ist für den Server ein Token — er
sucht danach, findet nichts und antwortet mit `401 Invalid or inactive API token`. Die
Session wird dabei nie angesehen, obwohl das Cookie gültig ist und mitgeschickt wurde. Der
Aufruf scheitert also **härter** als ohne jeden Header, denn ohne Header hätte die Session
gegriffen.

Für Clients heißt das: Den Header nur setzen, wenn tatsächlich ein Token vorliegt. Wer aus
dem Browser heraus mit Session arbeitet, sendet stattdessen `credentials: 'same-origin'`.

Diese Falle hat die CSV-Exporte des Dashboards vier Monate lang unbrauchbar gemacht, siehe
OI-24 in `docs/OPEN-ITEMS.md`.

### CSRF-Schutz

Bei Session-basierter Authentifizierung ist ein CSRF-Token erforderlich:
- **POST/PUT:** Im Request-Body als `csrf_token`
- **DELETE:** Als Query-Parameter `?csrf_token=XXX`
- **Ausnahmen:** `login`, `logout`, `auth`, `register`, `regenerate_token`

## Rollen & Berechtigungen

| Rolle | Beschreibung |
|-------|--------------|
| `admin` | Volle Systemrechte, Konfiguration, Benutzerverwaltung |
| `manager` | Verwaltung von Mitgliedern, Terminen, Anwesenheit |
| `user` | Eigene Anwesenheit einsehen, Check-in durchführen |

## Rate Limiting

- **100 Requests pro Minute** pro IP/User-Kombination
- Bei Überschreitung: HTTP 429 mit `retry_after` in Sekunden

## HTTP Status Codes

| Code | Bedeutung |
|------|-----------|
| 200 | Erfolg |
| 201 | Ressource erstellt |
| 400 | Ungültige Anfrage |
| 401 | Nicht authentifiziert |
| 403 | Keine Berechtigung / CSRF-Fehler |
| 404 | Ressource nicht gefunden |
| 409 | Konflikt (z.B. Duplikat) |
| 429 | Rate Limit überschritten |
| 500 | Serverfehler |
| 503 | Service nicht verfügbar |

## Zeit & Datum

Einheitliches Datumsformat für Endpoints: YYYY-MM-DD HH:MM:SS

---

## Öffentliche Endpoints

### System Status
Prüft ob das System installiert und betriebsbereit ist.

**Endpoint:** `GET /api.php?resource=ping`

**Authentifizierung:** Keine

**Response:**
```json
{
  "status": "ok",
  "message": "System ready",
  "version": "1.0"
}
```

**Fehler-Status:**
```json
{
  "status": "not_installed",
  "message": "Installation required"
}
```

---

### Appearance
Lädt Branding-Einstellungen (Logo, Farben, Name).

**Endpoint:** `GET /api.php?resource=appearance`

**Authentifizierung:** Keine

**Response:**
```json
{
  "settings": {
    "organization_name": "Mein Verein",
    "primary_color": "#1F5FBF",
    "secondary_color": "#4CAF50",
    "background_color": "#f8f9fa",
    "organization_logo": "uploads/logos/logo.png",
    "privacy_policy_url": "https://meine-domain.de/datenschutzerklaerung/",
    "subgroup_label": "Register"
  },
  "demo": false
}
```

`settings` enthält alle Einträge aus `system_settings` mit `category = 'public'` — welche
Schlüssel das sind, hängt von der Installation ab. Ein Client darf keinen davon voraussetzen.
`subgroup_label` (seit 1.8.0, Vorgabe „Untergruppe") gehört dazu, weil der Umschalter und die
Gruppenverwaltung aller Rollen ihn brauchen — Details bei `settings` unten.

`demo` liegt bewusst **neben** `settings` und nicht darin: `settings` kommt aus der Datenbank,
`demo` aus `config.php`. Der Wert ist nur auf einer Demo-Installation `true` (siehe
`DEMO_MODE` in `private/config/config_example.php`) und steuert das Hinweisband, das alle
Oberflächen daraufhin einblenden.

> Bis 1.4.0 zeigte dieser Abschnitt ein flaches Objekt mit den Schlüsseln `org_name` und
> `logo_url`. Das war nie die ausgelieferte Form — korrigiert am 2026-09-10.

---

### Login (Web)
Session-basierte Anmeldung für das Web-Dashboard.

**Endpoint:** `POST /api.php?resource=login`

**Authentifizierung:** Keine

**Request:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "success": true,
  "user_id": 1,
  "role": "admin",
  "email": "user@example.com",
  "csrf_token": "abc123..."
}
```

**Fehler:**
```json
{
  "success": false,
  "message": "Invalid credentials"
}
```

---

### Login (Token)
Token-basierte Anmeldung für PWA/IoT-Geräte.

**Endpoint:** `POST /api.php?resource=auth`

**Authentifizierung:** Keine

**Request:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "success": true,
  "token": "abc123...",
  "user_id": 1,
  "role": "user",
  "member_id": 5
}
```

---

### Logout
Beendet Session.

**Endpoint:** `POST /api.php?resource=logout`

**Authentifizierung:** Session

**Response:**
```json
{
  "success": true,
  "message": "Logged out"
}
```

---

### Registrierung
Erstellt neuen Benutzer-Account (falls aktiviert).

**Endpoint:** `POST /api.php?resource=register`

**Authentifizierung:** Keine

**Request:**
```json
{
  "email": "new@example.com",
  "password": "SecurePass123!"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Registration successful",
  "user_id": 42
}
```

---

### Passwort-Reset Anfrage
Sendet Reset-Link per E-Mail.

**Endpoint:** `POST /api.php?resource=password_reset_request`

**Request:**
```json
{
  "email": "user@example.com"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Reset link sent"
}
```

---

## Geschützte Endpoints

### Benutzer-Info
Gibt Informationen über den aktuell angemeldeten Benutzer zurück.

**Endpoint:** `GET /api.php?resource=me`

**Authentifizierung:** Session oder Token

**Response:**
```json
{
  "user_id": 1,
  "email": "admin@example.com",
  "role": "admin",
  "member_id": 5,
  "auth_type": "session"
}
```

---

### Session-Status
Restlaufzeit der aktuellen Web-Session. Gedacht als Grundlage für eine Ablaufwarnung in der
Oberfläche.

**Endpoint:** `GET /api.php?resource=session_info`

**Authentifizierung:** Nur Session. Aufrufe mit Bearer-Token erreichen den Endpoint nicht —
für Token-Aufrufe wird gar keine Session gestartet.

**Response:**
```json
{
  "success": true,
  "data": {
    "role": "admin",
    "last_activity": 1772787600,
    "time_since_activity": 42,
    "remaining_seconds": 1758
  }
}
```

`remaining_seconds` rechnet gegen `SESSION_TIMEOUT_SECONDS` aus `public/api/api.php` — also
gegen dieselbe Grenze, die der Server beim nächsten Aufruf durchsetzt.

**Bewusst nicht enthalten:** `session_id` und der Inhalt von `$_SESSION` (und damit der
CSRF-Token). Das Session-Cookie ist `HttpOnly`; die ID hier auszugeben, würde sie genau dem
JavaScript zurückgeben, dem `HttpOnly` sie entzieht. Siehe OI-18 in `docs/OPEN-ITEMS.md` —
diese Felder sind nicht wieder aufzunehmen.

**Fehler:**

| Status | Bedingung | Antwort |
|---|---|---|
| 401 | keine angemeldete Session | `{"message":"Unauthorized"}` |
| 403 | mutierendes Verfahren ohne gültigen CSRF-Token | `{"message":"Invalid CSRF token"}` |

Beide Fälle fängt die zentrale Prüfung in `public/api/api.php` ab, bevor der Handler läuft.
Die Prüfungen in `getSessionStatus()` selbst sind zweite Verteidigungslinie und im
Normalbetrieb nicht erreichbar.

---

### Version
Versionsstand der Installation. Das Dashboard zeigt daraus die Versionsnummer an.

**Endpoint:** `GET /api.php?resource=version`

**Authentifizierung:** Session oder Token, jede Rolle

**Response:**
```json
{
  "version": "1.7.0",
  "build": "2026-09-16",
  "commit": "auto",
  "name": "EhrenSache",
  "requires": {
    "php": "8.0.0",
    "extensions": ["pdo", "pdo_mysql", "json"]
  },
  "server_time": "2026-09-14 13:05:00",
  "php_version": "8.2.12",
  "config_format": "array",
  "update_available": null
}
```

`config_format` wird **nur an die Rolle `admin`** ausgeliefert und nennt die Form von
`private/config/config.php`:

| Wert | Bedeutung |
|---|---|
| `array` | reine Datendatei, Stand ab 1.6.0 |
| `legacy` | alte Klassenform — die Umstellung beim Update ist nicht gelungen; das Dashboard zeigt einen Hinweis |
| `missing` | keine Datei gefunden |
| `unknown` | Datei vorhanden, aber keine der beiden Formen |

`update_available` wird ebenfalls **nur an `admin`** ausgeliefert: die Versionsnummer aus der
zuletzt gespeicherten Update-Prüfung, sofern sie höher ist als die installierte, sonst `null`.

---

### Update-Prüfung
Fragt auf Knopfdruck nach einer neueren Version. Es gibt **keinen** automatischen Abruf.

**Endpoint:** `GET|POST /api.php?resource=update_check`

**Authentifizierung:** Session oder Token, nur `admin`

- `GET` liefert den gespeicherten Stand, ohne nach außen zu gehen.
- `POST` fragt `api.github.com` nach dem neuesten Release und speichert das Ergebnis in
  `system_settings` (`update_check_last`, `update_check_result`). Dabei erfährt GitHub die
  IP-Adresse des Servers.

**Response (beide Methoden):**
```json
{
  "installed": "1.6.0",
  "last_checked": "2026-09-14 13:05:00",
  "latest": {
    "version": "1.7.0",
    "tag": "v1.7.0",
    "published_at": "2026-10-01T08:00:00Z",
    "html_url": "https://github.com/mcmaier/EhrenSache/releases/tag/v1.7.0",
    "zipball_url": "https://api.github.com/repos/mcmaier/EhrenSache/zipball/v1.7.0",
    "notes": "…"
  },
  "update_available": "1.7.0"
}
```

`latest` ist `null`, solange nie geprüft wurde. `notes` stammt von GitHub und ist als Text zu
behandeln, nie als HTML.

**Fehler:**

| Status | Bedingung |
|---|---|
| 403 | nicht `admin`; im Demo-Modus `POST` |
| 405 | andere Methode als `GET` oder `POST` |
| 502 | GitHub nicht erreichbar, Anfragen begrenzt oder Antwort unbrauchbar — `message` nennt den Grund |

---

## Mitglieder (members)

### Alle Mitglieder abrufen
**Endpoint:** `GET /api.php?resource=members`

**Query-Parameter:**
- `id` (optional): Einzelnes Mitglied abrufen
- `group_id` (optional): Mitglieder einer Gruppe abrufen
- `year`, `date` (optional): Aktivitätsfilter über die Mitgliedschaftszeiträume
- `include_inactive` (optional): `true` nimmt inaktive Mitglieder mit auf

**Response:** ein **Array**, kein Objekt — es gibt weder einen `members`-Umschlag noch eine
Paginierung. Die Liste kommt vollständig; die Einstellung „Datenreihen pro Seite“ wirkt allein
im Browser (`globalPaginationValue` in `settings.js`). Serverseitig paginiert einzig
`import_logs`.

```json
[
  {
    "member_id": 1,
    "name": "Max",
    "surname": "Mustermann",
    "member_number": "M001",
    "pin_updated_at": null,
    "active": 1,
    "created_at": "2026-09-10 11:41:55",
    "group_ids": "2, 5720",
    "group_names": "Jugend, Klarinetten",
    "is_active_in_period": 1,
    "has_pin": false
  }
]
```

`group_ids` und `group_names` sind **Zeichenketten** mit `, ` als Trenner, keine Arrays.
`is_active_in_period` bezieht sich auf den über `year` oder `date` gewählten Zeitraum.
Mitgliedschaftszeiträume liefert die Liste nicht — dafür gibt es die eigene Ressource
`membership_dates`.

**Einzelnes Mitglied:**
```
GET /api.php?resource=members&id=1
```

Die Einzelantwort ist ein Objekt und trägt statt der beiden Zeichenketten ein `groups`-Array:

```json
{
  "member_id": 1,
  "name": "Max",
  "surname": "Mustermann",
  "member_number": "M001",
  "pin_updated_at": null,
  "active": 1,
  "created_at": "2026-09-10 11:41:55",
  "groups": [
    { "group_id": 2, "group_name": "Jugend" },
    { "group_id": 5720, "group_name": "Klarinetten" }
  ],
  "has_pin": false
}
```

Ein `user` ohne Admin- oder Managerrolle bekommt auf denselben Endpunkt nur die eigenen
Stammdaten (`name`, `surname`, `member_number`, `group_ids`) und bei fremder `id` zusätzlich ein
`warning`; die fremde `id` wird ignoriert, nicht abgewiesen.

**Stations-PIN (seit 1.3.0):** Jede Admin/Manager-Antwort (einzeln und Liste) trägt `has_pin`
(Boolean) und `pin_updated_at`; der Hash selbst (`pin_hash`) verlässt den Server nie.
`member_number` wird beim Speichern getrimmt (seit 1.3.0); ein danach leerer Wert wird als `null` gespeichert.

---

### Mitglied erstellen
**Endpoint:** `POST /api.php?resource=members`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "name": "Max",
  "surname": "Mustermann",
  "member_number": "M001",
  "active": 1,
  "group_ids": [1, 3],
  "membership_dates": [
    {
      "start_date": "2024-01-01",
      "end_date": null,
      "status": "active"
    }
  ]
}
```

Ein mitgeschicktes `pin` wird beim Anlegen ignoriert — die Stations-PIN wird erst über ein
anschließendes `PUT` gesetzt (so verfährt auch das Dashboard).

**Response:**
```json
{
  "message": "Member created",
  "id": 42
}
```

---

### Mitglied aktualisieren
**Endpoint:** `PUT /api.php?resource=members&id=1`

**Berechtigung:** Admin/Manager

**Request:** Wie bei POST, zusätzlich optional `pin` für die Stations-PIN (seit 1.3.0):

| Wert | Wirkung |
|---|---|
| Ziffernfolge (String) | setzt die PIN — 4 bis 8 Ziffern, Mindestlänge aus `station_pin_min_length`, nicht eine einzige wiederholte Ziffer, keine auf- oder absteigende Zahlenfolge |
| `null` oder `""` | löscht die PIN |

`409 "Station PIN login is disabled"` (`field: "pin"`) wenn `station_pin_enabled = 0` — die
Mitgliedsaktualisierung selbst bleibt eine gültige Ressource, daher `409` statt `404` (anders
als bei `change_pin`, siehe unten). `400` mit Fehlertext und `field: "pin"` bei einem
Regelverstoß. Setzen oder Löschen der PIN hebt eine bestehende Sperre des Mitglieds
(`station: identify`) auf.

**Response:**
```json
{
  "message": "Member updated"
}
```

---

### Mitglied löschen
**Endpoint:** `DELETE /api.php?resource=members&id=1`

**Berechtigung:** Admin/Manager

**Response:**
```json
{
  "message": "Member deleted"
}
```

---

## Termine (appointments)

### Alle Termine abrufen
**Endpoint:** `GET /api.php?resource=appointments`

**Query-Parameter:**
- `id`: Einzelner Termin
- `year`: Filter nach Jahr
- `month`: Filter nach Monat (Auch in Kombination mit Jahr)
- `from_date`: Filter nach Termine ab Zeitpunkt
- `to_date`: Filter nach Termine bis Zeitpunkt
- `member_id`: Nur Termine, deren Terminart einer Gruppe dieses Mitglieds zugeordnet ist.
  **Wirkt nur für Admin und Manager.** Für alle anderen Rollen wird der Parameter ignoriert;
  sie sehen immer die Termine der Gruppen ihres eigenen Mitglieds (bis 1.9.2 ließ sich die
  Gruppengrenze damit verschieben)
- `locations=1`: Statt der Terminliste ein Array bisher verwendeter Orte (letzte zwei Jahre,
  häufigste zuerst, höchstens 50) — für die Vorschlagsliste des Ortsfelds. **Nur Admin und
  Manager**, sonst `403`. Seit 1.10.0:

  ```json
  ["Probelokal", "Stadthalle Musterhausen", "Vereinsheim"]
  ```

**Response:**
```json
[
  {
    "appointment_id": 1,
    "title": "Probe",
    "description": null,
    "location": "Proberaum",
    "date": "2024-03-15",
    "start_time": "19:00:00",
    "end_time": "21:00:00",
    "created_by": 1,
    "created_at": "2024-03-01 10:12:00",
    "is_auto_created": 0,
    "series_id": null,
    "is_detached": 0,
    "type_id": 1,
    "type_name": "Probe",
    "color": "#667eea",
    "type_description": null,
    "responses_enabled": 0
  }
]
```

**Felder `location` und `end_time` (seit 1.10.0):** Ort und Ende des Termins, beide optional und
`null`, wenn nicht gesetzt. Rein informativ — sie gehen in keine Auswertung ein. Liegt
`end_time` vor `start_time`, meint es den Folgetag. Bis 1.9.3 stand beides in diesem Beispiel,
ohne dass es die Felder gab.

**Felder `series_id` und `is_detached` (seit 1.11.0, FI-7):** `series_id` verweist auf die Serie
(Ressource [`appointment_series`](#terminserien-appointment_series)), `null` bei einem
gewöhnlichen Einzeltermin. `is_detached` (0/1) heißt: sichtbar Teil der Serie, aber von
Serienaktionen ausgenommen — gesetzt durch Bearbeiten „nur dieser" oder automatisch, wenn eine
Serienaktion einen Termin mit erfassten Daten stehen lassen muss. **`PUT` auf einen Serientermin
setzt `is_detached = 1`** (bei der ersten tatsächlichen Änderung; ein `PUT` ohne Felder ändert
nichts). **`DELETE` eines Serientermins trägt sein Datum in `exdates` der Serie ein**, damit
„Serie fortsetzen" es nicht wieder erzeugt; die Serienzeile selbst bleibt dabei stehen, auch wenn
danach kein Termin mehr auf sie zeigt — das erledigt nur `DELETE appointment_series`.

Die Gruppen eines Termins ergeben sich aus seiner Terminart (`appointment_types`), nicht aus
dem Termin selbst.

**Feld `responses` (seit 1.7.0):** Nur wenn die Anfrage `year`, `from_date` oder `to_date`
mitschickt — ohne einen dieser Filter fehlt der Schlüssel ganz, damit die je Termin korrelierten
Abfragen nicht bei jedem ungefilterten Abruf der gesamten Historie laufen (so ruft die
Check-in-PWA die Liste mit `member_id` allein ab; sie holt Rückmeldungen stattdessen über
`resource=appointment_responses&upcoming=1`). Bei Terminarten mit Rückmeldung
`{"yes": 12, "no": 3, "maybe": 2, "open": 8, "own": "yes", "expected": true}` — `open` zählt
erwartete Mitglieder ohne Antwort, `own` ist die Antwort des Mitglieds, das mit dem angemeldeten
Konto verknüpft ist (sonst `null`) — **unabhängig von einer `member_id`-Filterung**, immer das
eigene Mitglied des Kontos, auch für Admin und Manager; die Zusage kann dadurch eine Antwort
zeigen, die inzwischen gar nicht mehr zählt (Mitglied nicht mehr erwartet). `expected` (Boolean)
sagt, ob dieses Mitglied für den Termin erwartet ist. Bei Terminarten ohne Rückmeldung `null`.

---

### Termin erstellen
**Endpoint:** `POST /api.php?resource=appointments`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "title": "Konzert",
  "description": "Treffpunkt 18:00 am Bühneneingang",
  "date": "2024-06-15",
  "start_time": "19:00",
  "end_time": "21:30",
  "location": "Stadthalle",
  "type_id": 1
}
```

Pflicht sind `title`, `date` und `start_time`. Fehlt `type_id`, gilt die Standard-Terminart.

**`end_time` und `location` (seit 1.10.0), beide optional:**

| Feld | Regel | Fehler |
|---|---|---|
| `end_time` | `HH:MM` oder `HH:MM:SS`; leer oder `null` = kein Ende; vor dem Beginn = Folgetag | `400` bei ungültigem Format oder wenn es **gleich** dem Beginn ist |
| `location` | Text, wird getrimmt; leer oder `null` = kein Ort | `400` bei mehr als 200 Zeichen |

**Response:**
```json
{
  "message": "Appointment created",
  "id": 42
}
```

Existiert im Toleranzfenster bereits ein Termin derselben Terminart, antwortet der Endpunkt mit
`409` und nennt ihn.

---

### Termin aktualisieren
**Endpoint:** `PUT /api.php?resource=appointments&id=1`

**Berechtigung:** Admin/Manager

**Felder:** `title`, `type_id`, `description`, `date`, `start_time`, `end_time`, `location`
(die beiden letzten seit 1.10.0, Regeln wie bei „Termin erstellen")

Ein Ende gleich dem Beginn wird auch dann mit `400` abgelehnt, wenn nur eines der beiden Felder
im Request steht: Ändert der Request nur `start_time` auf das gespeicherte Ende, gilt dieselbe
Regel wie umgekehrt.

**`PUT` ist eine Teiländerung, keine Vollersetzung.** Geschrieben wird nur, was im Request steht;
alle übrigen Felder bleiben unberührt. Ein ausdrückliches `null` ist dagegen eine Angabe und
löscht den Wert. Ein `PUT` auf eine unbekannte `id` ergibt `404 {"message": "Appointment not
found"}`.

Liegt nach der Änderung ein anderer Termin **derselben Terminart** im Toleranzfenster
(`checkin_tolerance_hours`), antwortet der Endpunkt mit `409` und nennt den bestehenden Termin.
Geprüft wird der Zustand nach dem Update: Fehlen `date`, `start_time` oder `type_id` im Request,
gelten die gespeicherten Werte.

**Bis einschließlich 1.9.0 war dies eine Vollersetzung.** Ein `PUT` ohne `title` und `type_id`
nullte beide, `date` und `start_time` wurden zu `0000-00-00`; die Dublettenprüfung fiel bei einem
Teil-Update still aus (OI-69). Wer vor 1.9.1 alle fünf Felder mitschickte, ist davon nicht
betroffen — die mitgelieferte Oberfläche tut das.

---

### Termin löschen
**Endpoint:** `DELETE /api.php?resource=appointments&id=1`

**Berechtigung:** Admin/Manager

**Seit 1.8.0:** Trifft `id` keinen Datensatz (bereits gelöscht, erfundene ID), antwortet der
Endpunkt mit `404` statt wie zuvor mit `200`.

**Seit 1.9.1:** Fehlt `id` ganz, antwortet der Endpunkt mit `400 {"message": "id ist
erforderlich"}` statt mit einer Erfolgsmeldung (OI-56).

---

## Terminserien (appointment_series)

**Seit 1.11.0 (FI-7).** Eine Serie ist eine Regel (Teilmenge von RFC 5545) plus Vorlage; die
Termine selbst sind gewöhnliche `appointments` mit `series_id` (siehe oben). Termine mit
erfassten Daten löscht keine Serienaktion — sie löst sie ab (`is_detached = 1`) und meldet sie,
statt sie zu verwerfen.

**Berechtigung:** Admin/Manager für **jeden** Aufruf dieser Ressource. Ein einfacher Nutzer oder
ein Gerät bekommt `403`.

**Vorschau statt Materialisierung:** `POST` (Anlegen), `POST …&action=split` und
`POST …&action=extend` akzeptieren zusätzlich `?preview=…` — derselbe Code rechnet den Plan,
schreibt aber nichts. **Jeder gesetzte Wert außer `'0'`** (`preview=1`, `preview=true`,
`preview=yes` …) gilt als Vorschau; nur ein fehlender Parameter oder `preview=0` schreibt
tatsächlich.

**„Seriendefinition"** — gemeinsamer Anfragekörper für Anlegen und `action=split`:
```json
{
  "rrule": "FREQ=WEEKLY;INTERVAL=1;BYDAY=TU",
  "start_date": "2026-10-06", "until": "2027-07-27",
  "exdates": ["2026-12-29"],
  "title": "Gesamtprobe", "type_id": 3, "description": null,
  "start_time": "19:30", "end_time": "22:00", "location": "Probelokal"
}
```
Pflicht sind `rrule`, `start_date`, `until`, `title` und `start_time`; `type_id`, `description`,
`end_time`, `exdates` sind optional. Fehlt `type_id`, gilt die Standard-Terminart wie bei
`POST appointments`. `end_time` und `location` folgen denselben Regeln wie bei `appointments`
(seit 1.10.0).

### Serie abrufen
**Endpoint:** `GET /api.php?resource=appointment_series&id=1`

```json
{
  "series_id": 1, "rrule": "FREQ=WEEKLY;INTERVAL=1;BYDAY=TU",
  "start_date": "2026-10-06", "until": "2027-07-27", "exdates": ["2026-12-29"],
  "title": "Gesamtprobe", "type_id": 3, "description": null,
  "start_time": "19:30:00", "end_time": "22:00:00", "location": "Probelokal",
  "created_by": 1, "created_at": "2026-09-18 10:00:00",
  "appointment_count": 39, "detached_count": 2
}
```
`appointment_count` und `detached_count` zählen die zugehörigen `appointments` (letztere davon
mit `is_detached = 1`). Unbekannte `id` → `404`.

### Serie anlegen (und Vorschau)
**Endpoint:** `POST /api.php?resource=appointment_series` (Vorschau: `?preview=1`)

**Request:** die Seriendefinition (siehe oben), ohne `id`.

**Antwort der Vorschau (`200`):**
```json
{
  "occurrences": [
    { "date": "2026-10-06", "holiday": null, "conflict": null, "excluded": false, "locked": false },
    { "date": "2026-10-13", "holiday": "Tag der Deutschen Einheit", "conflict": null, "excluded": true, "locked": false },
    { "date": "2026-10-20", "holiday": null,
      "conflict": { "appointment_id": 88, "title": "Jugendprobe", "start_time": "19:45:00" },
      "excluded": false, "locked": false }
  ],
  "count": 35
}
```
`occurrences` enthält **alle** Daten der Regel im Zeitraum — **ohne** die gesendeten `exdates`
angewandt, damit die Oberfläche sie als bereits abgewählt anzeigen kann (`excluded: true`).
Ebenfalls `excluded: true`, aber zusätzlich `locked: true`: Ausfälle, die der Server unabhängig
von der Auswahl der Oberfläche immer anwendet (in der Praxis nur bei `action=split`, siehe dort —
die aus dem Bestand übernommenen `exdates`) — nicht abwählbar. `conflict` zeigt einen
kollidierenden Bestandstermin (Toleranzfenster,
`checkin_tolerance_hours`); ein solcher Tag zählt nicht in `count`. `count` ist die Zahl der
Termine, die ein tatsächliches Schreiben anlegen würde (`excluded: false` **und**
`conflict: null`). Ergibt die Regel im Zeitraum **keinen einzigen** Termin (auch keinen
ausgeschlossenen), antwortet der Endpunkt mit `400 {"message": "Die Regel ergibt in diesem
Zeitraum keinen Termin"}` — auch in der Vorschau.

**Schreiben (`201`):** legt die Serienzeile und je nicht abgewähltem Datum einen Termin an, in
einer Transaktion. Kollisionen werden dabei **erneut** geprüft (die Vorschau ist nur ein
Vorschlag) und ausgelassen; ausgelassene Daten — abgewählte **und** neu kollidierende — landen in
`exdates` der gespeicherten Serie.
```json
{
  "series_id": 12, "created": 34,
  "skipped": [
    { "date": "2026-10-20", "reason": "conflict",
      "conflict": { "appointment_id": 88, "title": "Jugendprobe", "start_time": "19:45:00" } }
  ]
}
```
Kollidieren oder entfallen **alle** Termine der Regel, wird **nichts** geschrieben (Rollback):
`409 {"message": "Alle Termine der Serie kollidieren mit bestehenden Terminen oder sind
abgewählt", "skipped": [...]}`.

### Serie ändern: „Dieser und alle folgenden" ohne Regeländerung
**Endpoint:** `PUT /api.php?resource=appointment_series&id=1`

**Request:** `from_date` (Pflicht) plus eine beliebige Teilmenge der Vorlagenfelder (`title`,
`type_id`, `description`, `start_time`, `end_time`, `location`) — wie bei `PUT appointments` ist
das eine **Teiländerung**: nur gesendete Felder wirken.
```json
{ "from_date": "2027-01-05", "start_time": "20:00" }
```

Aktualisiert werden die Vorlage der Serie **und** alle ihre Termine mit `date >= from_date` und
`is_detached = 0`, **an Ort und Stelle** (IDs bleiben, damit Anwesenheit, Rückmeldungen und
Arbeitszeit hängen bleiben). Geprüft wird **je Termin mit seinen wirksamen Werten**: das
gesendete (normalisierte) Feld, sonst der bisherige Stand dieses einzelnen Termins — ein
früherer Teil-`PUT` mit einem anderen `from_date` kann Termine bereits von der aktuellen Vorlage
abweichen lassen.

Ein Termin bleibt unverändert, wird abgelöst (`is_detached = 1`) und im Ergebnis gemeldet, wenn:
- die Änderung sein Ende dem Beginn gleichmachen würde (`reason: invalid_time`),
- **nur** bei einer Änderung von `start_time` oder `type_id`: die neuen Werte mit einem
  bestehenden Termin derselben Terminart im Toleranzfenster kollidieren würden
  (`reason: conflict`, mit `conflict {appointment_id, title, start_time}`), oder
- **nur** bei einer Änderung von `start_time` oder `type_id`: für den Termin bereits
  **Anwesenheit erfasst** ist (`reason: has_data` — geprüft wird ausschließlich `records`, **nicht**
  Rückmeldungen, Ausnahmen oder Arbeitszeit; diese ziehen mit der Serie weiter, damit sich ein
  bereits zugesagter künftiger Termin noch verschieben lässt).

Titel, Beschreibung, Ort und Ende ändern sich bei erfasster Anwesenheit normal weiter — nur
Beginn und Terminart schützen eine erfasste Anwesenheit (Pünktlichkeit) vor dem Mitziehen.

**Antwort (`200`):**
```json
{
  "updated": 27,
  "detached": [
    { "appointment_id": 145, "date": "2027-01-12", "reason": "has_data" },
    { "appointment_id": 146, "date": "2027-01-19", "reason": "conflict",
      "conflict": { "appointment_id": 90, "title": "Jugendprobe", "start_time": "20:00:00" } }
  ]
}
```
`updated` zählt **jeden tatsächlich geschriebenen** Termin — auch wenn sich kein Feld dadurch
inhaltlich ändert (der Request schickt z. B. denselben Wert erneut). Die **Vorlage der Serie
wird geschrieben, sobald mindestens ein Feld im Request steht** — unabhängig davon, wie viele
(oder ob überhaupt) folgende Termine tatsächlich aktualisiert werden; `updated: 0` ist also
möglich, während die Serienvorlage bereits den neuen Wert trägt (z. B. wenn ab `from_date` alle
Termine abgelöst sind oder es dort keine mehr gibt). **Fehlt jedes Vorlagenfeld im Request**,
ändert sich nichts — auch die Serienvorlage nicht —, und die Antwort ist sofort
`{"updated": 0, "detached": []}`.

### Regel ab einem Termin ändern: „Dieser und alle folgenden" mit Regeländerung (Split)
**Endpoint:** `POST /api.php?resource=appointment_series&id=1&action=split`
(Vorschau: zusätzlich `?preview=1`)

**Request:** `from_date` (Pflicht) plus eine vollständige Seriendefinition (`rrule` bis
`location`, ohne `start_date` — `from_date` wird dafür verwendet).

Beendet die **alte** Serie am Vortag von `from_date` (wie `DELETE …&from=` unten) und legt eine
**neue** Serie ab `from_date` nach der neuen Regel an — beides in **einer** Transaktion. Die
`exdates` der alten Serie ab `from_date` gehen automatisch in die neue Serie über (ein einzeln
gelöschter Termin kommt so nicht zurück); in der Vorschau erscheinen sie als
`excluded: true, locked: true` und lassen sich nicht abwählen.

**Vorschau (`200`)** ergänzt `occurrences` und `count` um:
- `removes`: Zahl der folgenden Termine ohne Daten, die das Beenden der alten Serie löschen würde,
- `keeps`: Zahl der folgenden Termine **mit** Daten, die abgelöst stehen bleiben statt ersetzt zu
  werden.

**Schreiben (`201`):**
```json
{
  "series_id": 13, "created": 20, "skipped": [],
  "removed": 5,
  "detached": [ { "appointment_id": 150, "date": "2027-01-12", "reason": "has_data" } ],
  "series_deleted": false
}
```
`removed`, `detached` und `series_deleted` beschreiben das Beenden der **alten** Serie (siehe
`DELETE …&from=`) — `series_deleted: true` heißt, ihre Zeile wurde gelöscht, weil kein Termin
mehr auf sie zeigt (oder weil `from_date` der Serienbeginn war und die alte Serie damit ganz
endet).

Kollidieren oder entfallen **alle** Termine der **neuen** Regel, wird die gesamte Aktion
zurückgerollt — **auch das Beenden der alten Serie unterbleibt**: `409 {"message": "Alle Termine
der neuen Regel kollidieren oder sind abgewählt", "skipped": [...]}`. Ist `from_date` inzwischen
nicht mehr innerhalb der (zwischenzeitlich geänderten) Serie, antwortet der Endpunkt mit
`409 {"message": "Die Serie wurde inzwischen geändert"}` (Wettlauf zweier Aufrufe, z. B. ein
Doppelklick).

### Serie fortsetzen
**Endpoint:** `POST /api.php?resource=appointment_series&id=1&action=extend`
(Vorschau: zusätzlich `?preview=1`)

**Request:** `until` (Pflicht, neues Ende) sowie optional `exdates` (zusätzliche Ausfälle, werden
mit den bestehenden der Serie zusammengeführt).
```json
{ "until": "2028-01-31", "exdates": ["2027-08-03"] }
```
Das neue `until` muss **nach** dem bisherigen liegen und darf höchstens 12 Monate über das
bisherige hinausgehen, sonst `400`. Erzeugt werden nur Termine im Bereich `(altes until, neues
until]`, nach der gespeicherten Regel und Vorlage — der Wochentakt (alle `n` Wochen) bleibt am
ursprünglichen Serienbeginn verankert, nicht am neuen Bereich.

**Antwort (`200`, kein `201` — dieselbe Serie):**
```json
{ "created": 22, "skipped": [] }
```
Hat sich `until` der Serie seit der Vorschau bereits geändert (Wettlauf), antwortet der Endpunkt
mit `409 {"message": "Die Serie wurde inzwischen geändert"}`.

### Serie ab einem Termin beenden
**Endpoint:** `DELETE /api.php?resource=appointment_series&id=1&from=2027-01-05`

Setzt `until` der Serie auf den Vortag von `from`. Ihre nicht abgelösten Termine ab `from`:
ohne erfasste Daten werden **gelöscht** (wie ein einzelnes `DELETE appointments`), mit Daten
werden sie **abgelöst** (`is_detached = 1`) und gemeldet — geprüft wird hier die **breite**
Definition „Termin mit Daten" (`records`, `appointment_responses`, `exceptions`,
`work_sessions`), nicht nur Anwesenheit.

Ist `from` der Serienbeginn (oder liegt davor), endet die Serie **ganz**: Die Serienzeile wird in
jedem Fall gelöscht, und verbleibende (abgelöste) Termine werden zu gewöhnlichen Einzelterminen
(`series_id = NULL`, `is_detached = 0`) — sonst bliebe ein verwaister Ablöse-Merker stehen. Liegt
`from` **nach** dem Serienbeginn, wird die Serienzeile nur gelöscht, wenn danach kein Termin mehr
auf sie zeigt.

```json
{
  "removed": 8,
  "detached": [ { "appointment_id": 150, "date": "2027-01-12", "reason": "has_data" } ],
  "series_deleted": true
}
```

**Hinweis zu `DELETE appointments`:** Das Löschen eines **einzelnen** Serientermins löscht die
Serienzeile dagegen **nie**, auch wenn danach kein Termin mehr auf sie zeigt — sonst würde ein
versehentliches Löschen des letzten Termins „Serie fortsetzen" unmöglich machen. Nur
`DELETE appointment_series` räumt die Zeile auf.

### Validierung (`400`)
- `rrule` außerhalb der Teilmenge (`WEEKLY` mit `INTERVAL` 1–4 und mindestens einem Wochentag,
  `MONTHLY` mit `INTERVAL=1` und genau einer Position wie `2TU` oder `-1WE`) — je nach Verstoß
  eine eigene deutsche Meldung
- `start_date`/`until` kein gültiges Datum; `until < start_date`; `until` mehr als 12 Monate nach
  `start_date` (bei `extend`: nach dem bisherigen `until`)
- die Regel ergibt im Zeitraum keinen einzigen Termin
- Vorlagenfelder wie bei `POST`/`PUT appointments` (Titel Pflicht, `start_time` Pflicht,
  `end_time`- und `location`-Regeln aus 1.10.0)
- `from_date` (PUT, `action=split`) außerhalb `[start_date, until]` der Serie
- `exdates` fehlerhaft: kein Array oder ein Eintrag kein gültiges Datum

Unbekannte `id` → `404`. Einfacher Nutzer oder Gerät → `403`.

---

## Feiertage (holidays)

**Seit 1.11.0 (FI-16).** Berechnete gesetzliche Feiertage (Gauß/Meeus-Osterformel), **ohne**
externe Quelle und **ohne** `ext-calendar`. Bundesweite Feiertage immer, dazu die des in den
Einstellungen hinterlegten Bundeslands (`holiday_region`, siehe unten).

### Feiertage abrufen
**Endpoint:** `GET /api.php?resource=holidays&from=2026-10-01&to=2027-07-31`

**Berechtigung:** jede angemeldete Rolle **außer Gerät** (`403`).

**Query-Parameter:** `from`, `to` — beide Pflicht, gültige Datumsangaben, `to >= from`, Zeitraum
höchstens 400 Tage, sonst `400 {"message": "Ungültiger Zeitraum (höchstens 400 Tage)"}`.

**Response:**
```json
{
  "region": "BY",
  "holidays": {
    "2026-10-03": "Tag der Deutschen Einheit",
    "2026-11-01": "Allerheiligen",
    "2026-12-25": "1. Weihnachtstag",
    "2026-12-26": "2. Weihnachtstag"
  }
}
```
`region` ist das eingestellte Länderkürzel oder `null`, wenn keines oder ein unbekanntes
hinterlegt ist — dann liefert `holidays` nur die bundesweiten Feiertage.

**Nicht erzeugt** (bewusst, siehe Einstellung): Feiertage, die nur regional innerhalb eines
Bundeslands gelten (Mariä Himmelfahrt in Bayern, Augsburger Friedensfest, Fronleichnam in Teilen
Sachsens und Thüringens) sowie einmalige Feiertage (z. B. Reformationstag 2017 bundesweit,
8.5.2025 in Berlin).

---

## Anwesenheit (records)

### Anwesenheitseinträge abrufen
**Endpoint:** `GET /api.php?resource=records`

**Query-Parameter:**
- `appointment_id`: Alle Einträge eines Termins
- `member_id`: Alle Einträge eines Mitglieds
- `year`: Filter nach Jahr
- `month`: Filter nach Monat (auch in Kombination mit Jahr)
- `from_date`: Filter nach Einträge ab Datum
- `to_date`: Filter nach Einträge bis Datum
- `status`: Filter nach Status (Anwesend / Entschuldigt)
- `appointment_type_id`: Filter nach Termin-Arten

**Response:**
```json
[
  {
    "record_id": 1,
    "member_id": 5,
    "appointment_id": 10,
    "arrival_time": "2024-03-15 19:05:00",
    "status": "present",
    "member_name": "Max Mustermann",
    "appointment_title": "Probe"
  }
]
```

**`arrival_time` kann seit 1.5.0 `null` sein** und heißt dann: keine Aussage über die Ankunft.
Vorher trug die Spalte in diesem Fall die Startzeit des Termins — ein Eintrag, der wie eine
Messung aussah, ohne eine zu sein. Wer die Spalte auswertet, muss `null` behandeln; das Datum
eines Eintrags steht am Termin (`appointments.date`), nicht an der Ankunft.

`checkin_source` kennt zusätzlich `exception_request` für Einträge aus einem genehmigten
Zeitkorrektur-Antrag. Die Uhrzeit stammt dort aus der Selbstauskunft des Mitglieds.

---

### Anwesenheit erfassen (manuell)
**Endpoint:** `POST /api.php?resource=records`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "member_id": 5,
  "appointment_id": 10,
  "arrival_time": "2024-03-15 19:05:00",
  "status": "present"
}
```

**Status-Werte:** `present` (anwesend) und `excused` (entschuldigt) — mehr kennt die Spalte
nicht. Unentschuldigtes Fehlen wird nicht gespeichert, sondern aus dem Fehlen eines Eintrags
abgeleitet.

**`arrival_time` ist optional.** Fehlt sie oder ist sie leer, entsteht der Eintrag ohne
Ankunftszeit. Bei Status `excused` ist das der Regelfall: Wer nicht da war, ist nicht angekommen.

**Toleranzband:** Eine angegebene Ankunftszeit muss innerhalb von `checkin_tolerance_hours`
(Vorgabe 2) um die Startzeit des Termins liegen, sonst `400`. Dasselbe Fenster gilt für den
nachträglichen Antrag — ohne diese Grenze ließe sich über den direkten Weg eintragen, was
`resource=exceptions` abweist.

**Fehler:** `400` bei fehlender `member_id`/`appointment_id` oder einer Ankunftszeit außerhalb
des Toleranzbands · `409` wenn für dieses Paar aus Mitglied und Termin bereits ein Eintrag
besteht.

---

### Anwesenheit aktualisieren
**Endpoint:** `PUT /api.php?resource=records&id=1`

**Berechtigung:** Admin/Manager

Dieselben Regeln wie beim Anlegen: `arrival_time` darf leer sein (ein Leerstring löscht die
Angabe), und das Toleranzband wird geprüft — gegen den Termin, der nach der Änderung gilt, denn
ein `PUT` darf den Termin wechseln.

**`PUT` ist eine Teiländerung, keine Vollersetzung.** Geschrieben wird nur, was im Request steht;
`member_id`, `appointment_id`, `arrival_time` und `status` bleiben unberührt, wenn sie fehlen.
Bei `arrival_time` sind „fehlt" und „ist leer" ausdrücklich verschieden: Ein fehlendes Feld lässt
die gespeicherte Zeit stehen, ein Leerstring löscht sie.

**Bis einschließlich 1.9.0 war dies eine Vollersetzung** — ein `PUT` ohne `member_id`,
`appointment_id` und `status` nullte alle drei (OI-69).

---

### Anwesenheit löschen
**Endpoint:** `DELETE /api.php?resource=records&id=1`

**Berechtigung:** Admin/Manager

**Seit 1.8.0:** Trifft `id` keinen Datensatz (bereits gelöscht, erfundene ID), antwortet der
Endpunkt mit `404` statt wie zuvor mit `200`.

**Seit 1.9.1:** Fehlen `id` **und** `member_id`, antwortet der Endpunkt mit `400`. Die
Massenlöschung über `member_id` (ohne `id`) bleibt davon unberührt.

---

## Auto Check-In

### Automatischer Check-In
Geräte-Endpunkt (source_device).

**Source-Werte:**
- `user_totp`: QR-Code Scan via PWA
- `device_auth`: Authentifizierungs-Gerät
- `auto_checkin`: Anderes IoT-Gerät

Erfasst Anwesenheit basierend auf aktueller Zeit und Terminzuordnung. 
Sucht passenden Termin im Zeitfenster. Kann automatisch einen neuen Termin anlegen, wenn aktiviert.

**Endpoint:** `POST /api.php?resource=auto_checkin`

**Authentifizierung:** Token empfohlen

**Request:**
```json
{
  "member_id": 5 ODER "member_number": "M123",
  "arrival_time": "2024-03-15 19:05:32",
  "source_device": "device_auth"
}
```

**Response (Erfolg):**
```json
{
  "success": true,
  "message": "Check-in erfolgreich",
  "record_id": 42,
  "appointment_title": "Probe",
  "arrival_time": "2024-03-15 19:05:32"
}
```

**Fehler (kein Termin):**
```json
{
  "success": false,
  "message": "Kein passender Termin gefunden"
}
```

**Zusätzliches Feld (seit 1.2.4):**

| Feld | Typ | Pflicht | Beschreibung |
|---|---|---|---|
| `appointment_id` | int | nein | Bewusst gewählter Termin. Muss am selben Tag wie `arrival_time` liegen und für die Gruppen des Mitglieds zugelassen sein. Ohne Angabe sucht der Server im Toleranzfenster. |

**Antworten (seit 1.2.4):**

| Status | `reason` | Bedeutung |
|---|---|---|
| `201` / `200` | – | Check-in angelegt oder aktualisiert |
| `403` | `appointment_not_permitted` | Termin gehört zu einer anderen Gruppe |
| `404` | `appointment_not_found` | `appointment_id` existiert nicht |
| `409` | `appointment_wrong_day` | Termin liegt an einem anderen Tag |
| `409` | `appointment_outside_tolerance` | Termin liegt zeitlich außerhalb von `checkin_tolerance_hours` |
| `409` | `no_matching_appointment` | Kein Treffer und die Automatik ist abgeschaltet |

Das Zeitfenster steht in der Einstellung `checkin_tolerance_hours`, ob ohne Treffer ein Termin
angelegt wird in `checkin_auto_create_appointment` (siehe
[Systemeinstellungen](#systemeinstellungen-settings)). Ein automatisch erzeugter Termin trägt
`is_auto_created = 1`.

**Die Toleranz gilt auch für einen gewählten Termin** (`appointment_id`), nicht nur für die
automatische Suche. Die Tagesgrenze allein reicht nicht als Schutz: Ohne die Zeitprüfung ließe
sich für einen beliebigen Termin desselben Tages einchecken, unabhängig von der tatsächlichen
Uhrzeit.

**Seit 1.3.0:** `record_id`, `member_id` und `appointment_id` sind in den Antworten JSON-Zahlen
(zuvor teils Strings). Der gespeicherte `arrival_time` ist die normalisierte `Y-m-d H:i:s`-Form
des Request-Werts — ein ISO-Input mit `T` (z. B. `2026-09-04T19:05:32`) wird ebenso
normalisiert wie das PWA-Format.

---

## TOTP Check-In

### Check-In mit TOTP-Code
Standortverifizierter Check-In mit zeitbasiertem Einmalpasswort.
Wird automatisch für Authorisierten User durchgeführt (z.B. User über PWA).

**Endpoint:** `POST /api.php?resource=totp_checkin`

**Authentifizierung:** Token empfohlen

**Request:**
```json
{
  "totp_code": "123456",
  "arrival_time": "2024-03-15 19:05:32",
  "source_device": "user_totp"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Check-in erfolgreich",
  "record_id": 42,
  "verified": true
}
```

**Fehler (ungültiger Code):**
```json
{
  "success": false,
  "message": "Ungültiger TOTP-Code"
}
```

---

## Station (station)

Endpunkt der **virtuellen Station** (Gerätetyp `kiosk`, seit 1.3.0): ein Tablet, das den
Stations-Code anzeigt und Mitglieder per Mitgliedsnummer + PIN stempeln lässt.

**Authentifizierung:** ausschließlich Geräte-Token eines Kiosks. Ein Kiosk-Token darf
**nur** diese Ressource (und `version`) aufrufen — abgesehen von den öffentlichen Endpunkten
(`ping`, `appearance`, `login`, `register`, `password_reset_request`, `auth`, `logout`, die
noch vor der Authentifizierung laufen) antwortet jede andere Ressource `403`.
Eine vom Token erzeugte Session ist ohne den Token nicht nutzbar (`401`).
Im Unterschied zu `auto_checkin` (das Gerät bürgt für die Identität) prüft hier der Server
die Identität des Mitglieds; das Gerät ist nur Tastatur und Bildschirm.

Steuerung über `?action=`. Andere Methoden als GET und POST antworten `405`.

### Status
**Endpoint:** `GET /api.php?resource=station&action=status`

```json
{
  "device_name": "Tablet Proberaum",
  "totp_enabled": true,
  "pin_enabled": true,
  "pin_min_length": 4,
  "worktime_enabled": true,
  "server_time": "2026-09-04 19:02:11",
  "server_unix": 1788800531
}
```

### Stations-Code
**Endpoint:** `GET /api.php?resource=station&action=totp`

Das Secret verlässt den Server nicht. Der Kiosk erhält den aktuellen Code, den Folgecode und
das Fensterende (Unix-Sekunden) sowie `now`, um die Restlaufzeit gegen die Serveruhr zu rechnen.

```json
{ "code": "287082", "next_code": "081620", "valid_until": 1788800550, "period": 30, "now": 1788800531 }
```

`404` wenn der Kiosk keinen Code anzeigt (Häkchen in der Geräteverwaltung).

### Anmeldung: identify
**Endpoint:** `POST /api.php?resource=station&action=identify`

Alle POST-Aktionen tragen `member_number` und `pin` im Body (Strings; numerische Werte werden
angenommen); der Kiosk hält beides nur im Speicher und verwirft es nach der Ruhezeit.
`409 "Station PIN login is disabled"` wenn `station_pin_enabled = 0` — geprüft vor der
Anmeldung. `409 "Device has no name"` wenn dem Kiosk der Gerätename fehlt (er ist der Ortsnachweis).

```json
{ "member_number": "M123", "pin": "2580" }
```

**Response 200:**
```json
{
  "member": { "name": "Anna", "surname": "Muster" },
  "checkin_candidate": { "appointment_id": 42, "title": "Probe", "date": "2026-09-04", "start_time": "19:30:00", "already_checked_in": false, "record_status": null },
  "worktime_enabled": true,
  "running_session": null,
  "activities": [ { "activity_id": 3, "activity_name": "Aufbau", "color": "#1F5FBF", "is_default": 1, "verification": "start" } ]
}
```
`already_checked_in` ist nur bei einem Eintrag mit Status `present` wahr; `record_status` nennt
den vorhandenen Status (`present`, `excused`) oder `null`. `identify`, `checkin` und
`server_time` (Status-Endpunkt) rechnen mit der Datenbankuhr des Servers; `server_unix` und der
Stations-Code (TOTP) laufen dagegen auf Unix-Zeit, unabhängig von der Zeitzone der Datenbank.

**Fehler:** `400` Nummer oder PIN fehlt · `401 "Invalid member number or PIN"` — dieselbe
Meldung bei unbekannter Nummer, falscher PIN, fehlender PIN, inaktivem Mitglied und
mehrdeutiger Nummer · `423 "Too many attempts"` mit `retry_after` (Sekunden): 5 Fehlversuche
je Mitgliedsnummer (auch unbekannte) innerhalb von 15 Minuten · `423 "Station temporarily
locked"`: 30 Fehlversuche je Kiosk innerhalb von 15 Minuten. `retry_after` nennt in beiden
Fällen die volle Fensterlänge (900 Sekunden), nicht die verbleibende Sperrzeit. Eine neu
gesetzte PIN hebt die Mitgliedssperre auf; eine erfolgreiche Anmeldung setzt beide Zähler
zurück. Eine Sperre auf eine unbekannte oder mehrdeutige Nummer kennt kein Mitglied, dem eine
neue PIN die Sperre abnehmen könnte — sie läuft stattdessen 15 Minuten nach dem letzten
Fehlversuch von selbst ab.

### Anwesenheit: checkin
**Endpoint:** `POST /api.php?resource=station&action=checkin`

Body wie `identify`. Terminwahl serverseitig wie `auto_checkin` (Toleranzfenster,
Gruppenregel), **keine** automatische Terminanlage. Record mit `checkin_source = station_pin`,
`source_device` und `location_name` = Gerätename des Kiosks. `404 "Kein passender Termin gefunden"`
(`reason: no_matching_appointment`). Antwort wie `auto_checkin` (`record_action` created /
updated / unchanged) plus `appointment {appointment_id, title, date, start_time}`. Ein
bestehender Record mit Status `excused` wird von einem Check-in immer auf `present` mit der
neuen Ankunftszeit gehoben (`record_action: updated`) — ein tatsächlicher Stempel schlägt eine
Entschuldigung, unabhängig vom sonst geltenden Zeitvergleich.

### Arbeitszeit: work_start, work_pause, work_resume, work_stop
**Endpoint:** `POST /api.php?resource=station&action=work_start` (Body zusätzlich `activity_id`, positive Ganzzahl)

Verhalten wie `work_sessions` mit `action` start/pause/resume/stop, mit drei Unterschieden:
`source = station`; `start_location_name` und `end_location_name` = Gerätename des Kiosks
(der Kiosk gilt als Ortsnachweis, auch für nachweispflichtige Tätigkeitsarten); die
Notizpflicht (`worktime_require_note`) gilt am Kiosk nicht. `created_by` ist das Kioskkonto.
`404` wenn die Zeiterfassung aus ist · `400` bei fehlender oder ungültiger `activity_id` ·
`409` bei bereits laufender Sitzung bzw. `work_stop` ohne laufende Sitzung.

---

## Ausnahmen (exceptions)

### Ausnahmen abrufen
**Endpoint:** `GET /api.php?resource=exceptions`

**Query-Parameter:**
- `member_id`: Filter nach Mitglied
- `status`: `pending`, `approved`, `rejected`

**Response:**
```json
[
  {
    "exception_id": 1,
    "member_id": 5,
    "exception_date": "2024-03-20",
    "type": "excused",
    "reason": "Krankheit",
    "status": "pending",
    "created_at": "2024-03-19 10:00:00"
  }
]
```

---

### Ausnahme beantragen
**Endpoint:** `POST /api.php?resource=exceptions`

**Request:**
```json
{
  "member_id": 5,
  "appointment_id": 10,
  "exception_type": "time_correction",
  "reason": "QR-Scanner nicht verfügbar",
  "requested_arrival_time": "2024-03-20 19:55:00",
  "status": "pending"
}
```

**Exception-Typen:** `absence` (Abmeldung) und `time_correction` (nachgetragene Ankunftszeit) —
mehr kennt die Spalte nicht. Ein Antrag hängt immer an einem Termin, nicht an einem freien Datum.

**`requested_arrival_time`** gehört zu `time_correction` und muss innerhalb von
`checkin_tolerance_hours` (Vorgabe 2) um die Startzeit des Termins liegen, sonst `400`. Die Zeit
ist die **Ankunft des Mitglieds**, nicht der Zeitpunkt des Antrags — wer erst später merkt, dass
das Stempeln misslungen ist, würde sich sonst selbst eine Verspätung eintragen.

Genehmigt ein Admin den Antrag, entsteht daraus ein Eintrag in `records` mit
`checkin_source = 'exception_request'`. Die Grenze gilt auch beim Bearbeiten — für das Mitglied,
das seinen Antrag nachbessert, wie für den Admin, der ihn vor der Freigabe korrigiert.

**`status`** übernimmt der Server nur von Admin und Manager; jeder andere Antrag entsteht als
`pending`, unabhängig davon, was im Körper steht.

**Ein Antrag je Termin und Art.** Existiert zu Mitglied, Termin und `exception_type` bereits ein
Antrag, der nicht abgelehnt ist, antwortet der Server mit `409` und nennt im Feld `exception_id`
den vorhandenen Antrag. Das betrifft vor allem Terminarten mit Rückmeldung: dort entsteht aus
einer Absage bereits ein Antrag, der im Antragsdialog kein zweites Mal gestellt werden soll. Ein
abgelehnter Antrag blockiert nicht — nach einem Nein ist ein neuer Versuch möglich.

---

### Ausnahme genehmigen/ablehnen
**Endpoint:** `PUT /api.php?resource=exceptions&id=1`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "status": "approved"
}
```

**`PUT` ist eine Teiländerung, keine Vollersetzung.** Geschrieben wird nur, was im Request steht;
`reason` und `requested_arrival_time` bleiben unberührt, wenn sie fehlen. Die Art des Antrags
(`exception_type`) und der Termin lassen sich nicht nachträglich wechseln — beide kommen aus dem
Bestand.

**Bis einschließlich 1.9.0 löschte genau der oben gezeigte Aufruf die Begründung** und bei einer
Zeitkorrektur die beantragte Uhrzeit, weil beide Felder bedingungslos geschrieben wurden — also
gerade das, was die Entscheidung nachvollziehbar macht (OI-69). Die Dokumentation war hier
richtig, der Server nicht.

**Seit 1.9.1** ergibt ein `DELETE` ohne `id` `400` statt `404`.

---

## Mitgliedergruppen (member_groups)

### Gruppen abrufen
**Endpoint:** `GET /api.php?resource=member_groups`

**Response:**
```json
[
  {
    "group_id": 1,
    "group_name": "Trompeten",
    "description": "Trompetenregister",
    "is_default": 0,
    "is_subgroup": 1,
    "sort_order": 20,
    "member_count": 9
  }
]
```

Sortiert nach `sort_order`, bei Gleichstand nach `group_name`.

**Seit 1.8.0:** `is_subgroup` (0/1) markiert eine Gruppe als Untergruppe (z. B. Register), die
Anwesenheitslisten und Namenslisten der Terminrückmeldung gliedert — siehe `attendance_list` und
`appointment_responses`. `sort_order` (Ganzzahl, Vorgabe 0) gilt für **alle** Gruppen, nicht nur
Untergruppen, und bestimmt die Reihenfolge der Abschnitte; bei Gleichstand entscheidet
`group_name`.

Eine Untergruppe darf ganz normal einer Terminart zugeordnet werden (`group_ids` bei
`appointment_types`) — Anwendungsfall ist die Registerprobe: Die Terminart „Registerprobe
Klarinette" bekommt nur das Register Klarinette zugewiesen, erwartet werden dann genau dessen
Mitglieder, und Statistik/Berichte rechnen entsprechend nur für dieses Register.

`is_subgroup=1` und `is_default=1` schließen sich aus (400 bei Verstoß, auch wenn nur eines der
beiden Felder im Request-Körper steht und das andere bereits gespeichert ist) — eine Untergruppe
als Standardgruppe würde jedes neue Mitglied ungefragt einem Register zuordnen.

**Einzelne Gruppe mit Mitgliedern:**
```
GET /api.php?resource=member_groups&id=1
```
Antwort ist die Gruppenzeile mit zusätzlichem Feld `members`: Admin/Manager erhalten je
Mitglied `member_id`, `name`, `surname`, `member_number`, `active`, `created_at`; andere
Rollen nur `member_id`, `name`, `surname`. Weder `pin_hash` noch `pin_updated_at` werden hier
je ausgeliefert.

---

### Gruppe erstellen
**Endpoint:** `POST /api.php?resource=member_groups`

**Berechtigung:** Admin

**Request:**
```json
{
  "group_name": "Posaunen",
  "description": "Posaunenregister",
  "is_default": false,
  "is_subgroup": true,
  "sort_order": 60
}
```

`is_subgroup` und `sort_order` sind optional, Vorgabe `false`/`0`.

---

### Gruppe aktualisieren
**Endpoint:** `PUT /api.php?resource=member_groups&id=1`

**Berechtigung:** Admin

**Request:** wie beim Erstellen. `group_name`, `description` und `is_default` sind ein
Voll-Update: Fehlen sie im Körper, werden sie auf ihren Leerwert zurückgesetzt (`description`
auf `null`, `is_default` auf `false`) statt unverändert zu bleiben.

**Seit 1.8.0 abweichend:** `is_subgroup` und `sort_order` bleiben unverändert, wenn sie im
Körper fehlen — ein `PUT`, das nur `group_name` ändert, löscht damit nicht still die gepflegte
Reihenfolge (gleiches Muster wie bei Terminarten, OI-54).

---

### Gruppe löschen
**Endpoint:** `DELETE /api.php?resource=member_groups&id=1`

**Berechtigung:** Admin

**Seit 1.9.1:** Fehlt `id`, antwortet der Endpunkt mit `400 {"message": "id ist erforderlich"}`;
trifft `id` keinen Datensatz, mit `404`. Zuvor meldete beides `200 "Group deleted"` (OI-56).

---

## Terminarten (appointment_types)

### Terminarten abrufen
**Endpoint:** `GET /api.php?resource=appointment_types`

**Response:**
```json
[
  {
    "type_id": 1,
    "type_name": "Probe",
    "description": "Wöchentliche Probe",
    "color": "#667eea",
    "is_default": 1,
    "responses_enabled": 0,
    "responses_names_visible": 0,
    "responses_require_excuse": 0,
    "response_deadline_hours": null,
    "groups": [
      {
        "group_id": 1,
        "group_name": "Trompeten"
      }
    ]
  }
]
```

---

### Terminart erstellen
**Endpoint:** `POST /api.php?resource=appointment_types`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "type_name": "Konzert",
  "description": "Öffentliches Konzert",
  "color": "#f39c12",
  "is_default": false,
  "group_ids": [1, 2]
}
```

**Rückmeldung (seit 1.7.0):** Optional `responses_enabled`, `responses_names_visible`,
`responses_require_excuse` (je `true`/`false`) und `response_deadline_hours` (0–720 oder `null` für
die globale Frist). Eine Frist außerhalb 0–720 ergibt `400`. Ein `PUT` auf eine
unbekannte `id` ergibt `404 {"message": "Type not found"}`.

**`PUT` ist eine Teiländerung, keine Vollersetzung.** Geschrieben wird nur, was im Request steht;
`type_name`, `description`, `color` und `is_default` bleiben unberührt, wenn sie fehlen. Auch ein
fehlendes `group_ids` lässt die Gruppenzuordnung stehen — ein leeres Array löscht sie. Bis
einschließlich 1.7.0 schrieb der Server alle Grundfelder bedingungslos und löste die Gruppen bei
jedem `PUT` ohne `group_ids` (OI-54).

---

## Terminrückmeldungen (appointment_responses)

Seit 1.7.0. Mitglieder melden sich zu kommenden Terminen zu, ab oder unsicher. Nur bei
Terminarten mit `responses_enabled`. Erwartet ist, wer über Terminart → Gruppe erreicht wird und
am Termindatum aktiv ist. Geräte haben keinen Zugriff (`403`).

### Kommende Termine
**Endpoint:** `GET /api.php?resource=appointment_responses&upcoming=1`

**Berechtigung:** jede angemeldete Rolle außer Gerät. Liefert die Termine, zu denen das Mitglied
des Kontos erwartet ist — auch für Admin und Manager in der Sicht eines Mitglieds. Ohne
verknüpftes Mitglied: leere Liste. Höchstens 50 Termine.

**Response:**
```json
{ "appointments": [ { "appointment": {…}, "settings": {…}, "started": false,
                      "expected": true, "own": null, "summary": {…} } ] }
```

**Seit 1.10.0:**

- **Termine von heute bleiben bis Tagesende enthalten**, auch wenn sie schon begonnen haben —
  dann mit `"started": true`. Bis 1.9.3 verschwand ein Termin mit seinem Beginn. Eine eigene
  Rückmeldung nimmt der Server nach Beginn weiterhin nicht an.
- Das Objekt `appointment` trägt zusätzlich `end_time`, `location`, `description` und
  `responses_enabled` (hier immer `true`).
- **Schalter `with_info=1`:** hängt die Termine der eigenen Gruppen **ohne** Rückmeldung an, die
  von heute bis acht Wochen voraus liegen. Diese Einträge sind schlank — nur `appointment` mit
  `responses_enabled: false`, ohne `settings`, `own`, `summary` und `members`. Die Antwort ist
  dann chronologisch nach Datum und Beginn sortiert. Ohne den Schalter bleibt sie wie bisher,
  damit ein vor dem Update geöffneter Tab der Check-in-App keine Einträge bekommt, die sein Code
  nicht kennt.

```json
{ "appointment": { "appointment_id": 51, "title": "Gesamtprobe", "date": "2026-09-22",
                   "start_time": "19:30:00", "end_time": "22:00:00", "location": "Probelokal",
                   "description": null, "type_id": 1, "type_name": "Gesamtprobe",
                   "color": "#667eea", "responses_enabled": false } }
```

### Ein Termin
**Endpoint:** `GET /api.php?resource=appointment_responses&appointment_id=42`

**Response:**
```json
{
  "appointment": { "appointment_id": 42, "title": "Herbstkonzert", "date": "2026-09-26",
                   "start_time": "19:00:00", "type_id": 3, "type_name": "Auftritt", "color": "#F5A623" },
  "settings": { "names_visible": false, "require_excuse": true, "deadline_hours": 168,
                "deadline": "2026-09-19 19:00:00" },
  "started": false,
  "expected": true,
  "own": { "status": "no", "comment": "Urlaub", "status_changed_at": "2026-09-15 08:12:00",
           "is_late": false, "excuse_state": "pending", "excuse_created": true },
  "summary": { "yes": 21, "no": 4, "maybe": 3, "open": 9 },
  "members": [ … ],
  "comparison": { … }
}
```

- `members` — **Admin/Manager:** alle erwarteten Mitglieder nach Gruppen mit `status` (`null` =
  keine Antwort), `comment`, `status_changed_at`, `is_late`, `excuse_state`, `excuse_created` und
  nach Beginn `present`. **Mitglied:** nur bei `names_visible`, dann ausschließlich `member_id`,
  Name, Gruppe und Status. Ohne `names_visible` und ohne Admin/Manager-Rechte fehlt `members`
  ganz — keine Namen, keine Zugehörigkeiten.

  Jedes Element trägt außerdem `group_name` (die Gruppe der Terminart, über die das Mitglied
  erwartet wird) sowie **seit 1.8.0** `groups` und `subgroups` — dieselbe Struktur wie bei
  `attendance_list`: `groups` sind die Gruppen des Mitglieds, die zur Terminart gehören und
  **seit 1.9.0** nicht als Untergruppe markiert sind, `subgroups` alle als Untergruppe
  markierten Gruppen des Mitglieds, unabhängig vom Termin. Beide Listen sind überschneidungsfrei
  und unterliegen denselben Sichtbarkeitsregeln wie die Namen selbst — ohne Namen keine
  Zugehörigkeiten.
- `comparison` — nur Admin/Manager, nur nach Beginn: Anzahl je `yes_present`, `yes_absent`,
  `no_present`, `no_absent`, `maybe_present`, `maybe_absent`, `none_present`, `none_absent`.
- `is_late` — die letzte **Statusänderung** liegt nach der Frist (Frist = Beginn minus
  `deadline_hours`). Eine geänderte Bemerkung verschiebt den Zeitpunkt nicht. Wird für jeden Status
  geliefert; die Oberflächen (Dashboard, Druckansicht, PWA) kennzeichnen „kurzfristig" nur bei einer
  Absage (`status = 'no'`).
- `excuse_created` (bei `own` und in `members`) — wahr, wenn der verknüpfte Antrag von der
  Rückmeldung selbst angelegt wurde (`appointment_responses.exception_created`) **und** die
  Verknüpfung noch besteht (`excuse_state` nicht `null`). Entscheidet, ob eine Rücknahme den Antrag
  mitlöscht oder ein nur verknüpfter Antrag bestehen bleibt.
- `&format=html` (Admin/Manager): Druckansicht der Besetzung je Gruppe.

### Antworten
**Endpoint:** `PUT /api.php?resource=appointment_responses&appointment_id=42`

**Request:**
```json
{ "status": "no", "comment": "Urlaub" }
```

`status`: `yes`, `no` oder `maybe`. `comment`: optional, höchstens 255 Zeichen; fehlt er, ist die
Bemerkung danach leer. Antwort wie „Ein Termin", `own` ist die gespeicherte Antwort.

Admin und Manager tragen mit `&member_id=7` für ein Mitglied ein, auch nach Beginn.

**Entschuldigungspflicht** (`require_excuse`): Ein echter Wechsel auf `no` (der vorherige Status war
nicht schon `no`) braucht `comment` und legt einen Abwesenheitsantrag (`exceptions`, `pending`) an.
Hat das Mitglied schon einen eigenen offenen oder genehmigten Antrag zum Termin — etwa direkt über
`exceptions` gestellt —, wird dieser nur **verknüpft**, statt einen zweiten anzulegen.

Ob der verknüpfte Antrag der Rückmeldung „gehört", entscheidet, was mit ihm geschieht:

- **Von der Rückmeldung selbst angelegt:** Eine spätere Bemerkungsänderung bei weiterhin `no` zieht
  `reason` mit (solange der Antrag noch `pending` ist); eine Zusage oder Rücknahme löscht ihn, wenn
  er noch `pending` ist. Ein genehmigter oder abgelehnter Antrag bleibt in jedem Fall bestehen.
- **Nur verknüpft** (eigenständig über `exceptions` gestellt): Er bleibt immer unverändert — weder
  eine geänderte Bemerkung noch eine Zusage wirkt sich auf ihn aus. Er gehört dem Mitglied, nicht
  der Rückmeldung (Entscheidung 3b, Spec `2026-09-14-terminrueckmeldung-design.md` Abschnitt 5.4).

Ein neuer Antrag entsteht **nur bei einem echten Statuswechsel auf `no`**, nie bei einer reinen
Bemerkungsänderung während der Status schon `no` ist (Entscheidung 4b) — das greift insbesondere,
wenn ein Verwalter einen zuvor erzeugten Antrag direkt über `exceptions` löscht: Die Rückmeldung
bleibt danach bei `no` stehen, `excuse_state` wird `null`, aber erst ein echter Wechsel (z. B. über
`yes` und zurück auf `no`) legt wieder einen Antrag an.

**Ein abgelehnter Antrag blockiert keinen neuen** (A1): Ist der verknüpfte Antrag `rejected`, zählt
er bei einem echten Statuswechsel auf `no` wie kein Antrag — es entsteht ein neuer, oder ein
eigener, nicht abgelehnter Antrag wird verknüpft. Wechselt das Mitglied danach von `no` weg
(`yes`, `maybe` oder Rücknahme), löst sich nur die Verknüpfung (`excuse_state` wird `null`); der
abgelehnte Antrag selbst bleibt unverändert bestehen. Bei einer reinen Bemerkungsänderung
(`no` → `no`) bleibt ein abgelehnter Antrag hingegen verknüpft — das ist kein echter Statuswechsel.

Schreibzugriffe auf denselben Termin sind serialisiert (Zeilensperre auf den Termin) — zwei
gleichzeitige Erstantworten laufen damit nacheinander statt in einen Deadlock.

### Zurücknehmen
**Endpoint:** `DELETE /api.php?resource=appointment_responses&appointment_id=42[&member_id=7]`

Löscht die Antwort und einen offenen, **von der Rückmeldung selbst angelegten** Antrag. Ein nur
verknüpfter Antrag bleibt bestehen (Entscheidung 3b). Rechte wie beim PUT. Anders als bei `GET` und
`PUT` prüft das `DELETE` nicht, ob die Terminart überhaupt `responses_enabled` hat — eine
vorhandene Antwort lässt sich immer zurücknehmen, auch wenn die Terminart die Rückmeldung
zwischenzeitlich abgeschaltet hat.

### Fehler

| Code | Anlass |
|---|---|
| `400` | `appointment_id` fehlt oder ungültig, ungültiger `status`, Bemerkung zu lang |
| `403` | Mitglied nicht erwartet; `member_id` ohne Admin/Manager; Konto ohne Mitglied; Gerät |
| `404` | Termin oder Mitglied unbekannt; beim DELETE keine Antwort vorhanden |
| `409` | Terminart ohne Rückmeldung (nur `GET` und `PUT`, siehe oben); Termin hat begonnen (Mitglied für sich selbst) |
| `422` | Absage ohne Begründung bei Entschuldigungspflicht |

---

## Tätigkeitsarten (activity_types)

Stammdaten der Arbeitszeiterfassung. Ist die Zeiterfassung abgeschaltet
(`worktime_enabled = 0`), antwortet die Ressource mit **404** — ein
abgeschaltetes Feature verrät nicht, dass es existiert.

### Tätigkeitsarten abrufen
**Endpoint:** `GET /api.php?resource=activity_types`

**Filterung nach Mitgliedergruppen.** Eine Tätigkeitsart ist nur für Mitglieder
der zugeordneten Gruppen erfassbar (`activity_type_groups`, seit 1.2.1):

| Aufrufer | Parameter | Ergebnis |
|---|---|---|
| Rolle `user` | egal | nur Arten aus den Gruppen des **eigenen** Mitglieds |
| Admin / Manager | ohne `member_id` | alle Arten — sie erfassen Nachträge zugunsten anderer |
| Admin / Manager | `member_id=<id>` | nur Arten aus den Gruppen dieses Mitglieds |

Ein Mitglied ohne passende Gruppe erhält ein **leeres Array mit Status 200**,
nicht 404. Die beiden Fälle sind so unterscheidbar: leere Auswahl gegen
abgeschaltetes Feature.

Nicht-Admins sehen zusätzlich nur Arten mit `is_active = 1`.

**Response:**
```json
[
  {
    "activity_id": 1,
    "activity_name": "Vereinsheim-Pflege",
    "description": "Pflege und Instandhaltung",
    "color": "#1F5FBF",
    "is_default": 0,
    "is_active": 1,
    "verification": "start_end",
    "groups": [
      { "group_id": 1, "group_name": "Aktive" }
    ],
    "appointment_type_ids": [2, 5]
  }
]
```

`verification` steuert den Ortsnachweis: `none` (kein Code), `start` (Code beim
Start) oder `start_end` (Code beim Start und beim Beenden).

**Eingrenzung der Terminauswahl** (`activity_type_appointment_types`, seit 1.2.3).
`appointment_type_ids` nennt die Terminarten, zu denen diese Tätigkeit passt; die Oberfläche
bietet beim Erfassen dann nur solche Termine an. „Bühnenaufbau" zeigt so nur Konzerte, nicht
jede Probe des Jahres.

**Ein leeres Array bedeutet keine Einschränkung** — die Tätigkeitsart bietet alle Termine an.
Das ist die **andere** Semantik als bei `groups`, wo eine fehlende Zuordnung „niemand" heißt.
Der Unterschied liegt im Schaden der jeweils falschen Vorbelegung: Bei Gruppen wäre
„leer = alle" eine stille Rechteausweitung, bei Terminarten wäre „leer = keine" eine
Selbstblockade nach dem Update. Die Migration legt deshalb keine Zuordnungen an.

Die Eingrenzung ist eine Erfassungshilfe, keine Rechteprüfung: Der Server nimmt einen
Terminbezug auch dann an, wenn er nicht zu den verknüpften Terminarten passt. Ein bereits
zugeordneter Termin bleibt in der Oberfläche deshalb wählbar, auch wenn er durch den Filter
fällt — sonst löste ein Speichern die Zuordnung stillschweigend.

---

### Tätigkeitsart erstellen
**Endpoint:** `POST /api.php?resource=activity_types`

**Berechtigung:** Admin

**Request:**
```json
{
  "activity_name": "Bühnenaufbau",
  "description": "Auf- und Abbau",
  "color": "#8E44AD",
  "is_default": false,
  "is_active": true,
  "verification": "none",
  "group_ids": [1, 2],
  "appointment_type_ids": [2, 5]
}
```

`group_ids` ist **erforderlich** und darf nicht leer sein — ohne Gruppe wäre die
Tätigkeitsart für niemanden erfassbar. `appointment_type_ids` ist **optional**; fehlt es,
bleibt die Art unverknüpft und bietet alle Termine an.

Beim `PUT` gilt für beide Felder: Ein fehlendes Feld lässt die Zuordnung unangetastet. Ein
leeres `group_ids` wird mit `400` abgewiesen, ein leeres `appointment_type_ids` dagegen
angenommen — es löst die Eingrenzung. Eine unbekannte `type_id` ergibt `400`.

---

### Tätigkeitsart aktualisieren
**Endpoint:** `PUT /api.php?resource=activity_types&id=<id>`

**Berechtigung:** Admin

**`PUT` ist eine Teiländerung, keine Vollersetzung.** Geschrieben wird nur, was im Request steht —
`activity_name`, `description`, `color`, `verification`, `is_default` und `is_active` bleiben
unberührt, wenn sie fehlen. `activity_name` ist damit beim `PUT` nicht mehr Pflicht; mitgeschickt
darf es aber nicht leer sein (`400`). Bis einschließlich 1.7.0 schrieb der Server alle Grundfelder
bedingungslos — ein `PUT` ohne `is_active` aktivierte eine ausgemusterte Art still wieder (OI-54).

Ein **fehlendes** `group_ids` lässt die Zuordnung unangetastet, ein **leeres
Array** löscht sie.

---

### Tätigkeitsart löschen
**Endpoint:** `DELETE /api.php?resource=activity_types&id=<id>`

**Berechtigung:** Admin

Hängen Sitzungen an der Art, antwortet der Server mit **409** — Löschen würde
bestätigten Nachweisstunden ihre Zuordnung nehmen. Stattdessen `is_active = 0`
setzen.

**Seit 1.9.1:** Fehlt `id`, antwortet der Endpunkt mit `400 {"message": "id ist erforderlich"}`;
trifft `id` keinen Datensatz, mit `404`. Zuvor meldete beides `200` (OI-56).

---

## Arbeitszeiten (work_sessions)

Erfassung geleisteter Arbeitszeit. Wie `activity_types` mit **404**, wenn die
Zeiterfassung abgeschaltet ist. Geräte (Rolle `device`) haben keinen Zugriff.

Je Mitglied kann höchstens **eine** Sitzung offen sein; ein zweiter Start
antwortet mit **409**. Eine vergessene Sitzung wird beim nächsten Zugriff
automatisch geschlossen, sobald `worktime_max_session_hours` überschritten ist —
gekappt auf Start plus Obergrenze, Status `submitted`.

### Sitzungen abrufen
**Endpoint:** `GET /api.php?resource=work_sessions`

**Parameter:** `id`, `running=1` (nur die laufende Sitzung, sonst `null`),
`year`, `month` (nur zusammen mit `year`), `from_date`, `to_date`, `member_id`,
`activity_id`, `appointment_id`, `status`

---

### Sitzung steuern
**Endpoint:** `POST /api.php?resource=work_sessions`

**Request** (`action` bestimmt den Vorgang):
```json
{
  "action": "start",
  "activity_id": 1,
  "appointment_id": 196,
  "totp_code": "123456"
}
```

`action` kennt `start`, `pause`, `resume` und `stop`. Beim Stoppen sind `note`
und `force` möglich; `force` beendet ohne Ortsnachweis und setzt den Eintrag auf
`submitted`, also freigabepflichtig.

**Terminbezug:** `appointment_id` ordnet die Stunden einem Termin zu — für die Auswertung,
was eine Veranstaltung an Arbeit gekostet hat.

**Es entsteht dabei kein Anwesenheitseintrag.** Bis 1.2.2 legte ein Start mit einem Termin
am selben Tag einen `records`-Eintrag mit `checkin_source = 'timer'` an. Das ist seit 1.2.3
entfallen: Die Anwesenheitsauswertung liest `records` ohne Rücksicht auf `checkin_source`,
ein so erzeugter Eintrag zählte also voll in Anwesenheitsquote und Pünktlichkeit. Wer
morgens die Bühne für das Abendkonzert aufbaute, galt als anwesend und als Stunden zu früh.

Damit gilt für alle Erfassungswege dasselbe: Kein Weg der Zeiterfassung erzeugt Anwesenheit.
Wer beides festhalten will, nutzt zusätzlich den regulären Check-in.

---

### Nachtrag, Korrektur, Löschung
**Endpoints:** `POST` (ohne `action`), `PUT ...&id=<id>`, `DELETE ...&id=<id>`

Ein Nachtrag durch das Mitglied selbst landet in `submitted` und braucht eine
Freigabe; ein Nachtrag durch Admin oder Manager gilt sofort. Löschen darf nur
der Admin. Jede Änderung wird in `work_session_log` protokolliert.

**Eine Zeitkorrektur nimmt den Ortsnachweis mit.** Ändert ein `PUT` die `start_time`, wird
`start_location_name` auf `NULL` gesetzt; ändert es die `end_time`, entsprechend
`end_location_name`. Der Nachweisgrad fällt damit von `hours` auf `start` oder `none`.
Das gilt für **alle Rollen**: Ein Ortsnachweis gilt für den gestempelten Zeitpunkt, nicht für
den behaupteten — wird der Zeitpunkt verschoben, verliert das Etikett seine Grundlage.
Unveränderte Zeiten lassen beide Nachweise stehen, auch wenn sie in einer anderen Schreibweise
mitkommen (`08:00` gegen `08:00:00`).

**Terminbezug (`appointment_id`)**

Optional bei `POST` und `PUT`. Beim `PUT` entscheidet die Anwesenheit des Feldes im Payload:

| Payload | Wirkung |
|---|---|
| `appointment_id` fehlt | Der bestehende Terminbezug bleibt unverändert |
| `appointment_id: <id>` | Zuordnung auf diesen Termin; unbekannte ID ergibt `400` |
| `appointment_id: null` (oder leer) | Die Zuordnung wird gelöst |

**Ein Nachtrag erzeugt keinen Anwesenheitseintrag** — auch nicht bei der Freigabe. Arbeit für
einen Termin ist keine Anwesenheit bei ihm: Wer den Bühnenaufbau nachträgt, war nicht
notwendig beim Konzert. Zudem ist ein Nachtrag bis zur Freigabe eine ungeprüfte Behauptung.
Den `records`-Eintrag erzeugt allein der Timer-Start (`action: 'start'`), und auch dort nur,
wenn der Termin am selben Tag liegt.

---

### Meldungstexte sind eine Schnittstelle

Die PWA ordnet den **englischen Meldungstexten** dieser Ressource deutsche
Anzeigetexte zu (`SERVER_MESSAGES` in `public/checkin/js/app.js`). Der Schlüssel
ist die Meldung, nicht der Statuscode: Ein 403 trägt hier mehrere Ursachen —
fehlender Ortsnachweis und fremde Mitgliedergruppe —, die Meldung dagegen ist
eindeutig.

**Wer einen dieser Texte ändert, muss die Zuordnung dort mitziehen.** Für den
Gruppen-403 (`Activity type not allowed for this member`) sichert ein Test in
`tests/suites/worktime_api.php` den Wortlaut ab.

---

## Statistiken (statistics)

### Statistik abrufen
**Endpoint:** `GET /api.php?resource=statistics`

**Query-Parameter:**
- `member_id`: Statistik eines Mitglieds (User nur eigene)
- `group_id`: auf eine Gruppe einschränken; ohne Zugriff **403**
- `year`: Jahr (Standard: aktuelles)
- `appointment_type_id`: auf eine Terminart einschränken
- `include=worktime`: hängt den Arbeitszeitblock an (siehe unten); jeder andere Wert wird ignoriert

**Response:**
```json
{
  "warning": null,
  "year": 2026,
  "worktime": null,
  "summary": {
    "total_appointments": 37,
    "total_members": 33,
    "total_present": 751,
    "total_excused": 0,
    "total_unexcused": 211,
    "overall_average": 78.1
  },
  "statistics": [
    {
      "group_id": 1,
      "group_name": "Aktive",
      "appointment_types": [
        { "type_id": 1, "type_name": "Gesamtprobe" },
        { "type_id": 2, "type_name": "Registerprobe" }
      ],
      "members": [
        {
          "member_id": 5,
          "member_name": "Muster, Anna",
          "total_appointments": 37,
          "attended": 32,
          "excused": 0,
          "unexcused_absences": 5,
          "attendance_rate": 86.5,
          "by_type": [
            {
              "type_id": 1,
              "type_name": "Gesamtprobe",
              "total_appointments": 25,
              "attended": 22,
              "excused": 0,
              "unexcused_absences": 3,
              "attendance_rate": 88.0
            },
            {
              "type_id": 2,
              "type_name": "Registerprobe",
              "total_appointments": 12,
              "attended": 10,
              "excused": 0,
              "unexcused_absences": 2,
              "attendance_rate": 83.3
            }
          ]
        }
      ]
    }
  ]
}
```

`warning` trägt einen Hinweis, wenn ein Parameter ignoriert wurde — etwa eine fremde
`member_id` bei einem `user`. `excused` ergibt sich aus
`total_appointments − attended − unexcused_absences`, wird aber serverseitig gerechnet und
mitgeliefert: Jeder Verbraucher, der es selbst ausrechnet, ist eine Stelle mehr, an der die
Formel auseinanderlaufen kann.

**Ein Termin zählt nur, wenn er bereits begonnen hat** (`date <= CURDATE() + 2h`), und nur
innerhalb der Mitgliedschaftszeiträume des Mitglieds.

`appointment_types` listet **alle** Terminarten, an denen die Gruppe hängt. `by_type` führt sie
je Mitglied in **derselben Länge und derselben Reihenfolge** — Eintrag *n* von `by_type` gehört
zu Eintrag *n* von `appointment_types`. Wer die Spalten einer Terminart sucht, muss also nicht
nach `type_id` filtern, sondern kann beide Listen parallel durchlaufen.

Ein `by_type`-Eintrag mit `total_appointments: 0` heißt „diese Terminart hatte im gewählten Jahr
keinen Termin" — das ist etwas anderes als eine Quote von 0 %, bei der Termine stattfanden und
das Mitglied bei keinem anwesend war. `attendance_rate` steht in beiden Fällen auf `0.0`; wer die
beiden unterscheiden will, muss auf `total_appointments` schauen.

`summary.total_appointments` ist **entdoppelt**: Gezählt werden unterschiedliche Termine, nicht
Zeilen. Erreicht ein Mitglied denselben Termin über zwei Gruppen — weil es beiden angehört —,
zählt dieser Termin in der Kopfzahl einmal. Die Gruppentabellen darunter zählen dagegen je
Gruppe, ohne Rücksicht auf andere Gruppen. Deshalb kann die **Summe der Gruppentabellen größer
sein als `summary.total_appointments`**, ohne dass das ein Widerspruch wäre — es ist der
Unterschied zwischen „je Gruppe gezählt" und „unterschiedliche Termine gezählt". Im Bestand vom
2026-09-11 etwa tragen „Aktive" und „Jugend" je 62 Termine, die Vorstandschaft 9 — macht 133 in
der Summe der Gruppentabellen, während die Kopfzahl bei 71 steht, weil sich Aktive und Jugend
dieselben 62 Termine teilen.

#### Arbeitszeit im Ergebnis (`include=worktime`)

`include=worktime` hängt der Antwort einen eigenen Schlüssel `worktime` an. Ohne den
Parameter steht dort `null` — ebenso, wenn die Zeiterfassung abgeschaltet ist
(`worktime_enabled`); der Aufruf bleibt dann trotzdem **200**.

Der Block steht bewusst neben der Anwesenheitsauswertung und nicht in ihr: Anwesenheitsquote
und geleistete Stunden sind verschiedene Fragen.

Gezählt wird ausschließlich, was **bestätigt und beendet** ist — `status = 'confirmed'` mit
gesetztem `end_time`. Sitzungen in `submitted`, `rejected` oder noch laufende erscheinen nicht.
Der Zeitraum ist das Kalenderjahr aus `year`, maßgeblich ist `start_time`. Die Dauer ist netto:
Ende minus Start minus `break_minutes`, nie negativ.

`by_proof` teilt die Minuten nach dem Ortsnachweis der Sitzung auf:

| Wert | Bedeutung |
|---|---|
| `hours` | Start **und** Ende ortsbelegt — die Stunden selbst sind nachgewiesen |
| `start` | genau **eine** der beiden Grenzen ortsbelegt — Start oder Ende |
| `none` | kein Ortsnachweis |

Für die Sichtbarkeit gelten dieselben Regeln wie für die übrige Statistik: Ein `user` erhält
ausschließlich sein eigenes Mitglied, eine fremde `member_id` wird ignoriert und im Feld
`warning` quittiert. Admin und Manager bekommen ohne `member_id` alle Mitglieder mit Stunden
im Jahr.

```json
{
  "year": 2026,
  "worktime": {
    "summary": {
      "total_minutes": 736,
      "hours_proven": 365,
      "start_proven": 194,
      "unproven": 177,
      "sessions": 18
    },
    "members": [
      {
        "member_id": 5,
        "name": "Anna",
        "surname": "Muster",
        "member_number": "M001",
        "worked_minutes": 736,
        "sessions": 18,
        "by_proof": { "hours": 365, "start": 194, "none": 177 },
        "by_activity": [
          { "activity_id": 1, "activity_name": "Bühnenaufbau", "minutes": 152, "sessions": 11 },
          { "activity_id": 3, "activity_name": "Vereinsheim",  "minutes": 584, "sessions": 7 }
        ]
      }
    ]
  }
}
```

`summary` summiert über alle enthaltenen Mitglieder; `hours_proven`, `start_proven` und
`unproven` ergeben zusammen `total_minutes`.

#### Pünktlichkeit und Zuverlässigkeit im Ergebnis (ab 1.5.1)

**Pünktlichkeit und Zuverlässigkeit** stehen als zwei Blöcke auf der obersten Ebene,
neben `summary`. Beide gelten für den **gefilterten Bereich** — ohne `member_id` für Verein oder
Gruppe, mit `member_id` für diese Person. Für die Rolle `user` ist das immer die eigene Person.

```json
"punctuality": {
  "enabled": true, "sufficient": true, "min_measurements": 5,
  "measured_count": 15, "total_count": 22,
  "on_time_count": 12, "rate": 80.0,
  "late_count": 3, "avg_late_minutes": 7.3,
  "self_reported_count": 1
},
"reliability": {
  "enabled": true,
  "total": 22, "appeared": 15, "excused_in_time": 4, "missed": 3, "rate": 86.4
}
```

Ist eine Kennzahl ausgeschaltet (`punctuality_enabled` bzw. `reliability_enabled`, ab Werk `0`),
steht dort nur `{"enabled": false}`.

| Feld | Bedeutung |
|---|---|
| `rate` | Prozent, eine Nachkommastelle — dieselbe Einheit wie `summary.overall_average`. `null` unter `min_measurements` Messungen bzw. ohne Termine |
| `measured_count` | Ankünfte mit Uhrzeit, ohne Import und Timer |
| `total_count`, `total` | Soll-Paare aus Mitglied und Termin im Bereich |
| `on_time_count` | Ankünfte bis `punctuality_grace_minutes` nach Beginn (negativ: vor Beginn) |
| `avg_late_minutes` | Mittel der Verspätung **ab Beginn**, je Ankunft gekappt bei 20 Minuten, nur über Zuspätkommer; `null` ohne Verspätung |
| `self_reported_count` | davon aus genehmigten Zeitkorrektur-Anträgen |
| `excused_in_time` | rechtzeitig abgemeldet oder vom Verwalter ohne Abmeldung entschuldigt. Maßgeblich ist bei Terminarten **ohne** Rückmeldung der Terminbeginn (nicht abgelehnter `absence`-Antrag davor), bei Terminarten **mit** Rückmeldung die Frist — Absage oder Antrag davor; verlangt die Terminart eine Entschuldigung (`responses_require_excuse`), zählt die rechtzeitige Absage nur mit verknüpftem, nicht abgelehntem Antrag |
| `missed` | weder erschienen noch rechtzeitig abgemeldet — auch eine nach Beginn gemeldete, später genehmigte Abmeldung |

Minuten werden abgerundet: 20:00:59 gilt als 20:00.

**Farbschwellen (seit 1.9.0):** Jede Antwort trägt `rate_bands` mit den drei Schwellen der
Anwesenheitsquote — `{ "mid": 40, "fair": 60, "good": 80 }`. Darunter färbt die Oberfläche rot,
ab `mid` orange, ab `fair` gelb, ab `good` grün. Die Werte kommen aus `system_settings`
(`rate_threshold_mid`, `_fair`, `_good`); der Server klammert sie auf 1–99, fällt bei
unbrauchbaren Werten auf 40/60/80 zurück und sortiert sie aufsteigend. Sie reisen im Payload,
weil `settings` Admins vorbehalten ist, die Statistik aber jede Rolle sieht.

---

## Anwesenheitsbericht (statistics_report)

### Bericht abrufen
Liefert die Anwesenheitsstatistik als **druckbare HTML-Seite** — nicht als JSON. Gedacht zum
Lesen, Drucken und Vorlegen; die Zahlen selbst holt man über `resource=statistics`.

**Endpoint:** `GET /api.php?resource=statistics_report`

**Berechtigung:** Admin, Manager und User. Ein `user` erhält ausschließlich die eigene Person;
eine mitgeschickte fremde `member_id` wird **ignoriert, nicht abgewiesen**. Ein Gerätekonto
erhält 403, weil ihm kein Mitglied zugeordnet ist.

**Query-Parameter:**

| Parameter | Bedeutung |
|---|---|
| `year` | Kalenderjahr, Standard: laufendes |
| `group_id` | auf eine Gruppe einschränken; ohne Zugriff **403** |
| `member_id` | auf ein Mitglied einschränken; für `user` wirkungslos |

**Antwort:** `Content-Type: text/html`. Die Seite lädt `css/print.css` über ein
`<base href="../">` und enthält **kein JavaScript** — sie druckt sich nicht selbst, sondern
will vor dem Drucken gelesen werden. Auf einer Demo-Installation trägt sie einen Hinweisblock,
dass es sich um erfundene Daten handelt.

**Aufbau:**
1. Kennzahlen des Gesamtergebnisses
2. je Gruppe eine Tabelle: Mitglied, Termine, Anwesend, Entschuldigt, Unentschuldigt, Quote —
   danach **je Terminart der Gruppe eine weitere Spalte** mit der Quote des Mitglieds für
   genau diese Terminart. Hat eine Terminart im Berichtsjahr keine Termine, steht dort ein
   Strich statt „0 %": Eine Null läse sich auf einem Nachweis wie ein Vorwurf.
3. **nur bei genau einem Mitglied** — für `user` also immer — der Abschnitt
   „Termine im Einzelnen": Datum, Termin, Terminart, Status, Ankunft, Herkunft

**Die Spalte Herkunft** sagt, worauf eine Ankunftszeit beruht. `records.arrival_time` ist keine
durchgehende Messung — seit 1.5.0 darf sie aber `null` sein und sagt dann aus, dass keine Ankunft
bekannt ist, statt die Startzeit des Termins zu behaupten.

| Wert | Bedingung |
|---|---|
| `gemessen` | Ankunftszeit vorhanden **und** `checkin_source` ist `station_pin`, `device_auth`, `user_totp` oder `auto_checkin` |
| `korrigiert` | `checkin_source` ist `exception_request` — der Eintrag stammt aus einem genehmigten Zeitkorrektur-Antrag |
| `nachgetragen` | alles Übrige — `admin`, `import`, `timer`, und jeder Eintrag ohne Ankunftszeit |

Eine Quelle allein macht noch keine Messung: Ohne Uhrzeit gilt `nachgetragen`, auch wenn der
Datensatz von einem Kiosk stammt.

Ankunft und Herkunft bleiben leer, außer bei Status `present` mit gesetzter Ankunftszeit.

**Kein CSV.** Anwesenheitsdaten liefert `resource=export&type=records` bereits als CSV; ein
zweiter Weg dorthin wäre eine Dublette mit eigener Rechteprüfung.

**Fehler:** `405` bei anderem Verfahren als `GET`, `403` ohne verknüpftes Mitglied oder ohne
Gruppenzugriff, `500` bei einem Fehler im Berichtsaufbau (die Einzelheiten stehen im Serverlog,
nicht in der Antwort).

---

## Benutzerverwaltung (users)

### Benutzer abrufen
**Endpoint:** `GET /api.php?resource=users`

**Berechtigung:** Admin

**Query-Parameter (Geräteliste):**
- `user_type=device`: nur Geräte-Accounts
- `device_type` (seit 1.3.0, nur zusammen mit `user_type=device`): schränkt auf
  `totp_location`, `auth_device` oder `kiosk` ein

**Response:**
```json
[
  {
    "user_id": 1,
    "email": "admin@example.com",
    "role": "admin",
    "member_id": 5,
    "is_active": 1,
    "created_at": "2024-01-01 12:00:00",
    "api_token_expires_at": "2025-01-01 00:00:00"
  }
]
```

---

### Benutzer erstellen
**Endpoint:** `POST /api.php?resource=users`

**Berechtigung:** Admin

**Request:**
```json
{
  "action": "create",
  "email": "new@example.com",
  "password": "SecurePass123!",
  "role": "user",
  "member_id": 10
}
```

`member_id` ist optional und wird **beim Anlegen** geprüft wie beim Bearbeiten: **409**, wenn das
Mitglied bereits an einem anderen Benutzer hängt, **404**, wenn es nicht existiert. Ein Mitglied
gehört zu höchstens einem Benutzer; in der Datenbank steht darauf kein `UNIQUE`, die Prüfung ist
also die einzige Schranke.

---

### Gerät anlegen
**Endpoint:** `POST /api.php?resource=users`

**Berechtigung:** Admin

**Request:**
```json
{
  "action": "create_device",
  "device_name": "Tablet Proberaum",
  "device_type": "kiosk",
  "totp_enabled": true
}
```

`device_type` ist `totp_location`, `auth_device` oder `kiosk` (seit 1.3.0, „Virtuelle
Station“). Für `kiosk` ist `totp_enabled` optional (Standard `false`) und steuert, ob das
Gerät von Anfang an einen Stations-Code anzeigen darf. Ein beim Anlegen mitgeschicktes
`totp_secret` wird für `kiosk` und `auth_device` vollständig ignoriert — das Secret entsteht
für diese beiden Typen ausschließlich serverseitig (nur `PUT` lehnt ein eigenes
`totp_secret` bei `kiosk` mit `400` ab, siehe unten). Nur für `totp_location` wird ein
mitgeschicktes `totp_secret` verwendet und validiert: es muss ein String, Base32-kodiert
(Zeichen `A`–`Z`, `2`–`7`) und 16 bis 64 Zeichen lang sein, sonst antwortet die Anfrage mit
`400`. `is_active` ist optional (Standard `true`, also aktiv) und wird beim Anlegen direkt
übernommen.

**Response:**
```json
{
  "success": true,
  "device": {
    "user_id": 12,
    "device_name": "Tablet Proberaum",
    "device_type": "kiosk",
    "api_token": "generated_token_here",
    "totp_secret": null,
    "has_totp_secret": true
  }
}
```

Die Create-Antwort enthält für jeden Gerätetyp sowohl `totp_secret` (bei `kiosk` immer
`null`) als auch `has_totp_secret` — das Secret selbst verlässt den Server nie,
`has_totp_secret` zeigt an, ob eines hinterlegt wurde. `GET` lässt das Feld `totp_secret` bei
`kiosk` dagegen ganz weg, sowohl einzeln als auch in der Liste (siehe unten). `api_token`
erscheint nur in dieser Antwort; danach ist er nur noch über das Bearbeiten-Formular abrufbar.

### Gerät aktualisieren
**Endpoint:** `PUT /api.php?resource=users&id={user_id}`

**Berechtigung:** Admin

Um das TOTP-Secret eines Geräts zu ändern, ohne den Wert selbst zu übertragen:
```json
{
  "totp_action": "generate"
}
```
oder `"totp_action": "clear"`, um es zu entfernen. Ein unbekannter Wert antwortet `400`.
Eine `kiosk`-Anfrage mit einem eigenen `totp_secret` wird ebenfalls mit `400` abgelehnt. Ein
mitgeschicktes `totp_secret` muss wie beim Anlegen Base32-kodiert sein (16–64 Zeichen), sonst
antwortet `400`; gespeichert wird der normalisierte (großgeschriebene, getrimmte) Wert.
`"totp_secret": null` ist ein No-op — das gespeicherte Secret bleibt unverändert; entfernen
lässt es sich nur über `"totp_action": "clear"` (bei `totp_location` nicht erlaubt, siehe unten).
Ein Wechsel des `device_type` verwirft das gespeicherte Secret, außer die gleiche Anfrage
liefert oder erzeugt ein neues. Eine `totp_location` braucht dabei immer ein Secret: sowohl
`"totp_action": "clear"` auf einer `totp_location` als auch ein Wechsel zu `totp_location`
ohne mitgeschicktes oder erzeugtes Secret antworten mit `400`.

`totp_secret` liefert `GET` — einzeln wie in der Liste — nur für `totp_location` unverändert
zurück. Für `kiosk` **und** `auth_device` steht stattdessen `has_totp_secret` (Boolean); das
Secret selbst verlässt für diese beiden Typen den Server nie.

Für `auth_device` wird `totp_action: "generate"` mit `400 "Auth-Geräte haben kein Secret"`
abgelehnt — das Secret einer `auth_device` entsteht nicht am Server; es gibt dort nichts zu
erzeugen.

---

### Benutzer aktivieren/deaktivieren
**Endpoint:** `POST /api.php?resource=activate_user`

**Berechtigung:** Admin

**Request:**
```json
{
  "user_id": 5,
  "is_active": 1
}
```

---

### Benutzerstatus aktualisieren
**Endpoint:** `POST /api.php?resource=user_status`

**Berechtigung:** Admin

**Request:**
```json
{
  "user_id": 5,
  "role": "manager",
  "member_id": 10
}
```

---

## Token-Verwaltung

### API-Token neu generieren
**Endpoint:** `POST /api.php?resource=regenerate_token`

**Berechtigung:** Admin/Manager

**Response:**
```json
{
  "success": true,
  "token": "new_generated_token_here",
  "expires_at": "2025-12-31 23:59:59"
}
```

---

### Passwort ändern
**Endpoint:** `POST /api.php?resource=change_password`

Doppelte Eingabeprüfung erfolgt in HTML.

**Request:**
```json
{
  "current_password": "oldPass123",
  "new_password": "newSecurePass456!",
}
```

---

### Stations-PIN ändern
**Endpoint:** `POST /api.php?resource=change_pin`
**Berechtigung:** angemeldeter Nutzer mit verknüpftem Mitglied; `404` wenn `station_pin_enabled = 0`; Geräte `403`

```json
{ "current_password": "geheim", "new_pin": "2580" }
```
`403 "Current password incorrect"` · `400` mit Fehlertext und `field: "new_pin"` · `404 "Member not found"` wenn das verknüpfte Mitglied nicht mehr existiert · `200 "PIN changed successfully"`. Hebt eine bestehende Sperre des Mitglieds auf.

---

## Import/Export

### Export
Exportiert Daten als CSV-Datei oder – für die Arbeitszeitberichte – als druckbare
HTML-Seite.

**Endpoint:** `GET /api.php?resource=export`

**Berechtigung:** je Exporttyp verschieden.

| Typ | Admin/Manager | User |
|---|---|---|
| `members`, `appointments`, `records` | ja | **nein** (403) |
| `worktime_activity`, `worktime_appointment` | ja | **nein** (403) |
| `worktime_member` | ja, auch als CSV, auch für fremde `member_id` | ja — **nur** die eigene Person, **nur** mit `format=html` |

Ein `user` bekommt den eigenen Stundennachweis also ausschließlich als Druckansicht. Ohne
`format=html` antwortet der Server mit **403**, nicht mit einer stillen HTML-Ausgabe: Der
Aufrufer soll wissen, dass er nicht bekommt, was er angefordert hat. Eine mitgeschickte fremde
`member_id` wird **ignoriert, nicht abgewiesen** — eine Fehlermeldung wäre ein Orakel darüber,
welche IDs existieren. Gerätekonten erhalten 403, weil ihnen kein Mitglied zugeordnet ist.

Die eigenen Rohdaten gibt es über `resource=my_data`. **JSON und CSV enthalten dasselbe** — die
CSV-Form führt neben Stammdaten, Gruppen, Anwesenheiten und Ausnahmen auch die Abschnitte
„MITGLIEDSCHAFTSZEITRÄUME", „ARBEITSZEITEN" (Beginn, Ende, Pause, Dauer, Tätigkeit, Termin,
Status, Nachweis, Quelle, Notiz) und „ÄNDERUNGSHISTORIE ARBEITSZEIT" (Zeitpunkt, Sitzung,
Vorgang, Änderungen als JSON). **Seit 1.7.0** trägt die JSON-Antwort
zusätzlich `appointment_responses` (Termin, Status, Bemerkung, Zeitpunkte der letzten Status- und
der letzten Änderung), die CSV-Form einen Abschnitt „TERMINRÜCKMELDUNGEN" mit den Spalten
Termindatum, Termin, Rückmeldung, Bemerkung, Status geändert, Zuletzt geändert.

**Query-Parameter:**

| Parameter | Gilt für | Bedeutung |
|---|---|---|
| `type` | alle | `members`, `appointments`, `records`, `worktime_member`, `worktime_activity`, `worktime_appointment` |
| `year` | `appointments`, `records`, Arbeitszeit | Kalenderjahr |
| `from`, `to` | nur Arbeitszeit | Zeitraum als `JJJJ-MM-TT`, beide Grenzen einschließend |
| `format` | nur Arbeitszeit | `csv` (Standard) oder `html` für die Druckansicht |
| `member_id` | nur `worktime_member` | auf ein Mitglied einschränken |

**Zeitraum der Arbeitszeitberichte**

`from`/`to` haben Vorrang vor `year`. Ist nur eine der beiden Grenzen gesetzt, begrenzt das
Jahr die offene Seite; fehlen alle drei, gilt das laufende Jahr. `?year=2026` allein liefert
weiterhin genau das bisherige Ergebnis – bestehende Aufrufe brechen nicht.

Ein Monat ist damit kein eigener Parameter, sondern ein Zeitraum:
`&from=2026-01-01&to=2026-01-31`.

**Sitzungen zählen zu dem Zeitraum, in dem sie begonnen haben.** Eine Sitzung vom 31.01.
22:00 bis 01.02. 02:00 erscheint vollständig im Januar und gar nicht im Februar. Die Summe
über zwölf Monate entspricht deshalb der Jahressumme. Gezählt wird nur, was bestätigt
(`status = confirmed`) und beendet ist.

**Response**

- `format=csv`: CSV-Datei (`;` als Trennzeichen, UTF-8 mit BOM). Der Dateiname trägt den
  Zeitraum: `stundennachweis_2026-01.csv`, `taetigkeiten_2026.csv`,
  `termine_2026-02-01_2026-03-31.csv`.
- `format=html`: HTML-Seite mit Vereinslogo, Zeitraum, Tabelle, Summen und Fußnote, gestaltet
  über `public/css/print.css`. Enthält kein JavaScript; gedruckt wird über den Browserdialog.

**Fehler:**

| Status | Bedingung |
|---|---|
| 400 | `to` liegt vor `from`, ein Datum ist nicht `JJJJ-MM-TT`, oder der Zeitraum umfasst mehr als 24 Monate |
| 400 | unbekannter `type` |
| 405 | anderes Verfahren als `GET` |

---

### Import
Liest eine CSV-Datei ein. Ein Export dieser Anwendung ist ohne Umbau wieder importierbar —
Spaltenreihenfolge spielt keine Rolle, gelesen wird nach Namen, und unbekannte Spalten werden
übergangen.

**Endpoint:** `POST /api.php?resource=import`

**Berechtigung:** Admin

**Content-Type:** `multipart/form-data`

**Form-Data:**

| Feld | Bedeutung |
|---|---|
| `file` | CSV-Datei, höchstens 5 MB |
| `type` | `members`, `appointments`, `records`, `extract_appointments` |
| `csrf_token` | CSRF-Token |
| `create_missing_appointments` | nur bei `records`, siehe unten |
| `min_records`, `round_minutes`, `tolerance_hours` | nur bei `extract_appointments` |

**Pflichtspalten**

| `type` | verlangt |
|---|---|
| `members` | `name`, `surname` |
| `appointments` | `date`, `start_time`, `title`, `type_name` |
| `records` | `member_number`, `arrival_date_time`¹ |

¹ `arrival_date_time` darf seit 1.5.0 **leer bleiben**, wenn `appointment_date`,
`appointment_start_time` und `appointment_type` den Termin treffen — der Export dieser Anwendung
führt alle drei. Der Eintrag entsteht dann ohne Ankunftszeit. Fehlt der Terminschlüssel, bleibt
die Spalte Pflicht: Sie spannt dann das Toleranzfenster auf, über das der Termin gefunden wird.

Die früheren Namen `type` und `arrival_time` werden weiterhin akzeptiert, damit archivierte
Exporte einlesbar bleiben.

**Terminzuordnung bei `records`**

Führt die Datei `appointment_date`, `appointment_start_time` und `appointment_type` — wie der
Export dieser Anwendung —, wird der Termin darüber **exakt** bestimmt. Das ist derselbe
Schlüssel, an dem die Anwendung Termine unterscheidet: Zwei Termine derselben Art im
Toleranzfenster gelten als Konflikt, zwei verschiedene Arten am selben Abend nicht.

Fehlen die Spalten, sucht der Import den zeitlich nächsten Termin im Fenster von
`AUTO_CHECKIN_TOLERANCE_HOURS`. Das ist der richtige Weg für Daten aus einem Fremdsystem,
kann aber bei zwei Terminen am selben Abend den anderen treffen.

`create_missing_appointments=1` legt einen fehlenden Termin an — nur bei vollständigem
Schlüssel, und nur wenn die Terminart im Ziel bereits existiert. Standardmäßig aus: Ein
Import, der stillschweigend Termine anlegt, macht aus einem Tippfehler im Datum eine
Karteileiche.

**Response:**
```json
{
  "success": true,
  "imported": 25,
  "updated": 3,
  "skipped": 0,
  "appointments_created": 0,
  "errors": []
}
```

**`type=extract_appointments` schreibt nicht.** Es liest nur `arrival_date_time`, gruppiert
die Zeitstempel und **schlägt** Termine vor — für den Fall, dass eine Anwesenheitsdatei aus
einem System kommt, das gar keine Termine kennt. Die Antwort enthält `suggestions` mit Datum,
gerundeter Startzeit und Anzahl der Einträge; angelegt wird nichts.

---

## Systemeinstellungen (settings)

### Einstellungen abrufen
**Endpoint:** `GET /api.php?resource=settings`

**Berechtigung:** Admin

**Response:**
```json
{
  "settings": [
    {
      "setting_key": "org_name",
      "setting_value": "Mein Verein"
    }
  ]
}
```

---

### Client-Einstellungen (seit 1.2.4)
**Endpoint:** `GET /api.php?resource=settings&scope=client`

Liefert eine feste Auswahl an Einstellungen an **jede angemeldete Rolle**, nicht nur an
Administratoren. Die Liste steht als Whitelist im Handler und umfasst derzeit
`checkin_auto_create_appointment`, `checkin_tolerance_hours`, `station_pin_enabled` und
`station_pin_min_length` (die beiden letzteren seit 1.3.0).

Ohne `scope=client` bleibt die Ressource Administratoren vorbehalten.

**Berechtigung:** jede angemeldete Rolle

**Response:**
```json
{
  "settings": {
    "checkin_auto_create_appointment": "1",
    "checkin_tolerance_hours": "2",
    "station_pin_enabled": "1",
    "station_pin_min_length": "4"
  }
}
```

---

### Einstellung aktualisieren
**Endpoint:** `PUT /api.php?resource=settings`

**Berechtigung:** Admin

**Request:**
```json
{
  "setting_key": "org_name",
  "setting_value": "Neuer Vereinsname"
}
```

**`response_deadline_hours` (seit 1.7.0):** Vorgabe 24, zulässig eine ganze Zahl (auch als
getrimmter String) von 0 bis 720, sonst `400`. Gilt als globale Frist für Terminarten ohne eigene.

**`subgroup_label` (seit 1.8.0):** Die Bezeichnung der Untergruppen (z. B. „Register",
„Mannschaft") in Überschriften, Umschalter und Verwaltung. Wird beim Speichern normalisiert:
getrimmt, Steuerzeichen entfernt. Ein leerer oder nur aus Leerraum bestehender Wert ergibt die
Vorgabe `Untergruppe`. Länger als 30 Zeichen (nach dem Trimmen) wird mit `400`
(`{"message": "Die Bezeichnung darf höchstens 30 Zeichen haben"}`) abgewiesen.

**`holiday_region` (seit 1.11.0, FI-16):** Das Bundesland für die Berechnung der Feiertage
(Ressource [`holidays`](#feiertage-holidays) und die Vorschau von
[`appointment_series`](#terminserien-appointment_series)). Zulässig: leerer String (= nur
bundesweite Feiertage) oder eines der 16 Länderkürzel (`BW`, `BY`, `BE`, `BB`, `HB`, `HH`, `HE`,
`MV`, `NI`, `NW`, `RP`, `SL`, `SN`, `ST`, `SH`, `TH`). Alles andere `400 {"message": "Unbekanntes
Bundesland"}`.

---

### SMTP-Konfiguration
**Endpoint:** `POST /api.php?resource=settings`

**Berechtigung:** Admin

**Request (Konfiguration speichern):**
```json
{
  "action": "save_smtp_config",
  "config": {
    "smtp_host": "mail.example.com",
    "smtp_port": 587,
    "smtp_encryption": "tls",
    "smtp_username": "noreply@example.com",
    "smtp_password": "password123",
    "smtp_from_email": "noreply@example.com",
    "smtp_from_name": "Vereinsname"
  }
}
```

**Request (Test-Mail senden):**
```json
{
  "action": "test_mail",
  "recipient": "test@example.com"
}
```

---

## Datenlöschung (cleanup)

### Bestand nach Fristen bereinigen
**Endpoint:** `POST /api.php?resource=cleanup`

**Berechtigung:** Admin

Löscht endgültig. Es gibt keinen Rückgängig-Pfad und keinen Probelauf.

Drei getrennte Fristen, weil die Tabellen unterschiedlich lange gebraucht
werden. Jede Frist ist eine **ganze Zahl ab 1**; fehlt ein Feld, gilt die
gleichnamige Einstellung aus `system_settings`, sonst die Vorgabe.

| Feld | Einstellung | Vorgabe | Wirkung |
|---|---|---|---|
| `years` | `cleanup_years_records` | 3 | `records` (nach `arrival_time`), `exceptions` (nach `created_at`) und `appointment_responses` (nach dem Termindatum, seit 1.7.0) werden gelöscht |
| `years_worktime` | `cleanup_years_worktime` | 3 | `work_sessions` (nach `start_time`) und deren `work_session_log`-Einträge werden gelöscht |
| `years_audit` | `cleanup_years_audit` | 1 | Verwaiste `work_session_log`-Einträge (nach `changed_at`) werden **anonymisiert** |

**Laufende Sitzungen** (`end_time IS NULL`) fallen nie in die Frist — eine seit
Jahren offene Sitzung ist ein Fehlerfall, kein Löschfall.

**Verwaiste Einträge** der Änderungshistorie werden nicht gelöscht, sondern
anonymisiert: `changes` und `changed_by` werden auf `NULL` gesetzt, die Zeile
bleibt mit `session_id`, `action` und `changed_at` bestehen. Die Auditspur soll
weiterhin belegen, dass an dieser Stelle etwas geschah — ohne Personenbezug.
Siehe `DATENSCHUTZ.md` 10.4.

Alle Schritte laufen in **einer Transaktion**.

**Request:**
```json
{
  "years": 3,
  "years_worktime": 3,
  "years_audit": 1
}
```

**Response (200):**
```json
{
  "message": "Cleanup completed",
  "cutoff_date": "2023-09-04",
  "cutoff_date_worktime": "2023-09-04",
  "cutoff_date_audit": "2025-09-04",
  "deleted_records": 128,
  "deleted_exceptions": 4,
  "deleted_appointment_responses": 17,
  "deleted_work_sessions": 31,
  "deleted_work_session_log": 76,
  "anonymized_work_session_log": 12
}
```

**Response (400):** Eine Frist ist keine ganze Zahl ab 1.
```json
{
  "message": "Invalid retention period",
  "field": "years_worktime",
  "hint": "Whole number of years, at least 1"
}
```

---

## Logo-Upload

### Logo hochladen
**Endpoint:** `POST /api.php?resource=upload-logo`

**Berechtigung:** Admin

**Content-Type:** `multipart/form-data`

**Form-Data:**
- `logo`: Bild-Datei (JPG, PNG, max 2MB)
- `csrf_token`: CSRF-Token

**Response:**
```json
{
  "success": true,
  "logo_url": "/uploads/logo_1234567890.png"
}
```

---

## Anwesenheitsliste

### Anwesenheitsliste für Termin
**Endpoint:** `GET /api.php?resource=attendance_list&appointment_id=10`

**Response:**
```json
{
  "appointment": {
    "appointment_id": 10,
    "title": "Probe",
    "type_id": 1,
    "description": null,
    "date": "2024-03-15",
    "start_time": "19:00:00",
    "created_by": 1,
    "created_at": "2024-03-01 10:00:00",
    "is_auto_created": 0,
    "type_name": "Probe",
    "color": "#1F5FBF",
    "group_ids": "1,2"
  },
  "members": [
    {
      "member_id": 5,
      "name": "Max",
      "surname": "Mustermann",
      "member_number": "M005",
      "record_id": null,
      "arrival_time": null,
      "checkin_source": null,
      "status": null,
      "groups":    [{ "group_id": 1, "group_name": "Aktive",     "sort_order": 0 }],
      "subgroups": [{ "group_id": 9, "group_name": "Klarinette", "sort_order": 20 }]
    }
  ]
}
```

`group_ids` am Termin sind die Gruppen-IDs seiner Terminart, kommagetrennt. `record_id`,
`arrival_time`, `checkin_source` und `status` (`present`/`excused`) bleiben `null`, solange
für das Mitglied noch kein Anwesenheitseintrag zu diesem Termin vorliegt.

**Seit 1.8.0** treten je Mitglied zwei strukturierte Listen an die Stelle der früheren
Zeichenkette `groups`:
- `groups`: die Gruppen des Mitglieds, die zur Terminart gehören **und nicht als Untergruppe
  markiert sind**. Vor 1.9.0 enthielt diese Liste auch Terminart-Gruppen, die zugleich als
  Untergruppe markiert waren — eine Terminart mit direkt zugeordnetem Register führte so zu
  einer doppelten Erwartung desselben Mitglieds (einmal über `groups`, einmal über `subgroups`).
- `subgroups`: **alle** als Untergruppe markierten Gruppen des Mitglieds (z. B. das Register),
  unabhängig davon, ob die Terminart selbst nach diesen Gruppen eingeteilt ist.

Die beiden Listen sind seit 1.9.0 überschneidungsfrei: eine als Untergruppe markierte Gruppe
steht nie in `groups`, auch wenn sie zur Terminart gehört. Beide Listen sind nach `sort_order`,
bei Gleichstand nach `group_name` sortiert.

> Bis 1.8.0 zeigte dieser Abschnitt eine Antwort mit einem `attendance`-Array und Feldern
> `member_name`/`appointment_date`/`group_name`, die der Server so nie geliefert hat — geliefert
> wurde und wird ein `members`-Array mit `name`/`surname`/`date`. Korrigiert am 2026-09-16.

### Anwesenheitsliste für Mitglied
**Endpoint:** `GET /api.php?resource=attendance_list&member_id=10`

**Query-Parameter:** 
- `year`: Jahr (Standard: Aktuelles Jahr)

**Response:**
```json
{
  "member": {
    "member_id": 5,
    "name": "Max",
    "surname": "Mustermann",
    "member_number": "M005",
    "groups": "Aktive, Klarinette",
    "group_ids": "1,9"
  },
  "year": "2024",
  "appointments": [
    {
      "appointment_id": 10,
      "title": "Probe",
      "date": "2024-03-15",
      "start_time": "19:00:00",
      "description": null,
      "type_id": 1,
      "type_name": "Probe",
      "color": "#1F5FBF",
      "record_id": 42,
      "arrival_time": "2024-03-15 19:05:00",
      "checkin_source": "admin",
      "status": "present",
      "member_was_active": 1
    },
    ...
  ]
}
```

`member.groups` bleibt hier die kommagetrennte Zeichenkette aller Gruppen des Mitglieds — diese
Ansicht wurde von der Untergruppen-Gliederung (1.8.0) nicht angefasst, da sie zum Ausfüllen
einer Mitgliedskarte dient, nicht zum Gliedern einer Liste. `member_was_active` zeigt, ob das
Mitglied am Termindatum aktiv war (siehe `membership_dates`).

> Bis 1.8.0 zeigte dieser Abschnitt eine Antwort mit dem Schlüssel `member_id` statt `member`
> und einem `attendance`-Array statt `appointments`, mit Feldern (`member_name`,
> `appointment_date`), die der Server so nie geliefert hat. Korrigiert am 2026-09-16.

---

## Verfügbare Jahre

### Jahre mit Daten abrufen
**Endpoint:** `GET /api.php?resource=available_years`

**Response:**
```json
{
  "years": [2022, 2023, 2024, 2025]
}
```

---

## Mitgliedschaftszeiträume (membership_dates)

### Zeiträume abrufen
**Endpoint:** `GET /api.php?resource=membership_dates`

**Query-Parameter:**
- `member_id`: Filter nach Mitglied

**Response:**
```json
[
  {
    "membership_date_id": 1,
    "member_id": 5,
    "start_date": "2020-01-01",
    "end_date": null,
    "status": "active"
  }
]
```

---

### Zeitraum erstellen
**Endpoint:** `POST /api.php?resource=membership_dates`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "member_id": 5,
  "start_date": "2024-01-01",
  "end_date": null,
  "status": "active"
}
```

---

### Zeitraum aktualisieren
**Endpoint:** `PUT /api.php?resource=membership_dates&id=1`

**Berechtigung:** Admin/Manager

**Felder:** `start_date`, `end_date`, `status`

**`PUT` ist eine Teiländerung, keine Vollersetzung.** Geschrieben wird nur, was im Request steht.
Ein ausdrückliches `end_date: null` ist dagegen eine Angabe und öffnet den Zeitraum wieder. Ein
`PUT` auf eine unbekannte `id` ergibt `404`.

**Bis einschließlich 1.9.0 war dies eine Vollersetzung:** `start_date` wurde bedingungslos
gelesen, und ohne `status` fiel der Zeitraum auf `active` zurück — wer nur das Enddatum
nachtrug, führte einen beendeten Zeitraum danach wieder als laufend (OI-69).

---

### Zeitraum löschen
**Endpoint:** `DELETE /api.php?resource=membership_dates&id=1`

**Berechtigung:** Admin/Manager

**Seit 1.9.1:** Fehlt `id`, antwortet der Endpunkt mit `400 {"message": "id ist erforderlich"}`;
trifft `id` keinen Datensatz, mit `404`. Zuvor meldete beides `200` (OI-56).

---

## Fehlerbehandlung

### Typische Fehler-Responses

**Authentifizierung fehlgeschlagen:**
```json
{
  "message": "Unauthorized"
}
```

**Ungültiger CSRF-Token:**
```json
{
  "message": "Invalid CSRF token"
}
```

**Fehlende Berechtigung:**
```json
{
  "message": "Admin access required"
}
```

**Rate Limit überschritten:**
```json
{
  "message": "Rate limit exceeded",
  "retry_after": 60
}
```

**Ressource nicht gefunden:**
```json
{
  "message": "Endpoint not found"
}
```

---

## Best Practices

### Sicherheit
1. **HTTPS verwenden** in Produktion
2. **API-Tokens sicher aufbewahren** (Umgebungsvariablen)
3. **Token-Ablaufdatum prüfen** vor Verwendung
4. **Rate Limits beachten** bei automatisierten Anfragen

### Performance
1. **Pagination** Erfolgt Client-Seitig
2. **Caching** für statische Daten auf Jahresbasis
3. **Jahr-Filter verwenden** bei Anwesenheitsabfragen
4. **Batch-Operationen** statt Einzelaufrufen

### Fehlerbehandlung
1. **HTTP Status Codes auswerten**
2. **Retry-Logic** bei 429/500 Fehlern
3. **Logging** von API-Fehlern implementieren
4. **Timeout-Handling** (empfohlen: 30s)

---

## Beispiel-Implementierungen

### JavaScript (Fetch)
```javascript
const API_BASE = 'https://your-domain.com/api/api.php';
const API_TOKEN = 'your_api_token_here';

async function getMembers() {
  const response = await fetch(`${API_BASE}?resource=members`, {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/json'
    }
  });
  
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }
  
  return await response.json();
}

async function checkIn(memberId, appointmentId, totpCode) {
  const response = await fetch(`${API_BASE}?resource=totp_checkin`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      member_id: memberId,
      appointment_id: appointmentId,
      totp_code: totpCode,
      source: 'web'
    })
  });
  
  return await response.json();
}
```

---

### Python
```python
import requests

API_BASE = 'https://your-domain.com/api/api.php'
API_TOKEN = 'your_api_token_here'

headers = {
    'Authorization': f'Bearer {API_TOKEN}',
    'Content-Type': 'application/json'
}

def get_members():
    response = requests.get(
        f'{API_BASE}?resource=members',
        headers=headers
    )
    response.raise_for_status()
    return response.json()

def check_in(member_id, appointment_id, totp_code):
    data = {
        'member_id': member_id,
        'appointment_id': appointment_id,
        'totp_code': totp_code,
        'source': 'iot'
    }
    
    response = requests.post(
        f'{API_BASE}?resource=totp_checkin',
        headers=headers,
        json=data
    )
    
    return response.json()
```

---

### Arduino/ESP32
```cpp
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

const char* API_BASE = "https://your-domain.com/api/api.php";
const char* API_TOKEN = "your_api_token_here";

bool checkIn(int memberId, int appointmentId, String totpCode) {
  HTTPClient http;
  
  String url = String(API_BASE) + "?resource=totp_checkin";
  http.begin(url);
  
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", "Bearer " + String(API_TOKEN));
  
  StaticJsonDocument<200> doc;
  doc["member_id"] = memberId;
  doc["appointment_id"] = appointmentId;
  doc["totp_code"] = totpCode;
  doc["source"] = "nfc";
  
  String jsonData;
  serializeJson(doc, jsonData);
  
  int httpCode = http.POST(jsonData);
  
  if (httpCode == 200) {
    String payload = http.getString();
    DynamicJsonDocument response(1024);
    deserializeJson(response, payload);
    
    return response["success"];
  }
  
  http.end();
  return false;
}
```

---

## Support & Lizenz

**GitHub:** https://github.com/mcmaier/EhrenSache

**Lizenz:** AGPL-3.0 (gemeinnützig) / Kommerzielle Lizenz verfügbar

**Support:** Issues auf GitHub erstellen
