# EhrenSache – Station

**Virtuelle Station (Kiosk):** Ein ausgemustertes Tablet im Proberaum zeigt den rotierenden
Stations-Code (QR und Ziffern) für die Check-in-App und nimmt Stempel per Mitgliedsnummer + PIN
entgegen — Anwesenheit sowie Arbeitszeit (Start, Pause, Ende).

## Einrichtung

1. **Gerät anlegen:** Dashboard → Geräte → Neues Gerät → Typ „Virtuelle Station (Kiosk)".
   Häkchen „zeigt den Stations-Code" setzen, wenn das Tablet den QR-Code anzeigen soll.
   Nach dem Speichern öffnet sich das Gerät im Bearbeiten-Modus; dort den API-Token kopieren.
   Der Gerätename ist Pflicht — er wird als Ort in jeden Stempel geschrieben.
2. **PIN-Anmeldung freischalten:** Dashboard → Einstellungen → „Stations-Anmeldung (PIN)".
3. **Mitglieder:** PIN im Profil (Mitglieder mit Konto) oder im Mitglieds-Modal (Verwalter).
   Jedes Mitglied braucht eine eindeutige Mitgliedsnummer.
4. **Tablet:** `https://deine-domain/…/station/` im Browser öffnen, Token eingeben.
   Anschließend „Zum Startbildschirm hinzufügen" (Android Chrome ⋮-Menü, iPadOS Teilen-Symbol).

## Kiosk-Modus

Eine Web-App kann den Browser nicht einsperren. Was die Station selbst tut: Vollbild,
Bildschirm an (Wake Lock), kein Navigationspfad nach außen, Rückfall zum Ruhebild nach
30 Sekunden (einstellbar). Den Rest liefert das Tablet:

- **Android:** Einstellungen → Sicherheit → „App-Pinning" (Bildschirm anheften), dann die
  installierte Station anheften. Alternativ ein Kiosk-Browser aus dem Play Store.
- **iPadOS:** Einstellungen → Bedienungshilfen → „Geführter Zugriff" aktivieren, in der
  Station dreimal die Seitentaste drücken.
- Bildschirm-Timeout des Geräts auf „nie", falls der Browser den Wake Lock nicht unterstützt.

**Einstellungen der Station:** 5 Sekunden auf die Uhr drücken, API-Token eingeben.

## Voraussetzungen

- **HTTPS** für Installation und Wake Lock (lokal genügt `localhost`).
- Dauerhafte Verbindung zum Server: Ohne Verbindung zeigt die Station keinen Code und nimmt
  keine Stempel an — bewusst, siehe `docs/OPEN-ITEMS.md` („Kein Offline-Betrieb").
- Server und Datenbank teilen sich eine Uhr: Alle Stempel der Station tragen die Datenbankzeit.

## Sicherheit

- Das TOTP-Secret verlässt den Server nicht; die Station holt nur den jeweils gültigen Code.
- Der Token der Station kann **nur** die Ressource `station` aufrufen. Ohne PIN eines
  Mitglieds ist er wertlos.
- Nummer und PIN werden nie gespeichert, nur der Token (localStorage) und die Ruhezeit.
- Sperre: 5 Fehlversuche je Mitgliedsnummer, 30 je Station, jeweils 15 Minuten.
- Eine PIN ist weitergebbar. Stempel dieser Quelle sind in allen Auswertungen als
  „Station (PIN)" gekennzeichnet — siehe `DATENSCHUTZ.md` Abschnitt 10.7.

## Dateien

```
public/station/
  ├── index.html
  ├── manifest.json
  ├── service-worker.js
  ├── css/style.css
  └── js/
      ├── app.js
      └── qrcode.js      (qrcode-generator 1.4.4, Kazuhiko Arase, MIT-Lizenz)
```

## Lizenz & Copyright

- **Gemeinnützige Nutzung:** [AGPL-3.0](../../LICENSE)
- **Kommerzielle Nutzung:** [Kommerzielle Lizenz](../../COMMERCIAL-LICENSE.md)

Copyright (c) 2026 Martin Maier
