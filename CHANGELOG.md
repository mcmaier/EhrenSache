# Changelog

Alle wesentlichen Änderungen an EhrenSache werden in dieser Datei dokumentiert.

Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

---

## [Nicht veröffentlicht]

### Neu
- **Schnellinbetriebnahme des Kiosks per QR-Code.** Der Gerätedialog einer virtuellen Station
  zeigt neben Token und Kopieren einen QR-Code mit der Adresse `…/station/#t=<token>`. Statt
  48 Hex-Zeichen auf einem Tablet ohne Tastatur abzutippen, scannt man ihn mit der Kamera; die
  Station verbindet sich und entfernt den Token aus Adresszeile und Verlauf.

  Bewusst das **Fragment** und nicht der Query: Es wird vom Browser nie gesendet und steht damit
  in keinem Zugriffsprotokoll, keinem Referrer und keinem Reverse-Proxy-Log. Der Token bleibt
  ohne Mitglieds-PIN wertlos und darf weiterhin nur die Ressource `station` aufrufen — der
  QR-Code macht ihn nicht angreifbarer, als er im Gerätedialog ohnehin ist.

  Ein fehlgeschlagener Scan kostet die Station nicht ihre Kopplung: Der bisherige Token wird
  wiederhergestellt. Die Station wechselt aber sichtbar auf den Einrichtungs-Bildschirm mit der
  Fehlermeldung und **bleibt dort, bis jemand die Seite neu lädt** — in dieser Zeit nimmt sie
  keine Stempel an. Das ist Absicht: Ein stiller Rückfall auf den alten Token würde einen
  fehlgeschlagenen Scan verschweigen.

  **Reihenfolge beachten:** erst koppeln, dann zum Startbildschirm hinzufügen. Unter iPadOS hat
  eine installierte Web-App einen eigenen Speichercontainer; ein später gescannter Code landet
  in Safari und erreicht die installierte Station nicht.

- **Der QR-Code des Dashboards funktioniert ohne Internetzugang.** Die Bibliothek kam bisher von
  cdnjs und fiel in einem Netz ohne Internetzugang still aus — der QR-Code des PWA-Quicklinks
  fehlte dort einfach. Sie liegt jetzt als `public/js/vendor/qrcode.js` im Paket und dient
  Dashboard und Station gemeinsam. Zwei Tests halten fest, dass jedes Skript auf eine vorhandene
  Datei zeigt und weder Dashboard noch Station etwas von außen laden.

- **Demo-Modus für öffentlich erreichbare Installationen.** `define('DEMO_MODE', true);` in
  `private/config/config.php` begrenzt schreibende Zugriffe auf eine feste Erlaubnisliste:
  Mitglieder, Termine, Anwesenheit, Anträge, Arbeitszeit, Check-in und Kiosk bleiben nutzbar;
  Konten, Rechte, Mailversand, Dateiannahme und Systemeinstellungen sind gesperrt. Ohne die
  Zeile ist der Modus wirkungslos — eine Vereinsinstallation merkt nichts davon.

  Der Wächter steht an **einer** Stelle im Router, nicht in den Handlern, und die Liste ist
  eine **Erlaubnis**- und keine Sperrliste: Eine künftig neue Ressource ist damit von selbst
  gesperrt, bis jemand bewusst entscheidet. Genau daran ist der Vorgänger gescheitert — der
  alte `demo`-Branch schützte über zwölf harte `exit`-Aufrufe in Handler-Rümpfen, und als
  danach neun Ressourcen dazukamen, bemerkte das niemand.

  Ein gesetzter, aber unsauberer Wert fällt zur sicheren Seite: `true` heißt an, `false` aus,
  jeder andere Wert ebenfalls **an**, dazu eine Meldung im Protokoll. Ein Tippfehler lässt die
  öffentliche Demo also nicht stillschweigend ungeschützt.

- **Hinweisband auf allen Oberflächen.** `appearance` meldet zusätzlich `demo`; Dashboard,
  Anmeldung, Check-in-PWA und Kiosk blenden daraufhin ein Band ein. Der ausdruckbare
  Arbeitszeitnachweis trägt einen eigenen, deutlicheren Hinweis — er ist das einzige
  Erzeugnis, das den Bildschirm verlässt, und wäre sonst von einem echten Nachweis äußerlich
  nicht zu unterscheiden.

- **Die Anwesenheitsstatistik hat einen Druckbericht.** `GET ?resource=statistics_report`
  liefert eine eigenständige HTML-Seite mit Vereinslogo, Kennzahlen, einer Tabelle je Gruppe und
  Fußnoten — dasselbe Papierformat, das die Arbeitszeit seit 1.2.2 kennt. In der
  Statistik-Sektion öffnet der Knopf „📄 Bericht" ihn mit der aktuellen Filterauswahl in einem
  neuen Tab. Bewusst ohne eigenen Dialog: Die Sektion trägt Jahr, Gruppe und Mitglied bereits in
  der Filterleiste, ein zweites Formular hätte nur die Frage aufgeworfen, welche der beiden
  Auswahlen gilt.

  Ist genau ein Mitglied gewählt, folgt ein Abschnitt **„Termine im Einzelnen"**: Datum, Termin,
  Terminart, Status, Ankunft und Herkunft. Erst diese Liste macht das Blatt zum Nachweis statt
  zur Kennzahl.

- **Mitglieder drucken ihre Nachweise selbst.** Anwesenheitsbericht und Stundennachweis stehen
  jetzt auch der Rolle `user` offen — ausschließlich über die eigene Person, ausschließlich als
  Druckansicht. Wer eine Ehrenamtskarte beantragt oder dem Arbeitgeber etwas vorlegen muss,
  braucht dafür niemanden mehr.

  Der Server erzwingt die eigene Person; eine mitgeschickte fremde `member_id` wird ignoriert,
  nicht abgewiesen. CSV bleibt Admin und Manager vorbehalten — die eigenen Rohdaten gibt es über
  `?resource=my_data`.

### Geändert
- **Die Anmeldeseite trägt jetzt „Einsatz ist EhrenSache".** „Anwesenheit" beschrieb die
  Anwendung nicht mehr vollständig, seit sie auch Arbeitszeit erfasst; „Einsatz" deckt beide
  Erfassungsarten ab. Derselbe Claim steht in der README
- **Anmeldeseite: ruhige Fläche statt Zweifarb-Verlauf.** Der lineare Verlauf stellte die beiden
  frei einstellbaren Vereinsfarben unvermittelt nebeneinander — bei fremden Farbpaaren
  (Rot/Orange, Gelb/Anthrazit) wurde das unruhig bis grell. Jetzt trägt eine aus der Primärfarbe
  abgeleitete, abgedunkelte Fläche den Bildschirm, überlagert von zwei weichen Lichtern und einem
  feinen Punktraster gegen Streifenbildung; die Sekundärfarbe klingt nur noch als Schimmer an.
  Neue Tokens `--login-bg` und `--login-glow` in `variables.css`, per `color-mix` aus den
  eingestellten Farben abgeleitet, mit festem Rückfallwert für Browser ohne Unterstützung. Die
  Anmeldekarte bekommt gestaffelte Schatten und 16 px Radius, damit sie auf der Fläche aufliegt
  statt wie ein Ausschnitt zu wirken
- `private/demo/seed.php`: `--quiet` schweigt zusammen mit `--yes` vollständig. Zuvor gab auch
  ein stiller Lauf rund zwanzig Zeilen aus — bei einem stündlichen Cron-Job je nach
  Konfiguration eine Mail pro Stunde. Ohne `--yes` bleibt die Zielanzeige, weil danach nach
  `LOESCHEN` gefragt wird: Wer das tippen soll, muss sehen, was er löscht.

- **Der Bericht sagt, worauf seine Ankunftszeiten beruhen.** `records.arrival_time` ist keine
  durchgehende Messung: Hakt jemand eine Anwesenheitsliste ab, ohne eine Uhrzeit einzutragen,
  setzt das System die **Startzeit des Termins** — der Eintrag ist damit konstruiert pünktlich.
  Diese Uhrzeit ununterschieden auf einen Nachweis zu drucken, hieße eine Messung zu behaupten,
  die nie stattgefunden hat. Die Terminliste trägt deshalb eine Spalte **Herkunft** mit drei
  Stufen, erklärt in den Fußnoten:

  | Wert | Bedeutung |
  |---|---|
  | `gemessen` | bei der Anmeldung an einer Station oder in der App aufgezeichnet |
  | `korrigiert` | auf Antrag geändert und genehmigt |
  | `nachgetragen` | nicht bei der Anmeldung erfasst — von Hand, eingelesen oder aus einem anderen Vorgang |

  `korrigiert` schlägt `gemessen`: Eine genehmigte Zeitkorrektur überschreibt die Ankunftszeit,
  lässt die Quelle aber unverändert. Ohne diese Vorrangregel trüge eine korrigierte Zeit weiter
  das Etikett der ursprünglichen Messung.

- **Eine Fußnote nennt je Gruppe die ausgewertete Terminart.** Die Statistik rechnet je Gruppe
  nur über eine Terminart, obwohl eine Gruppe an mehreren hängen kann — ein Fehler im Bestand,
  der beim Bau dieses Berichts auffiel (OI-48) und der eine eigene Entscheidung braucht, weil
  seine Behebung sämtliche bestehenden Quoten verschiebt. Bis dahin behauptet das Blatt
  wenigstens keine Vollständigkeit, die es nicht hat.

- **Berichtsausgabe an einer Stelle.** Die HTML-Ausgabe der Arbeitszeitberichte lag in
  `handlers/export.php` und konnte eine Tabelle plus einen Summenblock. Sie liegt jetzt als
  `renderReport()` in `helpers/report.php` und kennt beliebig viele Abschnitte — der
  Anwesenheitsbericht braucht einen je Gruppe. Die drei Arbeitszeitberichte sehen unverändert
  aus; das ist byteweise nachgewiesen.

- **Bildschirm und Papier rechnen dasselbe.** Die Aggregation der Statistik lag als Schleife
  mitten im Handler und war für nichts anderes benutzbar. Sie steht jetzt als
  `buildStatisticsResult()` daneben, und der Bericht benutzt sie. Zwei Rechnungen, die dasselbe
  behaupten, laufen bei der ersten Änderung auseinander.

- **`?resource=statistics` liefert zwei Felder mehr.** Je Mitglied `excused` — bisher musste
  jeder Verbraucher es aus drei anderen Zahlen selbst ausrechnen, zuletzt an zwei Stellen
  gleichzeitig —, je Gruppe `appointment_type_name`. Beides additiv, bestehende Aufrufer sind
  unberührt. Ohne `year`-Parameter trägt die Antwort jetzt `"year": 2026` als Zahl statt
  `"year": "2026"` als Zeichenkette.

### Behoben
- **`API.md` beschrieb `appearance` falsch.** Das Beispiel zeigte ein flaches Objekt mit
  `org_name` und `logo_url`; ausgeliefert wird seit Langem `{"settings": {…}}` mit anderen
  Schlüsselnamen. Gegen die laufende Installation geprüft und ersetzt.

- **`API.md` beschrieb auch die Statistik-Antwort falsch.** Dasselbe Muster, andere Stelle: Das
  dokumentierte Beispiel führte `attended`, `absent`, `attendance_rate` und `monthly_stats` auf;
  die tatsächliche Antwort trägt `summary` und `statistics`. Wer nach dieser Referenz baute,
  baute gegen etwas, das nie existiert hat. Das Beispiel entspricht jetzt der Wirklichkeit.

  Darin standen auch `late_count` und `avg_arrival_minutes` — die Pünktlichkeitsauswertung, die
  das Projekt an mehreren Stellen bewirbt und nirgends einlöst. Sie sind aus der Referenz
  entfernt, statt eine Zusage stehen zu lassen, die kein Code erfüllt; als offener Punkt ist
  die Metrik in `docs/OPEN-ITEMS.md` festgehalten.

### Intern
- `tests/run.php` meldet einen vorzeitigen Abbruch. Bisher lud es alle Suiten in **einem**
  Prozess und rief die Zusammenfassung erst am Ende — ein `exit()` in irgendeiner Suite
  beendete den Lauf mit Rückgabewert **0** und übersprang stillschweigend alle späteren
  Suiten. Von außen sah das wie ein Erfolg aus.
- `tests/suites/demo_mode.php` liest `public/api/api.php` und verlangt, dass jede dort
  geroutete Ressource in genau einer der drei Listen steht, und dass der Wächter vor dem
  ersten öffentlichen Endpunkt aufgerufen wird — geprüft über den Tokenstrom, nicht über eine
  Textsuche.
- `$_GET['resource']` wird auf eine Zeichenkette geprüft. `?resource[]=x` lieferte sonst ein
  Array, das die Typprüfung des Wächters mit einem Fatal Error zerlegt hätte.
- Der `demo`-Branch entfällt. Sein Stand liegt als Tag `demo-legacy`.

---

## [1.4.1] – 2026-09-09

### Sicherheit
- **Dashboard und Check-in-PWA liefen mit eingeschaltetem Debug-Modus aus.** `DEBUG` stand in
  `public/js/app.js` und `public/checkin/js/app.js` fest auf `true` — eine Konstante, die vor
  einer Veröffentlichung von Hand umzustellen war. Das ist seit 1.1.3 in **jeder**
  veröffentlichten Version unterblieben — 1.4.0 ist nicht der Anfang, nur das Ende; einzig
  1.0.0 wurde stumm ausgeliefert. Folge: die PWA schrieb bei jeder Anmeldung die
  vollständige Serverantwort in die Browserkonsole, einschließlich des Bearer-Tokens, mit
  dem sich das Gerät danach dauerhaft ausweist.

  Das Token verließ das Gerät dabei nicht — es stand in der Konsole des Browsers, in dem die
  Anmeldung stattfand. Wer die Entwicklerwerkzeuge dieses Geräts öffnen kann, konnte es
  jedoch im Klartext mitlesen: relevant vor allem bei Geräten, die sich mehrere Personen
  teilen, und bei Fernwartungssitzungen mit geteiltem Bildschirm. **Wer solche Geräte im
  Einsatz hat, sollte die betroffenen Token nach dem Update neu erzeugen** (Profil →
  „Token neu generieren"; für Geräte derselbe Weg in der Geräteverwaltung).

  Der Schalter wird jetzt nicht mehr gesetzt, sondern aus `location.hostname` abgeleitet:
  lokal laut, auf jeder echten Domain still. Damit gibt es nichts mehr umzustellen, was
  vergessen werden könnte. Die virtuelle Station ist bewusst enger gefasst als Dashboard und
  PWA — sie hängt als Kiosk dauerhaft im Vereinsnetz und bleibt auch unter privaten Adressen
  und `.local`-Namen stumm. Für den Bedarfsfall lässt sich die Ausgabe an jedem Gerät
  einzeln über `localStorage.setItem('es_debug', '1')` in der Konsole zuschalten.

### Intern
- Zwei Prüfungen in der Suite `assets` halten den Zustand fest: kein hart gesetztes `DEBUG`
  mehr, und keine ungeschützte `console.log`/`warn`/`debug` in den ausgelieferten Skripten.
  `console.error` bleibt erlaubt — eine Fehlermeldung soll auch produktiv sichtbar sein und
  trägt keine Sitzungsdaten. Eine dritte Prüfung hält die engere Regel der Station fest
- Die Migration `1.4.0 → 1.4.1` ändert nichts am Schema. Sie existiert, damit die Kette
  lückenlos bis `version.json` reicht und der Update-Wizard für eine 1.4.0-Installation einen
  Weg findet

---
## [1.4.0] – 2026-09-09

### Neu
- **Der Kalender der Terminverwaltung zeigt die Termine schon beim Überfahren.** Bisher öffnete
  erst ein Klick das Popup; das Überfahren zeigte nur einen nativen Browser-Tooltip —
  unformatiert und erst nach rund einer Sekunde. Jetzt erscheint dasselbe Popup nach 180
  Millisekunden. Ein per Klick geöffnetes bleibt stehen, ein überfahrenes verschwindet beim
  Verlassen; der Klick bleibt damit der Weg auf Geräten ohne Mauszeiger

### Geändert
- **`public/.htaccess` leitet jetzt selbst auf HTTPS um.** Der Block war bisher auskommentiert
  und musste von Hand aktiviert werden — was beim nächsten Update verloren ging, weil die Datei
  zum Paket gehört und überschrieben wird. Der sichere Zustand ist deshalb jetzt der
  Auslieferungszustand. **Nach dem Update gilt die Umleitung ohne weiteres Zutun**; wer sie
  nicht will, kommentiert den Block aus und muss das nach jedem Update wiederholen.
  Drei Ausnahmen verhindern eine Endlosschleife dort, wo `%{HTTPS}` trügt: TLS-Terminierung am
  Proxy (`X-Forwarded-Proto`), Port 443, und `localhost` für die lokale Entwicklung. Es ist
  dieselbe dreifache Prüfung, die `public/api/api.php` schon für das Secure-Flag der Session
  verwendet
- **`work_sessions.active_member` ist jetzt eine gespeicherte statt einer virtuellen Spalte.**
  Die Migration legt Spalte und Unique-Index neu an — MariaDB lehnt die Umstellung per `MODIFY`
  ab (Fehler 1907). Der Wert folgt vollständig aus `end_time` und `member_id` und entsteht beim
  Neuanlegen aus den vorhandenen Zeilen; verloren geht nichts. Grund ist der Verdacht aus OI-1,
  dass die indizierte virtuelle Spalte nach einer Crash-Recovery den AUTO_INCREMENT-Zähler der
  Tabelle kostet. **Bei sehr vielen Sitzungen schreibt der Schritt die Tabelle neu und dauert
  entsprechend**; der Update-Assistent weist ab 50 000 Zeilen darauf hin
- **Datum und Uhrzeit im Kalender-Popup sind formatiert.** Statt `2026-09-04` und `20:00:00`
  steht dort `Fr., 04.09.2026` und `20:00` — dasselbe Muster wie an den übrigen Stellen der
  Oberfläche. Seit das Popup schon beim Überfahren erscheint, fiel die Rohform ständig auf
- **Der Anwesenheits-Export führt den Termin jetzt eindeutig.** Neu sind die Spalten
  `appointment_start_time` und `appointment_type`; zusammen mit `appointment_date` bilden sie
  den Schlüssel, den die Anwendung ohnehin verwendet — beim Anlegen gilt ein Termin *dieser
  Art* im Toleranzfenster als Konflikt, zwei verschiedene Arten am selben Abend sind erlaubt.
  Der Reimport ordnet damit exakt zu, statt über zeitliche Nähe zu raten und dabei Probe und
  Vorstandssitzung verwechseln zu können. Fehlen die Spalten — ältere Dateien, Fremdsysteme —,
  greift unverändert die bisherige Suche

### Hinzugefügt
- **Der Anwesenheits-Import kann fehlende Termine anlegen**, auf ausdrückliche Anforderung
  (`create_missing_appointments`). Verlangt denselben vollständigen Schlüssel und legt nichts
  auf Verdacht an; die Antwort nennt unter `appointments_created`, wie viele entstanden sind.
  Standardmäßig aus, damit ein Tippfehler im Datum keine Karteileiche erzeugt. Wer Termine
  aus bloßen Ankunftszeiten *rekonstruieren* will, nutzt weiterhin `extract_appointments` —
  das schlägt vor, ohne zu schreiben
- Neue Testsuite `export_import`: prüft die Kopfzeilen der drei Exporte gegen die
  Pflichtspalten des Imports, den Terminschlüssel auf Vorhandensein **und** Inhalt, und hält
  die Arbeitsteilung fest, dass `extract_appointments` nicht schreibt
- **Arbeitszeit in der Statistik der Check-in-PWA.** Der Statistik-Tab zeigt für Mitglieder,
  die Zeiten erfassen dürfen, die bestätigte Jahressumme, eine Aufschlüsselung nach Tätigkeit
  und eine Fußnote über eingereichte und abgelehnte Einträge. Die Summe stammt aus derselben
  Auswertung wie der Verwendungsnachweis (`statistics?include=worktime`); nur bestätigte und
  beendete Sitzungen zählen. Ohne die Fußnote läse sich eine „0:00 h" nach einem frischen
  Nachtrag als Fehler. Server, Schema und API bleiben unverändert.
- **Arbeitszeit aus der PWA korrigieren und nachtragen.** Eigene abgeschlossene Sitzungen
  lassen sich über den Verlauf korrigieren, vergessene über den Arbeitszeit-Tab nachtragen.
  Beides geht erneut in die Freigabe. **Eine Zeitkorrektur nimmt dem Eintrag den Ortsnachweis
  für die verschobene Zeit** — bisher behielt eine um Stunden zurückdatierte Sitzung das
  Etikett „stundenbelegt", obwohl für die zusätzliche Zeit nichts belegt war. Das gilt für alle
  Rollen und wirkt sich auf Statistik, Export und Verwendungsnachweis aus.

### Behoben
- **Eine Installation im Stand 1.0.0 ließ sich über den Update-Assistenten nicht
  aktualisieren.** Schritt 1 brach mit „Die installierte Datenbankversion konnte nicht
  bestimmt werden" ab — unabhängig vom Zielstand, und seit es den Assistenten gibt. Der Grund:
  Er übergibt der Versionserkennung den Präfix aus der Konfiguration, und eine 1.0.0-`config.php`
  kennt das Feld `$prefix` noch gar nicht. Die Prüfung lautete damit
  `tableExists('users') && !tableExists('users')` und hob sich selbst auf. Wer es versucht hat,
  hatte Grund, seine Datenbank zu verdächtigen statt der Software. Der Fall steht jetzt als
  `UPD-5a` in `tests/db/verify_migration_chain.php` — mit dem echten Schema aus dem Tag
  `v1.0.0` statt einer nachgebauten Ausgangslage
- **Der Installer stempelte eine geratene Version in die Datenbank.** Fehlte `version.json`,
  trug er fest `1.1.3` in `schema_version` ein. Eine frische Installation hätte sich damit als
  veraltet ausgegeben, und der Update-Assistent wäre später über ein längst aktuelles Schema
  gelaufen. Jetzt bricht die Installation mit klarer Meldung ab: Ein unvollständiges Paket soll
  auffallen. Der Kopf des Installers **nennt die Version außerdem sichtbar**, wie der
  Update-Assistent es schon tat
- **„Teilbelegt" gilt jetzt für beide Grenzen einer Sitzung, nicht nur für den Start.** Die
  Leiter des Nachweisgrades kannte eine Stufe für „nur der Start ist ortsbelegt", aber keine
  für „nur das Ende ist ortsbelegt" — letztere fiel bis „unbelegt" durch. Eine Sitzung mit
  verschobenem Beginn galt damit als gänzlich unbelegt, obwohl ihr Ende weiter belegt war.
  Tragbar war das, solange Ortsnachweise nur vom Timer kamen: Wer stoppt, hat vorher
  gestartet. Seit eine Zeitkorrektur den Nachweis der geänderten Zeit fallen lässt, ist der
  umgekehrte Fall alltäglich. **Die Änderung wirkt rückwirkend:** Bestehende Sitzungen mit
  belegtem Ende und unbelegtem Start rutschen von „unbelegt" auf „teilbelegt" — auch in
  abgeschlossenen Jahren. Ein neu erzeugter Verwendungsnachweis weicht dort von einem früher
  eingereichten ab
- **Verlauf und Anwesenheitsliste der Check-in-PWA rollten in einem eigenen Kasten
  innerhalb einer Seite, die selbst rollt.** Beide trugen die feste Obergrenze
  `calc(100vh - 320px)`; die 320 Pixel beschrieben eine Kopfzone, die je nach Fensterbreite
  umbricht und tatsächlich 377 bis 453 Pixel hoch ist. Der Kasten ragte damit aus dem Fenster,
  und wer über ihm wischte, bewegte ihn statt der Seite — es sah aus, als höre die Liste dort
  auf. Im Verlauf waren auf einem Handy 3 von 20 geladenen Einträgen ohne Geste sichtbar.
  Beide Listen wachsen jetzt mit ihrem Inhalt; eine einzige Geste erreicht alles
- **Der Knopf „Zeit nachtragen" war auf der weißen Karte kaum zu sehen.** `.action-button`
  setzt `border: none`, die Variante `secondary` überschrieb nur den Hintergrund — ihr
  Hover-Zustand setzte seit jeher ein `border-color`, das ins Leere lief. Alle fünf sekundären
  Knöpfe der PWA sind jetzt Umriss-Knöpfe in der Hausfarbe
- **Der Knopf „Korrigieren" im Verlauf steht rechts oben**, an derselben Stelle und in
  derselben Form wie der Löschen-Knopf eines Antrags — blau statt rot, weil Korrigieren nichts
  zerstört. Zuvor klebte er am Datum
- **Ein Anwesenheitsantrag heißt im Verlauf der PWA nicht mehr „Zeitkorrektur".** Das Wort
  meinte dort die Ankunftszeit und war neben den Arbeitszeit-Einträgen derselben Liste mit
  einer Korrektur der *Arbeitszeit* zu verwechseln. Ein Antrag liest sich jetzt als „Antrag
  wartet auf Freigabe", eine Entschuldigung behält ihr eindeutiges Wort. Das Dashboard nennt
  die Antragsart weiterhin „Zeitkorrektur" — die PWA sagt weniger, nicht etwas anderes
- **Die Zeiterfassung heilt einen verlorenen AUTO_INCREMENT-Zähler selbst.** Nach einer
  InnoDB-Crash-Recovery las sich der Zähler von `work_sessions` zweimal als `0`; jeder Versuch,
  eine Sitzung anzulegen, endete mit `1467 Failed to read auto-increment value from storage
  engine` und HTTP 500. Ein Verein ohne Datenbankkenntnisse kann das nicht beheben. Der Handler
  erkennt jetzt genau diesen Fehler, setzt den Zähler auf `MAX + 1` und wiederholt den
  Schreibvorgang einmal. **Der Eingriff wird laut protokolliert** — er behandelt ein Symptom,
  dessen Ursache außerhalb der Anwendung liegt, und soll künftige Diagnosen nicht erschweren.
  Siehe OI-1
- **Das Kalender-Popup lief am Fensterrand aus dem Bild.** Es wurde ohne Rücksicht auf die
  Fenstergröße positioniert; in der rechten Spalte und in der letzten Zeile war es teilweise
  unerreichbar. Es klappt jetzt um. Beim Klick fiel das selten auf, beim Überfahren ständig
- **Datum und Uhrzeit in der Terminliste standen in normaler Textgröße.** Ein fehlendes Wort im
  Markup (`<style=…>` statt `<small style=…>`) erzeugte ein unbekanntes Element, die
  Kleinschrift blieb wirkungslos

### Intern
- **Demo-Datengenerator** (`private/demo/`): Ein CLI-Skript füllt eine Datenbank reproduzierbar
  mit einem fiktiven Verein — als Bildmaterial für die Werbeseite und als Reset-Bestand der
  öffentlichen Demo, deren bisheriges SQL-Skript seit 1.1.0 nicht mehr lauffähig war. Getrennt
  in einen reinen Teil (`plan.php`, 99 Tests ohne Datenbank) und eine Schreibschicht
  (`seed.php`). Über `export-ignore` nicht im ZIP-Download enthalten
- `tests/suites/station_api.php` sammelte über Läufe hinweg eine Sperre an: Der Test mit
  falscher PIN schickte eine **feste** unbekannte Mitgliedsnummer, und die Stationssperre zählt
  Fehlversuche auch für unbekannte Nummern. Ab dem sechsten Lauf binnen 15 Minuten antwortete
  der Server `423` statt `401`. Die Nummer trägt jetzt ein `uniqid()`
- `tests/suites/worktime_api.php`: Zwei Tests setzten einen freien Timer-Zustand voraus, statt
  ihn herzustellen. Neue Testsuiten `demo_seed_unit`, `demo_seed_cli` und die Einzelprüfung
  `tests/db/verify_autoinc_repair.php`

---

## [1.3.1] – 2026-09-07

### Behoben
- **CSV-Export von Mitgliedern, Terminen und Anwesenheiten war unbenutzbar.** Das Dashboard
  schickte bei diesen drei Downloads den Header `Authorization: Bearer null` mit — es legt
  gar keinen API-Token ab, `getAuthHeaders()` baute den Header aber unbedingt. Ein solcher
  Header verdrängt in `api.php` die Session (`if (!$apiToken) session_start()`), läuft in die
  Token-Prüfung und endet mit `401`, obwohl der Nutzer angemeldet ist. Der Header entsteht
  jetzt nur noch, wenn tatsächlich ein Token vorliegt.

  Der Fehler lag seit der Einführung des Exports im Code, blieb aber vier Monate folgenlos,
  weil Apache den `Authorization`-Header verschluckte. Sichtbar wurde er erst, als dieser
  Header für die Token-Authentifizierung der PWA durchgereicht wurde — ein berechtigter Fix,
  der einen schlafenden Fehler geweckt hat. Betroffen waren ausschließlich diese drei
  Exporte; der Import und alle übrigen Aufrufe laufen über andere Wege. Siehe OI-24.
- **Der Termin-Export lud Fehlermeldungen als CSV-Datei herunter.** Ihm fehlte die Prüfung
  auf `response.ok`, sodass der Fehlerkörper zum Blob wurde und als `.csv` mit JSON darin
  auf der Platte landete.
- **Exportierte CSV-Dateien ließen sich nicht wieder importieren.** Der Termin-Export
  schrieb die Spalte `type`, der Import verlangt `type_name`; beim Anwesenheits-Export hieß
  die Spalte `arrival_time` statt `arrival_date_time`. Beide Dateien wurden mit „missing
  required columns" abgewiesen, bevor eine Zeile gelesen war. Der Export nutzt jetzt die
  Namen des Imports; der Import akzeptiert die alten weiter, damit archivierte Dateien
  einlesbar bleiben. Mitglieder-Exporte waren nie betroffen. Siehe OI-24
- **Mitgliedsfilter der Zeiterfassung war beim ersten Öffnen leer.** Die Auswahl wurde aus
  dem Mitglieder-Cache aufgebaut, den der Bereichswechsel erst 500 ms später im Hintergrund
  füllt. Filterleiste und Nachtrags-Dialog laden die Liste jetzt selbst (`loadMembers()`)
- **Check-in-PWA zeigte Administratoren und Managern im Verlauf die Arbeitszeiten aller
  Mitglieder.** Der Abruf ging ohne `member_id` an `work_sessions`; ohne diese Eingrenzung
  antwortet die Ressource für beide Rollen absichtlich ungefiltert (so gewollt fürs
  Dashboard). Der Verlauf grenzt jetzt auf das angemeldete Mitglied ein — die
  Gesamtübersicht bleibt dem Dashboard vorbehalten
- **Check-in-PWA behielt nach der Abmeldung die Ansichten des vorigen Mitglieds.** Verlauf,
  Statistik, Anwesenheitsliste, Auswahlfelder und der zuletzt geöffnete Tab standen unverändert
  weiter, bis ein Reload dazwischenkam. Die Abmeldung räumt diesen Zustand jetzt ab
- **Check-in-PWA hängte bei jeder Anmeldung dieselben Ereignisse erneut an.** Nach einem Zyklus
  Abmelden → Anmelden schickte ein Klick auf „Start" zwei Anfragen, ein Klick auf ein Jahr
  sprang zwei Jahre weit, und ein Tab-Wechsel lud seine Daten doppelt. Neues `bindOnce()`
  bindet je Element und Ereignisart nur einmal — dieselbe Sperre, die
  `initAttendanceList()` schon von Hand hatte

- **Kiosk: Restlaufzeit-Balken des TOTP-Codes begann nach einem Reload immer voll und blieb
  nach dem nächsten Codewechsel grau.** Die Leiste wurde in zwei Schritten gesetzt (Startbreite,
  dann per `requestAnimationFrame` das Ziel 0 %), doch der Browser fasst beide zu einer
  Stilberechnung zusammen — rAF-Callbacks laufen vor dem Style-Recalc desselben Frames. Die
  Transition startete deshalb vom vorigen Wert: nach dem Laden von 100 %, nach einem
  abgelaufenen Code von 0 % nach 0 %, also gar nicht. Nur ein Klick (Stempeln/Abbrechen)
  erzwang zufällig einen Flush dazwischen. Jetzt erzwingt `renderBar()` den Reflow selbst.

### Intern
- Neue Testsuite `worktime_frontend` (statische Gegenproben am Zeiterfassungs-Frontend) und
  ein API-Test, der die Eingrenzung per `member_id` festhält
- `initAttendanceList()` nutzt `bindOnce()` statt siebenmal `dataset.listenerAdded` von Hand;
  das Speichern des Termindialogs steht als `submitAppointmentForm()` daneben
- Testplan: Abschnitt 21 zu Sitzungswechsel in der PWA und Mitgliedsfilter

---

## [1.3.0] – 2026-09-07

### Neu
- **Virtuelle Station (Kiosk).** Neuer Gerätetyp `kiosk`: ein Tablet zeigt den rotierenden
  Stations-Code und nimmt Stempel per Mitgliedsnummer + PIN entgegen — Anwesenheit und
  Arbeitszeit (Start, Pause, Ende). Eigene PWA unter `public/station/`, eigene Ressource
  `station`. Das TOTP-Secret verlässt den Server nicht; der Kiosk holt nur den gültigen Code
- **Stations-PIN.** `members.pin_hash` (nur Hash). Mitglieder setzen die PIN im Profil
  (`change_pin`), Verwalter im Mitglieds-Modal. Regeln: 4–8 Ziffern, keine Einheitsziffern,
  keine Zahlenfolge. Sperre nach 5 Fehlversuchen je Mitgliedsnummer (auch unbekannte) und
  30 je Station, jeweils 15 Minuten; eine neue PIN hebt die Mitgliedssperre auf.
  Einstellungen `station_pin_enabled`, `station_pin_min_length`
- Neue Quellen `station_pin` (Anwesenheit) und `station` (Arbeitszeit), in der Oberfläche als
  „Station (PIN)" gekennzeichnet. Der Kiosk-Name gilt als Ortsnachweis an Start und Ende
- `GET users&user_type=device&device_type=` filtert Geräte; `is_active` wird beim Anlegen
  eines Geräts berücksichtigt
- Selbstauskunft (`my_data`) nennt `has_pin` und `pin_updated_at`, auch im CSV
- Testsuiten `station_unit` (ohne Datenbank, u. a. Sperrlogik gegen SQLite) und `station_api`

### Geändert
- Ein Geräte-Token vom Typ Kiosk darf ausschließlich `station` (und `version`) aufrufen
- **Eine vom Token erzeugte PHP-Session ist ohne den Token nicht mehr nutzbar (401).**
  Der Token-Zweig legte bislang eine vollwertige Session an, deren Cookie allein genügte
- Beim Wechsel des Gerätetyps wird das gespeicherte TOTP-Secret verworfen; `auth_device`
  und `kiosk` speichern nie ein Secret aus dem Request; ein Secret muss Base32 (16–64
  Zeichen) sein; eine `totp_location` kann ihr Secret nicht löschen
- Die Notizpflicht der Zeiterfassung gilt am Kiosk nicht (keine Tastatur für Fließtext)
- `auto_checkin`: Terminsuche und Eintrag in `findCheckinAppointment()` und
  `writeCheckinRecord()` herausgelöst. Ein bestehender Eintrag mit Status `excused` wird
  durch einen Stempel zu `present`; `record_id`, `member_id`, `appointment_id` sind
  JSON-Zahlen; `arrival_time` wird normalisiert gespeichert
- `member_number` wird beim Speichern getrimmt; „0" ist eine gültige Nummer
- Migration 1.2.5 → 1.3.0 meldet doppelte Mitgliedsnummern als Warnung, sichert alle
  Spaltenänderungen ab und erzeugt die View `v_users_extended` neu

### Behoben
- **`GET member_groups&id=` lieferte `m.*` ohne Rollenprüfung** und damit seit der
  Migration auch den PIN-Hash an jede angemeldete Rolle. Jetzt feste Spaltenlisten je Rolle
- `login()` löscht `auth_type` aus einer wiederverwendeten Session
- Geräte-Modal: `toggleDeviceTypeFields()` setzte `required` am Secret-Feld nicht zurück

---

## [1.2.5] – 2026-09-04

### Geändert
- **Die DSGVO-Bereinigung kennt drei Löschfristen statt einer.** Getrennt einstellbar für
  Anwesenheiten und Ausnahmen (`cleanup_years_records`), für Arbeitszeiten samt ihrer
  Änderungshistorie (`cleanup_years_worktime`) und für verwaiste Einträge der
  Änderungshistorie (`cleanup_years_audit`). Alle Schritte laufen in einer Transaktion
- Der Schlüssel `dsgvo-cleanup-years` heißt jetzt `cleanup_years_records` — die Migration
  übernimmt den eingestellten Wert

### Neu
- **`cleanup` löscht Arbeitszeiten und ihre Änderungshistorie.** Bis 1.2.4 kannte der
  Endpunkt nur `records` und `exceptions`; die Frist aus `DATENSCHUTZ.md` 10.4 musste jeder
  Betreiber selbst per SQL durchsetzen
- **Verwaiste Einträge der Änderungshistorie werden anonymisiert, nicht gelöscht.** `changes`
  und `changed_by` fallen weg, der Eintrag bleibt mit Zeitpunkt und Art der Änderung stehen.
  Die Spur belegt weiter, dass etwas geschah — ohne Personenbezug
- `cleanup` ist erstmals in `API.md` dokumentiert
- Testsuiten `cleanup_unit` und `cleanup_api` sowie `tests/db/verify_cleanup_retention.php`

### Behoben
- **`cleanup` nahm jede Frist an, auch `0`.** Der Stichtag war dann der heutige Tag, und der
  Aufruf löschte den gesamten Bestand an Anwesenheiten und Ausnahmen. Die Untergrenze stand
  nur als `min="1"` im Formular und wirkte bei einem direkten API-Aufruf nicht. Jede Frist
  muss jetzt eine ganze Zahl ab 1 sein, geprüft vor dem ersten `DELETE`
- Laufende Sitzungen (`end_time IS NULL`) fallen nicht mehr in die Löschfrist. Eine seit
  Jahren offene Sitzung ist ein Fehlerfall, kein Löschfall
- **Der Update-Wizard zeigte in Schritt 3 eine leere Seite.** Die Schleife über die
  Migrationskette lief mit `foreach ($chain as $step)` und überschrieb damit die Nummer des
  Wizard-Schritts. Danach traf keine der Bedingungen `$step == 1|2|3` mehr, und weder
  Protokoll noch Fehlermeldung wurden gerendert — obwohl die Migration sauber durchlief.
  Da der Wizard sich im selben Durchgang per `.htaccess` aussperrt, war das Ergebnis
  anschließend auch nicht mehr erreichbar. Der Fehler betraf jedes Update mit mindestens
  einem Migrationsschritt
- Der Wizard überschrieb beim Sperren die Anleitung zum Wiederöffnen in
  `public/update/.htaccess`. Nach dem ersten Update stand nirgends mehr, wie man den
  Assistenten für das nächste erreichbar macht
- **Die Zugriffssperren von Installer und Update-Assistent nutzten reine Apache-2.2-Syntax**
  (`Order Deny,Allow` / `Deny from all`). Auf einem Apache 2.4 ohne `mod_access_compat`
  beantwortet der Server das mit HTTP 500 statt 403 — gesperrt bleibt das Verzeichnis, es
  sieht aber nach einem Defekt aus. Beide Dateien und beide erzeugenden Skripte tragen jetzt
  beide Syntaxen, je in einem `<IfModule>`-Wächter
- **Der Installer wurde mit der Sperre des `abgeschlossenen` Zustands ausgeliefert.** Die
  Datei im Repository trug den Text, den der Installer nach seinem Lauf schreibt — wer klonte
  oder das Paket lud, bekam an Schritt 4 der Anleitung ein `403 Forbidden` und eine Datei,
  die ihm sagte, er sei fertig. Die Sperre bleibt (ein hochgeladener, noch nicht eingerichteter
  Webspace soll den Installer nicht offen zeigen); der Text benennt jetzt den
  Auslieferungszustand, und die README ergänzt den Freischaltschritt — wortgleich zum
  Update-Ablauf
- Die `.gitignore` enthielt seit jeher eine wirkungslose Regel `install/.htaccess`: falscher
  Pfad (nicht `public/install/`) und die Datei ist getrackt, wo `.gitignore` ohnehin nicht
  greift. Entfernt, damit niemand annimmt, hier wirke etwas

### Warum
Die Änderungshistorie überlebt das Löschen einer Arbeitszeit absichtlich — sonst wäre
ausgerechnet die Löschung nicht dokumentiert. Sie enthält damit personenbezogene Daten, die
den eigentlichen Datensatz überdauern, und braucht eine eigene Frist. Sie am Ende ganz zu
löschen nähme ihr allerdings den Zweck; deshalb die Anonymisierung.

Die Bereinigung läuft weiterhin nicht von selbst. Ein unbeaufsichtigt löschender Job ohne
Sicherung wäre die schlechtere Variante — und einen Scheduler hat das Projekt bewusst nicht.

---

## [1.2.4] – 2026-09-03

### Geändert
- **Die automatische Terminerzeugung beim Check-in ist abschaltbar.** Findet ein Check-in
  keinen passenden Termin, entscheidet `checkin_auto_create_appointment`, ob einer angelegt
  oder der Check-in mit einem Hinweis abgelehnt wird. Die Einstellung gilt für alle
  Check-in-Wege — PWA, IoT-Station und API
- **Bestandsinstallationen starten auf „an", Neuinstallationen auf „aus".** Ein Update ändert
  damit nichts am laufenden Betrieb; wer neu anfängt, entscheidet bewusst
- **Das Zeitfenster der Terminzuordnung steht in den Einstellungen**
  (`checkin_tolerance_hours`) statt in `config.php`. Die Migration übernimmt den bisherigen
  Wert der Konstante `AUTO_CHECKIN_TOLERANCE_HOURS`; die Konstante bleibt als Rückfall
  bestehen. Der Wert gilt jetzt auch für die Dublettenprüfung beim Anlegen von Terminen und
  für die Terminauswahl in der PWA, wo bis 1.2.3 eine abweichende Zahl stand

### Neu
- **Terminauswahl beim Check-in in der PWA.** Ein optionales Feld über dem Scan-Knopf bietet
  die Termine des Tages an. Ohne Auswahl sucht der Server wie bisher
- **Automatisch erzeugte Termine sind markiert** (`appointments.is_auto_created`), tragen in
  der Terminverwaltung ein Badge und lassen sich dort filtern. Die Migration markiert den
  Altbestand und nennt seine Anzahl im Protokoll

### Behoben
- Ein vom Client geschickter Termin wird serverseitig gegen Tag, Gruppenzugehörigkeit **und
  Zeitfenster** geprüft. Ohne diese Prüfungen ließe sich über den Check-in-Endpunkt rückwirkend
  Anwesenheit behaupten — die Tagesgrenze allein erlaubte einen Check-in zu jeder Uhrzeit
  desselben Tages, ein zeitlich weit entfernter Termin wurde ebenso angenommen wie ein naher

### Warum
Bis 1.2.3 legte ein Check-in ohne passenden Termin unbemerkt einen neuen an — Standard-
Terminart, Zeit auf fünf Minuten gerundet. `statistics.php` zählt jeden Termin einer Terminart
bei jedem aktiven Mitglied der verknüpften Gruppen als Solltermin; ein Fehlscan senkte damit
die Quote aller anderen. Strukturell derselbe Fall, der in 1.2.3 zur Regel „kein Weg der
Zeiterfassung erzeugt Anwesenheit" geführt hat.

**Automatisch erzeugte Termine zählen weiterhin voll in die Anwesenheitsquote.** Wer die
Automatik eingeschaltet lässt, sollte den neuen Filter regelmäßig durchsehen — siehe OI-20 in
`docs/OPEN-ITEMS.md`.

---

## [1.2.3] – 2026-09-03

### Geändert
- **Der Start der Zeiterfassung erzeugt keinen Anwesenheitseintrag mehr.** Bisher legte ein
  Timer-Start mit Terminbezug einen Check-in an, sofern der Termin am selben Tag lag.
  Damit gilt jetzt für alle drei Erfassungswege derselbe Satz: Kein Weg der Zeiterfassung
  erzeugt Anwesenheit. Wer beides festhalten will, tut beides — in der PWA liegen die Wege
  nebeneinander
- **Die PWA bietet alle Termine des Jahres an**, nicht mehr nur die des heutigen Tages.
  Vorbereitung findet vor der Veranstaltung statt, Nachbereitung danach; genau diese Stunden
  zeigt der Bericht „nach Termin", und genau sie ließen sich bisher nicht zuordnen
- Terminlisten sind nach zeitlicher Nähe sortiert — in der PWA zu heute, im Nachtrag-Dialog
  zum eingetragenen Datum

### Neu
- **Tätigkeitsarten lassen sich mit Terminarten verknüpfen.** „Bühnenaufbau" bietet dann nur
  noch Konzerte an, nicht jede Probe des Jahres. Ohne Verknüpfung stehen weiterhin alle
  Termine zur Wahl — die Migration legt deshalb keine einzige Zuordnung an, und wer die
  Eingrenzung nicht nutzt, merkt nicht, dass es sie gibt

### Warum
Die Anwesenheitsauswertung liest `records` ohne Rücksicht auf `checkin_source`. Ein vom Timer
erzeugter Eintrag zählte damit voll in die Anwesenheitsquote und — wegen `arrival_time = NOW()`
— auch in die Pünktlichkeit. Wer um 08:00 die Bühne für das Konzert um 19:00 aufbaute, galt
als anwesend und als elf Stunden zu früh. Der Datumsschutz sollte das verhindern, wehrte aber
nur den Vortag ab und ließ den Regelfall durch: Am Veranstaltungstag wird gearbeitet.

### Hinweis für bestehende Installationen
Anwesenheitseinträge, die vor dem Update aus der Zeiterfassung entstanden sind
(`checkin_source = 'timer'`), **bleiben erhalten** und zählen weiterhin in Anwesenheit und
Pünktlichkeit. Ein Teil davon ist korrekt — das Mitglied war tatsächlich da —, und welcher,
lässt sich nachträglich nicht entscheiden. Das Update-Protokoll nennt ihre Anzahl.

---

## [1.2.2] – 2026-09-03

### Neu
- **Zeitraum für Arbeitszeitberichte**: Alle drei Auswertungen nehmen `from` und `to` als
  Datum entgegen, nicht mehr nur ein Kalenderjahr. Damit sind Monats-, Quartals- und
  Förderzeiträume möglich; das Vereinsjahr von September bis Juni ebenso. `?year=` bleibt
  gültig und liefert unverändert das bisherige Ergebnis
- **Druckansicht**: `&format=html` liefert die Berichte als druckbare Seite mit Vereinslogo,
  Zeitraum, Summen und einer Fußnote, die Zuordnungsregel und Nachweisgrade erklärt. Das PDF
  entsteht über den Druckdialog des Browsers — ohne zusätzliche Bibliothek
- **Berichtsdialog** in der Zeiterfassung mit Schnellwahl für „Dieser Monat", „Letzter Monat"
  und „Laufendes Jahr". Er ersetzt die beiden bisherigen Export-Knöpfe und macht die Auswertung
  nach Termin erstmals über die Oberfläche erreichbar
- `public/css/print.css` — das Projekt hatte bisher kein Print-Stylesheet
- **Terminfeld beim Nachtragen von Zeiten** im Dashboard. Damit füllt sich die Spalte „Termin"
  auch für Einträge, die nicht über die PWA entstanden sind — und der Bericht „nach Termin"
  beantwortet erstmals vollständig, was eine Veranstaltung an Arbeit gekostet hat.
  Die Zuordnung erzeugt **keinen** Anwesenheitseintrag: Arbeit für einen Termin ist keine
  Anwesenheit bei ihm, und ein Nachtrag ist bis zur Freigabe eine ungeprüfte Behauptung. Den
  Check-in erzeugt weiterhin allein der Timer-Start

### Geändert
- Dateinamen der Exporte tragen den Zeitraum: `stundennachweis_2026-01.csv` statt
  `stundennachweis_2026.csv`. Ohne das ist ein gespeichertes Monats-CSV von einem Jahres-CSV
  nicht zu unterscheiden
- Die Summenzeile der CSV nennt den Zeitraum, über den sie gebildet wurde
- Der Zeitraumvergleich nutzt `>=` und `< Ende + 1 Tag` statt `YEAR(start_time)`. Das ist
  indextauglich und verliert keine Sitzung mehr, die am letzten Tag nach Mitternacht beginnt

### Behoben
- Die Korrektur einer Arbeitszeitsitzung verarbeitete `appointment_id` nicht. Ein über die API
  mitgesendeter Terminbezug wurde beim Bearbeiten stillschweigend verworfen; ein einmal
  gesetzter Termin ließ sich weder ändern noch entfernen

### Sicherheit
- Jeder Wert der Druckansicht wird maskiert. Notizen und Ortsnamen stammen aus der PWA, also
  aus Rollen unterhalb von `admin`; ohne Maskierung wäre der Bericht ein gespeichertes XSS in
  genau der Ansicht, die ein Administrator zum Prüfen öffnet. Die Berichtsseite enthält
  keinerlei JavaScript

### Hinweis
- Sitzungen zählen zu dem Zeitraum, in dem sie **begonnen** haben. Eine Sitzung über
  Mitternacht erscheint vollständig im Monat ihres Beginns; die Summe über zwölf Monate
  entspricht deshalb der Jahressumme. Die Regel steht auf jedem Bericht

---

## [1.2.1] – 2026-09-02

### Neu
- **Gruppenbindung der Tätigkeitsarten**: Eine Tätigkeitsart lässt sich Mitgliedergruppen
  zuordnen (`activity_type_groups`), analog zu den Terminarten. Nur Mitglieder dieser Gruppen
  sehen und erfassen sie — geprüft wird auch serverseitig beim Start und beim Selbst-Nachtrag,
  nicht nur in der Anzeige
- **Erfassen-Tab in der PWA**: Check-in und Zeiterfassung sind ein Tab. Stehen beide Absichten
  offen, erscheint zuerst eine Auswahl; wer nur eine hat, landet direkt beim Werkzeug
- **Laufende Sitzung über allen Tabs sichtbar**: Eine schmale Leiste zeigt Tätigkeit und
  laufende Zeit und führt auf Tippen zur Sitzung zurück
- **Zusammengeführter Verlauf**: Anwesenheiten, Anträge und Arbeitszeiten in einer Zeitachse

### Geändert
- Tätigkeitsarten sind nur noch für Mitglieder der zugeordneten Gruppen erfassbar. Die Migration
  ordnet den Bestand **allen** Gruppen zu — es ändert sich also nichts, bis ein Administrator die
  Zuordnung pflegt
- Die Liste „Erfasste Zeiten" in der Zeiterfassung ist entfallen; der Verlauf führt sie mit
- Fehlermeldungen der PWA benennen die Ursache, statt sie durch einen Statustext zu ersetzen
- Antrag und Arbeitszeit verwenden im Verlauf denselben Wortlaut für die Freigabe

### Behoben
- Ein Scan für den Check-in konnte versehentlich eine Arbeitszeitsitzung starten: Die Umleitung
  des nächsten Codes war unsichtbar, ohne Zeitlimit und überlebte den Tabwechsel. Der Zweck
  eines Scans folgt jetzt aus der sichtbaren Ansicht
- Der Start einer nachweispflichtigen Tätigkeit führte in eine Sackgasse — kein Abbruch, keine
  manuelle Eingabe
- Die Terminauswahl der Zeiterfassung blieb leer, wenn zuvor nicht der Antragsdialog geöffnet
  worden war; das Tagesdatum wurde zudem aus UTC gebildet und sprang abends auf den Folgetag
- Anwesenheitseinträge aus dem Timer zeigten im Dashboard keine Quelle
- Bei abgeschalteter Zeiterfassung meldete das Dashboard bei jedem Neuladen einen Fehler
- `id="scannerContainer"` existierte zweimal in der PWA

---

## [1.2.0] – 2026-09-01

### Neu
- **Zeiterfassung** für ehrenamtliche Arbeit (standardmäßig deaktiviert, siehe Einstellung `worktime_enabled`)
  - Live-Timer in der PWA mit Start, Pause und Stopp
  - Nachträgliche Erfassung über ein Formular, mit Freigabe durch Manager
  - Tätigkeitsarten als Stammdaten (`activity_types`)
  - Optionaler Terminbezug: Der Timer-Start erzeugt den Anwesenheits-Eintrag mit
  - Auditspur aller Änderungen (`work_session_log`)
- **Migrationskette**: Der Update-Wizard führt beliebig viele aufeinanderfolgende
  Migrationen aus, statt fest verdrahtet genau eine. Neue Schritte werden in
  `private/migrations/manifest.php` deklariert
- **Schema-Versionierung bei Neuinstallation**: Der Installer stempelt die Version
  aus `version.json`, sodass der Update-Wizard den Ausgangsstand kennt
- **Testharness** unter `tests/` – abhängigkeitsfrei, Aufruf über `php tests/run.php`

### Geändert
- `records.checkin_source` kennt zusätzlich den Wert `timer`

### Behoben
- Der Update-Wizard bestimmte die installierte Version über `ORDER BY applied_at`.
  Bei mehreren Einträgen in derselben Sekunde war das Ergebnis zufällig, und
  `1.10.0` hätte als kleiner als `1.9.0` gegolten. Jetzt entscheidet `version_compare`

---

## [1.1.3] – 2026-04-16

### Neu
- **Update-Wizard** (`/update`): Schritt-für-Schritt Datenbank-Migration ohne Datenverlust
  - Automatische Erkennung der installierten DB-Version
  - Tabellen-Prefix-Migration (v1.0.0 → v1.1.x)
  - `config.php` wird automatisch um Prefix-Feld und `table()`-Methode ergänzt
  - Migrationsprotokoll mit Warnhinweisen
  - Wizard sperrt sich nach erfolgter Migration automatisch
- **Schema-Versionierung**: Tabelle `schema_version` für künftiges Versions-Tracking
- **Import-Protokollierung**: Neue Tabelle `import_logs`
- **Terminarten-Zuordnung**: Neue Spalte `appointments.type_id`
- **Performance**: Zusätzliche Indizes auf `records`, `appointments`, `member_group_assignments`
- **Dropdown-Querfilterung**: Mitglieder- und Termin-Dropdowns in Record- und Ausnahmen-Modal filtern sich gegenseitig
- **Mitglieder-Aktivitätsstatus**: Inaktive Mitglieder werden in Listen hervorgehoben, Toggle zum Ein-/Ausblenden
- **Rollenbasierte Filter**: Statistik- und Terminart-Filter für normale Benutzer auf eigene Gruppen eingeschränkt

### Geändert
- `system_settings`: ENUM-Wert `appearance` → `public` für Einstellungskategorien
- Neue Einstellung `privacy_policy_url` in system_settings
- Mitglieder-Modal: `is_active`-Checkbox durch schreibgeschütztes Status-Badge ersetzt
- Statistik-Filterung nach Mitgliedschaftszeitraum (jahresbasiert) statt `active`-Flag

### Fixes
- **Sicherheit**: Direktzugriff auf `private/`-Verzeichnis über HTTP gesperrt (BUG-1)
- **Authentifizierung**: `Authorization`-Header wird korrekt durch Apache weitergeleitet (BUG-4)
- **Authentifizierung**: Session-Shadowing bei Token-Authentifizierung verhindert (BUG-4)
- **Authentifizierung**: Rate Limiting für Token- und Session-Login vereinheitlicht (BUG-5, BUG-6)
- **Authentifizierung**: Atomare Rate-Limiter-Transaktionen (BUG-5, BUG-6)
- **Login**: HTTP 401/429 statt 200 bei fehlgeschlagenem Login (BUG-7)
- **Login**: Expliziter Fehlercode statt String-Matching (BUG-7)
- **Mitglieder**: Eingabevalidierung ergänzt (BUG-2)
- **Mitglieder**: PUT verwendet PATCH-Semantik (BUG-2)
- **Mitglieder**: DELETE in Transaktion mit Deadlock-Behandlung (BUG-3, BUG-9)
- **Mitglieder**: Aktiv-Voraussetzung für DELETE entfernt (BUG-3, BUG-9)
- **Ausnahmen**: Eingabevalidierung ergänzt
- **Dropdowns**: Mitglied-/Termin-Dropdowns filtern nach aktiven Mitgliedschaftszeiträumen
- **Statistik**: Mitglieder-Dropdown filtert nach aktivem Zeitraum im gewählten Jahr
- **TOTP**: Geräte-Generierung und Datenbank-Initialisierung für Einstellungen korrigiert
- **version.php**: Pfad zu `version.json` auf `__DIR__`-basiert umgestellt

---

## [1.0.0] – 2026-01-23

### Erstveröffentlichung

- Mehrstufiges Rollensystem (Admin, Manager, Benutzer, Gerät)
- Session-basierte Authentifizierung (Web) und Bearer-Token (API/PWA)
- Terminverwaltung mit Terminarten und Gruppenzuordnung
- Anwesenheitserfassung (Web, QR-Code, TOTP-Station)
- Ausnahmenverwaltung (Entschuldigungen, Zeitkorrekturen)
- Gruppenverwaltung mit M:N-Zuordnung
- Mitgliederverwaltung mit CSV-Import
- Statistikauswertung nach Termin, Mitglied und Gruppe
- Progressive Web App (PWA) für mobilen Check-in
- TOTP-basierte Standortverifikation für Geräte
- Installations-Wizard (`/install`)
- Duales Lizenzmodell (AGPL-3.0 / Kommerziell)
