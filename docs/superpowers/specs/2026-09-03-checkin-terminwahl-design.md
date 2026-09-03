# Check-in ohne passenden Termin — Terminwahl statt Automatik

**Datum:** 2026-09-03 · **Zielversion:** 1.2.4 · **Branch:** `dev`
**Status:** entworfen, nicht umgesetzt

---

## 1 Ausgangslage

Findet `handleAutoCheckin()` im Toleranzfenster keinen Termin, legt sie selbst einen an —
Titel `Automatisch erstellter Termin`, Standard-Terminart, Zeit auf fünf Minuten gerundet
([auto_checkin.php:320-352](../../../private/handlers/auto_checkin.php)). Das geschieht ohne
Rückfrage, ohne Kennzeichnung und ohne Möglichkeit, es abzuschalten.

Drei Eigenschaften machen das zum Problem:

**Die Funktion sitzt nicht in der PWA.** Sie liegt im gemeinsamen Endpunkt aller
Check-in-Wege:

| Weg | Aufruf | Oberfläche zur Terminwahl |
|---|---|---|
| PWA-Scan (QR/NFC/Code) | `totp_checkin.php:72` → `user_totp` | ja |
| IoT-Station, Biometrie | `api.php:497` → `device_auth` | nein |
| Admin/Manager per API oder Token | `api.php:497` | teilweise |

**Ein Auto-Termin verfälscht fremde Statistiken.** `statistics.php` zählt jeden Termin einer
Terminart bei **jedem aktiven Mitglied** der verknüpften Gruppen als Solltermin; wer keinen
Record hat, gilt als unentschuldigt abwesend
([statistics.php:277-290](../../../private/handlers/statistics.php)). Ein Fehlscan um drei Uhr
nachts senkt damit die Quote aller anderen.

**Das Verhalten hängt an einer unabhängigen Einstellung.** Ist keine Standard-Terminart
gesetzt, bekommt der Auto-Termin `type_id = NULL` und taucht wegen `WHERE a.type_id = ?` in
gar keiner Statistik auf. Ob Schaden entsteht, entscheidet also eine Einstellung, die mit dem
Check-in nichts zu tun hat.

Strukturell ist das derselbe Fall, der in 1.2.3 zur Regel „kein Weg der Zeiterfassung erzeugt
Anwesenheit" geführt hat (siehe OI-4): ein Automatismus, der stillschweigend Daten erzeugt,
die in die Kernauswertung einfließen.

## 2 Leitsatz

> Ein Check-in erzeugt nur dann einen Termin, wenn der Verein das ausdrücklich erlaubt hat —
> und das Mitglied darf vorher selbst wählen.

Der Zustandsraum wird damit vollständig:

| Situation | Automatik **an** | Automatik **aus** |
|---|---|---|
| Termin gewählt (PWA) | dieser Termin | dieser Termin |
| Nichts gewählt, Treffer im Fenster | Treffer | Treffer |
| Nichts gewählt, kein Treffer | Termin wird angelegt, **markiert** | **409**, Klartext |

Die Regel gilt für alle Check-in-Wege gleich. Wo keine Oberfläche existiert (IoT-Station),
entfällt nur die Wahl, nicht die Regel.

## 3 Neue Einstellungen

| Schlüssel | Typ | Bestand | Neuinstallation | Wirkung |
|---|---|---|---|---|
| `checkin_auto_create_appointment` | boolean | `'1'` | `'0'` | Automatik an/aus |
| `checkin_tolerance_hours` | number | Wert der Konstante | `'2'` | Suchfenster in Stunden |

Beide in `category = 'general'`, sichtbar unter *Einstellungen → Allgemein*.

Der abweichende Startwert der ersten Einstellung ist Absicht: Kein bestehender Verein wird vom
Update überrascht, wer neu anfängt entscheidet bewusst. Die Abweichung gehört in `CHANGELOG.md`
**und** in die `description`-Spalte der Einstellung — sonst ist sie in zwei Jahren ein Rätsel.

## 4 Serverseite

### 4.1 Gruppenprüfung herauslösen

Die verschachtelte Schleife ab [auto_checkin.php:200](../../../private/handlers/auto_checkin.php)
beantwortet die Frage „darf dieses Mitglied zu diesem Termin?". Genau diese Frage stellt sich
jetzt ein zweites Mal — für den vom Client geschickten Termin.

Neue Funktion, im selben Handler:

```php
function memberMayAttendAppointment(PDO $db, string $prefix, int $memberId, ?int $typeId): bool
```

Regel unverändert übernommen: kein `type_id` oder keine Gruppenbindung der Terminart bedeutet
**für alle zugänglich**; sonst muss das Mitglied in einer der genannten Gruppen sein. Die
Priorisierung „Termin mit Standard-Gruppe schlägt Termin ohne" bleibt in der Schleife, sie ist
Auswahllogik und keine Berechtigungsfrage.

Ohne diese Auslagerung entstünde eine zweite, abweichende Kopie der Berechtigungsregel — der
gewöhnliche Weg, auf dem Rechteprüfungen auseinanderlaufen.

### 4.2 Optionales Feld `appointment_id`

Neu im Request-Körper von `auto_checkin`. `totp_checkin` nimmt das Feld entgegen und **reicht
es durch** — es ruft `handleAutoCheckin()` auf und liest den Körper nicht selbst neu ein, die
Weitergabe geschieht also von allein. Der PWA-Weg läuft über diesen Endpunkt und wäre sonst
der einzige ohne Wirkung.

Drei Prüfungen, alle serverseitig:

| Prüfung | Fehlschlag |
|---|---|
| Termin existiert | `404` |
| `memberMayAttendAppointment()` | `403` |
| `appointment.date` == Datum aus `arrival_time` | `409` |

**Die dritte Prüfung ist sicherheitsrelevant.** Ohne sie könnte ein Mitglied der Rolle `user`
von Hand eine beliebige `appointment_id` des Jahres senden und sich rückwirkend anwesend
melden. Heute ist das nur deshalb ausgeschlossen, weil das Toleranzfenster die Auswahl trifft;
sobald der Client mitredet, muss die Grenze ausgesprochen werden.

Die Prüfung gilt für **alle** Rollen. Admin und Manager korrigieren über `records`, nicht über
den Check-in-Endpunkt.

### 4.3 Ausfallfall

Kein Treffer und Automatik aus:

```json
{ "message": "Kein passender Termin gefunden",
  "reason": "no_matching_appointment",
  "hint": "Bitte beim Vorstand melden" }
```

HTTP `409`. Ist die Automatik an, bleibt der bestehende Pfad unverändert — bis auf
`is_auto_created = 1` beim Anlegen.

### 4.4 Toleranz aus der Datenbank

Zwei neue Funktionen in `private/helpers/utils.php`:

```php
function systemSetting($db, $database, string $key, string $default): string
function checkinToleranceHours($db, $database): int
```

`worktimeSetting()` in [worktime.php:255](../../../private/helpers/worktime.php) ist trotz
ihres Namens bereits generisch — sie liest einen beliebigen Schlüssel aus `system_settings`.
Ihr Rumpf wandert unverändert nach `systemSetting()`; `worktimeSetting()` bleibt als
Weiterleitung stehen, damit kein bestehender Aufrufer bricht. Ohne diesen Schritt entstünde
eine zweite Lesefunktion neben der vorhandenen.

`checkinToleranceHours()` liest die Kette: `system_settings` → Konstante
`AUTO_CHECKIN_TOLERANCE_HOURS` → `2`. Begrenzt auf 0–8, wie die bestehende Prüfung in
[auto_checkin.php:126](../../../private/handlers/auto_checkin.php).

Die Konstante in `config.php` **bleibt bestehen**. Sie ist der Rückfall, wenn die Migration
nicht gelaufen ist oder die Zeile fehlt.

Alle fünf Aufrufstellen nutzen die Funktion:

| Stelle | Zweck |
|---|---|
| `auto_checkin.php:123` | Suchfenster des Check-ins |
| `appointments.php:162` | Dublettenwarnung beim Anlegen |
| `appointments.php:224` | Dublettenwarnung beim Bearbeiten |
| `import.php:554` | Terminzuordnung beim Record-Import |
| `import.php:635` | Vorgabewert von `extractAppointments()` |

Der Parameter `tolerance_hours` im Request bleibt, wie er ist. Er überschreibt weiterhin pro
Anfrage und ist auf 0–8 begrenzt.

**Migrationsfalle:** Ein Verein, der `config.php` auf `4` gesetzt hat, bekäme durch eine
Migration mit fest verdrahteter `2` still ein anderes Verhalten. Die Migration liest deshalb
`AUTO_CHECKIN_TOLERANCE_HOURS` ein und schreibt **diesen** Wert. Ist die Konstante nicht
definiert, schreibt sie `2`.

### 4.5 Lesepfad für angemeldete Nutzer

`handleSettings()` beginnt heute mit `requireAdmin()`
([settings.php:20](../../../private/handlers/settings.php)). Die PWA braucht zwei Werte, ohne
Administrator zu sein.

`GET ?resource=settings&scope=client` liefert eine **Whitelist**, erreichbar für jede
authentifizierte Rolle:

```php
const CLIENT_READABLE_SETTINGS = [
    'checkin_auto_create_appointment',
    'checkin_tolerance_hours',
];
```

`requireAdmin()` rückt hinter diese Abzweigung. Die Whitelist ist eine feste Liste im Code,
keine Kategorie in der Datenbank — eine neu angelegte Einstellung wird damit nicht versehentlich
lesbar.

Bewusst **nicht** über den vorhandenen `appearance`-Endpunkt: der ist unauthentifiziert und
liefert `category = 'public'`. Beides passt hier nicht.

## 5 PWA

### 5.1 Terminwahl im Check-in-Tab

Über dem Scan-Knopf, gebaut nach dem Muster von `worktimeAppointment`
([app.js:2602](../../../public/checkin/js/app.js)):

```
Termin (optional)  [ Termin wählen …            ▾ ]
Ohne Auswahl wird der passende Termin gesucht —
findet sich keiner, wird ein neuer angelegt.
```

**Inhalt:** Termine des **heutigen Tages**, `from_date = to_date = heute`. Nicht des Jahres wie
bei der Arbeitszeit: Anwesenheit ist an den Tag gebunden, und die Prüfung aus 4.2 lehnt alles
andere ohnehin ab.

**Das Datum kommt aus der lokalen Zeit.** Der UTC-Fehler aus OI-4 (`d7ee191`) — abends ab
22:00 MESZ zeigte die Auswahl den Folgetag — darf sich hier nicht wiederholen.

**Der Hinweistext hängt am Wert aus `scope=client`.** Bei ausgeschalteter Automatik lautet der
zweite Satz: „…findet sich keiner, schlägt der Check-in fehl."

### 5.2 Meldung nach dem Scan

Die Antwort trägt `appointment_action` bereits heute. Die App unterscheidet:

| `appointment_action` | Meldung |
|---|---|
| `matched` | ✅ Eingecheckt: *Titel* |
| `created` | ✅ Eingecheckt — **neuer Termin angelegt**: *Titel, Uhrzeit* |

Der Vorab-Hinweis kündigt an, diese Meldung belegt. Ohne sie erfährt das Mitglied nie, dass es
gerade einen Termin erzeugt hat.

### 5.3 Fehlertext

In die vorhandene Zuordnung bei [app.js:228](../../../public/checkin/js/app.js):

```js
'Kein passender Termin gefunden':
    'Kein passender Termin gefunden. Bitte beim Vorstand melden.'
```

### 5.4 Die dritte Toleranz

`app.js:816` filtert die Terminauswahl der Anwesenheitsliste mit einer hart notierten `6`. Das
ist ein dritter Wert neben `config.php` und `public/js/config.js` und gehört auf
`checkin_tolerance_hours` aus 4.4 umgestellt, geliefert über den Lesepfad aus 4.5.

`public/js/config.js:44` exportiert `AUTO_CHECKIN_TOLERANCE_HOURS = 2` und legt den Wert unter
`autoCheckinTolerance` ab — **niemand importiert beides.** Tote Konstante, ersatzlos entfernen,
sonst steht nach dieser Änderung eine falsche Zahl im Quelltext.

## 6 Kennzeichnung

Neue Spalte:

```sql
ALTER TABLE {PREFIX}appointments
  ADD COLUMN is_auto_created TINYINT(1) NOT NULL DEFAULT 0
```

Gesetzt **ausschließlich** von `handleAutoCheckin()`. `POST` und `PUT` auf `appointments` fassen
die Spalte nicht an — sonst wäre die Markierung fälschbar und damit wertlos.

**Altbestand:**

```sql
UPDATE {PREFIX}appointments SET is_auto_created = 1
 WHERE title = 'Automatisch erstellter Termin'
   AND description = 'Erstellt durch Zeiterfassung'
```

Eine Heuristik über zwei Zeichenketten, die seit jeher unverändert im Code stehen. Sie greift
zuverlässig, außer jemand hat einen Auto-Termin von Hand umbenannt — dann bleibt er unmarkiert,
was folgenlos ist. Ihre Trefferzahl gehört als Zeile ins Update-Protokoll, wie die
`timer`-Records in 1.2.3.

**Oberfläche:** Badge „🤖 automatisch" in der Terminliste, dazu ein Filter „nur automatisch
erzeugte". Damit hat ein Verein erstmals eine Antwort auf „was ist da im letzten Halbjahr
aufgelaufen?".

**Die Statistik bleibt unverändert.** Auto-Termine zählen weiter voll. Sie sichtbar zu machen
ist der Zweck; sie aus der Auswertung zu nehmen wäre ein zweiter, größerer Eingriff und ist
bewusst nicht Teil dieser Änderung (siehe 9).

## 7 Migration

Neue Datei `private/migrations/1.2.3.php` mit `migrate_1_2_3()`, ein Manifest-Eintrag
`from 1.2.3` → `to 1.2.4`.

Schritte:

1. Spalte `is_auto_created` anlegen, falls nicht vorhanden (`information_schema`-Prüfung wie in
   den bestehenden Migrationen).
2. Altbestand nachtragen, Trefferzahl protokollieren.
3. `checkin_auto_create_appointment = '1'` einfügen (`INSERT IGNORE`).
4. `AUTO_CHECKIN_TOLERANCE_HOURS` einlesen, `checkin_tolerance_hours` mit diesem Wert einfügen;
   Vorgabe `'2'`, wenn die Konstante fehlt. Weicht der Wert von `2` ab, als Zeile protokollieren.

`private/setup/ehrensache_db.sql` zieht nach: Spalte in der Tabellendefinition,
`checkin_auto_create_appointment = '0'` und `checkin_tolerance_hours = '2'` im
`INSERT IGNORE`-Block.

## 8 Tests

Neue Suite `tests/suites/checkin_appointment.php`:

| Fall | Erwartung |
|---|---|
| Automatik aus, kein Treffer | `409`, `reason = no_matching_appointment`, kein Termin angelegt |
| Automatik an, kein Treffer | `201`, `appointment_action = created`, `is_auto_created = 1` |
| Treffer im Fenster, Automatik egal | `appointment_action = matched`, unverändert zu 1.2.3 |
| `appointment_id` desselben Tages, Gruppe passt | `201`, dieser Termin |
| `appointment_id` einer fremden Gruppe | `403` |
| `appointment_id` von gestern | `409` |
| `appointment_id` existiert nicht | `404` |
| Gerät ohne `appointment_id` | verhält sich wie vor der Änderung |
| `checkin_tolerance_hours = 4`, Termin 3 h entfernt | Treffer (mit Konstante `2` wäre es keiner) |
| `scope=client` als Rolle `user` | `200`, genau die zwei Whitelist-Schlüssel |
| `scope=client` unauthentifiziert | `401` |

Der Fall `checkin_tolerance_hours = 4` ist der eigentliche Beweis, dass die Toleranz aus der
Datenbank gelesen wird und nicht mehr aus der Konstante: Mit der Vorgabe `2` wäre ein drei
Stunden entfernter Termin kein Treffer.

Ergänzung in `docs/testplan.md`: manuelle Fälle für die PWA-Auswahl, den Hinweistext in beiden
Schalterstellungen und das Badge in der Terminverwaltung.

## 9 Bewusst nicht enthalten

**Auto-Termine aus der Statistik nehmen, bis bestätigt.** Wäre die konsequente Fortsetzung der
1.2.3-Regel, verlangt aber Eingriffe in `statistics.php` und den Bericht. Entschieden am
2026-09-03: erst sichtbar machen, dann sehen, ob es reicht.

**Warteschlange für nicht zuordenbare Check-ins.** `records.appointment_id` ist `NOT NULL`; ein
Check-in ohne Termin verlangt eine Schemaänderung und eine neue Zuordnungsoberfläche. Verworfen
am 2026-09-03 zugunsten der ehrlichen Ablehnung.

**Getrennte Schalter für Selbst- und Geräte-Check-in.** Verworfen zugunsten einer Regel, die
sich in einem Satz erklären lässt.

## 10 Aufwand

| Bereich | Dateien | ~Zeilen | Zeit |
|---|---|---|---|
| `auto_checkin.php` inkl. Gruppenprüfung | 1 | 110 | 4 h |
| Settings-Lesepfad, `systemSetting()`, `checkinToleranceHours()` | 3 | 60 | 2 h |
| Toleranz an fünf Aufrufstellen | 3 | 25 | 1 h |
| PWA: Auswahl, Hinweis, Meldungen, dritte Toleranz | 2 | 120 | 5 h |
| Kennzeichnung und Filter im Dashboard | 2 | 45 | 2 h |
| Zwei Einstellungs-Felder | 2 | 30 | 1 h |
| Migration und `ehrensache_db.sql` | 3 | 85 | 2,5 h |
| Tests | 1 | 150 | 3,5 h |
| `API.md`, `CHANGELOG.md`, `testplan.md`, `OPEN-ITEMS.md`, `version.json` | 5 | 90 | 2 h |
| **Summe** | **22** | **~715** | **~23 h ≈ 3 Tage** |

## 11 Reihenfolge

1. `systemSetting()` und `checkinToleranceHours()` — alles Weitere hängt daran.
2. Migration und Schema, damit Einstellungen und Spalte existieren.
3. `auto_checkin.php`: Gruppenprüfung, `appointment_id`, `409`, `is_auto_created`.
4. Tests zu 3 — ab hier ist die Serverseite beweisbar.
5. Settings-Lesepfad.
6. PWA.
7. Kennzeichnung im Dashboard, Einstellungs-Felder.
8. Dokumentation, Versionssprung auf 1.2.4.
