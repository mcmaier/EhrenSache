# Terminrückmeldung: Zusage, Absage, Unsicher

**Datum:** 2026-09-14
**Status:** Entwurf, abgestimmt
**Setzt um:** [FI-1](../../FEATURE-IDEAS.md#fi-1--terminzusage-im-vorfeld) vollständig,
[FI-2](../../FEATURE-IDEAS.md#fi-2--abgleich-zusage--tatsächliche-anwesenheit) teilweise (nur je Termin)
**Baut auf:** `2026-09-11-puenktlichkeit-und-zuverlaessigkeit-design.md` (Zuverlässigkeit, 1.5.1)
**Zielversion:** **1.7.0**, Migration `1.6.1.php` (1.6.1 → 1.7.0)
**Voraussetzung:** Der parallel vorbereitete Stand **1.6.1** ist auf `dev` gemergt, einschließlich
seines Manifest-Eintrags 1.6.0 → 1.6.1. Vorher wird der Eintrag dieses Vorhabens nicht angehängt.

---

## 1 Ausgangslage

EhrenSache erfasst Anwesenheit hinterher. Die Frage, die sich vor jedem Auftritt stellt — „reicht
die Besetzung, brauchen wir Aushilfen?“ —, beantwortet es nicht. Im Verein des Auftraggebers ist
genau das der Nutzen: Der Dirigent sieht vorab, wo externe Unterstützung nötig wird.

Eine Abmeldung im Vorfeld gibt es bereits: `exceptions` mit `exception_type = 'absence'` und
Freigabe über `status`. Seit 1.5.1 speist sie die Zuverlässigkeit — ein Antrag vor Terminbeginn
zählt als „abgemeldet“ ([`punctuality.php`](../../../private/helpers/punctuality.php),
`reliabilityFetchPairs()`).

Im Ehrenamt ist eine Teilnahme oft nicht verbindlich. Ein Antrag mit Freigabe für jede Absage
wäre Verwaltungsaufwand ohne Gegenwert.

---

## 2 Ziel

- Mitglieder melden sich zu kommenden Terminen **zu, ab oder unsicher**, mit optionaler Bemerkung,
  und dürfen die Antwort bis Terminbeginn ändern.
- Admin und Manager sehen je Termin, wer wie geantwortet hat und wer nicht.
- Ob es Rückmeldungen gibt, wer Namen sieht und ob eine Absage eine Entschuldigung braucht, legt
  die **Terminart** fest. Eine Probe bleibt, wie sie ist.
- Eine rechtzeitige Absage zählt in der Zuverlässigkeit als abgemeldet.
- Nach dem Termin zeigt die Terminansicht, wie weit Zusage und Anwesenheit übereinstimmten.

---

## 3 Entscheidungen

### 3.1 Die Rückmeldung ist ein Planungswerkzeug, keine Entschuldigung

Eine Antwort braucht keine Freigabe und ändert die Anwesenheitsquote nicht. „Entschuldigt“ bleibt
allein Sache genehmigter `exceptions` — die Grundregel „kein Record = nicht erschienen, genehmigte
Entschuldigung = `excused`-Record“ gilt unverändert.

**Verworfen:** die Rückmeldung als Vordergrund, aus dem jede Absage einen Antrag erzeugt (zu viel
Freigabearbeit im Ehrenamt); `exceptions` um zusagende Typen erweitern (die Tabelle ist auf Anträge
mit Freigabe gebaut, `reason` ist Pflicht, `pending` ergäbe für eine Zusage keinen Sinn).

### 3.2 Entschuldigungspflicht nur, wo die Terminart sie verlangt

Bei Terminarten mit hoher erwünschter Anwesenheit (Konzert, Wertungsspiel) schaltet der Admin
„Absage braucht Entschuldigung“ ein. Dann ist die Bemerkung zur Absage Pflicht, und aus ihr
entsteht ein `exceptions`-Antrag. Nur hier fällt Freigabearbeit an.

### 3.3 Eine rechtzeitige Absage zählt in der Zuverlässigkeit als abgemeldet

Ohne diese Regel wäre die Kennzahl aus 1.5.1 bei allen Terminarten ohne Entschuldigungspflicht
bedeutungslos, weil dort niemand mehr Anträge stellt. Wer rechtzeitig Bescheid gibt, ist
verlässlich — mit oder ohne förmliche Entschuldigung.

**Verworfen:** eine eigene Kennzahl daneben; sie würde fast dasselbe messen und der Zuverlässigkeit
widersprechen können.

### 3.4 Ändern bis Beginn, eine Frist bestimmt „rechtzeitig“

Die Antwort bleibt bis Terminbeginn änderbar. Eine **Frist** in Stunden vor Beginn trennt
rechtzeitig von kurzfristig: Eine Absage nach der Frist wird gespeichert und ist sofort sichtbar,
aber als *kurzfristig* markiert und zählt in der Zuverlässigkeit **nicht** als abgemeldet.

Die Frist ist global (`system_settings`, Vorgabe 24 h) und je Terminart überschreibbar.

**Verworfen:** harte Sperre ab der Frist — sie bestraft die ehrliche Absage am Vortag, die der
Dirigent gerade braucht, und erzeugt Verwaltungsaufwand; keine Frist — eine Absage fünf Minuten vor
Beginn gälte als verlässlich.

### 3.5 Bei Terminarten mit Rückmeldung gilt die Frist auch für Anträge

Heute ist ein Antrag rechtzeitig, wenn er vor Beginn angelegt wurde. Bei Entschuldigungspflicht
entsteht der Antrag im selben Moment wie die Absage — eine kurzfristige Absage würde über den Antrag
doch als rechtzeitig zählen. Deshalb: **Hat die Terminart `responses_enabled`, ist für Absage und
Antrag gleichermaßen die Frist maßgeblich.** Bei Terminarten ohne Rückmeldung bleibt die Regel aus
1.5.1 („vor Beginn“) unverändert.

### 3.6 Namen sieht, wer planen muss — Mitglieder nur, wenn die Terminart es freigibt

Admin und Manager sehen alle Antworten mit Bemerkung. Mitglieder sehen die eigene Antwort und die
Summen; Namen nur, wenn die Terminart „Namen für Mitglieder sichtbar“ hat (Vorgabe aus). Bemerkungen
sehen Mitglieder nie.

Der Dirigent erhält ein **Manager-Konto**. Dass er damit auch Termine und Mitglieder bearbeiten
darf, ist für den Auftraggeber in Ordnung; eine Rolle „Gruppenleiter“ (FI-15) ist nicht Teil
dieses Vorhabens.

### 3.7 Vergleich nur je Termin

Nach Beginn zeigt die Terminansicht Admin und Manager die Gegenüberstellung von Zusage und
Anwesenheit. Eine personenbezogene Kennzahl „Zusagetreue“ gibt es nicht — die Frage „auf wen ist
Verlass“ beantwortet schon die Zuverlässigkeit, mit ihren Datenschutzregeln aus 1.5.1.

### 3.8 Nur der aktuelle Stand wird gespeichert

Eine Zeile je Mitglied und Termin mit `status_changed_at`. Der Zeitstempel ändert sich nur bei einem
Statuswechsel, nicht bei einer geänderten Bemerkung. Das reicht für alle Fälle, die zählen:

| Verlauf | Ergebnis |
|---|---|
| früh abgesagt, kurz vor Beginn Bemerkung ergänzt | rechtzeitig abgemeldet |
| früh abgesagt, am Vortag zugesagt, nicht erschienen | ausgefallen |
| früh zugesagt, zwei Stunden vorher abgesagt (Frist 24 h) | kurzfristig, ausgefallen |

**Verworfen:** eine Verlaufstabelle nach dem Vorbild von `work_session_log` (mehr Datenbestand,
eigene Löschfrist, und das Umschwenken einer Person wäre eine Personenauswertung, die 3.7 gerade
ausschließt); die Rückmeldung als vorab angelegter `records`-Eintrag (bricht „kein Record = nicht
erschienen“ und damit alle Statistiken).

---

## 4 Datenmodell

### 4.1 Neue Tabelle `appointment_responses`

```sql
CREATE TABLE IF NOT EXISTS `{PREFIX}appointment_responses` (
  response_id       INT PRIMARY KEY AUTO_INCREMENT,
  appointment_id    INT NOT NULL,
  member_id         INT NOT NULL,
  status            ENUM('yes','no','maybe') NOT NULL,
  comment           VARCHAR(255) DEFAULT NULL,
  exception_id      INT DEFAULT NULL,
  status_changed_at DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  UNIQUE KEY uq_response (appointment_id, member_id),
  KEY idx_member (member_id),
  CONSTRAINT `{PREFIX}resp_appointment_fk` FOREIGN KEY (appointment_id)
      REFERENCES `{PREFIX}appointments`(appointment_id) ON DELETE CASCADE,
  CONSTRAINT `{PREFIX}resp_member_fk` FOREIGN KEY (member_id)
      REFERENCES `{PREFIX}members`(member_id) ON DELETE CASCADE,
  CONSTRAINT `{PREFIX}resp_exception_fk` FOREIGN KEY (exception_id)
      REFERENCES `{PREFIX}exceptions`(exception_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Zeitstempel werden von PHP gesetzt (`DATETIME`, keine `ON UPDATE`-Automatik), damit
`status_changed_at` nur beim Statuswechsel wandert.

Vor dem Anlegen der Fremdschlüssel prüft die Migration, dass `appointments`, `members` und
`exceptions` InnoDB sind und die referenzierten Spalten denselben Typ haben; scheitert das, wird
die Tabelle ohne den betroffenen Fremdschlüssel angelegt und eine Warnung ausgegeben.

### 4.2 `appointment_types` bekommt vier Spalten

| Spalte | Typ | Vorgabe | Bedeutung |
|---|---|---|---|
| `responses_enabled` | `TINYINT(1) NOT NULL` | 0 | Rückmeldung erbeten |
| `responses_names_visible` | `TINYINT(1) NOT NULL` | 0 | Namen für Mitglieder sichtbar |
| `responses_require_excuse` | `TINYINT(1) NOT NULL` | 0 | Absage braucht Entschuldigung |
| `response_deadline_hours` | `SMALLINT UNSIGNED NULL` | NULL | Frist; NULL = globale Einstellung |

Die drei abhängigen Spalten wirken nur bei `responses_enabled = 1`. Zulässig für die Frist: 0 bis
720 (30 Tage); 0 heißt „bis Beginn“.

### 4.3 Einstellung

`system_settings`, Schlüssel `response_deadline_hours`, Vorgabe `24`, zulässig 0 bis 720. Die
Migration legt den Schlüssel an, wenn er fehlt.

### 4.4 Migration

Datei `private/migrations/1.6.1.php`, Funktion `migrate_1_6_1()`, Manifest-Eintrag
`from 1.6.1 → to 1.7.0`. Idempotent: Tabelle mit `IF NOT EXISTS`, Spalten und Einstellung nur,
wenn nicht vorhanden. Kein `DELIMITER`, keine Trigger. `private/setup/ehrensache_db.sql` wird im
selben Zug nachgezogen.

---

## 5 Regeln

Die reinen Regeln liegen in einem neuen Helfer `private/helpers/responses.php`, damit sie ohne
Datenbank testbar sind.

### 5.1 Erwartete Mitglieder

Erwartet wird, wer über Terminart → `appointment_type_groups` → `member_group_assignments`
erreicht wird und zum Termindatum aktiv ist (`getMemberActivityWhere()` mit dem Termindatum) —
derselbe Weg wie `punctualityScope()`. Mehrfache Erreichbarkeit über zwei Gruppen wird entdoppelt.

### 5.2 Wer antworten darf

| Wer | Bedingung |
|---|---|
| Mitglied für sich selbst | Terminart hat `responses_enabled`, Termin hat nicht begonnen, Mitglied ist erwartet |
| Admin, Manager für ein Mitglied | Terminart hat `responses_enabled`, Mitglied ist erwartet; **auch nach Beginn** (etwa nach einem Anruf) |
| Nutzer ohne verknüpftes Mitglied | kann nicht für sich antworten |
| device | kein Zugriff |

„Begonnen“ heißt `CONCAT(date, ' ', start_time) <= NOW()`.

### 5.3 Frist und „kurzfristig“

```
deadline = Terminbeginn − (type.response_deadline_hours ?? setting.response_deadline_hours) Stunden
is_late  = status_changed_at > deadline
```

Genau auf der Frist gilt als rechtzeitig. `is_late` wird serverseitig berechnet und ausgeliefert.

### 5.4 Entschuldigungspflicht

Hat die Terminart `responses_require_excuse`:

- **Wechsel auf `no`:** `comment` ist Pflicht (sonst `422`). Es entsteht ein `exceptions`-Eintrag
  `absence`, `status = 'pending'`, `reason = comment`, `created_by` = handelnder Nutzer; seine ID
  steht in `appointment_responses.exception_id`.
- **Bemerkung einer bestehenden Absage geändert:** Ist der Antrag noch `pending`, wird `reason`
  mitgezogen; sonst bleibt der Antrag unverändert.
- **Wechsel von `no` auf `yes`/`maybe` oder Rücknahme:** Ein `pending`-Antrag wird gelöscht. Ein
  genehmigter oder abgelehnter bleibt bestehen; die Antwort liefert `excuse_state` mit, und die
  Detailansicht des Verwalters zeigt einen Hinweis.
- Hat ein Mitglied bereits vorher einen eigenen `absence`-Antrag zum Termin gestellt, wird kein
  zweiter angelegt; dieser wird verknüpft.

Ohne Entschuldigungspflicht entsteht nie ein Antrag.

Alle Schreibvorgänge eines Aufrufs laufen in einer Transaktion.

### 5.5 Zuverlässigkeit

`reliabilityFetchPairs()` liefert je Paar zusätzlich:

- `responses_enabled` der Terminart,
- `response_no_in_time` — 1, wenn eine Antwort `no` existiert und `status_changed_at` vor der Frist
  liegt,
- `absence_in_deadline_count` — Anträge (nicht abgelehnt) mit `created_at` vor der Frist.

`reliabilityOutcome()`:

1. erschienen → `appeared`
2. **Terminart mit Rückmeldung:** `response_no_in_time` oder `absence_in_deadline_count > 0` →
   `excused`; sonst, wenn eine Antwort `no` oder ein nicht abgelehnter Antrag existiert (also nur
   nach der Frist) → `missed`; sonst weiter mit 4
3. **Terminart ohne Rückmeldung:** Regel aus 1.5.1 unverändert — gibt es einen Antrag, entscheidet
   „vor Beginn“; sonst weiter mit 4. Eine Antwort wird hier nicht gelesen (es kann keine geben)
4. sonst Entschuldigung des Verwalters (`excused`-Record) → `excused`
5. sonst `missed`

Die Ausgabe der Kennzahl ändert sich nicht.

### 5.6 Summen und Gegenüberstellung

**Summen** je Termin: `yes`, `no`, `maybe`, `open` = erwartete Mitglieder ohne Antwort. Antworten von
Mitgliedern, die nicht mehr erwartet sind (Gruppe gewechselt, inaktiv), zählen nicht mit.

**Gegenüberstellung** nach Beginn, über die erwarteten Mitglieder; „gekommen“ = `records.status =
'present'`:

| Feld | Bedeutung |
|---|---|
| `yes_present` | zugesagt und gekommen |
| `yes_absent` | zugesagt und nicht gekommen |
| `no_present` | abgesagt und trotzdem da |
| `no_response` | keine Antwort (mit Aufteilung gekommen / nicht gekommen) |

`maybe` wird mit Aufteilung gekommen / nicht gekommen ausgewiesen, aber nicht als Abweichung
gewertet. Namen gibt es zu `yes_absent`, `no_present` und `no_response`.

### 5.7 Löschfrist

Die Bereinigung in `private/handlers/settings.php` löscht Antworten in derselben Transaktion und
nach derselben Frist wie Records — über das **Termindatum** (`cutoff['records']`), nicht über den
Antwortzeitpunkt. Die Löschung eines Termins oder Mitglieds entfernt Antworten per Fremdschlüssel.

---

## 6 API

### 6.1 Neue Ressource `appointment_responses`

Handler `private/handlers/appointment_responses.php`, `require_once` und `case` in `api.php`,
Eintrag in `DEMO_WRITE_ALLOWED` (`PUT`, `DELETE`) in `private/helpers/demo_mode.php`, Abschnitt in
`API.md`.

| Aufruf | Wer | Wirkung |
|---|---|---|
| `GET ?resource=appointment_responses&upcoming=1` | admin, manager, user | kommende Termine mit Rückmeldung, zu denen das eigene Mitglied erwartet ist: eigene Antwort, Frist, `is_late`, Summen; Namen nur bei Freigabe |
| `GET ?resource=appointment_responses&appointment_id=X` | admin, manager, user | ein Termin; siehe Sichtbarkeit unten |
| `PUT ?resource=appointment_responses&appointment_id=X` | Mitglied für sich | Body `{status, comment}`; Anlegen oder Ändern |
| `PUT …&appointment_id=X&member_id=Y` | admin, manager | Eintrag für ein Mitglied, auch nach Beginn |
| `DELETE …&appointment_id=X[&member_id=Y]` | wie `PUT` | Antwort zurücknehmen, 5.4 gilt |

**Sichtbarkeit bei `GET appointment_id`:**

| Rolle | Inhalt |
|---|---|
| admin, manager | alle erwarteten Mitglieder nach Gruppen mit Status, Bemerkung, `status_changed_at`, `is_late`, `excuse_state`; nach Beginn zusätzlich die Gegenüberstellung |
| user | eigene Antwort, Summen; bei `responses_names_visible` Namen und Status je Mitglied, **ohne** Bemerkung |

**Fehlerantworten:**

| Code | Anlass |
|---|---|
| `400` | ungültiger `status`, Bemerkung länger als 255 Zeichen |
| `403` | Rolle darf nicht; Mitglied nicht erwartet; Nutzer ohne Mitglied (mit Klartextmeldung) |
| `404` | Termin oder Mitglied unbekannt |
| `409` | Terminart ohne Rückmeldung; Termin hat begonnen (nur Mitglied) |
| `422` | Absage ohne Bemerkung bei Entschuldigungspflicht |

### 6.2 Bestehende Ressourcen

- **`appointment_types`**: die vier Felder aus 4.2 lesen (alle) und schreiben (wie heute nur Admin);
  Validierung der Frist 0–720 oder leer.
- **`appointments`** (Liste): je Termin `responses: {yes, no, maybe, open, own}` — `own` ist die
  eigene Antwort oder `null` —, oder `responses: null` bei Terminarten ohne Rückmeldung. Ermittelt in
  einer gruppierten Abfrage über alle Termine der Liste, nicht einmal je Termin.
- **`settings`**: Schlüssel `response_deadline_hours`.
- **`my_data`**: Die eigenen Antworten (Termin, Status, Bemerkung, Zeitpunkte) gehören zur
  Selbstauskunft und zum Export.
- **`statistics`**: nur die geänderte Berechnung aus 5.5, keine neuen Felder.

---

## 7 Oberfläche

### 7.1 PWA (`public/checkin`): neuer Tab „Termine“

- Steht zwischen „Erfassen“ und „Verlauf“; sichtbar nur, wenn `upcoming` mindestens einen Termin
  liefert. Ein Zähler am Tab nennt die unbeantworteten.
- Karte je Termin: Datum, Uhrzeit, Titel, Farbe der Terminart; darunter die Segmentgruppe
  **Zusage · Unsicher · Absage**. Die aktive Wahl ist gefüllt. Ein Tipp speichert sofort.
- Nach der Wahl klappt ein optionales Bemerkungsfeld auf; bei Entschuldigungspflicht und „Absage“
  als Pflichtfeld mit dem Hinweis „wird als Entschuldigung eingereicht“.
- Frist als „Rückmeldung bis Fr 18:00“; nach Ablauf „Frist abgelaufen – Änderung wird als
  kurzfristig vermerkt“. Die Knöpfe bleiben benutzbar.
- Unbeantwortete stehen oben, danach nach Datum.
- Darunter die Summen, bei Freigabe aufklappbar mit Namen.
- Ohne Netz sind die Knöpfe deaktiviert, mit Hinweis. Keine Offline-Warteschlange.

### 7.2 Dashboard, Bereich „Termine“

- **Liste:** Spalte „Rückmeldung“ mit ✓ 23 · ? 3 · ✗ 4 · — 12; bei Mitgliedern zusätzlich die eigene
  Antwort als Symbol. Leer bei Terminarten ohne Rückmeldung.
- **Termindetail, Abschnitt „Rückmeldungen“:**
  - *user:* Segmentgruppe und Bemerkungsfeld wie in der PWA, Summen bzw. Namen nach Freigabe.
  - *admin, manager:* Tabelle nach Gruppen — Name, Status, Bemerkung, Zeitpunkt, Markierung
    „kurzfristig“, Hinweis bei Entschuldigung —, Filter „keine Antwort“, Aktionsmenü je Zeile zum
    Setzen für das Mitglied.
  - *admin, manager nach Beginn:* darüber vier Kacheln der Gegenüberstellung; ein Klick filtert die
    Tabelle auf die Abweichung.
- **Druck:** Die Detailtabelle geht über die vorhandene Druckansicht mit — die Besetzungsliste für
  den Dirigenten. Kein eigener Bericht.

### 7.3 Verwaltung, Terminarten

Im Modal ein Block „Rückmeldung“ mit den vier Einstellungen. Die drei abhängigen sind ausgegraut,
solange „Rückmeldung erbeten“ aus ist. Neben „Namen für Mitglieder sichtbar“ ein Hinweis auf
`DATENSCHUTZ.md`. Die Frist hat den Platzhalter „global (24 h)“ mit dem aktuellen Wert.

### 7.4 Einstellungen

Feld „Frist für Rückmeldungen (Stunden vor Beginn)“ in der bestehenden Karte zu Terminen bzw.
Anwesenheit.

### 7.5 Technik

- Neues Modul `public/js/modules/responses.js` für Segmentgruppe, Detailabschnitt und Kacheln;
  `appointments.js` bindet es ein.
- **Cache:** Rückmeldungen liegen nicht im `dataCache`. Nach einer eigenen Antwort wird
  `invalidateCache('appointments', year)` aufgerufen, damit die Summen der Liste stimmen.
- **CSS:** Segmentgruppe in `components/buttons.css`, Kacheln über `components/cards.css`, Farben
  nur aus `variables.css` (Erfolg, Warnung, Gefahr). Die PWA erhält eigene Regeln in
  `public/checkin/css/style.css`.
- Neue PHP- und JS-Dateien tragen den Copyright-Header.

---

## 8 Datenschutz

Neuer Abschnitt in `DATENSCHUTZ.md`: Zweck (Planung), Sichtbarkeit (Verwalter immer, Mitglieder nur
Summen oder bei Freigabe Namen, Bemerkungen nie), Rechtsgrundlage wie bei der Anwesenheit, Löschfrist
wie Records, Selbstauskunft über `my_data`. Im Abschnitt zur Zuverlässigkeit ergänzt: Eine
rechtzeitige Absage zählt als abgemeldet; bei Terminarten mit Rückmeldung entscheidet die Frist.

---

## 9 Tests

| Suite | Inhalt |
|---|---|
| `responses_unit` (neu) | Frist auflösen (Terminart, NULL → global); `is_late` genau auf, eine Sekunde vor und nach der Frist; Summen mit `open`; Einordnung der Gegenüberstellung; `reliabilityOutcome()` mit: früh abgesagt · früh abgesagt, dann zugesagt · kurzfristig abgesagt · Antrag nach der Frist bei Terminart mit Rückmeldung · Antrag vor Beginn bei Terminart ohne Rückmeldung (1.5.1 unverändert) |
| `responses_api` (neu) | Upsert durch Mitglied; `409` bei Terminart ohne Rückmeldung und bei begonnenem Termin; `403` bei nicht erwartetem Mitglied und Nutzer ohne Mitglied; `422` bei Absage ohne Bemerkung unter Entschuldigungspflicht; Antrag entsteht, wird bei Zusage gelöscht, bleibt genehmigt bestehen; Manager trägt nach Beginn ein; Namenssichtbarkeit je Rolle und Schalter, Bemerkung nie für `user`; `status_changed_at` bleibt bei reiner Bemerkungsänderung stehen; Summen in `appointments`. Jeder Test räumt die eigenen Datensätze ab |
| `punctuality_api` | Zuverlässigkeit mit Absage-Quelle und Frist für Anträge |
| `migrations` | 1.6.1 → 1.7.0 auf einem 1.6.1-Stand, zweimal ausgeführt ohne Fehler; Schema aus `ehrensache_db.sql` deckt sich mit dem migrierten |
| `demo_mode` | erzwingt die Registrierung der neuen Ressource |

Manuell: neuer Abschnitt in `docs/testplan.md`. Die Oberfläche wird im Browser geprüft, die PWA in
Handybreite; Screenshots nach `temporary_screenshots`.

---

## 10 Dokumentation und Demo

- `API.md`: neue Ressource; Felder bei `appointments`, `appointment_types`, `settings`, `my_data`
- `CHANGELOG.md` und `version.json` → 1.7.0
- `DATENSCHUTZ.md`: siehe 8
- `README.md` (Nutzersicht), `public/checkin/README.md` (Tab „Termine“)
- `docs/FEATURE-IDEAS.md`: FI-1 umgesetzt, FI-2 teilweise (je Termin)
- `docs/OPEN-ITEMS.md`: die Punkte aus 12
- `private/demo/seed.php`: eine Terminart mit Rückmeldung und plausiblen Antworten zu kommenden und
  vergangenen Terminen, sonst zeigt die Demo das Feature leer

---

## 11 Reihenfolge

1. 1.6.1 auf `dev` gemergt.
2. Branch `feat/1.7.0-terminrueckmeldung` in eigenem Worktree mit eigener Datenbankkopie.
3. Migration und Schema → Helfer mit Unit-Tests → Handler und API-Tests → Zuverlässigkeit →
   Dashboard → PWA → Demo-Daten → Dokumentation.

---

## 12 Bewusst weggelassen

| Punkt | Grund |
|---|---|
| Erinnerungen und Benachrichtigungen | eigenes Vorhaben, [FI-6](../../FEATURE-IDEAS.md#fi-6--benachrichtigungskanal-e-mail-web-push); die Rückmeldung funktioniert ohne, wird aber seltener genutzt |
| Besetzungsansicht nach Registern | [FI-14](../../FEATURE-IDEAS.md#fi-14--untergruppen-register-und-besetzungsübersicht); flache Gruppen werden bereits je Gruppe angezeigt |
| Personenbezogene Kennzahl „Zusagetreue“ | 3.7 |
| Verlauf der Antwortänderungen | 3.8 |
| Rückmeldung je Termin abweichend von der Terminart | kein Bedarf erkennbar |
| Offline-Warteschlange in der PWA | die PWA arbeitet onlinebasiert |
| Rolle „Gruppenleiter“ | [FI-15](../../FEATURE-IDEAS.md#fi-15--rolle-gruppenleiter); der Dirigent erhält ein Manager-Konto |
