# CLAUDE.md – EhrenSache

Anwesenheitserfassung, Termin- und Mitgliederverwaltung für ehrenamtliche Organisationen
(Musikvereine, Sportvereine etc.). Statistische Auswertung von Anwesenheit und Pünktlichkeit
nach Terminen und Gruppen, seit 1.2.0 zusätzlich Arbeitszeiterfassung.

**Version:** siehe `version.json` · **Arbeitsbranch:** `dev` · **Release-Branch:** `main`

---

## Dokumentenkarte

Diese Datei ist der Einstieg. Details stehen in eigenen Dokumenten — dort nachlesen,
statt sie hier zu duplizieren:

| Dokument | Inhalt | Im Repo? |
|---|---|---|
| `README.md` | Installation, Systemvoraussetzungen, Nutzersicht | ja |
| `API.md` | Vollständige REST-Referenz aller Ressourcen | ja |
| `CHANGELOG.md` | Versionshistorie | ja |
| `SECURITY.md` | Meldeweg für Lücken, unterstützte Versionen, Offenlegungsgrenze | ja |
| `DATENSCHUTZ.md` | DSGVO-Anforderungen, Löschfristen, Betroffenenrechte | ja |
| `DISCLAIMER.md`, `LICENSE`, `COMMERCIAL-LICENSE.md` | Rechtliches | ja |
| `public/checkin/README.md` | PWA für mobilen Check-in | ja |
| `docs/OPEN-ITEMS.md` | **Offene Entscheidungen, Restarbeiten, bewusst Verworfenes** | ja |
| `docs/FEATURE-IDEAS.md` | **Ideen für künftige Funktionen — unverbindlich, nicht geplant** | ja |
| `docs/testplan.md` | Manueller Testplan nach Feature-Bereichen | ja |
| `docs/superpowers/specs/` | Design-Spezifikationen je Feature | ja |
| `docs/superpowers/plans/` | Umsetzungspläne je Feature (Prozessprotokolle) | nein (ignoriert) |
| `test_credentials.md` | Zugangsdaten Testsystem | nein (ignoriert) |

Alles ab `docs/OPEN-ITEMS.md` ist zwar im Repository, aber über `.gitattributes`
(`export-ignore`) aus dem ZIP-Download ausgenommen — sichtbar für Beitragende, nicht im
Installationspaket eines Vereins.

**Vor dem Start einer Aufgabe:** `docs/OPEN-ITEMS.md` lesen. Dort steht, was bereits
entschieden und was bewusst verworfen wurde — das verhindert doppelte Diskussionen.

**Sicherheitsfunde:** `docs/OPEN-ITEMS.md` ist öffentlich. Ungepatchte Lücken, die ohne
vorherigen Zugang ausnutzbar sind oder eine Rechteausweitung erlauben, dort **nicht**
eintragen — Ablauf in `SECURITY.md`.

---

## Lizenz

Duales Lizenzmodell:
- **AGPL-3.0** für gemeinnützige Organisationen (kostenlos, Änderungen müssen veröffentlicht werden)
- **Kommerzielle Lizenz** für kommerzielle Nutzung

Jede PHP- und JS-Datei trägt den Copyright-Header (Vorlage: `public/js/app.js`) — bei neuen
Dateien übernehmen.

## Tech-Stack

| Schicht    | Technologie                        |
|------------|------------------------------------|
| Backend    | PHP 8+ (Vanilla), MySQL 5.7+ / MariaDB 10.4+ |
| Frontend   | Vanilla HTML + JS (ES6 Modules), kein Build-Step |
| API        | REST, JSON                         |
| Auth       | Session (Web), Bearer Token (API/PWA), TOTP (Geräte) |
| IoT        | ESP32, TOTP-Verifikation via REST  |
| PWA        | Service Worker, Web App Manifest   |

## Projektstruktur

```
EhrenSache/
├── version.json                # Version, Build-Datum – Quelle für private/helpers/version.php
├── private/                    # NICHT öffentlich zugänglich
│   ├── config/
│   │   ├── config.php          # DB-Zugangsdaten + Tabellenpräfix (nicht im Repo!)
│   │   ├── config_example.php  # Template für config.php
│   │   ├── install.lock        # Existiert nach erfolgreicher Installation
│   │   └── mail_config.php
│   ├── handlers/               # API-Logik, je ein File pro Ressource
│   │   ├── members.php, appointments.php, records.php, exceptions.php
│   │   ├── users.php, membership_dates.php, member_groups.php
│   │   ├── appointment_types.php, activity_types.php
│   │   ├── work_sessions.php   # Arbeitszeiterfassung
│   │   ├── statistics.php, export.php, import.php, settings.php
│   │   ├── attendance_list.php, my_data.php
│   │   ├── auto_checkin.php, totp_checkin.php
│   │   └── regenerate_token.php, change_password.php, user_mailer.php
│   ├── helpers/
│   │   ├── auth.php            # login(), requireRole(), isAdmin(), isDevice(), CSRF
│   │   ├── worktime.php        # Fachlogik Arbeitszeit (Dauer, Validierung, Statistik)
│   │   ├── member_activity.php # Aktiv/Inaktiv-Zeiträume
│   │   ├── migrations.php      # Ausführung der Migrationskette
│   │   ├── version.php         # Liest version.json
│   │   ├── branding.php, mail_template.php, mailer.php
│   │   └── rate_limiter.php, totp.php, utils.php
│   ├── migrations/
│   │   ├── manifest.php        # Kette from → to; einzige Datei, die beim Anlegen geändert wird
│   │   └── <von-version>.php   # z. B. 1.1.3.php mit migrate_1_1_3()
│   ├── email_templates/        # base.html + Vorlagen (Aktivierung, Reset, Verifikation)
│   ├── setup/
│   │   └── ehrensache_db.sql   # Schema, Tabellennamen als {PREFIX}<name>
│   └── uploads/
│
└── public/                     # Web-Root der Domain zeigt hierher!
    ├── index.html              # Hauptanwendung (Dashboard)
    ├── login.html, reset_password.php, verify_email.php
    ├── favicon.ico, robots.txt, .htaccess
    ├── api/
    │   └── api.php             # Einziger API-Einstiegspunkt (Router)
    ├── js/
    │   ├── config.js           # Automatische API-Basispfad-Erkennung
    │   ├── theme.js            # Wird früh geladen (Branding, Farben)
    │   ├── app.js              # Einstiegspunkt, Redirect-Loop-Schutz, debug-Logger
    │   ├── login.js
    │   └── modules/            # api, auth, ui, members, appointments, records,
    │                           # exceptions, users, devices, profile, management,
    │                           # settings, statistics, worktime, import_export, utils
    ├── css/
    │   ├── variables.css, reset.css, main.css, responsive.css, utilities.css, login.css
    │   ├── components/         # buttons, cards, forms, modals, tables, badges,
    │   │                       # calendar, pagination, toast
    │   └── sections/           # sidebar, content, settings, statistics
    ├── assets/                 # logo-default.png etc.
    ├── uploads/                # Hochgeladenes Logo etc.
    ├── checkin/                # PWA für Mobile Check-in (eigener Service Worker)
    ├── install/index.php       # Setup-Wizard (nach Installation via .htaccess gesperrt)
    └── update/index.php        # Update-Wizard, fährt die Migrationskette

tests/                          # PHP-Testharness, siehe Abschnitt Testing
docs/                           # Specs, Pläne, offene Punkte – NICHT im Repo
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

**Ressourcen:** members, appointments, records, exceptions, users, membership_dates,
member_groups, appointment_types, activity_types, work_sessions, statistics, available_years,
auto_checkin, totp_checkin, regenerate_token, change_password, export, import, import_logs,
settings, upload-logo, attendance_list, activate_user, user_status, cleanup, my_data,
session_info, version, ping — Details in `API.md`.

**Authentifizierungsmethoden:**
- `Authorization: Bearer <token>` Header
- `X-API-Key: <token>` Header
- `?api_token=<token>` Query-Parameter (Geräte)
- Session (Web-Browser)

## Datenbank

**Tabellenpräfix:** Alle Tabellen tragen ein konfigurierbares Präfix. Im Schema steht
`{PREFIX}`, zur Laufzeit liefert `$database->table('members')` den vollen Namen.
Tabellennamen nie hart codieren.

**Kernentitäten**

| Tabelle | Inhalt |
|---|---|
| `users` | Login-Accounts (optional mit member verknüpft) |
| `members` | Vereinsmitglieder (Stammdaten) |
| `member_groups`, `member_group_assignments` | Gruppen/Abteilungen und Zuordnung |
| `membership_dates` | Aktiv/Inaktiv-Zeiträume pro Mitglied |
| `appointments` | Termine mit Typ und Gruppenzuordnung |
| `appointment_types`, `appointment_type_groups` | Terminarten und ihre Gruppen |
| `records` | Anwesenheitserfassungen |
| `exceptions` | Entschuldigungen, Zeitkorrekturen |
| `activity_types` | Tätigkeitsarten der Arbeitszeiterfassung |
| `work_sessions` | Arbeitszeitsitzungen (Start/Ende, Nachweis) |
| `work_session_log` | Änderungshistorie zu work_sessions |
| `system_settings` | Konfiguration (Organisation, Farben, Logo, Pagination, Arbeitszeit) |
| `schema_version` | Aktueller Migrationsstand |
| `import_logs`, `rate_limits`, `email_verification_tokens`, `password_reset_tokens` | Betrieb |

**Migrationen:** Kette aus `from`/`to`-Schritten in `private/migrations/manifest.php`.
Neue Migration = neue Datei `<from-version>.php` mit der Signatur
`fn(PDO $pdo, string $prefix, string $configPath): array{log: string[], warnings: string[]}`
plus **ein** neuer Manifest-Eintrag, dessen `from` dem `to` des Vorgängers entspricht.
Ausgeführt wird sie über `public/update/index.php`.

## Rollen

| Rolle   | Berechtigungen                                      |
|---------|-----------------------------------------------------|
| admin   | Vollzugriff, Systemeinstellungen, Benutzerverwaltung |
| manager | Mitglieder/Termine bearbeiten, keine Systemkonfiguration |
| user    | Eigenes Profil, Check-in, Statistik, Ausnahmenanträge |
| device  | Nur TOTP-Check-in oder Biometrie-Device (IoT-Geräte) |

Hilfsfunktionen in `private/helpers/auth.php`: `isAdmin()`, `isAdminOrManager()`, `isDevice()`,
`requireAdmin()`, `requireAdminOrManager()`, `requireRole()`, `requireDevice()`.

Manager sehen bewusst **alle** Datensätze ohne Gruppengrenze — konsistent über records,
exceptions, statistics und work_sessions (siehe `docs/OPEN-ITEMS.md`).

## Caching (Frontend)

- Speicher: **In-Memory-Objekt `dataCache` in `public/js/modules/ui.js`** — kein localStorage,
  kein `cache.js`-Modul. Der Cache ist nach einem Reload leer.
- Globale Schlüssel: `users`, `devices`, `groups`, `types`, `availableYears`, `userData`
- Jahresabhängig (`dataCache.<key>[year]`): `members`, `appointments`, `records`, `exceptions`,
  `workSessions`
- TTL: 10 Minuten (`CACHE_TTL` in `ui.js`), geprüft über `isCacheValid(key, year)`
- Invalidierung nach Mutationen über `invalidateCache(key, year)`
- `sessionStorage` wird nur für den Redirect-Loop-Schutz in `app.js` verwendet
- Ziel: Reduktion der API-Anfragen

## Sicherheit

- Prepared Statements für alle DB-Queries (kein SQL-Injection-Risiko)
- CSRF-Token für alle mutierende Requests via Session
- Rate Limiting: 100 Requests/Minute pro IP+User (`private/helpers/rate_limiter.php`)
- Session-Timeout: 1800 Sekunden, gesetzt in `public/api/api.php`
- TOTP für standortgebundene Geräte-Check-ins
- HttpOnly + SameSite Cookies, `Secure` nur über HTTPS (`public/api/api.php`)
- `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` in `public/.htaccess`
- **Keine CSP** — die Oberfläche nutzt Inline-Handler, siehe OI-17 in `docs/OPEN-ITEMS.md`
- `private/` nie öffentlich erreichbar

## Lokale Entwicklung

```bash
# XAMPP/MAMP: Projekt nach htdocs/EhrenSache/
# Web-Root auf public/ zeigen lassen
# Datenbank aus private/setup/ehrensache_db.sql erstellen
# private/config/config_example.php → config.php kopieren und anpassen
# http://localhost/EhrenSache/public/install aufrufen
```

## Testing

```bash
php tests/run.php
```

```bash
php tests/run.php worktime_api
```

- Harness: `tests/lib/harness.php` (Assertions, Summary), `tests/lib/api.php` (HTTP-Aufrufe)
- Suites in `tests/suites/`: api_selftest, assets, harness_selftest, migrations,
  worktime_api, worktime_unit
- Einzelprüfungen gegen die Datenbank: `tests/db/verify_*.php`
- Konfiguration: `tests/config.php` aus `tests/config.example.php` kopieren (ignoriert)
- Manueller Testplan: `docs/testplan.md`
- Verifizierung wenn möglich selbst durchführen
- Credentials für Testsystem sind in `@test_credentials.md` referenziert
- Wenn erforderlich Screenshots erstellen und in Ordner `temporary_screenshots` speichern
- Wenn keine Verifizierung möglich, Benutzer anleiten manuelle Verifizierung durchzuführen

## Konventionen

- PHP: Vanilla, keine Frameworks, Prepared Statements, `declare(strict_types=1)` in neuen Dateien
- JS: ES6 Module (`import`/`export`), kein Build-Step, kein Framework
- Neue API-Ressource: Handler in `private/handlers/<name>.php` + `case` in `api.php` Switch
  + Abschnitt in `API.md`
- Neues Frontend-Feature: Modul in `public/js/modules/<name>.js`
- Neues CSS: in `components/` oder `sections/` einsortieren, Farben nur über `variables.css`
- Schemaänderung: Migration anlegen **und** `private/setup/ehrensache_db.sql` nachziehen
- Sprache: Deutsch (UI, Kommentare), Englisch (Code/Variablen)
- Versionssprung: `version.json` und `CHANGELOG.md` gemeinsam pflegen

## Offene Aufgaben und geplante Features

Gepflegt in **`docs/OPEN-ITEMS.md`** (offene Entscheidungen, Restarbeiten, bewusst Verworfenes)
und `docs/superpowers/plans/`. Diese Datei führt bewusst keine zweite Liste — sie veraltet sonst.
