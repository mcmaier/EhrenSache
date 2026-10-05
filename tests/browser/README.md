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
API-Antwort wird um 800 ms verzögert, die erste Ansicht muss nach höchstens zwei Stufen stehen.
Gemessen wird der Mehraufwand gegenüber einem Lauf ohne Verzögerung, je drei Läufe und davon
jeweils das Minimum, Grenze +2000 ms (2,5 × Verzögerung; zwei Stufen liegen bei etwa +1600 ms,
drei bei mindestens +2400 ms). Jeder Lauf startet über `about:blank`, damit Chrome keine
Formularwerte wiederherstellt; das Dashboard gilt erst als fertig, wenn `loadProfile()` nach allen
Abrufen den PIN-Hinweis geschrieben hat. Dazu
prüft es die einmalige Wiederholung bei 503 (nur GET), dass `me` mit 503
nicht abmeldet und „Erneut versuchen“ hilft, und dass `me` mit 401 zur Anmeldemaske führt.

    node tests/browser/startup-chain.mjs

Braucht in `tests/config.php` die Konten `admin` und `user`. `ES_BASE_URL` überschreibt
`base_url`, etwa für eine Gegenprobe gegen einen älteren Stand.

# Version im Pfad (OI-120)

`cache-reuse.mjs` ruft Anmeldeseite und Dashboard je zweimal auf. Beim ersten Mal müssen alle
CSS- und JS-Adressen den Abschnitt `v<Version>/` tragen und keine Datei darf unter zwei Adressen
laden; beim zweiten Mal darf keine CSS- oder JS-Anfrage den Server erreichen (alles aus dem
Browser-Speicher, `immutable`). Ohne Request-Interception, weil sie den Cache abschaltet.

    node tests/browser/cache-reuse.mjs

Braucht in `tests/config.php` das Konto `admin`. `ES_BASE_URL` überschreibt `base_url`.

**Falle beim Entwickeln:** Dieselbe Regel gilt lokal. Im Feature-Branch bleibt die Version gleich,
nach einer Änderung an CSS oder JS liefert der Browser also weiter die alte Datei aus seinem
Speicher — ein normales Neuladen hilft nicht, nur ein harter Reload oder „Disable cache“ in den
Entwicklerwerkzeugen. Die Skripte hier starten mit frischem Profil und sind nicht betroffen.
