# Feature-Ideen — EhrenSache

Ideensammlung für mögliche künftige Funktionen. **Nichts hier ist beschlossen, geplant oder
terminiert.** Der Zweck ist, Einfälle festzuhalten, bevor sie verloren gehen, und ihre Kosten
grob abzuschätzen — nicht, sie zu versprechen.

**Angelegt:** 2026-09-02 · **Bezugsstand:** `dev`, Version 1.2.1

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
| [FI-1](#fi-1--terminzusage-im-vorfeld) | Terminzusage im Vorfeld | hoch | M | — |
| [FI-2](#fi-2--abgleich-zusage--tatsächliche-anwesenheit) | Abgleich Zusage ↔ tatsächliche Anwesenheit | hoch | S | FI-1 |
| [FI-3](#fi-3--gps-gestützter-check-in) | GPS-gestützter Check-in | mittel | M | — |
| [FI-4](#fi-4--registrierungsprozess-für-auth-geräte) | Registrierungsprozess für Auth-Geräte | mittel | M | — |
| [FI-5](#fi-5--pin-anmeldung-am-auth-gerät) | PIN-Anmeldung am Auth-Gerät | mittel | M | FI-4 |
| [FI-6](#fi-6--benachrichtigungskanal-e-mail-web-push) | Benachrichtigungskanal (E-Mail, Web-Push) | hoch | M | — |
| [FI-7](#fi-7--terminserien-für-wiederkehrende-proben) | Terminserien für wiederkehrende Proben | hoch | M | — |
| [FI-8](#fi-8--kalender-abo-ics-feed) | Kalender-Abo (ICS-Feed) | mittel | S | — |
| [FI-9](#fi-9--dienst--und-schichtplanung-für-veranstaltungen) | Dienst- und Schichtplanung für Veranstaltungen | mittel | L | FI-1 |
| [FI-10](#fi-10--jubiläen-und-ehrungen-automatisch-ermitteln) | Jubiläen und Ehrungen automatisch ermitteln | mittel | S | — |
| [FI-11](#fi-11--mehrsprachigkeit-der-oberfläche) | Mehrsprachigkeit der Oberfläche | niedrig | L | — |
| [FI-12](#fi-12--material--und-instrumentenausleihe) | Material- und Instrumentenausleihe | niedrig | L | — |
| [FI-13](#fi-13--geburtstagsliste-mit-gratulationsvermerk) | Geburtstagsliste mit Gratulationsvermerk | mittel | M | — |

FI-1 bis FI-5 und FI-13 stammen aus der Ideensammlung, FI-6 bis FI-12 sind Ergänzungen aus
der Sichtung des Bestands.

---

## A · Planung im Vorfeld

### FI-1 · Terminzusage im Vorfeld
**Nutzen:** hoch · **Aufwand:** M

Mitglieder geben vor einem Termin an, ob sie kommen: *zugesagt / abgesagt / unsicher*, mit
optionaler Bemerkung. Dirigent oder Vorstand sehen die Besetzung, bevor die Probe stattfindet.

**Warum interessant:** Das ist das stärkste Argument der Konkurrenz (Konzertmeister). Die
Frage „reicht die Besetzung für Samstag?" stellt sich jede Woche; die Anwesenheitserfassung
beantwortet sie erst hinterher. Das meiste andere auf dieser Liste ist Komfort — das hier ist
ein eigener Anwendungsfall.

**Berührt:** neue Tabelle `appointment_responses` (member_id, appointment_id, status, comment,
responded_at, unique je Paar) · neue Ressource in `api.php` · Terminansicht im Dashboard ·
Erfassen-Tab der PWA · Statistik (Zusagequote als eigene Kennzahl).

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

## B · Weitere Check-in-Wege

### FI-3 · GPS-gestützter Check-in
**Nutzen:** mittel · **Aufwand:** M

Für Termine an wechselnden Orten (Auftritt, Umzug, Arbeitseinsatz im Wald) gibt ein
Administrator den Check-in frei und hinterlegt Koordinaten samt Radius. Wer sich innerhalb des
Radius befindet, kann sich in der PWA eintragen.

**Warum interessant:** Deckt genau die Lücke, für die keine TOTP-Station aufgebaut werden kann.
Eine feste Station im Proberaum lohnt sich, für einen einmaligen Auftritt lohnt sie sich nicht.

**Berührt:** Koordinaten und Radius am Termin oder an einer eigenen Check-in-Freigabe · neue
Quelle im `checkin_source`-Enum von `records` (Migration; das Feld ist heute
`admin|user_totp|device_auth|auto_checkin|import`) · `auto_checkin.php` · PWA-Erfassen-Tab ·
`DATENSCHUTZ.md`.

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

**Vorher zu klären:** Der Versand braucht einen Auslöser zur richtigen Zeit — ohne Cron auf dem
Hosting bleibt nur ein Anstoß beim nächsten Seitenaufruf, was unzuverlässig ist. Außerdem:
E-Mail zuerst (funktioniert überall) oder Push zuerst (auffälliger, aber an die PWA gebunden)?
Und wer schaltet was ab — sonst empfindet ein Teil der Mitglieder das Ganze als Belästigung.

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

**Vorher zu klären:** Materialisieren oder berechnen? Einzeltermine anzulegen ist einfacher und
verträgt sich mit `records`, `exceptions` und allen Fremdschlüsseln; eine berechnete Serie wäre
sparsamer, kollidiert aber mit jedem Datensatz, der auf eine `appointment_id` zeigt. Zweitens:
Was passiert beim Ändern der Serie, wenn an einzelnen Terminen bereits Anwesenheiten hängen?

---

### FI-8 · Kalender-Abo (ICS-Feed)
**Nutzen:** mittel · **Aufwand:** S

Persönliche, mit Token geschützte Kalender-URL, die jedes Mitglied in Telefon oder
Mail-Programm abonniert. Nur lesend, nur die Termine der eigenen Gruppen.

**Warum interessant:** Sehr viel Wirkung für sehr wenig Code — ICS ist Textausgabe, keine
Bibliothek nötig, was zur Linie des Projekts passt (kein PDF-Export, „würde eine Bibliothek
einschleppen"). Vereinstermine stehen damit dort, wo die Leute ohnehin hinschauen.

**Berührt:** neue Ressource in `api.php` · Token-Erzeugung analog `regenerate_token.php` ·
Profilbereich für die URL.

**Vorher zu klären:** Ein Abo-Link wird zwangsläufig weitergegeben oder landet in einem
Cloud-Kalender. Deshalb ein eigenes, separat widerrufbares Token — nicht das API-Token
wiederverwenden, das schreibenden Zugriff hätte.

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

## Wenn etwas davon kommt: sinnvolle Reihenfolge

Keine Zusage, nur die Abhängigkeiten in ihrer natürlichen Ordnung:

1. **FI-6 Benachrichtigungen** — für sich schon nützlich und Voraussetzung dafür, dass eine
   Zusageabfrage überhaupt beantwortet wird.
2. **FI-1 Terminzusage** — das eine Feature mit Wettbewerbswirkung. Vorher muss die Frage zu
   `exceptions` entschieden sein.
3. **FI-2 Abgleich** — fällt danach fast von selbst an.
4. **FI-7 Terminserien** und **FI-8 ICS** — unabhängig, klein, jederzeit dazwischen möglich;
   FI-8 ist die günstigste Idee der Liste.
5. **FI-4 Auth-Geräte**, dann **FI-5 PIN**, dann **FI-3 GPS** — die Check-in-Wege gemeinsam
   entscheiden, damit Beweiswert und Kennzeichnung der Quellen einmal einheitlich festgelegt
   werden statt dreimal verschieden.
6. **FI-10 Jubiläen** und **FI-13 Geburtstage** zusammen entwerfen, auch wenn nur eines davon
   gebaut wird — beides ist ein Stichtag mit Erledigungsvermerk. Zwei getrennte Modelle dafür
   wären ein selbstgemachtes Problem.
7. Alles Übrige nur, wenn ein Verein danach fragt.

---

## Nicht auf dieser Liste

Bereits verworfen und hier nicht erneut aufzunehmen — Begründungen in `OPEN-ITEMS.md`,
Abschnitt „Bewusst entschieden — nicht erneut aufmachen": PDF-Export, Offline-Betrieb der PWA,
Segmentmodell für Pausen, Gruppengrenze für Manager.

Ebenfalls nicht hierher gehören Fehler und Restarbeiten am Bestehenden — die stehen in
`OPEN-ITEMS.md`.
