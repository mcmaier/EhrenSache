# Offene Punkte — EhrenSache

Sammelstelle für Funde, offene Entscheidungen und Restarbeiten. Ergänzt die Spezifikationen
unter `docs/superpowers/specs/`, ersetzt sie nicht: Was hier steht, ist noch nicht entschieden
oder noch nicht gebaut.

**Zuletzt geprüft:** 2026-09-02 · **Bezugsstand:** `dev`, noch nicht nach `main` übernommen ·
**Version:** 1.2.1

> **Diese Datei ist öffentlich.** Sie liegt seit 2026-09-02 im Repository (siehe
> [OI-14](#oi-14)). Was hier steht, kann jeder lesen — die Grenze für sicherheitsrelevante
> Einträge regelt der Abschnitt [Sicherheit](#sicherheit) und `SECURITY.md`.

**Priorität:** *hoch* = blockiert einen Merge nach `main` oder den produktiven Einsatz ·
*mittel* = sollte vor der Freigabe an Vereine gelöst sein · *niedrig* = Verbesserung

---

## Zu klären

### OI-1 · Selbstheilung bei verlorenem AUTO_INCREMENT
**Priorität:** hoch — Beobachtung läuft

Am 2026-09-02 nahm `ez_work_sessions` keine Einträge mehr an:
`SQLSTATE[HY000] 1467 Failed to read auto-increment value from storage engine`.
`AUTO_INCREMENT` las sich als `0` bei `MAX(session_id) = 1176`. Andere Tabellen derselben
Datenbank waren unauffällig. Behoben durch `ALTER TABLE ez_work_sessions AUTO_INCREMENT = 1177`.

**Nicht reproduzierbar.** Ein sauberer Neustart von MariaDB 10.4.32 am selben Tag ließ alle
Zähler intakt — auch bei einer eigens angelegten Kontrolltabelle mit derselben Konstruktion
(virtuelle Spalte `active_member` plus Unique-Index). `FLUSH TABLES` reproduziert es ebenfalls
nicht. Arbeitshypothese: unsauberes Herunterfahren in der Nacht zuvor; InnoDB verlor den
Zähler und scheiterte beim Neuberechnen. **Unbelegt.**

**Zu entscheiden:** Soll der Handler bei genau diesem Fehler den Zähler auf `MAX + 1` setzen
und den Einfügevorgang einmal wiederholen (~15 Zeilen)?

- *Dafür:* Ohne Eingriff steht die Anwendung still und zeichnet nichts mehr auf. Ein Verein
  ohne Datenbankkenntnisse kann das nicht beheben.
- *Dagegen:* Behandelt ein Symptom, dessen Ursache unbekannt ist. Ein stiller Reparaturpfad
  erschwert künftige Diagnosen.

**Nächster Schritt:** Datenbankverhalten über mehrere Neustarts beobachten (läuft). Tritt es
erneut auf: Selbstheilung einbauen **und** Ursache weiter eingrenzen. Tritt es nicht mehr auf:
als Einzelfall schließen.

---

### OI-2 · Löschfrist für die Änderungshistorie
**Priorität:** hoch

`DATENSCHUTZ.md` Abschnitt 10.4 fordert eine eigene Löschfrist für `work_session_log`, weil die
Auditspur das Löschen einer Sitzung **absichtlich** überlebt und personenbezogene Daten enthält.
Ein automatisches Löschen gibt es nicht; der vorhandene `cleanup`-Endpunkt kennt die Tabelle
nicht.

Betreiber müssen die Frist derzeit selbst per SQL durchsetzen — was in der Praxis heißt: niemand
tut es.

**Nächster Schritt:** `cleanup` um `work_session_log` und `work_sessions` erweitern, mit eigener
Frist je Tabelle. Vorher klären, ob die Historie einer noch bestehenden Sitzung überhaupt
gelöscht werden darf oder nur die verwaister Einträge.

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

## Restarbeiten

### OI-4 · Terminbezug: Oberfläche unvollständig
**Priorität:** mittel

Backend ist fertig (`7e9ec6a`): `worktimeByAppointment()`, Export `worktime_appointment`, und
der Check-in entsteht nur noch, wenn der Termin heute ist. Es fehlt:

- **Terminfeld im Dashboard-Nachtrag.** `saveWorkSession()` sendet kein `appointment_id`; das
  Modal hat kein Feld. Der Server akzeptiert es. Deshalb steht in der Spalte „Termin" bei jedem
  im Dashboard erfassten Eintrag „—".
- **PWA bietet nur heutige Termine an.** `loadWorktimeAppointments()` fragt `from_date = to_date
  = heute` ab. An Tagen ohne Termin wirkt das Feld funktionslos. Vorbereitungsarbeit für eine
  spätere Veranstaltung lässt sich gar nicht zuordnen.
- **Dritter Export-Knopf** für `worktime_appointment` fehlt im Dashboard.

**Teilweise erledigt am 2026-09-02:** Die Auswahl war zusätzlich schlicht leer, weil sie nur
beim Öffnen des Antragsdialogs befüllt wurde, und das Tagesdatum kam aus UTC — abends ab 22:00
MESZ zeigte sie den Folgetag. Beides behoben (`d7ee191`). Die Beschränkung auf den heutigen Tag
ist davon unberührt und bleibt offen.

---

### OI-5 · Automatisches Löschen der Auditspur
Siehe [OI-2](#oi-2) — dort zusammengefasst.

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
- **PWA im Stations-Modus:** Ein ausgemustertes Tablet meldet sich als Station an und zeigt den
  rotierenden Code, statt dedizierte Hardware zu erfordern.

---

### OI-7 · Gültigkeitsfenster der TOTP-Codes
**Priorität:** niedrig

Risiko R6. `verify($code, null, 1)` erlaubt ein Zeitfenster Toleranz in beide Richtungen, ein Code
gilt also rund 90 Sekunden — lange genug, um ihn per Screenshot an einen Abwesenden zu schicken.
Toleranz `0` wäre strenger, aber anfällig für Uhrendrift auf dem Mitgliedsgerät.

Bewusst unverändert. Nur dokumentieren, nicht als stärker beschreiben, als es ist.

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
**Priorität:** niedrig · Bestandsfehler, seit Langem vorhanden

`public/checkin/index.html` öffnet in Zeile 70 `<div id="mainScreen">`, schließt es aber nie —
über die ganze Datei bleibt genau ein `<div>` offen. Browser ergänzen das fehlende Tag
stillschweigend am `</body>`, deshalb ist bisher nichts aufgefallen.

Gefunden am 2026-09-02 beim Umbau zum Erfassen-Tab durch eine Zählung der `div`-Tags; die
Differenz bestand schon vor diesem Umbau (nachgeprüft gegen den vorherigen Commit).

**Warum nicht nebenbei behoben:** Die Stelle, an der das `</div>` eingefügt wird, entscheidet,
ob die Modals (`exceptionModal`, `appointmentModal`, `manualCodeModal`) innerhalb oder außerhalb
von `mainScreen` liegen. `mainScreen` wird über `showScreen()` ein- und ausgeblendet — eine
falsche Platzierung macht die Modals unsichtbar oder lässt sie beim Abmelden stehen. Das
gehört bewusst entschieden und einmal durchgeklickt, nicht beiläufig geraten.

**Absicherung wäre möglich:** Ein Test in `tests/suites/assets.php`, der die `div`-Bilanz jeder
ausgelieferten HTML-Datei prüft, würde solche Fälle künftig beim Entstehen melden.

---

### OI-16 · Zeiterfassung zeigt inaktive Mitglieder zur Auswahl
**Priorität:** mittel

Die Mitgliederauswahl der Zeiterfassung nimmt den Jahres-Cache ungefiltert:

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

**Nächster Schritt:** Beide Stellen auf dasselbe Filterkriterium ziehen. Vorher zu klären:

- Soll die **Filterleiste** ebenfalls filtern? Dort geht es um das Sichten vorhandener
  Einträge — Stunden eines inzwischen ausgetretenen Mitglieds bleiben ein gültiger
  Datensatz und müssen auffindbar bleiben. Möglicherweise ist hier nur eine Kennzeichnung
  richtig, kein Ausschluss.
- Im **Nachtrag-Modal** ist der Ausschluss dagegen eindeutig: Für einen Zeitraum ohne
  Mitgliedschaft sollte gar kein Eintrag entstehen können.

Die Statistik zeigt also die Lösung für den einen Fall, nicht zwingend für beide.

---

## Bewusst entschieden — nicht erneut aufmachen

| Thema | Entscheidung | Grund |
|---|---|---|
| Manager sehen alle Sitzungen | Keine Gruppengrenze | Konsistent mit `records`, `exceptions` und `statistics` — dort gibt es sie auch nicht. Eine Grenze nur hier wäre überraschend |
| Pause verlangt nie einen Nachweis | So belassen | Eine Pause ist keine Anwesenheitsbehauptung |
| Kein `force` beim Timer-Start | So belassen | Ein unbelegter Start bei nachweispflichtiger Tätigkeit soll gar nicht erst als Timer laufen; der Weg ist die nachträgliche Erfassung mit Freigabe |
| Kein Segmentmodell für Pausen | So belassen | Nachweise verlangen Dauer, nicht die Lage der Pausen. Nachrüstbar ohne Datenmigration |
| Kein Offline-Betrieb in der PWA | So belassen | Erzeugte Client-Zeitstempel, die als Nachweis wertlos sind |
| Kein PDF-Export | So belassen | Würde eine Bibliothek einschleppen, die das Projekt bewusst nicht hat |
| Statistik getrennt von Anwesenheit | Eigener `worktime`-Block | Anwesenheitsquote und geleistete Stunden sind verschiedene Fragen |

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
