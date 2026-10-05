# Klickdurchgang durch das Dashboard

Prüft unter der Content-Security-Policy (OI-17), dass jeder Knopf mit
`data-action` noch etwas tut: Anmeldung als Admin, jede Sektion öffnen, jeden
sichtbaren Knopf auslösen. Gemeldet werden CSP-Verstöße, Laufzeitfehler und
Aktionen, die niemand registriert hat. Datenändernde Aktionen werden
übersprungen (Liste `SKIP` im Skript).

Zusätzlich erreicht werden die Anwesenheitsmodi (Filter `#filterAppointment` und
`#filterMember` werden gesetzt) und alle Aktionen des Kalender-Popups (das Popup
wird je Aktion neu geöffnet, gesucht wird im angezeigten sowie im Vor- und Folgemonat).

Die Auskunft am Ende hat vier Listen: ausgelöst, übersprungen, „gesehen, aber
nie ausgelöst“ (stand im DOM, wurde aber weder ausgelöst noch bewusst übersprungen,
etwa Knöpfe in Schritten, die der Durchgang nicht erreicht) und „registriert, aber
nie gesehen“ (nie im DOM, oft datenabhängig). Nicht erreichte Schritte des Durchgangs
stehen als „Hinweis“. Keine der Listen ist ein Fehler.

Nur Entwicklungswerkzeug — nicht Teil von `php tests/run.php`, nicht im
Installationspaket (`tests/` ist `export-ignore`).

## Einmalig

    cd tests/browser && npm install

Chrome: `CHROME_BIN` setzen oder `npx @puppeteer/browsers install chrome@stable`.
Ohne beides wird ein installiertes Google Chrome unter `C:/Program Files` verwendet.

## Aufruf

    node tests/browser/click-through.mjs
    node tests/browser/click-through.mjs --section=mitglieder

Liest `base_url` und das Admin-Konto aus `tests/config.php`. Gegen eine
Installation laufen lassen, deren Datenbank eine Kopie ist — geöffnet wird
viel, gespeichert nichts, aber Filter und Ansichten wechseln.

# Startkette (OI-121)

`startup-chain.mjs` zählt die Wartestufen beim Start von Check-in-App und Dashboard: Jede
API-Antwort wird um 500 ms verzögert, die erste Ansicht muss nach höchstens zwei Stufen stehen
(gemessen wird der Mehraufwand gegenüber einem Lauf ohne Verzögerung, Grenze +1250 ms). Dazu
prüft es die einmalige Wiederholung bei 503 (nur GET), dass `me` mit 503
nicht abmeldet und „Erneut versuchen“ hilft, und dass `me` mit 401 zur Anmeldemaske führt.

    node tests/browser/startup-chain.mjs

Braucht in `tests/config.php` die Konten `admin` und `user`. `ES_BASE_URL` überschreibt
`base_url`, etwa für eine Gegenprobe gegen einen älteren Stand.
