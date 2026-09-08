# Changelog

Alle wesentlichen Änderungen an EhrenSache werden in dieser Datei dokumentiert.

Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

---

## [Nicht veröffentlicht]

### Geändert
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
