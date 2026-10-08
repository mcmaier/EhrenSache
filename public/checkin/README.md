[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0) [![Commercial License](https://img.shields.io/badge/Commercial-License%20Available-green.svg)](../../COMMERCIAL-LICENSE.md)

# EhrenSache - Checkin

## 📱 Progressive Web App für Vereinsverwaltung

**Moderne Anwesenheitserfassung für ehrenamtliche Organisationen**

Diese PWA ermöglicht Mitgliedern einen schnellen Check-In über Mobilgeräte.

---

## 🚀 Installation

Die PWA ist teil des EhrenSache-Pakets und  ist im selben Webspace wie die Webseite installiert, da sie die gleiche API und Datenbank nutzt.

```
/EhrenSache/public/checkin/
  ├── index.html
  ├── manifest.json
  ├── service-worker.js
  ├── css/
  │   └── style.css
  └── js/
      └── app.js
```
---

## 📲 App auf Smartphone installieren

> Eine Progressive Web App ist keine native App, die installiert wird.
> Es ist eher wie eine Webseite, die im Schnellzugriff gespeichert wird.

### Android (Chrome)

1. Öffne `http://deine-url/checkin/` im Chrome Browser
2. Tippe auf das ⋮ Menü (oben rechts)
3. Wähle "Zum Startbildschirm hinzufügen"
4. App erscheint auf dem Homescreen

### iOS (Safari)

1. Öffne `http://deine-url/checkin/` in Safari
2. Tippe auf das Teilen-Symbol (Quadrat mit Pfeil)
3. Scrolle runter und wähle "Zum Home-Bildschirm"
4. App erscheint auf dem Homescreen

---

## 🔑 Login

Sobald du dich im Web-Dashboard registriert hast und von einem Admin freigeschaltet wurdest, kannst du dich in der Checkin-App mit den gleichen Nutzerdaten anmelden.

---

## ✨ Features

### ✅ Was funktioniert

- **Auto-Login**: Login wird sicher gespeichert
- **Check-In**: Ein Klick für Zeiterfassung
- **Anwesenheiten**: Letzte 10 Records anzeigen
- **Installierbar**: Wie native App nutzbar
- **Responsive**: Optimiert für alle Bildschirmgrößen
- **QR-Code Scanner**: Schneller Termin-Check-In
- **Statistiken**: Anwesenheitsquote, geleistete Arbeitszeit des Jahres und letzte Einträge
- **Arbeitszeit korrigieren und nachtragen**: Eigene abgeschlossene Sitzungen über den Verlauf
  korrigieren, vergessene Sitzungen nachtragen — beides geht erneut in die Freigabe
- **Termine zu- und absagen**: Tab „Termine" mit Zusage, Unsicher, Absage und Bemerkung;
  erscheint nur, wenn es kommende Termine mit Rückmeldung gibt. Ohne Netz gesperrt
- **Offene Anträge** (nur Verwalter, nur mit eingeschalteter Anwesenheit): Im Tab „Liste“ schaltet
  ein Umschalter zwischen „Anwesenheit“ und „Offene Anträge (n)“ um. Die Ansicht zeigt alle
  offenen Entschuldigungen und Zeitanträge, geteilt in „Kommende Termine“ und „Vergangene
  Termine“ — unabhängig vom Check-in-Fenster. Zugeklappt steht nur die Art des Antrags, die
  Begründung erst aufgeklappt; Zeitanträge zeigen dort die erfasste Ankunft. Genehmigen und
  Ablehnen wie in der Anwesenheitsliste (Ablehnen mit Rückfrage, offline gesperrt); eigene
  Anträge sind gesperrt, solange ein zweites aktives Verwalterkonto existiert
- **Rückmelde-Link** (`checkin/#rueckmeldung=<Termin-ID>`): Das Kalender-Abo (FI-8) setzt ihn
  an kommende Termine mit Rückmeldung. Die App liest das Fragment beim Laden, meldet ggf. erst an
  und öffnet dann den Tab „Termine“ mit aufgeklappter Karte des Termins; danach entfernt sie das
  Fragment aus der Adresse. Der Link trägt kein Token, das Fragment erreicht den Server nicht.
  Gibt es die Karte nicht (Termin vorbei oder unbekannt), bleibt der Tab ohne Fehlermeldung offen

### ⚠️ Limitierungen

- **API erfordert Internet**: Check-In benötigt Online-Verbindung
- **Kein Background Sync**: Keine Offline-Queue für Check-Ins

---

## 🐛 Troubleshooting

### "Login nicht möglich"
- Ist der Account bereits freigeschaltet?
- API-Token im Web-Dashboard neu generieren
- Gespeicherten Token löschen: Browser-Cache leeren

### "Keine Verbindung zur API"

- API-URL in `js/app.js` prüfen
- Die App muss auf demselben Ursprung (Domain, Protokoll, Port) laufen wie die API — die API
  sendet keine CORS-Header, eine Check-in-App auf fremder Domain kann sie nicht abfragen
- Browser Console (F12) für Fehler checken

### Service Worker lädt nicht

- HTTPS verwenden
- Browser-Cache leeren
- Service Worker in DevTools manuell unregistrieren

### App nicht installierbar

- HTTPS erforderlich (außer localhost)
- `manifest.json` korrekt eingebunden
- Icons vorhanden

---

## 📊 Erweiterungsmöglichkeiten

### Geplante Features (Optional)

- [ ] Push-Notifications bei Check-In
- [ ] Offline-Queue für Check-Ins
- [ ] Dark Mode

---

## 📄 Lizenz & Copyright

Entwickelt für gemeinnützige Organisationen, wie z.B. Musikvereine, Sportvereine, ... 

- **Gemeinnützige Nutzung:** [AGPL-3.0](../../LICENSE)
- **Kommerzielle Nutzung:** [Kommerzielle Lizenz](../../COMMERCIAL-LICENSE.md)

Copyright (c) 2026 Martin Maier

Made with ❤️ for the volunteer community
