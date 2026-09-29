# Klickdurchgang durch das Dashboard

Prüft unter der Content-Security-Policy (OI-17), dass jeder Knopf mit
`data-action` noch etwas tut: Anmeldung als Admin, jede Sektion öffnen, jeden
sichtbaren Knopf auslösen. Gemeldet werden CSP-Verstöße, Laufzeitfehler und
Aktionen, die niemand registriert hat. Datenändernde Aktionen werden
übersprungen (Liste `SKIP` im Skript).

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
