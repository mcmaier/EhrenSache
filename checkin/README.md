# Check-In PWA - Installationsanleitung

## 📱 Progressive Web App für Vereinsverwaltung

Diese PWA ermöglicht Mitgliedern einen schnellen Check-In über Mobilgeräte.

---

## 🚀 Installation

### 1. Dateien kopieren

Kopiere den kompletten `checkin-app` Ordner in dein Webserver-Verzeichnis:

```
/htdocs/checkin-app/
  ├── index.html
  ├── manifest.json
  ├── service-worker.js
  ├── css/
  │   └── style.css
  └── js/
      └── app.js
```

### 2. API-URL anpassen

Öffne `js/app.js` und passe die API-URL an (Zeile 4):

```javascript
const API_BASE = 'http://deine-domain.de/members/api/api.php';
```

**Wichtig:** In Production HTTPS verwenden!

### 3. Icons erstellen (optional)

Erstelle zwei App-Icons:
- `icon-192.png` (192x192 Pixel)
- `icon-512.png` (512x512 Pixel)

Oder nutze einen Icon-Generator wie: https://favicon.io/

---

## 📲 App auf Smartphone installieren

### Android (Chrome)

1. Öffne `http://deine-url/checkin-app/` im Chrome Browser
2. Tippe auf das ⋮ Menü (oben rechts)
3. Wähle "Zum Startbildschirm hinzufügen"
4. App erscheint auf dem Homescreen

### iOS (Safari)

1. Öffne `http://deine-url/checkin-app/` in Safari
2. Tippe auf das Teilen-Symbol (Quadrat mit Pfeil)
3. Scrolle runter und wähle "Zum Home-Bildschirm"
4. App erscheint auf dem Homescreen

---

## 🔑 API Token erhalten

Users finden ihren API-Token im Web-Dashboard:

1. Im Dashboard anmelden
2. Zu "Mitglieder" → eigenes Profil navigieren
3. Token kopieren (unter der Mitglieder-Tabelle)

**Sicherheit:** Token wie ein Passwort behandeln!

---

## ✨ Features

### ✅ Was funktioniert

- **Auto-Login**: Token wird sicher gespeichert
- **Check-In**: Ein Klick für Zeiterfassung
- **Anwesenheiten**: Letzte 20 Records anzeigen
- **Offline-UI**: App funktioniert ohne Internet (nur UI)
- **Installierbar**: Wie native App nutzbar
- **Responsive**: Optimiert für alle Bildschirmgrößen

### ⚠️ Limitierungen

- **API erfordert Internet**: Check-In benötigt Online-Verbindung
- **Kein Background Sync**: Keine Offline-Queue für Check-Ins
- **HTTP in Entwicklung OK**: Production benötigt HTTPS für volle PWA-Features

---

## 🔧 Entwicklung & Testing

### Lokaler Test

```bash
# Im checkin-app Ordner
python -m http.server 8000

# Oder mit PHP
php -S localhost:8000
```

Öffne: `http://localhost:8000`

### Chrome DevTools

1. F12 → Application Tab
2. Manifest prüfen
3. Service Worker Status checken
4. Lighthouse Audit durchführen

---

## 🐛 Troubleshooting

### "Token ungültig"

- Token im Web-Dashboard neu generieren
- Token komplett kopieren (keine Leerzeichen)
- Gespeicherten Token löschen: Browser-Cache leeren

### "Keine Verbindung zur API"

- API-URL in `js/app.js` prüfen
- CORS-Einstellungen in `api.php` prüfen
- Browser Console (F12) für Fehler checken

### Service Worker lädt nicht

- HTTPS verwenden (in Production)
- Browser-Cache leeren
- Service Worker in DevTools manuell unregistrieren

### App nicht installierbar

- HTTPS erforderlich (außer localhost)
- `manifest.json` korrekt eingebunden
- Icons vorhanden
- Lighthouse Audit für Details

---

## 🔒 Sicherheit (Production)

### HTTPS aktivieren

```apache
# .htaccess
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### Token-Speicherung

Token wird Base64-codiert im LocalStorage gespeichert.
**Nicht ideal für höchste Sicherheit**, aber praktikabel für diesen Use-Case.

**Bessere Alternative (optional):**
- Token in httpOnly Cookie speichern
- Session-basierter Login statt Token-Speicherung

---

## 📊 Erweiterungsmöglichkeiten

### Geplante Features (Optional)

- [ ] Push-Notifications bei Check-In
- [ ] Offline-Queue für Check-Ins
- [ ] QR-Code Scanner für Termin-Check-In
- [ ] Dark Mode
- [ ] Statistiken (Anwesenheitsquote)
- [ ] Biometrische Authentifizierung

---

## 📝 Lizenz & Support

Erstellt für Vereinsverwaltung-System
Bei Fragen: Dokumentation im Projekt prüfen

---

## ⚡ Quick Reference

**Login:** Token aus Web-Dashboard
**Check-In:** Ein Klick → fertig
**Offline:** UI funktioniert, API benötigt Internet
**Update:** Service Worker cached automatisch Updates
