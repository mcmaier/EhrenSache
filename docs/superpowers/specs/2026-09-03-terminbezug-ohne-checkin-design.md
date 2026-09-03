# Design: Terminbezug ohne Anwesenheitskopplung

**Datum:** 2026-09-03
**Status:** Umgesetzt in 1.2.3 (2026-09-03)
**Betrifft:** `private/handlers/work_sessions.php`, `private/handlers/activity_types.php`,
`public/checkin/js/app.js`, `public/js/modules/worktime.js`, `public/index.html`,
neue Migration, `private/setup/ehrensache_db.sql`, `API.md`, `docs/OPEN-ITEMS.md`
**Zielversion:** 1.2.3

---

## Problem

### Der Timer erzeugt Anwesenheit, die keine ist

Startet ein Mitglied den Timer mit Terminbezug und liegt der Termin heute, legt
`workSessionStart()` einen `records`-Eintrag an. Die Absicht war Bequemlichkeit — ein
Vorgang, zwei Sichten (E4 der Zeiterfassungs-Spec). Der Preis ist höher als gedacht:

**Die Anwesenheitsauswertung liest `records` ohne Rücksicht auf `checkin_source`**
(`statistics.php:285`). Ein per Timer erzeugter Eintrag zählt voll in die Anwesenheitsquote,
und weil `arrival_time = NOW()` gesetzt wird, auch in die Pünktlichkeit.

Wer um 08:00 die Bühne für das Konzert um 19:00 aufbaut, erscheint damit als anwesend **und**
als elf Stunden zu früh. Beide Kernauswertungen des Produkts nehmen Schaden, und zwar
zugunsten des Mitglieds — bei einer Auswertung, die Verlässlichkeit messen soll, die
unangenehmere Richtung.

Der Datumsschutz (`$appointmentToday`) sollte genau das verhindern, trifft aber daneben: Am
Veranstaltungstag wird typischerweise gearbeitet. Was er abwehrt, ist der Vortag; was
durchgeht, ist der Regelfall.

### Die Terminauswahl passt nicht zum Zweck

Die PWA bietet nur Termine des heutigen Tages an (`loadWorktimeAppointments()`, OI-4). Für
eine Aufwandsbetrachtung ist das die falsche Einschränkung: Vorbereitungsarbeit findet vor
der Veranstaltung statt, Nachbereitung danach. Genau diese Stunden will der Bericht „nach
Termin" zeigen, und genau sie lassen sich nicht zuordnen.

Alle Termine anzubieten löst das, erzeugt aber in einem Verein mit wöchentlichen Proben eine
Liste von über hundert Einträgen.

---

## Leitgedanke

**Die Zuordnung zu einem Termin ist eine Aussage über den Aufwand, nicht über Anwesenheit.**

Sie beantwortet „wie viel Arbeit ist in diese Veranstaltung geflossen", nicht „wer war da".
Wer beides behaupten will, tut beides — die Wege liegen seit dem zusammengeführten
Erfassen-Tab nebeneinander.

---

## Umfang

**Enthalten**

1. Automatisches Einchecken beim Timer-Start entfällt ersatzlos
2. Terminauswahl in der PWA umfasst alle Termine, nicht nur die heutigen
3. Terminlisten sind nach zeitlicher Nähe sortiert — in PWA und Dashboard
4. Tätigkeitsarten lassen sich mit Terminarten verknüpfen und grenzen die Terminliste ein
5. Bestehende `records` bleiben unangetastet

**Bewusst nicht enthalten**

| Punkt | Begründung |
|---|---|
| Bereinigung des Altbestands | Ein Teil der per Timer erzeugten Check-ins ist korrekt — das Mitglied war tatsächlich da. Welcher, lässt sich nachträglich nicht entscheiden. Löschen wäre ein nicht umkehrbarer Eingriff in erfasste Anwesenheitsdaten |
| `checkin_source = 'timer'` aus dem Enum entfernen | Der Wert muss lesbar bleiben, solange Altbestand existiert |
| Terminbezug für Anwesenheits-Check-ins | Unberührt; der reguläre Check-in-Weg ändert sich nicht |

---

## Teil 1 — Das automatische Einchecken entfällt

In `workSessionStart()` entfallen der `records`-INSERT und die Variable `$appointmentToday`
samt der Abfrage, die sie füllt (`work_sessions.php:296`, `311`, `345`). Der Terminbezug wird
weiterhin geprüft und gespeichert — nur eben ohne Nebenwirkung.

Damit gilt für alle drei Erfassungswege dasselbe: **Kein Weg der Zeiterfassung erzeugt
Anwesenheit.** Der Nachtrag tat es bereits nicht (seit 1.2.2), der Timer tut es künftig auch
nicht mehr. Diese Einheitlichkeit ist der eigentliche Gewinn — die bisherige Regel war „der
Timer ja, der Nachtrag nein, und der Timer auch nur heute", was niemand erklären konnte.

**Umzukehrende Tests.** `tests/suites/worktime_api.php` prüft heute das Gegenteil:

- „Timer-Start mit Termin erzeugt den Check-in" (Zeile ~563) wird zu „erzeugt keinen"
- „Timer-Start überschreibt eine frühere Ankunftszeit nicht" (Zeile ~598) verliert seinen
  Gegenstand und entfällt; ein bestehender Check-in bleibt trivialerweise unberührt, wenn
  gar keiner mehr geschrieben wird

---

## Teil 2 — Terminauswahl

### Sortierung nach zeitlicher Nähe

Termine werden nach dem Abstand zu einem Bezugsdatum sortiert, der kleinste zuerst:

| Ort | Bezugsdatum |
|---|---|
| PWA | heute |
| Dashboard, Nachtrag | das eingetragene Startdatum, solange keines gesetzt ist: heute |

Das trifft den Normalfall — man ordnet Arbeit einem Termin in ihrer Nähe zu — und lässt
trotzdem jeden Termin erreichbar. Rein clientseitig, kein Datenmodell, keine Migration.

Die Beschriftung trägt weiterhin das Datum, damit die Sortierung nachvollziehbar bleibt.

### Verknüpfung Tätigkeitsart ↔ Terminart

```sql
CREATE TABLE IF NOT EXISTS `{PREFIX}activity_type_appointment_types` (
  activity_id INT NOT NULL,
  type_id     INT NOT NULL,
  PRIMARY KEY (activity_id, type_id),
  CONSTRAINT `{PREFIX}atat_activity_fk` FOREIGN KEY (activity_id)
      REFERENCES `{PREFIX}activity_types`(activity_id) ON DELETE CASCADE,
  CONSTRAINT `{PREFIX}atat_type_fk` FOREIGN KEY (type_id)
      REFERENCES `{PREFIX}appointment_types`(type_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Aufbau und Benennung folgen `activity_type_groups` und `appointment_type_groups`.

### Die entscheidende Abweichung: leer heißt „keine Einschränkung"

Bei `activity_type_groups` bedeutet eine leere Zuordnung **niemand** — deshalb weist der
Server eine Tätigkeitsart ohne Gruppe ab, und deshalb musste die Migration jede bestehende
Art jeder Gruppe zuordnen.

Hier gilt das **Gegenteil**: Eine Tätigkeitsart ohne verknüpfte Terminart schränkt die
Terminliste nicht ein. Der Grund ist der Unterschied im Schaden:

- Bei Gruppen ist die leere Menge eine **Sichtbarkeitsfrage**. „Leer = alle" hieße, jede
  Tätigkeit wäre für jeden sichtbar — eine stille Rechteausweitung.
- Bei Terminarten ist die leere Menge eine **Bequemlichkeitsfrage**. „Leer = keine" hieße,
  nach der Migration könnte niemand mehr einen Termin zuordnen, bis ein Administrator jede
  Tätigkeitsart durchpflegt.

Daraus folgt zweierlei, und beides ist der Punkt der ganzen Konstruktion:

1. **Die Migration schreibt keine einzige Datenzeile.** Sie legt die Tabelle an, mehr nicht.
   Nichts verschwindet, nichts muss nachgezogen werden.
2. **Der Filter wirkt genau dort, wo ihn jemand bewusst gesetzt hat.** Ein Verein, der ihn
   nicht pflegt, merkt nicht, dass es ihn gibt — und verliert nichts. Das ist der Unterschied
   zu einem Filter, der erst nach Konfigurationsarbeit funktioniert, die erfahrungsgemäß
   unterbleibt (siehe OI-2 zu den Löschfristen).

Beispiel: „Bühnenaufbau" wird mit „Konzert" und „Veranstaltung" verknüpft und bietet dann nur
noch solche Termine an. „Ensembleleitung" bleibt unverknüpft und bietet weiter alles —
sinnvoll, weil sie über `activity_type_groups` bereits auf die Dirigenten begrenzt ist.

### Zusammenspiel der beiden Mechanismen

Die Gruppenbindung beantwortet **wer** eine Tätigkeit erfassen darf, die Terminartbindung
**wozu** sie passt. Sie greifen an verschiedenen Stellen und ersetzen einander nicht:

> Eine wiederkehrende Probe ist nicht mit Arbeit verbunden — außer für den Dirigenten.

Der erste Teil ist eine Aussage über die Terminart, der zweite über die Person. Die Lösung
braucht beide: „Ensembleleitung" ist an die Gruppe der Dirigenten gebunden, sodass der
Musiker sie nicht sieht; wäre sie zusätzlich an die Terminart „Probe" gebunden, sähe der
Dirigent bei einer Probe nur die Termine, die dazu passen.

---

## API

`activity_types` führt die Verknüpfung analog zu `group_ids`:

| Verb | Verhalten |
|---|---|
| `GET` | liefert `appointment_type_ids` als Array, leer wenn unverknüpft |
| `POST` | `appointment_type_ids` optional; fehlt es, bleibt die Art unverknüpft |
| `PUT` | fehlendes Feld lässt die Zuordnung unangetastet, ein leeres Array löst sie |

Die `PUT`-Semantik entspricht der von `appointment_id` bei `work_sessions` (1.2.2) und der von
`group_ids` — mit dem Unterschied, dass ein leeres Array hier zulässig ist und schlicht
„keine Einschränkung" bedeutet, während es bei `group_ids` abgewiesen wird.

Die Filterung der Terminliste geschieht **im Client**: Die Termine sind ohnehin geladen, und
ein Serverfilter bräuchte einen zusätzlichen Parameter an `appointments` für einen Effekt,
den ein `filter()` erledigt.

---

## Oberfläche

**Tätigkeitsarten-Verwaltung** (Dashboard, Admin): zweites Mehrfachauswahlfeld „Passende
Terminarten" neben der bestehenden Gruppenauswahl, mit dem Hinweis, dass keine Auswahl
bedeutet: alle Termine.

**Nachtrag-Modal** (Dashboard): Die Terminliste sortiert nach Nähe zum eingetragenen
Startdatum und filtert nach der gewählten Tätigkeitsart. Wechselt die Tätigkeitsart, wird die
Terminliste neu aufgebaut. Ist der bereits zugeordnete Termin nicht mehr in der gefilterten
Menge, bleibt er trotzdem enthalten und gekennzeichnet — derselbe Fallstrick wie in OI-16 und
bei „(anderes Jahr)": Sonst löste das Speichern die Zuordnung stillschweigend.

**PWA:** dieselbe Logik, Bezugsdatum ist heute. Der Hinweistext unter dem Terminfeld sagt,
dass die Zuordnung der Auswertung dient und keinen Check-in erzeugt — die bisherige Kopplung
war für Mitglieder sichtbar und ihr Wegfall braucht eine Erklärung.

---

## Migration

Neue Datei `private/migrations/1.2.2.php` mit `migrate_1_2_2()`, Manifest-Eintrag
`from: '1.2.2'`, `to: '1.2.3'`. Sie legt ausschließlich die Tabelle an, wiederholbar
(`CREATE TABLE IF NOT EXISTS`). `private/setup/ehrensache_db.sql` ist nachzuziehen.

Kein Datenrückbau: `records` mit `checkin_source = 'timer'` bleiben unverändert, der
Enum-Wert bleibt bestehen.

---

## Prüfung

**Automatisiert**

| Testfall | Erwartung |
|---|---|
| Timer-Start mit Termin heute | **kein** `records`-Eintrag |
| Timer-Start mit Termin heute, Check-in existiert bereits | Der bestehende Eintrag bleibt unverändert |
| `GET activity_types` | `appointment_type_ids` vorhanden, leer bei unverknüpfter Art |
| `POST` ohne `appointment_type_ids` | Art wird angelegt, unverknüpft |
| `PUT` mit leerem Array | Zuordnung gelöst |
| `PUT` ohne das Feld | Zuordnung unangetastet |
| `PUT` mit unbekannter `type_id` | `400` |
| Migration | Tabelle existiert, wiederholter Lauf ist folgenlos |

**Manuell**

- Timer-Start mit Termin heute, danach Anwesenheitsliste des Termins: kein neuer Eintrag
- Anwesenheitsquote eines Mitglieds vor und nach einem Timer-Start: unverändert
- PWA: Termin in vier Wochen ist auswählbar; Liste beginnt bei den nächstgelegenen
- Tätigkeitsart mit zwei Terminarten verknüpfen: Terminliste zeigt nur passende
- Tätigkeitsart ohne Verknüpfung: Terminliste zeigt alles
- Eintrag mit zugeordnetem Termin öffnen, dessen Terminart nicht zur Tätigkeit passt: Termin
  bleibt gewählt und gekennzeichnet

---

## Auswirkungen auf andere Dokumente

| Datei | Änderung |
|---|---|
| `API.md` | `appointment_type_ids` bei `activity_types`; Wegfall des Auto-Check-ins beim Timer-Start |
| `docs/OPEN-ITEMS.md` | OI-4 schließen; der Datumsschutz-Punkt entfällt mit der Kopplung |
| `docs/testplan.md` | Abschnitt zum Terminbezug erweitern |
| `CHANGELOG.md`, `version.json` | gemeinsam auf 1.2.3 |
| `private/setup/ehrensache_db.sql` | neue Tabelle |
| `docs/superpowers/specs/2026-09-01-zeiterfassung-design.md` | E4 ist überholt; dort vermerken statt stillschweigend widersprechen |

---

## Offene Punkte

1. **Doppelerfassung ist jetzt möglich.** Wer bei einer Probe eincheckt *und* Arbeitszeit
   erfasst, erzeugt zwei Einträge — das ist gewollt, weil es zwei Aussagen sind. Ob die
   Oberfläche darauf hinweisen soll („für diesen Termin liegt bereits ein Check-in vor"),
   ist offen. Vorschlag: zunächst nicht, und beobachten, ob jemand danach fragt.
2. **Der Altbestand bleibt in der Statistik.** `records` mit `checkin_source = 'timer'`
   zählen weiterhin in Anwesenheit und Pünktlichkeit. Ein Hinweis in der Auswertung wäre
   denkbar, verlangt aber eine Entscheidung darüber, wie sichtbar historische Datenqualität
   gemacht werden soll.
