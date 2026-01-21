[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Commercial License](https://img.shields.io/badge/Commercial-License%20Available-green.svg)](COMMERCIAL-LICENSE.md)

# EhrenSache

**Moderne Anwesenheitserfassung für ehrenamtliche Organisationen**

Entwickelt für Musikvereine, Sportvereine und andere gemeinnützige 
Organisationen. Kostenlos unter AGPL-3.0 nutzbar.

> **💼 Kommerziell nutzen?** Siehe [Lizenzierung](#-lizenzierung)

## Features

### Kernfunktionen
- **Mehrstufiges Rollensystem**: Admin, Manager und Benutzer mit differenzierten Berechtigungen
- **Flexible Zeiterfassung**: Unterstützung für Web-Dashboard, Mobile PWA, QR-Codes, NFC-Tags und IoT-Geräte
- **Terminverwaltung**: Planung von Terminen mit Gruppenzuordnung und Teilnehmerverwaltung
- **Ausnahmenverwaltung**: Erfassung von Abwesenheiten, Urlaub und Sonderregelungen
- **Gruppenverwaltung**: Organisation von Mitgliedern in verschiedenen Gruppen

### Technische Highlights
- **Sichere Authentifizierung**: Session-basiert für Web, Token-basiert für Geräte
- **TOTP-Standortverifikation**: Zeitbasierte Einmalpasswörter für sichere Check-ins
- **Intelligentes Caching**: 95% Reduktion der API-Anfragen durch Jahr-basiertes Caching
- **Progressive Web App**: Installation auf Mobilgeräten möglich
- **Responsive Design**: Optimiert für Desktop, Tablet und Smartphone

### Sicherheit
- XSS-Schutz durch Content Security Policy
- SQL-Injection-Prävention mit Prepared Statements
- CSRF-Schutz für alle Formulare
- Sichere Session-Verwaltung mit HttpOnly und SameSite Cookies
- Input-Validierung auf Client- und Server-Seite
- Sichere Datei-Upload-Verifikation

## Technologie-Stack

**Backend:**
- PHP 7.4+ (Vanilla, keine Frameworks)
- MySQL 5.7+
- REST API Architektur

**Frontend:**
- Vanilla JavaScript (ES6 Module)
- CSS3 (Grid, Flexbox)
- Service Worker für PWA-Funktionalität

**IoT-Integration (WIP):**
- ESP32 Mikrocontroller
- PN532 NFC Module
- Displays
- RFID/Fingerprint Reader Support

## Installation

### Voraussetzungen
- Webserver mit PHP 7.4+ und MySQL 5.7+
- SSL-Zertifikat (für PWA und sichere Authentifizierung)
- Schreibrechte für Upload-Verzeichnisse

### Setup

1. Repository klonen:
```bash
git clone https://github.com/mcmaier/EhrenSache.git
cd EhrenSache
```

2. Datenbank erstellen und SQL-Schema importieren:
```bash
mysql -u username -p < database/schema.sql
```

3. Setup ausführen:

4. Upload-Verzeichnis mit Schreibrechten versehen:
```bash
chmod 755 uploads/
```

5. `.htaccess` für Apache konfigurieren (bereits enthalten)

### Erster Admin-Account

Nach der Installation über das Registrierungsformular anmelden. Der erste Benutzer erhält automatisch Admin-Rechte.

## Bedienung

### Für Administratoren

**Dashboard-Zugriff:**
1. Login mit Admin-Credentials
2. Zugriff auf alle Verwaltungsfunktionen über das Hauptmenü

**Mitglieder verwalten:**
- Navigation: Dashboard → Mitglieder
- Funktionen: Anlegen, Bearbeiten, Rollenzuweisung, Gruppenzuordnung
- Profile enthalten: Stammdaten, Gruppenzugehörigkeit, Berechtigungen

**Termine erstellen:**
1. Dashboard → Termine → Neuer Termin
2. Ausfüllen: Name, Beschreibung, Datum/Uhrzeit, Termintyp
3. Gruppen zuweisen (optional: Nur bestimmte Gruppen dürfen teilnehmen)

**Anwesenheit prüfen:**
- Dashboard → Termine → Termin auswählen → Anwesenheitsliste
- Filterung nach Termin oder Mitglied
- Export-Funktion für Berichte

**Geräte verwalten:**
- Dashboard → Geräte
- Token generieren für neue IoT-Geräte
- TOTP-Secrets für standortbasierte Verifikation
- Geräte aktivieren/deaktivieren

### Für Manager

Manager haben eingeschränkten Zugriff:
- Einsehen aller Mitglieder und Termine
- Teilnehmerverwaltung bei Terminen
- Keine Systemkonfiguration oder Rollenverwaltung

### Für Mitglieder

**Check-in Web:**
1. Login → Dashboard
2. Statistik einsehen
3. Anträge erstellen
4. Optional: TOTP-Code eingeben (falls Standortverifikation aktiv)

**Check-in Mobile (PWA):**
1. App auf Smartphone installieren (Browser-Menü → "Zum Startbildschirm")
2. Öffnen der App
3. QR-Code scannen oder manueller Check-in

**Check-in QR-Code:**
1. QR-Code am Veranstaltungsort scannen (z.B. mit Smartphone-Kamera)
2. Link öffnet direkt den Check-in
3. Automatische Erfassung

**Check-in NFC/IoT:**
- NFC-Tag an NFC-Station halten
- RFID-Karte an Lesegerät
- Fingerabdruck an Fingerprint-Reader
- Automatische Erfassung durch verknüpftes Gerät

**Ausnahmen beantragen:**
1. Dashboard → Meine Ausnahmen
2. Neue Ausnahme → Typ wählen (Urlaub, Krankheit, etc.)
3. Zeitraum angeben und Begründung
4. Absenden → Wartet auf Genehmigung durch Admin/Manager

## IoT-Integration

### NFC-Station (ESP32)

**Hardware:**
- ESP32 DevKit
- PN532 NFC Module (I2C)
- OLED Display SSD1306
- Spannungsversorgung 5V

**Konfiguration:**
1. ESP32 mit EhrenSache-Firmware flashen
2. WiFi-Credentials über Serial eingeben
3. Im Dashboard: Gerät registrieren und Token generieren
4. TOTP-Secret konfigurieren
5. NFC-Tags mit Mitglieder-IDs beschreiben

**Funktionsweise:**
- Mitglied hält NFC-Tag an Station
- ESP32 liest Mitglieds-ID
- Generiert aktuellen TOTP-Code
- Sendet Check-in an API mit Token-Auth
- Feedback über OLED Display

### Weitere Geräte

Das System unterstützt beliebige IoT-Geräte über die REST API:
- Endpoint: `/api/api.php&resource=auto_checkin`
- Authentifizierung: Bearer Token
- Parameter: `member_id`, `appointment_id`, `totp_code`, `source`

## API-Dokumentation

### Authentifizierung

**Web-Login:**
```
POST /api/api.php&resource=login
Body: { "email": "email", "password": "pass" }
Response: Session-Cookie
```

**Device-Auth:**
```
Header: Authorization: Bearer {token}
```

### Endpoints (Auswahl)

**Check-in:**
```
POST /api/api.php&resource=totp_checkin
Body: {
  "appointment_id": 123,
  "member_id": 456,
  "source": "nfc",
  "totp_code": "123456"
}
```

**Termine abrufen:**
```
GET /api/api.php&resource=appointments&year=2025
Response: Array of appointments
```

**Mitglieder abrufen:**
```
GET /api/api.php&resource=members
Response: Array of members with groups
```

## Sicherheitshinweise

- **HTTPS zwingend erforderlich** für Produktivbetrieb
- Regelmäßige Updates der Abhängigkeiten
- Starke Passwörter für Admin-Accounts
- TOTP-Secrets sicher aufbewahren
- Device-Tokens niemals im Code hardcoden
- Backup-Strategie für Datenbank implementieren

## Entwicklung

### Lokale Entwicklungsumgebung
```bash
# XAMPP oder ähnliches installieren
# Projekt nach htdocs/ kopieren
# Datenbank erstellen
# In Browser: http://localhost/EhrenSache
```

### Code-Struktur
```
EhrenSache/
├── api/              # REST API Endpoints
├── js/               # Frontend JavaScript (ES6 Module)
├── css/              # Stylesheets
├── uploads/          # Profilbilder
├── database/         # SQL Schema
├── esp32/            # IoT Firmware
└── index.html        # Hauptanwendung
```

### Caching-System

Das System verwendet ein intelligentes Jahr-basiertes Caching:
- Termine/Anwesenheiten: Pro Jahr gecacht
- Mitglieder/Gruppen: Global gecacht
- Invalidierung bei Änderungen über Event-System
- Cache-Keys im localStorage


## Support

Bei Fragen oder Problemen bitte ein Issue auf GitHub erstellen.

---

## 📋 Lizenzierung

EhrenZeit ist unter einer **dualen Lizenz** verfügbar:

### 🆓 Kostenlos für gemeinnützige Organisationen

Gemeinnützige Vereine, Musikvereine, Sportvereine und andere ehrenamtliche 
Organisationen können EhrenZeit **kostenlos** unter der 
[AGPL-3.0-Lizenz](LICENSE) nutzen.

**Das bedeutet:**
- ✅ Kostenlose Nutzung
- ✅ Quellcode einsehbar und anpassbar
- ✅ Selbst-Hosting möglich
- ⚠️ Änderungen müssen veröffentlicht werden (AGPL-Bedingung)

### 💼 Kommerzielle Lizenz

Für kommerzielle Nutzung (SaaS-Anbieter, Systemhäuser, Unternehmen) ist eine 
**kommerzielle Lizenz** erforderlich.

**Wann brauche ich eine kommerzielle Lizenz?**
- Du hostest EhrenZeit als kostenpflichtige Dienstleistung
- Du integrierst EhrenZeit in ein kommerzielles Produkt
- Du möchtest Änderungen NICHT veröffentlichen
- Du verkaufst EhrenZeit-basierte Lösungen


## 📄 Lizenz & Copyright

Copyright (c) 2026 Martin Maier

- **Gemeinnützige Nutzung:** [AGPL-3.0](LICENSE)
- **Kommerzielle Nutzung:** [Kommerzielle Lizenz](COMMERCIAL-LICENSE.md)

Made with ❤️ for the volunteer community