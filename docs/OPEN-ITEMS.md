# Offene Punkte — EhrenSache

Sammelstelle für Funde, offene Entscheidungen und Restarbeiten. Ergänzt die Spezifikationen
unter `docs/superpowers/specs/`, ersetzt sie nicht: Was hier steht, ist noch nicht entschieden
oder noch nicht gebaut.

**Zuletzt geprüft:** 2026-09-17 · **Bezugsstand:** `dev`, Fehlerkorrekturen für 1.9.1 ·
**Version:** 1.9.1

> **Diese Angabe ist Teil der Pflege, nicht Zierde.** Am 2026-09-17 stand hier noch 1.7.0,
> während der Code auf 1.9.0 war — fünf Punkte waren längst behoben, ohne dass ihr Eintrag es
> sagte. Wer aus dieser Datei heraus priorisiert, plant dann Arbeit ein, die schon getan ist.
> **Deshalb: jeden Punkt vor der Umsetzung gegen den Code prüfen, nicht gegen diese Datei**,
> und `git log --since=<letztes Prüfdatum>` lesen — dort stehen die Korrekturen paralleler
> Sitzungen unter ihren eigenen `fix(...)`-Titeln.

> **Diese Datei ist öffentlich.** Sie liegt seit 2026-09-02 im Repository (siehe
> [OI-14](#oi-14--dokumentation-liegt-unversioniert)). Was hier steht, kann jeder lesen — die Grenze für sicherheitsrelevante
> Einträge regelt der Abschnitt [Sicherheit](#sicherheit) und `SECURITY.md`.

**Priorität:** *hoch* = blockiert einen Merge nach `main` oder den produktiven Einsatz ·
*mittel* = sollte vor der Freigabe an Vereine gelöst sein · *niedrig* = Verbesserung

**Nächste Umsetzung (Stand 2026-09-22, aus dem Test vom 21.09.):** Die drei Fehlerkorrekturen
[OI-83](#oi-83--arbeitszeit-mit-ortsnachweis-ohne-kamera-nicht-startbar),
[OI-84](#oi-84--leeres-filterergebnis-lässt-die-alte-paginierung-stehen) und
[OI-82](#oi-82--nachträglicher-zeitantrag-auch-für-termine-in-der-zukunft) sind am selben Tag auf
`dev` erledigt und mit 1.11.2 veröffentlicht.

[OI-85](#oi-85--keine-ladeanzeige-und-kein-timeout-bei-langsamen-api-antworten) ist erledigt (nach 1.12.0).
[OI-86](#oi-86--bunte-status-filterknöpfe-nur-in-der-benutzerverwaltung) ist vertagt: Er geht in einem
Bedienkonzept für einheitliche, schlankere Filter auf, das zuerst in einem Brainstorming entsteht. Wie jeder Eintrag hier gilt
auch diese Liste nur bis zur Prüfung gegen den Code.

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

**Offen geblieben:** [OI-22](#oi-22--selbstauskunft-findet-verwaiste-logzeilen-nicht) — verwaiste Einträge erscheinen bis zum Ablauf ihrer Frist
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

**Dieselbe Lücke an einer weiteren Stelle:** Der in [OI-35](#oi-35--pwa-arbeitszeit-korrigieren-und-nachtragen) geplante
Korrekturweg der PWA hat denselben Effekt — korrigiert ein Manager die eigene Sitzung, bleibt sie
`confirmed`, ohne dass jemand anderes zustimmt.

**Vorgeschlagene Lösung (2026-09-22, zu entscheiden):** Die Variante oben ohne ihren Preis —
eigene Einträge gehen nur dann in die Freigabe, wenn es **ein anderes aktives Konto mit Rolle Admin
oder Manager** gibt (`is_active = 1`, `account_status = active`). Ob dieses Konto mit einem Mitglied
verknüpft ist, spielt keine Rolle: Freigeben braucht nur die Rolle. Ohne zweiten Verwalter bleibt
es beim sofortigen `confirmed`. Dieselbe Regel ist für Anträge vorgesehen, siehe
[OI-87](#oi-87--anträge-in-der-anwesenheitsliste-des-dashboards-selbstgenehmigung-nur-ohne-zweiten-verwalter).
Für die Arbeitszeit bewusst getrennt entschieden: Sie ändert den Arbeitsablauf der Manager spürbar
(eigene Nachträge warten dann auf Freigabe), bei Anträgen kaum.

---

### OI-20 · Auto-Termine zählen weiter in die Statistik
**Priorität:** mittel — bewusst so entschieden am 2026-09-03

Seit 1.2.4 ist die automatische Terminerzeugung abschaltbar und ein erzeugter Termin trägt
`is_auto_created = 1`. **Die Auswertung kennt die Markierung nicht.** Ein Auto-Termin zählt
bei jedem Mitglied der zugehörigen Gruppen als Solltermin; wer nicht eingecheckt hat,
erscheint als unentschuldigt abwesend.

Entschieden wurde: erst sichtbar machen, dann sehen, ob es reicht. Der Filter in der
Terminverwaltung zeigt den Bestand.

**Stand 1.9.2:** Der Filter sitzt jetzt in der Filterleiste der Terminverwaltung und kennt drei
Zustände — alle, nur automatisch erzeugte, nur von Hand angelegte. Die dritte Richtung fehlte
bisher und ist genau die, die der unten angekündigte Bestandsvergleich braucht: Sie zeigt, was
ohne die automatische Erzeugung übrig bliebe. Kalender und Kennzahlen filtern mit.

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
`saveAllSettings()` ([settings.js](../public/js/modules/settings.js)) prüfte mit `parseInt`,
speicherte danach aber den Rohstring — wer `0,5` eintrug, bekam in der Datenbank `"0,5"`
und überall sonst `0`. **Dieser Teilbefund ist seit 1.9.0 behoben:** Zahlenfelder senden den
geprüften Wert. Die Frage Stunden gegen Minuten bleibt offen.

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
stammen (verwandt: [OI-33](#oi-33--keine-quellen-kennzeichnung-bei-arbeitszeit-sitzungen)).

Der Weg steht heute jedem Mitglied offen: „✎ Bearbeiten“ an der eigenen abgeschlossenen
Sitzung im Dashboard. Mit [OI-35](#oi-35--pwa-arbeitszeit-korrigieren-und-nachtragen) käme er zusätzlich in die PWA — deshalb vorher
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
  `end_time`), solange die Auditspur nicht schon gelöscht ist — siehe [OI-2](#oi-2--löschfrist-für-die-änderungshistorie).

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
- **Verhältnis zu [OI-38](#oi-38--die-auditspur-ist-nirgends-zu-sehen).** Wird die Auditspur in der Freigabe sichtbar, gehört sie in
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
     `STORED` um und behebt damit [OI-1](#oi-1--verlorener-auto_increment-nach-crash-recovery). **Er ist Pflicht:** Eine Installation, die
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
**Erledigt am 2026-09-04** — siehe [OI-2](#oi-2--löschfrist-für-die-änderungshistorie), dort zusammengefasst.

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

**Nebenbefund, unabhängig davon:** ~~Der Wizard sperrt sich per `.htaccess` aus, **bevor** er
sein Ergebnis rendert.~~ **Erledigt am 2026-09-22** (Branch `fix/update-htaccess`): Die Sperre
steht jetzt hinter `</html>` am Dateiende, weiterhin nur bei `$migrationOk`. Scheitert das
Rendern, bleibt der Assistent offen und das Ergebnis erreichbar. Test in
`tests/suites/update_wizard.php`. Offen bleibt allein die Entscheidung oben.

**Zweiter Nebenbefund, am selben Tag behoben:** `public/update/.htaccess` stand nach jedem
lokalen Update als geändert in `git status`, bei identischem Inhalt. Ursache: Unter Windows mit
`core.autocrlf=true` steht `index.php` mit CRLF im Arbeitsbaum, das Heredoc der Sperre also auch;
das angehängte `"\n"` ergab gemischte Zeilenenden. Git hält unter `autocrlf` nur reines CRLF für
unverändert. Behoben mit zwei Teilen, die nur zusammen wirken: Beide Generatoren (Assistent und
Installer) schreiben reines LF, und `.gitattributes` legt `eol=lf` für beide Sperrdateien fest.
Tests in `tests/suites/htaccess_locks.php`. Der Installer schreibt bewusst einen anderen
Kommentartext als die ausgelieferte Datei („Installation abgeschlossen“); nach einem lokalen
Installerlauf bleibt `public/install/.htaccess` deshalb als geändert stehen. Das ist gewollt.

---

### OI-22 · Selbstauskunft findet verwaiste Logzeilen nicht
**Priorität:** mittel

`my_data` liest die Änderungshistorie über einen Join auf `work_sessions`
(`private/handlers/my_data.php`). Ist die Sitzung gelöscht, taucht ihre Historie in keiner
Selbstauskunft mehr auf — obwohl der `delete`-Eintrag in `changes` die komplette Sitzung samt
`member_id`, Notiz und Ortsnamen weiterträgt. Für Art. 15 DSGVO ist das eine Lücke.

Mit [OI-2](#oi-2--löschfrist-für-die-änderungshistorie) ist sie zeitlich begrenzt: Nach Ablauf der Auditfrist wird der Eintrag
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

**Ergänzung vom 2026-09-17:** Betroffen ist nicht nur die Arbeitszeit. `openStatisticsReport()`
([statistics.js](../public/js/modules/statistics.js)) öffnet den **Anwesenheitsbericht** ebenfalls
über `window.open` auf die nackte URL — der Punkt oben nennt nur die Arbeitszeit-Berichte und
unterschätzt damit seinen Umfang. Beide sind Druckansichten und führen deshalb in denselben
Fallstrick: Eine `blob:`-URL würde die `<base href="../">` aushöhlen, der Bericht käme ohne
Stylesheet und ohne Vereinslogo. Der Weg bleibt die Vorabprüfung per `fetch` und danach ein
`window.open` auf die echte URL — bewusst zwei Anfragen, jetzt aber für zwei Berichte.

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
  ([OI-21](#oi-21--checkin_tolerance_hours-kennt-nur-ganze-stunden)) —, ist offen.
- **Begründung.** Wer eine bestätigte Zeit ändert, sollte vermutlich sagen warum. Heute gäbe es
  dafür nur das Notizfeld; `work_session_log` hält zwar jede Änderung fest, aber keinen Grund.
- **Berührt [OI-3](#oi-3--vier-augen-prinzip-bei-manager-nachträgen):** Ein Manager, der seine eigene Sitzung über die PWA korrigiert,
  behält `confirmed` — dieselbe Lücke wie beim Nachtrag, nur an einer weiteren Stelle.
- **Voraussetzung [OI-37](#oi-37--ortsnachweis-überlebt-jede-zeitkorrektur):** Eine Zeitkorrektur lässt den Ortsnachweis heute
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
**Priorität:** niedrig — vorgemerkt, entstanden bei der Planung von [OI-35](#oi-35--pwa-arbeitszeit-korrigieren-und-nachtragen)

Jede Änderung an einer Arbeitszeitsitzung wird protokolliert: `logSessionChange()` schreibt
Vorher/Nachher-Werte als JSON in `work_session_log.changes`
([worktime.php:317](../private/helpers/worktime.php)). Angezeigt wird das nirgends. Der einzige
Weg heraus ist die Selbstauskunft — `my_data` gibt die eigenen Logzeilen aus
([my_data.php:150](../private/handlers/my_data.php)) —, und die liest niemand zur Freigabe.

**Folge für die Freigabe.** In der Freigabeliste des Dashboards steht ein Eintrag mit Status
„wartet auf Freigabe". Ob das eine frisch nachgetragene Sitzung ist oder eine bestätigte, deren
Zeiten das Mitglied nachträglich verschoben hat, ist daran nicht zu erkennen. Der Manager gibt
also frei, ohne zu wissen, worüber er entscheidet. Mit [OI-35](#oi-35--pwa-arbeitszeit-korrigieren-und-nachtragen) — Korrigieren und
Nachtragen aus der PWA — wird dieser Fall vom Sonderfall zum Regelfall.

Zwei Teilsignale gibt es bereits: Der Nachweisgrad fällt bei einer Zeitkorrektur auf
„teilbelegt" oder „unbelegt" (sobald [OI-37](#oi-37--ortsnachweis-überlebt-jede-zeitkorrektur) umgesetzt ist), und `source` unterscheidet
`timer` von `manual`. Beides sagt aber nur, *dass* etwas anders ist, nicht *was*.

**Zu klären:**

- **Wie viel gehört in die Liste?** Ein Vermerk „geändert am … von …" mit ausklappbarem
  Vorher/Nachher wäre das Vollbild; ein Badge „geändert" die kleinste brauchbare Stufe.
- **Wer darf die Spur sehen?** Naheliegend Admin und Manager für alle Sitzungen, das Mitglied
  für die eigenen. Letzteres gibt es über `my_data` bereits, nur nicht in der Oberfläche.
- **Neue Ressource oder Erweiterung?** `work_sessions` könnte die Logzeilen bei `GET` mit `id`
  mitliefern; sauberer wäre `work_session_log` als eigene, lesende Ressource. Die Listenansicht
  darf davon nicht langsamer werden.
- **Begründungsfeld.** Bei der Planung von [OI-35](#oi-35--pwa-arbeitszeit-korrigieren-und-nachtragen) wurde eine Pflichtbegründung für
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
Session-Timeout aussah ([OI-19](#oi-19--fehler-eines-exports-erscheinen-als-json-seite)). Einfach einen echten Token in
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
**Priorität:** erledigt am 2026-09-17 — mit 1.9.1. Der Durchlass bleibt, die Störung wird
sichtbar. Entschieden im Zuge von [OI-40](#oi-40--rate_limitsexpires_at-wird-nie-geschrieben),
das erst durch dieses Verhalten so lange unbemerkt blieb.

Der DB-gestützte Zweig von `RateLimiter::check()` gibt bei einer `PDOException` `true`
zurück — der Aufruf gilt dann als nicht gesperrt. Für einfaches Rate Limiting ist das
vertretbar (lieber durchlassen als die Anwendung lahmlegen). Für eine Sperre, die
Brute-Force verhindern soll — Login, seit 1.3.0 auch die Kiosk-PIN — bedeutet ein
Datenbank-Hänger dasselbe: keine Sperre, solange die Störung andauert.

**Entschieden am 2026-09-17:** fail-open bleibt, wird aber gemeldet. Drei Wege standen zur
Wahl — Durchlass sichtbar machen, fail-closed, oder nur OI-40 beheben und das Verhalten
lassen. Gewählt ist der erste:

- **fail-closed** hätte einen stillen Sicherheitsausfall gegen einen lauten Betriebsausfall
  getauscht. Für eine Anwendung, die Vereine selbst hosten und bei der niemand nachts ein
  Datenbankproblem behebt, ist das die schlechtere Richtung: Eine hängende Datenbank sperrte
  dann jeden aus, auch den Admin, der sie reparieren will.
- **Nichts tun** hätte den nächsten Auslöser derselben Art wieder unbemerkt durchgehen lassen.
  OI-40 lag jahrelang vor, ohne dass etwas darauf hinwies.

`noteFailure()` hält Zeitpunkt und Fehlernummer in `system_settings` fest
([rate_limiter.php](../private/helpers/rate_limiter.php)); die Systemeinstellungen zeigen im
Tab „System", ob der Schutz arbeitet — grün im Normalfall, gelb mit Zeitpunkt und
Fehlernummer, solange die letzte Störung keine 24 Stunden her ist.

**Bewusst nicht gespeichert wird der Meldungstext.** Er trägt Tabellen- und Spaltennamen und
wäre über `resource=settings` für jeden Admin lesbar; die vollständige Meldung bleibt im
Fehlerprotokoll von PHP. Scheitert auch der Vermerk, fängt ein zweites `catch` das ab — ein
Fehler im Melden darf den Aufrufer nie treffen.

**Offen geblieben:** Die Meldung erreicht nur, wer die Einstellungen öffnet. Ein aktiver Weg
(E-Mail an den Admin) wurde nicht gebaut — er bräuchte eine Entprellung, sonst schickt eine
hängende Datenbank im Minutentakt Post.

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
**Priorität:** erledigt — geprüft am 2026-09-17, die Lücke besteht nicht mehr

Die Login-Antwort (`resource=login`) liefert `user_id`, `email`, `role` — **kein** `member_id`.
Der Token-Login (`resource=auth`) liefert es. In `worktime.js` war das die Ursache eines Fehlers;
umgangen, weil der Server Nicht-Managern ohnehin nur eigene Sitzungen liefert.

**Gegengeprüft am 2026-09-17: nicht mehr aktuell.** `login()` liefert `member_id` in der
Login-Antwort mit ([auth.php](../private/helpers/auth.php)), und das Frontend wertet es aus
([api.js](../public/js/modules/api.js)). Wann das nachgezogen wurde, ist nirgends vermerkt —
der Eintrag blieb stehen, obwohl die Sache erledigt war.

---

### OI-10 · Breite Tabelle in der Zeiterfassung
**Priorität:** erledigt am 2026-09-22 — mit 1.12.0 (Branch `fix/oi-10-oi-75`), Wege 1 und 2 kombiniert.
`#worktimeTable` trägt `table-worktime` mit eigener Untergrenze `min-width: 1400px`
(`public/css/components/tables.css`); Beginn, Dauer und Status tragen `cell-nowrap`
(`renderWorkSessions()`). Gemessen mit 25 Zeilen aus dem Demo-Bestand und dem echten CSS: Die
Tabelle braucht ohne jeden Umbruch 1394 px, daher die 1400. Bei 1280 px Fensterbreite sinkt die
mittlere Zeilenhöhe von 124 auf 82 px (höchste Zeile 162 → 85 px), dafür rollt die Tabelle
waagerecht. Ab etwa 1725 px Fensterbreite (Seitenleiste und Innenabstand kosten 325 px) rollt nichts mehr. Der Status kam dazu, weil „wartet auf
Freigabe" ebenfalls umbrach. Test: `tests/suites/worktime_frontend.php`. Damit die Spalte
„Aktionen" auf einem Notebook nicht außerhalb des sichtbaren Bereichs liegt, steht sie per
`position: sticky; right: 0` am rechten Rand fest; im Browser bei 1280 px über den ganzen
Rollweg geprüft. Den Innenabstand rechts daneben deckt ein `::after` ab — ein äußerer
`box-shadow` hätte gereicht, Chrome zeichnet ihn an Tabellenzellen aber nicht. **Bleibt offen:**
Die allgemeine Untergrenze von 600 px für alle übrigen Tabellen ist unverändert und weiterhin
ungeprüft.

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
**Priorität:** erledigt am 2026-09-17 — anders als hier beschrieben, siehe unten

Hier stand: `device_type` und `work_sessions.source` bräuchten denselben Guard wie
`checkin_source`, sonst breche ein direktes Einspielen von `ehrensache_db.sql` auf eine
bereits migrierte Datenbank mit einem SQL-Fehler ab.

**Gegengeprüft am 2026-09-17: gegenstandslos.** Für beide Spalten gibt es gar kein `ALTER
TABLE`, das abbrechen könnte — sie stehen vollständig in ihren `CREATE TABLE`-Anweisungen
(`device_type` Z. 129, `work_sessions.source` Z. 526), und die tragen `IF NOT EXISTS`. Der
Eintrag hat einen Guard für einen Fall verlangt, den es nicht gibt.

**Der echte Fund an derselben Stelle.** Der vorhandene Guard prüfte auf `station_pin` (seit
1.2.5), schrieb dann aber eine Enum-Liste **ohne** `exception_request` (seit 1.4.1). Auf einer
Datenbank vor 1.2.5 setzte ein Einspielen des Schemas die Spalte damit auf einen
Zwischenstand, und eine genehmigte Zeitkorrektur konnte ihre Herkunft danach nicht mehr
ablegen.

**Nachgestellt, nicht geschlossen:** In einer Wegwerf-Datenbank mit `checkin_source` im Stand
vor 1.2.5 ergab der alte Guard `…,'timer','station_pin'` ohne `exception_request`, der
korrigierte die volle Liste.

**Behoben am 2026-09-17** (1.9.1): Der Guard fragt jetzt nach dem **jüngsten** Wert und
schreibt den vollen Wertevorrat. Damit gilt die Regel, die vorher nur zufällig stimmte — wer
den Guard beim nächsten neuen Enum-Wert anfasst, muss die Abfrage mitziehen, sonst greift sie
zu früh.

---

### OI-29 · `devices.js` Altlasten
**Priorität:** erledigt am 2026-09-17 — Maskierung am 2026-09-16, der Rest mit 1.9.1. Der Fund war größer als hier beschrieben, siehe unten

Mehrere kleine, voneinander unabhängige Funde in `public/js/modules/devices.js`:

- Fünf `getElementById`-Aufrufe ohne zugehöriges Markup: `devicesPagination`,
  `filterDeviceRole`, `filterDeviceStatus`, `filterGroup`, `resetDeviceFilters`.
- `device_name` fließt ungeschützt in `innerHTML`/`onclick` ein — ein Apostroph im Namen
  bricht den generierten `onclick`-Handler des Lösch-Buttons.
- `showDeviceSection(true, page)` ignoriert den übergebenen `page`-Parameter.
- Dieselbe Lücke besteht in der Mitgliederliste: Namen werden ohne `escapeHtml()`
  interpoliert.

**Erledigt in zwei Schritten.**

**2026-09-16:** `device_name` und die Mitgliedsnamen laufen über `escapeHtml()` (`70d6e08`,
`2ee35d6`), abgesichert durch einen Wächter in `tests/suites/assets.php` (`d4890b4`).

**2026-09-17 (1.9.1) — und dabei war der erste Punkt kein Aufräumen, sondern ein Fehler:**
`devicesPagination` hat sehr wohl ein Markup, es heißt nur `devicePagination` (Singular,
[index.html](../public/index.html)). Der Code suchte den Plural, `renderDevicesPagination()`
stieg also immer über `if (!container) return` aus. **Folge: Ab dem 26. Gerät war die Liste
abgeschnitten, und es gab keinen Weg zu den übrigen** — `renderDevices()` schneidet auf
`devicesPerPage = 25` zu. Ein Tippfehler, der Datensätze unerreichbar machte.

Nachgestellt: Mit `devicesPerPage = 1` erscheint die Blätterung, der Sprung auf Seite 2 zeigt
das zweite Gerät. Vorher blieb die Leiste leer.

`filterDeviceRole`, `filterDeviceStatus` und `resetDeviceFilters` haben dagegen wirklich kein
Markup — die Geräteliste hat keine Filterleiste. Die drei Handler sind entfernt; der
Reset-Handler hätte beim Feuern sogar geworfen, weil er ohne Optional Chaining auf `.value`
zugriff. `showDeviceSection()` wertet seinen `page`-Parameter jetzt aus, statt fest die 1 zu
nehmen.

Festgehalten in `tests/suites/profile_dashboard_frontend.php`.

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
**Priorität:** erledigt am 2026-09-16 — Teilerfolg wird benannt, Feld und Reiter markiert

**Umgesetzt** mit den Untertabs (1.9.0): Die Speicherschleife bricht bei einer Ablehnung nicht
mehr ab, sondern arbeitet die übrigen Schlüssel ab und sammelt die gescheiterten. Der Hinweis
nennt beide Zahlen („2 gespeichert, 1 abgelehnt“), das abgelehnte Feld bekommt `invalid`, sein
Reiter einen roten Punkt, und die Ansicht springt dorthin — mit Tabs läge der Fehler sonst auf
einer Seite, die niemand ansieht.

**Zwischenstand, der hier fehlte:** Die Prüfung auf `result.success` gab es bereits; was fehlte,
war die Behandlung danach. Die Schleife verließ sich stumm mit `return`, nachdem frühere
Schlüssel schon gespeichert waren.

<details>
<summary>Ursprünglicher Befund</summary>

Die Speicherschleife in `settings.js` zählt jeden abgesetzten `PUT`-Request als Erfolg und
meldet am Ende „N gespeichert", ohne die Antwort auszuwerten. Scheitert einer der Requests
(z. B. Validierungsfehler einer einzelnen Einstellung), meldet die Oberfläche trotzdem
Erfolg.

**Zu tun:** Antwort jedes `PUT` prüfen und einen Fehlschlag im Toast von den erfolgreichen
Speicherungen unterscheiden.
</details>

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
**Priorität:** erledigt am 2026-09-17 — mit 1.9.1, Spalte entfernt (Migration `1.9.0.php`).
Zusammen mit [OI-28](#oi-28--ratelimitercheck-fail-open) behoben; die Wirkung war schwerer
als der ursprüngliche Eintrag annahm, siehe unten.

`RateLimiter` schreibt beim Zählen nur `identifier`, `action` und `created_at`
([rate_limiter.php:104](../private/helpers/rate_limiter.php)) und rechnet seine Fenster
ausschließlich über `created_at`. Die Spalte `expires_at` ist im Schema aber `NOT NULL` ohne
Vorgabewert. Jede Zeile trägt deshalb das ungültige Nulldatum `0000-00-00 00:00:00`.

Solange MariaDB nicht im strengen Modus läuft, bleibt das folgenlos. Unter
`STRICT_TRANS_TABLES` — auf manchem Hosting die Voreinstellung — scheitert dagegen **jeder**
Schreibvorgang des Limiters.

**Korrektur vom 2026-09-17.** Hier stand: „und damit jeder Anmeldeversuch". **Das war falsch**
— aus der Fehlermeldung geschlossen, ohne den Aufrufer zu lesen. `checkDatabase()` fängt die
`PDOException` und gibt `true` zurück (fail-open, [OI-28](#oi-28--ratelimitercheck-fail-open)).
Die Anmeldung läuft also durch; **aus ist der Limiter**. Auf strengem Hosting fehlen damit seit
jeher der Schutz gegen das Durchprobieren von Passwörtern und die Sperre der Stations-PIN,
ohne einen anderen Hinweis als eine Zeile im Fehlerprotokoll von PHP.

Das dreht die Bewertung: kein Betriebsausfall, sondern ein stiller Ausfall einer
Sicherheitsfunktion — und die beiden Punkte gehören zusammen. Wer nur OI-40 behebt, beseitigt
den heutigen Auslöser, nicht die Klasse.

**Behoben am 2026-09-17** (1.9.1). Die Spalte ist entfernt statt gefüllt, weil sie nirgends
gelesen wird; an die Stelle von `idx_expires` tritt ein Index auf `created_at` (der alte lag
auf einer Spalte, die nur Nulldaten enthielt, und half dem Aufräumen nie).

**Kein Advisory — entschieden am 2026-09-17.** Die Lage erfüllt zwar die Grenze aus
`SECURITY.md` (ohne vorherigen Zugang ausnutzbar), aber ein GHSA warnt nur jemanden, den es
gibt: EhrenSache läuft bislang in praktisch keiner produktiven Installation. Der Punkt steht
im Changelog unter „Sicherheit" und hier in voller Länge — das ist die Offenlegung, die der
Sache angemessen ist. **Bei späterer Verbreitung gilt die Abwägung neu:** Derselbe Fund wäre
mit Vereinen im Feld advisory-würdig, gerade weil Betroffene ihn nicht selbst erkennen
können.

**Nachgestellt, nicht nur gelesen:** `tests/db/verify_rate_limiter_strict.php` fährt den
Limiter gegen eine Verbindung mit `STRICT_TRANS_TABLES`. Gegen den Stand vor der Migration
lässt er dort viermal durch, wo er beim vierten Mal sperren müsste — Fehler 1364. Nicht Teil
von `tests/run.php`, weil die Datei den SQL-Modus der Verbindung umstellt.

---

### OI-41 · `checkin_appointment` ist am selben Tag nicht wiederholbar
**Priorität:** erledigt — geprüft am 2026-09-17, die Suite räumt ab

Die Suite legt ihre Termine mit festen Uhrzeiten am aktuellen Tag an („Nachtrag-Termin" 07:00,
„Frueher Check-in" 04:00, „Spaeter zugeordnet" 13:00 …) und räumt sie nicht wieder ab. Beim
zweiten Lauf am selben Tag liegen zwei gleichartige Termine im Toleranzfenster, die Automatik
trifft den älteren, und der Test „Check-in trifft einen Termin im Toleranzfenster" meldet rot,
obwohl nichts kaputt ist.

Am 2026-09-09 hinterließen zwei Läufe zwölf solcher Termine. Aufgeräumt über den Titelanhang,
den die Suite vergibt: Titel mit einem angehängten 13-stelligen Hex-Wert.

**Erledigt — die erste Variante.** `tests/suites/checkin_appointment.php` schließt mit einem
Aufräumtest, der Termine, Terminarten und Gruppen in dieser Reihenfolge entfernt und den ersten
angelegten Termin als Stichprobe gegenprüft; die `records` der Check-ins fallen per
`ON DELETE CASCADE` mit ihren Terminen. Wie bei OI-9 stand der Eintrag hier noch, obwohl die
Sache getan war.

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
| Keine Pinnwand, kein Chat, keine Dateiablage | Bleibt draußen (2026-09-16) | Drei Funktionen, die jede Vereins-App mitbringt und die hier bewusst fehlen. Sie haben keine Datenberührung zu Anwesenheit, Pünktlichkeit oder Arbeitszeit, kosten aber jeweils ein eigenes Datenmodell mit Moderation, Löschfristen und Missbrauchsfällen. Eine Dateiablage bringt zusätzlich Uploads fremder Herkunft in ein System, das heute nur ein Vereinslogo entgegennimmt; ein Chat macht aus einer Anwesenheitserfassung einen Nachrichtendienst mit allem, was die DSGVO daran hängt. Wer Kommunikation braucht, hat sie bereits — EhrenSache tritt nicht gegen Messenger an. Die Grenze berührt auch FI-17: systemerzeugte offene Punkte ja, an einzelne Mitglieder adressierte Nachrichten nein |
| Kein PDF-Export | So belassen | Würde eine Bibliothek einschleppen, die das Projekt bewusst nicht hat. Der Bedarf ist seit 1.2.2 über die Druckansicht (`&format=html`) gedeckt: Das PDF entsteht im Druckdialog des Browsers |
| Installer und Update-Assistent werden gesperrt ausgeliefert | So belassen | Ein hochgeladener, aber noch nicht eingerichteter Webspace soll `/install` nicht offen zeigen. Der Freischaltschritt steht für beide in der README; nach dem Lauf sperrt sich jeder Assistent selbst wieder. Die Alternative — ungesperrt ausliefern — nähme dem Ersteinrichter eine Hürde, öffnete aber ein Zeitfenster zwischen Upload und Installation |
| Statistik getrennt von Anwesenheit | Eigener `worktime`-Block | Anwesenheitsquote und geleistete Stunden sind verschiedene Fragen |
| Kiosk-Sperre als Gruppen-DoS | So belassen (E12) | 30 Fehlversuche je Station sperren die ganze Station 15 Minuten — trifft damit alle, die an ihr stempeln wollen, nicht nur den Angreifer. Die Fehlermeldung unterscheidet Gerät und Konto, damit ein gesperrtes Mitglied von einer gesperrten Station unterscheidbar bleibt. Akzeptiert, weil die Alternative — keine Stationssperre — Nummern-Durchprobieren ohne Bremse erlaubt |
| Kiosk: Terminwahl serverseitig, keine Auto-Anlage (E9) | So belassen | Der Kiosk wählt den passenden Termin wie `auto_checkin` serverseitig aus, zeigt keine Terminliste zur Auswahl und legt keinen Termin an. Ein Stempel ohne passenden Termin bekommt nur eine Meldung, keinen Datensatz. Ziel ist ein Stempelvorgang in drei Tipps; die Terminauswahl bleibt der Handy-PWA vorbehalten |
| Kiosk: Notizpflicht entfällt (P1) | So belassen | Der Kiosk hat keine Tastatur für Fließtext. Ist `worktime_require_note` aktiv, verlangt ein Stopp über `station` trotzdem keine Notiz; die Tätigkeitsart bleibt die Beschreibung |
| Token im Fragment, nicht im Query (ab 1.5.0) | So belassen | `…/station/#t=<token>` statt `?t=`: Das Fragment wird vom Browser nie gesendet und steht damit in keinem Zugriffsprotokoll, keinem Referrer und keinem Reverse-Proxy-Log. Ein Query-Parameter landet in jedem davon. Die Station entfernt den Hash nach der Übernahme per `replaceState`, damit er auch nicht im Verlauf bleibt |
| QR-Übernahme überschreibt still (ab 1.5.0) | So belassen | Wer den Code vor das Tablet hält, steht physisch davor — dieselbe Schwelle wie beim Einstellungsdialog der Station. Eine Rückfrage kostet in der Demo bei jedem stündlichen Reset einen zusätzlichen Tipp und schützt vor nichts, was nicht schon durch den physischen Zugang gedeckt wäre |
| Der Scan-Reload unterbricht auch eine laufende Eingabe (ab 1.5.0) | So belassen | Der `hashchange`-Zuhörer lädt neu, ohne zu prüfen, ob gerade jemand Mitgliedsnummer oder PIN tippt — die Eingabe ist dann weg. Die Alternative, den Reload auf Ruhebild und Einrichtung zu beschränken, holt den Fehler zurück, den er behebt: Ein Scan täte dann in genau diesem Zustand wieder sichtbar nichts. Eine verlorene Eingabe kostet zwei Tipps und ist selbsterklärend; ein folgenloser Scan ist es nicht. Es geht dabei nichts verloren, was schon gespeichert wäre — gestempelt wird erst nach der PIN-Prüfung |
| Updater prüft das Paket nur über TLS | Keine Signatur, kein Hash | Ein Hash aus den Release-Notizen käme aus derselben Quelle wie das Paket und schützte nicht gegen eine Übernahme des GitHub-Kontos; über den bei Bedarf erzeugten zipball wäre er zudem nicht stabil. Eine Signatur mit einem Schlüssel außerhalb von GitHub schützte als einzige, erzeugt aber dauerhafte Betriebslast, und bei Schlüsselverlust könnte keine Installation mehr aktualisieren. Aussage damit: „Wir vertrauen GitHub und TLS." Zusätzlich muss jede Adresse, auch nach Weiterleitungen, auf einen GitHub-Host zeigen. Begründung in `docs/superpowers/specs/2026-09-11-integrierter-updater-design.md`, Abschnitt 6 |
| Updater fragt nie von selbst | Nur auf Knopfdruck | Ohne Zutun soll nichts den Server verlassen. Preis: Wer nie klickt, erfährt nichts von einem Sicherheitsupdate — dafür steht in der README das Abonnieren der Releases |
| Jede Version ist direkt von jedem älteren Stand erreichbar | Kein Pflicht-Zwischenschritt | Drei Regeln halten das: Migrationsdateien werden nie gelöscht (`tests/suites/migrations.php` erzwingt die lückenlose Kette); der Leser in `config_reader.php` behält die alte Klassenform, weil `migrate_1_5_1()` und der Update-Assistent sie brauchen; der Update-Pfad bleibt auf PHP 8.0 lauffähig (`tests/suites/update_path_syntax.php`), damit auch eine Installation auf 1.6.0 — deren Updater `requires` nicht kennt — über den Folgeaufruf der neuen Version zurückrollen kann. Keine Migration bindet die `config.php` ein (Test in `migrations.php`). Aufheben lässt sich die PHP-8.0-Grenze des Update-Pfads nur mit der ausdrücklichen Entscheidung, Installationen auf 1.6.0 nur noch von Hand zu aktualisieren. Begründung: `docs/superpowers/specs/2026-09-14-direktsprung-requires-design.md` |

---

## Historie der Korrekturen

Punkte, bei denen eine frühere Einschätzung revidiert wurde — als Warnung vor demselben Irrtum:

- **R1 „entschärft" war zu früh.** Am 2026-09-01 als geprüft vermerkt, nachdem Anlegen und
  Sperrwirkung des Unique-Index auf der virtuellen Spalte funktionierten. Nicht geprüft war das
  Verhalten nach einem Neustart. Am Folgetag trat [OI-1](#oi-1--verlorener-auto_increment-nach-crash-recovery) auf. Ein sauberer Neustart hat
  die Konstruktion inzwischen entlastet, die Ursache bleibt offen.
- **Gruppengrenze für Manager gab es nie.** Die Spec berief sich auf
  `hasStatisticsGroupAccess()`; diese Funktion liefert für Admin **und** Manager `true` und
  begrenzt nur einfache Nutzer.
- **Der Service Worker cacht nichts.** Seine Caching-Logik ist auskommentiert. Veraltete Assets
  kamen von gewöhnlichem HTTP-Caching.
- **`location_name` war immer `NULL`.** Der Bestandscode las `users.email` von Gerätekonten —
  ein Feld, das die Check-Constraint auf `NULL` zwingt.

**Aus der Durchsicht für 1.9.1 (2026-09-17) — fünf Einträge, die falsch lagen:**

- **OI-40 nannte die falsche Folge.** „Scheitert jeder Anmeldeversuch" war aus der Fehlermeldung
  geschlossen, ohne den Aufrufer zu lesen. `checkDatabase()` fängt die Exception und lässt durch:
  Nicht die Anmeldung fällt aus, sondern der Limiter — ein **stiller Ausfall einer
  Sicherheitsfunktion** statt eines Betriebsausfalls. Die Lehre ist nicht „genauer lesen",
  sondern: Eine Folge, die man aus einer Fehlermeldung ableitet, ist eine Vermutung, bis der
  aufrufende Code sie bestätigt.
- **OI-26 verlangte einen Guard für einen Fall, den es nicht gibt.** `device_type` und
  `work_sessions.source` haben gar kein `ALTER`, das abbrechen könnte. Dafür steckte an derselben
  Stelle ein echter Fehler, den der Eintrag nicht sah.
- **OI-56 führte `users.php` als versorgt.** Das dortige `400` gilt dem Löschen des eigenen
  Kontos, nicht einer fehlenden `id`. Ein `awk` über den Zweig hatte die beiden Prüfungen
  verwechselt — aufgedeckt hat es der Test, nicht das Lesen.
- **OI-29 hielt einen Fehler für Aufräumen.** „Tote `getElementById`-Aufrufe" — tatsächlich war
  einer davon ein Tippfehler gegen vorhandenes Markup (`devicesPagination` statt
  `devicePagination`), der **die Geräteliste ab dem 26. Eintrag unerreichbar machte**.
- **OI-9 und OI-41 waren längst erledigt**, ohne dass ihr Eintrag es sagte.

Das gemeinsame Muster: **Diese Datei ist ein Gedächtnis, kein Zustand.** Sie hält fest, was
jemand einmal gesehen hat — ob es noch gilt, sagt nur der Code.

### OI-44 · Demo: gespeichertes XSS zwischen zwei Resets
**Priorität:** niedrig · bewusst in Kauf genommen

Der Demo-Modus lässt Schreibzugriffe auf Mitglieder, Termine, Anwesenheiten, Anträge und
Arbeitszeiten zu — das ist sein Zweck. Was ein Besucher dabei in ein Freitextfeld schreibt,
bekommt bis zum nächsten Reset jeder weitere Besucher zu sehen. Die Oberfläche nutzt
Inline-Handler und führt bewusst keine CSP (siehe [OI-17](#oi-17--keine-content-security-policy)).

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
**Priorität:** erledigt am 2026-09-16 — die CSV führt jetzt dieselben Daten wie die JSON-Form

**Umgesetzt** in `private/handlers/my_data.php` (Branch `fix/my-data-csv`): drei neue Abschnitte
— `=== MITGLIEDSCHAFTSZEITRÄUME ===`, `=== ARBEITSZEITEN ===` (Beginn, Ende, Pause, Dauer,
Tätigkeit, Termin, Status, Nachweis, Quelle, Notiz) und
`=== ÄNDERUNGSHISTORIE ARBEITSZEIT ===` (Zeitpunkt, Sitzung, Vorgang, Änderungen als JSON).
Die Aufbereitung kommt aus `export.php` und `worktime.php` (`worktimeReportTimes()`,
`sessionDurationMinutes()`, `worktimeHours()`, `worktimeProofLabel()`), der Nachweisgrad über
`worktimeProofExpression()` aus der Abfrage — der Nachweis rechnet damit nicht anders als der
Stundennachweis des Vereins.

**Beim Umsetzen zusätzlich gefunden:** Auch die **Mitgliedschaftszeiträume** fehlten in der CSV,
obwohl der Handler sie holt und die JSON-Form sie ausgibt. Sie sind mit aufgenommen; der
ursprüngliche Befund nannte nur die Arbeitszeiten. Die offene Frage zur Änderungshistorie ist
mit „gehört hinein" entschieden: Sie enthält personenbezogene Daten und überlebt die Löschung
einer Sitzung bewusst.

**`DATENSCHUTZ.md` war damit ungenau, ist es jetzt nicht mehr:** Abschnitt 9 beschreibt die
Auskunft als „enthält auch Arbeitszeiten und deren Änderungshistorie" und die
Datenübertragbarkeit als „CSV-Export nutzen". Beides stimmt erst mit dieser Änderung.

**Abgesichert durch** `tests/suites/worktime_api.php` („my_data: Arbeitszeiten stehen in JSON UND
in der CSV") und `docs/testplan.md` AW-12.

<details>
<summary>Ursprünglicher Befund</summary>

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
</details>

---

### OI-51 · Pünktlichkeit wird beworben, aber nirgends ausgewertet
**Priorität:** erledigt am 2026-09-14 — mit 1.5.1. Pünktlichkeit und Zuverlässigkeit in Statistik, Anwesenheitsbericht und Selbstauskunft, beide ab Werk ausgeschaltet; `DATENSCHUTZ.md` Abschnitt 11.

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

**Schritt 2 (die Kennzahlen selbst) ist umgesetzt und liegt in 1.5.1.** Pünktlichkeit und
Zuverlässigkeit stehen in Statistik, Anwesenheitsbericht und Selbstauskunft, beide ab Werk
ausgeschaltet; `DATENSCHUTZ.md` Abschnitt 11 beschreibt Zweck, Sichtbarkeit und Auskunft.
Die Farbskala der Quoten bleibt bei [OI-55](#oi-55--farbschwellen-der-anwesenheitsquote-sind-fest-verdrahtet).

**OI-51 ist damit erledigt.**

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

**Zusammenhang mit [OI-48](#oi-48--statistik-zählt-je-gruppe-nur-eine-terminart):** Solange die Statistik je Gruppe nur eine Terminart
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
gemeinsam mit [OI-48](#oi-48--statistik-zählt-je-gruppe-nur-eine-terminart) entscheiden, das ohnehin an derselben Funktion ansetzt.

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
**Priorität:** erledigt am 2026-09-16 — beide Handler schreiben nur noch mitgeschickte Felder

**Umgesetzt** in `private/handlers/appointment_types.php` und
`private/handlers/activity_types.php` (Branch `fix/api-korrekturen`): dynamisches `UPDATE` wie
bei `members`, `API.md` weist die Teiländerung bei beiden Ressourcen ausdrücklich aus.
`activity_name` ist beim `PUT` damit nicht mehr Pflicht — mitgeschickt darf es aber nicht leer
sein.

**Zusätzlich gefunden:** Bei den Terminarten lief das `DELETE` auf
`appointment_type_groups` **bedingungslos** vor dem bedingten Neuanlegen. Ein `PUT` ohne
`group_ids` löste damit sämtliche Gruppen der Terminart — schwerer als der ursprüngliche Befund,
weil die Terminart danach niemanden mehr erreicht. Der Punkt hatte das nur für
`activity_types` geprüft, wo es bereits richtig war.

**Am Rande bestätigt:** Der Datenverlust blieb unbemerkt, weil MySQL ohne `STRICT_TRANS_TABLES`
ein fehlendes `type_name` als leeren String annimmt statt die Abfrage abzuweisen — der Name war
danach `''`, nicht `NULL`.

**Abgesichert durch** `tests/suites/partial_update_api.php` (vier Fälle, vor der Korrektur alle
rot).

<details>
<summary>Ursprünglicher Befund</summary>

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
</details>

---

### OI-55 · Farbschwellen der Anwesenheitsquote sind fest verdrahtet

**Priorität:** erledigt am 2026-09-16 — drei Schwellen als Systemeinstellung, global

**Umgesetzt** in 1.9.0 (Migration `1.8.0.php`): `rate_threshold_mid`, `_fair` und `_good` mit den
bisherigen Werten 40/60/80 als Vorgabe, einzustellen im Tab „Termine & Anwesenheit“. Der Server
klammert jeden Wert auf 1–99 und sortiert die drei beim Lesen (`rateBands()` in
`private/helpers/utils.php`); die Reihenfolge prüft die Oberfläche vor dem Absenden, weil jede
Einstellung einzeln geschrieben wird und ein Zwischenstand sie zwangsläufig verletzt. Ausgeliefert
werden die Werte im Statistik-Payload (`rate_bands`) — `settings` ist Admins vorbehalten, die
Statistik sehen alle Rollen.

**Entschieden:** global, **nicht je Terminart**. Das wäre fachlich verteidigbar, kostet aber eine
Zuordnungstabelle samt Pflegeoberfläche statt dreier Zahlen. Die Farben bleiben fest.

**Beim Umsetzen gefunden, hier nicht vermerkt:** Die Check-in-PWA färbte dieselbe Quote nach einer
**eigenen** Skala — drei Bänder bei 50/75 mit fest eingetragenen Hex-Werten
(`public/checkin/js/app.js`). Ein Mitglied mit 65,6 % war im Dashboard gelb und in der PWA orange.
Die PWA nutzt jetzt dieselben vier Bänder und dieselben Schwellen; nachgestellt in der laufenden
Instanz: Mit `fair = 70` wechselt dieselbe Quote dort von Gelb auf Orange.

<details>
<summary>Ursprünglicher Befund</summary>

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
</details>

---

### OI-56 · DELETE ohne `id` meldet Erfolg, ohne zu löschen
**Priorität:** erledigt am 2026-09-17 — mit 1.9.1, alle acht Ressourcen. Teilweise schon am
2026-09-16 (`appointments`, `records`), ohne dass es hier vermerkt war.

Ein `DELETE` ohne `id`-Parameter führt in den meisten Handlern
`DELETE FROM <tabelle> WHERE <spalte> = NULL` aus. Das trifft **keine Zeile** — `= NULL` ist in
SQL niemals wahr —, aber `PDOStatement::execute()` liefert trotzdem `true`. Der Handler antwortet
mit `200` und einer Erfolgsmeldung wie „Appointment deleted".

**Kein Datenverlustrisiko.** Es wird zu wenig gelöscht, nicht zu viel; ein `WHERE` steht überall.
Auch keine Rechteausweitung: Die Rollenprüfung (`requireAdmin()` bzw. `requireAdminOrManager()`)
läuft davor und bleibt wirksam.

**Das Problem ist die Antwort.** Ein Client kann nicht erkennen, dass sein Aufruf wirkungslos
war — er bekommt dieselbe Antwort wie bei einer erfolgreichen Löschung.

**Wie es aufgefallen ist:** beim Schreiben der Suite `arrival_api` (1.5.0). Ein Testtermin
verschwand nicht, obwohl der Aufruf `200 "Appointment deleted"` meldete. Ursache war dort ein
Fehler im Test — `apiRequest()` kennt keinen Schlüssel `id`, die Angabe gehört in `query` —, aber
der Handler hätte es sagen müssen.

**Betroffen** (geprüft am 2026-09-11, `DELETE`-Zweig ohne vorherige `id`-Prüfung):
`appointments.php`, `records.php`, `users.php`, `exceptions.php`, `activity_types.php`,
`appointment_types.php`, `member_groups.php`, `membership_dates.php`.

Es geht auch anders: `import.php`, `members.php` und `work_sessions.php` prüfen die `id` vorher
und antworten mit `400`.

**Zu tun:** In den betroffenen Zweigen vor dem Löschen auf eine vorhandene `id` prüfen und sonst
`400` liefern. Wo es fachlich passt, zusätzlich `rowCount()` auswerten und `404` melden, wenn kein
Datensatz getroffen wurde — das fängt auch den Fall einer gültigen, aber unbekannten ID.

**Vorsicht bei `records.php`:** Der Zweig kennt zwei Betriebsarten — einzelner Datensatz über
`id` und Massenlöschung über `member_id`. Eine Prüfung auf `id` allein würde die zweite
abwürgen. Die Prüfung steht deshalb **im** Einzelzweig, nicht davor; ein eigener Testfall hält
die Massenlöschung fest.

**Korrektur der Bestandsaufnahme:** Oben stand, `users.php` gehöre zu den Handlern, die es
richtig machen. **Das war falsch** — das dortige `400` galt dem Löschen des eigenen Kontos, nicht
einer fehlenden `id`. Aufgefallen ist es erst durch den Test; ein Blick in den Code hatte die
beiden Prüfungen verwechselt.

**Behoben am 2026-09-17.** Einheitlich in allen acht Ressourcen: fehlende `id` → `400`,
unbekannte `id` → `404`. `tests/suites/delete_id_api.php` prüft beides für jede Ressource,
einschließlich der vier bereits korrigierten — 13 der 17 Fälle waren gegen den alten Stand rot.

**Folge in einer anderen Suite:** `worktime_api` verlangte beim Aufräumen strikt `200`. Das ging
nur durch, weil der Handler jeden Aufruf als Erfolg meldete; jetzt gilt dort `200` oder `404`.

**Nicht sicherheitsrelevant:** keine Rechteausweitung, kein Zugriff ohne Anmeldung, kein
zusätzlicher Datenabfluss.

---

### OI-57 · ID-Badge fehlt in den Modals der Zeiterfassung
**Erledigt am 2026-09-14** — beide Dialoge rufen `updateModalId()`.

Die Bearbeitungsdialoge zeigen oben rechts im Kopf die Datenbank-ID des bearbeiteten
Datensatzes (`updateModalId()` in `public/js/modules/utils.js`, Stil `.modal-id-badge` in
`public/css/components/modals.css`). Das erleichtert das Zuordnen zwischen Oberfläche,
Exporten und Datenbank.

**Zwei Dialoge machen es nicht mit** — beide in `public/js/modules/worktime.js`:

| Dialog | Funktion | Modal-ID | vorhandene ID |
|---|---|---|---|
| Arbeitszeiteintrag | `openWorkSessionModal()` (ab Zeile 443) | `workSessionModal` | `sessionId` |
| Tätigkeitsart | `openActivityTypeModal()` (ab Zeile 811) | `activityTypeModal` | `activityId` |

Beide Funktionen kennen die ID bereits — sie schreiben sie in ein verstecktes Feld und
schalten den Titel auf „bearbeiten" — und `worktime.js` importiert `updateModalId` bisher
nicht.

**Zu tun:** `updateModalId` in `worktime.js` importieren und in beiden Funktionen aufrufen:
beim Bearbeiten mit der ID, beim Neuanlegen mit `null` (sonst bleibt der Badge des zuvor
bearbeiteten Datensatzes stehen — die Funktion entfernt einen alten Badge zwar, aber nur,
wenn sie überhaupt gerufen wird).

**Vollständig ist die Liste damit:** members, appointments, records, exceptions, users,
devices, groups und Terminarten rufen `updateModalId` bereits.

**Nicht sicherheitsrelevant:** keine Datenänderung, kein Rechtebezug; die IDs sind für den
Bearbeitenden ohnehin über die API sichtbar.

---

### OI-58 · Terminrückmeldung: bewusst nicht gebaut
**Priorität:** niedrig — Entscheidungen, keine Mängel

Mit 1.7.0 (Spec `2026-09-14-terminrueckmeldung-design.md`, Abschnitt 12) bewusst weggelassen,
damit es nicht erneut vorgeschlagen wird, ohne dass sich an den Gründen etwas geändert hat:

| Punkt | Grund | Wo es weitergeht |
|---|---|---|
| Erinnerung an offene Rückmeldungen | braucht einen Versandweg | FI-6 |
| Besetzungsansicht nach Registern | braucht Untergruppen | FI-14 |
| Rolle „Gruppenleiter" | der Dirigent erhält ein Manager-Konto | FI-15 |
| Kennzahl „Zusagetreue" je Person | Personenbewertung; die Zuverlässigkeit deckt die Frage ab | — |
| Verlauf der Antwortänderungen | mehr Datenbestand, eigene Löschfrist, wäre wieder eine Personenauswertung | — |
| Rückmeldung je Termin abweichend von der Terminart | kein Bedarf erkennbar | — |
| Offline-Warteschlange in der PWA | die PWA arbeitet onlinebasiert | OI-43 |

**Nicht sicherheitsrelevant.**

---

### OI-59 · CSV-Exporte entschärfen führende Formelzeichen nicht
**Priorität:** erledigt am 2026-09-17 — mit 1.9.1, alle 63 Ausgabestellen

Freitextfelder, die mit `=`, `+`, `-`, `@` (oder einem führenden Tab/CR) beginnen, werden von
Tabellenkalkulationen (Excel, LibreOffice Calc, Google Sheets) beim Öffnen einer CSV-Datei als
Formel ausgewertet („CSV-/Formel-Injection", CWE-1236). Betroffen sind Freitextfelder, die
Mitglieder oder Manager selbst setzen — Rückmeldungs- und Ausnahme-Bemerkungen, Ausnahmegründe,
Terminstitel — und unverändert in eine CSV-Datei gelangen: die Selbstauskunft
(`resource=my_data`, `private/handlers/my_data.php`) und die allgemeinen Exporte
(`private/handlers/export.php`).

**Wirkung:** Öffnet ein Empfänger — typischerweise ein Vorstandsmitglied, das den Export prüft
oder weiterverarbeitet — die Datei in einer Tabellenkalkulation mit Standardeinstellungen, kann
eine präparierte Zelle eine Formel ausführen. Das Risiko trifft den **Öffnenden**, nicht den
Schreibenden der Datenbank.

**Warum ein öffentlicher Eintrag zulässig ist** (`SECURITY.md`, „Umgang mit bekannten
Schwachstellen"): Ausnutzbar ist die Lücke nur über ein Konto, das ein solches Feld beschreiben
darf (`user`, `manager` oder `admin`, je nach Feld) — kein anonymer Zugriff — und sie erlaubt
innerhalb der Anwendung selbst keine Rechteausweitung; die eigentliche Wirkung entsteht
ausschließlich außerhalb, in der Tabellenkalkulation des Empfängers.

**Behoben am 2026-09-17.** `csvCell()` und `csvRow()` in `private/helpers/utils.php`; alle 63
Ausgabestellen laufen darüber (20 in `export.php`, 43 in `my_data.php`).

**Der Wächter ist der eigentliche Schutz, nicht die Funktion.** Eine Regel, die man bei jeder
neuen Exportspalte von Hand einhalten muss, wird irgendwann vergessen —
`tests/suites/csv_formula_unit.php` meldet deshalb jedes direkte `fputcsv()` in den beiden
Handlern, wie `demo_mode.php` es für die Registrierung neuer Ressourcen tut. Gegengeprüft: Mit
einem eingebauten `fputcsv()` schlägt der Test fehl.

**Zahlen sind ausgenommen.** Ohne die Prüfung auf `is_numeric()` würde jeder negative Wert zu
Text, und der Empfänger könnte im Stundennachweis nicht mehr rechnen. Gefährlich wird ein Minus
erst in Verbindung mit einem Bezug oder Funktionsnamen — dann ist die Zelle nicht mehr numerisch.

**Mitgezogen:** Die Abschnittsüberschriften der Selbstauskunft hießen `=== STAMMDATEN ===` und
hätten selbst ein Schutzapostroph bekommen. Sie heißen jetzt `[ STAMMDATEN ]` — programmerzeugte
Überschriften brauchen kein Formelzeichen. Ein Test hält das fest.

**Sicherheitsrelevanz:** real, aber nach der Grenze in `SECURITY.md` öffentlich dokumentierbar —
kein Zugriff ohne vorherige Anmeldung, keine Rechteausweitung innerhalb von EhrenSache.

---

### OI-60 · Zeitbasis der Terminrückmeldung: PHP-Uhr statt MySQL-Uhr
**Priorität:** niedrig

`responseDeadline()` selbst rechnet **in UTC** — bewusst nur als Rechenhilfsmittel, um über eine
Zeitumstellung hinweg dieselbe Wanduhr-Arithmetik wie MySQLs `DATE_SUB` auf `DATETIME` zu liefern
(siehe Kommentar dort); die Funktion liest keine Systemzeitzone. Die eigentliche Abhängigkeit von
der PHP-Uhr steckt in ihren **Eingaben**: `$now` (`date('Y-m-d H:i:s')` in
`handleAppointmentResponses()`) und `status_changed_at` (ebenfalls mit `date()` geschrieben) sind
Wanduhrzeit der Zeitzone des PHP-Prozesses. `exceptions.created_at` dagegen ist ein MySQL-
`TIMESTAMP`, geschrieben in der Sitzungszeitzone der Datenbankverbindung. Stehen PHP und MySQL auf
unterschiedlichen Zeitzonen, können Fristvergleiche zwischen einer Rückmeldung und einem daraus
entstandenen Antrag um den Zeitunterschied auseinanderlaufen.

Aktuell ist nirgends in der Anwendung eine Zeitzone konfiguriert; PHP und MySQL laufen beide auf
der Serveruhr. Der Fall tritt also nicht auf, solange Webserver und Datenbank auf derselben
Maschine mit derselben Systemzeitzone stehen — der übliche Fall bei einer Vereinsinstallation.

**Zu tun:** entweder dokumentieren, dass PHP- und MySQL-Zeitzone übereinstimmen müssen, oder
beide explizit auf denselben Wert setzen (`date.timezone` in PHP, `SET time_zone` bzw.
Verbindungsparameter bei PDO).

**Nicht sicherheitsrelevant:** keine Rechteausweitung, kein zusätzlicher Datenabfluss — im
schlechtesten Fall eine falsch eingeordnete Frist, kein Zugriff auf fremde Daten.

---

### OI-61 · Terminrückmeldung: Einstellungen der Terminart wirken rückwirkend auf die Zuverlässigkeit
**Priorität:** niedrig — bewusst so entschieden am 2026-09-15

`responseDeadlineHours()` und `responses_enabled` (`private/helpers/responses.php`,
`reliabilityFetchPairs()`) lesen die Einstellungen der Terminart zum Zeitpunkt der Auswertung, nicht
als Schnappschuss je Termin. Ändert der Admin sie später — etwa schaltet er Rückmeldungen für eine
Terminart erst nachträglich ein oder verschiebt die Frist —, wertet das auch **vergangene** Termine
neu aus. Die Zuverlässigkeit eines Mitglieds für einen bereits gelaufenen Termin kann sich dadurch
nachträglich verschieben, ohne dass sich am tatsächlichen Verhalten des Mitglieds etwas geändert
hätte.

Entschieden wurde, das bewusst in Kauf zu nehmen (Spec
`2026-09-14-terminrueckmeldung-design.md`, Abschnitt 3.5):

- Die Kennzahl ist ab Werk aus (`reliability_enabled`, seit 1.5.1) und wird nur bewusst
  eingeschaltet — wer sie nutzt, sieht auch, wenn sich ihre Grundlage ändert.
- Terminarten ändern sich in der Praxis selten, meist einmalig beim Einrichten.
- Ein Schnappschuss je Termin würde das nachträgliche Bearbeiten einer Terminart verkomplizieren
  (welcher Termin bekäme welchen Stand der Einstellungen?), ohne dass dafür bisher ein Bedarf
  erkennbar wäre.

**Nicht sicherheitsrelevant:** keine Rechteausweitung, kein zusätzlicher Datenabfluss — im
schlechtesten Fall eine nachträglich verschobene Kennzahl.

---

### OI-62 · Feature-Schalter ohne gemeinsame Prüfstelle
**Priorität:** niedrig–mittel · aufgenommen am 2026-09-16

Abschaltbare Grundfunktionen gibt es bereits, aber jede wurde einzeln nachgerüstet und wird
anders geprüft:

| Schalter | Prüfung |
|---|---|
| `worktime_enabled` | `isWorktimeEnabled()` über `worktimeSetting()` (`private/helpers/worktime.php`) |
| `punctuality_enabled`, `reliability_enabled` | direkt über `systemSetting()` in `private/helpers/punctuality.php` |
| `responses_enabled` | je Terminart, an mehreren Stellen als Spalte der Zeile geprüft (`appointment_responses.php`, `responses.php`, `punctuality.php`) |
| `station_pin_enabled`, `mail_enabled` | jeweils an ihrem Ort |

Es fehlt eine gemeinsame Stelle — Arbeitstitel `isFeatureEnabled()` —, über die Menü, Routing
und API dieselbe Antwort bekommen. Für Terminplanung und Anwesenheitserfassung, die beiden
Grundfunktionen, gibt es gar keinen Schalter; ein Verein, der EhrenSache nur zur
Arbeitszeiterfassung nutzt, sieht trotzdem die volle Oberfläche.

**Warum das zunehmend drückt:** Jede weitere Funktion aus `FEATURE-IDEAS.md` vergrößert die
Oberfläche für alle Vereine, auch die, die sie nicht brauchen. Ohne zentrale Prüfung wächst
zudem die Wahrscheinlichkeit, dass eine abgeschaltete Funktion im Menü verschwindet, ihr
Endpunkt aber weiter antwortet — das ist dann keine Kosmetik mehr.

**Zu entscheiden:**

- **Eine Prüfstelle oder eine Registrierung?** Eine Funktion `isFeatureEnabled(string $key)`
  wäre schnell gebaut, verlagert die Vollständigkeit aber weiter auf die Aufrufer. Eine Liste
  aller Funktionen mit ihren Ressourcen — analog zu den drei Listen in
  `private/helpers/demo_mode.php`, deren Vollständigkeit ein Test erzwingt — wäre die
  belastbarere Variante und passt zur bestehenden Konvention.
- **Was heißt „aus"?** Nur im Menü verbergen, oder der Endpunkt antwortet mit 403? Nur Letzteres
  ist eine Zusage.
- **Bestehende Daten.** Wird die Arbeitszeit abgeschaltet, sind die erfassten Sitzungen nicht
  weg. Bleiben sie über `my_data` und den Export erreichbar? Vermutlich ja — Abschalten ist
  keine Löschung, und der Auskunftsanspruch endet nicht mit einem Schalter.
- **Reihenfolge.** ~~Sinnvoll gemeinsam mit der Gruppierung der Einstellungen in Untertabs.~~
  **Erledigt am 2026-09-16, soweit die Oberfläche betroffen ist:** Die Untertabs stehen (1.9.0),
  und das Muster ist in ihrer Spec (3.2) festgeschrieben — ein Tab je Funktion, die erste Karte
  trägt den Schalter, abhängige Parameter darunter werden bei „aus“ per `disabled` gesperrt
  (umgesetzt für Zeiterfassung, Stations-PIN und Pünktlichkeit). Offen bleibt der Backend-Teil
  dieses Punktes: die gemeinsame Prüfstelle, 403 statt bloßem Verstecken und Schalter für
  Terminplanung und Anwesenheitserfassung. Die Einstellungsseite muss dafür nur ergänzt, nicht
  erneut umgebaut werden.

**Nicht sicherheitsrelevant:** Alle heutigen Prüfungen greifen, nur eben je Funktion
verschieden. Es geht um die Vollständigkeit künftiger Schalter, nicht um eine Lücke am Bestand.

---

### OI-63 · Rückmeldung für andere: kein Schutzschritt, keine Spur
**Priorität:** mittel · aufgenommen am 2026-09-16 · Schutzschritt erledigt in 1.11.0

`responsesResolveTarget()` (`private/handlers/appointment_responses.php`) erlaubt Admin und
Manager, mit `?member_id=<id>` die Rückmeldung eines **anderen** Mitglieds zu setzen oder zu
löschen. Das ist gewollt — jemand ruft an und sagt ab, der Dirigent trägt es ein. Zwei Dinge
fehlten drumherum:

1. ~~**Kein Schutzschritt in der Oberfläche.**~~ **Erledigt in 1.11.0:** Der
   Rückmeldungs-Dialog öffnet gesperrt; die Aktionsknöpfe fremder Zeilen sind ausgegraut
   (`title="Zum Ändern zuerst entsperren"`), bis das Schloss 🔒 im Spaltenkopf „Aktion" geklickt wird — je
   Dialogöffnung, nicht je Zeile, und beim nächsten Öffnen wieder zu. Die eigene Zeile bleibt
   ohne Entsperren bedienbar (`isOwnMember()`, `public/js/modules/responses.js`). Bewusst nur
   in der Oberfläche: Eine serverseitige Prüfung könnte nur wiederholen, was Rolle und
   Mitgliedsprüfung bereits leisten (siehe „Nicht sicherheitsrelevant" unten) — sie kann nicht
   feststellen, *ob ein Fehlklick vorlag*, nur *ob der Aufruf erlaubt ist*. Das entspricht der
   Einordnung: kein Rechteproblem, sondern ein Bedienschutz gegen den Fehlklick.
2. **Keine Spur.** Weder `appointment_responses` noch ein Protokoll hält fest, dass *jemand
   anderes* geschrieben hat. Nachträglich ist nicht unterscheidbar, ob ein Mitglied selbst
   abgesagt oder der Manager es für es getan hat — und auch nicht, wer. Die Arbeitszeit löst
   dieselbe Frage seit 1.2.0 über `work_session_log`. **Offen.**

Das wiegt schwerer, seit die Rückmeldung in die Zuverlässigkeitskennzahl einfließt (1.7.0): Ein
fremder Eintrag verschiebt eine Kennzahl, die einer Person zugerechnet wird.

**Zu entscheiden:**

- ~~**Schutzschritt:**~~ **Erledigt in 1.11.0** — ausdrückliches Entsperren je Dialog, die
  stärkere der beiden erwogenen Varianten, passend zum Vier-Augen-Gedanken aus
  [OI-3](#oi-3--vier-augen-prinzip-bei-manager-nachträgen). Nur UI-seitig (siehe oben).
- **Spur:** Reicht ein Feld `entered_by` in `appointment_responses` (billig, beantwortet „wer
  war es") oder braucht es ein Protokoll wie `work_session_log` (beantwortet zusätzlich „was
  stand vorher da")? Der Verlauf von Antwortänderungen ist in
  [OI-58](#oi-58--terminrückmeldung-bewusst-nicht-gebaut) bewusst abgelehnt worden — ein Feld
  für die Herkunft ist davon zu unterscheiden und widerspricht dem nicht.
- **Anzeige:** Wird ein fremder Eintrag dem Mitglied gegenüber kenntlich gemacht? Ohne das
  erfährt jemand nie, dass in seinem Namen geantwortet wurde. Mit FI-17
  (`docs/FEATURE-IDEAS.md`) wäre das der natürliche Ort dafür.

**Nicht sicherheitsrelevant im Sinne von `SECURITY.md`:** keine Rechteausweitung — wer das
kann, darf es bereits, und Rolle wie Mitgliedsprüfung greifen. Es fehlt die Nachvollziehbarkeit
einer erlaubten Handlung, nicht ihre Begrenzung.

---

### OI-64 · Im Kalender lässt sich kein Termin anlegen
**Erledigt** in 1.11.0

**Priorität:** niedrig · aufgenommen am 2026-09-16

`createCalendarDay()` (`public/js/modules/appointments.js`) hängt an einen Tag ohne Termine
keinen Handler. Ein Klick auf den 14. tut nichts; der Weg zu einem neuen Termin führt immer über
den Knopf und das Datumsfeld im Dialog. Erwartet wird von jedem Kalender das Gegenteil.

**Zu entscheiden:** Klick auf einen leeren Tag öffnet den Anlegen-Dialog mit vorbelegtem Datum —
sichtbar nur für Admin und Manager, da ein einfacher Nutzer keine Termine anlegt. Zu beachten
ist, dass Tage **mit** Terminen bereits ein Klickverhalten haben (Popup mit der Terminliste);
beides muss sich vertragen, statt einander zu überlagern.

**Vorschlag vom 2026-09-18:** Kein eigenes Kontextmenü. Es kostet beim leeren Tag einen Klick
mehr, und ein Rechtsklick ist auf dem Telefon nicht auffindbar. Stattdessen:

- **Leerer Tag** → Anlegen-Dialog direkt, Datum vorbelegt.
- **Tag mit Terminen** → das vorhandene Popup (`showAppointmentPopup()`) bleibt und bekommt je
  Termin „Bearbeiten" sowie unten „+ Termin an diesem Tag".

Damit gibt es ein Bedienmuster statt zwei. Für einfache Nutzer bleibt alles wie heute.
**Eingeplant** zusammen mit [FI-7](FEATURE-IDEAS.md#fi-7--terminserien-für-wiederkehrende-proben)
für 1.11.0, nach 1.10.0 — das Popup und der Termin-Dialog werden dort gerade um Ort und Ende
erweitert.

**Nicht sicherheitsrelevant.**

---

### OI-65 · Station: das Ruhebild leuchtet unvermindert weiter
**Priorität:** niedrig · aufgenommen am 2026-09-16

Die Station fällt nach `station_idle_seconds` auf das Ruhebild zurück
(`public/station/js/app.js`), zeigt dort aber unverändert helle Flächen in der Vereinsfarbe.
Ein Tablet, das im Probenraum dauerhaft hängt, brennt damit über Monate dasselbe Bild ein und
zieht rund um die Uhr Strom.

**Zu entscheiden:** Was im Ruhezustand passiert. Drei Stufen, aufsteigend im Aufwand:

1. **Gedämpftes Ruhebild** — nach einer zweiten, längeren Frist dunkle Darstellung mit
   reduzierter Helligkeit. Reines CSS, kein neues Recht, keine API.
2. **Bewegtes Ruhebild** — die Uhr wandert langsam über den Bildschirm, damit nichts einbrennt.
   Wenige Zeilen mehr.
3. **Dunkle Darstellung für die ganze Station** — als Einstellung oder über
   `prefers-color-scheme`. Das berührt die Vereinsfarben und das Branding und ist deshalb eine
   Gestaltungsentscheidung, keine technische.

Zur Energiefrage ehrlich bleiben: Spürbar spart eine dunkle Darstellung nur bei OLED. Die
üblichen Tablets im Vereinsheim haben LCD, dort hilft nur geringere Helligkeit oder ein
abgeschalteter Bildschirm — und Letzteres kann die Web-App nicht, siehe
[OI-32](#oi-32--wake-lock--kiosk-modus). Beides gehört zusammen entschieden: Es wäre
widersprüchlich, den Bildschirm per Wake Lock wachzuhalten und zugleich Strom sparen zu wollen.

**Nicht sicherheitsrelevant.**

---

### OI-66 · `API.md` gegen die echten Antworten prüfen
**Priorität:** mittel · aufgenommen am 2026-09-16

Beim Durchsehen der Dokumentation fiel auf, dass der Abschnitt „Alle Mitglieder abrufen" einen
Endpunkt beschrieb, den es so **nie gab**: eine Antwort `{"members": [...], "pagination": {…}}`
mit Seitenzahl, Gesamtzahl und Einträgen pro Seite, dazu verschachtelte `groups` und
`membership_dates`.

Tatsächlich liefert `GET ?resource=members` ein **nacktes Array**, ohne Umschlag und ohne jede
Paginierung — `private/handlers/members.php` enthält weder `LIMIT` noch `OFFSET` —, und die
Felder heißen anders (`group_ids` und `group_names` als kommagetrennte Zeichenketten,
`is_active_in_period`, `has_pin`, `pin_updated_at`). Serverseitig paginiert im ganzen Projekt
einzig `import_logs`; die Einstellung „Datenreihen pro Seite" wirkt allein im Browser.

**Der Mitglieder-Abschnitt ist korrigiert** (Branch `fix/api-korrekturen`). Offen ist die Frage,
die der Fund aufwirft: **Wie viele der übrigen Abschnitte beschreiben Wunschdenken?** Das
Beispiel sah plausibel aus und stand vermutlich seit der ersten Fassung darin; niemand hat es je
gegen eine laufende Instanz gehalten.

**Zweiter Fall, am selben Tag bestätigt:** Der Abschnitt „Anwesenheitsliste für Termin"
dokumentiert ein Array `attendance` mit `member_name` und `group_name`. Geliefert wird
`members`, und die Einträge tragen `name`, `surname`, `groups`, `record_id`, `checkin_source`.
Gegen die Testinstanz nachgestellt (Termin 104, HTTP 200): Schlüssel `appointment` und
`members`. Gemeldet aus dem parallelen Untergruppen-Vorhaben, das diesen Abschnitt in seiner
Dokumentationsaufgabe mitzieht — zwei unabhängige Funde an zwei Abschnitten machen den
Verdacht zur Regel.

**Warum das mehr als Kosmetik ist:** `API.md` ist die einzige Beschreibung der Schnittstelle für
alles, was nicht die mitgelieferte Oberfläche ist — eigene Skripte, die ESP32-Geräte, ein
späterer Fremdzugriff. Ein erfundenes Antwortformat fällt dort erst zur Laufzeit auf, und zwar
beim Anwender, nicht beim Entwickler.

**Zu tun:** Ressource für Ressource gegen die Testinstanz abrufen und Form, Feldnamen und
Statuscodes abgleichen. Der Weg steht schon: `tests/lib/api.php` spricht mit jeder Rolle, ein
kurzes Skript genügt je Endpunkt. Vorrangig die Lesepfade mit Beispielantwort in der
Dokumentation; Schreibpfade brauchen eine Welt zum Anlegen und Aufräumen.

**Zu entscheiden:** ob am Ende ein **Test** die Übereinstimmung festhält — etwa eine Suite, die
jeden in `API.md` ausgewiesenen Antwortschlüssel gegen eine echte Antwort prüft. Das wäre der
einzige Weg, der die Dokumentation dauerhaft ehrlich hält; es ist aber ein eigenes Vorhaben und
kein Nebenprodukt der Sichtung.

**Nicht sicherheitsrelevant:** falsche Dokumentation, keine falsche Berechtigung. Zu prüfen ist
allerdings, ob irgendwo **mehr** Felder beschrieben sind als der Server herausgibt — dann steht
dort ein Versprechen, das eine spätere Umsetzung einzulösen versuchen könnte.


---

### OI-67 · Offene Ansichten merken nicht, dass sich Daten geändert haben
**Priorität:** mittel · aufgenommen am 2026-09-16

Legt ein Manager im Dashboard einen Termin für heute an, während auf dem Telefon die
Check-in-PWA bereits geöffnet ist, fehlt dieser Termin in der Auswahl „Termin wählen …". Er
erscheint erst, wenn die Seite neu geladen wird.

**Der Service Worker ist es nicht.** `public/checkin/service-worker.js` speichert nichts
zwischen — jede Anfrage geht ans Netz (Zwischenspeicherung stillgelegt 2025-12-08, siehe
[OI-43](#oi-43--offline-betrieb-der-check-in-pwa)). Der veraltete Stand steckt im
JavaScript-Zustand der laufenden Seite: `loadCheckinAppointments()`
(`public/checkin/js/app.js`) läuft genau zweimal — beim Anmelden bzw. beim Start mit
gespeichertem Token und nach einem erfolgreichen Check-in. Danach nie wieder. Der einzige
`visibilitychange`-Hörer der Datei richtet den Sekundentakt der Uhr neu aus und holt keine
Daten. Eine PWA ist als Dauergast gebaut — sie bleibt auf dem Telefon tagelang offen —, und
genau dort fällt das auf.

**Gegenprobe im selben Modul:** Der Entschuldigungsdialog macht es richtig, `openExceptionModal()`
ruft `loadAppointments()` bei jedem Öffnen. Es fehlt also keine Technik, sondern eine Regel,
wann nachgeladen wird.

**Dasselbe Thema im Dashboard, andere Wurzel:** `dataCache` in `public/js/modules/ui.js` hält
Mitglieder, Termine, Aufzeichnungen, Ausnahmen und Arbeitszeiten zehn Minuten (`CACHE_TTL`).
`invalidateCache()` greift nach **eigenen** Mutationen; ändert ein *anderer* Benutzer etwas,
greift nichts. Zwei Manager an zwei Rechnern sehen bis zu zehn Minuten lang verschiedene Listen.
Bisher nicht als Fehler gemeldet, aber derselbe Sachverhalt — und der Grund, warum das hier als
eine Frage steht und nicht als zwei.

**Zu tun, vor jeder Lösung:** sichten, an welchen Stellen ein einmal geladener Stand beliebig
alt werden kann. Kandidaten sind alle Listen, die beim Start einmal gefüllt und danach nur nach
eigener Mutation erneuert werden — in der Check-in-PWA neben der Terminauswahl auch
Tätigkeitsarten und Einstellungen (`clientSettings`), in der Station die Mitgliederliste, im
Dashboard jeder Schlüssel des `dataCache`.

**Zu entscheiden,** aufsteigend im Aufwand:

1. **Beim Zurückkehren neu laden** — `visibilitychange` und `pageshow` lösen einen Nachlauf der
   Ladefunktionen aus. Wenige Zeilen, keine API-Änderung, deckt den beobachteten Fall
   vollständig ab (das Telefon wird aus der Tasche geholt, *dann* wird eingecheckt). Deckt
   **nicht** den Fall ab, dass die Seite ununterbrochen im Vordergrund liegt.
2. **Kurzes Nachladeintervall für die Terminauswahl**, etwa alle paar Minuten, solange die Seite
   sichtbar ist. Der Master-Tick (`tick()`) ist vorhanden und wäre der Aufhänger.
3. **Änderungsstempel serverseitig** — die Frage nach dem Dirty-Flag. Ein Flag *pro Client* gibt
   es nicht ohne Zustand auf dem Server; realistisch ist ein Stempel je Ressource, den eine
   offene Seite billig abfragt und nur bei Abweichung die volle Liste nachlädt. Der Haken ist
   die Datenbank: `appointments`, `members`, `records` und `exceptions` haben **kein**
   `updated_at` (nur `system_settings`, `work_sessions` und `appointment_responses` führen
   eines). Ein belastbarer Stempel bedeutet also eine Schemaänderung samt Migration; der billige
   Ersatz aus `MAX(id)` und Zeilenzahl erkennt Anlegen und Löschen, aber keine Bearbeitung.

**Verworfen, bevor es vorgeschlagen wird:** Push über SSE oder WebSocket. Das setzt einen
dauerhaft laufenden Prozess voraus und passt nicht zu PHP auf dem Standard-Hosting, für das
diese Anwendung gebaut ist.

**Nicht sicherheitsrelevant:** veraltete Anzeige, keine falsche Berechtigung. Der Server prüft
jeden Check-in unabhängig von dem, was die Auswahlliste zeigt — ein fehlender Eintrag führt
höchstens dazu, dass der automatische Terminabgleich greift oder der Check-in abgewiesen wird.

---

### OI-68 · „Mein Profil“: falscher Kartentitel, scheinbar gesperrtes Feld, veralteter Hinweistext
**Priorität:** erledigt am 2026-09-17 — mit 1.9.1, alle drei Punkte

Drei kleine Ungenauigkeiten auf der Profilseite, beim Umbau der Einstellungen (1.9.0) aufgefallen
und dort nur im Abgrenzungsabschnitt der Spec notiert. Keine davon ist ein Fehlverhalten des
Servers — alle drei führen den Nutzer in die Irre.

1. **Der Kartentitel nennt die falsche Anwendung.** Die Karte heißt „API-Token für
   Zeiterfassung“ ([`index.html:210`](../public/index.html)), der Text darunter erklärt: „Der
   API-Token wird für die Check-In App benötigt.“ Der Token ist der Zugang zur Check-in-PWA, die
   Zeiterfassung ist nur eine ihrer Funktionen und obendrein abschaltbar. Wer die Zeiterfassung
   nicht nutzt, hält den Token für überflüssig.

2. **Die Formatauswahl bei „Meine Daten“ sieht gesperrt aus, ist es aber nicht.** Das `<select>`
   trägt inline `background: #f8f9fa; cursor: not-allowed`
   ([`index.html:154`](../public/index.html)) — dieselbe Optik wie die echten Nur-Lese-Felder
   darüber (E-Mail, Rolle, verknüpftes Mitglied). Ausgewertet wird es sehr wohl:
   `downloadMyData()` liest den Wert ([`profile.js:279`](../public/js/modules/profile.js)). Wer
   die CSV will, probiert es gar nicht erst.

3. **Der Bestätigungsdialog zählt zu wenig auf.** Er nennt „Stammdaten, Anwesenheiten, Ausnahmen,
   Gruppenzugehörigkeiten“ ([`profile.js:277`](../public/js/modules/profile.js)). Tatsächlich
   enthält die Auskunft außerdem Mitgliedschaftszeiträume, Terminrückmeldungen, Arbeitszeiten samt
   Änderungshistorie sowie Pünktlichkeit und Zuverlässigkeit — seit OI-50 in **beiden** Formaten.
   Für eine Auskunft nach Art. 15 DSGVO ist eine zu kurze Aufzählung die unangenehmere Richtung:
   Sie erweckt den Eindruck, es werde weniger gespeichert, als es der Fall ist.

**Behoben am 2026-09-17** (1.9.1), alle drei. Der Dialog nennt jetzt keine Datenarten mehr,
sondern nur noch: „Die Datei enthält alles, was zu Ihnen gespeichert ist." Das ist die einzige
Formulierung, die beim nächsten Feature nicht wieder veraltet — und für eine Auskunft nach
Art. 15 DSGVO die richtige Richtung.

**Nicht Teil dieses Punktes: der Umbau der Seite zu Reitern.** „Mein Profil“ soll „Mein Konto“ mit
eigenen Tabs werden — entschieden am 2026-09-15, festgehalten in Abschnitt 8 der Spec
`docs/superpowers/specs/2026-09-16-einstellungen-untertabs-design.md`. Der Auslöser dafür ist
[FI-17](FEATURE-IDEAS.md#fi-17--offene-punkte-unter-mein-konto): Eine Sammelkarte der offenen
Punkte füllt einen Reiter von selbst. **Solange die Seite vier Karten hat, wäre eine Gliederung
ein zusätzlicher Klick ohne Gewinn** — anders als bei den Systemeinstellungen, wo zwölf Karten
untereinander standen.

**Wenn es so weit ist:** Das Muster der Einstellungen wiederverwenden, nicht kopieren.
`.settings-tabs`, `.settings-tab-btn` und `.settings-panel`
([`css/sections/settings.css`](../public/css/sections/settings.css)) sowie `showSettingsTab()`
und der gemerkte Reiter in `sessionStorage` ([`settings.js`](../public/js/modules/settings.js))
sind heute an die Einstellungsseite gebunden. Für eine zweite Seite gehören die Klassen nach
`css/components/` und die Umschaltlogik in einen gemeinsamen Helfer.

**Nicht sicherheitsrelevant:** Beschriftung und Darstellung, keine Datenänderung, kein
Rechtebezug. Punkt 3 berührt allerdings die Auskunftspflicht und ist deshalb der wichtigste der
drei.

---

### OI-69 · `PUT` auf Termine ist eine Vollersetzung — ein Teil-Update nullt Titel und Terminart
**Priorität:** erledigt am 2026-09-17 — mit 1.9.1, und nicht nur bei Terminen: Die drei als
„bisher nicht angesehen" vermerkten Ressourcen waren **alle** betroffen.

Dieselbe Familie wie [OI-54](#oi-54--put-auf-terminarten-überschreibt-nicht-mitgeschickte-felder),
nur bei `appointments` — dort geblieben, als die beiden Typ-Ressourcen in 1.9.0 umgestellt wurden.

Der `PUT`-Zweig ([`appointments.php:236`](../private/handlers/appointments.php)) baut `$data`
sauber über `isset()` aus den erlaubten Feldern. **Danach greift er unbedingt auf Felder zu, die
darin fehlen dürfen:**

| Stelle | Verhalten bei fehlendem Feld |
|---|---|
| `$newDateTime = $data->date . ' ' . $data->start_time` (Z. 247) | Notice, Vergleichswert wird `" "` |
| Konfliktprüfung mit `$data->type_id ?? null` (Z. 259) | `type_id = NULL` trifft nie — die Dublettenprüfung fällt still aus |
| `UPDATE … SET title=?, type_id=?, …` mit `$data->title` (Z. 285–287) | Notice `Undefined property: stdClass::$title`, gespeichert wird `NULL` |

Auffällig ist der Bruch mitten in der Funktion: `description` wird drei Zeilen über dem `UPDATE`
sorgfältig über `isset()` abgesichert, `title` und `type_id` nicht.

**Die schwerere Folge nennt nicht der Titel, sondern die Terminart.** Ein Termin ohne `type_id`
hat keine Gruppenzuordnung mehr — er verschwindet aus den Listen der Mitglieder, die ihn über
Terminart → Gruppe gesehen hätten, und zählt in keiner Auswertung mehr mit. Der fehlende Titel
fällt sofort auf, die fehlende Terminart nicht.

**Wie es aufgefallen ist:** beim Test der Untergruppen-Korrektur (1.9.0) in der parallelen
Sitzung — dort wurde ein Termin per Direkt-`PUT` verschoben und danach vollständig
wiederhergestellt. **Im Code belegt, nicht nachgestellt:** Für eine Reproduktion müsste ein echter
Termin verändert werden; die Zeilen oben sind eindeutig genug.

**Warum es im Betrieb bisher niemanden getroffen hat:** Die eigene Oberfläche schickt bei jedem
`PUT` alle fünf Felder mit ([`appointments.js:704`](../public/js/modules/appointments.js)). Wie
bei OI-54 trägt also allein die Disziplin des Aufrufers, dass nichts verloren geht — und `API.md`
weist die Vollersetzung nicht aus.

**Zu tun:** Dynamisches `UPDATE` wie bei `members`, `appointment_types` und `activity_types`
(1.9.0) — nur schreiben, was der Request enthält. Dazu die Konfliktprüfung: Fehlen `date` oder
`start_time`, gehören die gespeicherten Werte als Grundlage genommen, statt mit `" "` zu
vergleichen. `API.md` nachziehen, wie bei den beiden Typ-Ressourcen geschehen.

**Geprüft am 2026-09-17 — alle drei betroffen:**

| Ressource | Was ein Teil-Update zerstörte |
|---|---|
| `records` | `member_id`, `appointment_id` und `status` wurden genullt. Weil `NULL != member_id` als „geändert" galt, lief der Datensatz zusätzlich in die Dublettenprüfung |
| `exceptions` | `reason` und `requested_arrival_time` verschwanden bei jedem Genehmigen oder Ablehnen — `API.md` dokumentierte genau diesen Aufruf (`{"status": "approved"}`) als richtigen Weg |
| `membership_dates` | `start_date` wurde genullt, `status` fiel auf `active` zurück. Wer nur das Enddatum nachtrug, führte einen beendeten Zeitraum danach wieder als laufend |

**Behoben am 2026-09-17** in allen vier Ressourcen, dazu die Konfliktprüfung (sie rechnet jetzt
mit dem Zustand **nach** dem Update) und `404` statt Erfolg bei unbekannter `id`.

**Nebenbei richtiggestellt:** Der Feldaufbau nutzt `property_exists` statt `isset`. Ein
ausdrückliches `null` ist eine Angabe („Beschreibung löschen"), ein fehlendes Feld ist keine —
`isset()` warf beides in denselben Topf. Bei `records` bleiben „fehlt" und „ist leer" bewusst
verschieden: Ein Leerstring löscht die Ankunftszeit weiterhin, ein fehlendes Feld lässt sie
stehen. Beide Fälle stehen als Gegenprobe nebeneinander in der Suite.

**Acht neue Fälle** in `tests/suites/partial_update_api.php`, alle gegen den alten Stand rot.

**Nicht sicherheitsrelevant:** setzt ein Konto mit Schreibrecht auf Termine voraus (Admin oder
Manager) und erweitert keine Rechte. Es zerstört Daten, die derselbe Aufrufer ohnehin ändern
dürfte.

---

### OI-70 · Statistik nach Untergruppe rechnet nicht
**Priorität:** mittel — Entscheidung getroffen, Umsetzung offen · aufgenommen am 2026-09-17

Wählt man in der Statistik eine Gruppe, die als Untergruppe markiert ist (im Musikverein das
Register), bleibt die Auswertung leer. Das ist kein Fehler, sondern die Folge der Datenlage: Die
Statistik ermittelt ihren Bereich über Terminarten und deren Gruppen. Einem Register sind in der
Regel keine Terminarten zugeordnet, also gibt es keine Termine, über die gerechnet werden könnte.
Seit 1.8.0 erklärt ein Hinweis in der Statistik genau das, statt eine stumme leere Tabelle zu
zeigen ([`statistics.js`](../public/js/modules/statistics.js)).

**Entschieden am 2026-09-16:** Die richtige Auswertung soll kommen — gerechnet über die
**Mitglieder** der Untergruppe, ausgewertet über die Termine, zu denen diese Mitglieder über ihre
Zugehörigkeitsgruppen erwartet werden. Das ist die eigentlich interessante Auskunft („wie
zuverlässig ist das Register Klarinette?“) und der Grund, warum die Gliederung überhaupt gebaut
wurde.

**Warum es eine eigene Spec braucht:** Die Auswertung läuft heute konsequent über Terminarten. Ein
zweiter Weg daneben wirft Fragen auf, die zusammenpassen müssen: Welche Termine zählen für ein
Register — alle, zu denen seine Mitglieder erwartet werden, oder nur die der Terminarten, denen es
selbst zugeordnet ist? Wie verhält sich die Zuverlässigkeit aus 1.5.1 dazu? Und wie zählt jemand,
der in zwei Registern steht — einmal je Register oder anteilig? Ohne Antworten darauf entsteht
eine Zahl, der man nicht ansieht, was sie misst.

**Was heute schon geht:** Wer eine Registerstatistik braucht, ordnet das Register einer Terminart
zu (die Registerprobe) — dann rechnet die Statistik für diese Terminart. Das deckt den
Registerproben-Fall ab, nicht die Frage nach der Zuverlässigkeit eines Registers über alle
Termine.

**Berührt:** `private/handlers/statistics.php`, `private/helpers/punctuality.php`,
`public/js/modules/statistics.js`. Siehe auch
[FI-14](FEATURE-IDEAS.md#fi-14--untergruppen-register-und-besetzungsübersicht) — dort bleibt die
Besetzungsübersicht mit Sollstärke offen, die auf derselben Gliederung aufsetzt, aber eine andere
Frage beantwortet.

---

### OI-71 · Zwei Kleinigkeiten in den Dashboards
**Priorität:** erledigt am 2026-09-17 — mit 1.9.1, beide Punkte

Aus dem manuellen Test zum Untergruppen-Vorhaben (2026-09-16), bewusst zurückgestellt, weil ohne
Bezug zu diesem Vorhaben:

1. **Mitglieder-Dashboard: die Zahl der inaktiven Mitglieder fehlt.** Die Kennzahlenkarte zeigt
   das Häkchen für inaktive Mitglieder, aber nicht, wie viele es sind. Wer wissen will, wie viele
   Karteileichen im Bestand stehen, muss filtern und zählen.
2. **Statistik-Dashboard: kein „Filter zurücksetzen“.** Andere Ansichten haben einen Knopf dafür,
   die Statistik nicht — nach mehreren gesetzten Filtern bleibt nur, sie einzeln zurückzustellen
   oder die Seite neu zu laden.

**Behoben am 2026-09-17** (1.9.1).

**Beim Bauen zeigte sich ein zweiter Boden:** Die Kennzahlen rechneten über die **gefilterte**
Liste. Aus ihr gerechnet, hätte die neue Zahl genau dann 0 gezeigt, wenn das Häkchen daneben aus
ist — also immer dann, wenn jemand die Frage nach den Karteileichen überhaupt stellt. Beide
Zahlen kommen jetzt aus dem Gesamtbestand des gewählten Jahres; die Tabelle darunter zeigt das
Filterergebnis ohnehin.

Das Zurücksetzen in der Statistik lässt das Jahr stehen — wie bei den anderen Ansichten, deren
Knopf ebenfalls nur die Filterleiste meint.

**Nicht sicherheitsrelevant:** beides reine Anzeige und Bedienung.

---

### OI-72 · Zwei Reste aus der PWA-Überarbeitung
**Priorität:** niedrig · aufgenommen am 2026-09-17

Aus der Arbeit an der Check-in-PWA für 1.9.1 (`8f47006`, `044797d`), dort bewusst
zurückgestellt und nicht release-blockierend. Hier festgehalten, damit sie nicht allein in
einer Sitzungsnachricht stehen.

1. ~~**Die Trennlinie zwischen den Antwortgruppen fehlt im Dashboard.**~~ **Verworfen am
   2026-09-17 (Nutzerentscheidung): Im Dashboard genügt die vorhandene graue Zeile.** Der
   Befund als solcher stimmte und wurde bestätigt: `namesListHtml()`
   ([responses.js](../public/js/modules/responses.js)) erzeugt dieselben
   `.response-name-group`-Blöcke wie die PWA, und die Regel
   `.response-name-group + .response-name-group { border-top: ... }` steht allein in
   ([checkin/css/style.css](../public/checkin/css/style.css)). Nachgezogen wird sie trotzdem
   nicht — im Dashboard gliedern der größere Abstand und die graue Gruppenzeile
   (`.response-group-row`) bereits ausreichend.

   Dieselbe Frage wie bei OI-68: Zwei Oberflächen teilen sich ein Markup, aber nicht sein CSS.
   Beim nächsten gemeinsamen Baustein gehören die Klassen nach `css/components/`, statt sie zu
   verdoppeln.

2. **„Antrag stellen" bricht bei 320 px auf zwei Zeilen um.** Im Dialog „Nachträglicher Antrag";
   ab 360 px einzeilig. **Übernommen aus der PWA-Sitzung, hier nicht nachgestellt** — der Knopf
   sitzt in einem geschlossenen Modal und lässt sich ohne Anmeldung nicht messen.

**Nicht sicherheitsrelevant:** beides Darstellung.

---

### OI-73 · `:has()`-Selektor hängt an einer Inline-Schreibweise
**Priorität:** niedrig · aufgenommen am 2026-09-18

[sections/content.css](../public/css/sections/content.css) schaltet die Spaltenzahl der
Statistik-Filterkarte über

```css
.filter-grid:has(#statMemberFilterGroup[style*="display: none"])
```

Der Selektor trifft auf die **Zeichenfolge** des Inline-Styles. `statistics.js` setzt ihn heute
als `display: none` mit Leerzeichen; schriebe jemand `display:none`, griffe die Regel
stillschweigend nicht mehr, und die Karte bliebe zweispaltig mit einer leeren Spalte.

Darunter stehen bereits `.filter-grid.single-filter` und `.filter-grid.dual-filter` — zwei
Klassen, die genau dafür gedacht waren und die niemand setzt. Der saubere Weg wäre, sie in
`statistics.js` zu vergeben und die beiden `:has()`-Regeln zu entfernen.

**Nicht dringend:** Der heutige Zustand funktioniert. Es ist eine Falle für den Nächsten, kein
Fehler. Aufgefallen bei den Filterleisten für 1.9.2 (Spec
`2026-09-17-dashboard-filterleisten-design.md`), dort bewusst nicht mitgenommen.

---

### OI-74 · Der Cache-Bust erreicht nur einen Teil der Dateien
**Priorität:** niedrig · aufgenommen am 2026-09-18

Jede HTML-Einstiegsseite hängt `?v=<version>` an ihre Assets, und `tests/suites/assets.php`
erzwingt, dass der Wert zu `version.json` passt. Im Dashboard erreicht das aber nur einen
Bruchteil dessen, was sich ändert:

- `public/index.html` bindet ein einziges Stylesheet ein, `css/main.css?v=…`. `main.css` lädt
  alles Weitere per `@import url('…')` **ohne** Parameter — `components/*.css` und
  `sections/*.css` bleiben vom Versionssprung unberührt.
- Die ES-Module unter `js/modules/` tragen gar keinen Parameter; sie werden per `import`
  nachgeladen, nicht per `<script src>`.

Die PWA und die Station sind nicht betroffen: Sie binden je ein Stylesheet und ein `app.js`
direkt ein, beide mit Parameter.

Praktisch fällt das nicht auf, weil [public/.htaccess](../public/.htaccess) für `.css`, `.js`
und `.html` `Cache-Control: no-cache, must-revalidate` setzt — der Browser fragt ohnehin jedes
Mal nach. Der Block steht aber in `<IfModule mod_headers.c>`. Auf einem Hosting ohne
`mod_headers` fehlen damit der Header **und** ein wirksamer Cache-Bust; ein Mitglied sähe nach
einem Update alte Oberfläche zu neuer Logik, bis sein Browser-Cache von selbst verfällt.

**Mögliche Antworten**, keine ohne Preis:

- Die `@import`-Zeilen in `main.css` mit demselben Parameter versehen — ohne Build-Kette nur von
  Hand pflegbar; `assets.php` müsste `main.css` mitprüfen.
- Die Teil-Stylesheets einzeln in `index.html` einbinden — achtzehn `<link>`-Zeilen statt einer.
- Für die Module eine Import-Map mit versionierten Pfaden — sauber, aber ein neues Konzept im
  Projekt.

Entschieden ist nichts. Aufgefallen, als die Spec für 1.9.2 den Versionssprung zunächst mit
dem Cache begründete; die Begründung hielt der Prüfung nicht stand.

**Nicht sicherheitsrelevant:** betrifft nur die Aktualität der Oberfläche.

---

### OI-75 · CSV-Import ändert Serientermine, ohne sie abzulösen
**Priorität:** erledigt am 2026-09-22 — mit 1.12.0 (Branch `fix/oi-10-oi-75`). Entschieden: ablösen nur bei
tatsächlicher Änderung. `importAppointments()` vergleicht Titel, Beschreibung und, falls die Datei
sie führt, Ort und Ende über `appointmentFieldsChanged()` (`private/helpers/appointment_rules.php`,
dieselben Regeln wie `appointmentFieldChanged()` beim `PUT`) und setzt dann `is_detached = 1`. Ein
Reimport derselben Werte, auch als `19:30` statt `19:30:00`, löst nicht ab. Tests:
`tests/suites/import_series_api.php`. Nebenbei in `API.md` korrigiert: `type` steht beim Import
in der Query, nicht im Formular.

`importAppointments()` (`private/handlers/import.php`) erkennt einen bestehenden Termin über
Terminart, Datum und Startzeit (`WHERE type_id = ? AND date = ? AND start_time = ?`) und
aktualisiert ihn dann per `UPDATE` — Titel und Beschreibung immer, Ort und Ende, wenn die Datei
die Spalte führt. `series_id` und `is_detached` bleiben dabei unberührt: Ein Serientermin, den der
Import verändert, sieht sich weiterhin als „folgt der Serie".

**Wirkung:** Ändert eine spätere Serienaktion „Dieser und alle folgenden" (`PUT
appointment_series`, siehe `API.md`) danach dieselben Felder,
überschreibt sie die per Import eingespielten Werte wieder — stillschweigend, ohne Warnung. Der
umgekehrte Fall (Import nach einer Serienänderung) betrifft dagegen nur den einen importierten
Termin und ist unauffällig.

**Zu entscheiden:** Beim Import ablösen (`is_detached = 1`, wie ein Einzel-`PUT`) oder nicht.
Dafür spricht Konsistenz mit `PUT appointments`; dagegen, dass ein reiner CSV-Reimport (dieselben
Werte erneut) heute keine Änderung ist und auch keine sein sollte — `appointmentFieldChanged()`
wird hier nicht gerufen, der Import setzt also selbst bei identischen Werten ab, sobald sich das
ändert.

**Nicht sicherheitsrelevant.**

---

### OI-76 · Wettlauf zweier gleichzeitiger Einzel-Löschungen kann einen Ausfall verlieren
**Priorität:** niedrig · aufgenommen am 2026-09-21

`DELETE appointments` (`private/handlers/appointments.php`) trägt das Datum eines gelöschten
Serientermins über `seriesAddExdates()` (`private/helpers/appointment_series.php`) in `exdates`
der Serie ein: lesen (`seriesLoad()`), im PHP-Array ergänzen, komplett zurückschreiben
(`seriesSaveExdates()`) — ohne `SELECT … FOR UPDATE` und ohne eigene Transaktion.

**Wettlauf:** Löschen zwei Anfragen nahezu gleichzeitig zwei **verschiedene** Termine derselben
Serie, können beide `seriesLoad()` vor der ersten `seriesSaveExdates()` lesen. Die zweite
schreibt dann ihren Stand (Ausgangsliste plus ihr eigenes Datum) über den der ersten — deren
Datum fehlt danach in `exdates`. Beide Termine sind trotzdem gelöscht (`DELETE FROM appointments`
läuft unabhängig davon), nur der Ausfall-Eintrag der zuerst geschriebenen Anfrage geht verloren:
„Serie fortsetzen" würde diesen Tag dann erneut anlegen, obwohl der ursprüngliche Termin bewusst
gelöscht wurde.

**Selten** — zwei Löschungen derselben Serie innerhalb von Millisekunden sind ein
Admin-Doppelklick oder zwei gleichzeitig arbeitende Verwalter, kein Alltagsfall.

**Zu tun:** `seriesAddExdates()` (bzw. der Aufruf in `appointments.php`) in eine kleine
Transaktion mit `SELECT … FOR UPDATE` auf die Serienzeile fassen — wie `seriesFollowing()` es für
die Serienaktionen bereits tut (siehe dessen Kommentar zur `FOR UPDATE`-Sperre).

**Nicht sicherheitsrelevant.**

---

### OI-77 · Offene Zeitkorrektur kann an einer verschobenen Serie scheitern
**Priorität:** niedrig · aufgenommen am 2026-09-21

Ein Mitglied beantragt für einen künftigen Serientermin ohne erfasste Anwesenheit eine
Zeitkorrektur (`exceptions`, `exception_type = 'time_correction'`, noch `pending`). Die
gewünschte Ankunft wird beim Anlegen **und** bei jeder weiteren Änderung (auch der Genehmigung)
gegen den *aktuellen* Termin geprüft (`arrivalWithinAppointmentWindow()`,
`private/handlers/exceptions.php`, Zeilen ~194 und ~264).

`PUT appointment_series` „Dieser und alle folgenden“ (`seriesHandleUpdateFollowing()`,
`private/handlers/appointment_series.php`) schützt vor dem Mitziehen von `start_time` nur, wenn
bereits **Anwesenheit** erfasst ist (`appointmentHasAttendance()` prüft ausschließlich
`records`) — eine offene Zeitkorrektur allein hält den Beginn nicht fest, sie zählt bewusst nicht
als „Termin mit Daten“ im engen Sinn dieser Prüfung (siehe Kommentar dort: „ein zukünftiger
Probentermin, zu dem schon zugesagt wurde, muss verschiebbar bleiben“).

**Wirkung:** Verschiebt die Serie den Beginn, bleibt die gespeicherte `requested_arrival_time`
unverändert stehen. Ändert das Mitglied danach nur die Bemerkung, oder genehmigt ein Verwalter
den Antrag unverändert, prüft `arrivalWithinAppointmentWindow()` dieselbe Ankunftszeit erneut —
jetzt gegen den **neuen** Beginn. Eine beim Beantragen gültige Ankunft kann dadurch außerhalb des
Toleranzfensters liegen und die Genehmigung mit `400 "Die angegebene Ankunftszeit liegt zu weit
vom Termin entfernt"` scheitern, obwohl der Antrag zum Zeitpunkt der Beantragung korrekt war.

**Zu entscheiden:** Eine offene Zeitkorrektur beim Verschieben des Beginns automatisch mit
anpassen (schwierig — welcher Bezug gilt: absolute Uhrzeit oder Abstand zum Beginn?), ablösen wie
bei erfasster Anwesenheit, oder die Prüfung beim Genehmigen entschärfen. Betrifft nur den
seltenen Fall einer offenen Zeitkorrektur auf einem noch nicht stattgefundenen Serientermin.

**Nicht sicherheitsrelevant.**

---

### OI-78 · „Serie fortsetzen" bleibt auf einer durch Split abgelösten Serie verfügbar
**Priorität:** niedrig · aufgenommen am 2026-09-21

Ein Split (`POST appointment_series?action=split`, „Regel ändern ab diesem Termin") beendet die
**alte** Serie am Vortag von `from_date` und legt eine **neue** Serie ab `from_date` an — die
alte Serienzeile bleibt bestehen, solange noch ein Termin **vor** `from_date` auf sie zeigt
(`seriesEndFrom()`, `private/helpers/appointment_series.php`).

`renderSeriesBox()` (`public/js/modules/appointments.js`) zeigt „Serie fortsetzen …" für **jeden**
nicht abgelösten Serientermin, unabhängig davon, ob für die Serie inzwischen bereits ein
Nachfolger existiert. Öffnet jemand einen der frühen, noch zur alten Serie gehörenden Termine und
wählt „Serie fortsetzen …", verlängert das die **alte** Serie ab ihrem (durch den Split
verkürzten) `until` — in den Zeitraum, den die **neue** Serie bereits abdeckt.

**Ob daraus wirklich unbemerkte Doppeltermine entstehen, hängt davon ab, worin sich Alt- und
Neu-Serie unterscheiden.** `seriesInsertOccurrences()` prüft jedes Datum erneut gegen
`findAppointmentConflict()` (gleiche Terminart, Beginn innerhalb der Toleranz,
`checkin_tolerance_hours`) — traf der Split nur Terminart oder Uhrzeit und lässt Regel und
Wochentag unverändert, kollidiert die verlängerte alte Serie an denselben Tagen mit den bereits
vorhandenen Terminen der neuen Serie und wird dort **ausgelassen und gemeldet** (`skipped`), wie
bei jeder anderen Kollision auch — kein stiller Doppeltermin, nur eine überraschende Meldung.
**Echte, unbemerkte Doppeltermine entstehen nur, wenn die Dublettenprüfung nicht greift:**
unterscheidet sich die Terminart, oder liegt die Uhrzeit weiter als die Toleranz auseinander,
legt die verlängerte alte Serie zusätzliche Termine an, ohne dass die neue Serie das verhindert.
Änderte der Split zusätzlich den Wochentag, treffen beide Serien ohnehin unterschiedliche Tage —
auch dann keine erkannte Kollision, aber zwei parallel laufende Serien, von denen nur eine
gewollt ist.

**Zu entscheiden:** Die Aktion ausblenden, sobald ein Nachfolger existiert (erfordert eine
Erkennung „gibt es eine jüngere Serie mit `start_date` = `until` + 1 Tag, derselben Regel-Herkunft
o. Ä." — die Datenbank hält diese Beziehung heute nicht fest), oder den Zustand hinnehmen und nur
in der Dokumentation/Oberfläche vor doppelter Verlängerung warnen.

**Nicht sicherheitsrelevant.**

---

### OI-79 · Jahresauswahl zeigt ein neues Serienjahr erst nach Neuladen
**Priorität:** niedrig · aufgenommen am 2026-09-21

Die verfügbaren Jahre kommen aus `dataCache.availableYears` (`public/js/modules/ui.js`), einem
globalen (nicht jahresabhängigen) Cache-Schlüssel mit der üblichen TTL von 10 Minuten. Serien-
und Termin-Mutationen invalidieren gezielt `appointments` für die betroffenen Jahre, nicht aber
`availableYears`.

**Wirkung:** Reicht eine neu angelegte oder verlängerte Serie erstmals in ein Jahr, für das bisher
kein Termin existierte, fehlt dieses Jahr im Jahresfilter, bis der Cache abläuft oder die Seite
neu geladen wird — die neuen Termine dort sind zwar in der Datenbank, aber über die Jahresauswahl
nicht erreichbar.

**Zu tun:** Serienaktionen, die `until` über ein bisher unbeteiligtes Jahr hinaus verschieben
(Anlegen, Split, Fortsetzen), invalidieren zusätzlich `availableYears`.

**Nicht sicherheitsrelevant.**

---

### OI-80 · Kalendertage mit Terminen: keine Tastaturbedienung, Feiertag fehlt im Vorlesetext
**Priorität:** niedrig · aufgenommen am 2026-09-21

`createCalendarDay()` (`public/js/modules/appointments.js`) behandelt zwei Tagesarten
unterschiedlich:

- **Leerer Tag** (seit 1.11.0, OI-64): `tabindex="0"`, `role="button"`, `aria-label` mit dem
  Anlege-Hinweis — per Tastatur erreichbar und bedienbar.
- **Tag mit Terminen** (Zeilen ~550–616): nur `mouseenter`/`mouseleave`/`click`, **kein**
  `tabindex`, **kein** `keydown`-Zuhörer. Ein Tastaturnutzer kann das Popup dieses Tages nicht
  öffnen — vorbestehend, nicht durch dieses Vorhaben verursacht, aber mit dem Feiertagsteil von
  FI-16 zusätzlich relevant geworden, weil solche Tage jetzt zusätzliche Information tragen.

Das `aria-label` dieser Tage (Zeile ~585) listet Uhrzeit, Terminart, Titel und
Rückmeldungszusammenfassung je Termin, nennt aber **nicht**, wenn der Tag zusätzlich ein
Feiertag ist (`calendar-day--holiday`, `holidaysOfYear()`) — ein Screenreader-Nutzer erfährt den
Feiertagsnamen nicht, den sehende Nutzer als Text im Tagesfeld sehen.

**Zu tun:** Tage mit Terminen ebenfalls `tabindex="0"` und einen `keydown`-Zuhörer (Enter/Leertaste
öffnet das Popup) geben; den Feiertagsnamen, sofern vorhanden, vorn ins `aria-label` aufnehmen.

**Nicht sicherheitsrelevant.**

---

### OI-81 · Serie ohne Terminart: „Serie fortsetzen" legt Termine ohne Dublettenschutz und ohne Gruppe an
**Priorität:** niedrig · aufgenommen am 2026-09-21

`{PREFIX}appointment_series.type_id` verweist mit `ON DELETE SET NULL` auf `appointment_types`
(`series_type_fk`, `private/setup/ehrensache_db.sql`). `DELETE appointment_types`
(`private/handlers/appointment_types.php`) prüft vor dem Löschen nicht, ob noch eine Serie (oder
ein Einzeltermin) auf die Terminart zeigt — ein Admin kann eine Terminart löschen, die eine
laufende Serie noch trägt. Die Serienzeile bleibt bestehen, ihr `type_id` wird `NULL`.

**Wirkung:** `seriesHandleExtend()` („Serie fortsetzen") liest die Vorlage unverändert aus der
Serie (`seriesTemplateOf()`) und legt Termine mit `type_id = NULL` an:

- **Keine Dublettenprüfung.** `findAppointmentConflict()` liefert bei `typeId === null` immer
  `null` (Kommentar dort: „Ohne Terminart gibt es keine Dublette") — zwei fortgesetzte Serien
  ohne Terminart könnten beliebig oft auf denselben Tag treffen, ohne dass es auffällt.
- **Keine Gruppe.** Die Gruppen eines Termins ergeben sich aus `appointment_type_groups` über
  die Terminart (`API.md`, Abschnitt „Termine"); ohne Terminart hat der Termin keine Gruppe und
  ist für einfache Nutzer unsichtbar (Terminliste, Kalender-Rückmeldung, Check-in) — sichtbar nur
  für Admin und Manager, die ohne Gruppengrenze sehen.
- `POST appointment_series` (Anlegen) und `action=split` fangen den Fall meist ab
  (`seriesResolveType()` setzt eine fehlende oder leere `type_id` auf die Standard-Terminart) —
  nur ohne jede Standard-Terminart könnten auch sie mit `type_id = NULL` anlegen. Der hier
  beschriebene Weg über eine **bestehende**, nachträglich typlos gewordene Serie ist der
  naheliegendere: „Serie fortsetzen" übernimmt die gespeicherte Vorlage unverändert und fragt
  nicht erneut nach der Terminart.

**Zu entscheiden:** `POST appointment_series?action=extend` mit `400` ablehnen, wenn die
Vorlage der Serie `type_id = NULL` trägt (zwingt zu „Regel ändern …" / Split mit neuer
Terminart, statt stillschweigend weiterzulaufen) — oder generell verhindern, dass eine Terminart
gelöscht wird, solange eine Serie oder ein Termin auf sie zeigt (würde auch den analogen, schon
länger bestehenden Fall bei gewöhnlichen Einzelterminen mit erledigen, der hier nicht neu ist).

**Nicht sicherheitsrelevant.**

---

### OI-82 · Nachträglicher Zeitantrag auch für Termine in der Zukunft
**Priorität:** erledigt am 2026-09-22 — `95141c4`. Server: `timeCorrectionTooEarly()` in
`private/helpers/utils.php`, gilt für `POST` und `PUT`. Verglichen wird mit der Uhr der
Datenbank, mit 5 Minuten Spielraum für die Uhr des Telefons. PWA: Terminliste und Ankunftszeit
enden bei jetzt. Entschieden: Auch die Genehmigung eines Altbestands wird abgewiesen, die
Ablehnung nie. Die Ausnahme fürs Ablehnen gilt auch an der vorhandenen Fensterprüfung: Ein
Antrag zu einem verschobenen Termin ließ sich vorher gar nicht mehr bescheiden. Drei neue Tests
in `arrival_api` scheitern ohne die Korrektur. Die PWA ist im Browser geprüft (M002: drei
künftige Termine nicht mehr angeboten).

Der Dialog „Nachträglicher Antrag“ der Check-in-PWA bietet Termine der letzten drei Tage **und
alle künftigen** an. `loadAppointments()` in `public/checkin/js/app.js` (~Zeile 2248) filtert
bewusst so („Letzte 3 Tage + Zukunft (für nachträgliche Anträge)“). Ein Zeitantrag
(`exception_type = time_correction`) behauptet aber eine Ankunft, die schon stattgefunden hat —
für einen Termin in drei Wochen ergibt er keinen Sinn.

**Der Server fängt es nicht ab.** `POST exceptions` (`private/handlers/exceptions.php`, ~Zeile
194) prüft die Wunschzeit nur mit `arrivalWithinAppointmentWindow()` (`private/helpers/utils.php`)
gegen das Fenster **um den Termin**, nicht gegen die aktuelle Uhrzeit. Liegt der Termin in der
Zukunft, liegt auch jede gültige Wunschzeit in der Zukunft — und wird angenommen. Dasselbe gilt
für den `PUT`-Pfad (~Zeile 264). Die API ist direkt aufrufbar, eine Sperre allein in der PWA
reicht deshalb nicht.

**Wirkung:** Ein Mitglied kann für einen künftigen Termin eine Ankunft beantragen. Genehmigt ein
Verwalter den Antrag, entsteht über `handleApprovedTimeCorrection()` ein Anwesenheitseintrag für
einen Termin, der noch nicht stattgefunden hat — er zählt in Quote und Pünktlichkeit. Eine
Rechteausweitung ist es nicht: Der Antrag bleibt `pending`, bis jemand mit Verwalterrechten ihn
bescheidet.

**Zu tun:**

- **Server:** Für `time_correction` die Wunschzeit zusätzlich gegen die aktuelle Zeit prüfen
  (`requested_arrival_time <= jetzt`), in `POST` und `PUT`, mit `400` und verständlicher
  Meldung. Zeitbasis wie bei [OI-60](#oi-60--zeitbasis-der-terminrückmeldung-php-uhr-statt-mysql-uhr)
  beachten. Test in einer API-Suite.
- **PWA:** Terminliste des Dialogs auf Termine begrenzen, deren Fenster schon begonnen hat
  (Beginn minus `checkin_tolerance_hours` ≤ jetzt).
- **Nicht betroffen:** `absence`. Eine Entschuldigung im Voraus ist gewollt — die
  Terminrückmeldung legt genau so eine an (`responseExcuseAction()`).
- **Zu entscheiden:** Soll auch die Genehmigung ablehnen, wenn die Wunschzeit noch in der Zukunft
  liegt? Betrifft nur Anträge, die vor der Korrektur angelegt wurden.

**Nicht sicherheitsrelevant im Sinne von `SECURITY.md`:** ohne Verwalterfreigabe wirkungslos.

---

### OI-83 · Arbeitszeit mit Ortsnachweis ohne Kamera nicht startbar
**Priorität:** erledigt am 2026-09-22 — `7e12a7b`, alle drei Punkte unter „Zu tun“ umgesetzt
und im Browser geprüft: Start und Stopp per Handeingabe, simulierte verweigerte Kamera öffnet
die Eingabe. Nicht nachgestellt ist der Aufruf über HTTP im Netzwerk (`localhost` gilt als
sicherer Kontext).

**Nebenbefund:** Die Fehlermeldung unterscheidet die Ursachen nicht wirklich. `html5-qrcode`
reicht den Fehler als Text weiter, `error.name` ist nie `NotAllowedError`. Deshalb erscheint
immer „Kamera-Zugriff nicht möglich“. Seit die Handeingabe folgt, ist das nur noch kosmetisch.

Verlangt eine Tätigkeitsart einen Ortsnachweis, ruft `worktimeStart()` (`public/checkin/js/app.js`,
~Zeile 3520) direkt `toggleScanner()` auf. Dasselbe tut `worktimeStop()` bei `start_end`. Die
Handeingabe des Codes ist in der Arbeitszeit-Ansicht nur über `scanManualBtn` erreichbar, und
der sitzt in `#scannerActions` — sichtbar **nur, solange der Sucher läuft**
(`setScannerActionsVisible()`). Der Knopf „Code manuell eingeben“ (`manualCodeBtn`) steht allein in
der Ansicht „Anwesenheit erfassen“.

Scheitert der Kamerastart, fängt `toggleScanner()` den Fehler, setzt den Zustand auf `IDLE` und
zeigt eine Meldung. Der Sucher läuft dann nicht, also gibt es auch keinen Weg zur Handeingabe.
Betroffen sind:

- verweigerte Kamerafreigabe (`NotAllowedError`),
- Geräte ohne Kamera (`NotFoundError`), Kamera belegt (`NotReadableError`),
- Aufruf über HTTP statt HTTPS: Außerhalb eines sicheren Kontexts gibt es
  `navigator.mediaDevices` nicht, der Start scheitert immer.

**Wirkung:** Für Tätigkeiten mit Ortsnachweis lässt sich die Zeiterfassung auf solchen Geräten gar
nicht starten, obwohl der sechsstellige Code am Ort ablesbar wäre. Als Ausweg bleibt nur „Zeit
nachtragen“ — ohne Ortsnachweis und mit Freigabepflicht.

**Zu tun:**

- Knopf „Code eingeben“ in der Arbeitszeit-Ansicht neben „Start“ bzw. „Stopp“ — sichtbar, wenn
  die gewählte Tätigkeit (bzw. die laufende Sitzung) einen Nachweis verlangt. Er öffnet das
  vorhandene `manualCodeModal`. `deliverTotpCode()` leitet den Code nach der sichtbaren Ansicht
  weiter und braucht keine Änderung.
- Scheitert der Kamerastart, direkt die Handeingabe anbieten statt nur der Fehlermeldung — für
  beide Ansichten.
- Ohne `window.isSecureContext` den Kameraweg gar nicht erst versuchen und gleich die Handeingabe
  öffnen.

**Nicht sicherheitsrelevant:** Der Code wird serverseitig geprüft, egal auf welchem Weg er
eingegeben wird.

---

### OI-84 · Leeres Filterergebnis lässt die alte Paginierung stehen
**Priorität:** erledigt am 2026-09-22 — `500ad02`, im Browser als Admin geprüft. Anwesenheiten:
von Seite 2 bei 942 Einträgen auf eine Terminart ohne Einträge, danach ist die Paginierung leer.
Mitglieder: dasselbe, mit „Keine Mitglieder für diese Auswahl“. Anträge: Im Bestand gibt es nur
20, also keine Paginierung. Der leere Zweig läuft dort trotzdem sauber durch. Weil im Bestand
jede Terminart und jede Gruppe Einträge hat, kam der leere Fall über eine Option zustande, die
nur im Browser an die Auswahl angehängt wurde. Die Filterfunktion ist dieselbe. Die Frage nach
den Dropdowns (unten) ist geklärt.

Gemeldet in der Anwesenheitsverwaltung: Wählt man eine Terminart ohne Einträge, bleibt die
Paginierung beim alten Stand („von 196 Einträgen“). Erst die Wahl eines einzelnen Termins
wechselt in die Anwesenheitsliste.

**Ursache (aus dem Code, im Browser noch nicht nachgestellt):** Die Filterung selbst ist richtig.
`filterRecords()` liefert bei leerem Ergebnis `[]`, das Backend ist nicht beteiligt (gefiltert
wird im Client), und `applyRecordFilters()` ist bereits die zentrale Filterfunktion. Die beiden
Vermutungen aus der Notiz treffen also nicht. Der Fehler sitzt in `renderRecords()`
(`public/js/modules/records.js`, ~Zeile 131): Bei leerer Liste schreibt es „Keine Einträge
gefunden“ und kehrt **vor** `renderRecordsPagination()` zurück. Dadurch

1. bleibt der Inhalt von `#recordsPagination` stehen: Seitenknöpfe und „Zeige 1–25 von 196“,
2. bleibt `allFilteredRecords` auf der alten Liste. Ein Klick auf einen Seitenknopf ruft
   `goToRecordsPage()` auf, rendert **die alten 196 Einträge** und zeigt sie unter dem gesetzten
   Filter an.

**Dasselbe Muster** steckt in `renderMembers()` (`members.js`, ~Zeile 152) und
`renderExceptions()` (`exceptions.js`, ~Zeile 69). In `renderMembers()` kommt dazu, dass die
Leermeldung „Kein Profil verknüpft“ lautet — auch dann, wenn ein Admin einfach einen Filter ohne
Treffer gesetzt hat. Termine, Benutzer und Geräte sind nicht betroffen: Dort läuft auch eine
leere Liste durch die Paginierung, die sich bei `totalPages <= 1` selbst leert.

**Zu tun:** In den drei leeren Zweigen die Paginierung leeren und die gemerkte Liste auf `[]`
setzen. Die Leermeldung an den Fall anpassen („Keine Einträge für diese Auswahl“ bei gesetztem
Filter). Vorher im Browser nachstellen.

**Zum Hinweis auf „nicht gegenseitig gefilterte Dropdowns“:** Für die Modals von Anwesenheit und
Anträgen ist die gegenseitige Filterung seit der Spec `2026-04-15-dropdown-cross-filtering-design.md`
gebaut (`getCompatibleAppointments()`/`getCompatibleMembers()` in `utils.js`). In der
Filterleiste filtert die Terminart die Listen für Termin und Mitglied, und die Wahl von Termin
oder Mitglied sperrt die beiden anderen Felder. **Geklärt am 2026-09-22:** Gemeint war der
frühe Stand der Modals vor dieser Spec. Er ist behoben.

**Nicht sicherheitsrelevant:** reine Anzeige, die angezeigten Einträge liegen ohnehin im Cache
der Sitzung.

---

### OI-85 · Keine Ladeanzeige und kein Timeout bei langsamen API-Antworten
**Priorität:** erledigt am 2026-09-22 — mit 1.12.1 (`3300c26`), alle vier Punkte wie vorgeschlagen entschieden:
schmaler Balken oben erst nach 300 ms, nicht blockierend, an einem Zähler offener Anfragen;
Timeout 20 s, keiner für `cleanup` und `update_check` (Export und Import laufen ohnehin über
eigene `fetch`-Aufrufe); beim Speichern „Ob gespeichert wurde, ist unklar“; Dashboard und PWA,
nicht die Station. Im Browser geprüft, PWA und Dashboard, jeweils mit hängendem `fetch`: kein Balken nach 150 ms,
Balken nach 550 ms, nach 20 s beide Meldungen, danach kein Balken; PWA ohne Offline-Hinweis.

`apiCall()` (`public/js/modules/api.js`) und die gleichnamige Funktion der PWA
(`public/checkin/js/app.js`) warten ohne Rückmeldung und ohne zeitliche Grenze. Ladeanzeigen gibt
es nur vereinzelt: `.loading` als Tabellenplatzhalter, `statsLoading` im Statistik-Tab der PWA.
Bei langsamer Verbindung sieht die Oberfläche eingefroren aus.

**Vorschlag aus dem Test:** zentral im Wrapper statt je Ansicht. Die Anzeige erst nach etwa 300 ms
einblenden, damit schnelle Antworten nicht flackern. Timeout per `AbortController` mit
Fehlermeldung. Beide Wrapper brauchen das, die Station hat einen dritten.

**Vor der Umsetzung zu klären:**

- **Ein Timeout bricht nur das Warten ab, nicht die Anfrage.** Ein `POST`, der nach dem Abbruch
  auf dem Server doch durchläuft, hat trotzdem angelegt. Die Meldung darf deshalb bei Mutationen
  nicht „fehlgeschlagen“ heißen, sondern „Ergebnis unbekannt — bitte Ansicht neu laden“. Sonst
  schickt das Mitglied den Check-in ein zweites Mal ab (Dubletten fangen heute die Unique-Indizes
  und die Dublettenprüfung der Anträge ab, aber nicht jeder Weg hat eine).
- **Länge des Timeouts:** Export, Import und Druckbericht dauern legitim länger. Sie brauchen
  einen eigenen Wert oder gar keinen, über `callOptions`.
- **Gleichzeitige Anfragen:** Die Anzeige hängt an einem Zähler offener Anfragen, nicht an einem
  Schalter. Sonst blendet die erste fertige Antwort die Anzeige aus, während die zweite noch
  läuft.

**Nicht sicherheitsrelevant.**

---

### OI-86 · Bunte Status-Filterknöpfe nur in der Benutzerverwaltung
**Priorität:** niedrig · aufgenommen am 2026-09-22 (Test vom 21.09.) · **vertagt am 2026-09-22:**
Nicht einzeln lösen. Zuerst ein Brainstorming zu einem Bedienkonzept, das die Filter aller
Ansichten verschlankt und einheitlich gestaltet; dieser Punkt geht darin auf.

Die Benutzerverwaltung (`#userStatusFilter`, `public/index.html` ~Zeile 1264) filtert mit
farbigen Pillenknöpfen (`.filter-btn`, `.pending`, `.active-status`, `.suspended` in
`public/css/sections/content.css` ~Zeile 401). Alle anderen Ansichten filtern seit 1.9.2 über
Auswahlfelder in der Filterleiste.

**Die Farben tragen Bedeutung.** Gelb steht für ausstehend, Grün für aktiv, Rot für gesperrt, und
jeder Knopf zeigt die Anzahl. Damit ist die Frage aus der Notiz — ausweiten oder zurückbauen —
nach deren eigenem Kriterium beantwortet: nicht zurückbauen. Das eigentliche Problem ist die
Umsetzung. Die Farben sind hart codiert (`#ffc107`, `#28a745`, `#dc3545`, `#007bff`, `white`,
`#ddd`) statt über `variables.css`, entgegen der Konvention in `CLAUDE.md`. Im Branding
eingestellte Farben erreichen sie nicht, und das Blau des aktiven „Alle“ ist in keiner anderen
Ansicht Primärfarbe.

**Vorbild im Bestand:** `.response-chip--yes/--maybe/--no/--open` (`css/components/badges.css`)
lösen dieselbe Aufgabe schon mit Tokens (`color-mix()` über `--success-color`, `--warning-color`,
`--danger-color`).

**Zu tun:** Eine gemeinsame Komponente in `css/components/` (z. B. `.filter-chip` mit
Statusvarianten über die vorhandenen Farb-Tokens), die Benutzerverwaltung darauf umstellen.

**Zu entscheiden:** Ob weitere Ansichten mit festem Statussatz Chips bekommen — Anträge
(ausstehend/genehmigt/abgelehnt) und Arbeitszeit (eingereicht/bestätigt/abgelehnt) lägen nahe.
Das ginge gegen die Filterleisten-Konvention aus 1.9.2 und wäre eine eigene Entscheidung.
Dark Mode hat das Dashboard derzeit nicht. Mit Tokens wäre die Komponente dafür aber schon
vorbereitet.

**Nicht sicherheitsrelevant.**

---

### OI-87 · Anträge in der Anwesenheitsliste des Dashboards; Selbstgenehmigung nur ohne zweiten Verwalter
**Priorität:** mittel · aufgenommen am 2026-09-22 · **Umsetzung erst nach Abstimmung mit den parallelen
Sitzungen**, die an `records.js` und `exceptions.js` arbeiten könnten

**1. Anträge in der Anwesenheitsliste.** Die PWA zeigt seit 1.12.0 in ihrer Liste je Mitglied die
offenen Anträge und lässt sie bescheiden. Das Dashboard nicht. Geprüft am 2026-09-22, ohne Änderung:

- **Daten sind da.** `loadAttendanceList()` (`public/js/modules/records.js`) ruft dieselbe Ressource
  `attendance_list?appointment_id=…` auf, die seit 1.12.0 je Mitglied `pending_exceptions` liefert.
  Keine Server-Änderung nötig.
- **Entscheiden ist da.** `quickApproveException()` und `quickRejectException()` (`exceptions.js`)
  öffnen den Antragsdialog mit vorbelegtem Status. Im Dashboard den Dialog nutzen statt eines
  Direktknopfs wie in der PWA: Begründung und Wunschzeit sind vollständig sichtbar, die Zeit
  lässt sich vor der Genehmigung korrigieren.
- **„Entschuldigt“ unterscheidet das Dashboard bereits** — der Nebenbefund aus der PWA betrifft es
  nicht.
- **Zu bauen:** Hinweis und ✓/✗ in `buildAttendanceRow()`, Zähler offener Anträge, und nach dem
  Speichern im Dialog auch die Anwesenheitsliste neu laden (heute lädt `saveException()` nur die
  Antragsliste). Nur für die Ansicht je Termin; die Ansicht je Mitglied bekommt vom Server keine
  Anträge.

**2. Selbstgenehmigung.** Heute darf ein Manager im Dashboard seinen eigenen Antrag genehmigen, in
der PWA ist das seit 1.12.0 pauschal gesperrt. Vorgeschlagene Regel für beide Oberflächen:

> Seinen eigenen Antrag **genehmigt** niemand, solange es ein anderes aktives Konto mit Rolle Admin
> oder Manager gibt. Gibt es keins, bleibt die Selbstgenehmigung erlaubt.

- Maßgeblich ist das freigebende **Konto**, nicht ein verknüpftes Mitglied. Beispiel Testbestand:
  Admin ohne Mitglied, Manager mit Mitglied — der Admin kann den Antrag des Managers bescheiden, die
  Sperre greift also.
- Prüfung **im Server** (`PUT exceptions`, Wechsel auf `approved`, Antragsteller = Mitglied des
  freigebenden Kontos), die Oberflächen blenden nur passend aus. Sonst per API umgehbar.
- **Ablehnen und Löschen** des eigenen Antrags bleiben erlaubt.
- Die pauschale PWA-Sperre folgt dann derselben Regel und wird im Ein-Verwalter-Verein lockerer.
- **Kennzeichnung** im Dashboard: „selbst genehmigt“, wenn Antragsteller und `approved_by`
  zusammenfallen — auch im Ein-Verwalter-Verein nachvollziehbar. Die Spalten gibt es schon.
- **Preis:** Ein zweites Verwalterkonto, das praktisch nie genutzt wird, lässt Anträge hängen.
  Abhilfe: nicht mehr genutzte Konten sperren.
- Für die Arbeitszeit dieselbe Regel als eigene Entscheidung, siehe
  [OI-3](#oi-3--vier-augen-prinzip-bei-manager-nachträgen).

**3. Nebenbefund:** Nach der Genehmigung einer **Entschuldigung** leert `saveException()`
(`exceptions.js`, um Zeile 687) den Zwischenspeicher der Anwesenheiten nicht — nur bei Zeitanträgen.
Die Gesamtliste zeigt den neuen Eintrag „entschuldigt“ erst nach bis zu 10 Minuten oder einem
Neuladen. Eine Zeile, im selben Zug mitnehmen.

**4. Nebenbefund:** In `buildAttendanceRow()` (`records.js`) fehlt beim Status „✓ Anwesend“ das
schließende `>`: `…✓ Anwesend</span` statt `</span>`. Der Browser repariert es stillschweigend, das
Markup ist trotzdem kaputt. Gemeldet von der Sitzung der Filter-Chips, die die Funktion bewusst
nicht anfasst — beim Umbau für OI-87 mitnehmen.

**Abstimmung mit den parallelen Sitzungen (2026-09-22):**

- **Reihenfolge:** (1) Merge der Filter-Chips (Spec `2026-09-22-filter-chips-design.md`), (2)
  Schritt 1 von „Kalender → Anwesenheit“ (Spec `2026-09-22-kalender-anwesenheit-design.md`), (3)
  OI-87, (4) Schritt 2 von „Kalender → Anwesenheit“ (Umbau von `attendance_list` auf einen
  gemeinsamen Helfer — muss `pending_exceptions` unverändert liefern, das steht dort als Pflicht).
- **Zähler offener Anträge:** kein Status-Chip — die Status-Chips einer Reihe schließen sich aus und
  ergeben zusammen „Alle“, ein offener Antrag liegt quer dazu. Stattdessen ein eigener
  Anzeige-Chip (`renderFilterChips(…, {static: true})`, Variante `pending`) hinter der Status-Reihe,
  nach dem Muster `appointmentTimeChips`. **Entschieden (2026-09-22): nur anzeigen**, kein zweiter
  Filter „nur offene Anträge“ — bei meist ein bis drei offenen Anträgen je Termin unnötig.
- **Überschneidungen:** Die Filter-Chips schreiben `renderAttendanceList()` neu, lassen
  `buildAttendanceRow()` und `saveException()` aber stehen. FI-17 fasst `exceptions.php` nicht an
  und in der PWA nicht `attendanceRequestsHtml()` — keine Wartezeit. **Bedingung von FI-17:** Der
  PUT-Zweig setzt `approved_at` weiterhin auch bei `rejected` (darauf baut das 14-Tage-Fenster
  abgelehnter Anträge unter „Mein Konto“). FI-17 zeigt eigene offene Anträge neutral als „wartet“;
  das stimmt auch für den Antrag eines Managers, der nach dieser Regel auf den zweiten Verwalter
  wartet.

**Nicht sicherheitsrelevant im Sinne von `SECURITY.md`:** Die Selbstgenehmigung ist eine bewusste,
dokumentierte Regel (OI-3), keine Rechteausweitung.
