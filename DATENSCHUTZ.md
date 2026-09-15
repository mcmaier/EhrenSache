# Datenschutz-Hinweise für Betreiber von EhrenSache

## ⚠️ Wichtiger Hinweis

**Diese Hinweise ersetzen keine Rechtsberatung.**

Als Betreiber von EhrenSache verarbeiten Sie personenbezogene Daten und sind 
**Verantwortlicher** im Sinne der DSGVO (Art. 4 Nr. 7 DSGVO). Sie sind 
verpflichtet, die datenschutzrechtlichen Vorgaben eigenständig umzusetzen.

---

## 1. Ihre Rolle als Verantwortlicher

### Was bedeutet das?

- ✅ Sie entscheiden über **Zweck** und **Mittel** der Datenverarbeitung
- ✅ Sie müssen die **DSGVO-Konformität** sicherstellen
- ✅ Sie haften für **Datenschutzverstöße** in Ihrer Organisation
- ✅ Sie müssen **Betroffenenrechte** gewährleisten

### Was ist mit dem Entwickler?

- ❌ Der Entwickler ist **nicht** Verantwortlicher
- ❌ Der Entwickler ist **nicht** Auftragsverarbeiter
- ❌ Der Entwickler haftet **nicht** für Ihre Datenverarbeitung
- ℹ️ Der Entwickler stellt nur die Software bereit ("as is")

---

## 2. Checkliste: DSGVO-Konformität

### Phase 1: Vor Inbetriebnahme

- [ ] **Rechtsgrundlage** definieren (siehe unten)
- [ ] **Datenschutzerklärung** erstellen und veröffentlichen
- [ ] **Verzeichnis von Verarbeitungstätigkeiten** anlegen (Art. 30 DSGVO)
- [ ] **Mitglieder informieren** (z.B. Mitgliederversammlung, Rundmail)
- [ ] Falls erforderlich: **Datenschutzbeauftragten** bestellen
- [ ] **Auftragsverarbeitungsvertrag (AVV)** mit Hosting-Provider abschließen
- [ ] Nur falls **Pünktlichkeit oder Zuverlässigkeit** eingeschaltet werden sollen: Abschnitt 11
      lesen und den Zweck festlegen, **bevor** der Schalter umgelegt wird

### Phase 2: Technische Maßnahmen

- [ ] **HTTPS aktiviert** (SSL/TLS-Zertifikat)
- [ ] **Sichere Passwörter** erzwingen (Mind. 8 Zeichen)
- [ ] **Zugriffskontrolle** implementiert (Rollen: Admin/Manager/User)
- [ ] **Backups** erstellen und verschlüsselt speichern
- [ ] **Server-Logs** prüfen und ggf. IP-Anonymisierung
- [ ] **Session-Timeouts** aktiviert

### Phase 3: Organisation

- [ ] **Löschkonzept** erstellt (Wann werden Daten gelöscht?)
- [ ] **Prozess für Betroffenenrechte** definiert (Auskunft, Löschung, etc.)
- [ ] **Datenpanne-Prozess** etabliert (Was tun bei Sicherheitsvorfällen?)
- [ ] **Schulung** der Administratoren/Manager

---

## 3. Rechtsgrundlagen für die Verarbeitung

Sie benötigen eine **Rechtsgrundlage** für die Datenverarbeitung (Art. 6 DSGVO).

### Option A: Berechtigtes Interesse (Art. 6 Abs. 1 lit. f DSGVO)

**Geeignet für:** Vereinsverwaltung, Organisation ehrenamtlicher Tätigkeiten

**Voraussetzungen:**
- Berechtigtes Interesse muss überwiegen
- Interessen der Betroffenen nicht verletzt
- Transparenz gewährleisten

**Formulierung:**
> Die Verarbeitung erfolgt auf Grundlage unseres berechtigten Interesses 
> (Art. 6 Abs. 1 lit. f DSGVO) zur Organisation und Verwaltung 
> ehrenamtlicher Tätigkeiten im Verein.

### Option B: Einwilligung (Art. 6 Abs. 1 lit. a DSGVO)

**Geeignet für:** Sensible Daten, besondere Kategorien

**Voraussetzungen:**
- Freiwillig, informiert, unmissverständlich
- Widerrufbar jederzeit
- Dokumentiert

**Formulierung:**
> Hiermit willige ich ein, dass [Vereinsname] meine Daten zur Organisation 
> ehrenamtlicher Tätigkeiten verarbeitet. Die Einwilligung kann ich 
> jederzeit widerrufen.

### Option C: Vertragserfüllung (Art. 6 Abs. 1 lit. b DSGVO)

**Geeignet für:** Falls Mitgliedschaft vertragliches Verhältnis darstellt

---

## 4. Welche Daten verarbeitet EhrenSache?

### Personenbezogene Daten

- **Name, Vorname** (Identifikation)
- **Mitgliedsnummer** (optional, interne Verwaltung)
- **E-Mail** (optional, Kommunikation)
- **Gruppenzugehörigkeit** (z.B. "Vorstand", "Jugend")
- **Anwesenheitszeiten** (Datum, Uhrzeit, Dauer)
- **Ausnahmen** (Urlaub, Krankheit - optional)
- **Arbeitszeiten** (nur bei aktivierter Zeiterfassung, siehe Abschnitt 10):
  Beginn, Ende, Pausendauer, Tätigkeitsart, freie Notiz
- **Ortsnachweise** (nur bei aktivierter Zeiterfassung): Name der Station, an
  der Beginn oder Ende bestätigt wurde
- **Änderungshistorie der Arbeitszeiten**: wer wann welchen Wert geändert,
  freigegeben, abgelehnt oder gelöscht hat
- **Stations-PIN** (nur als Hash; Zeitpunkt der letzten Änderung): wird mit dem Mitglied gelöscht
- **Pünktlichkeit und Zuverlässigkeit** (nur wenn eingeschaltet, siehe Abschnitt 11): keine
  gespeicherten Daten, sondern Kennzahlen, die bei jedem Aufruf aus Anwesenheiten und Abmeldungen
  berechnet werden

### Technische Daten

- **IP-Adressen** (temporär in Server-Logs)
- **Session-Tokens** (temporär)
- **Login-Zeitpunkte** (optional in Logs)

---

### Update-Prüfung

Die Update-Prüfung in den Einstellungen und Schritt 0 des Update-Assistenten fragen
`api.github.com` nach der neuesten Version und laden gegebenenfalls das Paket. Dabei erfährt
GitHub die IP-Adresse des **Servers** — nicht die eines Mitglieds. Beides geschieht nur, wenn ein
Administrator den Knopf drückt; einen automatischen Abruf gibt es nicht. Wer das nicht möchte,
lädt Updates von Hand herunter.

## 5. Betroffenenrechte

Mitglieder haben folgende Rechte:

| Recht | Umsetzung in EhrenSache |
|-------|-------------------------|
| **Auskunft** (Art. 15) | Export-Funktion nutzen; enthält auch Arbeitszeiten und deren Änderungshistorie |
| **Berichtigung** (Art. 16) | Editier-Funktion nutzen |
| **Löschung** (Art. 17) | Lösch-Funktion nutzen |
| **Einschränkung** (Art. 18) | Deaktivierung des Accounts |
| **Datenübertragbarkeit** (Art. 20) | CSV-Export nutzen |
| **Widerspruch** (Art. 21) | Löschung oder Einschränkung |

**Wichtig:** Sie müssen Anfragen binnen **1 Monat** beantworten.

Die Selbstauskunft (JSON und CSV) enthält seit 1.3.0 zur Stations-PIN nur `has_pin` und
`pin_updated_at` (bzw. die Zeilen "Stations-PIN gesetzt" und "PIN zuletzt geändert") — nie den
Hash selbst.

---

## 6. Muster-Texte

### 6.1 Datenschutzerklärung (Auszug)
```
Datenschutzerklärung - [Vereinsname]

1. Verantwortlicher
[Vereinsname]
[Adresse]
[E-Mail]

2. Anwesenheitserfassung

Wir verarbeiten folgende personenbezogene Daten zur Organisation 
ehrenamtlicher Tätigkeiten:

- Name, Vorname
- Mitgliedsnummer (falls vorhanden)
- Anwesenheitszeiten (Datum, Uhrzeit, Dauer)
- Gruppenzugehörigkeit
- Optional: E-Mail-Adresse

Rechtsgrundlage: Berechtigtes Interesse (Art. 6 Abs. 1 lit. f DSGVO) 
zur Organisation und Verwaltung ehrenamtlicher Tätigkeiten.

Speicherdauer: [X Jahre] ab letzter Anwesenheit oder bis Vereinsaustritt.

3. Empfänger der Daten

Daten werden verarbeitet durch:
- Vereinsvorstand (Administratoren)
- [Name des Hosting-Providers] (Auftragsverarbeiter)

4. Ihre Rechte

Sie haben das Recht auf:
- Auskunft (Art. 15 DSGVO)
- Berichtigung (Art. 16 DSGVO)
- Löschung (Art. 17 DSGVO)
- Einschränkung (Art. 18 DSGVO)
- Datenübertragbarkeit (Art. 20 DSGVO)
- Widerspruch (Art. 21 DSGVO)
- Beschwerde bei Aufsichtsbehörde

Kontakt: [E-Mail des Vereins]
```

### 6.2 Einwilligungserklärung (falls gewünscht)
```
Einwilligung zur Datenverarbeitung

Hiermit willige ich ein, dass [Vereinsname] meine personenbezogenen Daten 
(Name, Vorname, Anwesenheitszeiten) zur Organisation ehrenamtlicher 
Tätigkeiten im Verein verarbeitet.

Die Einwilligung kann ich jederzeit mit Wirkung für die Zukunft widerrufen. 
Ein Widerruf berührt die Rechtmäßigkeit der bis dahin erfolgten Verarbeitung 
nicht.

_______________________  ___________________  ________________________
Ort, Datum               Unterschrift         Name in Druckbuchstaben
```

### 6.3 Information an Mitglieder (Rundmail)
```
Betreff: Neue Anwesenheitserfassung - Datenschutzinformation

Liebe Mitglieder,

ab [Datum] nutzen wir EhrenSache zur digitalen Erfassung von Anwesenheitszeiten.

Folgende Daten werden verarbeitet:
- Name, Vorname
- Anwesenheitszeiten

Rechtsgrundlage: Berechtigtes Interesse zur Vereinsorganisation 
(Art. 6 Abs. 1 lit. f DSGVO)

Eure Rechte:
- Auskunft über gespeicherte Daten
- Berichtigung, Löschung, Einschränkung
- Widerspruch gegen die Verarbeitung

Unsere vollständige Datenschutzerklärung: [Link]

Bei Fragen: [E-Mail Datenschutzbeauftragter/Vorstand]

Mit freundlichen Grüßen
[Vorstand]
```

---

## 7. Verzeichnis von Verarbeitungstätigkeiten

**Pflicht nach Art. 30 DSGVO** (sofern nicht < 250 Mitarbeiter UND keine 
besonderen Kategorien von Daten)

### Muster-Eintrag
```
Verarbeitungstätigkeit: Anwesenheitserfassung ehrenamtlicher Tätigkeiten

Verantwortlicher: [Vereinsname, Adresse, Kontakt]

Zweck: Organisation und Nachweis ehrenamtlicher Tätigkeiten

Kategorien Betroffener: Vereinsmitglieder

Kategorien Daten: 
- Stammdaten (Name, Vorname, Mitgliedsnummer)
- Anwesenheitsdaten (Datum, Uhrzeit, Dauer)

Kategorien Empfänger:
- Vereinsvorstand (Administratoren)
- [Hosting-Provider] (Auftragsverarbeiter)

Übermittlung Drittland: Nein

Löschfristen: [X Jahre] nach letzter Anwesenheit oder Vereinsaustritt

Technische/organisatorische Maßnahmen:
- Verschlüsselte Übertragung (HTTPS)
- Zugriffskontrolle (Passwörter, Rollen)
- Regelmäßige Backups
- Server-Standort: [Deutschland/EU]
```

---

## 8. Auftragsverarbeitungsvertrag (AVV)

Falls Sie einen **externen Hosting-Provider** nutzen, benötigen Sie einen 
**Auftragsverarbeitungsvertrag** (Art. 28 DSGVO).

### Typische Hosting-Provider mit AVV-Vorlagen:

- **All-Inkl.com**: AVV verfügbar
- **Hetzner**: AVV verfügbar
- **IONOS**: AVV verfügbar
- **Strato**: AVV verfügbar

**Wichtig:** AVV VOR Inbetriebnahme abschließen!

---

## 9. Datenpanne-Management

**Bei Sicherheitsvorfall (z.B. Hack, Datenleck):**

1. **Sofort:** Vorfall dokumentieren
2. **Binnen 72h:** Aufsichtsbehörde melden (falls Risiko für Betroffene)
3. **Unverzüglich:** Betroffene informieren (falls hohes Risiko)

**Zuständige Aufsichtsbehörde:** 
Je nach Bundesland → Google: "Datenschutzbeauftragte [Bundesland]"

---

## 10. Zeiterfassung (optionales Modul)

Die Zeiterfassung ist **standardmäßig abgeschaltet**. Solange die Einstellung
`worktime_enabled` auf `0` steht, werden keinerlei Arbeitszeitdaten erhoben, und
die Funktion ist weder im Dashboard noch in der PWA sichtbar. Alles in diesem
Abschnitt gilt erst, wenn Sie sie einschalten.

### 10.1 Warum dieser Abschnitt eigenständig ist

Eine Anwesenheitsliste hält fest, *dass* jemand da war. Eine Zeiterfassung mit
Beginn, Ende und Pausen hält fest, *wie lange* und *mit welchen Unterbrechungen*.
Das ist eine deutlich tiefere Verarbeitung — sie erlaubt Rückschlüsse auf
Arbeitstempo, Belastung und Tagesablauf. Bewerten Sie sie nicht als Nebensache
der Anwesenheitserfassung.

### 10.2 Zweckbindung

Legen Sie den Zweck **vor** der Aktivierung schriftlich fest. Üblich sind:

- **Nachweis geleisteter Stunden** gegenüber dem Mitglied selbst, für
  Ehrenamtskarte, Ehrungen oder Bescheinigungen
- **Verwendungsnachweis** gegenüber Fördergebern, aufgeschlüsselt nach
  Tätigkeitsart

Nicht gedeckt ist ohne gesonderte Grundlage: Leistungsvergleiche zwischen
Mitgliedern, Ableitung von Anwesenheitsprofilen oder die Weitergabe an Dritte
außerhalb des festgelegten Nachweiszwecks.

### 10.3 Rechtsgrundlage

Für den Nachweis gegenüber Fördergebern kommt regelmäßig ein berechtigtes
Interesse (Art. 6 Abs. 1 lit. f) in Betracht — der Verein muss die Mittelverwendung
belegen können. Für den personenbezogenen Stundennachweis, der dem Mitglied
selbst nützt, ist eine Einwilligung (Art. 6 Abs. 1 lit. a) oft der sauberere Weg,
weil die Erfassung dann freiwillig bleibt.

Prüfen Sie das für Ihren Fall. Ein Verein, der Stunden erfasst, ohne dass jemand
sie braucht, hat keinen Zweck — und damit keine Rechtsgrundlage.

### 10.4 Speicherdauer und Löschung

| Daten | Empfohlene Frist | Begründung |
|-------|------------------|------------|
| Arbeitszeiten (`work_sessions`) | Bis zum Ablauf der Nachweispflicht gegenüber dem Fördergeber, sonst 3 Jahre | Verwendungsnachweise werden meist mehrere Jahre nach Bewilligung geprüft |
| Änderungshistorie (`work_session_log`) | Wie die zugehörige Arbeitszeit | Sie belegt die Unverfälschtheit des Nachweises |
| Ortsnachweise | Wie die zugehörige Arbeitszeit | Ohne sie verliert der Nachweis seine Aussagekraft |

**Wichtig:** Die Änderungshistorie überlebt das Löschen einer Arbeitszeit
**absichtlich** — sonst würde ausgerechnet die Löschung nicht dokumentiert. Sie
enthält damit personenbezogene Daten, die den eigentlichen Datensatz überdauern.

Seit 1.2.5 setzen Sie die Fristen unter *Einstellungen → DSGVO
Datenverwaltung*. Es gibt drei, weil die Daten unterschiedlich lange gebraucht
werden:

| Frist | Wirkung |
|-------|---------|
| Anwesenheiten und Ausnahmen | löscht `records` und `exceptions` |
| Arbeitszeiten samt Änderungshistorie | löscht `work_sessions` und die dazugehörigen `work_session_log`-Einträge |
| Änderungshistorie ohne Sitzung | **anonymisiert** verwaiste `work_session_log`-Einträge |

Verwaiste Einträge entstehen, wenn eine Sitzung gelöscht wurde, ihre Historie
aber bestehen blieb. Sie werden nicht gelöscht, sondern anonymisiert: Der
Inhalt der Änderung und der handelnde Benutzer fallen weg, der Eintrag selbst
bleibt mit Zeitpunkt und Art der Änderung stehen. So belegt die Spur weiterhin,
dass an dieser Stelle etwas geschah, ohne noch einen Personenbezug zu haben.

**Die Bereinigung läuft nicht von selbst.** Sie stoßen sie an; die Fristen sind
nur die Vorgabe dafür. Nehmen Sie den Vorgang in Ihre wiederkehrenden Aufgaben
auf — einmal im Jahr genügt in der Regel.

**Es gibt kein Zurück.** Die Bereinigung löscht endgültig und kennt keinen
Probelauf. Legen Sie vorher eine Sicherung der Datenbank an.

**Solange die dritte Frist nicht abgelaufen ist**, enthält ein verwaister
Eintrag weiterhin die Daten der gelöschten Sitzung, erscheint aber in keiner
Selbstauskunft — die Auskunft findet ihn ohne seine Sitzung nicht mehr.
Setzen Sie diese Frist deshalb kurz an.

### 10.5 Abgrenzung zum Beschäftigungsverhältnis

Eine Erfassung von Arbeits- **und Pausenzeiten** ist ein typisches Merkmal eines
Beschäftigungsverhältnisses. Wird sie im Ehrenamt eingeführt, kann das
zusammen mit weiteren Merkmalen — Weisungsgebundenheit, feste Dienstpläne,
Vergütung über die Aufwandsentschädigung hinaus — die Abgrenzung verwischen.

Die Folgen träfen den Verein, nicht die Software: Sozialversicherungspflicht,
Lohnsteuer, Arbeitszeitgesetz. Dies ist **keine Rechtsberatung**. Lassen Sie den
Punkt prüfen, bevor Sie die Zeiterfassung scharf schalten, insbesondere wenn
Übungsleiter- oder Ehrenamtspauschalen gezahlt werden.

### 10.6 Was Sie den Mitgliedern sagen müssen

Informieren Sie **vor** der Aktivierung, mindestens über:

- dass Beginn, Ende und Pausen erfasst werden
- ob die Erfassung freiwillig ist und was passiert, wenn jemand nicht mitmacht
- wofür die Stunden verwendet werden und wer sie sieht (Manager sehen die
  Einträge **aller** Mitglieder)
- dass Manager Einträge korrigieren können und jede Änderung protokolliert wird
- wie lange die Daten gespeichert bleiben
- dass bei Tätigkeiten mit Ortsnachweis festgehalten wird, an welcher Station
  jemand war

### 10.7 Technische Hinweise

- Der Ortsnachweis beruht auf einem gemeinsamen Geheimnis der Station. Dieses
  liegt derzeit unverschlüsselt in der Datenbank und ist in der
  Geräte-Verwaltung lesbar. Wer Administrator- oder Manager-Zugang hat, könnte
  Ortsnachweise erzeugen. Der Nachweis schützt daher gegen Nachlässigkeit, nicht
  gegen Vorsatz von innen — beschreiben Sie ihn gegenüber Fördergebern nicht
  stärker, als er ist.
- Alle Zeitstempel stammen vom Server, nicht vom Gerät des Mitglieds.
- Die Zeiterfassung lässt sich jederzeit wieder abschalten. Bereits erfasste
  Daten bleiben dabei in der Datenbank; löschen Sie sie gesondert, wenn der
  Zweck entfallen ist.
- Stempel an einer virtuellen Station (Mitgliedsnummer + PIN) belegen den **Ort**, nicht
  sicher die **Person**: Eine PIN ist weitergebbar. Anwesenheits-Datensätze tragen die
  Quelle `station_pin` und sind in der Oberfläche mit dem Badge „Station (PIN)"
  gekennzeichnet. Arbeitszeit-Sitzungen vom Kiosk tragen im Datensatz und im Export die
  Quelle `station`; in der Oberfläche zeigt dafür kein eigenes Badge, sondern der
  Stations-/Kiosk-Name als Start- und Endort erkennt den Eintrag. Am Kiosk gilt die
  Notizpflicht der Zeiterfassung nicht. Fehlversuche werden 15 Minuten lang gezählt
  (Sperre je Mitgliedsnummer und je Station, auch bei unbekannter Nummer); die
  eingegebene PIN selbst wird dabei nicht gespeichert.

---

## 11. Pünktlichkeit und Zuverlässigkeit (optionale Kennzahlen)

Beide Kennzahlen sind **standardmäßig abgeschaltet** (`punctuality_enabled` und
`reliability_enabled` stehen auf `0`). Solange das so bleibt, werden sie weder berechnet noch
angezeigt. Ein Update schaltet sie nicht ein. Alles in diesem Abschnitt gilt erst, wenn Sie einen
der Schalter umlegen.

### 11.1 Warum dieser Abschnitt eigenständig ist

Eine Anwesenheitsliste hält fest, *dass* jemand da war. Diese Kennzahlen **bewerten**, *wie*:
wie oft jemand rechtzeitig kam und ob er abgesagt hat, wenn er nicht kommen konnte. Die DSGVO
nennt bei der Begriffsbestimmung des Profilings (Art. 4 Nr. 4) ausdrücklich die Analyse von
„Zuverlässigkeit" und „Verhalten". Behandeln Sie die Kennzahlen deshalb nicht als Anzeigevariante
der Anwesenheit.

Gespeichert wird dabei nichts Neues. Die Werte entstehen bei jedem Aufruf aus Daten, die ohnehin
vorliegen: Ankunftszeiten, Abmeldungen, Entschuldigungen.

### 11.2 Zweckbindung

Legen Sie den Zweck **vor** dem Einschalten fest und halten Sie ihn im Verzeichnis von
Verarbeitungstätigkeiten fest. Naheliegend ist:

- **Planung** — wie verlässlich ist eine Gruppe besetzt, reicht die Probezeit
- **Rückmeldung an das Mitglied selbst**, das seine eigenen Werte sieht

Nicht gedeckt ist ohne gesonderte Grundlage: öffentliche Vergleiche zwischen Mitgliedern,
Aushänge, Ranglisten, oder Entscheidungen über Mitgliedschaft, Besetzung oder Ämter, die **allein**
auf der Kennzahl beruhen. Letzteres berührt Art. 22 DSGVO (automatisierte Einzelentscheidung) —
eine Kennzahl darf einer Entscheidung zuarbeiten, sie aber nicht ersetzen.

### 11.3 Rechtsgrundlage

In Betracht kommt regelmäßig ein berechtigtes Interesse (Art. 6 Abs. 1 lit. f) an einem
verlässlichen Proben- oder Spielbetrieb, oder eine entsprechende Regelung in der Satzung. Beim
berechtigten Interesse haben Mitglieder ein **Widerspruchsrecht** (Art. 21) — planen Sie, wie Sie
damit umgehen, bevor der erste Widerspruch kommt.

Prüfen Sie das für Ihren Verein. Wer die Werte nicht nutzt, braucht sie nicht einzuschalten.

### 11.4 Was die Kennzahlen aussagen — und was nicht

- **Pünktlichkeit** wird nur über Ankünfte mit bekannter Uhrzeit gerechnet: Stempel an Station,
  Gerät oder App, vom Verwalter eingetragene Uhrzeiten und genehmigte Zeitkorrekturen. Einträge
  ohne Uhrzeit, importierte Daten und abgehakte Listen zählen nicht. Unter fünf Messungen gibt es
  keine Quote.
- **Zuverlässigkeit** zählt, wer erschienen ist oder rechtzeitig abgesagt hat. Eine Abmeldung
  zählt nach dem Zeitpunkt, zu dem sie eingegangen ist, nicht nach dem Zeitpunkt der Freigabe.
  Seit 1.7.0 gilt auch eine Terminrückmeldung „Absage" als Abmeldung. Bei Terminarten mit
  Rückmeldung ist „rechtzeitig" die dort eingestellte Frist, sonst der Terminbeginn.

Beide Werte sind so gut wie die Erfassung dahinter. Ein Verein, der überwiegend Listen abhakt, hat
wenige Messungen und damit eine wenig belastbare Pünktlichkeit — das zeigt die Oberfläche als
Messabdeckung an.

### 11.5 Wer was sieht

| Rolle | Sicht |
|---|---|
| Admin, Manager | Werte für den gewählten Bereich (Verein, Gruppe) und für jede einzelne Person, die sie gezielt aufrufen |
| Mitglied | ausschließlich die eigenen Werte |
| Gerät | nichts |

Eine Liste, in der alle Mitglieder nach Pünktlichkeit nebeneinanderstehen, erzeugt EhrenSache
nicht. Wer eine solche Übersicht selbst anfertigt, verlässt den in 11.2 beschriebenen Rahmen.

### 11.6 Speicherdauer und Auskunft

Eine eigene Speicherdauer gibt es nicht: Die Kennzahlen verschwinden mit den Anwesenheiten und
Abmeldungen, aus denen sie berechnet werden (Löschfrist für Anwesenheiten und Ausnahmen unter
*Einstellungen → DSGVO Datenverwaltung*).

Die eigenen Werte je Jahr erscheinen in der Selbstauskunft des Mitglieds (Profil → „Meine Daten",
JSON und CSV) — solange die jeweilige Kennzahl eingeschaltet ist.

### 11.7 Was Sie den Mitgliedern sagen sollten

Vor dem Einschalten, zum Beispiel in der Rundmail aus Abschnitt 6.3:

- dass und wozu Pünktlichkeit bzw. Zuverlässigkeit ausgewertet werden
- wer die Werte sieht (11.5)
- dass jedes Mitglied seine eigenen Werte einsehen kann
- wie ein Widerspruch eingelegt werden kann

---

## 12. Terminrückmeldungen (optional je Terminart)

Die Rückmeldung ist **ab Werk bei jeder Terminart ausgeschaltet**. Ein Update schaltet sie nicht
ein. Alles in diesem Abschnitt gilt für Terminarten, bei denen Sie „Rückmeldung erbeten" setzen.

### 12.1 Was gespeichert wird

Je Mitglied und Termin der aktuelle Stand: Zusage, Absage oder „unsicher", eine freiwillige
Bemerkung und die Zeitpunkte der letzten Status- und der letzten Änderung. Ein Verlauf früherer
Antworten wird nicht geführt. Bei Terminarten mit Entschuldigungspflicht entsteht aus einer Absage
zusätzlich ein Abwesenheitsantrag, der wie jeder Antrag behandelt wird.

### 12.2 Zweck und Rechtsgrundlage

Zweck ist die **Planung** eines Termins: ob die Besetzung reicht, wo Aushilfen nötig sind. Als
Rechtsgrundlage kommt dieselbe in Betracht wie für die Anwesenheit (Abschnitt 3) — in der Regel
das berechtigte Interesse an einem geordneten Proben- und Spielbetrieb.

Die Bemerkung ist ein Freitext. Weisen Sie darauf hin, dass dort **keine Gesundheitsangaben**
nötig sind: „verhindert" genügt. Eine Diagnose in der Bemerkung wäre ein Gesundheitsdatum nach
Art. 9 DSGVO.

### 12.3 Wer was sieht

| Rolle | Sicht |
|---|---|
| Admin, Manager | alle Antworten mit Bemerkung und Zeitpunkt, nach Beginn den Abgleich mit der Anwesenheit |
| Mitglied | die eigene Antwort und die Summen; Namen und Status der anderen **nur**, wenn die Terminart „Namen für Mitglieder sichtbar" hat — Bemerkungen nie |
| Gerät | nichts |

„Namen für Mitglieder sichtbar" legt innerhalb des Vereins offen, wer zu- oder abgesagt hat. Das
kann die Verbindlichkeit erhöhen, ist aber eine Offenlegung personenbezogener Daten gegenüber
anderen Mitgliedern. Schalten Sie es nur ein, wenn Sie das den Mitgliedern vorher mitgeteilt
haben und der Zweck es trägt.

### 12.4 Zuverlässigkeit

Ist die Zuverlässigkeit (Abschnitt 11) eingeschaltet, zählt eine rechtzeitige Absage als
Abmeldung. Eine eigene Kennzahl „Zusagetreue" je Person gibt es nicht; der Abgleich von Zusage und
Anwesenheit wird nur je Termin angezeigt.

### 12.5 Speicherdauer und Auskunft

Rückmeldungen werden mit derselben Frist gelöscht wie Anwesenheiten, bezogen auf das
Termindatum (*Einstellungen → DSGVO Datenverwaltung*), und mit dem Termin oder dem Mitglied. Die
eigenen Rückmeldungen stehen in der Selbstauskunft (Profil → „Meine Daten", JSON und CSV).

### 12.6 Was Sie den Mitgliedern sagen sollten

- für welche Terminarten Rückmeldungen erbeten sind und wozu
- wer die Antworten sieht (12.3), insbesondere ob andere Mitglieder Namen sehen
- dass die Bemerkung freiwillig ist und keine Gründe im Einzelnen verlangt

---

## 13. Hilfreiche Links & Ressourcen

### Gesetzestexte
- **DSGVO**: https://dsgvo-gesetz.de
- **BDSG**: https://www.gesetze-im-internet.de/bdsg_2018/

### Aufsichtsbehörden
- **Übersicht**: https://www.bfdi.bund.de/DE/Infothek/Anschriften_Links/anschriften_links-node.html
- **Datenschutzkonferenz**: https://www.datenschutzkonferenz-online.de

### Generatoren & Tools
- **Datenschutzerklärung-Generator**: https://www.datenschutz-generator.de
- **AVV-Generator**: https://www.datenschutz-notizen.de/avv-generator/

### Vereine & DSGVO
- **DOSB-Leitfaden**: https://www.dosb.de (Suche: "DSGVO")
- **Vereinsknowhow**: https://www.vereinsknowhow.de/datenschutz

---

## 14. Häufige Fragen (FAQ)

**Q: Müssen wir einen Datenschutzbeauftragten bestellen?**  
A: Nur falls mind. 20 Personen ständig mit automatisierter Datenverarbeitung 
beschäftigt sind (§ 38 BDSG). Bei kleinen Vereinen meist nicht nötig.

**Q: Wie lange dürfen wir Daten speichern?**  
A: So lange wie für den Zweck erforderlich. Üblich: 1-3 Jahre nach 
Vereinsaustritt oder letzter Aktivität.

**Q: Dürfen Mitglieder ihre eigenen Daten sehen?**  
A: Ja! Auskunftsrecht nach Art. 15 DSGVO. EhrenSache bietet Export-Funktion.

**Q: Was passiert bei DSGVO-Verstoß?**  
A: Bußgelder bis 20 Mio. € oder 4% des Jahresumsatzes (bei Vereinen selten, 
aber Abmahnungen möglich).

---

## 15. Disclaimer

**Keine Rechtsberatung**: Diese Hinweise dienen der Orientierung und 
ersetzen keine individuelle Rechtsberatung. Im Zweifel konsultieren Sie 
einen Datenschutzbeauftragten oder Fachanwalt für IT-Recht.

**Keine Garantie**: Der Entwickler von EhrenSache übernimmt keine Haftung 
für die DSGVO-Konformität Ihrer Datenverarbeitung.

**Stand**: Februar 2026

---

Bei rechtlichen Fragen: Konsultieren Sie einen Anwalt.