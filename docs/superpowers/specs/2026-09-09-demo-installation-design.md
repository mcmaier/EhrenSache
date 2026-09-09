# Öffentliche Demo-Installation — Design

**Datum:** 2026-09-09
**Status:** entworfen, nicht umgesetzt
**Vorhaben ③ von drei** — ① Demo-Datengenerator (umgesetzt), ② Werbeseite (umgesetzt)

---

## 1. Ziel

Eine öffentlich erreichbare Demo von EhrenSache 1.4.0, in der ein Besucher die Anwendung
ohne Anmeldung an einer Registrierung ausprobieren kann, ohne dass daraus ein Risiko für
den Server, für andere Besucher oder für den Betreiber entsteht.

Der Besucher darf **anlegen und ändern**. Gesperrt bleibt, was Konten, Rechte, Mailversand,
Dateiannahme oder Systemeinstellungen berührt (Ausbaustufe „Sandkasten mit Grenzen").
Ein stündlicher Reset stellt den Ausgangszustand wieder her.

## 2. Ausgangslage

Der heutige `demo`-Branch liegt **302 Commits hinter `dev`** und hat **drei** eigene Commits.
Die gesamte Demo-Anpassung sind 15 Dateien. Bei der Bestandsaufnahme am 2026-09-09 fiel auf:

- `checkDemoRestrictions()` — die dokumentierte, über `DEMO_BLOCKED_ACTIONS` konfigurierbare
  Funktion — wird **nirgends aufgerufen**. Toter Code.
- Was tatsächlich schützt, ist `demoBlockedResponse()`: ein hart eingesetztes `exit` in zwölf
  Handler-Rümpfen. Es prüft `DEMO_MODE` **nicht**; die Konstante ist wirkungslos.
- `api.php` lädt `private/demo/demo_config.php` per `require_once` ohne Existenzprüfung.
  Da `private/demo/` in `.gitattributes` auf `export-ignore` steht, wäre das in einem
  ZIP-Download ein Fatal Error.

Der Fork ist also keine Notwendigkeit, sondern eine Folge davon, dass die Umsetzung keinen
Aus-Schalter hat.

Seit der Abspaltung sind **neun API-Ressourcen** dazugekommen, die der Fork nie gesehen hat:
`activity_types`, `change_pin`, `cleanup`, `import_logs`, `my_data`, `session_info`,
`station`, `version`, `work_sessions`. Darunter `cleanup` (löscht Daten) und
`regenerate_token` (erzeugt Zugangsmittel, existierte schon, war nie gesperrt).

**Der eigentliche Fehler ist nicht „zu wenig Sperren", sondern dass niemand bemerkt, wenn
etwas Neues ungedeckt ist.** Daran richtet sich der Entwurf aus.

## 3. Leitgedanke

Ein Schalter pro Installation, **ein** Wächter im Router, und eine **Erlaubnisliste** statt
einer Sperrliste. Eine künftig neue Ressource ist im Demo-Modus damit automatisch gesperrt,
bis jemand bewusst entscheidet. Eine Sperrliste hätte diese Eigenschaft nicht.

Der `demo`-Branch entfällt dadurch ersatzlos. Der Demo-Server folgt `main` und unterscheidet
sich durch eine Zeile in `config.php`.

## 4. Der Schalter

In `private/config/config.php` — die Datei liegt nicht im Repository, ist also pro
Installation:

```php
define('DEMO_MODE', true);
```

`config_example.php` erhält die Zeile auskommentiert mit Erklärung. Fehlt die Konstante,
ist der Wächter wirkungslos. Die Prüfung ist streng auf `=== true`, damit ein versehentliches
`'false'` als Zeichenkette den Modus nicht einschaltet.

## 5. Der Wächter

Neue Datei `private/helpers/demo_mode.php`. Sie liegt im Produkt und nicht unter
`private/demo/`, damit die ausgelieferte Testsuite sie erreicht.

Aufgerufen wird sie in `public/api/api.php` an **einer** Stelle: nach dem Rate Limiting
(Abschnitt 5), **vor** den öffentlichen Endpunkten (Abschnitt 6). Diese Reihenfolge ist nicht
beliebig — `register` und `password_reset_request` steigen in Abschnitt 6 mit `exit()` aus
und lägen hinter dem `switch` außerhalb der Reichweite.

Der Wächter braucht nur `$resource` und `$request_method`. Keine Rolle, keine Session, kein
Handler-Rumpf wird angefasst.

**Regel:** `GET` geht immer durch. Jeder schreibende Zugriff muss gelistet sein.

## 6. Die Erlaubnisliste

| Ressource | Methoden | Warum erlaubt |
|---|---|---|
| `login`, `logout`, `auth` | POST | sonst kommt niemand hinein |
| `members` | POST, PUT, DELETE | Kern der Anwendung |
| `appointments` | POST, PUT, DELETE | Kern der Anwendung |
| `records` | POST, PUT, DELETE | Anwesenheitserfassung |
| `exceptions` | POST, PUT, DELETE | Anträge |
| `work_sessions` | POST, PUT, DELETE | Zeiterfassung, von der Werbeseite beworben |
| `activity_types` | POST, PUT, DELETE | gehört zur Zeiterfassung |
| `membership_dates` | POST, PUT, DELETE | Stammdatenpflege |
| `member_groups` | POST, PUT, DELETE | Stammdatenpflege |
| `appointment_types` | POST, PUT, DELETE | Stammdatenpflege |
| `auto_checkin` | POST | Check-in |
| `totp_checkin` | POST | Check-in am Gerät |
| `station` | POST | Kiosk |

Alles Übrige ist gesperrt, **ohne dass es aufgezählt werden muss**. Zum Stand 1.4.0 trifft es:

| Gesperrt | Begründung |
|---|---|
| `change_password` | macht die veröffentlichten Zugangsdaten unbrauchbar |
| `change_pin` | macht die veröffentlichte Kiosk-PIN unbrauchbar |
| `users`, `activate_user`, `user_status` | Konten und Rechte, teils mit Mailversand |
| `register`, `password_reset_request` | Mailversand an fremde Adressen |
| `settings` | könnte `smtp_configured` setzen und den Mailschutz aushebeln |
| `upload-logo`, `import` | Dateiannahme |
| `cleanup` | löscht Daten |
| `regenerate_token` | erzeugt API-Zugangsmittel |

Ressourcen, die **ausschließlich lesen**, kommen in keiner der beiden Listen vor und werden
im Wächter nicht betrachtet. Sie müssen für die Vollständigkeitsprüfung (Abschnitt 9) aber
benannt sein, damit „nicht gelistet" nicht mit „vergessen" verwechselt wird. Zum Stand 1.4.0:
`ping`, `appearance`, `me`, `version`, `session_info`, `my_data`, `statistics`,
`available_years`, `attendance_list`, `import_logs`, `export`.

### Offengelegte Annahme

„`GET` geht durch" trägt nur, solange kein `GET` etwas verändert. Geprüft wurden die beiden
gefährlichsten: `cleanup` und `regenerate_token` sind beide `POST` und fallen ohnehin unter
die Sperre. `export` ist `GET` und bleibt erlaubt — ein Download erfundener Daten, den die
Werbeseite ohnehin bewirbt. Die Vollständigkeitsprüfung (Abschnitt 9) hält die Annahme fest.

## 7. Der Hinweis im Betrieb

Der Fork klebt Banner-Markup samt `<style>`-Block in `public/index.html`. Das erfasst weder
`login.html` noch die beiden PWAs und ist eine weitere Datei, die auseinanderläuft.

Stattdessen meldet `getAppearance()` — ein öffentlicher `GET`, den `theme.js` ohnehin vor
jeder Anmeldung holt — zusätzlich `"demo": true`. Das Banner wird im ausgelieferten CSS
definiert und nur bei gesetztem Merkmal eingeblendet.

Bei der Umsetzung zu prüfen: ob `theme.js` in `public/checkin/` und `public/station/` läuft.
Falls nicht, gilt dort dieselbe Abfrage gegen `appearance`.

## 8. Der Reset

`private/demo/demo_reset.sql` (276 Zeilen aus der 1.0.0-Zeit, kennt weder Arbeitszeit noch
Kiosk) entfällt ersatzlos. Der Cron ruft stündlich:

```bash
php private/demo/seed.php --yes --quiet
```

`--yes` und `--quiet` bestehen bereits — am Generator aus Vorhaben ① ist nichts zu ändern.
Er arbeitet in einer Transaktion; ein Besucher mitten in einer Aktion sieht keinen halben
Bestand.

`buildSettings()` in `plan.php` schreibt acht Schlüssel und **nicht** `smtp_configured`.
Da `checkMailStatus()` ein fehlendes `smtp_configured` als „aus" wertet, stellt jeder Reset
den mailfreien Zustand aktiv wieder her. Diese Eigenschaft ist beabsichtigt und darf bei
Änderungen an `buildSettings()` nicht verlorengehen.

## 9. Prüfung

`tests/suites/demo_mode.php` — reine Funktionsprüfungen, ohne HTTP und ohne eingeschalteten
Demo-Modus, damit sie in jedem `php tests/run.php` mitlaufen:

1. **Vollständigkeit.** Der Test liest `public/api/api.php`, sammelt jede `case '<name>'`
   **und** jedes `if($resource === '<name>')` und verlangt, dass jeder Name in **genau einer**
   von drei Listen steht: schreibend erlaubt, schreibend gesperrt, oder nur lesend. Eine neue
   Ressource, die niemand bedacht hat, steht in keiner und macht die Suite rot. Das ist die
   Prüfung, die den Rückfall in den heutigen Zustand verhindert.

   Die dritte Liste trägt die Annahme aus Abschnitt 6: Wer eine Ressource dort einträgt,
   behauptet, dass sie nichts verändert. Das ist eine bewusste Aussage, kein Nebeneffekt
   des Schweigens.
2. **Schalter aus heißt untätig.** Ohne `DEMO_MODE` lässt der Wächter jede Kombination aus
   Ressource und Methode durch — die Vereinsinstallation ist beweisbar unberührt.
3. **Die Matrix.** Für jede gesperrte Ressource wird `POST`, `PUT` und `DELETE` abgewiesen,
   für jede erlaubte nicht.

## 10. Der `demo`-Branch verschwindet

Nach der Umsetzung hat er nichts Eigenes mehr:

| Bisher auf `demo` | Ersatz |
|---|---|
| `private/demo/demo_config.php` | `private/helpers/demo_mode.php` |
| `private/demo/demo_reset.php`, `demo_reset.sql` | `private/demo/seed.php` (Vorhaben ①) |
| Banner in `public/index.html` | `appearance` meldet `demo: true` |
| zwölf `demoBlockedResponse()` in Handlern | ein Wächter im Router |
| gelöschtes `verify_email.php` im Wurzelverzeichnis | auf `dev` längst entfallen |

Vorgehen: einmal als Tag `demo-legacy` sichern, dann den Branch löschen.

## 11. Betriebsbedingungen am Server

Nicht Code, aber Voraussetzung dafür, dass der Rest trägt. Einmalig zu prüfen:

- **`/update/` und `/install/` müssen 403 liefern.** Beide tragen eine `.htaccess` mit
  `Require all denied`, und `tests/suites/htaccess_locks.php` hält die Fassungen zusammen.
  Sind `.htaccess`-Dateien beim Hoster nicht wirksam (`AllowOverride`), sind beide offen —
  der Update-Assistent hat keine eigene Anmeldung.
- Der Datenbankbenutzer der Demo darf **nur** auf die Demo-Datenbank berechtigt sein.
- `install.lock` vorhanden.
- Keine Mail-Konfiguration hinterlegen; `smtp_configured` ungesetzt lassen.
- Der Cron muss CLI-PHP aufrufen — `seed.php` weist einen Aufruf über den Webserver ab.

## 12. Nicht enthalten

- **Kein Schutz gegen gespeichertes XSS.** Ein Besucher kann in Freitextfelder schreiben, was
  bis zum nächsten Reset jeder weitere Besucher zu sehen bekommt. Die Anwendung führt bewusst
  keine CSP (OI-17). Das wurde erwogen und für die Ausbaustufe „Sandkasten mit Grenzen"
  in Kauf genommen; der stündliche Reset begrenzt die Wirkung zeitlich, hebt sie nicht auf.
- **Keine Rollenabstufung im Wächter.** Die alte Idee `DEMO_RESTRICTED_ACTIONS`
  („nur für Manager") entfällt. Der Wächter entscheidet allein nach Ressource und Methode.
  Wer welche Rolle hat, regelt weiterhin die normale Rechteprüfung im Handler.
- **Keine Änderung an den Handlern.** Die zwölf `demoBlockedResponse()`-Aufrufe des Forks
  werden nicht portiert, sondern ersetzt.
- **Kein Rate Limiting eigens für die Demo.** Die bestehenden 100 Anfragen je Minute und die
  Mail-Grenzen (3 je Adresse, 10 je IP pro Stunde) bleiben unverändert.

## 13. Einordnung

Letztes der drei am 2026-09-08 vereinbarten Vorhaben:

| | Vorhaben | Stand |
|---|---|---|
| ① | Demo-Datengenerator | umgesetzt, auf `dev` |
| ② | Überarbeitung der Werbeseite | umgesetzt, Repository `ehrensache_app` |
| ③ | Demo-Installation auf 1.4.0 | dieses Dokument |

Vorhaben ③ setzt ① voraus: ohne `seed.php` gäbe es keinen Bestand, den der Reset herstellen
könnte.
