# Offene Punkte — EhrenSache

Sammelstelle für Funde, offene Entscheidungen und Restarbeiten. Ergänzt die Spezifikationen
unter `docs/superpowers/specs/`, ersetzt sie nicht: Was hier steht, ist noch nicht entschieden
oder noch nicht gebaut.

**Zuletzt geprüft:** 2026-09-04 · **Bezugsstand:** `dev`, noch nicht nach `main` übernommen ·
**Version:** 1.2.4

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
Siehe [OI-2](#oi-2) — dort zusammengefasst.

---

### OI-19 · Fehler eines Exports erscheinen als JSON-Seite
**Priorität:** niedrig

Alle Exporte werden über einen Seitenaufruf geholt — `window.location.href` für den
Download, `window.open` für die Druckansicht. Antwortet der Server mit einem Fehler, ist
diese Antwort die neue Seite: Der Nutzer sieht `{"message":"…"}` im Vollbild oder in einem
neuen Tab, statt einer Meldung in der Oberfläche.

**Gefunden** beim manuellen Test ZR-M9 am 2026-09-03 mit einem Zeitraum über 24 Monate.

**Nicht neu.** `exportMembers`, `exportAppointments` und `exportRecords` nutzen dasselbe
Muster seit jeher. Der Berichtsdialog hat es nur sichtbar gemacht, weil er der erste Export
mit einer Eingabe ist, die serverseitig scheitern kann.

**Teilweise entschärft.** Der konkrete Fall ist behoben: `runWorktimeReport()` prüft die
24-Monats-Grenze und das verdrehte Datum vor dem Aufruf und meldet beides als Toast. Die
Rechnung ist gegen die serverseitige geprüft — für zehn Datumspaare einschließlich
Schaltjahr liefern PHP und JavaScript denselben letzten zulässigen Tag. Die Prüfung im
Server bleibt die verbindliche.

**Was offen bleibt:** Läuft die Session ab, während der Dialog offen steht, erscheint
weiterhin `{"message":"Unauthorized"}` als Seite. Dasselbe gilt für jede von Hand
zusammengesetzte URL und für die drei älteren Exporte.

**Lösungsweg:** Eine gemeinsame Funktion, die den Export per `fetch` anfordert, den Status
prüft und erst bei `200` ausliefert. Zwei Fallstricke:

- Der **Download** ließe sich dann aus einem Blob speisen. Das ist der einfache Teil.
- Die **Druckansicht** darf *nicht* aus einem Blob kommen: Die Seite setzt `<base href="../">`,
  damit `css/print.css` und das Vereinslogo laden. Unter einer `blob:`-URL greift diese Basis
  nicht, der Bericht käme ohne Stylesheet und ohne Logo. Hier bliebe nur eine Vorabprüfung
  per `fetch` und danach ein `window.open` auf die echte URL — also bewusst zwei Anfragen.

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

## Bewusst entschieden — nicht erneut aufmachen

| Thema | Entscheidung | Grund |
|---|---|---|
| Manager sehen alle Sitzungen | Keine Gruppengrenze | Konsistent mit `records`, `exceptions` und `statistics` — dort gibt es sie auch nicht. Eine Grenze nur hier wäre überraschend |
| Pause verlangt nie einen Nachweis | So belassen | Eine Pause ist keine Anwesenheitsbehauptung |
| Kein `force` beim Timer-Start | So belassen | Ein unbelegter Start bei nachweispflichtiger Tätigkeit soll gar nicht erst als Timer laufen; der Weg ist die nachträgliche Erfassung mit Freigabe |
| Kein Segmentmodell für Pausen | So belassen | Nachweise verlangen Dauer, nicht die Lage der Pausen. Nachrüstbar ohne Datenmigration |
| Kein Offline-Betrieb in der PWA | So belassen | Erzeugte Client-Zeitstempel, die als Nachweis wertlos sind |
| Kein PDF-Export | So belassen | Würde eine Bibliothek einschleppen, die das Projekt bewusst nicht hat. Der Bedarf ist seit 1.2.2 über die Druckansicht (`&format=html`) gedeckt: Das PDF entsteht im Druckdialog des Browsers |
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
