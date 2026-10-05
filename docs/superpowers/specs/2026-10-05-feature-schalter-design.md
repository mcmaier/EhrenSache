# Feature-Schalter mit gemeinsamer Prüfstelle — Design

**Vorgang:** OI-62, Etappe 1 · entworfen am 2026-10-05 · Grundlage ist der Stand nach 1.20.1
(`b0165c4`)

## Ziel

Abschaltbare Funktionen gibt es seit 1.2.0, aber jede wurde einzeln nachgerüstet. Sie wird
anders geprüft, antwortet abgeschaltet anders, und das Frontend erfährt auf eigenem Weg davon:

| Schalter | Prüfung heute | Antwort „aus“ | Wie das Dashboard es erfährt |
|---|---|---|---|
| `worktime_enabled` | `isWorktimeEnabled()` / `requireWorktimeEnabled()` (`helpers/worktime.php`) | 404 „Endpoint not found“ | `GET activity_types` antwortet 404 (`worktime.js`, `checkWorktimeEnabled()`) |
| `station_pin_enabled` | `isStationPinEnabled()` (`helpers/station.php`) | `change_pin` 404, `members` PUT mit `pin` 409, `station` 409 | `settings&scope=client` |
| `punctuality_enabled`, `reliability_enabled` | direkt `systemSetting()` (`helpers/punctuality.php`) | Block `enabled:false` in der Statistik | aus der Statistikantwort |

Künftig: **eine** Liste der Funktionen mit ihren Ressourcen, **eine** Prüfstelle, **eine**
Antwort „abgeschaltet“ und **ein** Kanal zu den Oberflächen. Ein Test erzwingt, dass die Liste
vollständig bleibt.

Etappe 2 — Schalter für Terminplanung und Anwesenheitserfassung — ist nicht Teil dieser Spec.
Dort hängen Statistik, Rückmeldung, Check-in, PWA und Station fachlich daran; sie bekommt eine
eigene Spec und baut auf der Registrierung aus Etappe 1 auf.

## Entscheidungen des Nutzers (2026-10-05)

| Frage | Entscheidung |
|---|---|
| Zuschnitt | **Zwei Etappen.** Etappe 1: Registrierung, einheitliches Verhalten, ein Kanal. Etappe 2: neue Schalter, eigene Spec |
| Antwort „aus“ | **403 mit Kennung** `FEATURE_DISABLED` und dem Namen der Funktion |
| Umfang der Liste | `worktime`, `station_pin`, `punctuality`, `reliability` — **nicht** Rückmeldungen, **nicht** Mail |
| Kanal zum Frontend | Feld `features` in der Antwort von `me` |

Verworfen:
- **404 wie bisher bei der Zeiterfassung** („Existenz nicht preisgeben“): Bei quelloffener
  Software ist die Liste der Funktionen öffentlich. Ein 404 lässt sich nicht von einem Tippfehler
  im Ressourcennamen unterscheiden, und das Frontend muss am Status raten.
- **Nur eine Prüffunktion ohne Liste:** verlagert die Vollständigkeit auf jeden Aufrufer. Genau
  diese Art Vergessen holt das Projekt immer wieder ein (OI-107).
- **Eigener Endpunkt oder `settings&scope=client` als Kanal:** kostet eine zusätzliche Anfrage
  beim Start; `me` ruft das Dashboard und die PWA ohnehin auf.

## 1. Registrierung

Neue Datei `private/helpers/features.php` (`declare(strict_types=1)`, Copyright-Kopf).

```php
const FEATURES = [
    'worktime' => [
        'setting'   => 'worktime_enabled',
        'default'   => '0',
        'resources' => ['activity_types', 'work_sessions'],
    ],
    'station_pin' => [
        'setting'   => 'station_pin_enabled',
        'default'   => '0',
        'resources' => ['change_pin'],
    ],
    'punctuality' => [
        'setting'   => 'punctuality_enabled',
        'default'   => '0',
        'resources' => [],
    ],
    'reliability' => [
        'setting'   => 'reliability_enabled',
        'default'   => '0',
        'resources' => [],
    ],
];
```

`resources` nennt nur Ressourcen, die **vollständig** an der Funktion hängen. Teilpfade stehen
nicht in der Liste, sondern rufen `requireFeature()` an ihrer Stelle auf (Abschnitt 2).

Funktionen:

- `isFeatureEnabled($db, $database, string $key): bool` — liest `system_settings` über
  `systemSetting()` mit dem Default aus der Liste. Ein unbekannter Schlüssel wirft
  `InvalidArgumentException` (Programmierfehler, kein Nutzerfehler).
- `requireFeature($db, $database, string $key): void` — bei „aus“ HTTP 403 mit
  `{"message": "Diese Funktion ist abgeschaltet", "code": "FEATURE_DISABLED", "feature": "<key>"}`
  und `exit`.
- `featureForResource(string $resource): ?string` — die Funktion, an der eine Ressource
  vollständig hängt, sonst `null`.
- `enabledFeatures($db, $database): array` — `['worktime' => bool, …]` für alle Einträge, für
  die Antwort von `me`.

Die bisherigen Funktionen bleiben als dünne Weiterleitung, damit der Umbau nicht alle Aufrufer
auf einmal anfassen muss: `isWorktimeEnabled()` → `isFeatureEnabled(…, 'worktime')`,
`requireWorktimeEnabled()` → `requireFeature(…, 'worktime')`, `isStationPinEnabled()` →
`isFeatureEnabled(…, 'station_pin')`. In `punctuality.php` werden die direkten
`systemSetting()`-Aufrufe durch `isFeatureEnabled()` ersetzt.

**Nicht in der Liste:**
- **Rückmeldungen** (`appointment_types.responses_enabled`): eine Eigenschaft der Terminart, kein
  Schalter des Vereins. Die Antwort 409 bleibt.
- **Mail** (`mail_enabled` und Unterschalter): Infrastruktur. Fehlt die Konfiguration, ist 503
  die richtige Antwort; `Mailer::checkMailStatus()` bleibt zuständig.

## 2. Sperre im Backend

**Zentral im Router.** In `public/api/api.php` direkt vor dem `switch($resource)` (nach
Authentifizierung und CSRF):

```php
$feature = featureForResource($resource);
if ($feature !== null) {
    requireFeature($db, $database, $feature);
}
```

Damit kann für ganze Ressourcen kein Handler die Prüfung vergessen. Die Aufrufe von
`requireWorktimeEnabled()` am Anfang von `activity_types.php` und `work_sessions.php` und die
404-Prüfung in `change_pin.php` entfallen.

**Teilpfade** rufen `requireFeature()` selbst auf:

| Stelle | Funktion | heute | künftig |
|---|---|---|---|
| `export.php`, `worktime_member` / `worktime_activity` / `worktime_appointment` | `worktime` | 404 | 403 |
| `station.php`, Aktionen `work_*` | `worktime` | 404 | 403 |
| `station.php`, Anmeldung per PIN (identify, checkin) | `station_pin` | 409 | 403 |
| `members.php` PUT mit Feld `pin` | `station_pin` | 409 + `field: "pin"` | 403 + `field: "pin"` |

Bei `members` PUT bleibt `field: "pin"` in der Antwort, damit das Formular den Fehler weiter am
Feld zeigt. `requireFeature()` bekommt dafür einen optionalen Parameter mit zusätzlichen
Antwortfeldern.

**Weglassen statt sperren** bleibt dort, wo eine Antwort aus mehreren Teilen besteht und nur ein
Teil an der Funktion hängt: `statistics&include=worktime`, `my_open_items` (Arbeitszeit-Punkte),
die Blöcke `punctuality`/`reliability` in `statistics` und `statistics_report`, `station`
`status`/`identify` (`worktime_enabled`, `pin_enabled`). Diese Stellen prüfen künftig über
`isFeatureEnabled()`.

**Bleibt erreichbar:** `my_data` liefert Arbeitszeitsitzungen und deren Verlauf auch bei
abgeschalteter Zeiterfassung. Abschalten ist keine Löschung, und der Auskunftsanspruch (DSGVO
Art. 15) endet nicht mit einem Schalter.

## 3. Kanal zu den Oberflächen

**`me`** liefert in beiden Zweigen (Token und Sitzung) zusätzlich
`"features": {"worktime": true, "station_pin": false, "punctuality": true, "reliability": false}`.
Die Werte sind für alle Rollen gleich und nicht vertraulich.

**`settings&scope=client`** verliert `station_pin_enabled`; `station_pin_min_length` bleibt. Die
Leser (`members.js`, `profile.js`) fragen künftig `isFeatureOn('station_pin')`.

**Dashboard:**
- Neues Modul `public/js/modules/features.js`: `setFeatures(obj)`, `isFeatureOn(key)`,
  `applyFeatureVisibility()`. Gefüllt in `setCurrentUser()` aus der Antwort von `me`.
- Menüpunkte, Bereiche und Einstellungsblöcke, die an einer Funktion hängen, tragen
  `data-feature="<key>"`. `applyFeatureVisibility()` setzt `style.display` wie
  `updateUIForRole()` bei `data-role`. Weil `navigateToSection()` ausgeblendete Menüpunkte
  verweigert, ist auch der Sprung per Code gesperrt.
- `checkWorktimeEnabled()` fragt nicht mehr `activity_types` auf 404 ab. Erhalten bleibt die
  fachliche Zusatzbedingung: Ein Mitglied ohne Verwalterrolle sieht die Zeiterfassung nur, wenn
  es mindestens eine Tätigkeitsart gibt.
- `showDashboard()`: Ist der zuletzt gezeigte Bereich ausgeblendet (Funktion inzwischen aus),
  öffnet das Dashboard „Mein Profil“. Heute prüft es das nur für „Mitglieder“.
- Nach dem Speichern der Einstellungen lädt das Dashboard `me` neu und ruft
  `applyFeatureVisibility()` auf. Die eigenen Rücksetzwege je Schalter (`settings.js`) entfallen,
  soweit sie nur der Sichtbarkeit dienten.

**PWA** (`public/checkin`): liest `features.worktime` aus `me` statt `activity_types` auf 404
abzufragen. Die Tätigkeitsarten selbst lädt sie weiter, wenn die Funktion an ist.

**Station** (`public/station`): unverändert. Ein Kiosk-Gerät darf nur `station` und `version`
aufrufen, also nicht `me`. Die Werte in `status`/`identify` stammen serverseitig aus
`isFeatureEnabled()`.

**Statistik:** Die Blöcke `enabled:false` bleiben. Sie sind Teil der Antwort, kein Kanal für
Schalter.

## 4. Fehlerbehandlung im Frontend

`apiCall()` (`public/js/modules/api.js`) zeigt bei 403 heute „Zugriff verweigert“. Bei
`code: "FEATURE_DISABLED"` zeigt es stattdessen „Diese Funktion ist abgeschaltet“. Das tritt nur
auf, wenn zwischen Laden und Klick jemand den Schalter umgelegt hat; ein erneutes Laden von `me`
ist dann nicht nötig, die nächste Anmeldung oder das nächste Speichern der Einstellungen holt den
Stand. Die PWA bekommt dieselbe Unterscheidung in ihrem `apiCall()`.

## 5. Verhaltenswechsel für Clients

Im Changelog unter `[Unreleased]` / „Geändert“ und in `API.md`:

- Zeiterfassung aus: `activity_types`, `work_sessions`, die drei Arbeitszeit-Exporte und die
  Station-Aktionen `work_*` antworten **403 `FEATURE_DISABLED`** statt 404.
- Stations-PIN aus: `change_pin` 403 statt 404; `members` PUT mit `pin` und die PIN-Aktionen der
  Station 403 statt 409.
- `me` enthält `features`.
- `settings&scope=client` enthält `station_pin_enabled` nicht mehr.

Nicht betroffen: das IoT-Terminal (`totp_checkin`, `auto_checkin`), der Demo-Modus (keine neue
Ressource), die Konfiguration (kein neuer Schlüssel, keine Migration).

## 6. Tests

**`tests/suites/features_unit.php`** (statisch, Muster `tests/suites/demo_mode.php`):
- Die Liste `FEATURES` ist wörtlich festgehalten — jede Änderung ist eine sichtbare Entscheidung.
- Jeder `setting`-Schlüssel ist in `private/setup/ehrensache_db.sql` mit demselben Default
  angelegt.
- Keine Ressource gehört zu zwei Funktionen.
- Jede eingetragene Ressource wird in `api.php` geroutet (Auslesen wie
  `demoTestResourcesFromApi()`; die Funktion wird dafür nach `tests/lib/` gezogen und von beiden
  Suiten genutzt).
- Die zentrale Sperre steht in `api.php` vor `switch($resource)` und nach der CSRF-Prüfung.
- Außerhalb von `features.php` liest niemand `worktime_enabled`, `station_pin_enabled`,
  `punctuality_enabled` oder `reliability_enabled` per `systemSetting()`/`worktimeSetting()`.
- Weder `public/js` noch `public/checkin/js` erkennen eine Funktion über einen 404 von
  `activity_types` (Suche nach `silentStatuses` mit 404 bei `activity_types`).
- Jeder `data-feature`-Wert in `public/index.html` ist ein Schlüssel aus `FEATURES`.

**`tests/suites/features_api.php`** (HTTP, gegen die Testinstanz):
- Je Funktion abschalten, dann: alle gesperrten Pfade antworten 403 mit `code` und `feature`;
  `me` meldet `false`; `my_data` liefert bei abgeschalteter Zeiterfassung die Sitzungen weiter.
- Einschalten: dieselben Pfade antworten wie bisher.
- Der ursprüngliche Stand der Schalter wird im `finally` wiederhergestellt — ein abgebrochener
  Lauf hat schon einmal Schalter verstellt hinterlassen (1.19.0, `api_doc_keys_write`).

**Anzupassende Suiten:** alle, die heute 404 bzw. 409 bei abgeschalteter Funktion erwarten
(`worktime_api`, `station_api`, `api_doc_keys`, `api_doc_keys_write` u. a.) — beim Umbau per
Gesamtlauf ermitteln.

**Gegenproben** (müssen rot werden): zentrale Sperre entfernen; eine Ressource aus `FEATURES`
streichen; einen direkten `systemSetting(…, 'worktime_enabled', …)` außerhalb von `features.php`
einbauen; ein `data-feature` mit unbekanntem Schlüssel.

**Im Browser:** `tests/browser/click-through.mjs` und eine Sichtprüfung im Browser-Bereich:
Zeiterfassung an und aus, je als Admin und als Mitglied; Stations-PIN an und aus im
Mitgliedsformular und im Profil.

## 7. Ablauf und Abgrenzung

- Worktree `C:\xampp\htdocs\EhrenSache-fs`, Zweig `feat/feature-schalter`, Datenbank
  `ehrensache_fs`. Kein Versionssprung, kein Migrationsschritt; Eintrag unter `[Unreleased]`.
- `docs/OPEN-ITEMS.md`, OI-62: Zwischenstand — Etappe 1 erledigt, Etappe 2 offen.
- `CLAUDE.md`, Abschnitt Konventionen: eine Zeile „Neue abschaltbare Funktion: Eintrag in
  `FEATURES` (`private/helpers/features.php`)“.
- Nicht Teil dieser Etappe: Schalter für Terminplanung und Anwesenheitserfassung; Abhängigkeiten
  der Mail-Unterschalter in der Einstellungsseite; die immer wahre Abfrage `if($mailStatus)` in
  `users.php` (toter Code, `sendActivationEmail()` prüft selbst).
