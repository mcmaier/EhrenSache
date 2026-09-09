# Projekthistorie — Entscheidungen und Wendepunkte

Warum EhrenSache so gebaut ist, wie es gebaut ist. Diese Datei hält die Begründungen fest,
die sonst nur in den Chatverläufen des Claude-Projekts standen (Nov 2025 – Apr 2026, 34
Unterhaltungen) und im Repository nirgends auftauchen: getroffene Grundsatzentscheidungen,
ihre Gründe und die Wege, die bewusst verworfen wurden.

**Sie ist keine zweite Feature-Liste.** Was wann fertig wurde, steht in `CHANGELOG.md`; was
offen ist, in `docs/OPEN-ITEMS.md`; wie das System heute aussieht, in `CLAUDE.md` und
`API.md`. Diese Datei erklärt das *Warum* und ist damit stabil — sie wird nur ergänzt, wenn
eine Grundsatzentscheidung fällt oder eine alte gekippt wird.

**Wenn Historie und heutiger Stand auseinandergehen, gilt der Code.** Wo eine Entscheidung
später korrigiert wurde, steht das unter „heute" direkt dabei.

**Stand:** 2026-09-09 · **Quelle:** verdichtete Projektchats bis 2026-04-15; ab da liegt die
Historie in `git log`, `CHANGELOG.md` und `docs/`

---

## Auf einen Blick

| Phase | Zeitraum | Wendepunkt |
|---|---|---|
| Datenmodell zuerst | 11/2025 | Anwesenheit als eigene Entität, nur Ankunftszeit |
| Plattformwechsel | 11/2025 | C#/ASP.NET Core verworfen → PHP/MySQL auf Shared Hosting |
| Erfassungswege | 11–12/2025 | PWA statt nativer App; TOTP-Station statt statischer NFC-Tags |
| Gruppenlogik | 12/2025 | Sichtbarkeit über Terminart → Gruppe, serverseitig |
| Auswertung | 12/2025 | Statistik rechnet die API, das Frontend zeigt nur an |
| Performance | 12/2025 | Jahresbezogener Cache + clientseitige Filterung, Pagination |
| Härtung | 12/2025–01/2026 | Security-Review, Rate Limiting, CSRF, Session-Flags |
| Betrieb | 01–02/2026 | SemVer + `version.json`, Tabellenpräfix, Installer |
| Produkt | 02/2026 | Umbenennung in EhrenSache, Dual-Lizenz AGPL + kommerziell |
| Werkzeugwechsel | 04/2026 | Entwicklung wandert nach Claude Code, `CLAUDE.md` entsteht |

---

## 1 · Das Datenmodell stand vor der Technologie (11/2025)

Am Anfang stand nicht die Plattform, sondern die Frage, was ein Anwesenheitsdatensatz ist.

- **Anwesenheit ist eine eigene Entität**, keine Eigenschaft eines Mitglieds oder Termins —
  jede Erfassung ist ein eigener Datensatz mit Zeitstempel.
- **Nur die Ankunft wird erfasst.** `records` hat `arrival_time`, keine Abgangszeit;
  `appointments` trennt `date` und `start_time` und hat bewusst kein `end_time`. Wer
  Pünktlichkeit auswerten will, braucht die Ankunft — nicht die Anwesenheitsdauer.
  *(heute: Dauer wird dort, wo sie gebraucht wird, getrennt in `work_sessions` erfasst — eigene
  Tabelle seit 1.2.0; `records` blieb unberührt.)*
- **Spontane Erfassung schlägt Terminpflicht.** Trifft ein Stempel ein und es gibt keinen
  passenden Termin, wird einer angelegt statt die Erfassung abzuweisen. Begründung: bei
  kurzfristigen Änderungen ist es einfacher, Termine im Nachgang zu korrigieren, als vorher
  alles zu pflegen. Daraus wurde das **Toleranzfenster** (Vorgabe ±2 h), das für Terminanlage,
  Auto-Check-in, Records-Import und Station dieselbe Quelle verwendet. *(heute: keine Konstante
  mehr, sondern die Einstellung `checkin_tolerance_hours`, gelesen über `checkinToleranceHours()`
  in `private/helpers/utils.php`, zulässig 0–8 h; `AUTO_CHECKIN_TOLERANCE_HOURS` ist nur noch
  Rückfall für nicht migrierte Installationen.)*
- **Verworfen:** die strikte Variante („kein Termin → HTTP 400"); Enum-Abwesenheitsgründe im
  Record — Entschuldigungen und Zeitkorrekturen laufen stattdessen über `exceptions`, eine
  eigene `requests`-Tabelle wurde bewusst nicht angelegt.

## 2 · Der Plattformwechsel: C#/.NET → LAMP (11/2025)

Der erste Entwurf war C# / ASP.NET Core mit EF Core, SQLite und Swagger. Er scheiterte an
einer einzigen Randbedingung: **Zielumgebung ist der Webspace eines Vereins.** Shared Hosting
kann PHP, kein .NET Core, kein Composer, keine Hintergrunddienste, keine Schreibrechte auf
Programmdateien, kein Dienst-Neustart.

Daraus folgt fast alles Weitere:

- **PHP 8 + MySQL/MariaDB, Vanilla, ohne Build-Step und ohne Framework.**
- **Routing über Query-Parameter** (`api.php?resource=…`) statt mod_rewrite — `.htaccess`-
  Rewriting lief auf der Zielumgebung nicht zuverlässig (404).
- **Session-Auth im Browser**, weil auf jedem Webspace ohne Zusatz-Extension verfügbar.
- **Token als Query-Parameter bzw. später auch per Header**, weil Apache den
  `Authorization`-Header verschluckte. *(heute: beide Wege werden unterstützt — der
  durchgereichte Header hat 2026-09 einen schlafenden Export-Fehler geweckt, siehe OI-24.)*
- **CSRF-Token im Body bzw. als Query-Parameter**, aus demselben Header-Grund.
- **Konfiguration in der Datenbank statt in Dateien**, wo möglich; der Installer schreibt nur
  `private/config/config.php` und legt den Admin an.
- **TOTP in Vanilla PHP**, keine Composer-Library — HMAC-SHA1 ist eingebaut.

Der C#-Teil verschwand nicht ganz: Ein WinForms-Desktop-Client wurde als reiner API-Konsument
mit Schichtenmodell entworfen (Models/DTO → ApiClient → Services → Forms). Er hat nie eigenen
Datenbankzugriff bekommen — die API blieb die einzige Schnittstelle. *(heute: nicht Teil des
Repositories.)*

## 3 · Erfassungswege: von QR über PWA zur Station (11/2025 – 09/2026)

- **PWA statt nativer App**: läuft auf allen Mobilgeräten, installierbar, kein App-Store,
  Werkzeuge sind Texteditor und Webserver.
- **Alles unter einer Domain** — Dashboard im Root, API unter `/api`, PWA unter `/checkin`;
  damit entfällt CORS. Später kam `/station` dazu.
- **PWA und Dashboard bleiben getrennte Auth-Welten**: Token hier, Session + CSRF dort. Eine
  Token-Übergabe beim Wechsel zwischen beiden wurde als konzeptionell falsch verworfen.
- **Ortsnachweis per TOTP (RFC 6238)** statt eines eigenen Verfahrens: Ein Gerät vor Ort zeigt
  einen rotierenden Code, die API prüft ihn gegen das serverseitig hinterlegte Secret. Damit
  kann niemand von zu Hause einchecken. **Statische NFC-Tags wurden dafür verworfen** — sie
  lassen sich kopieren und tragen keine Zeit.
- **Zweiter, ehrlicher Weg daneben:** Wer den Code nicht scannen kann, stellt einen Antrag
  über `exceptions`, den ein Verwalter genehmigt. Es gibt bewusst keinen ungeprüften
  Selbst-Check-in.
- **Die Erfassungsmethode ist Sache des Geräts, nicht der API.** Die Quellen wurden früh
  methodenneutral benannt (`admin`, `user_totp`, `device_auth`) und die
  Gerätetypen radikal auf zwei reduziert: `totp_location` (Ortsnachweis) und `auth_device`
  (Identifikation des Mitglieds). Eine feingliedrige Liste (biometric/rfid/nfc/keypad) wurde
  ausdrücklich abgelehnt — auch aus Datenschutzgründen: **biometrische Daten bleiben auf dem
  Endgerät, die API sieht nur die Mitgliedsnummer.** *(heute: dritter Gerätetyp `kiosk` seit
  1.3.0; als Quellen kamen `auto_checkin`, `import`, `timer` und `station_pin` dazu —
  methodenneutral geblieben ist das Prinzip.)*
- **Die Station als Gerät** entstand als ESP32 mit PN532 und DS3231-RTC: Codeanzeige per
  Display/QR/NFC-Emulation, Zeit intern durchgängig UTC, nur die Anzeige lokalisiert. Eigenes
  Projekt, eigene Weboberfläche (AP + Captive Portal, Konfiguration im NVS, nicht-blockierende
  State Machine, damit das Gerät auch ohne WLAN arbeitet). *(heute: dieselbe Rolle erfüllt
  zusätzlich die virtuelle Station — ein Tablet mit PIN-Eingabe, ohne eigene Hardware.)*

## 4 · Rollen, Gruppen und Sichtbarkeit (12/2025 – 01/2026)

- **Sichtbarkeit läuft über die Terminart, nicht über den Termin.** Kette:
  `appointments.type_id → appointment_type_groups → member_group_assignments`. Ein Termin
  trägt keine Gruppe.
- **Fail closed:** Wer keiner Gruppe oder keinem Mitglied zugeordnet ist, sieht eine leere
  Liste — nicht alles.
- **Filterlogik gehört ins Backend.** Frontend zeigt höchstens den Hinweis, dass gefiltert
  wird. Dasselbe Prinzip später bei inaktiven Mitgliedern: Filterung im SQL, nicht im JS.
- **Genau eine Standard-Terminart und genau eine Standardgruppe**, jeweils automatisch
  exklusiv. Die Standard-Terminart muss die Gruppe „Alle Mitglieder" enthalten, weil die
  automatische Erfassung sie verwendet.
- **Die Rolle `manager` wurde eingeführt**, weil „administrativ aus Organisationssicht" etwas
  anderes ist als „administrativ aus Systemsicht": Mitglieder, Termine, Anwesenheiten und
  Anträge ja — Benutzerverwaltung und Systemeinstellungen nein.
- **Statistik-Gruppen brauchten keine neue Struktur.** Register/Abteilungen werden über die
  vorhandenen `member_groups` an der Standard-Terminart abgebildet; gegen die dabei
  entstehende Doppelzählung wird zusätzlich nach `type_id` ausgewertet. Ein eigener
  Gruppen-Unterbau (Tabellen, API, UI) wurde verworfen.

## 5 · Auswertung: die API rechnet, das Frontend zeigt (12/2025)

- **Der Gesamtdurchschnitt wird pro Gruppe gebildet** (`groupAppointments × groupMembers`) und
  aufaddiert — nie global, weil jede Gruppe unterschiedlich viele Termine und Mitglieder hat.
- **Mitglieder werden in der Datenbank gezählt** (`COUNT(DISTINCT member_id)`), sonst zählt
  ein Mitglied in mehreren Gruppen mehrfach.
- **Nur vergangene Termine zählen**, sonst verdirbt das Restjahr die Quote. *(im Code
  `a.date <= DATE_ADD(CURDATE(), INTERVAL 2 HOUR)` in `statistics.php` — wirkungsgleich zu
  `date <= CURDATE()`, die zwei Stunden sind dort hart codiert.)*
- **Kein Record = unentschuldigtes Fehlen**; genehmigte Entschuldigungen erzeugen einen Record
  mit Status `excused`, sonst tauchen sie in der Auswertung gar nicht auf.
- **Verworfen:** Berechnung im Frontend aus mehreren parallelen Abfragen („zu umständlich"),
  ebenso das Zählen von Terminen und Mitgliedern aus den Statistikzeilen.
- **Import/Export verzichten auf die interne `member_id`.** Gematcht wird über
  `member_number`, ersatzweise Nachname + Vorname; bei Namensgleichheit ohne Nummer bricht der
  Import ab. Die DB-ID ist Interna. *(heute: Termine tragen zusätzlich einen stabilen
  Schlüssel aus Datum, Startzeit und Terminart, siehe OI-24.)*

## 6 · Performance: Caching und Pagination (12/2025)

Auslöser waren ~30 API-Calls pro Menüwechsel und ~10 s blockierendes Rendering bei realistischen
Datenmengen (50 Mitglieder × 40 Termine ≈ 2000 Records).

- **Muster „Load → Filter → Render"** für jede Section: `loadXxx()` holt (Cache oder API) und
  rendert nicht, `filterXxx()` filtert clientseitig, `renderXxx()` zeigt, `applyXxxFilters()`
  orchestriert.
- **Ein API-Call pro Jahr**, danach clientseitig filtern — deshalb ist der Cache jahresbezogen.
- **TTL plus explizite Invalidierung** nach eigenen Mutationen. *(heute: 10 Minuten,
  In-Memory in `ui.js`; `localStorage` wird für den Datencache bewusst nicht verwendet — PWA
  und Station legen dort nur ihren API-Token ab.)*
- **Die Statistik wird bewusst nicht gecacht** — dort steckt die Rechenarbeit, und veraltete
  Zahlen wären schlimmer als ein Request. Das Funktionsschema behält sie trotzdem.
- **Pagination statt vollständigem Rendering**, Statistiken aber weiterhin über *alle*
  gefilterten Records, nicht über die Seite. *(heute: 25 Einträge/Seite als Vorgabe, über die
  Einstellung `pagination_limit` änderbar.)*
- **Keine externen Icon- oder CSS-Abhängigkeiten** — Unicode-Zeichen statt FontAwesome.
- **Verworfen:** Bulk-Endpoint `/api/dashboard-data`, Backend-Pagination, Lazy-Rendering.

## 7 · Sicherheit als eigener Arbeitsstrang (12/2025 – 01/2026)

Ein Code-Review Ende 12/2025 lieferte die Prioritätenliste, die den Jahreswechsel prägte:
Session-Flags (`HttpOnly`, `SameSite`, `Secure`), XSS-Schutz, Eingabevalidierung mit
Längenlimits, sparsame Fehlermeldungen, CSRF auch beim Import.

- **Ein einziger Rate Limiter** für API, Login und Mailversand — mit DB-Persistenz,
  Session-Fallback, gehashten Identifiern und **fail open** bei DB-Fehlern. Zwei getrennte
  Klassen wurden verworfen. *(heute: 150 Requests/Minute pro IP+User für die API, 5
  Login-Versuche je 15 Minuten.)*
- **CSRF nur für Session-Mutationen**, nicht für Token-Auth — IoT-Geräte haben keine Session.
- **Secrets verlassen den Server nicht:** `totp_secret`, `api_token` und später `pin_hash`
  werden nie in Listen ausgeliefert; ein Token wird maskiert angezeigt und pro Datensatz neu
  geladen.
- **IP-Blocking und CAPTCHA wurden verworfen** — auf Shared Hosting unzuverlässig (geteilte
  IPs, kein Zugriff auf die Serverebene).
- **Uploads bleiben öffentlich erreichbar, aber entschärft**: PHP-Ausführung, unerwünschte
  Dateitypen und Directory-Listing per `.htaccess` gesperrt, zufällige Dateinamen, MIME- und
  Endungsprüfung. Ein Zugriffsschutz hätte Logos unsichtbar gemacht.

## 8 · Betrieb: Installation, Präfix, Versionierung (12/2025 – 02/2026)

- **`public/` und `private/` liegen beide im Web-Root**, weil Vereins-Hosting oft keinen
  übergeordneten Ordner erlaubt; abgesichert wird bevorzugt über den DocumentRoot, ersatzweise
  per `.htaccess`. Eine `htdocs/`-Struktur mit privatem Geschwisterordner war nicht umsetzbar.
- **Eine `.htaccess` für lokal und live** mit Umgebungserkennung statt zweier Dateien.
- **`install.lock` bleibt liegen**, der Installer sperrt sich selbst.
- **Tabellenpräfix wie bei WordPress**, zentral in der Database-Klasse mit `table()`-Methode —
  damit mehrere Instanzen in einer Datenbank leben können und keine Query von Hand angepasst
  werden muss. Tabellennamen werden nirgends hart codiert.
- **Das Schema ist idempotent** (`IF NOT EXISTS`, Prüfungen über `information_schema`), damit
  eine abgebrochene Installation fortgesetzt werden kann.
- **Keine Trigger, keine Stored Programs, kein `DELIMITER`.** Auf Shared Hosting fehlt die
  SUPER-Privilege (Fehler 1419) und phpMyAdmin-Importe kennen `DELIMITER` nicht — Validierung
  gehört nach PHP. Foreign Keys bekommen explizite Namen.
- **Zielkompatibilität MariaDB *und* MySQL 8.0** — festgezurrt nach einem GitHub-Issue im
  06/2026: Der Schema-Export stammte aus MariaDB und war für MySQL syntaktisch ungültig
  (`PREPARE … FROM IF(…)`, ungequotete Constraint-Namen, `int(11)`, `current_timestamp()`).
- **Semantic Versioning mit `version.json` als einziger Quelle**, ausgelesen über einen
  `version`-Endpoint, sichtbar im Footer und im PWA-Manifest; annotierte Git-Tags mit
  `v`-Präfix, Release Notes im GitHub-Release. *(Die Migrationskette und der Update-Wizard
  kamen erst später dazu — die Versionierung regelte zunächst nur den Code.)*

## 9 · Mail und Registrierung (01/2026)

- **Hybrid-Konfiguration:** SMTP-Zugangsdaten in `private/config/mail_config.php` (nie in der
  DB, nie im Git), in den Systemeinstellungen nur Absender, globaler Schalter und
  Feature-Toggles plus Testversand. Credentials in der Datenbank wurden als Risiko verworfen,
  reine Dateikonfiguration als unpraktisch (kein FTP für den Verein).
- **Registrierung dreistufig:** Selbstregistrierung → E-Mail-Verifikation → Freischaltung durch
  einen Admin samt Verknüpfung mit einem Mitglied. `pending_member_id` hält die vorläufige
  Verknüpfung, bis aktiviert wird.
- **Geräte gehen einen eigenen Weg:** vom Admin angelegt, ohne E-Mail, ohne Passwort, sofort
  aktiv, Authentifizierung über Token bzw. TOTP — und deshalb auch mit eigener Ansicht statt
  Fallunterscheidungen im Benutzer-Modal.
- **Mails sind HTML-Templates** unter `private/email_templates/` mit `base.html` und
  Platzhaltern; das Branding der Organisation gilt auch dort.
- **Verworfen:** OAuth2/SSO und Magic Links (zurückgestellt), Backend-Pagination der
  Benutzerliste.

## 10 · Aus dem Werkzeug wurde ein Produkt (02–03/2026)

- **Umbenennung EhrenZeit → EhrenSache**, Domain `ehrensache.app`. Der Name ist eine
  Redewendung, funktioniert als Slogan und trägt sich im Verein weiter; `.app` erzwingt HTTPS
  und passt zur PWA. Verworfen: `.club` (wird nicht ernst genommen), das nüchternere
  „EhrenZeit".
- **Duale Lizenz: AGPL-3.0 plus kommerzielle Lizenz.** Die AGPL verhindert, dass der Code in
  einer proprietären SaaS-Lösung verschwindet; Vereine nutzen und ändern ihn kostenlos, wer
  damit Geld verdient, verhandelt. **MIT/Apache wurden verworfen** (kein Schutz), ebenso Open
  Core und Source Available. **Preise stehen bewusst nicht in der öffentlichen Lizenz** —
  individuelle Vereinbarung statt Preisbindung.
- **Verantwortlich im Sinne der DSGVO ist der Betreiber, nicht der Entwickler.** Trotzdem
  liefert das Projekt Hilfestellung (`DATENSCHUTZ.md`, `DISCLAIMER.md`) und die technischen
  Mittel: Bulk-Löschen, Cleanup alter Daten, Selbstauskunft.
- **Lizenzheader in jeder PHP- und JS-Datei** (Vorlage `public/js/app.js`).
- **Öffentliche Demo mit periodischem Reset** statt einer Read-only-Demo: `demo_reset` leert
  die Tabellen und füllt sie neu, per Cron. Nicht indexiert — für die interne Vereinsverwaltung
  gilt durchgängig `Disallow: /`.

## 11 · Werkzeugwechsel: Entwicklung wandert nach Claude Code (04/2026)

Ab 2026-04-15 läuft die Arbeit lokal in Claude Code statt im Projekt-Chat. Dafür entstand
`CLAUDE.md` im Repo-Root als Projektgedächtnis neben `README.md` und `API.md`.

**Damit endet die Chat-Historie als Quelle.** Alles danach ist im Repository nachvollziehbar
dokumentiert und wird hier nicht wiederholt:

- `git log` auf `dev` (Commits sind fachlich formuliert und nennen betroffene OI-Nummern)
- `CHANGELOG.md` — was in welcher Version fertig wurde
- `docs/OPEN-ITEMS.md` — offene Entscheidungen, Restarbeiten, bewusst Verworfenes
- `docs/superpowers/specs/` — eine Design-Spezifikation je Feature, mit Begründung

Grobe Linie dieser Phase, nur zur Einordnung: Sicherheits- und Bugfix-Runde nach Code-Review
(1.1.3, 04/2026), Update-Wizard und Migrationskette, Arbeitszeiterfassung (1.2.x), virtuelle
Station mit PIN (1.3.0), eigene Testsuiten unter `tests/` und ein Demo-Datengenerator.

---

## Leitplanken, die aus alldem folgen

Kurzfassung für den Alltag — alles oben begründet:

1. **Shared Hosting ist die Messlatte.** Kein Composer, kein Build-Step, keine Dienste, keine
   Stored Programs, keine SUPER-Privilege, keine Annahme über Header oder Rewrites.
2. **Die Fachlogik liegt im Backend.** Filtern, Rechnen, Berechtigungen — das Frontend zeigt an.
3. **Ein Weg pro Sache.** Ein API-Einstiegspunkt, ein Rate Limiter, ein Toleranzfenster für den
   Terminbezug, eine Anwesenheitslisten-API für PWA und Dashboard, eine Versionsquelle. (Eigene
   Zeitfenster haben nur TOTP und der Datumsfilter der Statistik.)
4. **Vorhandenes erweitern statt Parallelstrukturen bauen** — `exceptions` statt `requests`,
   Gruppen statt Statistik-Gruppen, ein Tab in der PWA statt einer zweiten PWA.
5. **Fail closed bei Sichtbarkeit, fail open beim Rate Limiter.** Keine Daten zu zeigen ist
   sicher; niemanden mehr einchecken zu lassen, weil eine Tabelle klemmt, ist es nicht.
6. **Datensparsamkeit als Architektur.** Interne IDs bleiben intern, Secrets verlassen den
   Server nicht, die Erfassungsmethode bleibt beim Gerät.
7. **Zeit ist lokal, außer sie ist es nicht.** Im Web wird lokale Zeit erzeugt (nie
   `toISOString()`), auf dem ESP32 läuft alles in UTC und nur die Anzeige wird lokalisiert.
8. **Nur den tatsächlichen Auslöser ändern.** „Wenn es keinen Fehler wirft, so lassen."

## Verworfene Wege — nicht erneut vorschlagen

| Vorschlag | Warum verworfen |
|---|---|
| .NET/ASP.NET Core, Node, Framework, Build-Step | Zielumgebung ist PHP-Shared-Hosting |
| Composer-Abhängigkeiten | auf dem Zielhosting nicht verfügbar |
| Saubere URLs per mod_rewrite | `.htaccess`-Rewriting lief dort nicht zuverlässig |
| Trigger, Stored Procedures, `DELIMITER` | fehlende SUPER-Privilege, Importe scheitern |
| Statische NFC-Tags als Ortsnachweis | kopierbar, kein Zeitbezug → TOTP |
| Feingliedrige Gerätetypen (biometric/rfid/nfc/…) | Methode ist Sache des Geräts, Datenschutz |
| Zweite PWA für Verwalter | doppelte Codebasis, doppelter Service Worker |
| Statistik im Frontend berechnen / cachen | Mehrfachzählung, veraltete Zahlen |
| Backend-Pagination, Bulk-Dashboard-Endpoint | Cache + clientseitige Filterung reichen |
| `localStorage` für den Datencache | In-Memory-Cache in `ui.js`, bewusst flüchtig |
| IP-Blocking, CAPTCHA | Shared Hosting, geteilte IPs |
| SMTP-Zugangsdaten in der Datenbank | Sicherheitsrisiko |
| MIT/Apache, Open Core, Source Available | kein Schutz vor proprietärer SaaS-Nutzung |
| Preisliste in der öffentlichen Lizenz | Preisbindung, kein Verhandlungsspielraum |
| Interne `member_id` in Import/Export | DB-Interna, nicht stabil zwischen Instanzen |

---

*Ergänzt wird diese Datei nur bei Grundsatzentscheidungen. Alles Übrige gehört in
`CHANGELOG.md`, `docs/OPEN-ITEMS.md` oder eine Spec.*
