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

# Kalender-Feed mit ical.js (FI-8)

`ics-check.mjs` parst den echten Kalender-Feed mit [ical.js](https://github.com/kewisch/ical.js),
dem Parser von Thunderbird — ohne Browser, alles lokal. Ablauf: Anmeldung als `user` über
`resource=auth`, Schalter `calendar_feed_enabled` als `admin` einschalten, einen Probetermin mit
Umlauten (23:15 bis 00:45, Terminart eines Termins des Mitglieds) anlegen, Abo erzeugen, den vom
Server ausgegebenen Link abrufen. Am Ende (auch nach einem Fehler) werden Probetermin und Abo
gelöscht und der Schalter auf den vorherigen Wert zurückgestellt. Hat `user` schon ein Abo, bricht
das Skript ab, statt es zu ersetzen.

Geprüft werden: `VERSION:2.0` und `PRODID`; mindestens ein `VEVENT`; je Termin eine eindeutige
`UID`, `DTSTART`/`DTEND` mit `TZID=Europe/Berlin`, Ende nach Beginn, Dauer unter 24 h, `SUMMARY`;
die Umrechnung nach UTC ergibt +1 h im Winter und +2 h im Sommer (EU-Regel, letzter Sonntag im
März/Oktober) — das gelingt nur, wenn ical.js den `VTIMEZONE`-Block liest; Titel (ohne `✓ `/`✗ `/`? `),
Ort, Datum und Uhrzeit stimmen mit `GET appointments` desselben Kontos überein; der Probetermin
kommt mit Umlauten unversehrt an und endet am Folgetag; Zeilen höchstens 75 Oktette, Zeilenende
CRLF (RFC 5545, 3.1).

    node tests/browser/ics-check.mjs      (oder: cd tests/browser && npm run ics-check)

Braucht in `tests/config.php` die Konten `admin` und `user` (mit verknüpftem Mitglied) und die
Tabelle `calendar_feeds`. `ES_BASE_URL` überschreibt `base_url`. Rückgabewert 1 bei jedem Fehler.

### pwa-offline.mjs

`pwa-offline.mjs` prüft den App-Rahmen der Check-in-PWA (OI-43, Stufe 1) in einem echten Chrome mit
frischem Profil: Anmeldung als `user`, Service Worker übernimmt (ohne automatisches Neuladen), Speicher
`checkin-<Version>` existiert. Offline lädt die App aus dem Speicher (`./`, `index.html`, Rückmelde-Link)
und der Startbildschirm meldet „Server nicht erreichbar.“. Danach wird eine neue Version simuliert: Die
Hinweisleiste erscheint, „Neu laden“ lädt genau einmal neu, und es bleibt nur der neue Speicher.

    node tests/browser/pwa-offline.mjs      (oder: cd tests/browser && npm run pwa-offline)

Das Skript überschreibt `public/checkin/service-worker.js` vorübergehend (andere `VERSION`) und schreibt
die Datei im `finally` zurück; `git status` danach prüfen. `base_url` in `tests/config.php` (oder
`ES_BASE_URL`) muss auf denselben Arbeitsbaum zeigen, in dem das Skript liegt. Braucht das Konto `user`. Solange das Skript läuft, würde jeder andere Browser, der denselben
Arbeitsbaum öffnet, die Probe-Version installieren. Ein abgebrochener Lauf wird beim nächsten Start
erkannt (das Skript bricht ab, wenn die `VERSION` in der Datei nicht zu `version.json` passt);
behoben mit `git checkout public/checkin/service-worker.js`.
Rückgabewert 1 bei jedem Fehler.
