# Feature-Ideen — EhrenSache

Ideensammlung für mögliche künftige Funktionen. **Nichts hier ist beschlossen, geplant oder
terminiert.** Der Zweck ist, Einfälle festzuhalten, bevor sie verloren gehen, und ihre Kosten
grob abzuschätzen — nicht, sie zu versprechen.

**Angelegt:** 2026-09-02 · **Zuletzt abgeglichen:** 2026-09-16 · **Bezugsstand:** `dev`,
Version 1.7.0

## Abgrenzung zu `docs/OPEN-ITEMS.md`

|  | `OPEN-ITEMS.md` | **diese Datei** |
|---|---|---|
| Gegenstand | Funde am Bestand, angefangene Arbeit, zu klärende Entscheidungen | Neuland, an dem noch keine Zeile geschrieben ist |
| Verbindlichkeit | Restarbeit oder Blocker — muss adressiert werden | unverbindlich, jederzeit streichbar |
| Auslöser | etwas ist gebaut, unfertig oder kaputt | jemand hatte einen Einfall |

Wandert eine Idee von hier in die Umsetzung, entsteht zuerst eine Spec unter
`docs/superpowers/specs/`. Erst was daraus als Restarbeit oder offene Entscheidung übrig
bleibt, gehört nach `OPEN-ITEMS.md`. Eine Idee steht nie in beiden Dateien.

> **Auch diese Datei ist öffentlich.** Für sicherheitsrelevante Inhalte gilt dieselbe Grenze
> wie in `OPEN-ITEMS.md`: Überlegungen zur Härtung künftiger Funktionen ja — konkrete
> ungepatchte Lücken am Bestand nein, die gehen den Weg aus `SECURITY.md`.

## Bewertungsschema

**Nutzen** — *hoch* = Grund, EhrenSache statt einer Alternative zu wählen · *mittel* =
spürbare Erleichterung im Alltag · *niedrig* = nett, aber niemand vermisst es.

**Aufwand** — *S* = ein Handler, ein Modul, keine Schemaänderung · *M* = Migration plus
Oberfläche plus API-Doku · *L* = neues Datenmodell, das in Statistik, Export und PWA
durchschlägt.

---

## Übersicht

| ID | Idee | Nutzen | Aufwand | Hängt ab von |
|---|---|---|---|---|
| [FI-1](#fi-1--terminzusage-im-vorfeld) | Terminzusage im Vorfeld — **umgesetzt in 1.7.0** | hoch | M | — |
| [FI-2](#fi-2--abgleich-zusage--tatsächliche-anwesenheit) | Abgleich Zusage ↔ tatsächliche Anwesenheit — **je Termin umgesetzt in 1.7.0** | hoch | S | FI-1 |
| [FI-3](#fi-3--gps-gestützter-check-in) | GPS-gestützter Check-in | mittel | M | — |
| [FI-4](#fi-4--registrierungsprozess-für-auth-geräte) | Registrierungsprozess für Auth-Geräte (Rest: NFC/Biometrie) | mittel | M | — |
| [FI-5](#fi-5--pin-anmeldung-am-auth-gerät) | PIN-Anmeldung am Auth-Gerät — **umgesetzt in 1.3.0** | mittel | M | FI-4 |
| [FI-6](#fi-6--benachrichtigungskanal-e-mail-web-push) | Benachrichtigungskanal (E-Mail, Web-Push) | hoch | M | — |
| [FI-7](#fi-7--terminserien-für-wiederkehrende-proben) | Terminserien für wiederkehrende Proben | hoch | M | — |
| [FI-8](#fi-8--kalender-abo-ics-feed) | Kalender-Abo (ICS-Feed) | mittel | S | FI-23 für die Wirkung |
| [FI-9](#fi-9--dienst--und-schichtplanung-für-veranstaltungen) | Dienst- und Schichtplanung für Veranstaltungen | mittel | L | FI-1 |
| [FI-10](#fi-10--jubiläen-und-ehrungen-automatisch-ermitteln) | Jubiläen und Ehrungen automatisch ermitteln | mittel | S | — |
| [FI-11](#fi-11--mehrsprachigkeit-der-oberfläche) | Mehrsprachigkeit der Oberfläche | niedrig | L | — |
| [FI-12](#fi-12--material--und-instrumentenausleihe) | Material- und Instrumentenausleihe | niedrig | L | — |
| [FI-13](#fi-13--geburtstagsliste-mit-gratulationsvermerk) | Geburtstagsliste mit Gratulationsvermerk | mittel | M | — |
| [FI-14](#fi-14--untergruppen-register-und-besetzungsübersicht) | Untergruppen (Register) und Besetzungsübersicht — **Variante B teilweise umgesetzt in 1.8.0** | mittel¹ | M | FI-1 für die Wirkung |
| [FI-15](#fi-15--rolle-gruppenleiter) | Rolle „Gruppenleiter" | hoch | L | — |
| [FI-16](#fi-16--feiertage-und-ferien-im-terminkalender) | Feiertage und Ferien im Terminkalender | mittel | M | FI-7 für die Wirkung |
| [FI-17](#fi-17--offene-punkte-unter-mein-konto) | Offene Punkte unter „Mein Konto" | hoch | S | — |
| [FI-18](#fi-18--kalender-import-ics) | Kalender-Import (ICS) | niedrig | M | — |
| [FI-19](#fi-19--terminvorlagen) | Terminvorlagen | niedrig | S | — |
| [FI-20](#fi-20--einfache-umfragen) | Einfache Umfragen | niedrig | M | FI-6 |
| [FI-21](#fi-21--aufgaben-mit-zuweisung-und-fälligkeit) | Aufgaben mit Zuweisung und Fälligkeit | niedrig | L | FI-6 |
| [FI-22](#fi-22--musikstücke-und-programme) | Musikstücke und Programme | niedrig | L | — |
| [FI-23](#fi-23--ort-und-ende-am-termin) | Ort und Ende am Termin (rein informativ) | mittel | M | — |

¹ hoch in Kombination mit [FI-1](#fi-1--terminzusage-im-vorfeld), für sich allein mittel.

FI-1 bis FI-5 und FI-13 bis FI-15 stammen aus der Ideensammlung, FI-6 bis FI-12 sind
Ergänzungen aus der Sichtung des Bestands. Die Nummern folgen dem Eingang, die Abschnitte dem
Thema — deshalb steht FI-14 unter A und nicht am Ende.

FI-23 kam am 2026-09-17 aus der Sichtung der Terminverwaltung dazu (Spec
`2026-09-17-dashboard-filterleisten-design.md`) — nicht als eigener Wunsch, sondern weil drei
bestehende Einträge die Felder stillschweigend voraussetzten.

FI-16 bis FI-22 kamen am 2026-09-16 aus einem getrennt geführten Ideen-Backlog dazu, teils aus
einem Vergleich mit `konzertmeister.app`. Aus demselben Abgleich stammen die Ergänzungen an
[FI-6](#fi-6--benachrichtigungskanal-e-mail-web-push), [FI-7](#fi-7--terminserien-für-wiederkehrende-proben)
und [FI-8](#fi-8--kalender-abo-ics-feed); was davon Bestandsarbeit war, ging nach
`OPEN-ITEMS.md` (OI-62 bis OI-65).

---

## A · Planung im Vorfeld

### FI-1 · Terminzusage im Vorfeld
**Nutzen:** hoch · **Aufwand:** M

**Umgesetzt in 1.7.0** — Spec `docs/superpowers/specs/2026-09-14-terminrueckmeldung-design.md`.
Die Fragen unten sind dort entschieden: Rückmeldung als Planungswerkzeug ohne Freigabe,
Entschuldigungspflicht und Namenssichtbarkeit je Terminart, Frist für „rechtzeitig". Ohne
Benachrichtigung (FI-6) gebaut.

Mitglieder geben vor einem Termin an, ob sie kommen: *zugesagt / abgesagt / unsicher*, mit
optionaler Bemerkung. Dirigent oder Vorstand sehen die Besetzung, bevor die Probe stattfindet.

**Warum interessant:** Das ist das stärkste Argument der Konkurrenz (Konzertmeister). Die
Frage „reicht die Besetzung für Samstag?" stellt sich jede Woche; die Anwesenheitserfassung
beantwortet sie erst hinterher. Das meiste andere auf dieser Liste ist Komfort — das hier ist
ein eigener Anwendungsfall.

**Berührt:** neue Tabelle `appointment_responses` (member_id, appointment_id, status, comment,
responded_at, unique je Paar) · neue Ressource in `api.php` · Terminansicht im Dashboard ·
Erfassen-Tab der PWA · Statistik (Zusagequote als eigene Kennzahl).

**Umgesetzt in 1.7.0 mit einer Abweichung von dieser ursprünglichen Idee:** Die gebaute Tabelle
trägt statt eines einzelnen `responded_at` zwei Zeitstempel — `status_changed_at` (ändert sich nur
bei einem echten Statuswechsel) und `updated_at` (jede Änderung, auch nur der Bemerkung) —, siehe
Spec 3.8 und 4.1. Eine „Zusagequote als eigene Kennzahl" der Statistik wurde bewusst nicht gebaut
(Entscheidung 3.7 der Spec: das beantwortet schon die Zuverlässigkeit). Der Rest dieses Abschnitts
ist die ursprüngliche Idee, nicht der umgesetzte Stand — Einzelheiten stehen in
`docs/superpowers/specs/2026-09-14-terminrueckmeldung-design.md`.

**Vorher zu klären — der eigentliche Knackpunkt:**

- **Verhältnis zu `exceptions`.** Eine Abmeldung im Vorfeld gibt es bereits: `exceptions` mit
  `exception_type = 'absence'`, Freigabe über `status`. Eine zweite, freigabefreie Absage
  daneben zu stellen, erzeugt zwei Wahrheiten über denselben Sachverhalt. Entweder wird die
  Zusage der neue Vordergrund und die Entschuldigung entsteht daraus (Absage mit Begründung →
  `exceptions`-Eintrag), oder `exceptions` wird um einen zusagenden Fall erweitert. Diese
  Entscheidung ist wichtiger als die Oberfläche.
- **Braucht eine Absage weiterhin eine Freigabe?** Bei einer Pflichtprobe ja, bei einem
  freiwilligen Arbeitseinsatz nein. Womöglich eine Eigenschaft der Terminart.
- **Nutzt es ohne Erinnerung überhaupt?** Eine Abfrage, an die niemand erinnert wird,
  beantwortet die Hälfte der Mitglieder nicht — siehe FI-6.
- **Wer sieht die Antworten der anderen?** Eine offene Liste fördert Verbindlichkeit, ist aber
  eine Offenlegung personenbezogener Daten innerhalb des Vereins (`DATENSCHUTZ.md`).

---

### FI-2 · Abgleich Zusage ↔ tatsächliche Anwesenheit
**Nutzen:** hoch · **Aufwand:** S — **setzt FI-1 voraus**

**Teilweise umgesetzt in 1.7.0** — nur als Gegenüberstellung je Termin für Admin und Manager.
Eine personenbezogene Kennzahl gibt es bewusst nicht; die Frage „auf wen ist Verlass"
beantwortet die Zuverlässigkeit (1.5.1), die rechtzeitige Absagen seit 1.7.0 mitzählt.

Gegenüberstellung von angekündigtem und eingetretenem Verhalten: zugesagt und gekommen,
zugesagt und nicht gekommen, abgesagt und trotzdem da, gar nicht geantwortet.

**Warum interessant:** Es ist der Grund, warum die Zusage mehr ist als eine Umfrage. Der Verein
erfährt, auf wessen Zusage Verlass ist — und ein Auftritt lässt sich planen. Technisch fast
geschenkt: Beide Seiten liegen dann in derselben Datenbank, es ist ein Join plus eine
Auswertungsspalte.

**Berührt:** `statistics.php` (neuer Block analog zum `worktime`-Block) · `statistics.js` ·
`export.php` (eigener Export-Typ).

**Vorher zu klären:** Wie hart wird das dargestellt? Eine Spalte „Zusageverlässlichkeit 62 %"
ist eine Bewertung von Personen und keine Betriebsstatistik mehr. Im Ehrenamt kann das
Stimmung kosten. Möglicherweise nur aggregiert je Termin und je Gruppe zeigen, personenbezogen
nur dem Mitglied selbst — dieselbe Abwägung, die `my_data` schon einmal getroffen hat.

---

### FI-14 · Untergruppen (Register) und Besetzungsübersicht
**Nutzen:** mittel — hoch zusammen mit [FI-1](#fi-1--terminzusage-im-vorfeld) · **Aufwand:** M

**Teilweise umgesetzt in 1.8.0** — Spec
`docs/superpowers/specs/2026-09-16-untergruppen-gliederung-design.md`. Gebaut ist Variante B
(Gruppenart, siehe Tabelle unten): eine Gruppe lässt sich als Untergruppe markieren
(`is_subgroup`) und bekommt eine pflegbare Reihenfolge (`sort_order`); Anwesenheitslisten und
die Namensliste der Terminrückmeldung lassen sich per Umschalter „Alphabetisch · Gruppe ·
<Wort>" danach gliedern, der Oberbegriff ist frei wählbar (Einstellung `subgroup_label`, z. B.
„Register" oder „Mannschaft"). Offen bleibt aus der ursprünglichen Idee weiterhin die
**Besetzungsübersicht mit Sollstärke** — „Klarinette 3 von 6" statt nur der Gliederung nach
Register. Der Rest dieses Abschnitts beschreibt diesen offenen Teil.

Zusammen mit der Zusage ([FI-1](#fi-1--terminzusage-im-vorfeld), umgesetzt in 1.7.0) ergäbe das
die eigentlich interessante Auskunft vor einem Auftritt: nicht „38 von 55 haben zugesagt",
sondern „Klarinette 3 von 6, Horn 0 von 2" — also die Frage, ob das Stück überhaupt spielbar
ist.

**Warum interessant:** Eine reine Kopfzahl beantwortet die Besetzungsfrage nicht. Fehlen zehn
Beliebige, geht die Probe; fehlt die einzige Tuba, klingt sie nicht.

| Variante | Was sie kostet | Was sie kann |
|---|---|---|
| **A: gar nichts** — Register als gewöhnliche Gruppe | nichts | Zuordnung und Filter. Keine Auswertung „nach Register" |
| **B: Gruppenart** — Häkchen `is_subgroup` und `sort_order` an `member_groups` | eine Migration, etwas Oberfläche | **Umgesetzt in 1.8.0:** Listen nach Register gliedern, ohne dass bestehende Logik sich ändert. Keine Besetzungsübersicht |
| **C: echte Hierarchie** — `parent_group_id` in `member_groups` | schlägt in Terminarten-Zuordnung, Sichtbarkeitsprüfung, Cross-Filtering, Statistik, Import/Export durch | Vererbung: ein Termin für das Orchester erreicht alle Register automatisch |

Variante C bleibt bewusst weggelassen (Spec-Abschnitt 10): Ein Mitglied in mehreren
Untergruppen wird mehrfach angezeigt, statt es einem Hauptregister zuzuordnen oder Ebenen zu
vererben — die Doppelnennung ist die gewählte Antwort auf Mehrfachzugehörigkeit, nicht eine
Hierarchie.

**Berührt (für die Besetzungsübersicht):** `statistics.php`, Filterleisten der
Mitgliederliste, Druckbericht der Besetzung (bleibt bewusst nach Terminart-Gruppe gegliedert,
Spec-Abschnitt 10) · bei Variante C zusätzlich `appointment_type_groups`,
`hasStatisticsGroupAccess()` und die Cross-Filtering-Logik aus
`docs/superpowers/specs/2026-04-15-dropdown-cross-filtering-design.md`.

**Vorher zu klären, noch offen:**

- **Sollstärke je Register.** „3 von 6" setzt voraus, dass irgendwo 6 steht. Ist das die Zahl
  der zugeordneten Mitglieder oder eine gepflegte Mindestbesetzung? Ersteres ist geschenkt,
  Letzteres aussagekräftiger und ein weiteres Pflegefeld.
- **Zählweise bei Doppelspielern.** 1.8.0 zeigt, wer in zwei Registern steht, zweimal an —
  gewollt für die Gliederung (Spec 3.2), aber ungelöst für eine Auswertung: Zählt „Klarinette 3
  von 6" einen Doppelspieler mit, der schon bei „Saxophon 2 von 4" mitgezählt wurde? Eine
  Besetzungsübersicht muss das entscheiden, die entdoppelten Kennzahlen der Rückmeldung
  (`responseSummary()`) helfen hier nicht weiter.
- **Wieviel Hierarchie für Variante C wirklich?** Nur relevant, falls C doch einmal verfolgt
  wird: Erbt eine Untergruppe die Terminarten der Obergruppe? Sieht ein Nutzer mit Zugriff auf
  das Register auch die Orchesterdaten — oder umgekehrt? Jede Antwort ist für sich vertretbar,
  muss aber zusammenpassen.

---

## B · Weitere Check-in-Wege

### FI-3 · GPS-gestützter Check-in
**Nutzen:** mittel · **Aufwand:** M

Für Termine an wechselnden Orten (Auftritt, Umzug, Arbeitseinsatz im Wald) gibt ein
Administrator den Check-in frei und hinterlegt Koordinaten samt Radius. Wer sich innerhalb des
Radius befindet, kann sich in der PWA eintragen.

**Warum interessant:** Deckt genau die Lücke, für die keine TOTP-Station aufgebaut werden kann.
Eine feste Station im Proberaum lohnt sich, für einen einmaligen Auftritt lohnt sie sich nicht.

**Wo die Koordinaten sitzen, entscheidet [FI-23](#fi-23--ort-und-ende-am-termin)** — und zwar
vorher. Dort fällt die Wahl zwischen einem Ortsfeld am Termin und einer eigenen Ortstabelle.
Fällt sie auf die Tabelle, hängen Koordinaten und Radius dort; fällt sie auf das Freitextfeld,
braucht FI-3 eine eigene Ablage. Diese Frage hier nicht ein zweites Mal aufmachen.

**Berührt:** Koordinaten und Radius am Termin oder an einer eigenen Check-in-Freigabe · neue
Quelle im `checkin_source`-Enum von `records` (Migration; das Feld ist heute
`admin|user_totp|device_auth|auto_checkin|import`) · `auto_checkin.php` · PWA-Erfassen-Tab ·
`DATENSCHUTZ.md`. Wie eine neue Quelle sauber gekennzeichnet wird, zeigt seit 1.3.0
`station_pin` — Enum-Wert, Anzeige „Station (PIN)" in der Oberfläche und Erwähnung in
`DATENSCHUTZ.md` gehören zusammen.

**Vorher zu klären:**

- **Beweiswert.** Browser-Standort ist ohne Aufwand fälschbar — Entwicklerwerkzeuge und
  Mock-Location-Apps genügen. Für einen Förder-Verwendungsnachweis ist das schwächer als eine
  TOTP-Station (vgl. OI-6 in `OPEN-ITEMS.md` und `DATENSCHUTZ.md` Abschnitt 10.7). Die Quelle
  muss im Datensatz und in jeder Auswertung als das kenntlich sein, was sie ist.
- **Was wird gespeichert?** Aus Datenschutzsicht nur das Ergebnis („innerhalb des Radius, Ort
  *Stadthalle*"), nicht die Rohkoordinate des Mitglieds. Alles andere ist eine
  Standortdatenbank über Ehrenamtliche und braucht eine sehr gute Begründung.
- **Freigabefenster.** Nur solange der Termin läuft, mit Vor- und Nachlauf — sonst kann sich
  jemand drei Wochen später am selben Ort eintragen.
- **Technische Voraussetzung.** Die Geolocation-API verlangt HTTPS. Auf einer per HTTP
  betriebenen Vereinsinstanz ist die Funktion schlicht nicht verfügbar; das gehört in die
  Installationsvoraussetzungen.

---

### FI-4 · Registrierungsprozess für Auth-Geräte
**Nutzen:** mittel · **Aufwand:** M

Der Gerätetyp `auth_device` existiert bereits in `users.device_type` und wird in der
Geräteverwaltung als „Authentifiziert Benutzer (z. B. Fingerabdruck, Karte, PIN)" beschrieben —
ein festgelegter Registrierungs- und Zuordnungsweg existiert nicht. Diese Idee holt das nach:
Wie kommt ein Gerät in den Verein, wie lernt es ein Mitglied, wie wird es wieder entzogen?

Das Verfahren *PIN, serverseitig geprüft* und der Registrierungsweg der virtuellen Station
sind seit 1.3.0 gebaut. Offen bleiben NFC und Biometrie.

**Warum interessant:** Ohne definierten Ablauf ist der Gerätetyp eine Zusage, die die Software
nicht einlöst. Und der Weg entscheidet mit, ob OI-6 — TOTP-Secret im Klartext — sich bei dieser
Gelegenheit gleich mit erledigen lässt: In der Spec ist die Selbstregistrierung der Station
bereits als Lösungsweg vorgemerkt.

**Berührt:** `users.php` · `devices.js` · `regenerate_token.php` · Gerätedokumentation ·
je nach Entscheidung eine Zuordnungstabelle Gerät ↔ Mitglied (Kartennummer, Template-Id).

**Vorher zu klären — die drei genannten Verfahren sind nicht ein Thema, sondern drei:**

| Verfahren | Wo liegt das Geheimnis | Trägt es sich selbst? |
|---|---|---|
| TOTP | Secret auf Server **und** Station, symmetrisch | nein — Registrierung heißt: einmalige Übertragung, verschlüsselte Ablage, kein Rücklesen |
| NFC | Kartennummer, praktisch eine Kennung ohne Geheimnis | nein — klonbar, nur so gut wie die Kontrolle über die Karten |
| Biometrie | Template auf dem Gerät (ESP32-Sensor) oder Schlüsselpaar im Endgerät (WebAuthn) | ja, im Fall WebAuthn |

Insbesondere sind **Fingerabdrucksensor am ESP32** und **WebAuthn auf dem Mitgliedstelefon**
zwei völlig verschiedene Produkte mit verschiedenem Datenschutzprofil: biometrisches Template in
der Hand des Vereins gegenüber einem privaten Schlüssel, der den Server nie erreicht. Vor allem
anderen ist zu entscheiden, welches der drei Verfahren überhaupt gebaut wird — alle drei
gleichzeitig ist der sichere Weg, keines davon fertig zu bekommen.

---

### FI-5 · PIN-Anmeldung am Auth-Gerät
**Nutzen:** mittel · **Aufwand:** M — **setzt FI-4 voraus**

**Umgesetzt in 1.3.0** — Spec `docs/superpowers/specs/2026-09-04-station-pin-kiosk-design.md`.

Für Mitglieder ohne installierte PWA, ohne NFC-Karte und ohne Fingerabdruck: Eingabe einer
persönlichen PIN an einem fest installierten Gerät — Prinzip Stempeluhr. Die PIN vergibt sich
das Mitglied selbst im Profil des Dashboards.

**Warum interessant:** Deckt den Rest ab. In jedem Verein gibt es Mitglieder ohne Smartphone
oder ohne Bereitschaft, eine App zu installieren; solange für die noch eine Liste geführt wird,
ist die Erfassung nicht digital. Für die Arbeitszeiterfassung mit Kommen und Gehen ist die
Analogie zur Stempeluhr zudem genau das, was Nutzer erwarten.

**Berührt:** `users` oder `members` um einen PIN-Hash erweitern (Migration) · `profile.js` ·
`change_password.php` als Vorbild für die Änderung · eigener Endpunkt für die Geräteanmeldung ·
`rate_limiter.php`.

**Vorher zu klären:**

- **Die PIN allein genügt nicht als Kennung.** Bei vierstelliger PIN und sechzig Mitgliedern
  kollidieren Werte zwangsläufig. Der Ablauf braucht zwei Schritte: erst Mitglied wählen oder
  Kennnummer eingeben, dann PIN. Bedienbar ist das, entworfen werden muss es trotzdem.
- **Rohgewalt.** Vier Stellen sind 10 000 Möglichkeiten. Das vorhandene Rate Limiting greift pro
  IP und Nutzer — an einer Station ist die IP für alle dieselbe. Es braucht eine Sperre je
  Mitgliedskonto plus eine Verzögerung, sonst ist die Idee ein Rückschritt gegenüber TOTP.
- **Speicherung.** Nur als Hash über `password_hash()`, nie im Klartext, nie in der
  Geräteverwaltung anzeigbar — ausdrücklich nicht der Weg, den `totp_secret` heute geht.
- **Beweiswert.** Eine PIN ist weitergebbar. Für die Anwesenheitserfassung im Verein
  angemessen, für einen Verwendungsnachweis das schwächste der Verfahren. Wie bei FI-3:
  kenntlich machen, nicht überhöhen.

---

## C · Vereinspflege und Komfort

### FI-6 · Benachrichtigungskanal (E-Mail, Web-Push)
**Nutzen:** hoch · **Aufwand:** M

Ein gemeinsamer Weg, Mitglieder aktiv zu erreichen: Terminerinnerung, Bitte um Zusage,
Entscheidung über einen Antrag, Freigabe einer Arbeitszeit.

**Warum interessant:** Heute ist EhrenSache eine Holschuld — wer nicht hineinschaut, erfährt
nichts. Fast jede andere Idee auf dieser Liste wird erst dadurch wirksam, FI-1 am deutlichsten.
Die Bausteine liegen bereits: Mailer, Vorlagensystem mit `base.html`, Service Worker in der PWA.

**Berührt:** `mailer.php` · `private/email_templates/` · Benachrichtigungseinstellungen in
`system_settings` und je Mitglied · Service Worker für Web-Push (VAPID-Schlüssel, Abo-Tabelle).

**Anlässe, die es heute schon gäbe:** offene Rückmeldung vor Ablauf der Frist (die Frist selbst
ist seit 1.7.0 gebaut, `responseDeadlineHours()`), Absage eines Mitglieds an Admin und Manager,
Entscheidung über einen Antrag, Freigabe einer Arbeitszeit.

**Rückmeldung direkt aus der Mail** — ein signierter Einmal-Link je Mitglied und Termin, der
ohne Anmeldung auf Zusage oder Absage führt. Das ist der Unterschied zwischen einer Mail, die
gelesen wird, und einer, die beantwortet wird. Es ist zugleich der heikelste Teil: Der Link ist
ein Zugangsmittel im Postfach. Zu entscheiden wären Gültigkeitsdauer, Einmaligkeit,
Widerrufbarkeit und was der Link außer der einen Antwort erlaubt — nach heutigem Stand nichts,
insbesondere kein Einloggen. Vor dem Bau gehört das durch dieselbe Prüfung wie die
Passwort-Reset-Token.

**Vorher zu klären:** Der Versand braucht einen Auslöser zur richtigen Zeit — ohne Cron auf dem
Hosting bleibt nur ein Anstoß beim nächsten Seitenaufruf, was unzuverlässig ist. Außerdem:
E-Mail zuerst (funktioniert überall) oder Push zuerst (auffälliger, aber an die PWA gebunden)?
Und wer schaltet was ab — sonst empfindet ein Teil der Mitglieder das Ganze als Belästigung.

**Was den Cron-Teil entschärft:** `private/demo/cron.php` zeigt seit 1.6.x, dass ein
argumentloser Dateiaufruf als Aufhänger im Shared Hosting trägt. Das löst die Frage nicht — ein
Verein ohne Aufgabenplaner bleibt ohne Versand —, aber das Muster ist erprobt und muss nicht
erfunden werden. Solange FI-6 nicht steht, deckt [FI-17](#fi-17--offene-punkte-unter-mein-konto)
denselben Bedarf als Holschuld: ohne Infrastruktur, ohne Zustellrisiko und mit einem Bruchteil
des Aufwands.

---

### FI-7 · Terminserien für wiederkehrende Proben
**Nutzen:** hoch · **Aufwand:** M

Ein Termin mit Wiederholungsregel („jeden Dienstag 19:30 bis Ende Juli") erzeugt die
Einzeltermine; einzelne Ausfälle lassen sich streichen.

**Warum interessant:** Die wöchentliche Probe ist der Normalfall eines Musikvereins. Heute legt
jemand rund vierzig Termine im Jahr von Hand an — die absehbar lästigste wiederkehrende Arbeit
im System und ein guter Grund, es gar nicht erst zu benutzen.

**Berührt:** `appointments` um einen Serienbezug erweitern (Migration) · `appointments.php` ·
`appointments.js` · Kalenderansicht.

**Entwurfsstand vom 2026-09-16.** Die beiden Fragen, die hier bis dahin offen standen —
materialisieren oder berechnen, und was beim Ändern mit bereits erfassten Anwesenheiten
geschieht — sind aus dem Ideen-Backlog beantwortet. Sie sind damit **nicht** beschlossen; sie
sind ein durchgerechneter Vorschlag, der in eine Spec gehört, bevor eine Zeile entsteht:

| Frage | Vorschlag | Begründung |
|---|---|---|
| Speicherform | Serie als Regel, dazu **echte Einzeltermine** mit `series_id` | `records`, `exceptions` und `appointment_responses` zeigen alle auf eine `appointment_id`. Eine berechnete Serie hätte keine |
| Horizont | offene Serien bis ca. 12 Monate erzeugen, ein Cron verlängert | Ohne Cron bleibt der Verein im ersten Jahr trotzdem vollständig bedient — die Verlängerung ist Komfort, kein Blocker (anders als bei [FI-6](#fi-6--benachrichtigungskanal-e-mail-web-push)) |
| Regelformat | Teilmenge von RFC 5545, z. B. `FREQ=WEEKLY;INTERVAL=2;BYDAY=TU` | Direkt verwendbar für [FI-8](#fi-8--kalender-abo-ics-feed), kein eigenes Format zu pflegen |
| Bearbeiten | „nur dieser" / „dieser und alle folgenden" / „alle" | Der Bedienstandard jedes Kalenders; alles andere überrascht |
| Einzeln Geänderte | Kennzeichen `is_detached`, wird von der Serie nicht mehr überschrieben | Sonst überschreibt eine Serienänderung genau die Termine, die jemand bewusst angefasst hat |
| Ausfälle | übersprungene Daten in `exdates` der Serie | Ein gestrichener Termin ist eine Eigenschaft der Regel, kein gelöschter Datensatz |
| Löschen | Termine mit Anwesenheiten oder Rückmeldungen nie hart löschen, nur `is_cancelled` | Erfasste Anwesenheit ist die Primärdatei des Systems |
| Zeitzone | lokale Zeit speichern (`DATE` + `TIME`), Erzeugung über `DateTimeImmutable` | „Jeden Dienstag 19:30" heißt auch nach der Zeitumstellung 19:30 |
| Gruppen | Zuordnung an der Serie, Vererbung auf die Einzeltermine | Ansonsten pflegt jemand vierzigmal dieselbe Gruppe |

**Weiterhin offen:** Was geschieht bei „dieser und alle folgenden", wenn an einem der folgenden
Termine schon Anwesenheiten hängen — mitziehen, abhängen (`is_detached`) oder die Änderung
verweigern? Der Vorschlag oben legt das Abhängen nahe, entschieden ist es nicht. Zweitens die
Rückwirkung auf [FI-1](#fi-1--terminzusage-im-vorfeld): Erbt ein neu erzeugter Serientermin die
Rückmeldepflicht seiner Terminart, und was passiert mit den Antworten, wenn ein Termin
verschoben wird?

**Gehört zusammen mit** [FI-16](#fi-16--feiertage-und-ferien-im-terminkalender) entworfen: Eine
Wochenserie ohne die Option „Feiertage überspringen" legt den Termin zuverlässig auf
Fronleichnam.

**Eingeplant am 2026-09-18** als 1.11.0, zusammen mit dem Feiertagsteil von FI-16 und
[OI-64](OPEN-ITEMS.md#oi-64--im-kalender-lässt-sich-kein-termin-anlegen) (Anlegen per Klick in
den Kalender — dort beginnt eine Serie). Erst nach 1.10.0: Eine Serie muss Ort und Ende aus
[FI-23](#fi-23--ort-und-ende-am-termin) mitführen, und beide Vorhaben bräuchten sonst eine
Migration mit demselben `from`.

---

### FI-23 · Ort und Ende am Termin
**Nutzen:** mittel · **Aufwand:** M — *der Ort allein wäre S*

Ein Termin trägt heute Titel, Beschreibung, Datum und Startzeit — mehr nicht
(`{PREFIX}appointments`). Es fehlen **wo** und **wie lange**. Wer den Ort eines Auftritts
mitteilen will, schreibt ihn in die Beschreibung; wer die Dauer angeben will, gar nicht.

**Rein informativ.** Beide Felder sind eine Mitteilung an die Mitglieder und **nichts
weiter**. Das Ende wird nicht ausgewertet, beeinflusst die Anwesenheit nicht und verändert die
Pünktlichkeitsrechnung nicht — ein Check-in nach Terminende bleibt genau das, was er heute ist.
Das ist die wichtigste Festlegung dieses Eintrags, weil sie den Aufwand begrenzt: Ohne sie
zöge das Feld `statistics.php`, den Druckbericht und die Zeitkorrektur nach sich.

**Warum interessant:** Weniger wegen des eigenen Nutzens als deshalb, weil **drei Einträge
dieser Liste die Felder bereits voraussetzen, ohne es zu sagen**:

- [FI-8](#fi-8--kalender-abo-ics-feed) braucht `LOCATION` und `DTEND`. Ohne sie liefert das
  Kalender-Abo ortlose Termine mit geratener Länge — und verliert genau den Nutzen, der es zur
  günstigsten Idee der Liste macht. Die Einstufung „Aufwand S" bei FI-8 gilt nur, wenn die
  Felder vorher existieren.
- [FI-19](#fi-19--terminvorlagen) nennt „Ort" wörtlich als eines der Vorbelegungsfelder.
- [FI-3](#fi-3--gps-gestützter-check-in) hängt Koordinaten an den Termin. Anderer Datentyp,
  aber dieselbe Stelle im Modell — wird beides getrennt entschieden, steht später ein
  Ortsname neben einem Koordinatenpaar, das nichts von ihm weiß.

Für die Mitglieder zählt der Unterschied zwischen Probenabend und Auftritt: Die wöchentliche
Probe ist immer am selben Ort und ungefähr gleich lang, ein Auftritt oder Arbeitseinsatz nicht.
Gerade dort steht die Information heute im Fließtext oder nirgends.

**Berührt:** Migration plus `private/setup/ehrensache_db.sql` ·
`private/handlers/appointments.php` (SELECT, `$allowedFields` in POST und PUT,
Dublettenprüfung) · `public/js/modules/appointments.js` (Tabelle, Modal, Kalender-Popup) ·
PWA: Terminwahl beim Check-in und Terminliste · CSV-Import und -Export samt Spaltenzuordnung ·
`API.md`. **Nicht berührt:** `statistics.php`, Pünktlichkeit, Druckberichte.

**Vorher zu klären:**

- **Kein zweites Freitextfeld.** `description` gibt es bereits und steht im Dashboard als
  eigene Tabellenspalte. Ein zusätzliches „Anmerkungen" wären zwei Felder ohne trennscharfe
  Bedeutung — die füllt auf Dauer niemand konsistent, und die Anzeige muss beide unterbringen.
  Vorschlag: Ort und Ende als benannte Felder, Beschreibung bleibt, wie sie ist.
- **Dauer als Endzeit oder als Minutenzahl?** `end_time TIME NULL` liegt an ICS (`DTEND`) näher
  und liest sich im Formular natürlicher, braucht aber eine Regel für den Überlauf über
  Mitternacht (Vorschlag: `end_time < start_time` bedeutet Folgetag — kleinster Eingriff, weil
  `date` und `start_time` ohnehin getrennt liegen). `duration_minutes` hat das Problem nicht,
  zwingt aber jede Anzeige zum Rechnen und jede Eingabe zum Umdenken.
- **Ort als Freitext oder als eigene Tabelle?** Freitext ist eine Spalte und fertig. Eine
  Ortsliste (`locations`) wäre dagegen die Stelle, an der [FI-3](#fi-3--gps-gestützter-check-in)
  später Koordinaten und Radius anhängt, statt sie ein zweites Mal am Termin zu führen. Die
  Entscheidung gehört deshalb **vor** FI-3, nicht danach.
- **Beide Felder optional.** Alles andere bricht jeden Bestandstermin und jeden automatisch
  erzeugten Check-in-Termin, der weder Ort noch Ende kennt und auch keins bekommen kann.
  Anzeige und Export müssen den leeren Fall sauber darstellen — das ist der Normalfall, nicht
  die Ausnahme.
- **Reimport-Identität bleibt unberührt.** Ein Termin wird beim CSV-Reimport über Datum,
  Uhrzeit und Terminart wiedererkannt (siehe `OPEN-ITEMS.md`, Abschnitt zur Terminzuordnung).
  Weder Ort noch Ende dürfen in dieses Merkmal einfließen, sonst erzeugt derselbe Bestand nach
  einer Ortskorrektur Dubletten.

**Aufteilbar.** Der Ort zuerst ist der lohnendste Schritt: eine optionale Spalte, keine
Berührung der Auswertung, und er hebt sofort den Wert von FI-8 und FI-19. Das Ende kann mit
FI-8 zusammen kommen oder warten, bis ein Verein danach fragt.

---

### FI-8 · Kalender-Abo (ICS-Feed)
**Nutzen:** mittel · **Aufwand:** S — **setzt [FI-23](#fi-23--ort-und-ende-am-termin) voraus**

Persönliche, mit Token geschützte Kalender-URL, die jedes Mitglied in Telefon oder
Mail-Programm abonniert. Nur lesend, nur die Termine der eigenen Gruppen.

**Der Aufwand „S" gilt nur mit FI-23.** Ohne `location` und `end_time` am Termin exportiert der
Feed Einträge ohne Ort und mit geratener Länge — technisch ein gültiger Kalender, praktisch
eine Liste von Titeln. Die Felder nachzuliefern ist Arbeit an `appointments`, nicht am Feed,
und gehört deshalb dorthin.

**Warum interessant:** Sehr viel Wirkung für sehr wenig Code — ICS ist Textausgabe, keine
Bibliothek nötig, was zur Linie des Projekts passt (kein PDF-Export, „würde eine Bibliothek
einschleppen"). Vereinstermine stehen damit dort, wo die Leute ohnehin hinschauen.

**Berührt:** neue Ressource in `api.php` · Token-Erzeugung analog `regenerate_token.php` ·
Profilbereich für die URL.

**Vorher zu klären:** Ein Abo-Link wird zwangsläufig weitergegeben oder landet in einem
Cloud-Kalender. Deshalb ein eigenes, separat widerrufbares Token — nicht das API-Token
wiederverwenden, das schreibenden Zugriff hätte.

**Nur die Abo-Richtung.** FI-8 gibt Termine nach außen. Die Gegenrichtung — fremde Kalender
einlesen, etwa Schulferien — ist ein anderes Feature mit anderen Problemen und steht als
[FI-18](#fi-18--kalender-import-ics). Gemeinsam ist beiden nur das Dateiformat. Wird
[FI-7](#fi-7--terminserien-für-wiederkehrende-proben) vorher gebaut, fällt der Export der
Wiederholungsregel hier ohne Zusatzarbeit an, weil die Serie ohnehin in RFC-5545-Schreibweise
vorliegt.

**Stand 2026-09-18:** Nach 1.10.0 (FI-23) erfüllt, damit echt „S". Bewusst **nicht** Teil von
1.11.0 (FI-7/FI-16) — unabhängig davon und als eigenes kleines Paket vor oder nach der Serie
möglich; danach entfällt nur der Nachtrag der Regel.

---

### FI-16 · Feiertage und Ferien im Terminkalender
**Nutzen:** mittel · **Aufwand:** M — **entfaltet sich erst mit [FI-7](#fi-7--terminserien-für-wiederkehrende-proben)**

Der Kalender kennt gesetzliche Feiertage und, optional, Schulferien. Eine Terminserie bekommt
dadurch die Optionen „Feiertage überspringen" und „Ferien überspringen".

**Warum interessant:** Ohne das legt jede Wochenserie zuverlässig Proben auf Karfreitag und in
die Sommerferien, und jemand räumt sie von Hand wieder ab — womit der Hauptnutzen von FI-7 zur
Hälfte wieder verloren ist. Für sich allein ist es dagegen nur Kalenderdekoration; deshalb
gemeinsam entwerfen, auch wenn nur FI-7 gebaut wird.

**Berechnen statt importieren.** Die beweglichen Feiertage hängen alle am Osterdatum, und das
lässt sich rechnen (Gauß/Meeus). Damit braucht es keine Datenquelle, keinen jährlichen Import
und keine Internetverbindung — und ausdrücklich **nicht** `ext-calendar`: `easter_days()` ist an
diese Erweiterung gebunden, die auf einem Teil der Hostings fehlt, und stünde damit im
Widerspruch zu `requires` in `version.json`. Eine eigene kleine Klasse (Arbeitstitel
`HolidayCalculator`) ist ein paar Dutzend Zeilen und hat keine Abhängigkeit.

**Berührt:** neuer Helfer in `private/helpers/` · Bundesland als Einstellung in
`system_settings` · Kalenderansicht in `appointments.js` · bei FI-7 zwei Serienoptionen.

**Vorher zu klären:**

- **Wie weit geht der Feiertagsteil?** Bundesweite Feiertage plus das eigene Bundesland deckt
  den Normalfall. Die Sonderfälle sind zwei: Buß- und Bettag ist nur in Sachsen gesetzlich, und
  Mariä Himmelfahrt gilt in Bayern nur in Gemeinden mit überwiegend katholischer Bevölkerung —
  Letzteres ist auf Bundeslandebene gar nicht korrekt abbildbar. Ehrlicher wäre, das offen zu
  benennen und zusätzlich eigene Termine als Feiertag markierbar zu machen, als eine Tabelle zu
  pflegen, die stillschweigend falsch liegt.
- **Ein Bundesland oder mehrere?** Ein Verein an einer Landesgrenze hat Mitglieder aus zwei
  Bundesländern. Voraussichtlich trotzdem eine Einstellung je Installation — alles andere zieht
  eine Zuordnung je Mitglied nach sich.
- **Ferien sind keine Feiertage.** Sie sind nicht berechenbar, ändern sich jährlich und müssten
  eingelesen werden — das ist [FI-18](#fi-18--kalender-import-ics) und sollte FI-16 nicht
  blockieren. Feiertage zuerst, Ferien später oder nie.
- **Was heißt „überspringen"?** Den Termin gar nicht erzeugen oder ihn als abgesagt anlegen?
  Ersteres ist sauberer, Letzteres zeigt dem Mitglied, dass an dem Tag bewusst nichts ist.
  Berührt `is_cancelled` und `exdates` aus FI-7 und ist dort mitzuentscheiden.

**Eingeplant am 2026-09-18:** Der **Feiertagsteil** geht mit FI-7 in 1.11.0. Ferien bleiben
draußen — sie hängen an [FI-18](#fi-18--kalender-import-ics), und das ist nicht eingeplant.
Einen „Feiertag-Import" gibt es in diesem Sinne nicht: berechnet, nicht eingelesen.

---

### FI-17 · Offene Punkte unter „Mein Konto"
**Nutzen:** hoch · **Aufwand:** S

Eine Übersicht im eigenen Bereich, die zeigt, was das System gerade von einem will: Termine
ohne Rückmeldung, deren Frist läuft; ein abgelehnter oder noch offener Entschuldigungsantrag;
eine Arbeitszeit, die auf Freigabe wartet; ein Hinweis, den ein Admin hinterlegt hat.

**Warum interessant:** Es ist die billigste Antwort auf das Problem, das
[FI-6](#fi-6--benachrichtigungskanal-e-mail-web-push) teuer löst. EhrenSache ist heute eine
Holschuld — wer hineinschaut, erfährt nichts Gebündeltes, sondern muss vier Bereiche einzeln
durchsehen. Eine Sammelansicht braucht dafür keinen Versandweg, keinen Cron, keine
VAPID-Schlüssel und kein Opt-out je Mitglied: Die Daten liegen alle schon da, es ist im Kern
eine Abfrage und eine Karte. Wer beides baut, speist FI-6 später aus derselben Quelle.

**Berührt:** Profilbereich in `public/index.html` und `public/js/modules/profile.js` · eine
sammelnde Abfrage (entweder neue Ressource oder Erweiterung von `session_info`) ·
`responses.php` für die offenen Rückmeldungen · PWA, wenn die Übersicht auch dort erscheinen
soll.

**Vorher zu klären:**

- **Eine Ressource oder vier Abfragen?** Vier vorhandene Endpunkte nacheinander aufzurufen ist
  ohne neuen Code zu haben, kostet aber vier Anfragen bei jedem Aufruf des Profils. Eine eigene
  Ressource ist sauberer und der Ort, an dem später auch FI-6 nachsieht.
- **Was ist eine „Nachricht eines Admins"?** Das ist der Punkt, an dem der Eintrag kippen kann.
  Ein vom Admin gesetzter Hinweistext, den jeder sieht, ist eine Einstellung und harmlos. Eine
  Nachricht **an ein bestimmtes Mitglied** ist der Anfang eines Postfachs — und damit genau das,
  was unter „Nicht auf dieser Liste" als Chat ausgeschlossen ist. Vorschlag: nur systemerzeugte
  Punkte plus ein globaler Hinweistext; alles Adressierte bleibt draußen, bis jemand einen
  belegten Bedarf nennt.
- **Leerer Zustand.** Die Ansicht muss gut aussehen, wenn nichts offen ist — das ist der
  Normalfall. „Nichts zu tun" ist eine Aussage, keine leere Liste.
- **Nur eigene Daten.** Die Übersicht zeigt ausschließlich den angemeldeten Benutzer. Für
  Manager ist sie keine Arbeitsliste — deren offene Freigaben sind eine andere Frage und
  gehören nicht hierher.

---

### FI-18 · Kalender-Import (ICS)
**Nutzen:** niedrig · **Aufwand:** M

Eine `.ics`-Datei oder eine abonnierte URL einlesen und daraus Termine oder Sperrzeiten
erzeugen — in erster Linie Schulferien und Schließtage, die kein Mensch von Hand einträgt.

**Warum interessant:** Die Gegenrichtung zu [FI-8](#fi-8--kalender-abo-ics-feed) und die
einzige praktikable Quelle für Ferien, die sich nicht berechnen lassen (siehe
[FI-16](#fi-16--feiertage-und-ferien-im-terminkalender)).

**Warum eher nicht, jedenfalls nicht bald:** Lesen ist um ein Vielfaches aufwendiger als
Schreiben. ICS erlaubt Zeitzonen, Wiederholungsregeln, Ausnahmen, Anhänge und beliebig große
Dateien; ein Import muss entscheiden, was er davon ignoriert, was ein Termin wird und was
passiert, wenn dieselbe Datei ein zweites Mal kommt. Dazu ist es eine Datei aus fremder Hand —
Größenbegrenzung, Zeitbudget und strikte Feldprüfung gehören von Anfang an dazu, nicht später.

**Vorher zu klären:** Einmaliger Upload oder dauerhaftes Abo mit erneutem Abruf? Werden
importierte Termine zu gewöhnlichen `appointments` (dann brauchen sie eine Herkunft und dürfen
nicht in die Anwesenheitsstatistik zählen) oder zu einer eigenen Kategorie „Sperrzeit", die nur
FI-7 beim Erzeugen berücksichtigt? Die zweite Variante ist kleiner und beantwortet den
eigentlichen Bedarf.

---

### FI-9 · Dienst- und Schichtplanung für Veranstaltungen
**Nutzen:** mittel · **Aufwand:** L — **setzt FI-1 voraus**

Für Feste: Schichten mit Aufgabe, Zeitfenster und Sollbesetzung („Ausschank Samstag 18–21 Uhr,
3 Personen"), in die sich Mitglieder eintragen. Die geleistete Schicht führt direkt in die
Arbeitszeiterfassung.

**Warum interessant:** Für Vereine mit Festbetrieb der Punkt, an dem heute Papierlisten und
Gruppenchats regieren. Zusammen mit der seit 1.2.0 vorhandenen Arbeitszeiterfassung und den
Tätigkeitsarten wäre der Kreis geschlossen: Planung, Erfassung und Nachweis in einem System.

**Berührt:** neues Datenmodell Schicht ↔ Termin ↔ Tätigkeitsart · Zuordnung Mitglied ↔ Schicht ·
`work_sessions` · Statistik · PWA.

**Vorher zu klären:** Das ist der größte Brocken der Liste und funktioniert erst, wenn die
Zusage (FI-1) steht — eine Schichtzuteilung ist deren Spezialfall. Vorher lohnt die Frage, ob
es die Bedarfslage überhaupt gibt: Ein Verein mit zwei Festen im Jahr plant die auf einem Zettel
und wird das weiter tun.

---

### FI-10 · Jubiläen und Ehrungen automatisch ermitteln
**Nutzen:** mittel · **Aufwand:** S

Eine Ansicht, die aus `membership_dates` die anstehenden Jubiläen eines Jahres berechnet — 10,
25, 40 Jahre aktive Mitgliedschaft, Schwellen konfigurierbar.

**Warum interessant:** Eine Aufgabe, die jeden Verein jedes Jahr trifft und heute in einer
Excel-Tabelle des Schriftführers lebt. Die Daten liegen bereits vollständig vor, samt der
Unterbrechungen — genau die Rechnung, die von Hand fehleranfällig ist. Zudem verwertet es
`member_activity.php`, das ohnehin schon existiert.

**Berührt:** `statistics.php` oder eine eigene kleine Ressource · Verwaltungsbereich ·
Schwellen in `system_settings` · Export.

**Vorher zu klären:** Zählen Pausen mit oder unterbrechen sie? Vereinssatzungen regeln das
unterschiedlich, also gehört es in die Einstellungen und nicht in den Code. Zweitens: Ist das
Eintrittsdatum in den Bestandsdaten realistisch gepflegt genug, damit die Zahl stimmt?

---

### FI-11 · Mehrsprachigkeit der Oberfläche
**Nutzen:** niedrig · **Aufwand:** L

Oberflächentexte aus Sprachdateien statt fest im Markup — zunächst Deutsch und Englisch.

**Warum interessant:** Öffnet das Projekt über den deutschsprachigen Raum hinaus, was für ein
AGPL-Projekt auf GitHub den Unterschied zwischen einzelnen und vielen Anwendern machen kann.

**Warum vermutlich nicht jetzt:** Betrifft jede HTML-Datei, jedes JS-Modul und jede
Server-Meldung, ohne einem bestehenden Nutzer irgendetwas zu bringen. Die Konvention „Deutsch in
der Oberfläche, Englisch im Code" ist bewusst gesetzt. Realistisch nur sinnvoll, wenn jemand von
außen die Übersetzung tatsächlich beiträgt — vorher wäre es Arbeit auf Vorrat.

---

### FI-12 · Material- und Instrumentenausleihe
**Nutzen:** niedrig · **Aufwand:** L

Verwaltung vereinseigener Gegenstände — Instrumente, Uniformen, Noten — mit Ausgabe, Rückgabe
und Zustand.

**Warum interessant:** Reales Problem jedes Musikvereins, und die Mitgliederverwaltung liegt
schon da.

**Warum eher nicht:** Es ist ein zweites Produkt in derselben Anwendung. Mit Anwesenheit,
Pünktlichkeit und Arbeitszeit hat es keine Datenberührung außer dem Mitglied selbst. Hier
festzuhalten ist trotzdem richtig, damit die Idee bei der nächsten Nennung nicht neu diskutiert,
sondern auf diesen Eintrag verwiesen werden kann.

---

### FI-13 · Geburtstagsliste mit Gratulationsvermerk
**Nutzen:** mittel · **Aufwand:** M

Liste der anstehenden Geburtstage für Manager und Admin, mit Vermerk je Jahr, ob und wie
reagiert wurde: gratuliert, Karte geschickt, Geschenk übergeben, Ständchen gespielt — plus
Notizfeld. Für Mitglieder der Rolle `user` nicht sichtbar.

**Warum interessant:** Gehört zu den Aufgaben, an denen Vorstände tatsächlich scheitern, und
scheitert immer auf dieselbe Art: Nicht das Datum ist das Problem, sondern die Frage „hat sich
schon jemand gekümmert?" Genau die beantwortet ein geteilter Erledigungsvermerk und eine
private Liste im Kalender des Schriftführers nicht. Vom Muster her identisch zu
[FI-10](#fi-10--jubiläen-und-ehrungen-automatisch-ermitteln): kalendarischer Stichtag plus
Erledigungsvermerk — wenn beides kommt, sollte es **ein** Modell sein und nicht zwei.

**Berührt:** `members` um `birth_date` erweitern (Migration) · neue Tabelle für den Vermerk
(member_id, year, art, notiz, erledigt_von, erledigt_am; unique je Mitglied und Jahr) ·
`members.php`, `members.js` · Import/Export · Rollenprüfung · `DATENSCHUTZ.md`.

**Vorher zu klären:**

- **Das Geburtsdatum gibt es heute nicht.** `members` führt nur Name, Vorname, Nummer und
  Aktiv-Kennzeichen; im gesamten Code kommt kein Geburtsdatum vor. Es muss also erst erhoben
  werden — der Aufwand liegt weniger in der Migration als beim Verein, der sechzig Datensätze
  nachpflegt. Ohne diese Bereitschaft ist die Liste leer und die Funktion wertlos.
- **Datenschutz.** Das Geburtsdatum ist eine neue Datenkategorie mit eigenem Zweck und eigener
  Löschfrist und gehört vor dem Bau in `DATENSCHUTZ.md`. Es ist zudem das Feld, das eine
  Mitgliederliste am ehesten zur Identitätsdatenbank macht. Sichtbarkeit deshalb eng halten:
  Manager und Admin, nicht in der allgemeinen Mitgliederansicht, und ein Mitglied sollte der
  Veröffentlichung im Verein widersprechen können.
- **Feste Kategorien oder Freitext?** Auswerten lässt sich nur, was kategorisiert ist; was
  Vereine tatsächlich tun, ist aber individuell. Wahrscheinlich beides: kleine feste Liste plus
  Notiz.
- **Wer bekommt überhaupt etwas?** Viele Vereine gratulieren nur zu runden Geburtstagen oder ab
  einem Alter. Schwellen konfigurierbar halten — dieselbe Entscheidung wie bei FI-10, und ein
  weiteres Argument für ein gemeinsames Modell.
- **Wen zeigt die Liste?** Ausgetretene und inaktive Mitglieder müssen über `membership_dates`
  gefiltert werden, sonst steht in der Januar-Liste jemand, der seit drei Jahren weg ist.
- **Erinnerung.** Ohne Hinweis ein paar Tage vorher schaut niemand in die Liste — siehe
  [FI-6](#fi-6--benachrichtigungskanal-e-mail-web-push). Ohne FI-6 nützt sie vor allem dem, der
  ohnehin regelmäßig hineinsieht.

---

## D · Rollen und Berechtigungen

### FI-15 · Rolle „Gruppenleiter"
**Nutzen:** hoch · **Aufwand:** L

Eine vierte Rolle zwischen `manager` und `user`: im Wesentlichen die Rechte eines Managers,
aber nur für die Gruppen, die diese Person leitet. Anwesenheiten und Entschuldigungen der
eigenen Gruppe sehen und pflegen, Statistik der eigenen Gruppe auswerten — und
Arbeitszeit-Anträge freigeben, sofern die Arbeitszeiterfassung für diese Gruppe greift.

**Warum interessant:** Es ist die Rolle, die Vereine tatsächlich haben — Registerführer,
Abteilungs- oder Jugendleiter. Heute bleibt nur die Wahl zwischen zu wenig (`user`: sieht nur
sich selbst) und zu viel (`manager`: sieht und ändert alles vereinsweit). In größeren Vereinen
ist das der Grund, warum die Pflege an zwei Personen hängen bleibt, statt sich zu verteilen.

**Der Preis, ehrlich benannt — es fehlt nicht ein Enum-Wert, sondern die halbe Zugriffslogik:**

Eine Gruppengrenze existiert im Backend **nirgends** außer für einfache Nutzer. In
`hasStatisticsGroupAccess()` ([statistics.php:227](../private/handlers/statistics.php)) steht
der ganze Mechanismus: Admin und Manager bekommen `true`, alle anderen werden gegen ihre
Gruppenzugehörigkeit geprüft. Das ist die einzige Stelle dieser Art. Zum Vergleich: `records`,
`exceptions`, `work_sessions`, `members`, `appointments` und `export` liefern Managern
schlicht alles, ohne Gruppenbedingung in der Abfrage.

Konkret sind das **41 Prüfungen auf `isAdminOrManager()` oder `requireAdminOrManager()` in
zwölf Handlern**. Jede davon muss beantwortet werden, und zwar zweimal:

1. *Darf diese Rolle die Aktion überhaupt?* — die einfachere Hälfte, eine Fallunterscheidung.
2. *Welche Zeilen sieht sie dabei?* — dafür braucht jede Listenabfrage eine Join-Bedingung
   über `member_group_assignments`, die es heute in keiner dieser Abfragen gibt.

Immerhin ist der Ausgangszustand sicher: `isAdminOrManager()` liefert für eine unbekannte
Rolle `false`, ein Gruppenleiter fiele also zunächst in den Nutzerpfad. Nichts öffnet sich
versehentlich — aber jede Fähigkeit muss einzeln freigeschaltet werden.

**Berührt:** `users.role` als ENUM erweitern (Migration) · `auth.php` (neue Helfer, `isAdmin`
und `isAdminOrManager` bleiben unangetastet) · alle zwölf genannten Handler · `api.js`,
`ui.js` und die Sichtbarkeit der Bereiche im Dashboard · `API.md` · Rollentabelle in
`CLAUDE.md` und `README.md`.

**Vorher zu klären:**

- **Leitung ist nicht Mitgliedschaft.** Ein Dirigent leitet mehrere Register, ohne in allen
  Mitglied zu sein; ein Jugendleiter ist selten selbst in der Jugendgruppe.
  `member_group_assignments` taugt deshalb nicht als Grundlage — es braucht eine eigene
  Zuordnung „leitet Gruppe", mehrfach je Person.
- **Überlappung.** Ein Mitglied gehört zu Blasorchester **und** Jugend. Sieht der Jugendleiter
  dessen Anwesenheit bei der Orchesterprobe? Datensätze in `records` und `work_sessions` hängen
  an Mitglied und Termin, nicht an einer Gruppe — die Zuordnung ist also mehrdeutig, und die
  Antwort muss gesetzt werden, bevor die erste Abfrage geschrieben wird. Die naheliegende
  Regel: Der Gruppenleiter sieht den Datensatz, wenn der **Termin** seiner Gruppe zugeordnet
  ist, nicht schon dann, wenn das Mitglied es ist.
- **„Arbeitszeiterfassung für die Gruppe aktiv" gibt es heute nicht.** `worktime_enabled` ist
  ein globaler Schalter in `system_settings`. Gruppenbezug existiert nur bei den Tätigkeitsarten
  über `activity_type_groups` (seit 1.2.0). Also entweder so lesen — „die Gruppe hat mindestens
  eine Tätigkeitsart" — oder einen echten Schalter je Gruppe einführen. Ersteres ist geschenkt
  und vermutlich schon die gemeinte Semantik, Letzteres ausdrücklicher.
- **Freigabe der eigenen Stunden.** [OI-3](OPEN-ITEMS.md#oi-3) ist bereits offen: Ein Manager
  bestätigt seinen eigenen Nachtrag ohne Kontrolle. Mit einer dritten freigebenden Rolle
  vervielfacht sich die Frage — und beim Gruppenleiter wiegt sie schwerer, weil er in seiner
  kleinen Gruppe oft die einzige freigebende Instanz ist. Diese Entscheidung gehört zu OI-3 und
  sollte dort mitgetroffen werden, nicht hier zum zweiten Mal.
- **Rückwirkung auf eine bewusste Entscheidung.** „Manager sehen alle Datensätze ohne
  Gruppengrenze" steht in `CLAUDE.md` und in `OPEN-ITEMS.md` unter *Bewusst entschieden*.
  FI-15 stellt das nicht infrage — schafft aber erstmals den technischen Weg, eine Grenze zu
  ziehen. Damit wird die alte Entscheidung erneut zur Wahl, und das sollte man wissen, bevor
  man anfängt.
- **Passt zu [FI-14](#fi-14--untergruppen-register-und-besetzungsübersicht).** Registerführer
  ist der wahrscheinlichste Gruppenleiter überhaupt. Wenn beides kommt, sollte die
  Leitungszuordnung auf demselben Gruppenbegriff aufsetzen — sonst leitet jemand ein Register,
  das im Berechtigungsmodell keine Gruppe ist.

---

## E · Module, die eigene Produkte wären

Vier Ideen aus dem Vergleich mit `konzertmeister.app`, festgehalten, damit sie bei der nächsten
Nennung nicht neu diskutiert werden. Allen gemeinsam: Sie berühren Anwesenheit, Pünktlichkeit
und Arbeitszeit nicht, hätten also nichts vom vorhandenen Datenmodell — und wenn eine davon
kommt, dann als abschaltbares Modul (siehe OI-62 in `OPEN-ITEMS.md`, ohne das jede weitere
Funktion die Oberfläche für Vereine zumüllt, die sie nicht brauchen).

### FI-19 · Terminvorlagen
**Nutzen:** niedrig · **Aufwand:** S

Ein benannter Satz Vorbelegungen (Terminart, Uhrzeit, Gruppen, Ort), aus dem sich ein neuer
Termin mit einem Klick füllt. Die kleinste Idee der Liste — im Kern eine Tabelle und ein
Auswahlfeld im Anlegen-Dialog.

Das Feld „Ort" gibt es am Termin noch nicht; es kommt mit
[FI-23](#fi-23--ort-und-ende-am-termin). Ohne dieses bleiben als Vorbelegung nur Terminart,
Uhrzeit und Gruppen — womit die Vorlage noch weniger hergibt als ohnehin schon.

**Warum eher nicht zuerst:** [FI-7](#fi-7--terminserien-für-wiederkehrende-proben) nimmt ihr den
Anlass. Was sich regelmäßig wiederholt, ist dann eine Serie; was einmalig ist, lohnt keine
Vorlage. Sinnvoll bleibt sie höchstens für unregelmäßig wiederkehrende Terminarten — Auftritte,
Ständchen — und erst, nachdem FI-7 gezeigt hat, was übrig bleibt.

---

### FI-20 · Einfache Umfragen
**Nutzen:** niedrig · **Aufwand:** M — **wirkt erst mit [FI-6](#fi-6--benachrichtigungskanal-e-mail-web-push)**

Eine Frage an alle oder an eine Gruppe, mit ein paar Antwortmöglichkeiten und einer Auswertung
— etwa zur Terminfindung oder zur Auswahl des Ausflugsziels.

**Warum eher nicht:** Es ist [FI-1](#fi-1--terminzusage-im-vorfeld) noch einmal, nur ohne
Termin — dieselbe Mechanik aus Frage, Antwort je Mitglied, Frist und Auswertung. Wer das baut,
hat entweder zwei Umsetzungen desselben Musters oder muss die vorhandene verallgemeinern, und
das ist der eigentliche Aufwand. Ohne Versandweg beantwortet außerdem niemand eine Umfrage, von
der er nichts erfährt.

---

### FI-21 · Aufgaben mit Zuweisung und Fälligkeit
**Nutzen:** niedrig · **Aufwand:** L — **wirkt erst mit [FI-6](#fi-6--benachrichtigungskanal-e-mail-web-push)**

Aufgabenliste mit Zuständigem, Fälligkeitsdatum, Wiederholung und Erledigungsvermerk.

**Warum eher nicht:** Ein eigenständiges Produkt, für das es gute kostenlose Alternativen gibt,
und das mit der Anwesenheitserfassung nur das Mitglied gemeinsam hat. Es überschneidet sich
zudem mit [FI-9](#fi-9--dienst--und-schichtplanung-für-veranstaltungen) (Schicht = zugewiesene
Aufgabe mit Zeitfenster) und mit dem Erledigungsvermerk aus
[FI-10](#fi-10--jubiläen-und-ehrungen-automatisch-ermitteln) und
[FI-13](#fi-13--geburtstagsliste-mit-gratulationsvermerk). Wenn überhaupt, dann ein Modell für
alle drei — sonst entstehen drei.

---

### FI-22 · Musikstücke und Programme
**Nutzen:** niedrig · **Aufwand:** L

Notenarchiv und Programmzusammenstellung je Auftritt.

**Warum eher nicht:** Als einzige Idee der Liste spartenspezifisch — für einen Sportverein
wertlos, während EhrenSache ausdrücklich für ehrenamtliche Organisationen allgemein gebaut ist.
Berührungspunkt zur Anwesenheit gäbe es nur über [FI-14](#fi-14--untergruppen-register-und-besetzungsübersicht)
(„ist das Stück mit den Zusagen besetzbar?"), und das ist eine Auswertung, kein Archiv.
Verwandt mit [FI-12](#fi-12--material--und-instrumentenausleihe) und aus demselben Grund
zurückgestellt.

---

## Wenn etwas davon kommt: sinnvolle Reihenfolge

Keine Zusage, nur die Abhängigkeiten in ihrer natürlichen Ordnung.

> **Geändert am 2026-09-16.** Bis dahin stand hier FI-6 an erster und FI-7 an vierter Stelle,
> mit der Begründung, ohne Versandweg werde eine Zusageabfrage nicht beantwortet. Das Argument
> stimmt, trägt die Reihenfolge aber nicht mehr: FI-1 ist seit 1.7.0 gebaut — **ohne** FI-6 —,
> und FI-6 hängt weiterhin an einer Frage, die das Projekt gar nicht entscheiden kann, nämlich
> ob die Installation eines Vereins einen Aufgabenplaner hat. FI-7 hängt an nichts, und die
> Lücke, die FI-6 füllen sollte, deckt FI-17 zum Bruchteil des Aufwands. Die alte Reihenfolge
> steht in der Versionshistorie, falls die Begründung noch einmal gebraucht wird.

1. **FI-7 Terminserien** — die lästigste wiederkehrende Arbeit im System und der häufigste
   Grund, es gar nicht erst zu benutzen. Es hängt von nichts ab, der Entwurf steht (siehe dort),
   und es beschafft FI-1 überhaupt erst die Termine, zu denen jemand etwas zurückmeldet.
   Zusammen mit **FI-16 Feiertage** entwerfen, sonst legt die erste Wochenserie Proben auf
   Karfreitag.
2. **FI-17 Offene Punkte unter „Mein Konto"** — kleinster sinnvoller Schritt gegen die
   Holschuld. Kein Cron, kein Zustellrisiko, keine Einwilligung; bündelt, was FI-6 später
   verschickt, und speist sich aus derselben Abfrage.
3. **FI-14 Register** in der kleinen Variante (Gruppenart statt Hierarchie) — die
   Besetzungsansicht ist der Grund, warum die Zusagen aus 1.7.0 mehr sind als eine
   Anwesenheitsprognose. Fast kostenlos, solange niemand echte Vererbung verlangt.
4. **FI-6 Benachrichtigungen** — erst jetzt, und erst nachdem die Auslöserfrage beantwortet ist.
   Danach wird alles Vorherige wirksamer, FI-1 am deutlichsten. Die Einmal-Links aus der Mail
   gehören in dieselbe Runde, weil sie dieselbe Sicherheitsprüfung brauchen.
5. **FI-23 Ort und Ende**, dann **FI-8 ICS-Abo** — in dieser Reihenfolge. FI-8 ist weiterhin
   die günstigste Idee der Liste, aber ein Feed ohne Ort und ohne Dauer ist eine Liste von
   Titeln; die zwei Felder davor gebaut, macht aus derselben Arbeit einen brauchbaren Kalender.
   FI-23 nützt außerdem für sich allein und hebt nebenbei FI-19. Nach FI-7 wird FI-8 noch
   günstiger, weil die Wiederholungsregel dann schon in der richtigen Schreibweise vorliegt.
   **FI-18 ICS-Import** ist davon unabhängig und deutlich teurer — nicht zusammen einplanen,
   nur weil beide „ICS" heißen.
6. **FI-4 Auth-Geräte (Rest: NFC/Biometrie)**, dann **FI-3 GPS** — die Check-in-Wege gemeinsam
   entscheiden, damit Beweiswert und Kennzeichnung der Quellen einmal einheitlich festgelegt
   werden statt dreimal verschieden.
7. **FI-10 Jubiläen** und **FI-13 Geburtstage** zusammen entwerfen, auch wenn nur eines davon
   gebaut wird — beides ist ein Stichtag mit Erledigungsvermerk. Zwei getrennte Modelle dafür
   wären ein selbstgemachtes Problem. **FI-21 Aufgaben** gehört, falls es je kommt, in dasselbe
   Modell.
8. **FI-15 Gruppenleiter** eigenständig planen, nicht nebenbei. Die Rolle ist der einzige
   Punkt der Liste, der jeden Handler anfasst — sie verträgt sich schlecht damit, parallel zu
   etwas anderem zu laufen. Sinnvoll erst nach [FI-14](#fi-14--untergruppen-register-und-besetzungsübersicht),
   damit feststeht, worauf sich „seine Gruppe" bezieht.
9. Alles Übrige — Abschnitt E, FI-9, FI-11, FI-12 — nur, wenn ein Verein danach fragt.

---

## Nicht auf dieser Liste

Bereits verworfen und hier nicht erneut aufzunehmen — Begründungen in `OPEN-ITEMS.md`,
Abschnitt „Bewusst entschieden — nicht erneut aufmachen": PDF-Export, Offline-Betrieb der PWA,
Segmentmodell für Pausen, Gruppengrenze für Manager sowie **Pinnwand, Chat und Dateiablage**
(seit 2026-09-16 dort eingetragen).

Ebenfalls schon gebaut und deshalb keine Idee mehr, auch wenn es gelegentlich noch als eine
genannt wird: Kommentar zur Rückmeldung (`appointment_responses.comment`, 1.7.0),
Rückmeldefrist je Terminart (`responseDeadlineHours()`, 1.7.0) und der Druckbericht der
Statistik (`openStatisticsReport()`, seit 1.2.2 — ein PDF entsteht daraus im Druckdialog des
Browsers).

Ebenfalls nicht hierher gehören Fehler und Restarbeiten am Bestehenden — die stehen in
`OPEN-ITEMS.md`.
