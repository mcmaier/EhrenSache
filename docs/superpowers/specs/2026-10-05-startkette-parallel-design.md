# Startkette parallel: Dashboard und Check-in-App

**Datum:** 2026-10-05
**Status:** Entwurf, mit dem Nutzer abschnittsweise abgestimmt
**Anlass:** [OI-121](../../OPEN-ITEMS.md#oi-121--dashboard-und-check-in-app-laden-beim-start-stufe-für-stufe),
Messung auf der Demo nach dem Update auf 1.20.1
**Zielversion:** keine — Arbeit ohne Versionssprung, Eintrag unter `[Unreleased]`. **Keine
Schemaänderung, keine neue API-Ressource.**
**Entschieden:** Weg 3 aus OI-121 — erst im Frontend parallelisieren, dann neu messen; ein
Sammel-Endpunkt (`bootstrap`) nur, falls es danach im Mobilfunk noch spürbar hakt.

---

## 1 Befund

Gemessen am 2026-10-05 auf `demo.ehrensache.app` (1.20.1, Cloudflare, Shared Hosting).

1. **Einzelne Abrufe sind schnell.** Seit dem Fix der Sitzungssperre laufen gleichzeitige Abrufe
   gleichzeitig; Median rund 40 ms.
2. **Der Hoster streut.** Von 80 nacheinander gesendeten Abrufen brauchten etwa 4 % 1,0 bis
   1,25 s, unabhängig vom Endpunkt, auch `ping`. Nicht im Code behebbar.
3. **Beide Oberflächen warten Stufe für Stufe**, sodass sich jeder Ausreißer addiert:
   - Dashboard (Admin, Bereich Profil): fünf Stufen — `me` → `users&user_type=human`,
     `activity_types`, `available_years` → `version` → `my_open_items`, `users&id` →
     `settings&scope=client`. Sichtbar fertig nach 1,5 s, 2,8 s und 1,6 s; ohne Ausreißer ~0,6 s.
   - Check-in-App (Mitglied, Handy-Ansicht): zehn Stufen — `me` → `appointment_types` → `me` →
     `members&id` → `activity_types` → `work_sessions&running=1` → `settings&scope=client` →
     `appointments` (heute) → `my_open_items`, `appointment_responses`. Bis zum Ende steht
     die **Anmeldemaske** da: 1,7 s und 3,1 s. Im Mobilfunk kommt je Stufe die Laufzeit der
     Verbindung hinzu (typisch 50 bis 150 ms).
4. **Nebenfund:** `checkAutoLogin()` löscht den gespeicherten Token bei **jedem** Fehlschlag von
   `me`, also auch bei 503, Timeout oder fehlendem Netz. Ein Hänger beim Hoster meldet das
   Mitglied ab.

## 2 Ziel

- Nach `me` lädt alles, was die erste Ansicht braucht, **gleichzeitig**: zwei Wartestufen statt
  fünf bzw. zehn. Ein Ausreißer kostet die sichtbare Ansicht dann höchstens einmal rund 1 s.
- Die Check-in-App zeigt beim Start mit gespeichertem Token eine **Ladeanzeige**, nie die
  Anmeldemaske.
- Ein überlasteter Server meldet niemanden ab, und lesende Abrufe überstehen einen einzelnen 503.

**Nicht Ziel:** weniger Dateien (OI-120), ein Sammel-Endpunkt, Umbau der Abläufe innerhalb der
Dashboard-Bereiche außer Profil, Änderungen am Vorladen nach 500 ms.

## 3 Risiko: Spitzenlast beim Hoster

Der Hoster weist ab etwa zwölf gleichzeitigen Anfragen je Hosting-Konto mit 503 ab (OI-120).
Heute stellt jedes Handy seine Anfragen nacheinander; danach schickt es kurz bis zu fünf
gleichzeitig. Öffnen zu Probenbeginn viele Mitglieder in derselben Minute die App, steigt die
Spitzenlast, obwohl die Gesamtarbeit gleich bleibt. Zwei Gegenmittel sind Teil dieses Vorhabens:

1. **Zwei Wellen:** zuerst nur, was die erste Ansicht braucht; der Rest erst nach dem Einblenden
   (Abschnitt 4.2, Stufe 3).
2. **Einmalige Wiederholung lesender Abrufe** bei Überlastungsantworten (Abschnitt 6).

## 4 Check-in-App (`public/checkin/js/app.js`)

### 4.1 Ein gemeinsamer Startweg

`checkAutoLogin()` und `handleLogin()` tragen heute dieselbe Kette als Kopie. Beide rufen künftig
eine Funktion `startSession(meData)` auf, die alles nach der erfolgreichen Anmeldung erledigt.
`handleLogin()` holt nach `auth` einmal `me` und übergibt das Ergebnis; `checkAutoLogin()` übergibt
das Ergebnis seiner Tokenprüfung.

`loadUserData()` holt `me` nicht mehr selbst, sondern bekommt `meData` übergeben.

### 4.2 Ablauf

1. **Ladeanzeige.** Liegt ein Token in `localStorage`, zeigt die App beim Laden eine Ladeanzeige
   (Logo, Ladebalken aus OI-85) statt der Anmeldemaske. Ohne Token erscheint die Anmeldemaske
   sofort. Der Anfangszustand steht im Markup (`index.html`), nicht in Inline-Skript (CSP).
2. **Stufe 1:** `me`, genau einmal.
3. **Stufe 2, gleichzeitig** (`Promise.all` bzw. `Promise.allSettled`):
   - `members&id` — Name und Mitgliedsnummer im Kopf
   - `settings&scope=client` — Check-in-Fenster, Hinweistext
   - `appointments` (heute, `member_id`) — Terminauswahl im Check-in
   - `activity_types` — ob die Zeiterfassung zur Wahl steht, Tätigkeitsliste
   - `work_sessions&running=1` — Leiste einer laufenden Sitzung. Ausgewertet nur, wenn
     `activity_types` erfolgreich war (Zeiterfassung freigeschaltet), wie heute.

   `loadCheckinAppointments()` braucht `userData.member_id`; der kommt aus `me` (Stufe 1), nicht
   aus `members`. Die Abrufe hängen also nicht voneinander ab.

   `renderCheckinAppointmentOptions()` liest `clientSettings` für den Hinweistext. Es läuft erst,
   wenn Stufe 2 vollständig ist, damit die Reihenfolge der Antworten keine Rolle spielt.

   Ohne `member_id` (Konto ohne verknüpftes Mitglied) entfallen in Stufe 2 `members`,
   `appointments` und die Zeiterfassung, und die Anwesenheitsliste wird nicht eingerichtet — wie
   bisher, wo `loadUserData()` in diesem Fall vorzeitig zurückkehrte.
4. **Hauptbildschirm einblenden** (`showScreen('main')`, `startTicker()`), danach `initTabs()`
   und `initYearNavigation()`, wie bisher.
5. **Stufe 3, im Hintergrund:** `my_open_items` und `appointment_responses` (wie heute über
   `initTabs()`/`enterCaptureTab()`), dazu `appointment_types` — für **alle** Rollen: Der Verlauf färbt seine Einträge nach Terminart
   (`getTypeColor()`), deshalb wartet `loadHistory()` auf diesen Abruf. Der Termindialog für Admin
   und Manager lädt die Terminarten ohnehin selbst.

Höchstens fünf Abrufe gleichzeitig je Gerät.

### 4.3 Fehler

- **`me` scheitert mit 401 oder 403:** Token löschen, Anmeldemaske. 401 heißt ungültig oder
  abgelaufen; 403 heißt gültig, aber für die App nicht zugelassen (etwa ein Kiosk-Token, der nur
  `station` darf) — „Erneut versuchen" hülfe dort nie.
- **`me` scheitert anders** (503 nach Wiederholung, sonstiger Status, Timeout, kein Netz): Token
  **bleibt**. Die Ladeanzeige zeigt „Server nicht erreichbar“ und einen Knopf „Erneut versuchen“,
  der `checkAutoLogin()` erneut ausführt, daneben „Mit anderem Konto anmelden“ (verwirft den
  gespeicherten Zugang, zeigt die Anmeldemaske — Ausweg, falls der Server für diesen Token
  dauerhaft scheitert; ergänzt nach der Code-Prüfung am 2026-10-05). Beide Knöpfe tragen
  `data-action` (CSP) und erscheinen nur nach einem Fehlschlag.
- **Ein Fehler beim Aufbau** (Ausnahme in `startSession()`): dieselbe Anzeige mit „Die App
  konnte nicht starten.“ — die Ladeanzeige bleibt nie ohne Ausweg stehen.
- **Ein Abruf aus Stufe 2 scheitert:** wie heute — der betroffene Bereich bleibt leer bzw.
  verborgen, die App erscheint trotzdem.
- **Abmelden während des Starts:** `resetSessionState()` bleibt die Stelle, die Zustand verwirft.
  Antworten, die nach einem Abmelden eintreffen, dürfen nichts zeichnen; dafür gilt das Muster der
  vorhandenen Generationszähler (`responsesGeneration`, `openItemsSeq`) für die ganze Stufe 2.

## 5 Dashboard

### 5.1 Startkette (`public/js/app.js`)

1. **Stufe 1:** `me` (`checkAuth()`), unverändert. Ohne Rolle geht nichts.
2. **Dashboard sofort einblenden:** `showDashboard()` direkt nach `checkAuth()` und den
   synchronen `init*`-Aufrufen.
3. **Stufe 2, gleichzeitig, ohne aufeinander zu warten:**
   - `loadAllData()` — Daten des offenen Bereichs
   - `initAllYearFilters()` — `available_years`; der Bereich lädt ohnehin mit `currentYear`, die
     Auswahlfelder füllen sich nebenher
   - `loadVersion()` — nicht mehr abgewartet; Versionsnummer und Update-Hinweis erscheinen mit
     der Antwort
   - `checkWorktimeEnabled()` — `activity_types` nur, wenn die Zeiterfassung laut `features`
     (me, OI-62) an ist, nicht mehr vor der Ansicht; gleichzeitige Aufrufe (Navigation und
     Bereichsaufruf) teilen sich über das gemerkte Promise `worktimeCheck` eine Anfrage
   - Name im Kopf: `setCurrentUser()` holt `members&id` nicht mehr abgewartet; bis die Antwort da
     ist, steht dort die E-Mail-Adresse (wie heute bei Benutzern ohne Mitglied), danach der Name

### 5.2 Benutzerliste nicht beim Start

`initUsersEventHandlers()` lädt heute über `applyUserFilters()` die Benutzerliste, auch wenn der
Bereich Benutzer nicht offen ist. Künftig registriert es nur die Zuhörer. Die Liste laden
`showUserSection()` beim Öffnen und das Vorladen nach 500 ms (`loadAllData()`), wie bei den
übrigen Bereichen.

### 5.3 Profil

`loadProfile()` startet `my_open_items`, die eigenen Benutzerdaten (`users&id`), `members&id` und
`settings&scope=client` gleichzeitig und zeichnet, wenn sie da sind. Gleiche Schlüssel teilen sich
über `sharedLoad()` eine Anfrage; `members&id` aus 5.1 und aus dem Profil wird damit nicht doppelt
geholt. Wo `members&id` heute nicht über `sharedLoad()` läuft, kommt es dorthin.

### 5.4 Unverändert

Die Abläufe innerhalb der übrigen Bereiche (`showRecordsSection()` usw.) und das Vorladen nach
500 ms. Nach dem Umbau werden zwei, drei häufige Startbereiche gemessen; Befunde gehen in OI-121.

## 6 Einmalige Wiederholung lesender Abrufe

In beiden `apiCall()` (`public/js/modules/api.js`, `public/checkin/js/app.js`) nach derselben
Regel. Beide Stellen tragen einen Kommentar, der auf die jeweils andere verweist (wie bei OI-85).

- **Nur `GET`.** Schreibende Abrufe werden nie wiederholt.
- **Nur bei Überlastungsantworten:** 502, 503, 504, 520–524.
- **Genau einmal**, nach einer Zufallspause von 300 bis 600 ms.
- **Nicht** nach Timeout (`AbortError`) und **nicht** bei Verbindungsfehlern.
- Der Zähler offener Anfragen (Ladebalken) bleibt über die Wiederholung hinweg belegt; die
  Wiederholung ist für den Nutzer unsichtbar.
- Scheitert auch der zweite Versuch, gilt die heutige Fehlerbehandlung unverändert.
- Der Timeout von 20 s gilt für beide Versuche **zusammen**: Ein lesender Abruf wartet nie länger
  als heute.

`theme.js` und `loadAppearanceSettings()` rufen `fetch` direkt auf und bleiben ohne Wiederholung;
ein Ausfall dort lässt nur die Vereinsfarben weg.

## 7 Tests

1. **Statische Wächter**, neue Suite `tests/suites/startup_chain_frontend.php`, liest über
   `tests/lib/source.php`:
   - Check-in-App: `checkAutoLogin()` und `handleLogin()` rufen `startSession()` auf und enthalten
     keine eigene Ladekette; `loadUserData()` ruft `apiCall('me')` nicht auf; das Löschen von
     `api_token` in `checkAutoLogin()` steht nur im Zweig für Status 401 oder 403.
   - Dashboard: `init()` wartet `loadVersion()` nicht ab; `initUsersEventHandlers()` ruft weder
     `applyUserFilters()` noch `loadUsers()` auf.
   - Beide `apiCall()`: Die Wiederholung ist an `GET` gebunden, die Statusliste ist vorhanden.
   - Jede Zusicherung wird per Mutationsprobe geprüft: Regel im Code gezielt brechen, Suite muss
     rot werden.
2. **Verhaltensprüfung im Browser**, neues Skript `tests/browser/startup-chain.mjs` (lokal, nicht
   Teil von `tests/run.php`, wie `click-through.mjs`):
   - **Stufen zählen statt Zeit messen:** Jede API-Antwort wird per Request-Interception um
     800 ms je Antwort verzögert; gemessen wird der Mehraufwand gegenüber einem Lauf ohne
     Verzögerung (Minimum aus drei Läufen), Grenze +2,5 × Verzögerung. Zusicherung:
     Hauptbildschirm bzw. Profil sichtbar nach höchstens zwei Stufen plus Spielraum. Heute fiele
     das bei zehn bzw. fünf Stufen durch.
   - **Wiederholung:** Ein `GET` bekommt einmal 503 und kommt trotzdem an. Ein `POST` bekommt
     503 und wird **nicht** wiederholt.
   - **Abmeldung:** `me` mit 503 (zweimal) → Token bleibt, „Erneut versuchen“ sichtbar; Knopf
     drücken mit nun gesunder Antwort → App erscheint. `me` mit 401 → Anmeldemaske, Token weg.
   - Die Ladeanzeige erscheint mit Token, die Anmeldemaske nicht.
3. `click-through.mjs` und `php tests/run.php` bleiben grün.
4. **Manuell:** Abschnitt in `docs/testplan.md` (Ladeanzeige, „Erneut versuchen“, Wiederholung).
5. **Nach dem Release:** Messung auf der Demo wie in Abschnitt 1, Ergebnis in OI-121.

## 8 Rahmen

- Eigener Worktree mit eigener Datenbank (Muster aus früheren Vorhaben).
- Kein Versionssprung, kein `?v=`-Sprung. Eintrag in `CHANGELOG.md` unter `[Unreleased]`; die
  Release-Sitzung übernimmt.
- Neue JS-Dateien gibt es nicht; neue Testdateien tragen den Copyright-Header.
- Neue Knöpfe ausschließlich über `data-action` (CSP); in der Check-in-App über die vorhandene
  Tabelle `dataActions`.
