# Offene Punkte — EhrenSache

Sammelstelle für Funde, offene Entscheidungen und Restarbeiten. Ergänzt die Spezifikationen
unter `docs/superpowers/specs/`, ersetzt sie nicht: Was hier steht, ist noch nicht entschieden
oder noch nicht gebaut.

**Zuletzt geprüft:** 2026-09-10 · **Bezugsstand:** `dev`, mit `main` gleichgezogen, drei
Korrekturen und der unveröffentlichte Demo-Modus darüber · **Version:** 1.4.1

> **Diese Datei ist öffentlich.** Sie liegt seit 2026-09-02 im Repository (siehe
> [OI-14](#oi-14)). Was hier steht, kann jeder lesen — die Grenze für sicherheitsrelevante
> Einträge regelt der Abschnitt [Sicherheit](#sicherheit) und `SECURITY.md`.

**Priorität:** *hoch* = blockiert einen Merge nach `main` oder den produktiven Einsatz ·
*mittel* = sollte vor der Freigabe an Vereine gelöst sein · *niedrig* = Verbesserung

---

## Zu klären

### OI-1 · Verlorener AUTO_INCREMENT nach Crash-Recovery
**Erledigt am 2026-09-09** — mit 1.4.0. Selbstheilung gebaut, Ursache entfernt.
Beobachtung läuft weiter: Ob die Umstellung wirklich hilft, zeigt erst der nächste Absturz.

`ez_work_sessions` verliert nach einer InnoDB-**Crash-Recovery** seinen AUTO_INCREMENT-Zähler.
Er liest sich dann als `0`, und jeder `INSERT` scheitert mit
`SQLSTATE[HY000] 1467 Failed to read auto-increment value from storage engine`. Die
Zeiterfassung steht damit vollständig still — Timer-Start und Nachtrag antworten mit HTTP 500.

**Zweimal beobachtet:** 2026-09-02 (bei `MAX(session_id) = 1176`) und 2026-09-09 (bei 6200,
33 von 395 Tests rot). Beide Male war **keine andere Tabelle** betroffen.

#### Ursache — seit 2026-09-09 belegt, nicht mehr vermutet

Das MariaDB-Fehlerprotokoll (`C:\xampp\mysql\data\mysql_error.log`) nennt sie ausdrücklich:

```
Assertion failure in file …\os0file.cc line 6132        ← Absturz
…
[Note] InnoDB: Starting crash recovery from checkpoint LSN=20592797
…
[Note] InnoDB: AUTOINC next value generation is disabled
                for '`ehrensache`.`ez_work_sessions`'
```

InnoDB **schaltet** die Zählergenerierung für diese eine Tabelle ab, weil es die
AUTOINC-Spalte im eigenen Datenwörterbuch nicht mehr findet — Wörterbuch und
Tabellendefinition sind auseinandergelaufen. `ez_work_sessions` ist die einzige Tabelle des
Schemas mit einer **indizierten virtuellen Spalte** (`active_member`, `GENERATED ALWAYS AS
(if(end_time is null, member_id, NULL)) VIRTUAL`, dazu ein Unique-Index). Virtuelle Spalten
werden nicht gespeichert und verschieben damit die Spaltenzuordnung zwischen der Definition
und den tatsächlich abgelegten Spalten.

**Der Auslöser ist die Crash-Recovery, nicht der Neustart.** Das erklärt, warum ein sauberer
Neustart am 2026-09-02 nichts reproduzierte und `FLUSH TABLES` es ebenfalls nicht auslöst:
Der Zähler übersteht das Neuöffnen einer Tabelle, verloren geht er beim Wiederaufbau des
Wörterbuchs nach einer Recovery.

Ausgeschlossen wurde: `innodb_force_recovery` ist `0` und steht in keiner `my.ini` —
der Verdacht, ein erzwungener Wiederherstellungsmodus schalte AUTOINC ab, trifft nicht zu.

Am 2026-09-09 ging dem Absturz voraus, dass MariaDB überhaupt nicht startete (Verdacht
Virenscanner); erst ein Rechnerneustart und XAMPP mit Administratorrechten brachten den
Dienst hoch. Auf dem Webspace eines Vereins entsprechen dem Stromausfall, OOM-Kill oder eine
erzwungene Wartung des Hosters — der Fall ist also nicht auf die Entwicklungsumgebung
beschränkt.

#### Was gebaut wurde (2026-09-09)

Die Anwendung heilt den Zustand selbst. In `private/helpers/worktime.php`:

- `worktimeIsLostAutoinc(PDOException)` — erkennt genau Fehler 1467
- `worktimeRepairAutoinc(PDO, table, idColumn)` — setzt den Zähler auf `MAX + 1`, prüft
  Tabellen- und Spaltenname gegen `/^[A-Za-z0-9_]+$/` (ALTER TABLE erlaubt keine
  Platzhalter) und **protokolliert den Eingriff laut** über `error_log()`
- `worktimeWithAutoincRepair(PDO, database, callable)` — fängt 1467, rollt eine offene
  Transaktion zurück (`ALTER TABLE` ist DDL und würde sie sonst unbemerkt festschreiben),
  repariert und wiederholt den Schreibvorgang **genau einmal**

Eingehängt an beiden Einfügestellen in `private/handlers/work_sessions.php`: dem Timer-Start
(transaktional) und dem Nachtrag.

Der Einwand aus der früheren Fassung — *ein stiller Reparaturpfad erschwert künftige
Diagnosen* — ist durch die Protokollierung beantwortet: Die Meldung nennt Tabelle, gesetzten
Wert, die Ursache außerhalb der Anwendung und verweist auf diesen Eintrag.

**Nicht End-to-End belegt:** Der Zustand „InnoDB hat AUTOINC abgeschaltet" lässt sich nicht
auf Kommando herstellen — `ALTER TABLE … AUTO_INCREMENT = 1` hebt MariaDB selbsttätig wieder
auf `MAX + 1` an. Geprüft sind die Erkennung (Unit) und die Reparatur gegen eine
Wegwerftabelle (`tests/db/verify_autoinc_repair.php`); der Wiederholungspfad läuft in keinem
Test durch. Tritt der Fehler erneut auf, ist die Log-Meldung der erste verlässliche Beleg,
dass er greift.

#### Umgestellt mit 1.4.0

`active_member` ist seit dem 2026-09-09 **`STORED`** statt `VIRTUAL`. Migration
`private/migrations/1.3.1.php`, Basisschema nachgezogen. Gespeicherte Spalten liegen im
Zeilenformat und verschieben die Zuordnung zwischen Definition und abgelegten Spalten nicht —
genau die Verschiebung, die als Ursache vermutet wird.

Gegen die Testdatenbank belegt: 120 Sitzungen unverändert, die laufende behält ihren Eintrag,
der Unique-Index weist eine zweite laufende Sitzung weiterhin ab (`Non_unique: 0`, Duplicate
entry beim Test-Insert), ein zweiter Lauf der Migration erkennt sich als erledigt.

**Die Selbstheilung bleibt.** Sie behandelt die Folge, die Umstellung nimmt dem Fehler die
*vermutete* Ursache — belegt ist der Zusammenhang nicht, und das lässt sich auch nicht
belegen, solange der Zustand nicht auf Kommando herbeizuführen ist. Tritt der Fehler erneut
auf, steht die Meldung der Selbstheilung im Server-Log; dann war die Umstellung nicht die
Lösung und dieser Eintrag ist wieder zu öffnen.

Die frühere Abwägung, festgehalten für den Fall, dass er wieder aufgemacht werden muss:

- *Dafür:* Der Auslöser verschwindet, statt abgefangen zu werden. Der Unique-Index und die
  Garantie „höchstens eine laufende Sitzung je Mitglied" bleiben unverändert.
- *Dagegen:* Braucht eine Migration, kostet vier Byte je Zeile — und ob es wirklich hilft,
  ist unbewiesen, solange sich der Fehler nicht gezielt herbeiführen lässt. Bei sehr vielen
  Sitzungen schreibt der Umbau die Tabelle neu; der Update-Assistent weist ab 50 000 Zeilen
  darauf hin.

**Verhalten geprüft (2026-09-09), gegen Wegwerftabellen in der Testdatenbank:** `STORED`
bildet die Regel unverändert ab. Mehrere beendete Sitzungen desselben Mitglieds gehen durch
(der Ausdruck liefert `NULL`, und davon erlaubt ein Unique-Index beliebig viele), eine
laufende geht durch, die zweite scheitert mit `Duplicate entry '7' for key 'uq_active'`.

**Der direkte Weg ist nicht möglich.** MariaDB 10.4 lehnt die Umstellung einer generierten
Spalte von `VIRTUAL` auf `STORED` ab:

```
ALTER TABLE … MODIFY active_member int(11) GENERATED ALWAYS AS (…) STORED;
→ ERROR 1907 (HY000): This is not yet supported for generated columns
```

**Was stattdessen geht** — geprüft, die Werte werden dabei korrekt neu berechnet und der
Unique-Index greift danach unverändert:

```sql
ALTER TABLE {PREFIX}work_sessions DROP INDEX `{PREFIX}uq_running_session`;
ALTER TABLE {PREFIX}work_sessions DROP COLUMN active_member;
ALTER TABLE {PREFIX}work_sessions
  ADD COLUMN active_member int(11) GENERATED ALWAYS AS
      (if(end_time is null, member_id, NULL)) STORED,
  ADD UNIQUE KEY `{PREFIX}uq_running_session` (active_member);
```

Das Löschen der Spalte ist dabei ungefährlich: Ihr Wert folgt vollständig aus `end_time` und
`member_id` und entsteht beim Neuanlegen aus den vorhandenen Zeilen neu. Das unterscheidet
eine generierte Spalte von einer gewöhnlichen, bei der ein `DROP COLUMN` Daten vernichtet.

So umgesetzt in `private/migrations/1.3.1.php`, ergänzt um zwei Sicherungen: Der Schritt
bricht ab, wenn es Mitglieder mit mehr als einer laufenden Sitzung gibt — der Unique-Index
ließe sich danach nicht wieder anlegen und die Tabelle bliebe ohne Spalte zurück —, und er
erkennt eine bereits gespeicherte Spalte als erledigt.

Ein früher angelegter Kontrollaufbau (`zz_virt` / `zz_plain` in der Testdatenbank) wurde am
2026-09-09 wieder entfernt: Er hätte nur nach einem echten Absturz etwas gezeigt, nicht nach
einem gewöhnlichen Neustart.

---

### OI-2 · Löschfrist für die Änderungshistorie
**Erledigt am 2026-09-04** — mit 1.2.5

`cleanup` kennt jetzt drei Fristen statt einer: Anwesenheiten und Ausnahmen, Arbeitszeiten samt
ihrer Änderungshistorie, und verwaiste Einträge der Historie. Alles läuft in einer Transaktion.

**Entschieden:** Die Historie einer bestehenden Sitzung wird nicht gesondert gelöscht — sie
folgt der Frist ihrer Sitzung, wie `DATENSCHUTZ.md` 10.4 es vorgibt. Verwaiste Einträge
bekommen eine eigene, kürzere Frist und werden dann **anonymisiert statt gelöscht**: `changes`
und `changed_by` fallen weg, die Zeile bleibt. Die Auditspur soll weiterhin belegen, dass an
dieser Stelle etwas geschah — ein vollständiges Löschen nähme ihr genau den Zweck.

Laufende Sitzungen (`end_time IS NULL`) fallen nie in die Frist. Eine seit Jahren offene
Sitzung ist ein Fehlerfall, kein Löschfall.

**Mit erledigt:** Die Fristen wurden serverseitig geprüft. Bis dahin nahm der Endpunkt jeden
Wert an — `years: 0` ergab den heutigen Tag als Stichtag und löschte damit den gesamten
Bestand. Das `min="1"` stand nur im Formular. Genau das ist am 2026-09-04 bei einem Testlauf
gegen die Entwicklungsdatenbank passiert — 1379 Anwesenheiten und 3 Ausnahmen waren weg, ohne
Sicherung und ohne Binlog nicht wiederherstellbar. Die Prüfung sitzt jetzt in
`retentionYears()` und greift vor jedem `DELETE`.

**Offen geblieben:** [OI-22](#oi-22) — verwaiste Einträge erscheinen bis zum Ablauf ihrer Frist
in keiner Selbstauskunft.

**Restrisiko, bewusst so:** Die Bereinigung läuft nicht von selbst; ein Admin stößt sie an.
Ein Cronlauf hätte keinen Ort im Projekt — es gibt keinen Scheduler, und ein unbeaufsichtigt
löschender Job ohne Sicherung ist die schlechtere Variante.

---

### OI-3 · Vier-Augen-Prinzip bei Manager-Nachträgen
**Priorität:** mittel — bewusst so entschieden, Folge dokumentieren

Seit `0097bd2` gilt ein von Manager oder Admin angelegter Nachtrag sofort als `confirmed`
(`source = 'admin'`). Begründung: Sie sind die freigebende Instanz und müssten sich sonst selbst
genehmigen — dieselbe Regel wie bei ihren Änderungen.

**Folge:** Trägt ein Manager sich selbst Stunden ein, gelten sie ohne jede Kontrolle. Bei einem
Verwendungsnachweis ist das die Stelle, an die ein Prüfer zuerst schaut.

**Offene Variante:** Nur Nachträge für **fremde** Mitglieder sofort bestätigen, eigene in die
Freigabe geben. Preis: In einem Verein mit nur einem Manager bleibt dessen Eintrag hängen, bis
ein Admin ihn freigibt.

---

### OI-20 · Auto-Termine zählen weiter in die Statistik
**Priorität:** mittel — bewusst so entschieden am 2026-09-03

Seit 1.2.4 ist die automatische Terminerzeugung abschaltbar und ein erzeugter Termin trägt
`is_auto_created = 1`. **Die Auswertung kennt die Markierung nicht.** Ein Auto-Termin zählt
bei jedem Mitglied der zugehörigen Gruppen als Solltermin; wer nicht eingecheckt hat,
erscheint als unentschuldigt abwesend.

Entschieden wurde: erst sichtbar machen, dann sehen, ob es reicht. Der Filter in der
Terminverwaltung zeigt den Bestand.

**Offene Variante:** Ein Auto-Termin zählt erst, wenn Admin oder Manager ihn bestätigt hat.
Preis: Eingriffe in `statistics.php` und den Bericht, plus ein Bestätigungsschritt in der
Oberfläche.

**Nächster Schritt:** Nach einem Halbjahr Betrieb prüfen, wie viele Auto-Termine anfallen und
wie viele davon nachbearbeitet wurden.

---

### OI-21 · `checkin_tolerance_hours` kennt nur ganze Stunden
**Priorität:** niedrig

Das Zuordnungsfenster ist durchgehend ganzzahlig. Das Eingabefeld ist
`<input type="number" min="0" max="8" step="1">` ([public/index.html](../public/index.html)),
und jeder Leser schneidet Nachkommastellen ab: `parseInt(…, 10)` in der PWA
([app.js](../public/checkin/js/app.js), zwei Stellen — seit `7f4d445` mit derselben
NaN-geprüften Regel, vorher fiel die Anwesenheitsliste bei `'0'` still auf 2 h zurück),
`(int)` in `checkinToleranceHours()` ([utils.php:145](../private/helpers/utils.php),
Rückgabetyp `int`), `intval()` in [auto_checkin.php:123](../private/handlers/auto_checkin.php).
`saveAllSettings()` ([settings.js](../public/js/modules/settings.js)) prüft mit `parseInt`,
speichert danach aber den Rohstring — wer `0,5` einträgt, bekommt in der Datenbank `"0,5"`
und überall sonst `0`.

**Folge:** Der kleinste Nicht-Standardwert ist `0` = sekundengenauer Treffer (bewusst erlaubt,
Range-Check ist nur `< 0 || > 8`, und `tests/suites/checkin_appointment.php` deckt `'0'`
explizit ab). Praktisch ist das unbrauchbar — die nächste Stufe darüber ist `1` Stunde.
Ein Verein, der das Fenster enger als die Vorgabe `2` will, aber nicht auf Sekunden, hat
keine Option. 30 Minuten sind nicht darstellbar.

**Zu entscheiden:** Feld auf **Minuten** umstellen (`step="15"`, Werte 0/15/30/60/120) —
vermeidet Fließkomma und ist verständlicher — oder Bruchstunden mit `parseFloat`/`floatval`
durch die ganze Kette ziehen.

**Preis:** ~5 Codestellen (2× `app.js`, `settings.js`, `utils.php`, `auto_checkin.php`),
Migration (Bestandswert steht in Stunden), `tests/suites/checkin_appointment.php`, und Doku
(`API.md`, `docs/testplan.md`, Spec). Die Dublettenprüfung beim Terminanlegen
([appointments.php](../private/handlers/appointments.php)) rechnet noch mit der Konstante
`AUTO_CHECKIN_TOLERANCE_HOURS` statt der Einstellung — beim Umbau mitnehmen oder bewusst
trennen.

**Nächster Schritt:** Bedarf abwarten. Meldet ein Verein, dass `1` h zu grob ist, das
Minuten-Modell in einer eigenen Spec umsetzen.

---

### OI-27 · `members.active` vs. `membership_dates` am Kiosk
**Priorität:** mittel

`stationAuthenticate()` prüft für den Stempel nur `active = 1` auf `members`. Die Statistik
und die übrige Anwesenheitslogik werten dagegen `getMemberActivityWhere()` gegen
`membership_dates` aus — ein Mitglied kann `active = 1` sein, aber außerhalb seines aktiven
Zeitraums liegen (Austritt zu einem künftigen Datum, Karenzzeit, Datenpflegefehler). Ein
solches Mitglied kann am Kiosk weiterhin stempeln, obwohl es für den fraglichen Zeitraum in
keiner Auswertung als aktiv zählt.

**Zu entscheiden:** `stationAuthenticate()` auf dieselbe Aktivitätsprüfung wie die Statistik
umstellen, oder bewusst bei `active` belassen, weil ein Kiosk-Stempel ohnehin nur eine
Anwesenheit erzeugt und die nachgelagerte Auswertung über `membership_dates` filtert.

---

### OI-32 · Wake Lock / Kiosk-Modus
**Priorität:** niedrig

Die Station kann den Browser nicht gegen Bildschirmsperre oder Verlassen der Seite
verriegeln — dokumentiert in `public/station/README.md`. Ohne Wake Lock oder echten
Kiosk-Modus des Tablets kann der Bildschirm während des Betriebs einschlafen oder jemand
navigiert versehentlich weg.

**Zu entscheiden:** Ob die Web-App eine Wake-Lock-API anfordert (nicht auf allen Browsern
verfügbar) oder ob das Vereinssache bleibt (Tablet-eigene Kiosk-App, Geräteverwaltung durch
MDM). Eine native Kiosk-App ist ausdrücklich außerhalb des Projektumfangs.

### OI-37 · Ortsnachweis überlebt jede Zeitkorrektur
**Priorität:** erledigt am 2026-09-08 — `workSessionUpdate()` nullt den Ortsnachweis der geänderten Zeit, Regel in `worktimeProofDrop()` (`private/helpers/worktime.php`), Tests in `tests/suites/worktime_unit.php` und `tests/db/verify_proof_drop.php`

**Nachtrag vom 2026-09-08.** Beim Nachmessen fiel eine Asymmetrie in der Leiter des
Nachweisgrades auf: Es gab eine Stufe für „nur der Start ist belegt", aber keine für „nur das
Ende ist belegt" — letztere fiel bis „unbelegt" durch. Eine voll belegte Sitzung mit
verschobenem Beginn galt damit als gänzlich unbelegt, obwohl ihr Ende weiter belegt war.
Tragbar war das, solange Ortsnachweise nur vom Timer kamen (wer stoppt, hat gestartet); mit
diesem Punkt wurde der Fall alltäglich. `worktimeProofExpression()` prüft die mittlere Stufe
jetzt mit ODER statt nur auf den Start — „teilbelegt" heißt „genau eine der beiden Grenzen ist
belegt", gleich welche. Der Wert heißt weiterhin `start`, weil er über `by_proof` und
`start_proven` bis in `API.md` und in die Auswertungen der Vereine reist.

`workSessionUpdate()` schreibt `start_time` und `end_time` neu, lässt `start_location_name`
und `end_location_name` dabei aber unberührt
([work_sessions.php:770](../private/handlers/work_sessions.php)). Der Nachweisgrad wird aus
genau diesen beiden Feldern abgeleitet — im Dashboard clientseitig (`proofOf()` in
[worktime.js:73](../public/js/modules/worktime.js)), in Statistik und Export serverseitig über
`worktimeProofExpression()`. Er hängt damit daran, dass **irgendwann** ein Ortsnachweis vorlag,
nicht an dem Zeitraum, für den er gelten soll.

**Folge:** Wer um 10:00 mit TOTP startet, um 11:00 mit TOTP stoppt und den Start danach auf
06:00 zieht, hat fünf Stunden mit dem Etikett „stundenbelegt“ — nachgewiesen ist davon eine.
Der Eintrag fällt zwar auf `submitted` zurück und braucht eine Freigabe, aber in der
Freigabeliste steht dasselbe grüne „stundenbelegt“ wie bei einer sauber gestempelten Sitzung.
Was geändert wurde, hält `work_session_log` fest — angezeigt wird es nirgends außer in der
Selbstauskunft (`my_data`). Auch `source` bleibt auf `timer`, obwohl die Zeiten von Hand
stammen (verwandt: [OI-33](#oi-33)).

Der Weg steht heute jedem Mitglied offen: „✎ Bearbeiten“ an der eigenen abgeschlossenen
Sitzung im Dashboard. Mit [OI-35](#oi-35) käme er zusätzlich in die PWA — deshalb vorher
klären. Absicht war er nicht: Der Kommentar an der Stelle begründet den Statuswechsel, den
Ortsnachweis erwähnt er nicht.

**Vorschlag:** Den betroffenen Nachweis mit der Zeit fallen lassen — `start_time` geändert →
`start_location_name = NULL`, `end_time` geändert → `end_location_name = NULL`. Der
Nachweisgrad rutscht damit auf `start` bzw. `none`. Ein Ortsnachweis gilt für den gestempelten
Zeitpunkt, nicht für den behaupteten; das ist die einzige Lesart, die vor einem Fördergeber
hält.

**Zu entscheiden:**

- **Gilt das auch für Manager und Admin?** Fachlich ja — die Verschiebung macht den Nachweis
  sachlich falsch, gleich wer sie vornimmt. Es nimmt der freigebenden Instanz allerdings die
  Möglichkeit, einen offensichtlichen Vertipper zu heilen, ohne den Nachweis zu opfern.
- **Toleranz für Minutenkorrekturen?** Hier ohne Empfehlung dafür: Eine Schwelle erzeugt einen
  Sonderfall, den später niemand mehr erklären kann.
- **Oder statt dessen:** Geänderte Sitzungen in der Freigabeliste kennzeichnen und das
  `work_session_log` dort anzeigen. Ehrlicher gegenüber dem Manager, aber deutlich mehr Arbeit
  — und das Etikett bliebe trotzdem falsch. Beides zusammen wäre das Vollbild.
- **Bestand.** Ob bereits korrigierte Sitzungen nachträglich herabgestuft werden, ist offen.
  Ermitteln ließen sie sich über `work_session_log` (Änderungssätze mit `start_time` oder
  `end_time`), solange die Auditspur nicht schon gelöscht ist — siehe [OI-2](#oi-2).

**Berührt:** `private/handlers/work_sessions.php` (`workSessionUpdate()`),
`tests/suites/worktime_api.php`. Frontend unberührt: Der Nachweisgrad wird überall aus den
Ortsfeldern abgeleitet, hier wie dort.

### OI-39 · Freigaben liegen an zwei Orten
**Priorität:** niedrig — offen, aufgekommen bei den PWA-Korrekturen am 2026-09-08

Ein Manager, der wissen will, was seine Entscheidung braucht, muss zwei Bereiche öffnen:

| Bereich | Inhalt | Zähler |
|---|---|---|
| „📋 Anträge" | Entschuldigungen und Zeitkorrekturen aus `exceptions` | „Ausstehende Anträge" |
| „Zeiterfassung" | Arbeitszeitsitzungen in `submitted`, Freigabe über ✓/✗ in der Tabellenzeile | keiner |

Beide sind Freigaben desselben Zuschnitts — jemand behauptet etwas, ein Manager entscheidet
darüber. Sie heißen nur verschieden, liegen an verschiedenen Stellen, und nur eine der beiden
hat einen Zähler. Wer ausschließlich in die Anträge schaut, übersieht wartende Stunden
vollständig.

**Der Aufwand ist geringer, als er wirkt.** Für eine gemeinsame Ansicht braucht es **keine**
Schemaänderung: `exceptions` und `work_sessions` tragen beide einen Status und kennen beide
den Weg „freigeben / ablehnen". Die Check-in-PWA führt es bereits vor — ihr Verlauf mischt
`records`, `exceptions` und `work_sessions` in einer Zeitachse und unterscheidet sie über
Symbol und Beschriftung (`loadHistory()` in `public/checkin/js/app.js`). Dasselbe ließe sich
für eine Freigabeliste tun.

**Zwei Stufen, die man nicht verwechseln sollte:**

- **Gemeinsame Ansicht** — eine Liste, die beide Quellen liest und je Zeile in die zuständige
  Ressource schreibt. Frontend und höchstens ein zusammenfassender Lesezugriff. Kein
  Datenmodell, keine Migration.
- **Gemeinsame Entität** — ein Antragsdatensatz, auf den beides zurückgeht. Das wäre die
  aufwendige Variante: Migration, Umbau beider Handler, Auswirkungen auf Export, Statistik und
  Auditspur. Für den Zweck („ich will an einer Stelle sehen, was offen ist") ist sie nicht
  nötig.

**Vor der Umsetzung zu klären:**

- **Was heißt „Antrag" dann?** Heute steht das Wort im Dashboard ausschließlich für
  `exceptions`. Nimmt die Liste Arbeitszeiten auf, ändert sich die Bedeutung des Bereichsnamens
  und die des Zählers — beides muss dann überall mitgezogen werden.
- **Getrennte Wortwelten.** Die Anträge sprechen von „genehmigt", die Arbeitszeit von
  „bestätigt"; die Statuswerte heißen `approved` beziehungsweise `confirmed`. Eine gemeinsame
  Liste braucht eine gemeinsame Sprache, ohne die Werte in der Datenbank anzufassen.
- **Nebenwirkung der Freigabe.** Eine genehmigte Zeitkorrektur schreibt in `records` zurück,
  eine freigegebene Arbeitszeitsitzung nicht. Die Liste darf nicht suggerieren, beide täten
  dasselbe.
- **Verhältnis zu [OI-38](#oi-38).** Wird die Auditspur in der Freigabe sichtbar, gehört sie in
  dieselbe Ansicht. Beide Punkte betreffen denselben Arbeitsplatz und sollten zusammen gedacht
  werden.

**Berührt:** `public/index.html`, `public/js/modules/exceptions.js`,
`public/js/modules/worktime.js`, gegebenenfalls `private/handlers/` für einen
zusammenfassenden Lesezugriff. Kein Schemabedarf für die erste Stufe.

### OI-42 · Neun Versionen haben den Release-Branch nie erreicht
**Priorität:** erledigt am 2026-09-09 — `main` steht auf **1.4.0** (`fcf0d2e`), die neun Tags liegen auf `origin`, das Release ist angelegt. Der Rückstand von neun Versionen ist damit aufgeholt; `SECURITY.md` nennt wieder eine Version, die es wirklich gibt.

`main` steht auf **1.1.3** (Build 2026-04-15), `dev` auf **1.3.1**. Dazwischen liegen
**230 Commits**, und der CHANGELOG führt neun Versionen als veröffentlicht, die es auf dem
Release-Branch nie gab: 1.2.0 bis 1.3.1, alle datiert zwischen dem 1. und 7. September 2026.
Tags gibt es nur bis `v1.1.3`.

**Was das für einen Verein bedeutet:**

- `origin/HEAD` zeigt auf `main`. Wer der Anleitung in `README.md` folgt und das Repository
  klont — oder das ZIP von GitHub lädt —, bekommt **1.1.3**. Alles, was seit September
  dokumentiert ist (Arbeitszeiterfassung, virtuelle Station, Kiosk, die gesamte PWA-Arbeit),
  ist für eine Neuinstallation nicht erreichbar.
- `SECURITY.md` sagt zu: Sicherheitsupdates für **1.2.x**, und 1.1.x-Nutzer sollen „über den
  Update-Wizard auf 1.2.x aktualisieren". Beides geht nicht: 1.2.x ist nirgends zu beziehen,
  und die einzige beziehbare Version — 1.1.3 — ist dort als nicht unterstützt geführt. 1.3.x
  kommt in der Tabelle gar nicht vor.
- Die Migrationskette in `private/migrations/manifest.php` ist vollständig (1.0.0 → 1.1.3 →
  … → 1.3.1), aber das hilft niemandem, der bei 1.1.3 steht: Eine Installation führt die
  Kette aus, die in **ihrem eigenen** Code liegt, und 1.1.3 kennt nur den Schritt bis 1.1.3.
  Die Kette wirkt erst, nachdem der neue Code angekommen ist.

**Die gute Nachricht:** `main` ist ein reiner Vorfahr von `dev` — 230 Commits voraus, **null**
zurück. Ein Merge wäre ein Fast-Forward, es gibt nichts aufzulösen.

**Vor dem Nachholen zu entscheiden:**

**Entschieden am 2026-09-09:**

- **Die Zwischenstände werden nachträglich getaggt.** `v1.2.0` bis `v1.3.1` liegen als
  Commits vor; die Tags kommen auf die jeweiligen Commits, damit CHANGELOG und Tags sich
  decken. Eine **Rückdatierung ist nicht nötig** — der zeitliche Verlauf ergibt sich aus der
  Historie des `dev`-Branches.
- **`SECURITY.md` ist richtiggestellt** (2026-09-09). Die Zusage gilt jetzt der jeweils
  neuesten veröffentlichten Version statt einer Versionsnummer, die es auf `main` nie gab;
  der Abstand zu `dev` ist dort benannt.

**Offen:**

- **Wann?** Der nächste Versionssprung ist der natürliche Moment. Bis dahin gilt: Wer die
  Anwendung heute installiert, bekommt eine ein halbes Jahr alte Fassung.
- **Vorbereitung des Releases** — Stand 2026-09-09:

  **Erledigt auf `dev`:**
  1. `version.json` steht auf **1.4.0**, Build `2026-09-09`.
  2. Die `?v=`-Querys in den HTML-Dateien stehen geschlossen auf `1.4.0`; die Suite `assets`
     wacht darüber.
  3. Der `CHANGELOG.md` führt den Block als `[1.4.0] – 2026-09-09`.
  4. Die Migrationskette reicht durchgängig bis `1.4.0`. Der Schritt `1.3.1 → 1.4.0`
     (`private/migrations/1.3.1.php`) stellt `work_sessions.active_member` von `VIRTUAL` auf
     `STORED` um und behebt damit [OI-1](#oi-1). **Er ist Pflicht:** Eine Installation, die
     ihn überspringt, verliert bei einer InnoDB-Crash-Recovery weiterhin den
     AUTO_INCREMENT-Zähler.
  5. **Die Kette ist von Hand belegt**, am 2026-09-09 auf einer echten Installation über den
     Assistenten:

     | Ausgangsstand | Bezug | Ergebnis |
     |---|---|---|
     | 1.1.3 | ZIP von `main` | durchgelaufen |
     | 1.0.0 | ZIP von `v1.0.0` | **erst gescheitert**, nach der Korrektur durchgelaufen |

     Der Fehlschlag war echt: `detectDbVersion()` erkannte eine 1.0.0-Installation nie, weil
     der Assistent den Präfix aus der Konfiguration übergibt und eine 1.0.0-`config.php` das
     Feld `$prefix` nicht kennt. Die Bedingung lautete dann
     `tableExists('users') && !tableExists('users')` und hob sich selbst auf. Behoben in
     `af29e63`.

     **Beide automatisierten Skripte waren dabei grün** — sie geben der Erkennung einen
     Präfix mit, den eine echte 1.0.0-Installation nicht haben kann. Der Fall aus der
     Wirklichkeit steht jetzt als `UPD-5a` in `tests/db/verify_migration_chain.php`; die
     Schema-Konvergenz prüft `tests/db/verify_schema_convergence.php` (46 Prüfungen).

     Die Lehre für künftige Releases: Ein manueller Lauf aus einem **echten alten Paket**
     ersetzt keine Tests, findet aber, was Tests mit selbstgebauten Ausgangslagen nicht
     sehen können.

  **Beim Release ausgeführt (2026-09-09):**
  6. Fast-Forward auf `main` — `fcf0d2e`, 230 Commits, konfliktfrei.
  7. Tags gesetzt, annotiert, und auf `origin` gepusht. Die Zuordnung ist am 2026-09-09 entschieden und geprüft — an jedem Ziel
     nennt `version.json` die passende Version, und alle sind Vorfahren von `dev`:

     | Tag | Commit | | Tag | Commit |
     |---|---|---|---|---|
     | `v1.2.0` | `c68bbad` | | `v1.2.4` | `70848dd` |
     | `v1.2.1` | `6520ae7` | | `v1.2.5` | `14ef156` |
     | `v1.2.2` | `5034860` | | `v1.3.0` | `79e8796` |
     | `v1.2.3` | `72090c0` | | `v1.3.1` | `a849ca7` |

     `v1.4.0` zeigt auf `fcf0d2e`. Für die acht nachgeholten Stände wurde bewusst **kein**
     GitHub-Release angelegt: Sie waren nie beziehbar, und leere Einträge verwässerten die
     Release-Seite. Als Tags bleiben sie auffindbar.

  9. Das Release zu `v1.4.0` ist angelegt und als *Latest* markiert. Die Notizen sind der
     `[1.4.0]`-Block aus dem `CHANGELOG.md`, davor die Update-Anleitung und ein Hinweis auf
     die beiden Änderungen, die ohne Zutun wirken: die HTTPS-Umleitung und der veränderte
     Nachweisgrad bestehender Arbeitszeiten. Das ZIP stammt von GitHub selbst — nur das
     beachtet die `export-ignore`-Regeln aus `.gitattributes`.

     **Warum nicht durchgängig dieselbe Regel?** Für `v1.2.0` bis `v1.3.0` markiert der Tag
     den **letzten** Commit, der diese Version in `version.json` trug — sonst fehlten bis zu
     42 Commits Arbeit, weil der Sprung jeweils am Anfang der Arbeit an einer Version steht,
     nicht am Ende. Bei `1.3.1` führt dieselbe Regel in die Irre: Zwischen dem Sprung (`a849ca7`,
     07.09.) und dem auf 1.4.0 liegen **86 Commits** — der Demo-Datengenerator, die PWA-Arbeit,
     OI-1 —, die der `CHANGELOG.md` bereits 1.4.0 zurechnet. Dort markiert der Tag deshalb den
     Punkt, an dem 1.3.1 ausgerufen wurde. Da diese Zwischenstände nie ausgeliefert wurden, hat
     niemand je einen davon in der Hand gehabt; die Tags sind historische Marken, keine
     Auslieferungspunkte.

     Gesetzt werden sie **annotiert** (`git tag -a`): Ihr eigenes Datum weist sie als
     nachträglich gesetzt aus, statt ein Auslieferungsdatum vorzutäuschen.

     **Nebenbefund:** Der `CHANGELOG.md` datiert 1.3.0 auf den 07.09., der Sprung war am 04.09.
     Vermutlich wurde das Datum beim Schreiben des Eintrags gesetzt. Nicht korrigiert — die
     Angabe ist harmlos, und eine nachträgliche Änderung veröffentlichter Daten wäre die
     schlechtere Wahl.
  8. ~~In `SECURITY.md` die Zeile „Aktuell veröffentlicht" ziehen~~ — **erledigt auf `dev`**
     (2026-09-09). Sie nennt bereits 1.4.0, und der Absatz zum Abstand von `dev` ist auf eine
     dauerhafte Formulierung umgestellt, die nach jedem Release stimmt. Das musste **vor** dem
     Fast-Forward geschehen: Er überträgt genau den Stand von `dev`, eine spätere Korrektur
     bräuchte einen zweiten Durchlauf. Bis dahin trägt `main` weiterhin die für `main`
     richtige Angabe 1.1.3.

  **Kein Handlungsbedarf besteht bei:** Auslieferungsumfang (`.gitattributes` nimmt `docs/`,
  `CLAUDE.md`, `test_credentials.md`, `temporary_screenshots/` und `private/demo/` aus dem
  ZIP) und Geheimnissen im Index (`config.php`, `tests/config.php` und `test_credentials.md`
  sind über `.gitignore` draußen; der Demo-Seeder, der die Tabelle `users` leert, liegt unter
  `private/` und verweigert den Dienst außerhalb der CLI).

**Berührt:** Branch `main`, Tags, `version.json`, `CHANGELOG.md`, `SECURITY.md`, `README.md`
(Bezugsweg). Kein Code.

---

### OI-43 · Offline-Betrieb der Check-in-PWA
**Priorität:** niedrig — heute bewusst ohne, aber nie entschieden

Die Check-in-PWA hat **keinen Cache**. Ihr Service Worker reicht jede Anfrage ans Netz durch;
er dient allein der Installierbarkeit auf dem Startbildschirm. Ohne Verbindung zeigt die App
also nichts.

**Wie es dazu kam.** Eine Zwischenspeicherung war gebaut und wurde am 2025-12-08 mit `ffe4690`
stillgelegt — demselben Umbau, der die Web-Root auf `public/` legte. Danach zeigten die
absoluten Pfade der Vorabladeliste (`/index.html`, `/css/style.css`, `/js/app.js`) nicht mehr
auf die PWA, sondern auf das Dashboard, und `/manifest.json` gab es dort gar nicht. Ein
`cache.add()` auf eine 404 scheitert; der Folgecommit desselben Tages heißt „Fixed service
worker" und behebt es durch Abschalten.

Der abgeschaltete Code stand danach neun Monate auskommentiert in der Datei, zusammen mit
`CACHE_NAME` und `urlsToCache` — zwei Konstanten, die nach Bedeutung aussahen und keine
hatten. Am 2026-09-09 entfernt (die Historie hält sie fest), der Verzicht steht jetzt als
Kommentar in `public/checkin/service-worker.js`.

**Zu entscheiden:** Soll die PWA offline etwas können?

- *Dafür:* Ein Proberaum im Keller hat oft schlechten Empfang. Ein zwischengespeicherter
  Rahmen lädt sofort und zeigt wenigstens Verlauf und Statistik des letzten Standes.
- *Dagegen:* Check-in und Zeiterfassung brauchen die API. Ein Rahmen, der lädt und dann bei
  jeder Aktion scheitert, kann irreführender sein als eine klare Meldung „keine Verbindung".
  Die virtuelle Station hat sich aus demselben Grund bewusst dagegen entschieden — dort steht
  zusätzlich, dass eine Offline-Warteschlange keine verlässliche Uhr hätte.

**Falls dafür:** Pfade relativ zum Scope (`./index.html` statt `/index.html`), Cache-Name an
`version.json` hängen und wie die `?v=`-Links per Test absichern, und einen Weg vorsehen, wie
ein Nutzer einen veralteten Rahmen loswird.

---

### OI-45 · Kamera-Scanner in der Station
**Priorität:** niedrig — heute über die Kamera-App des Tablets gelöst

Die Schnellinbetriebnahme (ab 1.5.0) setzt darauf, dass die **Kamera-App des Tablets** den
QR-Code scannt und die Station öffnet. Die Station selbst hat keinen Scanner.

Das trägt, solange gekoppelt wird, **bevor** die Station zum Startbildschirm hinzugefügt wird.
Danach greift unter **iPadOS** der eigene Speichercontainer der installierten Web-App: ein
später gescannter Code landet in Safari, die installierte Station sieht ihn nie. Unter Android
teilen sich installierte PWA und Chrome den Speicher, dort besteht das Problem nicht.

**Zu entscheiden:** Bekommt der Einrichtungs- und Einstellungs-Bildschirm der Station einen
eigenen Kamera-Scanner?

- *Dafür:* Eine installierte iPadOS-Station ließe sich ohne Tippen neu koppeln — der Fall
  tritt bei jedem neuen Token auf, in der öffentlichen Demo stündlich.
- *Dagegen:* Eine zweite Fremdbibliothek (`html5-qrcode`, ~350 kB, in der Check-in-PWA heute
  über unpkg statt vendored), Kamerarechte auf einem Kiosk-Tablet, mehr Testfläche. Der Weg
  über die Kamera-App kostet nichts davon.

**Heutiger Ausweg:** 5 Sekunden auf die Uhr drücken und den Token eingeben. Die Reihenfolge
„erst koppeln, dann installieren" steht in `public/station/README.md`.

**Ungeprüft, weil kein Tablet zur Hand war:** Ist die Station bereits in einem Reiter offen,
hängt es vom Browser ab, ob ein aus der Kamera-App geöffneter Link **diesen** Reiter aktualisiert
oder einen zweiten öffnet. Im zweiten Fall koppelt sich der neue Reiter erfolgreich, während der
sichtbare — womöglich angeheftete — Reiter unverändert das alte Bild zeigt: Der Scan wirkt dann
folgenlos, obwohl er funktioniert hat. Der `hashchange`-Zuhörer in `public/station/js/app.js`
greift nur im ersten Fall. Verifiziert ist bislang nur die Änderung des Fragments im selben
Reiter. **Vor einem produktiven Rollout auf einem echten Tablet nachstellen** (Testfall QR-15 in
`docs/testplan.md`); bestätigt sich das Zwei-Reiter-Verhalten, ist die manuelle Token-Eingabe
über die Einstellungen der verlässlichere Weg für eine bereits laufende Station.

---

### OI-46 · Einmal-Kopplungscode statt Token im QR-Bild
**Priorität:** niedrig — bewusst zurückgestellt, siehe Abwägung

Der QR-Code zur Inbetriebnahme enthält den API-Token der Station im Klartext. Wer den Bildschirm
abfotografiert, hat ihn.

**Warum das heute vertretbar ist:** Der Token darf nur die Ressource `station` aufrufen
(`public/api/api.php`), `handleStation()` prüft zusätzlich `device_type = kiosk`, und ohne die
PIN eines Mitglieds bewirkt er nichts. Er ist nur für Admins lesbar
(`private/handlers/users.php`) und steht im Gerätedialog ohnehin im Klartextfeld — der QR-Code
macht ihn nicht exponierter, als er dort schon ist. Das Modal warnt ausdrücklich.

**Die Alternative:** Der QR enthält nur einen kurzlebigen Einmal-Code (etwa 8 Zeichen, 5 Minuten,
einmal einlösbar), den die Station an einem neuen, unauthentifizierten Endpunkt gegen den echten
Token tauscht. Der Token stünde dann nie in einem Bild.

**Was das kostet:** neue Spalte oder Tabelle samt Migration, ein Endpunkt ohne Authentifizierung,
ein eigenes Rate-Limit, Ablauflogik, Tests — und in der öffentlichen Demo eine zusätzliche
Freischaltung in `private/helpers/demo_mode.php`, also genau an der Stelle, die dort alles trägt.

**Zu entscheiden, falls das je gebaut wird:** Der Aufnahmeweg in der Station
(`tokenFromHash()` in `public/station/js/app.js`) ist bewusst eine einzige Eintrittsstelle. Ein
Kopplungscode kann dieselbe nutzen und muss keine zweite aufmachen.

---

### OI-47 · Gesperrtes Gerät: totes Polling nach fehlgeschlagenem Verbindungsversuch
**Priorität:** niedrig — Bestandsverhalten, seit 1.5.0 über einen weiteren Weg erreichbar

`api()` in `public/station/js/app.js` behandelt 403 und „Device has no name" (409) bewusst anders
als 401: Der Token wird **behalten** und `enterBlocked()` pollt im Minutentakt auf Besserung
(Entscheidung I1). `connect()` setzt bei jedem Fehlschlag jedoch `state.token = null`.

Trifft ein Verbindungsversuch auf ein gesperrtes Gerät, laufen beide Mechanismen gegeneinander:
`enterBlocked()` registriert ein 60-Sekunden-Intervall, das anschließend mit
`Authorization: Bearer null` abfragt und deshalb nie wieder greift, während der Aufrufer den
Einrichtungs-Bildschirm zeigt. Ein Banner-Rest und der Einrichtungs-Bildschirm liegen dabei
übereinander.

**Das ist kein neuer Fehler:** Derselbe Ablauf besteht schon im Kaltstart (`init()`, Zweig mit
`loadToken()`) und in `setupSave`. Seit der Schnellinbetriebnahme ist er zusätzlich über einen
gescannten QR-Code erreichbar.

**Zu entscheiden:** Soll `connect()` den Sperrfall vom Tokenverlust trennen — etwa indem es
`state.token` nur bei 401 verwirft — oder soll `enterBlocked()` bei fehlendem Token gar nicht
erst starten? Gefunden im Codequalitäts-Review zur Schnellinbetriebnahme.

---

## Restarbeiten

### OI-4 · Terminbezug: Oberfläche unvollständig
**Priorität:** erledigt am 2026-09-03 (1.2.2 und 1.2.3)

Backend war fertig seit `7e9ec6a`; es fehlte der Zugang in der Oberfläche. Alle drei
Teilpunkte sind geschlossen:

- **Terminfeld im Dashboard-Nachtrag** (1.2.2). Dabei fiel auf, dass `workSessionUpdate()`
  `appointment_id` überhaupt nicht verarbeitete — beim **Bearbeiten** wäre die Auswahl
  stillschweigend verworfen worden. Der Update-Pfad unterscheidet jetzt drei Fälle: Feld
  fehlt (Termin bleibt), Feld gesetzt (Zuordnung), Feld leer (Zuordnung gelöst).
- **Zugang zum Termin-Bericht** (1.2.2). Der Berichtsdialog bietet „Summen nach Termin" als
  CSV und als Druckansicht; ein dritter Knopf war damit nicht mehr nötig.
- **PWA bietet alle Termine des Jahres an** (1.2.3), nicht mehr nur die heutigen. Die
  Beschränkung war die falsche: Vorbereitung findet vor der Veranstaltung statt,
  Nachbereitung danach — genau die Stunden, die der Bericht zeigen soll.

**Die Anwesenheitskopplung ist ersatzlos entfallen** (1.2.3). Der ursprünglich hier notierte
„Datumsschutz trifft die Sache nur ungefähr" hat sich damit erledigt, aber anders als gedacht:
Nicht der Schutz wurde verfeinert, sondern das Geschützte abgeschafft.

Ausschlaggebend war ein Befund, der beim Abwägen auftauchte: `statistics.php` liest `records`
**ohne Rücksicht auf `checkin_source`**. Ein vom Timer erzeugter Eintrag zählte damit voll in
die Anwesenheitsquote und — wegen `arrival_time = NOW()` — auch in die Pünktlichkeit. Wer um
08:00 die Bühne für das Konzert um 19:00 aufbaute, galt als anwesend **und** als elf Stunden
zu früh. Beide Kernauswertungen nahmen Schaden, und zwar zugunsten des Mitglieds.

Der Datumsschutz sollte das verhindern, wehrte aber nur den Vortag ab und ließ den Regelfall
durch: Am Veranstaltungstag wird gearbeitet.

Seither gilt für alle Erfassungswege ein Satz: **Kein Weg der Zeiterfassung erzeugt
Anwesenheit.** Die bisherige Regel — der Timer ja, der Nachtrag nein, und der Timer auch nur
heute — konnte niemand erklären.

**Eingrenzung statt Kopplung** (1.2.3): Tätigkeitsarten lassen sich mit Terminarten
verknüpfen (`activity_type_appointment_types`). Eine leere Zuordnung bedeutet hier **keine
Einschränkung**, anders als bei den Gruppen — die Migration legt deshalb keine Datenzeile an,
und wer die Eingrenzung nicht pflegt, verliert nichts. Zusammen mit der Gruppenbindung
beantwortet das beide Fragen getrennt: die Gruppe **wer** eine Tätigkeit erfassen darf, die
Terminart **wozu** sie passt.

**Nicht bereinigt:** Anwesenheitseinträge mit `checkin_source = 'timer'` aus der Zeit vor
1.2.3 bleiben bestehen und zählen weiter in beide Auswertungen. Ein Teil davon ist korrekt —
das Mitglied war tatsächlich da —, und welcher, lässt sich nachträglich nicht entscheiden.
Löschen wäre ein nicht umkehrbarer Eingriff in erfasste Daten. Die Migration nennt ihre
Anzahl als Warnung im Update-Protokoll.

**Teilweise erledigt am 2026-09-02:** Die Auswahl in der PWA war zusätzlich schlicht leer,
weil sie nur beim Öffnen des Antragsdialogs befüllt wurde, und das Tagesdatum kam aus UTC —
abends ab 22:00 MESZ zeigte sie den Folgetag. Beides behoben (`d7ee191`).

---

### OI-5 · Automatisches Löschen der Auditspur
**Erledigt am 2026-09-04** — siehe [OI-2](#oi-2), dort zusammengefasst.

---

### OI-23 · Der Kettentest bildet den Wizard nach, statt ihn auszuführen
**Priorität:** mittel

`tests/db/verify_migration_chain.php` enthält `runWizardStep3()` — eine Nachbildung von
Schritt 3 aus `public/update/index.php`. Beide Seiten können auseinanderlaufen, und genau das
ist am 2026-09-04 passiert: Der Wizard rendete nach einer erfolgreichen Migration eine leere
Seite, weil `foreach ($chain as $step)` die Nummer des Wizard-Schritts überschrieb. Der
Kettentest lief grün durch, weil seine Nachbildung diese Variable nicht kennt.

Behoben ist der Fehler; die Ursache für sein Übersehen nicht. `tests/suites/update_wizard.php`
prüft seither die Quelle statisch — das fängt dieselbe Fehlerklasse, aber keine andere.

**Zu entscheiden:** Schritt 3 aus dem Wizard in eine eigene Funktion ziehen, die beide Seiten
aufrufen. Preis: Der Wizard ist bewusst eine einzelne, abhängigkeitsfreie Datei, die auch dann
läuft, wenn der Rest der Installation nicht mehr zusammenpasst.

**Nebenbefund, unabhängig davon:** Der Wizard sperrt sich per `.htaccess` aus, **bevor** er
sein Ergebnis rendert. Scheitert das Rendern, ist das Ergebnis nicht mehr erreichbar. Die
Sperre selbst ist richtig — die Reihenfolge macht jeden Fehler in der Ausgabe unauffindbar.
Wieder öffnen lässt sich der Assistent nur, indem man `public/update/.htaccess` löscht.

---

### OI-22 · Selbstauskunft findet verwaiste Logzeilen nicht
**Priorität:** mittel

`my_data` liest die Änderungshistorie über einen Join auf `work_sessions`
(`private/handlers/my_data.php`). Ist die Sitzung gelöscht, taucht ihre Historie in keiner
Selbstauskunft mehr auf — obwohl der `delete`-Eintrag in `changes` die komplette Sitzung samt
`member_id`, Notiz und Ortsnamen weiterträgt. Für Art. 15 DSGVO ist das eine Lücke.

Mit [OI-2](#oi-2) ist sie zeitlich begrenzt: Nach Ablauf der Auditfrist wird der Eintrag
anonymisiert und enthält nichts Personenbezogenes mehr. Bis dahin bleibt sie offen.

**Zu entscheiden:** Die Zuordnung ginge nur über `member_id` aus dem JSON in `changes` —
`JSON_EXTRACT` in einer Abfrage, die bisher ohne auskommt, für einen Datenbestand, den es in
den meisten Installationen gar nicht gibt.

- *Dafür:* Die Auskunft wäre vollständig, solange der Eintrag Personenbezug hat.
- *Dagegen:* Die Abfrage hinge am inneren Aufbau der Logzeilen. Ändert sich das Format der
  `changes`, liefert die Auskunft still wieder nichts — schlechter als eine bekannte Lücke.

**Alternative:** Die Auditfrist in der Vorgabe kurz halten (steht auf 1 Jahr) und den Punkt
in `DATENSCHUTZ.md` benennen, statt ihn technisch zu lösen. Das ist der aktuelle Stand.

---

### OI-19 · Fehler eines Exports erscheinen als JSON-Seite
**Priorität:** niedrig

Alle Exporte werden über einen Seitenaufruf geholt — `window.location.href` für den
Download, `window.open` für die Druckansicht. Antwortet der Server mit einem Fehler, ist
diese Antwort die neue Seite: Der Nutzer sieht `{"message":"…"}` im Vollbild oder in einem
neuen Tab, statt einer Meldung in der Oberfläche.

**Gefunden** beim manuellen Test ZR-M9 am 2026-09-03 mit einem Zeitraum über 24 Monate.

**Korrektur vom 2026-09-07.** Hier stand: „`exportMembers`, `exportAppointments` und
`exportRecords` nutzen dasselbe Muster seit jeher." **Das war falsch** — ungeprüft aus dem
Muster der Arbeitszeit-Exporte geschlossen. Die drei nutzen seit ihrer Einführung
`fetch` + Blob, also genau den Weg, der hier als Lösung vorgeschlagen wird.

Damit dreht sich die Bewertung: Betroffen sind allein die Arbeitszeit-Berichte, die über
`window.location.href` und `window.open` gehen. Dass ausgerechnet dieses Muster
funktioniert, während die „bessere" Variante mit `fetch` an einem leeren Auth-Header
scheiterte (OI-24), ist der Grund, warum der Umbau hier ohne Not nichts gewinnt.

**Teilweise entschärft.** Der konkrete Fall ist behoben: `runWorktimeReport()` prüft die
24-Monats-Grenze und das verdrehte Datum vor dem Aufruf und meldet beides als Toast. Die
Rechnung ist gegen die serverseitige geprüft — für zehn Datumspaare einschließlich
Schaltjahr liefern PHP und JavaScript denselben letzten zulässigen Tag. Die Prüfung im
Server bleibt die verbindliche.

**Was offen bleibt:** Läuft die Session ab, während der Berichtsdialog offen steht, erscheint
weiterhin `{"message":"Unauthorized"}` als Seite. Dasselbe gilt für jede von Hand
zusammengesetzte URL. Die drei älteren Exporte sind seit 2026-09-07 nicht mehr betroffen —
sie prüfen den Status und melden über einen Toast (OI-24).

**Lösungsweg:** Eine gemeinsame Funktion, die den Export per `fetch` anfordert, den Status
prüft und erst bei `200` ausliefert. Zwei Fallstricke:

- Der **Download** ließe sich dann aus einem Blob speisen. Das ist der einfache Teil.
- Die **Druckansicht** darf *nicht* aus einem Blob kommen: Die Seite setzt `<base href="../">`,
  damit `css/print.css` und das Vereinslogo laden. Unter einer `blob:`-URL greift diese Basis
  nicht, der Bericht käme ohne Stylesheet und ohne Logo. Hier bliebe nur eine Vorabprüfung
  per `fetch` und danach ein `window.open` auf die echte URL — also bewusst zwei Anfragen.

### OI-34 · Kiosk: Bedienbarkeit der Nummern- und PIN-Eingabe
**Priorität:** erledigt am 2026-09-07 — Rückmeldung aus dem ersten Tablet-Test, umgesetzt in `public/station/` (Umschalter „ABC“/„123“ als Taste im Ziffernblock, Hinweis „Nummer darf nicht leer sein“, Mitgliedsnummer im PIN-Bild)

Drei Beobachtungen aus dem Probebetrieb der virtuellen Station (`public/station/`):

- **„ABC"-Taste absetzen.** Im Nummernbild steht der Umschalter auf die Buchstabentastatur
  zwischen „Abbrechen" und „Weiter" und sieht aus wie eine dritte Navigationstaste. Er gehört
  optisch zur Tastatur — abgesetzt von den beiden Aktionen, eher größer, etwa als eigene Taste
  im Ziffernblock.
- **Leere Nummer benennen.** Ein Tipp auf „Weiter" ohne Nummer tut heute nichts
  (`numberNext` bricht still ab). Ein Hinweis „Nummer darf nicht leer sein" gehört ins
  Nummernbild, analog zum Fehlertext unter dem PIN-Block.
- **Nummer im PIN-Bild anzeigen.** Wer „Nummer oder PIN falsch" liest, weiß nicht, ob er sich
  bei der Nummer oder bei der PIN vertippt hat. Die eingegebene Nummer im PIN-Bild als Text
  zu zeigen, verrät nichts Neues (er hat sie selbst eingetippt) und spart den zweiten
  Durchlauf. Die Einheitlichkeit der Fehlermeldung (E12) bleibt unberührt.

**Berührt:** `public/station/index.html`, `public/station/css/style.css`,
`public/station/js/app.js` (`renderNumberPad()`, `numberNext`, `showScreen('pin')`).
Kein Server-Anteil.

### OI-35 · PWA: Arbeitszeit korrigieren und nachtragen
**Priorität:** erledigt am 2026-09-08 — umgesetzt in `public/checkin/` (Modal mit zwei Einstiegen: „Korrigieren“ am Verlaufseintrag, „Zeit nachtragen“ im Arbeitszeit-Tab)

Der Verlauf-Tab der Check-in-PWA führt Arbeitszeitsitzungen in der Zeitachse mit, aber ohne
jede Aktion: `addWorkSessionToHistory()` rendert Datum, Tätigkeit, Dauer, Notiz und Status —
keinen Knopf. Wer einen Vertipper in Start, Ende oder Notiz bemerkt, muss ans Dashboard. Dort
darf ein einfacher Nutzer seine eigenen abgeschlossenen Sitzungen korrigieren
([worktime.js:211](../public/js/modules/worktime.js), `renderWorktimeActions()`). Die PWA bleibt
hinter dieser Möglichkeit zurück — obwohl sie das Gerät ist, mit dem die Zeit erfasst wurde.

**Serverseitig ist der Weg schon da.** `workSessionUpdate()` lässt den Eigentümer seine eigene
Sitzung ändern und setzt den Status dabei zurück auf `submitted`: Eine Änderung durch das
Mitglied entzieht die Bestätigung und verlangt eine neue Freigabe
([work_sessions.php:767](../private/handlers/work_sessions.php)). Ein „Korrektur-Antrag“ ist
damit kein neuer Datentyp und keine neue Ressource, sondern ein `PUT work_sessions` aus der PWA.
Zu bauen wäre allein die Oberfläche: Knopf am Verlaufseintrag, ein Formular (Tätigkeit, Start,
Ende, Pause, Notiz) und danach ein Neuladen des Verlaufs.

**Zweiter Fall: vergessenes Anstempeln.** Wer den Start ganz vergessen hat, hat keine Sitzung
zu korrigieren, sondern eine anzulegen. Auch dafür ist der Server fertig: `POST work_sessions`
**ohne** `action` legt über `workSessionCreateManual()` eine vollständige Sitzung an — Status
`submitted`, `source = manual`, ohne Ortsnachweis, ohne Rückwirkungsgrenze. Das Dashboard hat
den Dialog dafür („+ Zeit nachtragen“, [index.html:1160](../public/index.html)), die PWA nicht.
Beides gehört in dieselbe Oberfläche: „Korrigieren“ am Verlaufseintrag (`PUT`), „Zeit
nachtragen“ im Arbeitszeit-Tab (`POST`) — ein Formular, zwei Einstiege.

**Vor der Umsetzung zu klären:**

- **Welche Sitzungen dürfen korrigiert werden?** `submitted` und `confirmed` liegen nahe. Bei
  `rejected` ist offen, ob eine Korrektur den Fall wieder öffnen soll oder ob eine Ablehnung
  endgültig bleibt.
- **Laufende Sitzungen bleiben außen vor.** Ein `PUT` auf eine laufende Sitzung antwortet
  **409** („Session is still running“), solange kein `end_time` mitkommt. Wer zu spät
  angestempelt hat, stoppt also zuerst und korrigiert danach. Ein Sonderweg für laufende
  Sitzungen wäre nicht ratsam: Eine zurückverlegte Startzeit kann die Sitzung sofort überfällig
  machen (`worktime_max_session_hours`), der nächste Zugriff kappt sie dann auf Start plus
  Obergrenze — das Mitglied verlöre genau die Zeit, die es nachtragen wollte.
- **Rückwirkende Frist.** Eine Korrektur an einer Sitzung aus dem Vorjahr verändert eine bereits
  abgeschlossene Auswertung und einen womöglich schon eingereichten Verwendungsnachweis. Ob es
  eine Grenze braucht — und ob sie eine Einstellung wird, analog `checkin_tolerance_hours`
  ([OI-21](#oi-21)) —, ist offen.
- **Begründung.** Wer eine bestätigte Zeit ändert, sollte vermutlich sagen warum. Heute gäbe es
  dafür nur das Notizfeld; `work_session_log` hält zwar jede Änderung fest, aber keinen Grund.
- **Berührt [OI-3](#oi-3):** Ein Manager, der seine eigene Sitzung über die PWA korrigiert,
  behält `confirmed` — dieselbe Lücke wie beim Nachtrag, nur an einer weiteren Stelle.
- **Voraussetzung [OI-37](#oi-37):** Eine Zeitkorrektur lässt den Ortsnachweis heute
  unangetastet. Solange das so bleibt, vervielfacht jeder neue Korrekturweg die Zahl falsch
  etikettierter Stunden.

**Berührt:** `public/checkin/index.html`, `public/checkin/js/app.js`
(`addWorkSessionToHistory()`, `loadHistory()`, Arbeitszeit-Tab), `public/checkin/css/style.css`.
Server voraussichtlich unberührt.

### OI-36 · PWA-Statistik ohne geleistete Stunden
**Priorität:** erledigt am 2026-09-07 — umgesetzt in `public/checkin/` (Block „Arbeitszeit“ im Statistik-Tab: bestätigte Jahressumme, Fußnote über Eingereichtes und Abgelehntes, Aufschlüsselung nach Tätigkeit)

Der Statistik-Tab der PWA zeigt zwei Karten — Anwesenheitsquote und Terminzahl — und die
Übersicht nach Gruppen. Die seit 1.2.0 erfassten Stunden kommen darin nicht vor. Ein Mitglied,
das seine Arbeitszeit über die PWA erfasst, kann dort seinen Verlauf sehen, aber keine Summe:
„Wie viele Stunden habe ich dieses Jahr geleistet?“ beantwortet die PWA nicht.

**Der Server liefert die Zahlen bereits.** `statistics` kennt den Parameter `include=worktime`
und hängt dann einen eigenen `worktime`-Block an
([statistics.php:153](../private/handlers/statistics.php), `worktimeStatistics()` in
[worktime.php:445](../private/helpers/worktime.php)): Gesamtminuten, Sitzungszahl, Aufteilung
nach Nachweisart und nach Tätigkeit, für einen `user` auf das eigene Mitglied begrenzt.
`loadStatistics()` in der PWA fragt den Parameter schlicht nicht an
([app.js:3080](../public/checkin/js/app.js)) — und auch das Dashboard nutzt den Block nirgends;
es rechnet seine Summen in `updateWorktimeStats()` selbst aus der Sitzungsliste. Der Block hat
damit heute **keinen** Abnehmer im Frontend.

**Vor der Umsetzung zu klären:**

- **Wann die Karte erscheint.** Nur wenn das Mitglied überhaupt Arbeitszeit erfassen darf —
  dieselbe Bedingung, die der Verlauf schon nutzt (`worktimeActivities.length > 0`). Sonst
  zeigt die Statistik allen anderen dauerhaft „0 h“.
- **Was mit `submitted` passiert.** `worktimeStatistics()` summiert ausschließlich `confirmed`
  mit `end_time` (Testplan AW-2). Wer gestern acht Stunden eingetragen hat und heute „0 h“ liest,
  hält das für einen Fehler. Ein zweiter Wert „davon x h in Freigabe“ wäre die ehrlichere
  Anzeige, verlangt aber eine Erweiterung des Blocks oder einen zweiten Abruf auf
  `work_sessions`.
- **Wie tief die Aufschlüsselung geht.** `by_activity` und `by_proof` liegen bereit; auf einem
  Handybildschirm ist die Frage, ob mehr als eine Summe plus Tätigkeitsliste noch lesbar ist.
- **Getrennt bleiben.** Die Stunden gehören in einen eigenen Block, nicht in die Anwesenheits-
  quote — siehe „Statistik getrennt von Anwesenheit“ unter *Bewusst entschieden*.

**Erledigt vorab:** `include=worktime` war nur über `docs/testplan.md` (AW-2, AW-5) belegt und
ist seit 2026-09-07 in `API.md` dokumentiert (Abschnitt *Statistiken → Arbeitszeit im Ergebnis*).

**Berührt:** `public/checkin/index.html` (Statistik-Tab), `public/checkin/js/app.js`
(`loadStatistics()`, `displayStatistics()`), `public/checkin/css/style.css`.
Server voraussichtlich unberührt.

### OI-38 · Die Auditspur ist nirgends zu sehen
**Priorität:** niedrig — vorgemerkt, entstanden bei der Planung von [OI-35](#oi-35)

Jede Änderung an einer Arbeitszeitsitzung wird protokolliert: `logSessionChange()` schreibt
Vorher/Nachher-Werte als JSON in `work_session_log.changes`
([worktime.php:317](../private/helpers/worktime.php)). Angezeigt wird das nirgends. Der einzige
Weg heraus ist die Selbstauskunft — `my_data` gibt die eigenen Logzeilen aus
([my_data.php:150](../private/handlers/my_data.php)) —, und die liest niemand zur Freigabe.

**Folge für die Freigabe.** In der Freigabeliste des Dashboards steht ein Eintrag mit Status
„wartet auf Freigabe". Ob das eine frisch nachgetragene Sitzung ist oder eine bestätigte, deren
Zeiten das Mitglied nachträglich verschoben hat, ist daran nicht zu erkennen. Der Manager gibt
also frei, ohne zu wissen, worüber er entscheidet. Mit [OI-35](#oi-35) — Korrigieren und
Nachtragen aus der PWA — wird dieser Fall vom Sonderfall zum Regelfall.

Zwei Teilsignale gibt es bereits: Der Nachweisgrad fällt bei einer Zeitkorrektur auf
„teilbelegt" oder „unbelegt" (sobald [OI-37](#oi-37) umgesetzt ist), und `source` unterscheidet
`timer` von `manual`. Beides sagt aber nur, *dass* etwas anders ist, nicht *was*.

**Zu klären:**

- **Wie viel gehört in die Liste?** Ein Vermerk „geändert am … von …" mit ausklappbarem
  Vorher/Nachher wäre das Vollbild; ein Badge „geändert" die kleinste brauchbare Stufe.
- **Wer darf die Spur sehen?** Naheliegend Admin und Manager für alle Sitzungen, das Mitglied
  für die eigenen. Letzteres gibt es über `my_data` bereits, nur nicht in der Oberfläche.
- **Neue Ressource oder Erweiterung?** `work_sessions` könnte die Logzeilen bei `GET` mit `id`
  mitliefern; sauberer wäre `work_session_log` als eigene, lesende Ressource. Die Listenansicht
  darf davon nicht langsamer werden.
- **Begründungsfeld.** Bei der Planung von [OI-35](#oi-35) wurde eine Pflichtbegründung für
  Korrekturen bewusst verworfen: In der Notiz verschmutzte sie den Verwendungsnachweis, in der
  Auditspur wäre sie unsichtbar geblieben. Wird die Spur sichtbar, ist ein zusätzlicher
  Schlüssel `reason` im vorhandenen JSON von `changes` der naheliegende Ort — ohne Migration.

**Berührt:** `private/handlers/work_sessions.php`, `public/js/modules/worktime.js`,
`API.md`, `docs/testplan.md`. Kein Schemabedarf.

---

## Sicherheit

> **Was hier stehen darf.** Dieser Abschnitt ist öffentlich. Aufgenommen werden nur
> Schwächen, die bereits privilegierten Zugang voraussetzen, aus dem AGPL-Quellcode ohnehin
> ablesbar sind oder eine bewusst getroffene Abwägung darstellen. **Ungepatchte Lücken, die
> ohne vorherigen Zugang ausnutzbar sind oder eine Rechteausweitung erlauben, gehören nicht
> hierher**, sondern als privates GitHub Security Advisory — und erscheinen erst mit dem Fix
> in dieser Liste. Ablauf in `SECURITY.md`.

### OI-6 · TOTP-Secret im Klartext
**Priorität:** hoch für den Nachweiszweck, mittel für den Betrieb

Risiko R5 aus der Spec. Das Secret der Stationen liegt unverschlüsselt in `users.totp_secret` und
wird in der Geräte-Verwaltung angezeigt ([devices.js:347](../public/js/modules/devices.js)). Wer
Administrator- oder Manager-Zugang hat, kann Codes offline erzeugen und Ortsnachweise fälschen.

Für die Anwesenheitserfassung war das vertretbar. Für einen Förder-Verwendungsnachweis begrenzt
es, was „ortsbelegt" aussagt — das steht so auch in `DATENSCHUTZ.md` Abschnitt 10.7.

**Vorgemerkt als Lösungsweg** (Spec, Abschnitt 13):

- **Selbstregistrierung von Stationen:** Das Gerät erzeugt das Secret selbst und meldet sich
  einmalig an; danach ist es in der Oberfläche nicht mehr lesbar. Einschränkung: TOTP ist
  symmetrisch, der Server muss das Secret kennen. Erreichbar ist einmalige Übertragung,
  verschlüsselte Ablage und kein Rücklesen — nicht: „verlässt das Gerät nie".
- **PWA im Stations-Modus — seit 1.3.0 gebaut** (`public/station/`, Gerätetyp `kiosk`): Der
  Kiosk erhält nur den gültigen Code, nie das Secret; für Kiosks und Auth-Geräte zeigt die
  Geräteverwaltung kein Secret mehr. **Bleibt offen:** die verschlüsselte Ablage in
  `users.totp_secret` für TOTP-Stationen (Schlüssel in `config.php`, Installer und Updater
  schreiben ihn, Umschlüsselung im Update).

---

### OI-7 · Gültigkeitsfenster der TOTP-Codes
**Priorität:** niedrig

Risiko R6. `verify($code, null, 1)` erlaubt ein Zeitfenster Toleranz in beide Richtungen, ein Code
gilt also rund 90 Sekunden — lange genug, um ihn per Screenshot an einen Abwesenden zu schicken.
Toleranz `0` wäre strenger, aber anfällig für Uhrendrift auf dem Mitgliedsgerät.

Bewusst unverändert. Nur dokumentieren, nicht als stärker beschreiben, als es ist.

---

### OI-17 · Keine Content-Security-Policy
**Priorität:** mittel

Die Anwendung liefert **keine** CSP — weder als Header noch als `<meta http-equiv>`. Am
2026-09-03 nachgeprüft: keine der neun `.htaccess`-Dateien und kein `header()`-Aufruf setzt
sie. `CLAUDE.md` behauptete das Gegenteil; die Zeile war schlicht falsch und ist korrigiert.

**Warum sie nicht einfach nachgereicht wird.** `public/index.html` enthält 95
`onclick`-Attribute und 124 Inline-`style`-Attribute. Jedes davon ist aus Sicht einer CSP
Inline-Code:

- CSP ohne `'unsafe-inline'` → die Oberfläche funktioniert nicht mehr
- CSP mit `'unsafe-inline'` für `script-src` → gegen XSS praktisch wirkungslos

Die zweite Variante wäre eine Zeile, die in einem Audit gut aussieht und nichts verhindert.
Deshalb bewusst keine CSP, statt einer, die nur so heißt.

**Was stattdessen gesetzt wurde** (`public/.htaccess`, seit 2026-09-03):
`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: strict-origin-when-cross-origin`.

**Weg zu einer echten CSP** — in dieser Reihenfolge, sonst bricht Schritt 3:

1. Die 95 `onclick`-Attribute auf `addEventListener` umstellen. Die PWA unter
   `public/checkin/` ist bereits frei von Inline-Handlern und taugt als Vorlage.
2. Inline-`style` auf Klassen aus `public/css/` umstellen, oder `style-src 'unsafe-inline'`
   als bewusste Ausnahme behalten — Inline-Styles sind das deutlich kleinere Risiko.
3. `Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none';
   base-uri 'self'; frame-ancestors 'none'` setzen und gegen alle Sektionen prüfen.

Schritt 1 ist der gesamte Aufwand und gehört in eine eigene Spec.

**Keine Entwarnung.** Eine CSP ist die zweite Verteidigungslinie, nicht die erste. Ihr Fehlen
ist kein Freibrief für ungeprüfte Ausgabe: Jede neue serverseitig gerenderte HTML-Ansicht
maskiert ihre Werte selbst. Das betrifft insbesondere die geplante Druckansicht der
Arbeitszeitauswertung, in die freie Nutzereingaben aus der PWA fließen — siehe
`docs/superpowers/specs/2026-09-03-zeitraumfilter-druckansicht-design.md`, Abschnitt
„Sicherheit".

---

### OI-18 · `session_info` gab Session-ID und CSRF-Token heraus
**Priorität:** erledigt am 2026-09-03

`GET ?resource=session_info` lieferte jeder angemeldeten Rolle — auch `user` — die
Session-ID im Klartext und das vollständige `$_SESSION`-Array, darin den CSRF-Token,
`user_id`, `email` und interne Rate-Limit-Schlüssel. Die Funktion hieß
`getSessionDebugInfo()` ([auth.php:263](../private/helpers/auth.php)) — ein Debug-Werkzeug,
produktiv geroutet.

**Warum das zählte.** Das Session-Cookie ist `HttpOnly`, JavaScript kommt also nicht daran.
Dieser Endpoint reichte die ID per `fetch()` an genau dieses JavaScript zurück, zusammen mit
dem CSRF-Token. Bei einem XSS war der Unterschied zwischen gesetztem und nicht gesetztem
`HttpOnly` damit aufgehoben: ein Request, und Sitzungsübernahme wie CSRF-Umgehung lagen
zusammen vor.

**Warum es keine eigenständige Lücke war.** Ohne XSS ist die Antwort nicht auslesbar — die
Same-Origin-Policy schützt sie, CORS ist nicht aktiv. Der Fund verstärkte andere Lücken,
öffnete aber keine. Aufgefallen ist er beiläufig bei der Arbeit an OI-17.

**Behoben am 2026-09-03.** Ausgeliefert werden nur noch `role`, `last_activity`,
`time_since_activity` und `remaining_seconds`. `session_id` und `session_data` sind entfernt;
ein Kommentar an der Fundstelle und der Abschnitt „Session-Status" in `API.md` halten fest,
dass sie nicht wieder aufzunehmen sind.

**Fußangel, die bestehen bleibt.** In [public/api/.htaccess](../public/api/.htaccess) liegen
CORS-Zeilen auskommentiert unter der Überschrift „bei Bedarf". `Access-Control-Allow-Origin: *`
allein ist harmlos, weil der Browser dann keine Cookies mitsendet. Wer dort je eine konkrete
Origin zusammen mit `Access-Control-Allow-Credentials: true` einträgt, macht jede
Session-Antwort für diese Origin lesbar. Vor dem Aktivieren zu prüfen, welche Endpoints dann
von fremden Seiten lesbar würden.

Die Funktion heißt seit demselben Tag `getSessionStatus()`; der alte Name benannte einen
Zweck, den sie nach der Kürzung nicht mehr hat.

**Offen geblieben:** Der Endpoint hat **keinen Aufrufer** im Frontend. Bewusst behalten — die
gekürzte Antwort ist die vorgesehene Grundlage für eine Ablaufwarnung in der Oberfläche.
Kommt sie nicht, ist der Endpoint ersatzlos entfernbar.

---

### OI-25 · Token-erzeugte Sessions
**Priorität:** mittel

`api.php` befüllt bei einem Bearer-Token-Request eine vollwertige PHP-Session. Seit 1.3.0 ist
eine so entstandene Session ohne den Token nicht mehr nutzbar — ein Zugriff allein über das
Session-Cookie liefert `401`. Das schließt die Lücke, dass ein einmal ausgestelltes
Session-Cookie eines Geräte-Tokens den Token selbst überflüssig machte.

**Fußangel dabei gefunden:** `public/js/modules/api.js::getAuthHeaders()` sendet
`Bearer ${sessionStorage.api_token}` — dieser Schlüssel wird im Dashboard nirgends gesetzt,
der Header lautet also faktisch `Bearer null`. Die Exporte in `import_export.js` scheiterten
dadurch bereits vor 1.3.0 mit `401`, nur unauffällig, weil der Fehler wie ein normaler
Session-Timeout aussah ([OI-19](#oi-19)). Einfach einen echten Token in
`sessionStorage.api_token` zu hinterlegen würde das Problem verschieben statt lösen: Die
Dashboard-Session liefe dann über den Token-Zweig und würde als `auth_type = 'token'`
markiert — mit allen Einschränkungen, die seit 1.3.0 für Token-Sessions gelten (siehe oben),
obwohl der Nutzer per Passwort angemeldet ist.

**Zu entscheiden:** Der Token-Zweig in `api.php` darf eine bereits bestehende
Browser-Session nicht überschreiben. Sauberer wäre, `getAuthHeaders()` im Dashboard-Kontext
gar keinen Bearer-Header zu setzen und Exporte über die normale Session laufen zu lassen —
das API-Token ist für Geräte und die PWA gedacht, nicht für das eigene Dashboard.

---

### OI-28 · `RateLimiter::check()` fail-open
**Priorität:** niedrig — vorbestehend, nicht durch 1.3.0 verursacht

Der DB-gestützte Zweig von `RateLimiter::check()` gibt bei einer `PDOException` `true`
zurück — der Aufruf gilt dann als nicht gesperrt. Für einfaches Rate Limiting ist das
vertretbar (lieber durchlassen als die Anwendung lahmlegen). Für eine Sperre, die
Brute-Force verhindern soll — Login, seit 1.3.0 auch die Kiosk-PIN — bedeutet ein
Datenbank-Hänger dasselbe: keine Sperre, solange die Störung andauert.

**Zu entscheiden:** Ob Login- und PIN-Sperren fail-open bleiben (Verfügbarkeit vor Schutz)
oder bei einer Datenbankstörung sicherheitshalber fail-closed reagieren sollen — mit dem
Preis, dass ein DB-Hänger dann auch reguläre Logins blockiert.

---

## Kleinere Funde

### OI-8 · Doppelte `id="scannerContainer"` in der PWA
**Priorität:** erledigt am 2026-09-02

`public/checkin/index.html` enthielt das Element zweimal mit derselben Id. `getElementById`
lieferte nur das erste; das zweite war toter Markup.

**Behoben** beim Zusammenführen von Check-in und Zeiterfassung zum Erfassen-Tab (Branch
`feat/pwa-capture-tab`): Der Scanner steht nun einmal, eine Ebene über den Ansichten, weil beide
Absichten ihn brauchen. Ein Skript über alle `id`-Attribute der Datei bestätigt, dass keine Id
mehr doppelt vorkommt.

---

### OI-9 · `currentUser` ohne `member_id` im Dashboard
**Priorität:** niedrig

Die Login-Antwort (`resource=login`) liefert `user_id`, `email`, `role` — **kein** `member_id`.
Der Token-Login (`resource=auth`) liefert es. In `worktime.js` war das die Ursache eines Fehlers;
umgangen, weil der Server Nicht-Managern ohnehin nur eigene Sitzungen liefert.

Wer künftig im Dashboard „gehört mir?" prüfen will, läuft in dieselbe Falle. Entweder `member_id`
in die Login-Antwort aufnehmen oder die Lücke hier dokumentiert lassen.

---

### OI-10 · Breite Tabelle in der Zeiterfassung
**Priorität:** ~~niedrig~~ → mittel (2026-09-09)

Acht Spalten scrollen auf schmalen Fenstern horizontal. Das ist `overflow-x: auto` aus
`.data-table` und verhält sich wie jede andere Tabelle der App — fällt hier nur stärker auf.
Kein Fehler, aber ein Kandidat für Spaltenpriorisierung auf kleinen Bildschirmen.

**Ergänzung 2026-09-09 — der Befund war zu milde.** Beim Aufnehmen der Produktbilder für
die Werbeseite fiel auf, dass die Tabelle nicht erst auf schmalen Fenstern leidet, sondern
schon auf gewöhnlichen Notebook-Auflösungen. `public/css/components/tables.css` setzt für
**jede** Tabelle dieselbe Untergrenze:

```css
table { width: 100%; border-collapse: collapse; min-width: 600px; }
```

600px ist für diese acht Spalten viel zu wenig. Gemessen am Demo-Bestand (admin-Ansicht,
25 Zeilen je Seite):

| Fensterbreite | Platz für die Tabelle | Eigenbedarf der Tabelle | Zeilenhöhe |
|---|---|---|---|
| 1280 px | 970 px | ~1570 px | 108 px |
| 1440 px | 1130 px | ~1570 px | 108 px |
| 1680 px | 1370 px | ~1570 px | 108 px |
| 1920 px | 1610 px | ~1570 px | 83 px |

Die Tabelle braucht rund 1570 px Inhaltsbreite. Weil die Seitenleiste etwa 250 px abzieht,
liefert ein Fenster das erst ab knapp 1900 px. Darunter greift nicht der waagerechte
Rollbalken — der käme erst unter 600 px —, sondern die automatische Tabellenberechnung des
Browsers quetscht die Spalten und bricht den Zellinhalt um. Sichtbare Folgen:

- Datumsangaben brechen mitten im Wert: `2026-` / `09-09` / `10:38` auf drei Zeilen
- Dauerangaben zerfallen: `4:39 h` / `15` / `Min.` / `Pause` auf vier Zeilen
- Zeilen wachsen von 83 px auf 108 px. Bei 25 Einträgen je Seite sind das gut 600 px
  zusätzliche Scrollstrecke ohne jeden Informationsgewinn.

Denkbare Wege, noch nicht entschieden:

1. Eine eigene Untergrenze für diese Tabelle (Modifikator-Klasse mit `min-width` um 1550px),
   damit sie unterhalb davon rollt statt umzubrechen. Kleinster Eingriff, löst aber nur das
   Umbrechen — die Tabelle bleibt breit.
2. `white-space: nowrap` auf den Zellen mit Datum und Dauer. Behebt die schlimmsten Brüche,
   ohne die Gesamtbreite anzufassen.
3. Spaltenpriorisierung wie oben schon angedacht: `Nachweis` und `Termin` unter einer
   Schwelle ausblenden oder in die Mitgliedszelle einklappen.

Die Untergrenze von 600px betrifft **alle** Tabellen der Anwendung. Ob andere Ansichten
mit weniger Spalten ebenfalls unter der Schwelle leiden, ist nicht geprüft.

---

### OI-11 · `manager@example.com` ohne verknüpftes Mitglied
**Priorität:** niedrig · Testdaten, nicht Code

Der Manager-Testaccount hat kein `member_id`. Ein Test musste deshalb umgebaut werden. Wer
Manager-Funktionen mit eigenem Mitgliedsbezug testen will, muss das Konto erst verknüpfen.

---

### OI-12 · `mod_expires` lokal nicht geladen
**Priorität:** niedrig · nur Entwicklungsumgebung

Der Block für Bilder-Caching in `public/.htaccess` ist in diesem XAMPP wirkungslos, weil das Modul
auskommentiert ist. Auf Hostern mit `mod_expires` greift er. Deshalb in `<IfModule>` gekapselt —
kein Handlungsbedarf, nur zur Kenntnis.

---

### OI-13 · Versions-Query muss von Hand gepflegt werden
**Priorität:** niedrig · abgesichert

`?v=<version>` an den CSS-Links wird bei jedem Release manuell nachgezogen.
`tests/suites/assets.php` schlägt fehl, wenn es vergessen wird — die Disziplin ist also
abgesichert, aber nicht automatisiert.

---

### OI-14 · Dokumentation liegt unversioniert
**Priorität:** erledigt am 2026-09-02

`docs/` und `CLAUDE.md` waren in `.gitignore` (Commits `82725c8`, `e511a93` vom 2026-04-16),
damit KI-Arbeitsdateien nicht im veröffentlichten AGPL-Release landen. Folge: Spezifikationen,
Umsetzungspläne, Testplan **und diese Datei** existierten nur lokal und wären mit dem
Arbeitsverzeichnis verloren gegangen.

**Entscheidung:** Als Open-Source-Projekt gehört die Doku ins Repository. Versioniert sind
seither `CLAUDE.md`, `docs/OPEN-ITEMS.md`, `docs/testplan.md` und `docs/superpowers/specs/`.

Weiterhin ignoriert bleiben:

- `docs/superpowers/plans/` — Prozessprotokolle abgeschlossener Arbeit, für Beitragende
  Rauschen. Die Specs erklären das *Warum*, die Pläne nur das *Wie es damals lief*.
  Nebeneffekt: Sie enthalten Testzugangsdaten inline und müssten vor einem Commit bereinigt
  werden.
- `test_credentials.md` — eine Datei dieses Namens im Repo ist ein schlechtes Signal und lädt
  zum Kopieren schwacher Vorgaben ein. Die erwarteten Testrollen stehen ohne Passwörter in
  `docs/testplan.md`.
- `.claude/` und `temporary_screenshots/` — lokale Werkzeug- und Arbeitsdateien.

Der bestehende `export-ignore`-Eintrag in `.gitattributes` wirkt dadurch endlich: Die
Dokumente sind im Repository sichtbar, fehlen aber im ZIP-Download, den ein Verein zur
Installation zieht.

**Offene Folge:** Diese Datei ist damit öffentlich. Für sicherheitsrelevante Einträge gilt
die Grenze im Abschnitt [Sicherheit](#sicherheit) und der Ablauf in `SECURITY.md`.

---

### OI-15 · `mainScreen` wird nie geschlossen
**Priorität:** erledigt am 2026-09-02

`public/checkin/index.html` öffnet in Zeile 70 `<div id="mainScreen">`, schließt es aber nie —
über die ganze Datei bleibt genau ein `<div>` offen. Browser ergänzen das fehlende Tag
stillschweigend am `</body>`, deshalb ist bisher nichts aufgefallen.

Gefunden am 2026-09-02 beim Umbau zum Erfassen-Tab durch eine Zählung der `div`-Tags; die
Differenz bestand schon vor diesem Umbau (nachgeprüft gegen den vorherigen Commit).

**Tatsächliche Ursache — kein Strukturproblem, sondern ein Tippfehler.** In Zeile 353 stand
`</div` ohne schließende spitze Klammer. Der HTML-Parser liest die folgende Zeile als Fortsetzung
desselben Tags und verschluckt sie: aus zwei schließenden Tags wurde eines. Die Einrückung war
die ganze Zeit korrekt, es fehlte ein einzelnes Zeichen.

**Zweiter Fund derselben Art:** In `public/index.html` fehlte das schließende Tag von
`<div class="dashboard">` tatsächlich — ebenfalls unbemerkt, weil der Browser es am `</body>`
ergänzte. Auch dort lagen sämtliche 16 Modals im Dashboard-Container.

**Folge des Fixes:** Die Modals liegen jetzt außerhalb ihrer Bildschirm-Container und sind damit
von deren `display` unabhängig. Das ist richtig so — sie tragen `position: fixed` und werden
ohnehin nur über `.active` sichtbar. Es hatte aber eine Konsequenz, die zuvor der Container
verdeckte: Ein offener Dialog überlebte in der PWA das Abmelden und stünde über dem
Anmeldebildschirm. `handleLogout()` schließt offene Dialoge deshalb jetzt ausdrücklich. Im
Dashboard entfällt das, weil der Abmeldevorgang zu `login.html` navigiert.

Geprüft: alle drei ausgelieferten HTML-Dateien sind ausgeglichen, alle 16 Dashboard-Modals und
alle 4 PWA-Modals öffnen weiterhin, und das offene Modal verschwindet beim Abmelden.

**Absicherung wäre möglich:** Ein Test in `tests/suites/assets.php`, der die `div`-Bilanz jeder
ausgelieferten HTML-Datei prüft, würde solche Fälle künftig beim Entstehen melden — beide hier
gefundenen wären damit sofort aufgefallen.

---

### OI-16 · Zeiterfassung zeigt inaktive Mitglieder zur Auswahl
**Priorität:** erledigt am 2026-09-02

Die Mitgliederauswahl der Zeiterfassung nahm den Jahres-Cache ungefiltert:

- **Filterleiste** — `fillWorktimeFilters()`, [worktime.js:274](../public/js/modules/worktime.js)
- **Nachtrag-Modal** — `openWorkSessionModal()`, [worktime.js:375](../public/js/modules/worktime.js)

Beide bauen ihre Optionen aus `dataCache.members[currentYear].data`, ohne den
Mitgliedschaftszeitraum zu berücksichtigen. Wer im gewählten Jahr nicht aktiv war, steht
trotzdem zur Wahl — und ein Nachtrag für ein ausgetretenes Mitglied lässt sich anlegen.

**Woran es sich messen lassen muss:** Die Statistik löst das bereits. Sie filtert mit
`allMembers.filter(m => m.is_active_in_period)`
([statistics.js:130](../public/js/modules/statistics.js)). Das Feld liefert der Server
jahresabhängig aus `membership_dates` — es steht im selben Cache, wird in der Zeiterfassung
nur nicht ausgewertet.

**Behoben** mit unterschiedlicher Regel je Ort, weil die beiden Auswahlen Verschiedenes tun:

- **Nachtrag-Modal:** Ausschluss. Für einen Zeitraum ohne Mitgliedschaft soll gar kein
  Eintrag entstehen können.
- **Filterleiste:** Kennzeichnung mit „(inaktiv)", kein Ausschluss. Sie dient dem Sichten
  vorhandener Einträge; geleistete Stunden bleiben ein gültiger Nachweis, auch wenn das
  Mitglied ausgetreten ist. Wären sie nicht auffindbar, fehlten sie in der Auswertung.

Die Anwesenheitsverwaltung blendet Einträge inaktiver Mitglieder sogar ganz aus
([records.js:545](../public/js/modules/records.js)). Für Arbeitszeiten wurde das **bewusst
nicht** übernommen: Stunden dürfen nicht verschwinden, weil jemand den Verein verlässt.

**Fallstrick beim Bearbeiten:** Das Modal setzt `memberSelect.value = session.member_id`.
Fiele das zugeordnete Mitglied aus der Liste, bliebe das Feld leer und das Speichern
verschöbe den Eintrag stillschweigend auf ein anderes Mitglied. Die Sitzung wird deshalb
**vor** dem Aufbau der Auswahl geladen, und ihr Mitglied bleibt enthalten — gekennzeichnet.
Am lebenden Objekt geprüft: neuer Eintrag 73 Optionen, Bearbeiten desselben Formulars 74 mit
korrekter Vorauswahl.

---

### OI-24 · CSV-Export war durch einen leeren Auth-Header blockiert
**Priorität:** erledigt am 2026-09-07 — Import weiterhin ungetestet

Der CSV-Export von Terminen, Mitgliedern und Anwesenheiten funktionierte nicht:

- Kalender: heruntergeladene Datei mit einer JSON-Fehlermeldung darin
- Mitglieder und Anwesenheiten: Fehlermeldung in der Oberfläche

**Ursache.** `getAuthHeaders()` baute den Header
`Authorization: Bearer ${sessionStorage.getItem('api_token')}` **unbedingt**. Das Dashboard
legt aber nie einen Token ab — `sessionStorage` hält dort nur `csrf_token` und
`current_user`. Der Header lautete also wörtlich `Bearer null`.

`api.php` startet die Session nur, wenn **kein** Token mitkommt
(`if (!$apiToken) session_start()`). Der Aufruf lief damit in die Token-Prüfung, fand
keinen Nutzer zu `"null"` und endete mit `401 Invalid or inactive API token` — trotz
gültiger Anmeldung. `getAuthHeaders()` wurde ausschließlich von diesen drei Exporten
benutzt; deshalb war sonst nichts betroffen.

**Warum es erst spät auffiel — und warum es vorher funktionierte.** Der Fehler steckt seit
der Einführung des Exports (`8766480`, 2025-12-11) im Code, blieb aber wirkungslos: Apache
verschluckte den `Authorization`-Header, PHP sah ihn nie, `$apiToken` blieb leer und die
Session griff.

Geweckt hat ihn `3d1a30e` (2026-04-16),
*„pass Authorization header through Apache; prevent session shadowing on token auth
(BUG-4)"* — ein in beiden Teilen berechtigter Fix: Ohne das Durchreichen funktioniert
Token-Auth unter Apache nicht, und das `session_unset()` verhindert, dass eine alte
Admin-Session die Rechte eines Token-Nutzers ausweitet. Der Nebeneffekt war, dass
`Bearer null` von da an ankam.

**Warum eine 1.1.3-Installation betroffen sein kann.** Die Version wurde am 2026-04-15
gestempelt und blieb bis zum 2026-09-01 stehen. Der Bruch liegt am **zweiten Tag** dieses
Fensters: Eine 1.1.3 von vor dem 16.04. exportiert einwandfrei, eine von danach nicht — bei
gleicher Versionsnummer.

**Behoben am 2026-09-07.** `getAuthHeaders()` setzt den Header nur noch, wenn ein Token
vorliegt. Die drei Exporte senden zusätzlich `credentials: 'same-origin'`, und der
Termin-Export prüft jetzt `response.ok` — ohne das wurde der Fehlerkörper zum Blob und
landete als `.csv` mit JSON darin auf der Platte.

Abgesichert durch drei Tests: Export über die Session ohne Auth-Header (`api_selftest`),
ein leerer Bearer-Header wird abgewiesen (hält das Server-Verhalten fest), und ein Wächter
in `assets`, der anschlägt, sobald in `api.js` wieder ein Bearer-Header direkt aus
`sessionStorage` gebaut wird. Der Wächter ist gegengeprüft: Mit der alten Fassung von
`api.js` schlägt er fehl.

**Import.** Von dieser Ursache **nicht** betroffen — die Import-Aufrufe nutzen
`credentials: 'same-origin'` und schicken den CSRF-Token im FormData, ohne
`getAuthHeaders()`. Am 2026-09-07 mit je einem Datensatz manuell geprüft: funktioniert.

---

**Der eigentliche Punkt: Export und Import passten nicht zusammen** (behoben am 2026-09-07)

Die Anforderung hinter diesem Eintrag ist der Round-Trip — ein exportiertes CSV soll ohne
Umbau wieder importierbar sein. Zwei Spaltennamen standen dem im Weg:

| Bereich | Export schrieb | Import verlangt |
|---|---|---|
| Termine | `type` | `type_name` |
| Anwesenheiten | `arrival_time` | `arrival_date_time` |

Beide Male wurde die Datei mit „missing required columns" abgewiesen, bevor eine Zeile
gelesen war. **Mitglieder waren immer schon round-trip-fähig**, auch die Gruppen: Der Export
verkettet mit `GROUP_CONCAT(… SEPARATOR '|')`, der Import zerlegt mit `explode('|', …)`.

Die **Reihenfolge** der Spalten spielt keine Rolle und tat es nie — der Import bildet mit
`array_combine($header, $data)` ab und liest nach Namen. Zusatzspalten wie `groups` im
Termin-Export oder `checkin_source` im Anwesenheits-Export werden ignoriert.

**Gelöst durch Angleichung des Exports** an die Namen des Imports; die sind die präziseren
(`type_name` ist auch die Spalte in der Datenbank, `arrival_date_time` sagt, dass Datum und
Uhrzeit darinstehen). Der Import akzeptiert die alten Namen weiter als Zweitnamen, damit
archivierte Dateien einlesbar bleiben.

Abgesichert durch die neue Suite `export_import`: Sie ruft die drei Exporte ab und hält die
Kopfzeile gegen die Pflichtspalten des Imports — rein lesend, ohne Importlauf, weil ein
Test, der Datensätze anlegt, nicht in einen beiläufig gestarteten Durchlauf gehört.
Gegengeprüft: Mit der alten Fassung von `export.php` schlagen genau die zwei betroffenen
Fälle fehl, Mitglieder bleiben grün.

**Terminzuordnung beim Reimport** (gelöst am 2026-09-07)

`importRecords()` ordnete allein über zeitliche Nähe zu und konnte deshalb Probe und
Vorstandssitzung am selben Abend verwechseln.

Der Schlüssel dafür musste nicht erfunden werden — die Anwendung hat ihn: Beim Anlegen weist
`appointments.php` einen Termin **dieser Art** im Toleranzfenster als Konflikt ab, während
zwei verschiedene Arten am selben Abend erlaubt sind. Termin-Identität ist also
**Art + Datum + Startzeit**. Genau die Art fehlte der Zuordnung; sie nutzte nur die Hälfte
des Merkmals, an dem die Anwendung selbst Termine unterscheidet.

`appointment_id` schied aus: beim Umzug in eine andere Installation bedeutungslos.
`appointment_title` ebenfalls — zwei Proben heißen beide „Probe"; ein Titel benennt, er
identifiziert nicht. Er dient nur zum Anlegen.

Der Export führt deshalb zusätzlich `appointment_start_time` und `appointment_type`. Der
Import steigt ab:

1. **Exakt** über Datum + Startzeit + Terminart, wenn die Spalten vorhanden sind
2. **Zeitliche Nähe** wie bisher — für ältere Dateien und Fremdsysteme, die keine zu unseren
   passende Terminart kennen
3. **Anlegen**, nur mit `create_missing_appointments` und nur bei vollständigem Schlüssel

**Verhältnis zu `extract_appointments`** — geklärt, weil beide nach „Termine aus einer
Anwesenheitsdatei" aussehen:

| | `extract_appointments` | Stufe 3 des Record-Imports |
|---|---|---|
| Eingabe | nur `arrival_date_time` | mitgelieferter Schlüssel |
| Verfahren | raten: clustern, runden, Schwellwert ≥ 5 | übernehmen |
| Ergebnis | **Vorschläge, kein Schreibzugriff** | Termin wird angelegt |
| Wofür | Termine sind unbekannt und sollen rekonstruiert werden | Termine sind bekannt und fehlen nur im Ziel |

Sie überschneiden sich nicht, und nur einer der beiden Wege schreibt überhaupt.
`extractAppointments()` enthält kein einziges `INSERT` — ein Test hält das fest, damit die
Arbeitsteilung nicht unbemerkt verwischt.

**Restrisiko, bewusst getragen:** Heißt eine Terminart im Zielsystem anders („Gesamtprobe"
statt „Probe"), greift Stufe 1 nicht und es geht auf Stufe 2 — also auf das bisherige
Verhalten, nicht schlechter. Wird ein Termin nachträglich um Stunden verschoben, findet ihn
weder Stufe 1 noch die Toleranz. Der Schlüssel ist genau so belastbar wie die Regel, mit der
die Anwendung ohnehin arbeitet.

---

### OI-26 · Schema-Guard nur für `checkin_source`
**Priorität:** niedrig

In `ehrensache_db.sql` prüft nur die Spalte `checkin_source` vor dem `ALTER TABLE`, ob der
neue Enum-Wert schon vorhanden ist (Schutz für ein frisches Einspielen des Schemas gegen
eine bereits migrierte Datenbank). `device_type` und `work_sessions.source` — beide seit
1.3.0 ebenfalls um neue Werte erweitert (`kiosk` bzw. `station`) — haben keinen
entsprechenden Guard.

**Folge:** Ein direktes Einspielen von `ehrensache_db.sql` auf eine Datenbank, die diese
Spalten bereits in der neuen Form hat, kann an diesen beiden Stellen mit einem SQL-Fehler
abbrechen, während `checkin_source` das abfängt.

**Zu tun:** Denselben Guard (Abfrage gegen `INFORMATION_SCHEMA.COLUMNS`, `ALTER TABLE` nur
bei Bedarf) für `device_type` und `work_sessions.source` ergänzen.

---

### OI-29 · `devices.js` Altlasten
**Priorität:** niedrig

Mehrere kleine, voneinander unabhängige Funde in `public/js/modules/devices.js`:

- Fünf `getElementById`-Aufrufe ohne zugehöriges Markup: `devicesPagination`,
  `filterDeviceRole`, `filterDeviceStatus`, `filterGroup`, `resetDeviceFilters`.
- `device_name` fließt ungeschützt in `innerHTML`/`onclick` ein — ein Apostroph im Namen
  bricht den generierten `onclick`-Handler des Lösch-Buttons.
- `showDeviceSection(true, page)` ignoriert den übergebenen `page`-Parameter.
- Dieselbe Lücke besteht in der Mitgliederliste: Namen werden ohne `escapeHtml()`
  interpoliert.

**Zu tun:** Tote `getElementById`-Aufrufe entfernen oder das fehlende Markup ergänzen,
`device_name` und Mitgliedsnamen konsequent über `escapeHtml()` führen, `page` in
`showDeviceSection()` auswerten oder den Parameter streichen.

---

### OI-30 · `totp_location` ohne Secret aus der Zeit vor 1.3.0
**Priorität:** niedrig

Vor 1.3.0 konnte `clear` ein TOTP-Stationsgerät ohne Secret zurücklassen. Ein solches Gerät
fällt in `resolveTotpLocation()` heute stillschweigend heraus, ohne dass die Geräteliste
darauf hinweist — für den Betrieb unauffällig, aber schwer zu erklären, wenn eine Station
plötzlich keine Codes mehr annimmt.

**Zu tun (optional):** Warnhinweis in der Geräteliste für ein `totp_location`-Gerät ohne
gesetztes Secret.

---

### OI-31 · `settings.js` prüft PUT-Ergebnisse nicht
**Priorität:** niedrig

Die Speicherschleife in `settings.js` zählt jeden abgesetzten `PUT`-Request als Erfolg und
meldet am Ende „N gespeichert", ohne die Antwort auszuwerten. Scheitert einer der Requests
(z. B. Validierungsfehler einer einzelnen Einstellung), meldet die Oberfläche trotzdem
Erfolg.

**Zu tun:** Antwort jedes `PUT` prüfen und einen Fehlschlag im Toast von den erfolgreichen
Speicherungen unterscheiden.

---

### OI-33 · Keine Quellen-Kennzeichnung bei Arbeitszeit-Sitzungen
**Priorität:** niedrig

Anwesenheits-Datensätze zeigen ihre Quelle als Badge (u. a. „Station (PIN)"), Arbeitszeit-
Sitzungen dagegen nicht: Weder die Dashboard-Ansicht der Zeiterfassung noch der Export
zeigen `source` (`timer`/`manual`/`admin`/`import`/`station`) an. Eine Kiosk-Sitzung ist
bis dahin nur an Start- und Endort (Name der Station) erkennbar, nicht an einem eigenen
Kennzeichen — siehe `DATENSCHUTZ.md` §10.7.

**Zu tun (optional):** Badge oder Spalte für `source` in Zeiterfassungs-Ansicht und -Export.
Bis dahin genügen die Ortsfelder zur Einordnung.

---

### OI-40 · `rate_limits.expires_at` wird nie geschrieben
**Priorität:** niedrig — folgenlos im Betrieb, riskant bei strengerem SQL-Modus

`RateLimiter` schreibt beim Zählen nur `identifier`, `action` und `created_at`
([rate_limiter.php:104](../private/helpers/rate_limiter.php)) und rechnet seine Fenster
ausschließlich über `created_at`. Die Spalte `expires_at` ist im Schema aber `NOT NULL` ohne
Vorgabewert. Jede Zeile trägt deshalb das ungültige Nulldatum `0000-00-00 00:00:00`.

Solange MariaDB nicht im strengen Modus läuft, bleibt das folgenlos. Unter
`STRICT_TRANS_TABLES` — auf manchem Hosting die Voreinstellung — scheitert dagegen **jeder**
Schreibvorgang des Limiters, und damit jeder Anmeldeversuch.

**Zu entscheiden:** Spalte entfernen (sie wird nirgends gelesen) oder beim Einfügen mitfüllen.
Beides braucht eine Migration; die erste Variante ist ehrlicher, weil die Spalte keine
Bedeutung hat.

---

### OI-41 · `checkin_appointment` ist am selben Tag nicht wiederholbar
**Priorität:** niedrig — betrifft nur die Testbarkeit

Die Suite legt ihre Termine mit festen Uhrzeiten am aktuellen Tag an („Nachtrag-Termin" 07:00,
„Frueher Check-in" 04:00, „Spaeter zugeordnet" 13:00 …) und räumt sie nicht wieder ab. Beim
zweiten Lauf am selben Tag liegen zwei gleichartige Termine im Toleranzfenster, die Automatik
trifft den älteren, und der Test „Check-in trifft einen Termin im Toleranzfenster" meldet rot,
obwohl nichts kaputt ist.

Am 2026-09-09 hinterließen zwei Läufe zwölf solcher Termine. Aufgeräumt über den Titelanhang,
den die Suite vergibt: Titel mit einem angehängten 13-stelligen Hex-Wert.

**Zu tun:** Entweder räumt die Suite ihre Termine am Ende ab, oder sie legt sie zu einer
Uhrzeit an, die aus der laufenden Sekunde abgeleitet ist.

Dieselbe Familie wie die Stationssperre in `station_api`, dort am 2026-09-09 behoben: Der Test
„identify mit falscher PIN" schickte eine **feste** unbekannte Mitgliedsnummer
(`'gibt-es-nicht'`). Die Sperre zählt Fehlversuche auch für unbekannte Nummern — absichtlich,
damit sich Nummern nicht durchprobieren lassen —, also sammelte diese eine Zeichenkette über
Läufe hinweg an und der sechste Lauf binnen 15 Minuten bekam `423` statt `401`. Die Nummer
trägt jetzt ein `uniqid()`, wie alles andere in der Suite auch. Sieben Läufe hintereinander
sind seither grün.

---

## Bewusst entschieden — nicht erneut aufmachen

| Thema | Entscheidung | Grund |
|---|---|---|
| Manager sehen alle Sitzungen | Keine Gruppengrenze | Konsistent mit `records`, `exceptions` und `statistics` — dort gibt es sie auch nicht. Eine Grenze nur hier wäre überraschend |
| Pause verlangt nie einen Nachweis | So belassen | Eine Pause ist keine Anwesenheitsbehauptung |
| Kein `force` beim Timer-Start | So belassen | Ein unbelegter Start bei nachweispflichtiger Tätigkeit soll gar nicht erst als Timer laufen; der Weg ist die nachträgliche Erfassung mit Freigabe |
| Kein Segmentmodell für Pausen | So belassen | Nachweise verlangen Dauer, nicht die Lage der Pausen. Nachrüstbar ohne Datenmigration |
| Kein Offline-Betrieb in der PWA | So belassen | Erzeugte Client-Zeitstempel, die als Nachweis wertlos sind |
| Kein PDF-Export | So belassen | Würde eine Bibliothek einschleppen, die das Projekt bewusst nicht hat. Der Bedarf ist seit 1.2.2 über die Druckansicht (`&format=html`) gedeckt: Das PDF entsteht im Druckdialog des Browsers |
| Installer und Update-Assistent werden gesperrt ausgeliefert | So belassen | Ein hochgeladener, aber noch nicht eingerichteter Webspace soll `/install` nicht offen zeigen. Der Freischaltschritt steht für beide in der README; nach dem Lauf sperrt sich jeder Assistent selbst wieder. Die Alternative — ungesperrt ausliefern — nähme dem Ersteinrichter eine Hürde, öffnete aber ein Zeitfenster zwischen Upload und Installation |
| Statistik getrennt von Anwesenheit | Eigener `worktime`-Block | Anwesenheitsquote und geleistete Stunden sind verschiedene Fragen |
| Kiosk-Sperre als Gruppen-DoS | So belassen (E12) | 30 Fehlversuche je Station sperren die ganze Station 15 Minuten — trifft damit alle, die an ihr stempeln wollen, nicht nur den Angreifer. Die Fehlermeldung unterscheidet Gerät und Konto, damit ein gesperrtes Mitglied von einer gesperrten Station unterscheidbar bleibt. Akzeptiert, weil die Alternative — keine Stationssperre — Nummern-Durchprobieren ohne Bremse erlaubt |
| Kiosk: Terminwahl serverseitig, keine Auto-Anlage (E9) | So belassen | Der Kiosk wählt den passenden Termin wie `auto_checkin` serverseitig aus, zeigt keine Terminliste zur Auswahl und legt keinen Termin an. Ein Stempel ohne passenden Termin bekommt nur eine Meldung, keinen Datensatz. Ziel ist ein Stempelvorgang in drei Tipps; die Terminauswahl bleibt der Handy-PWA vorbehalten |
| Kiosk: Notizpflicht entfällt (P1) | So belassen | Der Kiosk hat keine Tastatur für Fließtext. Ist `worktime_require_note` aktiv, verlangt ein Stopp über `station` trotzdem keine Notiz; die Tätigkeitsart bleibt die Beschreibung |
| Token im Fragment, nicht im Query (ab 1.5.0) | So belassen | `…/station/#t=<token>` statt `?t=`: Das Fragment wird vom Browser nie gesendet und steht damit in keinem Zugriffsprotokoll, keinem Referrer und keinem Reverse-Proxy-Log. Ein Query-Parameter landet in jedem davon. Die Station entfernt den Hash nach der Übernahme per `replaceState`, damit er auch nicht im Verlauf bleibt |
| QR-Übernahme überschreibt still (ab 1.5.0) | So belassen | Wer den Code vor das Tablet hält, steht physisch davor — dieselbe Schwelle wie beim Einstellungsdialog der Station. Eine Rückfrage kostet in der Demo bei jedem stündlichen Reset einen zusätzlichen Tipp und schützt vor nichts, was nicht schon durch den physischen Zugang gedeckt wäre |
| Der Scan-Reload unterbricht auch eine laufende Eingabe (ab 1.5.0) | So belassen | Der `hashchange`-Zuhörer lädt neu, ohne zu prüfen, ob gerade jemand Mitgliedsnummer oder PIN tippt — die Eingabe ist dann weg. Die Alternative, den Reload auf Ruhebild und Einrichtung zu beschränken, holt den Fehler zurück, den er behebt: Ein Scan täte dann in genau diesem Zustand wieder sichtbar nichts. Eine verlorene Eingabe kostet zwei Tipps und ist selbsterklärend; ein folgenloser Scan ist es nicht. Es geht dabei nichts verloren, was schon gespeichert wäre — gestempelt wird erst nach der PIN-Prüfung |

---

## Historie der Korrekturen

Punkte, bei denen eine frühere Einschätzung revidiert wurde — als Warnung vor demselben Irrtum:

- **R1 „entschärft" war zu früh.** Am 2026-09-01 als geprüft vermerkt, nachdem Anlegen und
  Sperrwirkung des Unique-Index auf der virtuellen Spalte funktionierten. Nicht geprüft war das
  Verhalten nach einem Neustart. Am Folgetag trat [OI-1](#oi-1) auf. Ein sauberer Neustart hat
  die Konstruktion inzwischen entlastet, die Ursache bleibt offen.
- **Gruppengrenze für Manager gab es nie.** Die Spec berief sich auf
  `hasStatisticsGroupAccess()`; diese Funktion liefert für Admin **und** Manager `true` und
  begrenzt nur einfache Nutzer.
- **Der Service Worker cacht nichts.** Seine Caching-Logik ist auskommentiert. Veraltete Assets
  kamen von gewöhnlichem HTTP-Caching.
- **`location_name` war immer `NULL`.** Der Bestandscode las `users.email` von Gerätekonten —
  ein Feld, das die Check-Constraint auf `NULL` zwingt.

### OI-44 · Demo: gespeichertes XSS zwischen zwei Resets
**Priorität:** niedrig · bewusst in Kauf genommen

Der Demo-Modus lässt Schreibzugriffe auf Mitglieder, Termine, Anwesenheiten, Anträge und
Arbeitszeiten zu — das ist sein Zweck. Was ein Besucher dabei in ein Freitextfeld schreibt,
bekommt bis zum nächsten Reset jeder weitere Besucher zu sehen. Die Oberfläche nutzt
Inline-Handler und führt bewusst keine CSP (siehe [OI-17](#oi-17)).

Beim Entwurf am 2026-09-09 erwogen und für die Ausbaustufe „Sandkasten mit Grenzen"
hingenommen. Die Alternative wäre eine reine Schaufenster-Demo gewesen, die weder Check-in
noch Zeiterfassung zeigen kann — also genau die Funktionen, für die die Werbeseite gebaut
wurde. Der stündliche Reset begrenzt die Wirkung zeitlich, hebt sie nicht auf.

**Betroffen ist ausschließlich die Demo-Installation mit erfundenen Daten.** Eine
Vereinsinstallation setzt `DEMO_MODE` nicht und ist unberührt; dort schützt weiterhin die
normale Rechteprüfung, die einen anonymen Besucher gar nicht erst schreiben lässt.

Fällt OI-17, fällt dieser Punkt mit.

---

### OI-48 · Statistik zählt je Gruppe nur **eine** Terminart
**Priorität:** erledigt am 2026-09-11 — alle Terminarten einer Gruppe werden jetzt ausgewertet,
Kopfzahlen sind entdoppelt; Fundstellen: `private/helpers/attendance.php` (neu, trennt holende
von formenden Funktionen), `private/handlers/statistics.php`,
`private/handlers/report_statistics.php`, `public/js/modules/statistics.js`, Tests in
`tests/suites/statistics_unit.php`, `tests/suites/report_unit.php` und
`tests/suites/report_api.php`

`calculateGroupStatistics()` in `private/handlers/statistics.php` ermittelt die Terminart einer
Gruppe so:

```php
SELECT atg.type_id, mg.group_name
FROM {PREFIX}appointment_type_groups atg
JOIN {PREFIX}member_groups mg ON atg.group_id = mg.group_id
WHERE atg.group_id = ?
```

und liest davon **eine** Zeile (`fetch()`). Die gesamte folgende Auswertung filtert dann auf
`WHERE a.type_id = ?`.

`appointment_type_groups` ist aber eine M:N-Tabelle. Sie beantwortet die Frage „welche Gruppen
betrifft diese Terminart" — in der Gegenrichtung gelesen hängt eine Gruppe damit
selbstverständlich an mehreren Terminarten. Genau so ist der Bestand aufgebaut.

**Wirkung im Demo-Datenbestand (Stand 2026-09-10):**

| Gruppe | verknüpfte Terminarten | in der Statistik gezählt |
|---|---|---|
| Aktive | Gesamtprobe, Registerprobe, Auftritt | nur eine davon |
| Jugend | Gesamtprobe, Registerprobe, Auftritt | nur eine davon |
| Vorstandschaft | Vorstandssitzung | vollständig |

Für 2026 sind das 37 gezählte gegenüber 61 erfassten Terminen — **24 Termine, knapp 40 %,
bleiben unsichtbar**. Die Abfrage trägt kein `ORDER BY`; welche Terminart gewinnt, entscheidet
die Datenbank.

Die Quoten sind dadurch nicht in sich falsch, sie beantworten nur eine engere Frage als die,
die die Oberfläche stellt: nicht „wie zuverlässig erscheint dieses Mitglied", sondern „wie
zuverlässig erscheint es bei einer nicht näher bestimmten der ihm zugeordneten Terminarten".

**Wie es aufgefallen ist:** Beim Bau des Anwesenheitsberichts (1.5.0) stand die Spalte
„Entschuldigt" für jedes Mitglied und jedes Jahr auf null. Der einzige verwertbare
`excused`-Eintrag im Bestand hängt an einer Registerprobe — einer Terminart, die für die Gruppe
des Mitglieds nicht ausgewertet wird.

**Dass die Summenbildung in `buildStatisticsResult()` doppelte Terminarten über
`$countedAppointmentTypes` entschärft, zeigt die ursprüngliche Annahme:** je Gruppe genau eine
Terminart. Das Datenmodell hat diese Annahme nie getragen.

**Zu tun:** `calculateGroupStatistics()` muss alle Terminarten einer Gruppe auswerten
(`fetchAll()` statt `fetch()`, `IN (…)` statt `= ?`), und die Terminzählung in
`buildStatisticsResult()` muss entsprechend nachziehen — `$stats['appointment_type_id']` ist
dann kein einzelner Wert mehr.

**Vorher zu entscheiden, deshalb nicht nebenbei behoben:** Die Korrektur verschiebt **jede
bestehende Anwesenheitsquote in jeder Installation**, in unvorhersehbare Richtung — je nachdem,
wie diszipliniert bei den bisher ignorierten Terminarten erfasst wurde. Das braucht eine eigene
Spec, eine Aussage im Changelog und vermutlich einen Hinweis für Vereine, die ihre Zahlen über
Jahre verfolgen.

**Bis dahin** benennt der Anwesenheitsbericht (1.5.0) in einer Fußnote je Gruppe die Terminart,
über die gerechnet wurde. Das Blatt behauptet damit keine Vollständigkeit, die es nicht hat.

**Nicht sicherheitsrelevant:** ohne vorherigen Zugang nicht auslösbar, keine Rechteausweitung,
keine Preisgabe fremder Daten. Betroffen ist allein die Aussagekraft der Zahlen.

**Umsetzung vom 2026-09-11.** Die Rechnung liegt jetzt in einem eigenen Helfer
(`private/helpers/attendance.php`), getrennt in holende Funktionen (SQL) und formende Funktionen
(reine Funktionen ohne Datenbankzugriff) — Letztere sind ohne Datenbank prüfbar. Die Entdopplung
über zwei Gruppen hinweg ist durch einen eigens dafür geschriebenen HTTP-Test belegt, weil dieser
Fall im bestehenden Datenbestand nicht vorkommt. **Die oben genannten Zahlen (37 von 61) sind
stichtagsabhängig** und bezeichnen den Stand vom 10.09.2026; am 11.09.2026 waren es bereits 47
von 71 — jeden Tag rutschen weitere Termine in die Zählung, weil nur bereits begonnene Termine
zählen. Konstant ist allein die Differenz von 24 Terminen.

---

### OI-49 · Demo-Generator legt genehmigte Entschuldigungen ohne Anwesenheitseintrag an
**Priorität:** niedrig — betrifft nur die Demo- und Testdaten

Im Bestand vom 2026-09-10 stehen 14 genehmigte Ausnahmen vom Typ `absence`, aber nur **eine**
davon hat einen zugehörigen `records`-Eintrag mit `status = 'excused'`. Die übrigen 13
Mitglieder erscheinen in der Statistik als **unentschuldigt** — das Gegenteil dessen, was die
Demo erzählen will.

Der Produktivpfad ist in Ordnung: `handleApprovedAbsence()` in `private/helpers/utils.php` legt
den Eintrag beim Genehmigen korrekt an. Der Demo-Generator schreibt die Ausnahmen jedoch direkt
in die Tabelle und geht an dieser Funktion vorbei.

**Zu tun:** Der Generator legt für jede genehmigte `absence` zusätzlich den `records`-Eintrag
mit `status = 'excused'` an — oder er ruft beim Erzeugen denselben Weg wie die Oberfläche.

**Am Rande aufgefallen:** `handleApprovedAbsence()` arbeitet mit `INSERT IGNORE`. Existiert
bereits ein Eintrag — etwa weil das Mitglied vorher als anwesend erfasst wurde —, verpufft die
Genehmigung wirkungslos, und das Mitglied bleibt „anwesend". `handleApprovedTimeCorrection()`
aktualisiert in derselben Lage einen vorhandenen Eintrag. Ob dieser Unterschied Absicht ist,
ist ungeklärt; er ist von diesem Punkt getrennt zu bewerten.

---

### OI-50 · `my_data` als CSV enthält keine Arbeitszeiten
**Priorität:** mittel — betrifft das Auskunftsrecht, nicht die Sicherheit

`?resource=my_data` gibt einer angemeldeten Person ihre eigenen Daten heraus. Der Handler holt
dabei auch `work_sessions` und `work_session_log` (`private/handlers/my_data.php`, ab der
Sitzungsabfrage), und die **JSON**-Form gibt beides vollständig aus.

`exportAsCSV()` in derselben Datei schreibt dagegen nur vier Blöcke:
`=== STAMMDATEN ===`, `=== GRUPPEN ===`, `=== ANWESENHEITEN ===` und
`=== AUSNAHMEN/ANTRÄGE ===`. **Die Arbeitszeitsitzungen fehlen**, obwohl sie im Datensatz
stehen, den die Funktion entgegennimmt.

Zwei Formate desselben Auskunftsersuchens liefern damit unterschiedlich viel. Wer die CSV wählt
— das naheliegende Format für jemanden, der seine Daten in einer Tabelle ansehen will —,
bekommt einen unvollständigen Auszug, ohne dass irgendwo steht, dass etwas fehlt.

**Aufgefallen am 2026-09-10** beim Review der Berichtsrechte: Dort wird der Rolle `user` das
CSV des Stundennachweises verweigert, mit der Begründung, der Selbstexport über `my_data` decke
das bereits ab. Für die CSV-Form stimmt das nicht.

**Zu tun:** `exportAsCSV()` um einen Block `=== ARBEITSZEITEN ===` ergänzen — Beginn, Ende,
Pause, Dauer, Tätigkeit, Termin, Status, Nachweisgrad. Die Aufbereitung dafür gibt es bereits in
`private/handlers/export.php` (`worktimeHours()`, `worktimeProofLabel()`,
`worktimeReportTimes()`); sie ist nicht neu zu erfinden.

**Zu prüfen dabei:** ob auch `work_session_log` in die CSV gehört. Die Änderungshistorie ist
Teil dessen, was über eine Person gespeichert ist; in der JSON-Form steht sie drin.

**Berührt** `DATENSCHUTZ.md`: Dort ist zu prüfen, ob die Beschreibung des Auskunftswegs die
beiden Formate als gleichwertig darstellt. Falls ja, ist sie bis zur Behebung ungenau.

**Nicht sicherheitsrelevant:** Es werden keine fremden Daten preisgegeben, sondern eigene
zurückgehalten. Kein Zugang, keine Rechteausweitung.

---

### OI-51 · Pünktlichkeit wird beworben, aber nirgends ausgewertet
**Priorität:** hoch — eine Zusage, die das Projekt an vier Stellen macht und an keiner einlöst

**Entwurf liegt vor (2026-09-11):**
`docs/superpowers/specs/2026-09-11-puenktlichkeit-und-zuverlaessigkeit-design.md`. Er entscheidet
die unten offenen Punkte und **revidiert zwei der Vorentscheidungen** — statt des Medians eine
Quote als Leitzahl (Abschnitt 3.1), und statt der Spalte `arrival_measured` eine Ankunftszeit, die
`NULL` sein darf (Abschnitt 4.1). Die Spec ist maßgeblich; die Punkte unten bleiben stehen, damit
die Revision nachvollziehbar ist.

Die Umsetzung ist in zwei Schritte geteilt.

**Schritt 1 (Datenmodell) ist umgesetzt und liegt in 1.5.0.** `records.arrival_time` darf leer
sein, statt die Startzeit des Termins zu behaupten; ein genehmigter Zeitkorrektur-Antrag trägt
`checkin_source = 'exception_request'`; Jahresauswahl und Löschfrist rechnen über
`appointments.date`. Die Spalte `arrival_measured` aus den Vorentscheidungen wird damit nicht
gebraucht.

**Schritt 2 (die Kennzahlen selbst) ist offen** — Quote, Verspätungsmaß, Zuverlässigkeit,
Einstellungen, Anzeige und der Abschnitt in `DATENSCHUTZ.md`. Die Sperre durch
[OI-48](#oi-48--statistik-zählt-je-gruppe-nur-eine-terminart) ist seit dem 2026-09-11 aufgehoben.

`README.md` schreibt „Inklusive Ankunftszeit, für alle die Pünktlichkeit belohnen wollen".
`CLAUDE.md` beschreibt das Projekt als „Statistische Auswertung von Anwesenheit **und
Pünktlichkeit**". `API.md` führte bis 2026-09-10 die Antwortfelder `late_count` und
`avg_arrival_minutes` auf. Die Werbeseite nennt es ebenfalls.

**Im Code gibt es davon nichts.** `records.arrival_time` wird erfasst und — außer zur
Terminzuordnung im Toleranzfenster — nirgends verwendet. Keine Verspätung wird berechnet, keine
Quote gebildet, keine Kennzahl ausgegeben.

Die falschen Felder sind am 2026-09-10 aus `API.md` entfernt worden; eine Referenz, die
Nichtvorhandenes beschreibt, ist schlimmer als eine Lücke. Die Zusage selbst bleibt offen.

**Was vorher entschieden werden muss — und warum es nicht einfach „nachgebaut" werden kann:**

`arrival_time` ist keine verlässliche Messung. Legt ein Admin einen Anwesenheitseintrag ohne
Uhrzeit an, setzt `private/handlers/records.php` die **Startzeit des Termins**. Der Datensatz ist
damit konstruiert pünktlich. In vielen Vereinen dürfte das die Mehrheit sein — wer eine Liste
abhakt, tippt keine Uhrzeiten. Eine Pünktlichkeitsquote über diesen Bestand läge nahe 100 % und
wäre Fiktion.

| `checkin_source` | Herkunft der Zeit | Messung? |
|---|---|---|
| `station_pin`, `device_auth`, `user_totp` | Serverzeit bei der Authentifizierung | ja, belastbar |
| `auto_checkin` | vom Client mitgeschickt | ja, aber fremde Uhr |
| `admin` ohne mitgegebene Zeit | **Startzeit des Termins** | nein |
| `import` | aus der CSV | unbekannte Güte |
| `timer` | historisch `NOW()`; erzeugt seit 1.2.3 keine Einträge mehr | nein — misst Arbeitsbeginn, nicht Ankunft |

**Vorentscheidungen, beim Entwurf der Berichte am 2026-09-10 getroffen** (ausführlich in
`docs/superpowers/specs/2026-09-10-berichte-statistik-und-nutzerrolle-design.md`, Abschnitt 11) —
Punkt 1 und der Median in Punkt 2 sind durch den Entwurf vom 2026-09-11 überholt, siehe oben:

1. **Herkunft dauerhaft speichern**, nicht heuristisch ableiten: eine Spalte `arrival_measured`
   in `records`, von jedem Schreibpfad gesetzt, per Migration rückwirkend befüllt. Der
   Anwesenheitsbericht (1.5.0) leitet sie bis dahin aus `checkin_source` ab — das ist die
   Übergangslösung, nicht das Ziel.
2. **Zwei getrennte Kennzahlen, niemals multipliziert.** Ein zusammengerechneter „Score"
   verbirgt, was tatsächlich passiert ist, und lädt zum Missbrauch ein.
   - *Pünktlichkeit* nur über gemessene Ankünfte: Quote innerhalb einer Karenz plus der
     **Median** der Verspätung — der Mittelwert kippt bei einem einzigen Ausreißer. Immer mit
     Bezugsgröße; unter fünf Messungen keine Quote, sondern „zu wenige Messungen".
   - *Zuverlässigkeit* über alle Termine, mit drei Ausgängen: **erschienen**, **abgemeldet**
     (Ausnahme vor Terminbeginn angelegt, ablesbar an `exceptions.created_at`), **ausgefallen**.
     Quote = (erschienen + abgemeldet) / Termine. Die heutige Anwesenheitsquote bestraft eine
     rechtzeitige Absage wie unentschuldigtes Fehlen; das ist der Punkt, den sie verfehlt.
3. **Eigene Einstellung für die Karenz**, Vorgabe 5 Minuten. `checkin_tolerance_hours` wird
   **nicht** wiederverwendet: Die zwei Stunden dort sind das Fenster für die *Terminzuordnung*.
   Wer 90 Minuten zu spät kommt, wird korrekt zugeordnet und ist trotzdem zu spät.
4. **Vor der Umsetzung** gehört eine personenbezogene Verhaltenskennzahl nach `DATENSCHUTZ.md` —
   Zweck, Aufbewahrung, Sichtbarkeit für andere Rollen. Eine Zahl, die aussagt, wie verlässlich
   ein einzelnes Mitglied ist, ist etwas anderes als eine Anwesenheitsliste.

**Zusammenhang mit [OI-48](#oi-48):** Solange die Statistik je Gruppe nur eine Terminart
auswertet, würde eine Pünktlichkeitsquote denselben Ausschnitt erben. OI-48 gehört davor.

---

### OI-52 · Anwesenheitsbericht kennt nur ganze Jahre
**Priorität:** niedrig · bewusst verschoben

Der Anwesenheitsbericht (1.5.0) übernimmt die Filter der Statistik-Sektion: Jahr, Gruppe,
Mitglied. Einen freien Zeitraum wie der Arbeitszeitbericht (`from`/`to`, höchstens 24 Monate)
kennt er nicht.

Das ist keine Nachlässigkeit, sondern eine Entscheidung beim Entwurf am 2026-09-10: Die Statistik
rechnet durchgehend über `YEAR(a.date) = ?`, und die Mitgliedschaftszeiträume kommen über
`getMemberActivityWhereYear()` dazu. Ein freier Zeitraum würde `calculateGroupStatistics()`, die
Terminzählung und die Aktivitätsprüfung umbauen — ein Eingriff in Zahlen, die Vereine seit Jahren
kennen, für einen Bedarf, den niemand geäußert hat.

Solange der Bericht dieselben Filter benutzt wie der Bildschirm, können beide sich nicht
widersprechen. Das ist der eigentliche Gewinn der Beschränkung.

**Zu tun, falls der Bedarf entsteht:** Monat, Quartal oder Vereinsjahr auswerten zu können, hieße
die Jahresbasis der gesamten Statistik aufzugeben — nicht nur die des Berichts. Dann besser
gemeinsam mit [OI-48](#oi-48) entscheiden, das ohnehin an derselben Funktion ansetzt.

---

### OI-53 · Navigation im Querformat auf dem Telefon kaum bedienbar
**Priorität:** erledigt am 2026-09-11 — Ursache war eine andere als hier vermutet, siehe unten

**Gemeldet am 2026-09-10** vom Betreiber: In der mobilen Ansicht im **Querformat** ist das
Navigationsmenü zu klein und lässt sich nicht bedienen.

**Nachgestellt am 2026-09-11** in der laufenden Instanz, und der Befund fiel deutlicher aus als
die Meldung: Das Menü war nicht zu klein, es war **nicht vorhanden**. Gemessen bei 667x375 mit
aufgeklappter Leiste — Kopfbereich 213 px, Reiter 33 px, Fußbereich 145 px, zusammen 391 px in
einem 375 px hohen Fenster. `.nav-menu` trägt `flex: 1`, bekommt also den Rest, und der ist
null. Die sieben Einträge zu je 56 px waren im Baum vorhanden und auf 0 px Höhe zusammengelegt.

Die hier notierte Vermutung — Sprung über den Breiten-Haltepunkt, dadurch winzige Trefferflächen
— war **zur Hälfte richtig und zur Hälfte irreführend.** Richtig war der Haltepunkt: Quer ist ein
Telefon 844 bis 926 px breit, die Mobilregel greift nicht, die Leiste steht fest im Layout; dort
kollabierte dieselbe Liste auf 17 px. Irreführend war „winzige Trefferflächen": Die Einträge
behalten ihre 56 px, sie bekommen nur keinen Platz. Wer nur den Haltepunkt erweitert hätte,
hätte einen Menüknopf gebaut, der eine leere Leiste aufklappt.

**Dritte Ursache, hier nicht vermutet:** Über die Sichtbarkeit des Menüknopfs entscheidet gar
nicht das Stylesheet, sondern ein Inline-Style aus `updateMobileMenuVisibility()`
(`public/js/modules/ui.js`), der `window.innerWidth <= 768` selbst prüft. Inline-Styles schlagen
jede CSS-Regel — die Regeln für `.mobile-menu-btn` in `responsive.css` waren wirkungslos, und die
Schwelle stand ein zweites Mal im JavaScript.

**Umgesetzt:**

1. Eigener Block `@media (max-height: 500px)` in `public/css/responsive.css`: Menüknopf,
   ausfahrbare Leiste, Inhalt über die volle Breite. Bewusst ein eigener Block statt einer
   Komma-Erweiterung des 768-px-Blocks — dessen übrige Regeln (Statistik einspaltig,
   Filterleiste gestapelt) wären auf einem breiten, flachen Fenster eine Verschlechterung.
2. Im selben Block behält die Liste ihre Höhe (`flex: none`), und die Leiste scrollt als Ganzes
   (`overflow-y: auto`). Kopf- und Fußbereich sind dort kompakt, damit nach dem Aufklappen
   sofort Einträge zu sehen sind; die Trefferfläche bleibt bei 50 px.
3. `ui.js` wertet über `matchMedia` **dieselbe** Bedingung aus wie das Stylesheet. Die Schwelle
   steht weiterhin zwangsläufig zweimal da — ein Test hält die beiden deckungsgleich.
4. `.sidebar` rechnet mit `100dvh` als Nachzug zu `100vh`; ältere Browser überlesen die Zeile.
5. `@media (min-width: 1200px)` trägt jetzt `and (min-height: 501px)`. **Das war die Falle:**
   Der Block steht in der Datei nach der neuen Regel und hätte bei einem flach gezogenen
   Fenster gewonnen — Leiste ausgefahren, Menüknopf versteckt, Inhalt mit 250 px Rand ins Leere.

**Gegenproben** in der laufenden Instanz, jeweils frisch geladen: 844x390 und 667x375 (Telefon
quer) zeigen Menüknopf und eine Liste von 347 px, drei Einträge ohne Scrollen sichtbar, der
Abmelden-Knopf über die scrollende Leiste erreichbar. 1024x768 (Tablet quer) behält Leiste und
250 px Inhaltsrand, kein Menüknopf. 1400x450 (flach gezogenes Fenster) verhält sich mobil,
1400x900 und 390x844 sind unverändert. Beim Flachziehen ohne Neuladen schaltet der
`resize`-Handler den Knopf korrekt ein.

**Nicht Teil dieses Punktes, dabei aufgefallen:** Der Menüknopf liegt als `position: fixed` bei
15/15 über dem Vereinsnamen in der aufgeklappten Leiste — im Hochformat genauso wie im
Querformat. Ein Anzeigefehler, keine Bedienhürde, und älter als dieser Punkt.

**Berührt:** `public/css/responsive.css`, `public/css/sections/sidebar.css`,
`public/js/modules/ui.js`, `tests/suites/responsive_nav.php`. Die Kiosk- und die Check-in-PWA
sind **nicht** betroffen — beide laden ein eigenes `css/style.css` und erben diese Regeln nicht.

**Nicht sicherheitsrelevant.**

---

### OI-54 · PUT auf Terminarten überschreibt nicht mitgeschickte Felder
**Priorität:** mittel — führt zu Datenverlust, ist aber nur von einem angemeldeten Admin
auslösbar, und die eigene Oberfläche schickt derzeit bei jedem PUT ohnehin alle Felder mit

`handleAppointmentTypes()` in `private/handlers/appointment_types.php:110` schreibt bei `PUT`
alle vier Grundfelder bedingungslos, ohne zu prüfen, ob sie im Request überhaupt enthalten
waren:

```php
$stmt = $db->prepare("UPDATE {$prefix}appointment_types
                      SET type_name = ?, description = ?, is_default = ?, color = ?
                      WHERE type_id = ?");
$stmt->execute([
    $data->type_name,
    $data->description ?? null,
    $data->is_default ?? false,
    $data->color ?? '#667eea',
    $id
]);
```

Ein PUT, das nur `color` ändern will, verliert dadurch `description` (wird `null`) und
`is_default` (wird `false`); fehlt `type_name` ganz, scheitert die Abfrage sogar, weil die
Spalte `NOT NULL` ist. Es gibt keine `isset`-Prüfung je Feld — das PUT ist eine stille
Vollersetzung, obwohl nichts in `API.md` das ausweist.

**Im Gegensatz dazu `handleMembers()`** (`private/handlers/members.php`, ab Zeile 367): Dort
baut ein dynamisches `UPDATE` das `SET` ausschließlich aus den tatsächlich gelieferten Feldern
(`$updatable` plus `isset()`-Prüfung je Feld). Zwei Ressourcen desselben Projekts mit
gegensätzlichem PUT-Verhalten sind für jeden Verbraucher eine Falle — was bei der einen
Ressource ein harmloses Teil-Update ist, löscht bei der anderen still Daten.

**Nachgeprüft, ob weitere Handler dasselbe Muster zeigen:** `private/handlers/activity_types.php`
ist derselbe Fall. Der `PUT`-Zweig (ab Zeile 286) schreibt `activity_name`, `description`,
`color`, `is_default`, `is_active` und `verification` ebenfalls bedingungslos in einem
UPDATE-Statement — mit denselben `?? null` / `?? false`-Rückfällen. Einzig `group_ids` und
`appointment_type_ids` sind dort bereits sauber über `isset()` abgesichert und bleiben bei
fehlendem Feld unangetastet (mit Kommentar, der das Verhalten bewusst begründet) — nur die
Grundfelder der Terminart selbst tragen den Fehler. Bemerkenswert: Fehlt `is_active`, fällt es
auf `1` zurück — ein PUT ohne dieses Feld kann eine deaktivierte Tätigkeitsart also still
wieder aktivieren.

**Zu tun:** Entweder ein dynamisches `UPDATE` wie bei `members` (nur gelieferte Felder
schreiben), oder das heutige Verhalten in `API.md` ausdrücklich als Vollersetzung dokumentieren
— beides ist vertretbar, es muss aber **eines** von beiden sein, statt der stillen Lücke, die
heute besteht.

**Wie es aufgefallen ist:** beim Schreiben des Entdopplungstests für OI-48 — ein PUT, das
zunächst nur `group_ids` schickte, setzte `type_name` auf `NULL` und scheiterte an der
`NOT NULL`-Spalte. `description` und `is_default` wären ohne diesen Fehlschlag still verloren
gegangen.

**Nicht sicherheitsrelevant:** setzt ein angemeldetes Adminkonto voraus und erweitert keine
Rechte — es zerstört nur Daten, die derselbe Admin ohnehin ändern dürfte.

---

### OI-55 · Farbschwellen der Anwesenheitsquote sind fest verdrahtet

**Priorität:** niedrig — die Zahlen bleiben richtig, nur ihre Einfärbung ist eine Vorgabe

Die Statistiktabelle färbt jeden Quotenbalken nach vier Bändern ein, gesetzt in `rateBand()`
in `public/js/modules/statistics.js`:

| Band | Quote | Farbe |
|---|---|---|
| `rate-low` | unter 40 % | Rot |
| `rate-mid` | 40 bis 59 % | Orange |
| `rate-fair` | 60 bis 79 % | Gelb |
| `rate-good` | 80 % und mehr | Grün |

**Das ist ein Urteil darüber, was gute Anwesenheit ist** — und es fällt je nach Organisation
verschieden aus. Ein Blasorchester mit wöchentlicher Probe bewertet 65 % anders als eine
Feuerwehr mit Monatsdienst oder ein Verein, dessen Mitglieder berufsbedingt schichten. Fest
verdrahtet gibt EhrenSache jedem Verein dieselbe Meinung vor und färbt Mitglieder rot, die
nach dem Maßstab ihres Vereins unauffällig sind.

**Warum die Schwellen trotzdem nicht bei 20er-Schritten liegen:** Die naheliegende Einteilung
(20/40/60/80) wurde verworfen, weil die realen Quoten überwiegend oberhalb von 50 % liegen.
Eine Skala, die genau dort nicht mehr unterscheidet, wo die Daten sich sammeln, hilft beim
Überfliegen nicht. 40/60/80 verteilt den vorhandenen Bestand über alle vier Bänder.

**Zu tun:** Die drei Schwellen als Systemeinstellung führen (`system_settings`, analog zu den
übrigen Darstellungsoptionen), mit den heutigen Werten als Vorgabe. Die Farben selbst sollten
nicht mitkonfigurierbar sein — Rot für „schlecht" ist eine Konvention, an der zu drehen mehr
schadet als nützt.

**Vorher zu klären:** ob die Schwellen je Terminart gelten sollen. Für einen Auftritt ist eine
andere Erwartung angemessen als für eine Registerprobe, und die Statistik weist beide
inzwischen getrennt aus. Das spricht für Schwellen je Terminart — kostet aber eine
Zuordnungstabelle statt dreier Zahlen.

**Wie es aufgefallen ist:** bei der Überarbeitung der Statistiktabelle. Der Vorgänger war ein
Farbverlauf von Rot nach Grün, den eine Maske beschnitt — dort begann *jeder* Balken bei Rot,
auch der eines Mitglieds mit 92 %. Die Farbe trug damit keine Information. Beim Ersetzen durch
eine Skala nach Wertebereich wurde die Vorgabe überhaupt erst zu einer Aussage.

**Nicht sicherheitsrelevant:** reine Darstellung, keine Datenänderung, kein Rechtebezug.

---
