# Demo-Datengenerator — Design

**Datum:** 2026-09-08
**Status:** entworfen, nicht umgesetzt
**Vorhaben ① von drei** — siehe „Einordnung" unten

---

## 1. Ziel

Ein Kommandozeilenskript, das eine EhrenSache-Datenbank auf Schemastand 1.3.1 in einen
reproduzierbaren, vorzeigbaren Zustand versetzt: den fiktiven **Musikverein Musterhausen**.

Zwei Abnehmer:

1. **Screenshots für die Werbeseite** (Vorhaben ②). Die heutigen Bilder stammen vom 20.03.2026
   und zeigen eine Oberfläche, die es so nicht mehr gibt.
2. **Stündlicher Reset der öffentlichen Demo** (Vorhaben ③). Das dortige `demo_reset.sql`
   stammt vom Fork-Punkt 26.01.2026 und ist auf 1.3.1 nicht lauffähig.

Beide brauchen denselben Datenbestand. Er entsteht einmal, als Programm statt als
abgeschriebene SQL-Datei.

## 2. Ausgangslage

**Lokale Testinstanz** (`ehrensache`, Präfix `ez_`): 74 Mitglieder, 55 Termine, aber nur
**7 Anwesenheitseinträge**. Anwesenheitsliste und Statistik sind faktisch leer. Dazu Rückstände
der Testsuiten — Tätigkeitsarten wie `Mit Terminart 6a9ff043aed53`, `Nurnotiz 6a9ff25e4d1f8`.
Vereinsname „EhrenSache", kein Logo.

**Bestehendes `private/demo/demo_reset.sql`** (Branch `demo`, 276 Zeilen) ist unbrauchbar:

- spricht Tabellen **ohne Präfix** an (`TRUNCATE TABLE members`) — das Präfix kam mit 1.1.0
- kennt keine der ab 1.2.0 hinzugekommenen Tabellen: `activity_types`, `work_sessions`,
  `work_session_log`, ebenso wenig `members.pin_hash` und den Gerätetyp `kiosk`
- schreibt feste Kalenderdaten aus 2024 statt relativer

Die Spaltennamen (`members.name`, `members.surname`) stimmen dagegen weiterhin.

## 3. Entscheidungen

| # | Entscheidung | Begründung |
|---|---|---|
| E1 | **PHP-CLI-Skript**, keine SQL-Datei | Relative Daten, Streuung und Wahrscheinlichkeiten lassen sich in SQL nicht wartbar ausdrücken. Das Skript liest `config.php` und kennt damit Präfix und Zugangsdaten. |
| E2 | **Fester Zufallssaat**, per Parameter änderbar | Zwei Läufe erzeugen denselben Bestand. Ein Screenshot lässt sich ein halbes Jahr später identisch nachstellen. |
| E3 | **Alle Zeitpunkte relativ zu einem Stichtag** (Vorgabe: heute) | Die Demo darf nicht altern. Ein fester Stichtag als Parameter erlaubt reproduzierbare Bilder. |
| E4 | **Leeren statt ergänzen** (`TRUNCATE`, keine `DROP`) | Wiederholbar, und die Testsuiten-Rückstände verschwinden. Tabellen und Schema bleiben unangetastet — der Generator ist keine Migration. |
| E5 | **Vereinsname „Musikverein Musterhausen", kein Logo** | Terminarten und Tätigkeiten tragen die Erzählung von selbst. Ohne hinterlegtes Logo greift die Rückfallregel in `private/helpers/branding.php` auf `assets/logo-default.png` — die Bilder zeigen damit nebenbei, wie die Anwendung ohne Vereinswappen aussieht. |
| E6 | **Ablage in `private/demo/`, mit `export-ignore`** | Der Branch `demo` erbt das Skript aus `dev`, ohne dass es im ZIP-Download eines Vereins landet. Ein Skript, das Tabellen leert, gehört nicht ins Installationspaket. |
| E7 | **Keine Demo-Restriktionen in diesem Vorhaben** | `checkDemoRestrictions()` und die Wächter in den Handlern sind Vorhaben ③. Der Generator schreibt nur Daten. |

## 4. Schnittstelle

```
php private/demo/seed.php [Optionen]

  --yes                   Ohne Rückfrage ausführen (für den Cron-Job)
  --seed=<int>            Zufallssaat (Vorgabe: 20260908)
  --reference-date=<Y-m-d>  Stichtag, auf den alle Zeitpunkte bezogen werden (Vorgabe: heute)
  --password=<klartext>   Passwort der drei Demo-Konten (Vorgabe: demo2025)
  --quiet                 Nur Fehler ausgeben
```

**Schutz vor dem falschen Ziel.** Ohne `--yes` nennt das Skript vor dem ersten Schreibzugriff
Datenbanknamen, Präfix und die Zeilenzahl jeder Tabelle, die es leeren wird, und verlangt die
Eingabe `LOESCHEN`. Aufruf nur über CLI; ein Aufruf über den Webserver bricht ab
(`php_sapi_name() !== 'cli'`), wie es `demo_reset.php` heute schon tut.

**Rückgabe:** Exit 0 bei Erfolg, 1 bei Fehler. Am Ende eine Zeile je befüllter Tabelle mit
Zeilenzahl, damit der Cron-Job etwas zum Protokollieren hat.

## 5. Erzeugter Bestand

Alle Mengen beziehen sich auf zwölf Monate rückwärts und vier Wochen vorwärts vom Stichtag.

### 5.1 Stammdaten

**Einstellungen** (`system_settings`, nur `UPDATE` bestehender Schlüssel):
`organization_name` = „Musikverein Musterhausen", `organization_logo` leer,
`worktime_enabled` = 1, `station_pin_enabled` = 1, `station_pin_min_length` = 4,
Farben auf den Standardwerten, `pagination_limit` = 25.

**Gruppen** (4): Aktive · Jugend · Vorstandschaft · Ehrenmitglieder.

**Mitglieder** (40): häufige deutsche Vor- und Nachnamen aus einer Liste im Skript,
Mitgliedsnummern `M001`–`M040`. Gruppenstärken: 24 Aktive, 8 Jugend, 6 Vorstandschaft,
4 Ehrenmitglieder — die Summe liegt bewusst über 40, weil Mitglieder der Vorstandschaft
zugleich Aktive sind. **3 Mitglieder inaktiv** über
`membership_dates` mit gesetztem `end_date` — damit der Aktiv/Inaktiv-Filter und die
Hervorhebung inaktiver Mitglieder auf einem Bild etwas zeigen. Jedes Mitglied erhält
mindestens einen `membership_dates`-Eintrag.

**PIN** (`members.pin_hash`) für 15 Mitglieder, damit die Station bedienbar ist.

**Terminarten** (4): Gesamtprobe · Registerprobe · Auftritt · Vorstandssitzung, je mit
Farbe und Gruppenbindung über `appointment_type_groups` (Vorstandssitzung nur Vorstandschaft,
Registerprobe nur Aktive und Jugend).

**Tätigkeitsarten** (6), mit unterschiedlichem `verification`-Grad, damit die Spalte
„Nachweis" in den Auswertungen Abstufungen zeigt:

| Tätigkeit | `verification` |
|---|---|
| Bühnenaufbau | `start_end` |
| Festvorbereitung | `start_end` |
| Vereinsheim-Renovierung | `start` |
| Notenarchiv | `none` |
| Instrumentenpflege | `none` |
| Jugendbetreuung | `none` |

**Konten** (`users`): `admin@musterhausen.example`, `manager@…`, `user@…` — letzteres mit einem
Mitglied verknüpft, damit Profil, PWA und Selbstauskunft bespielbar sind. Dazu ein
Kiosk-Gerät **„Probenraum-Station"** (`device_type = kiosk`, Token) und ein TOTP-Gerät
**„Proberaum"**.

### 5.2 Bewegungsdaten

**Termine** (~90): Gesamtprobe wöchentlich freitags 20:00 · Registerprobe vierzehntägig
dienstags 19:30 · Vorstandssitzung monatlich · Auftritte 10 im Jahr, ungleich über die
Monate verteilt. Vier Termine liegen in der Zukunft, damit die Terminliste nicht mit der
Vergangenheit endet.

**Anwesenheiten** (`records`): je Mitglied eine eigene Grundquote zwischen 60 % und 95 %,
gezogen aus dem Saat. `arrival_time` streut um den Terminbeginn (−10 bis +15 Minuten, wenige
Ausreißer bis +40) — sonst zeigt die Pünktlichkeitsstatistik eine Gerade statt einer
Verteilung. `checkin_source` gemischt über `user_totp`, `station_pin`, `auto_checkin` und
`admin`, damit die Quellen-Kennzeichnung in der Liste sichtbar wird; bei `station_pin`
trägt `location_name` den Stationsnamen.

**Anträge** (`exceptions`, ~25): Mischung aus `absence` und `time_correction`, Status verteilt
auf `approved`, `rejected` und **mindestens 4 × `pending`** — sonst ist der Antrags-Tab leer,
und genau der belegt den Freigabe-Ablauf.

**Arbeitszeiten** (`work_sessions`, ~120 über zwölf Monate):

- Status: ~100 `confirmed`, ~15 `submitted`, ~5 `rejected`
- `source` gemischt über `timer`, `manual`, `station`
- rund ein Drittel mit `appointment_id` (Terminbezug), der Rest ohne
- Sitzungen mit `station`-Quelle tragen `start_location_name` / `end_location_name`
  = „Probenraum-Station" — das ist der Ortsnachweis, auf den sich der Bericht stützt
- `break_minutes` gestreut, einzelne Sitzungen über mehrere Stunden
- **genau eine laufende Sitzung** (`end_time` NULL) für das Mitglied, mit dem die PWA
  fotografiert wird. `work_sessions.active_member` ist eindeutig, mehr als eine je Mitglied
  ist ohnehin ausgeschlossen.

**Auditspur** (`work_session_log`): zu jeder Sitzung ein `create`-Eintrag, zu bestätigten ein
`approve`, zu abgelehnten ein `reject`, zu einigen ein `update`. Ohne sie ist die Behauptung
„jede Änderung ist protokolliert" auf der Werbeseite unbelegt.

## 6. Aufbau des Skripts

Eine Datei, `private/demo/seed.php`, gegliedert in Funktionen mit je einer Zuständigkeit:

```
parseOptions()        Kommandozeile → Konfiguration
confirmTarget()       Ziel anzeigen, Bestätigung einholen
truncateAll()         Tabellen in Fremdschlüsselreihenfolge leeren
seedSettings()        system_settings aktualisieren
seedGroups()          Gruppen
seedMembers()         Mitglieder, Gruppenzuordnung, Mitgliedschaftszeiträume, PINs
seedUsers()           Konten und Geräte
seedAppointmentTypes()
seedActivityTypes()
seedAppointments()    Terminserie über den Zeitraum
seedRecords()         Anwesenheiten mit Quote und Streuung
seedExceptions()      Anträge
seedWorkSessions()    Arbeitszeiten samt Auditspur
report()              Zeilenzahlen ausgeben
```

Jede `seed*`-Funktion nimmt PDO, Präfix und den Zufallsgenerator entgegen und gibt zurück,
was nachfolgende Funktionen brauchen (etwa die erzeugten Mitglieds-IDs). Keine globalen
Zustände. Namenslisten und Terminarten stehen als Konstanten am Kopf der Datei, nicht
verstreut im Code.

`declare(strict_types=1)` und der Copyright-Header wie in allen neuen Dateien des Projekts.
Alle Schreibzugriffe über Prepared Statements, Tabellennamen ausschließlich über
`$database->table(...)`.

**Umfang:** geschätzt 500–600 Zeilen. Sollte die Datei darüber hinauswachsen, werden die
Namenslisten nach `private/demo/data.php` ausgelagert.

## 7. Nicht-Ziele

- **Keine Demo-Restriktionen.** Gehört zu ③.
- **Kein Cron-Job, kein Deployment.** Gehört zu ③.
- **Keine Schemaänderung, keine Migration.** Der Generator setzt Schemastand **1.3.1 oder
  neuer** voraus und prüft das über `schema_version`; liegt die Datenbank darunter, bricht er
  mit dem Hinweis auf den Update-Assistenten ab. Ein neuerer Stand wird akzeptiert — das
  Skript wird beim nächsten Versionssprung mitgezogen, nicht die Prüfung aufgeweicht.
- **Keine Anpassung der Testsuiten.** Sie erzeugen ihre Daten weiterhin selbst.

## 8. Risiken

| Risiko | Umgang |
|---|---|
| Skript wird versehentlich gegen eine produktive Datenbank gefahren | Ziel wird vor dem Schreiben genannt, Eingabe `LOESCHEN` verlangt, CLI-Zwang. `--yes` bleibt dem Cron-Job vorbehalten. |
| Testsuiten hinterlassen nach dem Lauf wieder Rückstände | Reihenfolge festhalten: erst `php tests/run.php`, dann `seed.php`, dann fotografieren. In der Anleitung vermerken. |
| Erzeugte Namen treffen zufällig reale Personen | Häufige, unauffällige Namen; keine Kombination aus dem Umfeld des Projekts. Bei Bedarf Saat ändern. |
| Die Demo-Konten stehen im Klartext auf der Werbeseite | Passwort ist Parameter, nicht fest verdrahtet. Die öffentliche Demo bekommt in ③ ohnehin Schreibsperren. |

## 9. Prüfkriterien

Das Vorhaben ist fertig, wenn:

1. `php private/demo/seed.php` zweimal hintereinander ohne Fehler durchläuft und beide Male
   denselben Bestand erzeugt (Zeilenzahlen und Stichproben identisch).
2. Nach dem Lauf **keine** dieser Ansichten leer ist: Mitgliederliste, Terminübersicht,
   Anwesenheitsliste eines vergangenen Termins, Statistik mit Pünktlichkeit,
   Zeiterfassung im Dashboard, Antragsliste, Arbeitszeitbericht als Druckansicht
   (`statistics?include=worktime&format=html`).
3. Die Check-in-PWA lässt sich mit dem Benutzerkonto anmelden und zeigt eine laufende Sitzung.
4. Die Station lässt sich mit dem erzeugten Kiosk-Token in Betrieb nehmen und nimmt einen
   Stempel mit Mitgliedsnummer und PIN an.
5. `php tests/run.php` läuft nach dem Generatorlauf unverändert durch.
6. `private/demo/` steht in `.gitattributes` unter `export-ignore`.

## 10. Einordnung

Erstes von drei Vorhaben, Reihenfolge am 08.09.2026 abgestimmt:

| | Vorhaben | Hängt ab von |
|---|---|---|
| ① | **Demo-Datengenerator** (diese Spec) | — |
| ② | Überarbeitung der Werbeseite `ehrensache_app` | ① (Bildmaterial) |
| ③ | Demo-Installation auf 1.3.1 ziehen | ① (Reset-Daten) |

③ bekommt eine eigene Spec. Bekannt ist bereits: Der demo-eigene Anteil ist klein und gut
abgegrenzt — gegenüber dem Fork-Punkt `7982489` sind es drei Dateien unter `private/demo/`,
rund fünfzehn einzeilige `checkDemoRestrictions()`-Wächter in den Handlern, ein Demo-Banner in
`public/index.html` und Anpassungen in `public/verify_email.php`. Beim Neuauftragen auf 1.3.1
sind die Wächter auf die seit 1.0.0 hinzugekommenen Ressourcen zu erweitern: `work_sessions`,
`station`, `change_pin`, `cleanup`, `import`, `export`.
