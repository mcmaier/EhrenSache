# Pünktlichkeit und Zuverlässigkeit

**Datum:** 2026-09-11
**Status:** Entwurf, abgestimmt
**Löst ein:** [OI-51](../../OPEN-ITEMS.md#oi-51--pünktlichkeit-wird-beworben-aber-nirgends-ausgewertet)
**Vorgänger:** `2026-09-10-berichte-statistik-und-nutzerrolle-design.md`, Abschnitt 11
**Zielversion:** offen — hängt an einer Release-Entscheidung, die vor der Umsetzung fällt. Siehe
4.5.

---

## 1 Ausgangslage

`README.md`, `CLAUDE.md` und die Werbeseite beschreiben EhrenSache als Auswertung von Anwesenheit
**und Pünktlichkeit**. Im Code gibt es davon nichts: `records.arrival_time` wird erfasst und außer
zur Terminzuordnung nicht verwendet. Die falschen API-Felder sind am 2026-09-10 aus `API.md`
entfernt worden; die Zusage selbst ist offen.

Ein einfaches Nachbauen scheitert an der Datenlage. `arrival_time` ist **nicht durchgehend eine
Messung**: Hakt ein Admin eine Anwesenheitsliste ab, setzt [`records.php:192`](../../../private/handlers/records.php)
die **Startzeit des Termins** ein. Der Datensatz ist damit konstruiert pünktlich. Eine Quote über
diesen Bestand läge nahe 100 % und wäre Fiktion.

Ein zweiter Bestand kommt aus der Praxis: Vor EhrenSache wurde in einem Verein bereits eine
Pünktlichkeitsauswertung geführt — als geclampter Mittelwert der Abweichung zu einem Referenzpunkt
fünf Minuten vor Terminbeginn, mit Fehlzeiten als −20 Minuten eingerechnet. Dieses Verfahren ist
der Ausgangspunkt der Abwägung in Abschnitt 3.

---

## 2 Ziel

Zwei getrennte Kennzahlen, die halten, was sie im Namen tragen:

- **Pünktlichkeit** — ausschließlich über Ankünfte, deren Uhrzeit eine Aussage ist.
- **Zuverlässigkeit** — über alle Termine, mit einer rechtzeitigen Absage als eigenem Ausgang.

Dazu ein Datenmodell, in dem eine fehlende Uhrzeit als fehlend erkennbar ist, statt als Startzeit
erfunden zu werden.

---

## 3 Entscheidungen

### 3.1 Quote als Leitzahl, nicht Median, nicht Mittelwert

Abschnitt 11 der Vorgänger-Spec sah den **Median** der Verspätung vor, mit der Begründung, der
Mittelwert kippe bei einem einzigen Ausreißer. Das gilt für den *rohen* Mittelwert. Das
Praxisverfahren hatte das Problem bereits gelöst — Clamping auf [−20, +10] ist Winsorisierung und
damit ein robuster Schätzer wie der Median.

Der Vergleich an vier Personen, zehn Termine, Abweichung zum Referenzpunkt (positiv = früher):

| Person | Messwerte | roher Mittelwert | geclampt | Median | Quote |
|---|---|---|---|---|---|
| A | 8× +2, 2× −45 | −7,4 | −2,4 | +2 | 80 % |
| B | 10× −8 | −8,0 | −8,0 | −8 | 0 % |
| C | 6× +2, 4× −60 | −22,8 | −6,8 | +2 | 60 % |
| D | 6× +2, 4× −6 | −1,2 | −1,2 | +2 | 60 % |

A/B zeigt, warum der rohe Mittelwert ausfällt: Er kollabiert zwei völlig verschiedene Fälle auf
fast denselben Wert. **C/D ist das Argument gegen den Median:** C kommt viermal eine Stunde zu
spät, D viermal eine Minute — der Median sieht in beiden Fällen +2 und verschweigt den
Unterschied. Dazu kommt die Stichprobengröße: Bei acht bis fünfzehn gemessenen Ankünften pro
Person und Jahr springt der Median von Messwert zu Messwert und reagiert kaum auf Besserung.

**Entschieden:** Die Leitzahl ist die **Quote** — Anteil der Ankünfte innerhalb der Karenz. Sie
ist robust gegen jeden Ausreißer, braucht nur einen Parameter und muss niemandem erklärt werden.
Der Median entfällt; die Vorentscheidung aus Abschnitt 11 wird damit **bewusst revidiert**.

### 3.2 Nur gemessene Ankünfte, Fehlen fließt nicht ein

Das Praxisverfahren rechnete Fehlzeiten mit −20 Minuten in die Pünktlichkeit ein. Das erzeugt
einen Kollaps, den man der Zahl nicht ansieht:

- Mitglied X: 100 % anwesend, immer 5 Minuten nach Start → **−10**
- Mitglied Y: 50 % anwesend, wenn da immer 10 Minuten früh → 0,5·10 + 0,5·(−20) = **−5**

Y hat die bessere Pünktlichkeitszahl und war bei der Hälfte der Termine nicht da.

**Entschieden:** Die Pünktlichkeit rechnet ausschließlich über Ankünfte mit einer Uhrzeitaussage.
Fehlen, Absagen und Nachträge ohne Zeit gehen vollständig in die Zuverlässigkeit (3.6). Die
**Messabdeckung** steht immer daneben: „15 von 22 Terminen gemessen".

### 3.3 Die Karenzgrenze liegt am Terminbeginn, die Einstellung erlaubt negative Werte

Praxisverfahren und Vorgänger-Spec widersprachen sich im **Vorzeichen**: Dort galt als pünktlich,
wer fünf Minuten *vor* Beginn da war (Bringschuld), hier las sich die Karenz als fünf Minuten
*nach* Beginn (Nachsicht). In diesem Zehn-Minuten-Fenster ballt sich die Masse aller realen
Ankünfte — dieselbe Gruppe landet je nach Auslegung bei 55 % oder bei 95 %.

**Entschieden:** Einstellung `punctuality_grace_minutes`, gemessen **relativ zum Terminbeginn**,
Vorgabe **0**, negative Werte erlaubt (−5 bildet die bisherige Vereinspraxis ab). Begründung für
die Vorgabe: „pünktlich = zum Beginn da" muss niemand erklären, und eine Vorgabe, die man erklären
muss, wird falsch verstanden. `checkin_tolerance_hours` wird **nicht** wiederverwendet — die zwei
Stunden dort sind das Fenster für die Terminzuordnung.

### 3.4 Ein zweites Maß: Verspätung nur über die Zuspätkommer

Die Quote allein trennt C und D aus der Tabelle in 3.1 nicht. Das zweite Maß beantwortet die
Frage, die nach der Quote aufkommt — *ist das schlimm?*

**Entschieden:** Mittelwert der Verspätung, gebildet **nur über Ankünfte nach dem Terminbeginn**,
gekappt bei **20 Minuten** (aus dem Praxisverfahren übernommen). Ausgabe: „wenn zu spät, im Schnitt
7 Minuten". Zwei Zahlen, die sich nicht überlappen: *wie oft* und *wie schlimm*.

Die Kappung ist der Grund, warum hier ein Mittelwert steht und kein Median: Sie erledigt den
Ausreißer, und der Mittelwert zeigt anschließend mehr als der Median.

**Bezugspunkt ist immer der Terminbeginn, nicht die eingestellte Karenzgrenze.** Sonst bedeutet
„7 Minuten" in jedem Verein etwas anderes. Daraus folgt eine Abweichung, die dokumentiert gehört:
Bei einer Karenz von 0 (Vorgabe) sind „unpünktlich" und „im Verspätungsmaß enthalten" dieselbe
Menge. Bei abweichender Karenz driften sie auseinander — bei −5 ist jemand mit drei Minuten *vor*
Beginn unpünktlich, erscheint aber nicht im Verspätungsmaß. Das ist gewollt: Die Quote misst die
Vereinsregel, das Verspätungsmaß misst die Uhr.

### 3.5 Vier Herkünfte, drei davon zählen

`admin` ist keine Herkunft, sondern ein Sammelbecken. Am Code unterschieden:

| Weg | Code | Zeit stammt von | Zählt |
|---|---|---|---|
| Liste abhaken | [`records.php:192`](../../../private/handlers/records.php) | Terminstartzeit | **nein** — konstruiert |
| Admin tippt Zeit ein | [`records.php:183`](../../../private/handlers/records.php) | Beobachtung eines Menschen | ja |
| Kiosk, Gerät, TOTP | `station.php`, `totp_checkin.php` | Serverzeit bei der Authentifizierung | ja |
| Check-in-PWA | `auto_checkin.php` | Uhr des Telefons | ja |
| Genehmigter Zeitkorrektur-Antrag | [`utils.php:36`](../../../private/helpers/utils.php) | **Selbstauskunft des Mitglieds** | ja |
| Import | [`import.php:707`](../../../private/handlers/import.php) | CSV, unbekannte Güte | **nein** — siehe unten |
| `timer` | historisch | `NOW()` beim Arbeitsbeginn | **nein** |

Der `NOW()`-Zweig in [`records.php:195`](../../../private/handlers/records.php) ist **toter Code**:
`appointment_id` ist Pflicht, in `appointments` sind `date` und `start_time` NOT NULL, und seit
[`ehrensache_db.sql:232`](../../../private/setup/ehrensache_db.sql) hängt ein Fremdschlüssel daran —
wird der Termin nicht gefunden, scheitert der INSERT eine Zeile später. Der Zweig wird beim Umbau
entfernt.

**Zur Selbstauskunft:** Sie zählt mit. Das Gegenargument ist real — jede Verspätung ließe sich
nachträglich per Antrag heilen, und dann misst die Zahl, wer Anträge stellt. Es wiegt hier weniger
als der umgekehrte Fall: Wem das Stempeln technisch misslungen ist, darf dafür nicht bestraft
werden. Die Verantwortung liegt bei der Freigabe, nicht beim Algorithmus. Der Bericht weist die
Zahl getrennt aus („davon 3 nachträglich genehmigt"), damit die Freigabepraxis sichtbar bleibt.

**Zum Import:** Er zählt nicht. Die Pflichtspalte `arrival_date_time` ([`import.php:555`](../../../private/handlers/import.php))
erzwingt zwar eine Zeit, sagt aber nichts über deren Güte — ein Altsystem, das nur Datum kannte,
liefert „20:00" für alle. Ein Import ist ein einmaliger Altbestandstransfer und soll die laufende
Kennzahl nicht prägen. Die Daten bleiben unangetastet, nur die Auswertung übergeht sie.

### 3.6 Zuverlässigkeit über drei Ausgänge, ohne Status `absent`

Die heutige Anwesenheitsquote bestraft eine rechtzeitige Absage wie unentschuldigtes Fehlen. Das
ist der Punkt, den sie verfehlt.

| Ausgang | Bedingung |
|---|---|
| **erschienen** | Record mit `status = 'present'` |
| **abgemeldet** | Ausnahme zum Termin, angelegt **vor** Terminbeginn, nicht abgelehnt |
| **ausgefallen** | im Soll, aber weder das eine noch das andere |

Quote = (erschienen + abgemeldet) / Soll-Termine.

**Kein Status `absent`.** `status` ist heute `ENUM('present','excused')`; Fehlen heißt: kein
Record. Ihn zu materialisieren verlangte einen Cronjob nach jedem Termin oder Records auf Vorrat
beim Anlegen — und anschließend dauerhafte Synchronität, wenn ein Mitglied der Gruppe später
beitritt, ein Termin die Terminart wechselt oder jemand inaktiv wird. „Ausgefallen" ist die
**Restmenge** aus einer Soll-Menge, die ohnehin berechnet wird: Die heutige Anwesenheitsquote
braucht sie als Nenner. Die damalige Entscheidung war richtig und bleibt.

**Eine offene Abmeldung zählt wie eine genehmigte**, nur eine abgelehnte nicht. Das Mitglied hat
rechtzeitig gemeldet; eine liegengebliebene Freigabe darf ihm nicht schaden.

**Verhältnis zu FI-1 (Terminzusage im Vorfeld):** Kein Grund zu warten. FI-1 speichert eine
*Absicht vor dem Termin*, keinen Anwesenheitsstatus danach. Es käme lediglich eine zweite,
freigabefreie Quelle für den Ausgang „abgemeldet" hinzu — die Definition oben ist so geschnitten,
dass sie das additiv aufnimmt.

**Namenskollision, jetzt zu klären:** [FI-2](../../FEATURE-IDEAS.md) heißt
„Zusageverlässlichkeit", diese Kennzahl heißt „Zuverlässigkeit". Zwei Prozentzahlen mit fast
gleichem Namen und verschiedener Bedeutung — die eine misst, ob jemand hält, was er zugesagt hat,
die andere, ob er erscheint oder sich abmeldet. Falls FI-1/FI-2 je gebaut werden, ist die
Benennung vorher zu bereinigen.

---

## 4 Datenmodell

### 4.1 Das Kernproblem und seine Auflösung

`arrival_time` ist heute `DATETIME NOT NULL` ([`ehrensache_db.sql:102`](../../../private/setup/ehrensache_db.sql))
und trägt **zwei Rollen**, die nie getrennt wurden:

1. *Wann kam die Person?* — die Messung
2. *Zu welchem Zeitraum gehört der Datensatz?* — der Datumsträger

An Rolle 2 hängen die Jahresliste ([`statistics.php:32`](../../../private/handlers/statistics.php)),
die DSGVO-Löschfrist ([`settings.php:513`](../../../private/handlers/settings.php),
[`records.php:279`](../../../private/handlers/records.php)) und drei Indizes. Genau deshalb *musste*
eine fehlende Uhrzeit bisher erfunden werden — ein Record ohne Datum fiele aus der Jahresauswahl
und würde **von der Löschfrist nie erfasst**.

Abschnitt 11 der Vorgänger-Spec löste das mit einer zusätzlichen Spalte `arrival_measured`. Diese
Spec verwirft das: Die Spalte wäre nur nötig, weil ein konstruierter Wert im selben Feld steht wie
ein gemessener. Lässt man die Uhrzeit `NULL` sein, sagt das Feld selbst aus, ob es eine Aussage
enthält — kein Flag, das mit seinem Wert auseinanderlaufen kann.

**Entschieden:**

```sql
arrival_time  DATETIME NULL   -- NULL = keine Aussage über die Ankunft
```

Rolle 2 wandert auf `appointments.date`. Der Record hängt über `appointment_id` an genau einem
Termin (`unique_member_appointment`, Fremdschlüssel, `date` NOT NULL) — das Datum ist dort immer
verfügbar.

**Nebengewinn:** Die Jahresbasis ist heute uneinheitlich.
[`statistics.php:338`](../../../private/handlers/statistics.php) rechnet über `YEAR(a.date)`, die
Jahresauswahl daneben zieht zusätzlich `YEAR(arrival_time)`. Ein Termin am 31.12. um 22:00 mit
Ankunft um 00:15 erzeugt heute einen Record im Folgejahr — die Statistik zählt ihn ins alte, die
Jahresliste kennt ihn im neuen. Der Umbau räumt das mit auf.

**Bewusst nicht weiter getrieben:** `arrival_time` auf `TIME` zu reduzieren und das Datum ganz zu
streichen wäre noch sauberer, bräche aber die CSV-Spalte `arrival_date_time` — die Schnittstelle
nach außen, an der ein Formatbruch Vereinen am ehesten wehtut. Der Schritt bleibt jederzeit
möglich und ist dann rein kosmetisch.

### 4.2 `checkin_source` bekommt einen Wert

```sql
checkin_source ENUM('admin','user_totp','device_auth','auto_checkin',
                    'import','timer','station_pin','exception_request')
```

`exception_request` für Records aus einem genehmigten Zeitkorrektur-Antrag. Damit ist die Herkunft
der Uhrzeit vollständig aus `checkin_source` ablesbar und **keine zusätzliche Spalte nötig**.

### 4.3 Schreibpfade

Jeder Pfad setzt Uhrzeit und Herkunft ausdrücklich:

| Datei | Änderung |
|---|---|
| [`records.php:180–196`](../../../private/handlers/records.php) | Fallback-Kette entfällt. Ohne mitgegebene Zeit: `arrival_time = NULL`. Der tote `NOW()`-Zweig wird gelöscht. |
| [`records.php:246–254`](../../../private/handlers/records.php) | PUT: Leerwert schreibt `NULL` statt zu scheitern. |
| [`utils.php:36–52`](../../../private/helpers/utils.php) | **Fehlerbehebung.** Der Pfad setzt `checkin_source` heute gar nicht: Beim INSERT greift der Spalten-Default `'admin'`, beim UPDATE bleibt die **alte Quelle stehen** — überschreibt ein genehmigter Antrag eine echte Kiosk-Messung, trägt der Datensatz danach die Selbstauskunft und weiterhin `station_pin`. Beide Zweige setzen künftig `exception_request`. |
| [`utils.php:74–90`](../../../private/helpers/utils.php) | `handleApprovedAbsence` legt einen `excused`-Record mit Terminstartzeit an. Künftig `arrival_time = NULL` — der Record sagt „entschuldigt", nicht „um 20:00 erschienen". |
| `auto_checkin.php`, `totp_checkin.php`, `station.php` | unverändert; sie liefern immer eine Zeit. |
| [`import.php:699/707`](../../../private/handlers/import.php) | unverändert; `arrival_date_time` bleibt Pflichtspalte. |

### 4.4 Lesepfade

| Datei | Änderung |
|---|---|
| [`statistics.php:32`](../../../private/handlers/statistics.php) | Jahresliste nur noch aus `appointments.date`; der `YEAR(arrival_time)`-Zweig entfällt. |
| [`settings.php:513`](../../../private/handlers/settings.php) | Löschfrist als Join: `DELETE r FROM records r JOIN appointments a ON a.appointment_id = r.appointment_id WHERE a.date < ?` |
| [`records.php:279`](../../../private/handlers/records.php) | dieselbe Umstellung beim Mitglied |
| [`records.php:140`](../../../private/handlers/records.php) | Sortierung: `ORDER BY a.date DESC, r.arrival_time IS NULL, r.arrival_time DESC` — Records ohne Zeit ans Ende ihres Tages |
| `export.php`, `my_data.php` | `NULL` als leere Zelle ausgeben, nicht als `01.01.1970` |
| [`auto_checkin.php:179`](../../../private/handlers/auto_checkin.php) | Dublettenvergleich muss `NULL` vertragen |
| `report_statistics.php` | Die Heuristik aus 6.3 der Vorgänger-Spec entfällt — sie war die Übergangslösung bis hierher |

### 4.5 Migration

**Der Name steht erst bei der Umsetzung fest.** Eine Migration heißt nach ihrer
*Ausgangsversion*, und `from` muss dem `to` des letzten Manifest-Eintrags entsprechen. Heute endet
die Kette bei `to: '1.4.1'` — die nächste Migration hieße also `1.4.1.php` mit `from: '1.4.1'`.
Wird vorher ein Release ausgeliefert, verschiebt sich beides entsprechend.

**Vorbedingung, die nicht aus dieser Spec stammt, sie aber blockiert:** Die Funktionen auf `dev`
(Anwesenheitsbericht, Berichte für die Rolle `user`, QR-Kopplung der Station) sind **ohne
Versionssprung** gemergt — `version.json` steht auf 1.4.1. Sobald diese Version steigt, verlangt
die Kette einen Manifest-Eintrag mit passendem `from`:
[`getTargetVersion()`](../../../public/update/index.php) (`update/index.php:54`) liest schlicht
`version.json` und reicht den Wert in Zeile 134–136 an
[`resolveMigrationChain()`](../../../private/helpers/migrations.php)
(`migrations.php:90`, Abbruch in Zeile 107) weiter. Fehlt der Einstieg, bricht das Update mit
*„Keine Migration ab Version 1.4.1 vorhanden"* ab.

Das gilt **unabhängig von dieser Spec und unabhängig davon, ob sich am Schema etwas ändert.**
[`1.4.0.php`](../../../private/migrations/1.4.0.php) ist genau so ein Fall: 25 Zeilen, kein DDL,
nur da, damit die Kette nicht reißt. Ihr Kommentar sagt es wörtlich — die Kette muss lückenlos bis
zur Version aus `version.json` führen.

Daraus folgt für die Reihenfolge: Wird der 1.5.0-Sprung vor dieser Arbeit nachgeholt, braucht er
seine eigene (voraussichtlich leere) Migration `1.4.1.php`, und diese hier heißt `1.5.0.php`. Wird
er nicht nachgeholt, heißt diese hier `1.4.1.php` und trägt den Versionssprung mit. **Beides ist
vertretbar; die Entscheidung gehört dem Release, nicht dieser Spec** — sie muss nur getroffen
sein, bevor die Migration geschrieben wird.

Inhalt der Migration:

1. `checkin_source` um `exception_request` erweitern
2. `arrival_time` auf `DATETIME NULL` ändern
3. **Konstruierte Zeiten rückwirkend auf `NULL`:**
   ```sql
   UPDATE records r JOIN appointments a USING (appointment_id)
   SET r.arrival_time = NULL
   WHERE r.checkin_source IN ('admin','timer')
     AND r.arrival_time = CONCAT(a.date, ' ', a.start_time)
   ```
   Die Einschränkung auf `admin` und `timer` schützt den Kiosk-Stempel, der zufällig auf die
   Startminute fällt — er trägt `station_pin` und wird nicht angefasst.

   **In Kauf genommen:** Ein Admin, der eine Ankunft *bewusst* auf die Startminute gesetzt hat,
   verliert diese Angabe. Sie ist von einem Listeneintrag nicht unterscheidbar — beide tragen
   `admin` und dieselbe Uhrzeit. Der Fehler geht zulasten der Messabdeckung, nicht zulasten der
   Quote, und das ist die richtige Richtung: lieber eine Messung zu wenig als eine erfundene.
4. `excused`-Records auf `arrival_time = NULL`, **aber nur, wenn die Zeit der Startzeit
   entspricht** — dieselbe Bedingung wie in Schritt 3. Wer gestempelt hat und erst danach auf
   „entschuldigt" gesetzt wurde, behält seine echte Ankunftszeit. Sie zählt zwar nicht in die
   Pünktlichkeit (die filtert auf `status = 'present'`), ist aber im Bericht sichtbar und darf
   nicht gelöscht werden.
5. Index `idx_year` (`arrival_time`) entfernen — er stützte allein `YEAR(arrival_time)`.
   `idx_member_year` (`member_id`, `arrival_time`) **bleibt**: das `member_id`-Präfix ist weiter
   nützlich. `idx_arrival` bleibt für die Sortierung.
6. `private/setup/ehrensache_db.sql` nachziehen

Die Migration ist **verlustbehaftet und nicht umkehrbar** — Schritt 3 und 4 löschen Uhrzeiten, die
nie eine Aussage waren. Der Update-Wizard weist darauf hin und nennt die Zahl der betroffenen
Datensätze im Protokoll.

---

## 5 Berechnung

Alles in `private/helpers/punctuality.php` (neu), damit es ohne HTTP testbar ist — dieselbe
Trennung wie bei `worktime.php` und `station.php`.

### 5.1 Pünktlichkeit

Grundmenge je Mitglied und Zeitraum:

```
status = 'present'
AND arrival_time IS NOT NULL
AND checkin_source NOT IN ('import', 'timer')
```

Für jeden Datensatz: `v = arrival_time − (appointment.date + appointment.start_time)` in Minuten,
positiv = zu spät.

| Größe | Formel |
|---|---|
| `measured_count` | Anzahl der Grundmenge |
| `on_time_count` | Anzahl mit `v ≤ punctuality_grace_minutes` |
| `rate` | `on_time_count / measured_count` |
| `avg_late_minutes` | Mittelwert von `min(v, 20)` über `{ v > 0 }`, auf eine Nachkommastelle |
| `late_count` | Anzahl mit `v > 0` |
| `self_reported_count` | Anzahl mit `checkin_source = 'exception_request'` |
| `total_count` | Soll-Termine im Zeitraum — die Bezugsgröße der Messabdeckung |

**Unter fünf Messungen wird keine Quote ausgegeben**, sondern `sufficient: false`. Die Oberfläche
zeigt dann „zu wenige Messungen (3)" — nicht 0 %, nicht 100 %, nicht leer. Der Schwellwert 5 ist
fest im Code; siehe Abschnitt 9.

Ist `late_count = 0`, entfällt `avg_late_minutes` (`null`), statt 0 auszugeben — „im Schnitt 0
Minuten zu spät" ist keine Aussage über eine leere Menge.

### 5.2 Zuverlässigkeit

Soll-Menge je Mitglied: Termine im Zeitraum, deren Terminart eine Gruppe des Mitglieds trifft,
eingeschränkt auf dessen aktive Zeiträume (`getMemberActivityWhereYear()`, wie die heutige
Anwesenheitsquote).

| Größe | Formel |
|---|---|
| `appeared` | Records mit `status = 'present'` |
| `excused_in_time` | Ausnahmen mit `created_at < appointment.date + start_time` und `status ≠ 'rejected'` |
| `missed` | `total − appeared − excused_in_time` |
| `rate` | `(appeared + excused_in_time) / total` |

Erscheint jemand trotz Abmeldung, zählt `appeared` — er war da. Doppelzählung wird über die
Reihenfolge verhindert: erst `appeared`, dann `excused_in_time` nur für Termine ohne Record.

---

## 6 Einstellungen

| Schlüssel | Typ | Vorgabe | Bedeutung |
|---|---|---|---|
| `punctuality_enabled` | bool | **aus** | Pünktlichkeitskennzahl berechnen und anzeigen |
| `reliability_enabled` | bool | **aus** | Zuverlässigkeitskennzahl berechnen und anzeigen |
| `punctuality_grace_minutes` | int, −60…+60 | `0` | Karenz relativ zum Terminbeginn |

**Beide Vorgaben stehen auf „aus".** Eine personenbezogene Verhaltenskennzahl darf nicht durch ein
Update entstehen, das jemand eingespielt hat, um einen Fehler zu beheben. Das Einschalten ist die
bewusste Entscheidung des Verantwortlichen — und genau das verlangt `DATENSCHUTZ.md` an dieser
Stelle (Abschnitt 7).

Zwei getrennte Schalter, weil die Zuverlässigkeit keine Messdaten braucht: Ein Verein ohne Kiosk
kann sie sinnvoll nutzen, während die Pünktlichkeit bei ihm dauerhaft „zu wenige Messungen"
meldet.

Die Einstellungen kommen **nicht** in die `scope=client`-Whitelist in
[`settings.php:30`](../../../private/handlers/settings.php) — die PWA braucht sie nicht.

---

## 7 Rechte und Datenschutz

| Rolle | Sicht |
|---|---|
| `admin`, `manager` | alle Mitglieder, ohne Gruppengrenze — konsistent mit records, exceptions, statistics, work_sessions |
| `user` | **nur die eigenen Werte**, in der Statistik-Sektion und in `my_data` |
| `device` | nichts |

**Keine Rangliste.** Weder Sortierung nach Pünktlichkeit noch eine gruppenweite Tabelle, die
Mitglieder gegeneinander stellt, für die Rolle `user`. Dieselbe Abwägung, die `my_data` bereits
getroffen hat und die [FI-2](../../FEATURE-IDEAS.md) für die Zusageverlässigkeit aufwirft: Im
Ehrenamt kostet eine öffentliche Bewertung von Personen Stimmung, und der Gewinn ist gering.

**`DATENSCHUTZ.md` ist vor der Umsetzung zu ergänzen** — das ist Vorbedingung, nicht Nacharbeit:

- **Zweck:** Auswertung der Anwesenheitsdisziplin für die Vereinsführung
- **Rechtsgrundlage:** berechtigtes Interesse bzw. Vereinssatzung; die Kennzahl ist deaktivierbar
  und standardmäßig aus
- **Aufbewahrung:** keine eigene — die Kennzahl ist eine Berechnung über `records` und
  `exceptions` und verschwindet mit deren Löschfrist
- **Betroffenenrechte:** Die eigenen Werte erscheinen in `my_data` (Auskunft nach Art. 15)
- **Sichtbarkeit für andere Rollen:** wie oben tabelliert

---

## 8 API und Oberfläche

**Kein neuer Endpunkt.** Die Kennzahlen kommen als zusätzliche Blöcke aus `statistics` — das
vermeidet einen Eintrag in `demo_mode.php` und hält die Filterlogik an einer Stelle.

```json
"punctuality": {
  "enabled": true, "sufficient": true,
  "measured_count": 15, "total_count": 22,
  "on_time_count": 12, "rate": 0.8,
  "late_count": 3, "avg_late_minutes": 7.3,
  "self_reported_count": 1
},
"reliability": {
  "enabled": true,
  "total": 22, "appeared": 15, "excused_in_time": 4, "missed": 3, "rate": 0.864
}
```

Ist der jeweilige Schalter aus, steht dort `{"enabled": false}` und sonst nichts — keine Nullwerte,
die eine abgeschaltete Kennzahl wie eine leere aussehen lassen.

**Oberfläche:** zusätzliche Kennzahlenkacheln in der Statistik-Sektion, mit der Messabdeckung als
Unterzeile. Im Statistikbericht zusätzliche Zeilen im ersten Abschnitt — additiv, ein älterer
Ausdruck wird dadurch nicht falsch. In `my_data` die eigenen Werte.

Formulierungen, wörtlich so:

- „Pünktlich bei 12 von 15 gemessenen Ankünften (80 %)"
- „Wenn zu spät, dann im Schnitt 7,3 Minuten"
- „Gemessen bei 15 von 22 Terminen"
- „Zu wenige Messungen (3 von mindestens 5)"
- „Erschienen oder rechtzeitig abgemeldet: 19 von 22 (86 %)"

---

## 9 Tests

| Suite | Prüft |
|---|---|
| `punctuality_unit` (neu) | Formeln aus Abschnitt 5 ohne HTTP: Karenz 0 / −5 / +5, Kappung bei 20, leere Verspätungsmenge, Schwelle von 5 Messungen, Quellenfilter |
| `punctuality_api` (neu) | Endpunkt je Rolle, beide Schalter an/aus, Sichtbarkeitsgrenzen aus Abschnitt 7 |
| `migrations` (erweitert) | Schritt 3 und 4 der Migration: konstruierte Zeiten werden `NULL`, ein `station_pin`-Stempel auf der Startminute **nicht** |
| `api_selftest` (erweitert) | **Löschfrist über den Join** — ein Record mit `arrival_time = NULL` muss von der Frist erfasst werden. Der wichtigste Test dieser Spec: Hier entstünde sonst ein Datenschutzfehler. |
| `api_selftest` (erweitert) | Export/Import-Rundlauf mit leerer Uhrzeit |

Dazu `docs/testplan.md` um einen Abschnitt ergänzen: Liste abhaken → Ankunftszeit bleibt leer →
Kennzahl ignoriert den Datensatz.

---

## 10 Reihenfolge und Abhängigkeit

**[OI-48](../../OPEN-ITEMS.md#oi-48--statistik-zählt-je-gruppe-nur-eine-terminart) gehört davor.** Solange die Statistik je Gruppe nur eine
Terminart auswertet, erbt jede neue Kennzahl denselben Ausschnitt — die Zahl stünde dann unter
einer Überschrift, die mehr verspricht, als sie zeigt. Diese Spec ist unabhängig davon umsetzbar,
sollte aber **nicht vor OI-48 ausgeliefert** werden.

Sinnvolle Teilung in zwei Schritte:

1. **Datenmodell** (Abschnitt 4) — für sich wertvoll: Es behebt den `utils.php`-Fehler, räumt die
   Jahresbasis auf und beendet die erfundenen Uhrzeiten. Ohne jede neue Kennzahl.
2. **Kennzahlen** (Abschnitte 5–8) — danach, nach OI-48.

**Berührungspunkte mit der OI-48-Arbeit** (abgeglichen am 2026-09-11 mit der parallel
entstehenden Spec `2026-09-11-anwesenheitsauswertung-terminarten-design.md`):

| Datei | OI-48 | diese Spec |
|---|---|---|
| `statistics.php` | `calculateGroupStatistics()`, `getActiveMemberCount()` wandern nach `private/helpers/attendance.php`; `buildStatisticsResult()` behält die Signatur | `handleAvailableYears()` (Zeile 32) — bleibt, wo es ist |
| `report_statistics.php` | Gruppentabelle bekommt Spalten je Terminart | `statisticsReportOrigin()`: Heuristik entfällt zugunsten der echten Datenlage |

Zwei gemeinsame Dateien, keine gemeinsame Funktion. Nacheinander gebaut unproblematisch; **parallel
in zwei Zweigen nicht empfohlen.**

Die Gegenrichtung ist geprüft: Das Datenmodell aus Abschnitt 4 wirkt **nicht** auf die
Anwesenheitszahlen. Die Statistik liest `arrival_time` nirgends — sie wertet `records.status` und
das Fehlen eines Eintrags aus.

---

## 11 Bewusst weggelassen

| Verworfen | Grund |
|---|---|
| Median der Verspätung | Trennt C und D aus 3.1 nicht; springt bei kleinen Stichproben |
| Zusammengerechneter „Score" aus beiden Kennzahlen | Verbirgt, was passiert ist, und lädt zum Missbrauch ein |
| Fehlzeiten in die Pünktlichkeit einrechnen | Erzeugt den Kollaps aus 3.2 |
| Belohnung für frühes Erscheinen (das `+10` der Praxis) | Saldiert „früh" und „spät" zu einer Zahl, die man deuten lernen muss |
| Kappung und Messschwelle als Einstellung | Zwei weitere Stellschrauben, die Vereine unvergleichbar machen; 20 und 5 sind fest |
| Frist für die Abmeldung (z. B. 24 Stunden) | Terminbeginn ist die natürliche Grenze; eine Frist wäre eine weitere Einstellung ohne geäußerten Bedarf |
| Spalte `arrival_measured` | Durch `arrival_time = NULL` überflüssig (4.1) |
| Status `absent` | Ableitbare Restmenge; Materialisierung verlangt dauerhafte Synchronität (3.6) |
| `arrival_time` auf `TIME` reduzieren | Bräche die CSV-Spalte `arrival_date_time`; später jederzeit nachholbar |
| Rangliste nach Pünktlichkeit | Bewertung von Personen statt Betriebsstatistik (Abschnitt 7) |
