# Demo-Datengenerator

Füllt eine EhrenSache-Datenbank reproduzierbar mit dem fiktiven
**Musikverein Musterhausen** — als Bildmaterial für die Werbeseite und als Reset-Bestand
der öffentlichen Demo.

> **Achtung:** Das Skript **leert** alle Fachtabellen, einschließlich `users`. Niemals gegen
> eine produktive Installation ausführen.

## Aufruf

```
php private/demo/seed.php [Optionen]
```

| Option | Wirkung |
|---|---|
| `--yes` | ohne Rückfrage ausführen (für den Cron-Job) |
| `--seed=<int>` | Zufallssaat, Vorgabe `20260908` |
| `--reference-date=<Y-m-d>` | Stichtag aller Zeitpunkte, Vorgabe: heute |
| `--password=<klartext>` | Passwort der drei Konten, Vorgabe `demo2025` |
| `--quiet` | unterdrückt den Abschlussbericht; zusammen mit `--yes` auch die Zielanzeige (die Sicherheitsabfrage bleibt) |

Ohne `--yes` nennt das Skript Datenbank, Präfix und die Zeilenzahl jeder Tabelle, die es
leeren wird, und verlangt die Eingabe `LOESCHEN`. Aufruf nur über die Kommandozeile; über
den Webserver bricht es mit 403 ab.

## Voraussetzung

`schema_version` ≥ **1.3.0**. Geprüft wird 1.3.0 und nicht 1.3.1, weil die Migration
`1.3.0.php` das Schema nicht ändert — sie schließt nur die Kette bis zur Version aus
`version.json`. Eine korrekt aktualisierte 1.3.1-Installation trägt daher 1.3.0 als letzten
Stempel; eine Prüfung auf 1.3.1 würde sie fälschlich abweisen.

## Reihenfolge im Alltag

1. `php tests/run.php` — die Suiten legen eigene Daten an und lassen Reste zurück
2. `php private/demo/seed.php` — Bestand herstellen
3. Screenshots aufnehmen

Umgekehrt stehen die Rückstände der Testsuiten auf dem Bild.

Ein Testlauf **nach** dem Generator ist dagegen unschädlich: Seit dem 09.09.2026 hängt die
Testsuite an einem eigenen Mitgliedskonto (`user2@`), sodass sie die laufende Sitzung von
`user@` nicht mehr anfasst. Vorher teilten sich beide ein Mitglied, und jeder Testlauf
beendete den Timer, den das PWA-Bild zeigen soll.

## Aufbau

| Datei | Zuständigkeit |
|---|---|
| `plan.php` | **rein**: berechnet den Bestand als Arrays. Keine Datenbank, keine Uhr außer dem übergebenen Stichtag. Geprüft von `tests/suites/demo_seed_unit.php` (80 Tests). |
| `seed.php` | **Ein-/Ausgabe**: Optionen, Sicherheitsabfrage, Leeren, `INSERT`, Geheimnisse. Geprüft von `tests/suites/demo_seed_cli.php` (19 Tests). |

Die Trennung ist der Grund, warum es überhaupt Tests gibt: Eine Suite, die den Schreibteil
ausführt, würde die Entwicklungsdatenbank leeren. Deshalb enthält `seed.php` beim Einbinden
keinen laufenden Code — der Ablauf startet nur, wenn die Datei direkt aufgerufen wird.

## Reproduzierbarkeit

Gleicher Saat und gleicher Stichtag ergeben denselben Bestand — mit drei benannten Ausnahmen:

- **Geheimnisse.** `pin_hash` und `password_hash` tragen je Lauf ein neues Salz, `api_token`
  und `totp_secret` stammen aus `random_bytes`.
- **Die laufende Sitzung.** Sie beginnt 95 Minuten vor dem *Zeitpunkt* des Laufs, nicht vor
  dem Stichtag — eine „laufende" Sitzung, die erst in Stunden beginnt, zeigte in der PWA eine
  negative Laufzeit und ließe sich nicht beenden. `buildDemoPlan()` nimmt dafür eine Uhrzeit
  entgegen, die `seed.php` aus der Systemuhr setzt.
- **`pin_updated_at`** trägt den Zeitpunkt des Laufs.

Alles Übrige hängt allein an Saat und Stichtag.

Die PINs im Klartext ausgeben:

```
php -r "require 'private/demo/plan.php'; foreach (buildMembers(new DemoRandom(20260908))['members'] as \$m) { if (\$m['pin'] !== null) { echo \$m['member_number'], ' ', \$m['pin'], PHP_EOL; } }"
```

## Konten

| Konto | Rolle |
|---|---|
| `admin@musterhausen.example` | Administrator |
| `manager@musterhausen.example` | Manager |
| `user@musterhausen.example` | Benutzer, verknüpft mit Mitglied **M001** — trägt die laufende Sitzung, hiermit wird die PWA fotografiert |
| `user2@musterhausen.example` | Benutzer, verknüpft mit Mitglied **M002** — nur für die Testsuiten, ohne laufende Sitzung |

Passwort für alle vier: der Wert von `--password`, Vorgabe `demo2025`.

**Diese Konten stehen auch in `tests/config.php`**, dort `user2@` als Rolle `user`. Der
Generator leert `users` — wer die Zugänge hier ändert, muss sie dort ändern, sonst scheitert
der gesamte Testlauf an der Anmeldung.

Die Trennung ist Absicht: Ein gemeinsames Konto hiesse, dass jeder Testlauf den Timer beendet,
den das Bild zeigen soll — und dass die Timer-Tests an einer Sitzung scheitern, die sie nicht
erwarten.

Geräte: `Probenraum-Station` (Kiosk) und `Proberaum` (TOTP). Die Token stehen im Dashboard
unter Geräte und werden bei jedem Lauf neu gewürfelt.

## Was der Bestand enthält

40 Mitglieder in vier Gruppen (Aktive, Jugend, Vorstandschaft, Ehrenmitglieder), davon drei
mit beendeter Mitgliedschaft und 15 mit Stations-PIN · vier Terminarten und rund 105 Termine
über zwölf Monate rückwärts und vier Wochen vorwärts · rund 2400 Anwesenheiten mit
gestreuter Quote und Ankunftszeit · 25 Anträge, davon fünf offen · sechs Tätigkeitsarten mit
Gruppenbindung · 120 Arbeitszeiten (106 bestätigt, 10 eingereicht, 4 abgelehnt) samt
Auditspur.

## Regeln, die der Bestand einhält

Sie stehen als Tests fest, weil jede von ihnen einmal verletzt war:

- Anwesenheiten und Anträge liegen **innerhalb des Mitgliedschaftszeitraums**.
- Ein Mitglied erscheint nur zu Terminen, zu denen seine **Gruppe** erwartet wird.
- Eine **Entschuldigung** gibt es nur zu Terminen ohne Anwesenheitseintrag, eine
  **Zeitkorrektur** nur zu solchen mit.
- Eine **Arbeitszeit** nutzt nur eine Tätigkeit, die über `activity_type_groups` an eine
  Gruppe des Mitglieds gebunden ist. Ohne diesen Eintrag wäre die Tätigkeit in der Oberfläche
  unsichtbar und nicht buchbar (`activity_types` filtert per `EXISTS`,
  `memberMayUseActivity()` weist ab).
- Ein gesetzter **Terminbezug** einer Arbeitszeit zeigt nur auf einen Termin, zu dem das
  Mitglied erwartet wurde.
- **Auftrittstitel** folgen dem Monat, nicht der Listenposition.
- Genau **eine** Sitzung läuft, sie gehört Mitglied M001 und nutzt eine Tätigkeit **ohne**
  Nachweispflicht — sonst ließe sie sich ohne TOTP-Code nicht beenden, etwa von einem
  Besucher der Demo, der den Timer ausprobiert.
- Das Konto der Testsuite hängt an einem **anderen** Mitglied als die laufende Sitzung.

## Bekannte Stolperstellen

- **`work_sessions` verliert nach einem Serverneustart seinen AUTO_INCREMENT-Zähler**
  (Fehler 1467). Dann scheitert jeder Lauf. Behelf und Stand der Untersuchung: OI-1 in
  `docs/OPEN-ITEMS.md`.
- **`checkin_appointment` ist am selben Tag nicht wiederholbar** (OI-41). Betrifft den
  Testlauf, nicht den Generator.

## Betrieb der öffentlichen Demo

Der stündliche Reset ruft den Generator ohne Rückfrage:

```
0 * * * * /usr/bin/php /pfad/zur/installation/private/demo/seed.php --yes --quiet
```

**Der Takt muss stündlich sein.** Das Hinweisband sagt dem Besucher „stündlicher Reset", und
der Satz steht fest verdrahtet an **drei** Stellen: `public/js/theme.js` (Hauptanwendung und
Anmeldung), `public/checkin/js/app.js` und `public/station/js/app.js`. Der Hinweis auf dem
Arbeitszeitnachweis (`private/handlers/export.php`) nennt den Takt bewusst nicht — auf Papier
wäre er ohne Zeitstempel wertlos.

Wer seltener zurücksetzt, lässt die Oberfläche lügen; wer den Takt ändern will, muss die drei
Stellen mitziehen.

`seed.php` weist einen Aufruf über den Webserver ab und läuft nur auf der Kommandozeile.
Der gesamte Schreibvorgang liegt in einer Transaktion — ein Besucher mitten in einer Aktion
bekommt keinen halben Bestand zu sehen. Dass hier `DELETE` und nicht `TRUNCATE` geleert wird,
ist die Voraussetzung dafür: `TRUNCATE` löst ein implizites COMMIT aus und machte das
Zurückrollen wirkungslos.

**`--quiet` zusammen mit `--yes` schweigt vollständig** — kein Zeichen auf STDOUT, damit ein
stündlicher Cron-Job nicht stündlich eine Mail auslöst. Fehler gehen weiterhin auf STDERR und
bleiben sichtbar; der Rückgabewert ist 0 bei Erfolg und 1 bei einem Fehler.

`--quiet` **allein** unterdrückt nur die Schlusszusammenfassung. Die Zielanzeige mit Datenbank,
Präfix und der Zeilenzahl jeder zu leerenden Tabelle erscheint weiterhin, weil ohne `--yes`
gleich nach `LOESCHEN` gefragt wird — wer das tippen soll, muss sehen, was er löscht. Die Regel
steht als `showTargetListing()` im Skript und ist in `tests/suites/demo_seed_cli.php`
festgehalten.

### Der Reset räumt die Mail-Einstellungen nicht ab

`system_settings` steht **nicht** in `DEMO_TABLES`. Der Generator leert die Tabelle nie;
`writePlan()` führt lediglich ein `UPDATE` für die acht Schlüssel aus `buildSettings()` aus.
`smtp_configured` und `mail_enabled` sind keine davon und werden **nicht angefasst**. Steht
dort einmal `1` — weil die Installation vorher Mail konfiguriert hatte oder aus einem Abzug
stammt —, überlebt der Wert jeden Reset.

Dass `password_reset_request` auf der Demo nichts verschickt, liegt deshalb **allein** am
Sperrlisteneintrag in `private/helpers/demo_mode.php`: Der Wächter weist die Ressource mit
403 ab, bevor ein Handler läuft. Die fehlende Mail-Konfiguration ist **kein** zweiter Riegel.

Wer den Sperreintrag lockert im Vertrauen darauf, dass „der Reset das schon abfängt", öffnet
damit unmittelbar den Versand an beliebige Adressen. Die Prüfung der beiden Schlüssel steht
deshalb unten in der Checkliste — sie ist eine Aufgabe am Server, keine Eigenschaft des
Generators.

### Der Demo-Modus ist davon getrennt

Der Generator stellt den **Datenbestand** her. Was ein Besucher damit tun darf, regelt der
Wächter in `private/helpers/demo_mode.php`, eingeschaltet über `define('DEMO_MODE', true);`
in `private/config/config.php`. Beides gehört zusammen, ist aber unabhängig: Ein Reset ohne
Wächter ergäbe eine Demo, in der jeder Konten anlegen kann; ein Wächter ohne Reset eine, die
nach einem Tag zerfahren aussieht.

### Einmalig am Server zu prüfen

- **`/update/` und `/install/` müssen 403 liefern.** Beide tragen eine `.htaccess` mit
  `Require all denied`, und `tests/suites/htaccess_locks.php` hält die Fassungen zusammen.
  Ist `AllowOverride` beim Hoster abgeschaltet, sind beide offen — der Update-Assistent hat
  keine eigene Anmeldung.
- Der Datenbankbenutzer der Demo darf **nur** auf die Demo-Datenbank berechtigt sein.
- `private/config/install.lock` muss vorhanden sein.
- Keine Mail-Konfiguration hinterlegen. **In `system_settings` prüfen, dass `mail_enabled`
  und `smtp_configured` nicht auf `1` stehen** — der Reset räumt sie nicht ab.
- `define('DEMO_MODE', true);` in `private/config/config.php` eintragen.
- Der Cron muss CLI-PHP aufrufen, nicht den Webserver.
