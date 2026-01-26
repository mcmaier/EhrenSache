[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0) [![Commercial License](https://img.shields.io/badge/Commercial-License%20Available-green.svg)](COMMERCIAL-LICENSE.md)

# EhrenSache

**Moderne Anwesenheitserfassung für ehrenamtliche Organisationen**

Entwickelt für gemeinnützige Organisationen, wie z.B. Musikvereine, Sportvereine, ... 
Kostenlos unter AGPL-3.0 nutzbar.

> **💼 Kommerziell nutzen?** Siehe [Lizenzierung](#-lizenzierung)

## Features

**Anwesenheit ist EhrenSache!** 

Und jetzt einfach und überall erfassbar ohne Zettel und Stift. Egal ob jeder sich eigenverantwortlich anmeldet oder der Schriftführer die Anwesenheit prüft. EhrenSache erfasst Anwesenheit und Entschuldigen inklusive nachträglicher Korrekturmöglichkeit. 

Jeder kann seine Statistik einsehen und prüfen, ob alles erfasst wurde. Inklusive Ankunftszeit, für alle die Pünkltichkeit belohnen wollen.


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
- CSRF-Schutz für Dateneingaben
- Sichere Session-Verwaltung mit HttpOnly und SameSite Cookies
- Input-Validierung auf Client- und Server-Seite
- Sichere Datei-Upload-Verifikation

## Technologie-Stack

**Backend:**
- PHP 8+ (Vanilla)
- MySQL 5.7+
- REST API Architektur

**Frontend:**
- Vanilla HTML + JavaScript
- CSS3 (Grid, Flexbox)

**IoT-Integration (WIP):**
- (geplant) TOTP-Device für QR-Checkin via App
- (geplant) Fingerprint-Scanner für Biometrie-Checkin


## Installation

### Voraussetzungen
- Webserver mit PHP 8+ und MySQL 5.7+
- SSL-Zertifikat (für PWA und sichere Authentifizierung)
- Schreibrechte für Upload-Verzeichnisse

### Setup

1. Repository klonen oder Paket downloaden:
```bash
git clone https://github.com/mcmaier/EhrenSache.git
```
2. Komplettes Verzeichnis in Webspace hochladen.
> [!IMPORTANT]
> Die Web-Root der Domain muss auf den Ordner **EhrenSache/public** zeigen!

3. Neue Datenbank erstellen.

4. Setup ausführen (Angenommen, Ehrensache ist in der Subdomain ehrensache installiert):
```
https://ehrensache.meine-domain.de/
--> Leitet automatisch zu Setup weiter
```

## Erste Schritte

Während der Installation wird ein Admin-Account erstellt.

1. Gruppenverwaltung --> Mindestens eine Benutzergruppe anlegen
2. Gruppenverwaltung --> Eine Terminart erstellen und Benutzergruppe zuweisen
3. Mitglieder --> Erstellen oder aus CSV Importieren

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
- Einsehen und Bearbeiten aller Mitglieder und Termine
- Teilnehmerverwaltung bei Terminen
- Keine Systemkonfiguration oder Rollenverwaltung

### Für Mitglieder

**Check-in Web:**
1. Login → Dashboard
2. Termine und Anwesenheiten einsehen
3. Statistik einsehen
4. Anträge erstellen

**Check-in Mobile (PWA):**
1. App auf Smartphone installieren (Browser-Menü → "Zum Startbildschirm")
2. Öffnen der App
3. QR-Code scannen an TOTP-Station oder manueller Check-in 
4. Korrekturantrag stellen

**Check-in QR-Code:**
1. QR-Code am Veranstaltungsort scannen (z.B. mit Smartphone-Kamera)
2. Link öffnet direkt den Check-in
3. Automatische Erfassung

**Check-in NFC/IoT (geplant)**
- NFC-Tag an NFC-Station halten
- RFID-Karte an Lesegerät
- Fingerabdruck an Fingerprint-Reader
- Automatische Erfassung durch verknüpftes Gerät

**Ausnahmen beantragen:**
1. Dashboard → Meine Anträge
2. Neue Ausnahme → Typ wählen (Zeitkorrektur, Entschuldigt)
3. Datum angeben und Begründung
4. Absenden → Wartet auf Genehmigung durch Admin/Manager

## IoT-Integration

**geplant:**
- QR Code Station mit TOTP Code
- NFC Station für TOTP Code
- Fingerprint Authentifizierungsgerät

### Weitere Geräte

Das System unterstützt beliebige IoT-Geräte über die REST API mit API Token:
- TOTP-Endpoint: `/api/api.php&resource=totp_checkin`
- Auth-Endpoint: `/api/api.php&resource=auto_checkin`
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

### Endpoints (Doku unvollständig!)

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

- **HTTPS erforderlich** für Produktivbetrieb
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
├── private/              # Interne Dateien
|   ├── config/           # Config Dateien
|   ├── handlers/         # API Endpunkt-Handler
|   └── ...             
└── public/               # Öffentlich zugänglich
    ├── checkin/          # PWA
    ├── api/              # REST API Endpoints
    ├── js/               # Frontend JavaScript
    ├── css/              # Stylesheets
    ├── ...             
    └── index.html        # Hauptanwendung
```

### Caching-System

Das System verwendet ein Jahr-basiertes Caching:
- Termine/Anwesenheiten: Pro Jahr gecacht
- Mitglieder/Gruppen: Global gecacht
- Invalidierung bei Änderungen über Event-System
- Cache-Keys im localStorage


## Support

Bei Fragen oder Problemen bitte ein Issue auf GitHub erstellen.

---

## 📋 Lizenzierung

EhrenSache ist unter einer **dualen Lizenz** verfügbar:

### 🆓 Kostenlos für gemeinnützige Organisationen

Gemeinnützige Vereine, Musikvereine, Sportvereine und andere ehrenamtliche 
Organisationen können EhrenSache **kostenlos** unter der [AGPL-3.0-Lizenz](LICENSE) nutzen.



**Das bedeutet:**
- ✅ Kostenlose Nutzung
- ✅ Quellcode einsehbar und anpassbar
- ✅ Selbst-Hosting möglich
- ⚠️ Änderungen müssen veröffentlicht werden (AGPL-Bedingung)

### 💼 Kommerzielle Lizenz

Für kommerzielle Nutzung (SaaS-Anbieter, Systemhäuser, Unternehmen) ist eine 
**[Kommerzielle Lizenz](COMMERCIAL-LICENSE.md)** erforderlich.

**Wann brauche ich eine kommerzielle Lizenz?**
- Du hostest EhrenSache als kostenpflichtige Dienstleistung
- Du integrierst EhrenSache in ein kommerzielles Produkt
- Du möchtest Änderungen NICHT veröffentlichen
- Du verkaufst EhrenSache-basierte Lösungen

**Lizenzmodelle:**  
Wir bieten flexible Einmal- und Jahreslizenzen, abgestimmt auf Ihr 
Geschäftsmodell. Kontaktieren Sie uns für ein individuelles Angebot.

## ©️ Copyright

Copyright (c) 2026 Martin Maier

Made with ❤️ for the volunteer community
