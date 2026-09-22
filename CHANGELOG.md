# Changelog

Alle wesentlichen Änderungen an EhrenSache werden in dieser Datei dokumentiert.

Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).
Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

---

## [Unreleased]

### Geändert
- **Demo:** Der Beispielbestand zeigt Terminserien und Feiertage. Gesamtprobe, Registerprobe und
  Vorstandssitzung sind echte Serien – je eine ausgelaufene und eine laufende, weil eine Serie
  höchstens zwölf Monate umfasst – und reichen fünf bis sechs Monate in die Zukunft statt vier
  Termine. Feiertage in Baden-Württemberg fallen als Ausfälle der Serie weg, das Bundesland ist
  eingestellt. Der Bestand ändert sich dadurch insgesamt (weniger Proben an Feiertagen, andere
  Anwesenheiten); vorhandene Screenshots der Demo stimmen nicht mehr.

### Behoben
- **Arbeitszeit mit Ortsnachweis geht in der PWA auch ohne Kamera.** Bisher führten Start und
  Stopp nur in den Kamerasucher. Ohne Kamera, bei verweigerter Freigabe oder bei Aufruf über
  HTTP ließ sich die Sitzung deshalb nicht starten. Jetzt steht neben Start und Stopp der Knopf
  „Code eingeben“ für den Code der Station. Scheitert die Kamera, öffnet sich die Eingabe von
  selbst (OI-83).
- **Ein Filter ohne Treffer lässt keine alte Paginierung mehr stehen.** In Anwesenheiten,
  Mitgliedern und Anträgen blieben die Seitenknöpfe und „von 196 Einträgen“ des vorigen Filters
  stehen. Ein Klick darauf zeigte dessen Einträge wieder an. Für Admins und Manager heißt die
  Leermeldung der Mitgliederliste außerdem „Keine Mitglieder für diese Auswahl“ statt
  „Kein Profil verknüpft“ (OI-84).
- **Zeitanträge gibt es nur noch für Ankünfte, die schon stattgefunden haben.** Der
  „Nachträgliche Antrag“ der PWA bot auch künftige Termine an, und der Server nahm dafür jede
  Wunschzeit im Fenster des Termins an. Wurde so ein Antrag genehmigt, entstand eine Anwesenheit
  für einen Termin, der noch gar nicht stattgefunden hatte. Jetzt weist der Server einen
  Zeitantrag ab, solange das Check-in-Fenster des Termins nicht begonnen hat oder die Wunschzeit
  nach jetzt liegt. Die PWA bietet solche Termine nicht mehr an. Entschuldigungen im Voraus
  bleiben erlaubt. Ablehnen lässt sich ein Zeitantrag jetzt immer, auch wenn sein Termin
  inzwischen verschoben wurde (OI-82).
- **Tests:** Die Suite `appointment_series_api` setzt das Bundesland nach ihren Tests auf den
  vorigen Wert zurück statt auf leer – ein Testlauf ließ den Demo-Bestand sonst ohne
  Landesfeiertage zurück.

---

## [1.11.1] – 2026-09-21

### Behoben
- **Ein im Listen-Tab der PWA angelegter Termin verschwindet nicht mehr kommentarlos.** Die
  Auswahl zeigt nur Termine im Check-in-Fenster um jetzt – diese Liste erfasst Anwesenheit.
  Ein neuer Termin darin wird jetzt gleich ausgewählt, einer außerhalb bekommt einen
  erklärenden Hinweis statt der Meldung „Termin erstellt" ohne sichtbares Ergebnis. Der Dialog
  ist mit dem aktuellen Zeitpunkt vorbelegt und bleibt bei einem Fehler offen, etwa bei einer
  Dublette; bisher schloss er sich und die Eingaben waren verloren. Die Fehlermeldung steht jetzt
  im Dialog selbst, bei einer Dublette mit dem bestehenden Termin — als Einblendung lag sie
  unter dem offenen Dialog und war nicht zu sehen.
- **Kurz nach Mitternacht fehlte in derselben Auswahl ein Termin vom Vorabend**, obwohl er noch
  im Fenster lag: Die Abfrage begann erst mit dem heutigen Datum.

---

## [1.11.0] – 2026-09-21

### Neu
- **Terminserien** (FI-7): wöchentlich (alle 1–4 Wochen, mehrere Tage) oder monatlich nach Position
  („jeden ersten Freitag", „letzten Mittwoch"), höchstens 12 Monate, mit Vorschau vor dem Anlegen.
  Bearbeiten als „Nur dieser" oder „Dieser und alle folgenden", Serie fortsetzen, Regel ab einem
  Termin ändern, ab einem Termin beenden. Termine mit erfassten Daten werden dabei nie gelöscht,
  sondern aus der Serie gelöst.
- **Feiertage** (FI-16): berechnet je Bundesland (Einstellung unter Termine → Kalender), im
  Kalender angezeigt und in der Serienvorschau abgewählt; Oster- und Pfingstsonntag erscheinen
  dabei unabhängig vom Bundesland immer.
- **Termin per Klick im Kalender anlegen** (OI-64); das Popup belegter Tage bietet „Bearbeiten"
  und „+ Termin an diesem Tag".
- **Die Terminliste der PWA zeigt den Rückmeldestand schon an der zugeklappten Karte.** Ein
  schmaler Balken unter Ort und eigenem Status teilt sich in Zusagen, Unsichere, Absagen und
  Mitglieder ohne Antwort — dieselbe Zählung wie im aufgeklappten Teil, nur ohne Aufklappen lesbar.

### Geändert
- Die Vorschau „Termine aus Records ableiten" nutzt dieselbe Datumsliste wie die Serienvorschau.
- Der Herkunftsfilter der Terminverwaltung kennt zusätzlich „Nur Serientermine" und „Ohne
  Serientermine"; die Auswahl wirkt wie gehabt auf Liste, Kalender und Kennzahlen gemeinsam.
- Die Terminauswahl der Zeiterfassung (Check-in-PWA) zeigt nur noch ein Fenster von 60 Tagen
  zurück bis 30 Tage voraus, gruppiert in „Heute", „Zurückliegend" und „Kommend" statt einer nach
  Nähe zu heute sortierten, mit Terminserien unübersichtlich langen Liste. Termine außerhalb des
  Fensters lassen sich nicht neu zuordnen; ein bereits zugeordneter Termin bleibt davon
  unberührt und wählbar, auch über einen Wechsel der Tätigkeitsart hinweg.
- Der Rückmeldungs-Dialog für Admin/Manager öffnet gesperrt; fremde Antworten lassen sich erst
  nach einem ausdrücklichen Entsperren ändern (Schloss im Spaltenkopf „Aktion"), die eigene Zeile
  bleibt davon unberührt (OI-63). Die Status-Badges der Tabelle tragen dieselbe ruhige Optik wie
  die Summen im Kopf des Dialogs.

### Datenbank
- Migration 1.10.0 → 1.11.0: Tabelle `appointment_series`, Spalten `appointments.series_id` und
  `appointments.is_detached`, Einstellung `holiday_region`.

---

## [1.10.0] – 2026-09-18

### Neu
- **Termine haben einen Ort und ein Ende.** Beide sind optional und rein informativ — sie gehen
  in keine Auswertung ein, weder in die Anwesenheit noch in die Pünktlichkeit. Das Ortsfeld
  schlägt bisher verwendete Orte vor. Liegt das Ende vor dem Beginn, gilt der Folgetag
  (Nachtauftritt 20:00–01:00). Dashboard, Check-in-App und Kalender zeigen beide an; der
  CSV-Export führt sie als letzte Spalten, der Import nimmt sie optional an. Eine ältere Datei
  ohne die Spalten lässt Ort und Ende beim Aktualisieren stehen.
- **Der Tab „Termine" der Check-in-App zeigt alle kommenden Termine.** Bisher erschienen dort nur
  Termine mit Rückmeldung; ein Verein, der die Rückmeldung nicht nutzt, hatte in der App keine
  Terminübersicht, der Tab blieb ganz verborgen. Jetzt stehen dort die Termine der eigenen
  Gruppen der nächsten acht Wochen, Termine mit Rückmeldung auch darüber hinaus —
  chronologisch, nach Monaten gegliedert und zugeklappt. Ein Tipp klappt eine Karte auf. Die
  Liste reicht über den Jahreswechsel: Am 20. Dezember stehen die Januartermine schon darin.

### Geändert
- Die Terminliste der Check-in-App stellt unbeantwortete Rückmeldungen nicht mehr an den Anfang.
  Lange vorausgeplante Veranstaltungen, für die noch keine Zusage möglich ist, standen dort
  dauerhaft oben und verdrängten die Proben der Woche. Offene Rückmeldungen sind am Chip
  „Rückmeldung offen" und am farbigen Rand erkennbar.
- **Termine von heute bleiben bis Tagesende in der Liste**, auch wenn sie schon begonnen haben —
  so lässt sich der Ort auf dem Weg noch nachsehen. Das Badge am Tab zählt begonnene Termine
  nicht mehr mit, denn eine Rückmeldung ist dann nicht mehr möglich.
- Die Demo-Daten tragen Ort und Ende für Proben, Sitzungen und Auftritte.

---

## [1.9.3] – 2026-09-18

### Sicherheit
- **Ein Mitglied konnte die Termine fremder Gruppen abrufen.** Die Terminliste der Schnittstelle
  wertete den Parameter `member_id` auch für einfache Mitglieder aus. Mit der Kennung eines
  anderen Mitglieds ließen sich damit Titel, Datum und Beschreibung der Termine von Gruppen
  abrufen, denen man selbst nicht angehört. Personendaten waren nicht betroffen, wohl aber die
  Gruppengrenze, die sonst überall gilt. Der Parameter wirkt jetzt nur noch für Admin und
  Manager. Die Check-in-App schickt die eigene Kennung mit und ist nicht betroffen. Die Stelle
  bestand seit 1.0.0.

---

## [1.9.2] – 2026-09-18

### Geändert
- **Die Terminverwaltung lässt sich nach Terminart filtern.** Sie war der einzige
  Listenbereich ohne Filterleiste. Die Leiste sitzt wie in den übrigen Bereichen direkt unter
  den Kennzahlen; Benutzer ohne Verwaltungsrechte sehen darin nur die Terminarten ihrer
  Gruppen.
- **Der Schalter für automatisch erzeugte Termine ist aus der Kennzahlkarte in die
  Filterleiste gewandert** und kennt jetzt drei Zustände: alle, nur automatisch erzeugte, nur
  von Hand angelegte. Die letzte Richtung fehlte bisher — sie zeigt, was ohne die automatische
  Erzeugung übrig bliebe.
- **Der Kalender berücksichtigt die Filter.** Bisher zeigte er unabhängig von jeder Auswahl
  alle Termine des Jahres, während Tabelle und Kennzahlen darunter nur den gefilterten
  Ausschnitt zählten.
- In der Mitgliederverwaltung steht der Schalter „Inaktive anzeigen" in der Filterleiste; die
  Kennzahlkarte zeigt nur noch die Zahl.
- **Die Kennzahlkarten folgen jetzt überall der Auswahl.** In der Zeiterfassung blieben „Wartet
  auf Freigabe" und „Laufende Sitzungen" bei jedem Filter auf dem Gesamtwert stehen, nur die
  Stunden änderten sich. In der Mitgliederverwaltung zählen die Karten jetzt die Mitglieder der
  gewählten Gruppe — unabhängig vom Schalter „Inaktive anzeigen", damit die Zahl der Inaktiven
  auch bei ausgeblendeten Inaktiven sichtbar bleibt.
- In der Statistik steht „Filter zurücksetzen" bündig neben den Auswahlfeldern statt ohne
  Abstand darunter.

---

## [1.9.1] – 2026-09-17

### Sicherheit
- **Das Rate Limiting arbeitete auf strengen Datenbanken lautlos gar nicht.** Die Tabelle
  `rate_limits` verlangte eine Spalte `expires_at`, die nie geschrieben wurde. Unter
  `STRICT_TRANS_TABLES` — bei manchem Hosting die Voreinstellung — scheiterte damit jeder
  Schreibvorgang des Limiters, und weil er einen Datenbankfehler bewusst durchlässt, blieben
  Brute-Force-Schutz und die Sperre der Stations-PIN dauerhaft aus, ohne jeden Hinweis im
  Betrieb. Die Spalte ist entfernt; der Limiter rechnet wie bisher über `created_at` (OI-40).
- **Eine Störung dieses Schutzes bleibt nicht mehr unbemerkt.** Kann EhrenSache seine Zähler
  nicht schreiben, lässt es die Anfrage weiterhin bewusst durch — eine gestörte Datenbank soll
  niemanden aussperren, auch nicht den Admin, der sie reparieren will. Die Systemeinstellungen
  zeigen im Tab „System" jetzt aber an, ob der Schutz arbeitet, und nennen Zeitpunkt und
  Fehlernummer der letzten Störung (OI-28).
- **Freitext aus der Datenbank kann in keiner CSV mehr als Formel ausgeführt werden.** Eine
  Zelle, die mit `=`, `+`, `-` oder `@` beginnt, wertet eine Tabellenkalkulation beim Öffnen
  einer CSV-Datei aus. Wer eine Bemerkung oder einen Terminstitel entsprechend beginnen ließ,
  konnte damit auf dem Rechner desjenigen etwas auslösen, der den Export öffnet — typischerweise
  ein Vorstandsmitglied. Alle Exporte und die Selbstauskunft entschärfen solche Zellen jetzt.
  Zahlen bleiben Zahlen, damit im Stundennachweis weiter gerechnet werden kann (OI-59).

### Geändert
- Die Abschnittsüberschriften der Selbstauskunft als CSV heißen `[ STAMMDATEN ]` statt
  `=== STAMMDATEN ===`. Ein führendes Gleichheitszeichen hätte sonst in jeder Trennzeile ein
  Schutzapostroph nach sich gezogen, das beim Import je nach Programm sichtbar bleibt.
- **Jeder Bereich der Check-in-PWA trägt jetzt dieselbe Überschrift mit Symbol.** Die
  Anwesenheitserfassung bekommt einen eigenen Kasten („📍 Anwesenheit erfassen") wie die
  Arbeitszeit daneben, Statistik und Anwesenheitsliste erhalten überhaupt erst eine
  Überschrift, und die Überschrift im Termine-Tab steht nicht mehr mittig, sondern links wie
  alle anderen. Der Erfassen-Tab selbst bleibt ohne Überschrift: die beiden großen Kacheln
  sagen bereits, worum es geht.
- **Die Überschrift im Termin-Modal der PWA sieht aus wie die der übrigen vier.** Sie stand als
  `h3` im Markup und fiel damit auf die Vorgabe des Browsers zurück, während „Code manuell
  eingeben", „Nachträglicher Antrag", „Zeit nachtragen" und „Löschen bestätigen" blau und
  fett erscheinen.
- **Auch die Knöpfe sehen in allen fünf Dialogen gleich aus.** Das Termin-Modal hatte eigene
  Knopfklassen mit blauer Bestätigung, die übrigen vier eine grüne. Grün bleibt — es ist in
  der Check-in-App die Farbe der Hauptaktion, so wie Blau im Dashboard. Beide Knöpfe eines
  Dialogs sind jetzt außerdem gleich breit: die Regel dafür stand auf einer Klasse, die in
  dieser Oberfläche nirgends vergeben wird, weshalb sich die Breite nach der Beschriftung
  richtete und „Antrag stellen" in seinem Knopf umbrach.
- **Unter „Wer hat geantwortet?" trennt eine feine Linie die Gruppen.** Bei mehreren Registern
  liefen Überschriften und Namens-Chips bisher als ein Block ineinander. Bewusst eine Linie und
  kein Kasten je Gruppe: Die Liste steckt schon in zwei Kästen, ein dritter Rahmen wäre eine
  Schachtelungsebene zu viel.
- **„Termin anlegen" und „Termin bearbeiten" sind nicht mehr orange.** Orange ist in dieser
  Oberfläche die Warnfarbe, und keiner der beiden Knöpfe warnt vor etwas. „Anlegen" trägt
  jetzt die Vereinsfarbe, „Bearbeiten" steht als Umriss daneben, weil es zusammen mit
  „Aktualisieren" über einer bereits gefüllten Liste erscheint.
- **Die Anwesenheitsquote in der Statistik der PWA bekommt einen Farbbalken.** Bisher war die
  Einordnung in die vier Bänder nur in der Gruppenliste darunter zu sehen, nicht bei der
  Gesamtquote. Die Zahl selbst bleibt schwarz — in dieser Größe liest sie sich gefärbt
  schlechter; die Farbe trägt der Balken, mit denselben Schwellen wie Gruppenliste und
  Dashboard.

### Behoben
- **Ein `PUT` löscht keine Felder mehr, die gar nicht mitgeschickt wurden.** Wer über die
  Schnittstelle nur ein einzelnes Feld ändern wollte, verlor bei Terminen Titel und Terminart,
  bei Anwesenheiten Mitglied, Termin und Status, bei Anträgen die Begründung und bei
  Mitgliedschaftszeiträumen den Beginn. Am schwersten wog die Terminart: Ein Termin ohne sie hat
  keine Gruppenzuordnung mehr, verschwindet aus den Listen der Mitglieder und zählt in keiner
  Auswertung mit. Die mitgelieferte Oberfläche schickt immer alle Felder mit und war deshalb nie
  betroffen (OI-69).
- **Die Dublettenprüfung beim Verschieben eines Termins greift wieder.** Wurde ein Termin ohne
  Angabe seiner Terminart verschoben, fiel die Prüfung auf einen bereits bestehenden Termin
  derselben Art still aus (OI-69).
- **Ein Löschaufruf ohne Kennung meldet keinen Erfolg mehr.** `DELETE` ohne `id` traf keine
  Zeile, antwortete aber mit „gelöscht" — bei Benutzern, Terminarten, Tätigkeitsarten, Gruppen
  und Mitgliedschaftszeiträumen. Jetzt kommt `400`, und eine unbekannte Kennung ergibt `404`
  (OI-56).
- **Die Geräteliste zeigte nie mehr als 25 Geräte — ohne Weg zu den übrigen.** Die Blätterung
  sprach ein Element an, das es unter diesem Namen nicht gab, und blieb deshalb unsichtbar.
  Vereine mit vielen Stationen kamen an ihre restlichen Geräte nicht heran (OI-29).
- **Das Mitglieder-Dashboard nennt die Zahl der inaktiven Mitglieder.** Bisher stand dort nur
  das Häkchen zum Einblenden; wer wissen wollte, wie viele Karteileichen im Bestand stehen,
  musste filtern und zählen. Beide Zahlen beziehen sich jetzt auf den Bestand des gewählten
  Jahres, nicht auf die gerade gefilterte Liste (OI-71).
- **Die Statistik hat einen Knopf „Filter zurücksetzen"**, wie die übrigen Ansichten. Das Jahr
  bleibt dabei stehen (OI-71).
- **„Mein Profil": drei Ungenauigkeiten, die in die Irre führten.** Die Token-Karte heißt nach
  der Check-in-App statt nach der Zeiterfassung — der Token ist der Zugang zur ganzen App, nicht
  nur zu einer ihrer Funktionen. Die Formatauswahl bei „Meine Daten" sah gesperrt aus, obwohl
  sie ausgewertet wird; wer die CSV wollte, probierte es gar nicht erst. Und der
  Bestätigungsdialog zählte vier von zehn Datenarten auf und ließ die Auskunft damit kleiner
  erscheinen, als sie ist — er nennt jetzt keine Liste mehr (OI-68).
- **Die Tab-Leiste der Check-in-PWA ragte auf schmalen Telefonen über den Bildschirmrand.**
  Betroffen waren Manager, die fünf Tabs sehen: Die Schaltflächen durften nicht unter die
  Breite ihres längsten Wortes schrumpfen, weshalb „Anwesenheit" die Leiste aufspreizte.
  Die Beschriftungen richten sich jetzt nach der Gerätebreite, der Anwesenheitstab heißt
  kürzer „Liste", und reicht der Platz trotzdem nicht, wird gekürzt statt übergelaufen.
- **Eine vergrößerte Systemschriftgröße zerlegt die Oberfläche der PWA nicht mehr.** Android
  skaliert Schriften, nicht aber feste Maße in Pixeln: Die Rolle im Benutzerkästchen verlor
  ihre Mitgliedsnummer hinter Auslassungspunkten, die Zahl im Termin-Abzeichen stand über
  ihrem Kreis, die Beschriftung der Scanner-Knöpfe lief heraus und die Jahrespfeile der
  Statistik sprengten ihr Kästchen. Diese Maße wachsen jetzt mit.

---

## [1.9.0] – 2026-09-17

### Neu
- **Systemeinstellungen in sieben Untertabs**: Allgemein, Termine & Anwesenheit, Zeiterfassung,
  Stationen, Datenschutz, E-Mail und System. Statt einer Spalte aus zwölf Karten ist immer nur
  ein Bereich sichtbar; der zuletzt gewählte Tab wird gemerkt. Knöpfe, die ohne Speichern wirken
  (Bereinigung, Test-Mail, Update-Prüfung), sind als solche gekennzeichnet, und wer den Bereich
  mit ungespeicherten Änderungen verlässt, wird gefragt.
- **Farbschwellen der Anwesenheitsquote einstellbar** (Termine & Anwesenheit). Ab wann ein Balken
  orange, gelb oder grün wird, entscheidet der Verein — eine Wochenprobe verlangt einen anderen
  Maßstab als ein Monatsdienst. Vorgabe bleibt 40/60/80. Dashboard und Check-in-App nutzen jetzt
  dieselbe Skala; die App färbte bisher nach eigenen Werten (OI-55).

### Behoben
- **Doppelte Mitglieder bei Registern, die direkt einer Terminart zugeordnet sind.** War ein
  Register (Untergruppe) einer Terminart zugeordnet, erschien jedes Mitglied sowohl bei der
  Gruppe als auch beim Register — Anwesenheitsliste und Terminrückmeldung zeigten es doppelt,
  inklusive einer falsch hohen Zahl an Mehrfachnennungen. Gruppe und Register schließen sich in
  der Gliederung jetzt aus.
- **Ein fehlgeschlagenes Speichern wird benannt.** Lehnte der Server einen Wert ab, sagte die
  Oberfläche nichts über die übrigen. Jetzt nennt der Hinweis beides („2 gespeichert,
  1 abgelehnt“), das betroffene Feld wird markiert und sein Tab angesprungen (OI-31).
- Zahlenfelder der Einstellungen schicken den geprüften Wert statt der Roheingabe.
- Die Bereinigung weist darauf hin, wenn die angezeigten Löschfristen nicht gespeichert sind —
  gelöscht wird nach den angezeigten Werten.

---

## [1.8.0] – 2026-09-16

### Neu
- **Untergruppen.** Eine Gruppe lässt sich jetzt als Untergruppe markieren — im Musikverein zum
  Beispiel das Register (Klarinette, Trompete, Tenorhorn), im Sportverein die Mannschaft. Die
  Reihenfolge der Untergruppen ist frei pflegbar (Verwaltung → Gruppen), sodass sie zum Beispiel
  in Partiturreihenfolge statt alphabetisch erscheinen.

  Die Anwesenheitsliste (Dashboard und Check-in-App) und die Namensliste der Terminrückmeldung
  „Wer hat geantwortet?" (Dashboard und Check-in-App) zeigen darüber einen Umschalter
  „Alphabetisch · Gruppe · <Bezeichnung>" — mit einem Klick lässt sich die Liste nach Register
  gliedern, statt nur alphabetisch nach Nachname zu sortieren. Die gewählte Ansicht wird je
  Liste gemerkt und bleibt auch nach einem Neuladen erhalten. Wer mehreren Registern angehört,
  erscheint in jedem davon; eine Hinweiszeile macht das kenntlich, damit niemand übersehen wird.

  Wie die Untergruppen heißen sollen, ist in den Einstellungen frei wählbar — ein Musikverein
  trägt „Register" ein, ein Sportverein „Mannschaft". Vorgabe ist „Untergruppe".

  Eine Untergruppe lässt sich wie jede andere Gruppe auch einer Terminart zuordnen — zum
  Beispiel die Terminart „Registerprobe Klarinette" mit dem Register Klarinette: Erwartet
  werden dann genau dessen Mitglieder, und die Statistik rechnet für diese Terminart auch
  nur für dieses Register.

  Ohne einen einzigen gesetzten Haken verhält sich die Anwendung wie bisher.

### Geändert
- **Demo:** Der Beispielbestand des „Musikverein Musterhausen" hat jetzt fünf Register (Flöte,
  Klarinette, Trompete, Tenorhorn, Schlagzeug) in Partiturreihenfolge, auf die fast alle
  Mitglieder verteilt sind — einige davon bewusst auf zwei Register, einige bewusst auf keines.

### Behoben
- **Verknüpftes Mitglied beim Anlegen eines Benutzers.** Die im Dialog gewählte Verknüpfung kam
  nicht beim Server an; sie musste nach dem Anlegen im Bearbeiten-Dialog noch einmal gesetzt
  werden. Der Server prüft die Verknüpfung jetzt auch beim Anlegen — ein bereits vergebenes
  Mitglied wird abgewiesen statt doppelt verknüpft.
- **`PUT` auf Terminarten und Tätigkeitsarten ändert nur mitgeschickte Felder.** Bisher schrieb
  der Server alle Grundfelder bedingungslos: Ein Aufruf, der nur die Farbe ändern wollte, verlor
  Beschreibung und Vorgabe-Kennzeichen, löste bei Terminarten die Gruppenzuordnung und
  aktivierte bei Tätigkeitsarten eine ausgemusterte Art still wieder (OI-54).
- **Selbstauskunft als CSV war unvollständig.** „Meine Daten" lieferte als CSV weder
  Arbeitszeiten noch deren Änderungshistorie noch die Mitgliedschaftszeiträume, während die
  JSON-Form sie enthielt — zwei Formate desselben Auskunftsersuchens mit unterschiedlichem
  Inhalt. Die CSV führt jetzt dieselben Daten (OI-50).
- **Ein Antrag je Termin und Antragsart.** Über die Absage einer Terminrückmeldung und über den
  Antragsdialog ließen sich zwei Abwesenheitsanträge zum selben Termin stellen, die einzeln
  beschieden werden mussten. Der Server weist den zweiten jetzt ab und nennt den vorhandenen.
  Ein abgelehnter Antrag blockiert wie bisher keinen neuen.
- **Eingetragene Texte erschienen vielerorts ungeschützt im Seitencode.** Namen, Titel,
  Bemerkungen und Begründungen wurden in Dashboard und Check-in-App an zahlreichen Stellen
  unmaskiert ausgegeben; Farben und der Link zur Datenschutzerklärung wurden ohne Formatprüfung
  übernommen. An einigen dieser Stellen konnte ein gewöhnliches Mitgliedskonto Inhalte
  hinterlegen, die Admin und Manager in ihren Ansichten zu sehen bekamen. Alle bekannten
  Stellen sind abgesichert; Einzelheiten stehen im Security Advisory (siehe Release-Hinweise).

---

## [1.7.0] – 2026-09-16

### Neu
- **Terminrückmeldung.** Mitglieder sagen vor einem Termin zu, ab oder „unsicher", mit
  optionaler Bemerkung — im Dashboard und im neuen Tab „Termine" der Check-in-App. Admin und
  Manager sehen je Termin, wer wie geantwortet hat und wer noch nicht, und drucken die Besetzung.
  Nach dem Termin stellt die Terminansicht Zusage und Anwesenheit gegenüber.

  Eingeschaltet wird die Rückmeldung **je Terminart** (Verwaltung → Terminarten), ab Werk ist sie
  überall aus. Dort außerdem: ob Mitglieder die Namen sehen, ob eine Absage eine Entschuldigung
  braucht (dann entsteht ein Abwesenheitsantrag zur Freigabe) und eine eigene Frist.

  Die Antwort bleibt bis Terminbeginn änderbar. Eine Absage nach der Frist (Vorgabe 24 Stunden,
  Einstellungen → Terminrückmeldungen) wird gespeichert und als kurzfristig markiert.

### Geändert
- **Zuverlässigkeit** zählt eine rechtzeitige Absage als abgemeldet, auch ohne Antrag. Bei
  Terminarten mit Rückmeldung entscheidet für Absage **und** Antrag die Frist statt des
  Terminbeginns. Bei allen anderen Terminarten bleibt es bei der Regel aus 1.5.1.
- **`DATENSCHUTZ.md`** hat einen Abschnitt 12 zur Terminrückmeldung. Die bisherigen Abschnitte 12
  bis 14 heißen jetzt 13 bis 15.
- **Demo:** Auftritte fragen Rückmeldungen ab, dazu ein kommendes Konzert. Der Generator verlangt
  Schemastand 1.7.0.
- `mbstring` ist jetzt eine geprüfte Anforderung (war bereits für den Kiosk nötig).

### Behoben
- Beim Anlegen eines Antrags übernimmt der Server den Status nur noch von Admin und Manager.

---

## [1.6.1] – 2026-09-14

> **Ab 1.6.0 über den Assistenten.** Schritt 0 des Update-Assistenten holt dieses Paket selbst.
> Wer von einer älteren Version kommt, lädt es wie bisher von Hand hoch — ein Zwischenschritt
> über 1.6.0 ist nicht nötig.

### Geändert
- **Anforderungen stehen in `version.json`.** `requires` nennt PHP-Version und PHP-Erweiterungen;
  Installer, Update-Assistent und Updater prüfen dieselbe Angabe.
- **Der Updater spielt keine Version ein, deren Anforderungen der Server nicht erfüllt.** Er
  prüft vor dem Tausch; der neue Code prüft im Folgeaufruf noch einmal und spielt sonst die
  Sicherung zurück — das schützt auch Installationen auf 1.6.0.

### Behoben
- Migration 1.2.3 band die `config.php` per `require` ein. Das hätte den direkten Sprung von
  Versionen bis 1.2.2 abgebrochen, sobald der Update-Assistent die Datenbankklasse lädt. Sie liest
  die Toleranz jetzt als Text; ein Ausdruck statt einer Zahl ergibt die Vorgabe 2 mit Warnung.

---

## [1.6.0] – 2026-09-14

> **Dieses Update noch von Hand.** Der Assistent holt Pakete erst ab 1.6.0 selbst von GitHub.
> Wer von 1.5.1 oder älter kommt, lädt die Dateien dieses eine Mal wie bisher hoch und startet
> dann den Update-Assistenten. Er stellt dabei `config.php` auf die neue Form um und legt vorher
> `config.php.bak-1.5.1` an; die Sicherung enthält die Zugangsdaten und kann nach einer
> erfolgreichen Anmeldung gelöscht werden.

### Neu
- **Der Update-Assistent holt das Paket selbst von GitHub.** Ein neuer Schritt 0 fragt auf
  Knopfdruck nach der neuesten Version, lädt das Paket, prüft Aufbau und Migrationskette,
  sichert jede ersetzte Datei nach `private/backup/` und tauscht die Dateien. Während des Tauschs
  antwortet die API mit 503. Nach dem Tausch prüft der getauschte Code die Kette ein zweites Mal
  und spielt bei einem Fehler die Sicherung zurück. Der Weg von Hand bleibt.
- **Updates prüfen in den Einstellungen.** Nur auf Knopfdruck; es gibt keinen automatischen
  Abruf. Ist eine neuere Version bekannt, weist das Dashboard Administratoren darauf hin.
- Ressource `update_check`; `version` meldet der Rolle `admin` zusätzlich `update_available`.

### Geändert
- **`config.php` enthält nur noch Daten.** Zugangsdaten, `base_url` und `demo_mode` als Array;
  die Klasse `Database`, die Ermittlung der Basis-URL und das Laden der Konfiguration liegen
  jetzt in `private/helpers/` (`database.php`, `bootstrap.php`, `config_reader.php`) und werden
  bei jedem Update mit ausgetauscht. Bisher veraltete dieser Code bei jedem Release still,
  weil `config.php` nie überschrieben wird.
- **Der Update-Assistent stellt bestehende Dateien um.** Er legt vorher
  `config.php.bak-1.5.1` an, liest das Ergebnis gegen und spielt die Sicherung zurück, falls
  die Werte abweichen. Ein gesetztes `BASE_URL` und `DEMO_MODE` werden übernommen, `DEMO_MODE`
  mitsamt Rohwert. Kann er die Datei nicht schreiben, läuft die Installation weiter; das
  Dashboard weist Administratoren darauf hin.
- **Künftige Konfigurationsschalter brauchen keinen Eingriff mehr** in die `config.php` einer
  bestehenden Installation — fehlende Schlüssel erhalten einen Default.
- Die Ressource `version` meldet der Rolle `admin` zusätzlich `config_format`.
- Der Update-Assistent prüft die Migrationskette schon in Schritt 1 und listet in Schritt 2 die
  tatsächlich anstehenden Migrationen. Die Warnung „weicht von der erwarteten Ausgangsversion
  (1.0.0) ab" und die feste Liste aus der 1.0.0-Zeit entfallen — beide erschienen bei jedem
  regulären Update.

### Behoben
- Der Installer setzte das Datenbankpasswort unmaskiert in `config.php`: Ein `"` darin ergab
  eine kaputte Datei, ein `$` wurde von PHP still verändert.
- Bei fehlender `config.php` brach `api.php` mit einem Fatal Error ab; `ping` meldet jetzt wie
  vorgesehen `not_installed`.

### Entfernt
- `getMailConfig()` — seit `loadMailConfig()` nirgends mehr aufgerufen.
- Der wirkungslose Installer-Patch, der ein `require_once 'config.php'` in `api.php`
  umschreiben sollte.
- `AUTO_CHECKIN_TOLERANCE_HOURS` aus der Vorlage; der Wert steht seit 1.2.3 in den
  Einstellungen.

---

## [1.5.1] – 2026-09-14

### Neu
- **Pünktlichkeit und Zuverlässigkeit.** Zwei getrennte Kennzahlen in Statistik,
  Anwesenheitsbericht und Selbstauskunft, **beide ab Werk ausgeschaltet**. Eingeschaltet werden
  sie in den Einstellungen; vorher gehört `DATENSCHUTZ.md` Abschnitt 11 gelesen — eine Kennzahl,
  die aussagt, wie verlässlich ein Mitglied ist, ist etwas anderes als eine Anwesenheitsliste.

  **Pünktlichkeit** ist der Anteil der Ankünfte bis zu einer einstellbaren Karenz (Vorgabe:
  Terminbeginn; negative Werte verlangen Anwesenheit davor), dazu die durchschnittliche Verspätung
  der Zuspätkommer, je Ankunft gekappt bei 20 Minuten. Gerechnet wird nur über Ankünfte mit
  bekannter Uhrzeit, und unter fünf Messungen gibt es keine Quote. Die Messabdeckung steht immer
  daneben.

  **Zuverlässigkeit** ist der Anteil der Termine, zu denen jemand erschienen ist oder sich vor
  Beginn abgemeldet hat. Die heutige Anwesenheitsquote bestraft eine rechtzeitige Absage wie
  unentschuldigtes Fehlen; das behebt diese Kennzahl. Eine Abmeldung nach Beginn zählt als
  ausgefallen, auch wenn sie später genehmigt wird.

  Beide gelten für den gewählten Bereich — Verein, Gruppe oder eine einzelne, gezielt aufgerufene
  Person. Eine Liste, in der alle Mitglieder nach Pünktlichkeit nebeneinanderstehen, gibt es nicht.

  Grundlage war ein in einem Verein praktiziertes Verfahren; warum es nicht eins zu eins
  übernommen wurde, steht in der Spec.

### Geändert
- **`DATENSCHUTZ.md`** hat einen Abschnitt 11 zu den neuen Kennzahlen. Die bisherigen Abschnitte
  11 bis 13 heißen jetzt 12 bis 14.

### Behoben
- **Neues Mitglied mit Gruppe verlor PIN und Mitgliedschaftszeiträume.** Beim Anlegen lieferte die
  API die ID `0`, sobald Gruppen mitgeschickt wurden — sie wurde erst nach dem Speichern der
  Gruppenzuordnung gelesen. Die Oberfläche schickte PIN und Zeiträume dann an ein Mitglied, das es
  nicht gibt, und meldete einen Fehler wie „Member not found" — obwohl das Mitglied selbst angelegt
  war. Wer diese Meldung beim Anlegen gesehen hat: PIN und Zeitraum beim Mitglied nachtragen.
- **Die Dialoge der Zeiterfassung zeigen die ID des Eintrags** (OI-57). „Zeit nachtragen" bzw.
  „Eintrag bearbeiten" und der Dialog für Tätigkeitsarten trugen als einzige Bearbeitungsdialoge
  kein ID-Badge im Kopf.

## [1.5.0] – 2026-09-11

### Geändert
- **Die Ankunftszeit darf fehlen.** Wer eine Anwesenheitsliste abhakt, erzeugte bisher einen
  Eintrag mit der **Startzeit des Termins** als Ankunft. Der Datensatz war damit konstruiert
  pünktlich, und keine Auswertung konnte ihn von einer echten Messung unterscheiden. `arrival_time`
  darf jetzt leer sein und heißt dann: keine Aussage über die Ankunft.

  Das betrifft mehr als das Anlegen: Die Jahresauswahl der Statistik kommt jetzt allein aus den
  Terminen, und die DSGVO-Löschfrist rechnet über das Termindatum statt über die Ankunftszeit —
  ein Eintrag ohne Uhrzeit wäre sonst von keiner Frist mehr erfasst worden. Im Auskunftsexport
  nach Art. 15 DSGVO steht bei fehlender Uhrzeit eine leere Zelle statt des 01.01.1970.

  **Die Migration ist nicht umkehrbar.** Sie leert Ankunftszeiten, die exakt auf der Startminute
  liegen und von einem Listeneintrag stammen (`admin`, `timer`). Kiosk- und Gerätestempel bleiben
  unangetastet, auch wenn sie zufällig punktgenau fielen. Eine vom Verwalter *bewusst* auf die
  Startminute gesetzte Zeit ist davon nicht unterscheidbar und geht mit verloren — zulasten der
  Messabdeckung, nicht zulasten einer Quote.

- **Ein genehmigter Zeitkorrektur-Antrag kennzeichnet seine Herkunft.** Bisher behielt ein so
  überschriebener Eintrag die Quelle der Messung, die er ersetzt hat — eine vom Mitglied selbst
  angegebene Zeit lief als Kiosk-Stempel weiter. Neuer Wert `exception_request` in
  `checkin_source`; der Anwesenheitsbericht weist solche Zeiten als *korrigiert* aus.

- **Der Import verträgt eine leere Ankunftszeit**, sofern die Terminspalten den Termin treffen.
  Ohne diese Ausnahme hätte der Import den eigenen Export abgelehnt.

- **Im Anwesenheitsdialog steht der Status jetzt vor der Ankunftszeit.** Bei „Entschuldigt" wird
  die Ankunft ausgeblendet — wer nicht da war, ist nicht angekommen. Bei „Anwesend" bleibt das
  Feld leer und ist kein Pflichtfeld mehr; ein Knopf setzt den Terminbeginn ein, wenn der Nachtrag
  von dort ausgehen soll.

  Zuvor trug der Dialog beim Wechsel des Termins **stillschweigend dessen Startzeit** ein. Wer
  danach speicherte, erzeugte eine Ankunftszeit, die niemand gemessen und niemand gewollt hat.

- **Der nachträgliche Antrag fragt, wann man da war.** Die Check-in-App hat bisher ungefragt den
  Zeitpunkt der Antragstellung als Ankunft eingetragen: Wer um 19:55 kam, das gescheiterte
  Stempeln erst um 20:30 bemerkte und dann den Antrag stellte, beantragte damit 20:30. Das
  Mitglied gibt die Zeit jetzt selbst an.

- **Ein Toleranzband für Ankunftszeiten.** Eine eingetragene oder beantragte Ankunft muss
  innerhalb der Check-in-Toleranz (Vorgabe: zwei Stunden) um den Terminbeginn liegen. Das gilt
  für alle Wege gleichermaßen — Dialog, Antrag, Bearbeitung durch das Mitglied und durch die
  Verwaltung.

### Neu
- **Schnellinbetriebnahme des Kiosks per QR-Code.** Der Gerätedialog einer virtuellen Station
  zeigt neben Token und Kopieren einen QR-Code mit der Adresse `…/station/#t=<token>`. Statt
  48 Hex-Zeichen auf einem Tablet ohne Tastatur abzutippen, scannt man ihn mit der Kamera; die
  Station verbindet sich und entfernt den Token aus Adresszeile und Verlauf.

  Bewusst das **Fragment** und nicht der Query: Es wird vom Browser nie gesendet und steht damit
  in keinem Zugriffsprotokoll, keinem Referrer und keinem Reverse-Proxy-Log. Der Token bleibt
  ohne Mitglieds-PIN wertlos und darf weiterhin nur die Ressource `station` aufrufen — der
  QR-Code macht ihn nicht angreifbarer, als er im Gerätedialog ohnehin ist.

  Ein fehlgeschlagener Scan kostet die Station nicht ihre Kopplung: Der bisherige Token wird
  wiederhergestellt. Die Station wechselt aber sichtbar auf den Einrichtungs-Bildschirm mit der
  Fehlermeldung und **bleibt dort, bis jemand die Seite neu lädt** — in dieser Zeit nimmt sie
  keine Stempel an. Das ist Absicht: Ein stiller Rückfall auf den alten Token würde einen
  fehlgeschlagenen Scan verschweigen.

  **Reihenfolge beachten:** erst koppeln, dann zum Startbildschirm hinzufügen. Unter iPadOS hat
  eine installierte Web-App einen eigenen Speichercontainer; ein später gescannter Code landet
  in Safari und erreicht die installierte Station nicht.

- **Der QR-Code des Dashboards funktioniert ohne Internetzugang.** Die Bibliothek kam bisher von
  cdnjs und fiel in einem Netz ohne Internetzugang still aus — der QR-Code des PWA-Quicklinks
  fehlte dort einfach. Sie liegt jetzt als `public/js/vendor/qrcode.js` im Paket und dient
  Dashboard und Station gemeinsam. Zwei Tests halten fest, dass jedes Skript auf eine vorhandene
  Datei zeigt und weder Dashboard noch Station etwas von außen laden.

- **Demo-Modus für öffentlich erreichbare Installationen.** `define('DEMO_MODE', true);` in
  `private/config/config.php` begrenzt schreibende Zugriffe auf eine feste Erlaubnisliste:
  Mitglieder, Termine, Anwesenheit, Anträge, Arbeitszeit, Check-in und Kiosk bleiben nutzbar;
  Konten, Rechte, Mailversand, Dateiannahme und Systemeinstellungen sind gesperrt. Ohne die
  Zeile ist der Modus wirkungslos — eine Vereinsinstallation merkt nichts davon.

  Der Wächter steht an **einer** Stelle im Router, nicht in den Handlern, und die Liste ist
  eine **Erlaubnis**- und keine Sperrliste: Eine künftig neue Ressource ist damit von selbst
  gesperrt, bis jemand bewusst entscheidet. Genau daran ist der Vorgänger gescheitert — der
  alte `demo`-Branch schützte über zwölf harte `exit`-Aufrufe in Handler-Rümpfen, und als
  danach neun Ressourcen dazukamen, bemerkte das niemand.

  Ein gesetzter, aber unsauberer Wert fällt zur sicheren Seite: `true` heißt an, `false` aus,
  jeder andere Wert ebenfalls **an**, dazu eine Meldung im Protokoll. Ein Tippfehler lässt die
  öffentliche Demo also nicht stillschweigend ungeschützt.

- **Hinweisband auf allen Oberflächen.** `appearance` meldet zusätzlich `demo`; Dashboard,
  Anmeldung, Check-in-PWA und Kiosk blenden daraufhin ein Band ein. Der ausdruckbare
  Arbeitszeitnachweis trägt einen eigenen, deutlicheren Hinweis — er ist das einzige
  Erzeugnis, das den Bildschirm verlässt, und wäre sonst von einem echten Nachweis äußerlich
  nicht zu unterscheiden.

- **Die Anwesenheitsstatistik hat einen Druckbericht.** `GET ?resource=statistics_report`
  liefert eine eigenständige HTML-Seite mit Vereinslogo, Kennzahlen, einer Tabelle je Gruppe und
  Fußnoten — dasselbe Papierformat, das die Arbeitszeit seit 1.2.2 kennt. In der
  Statistik-Sektion öffnet der Knopf „📄 Bericht" ihn mit der aktuellen Filterauswahl in einem
  neuen Tab. Bewusst ohne eigenen Dialog: Die Sektion trägt Jahr, Gruppe und Mitglied bereits in
  der Filterleiste, ein zweites Formular hätte nur die Frage aufgeworfen, welche der beiden
  Auswahlen gilt.

  Ist genau ein Mitglied gewählt, folgt ein Abschnitt **„Termine im Einzelnen"**: Datum, Termin,
  Terminart, Status, Ankunft und Herkunft. Erst diese Liste macht das Blatt zum Nachweis statt
  zur Kennzahl.

- **Mitglieder drucken ihre Nachweise selbst.** Anwesenheitsbericht und Stundennachweis stehen
  jetzt auch der Rolle `user` offen — ausschließlich über die eigene Person, ausschließlich als
  Druckansicht. Wer eine Ehrenamtskarte beantragt oder dem Arbeitgeber etwas vorlegen muss,
  braucht dafür niemanden mehr.

  Der Server erzwingt die eigene Person; eine mitgeschickte fremde `member_id` wird ignoriert,
  nicht abgewiesen. CSV bleibt Admin und Manager vorbehalten — die eigenen Rohdaten gibt es über
  `?resource=my_data`.

### Geändert
- **Neues Zeichen, neuer Icon-Satz.** Logo, Favicon und die PWA-Icons stammen jetzt aus einer
  überarbeiteten Vorlage: zwei Farben statt vier, der Haken sitzt in einem eigenen Kreis. Neu
  sind eigene **maskable**-Icons — bisher trugen `icon-192/512` die Kennzeichnung
  `any maskable`, weshalb Android das Zeichen beim runden Zuschnitt an den Rändern abschnitt.
  Das Apple-Touch-Icon ist nicht mehr das 192er-Icon, sondern eine eigene Datei mit weißem
  Grund, weil iOS keine Transparenz kennt. Das Favicon zeigt unter 48 px nur noch den
  Haken-Kreis; das vollständige Zeichen war dort nicht mehr lesbar. Die Check-in-PWA meldet
  zudem `#1F5FBF` als Themenfarbe statt des alten `#667eea` aus der ersten Entwurfspalette
- **Die Anmeldeseite trägt jetzt „Einsatz ist EhrenSache".** „Anwesenheit" beschrieb die
  Anwendung nicht mehr vollständig, seit sie auch Arbeitszeit erfasst; „Einsatz" deckt beide
  Erfassungsarten ab. Derselbe Claim steht in der README
- **Anmeldeseite: ruhige Fläche statt Zweifarb-Verlauf.** Der lineare Verlauf stellte die beiden
  frei einstellbaren Vereinsfarben unvermittelt nebeneinander — bei fremden Farbpaaren
  (Rot/Orange, Gelb/Anthrazit) wurde das unruhig bis grell. Jetzt trägt eine aus der Primärfarbe
  abgeleitete, abgedunkelte Fläche den Bildschirm, überlagert von zwei weichen Lichtern und einem
  feinen Punktraster gegen Streifenbildung; die Sekundärfarbe klingt nur noch als Schimmer an.
  Neue Tokens `--login-bg` und `--login-glow` in `variables.css`, per `color-mix` aus den
  eingestellten Farben abgeleitet, mit festem Rückfallwert für Browser ohne Unterstützung. Die
  Anmeldekarte bekommt gestaffelte Schatten und 16 px Radius, damit sie auf der Fläche aufliegt
  statt wie ein Ausschnitt zu wirken
- `private/demo/seed.php`: `--quiet` schweigt zusammen mit `--yes` vollständig. Zuvor gab auch
  ein stiller Lauf rund zwanzig Zeilen aus — bei einem stündlichen Cron-Job je nach
  Konfiguration eine Mail pro Stunde. Ohne `--yes` bleibt die Zielanzeige, weil danach nach
  `LOESCHEN` gefragt wird: Wer das tippen soll, muss sehen, was er löscht.

- **Installations- und Update-Assistent verlangen jetzt PHP 8.0.** Beide prüften bisher nur
  auf 7.4 — eine Version, die seit November 2022 keine Sicherheitsupdates mehr erhält und die
  weder README noch Projektdokumentation je als unterstützt genannt haben. Die Prüfzeile nennt
  zusätzlich die tatsächlich laufende Version, damit im Fehlerfall nicht erst im
  Hosting-Panel nachgesehen werden muss.

  **Folge für Bestandsinstallationen auf PHP 7.4:** Der Update-Assistent bricht in Schritt 1 ab,
  bis die PHP-Version im Hosting umgestellt ist. Die bestehende Installation läuft bis dahin
  unverändert weiter — blockiert ist nur der Weg auf eine neuere Version. Der Hinweistext im
  Assistenten sagt das jetzt auch.

- **Der Bericht sagt, worauf seine Ankunftszeiten beruhen.** `records.arrival_time` ist keine
  durchgehende Messung: Hakt jemand eine Anwesenheitsliste ab, ohne eine Uhrzeit einzutragen,
  setzt das System die **Startzeit des Termins** — der Eintrag ist damit konstruiert pünktlich.
  Diese Uhrzeit ununterschieden auf einen Nachweis zu drucken, hieße eine Messung zu behaupten,
  die nie stattgefunden hat. Die Terminliste trägt deshalb eine Spalte **Herkunft** mit drei
  Stufen, erklärt in den Fußnoten:

  | Wert | Bedeutung |
  |---|---|
  | `gemessen` | bei der Anmeldung an einer Station oder in der App aufgezeichnet |
  | `korrigiert` | auf Antrag geändert und genehmigt |
  | `nachgetragen` | nicht bei der Anmeldung erfasst — von Hand, eingelesen oder aus einem anderen Vorgang |

  `korrigiert` schlägt `gemessen`: Eine genehmigte Zeitkorrektur überschreibt die Ankunftszeit,
  lässt die Quelle aber unverändert. Ohne diese Vorrangregel trüge eine korrigierte Zeit weiter
  das Etikett der ursprünglichen Messung.

- **Berichtsausgabe an einer Stelle.** Die HTML-Ausgabe der Arbeitszeitberichte lag in
  `handlers/export.php` und konnte eine Tabelle plus einen Summenblock. Sie liegt jetzt als
  `renderReport()` in `helpers/report.php` und kennt beliebig viele Abschnitte — der
  Anwesenheitsbericht braucht einen je Gruppe. Die drei Arbeitszeitberichte sehen unverändert
  aus; das ist byteweise nachgewiesen.

- **Bildschirm und Papier rechnen dasselbe.** Die Aggregation der Statistik lag als Schleife
  mitten im Handler und war für nichts anderes benutzbar. Sie steht jetzt als
  `buildStatisticsResult()` daneben, und der Bericht benutzt sie. Zwei Rechnungen, die dasselbe
  behaupten, laufen bei der ersten Änderung auseinander.

- **`?resource=statistics` liefert zwei Felder mehr.** Je Mitglied `excused` — bisher musste
  jeder Verbraucher es aus drei anderen Zahlen selbst ausrechnen, zuletzt an zwei Stellen
  gleichzeitig —, je Gruppe `appointment_type_name`. Beides additiv, bestehende Aufrufer sind
  unberührt. Ohne `year`-Parameter trägt die Antwort jetzt `"year": 2026` als Zahl statt
  `"year": "2026"` als Zeichenkette.

### Behoben
- **Die Statistik wertete je Gruppe nur eine einzige Terminart aus (OI-48) — die Quoten ändern
  sich dadurch in jeder bestehenden Installation.** In welche Richtung, hängt davon ab, wie
  diszipliniert bisher bei den ignorierten Terminarten erfasst wurde: Ein Verein, der seine
  Zahlen über Jahre verfolgt, sollte das wissen, bevor er sich über einen Sprung wundert, statt
  einen Rechenfehler zu vermuten.

  Der Grund lag in `calculateGroupStatistics()`: `appointment_type_groups` ist eine M:N-Tabelle
  — eine Gruppe kann an mehreren Terminarten hängen —, aber die Funktion las mit `fetch()` nur
  eine einzige Zeile davon, **ohne `ORDER BY`**. Welche Terminart damit gewann, entschied die
  Datenbank, nicht die Fachlogik. Im Demo-Bestand blieben dadurch **24 Termine — knapp 40 Prozent
  aller erfassten Termine — unsichtbar** (Stand 2026-09-11: 47 von 71 gezählt).

  Jetzt werden alle Terminarten einer Gruppe ausgewertet, je Mitglied unter `by_type` einzeln
  ausgewiesen, und die Kopfzahlen sind entdoppelt: Erreicht ein Mitglied denselben Termin über
  zwei Gruppen, zählt er in `summary.total_appointments` einmal statt doppelt.

  Wie irreführend die alte Zahl war, zeigt ein Befund aus dem Demo-Bestand: Die bisherige
  „Anwesenheitsquote" eines Mitglieds stimmt auf die Nachkommastelle mit seiner neuen Quote für
  die Terminart „Gesamtprobe" überein. Es war also nie eine Gesamtquote — nur die Quote einer
  einzelnen Terminart unter falschem Namen.

  Die Check-in-PWA war von dem Fehler nicht separat betroffen und braucht auch keine eigene
  Änderung, um korrekt zu werden: Ihre Gruppenkacheln beruhen auf derselben Statistik-Ressource
  und zeigen ab sofort automatisch die vollständige Auswertung.

- **Die Navigation war im Querformat nicht bedienbar, sondern unsichtbar** (OI-53). Gemeldet
  war ein „zu kleines" Menü; gemessen wurde etwas anderes. Die Seitenleiste ist ein
  Flex-Container über die volle Fensterhöhe, in dem Kopf- und Fußbereich ihre Höhe behalten
  und allein die Navigationsliste nachgibt (`flex: 1`). Bei einem quer gehaltenen Telefon —
  rund 390 px hoch — beanspruchen Kopf (213 px), Reiter (33 px) und Fußbereich (145 px)
  bereits mehr als das ganze Fenster. Die Liste bekam **0 px**: Alle Einträge waren da, keiner
  war zu sehen.

  Dazu kam der Haltepunkt. Quer ist ein Telefon 844 bis 926 px breit und damit oberhalb der
  768 px, an denen die mobile Bedienung hing — der Menüknopf verschwand, die 250 px breite
  Leiste stand fest im Layout, und dieselbe Liste kollabierte dort auf 17 px. Entschieden hat
  das ohnehin nicht das Stylesheet, sondern ein Inline-Style aus `ui.js`, der die Fensterbreite
  selbst gegen 768 prüfte; die zugehörigen CSS-Regeln waren wirkungslos.

  Behoben an allen drei Stellen: Ein eigener Block `@media (max-height: 500px)` in
  `responsive.css` bringt Menüknopf und ausfahrbare Leiste ins flache Querformat, die Liste
  behält dort ihre Eintragshöhe (50 px Trefferfläche) und die Leiste scrollt als Ganzes;
  `ui.js` wertet über `matchMedia` dieselbe Bedingung aus wie das Stylesheet; und
  `.sidebar` rechnet mit `100dvh` statt `100vh`, damit die eingeblendete Adressleiste des
  Telefons die Leiste nicht unten abschneidet. Der Block `min-width: 1200px` trägt jetzt
  zusätzlich `min-height: 501px` — ohne das hätte er bei einem flach gezogenen Fenster gegen
  die neue Regel gewonnen und den Menüknopf wieder versteckt, während die Leiste bereits
  ausgefahren war. Das Tablet im Querformat ist nicht betroffen: Es misst quer 744 px Höhe
  und mehr und behält seine Leiste. Festgehalten in `tests/suites/responsive_nav.php`

- **`API.md` beschrieb `appearance` falsch.** Das Beispiel zeigte ein flaches Objekt mit
  `org_name` und `logo_url`; ausgeliefert wird seit Langem `{"settings": {…}}` mit anderen
  Schlüsselnamen. Gegen die laufende Installation geprüft und ersetzt.

- **Die README beschrieb einen Stand, den es nicht gibt.** Am schwersten wog das Versprechen
  „XSS-Schutz durch Content Security Policy" — ausgeliefert wird bewusst keine CSP (OI-17).
  Ebenfalls falsch: der Zwischenspeicher liege im `localStorage` (er liegt im Arbeitsspeicher
  und ist nach jedem Neuladen leer), vier API-Beispiele riefen `api.php&resource=` statt
  `?resource=` auf, das Rollensystem habe drei statt vier Rollen, und „~90 % Reduktion der
  API-Anfragen" war eine unbelegte Zahl. Ergänzt wurden die seit 1.2.0 vorhandene
  Arbeitszeiterfassung und die virtuelle Station, die beide bisher nirgends erwähnt waren.

- **`API.md` beschrieb auch die Statistik-Antwort falsch.** Dasselbe Muster, andere Stelle: Das
  dokumentierte Beispiel führte `attended`, `absent`, `attendance_rate` und `monthly_stats` auf;
  die tatsächliche Antwort trägt `summary` und `statistics`. Wer nach dieser Referenz baute,
  baute gegen etwas, das nie existiert hat. Das Beispiel entspricht jetzt der Wirklichkeit.

  Darin standen auch `late_count` und `avg_arrival_minutes` — die Pünktlichkeitsauswertung, die
  das Projekt an mehreren Stellen bewirbt und nirgends einlöst. Sie sind aus der Referenz
  entfernt, statt eine Zusage stehen zu lassen, die kein Code erfüllt; als offener Punkt ist
  die Metrik in `docs/OPEN-ITEMS.md` festgehalten.

### Intern
- `tests/run.php` meldet einen vorzeitigen Abbruch. Bisher lud es alle Suiten in **einem**
  Prozess und rief die Zusammenfassung erst am Ende — ein `exit()` in irgendeiner Suite
  beendete den Lauf mit Rückgabewert **0** und übersprang stillschweigend alle späteren
  Suiten. Von außen sah das wie ein Erfolg aus.
- `tests/suites/demo_mode.php` liest `public/api/api.php` und verlangt, dass jede dort
  geroutete Ressource in genau einer der drei Listen steht, und dass der Wächter vor dem
  ersten öffentlichen Endpunkt aufgerufen wird — geprüft über den Tokenstrom, nicht über eine
  Textsuche.
- `$_GET['resource']` wird auf eine Zeichenkette geprüft. `?resource[]=x` lieferte sonst ein
  Array, das die Typprüfung des Wächters mit einem Fatal Error zerlegt hätte.
- Der `demo`-Branch entfällt. Sein Stand liegt als Tag `demo-legacy`.

---

## [1.4.1] – 2026-09-09

### Sicherheit
- **Dashboard und Check-in-PWA liefen mit eingeschaltetem Debug-Modus aus.** `DEBUG` stand in
  `public/js/app.js` und `public/checkin/js/app.js` fest auf `true` — eine Konstante, die vor
  einer Veröffentlichung von Hand umzustellen war. Das ist seit 1.1.3 in **jeder**
  veröffentlichten Version unterblieben — 1.4.0 ist nicht der Anfang, nur das Ende; einzig
  1.0.0 wurde stumm ausgeliefert. Folge: die PWA schrieb bei jeder Anmeldung die
  vollständige Serverantwort in die Browserkonsole, einschließlich des Bearer-Tokens, mit
  dem sich das Gerät danach dauerhaft ausweist.

  Das Token verließ das Gerät dabei nicht — es stand in der Konsole des Browsers, in dem die
  Anmeldung stattfand. Wer die Entwicklerwerkzeuge dieses Geräts öffnen kann, konnte es
  jedoch im Klartext mitlesen: relevant vor allem bei Geräten, die sich mehrere Personen
  teilen, und bei Fernwartungssitzungen mit geteiltem Bildschirm. **Wer solche Geräte im
  Einsatz hat, sollte die betroffenen Token nach dem Update neu erzeugen** (Profil →
  „Token neu generieren"; für Geräte derselbe Weg in der Geräteverwaltung).

  Der Schalter wird jetzt nicht mehr gesetzt, sondern aus `location.hostname` abgeleitet:
  lokal laut, auf jeder echten Domain still. Damit gibt es nichts mehr umzustellen, was
  vergessen werden könnte. Die virtuelle Station ist bewusst enger gefasst als Dashboard und
  PWA — sie hängt als Kiosk dauerhaft im Vereinsnetz und bleibt auch unter privaten Adressen
  und `.local`-Namen stumm. Für den Bedarfsfall lässt sich die Ausgabe an jedem Gerät
  einzeln über `localStorage.setItem('es_debug', '1')` in der Konsole zuschalten.

### Intern
- Zwei Prüfungen in der Suite `assets` halten den Zustand fest: kein hart gesetztes `DEBUG`
  mehr, und keine ungeschützte `console.log`/`warn`/`debug` in den ausgelieferten Skripten.
  `console.error` bleibt erlaubt — eine Fehlermeldung soll auch produktiv sichtbar sein und
  trägt keine Sitzungsdaten. Eine dritte Prüfung hält die engere Regel der Station fest
- Die Migration `1.4.0 → 1.4.1` ändert nichts am Schema. Sie existiert, damit die Kette
  lückenlos bis `version.json` reicht und der Update-Wizard für eine 1.4.0-Installation einen
  Weg findet

---
## [1.4.0] – 2026-09-09

### Neu
- **Der Kalender der Terminverwaltung zeigt die Termine schon beim Überfahren.** Bisher öffnete
  erst ein Klick das Popup; das Überfahren zeigte nur einen nativen Browser-Tooltip —
  unformatiert und erst nach rund einer Sekunde. Jetzt erscheint dasselbe Popup nach 180
  Millisekunden. Ein per Klick geöffnetes bleibt stehen, ein überfahrenes verschwindet beim
  Verlassen; der Klick bleibt damit der Weg auf Geräten ohne Mauszeiger

### Geändert
- **`public/.htaccess` leitet jetzt selbst auf HTTPS um.** Der Block war bisher auskommentiert
  und musste von Hand aktiviert werden — was beim nächsten Update verloren ging, weil die Datei
  zum Paket gehört und überschrieben wird. Der sichere Zustand ist deshalb jetzt der
  Auslieferungszustand. **Nach dem Update gilt die Umleitung ohne weiteres Zutun**; wer sie
  nicht will, kommentiert den Block aus und muss das nach jedem Update wiederholen.
  Drei Ausnahmen verhindern eine Endlosschleife dort, wo `%{HTTPS}` trügt: TLS-Terminierung am
  Proxy (`X-Forwarded-Proto`), Port 443, und `localhost` für die lokale Entwicklung. Es ist
  dieselbe dreifache Prüfung, die `public/api/api.php` schon für das Secure-Flag der Session
  verwendet
- **`work_sessions.active_member` ist jetzt eine gespeicherte statt einer virtuellen Spalte.**
  Die Migration legt Spalte und Unique-Index neu an — MariaDB lehnt die Umstellung per `MODIFY`
  ab (Fehler 1907). Der Wert folgt vollständig aus `end_time` und `member_id` und entsteht beim
  Neuanlegen aus den vorhandenen Zeilen; verloren geht nichts. Grund ist der Verdacht aus OI-1,
  dass die indizierte virtuelle Spalte nach einer Crash-Recovery den AUTO_INCREMENT-Zähler der
  Tabelle kostet. **Bei sehr vielen Sitzungen schreibt der Schritt die Tabelle neu und dauert
  entsprechend**; der Update-Assistent weist ab 50 000 Zeilen darauf hin
- **Datum und Uhrzeit im Kalender-Popup sind formatiert.** Statt `2026-09-04` und `20:00:00`
  steht dort `Fr., 04.09.2026` und `20:00` — dasselbe Muster wie an den übrigen Stellen der
  Oberfläche. Seit das Popup schon beim Überfahren erscheint, fiel die Rohform ständig auf
- **Der Anwesenheits-Export führt den Termin jetzt eindeutig.** Neu sind die Spalten
  `appointment_start_time` und `appointment_type`; zusammen mit `appointment_date` bilden sie
  den Schlüssel, den die Anwendung ohnehin verwendet — beim Anlegen gilt ein Termin *dieser
  Art* im Toleranzfenster als Konflikt, zwei verschiedene Arten am selben Abend sind erlaubt.
  Der Reimport ordnet damit exakt zu, statt über zeitliche Nähe zu raten und dabei Probe und
  Vorstandssitzung verwechseln zu können. Fehlen die Spalten — ältere Dateien, Fremdsysteme —,
  greift unverändert die bisherige Suche

### Hinzugefügt
- **Der Anwesenheits-Import kann fehlende Termine anlegen**, auf ausdrückliche Anforderung
  (`create_missing_appointments`). Verlangt denselben vollständigen Schlüssel und legt nichts
  auf Verdacht an; die Antwort nennt unter `appointments_created`, wie viele entstanden sind.
  Standardmäßig aus, damit ein Tippfehler im Datum keine Karteileiche erzeugt. Wer Termine
  aus bloßen Ankunftszeiten *rekonstruieren* will, nutzt weiterhin `extract_appointments` —
  das schlägt vor, ohne zu schreiben
- Neue Testsuite `export_import`: prüft die Kopfzeilen der drei Exporte gegen die
  Pflichtspalten des Imports, den Terminschlüssel auf Vorhandensein **und** Inhalt, und hält
  die Arbeitsteilung fest, dass `extract_appointments` nicht schreibt
- **Arbeitszeit in der Statistik der Check-in-PWA.** Der Statistik-Tab zeigt für Mitglieder,
  die Zeiten erfassen dürfen, die bestätigte Jahressumme, eine Aufschlüsselung nach Tätigkeit
  und eine Fußnote über eingereichte und abgelehnte Einträge. Die Summe stammt aus derselben
  Auswertung wie der Verwendungsnachweis (`statistics?include=worktime`); nur bestätigte und
  beendete Sitzungen zählen. Ohne die Fußnote läse sich eine „0:00 h" nach einem frischen
  Nachtrag als Fehler. Server, Schema und API bleiben unverändert.
- **Arbeitszeit aus der PWA korrigieren und nachtragen.** Eigene abgeschlossene Sitzungen
  lassen sich über den Verlauf korrigieren, vergessene über den Arbeitszeit-Tab nachtragen.
  Beides geht erneut in die Freigabe. **Eine Zeitkorrektur nimmt dem Eintrag den Ortsnachweis
  für die verschobene Zeit** — bisher behielt eine um Stunden zurückdatierte Sitzung das
  Etikett „stundenbelegt", obwohl für die zusätzliche Zeit nichts belegt war. Das gilt für alle
  Rollen und wirkt sich auf Statistik, Export und Verwendungsnachweis aus.

### Behoben
- **Eine Installation im Stand 1.0.0 ließ sich über den Update-Assistenten nicht
  aktualisieren.** Schritt 1 brach mit „Die installierte Datenbankversion konnte nicht
  bestimmt werden" ab — unabhängig vom Zielstand, und seit es den Assistenten gibt. Der Grund:
  Er übergibt der Versionserkennung den Präfix aus der Konfiguration, und eine 1.0.0-`config.php`
  kennt das Feld `$prefix` noch gar nicht. Die Prüfung lautete damit
  `tableExists('users') && !tableExists('users')` und hob sich selbst auf. Wer es versucht hat,
  hatte Grund, seine Datenbank zu verdächtigen statt der Software. Der Fall steht jetzt als
  `UPD-5a` in `tests/db/verify_migration_chain.php` — mit dem echten Schema aus dem Tag
  `v1.0.0` statt einer nachgebauten Ausgangslage
- **Der Installer stempelte eine geratene Version in die Datenbank.** Fehlte `version.json`,
  trug er fest `1.1.3` in `schema_version` ein. Eine frische Installation hätte sich damit als
  veraltet ausgegeben, und der Update-Assistent wäre später über ein längst aktuelles Schema
  gelaufen. Jetzt bricht die Installation mit klarer Meldung ab: Ein unvollständiges Paket soll
  auffallen. Der Kopf des Installers **nennt die Version außerdem sichtbar**, wie der
  Update-Assistent es schon tat
- **„Teilbelegt" gilt jetzt für beide Grenzen einer Sitzung, nicht nur für den Start.** Die
  Leiter des Nachweisgrades kannte eine Stufe für „nur der Start ist ortsbelegt", aber keine
  für „nur das Ende ist ortsbelegt" — letztere fiel bis „unbelegt" durch. Eine Sitzung mit
  verschobenem Beginn galt damit als gänzlich unbelegt, obwohl ihr Ende weiter belegt war.
  Tragbar war das, solange Ortsnachweise nur vom Timer kamen: Wer stoppt, hat vorher
  gestartet. Seit eine Zeitkorrektur den Nachweis der geänderten Zeit fallen lässt, ist der
  umgekehrte Fall alltäglich. **Die Änderung wirkt rückwirkend:** Bestehende Sitzungen mit
  belegtem Ende und unbelegtem Start rutschen von „unbelegt" auf „teilbelegt" — auch in
  abgeschlossenen Jahren. Ein neu erzeugter Verwendungsnachweis weicht dort von einem früher
  eingereichten ab
- **Verlauf und Anwesenheitsliste der Check-in-PWA rollten in einem eigenen Kasten
  innerhalb einer Seite, die selbst rollt.** Beide trugen die feste Obergrenze
  `calc(100vh - 320px)`; die 320 Pixel beschrieben eine Kopfzone, die je nach Fensterbreite
  umbricht und tatsächlich 377 bis 453 Pixel hoch ist. Der Kasten ragte damit aus dem Fenster,
  und wer über ihm wischte, bewegte ihn statt der Seite — es sah aus, als höre die Liste dort
  auf. Im Verlauf waren auf einem Handy 3 von 20 geladenen Einträgen ohne Geste sichtbar.
  Beide Listen wachsen jetzt mit ihrem Inhalt; eine einzige Geste erreicht alles
- **Der Knopf „Zeit nachtragen" war auf der weißen Karte kaum zu sehen.** `.action-button`
  setzt `border: none`, die Variante `secondary` überschrieb nur den Hintergrund — ihr
  Hover-Zustand setzte seit jeher ein `border-color`, das ins Leere lief. Alle fünf sekundären
  Knöpfe der PWA sind jetzt Umriss-Knöpfe in der Hausfarbe
- **Der Knopf „Korrigieren" im Verlauf steht rechts oben**, an derselben Stelle und in
  derselben Form wie der Löschen-Knopf eines Antrags — blau statt rot, weil Korrigieren nichts
  zerstört. Zuvor klebte er am Datum
- **Ein Anwesenheitsantrag heißt im Verlauf der PWA nicht mehr „Zeitkorrektur".** Das Wort
  meinte dort die Ankunftszeit und war neben den Arbeitszeit-Einträgen derselben Liste mit
  einer Korrektur der *Arbeitszeit* zu verwechseln. Ein Antrag liest sich jetzt als „Antrag
  wartet auf Freigabe", eine Entschuldigung behält ihr eindeutiges Wort. Das Dashboard nennt
  die Antragsart weiterhin „Zeitkorrektur" — die PWA sagt weniger, nicht etwas anderes
- **Die Zeiterfassung heilt einen verlorenen AUTO_INCREMENT-Zähler selbst.** Nach einer
  InnoDB-Crash-Recovery las sich der Zähler von `work_sessions` zweimal als `0`; jeder Versuch,
  eine Sitzung anzulegen, endete mit `1467 Failed to read auto-increment value from storage
  engine` und HTTP 500. Ein Verein ohne Datenbankkenntnisse kann das nicht beheben. Der Handler
  erkennt jetzt genau diesen Fehler, setzt den Zähler auf `MAX + 1` und wiederholt den
  Schreibvorgang einmal. **Der Eingriff wird laut protokolliert** — er behandelt ein Symptom,
  dessen Ursache außerhalb der Anwendung liegt, und soll künftige Diagnosen nicht erschweren.
  Siehe OI-1
- **Das Kalender-Popup lief am Fensterrand aus dem Bild.** Es wurde ohne Rücksicht auf die
  Fenstergröße positioniert; in der rechten Spalte und in der letzten Zeile war es teilweise
  unerreichbar. Es klappt jetzt um. Beim Klick fiel das selten auf, beim Überfahren ständig
- **Datum und Uhrzeit in der Terminliste standen in normaler Textgröße.** Ein fehlendes Wort im
  Markup (`<style=…>` statt `<small style=…>`) erzeugte ein unbekanntes Element, die
  Kleinschrift blieb wirkungslos

### Intern
- **Demo-Datengenerator** (`private/demo/`): Ein CLI-Skript füllt eine Datenbank reproduzierbar
  mit einem fiktiven Verein — als Bildmaterial für die Werbeseite und als Reset-Bestand der
  öffentlichen Demo, deren bisheriges SQL-Skript seit 1.1.0 nicht mehr lauffähig war. Getrennt
  in einen reinen Teil (`plan.php`, 99 Tests ohne Datenbank) und eine Schreibschicht
  (`seed.php`). Über `export-ignore` nicht im ZIP-Download enthalten
- `tests/suites/station_api.php` sammelte über Läufe hinweg eine Sperre an: Der Test mit
  falscher PIN schickte eine **feste** unbekannte Mitgliedsnummer, und die Stationssperre zählt
  Fehlversuche auch für unbekannte Nummern. Ab dem sechsten Lauf binnen 15 Minuten antwortete
  der Server `423` statt `401`. Die Nummer trägt jetzt ein `uniqid()`
- `tests/suites/worktime_api.php`: Zwei Tests setzten einen freien Timer-Zustand voraus, statt
  ihn herzustellen. Neue Testsuiten `demo_seed_unit`, `demo_seed_cli` und die Einzelprüfung
  `tests/db/verify_autoinc_repair.php`

---

## [1.3.1] – 2026-09-07

### Behoben
- **CSV-Export von Mitgliedern, Terminen und Anwesenheiten war unbenutzbar.** Das Dashboard
  schickte bei diesen drei Downloads den Header `Authorization: Bearer null` mit — es legt
  gar keinen API-Token ab, `getAuthHeaders()` baute den Header aber unbedingt. Ein solcher
  Header verdrängt in `api.php` die Session (`if (!$apiToken) session_start()`), läuft in die
  Token-Prüfung und endet mit `401`, obwohl der Nutzer angemeldet ist. Der Header entsteht
  jetzt nur noch, wenn tatsächlich ein Token vorliegt.

  Der Fehler lag seit der Einführung des Exports im Code, blieb aber vier Monate folgenlos,
  weil Apache den `Authorization`-Header verschluckte. Sichtbar wurde er erst, als dieser
  Header für die Token-Authentifizierung der PWA durchgereicht wurde — ein berechtigter Fix,
  der einen schlafenden Fehler geweckt hat. Betroffen waren ausschließlich diese drei
  Exporte; der Import und alle übrigen Aufrufe laufen über andere Wege. Siehe OI-24.
- **Der Termin-Export lud Fehlermeldungen als CSV-Datei herunter.** Ihm fehlte die Prüfung
  auf `response.ok`, sodass der Fehlerkörper zum Blob wurde und als `.csv` mit JSON darin
  auf der Platte landete.
- **Exportierte CSV-Dateien ließen sich nicht wieder importieren.** Der Termin-Export
  schrieb die Spalte `type`, der Import verlangt `type_name`; beim Anwesenheits-Export hieß
  die Spalte `arrival_time` statt `arrival_date_time`. Beide Dateien wurden mit „missing
  required columns" abgewiesen, bevor eine Zeile gelesen war. Der Export nutzt jetzt die
  Namen des Imports; der Import akzeptiert die alten weiter, damit archivierte Dateien
  einlesbar bleiben. Mitglieder-Exporte waren nie betroffen. Siehe OI-24
- **Mitgliedsfilter der Zeiterfassung war beim ersten Öffnen leer.** Die Auswahl wurde aus
  dem Mitglieder-Cache aufgebaut, den der Bereichswechsel erst 500 ms später im Hintergrund
  füllt. Filterleiste und Nachtrags-Dialog laden die Liste jetzt selbst (`loadMembers()`)
- **Check-in-PWA zeigte Administratoren und Managern im Verlauf die Arbeitszeiten aller
  Mitglieder.** Der Abruf ging ohne `member_id` an `work_sessions`; ohne diese Eingrenzung
  antwortet die Ressource für beide Rollen absichtlich ungefiltert (so gewollt fürs
  Dashboard). Der Verlauf grenzt jetzt auf das angemeldete Mitglied ein — die
  Gesamtübersicht bleibt dem Dashboard vorbehalten
- **Check-in-PWA behielt nach der Abmeldung die Ansichten des vorigen Mitglieds.** Verlauf,
  Statistik, Anwesenheitsliste, Auswahlfelder und der zuletzt geöffnete Tab standen unverändert
  weiter, bis ein Reload dazwischenkam. Die Abmeldung räumt diesen Zustand jetzt ab
- **Check-in-PWA hängte bei jeder Anmeldung dieselben Ereignisse erneut an.** Nach einem Zyklus
  Abmelden → Anmelden schickte ein Klick auf „Start" zwei Anfragen, ein Klick auf ein Jahr
  sprang zwei Jahre weit, und ein Tab-Wechsel lud seine Daten doppelt. Neues `bindOnce()`
  bindet je Element und Ereignisart nur einmal — dieselbe Sperre, die
  `initAttendanceList()` schon von Hand hatte

- **Kiosk: Restlaufzeit-Balken des TOTP-Codes begann nach einem Reload immer voll und blieb
  nach dem nächsten Codewechsel grau.** Die Leiste wurde in zwei Schritten gesetzt (Startbreite,
  dann per `requestAnimationFrame` das Ziel 0 %), doch der Browser fasst beide zu einer
  Stilberechnung zusammen — rAF-Callbacks laufen vor dem Style-Recalc desselben Frames. Die
  Transition startete deshalb vom vorigen Wert: nach dem Laden von 100 %, nach einem
  abgelaufenen Code von 0 % nach 0 %, also gar nicht. Nur ein Klick (Stempeln/Abbrechen)
  erzwang zufällig einen Flush dazwischen. Jetzt erzwingt `renderBar()` den Reflow selbst.

### Intern
- Neue Testsuite `worktime_frontend` (statische Gegenproben am Zeiterfassungs-Frontend) und
  ein API-Test, der die Eingrenzung per `member_id` festhält
- `initAttendanceList()` nutzt `bindOnce()` statt siebenmal `dataset.listenerAdded` von Hand;
  das Speichern des Termindialogs steht als `submitAppointmentForm()` daneben
- Testplan: Abschnitt 21 zu Sitzungswechsel in der PWA und Mitgliedsfilter

---

## [1.3.0] – 2026-09-07

### Neu
- **Virtuelle Station (Kiosk).** Neuer Gerätetyp `kiosk`: ein Tablet zeigt den rotierenden
  Stations-Code und nimmt Stempel per Mitgliedsnummer + PIN entgegen — Anwesenheit und
  Arbeitszeit (Start, Pause, Ende). Eigene PWA unter `public/station/`, eigene Ressource
  `station`. Das TOTP-Secret verlässt den Server nicht; der Kiosk holt nur den gültigen Code
- **Stations-PIN.** `members.pin_hash` (nur Hash). Mitglieder setzen die PIN im Profil
  (`change_pin`), Verwalter im Mitglieds-Modal. Regeln: 4–8 Ziffern, keine Einheitsziffern,
  keine Zahlenfolge. Sperre nach 5 Fehlversuchen je Mitgliedsnummer (auch unbekannte) und
  30 je Station, jeweils 15 Minuten; eine neue PIN hebt die Mitgliedssperre auf.
  Einstellungen `station_pin_enabled`, `station_pin_min_length`
- Neue Quellen `station_pin` (Anwesenheit) und `station` (Arbeitszeit), in der Oberfläche als
  „Station (PIN)" gekennzeichnet. Der Kiosk-Name gilt als Ortsnachweis an Start und Ende
- `GET users&user_type=device&device_type=` filtert Geräte; `is_active` wird beim Anlegen
  eines Geräts berücksichtigt
- Selbstauskunft (`my_data`) nennt `has_pin` und `pin_updated_at`, auch im CSV
- Testsuiten `station_unit` (ohne Datenbank, u. a. Sperrlogik gegen SQLite) und `station_api`

### Geändert
- Ein Geräte-Token vom Typ Kiosk darf ausschließlich `station` (und `version`) aufrufen
- **Eine vom Token erzeugte PHP-Session ist ohne den Token nicht mehr nutzbar (401).**
  Der Token-Zweig legte bislang eine vollwertige Session an, deren Cookie allein genügte
- Beim Wechsel des Gerätetyps wird das gespeicherte TOTP-Secret verworfen; `auth_device`
  und `kiosk` speichern nie ein Secret aus dem Request; ein Secret muss Base32 (16–64
  Zeichen) sein; eine `totp_location` kann ihr Secret nicht löschen
- Die Notizpflicht der Zeiterfassung gilt am Kiosk nicht (keine Tastatur für Fließtext)
- `auto_checkin`: Terminsuche und Eintrag in `findCheckinAppointment()` und
  `writeCheckinRecord()` herausgelöst. Ein bestehender Eintrag mit Status `excused` wird
  durch einen Stempel zu `present`; `record_id`, `member_id`, `appointment_id` sind
  JSON-Zahlen; `arrival_time` wird normalisiert gespeichert
- `member_number` wird beim Speichern getrimmt; „0" ist eine gültige Nummer
- Migration 1.2.5 → 1.3.0 meldet doppelte Mitgliedsnummern als Warnung, sichert alle
  Spaltenänderungen ab und erzeugt die View `v_users_extended` neu

### Behoben
- **`GET member_groups&id=` lieferte `m.*` ohne Rollenprüfung** und damit seit der
  Migration auch den PIN-Hash an jede angemeldete Rolle. Jetzt feste Spaltenlisten je Rolle
- `login()` löscht `auth_type` aus einer wiederverwendeten Session
- Geräte-Modal: `toggleDeviceTypeFields()` setzte `required` am Secret-Feld nicht zurück

---

## [1.2.5] – 2026-09-04

### Geändert
- **Die DSGVO-Bereinigung kennt drei Löschfristen statt einer.** Getrennt einstellbar für
  Anwesenheiten und Ausnahmen (`cleanup_years_records`), für Arbeitszeiten samt ihrer
  Änderungshistorie (`cleanup_years_worktime`) und für verwaiste Einträge der
  Änderungshistorie (`cleanup_years_audit`). Alle Schritte laufen in einer Transaktion
- Der Schlüssel `dsgvo-cleanup-years` heißt jetzt `cleanup_years_records` — die Migration
  übernimmt den eingestellten Wert

### Neu
- **`cleanup` löscht Arbeitszeiten und ihre Änderungshistorie.** Bis 1.2.4 kannte der
  Endpunkt nur `records` und `exceptions`; die Frist aus `DATENSCHUTZ.md` 10.4 musste jeder
  Betreiber selbst per SQL durchsetzen
- **Verwaiste Einträge der Änderungshistorie werden anonymisiert, nicht gelöscht.** `changes`
  und `changed_by` fallen weg, der Eintrag bleibt mit Zeitpunkt und Art der Änderung stehen.
  Die Spur belegt weiter, dass etwas geschah — ohne Personenbezug
- `cleanup` ist erstmals in `API.md` dokumentiert
- Testsuiten `cleanup_unit` und `cleanup_api` sowie `tests/db/verify_cleanup_retention.php`

### Behoben
- **`cleanup` nahm jede Frist an, auch `0`.** Der Stichtag war dann der heutige Tag, und der
  Aufruf löschte den gesamten Bestand an Anwesenheiten und Ausnahmen. Die Untergrenze stand
  nur als `min="1"` im Formular und wirkte bei einem direkten API-Aufruf nicht. Jede Frist
  muss jetzt eine ganze Zahl ab 1 sein, geprüft vor dem ersten `DELETE`
- Laufende Sitzungen (`end_time IS NULL`) fallen nicht mehr in die Löschfrist. Eine seit
  Jahren offene Sitzung ist ein Fehlerfall, kein Löschfall
- **Der Update-Wizard zeigte in Schritt 3 eine leere Seite.** Die Schleife über die
  Migrationskette lief mit `foreach ($chain as $step)` und überschrieb damit die Nummer des
  Wizard-Schritts. Danach traf keine der Bedingungen `$step == 1|2|3` mehr, und weder
  Protokoll noch Fehlermeldung wurden gerendert — obwohl die Migration sauber durchlief.
  Da der Wizard sich im selben Durchgang per `.htaccess` aussperrt, war das Ergebnis
  anschließend auch nicht mehr erreichbar. Der Fehler betraf jedes Update mit mindestens
  einem Migrationsschritt
- Der Wizard überschrieb beim Sperren die Anleitung zum Wiederöffnen in
  `public/update/.htaccess`. Nach dem ersten Update stand nirgends mehr, wie man den
  Assistenten für das nächste erreichbar macht
- **Die Zugriffssperren von Installer und Update-Assistent nutzten reine Apache-2.2-Syntax**
  (`Order Deny,Allow` / `Deny from all`). Auf einem Apache 2.4 ohne `mod_access_compat`
  beantwortet der Server das mit HTTP 500 statt 403 — gesperrt bleibt das Verzeichnis, es
  sieht aber nach einem Defekt aus. Beide Dateien und beide erzeugenden Skripte tragen jetzt
  beide Syntaxen, je in einem `<IfModule>`-Wächter
- **Der Installer wurde mit der Sperre des `abgeschlossenen` Zustands ausgeliefert.** Die
  Datei im Repository trug den Text, den der Installer nach seinem Lauf schreibt — wer klonte
  oder das Paket lud, bekam an Schritt 4 der Anleitung ein `403 Forbidden` und eine Datei,
  die ihm sagte, er sei fertig. Die Sperre bleibt (ein hochgeladener, noch nicht eingerichteter
  Webspace soll den Installer nicht offen zeigen); der Text benennt jetzt den
  Auslieferungszustand, und die README ergänzt den Freischaltschritt — wortgleich zum
  Update-Ablauf
- Die `.gitignore` enthielt seit jeher eine wirkungslose Regel `install/.htaccess`: falscher
  Pfad (nicht `public/install/`) und die Datei ist getrackt, wo `.gitignore` ohnehin nicht
  greift. Entfernt, damit niemand annimmt, hier wirke etwas

### Warum
Die Änderungshistorie überlebt das Löschen einer Arbeitszeit absichtlich — sonst wäre
ausgerechnet die Löschung nicht dokumentiert. Sie enthält damit personenbezogene Daten, die
den eigentlichen Datensatz überdauern, und braucht eine eigene Frist. Sie am Ende ganz zu
löschen nähme ihr allerdings den Zweck; deshalb die Anonymisierung.

Die Bereinigung läuft weiterhin nicht von selbst. Ein unbeaufsichtigt löschender Job ohne
Sicherung wäre die schlechtere Variante — und einen Scheduler hat das Projekt bewusst nicht.

---

## [1.2.4] – 2026-09-03

### Geändert
- **Die automatische Terminerzeugung beim Check-in ist abschaltbar.** Findet ein Check-in
  keinen passenden Termin, entscheidet `checkin_auto_create_appointment`, ob einer angelegt
  oder der Check-in mit einem Hinweis abgelehnt wird. Die Einstellung gilt für alle
  Check-in-Wege — PWA, IoT-Station und API
- **Bestandsinstallationen starten auf „an", Neuinstallationen auf „aus".** Ein Update ändert
  damit nichts am laufenden Betrieb; wer neu anfängt, entscheidet bewusst
- **Das Zeitfenster der Terminzuordnung steht in den Einstellungen**
  (`checkin_tolerance_hours`) statt in `config.php`. Die Migration übernimmt den bisherigen
  Wert der Konstante `AUTO_CHECKIN_TOLERANCE_HOURS`; die Konstante bleibt als Rückfall
  bestehen. Der Wert gilt jetzt auch für die Dublettenprüfung beim Anlegen von Terminen und
  für die Terminauswahl in der PWA, wo bis 1.2.3 eine abweichende Zahl stand

### Neu
- **Terminauswahl beim Check-in in der PWA.** Ein optionales Feld über dem Scan-Knopf bietet
  die Termine des Tages an. Ohne Auswahl sucht der Server wie bisher
- **Automatisch erzeugte Termine sind markiert** (`appointments.is_auto_created`), tragen in
  der Terminverwaltung ein Badge und lassen sich dort filtern. Die Migration markiert den
  Altbestand und nennt seine Anzahl im Protokoll

### Behoben
- Ein vom Client geschickter Termin wird serverseitig gegen Tag, Gruppenzugehörigkeit **und
  Zeitfenster** geprüft. Ohne diese Prüfungen ließe sich über den Check-in-Endpunkt rückwirkend
  Anwesenheit behaupten — die Tagesgrenze allein erlaubte einen Check-in zu jeder Uhrzeit
  desselben Tages, ein zeitlich weit entfernter Termin wurde ebenso angenommen wie ein naher

### Warum
Bis 1.2.3 legte ein Check-in ohne passenden Termin unbemerkt einen neuen an — Standard-
Terminart, Zeit auf fünf Minuten gerundet. `statistics.php` zählt jeden Termin einer Terminart
bei jedem aktiven Mitglied der verknüpften Gruppen als Solltermin; ein Fehlscan senkte damit
die Quote aller anderen. Strukturell derselbe Fall, der in 1.2.3 zur Regel „kein Weg der
Zeiterfassung erzeugt Anwesenheit" geführt hat.

**Automatisch erzeugte Termine zählen weiterhin voll in die Anwesenheitsquote.** Wer die
Automatik eingeschaltet lässt, sollte den neuen Filter regelmäßig durchsehen — siehe OI-20 in
`docs/OPEN-ITEMS.md`.

---

## [1.2.3] – 2026-09-03

### Geändert
- **Der Start der Zeiterfassung erzeugt keinen Anwesenheitseintrag mehr.** Bisher legte ein
  Timer-Start mit Terminbezug einen Check-in an, sofern der Termin am selben Tag lag.
  Damit gilt jetzt für alle drei Erfassungswege derselbe Satz: Kein Weg der Zeiterfassung
  erzeugt Anwesenheit. Wer beides festhalten will, tut beides — in der PWA liegen die Wege
  nebeneinander
- **Die PWA bietet alle Termine des Jahres an**, nicht mehr nur die des heutigen Tages.
  Vorbereitung findet vor der Veranstaltung statt, Nachbereitung danach; genau diese Stunden
  zeigt der Bericht „nach Termin", und genau sie ließen sich bisher nicht zuordnen
- Terminlisten sind nach zeitlicher Nähe sortiert — in der PWA zu heute, im Nachtrag-Dialog
  zum eingetragenen Datum

### Neu
- **Tätigkeitsarten lassen sich mit Terminarten verknüpfen.** „Bühnenaufbau" bietet dann nur
  noch Konzerte an, nicht jede Probe des Jahres. Ohne Verknüpfung stehen weiterhin alle
  Termine zur Wahl — die Migration legt deshalb keine einzige Zuordnung an, und wer die
  Eingrenzung nicht nutzt, merkt nicht, dass es sie gibt

### Warum
Die Anwesenheitsauswertung liest `records` ohne Rücksicht auf `checkin_source`. Ein vom Timer
erzeugter Eintrag zählte damit voll in die Anwesenheitsquote und — wegen `arrival_time = NOW()`
— auch in die Pünktlichkeit. Wer um 08:00 die Bühne für das Konzert um 19:00 aufbaute, galt
als anwesend und als elf Stunden zu früh. Der Datumsschutz sollte das verhindern, wehrte aber
nur den Vortag ab und ließ den Regelfall durch: Am Veranstaltungstag wird gearbeitet.

### Hinweis für bestehende Installationen
Anwesenheitseinträge, die vor dem Update aus der Zeiterfassung entstanden sind
(`checkin_source = 'timer'`), **bleiben erhalten** und zählen weiterhin in Anwesenheit und
Pünktlichkeit. Ein Teil davon ist korrekt — das Mitglied war tatsächlich da —, und welcher,
lässt sich nachträglich nicht entscheiden. Das Update-Protokoll nennt ihre Anzahl.

---

## [1.2.2] – 2026-09-03

### Neu
- **Zeitraum für Arbeitszeitberichte**: Alle drei Auswertungen nehmen `from` und `to` als
  Datum entgegen, nicht mehr nur ein Kalenderjahr. Damit sind Monats-, Quartals- und
  Förderzeiträume möglich; das Vereinsjahr von September bis Juni ebenso. `?year=` bleibt
  gültig und liefert unverändert das bisherige Ergebnis
- **Druckansicht**: `&format=html` liefert die Berichte als druckbare Seite mit Vereinslogo,
  Zeitraum, Summen und einer Fußnote, die Zuordnungsregel und Nachweisgrade erklärt. Das PDF
  entsteht über den Druckdialog des Browsers — ohne zusätzliche Bibliothek
- **Berichtsdialog** in der Zeiterfassung mit Schnellwahl für „Dieser Monat", „Letzter Monat"
  und „Laufendes Jahr". Er ersetzt die beiden bisherigen Export-Knöpfe und macht die Auswertung
  nach Termin erstmals über die Oberfläche erreichbar
- `public/css/print.css` — das Projekt hatte bisher kein Print-Stylesheet
- **Terminfeld beim Nachtragen von Zeiten** im Dashboard. Damit füllt sich die Spalte „Termin"
  auch für Einträge, die nicht über die PWA entstanden sind — und der Bericht „nach Termin"
  beantwortet erstmals vollständig, was eine Veranstaltung an Arbeit gekostet hat.
  Die Zuordnung erzeugt **keinen** Anwesenheitseintrag: Arbeit für einen Termin ist keine
  Anwesenheit bei ihm, und ein Nachtrag ist bis zur Freigabe eine ungeprüfte Behauptung. Den
  Check-in erzeugt weiterhin allein der Timer-Start

### Geändert
- Dateinamen der Exporte tragen den Zeitraum: `stundennachweis_2026-01.csv` statt
  `stundennachweis_2026.csv`. Ohne das ist ein gespeichertes Monats-CSV von einem Jahres-CSV
  nicht zu unterscheiden
- Die Summenzeile der CSV nennt den Zeitraum, über den sie gebildet wurde
- Der Zeitraumvergleich nutzt `>=` und `< Ende + 1 Tag` statt `YEAR(start_time)`. Das ist
  indextauglich und verliert keine Sitzung mehr, die am letzten Tag nach Mitternacht beginnt

### Behoben
- Die Korrektur einer Arbeitszeitsitzung verarbeitete `appointment_id` nicht. Ein über die API
  mitgesendeter Terminbezug wurde beim Bearbeiten stillschweigend verworfen; ein einmal
  gesetzter Termin ließ sich weder ändern noch entfernen

### Sicherheit
- Jeder Wert der Druckansicht wird maskiert. Notizen und Ortsnamen stammen aus der PWA, also
  aus Rollen unterhalb von `admin`; ohne Maskierung wäre der Bericht ein gespeichertes XSS in
  genau der Ansicht, die ein Administrator zum Prüfen öffnet. Die Berichtsseite enthält
  keinerlei JavaScript

### Hinweis
- Sitzungen zählen zu dem Zeitraum, in dem sie **begonnen** haben. Eine Sitzung über
  Mitternacht erscheint vollständig im Monat ihres Beginns; die Summe über zwölf Monate
  entspricht deshalb der Jahressumme. Die Regel steht auf jedem Bericht

---

## [1.2.1] – 2026-09-02

### Neu
- **Gruppenbindung der Tätigkeitsarten**: Eine Tätigkeitsart lässt sich Mitgliedergruppen
  zuordnen (`activity_type_groups`), analog zu den Terminarten. Nur Mitglieder dieser Gruppen
  sehen und erfassen sie — geprüft wird auch serverseitig beim Start und beim Selbst-Nachtrag,
  nicht nur in der Anzeige
- **Erfassen-Tab in der PWA**: Check-in und Zeiterfassung sind ein Tab. Stehen beide Absichten
  offen, erscheint zuerst eine Auswahl; wer nur eine hat, landet direkt beim Werkzeug
- **Laufende Sitzung über allen Tabs sichtbar**: Eine schmale Leiste zeigt Tätigkeit und
  laufende Zeit und führt auf Tippen zur Sitzung zurück
- **Zusammengeführter Verlauf**: Anwesenheiten, Anträge und Arbeitszeiten in einer Zeitachse

### Geändert
- Tätigkeitsarten sind nur noch für Mitglieder der zugeordneten Gruppen erfassbar. Die Migration
  ordnet den Bestand **allen** Gruppen zu — es ändert sich also nichts, bis ein Administrator die
  Zuordnung pflegt
- Die Liste „Erfasste Zeiten" in der Zeiterfassung ist entfallen; der Verlauf führt sie mit
- Fehlermeldungen der PWA benennen die Ursache, statt sie durch einen Statustext zu ersetzen
- Antrag und Arbeitszeit verwenden im Verlauf denselben Wortlaut für die Freigabe

### Behoben
- Ein Scan für den Check-in konnte versehentlich eine Arbeitszeitsitzung starten: Die Umleitung
  des nächsten Codes war unsichtbar, ohne Zeitlimit und überlebte den Tabwechsel. Der Zweck
  eines Scans folgt jetzt aus der sichtbaren Ansicht
- Der Start einer nachweispflichtigen Tätigkeit führte in eine Sackgasse — kein Abbruch, keine
  manuelle Eingabe
- Die Terminauswahl der Zeiterfassung blieb leer, wenn zuvor nicht der Antragsdialog geöffnet
  worden war; das Tagesdatum wurde zudem aus UTC gebildet und sprang abends auf den Folgetag
- Anwesenheitseinträge aus dem Timer zeigten im Dashboard keine Quelle
- Bei abgeschalteter Zeiterfassung meldete das Dashboard bei jedem Neuladen einen Fehler
- `id="scannerContainer"` existierte zweimal in der PWA

---

## [1.2.0] – 2026-09-01

### Neu
- **Zeiterfassung** für ehrenamtliche Arbeit (standardmäßig deaktiviert, siehe Einstellung `worktime_enabled`)
  - Live-Timer in der PWA mit Start, Pause und Stopp
  - Nachträgliche Erfassung über ein Formular, mit Freigabe durch Manager
  - Tätigkeitsarten als Stammdaten (`activity_types`)
  - Optionaler Terminbezug: Der Timer-Start erzeugt den Anwesenheits-Eintrag mit
  - Auditspur aller Änderungen (`work_session_log`)
- **Migrationskette**: Der Update-Wizard führt beliebig viele aufeinanderfolgende
  Migrationen aus, statt fest verdrahtet genau eine. Neue Schritte werden in
  `private/migrations/manifest.php` deklariert
- **Schema-Versionierung bei Neuinstallation**: Der Installer stempelt die Version
  aus `version.json`, sodass der Update-Wizard den Ausgangsstand kennt
- **Testharness** unter `tests/` – abhängigkeitsfrei, Aufruf über `php tests/run.php`

### Geändert
- `records.checkin_source` kennt zusätzlich den Wert `timer`

### Behoben
- Der Update-Wizard bestimmte die installierte Version über `ORDER BY applied_at`.
  Bei mehreren Einträgen in derselben Sekunde war das Ergebnis zufällig, und
  `1.10.0` hätte als kleiner als `1.9.0` gegolten. Jetzt entscheidet `version_compare`

---

## [1.1.3] – 2026-04-16

### Neu
- **Update-Wizard** (`/update`): Schritt-für-Schritt Datenbank-Migration ohne Datenverlust
  - Automatische Erkennung der installierten DB-Version
  - Tabellen-Prefix-Migration (v1.0.0 → v1.1.x)
  - `config.php` wird automatisch um Prefix-Feld und `table()`-Methode ergänzt
  - Migrationsprotokoll mit Warnhinweisen
  - Wizard sperrt sich nach erfolgter Migration automatisch
- **Schema-Versionierung**: Tabelle `schema_version` für künftiges Versions-Tracking
- **Import-Protokollierung**: Neue Tabelle `import_logs`
- **Terminarten-Zuordnung**: Neue Spalte `appointments.type_id`
- **Performance**: Zusätzliche Indizes auf `records`, `appointments`, `member_group_assignments`
- **Dropdown-Querfilterung**: Mitglieder- und Termin-Dropdowns in Record- und Ausnahmen-Modal filtern sich gegenseitig
- **Mitglieder-Aktivitätsstatus**: Inaktive Mitglieder werden in Listen hervorgehoben, Toggle zum Ein-/Ausblenden
- **Rollenbasierte Filter**: Statistik- und Terminart-Filter für normale Benutzer auf eigene Gruppen eingeschränkt

### Geändert
- `system_settings`: ENUM-Wert `appearance` → `public` für Einstellungskategorien
- Neue Einstellung `privacy_policy_url` in system_settings
- Mitglieder-Modal: `is_active`-Checkbox durch schreibgeschütztes Status-Badge ersetzt
- Statistik-Filterung nach Mitgliedschaftszeitraum (jahresbasiert) statt `active`-Flag

### Fixes
- **Sicherheit**: Direktzugriff auf `private/`-Verzeichnis über HTTP gesperrt (BUG-1)
- **Authentifizierung**: `Authorization`-Header wird korrekt durch Apache weitergeleitet (BUG-4)
- **Authentifizierung**: Session-Shadowing bei Token-Authentifizierung verhindert (BUG-4)
- **Authentifizierung**: Rate Limiting für Token- und Session-Login vereinheitlicht (BUG-5, BUG-6)
- **Authentifizierung**: Atomare Rate-Limiter-Transaktionen (BUG-5, BUG-6)
- **Login**: HTTP 401/429 statt 200 bei fehlgeschlagenem Login (BUG-7)
- **Login**: Expliziter Fehlercode statt String-Matching (BUG-7)
- **Mitglieder**: Eingabevalidierung ergänzt (BUG-2)
- **Mitglieder**: PUT verwendet PATCH-Semantik (BUG-2)
- **Mitglieder**: DELETE in Transaktion mit Deadlock-Behandlung (BUG-3, BUG-9)
- **Mitglieder**: Aktiv-Voraussetzung für DELETE entfernt (BUG-3, BUG-9)
- **Ausnahmen**: Eingabevalidierung ergänzt
- **Dropdowns**: Mitglied-/Termin-Dropdowns filtern nach aktiven Mitgliedschaftszeiträumen
- **Statistik**: Mitglieder-Dropdown filtert nach aktivem Zeitraum im gewählten Jahr
- **TOTP**: Geräte-Generierung und Datenbank-Initialisierung für Einstellungen korrigiert
- **version.php**: Pfad zu `version.json` auf `__DIR__`-basiert umgestellt

---

## [1.0.0] – 2026-01-23

### Erstveröffentlichung

- Mehrstufiges Rollensystem (Admin, Manager, Benutzer, Gerät)
- Session-basierte Authentifizierung (Web) und Bearer-Token (API/PWA)
- Terminverwaltung mit Terminarten und Gruppenzuordnung
- Anwesenheitserfassung (Web, QR-Code, TOTP-Station)
- Ausnahmenverwaltung (Entschuldigungen, Zeitkorrekturen)
- Gruppenverwaltung mit M:N-Zuordnung
- Mitgliederverwaltung mit CSV-Import
- Statistikauswertung nach Termin, Mitglied und Gruppe
- Progressive Web App (PWA) für mobilen Check-in
- TOTP-basierte Standortverifikation für Geräte
- Installations-Wizard (`/install`)
- Duales Lizenzmodell (AGPL-3.0 / Kommerziell)
