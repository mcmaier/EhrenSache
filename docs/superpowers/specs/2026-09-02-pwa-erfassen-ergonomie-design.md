# Design: Erfassen-Einstieg der PWA und Gruppenbindung der Tätigkeitsarten

**Datum:** 2026-09-02
**Status:** Approved
**Betrifft:** `public/checkin/`, `private/handlers/activity_types.php`,
`private/handlers/work_sessions.php`, `public/js/modules/worktime.js`, neue Migration

---

## Problem

Die PWA modelliert **Werkzeuge als Tabs**, nicht Absichten. „Check-in“ ist kein Ziel, sondern
ein Mittel — der Scanner. Zwei verschiedene Absichten brauchen dasselbe Mittel:

- Anwesenheit bei einem Termin erfassen
- eine Arbeitszeitsitzung mit Ortsnachweis starten

Weil der Scanner nur im Check-in-Tab existiert, schickt der Zeit-Tab das Mitglied dorthin und
biegt das Verhalten über eine Modulvariable um (`totpCodeConsumer`, `app.js`). Diese Variable
ist unsichtbar, hat kein Zeitlimit und überlebt Tab-Wechsel.

### Der konkrete Fehlerweg

1. Zeit-Tab, nachweispflichtige Tätigkeit, Start → Wechsel zum Check-in-Tab, Umleitung gesetzt
2. Mitglied scannt nicht und geht zurück auf „Zeit“ — die Umleitung bleibt bestehen, weil der
   Tab-Handler sie nur für andere Ziele verwirft
3. Mitglied geht auf „Check-in“, um sich normal für einen Termin einzuchecken — die äußere
   Bedingung `if (targetTab !== 'checkin')` überspringt das Aufräumen vollständig
4. Der Scan **startet die Arbeitszeitsitzung**, statt die Anwesenheit zu erfassen

Verschärfend: Der Hinweis „Code für den Start der Zeiterfassung scannen“ läuft über
`showMessage()` und verschwindet nach fünf Sekunden. Der in `requestTotpCode()` mitgegebene
`hint` wird gespeichert, aber nirgends gerendert. Danach gibt es kein sichtbares Signal mehr,
in welchem Modus der Scanner steht.

Die so entstandene Sitzung läuft unbemerkt bis zu `worktime_max_session_hours` (Standard 12)
weiter und blockiert jeden weiteren Start mit 409.

### Warum keine Absicherung des Zustands genügt

Ein Verfallszeitpunkt, ein zusätzlicher Abbruch-Knopf oder eine reparierte Tab-Bedingung
behandeln das Symptom. Solange der Zweck eines Scans in einer Variablen steht, die von der
sichtbaren Oberfläche entkoppelt ist, entstehen neue Wege, sie zu veralten.

---

## Leitgedanke

**Der Zweck eines Scans ist eine Funktion der sichtbaren Ansicht, kein separater Zustand.**

Ein Zustand, den es nicht gibt, kann nicht veralten. Der Scanner wird nur noch über eine
Absicht erreichbar; der Zweck ergibt sich aus dem Navigationspfad.

---

## Umfang

**Enthalten**

1. Gruppenbindung der Tätigkeitsarten (Backend, Dashboard, Migration)
2. Zusammengeführter Erfassen-Tab in der PWA mit vorgeschalteter Absichtswahl
3. Global sichtbare Leiste für eine laufende Arbeitszeitsitzung
4. Zusammengeführter Verlauf aus Anwesenheiten, Anträgen und Arbeitszeiten
5. Verständliche Fehlermeldungen in der PWA

**Bewusst nicht enthalten**

| Punkt | Begründung |
|---|---|
| Statistik-Zusammenführung | Aufwändigster Teil, ohne Bezug zum Kernproblem. Es ist ungeklärt, welche Frage die Seite dem Mitglied beantworten soll — eigenes Brainstorming. |
| Terminauswahl über heute hinaus | Eigener Fachpunkt, siehe OI-4 in `docs/OPEN-ITEMS.md`. |
| Offline-Betrieb | Verworfen, siehe `docs/OPEN-ITEMS.md`. |
| PWA im Stations-Modus | Gehört zu OI-6. |

---

## Teil 1 — Gruppenbindung der Tätigkeitsarten

### Ausgangslage

Die Zeiterfassung kennt heute **keine** Zuordnung pro Mitglied oder Gruppe. Sie ist global
an oder aus; jedes Mitglied sieht jede Tätigkeitsart. Für Terminarten existiert das Gegenstück
bereits als `appointment_type_groups` — die Tätigkeitsarten sind die Ausnahme.

### Datenmodell

```sql
CREATE TABLE IF NOT EXISTS `{PREFIX}activity_type_groups` (
  activity_id INT NOT NULL,
  group_id    INT NOT NULL,
  PRIMARY KEY (activity_id, group_id),
  CONSTRAINT `{PREFIX}atg_activity_fk` FOREIGN KEY (activity_id)
      REFERENCES `{PREFIX}activity_types`(activity_id) ON DELETE CASCADE,
  CONSTRAINT `{PREFIX}atg_group_fk` FOREIGN KEY (group_id)
      REFERENCES `{PREFIX}member_groups`(group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Aufbau und Namensgebung folgen `{PREFIX}appointment_type_groups`
(`private/setup/ehrensache_db.sql`).

### Migration

Neue Datei `private/migrations/1.2.0.php` mit `migrate_1_2_0()` plus ein Manifest-Eintrag
`from: '1.2.0'`, `to: '1.2.1'`. Die letzte Kette endet heute bei `to: '1.2.0'`
(`private/migrations/manifest.php`), der neue Schritt setzt also darauf auf. `version.json` und
`CHANGELOG.md` sind gemeinsam auf `1.2.1` zu heben; weicht die dort gepflegte Version ab, gilt
sie und der Manifest-Eintrag wird angeglichen.

Die Migration ordnet **alle bestehenden Tätigkeitsarten allen bestehenden Gruppen zu**. Das ist
die einzig vertretbare Wahl: Jede andere Vorbelegung ließe in bestehenden Installationen
Tätigkeiten verschwinden, bis ein Administrator eingreift.

Die Migration ist wiederholbar zu schreiben (Existenzprüfung der Tabelle, `INSERT IGNORE`),
wie in `1.1.3.php` vorgemacht.

Ebenfalls nachzuziehen: `private/setup/ehrensache_db.sql`.

### Handler `private/handlers/activity_types.php`

Vorbild ist durchgängig `private/handlers/appointment_types.php`:

| Änderung | Vorlage |
|---|---|
| `GET` liefert je Tätigkeitsart die zugeordneten Gruppen mit | `appointment_types.php:30,50` |
| `POST`/`PUT` schreiben die Zuordnung (erst löschen, dann einfügen) | `appointment_types.php:86,121` |
| `GET` mit Parameter `member_id` filtert nach den Gruppen dieses Mitglieds | `appointments.php:59` |

**Filterregel.** Wie in `appointments.php:59` entscheidet die Rolle zusammen mit dem Parameter:

| Aufrufer | Parameter | Ergebnis |
|---|---|---|
| Rolle `user` | egal | gefiltert nach den Gruppen des **eigenen** Mitglieds |
| Administrator / Manager | ohne `member_id` | alle Tätigkeitsarten |
| Administrator / Manager | `member_id=<id>` | gefiltert nach den Gruppen dieses Mitglieds |

Damit braucht die PWA ihren Aufruf nicht zu ändern: Ein Mitglied bekommt ohne Zutun die eigene
Auswahl. Administrator und Manager erhalten ungefiltert alles, was sie für Nachträge zugunsten
anderer brauchen — konsistent zur bestehenden Regel, dass Manager keine Gruppengrenze sehen
(`CLAUDE.md`, `docs/OPEN-ITEMS.md`).

Ein Administrator oder Manager, der die Zeiterfassung **für sich selbst** nutzt, sieht folglich
alle Tätigkeitsarten. Das ist gewollt und entspricht der Handhabung bei Terminarten.

Ist ein Mitglied keiner Gruppe zugeordnet oder liegt in seinen Gruppen keine Tätigkeitsart, ist
die Antwort ein leeres Array mit Status 200 — nicht 404. Der 404 bleibt dem abgeschalteten
Feature vorbehalten (`requireWorktimeEnabled()`), damit die PWA beide Fälle unterscheiden kann.

### Handler `private/handlers/work_sessions.php`

Die Filterung im GET ist ohne serverseitige Prüfung reine Kosmetik — die Ressource bleibt
direkt aufrufbar. Deshalb:

- **Start** (`action: 'start'`) prüft nach der bestehenden Prüfung auf `is_active`, ob die
  Tätigkeitsart in einer Gruppe des erfassenden Mitglieds liegt. Sonst 403 mit der Meldung
  `Activity type not allowed for this member` — Teil 5 übersetzt sie für das Mitglied.
- **Selbst-Nachtrag** eines Mitglieds prüft dieselbe Bedingung.
- **Nachträge von Administrator oder Manager zugunsten anderer** prüfen sie nicht.

### Dashboard `public/js/modules/worktime.js` und `public/index.html`

Das Modal der Tätigkeitsart erhält eine Gruppen-Checkboxliste. `renderTypeGroups()` in
`public/js/modules/management.js:357` ist praktisch unverändert übernehmbar; der HTML-Block
entspricht `typeGroupsList` in `public/index.html:1691`. Die Übersichtstabelle erhält eine
Spalte „Gruppen“ analog zu `loadTypeGroup()` (`management.js:291`).

**Anzupassen: `checkWorktimeEnabled()`** (`worktime.js:80`). Die Funktion schließt heute allein
aus `Array.isArray(result)` auf ein freigeschaltetes Feature. Ein leeres Array — künftig der
Normalfall für ein Mitglied ohne zugeordnete Tätigkeitsart — würde den Navigationspunkt und den
Stammdatenblock einblenden und in einen leeren Bereich führen. Künftig gilt: Feature vorhanden
**und** Liste nicht leer. Für Administrator und Manager bleibt es beim heutigen Verhalten, da
sie ungefiltert alle Arten erhalten.

---

## Teil 2 — Erfassen-Tab

### Struktur

Die Tab-Inhalte `checkin` und `worktime` verschmelzen zu einem Tab `capture`. Er kennt drei
Ansichten:

| Ansicht | Inhalt |
|---|---|
| `chooser` | Zwei große Kacheln: *Anwesenheit* und *Arbeitszeit* |
| `attendance` | QR-Code scannen, NFC, Code manuell eingeben; darunter der Erfassungsantrag |
| `worktime` | Tätigkeit, Termin, Start; bei Nachweispflicht der Scanner an Ort und Stelle |

### Regel für den Einstieg

Beim Öffnen des Tabs wird ermittelt, welche Absichten verfügbar sind:

- **Anwesenheit** — immer
- **Arbeitszeit** — wenn `activity_types` mit Status 200 antwortet **und** die Liste nicht leer
  ist

Sind beide verfügbar, erscheint `chooser`. Ist nur eine verfügbar, wird direkt deren Ansicht
gezeigt und die Auswahl übersprungen. Das ist kein nachgereichter Sonderfall, sondern die Regel:
*Der Tab zeigt, was das Mitglied darf, und fragt nur bei mehr als einer Möglichkeit.*

Aus `chooser` erreichte Ansichten tragen einen Zurück-Weg zur Auswahl. Ohne `chooser` entfällt er.

### Wegfallender Code

`totpCodeConsumer`, `requestTotpCode()`, `cancelTotpCodeRequest()` und `askForTotpCode()`
entfallen ersatzlos. `deliverTotpCode()` entscheidet anhand der aktiven Ansicht des
Erfassen-Tabs, wohin ein eingelesener Code geht. Die Aufräumlogik im Tab-Handler
(`app.js`, Zweig `targetTab !== 'checkin'`) entfällt ebenfalls.

### Scanner als Komponente

Der Scanner wird mit einem Zweck-Parameter herausgelöst, statt an einer festen Stelle im
Markup zu hängen. Damit verschwindet zugleich der in OI-8 notierte Bestandsfehler: derzeit
existiert `id="scannerContainer"` zweimal im Dokument.

---

## Teil 3 — Leiste für die laufende Sitzung

Eine schmale Leiste oberhalb der Tab-Leiste, in **allen** Ansichten sichtbar, solange eine
Sitzung läuft. Sie zeigt die Tätigkeit und die laufende Zeit; ein Tippen führt zur
Arbeitszeit-Ansicht.

Sie schließt eine bestehende Lücke: Heute lädt die PWA den Sitzungszustand erst beim Wechsel
auf den Zeit-Tab (`loadWorktimeState()` im Tab-Handler). Wer die App öffnet und nicht dorthin
geht, erfährt nie, dass eine Sitzung läuft. Künftig wird `work_sessions?running=1` beim Start
der App geladen und nach jeder Mutation aufgefrischt.

Ist die Zeiterfassung abgeschaltet oder das Mitglied nicht berechtigt, entfällt der Abruf.

---

## Teil 4 — Zusammengeführter Verlauf

### Datenbeschaffung

Drei bestehende Endpunkte (`records`, `exceptions`, `work_sessions`), clientseitig
zusammengeführt und absteigend nach Zeit sortiert. **Kein neuer Endpunkt.** Je Quelle werden
die letzten N geladen, davon die neuesten 20 angezeigt.

### Gemeinsames Statusvokabular

Ohne Vereinheitlichung wird die Liste ein Sammelsurium aus drei Datenmodellen:

| Art | Rohstatus | Anzeige |
|---|---|---|
| Anwesenheit | `present` | bestätigt |
| Anwesenheit | `excused` | entschuldigt |
| Antrag | `pending` / `approved` / `rejected` | wartet auf Freigabe / bestätigt / abgelehnt |
| Arbeitszeit | `confirmed` / `submitted` / `rejected` | bestätigt / wartet auf Freigabe / abgelehnt |

Jeder Eintrag trägt zusätzlich ein Symbol für seine Art.

Ein zweiter Nutzen: Eine vergessene laufende Arbeitszeitsitzung wird hier sichtbar — der
Verlauf ist damit die zweite Absicherung gegen genau den Fehler, um den es in dieser Spec geht.

---

## Teil 5 — Verständliche Fehlermeldungen

### Problem

Die PWA **verwirft aussagekräftige Servermeldungen** und ersetzt sie durch generische Texte.
Der Switch über `response.status` in `apiCall` (`app.js`) überschreibt das zuvor gelesene
`responseData.message`:

| Server sagt | Mitglied liest |
|---|---|
| `A session is already running` | „Start fehlgeschlagen: Konflikt - Eintrag existiert bereits“ |
| `This activity type requires a TOTP code to start` | „Keine Berechtigung für diese Aktion“ |
| `Invalid or expired TOTP code` | (401 ist auskommentiert, bleibt roh) |

Beides ist irreführend, beim 403 sachlich falsch — es ist keine Berechtigungsfrage.

Die Gruppenbindung aus Teil 1 verschärft das: Sie führt einen **echten** 403 ein („Tätigkeit
liegt nicht in deinen Gruppen“). Damit lägen zwei fachlich völlig verschiedene Ursachen hinter
demselben Text. Aus einem Schönheitsfehler wird ein Diagnosehindernis.

### Lösung

Der generische Switch bleibt als Rückfallebene für Ressourcen ohne eigene Behandlung. Für
`work_sessions` gilt künftig: **Die Servermeldung wird nicht überschrieben, sondern übersetzt.**

Eine Zuordnung von Servermeldung auf deutschen Text, ausgewertet vor dem Status-Switch:

| Servermeldung | Anzeige in der PWA |
|---|---|
| `A session is already running` | „Es läuft bereits eine Zeiterfassung. Bitte zuerst beenden.“ |
| `This activity type requires a TOTP code to start` | „Diese Tätigkeit verlangt den QR-Code der Station.“ |
| `Activity type not allowed for this member` | „Diese Tätigkeit ist für deine Gruppe nicht vorgesehen.“ |
| `Invalid or expired TOTP code` | „Der Code ist ungültig oder abgelaufen.“ |
| `No TOTP station configured` | „Es ist keine Station eingerichtet. Bitte an die Verwaltung wenden.“ |

Unbekannte Meldungen fallen auf den bisherigen generischen Text zurück; die Rohmeldung geht
zusätzlich in `debug.log`, damit sie bei der Fehlersuche nicht verloren ist.

Die Zuordnung erfolgt über die **englische Servermeldung als Schlüssel**, nicht über den
HTTP-Status: Ein Status trägt hier mehrere Ursachen, die Meldung ist eindeutig. Serverseitige
Meldungstexte werden damit zu einer Schnittstelle und sind entsprechend stabil zu halten — ein
Hinweis darauf gehört in `API.md`.

---

## Tests

| Ebene | Prüfung |
|---|---|
| `tests/suites/migrations` | Neue Migration läuft, ist wiederholbar, ordnet Bestand allen Gruppen zu |
| `tests/suites/worktime_api` | Start mit einer Tätigkeit außerhalb der eigenen Gruppen → 403 |
| `tests/suites/worktime_api` | Selbst-Nachtrag außerhalb der eigenen Gruppen → 403 |
| `tests/suites/worktime_api` | Manager-Nachtrag zugunsten anderer bleibt erlaubt |
| `tests/suites/worktime_api` | `activity_types?member_id=…` liefert nur zugeordnete Arten |
| `tests/suites/worktime_api` | Mitglied ohne zugeordnete Art erhält `[]` mit Status 200, nicht 404 |
| `tests/suites/worktime_api` | Der 403 der Gruppenprüfung trägt `Activity type not allowed for this member` — Teil 5 hängt an diesem Wortlaut |
| manuell | `docs/testplan.md`, Abschnitte Check-in und Zeiterfassung, **vollständig** |
| manuell | Jede Meldung aus Teil 5 mindestens einmal ausgelöst und gelesen |

Für das PWA-Frontend gibt es keinen automatisierten Testharness. Der manuelle Testplan ist
daher vor dem Merge verbindlich und um die neuen Einstiegswege zu ergänzen.

---

## Risiken

**Der Umbau berührt den Check-in — die meistgenutzte Funktion der Anwendung.** Das ist das
größte Risiko dieses Vorhabens, und kein automatisierter Test fängt es ab. Der manuelle
Testplan muss vollständig durchlaufen, bevor die Arbeit auf `main` geht.

**Kurzfristig wird es für alle einen Klick langsamer.** Weil die Migration alle Tätigkeitsarten
allen Gruppen zuordnet, dürfen zunächst alle beides — also sehen zunächst alle die Auswahl. Der
Gewinn tritt erst ein, wenn ein Administrator die Zuordnung pflegt. Das ist der bewusst
gezahlte Preis dafür, bestehende Installationen nicht zu überfahren. In `README.md` und
`CHANGELOG.md` ist darauf hinzuweisen.

**Gewohnheitsbruch.** Wer die App kennt, sucht den Scanner zuerst dort, wo er war.

**Berechtigungsänderung.** Nach der Migration kann ein Administrator Mitgliedern Tätigkeiten
entziehen, die sie vorher erfassen durften. Das ist beabsichtigt, aber es ist eine
Verhaltensänderung und gehört in den `CHANGELOG.md`.

---

## Abgrenzung zu bestehenden Einträgen

- **OI-4** (Terminbezug unvollständig): berührt, aber nicht gelöst. Das fehlende Terminfeld im
  Dashboard-Nachtrag und der dritte Export-Knopf bleiben offen.
- **OI-8** (doppelte `id="scannerContainer"`): wird durch Teil 2 erledigt.
