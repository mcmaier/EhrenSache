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

### Technische Daten

- **IP-Adressen** (temporär in Server-Logs)
- **Session-Tokens** (temporär)
- **Login-Zeitpunkte** (optional in Logs)

---

## 5. Betroffenenrechte

Mitglieder haben folgende Rechte:

| Recht | Umsetzung in EhrenSache |
|-------|-------------------------|
| **Auskunft** (Art. 15) | Export-Funktion nutzen |
| **Berichtigung** (Art. 16) | Editier-Funktion nutzen |
| **Löschung** (Art. 17) | Lösch-Funktion nutzen |
| **Einschränkung** (Art. 18) | Deaktivierung des Accounts |
| **Datenübertragbarkeit** (Art. 20) | CSV-Export nutzen |
| **Widerspruch** (Art. 21) | Löschung oder Einschränkung |

**Wichtig:** Sie müssen Anfragen binnen **1 Monat** beantworten.

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

## 10. Hilfreiche Links & Ressourcen

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

## 11. Häufige Fragen (FAQ)

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

## 12. Disclaimer

**Keine Rechtsberatung**: Diese Hinweise dienen der Orientierung und 
ersetzen keine individuelle Rechtsberatung. Im Zweifel konsultieren Sie 
einen Datenschutzbeauftragten oder Fachanwalt für IT-Recht.

**Keine Garantie**: Der Entwickler von EhrenSache übernimmt keine Haftung 
für die DSGVO-Konformität Ihrer Datenverarbeitung.

**Stand**: Februar 2026

---

Bei rechtlichen Fragen: Konsultieren Sie einen Anwalt.