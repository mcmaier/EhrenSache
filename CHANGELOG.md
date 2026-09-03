# Changelog

Alle wesentlichen Änderungen an EhrenSache werden in dieser Datei dokumentiert.

Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

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
