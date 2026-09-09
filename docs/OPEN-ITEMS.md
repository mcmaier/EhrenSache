# Offene Punkte — EhrenSache

Sammelstelle für Funde, offene Entscheidungen und Restarbeiten. Ergänzt die Spezifikationen
unter `docs/superpowers/specs/`, ersetzt sie nicht: Was hier steht, ist noch nicht entschieden
oder noch nicht gebaut.

**Zuletzt geprüft:** 2026-09-09 · **Bezugsstand:** `dev`, noch nicht nach `main` übernommen ·
**Version:** 1.3.1

> **Diese Datei ist öffentlich.** Sie liegt seit 2026-09-02 im Repository (siehe
> [OI-14](#oi-14)). Was hier steht, kann jeder lesen — die Grenze für sicherheitsrelevante
> Einträge regelt der Abschnitt [Sicherheit](#sicherheit) und `SECURITY.md`.

**Priorität:** *hoch* = blockiert einen Merge nach `main` oder den produktiven Einsatz ·
*mittel* = sollte vor der Freigabe an Vereine gelöst sein · *niedrig* = Verbesserung

---

## Zu klären

### OI-1 · Verlorener AUTO_INCREMENT nach Crash-Recovery
**Priorität:** Ursache belegt, Selbstheilung gebaut · **offen:** ob die virtuelle Spalte bleibt

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

#### Offen: bleibt `active_member` virtuell?

Die Selbstheilung behandelt die Folge. Die Ursache ließe sich beseitigen, indem die Spalte
von `VIRTUAL` auf `STORED` umgestellt wird: Gespeicherte Spalten liegen im Zeilenformat und
verschieben die Zuordnung nicht.

- *Dafür:* Der Auslöser verschwindet, statt abgefangen zu werden. Der Unique-Index und die
  Garantie „höchstens eine laufende Sitzung je Mitglied" bleiben unverändert.
- *Dagegen:* Braucht eine Migration, kostet ein paar Bytes je Zeile — und ob es wirklich
  hilft, ist unbewiesen, solange sich der Fehler nicht gezielt herbeiführen lässt.

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
**Priorität:** niedrig

Acht Spalten scrollen auf schmalen Fenstern horizontal. Das ist `overflow-x: auto` aus
`.data-table` und verhält sich wie jede andere Tabelle der App — fällt hier nur stärker auf.
Kein Fehler, aber ein Kandidat für Spaltenpriorisierung auf kleinen Bildschirmen.

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
