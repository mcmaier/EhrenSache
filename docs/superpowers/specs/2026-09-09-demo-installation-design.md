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
ist der Wächter wirkungslos.

**Ein gesetzter, aber unsauberer Wert fällt zur sicheren Seite:**

| In `config.php` | Ergebnis |
|---|---|
| nicht definiert | Wächter aus |
| `true` | Wächter an |
| `false` | Wächter aus |
| alles andere (`1`, `'true'`, `'false'`, …) | **Wächter an**, dazu eine Meldung über `error_log` |

> **Festgelegt am 2026-09-09 nach der zweiten Qualitätsdurchsicht.** Die erste Fassung prüfte
> streng auf `=== true` — mit der Begründung, ein versehentliches `'false'` als Zeichenkette
> dürfe den Modus nicht einschalten. Ein Mutationstest deckte die Kehrseite auf: Damit
> schaltet ein `define('DEMO_MODE', 1);` den Wächter auf dem öffentlichen Demo-Server
> **stillschweigend ab**. Ein Tippfehler in der Konfigurationsdatei hätte die Demo
> ungeschützt gelassen, ohne jeden Hinweis — genau die Fehlerklasse, gegen die dieses
> Vorhaben antritt. `false` bleibt ein gültiges „aus", weil ein Betreiber den Modus
> ausdrücklich abschalten können muss; jeder andere Wert ist ein Versehen und wird
> als „an" behandelt.

## 5. Der Wächter

Neue Datei `private/helpers/demo_mode.php`. Sie liegt im Produkt und nicht unter
`private/demo/`, damit die ausgelieferte Testsuite sie erreicht.

Aufgerufen wird sie in `public/api/api.php` an **einer** Stelle: nach dem Rate Limiting
(Abschnitt 5), **vor** den öffentlichen Endpunkten (Abschnitt 6). Diese Reihenfolge ist nicht
beliebig — `register` und `password_reset_request` steigen in Abschnitt 6 mit `exit()` aus
und lägen hinter dem `switch` außerhalb der Reichweite.

Der Wächter braucht nur `$resource` und `$request_method`. Keine Rolle, keine Session, kein
Handler-Rumpf wird angefasst.

**Regel:** `GET` und `HEAD` gehen durch, sofern die Ressource in einer der drei Listen steht.
Jeder schreibende Zugriff muss ausdrücklich erlaubt sein.

> **Nachgeschärft am 2026-09-09 nach der Qualitätsdurchsicht.** Die erste Fassung lautete
> „`GET` geht immer durch". Damit wäre eine künftig neue Ressource lesend ohne jede
> Entscheidung freigegeben gewesen — nur ihre Schreibzugriffe wären gesperrt. Das
> widerspricht dem Leitgedanken „neu heißt gesperrt" aus Abschnitt 3. Jetzt tragen alle
> drei Listen Last: Wer eine Ressource ergänzt, ohne sie einzutragen, bekommt in der Demo
> ein sichtbares 403 statt einer stillen Freigabe.

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

Ressourcen, die **ausschließlich lesen**, kommen in keiner der beiden Schreiblisten vor.
Der Wächter führt sie aber sehr wohl — sie machen die Ressource **bekannt**, und nur
bekannte Ressourcen lassen `GET` und `HEAD` durch. Damit unterscheidet sich „nicht gelistet"
sichtbar von „vergessen". Zum Stand 1.4.0:
`ping`, `appearance`, `me`, `version`, `session_info`, `my_data`, `statistics`,
`available_years`, `attendance_list`, `import_logs`, `export`.

### Offengelegte Annahme

„`GET` geht durch" trägt nur, solange kein `GET` etwas verändert. Bei der Qualitätsdurchsicht
am 2026-09-09 wurde jeder schreibende Handler daraufhin einzeln geprüft: `handleCleanup`,
`handleTokenRegeneration`, `handlePasswordChange`, `handlePinChange`, `handleImport`,
`handleUserActivation`, `handleUserStatus`, `handleTotpCheckin` und `handleAutoCheckin`
weisen eine falsche Methode ab; `handleStation` trennt `GET` (`status`, `totp`) sauber von
`POST`. `get_smtp_config` ist eine `POST`-Aktion von `settings` — die SMTP-Zugangsdaten des
Betreibers sind nicht über `GET` erreichbar. `export` ist `GET` und bleibt erlaubt: ein
Download erfundener Daten, den die Werbeseite ohnehin bewirbt.

Die Vollständigkeitsprüfung (Abschnitt 9) hält die Annahme fest.

### Bekannte Lücke: die Kiosk-PIN

`change_pin` ist gesperrt, aber dieselbe Wirkung ist über die **erlaubte** Ressource
`members` erreichbar: deren `PUT`-Zweig schreibt `pin_hash` (`private/handlers/members.php`,
etwa Zeile 381). Ein Besucher kann die PIN des Mitglieds ändern oder löschen, dessen PIN auf
der Demo-Seite steht; die Kiosk-Demo ist dann bis zum nächsten Reset unbrauchbar. Dasselbe
gilt abgeschwächt für `members` `DELETE`, das `users.member_id` auf `NULL` setzt.

Kein Sicherheitsproblem — keine Rechteausweitung, kein Datenabfluss, nur erfundene Daten.
Am 2026-09-09 bewusst hingenommen: Der stündliche Reset ist die Gegenmaßnahme. Eine
Feldsperre im `members`-Handler wurde erwogen und verworfen, weil sie die Grundentscheidung
„ein Wächter, keine Wächter in den Handlern" aufgäbe. Die Begründung an `change_pin` in der
Sperrliste ist entsprechend zu formulieren, damit niemand einen Schutz vermutet, den es
nicht gibt.

## 7. Der Hinweis im Betrieb

Der Fork klebt Banner-Markup samt `<style>`-Block in `public/index.html`. Das erfasst weder
`login.html` noch die beiden PWAs und ist eine weitere Datei, die auseinanderläuft.

Stattdessen meldet `getAppearance()` — ein öffentlicher `GET`, den `theme.js` ohnehin vor
jeder Anmeldung holt — zusätzlich `"demo": true`. Das Banner wird im ausgelieferten CSS
definiert und nur bei gesetztem Merkmal eingeblendet.

Bei der Umsetzung geprüft: `theme.js` läuft **nicht** in den beiden PWAs, beide holen
`appearance` aber selbst. Dort steht die Abfrage deshalb in ihrer eigenen Ladefunktion.

### Es sind fünf Oberflächen, nicht vier

> **Ergänzt am 2026-09-10 nach der Durchsicht.** Die erste Fassung zählte vier auf. Übersehen
> wurde die einzige, deren Erzeugnis den Bildschirm verlässt.

`renderWorktimeReport()` in `private/handlers/export.php` liefert eine eigenständige
HTML-Seite mit Vereinslogo, Vereinsname, Zeitraum und „Erstellt am …", ausgelegt zum
Ausdrucken als Nachweis. Sie hängt an der Ressource `export`, die in `DEMO_READ_ONLY` steht
und auf der Demo damit erreichbar ist. Sie lädt `css/print.css` und keines der vier Bänder.

Ein ausgedruckter Arbeitszeitnachweis über erfundene Personen wäre äußerlich nicht von einem
echten zu unterscheiden. Der Nachweis bekommt deshalb einen eigenen Hinweis, und `print.css`
muss ihn mit `print-color-adjust: exact` aufs Papier bringen — ein Streifen, den der Drucker
wegoptimiert, ist keiner.

**Das bedeutet einen Eingriff in einen Handler** und weicht damit von Abschnitt 12 ab
(„Keine Änderung an den Handlern"). Die Abweichung ist bewusst: Diese Regel richtete sich
gegen das Portieren der zwölf `demoBlockedResponse()`-Aufrufe des Forks. Ein gedrucktes
Dokument ohne Kennzeichnung wiegt schwerer als ihre buchstabengetreue Einhaltung.

## 8. Der Reset

`private/demo/demo_reset.sql` (276 Zeilen aus der 1.0.0-Zeit, kennt weder Arbeitszeit noch
Kiosk) entfällt ersatzlos. Der Cron ruft stündlich:

```bash
php private/demo/seed.php --yes --quiet
```

`--yes` und `--quiet` bestehen bereits — am Generator aus Vorhaben ① ist nichts zu ändern.
Er arbeitet in einer Transaktion; ein Besucher mitten in einer Aktion sieht keinen halben
Bestand.

> **Korrigiert am 2026-09-10.** Hier stand: „`buildSettings()` schreibt acht Schlüssel und
> nicht `smtp_configured`. Da `checkMailStatus()` ein fehlendes `smtp_configured` als ‚aus'
> wertet, stellt jeder Reset den mailfreien Zustand aktiv wieder her." **Das war falsch**,
> und zwar in der gefährlichen Richtung — es behauptete eine zweite Verteidigungslinie, die
> es nicht gibt.

`system_settings` steht **nicht** in `DEMO_TABLES` (`seed.php`). Der Generator leert die
Tabelle nie; `writePlan()` führt lediglich ein `UPDATE` für die acht Schlüssel aus
`buildSettings()` aus. `smtp_configured` und `mail_enabled` werden dabei **nicht angefasst**.
Steht dort einmal `1` — etwa weil die Installation vorher Mail konfiguriert hatte oder aus
einem Abzug stammt —, überlebt der Wert jeden Reset. Nachgemessen am 2026-09-10: In der
Entwicklungsdatenbank stehen beide auf `1`.

**Der Mailversand wird allein durch die Sperrliste verhindert.** `register` und
`password_reset_request` stehen in `DEMO_WRITE_DENIED`; der Wächter weist sie mit 403 ab,
bevor ein Handler läuft. Das ist die einzige wirksame Linie — nicht eine von zweien.

Wer den Sperreintrag lockert, öffnet damit unmittelbar den Versand an beliebige Adressen.
Die fehlende Mail-Konfiguration ist deshalb **kein Ersatz**, sondern eine Betriebsbedingung,
die eigens geprüft werden muss: siehe Abschnitt 11.

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

4. **Die Stellung des Wächters.** Der Test verlangt, dass `demoGuard(` in `api.php`
   **vor** dem ersten `if($resource ===` steht.

   > **Ergänzt am 2026-09-09 nach der Durchsicht von Prüfung 1.** Die Prüfungen 1 bis 3
   > belegen, dass jede Ressource *eingeordnet* ist — nicht, dass sie den Wächter
   > überhaupt *durchläuft*. Stünde der Aufruf hinter Abschnitt 6 von `api.php`, liefen
   > `ping`, `appearance`, `login`, `auth`, `logout`, `register` und
   > `password_reset_request` daran vorbei; die beiden letzten stehen ausdrücklich in der
   > Sperrliste, weil sie Mail an fremde Adressen versenden. Die Suite bliebe dabei grün.
   > Ohne diese vierte Prüfung endet das Schutzversprechen genau an der Stelle, an der es
   > gebraucht wird.

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
- Keine Mail-Konfiguration hinterlegen. **In `system_settings` prüfen, dass `mail_enabled`
  und `smtp_configured` nicht auf `1` stehen** — der Reset räumt sie nicht ab (Abschnitt 8).
  Das ist eine Prüfung am Server, keine Eigenschaft des Generators.
- Der Cron muss CLI-PHP aufrufen — `seed.php` weist einen Aufruf über den Webserver ab.

## 12. Nicht enthalten

- **Kein Schutz gegen gespeichertes XSS.** Ein Besucher kann in Freitextfelder schreiben, was
  bis zum nächsten Reset jeder weitere Besucher zu sehen bekommt. Die Anwendung führt bewusst
  keine CSP (OI-17). Das wurde erwogen und für die Ausbaustufe „Sandkasten mit Grenzen"
  in Kauf genommen; der stündliche Reset begrenzt die Wirkung zeitlich, hebt sie nicht auf.
- **Keine Rollenabstufung im Wächter.** Die alte Idee `DEMO_RESTRICTED_ACTIONS`
  („nur für Manager") entfällt. Der Wächter entscheidet allein nach Ressource und Methode.
  Wer welche Rolle hat, regelt weiterhin die normale Rechteprüfung im Handler.
- **Keine Änderung an den Handlern**, mit **einer** benannten Ausnahme. Die zwölf
  `demoBlockedResponse()`-Aufrufe des Forks werden nicht portiert, sondern ersetzt. Die
  Ausnahme ist der Hinweis im ausdruckbaren Arbeitszeitnachweis (`export.php`), begründet in
  Abschnitt 7.
- **Keine Änderung am Generator aus Vorhaben ①** — auch hier eine Ausnahme: `--quiet`
  unterdrückt seit dem 2026-09-10 zusammen mit `--yes` auch die Zielanzeige. Grund war, dass
  ein stündlicher Cron-Job sonst je nach Konfiguration stündlich eine Mail auslöst. Die Regel
  steht als `showTargetListing()` im Skript und ist geprüft; ohne `--yes` erscheint die
  Anzeige weiterhin, weil danach nach `LOESCHEN` gefragt wird.
- **Kein Rate Limiting eigens für die Demo.** Die bestehenden 150 Anfragen je Minute und die
  Mail-Grenzen (3 je Adresse, 10 je IP pro Stunde) bleiben unverändert.
- **Kein Schutz außerhalb von `api.php`.** `public/reset_password.php` und
  `public/verify_email.php` sind eigene Einstiegspunkte mit Schreibwirkung; der Wächter sitzt
  im API-Router und sieht sie nicht. Praktisch entschärft, weil beide einen Token voraussetzen,
  den nur eine Mail liefert — und `register` wie `password_reset_request` sind gesperrt, der
  Mailversand der Demo ohnehin abgeschaltet. Bei der Durchsicht am 2026-09-09 festgestellt und
  bewusst so belassen. Wer künftig einen weiteren öffentlichen Einstiegspunkt neben `api.php`
  schafft, muss ihn eigens bedenken — die Vollständigkeitsprüfung aus Abschnitt 9 kann ihn
  von Bauart wegen nicht sehen.

## 13. Einordnung

Letztes der drei am 2026-09-08 vereinbarten Vorhaben:

| | Vorhaben | Stand |
|---|---|---|
| ① | Demo-Datengenerator | umgesetzt, auf `dev` |
| ② | Überarbeitung der Werbeseite | umgesetzt, Repository `ehrensache_app` |
| ③ | Demo-Installation auf 1.4.0 | dieses Dokument |

Vorhaben ③ setzt ① voraus: ohne `seed.php` gäbe es keinen Bestand, den der Reset herstellen
könnte.
