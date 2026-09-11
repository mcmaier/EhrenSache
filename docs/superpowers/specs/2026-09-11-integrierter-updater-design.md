# Integrierter Updater (Halbautomat) — Design

**Datum:** 2026-09-11
**Status:** entworfen, nicht umgesetzt
**Vorhaben ② von zwei** — setzt ① voraus:
`2026-09-10-config-reine-daten-design.md`

---

## 1. Ziel

Der Update-Assistent holt das Paket selbst von GitHub, entpackt es und ersetzt die Dateien.
Der manuelle Upload per FTP entfällt. Alles danach — Systemprüfung, Migrationskette,
Protokoll, Selbstsperre — bleibt, wie es ist.

Der Assistent macht heute bereits den schwierigen Teil. Dieses Vorhaben setzt ihm einen
Schritt 0 davor, mehr nicht.

## 2. Warum ① zuerst kommt

`config.php` enthält heute Programmcode und wird nie überschrieben. Ein Updater müsste sie
deshalb per regulärem Ausdruck patchen, wie es `private/migrations/1.0.0.php` in Schritt 7
tut. Erst wenn die Datei reine Daten hält und ein Bootstrap fehlende Schlüssel mit Defaults
füllt, ist der Dateitausch eine Dateioperation und keine Textchirurgie an fremdem PHP.

## 3. Ausgangslage

Bestandsaufnahme am 2026-09-11:

- **Das Paket ist klein.** `git archive` auf `HEAD` ergibt 2,2 MB und 168 Dateien. Download
  und Sicherung sind damit billig; ein Streaming- oder Chunk-Verfahren braucht es nicht.
- **Es gibt keine Release-Assets.** Die API zu `releases/latest` liefert für `v1.4.1` ein
  leeres `assets`-Array. Bezugsweg ist ausschließlich der von GitHub erzeugte `zipball_url` —
  konsistent mit der Notiz in `docs/OPEN-ITEMS.md`: „Das ZIP stammt von GitHub selbst — nur
  das beachtet die `export-ignore`-Regeln."
- **Einen Wartungsmodus gibt es nicht.** Nirgends im Code.
- **PHP 8.0 ist seit dem 10.09.2026 die zugesicherte Untergrenze**; Installer und Assistent
  prüfen sie. Lokal stehen `curl`, `zip`, `openssl` und `allow_url_fopen` zur Verfügung — was
  über fremde Webspaces nichts aussagt und deshalb im Preflight geprüft wird.

### 3.1 Zwei Fallstricke des zipball

1. `https://api.github.com/repos/…/zipball/<tag>` **leitet weiter** auf `codeload.github.com`.
   Auf Webspaces mit gesetztem `open_basedir` schaltet PHP `CURLOPT_FOLLOWLOCATION` ab; der
   Updater muss dem `Location`-Header dann selbst folgen.
2. Der Inhalt steckt in einem Wrapper-Ordner `mcmaier-EhrenSache-<sha>/`, der beim Entpacken
   abzustreifen ist.

### 3.2 Zurückgenommen: der atomare Tausch

Im Ausblick der Spezifikation zu ① stand „atomarer Tausch statt Überschreiben im Betrieb".
Das ist nicht haltbar. Der Web-Root der Domain zeigt auf `public/`, `private/` liegt eine
Ebene darüber. Der Updater kann also kein fertiges Verzeichnis danebenstellen und umschalten —
dazu müsste er die Hosting-Konfiguration ändern, die er weder kennt noch anfassen darf.

Es bleibt **dateiweises Ersetzen**. Die Absicherung kommt deshalb aus vollständigem Preflight,
Sicherung und Rückweg statt aus Atomarität.

## 4. Aufteilung: Prüfung im Dashboard, Vollzug in `/update`

Der Vollzug bleibt hinter der bestehenden `.htaccess`-Sperre von `public/update/`. Der Admin
leert die Datei wie bisher von Hand.

**Begründung:** Säße der Vollzug im Dashboard, wäre ein übernommener Admin-Account gleich­
bedeutend mit beliebiger Codeausführung — der Updater lädt Code nach und führt ihn aus. Heute
braucht ein Angreifer dafür zusätzlich FTP-Zugang. Diese Eigenschaft wird nicht aufgegeben.

Im Dashboard sitzt nur die **Prüfung**, die nichts schreibt.

## 5. Prüfung — ausschließlich auf Knopfdruck

Es gibt **keinen** automatischen Abruf. Kein Intervall, kein Abruf beim Login, kein
Hintergrundlauf. Die Installation telefoniert nur nach außen, wenn ein Admin in den
Einstellungen auf „Auf Updates prüfen" klickt.

Das ist datenschutzrechtlich die saubere Variante: Ohne Zutun verlässt nichts den Server.

**Der Preis ist bekannt und akzeptiert:** Wer nie klickt, erfährt nichts von einem
Sicherheitsupdate. Der Updater spart damit den FTP-Upload, löst aber nicht das
Informationsproblem — und genau daran hat es zuletzt gehapert (neun Versionen erreichten den
Release-Branch nie, `SECURITY.md` verwies auf eine nicht beziehbare Version). Dass ein Verein
von neuen Versionen erfährt, muss außerhalb der Software geschehen: GitHub-Releases
abonnieren. Das gehört in die `README.md`.

**Was der Klick auslöst:** ein Aufruf von `releases/latest` mit `User-Agent`-Header, ohne
Token. Ergebnis und Zeitstempel gehen nach `system_settings` (`update_check_last`,
`update_check_result`, Kategorie `general`).

**Das Ergebnis bleibt stehen.** Ergibt die Prüfung eine neuere Version, zeigt das Dashboard
der Rolle `admin` weiterhin einen Streifen. Kein Abruf ohne Klick, aber die einmal gewonnene
Information geht nicht verloren. Die Einstellungen nennen dazu „zuletzt geprüft am …".

Der Streifen verschwindet ohne Zutun: Er erscheint nur, solange `update_check_result` eine
höhere Version nennt als die laufende aus `version.json`. Nach dem Update trifft das nicht
mehr zu. Ein Aufräumen des gespeicherten Ergebnisses braucht es deshalb nicht — und ein
vergessener Eintrag kann keinen falschen Hinweis erzeugen.

Der Streifen benutzt dieselbe Mechanik wie der Config-Hinweis aus ①: nicht haftend, im
Inhaltsbereich, Klasse `.alert-warning` aus `public/css/components/modals.css`, nur für
`admin`. Er nennt die neue Version, einen Auszug der Release-Notizen und den
Freischaltschritt.

Die Rate-Grenze der GitHub-API — 60 Aufrufe je Stunde und IP, auf Shared Hosting eine
geteilte IP — ist bei einer handausgelösten Prüfung kein Thema.

## 6. Verifikation: TLS, und sonst nichts

`CURLOPT_SSL_VERIFYPEER` hart auf `true`, ohne Rückfall, wenn kein CA-Bundle vorhanden ist.
Keine Signatur, keine Hash-Prüfung.

**Das ist eine bewusste Entscheidung und gehört als solche nach `docs/OPEN-ITEMS.md.`** Die
Begründung, in aller Deutlichkeit:

- Ein Hash in den Release-Notizen wäre **kein Sicherheitsgewinn**. Er stammt aus derselben
  Quelle wie das Paket; wer das GitHub-Konto übernimmt, ändert beides. Gegen einen
  Netzwerkangreifer schützt bereits TLS. Ein Hash erkennt genau eine Sache: einen
  abgebrochenen Download.
- Ein Hash wäre über den zipball ohnehin unzuverlässig. Das Archiv wird bei Bedarf erzeugt und
  ist nicht byte-stabil; eine Änderung der Erzeugung bricht den Hash, ohne dass jemand etwas
  tut. Ein stabiler Hash setzte ein eigenes, angehängtes Asset voraus.
- Eine **Signatur** mit einem privaten Schlüssel außerhalb von GitHub wäre der einzige Weg,
  der eine Kontoübernahme abfängt. Sie ist bewusst verworfen: Sie erzeugt dauerhafte
  Betriebslast, und bei Schlüsselverlust könnte keine Installation mehr aktualisieren, bis ein
  neuer Schlüssel von Hand verteilt ist.

Die verbleibende Aussage lautet also: **Wir vertrauen GitHub und TLS.** Das ist für den
Bedrohungsrahmen einer Vereinsinstallation vertretbar — aber es ist eine Entscheidung, kein
Versehen, und soll als solche auffindbar sein.

## 7. Was ersetzt wird

**Regel, in zwei Teilen — beide sind nötig:**

1. Ein Verzeichnis wird nur betreten, wenn das Paket **darin** Einträge hat.
2. Gelöscht werden ausschließlich **Dateien**. Verzeichnisse löscht der Updater nie.

Teil 2 ist nicht Beiwerk. Ohne ihn kippt die Regel ins Gegenteil: Das Paket enthält
`private/handlers/`, `private/helpers/` und weitere Unterverzeichnisse, also gilt `private/`
als abgedeckt — und ein naives Spiegeln auf dieser Ebene löschte `private/demo/`,
`private/backup/` und das Arbeitsverzeichnis `private/.update-tmp/` mitsamt Inhalt, weil das
Paket sie nicht kennt. Genau der Schaden, der vermieden werden soll.

Mit beiden Teilen zusammen stimmt das Ergebnis: In `private/handlers/` wird aufgeräumt, ein
entfernter Handler verschwindet also wirklich. `private/` selbst wird betreten, enthält aber
keine losen Dateien. `docs/`, `tests/` und `private/demo/` stehen auf `export-ignore`, haben
im Paket keine Einträge, werden nach Teil 1 nicht betreten und nach Teil 2 ohnehin nicht
gelöscht. Ohne das verlöre jede per Git-Klon aufgesetzte Installation — das
Entwicklungssystem und laut `docs/DEMO.md` auch die öffentliche Demo — diese Verzeichnisse
beim ersten Lauf.

Der Preis von Teil 2: Wird je ein ganzes Verzeichnis aus dem Projekt entfernt, bleibt es als
leere Hülle liegen. Das ist hinnehmbar — ein leeres Verzeichnis führt keinen Code aus.

Der Vorzug gegenüber einer Ausnahmeliste für `export-ignore`-Pfade: Die Regel veraltet nicht,
wenn ein Eintrag in `.gitattributes` dazukommt.

**Feste Ausschlussliste, auch innerhalb abgedeckter Verzeichnisse:**

| Pfad | Grund |
|---|---|
| `private/config/` (vollständig) | `config.php`, `mail_config.php`, `install.lock` |
| `public/uploads/`, `private/uploads/` | Logo und hochgeladene Dateien |
| `public/update/.htaccess` | siehe unten |
| `public/install/.htaccess` | dito |

### 7.1 Der Updater würde sich sonst selbst aussperren

`public/update/.htaccess` liegt mit `Require all denied` im Paket. Entpackt der Updater es,
überschreibt er die vom Admin geleerte Sperrdatei — und der nächste Schritt im Assistenten
quittiert mit 403, mit einer halb getauschten Installation.

Die Aktualisierung der Sperrdateien kommt an anderer Stelle an: `public/update/index.php:199`
schreibt die Sperre am Ende selbst zurück, und zwar aus dem **neuen** Code.
`tests/suites/htaccess_locks.php` hält die Repo-Fassung und die eingebettete Fassung
zusammen — diese Kopplung bleibt damit wirksam.

### 7.2 Bewusst ersetzt

`public/.htaccess` wird überschrieben. Die Datei sagt es selbst: „Aktiv ausgeliefert … diese
Datei liegt im Paket und wird überschrieben." Die HTTPS-Umleitung wird genau deshalb aktiv
ausgeliefert. Kein Sonderfall.

## 8. Ablauf des Vollzugs

Neuer Schritt 0 in `public/update/index.php`, vor der bestehenden Systemprüfung.

1. **Preflight — vollständig, nicht stichprobenartig.** Alle Zieldateien auf `is_writable`,
   alle Zielverzeichnisse auf Anlegbarkeit, `curl` oder `allow_url_fopen` mit TLS,
   `ZipArchive`, freier Speicherplatz. Erst wenn alles passt, geht es weiter.
   Das ist der wichtigste Schritt. Ein Tausch, der bei Datei 100 an einem fehlenden
   Schreibrecht scheitert, hinterlässt eine Installation aus zwei Versionen.
   `docs/project_history.md` nennt „keine Schreibrechte auf Programmdateien" ausdrücklich als
   Randbedingung des Zielhostings — schlägt der Preflight fehl, nennt der Assistent den Grund
   und verweist auf den unveränderten manuellen Weg aus der `README.md`.
2. **Laden** nach `private/.update-tmp/`, dem `Location`-Header notfalls von Hand folgend,
   `SSL_VERIFYPEER` hart an.
3. **Entpacken**, Wrapper-Ordner abstreifen, Paket plausibilisieren: Enthält es
   `version.json`, `public/api/api.php` und `private/migrations/manifest.php`? Ist die Version
   darin höher als die installierte?
4. **Wartungsflag setzen.** `private/config/maintenance.lock` mit Zeitstempel; `api.php`
   prüft es früh und antwortet mit 503. Der Zeitstempel ist wesentlich: Nach 15 Minuten
   ignoriert `api.php` das Flag, damit ein abgebrochener Lauf die Installation nicht dauerhaft
   stilllegt.
5. **Sichern** — jede Datei, die ersetzt oder gelöscht wird, nach
   `private/backup/<version>-<datum>/`.
6. **Tauschen** nach der Regel aus Abschnitt 7.
7. **Bei Fehler:** Sicherung zurückspielen, Wartungsflag entfernen, Protokoll zeigen.
   Scheitert auch der Rückweg, bleibt das Flag stehen und der Text nennt den Pfad der
   Sicherung — dann ist Handarbeit nötig, aber der Admin weiß, wo alles liegt.
8. **Erfolg:** Wartungsflag entfernen, `private/.update-tmp/` aufräumen, weiter in die
   bestehende Systemprüfung. Ab hier läuft der Assistent unverändert, einschließlich
   Migrationskette und Selbstsperre.

Der laufende Updater-Request überlebt das Überschreiben seiner eigenen Datei — PHP hat sie
längst geladen. Parallele Requests sind der Grund für das Wartungsflag.

## 9. Neue und geänderte Bausteine

| Baustein | Art |
|---|---|
| `private/helpers/updater.php` | neu — Abruf, Download, Entpacken, Plausibilisierung, Tauschplan, Sicherung, Rückweg |
| `private/handlers/update_check.php` | neu — Ressource für die handausgelöste Prüfung, nur `admin` |
| `public/update/index.php` | Schritt 0 davor |
| `public/api/api.php` | Wartungsflag früh prüfen; `case 'update_check'` |
| `private/helpers/demo_mode.php` | Eintrag für `update_check` — durch `tests/suites/demo_mode.php` erzwungen |
| `public/js/modules/settings.js` | Knopf „Auf Updates prüfen", Anzeige „zuletzt geprüft am …" |
| `public/js/modules/ui.js` | Hinweisstreifen |
| `API.md`, `README.md` | Abschnitt zur Ressource; Update-Anleitung um den neuen Weg ergänzen, Hinweis auf das Abonnieren der Releases |

Die Ressource `update_check` ist **schreibend** im Sinne des Demo-Modus (sie erzeugt einen
ausgehenden Abruf und schreibt `system_settings`) und gehört dort auf die gesperrte Seite.

## 10. Tests

| Ebene | Prüft |
|---|---|
| `updater` (Unit) | Wrapper-Ordner abstreifen; Tauschplan gegen eine nachgebaute Verzeichnisstruktur; die Regel aus Abschnitt 7 lässt `docs/`, `tests/`, `private/demo/` unberührt; Ausschlussliste greift; Plausibilisierung weist ein Paket ohne `version.json` ab und eines mit niedrigerer Version |
| `updater` (Unit) | Preflight meldet eine nicht schreibbare Datei, **bevor** irgendetwas getauscht wird |
| `updater` (Unit) | Rückweg stellt den Ausgangszustand her, wenn der Tausch in der Mitte abbricht |
| `update_check` (API) | nur `admin`; im Demo-Modus gesperrt; Ergebnis landet in `system_settings` |
| Wartungsflag (API) | gesetztes Flag ergibt 503; ein Flag älter als 15 Minuten wird ignoriert |
| `htaccess_locks` (Regression) | die beiden Sperrdateien stehen auf der Ausschlussliste |

Der Tauschplan wird gegen eine **nachgebaute** Verzeichnisstruktur geprüft, nicht gegen die
echte Installation. Das Muster dafür steht in `tests/db/verify_schema_convergence.php`, das
schon heute mit einer Kopie arbeitet und das Original nicht anfasst.

## 11. Versionsbindung

Wie bei ① ein **Minor**, Nummer offen. Die Reihenfolge steht fest: ① zuerst, ② danach — beides
sind eigenständige Versionen, nicht ein gemeinsamer Sprung.

Einzusetzen ist die Nummer an denselben Stellen wie in ① (Abschnitt 9 dort). Eine
Schemaänderung bringt ② nicht mit: `system_settings` ist ein Schlüssel-Wert-Speicher, die
beiden neuen Einträge passen in die Kategorie `general` ohne Eingriff ins Schema. Eine
Migration ist trotzdem nötig, damit die Kette lückenlos bleibt — sie darf leer sein, wie
`private/migrations/1.4.0.php` zeigt.

## 12. Bewusst nicht enthalten

| Verworfen | Grund |
|---|---|
| Automatische Prüfung, Intervall, Prüfung beim Login | Die Installation soll ohne Zutun nicht nach außen telefonieren |
| Vollzug im Dashboard (Ein-Klick-Update) | Ein übernommener Admin-Account wäre gleichbedeutend mit Codeausführung |
| Signatur- oder Hash-Prüfung | Abschnitt 6 |
| Atomarer Tausch | Abschnitt 3.2 — der Web-Root zeigt auf `public/` |
| Eigenes Release-Asset statt zipball | Kein Sicherheitsgewinn ohne Signatur; der zipball beachtet `export-ignore`. Wiederaufnehmen, falls je signiert wird |
| Downgrade auf eine ältere Version | Die Migrationskette kennt keine Rückrichtung |
