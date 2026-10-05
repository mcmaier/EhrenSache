# Schalter für Terminplanung und Anwesenheitserfassung — Design

**Vorgang:** OI-62, Etappe 2 · entworfen am 2026-10-05 · baut auf Etappe 1 auf (Zweig
`feat/feature-schalter`, Spec `2026-10-05-feature-schalter-design.md`)

## Ziel

Etappe 1 hat eine Liste der abschaltbaren Funktionen (`FEATURES` in
`private/helpers/features.php`), eine Prüfstelle, die Antwort 403 `FEATURE_DISABLED` und das
Feld `features` in `me` gebracht. Die beiden Grundfunktionen — Terminplanung und
Anwesenheitserfassung — lassen sich noch nicht abschalten. Ein Verein, der EhrenSache nur für
Mitglieder und Arbeitszeit nutzt, sieht trotzdem Kalender, Anwesenheit, Anträge und Statistik;
einer, der nur Termine plant und Rückmeldungen einholt, sieht Anwesenheit und Quoten.

Künftig: zwei weitere Schalter, gestuft, mit denselben Mechanismen wie Etappe 1.

## Entscheidungen des Nutzers (2026-10-05)

| Frage | Entscheidung |
|---|---|
| Kombinationen | **Zwei Schalter, gestuft.** Anwesenheit setzt Terminplanung voraus. Stufen: alles an · nur Termine (mit Rückmeldungen) · weder noch |
| Statistik ohne Anwesenheit | Check-in-App: nur der Arbeitszeit-Block, sofern die Zeiterfassung an ist, sonst kein Tab. Dashboard: Bereich „Statistik“ entfällt (er enthält nur Anwesenheitswerte; die Stunden stehen im Bereich „Zeiterfassung“) |
| Abhängigkeiten | Feld `requires` in `FEATURES`; Pünktlichkeit und Zuverlässigkeit setzen Anwesenheit voraus |
| Teilpfade | wie Etappe 1: ganze Aktion → 403, Teil einer Antwort → fällt weg (Tabelle Abschnitt 3) |
| Jahresliste | `available_years` bezieht die Jahre der Arbeitszeitsitzungen ein, solange die Zeiterfassung an ist |

Verworfen:
- **Ein gemeinsamer Schalter „Termine & Anwesenheit“:** deckt den Verein nicht ab, der nur Termine
  plant und Rückmeldungen einholt.
- **Anwesenheit ohne Terminplanung:** nicht möglich. `records.appointment_id` und
  `exceptions.appointment_id` sind Pflicht (`NOT NULL`, FK mit `ON DELETE CASCADE`), jeder
  Check-in-Weg ordnet einem Termin zu.

## Ausgangslage (Bestandsaufnahme 2026-10-05)

- Anwesenheit hängt vollständig an Terminen: Anwesenheiten, Anträge (`absence`,
  `time_correction`), Anwesenheitsliste, Check-in über Code, Gerät und Station.
- Termine ohne Anwesenheit sind möglich. Sinnlos werden dabei Quote, Pünktlichkeit, der Teil
  „erschienen“ der Zuverlässigkeit, die Anwesenheitsbalken im Kalender und der Abgleich
  „zugesagt gegen anwesend“.
- Zeiterfassung ist von beiden unabhängig: Ihr Terminbezug (`work_sessions.appointment_id`,
  `activity_type_appointment_types`) ist optional.
- `available_years` liest nur `YEAR(appointments.date)` plus das laufende Jahr.
- Das Dashboard lädt Termine, Anwesenheiten, Anträge und Terminarten beim Start immer im
  Hintergrund (`ui.js`, `loadAllData()`), unabhängig vom geöffneten Bereich.
- Die Check-in-App lädt bei der Anmeldung Terminarten und Termine des Tages **vor** `me`.
- `PUT settings` legt eine fehlende Zeile an (`ON DUPLICATE KEY UPDATE`).

## 1. Schalter und Abhängigkeiten

`FEATURES` bekommt zwei Einträge und das Feld `requires` (Liste von Schlüsseln, Default `[]`):

| Schlüssel | Einstellung | Default | `requires` | ganze Ressourcen |
|---|---|---|---|---|
| `appointments` | `appointments_enabled` | `'1'` | — | `appointments`, `appointment_series`, `appointment_types`, `appointment_responses`, `holidays` |
| `attendance` | `attendance_enabled` | `'1'` | `appointments` | `records`, `exceptions`, `attendance_list`, `auto_checkin`, `totp_checkin` |
| `punctuality` | (unverändert) | `'0'` | `attendance` | — |
| `reliability` | (unverändert) | `'0'` | `attendance` | — |
| `worktime`, `station_pin` | (unverändert) | `'0'` | — | (unverändert) |

`isFeatureEnabled($db, $database, $key)` ist `true` nur, wenn die eigene Einstellung `'1'` ist
**und** alle Einträge aus `requires` (rekursiv) eingeschaltet sind. Die Einstellungen selbst
werden dabei nicht verändert: Wer die Terminplanung wieder einschaltet, hat den vorigen Stand der
abhängigen Schalter sofort zurück. `requireFeature()` meldet im Feld `feature` den Schlüssel, nach
dem gefragt wurde (nicht die verursachende Voraussetzung).

**Keine Migration.** Fehlt die Zeile, gilt der Default `'1'` aus `FEATURES`; das Speichern in den
Einstellungen legt sie an. `private/setup/ehrensache_db.sql` bekommt die beiden Zeilen für neue
Installationen (Wächter aus Etappe 1: Default in Schema und Liste gleich).

**Bleibt bei jedem Stand erreichbar:** `my_data` (Auskunft nach DSGVO Art. 15). Abschalten ist
keine Löschung.

## 2. Zentrale Sperre

Unverändert der Mechanismus aus Etappe 1: `api.php` sperrt vor dem Routing jede Ressource aus
`resources`. Da `isFeatureEnabled()` die Voraussetzungen prüft, sperrt eine abgeschaltete
Terminplanung automatisch auch alle Anwesenheitsressourcen.

## 3. Teilpfade im Backend

| Ressource | Terminplanung aus | Anwesenheit aus |
|---|---|---|
| `statistics` | wie rechts | ohne `include=worktime`: 403 (`attendance`). Mit `include=worktime`: Antwort enthält nur den Block `worktime` (bzw. 403 `worktime`, wenn auch die Zeiterfassung aus ist) |
| `statistics_report` | wie rechts | 403 (`attendance`) |
| `export` | Typ `appointments`: 403 | Typ `records`: 403. Arbeitszeit-Exporte bleiben; `worktime_appointment` nennt weiter Titel vorhandener Termine |
| `import` | Typ `appointments`: 403 | Typen `records`, `extract_appointments`: 403 |
| `station` | wie rechts | POST `checkin`: 403. `identify`: `checkin_candidate` ist `null`. `status`: neues Feld `attendance_enabled` |
| `my_open_items` | Punkte der Art `response` entfallen | Punkte der Art `exception` entfallen |
| `appointments` | (ganz gesperrt) | `include=attendance` liefert keine Anwesenheitszahlen |
| `appointment_responses` | (ganz gesperrt) | Abgleich mit Anwesenden entfällt; eine Absage legt keinen Entschuldigungsantrag an, `responses_require_excuse` ruht |
| `work_sessions` | Feld `appointment_id` bei POST/PUT: 403 mit `field: "appointment_id"`. Bestehende Verknüpfungen bleiben, Lesen unverändert | — |
| `available_years` | Jahre aus `work_sessions` werden einbezogen, wenn die Zeiterfassung an ist (gilt bei jedem Stand) | — |

Unverändert: `activity_types` (gespeicherte Verknüpfungen zu Terminarten bleiben, die Oberfläche
blendet das Feld aus), `cleanup` (Fristen gelten für vorhandene Daten), `members` (Lösch-Kaskade).

## 4. Oberflächen

**Dashboard**
- `data-feature` an den Menüpunkten `termine` (`appointments`), `anwesenheit`, `antraege`,
  `statistik` (je `attendance`) und am Block Terminarten in „Verwaltung“ (`appointments`).
- Startabrufe in `loadAllData()`: Termine und Terminarten nur bei `isFeatureOn('appointments')`,
  Anwesenheiten und Anträge nur bei `isFeatureOn('attendance')`.
- Kalender bei Anwesenheit aus: Abruf ohne `include=attendance`, keine Anwesenheitsbalken, keine
  Knöpfe „Zur Anwesenheit“.
- Zeiterfassung bei Terminplanung aus: Terminauswahl im Dialog der Arbeitszeitsitzung
  ausgeblendet; „Summen nach Termin“ bleibt.
- Profil: „Offene Punkte“ unverändert (der Server filtert).

**Einstellungen** (Muster der Untertabs aus 1.9.0, Spec 3.2)
- Tab „Termine & Anwesenheit“: erste Karte mit den Schaltern „Terminplanung“ und
  „Anwesenheitserfassung“. Letzterer ist `disabled`, solange die Terminplanung aus ist.
- Abhängige Einstellungen per `disabled` (Eintrag in `FEATURE_SWITCHES`, `settings.js`): bei
  Anwesenheit aus Check-in-Fenster, automatisches Anlegen von Terminen, Pünktlichkeit,
  Zuverlässigkeit, Quotenschwellen; bei Terminplanung aus zusätzlich Feiertagsregion und
  Rückmeldefrist.
- Löschfristen (Tab Datenschutz) bleiben bedienbar.
- Nach dem Speichern greift der Weg aus Etappe 1 (`refreshFeatures()`); `FEATURE_KEYS` in
  `settings.js` bekommt die beiden neuen Einstellungen.

**Check-in-App** (`public/checkin/js/app.js`)
- Anmeldung: `me` zuerst, danach Terminarten und Termine des Tages nur bei eingeschalteter
  Terminplanung (bzw. Anwesenheit für die Check-in-Termine).
- Tab Erfassen: Option „Anwesenheit“ und „Erfassungsantrag“ nur mit Anwesenheit. Ohne Anwesenheit
  und ohne Zeiterfassung: Hinweis „Hier ist nichts zum Erfassen freigeschaltet“.
- Tab Termine (Rückmeldungen): nur mit Terminplanung.
- Tab Verlauf: nur Abschnitte eingeschalteter Funktionen.
- Tab Statistik: mit Anwesenheit wie bisher; ohne Anwesenheit nur Arbeitszeit (sofern an), sonst
  ausgeblendet.
- Verwalter: Anwesenheitsliste nur mit Anwesenheit, Termin anlegen nur mit Terminplanung.

**Station** (`public/station`)
- `status.attendance_enabled = false` blendet den Check-in aus.
- Weder Check-in noch Arbeitszeit möglich: „An dieser Station ist keine Funktion freigeschaltet.“

## 5. Verhaltenswechsel für Clients

Im Changelog unter `[Unreleased]` / „Geändert“ (Abschnitt aus Etappe 1 ergänzen) und in
`API.md`: neue Schalter `appointments_enabled`, `attendance_enabled` (Default an); bei „aus“ die
Antworten aus Abschnitt 1–3; `me.features` mit `appointments` und `attendance`;
`station` `status` mit `attendance_enabled`; `available_years` mit Jahren der Arbeitszeit.

Nicht betroffen, solange beide Schalter an sind (Default): alle bestehenden Clients, das
IoT-Terminal, die Konfiguration.

## 6. Tests

**`features_unit`**
- Wörtliche Liste mit den neuen Einträgen und `requires`.
- `requires` nennt nur bekannte Schlüssel und bildet keinen Ring.
- Die Auflösung der Voraussetzungen steckt in einer reinen Funktion
  `resolveFeatures(array $raw): array` (Schlüssel → eigene Einstellung als bool, Ergebnis →
  wirksamer Stand); `isFeatureEnabled()` und `enabledFeatures()` nutzen sie. Unit-Tests: alles an;
  Terminplanung aus → `attendance`, `punctuality`, `reliability` aus; Anwesenheit aus →
  `punctuality`, `reliability` aus, `appointments` an; Zeiterfassung und PIN bleiben unberührt.
- `data-feature` an `termine`, `anwesenheit`, `antraege`, `statistik`.
- Die Startabrufe in `ui.js` laden Termine/Anwesenheiten nur hinter `isFeatureOn(…)`.

**`features_api`**
- Terminplanung aus: alle Ressourcen aus `appointments` und `attendance` 403; `me` meldet
  `appointments`, `attendance`, `punctuality`, `reliability` als `false` (auch wenn deren eigene
  Einstellung `'1'` ist); `my_data` 200.
- Anwesenheit aus (Terminplanung an): Anwesenheitsressourcen 403, `appointments` 200 ohne
  Anwesenheitszahlen bei `include=attendance`; `statistics` 403, `statistics?include=worktime`
  nur mit Block `worktime`; `export&type=records` 403; `station` `checkin` 403.
- Terminplanung aus: `work_sessions` POST mit `appointment_id` 403 mit `field`.
- `available_years` enthält ein Jahr, in dem es nur eine Arbeitszeitsitzung gibt.
- Wiederherstellung der Schalter im `finally` (`fsWith`).

**Anzupassende Suiten** per Gesamtlauf ermitteln.

**JS-Syntax:** `node --check` auf alle Dateien in `public/js`, `public/checkin/js`,
`public/station/js`, solange die Suite dafür (parallele Sitzung) noch nicht in `dev` ist.

**Gegenproben:** `requires` von `attendance` entfernen; zentrale Sperre für eine
Anwesenheitsressource umgehen; Startabruf ohne Bedingung — jeweils rot.

**Im Browser:** Klickdurchgang; Sichtprüfung der drei Stufen (alles an · nur Termine · weder noch)
im Dashboard als Admin und als Mitglied, in der Check-in-App und an der Station.

## 7. Ablauf und Abgrenzung

- Zweig `feat/feature-schalter-2` auf `feat/feature-schalter`, Worktree
  `C:\xampp\htdocs\EhrenSache-fs`, Datenbank `ehrensache_fs`. Ist Etappe 1 bis zur Übergabe in
  `dev`, wird der Zweig auf `dev` umgesetzt.
- Kein Versionssprung, keine Migration; Changelog unter `[Unreleased]`.
- `docs/OPEN-ITEMS.md`, OI-62: Etappe 2 erledigt.
- Nicht Teil: weitere Schalter (etwa Rückmeldungen vereinsweit), Umbau der Statistik, eigene
  Jahreslogik je Bereich.
