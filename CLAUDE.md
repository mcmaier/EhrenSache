# CLAUDE.md – EhrenSache

Anwesenheitserfassung, Termin und Mitgliederverwaltung für ehrenamtliche Organisationen (Musikvereine, Sportvereine etc.).
Statistische Auswertung der Anwesenheit und Pünktlichkeit nach Terminen und Gruppen.

## Lizenz

Duales Lizenzmodell:
- **AGPL-3.0** für gemeinnützige Organisationen (kostenlos, Änderungen müssen veröffentlicht werden)
- **Kommerzielle Lizenz** für kommerzielle Nutzung

## Tech-Stack

| Schicht    | Technologie                        |
|------------|------------------------------------|
| Backend    | PHP 8+ (Vanilla), MySQL 5.7+       |
| Frontend   | Vanilla HTML + JS (ES6 Modules)    |
| API        | REST, JSON                         |
| Auth       | Session (Web), Bearer Token (API/PWA), TOTP (Geräte) |
| IoT        | ESP32, TOTP-Verifikation via REST  |
| PWA        | Service Worker, Web App Manifest   |

## Projektstruktur

```
EhrenSache/
├── private/                    # NICHT öffentlich zugänglich
│   ├── config/
│   │   ├── config.php          # DB-Zugangsdaten (nicht im Repo!)
│   │   ├── config_example.php  # Template für config.php
│   │   ├── install.lock        # Existiert nach erfolgreicher Installation
│   │   └── mail_config.php
│   ├── handlers/               # API-Logik, je ein File pro Ressource
│   │   ├── members.php
│   │   ├── appointments.php
│   │   ├── records.php
│   │   ├── exceptions.php
│   │   ├── users.php
│   │   ├── membership_dates.php
│   │   ├── member_groups.php
│   │   ├── appointment_types.php
│   │   ├── statistics.php
│   │   ├── export.php
│   │   ├── import.php
│   │   ├── settings.php
│   │   ├── attendance_list.php
│   │   ├── auto_checkin.php
│   │   ├── totp_checkin.php
│   │   ├── regenerate_token.php
│   │   ├── change_password.php
│   │   └── user_mailer.php
│   ├── helpers/
│   │   ├── auth.php            # isAdmin(), isManager(), Session/Token-Prüfung
│   │   ├── rate_limiter.php
│   │   ├── totp.php
│   │   ├── utils.php
│   │   └── mailer.php
│   └── setup/
│       └── ehrensache_db.sql   # Datenbankschema
│
└── public/                     # Web-Root der Domain zeigt hierher!
    ├── index.html              # Hauptanwendung (Dashboard)
    ├── login.html
    ├── favicon.ico
    ├── .htaccess
    ├── api/
    │   └── api.php             # Einziger API-Einstiegspunkt (Router)
    ├── js/
    │   ├── theme.js            # Wird früh geladen (Branding, Farben)
    │   ├── app.js              # Haupt-Einstiegspunkt
    │   └── modules/            # ES6-Module, je ein File pro Feature
    │       ├── api.js          # fetch-Wrapper, Token-Handling
    │       ├── cache.js        # Caching-Logik (localStorage)
    │       ├── ui.js           # Allgemeine UI-Helpers, Navigation
    │       ├── members.js
    │       ├── appointments.js
    │       ├── records.js
    │       ├── exceptions.js
    │       ├── users.js
    │       ├── statistics.js
    │       ├── import_export.js
    │       └── ...
    ├── css/
    │   ├── main.css
    │   └── login.css
    ├── assets/
    │   └── logo-default.png
    ├── checkin/                # PWA für Mobile Check-in
    │   ├── index.html
    │   ├── manifest.json
    │   ├── service-worker.js
    │   └── .htaccess
    └── install/                # Setup-Wizard (nach Installation gesperrt via .htaccess)
        └── index.php
```

## API-Architektur

Einziger Einstiegspunkt: `public/api/api.php`

Request-Parameter: `?resource=<name>&id=<id>`

**Ablauf in api.php:**
1. Headers setzen
2. Includes laden (config, helpers, alle handlers)
3. Request-Variablen lesen (`$resource`, `$id`, `$request_method`)
4. Session starten (nur ohne Bearer Token)
5. Rate Limiting prüfen
6. Öffentliche Endpoints (ping, login, register, password_reset)
7. Authentifizierung (Session oder Bearer Token → `$authUserId`, `$authUserRole`)
8. CSRF-Prüfung (für POST/PUT/DELETE via Session)
9. Routing via `switch($resource)` → Handler-Funktion aufrufen

**Authentifizierungsmethoden:**
- `Authorization: Bearer <token>` Header
- `X-API-Key: <token>` Header
- `?api_token=<token>` Query-Parameter (Geräte)
- Session (Web-Browser)

## Rollen

| Rolle   | Berechtigungen                                      |
|---------|-----------------------------------------------------|
| admin   | Vollzugriff, Systemeinstellungen, Benutzerverwaltung |
| manager | Mitglieder/Termine bearbeiten, keine Systemkonfiguration |
| user    | Eigenes Profil, Check-in, Statistik, Ausnahmenanträge |
| device  | Nur TOTP-Check-in oder Biometrie-Device (IoT-Geräte)                      |

Hilfsfunktionen in `private/helpers/auth.php`: `isAdmin()`, `isManager()`, `isDevice()`

## Caching (Frontend)

- Speicher: `localStorage`
- Schlüssel-Schema: `appointments_<year>`, `records_<year>`, `members_<year>`, `groups`
- Termine/Anwesenheiten werden jahresweise gecacht
- Mitglieder/Gruppen global gecacht
- Invalidierung über Event-System bei Datenänderungen
- Ziel: Reduktion der API-Anfragen

## Datenbank-Kernentitäten

- `users` – Login-Accounts (können mit member verknüpft sein)
- `members` – Vereinsmitglieder (Stammdaten)
- `member_groups` – Gruppen/Abteilungen
- `appointments` – Termine mit Typ und Gruppenzuordnung
- `appointment_types` – Terminarten
- `records` – Anwesenheitserfassungen
- `exceptions` – Entschuldigungen, Zeitkorrekturen
- `membership_dates` – Aktiv/Inaktiv-Zeiträume pro Mitglied
- `system_settings` – Konfiguration (Organisation, Farben, Logo, Pagination)
- `rate_limit_log`, `email_verification_tokens`, `password_reset_tokens`

## Sicherheit

- Prepared Statements für alle DB-Queries (kein SQL-Injection-Risiko)
- CSRF-Token für alle mutierende Requests via Session
- Rate Limiting: 100 Requests/Minute pro IP+User
- Session-Timeout: 3600 Sekunden
- TOTP für standortgebundene Geräte-Check-ins
- CSP-Header, HttpOnly + SameSite Cookies
- `private/` nie öffentlich erreichbar

## Lokale Entwicklung

```bash
# XAMPP/MAMP: Projekt nach htdocs/EhrenSache/
# Web-Root auf public/ zeigen lassen
# Datenbank aus private/setup/ehrensache_db.sql erstellen
# private/config/config_example.php → config.php kopieren und anpassen
# http://localhost/EhrenSache/public/install aufrufen
```

## Offene Aufgaben (ToDo)

- Testing und Validierung
- Exceptions nur erlauben, wenn kein Record vorliegt
- Beim Anlegen eines Records eines Mitglieds auf parallele Exceptions prüfen (Fehlerbehandlung definieren!)

## Geplante Features

- Terminplanung mit Zusage/Absage
- Google-Kalender-Integrations
- PWA-Sortierung umschaltbar

## Konventionen

- PHP: Vanilla, keine Frameworks, Prepared Statements
- JS: ES6 Module (`import`/`export`), kein Build-Step, kein Framework
- Neue API-Ressource: Handler in `private/handlers/<name>.php` + `case` in `api.php` Switch
- Neues Frontend-Feature: Modul in `public/js/modules/<name>.js`
- Sprache: Deutsch (UI, Kommentare), Englisch (Code/Variablen)

## Testing

- Verifizierung wenn möglich selbst durchführen
- Credentials für Testsystem sind in `@test_credentials.md` referenziert
- Wenn erforderlich Screenshots erstellen und in Ordner `temporary_screenshots` speichern
- Wenn keine Verifizierung möglich, Benutzer anleiten manuelle Verifizierung durchzuführen
