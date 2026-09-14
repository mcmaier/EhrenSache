# Direktsprung von jeder älteren Version — Design (1.6.1)

**Datum:** 2026-09-14
**Status:** entworfen, nicht umgesetzt
**Baut auf:** `2026-09-10-config-reine-daten-design.md` (①) und
`2026-09-11-integrierter-updater-design.md` (②), beide veröffentlicht als 1.6.0

---

## 1. Ziel

Jede künftige Version bleibt **direkt** von jedem älteren Stand erreichbar — von 1.0.0 bis
zur Vorgängerversion —, ohne Zwischenschritt über 1.6.0 oder eine andere Version. Und der
Updater aus Schritt 0 spielt keine Version ein, deren Anforderungen der Server nicht erfüllt.

## 2. Ausgangslage

Heute ist kein Zwischenschritt nötig:

- **Von Hand:** Jedes Paket enthält die vollständige Migrationskette ab 1.0.0.
  `tests/suites/migrations.php` erzwingt eine lückenlose Kette, die bei `version.json` endet,
  und zu jedem Eintrag die Migrationsfunktion. `tests/db/verify_migration_chain.php` fährt die
  Kette von 1.0.0 an gegen eine echte Datenbank, einschließlich der Umstellung der `config.php`.
- **Über GitHub:** Schritt 0 braucht einmal eine Installation ab 1.6.0. Der eine Upload von Hand
  dorthin kann aber gleich die neueste Version sein.

Drei Dinge würden einen Zwischenschritt erzwingen:

**2.1 Der geplante Ausbau des Altform-Lesers.** Spec ① sagt in Abschnitt 6: „Der Altform-Zweig
in `config_reader` und der Hinweisstreifen fliegen zwei Minor-Versionen später wieder heraus."
Danach könnte eine 1.5.1-Installation beim Direktsprung weder ihre Zugangsdaten im Assistenten
lesen (`readWizardConfig()` benutzt den Leser) noch die Datei umstellen (`migrate_1_5_1()`
benutzt ihn ebenfalls). Das Update scheiterte.

**2.2 Höhere Anforderungen einer künftigen Version.** Schritt 0 läuft mit dem Code der
**installierten** Version. Hebt eine Version die PHP-Mindestversion oder verlangt sie eine neue
Erweiterung, prüft der alte Updater das nicht, tauscht — und der Folgeaufruf bricht mit dem
neuen Code auf dem alten PHP ab. Der Rückweg ist selbst neuer Code und greift dann nicht.

Die Anforderungen stehen heute zweifach hart codiert: `public/install/index.php` (PHP 8.0,
`pdo`, `pdo_mysql`, `json`) und `public/update/index.php` (PHP 8.0, `pdo`, `pdo_mysql`).

**2.3 Migration 1.2.3 bindet die `config.php` ein.** Sie ruft `require_once $configPath`, um
`AUTO_CHECKIN_TOLERANCE_HOURS` zu lesen. Bei einer Installation bis 1.2.2 definiert das im
Prozess des Assistenten die Klasse `Database` aus der alten Datei. Heute harmlos, weil der
Assistent `private/helpers/database.php` nie lädt. Tut er es künftig, bricht der Direktsprung
mit „Cannot declare class Database" ab.

## 3. Entwurf

### 3.1 `requires` in `version.json` — eine Quelle

```json
{
  "version": "1.6.1",
  "build": "…",
  "commit": "auto",
  "name": "EhrenSache",
  "requires": {
    "php": "8.0.0",
    "extensions": ["pdo", "pdo_mysql", "json"]
  }
}
```

Das sind die Anforderungen der **Anwendung**. `curl` und `zip` gehören nicht hinein — sie
braucht nur der Weg über GitHub, und dafür prüft `updateEnvironmentErrors()` sie bereits. Der
Weg von Hand funktioniert ohne beide.

Neuer Helfer **`private/helpers/requirements.php`**, ohne weitere Abhängigkeiten, damit ihn
Installer, Update-Assistent und Updater gleichermaßen laden können:

- `requirementsRead(string $root): array` — liest `requires` aus `version.json` unter `$root`.
  **Fehlt die Angabe, gelten keine zusätzlichen Anforderungen** (`php` `0.0.0`, keine
  Erweiterungen). Ältere Pakete ohne `requires` sind ohnehin nie neuer als die installierte
  Version und werden aus diesem Grund abgewiesen.
- `requirementsChecks(array $requires, string $phpVersion, callable $extensionLoaded): array` —
  Prüfpunkte als `Bezeichnung => bool`, in der Form, die beide Assistenten schon anzeigen.
- `requirementsErrors(...)` — dieselben Prüfungen als Liste der Verstöße, für den Updater.

Laufende PHP-Version und Erweiterungsprüfung sind Parameter, damit die Tests beliebige Server
nachstellen können.

Installer und Update-Assistent bauen ihre Systemprüfung aus `requirementsChecks()` statt aus
eigenen Werten. Die übrigen Prüfpunkte (`install.lock`, `config.php`, Schreibrecht,
Datenbankverbindung, Migrationskette) bleiben, wie sie sind.

### 3.2 Geprüft wird an zwei Stellen

**Vor dem Tausch** — `updaterApply()` liest `requires` aus dem **entpackten Paket**, direkt nach
`updateValidatePackage()`. Verstöße weisen das Paket ab, bevor eine Datei angefasst wird.
Schützt jede Installation ab 1.6.1.

**Im Folgeaufruf** — `updaterVerify()` liest `requires` der **frisch getauschten** Dateien und
prüft sie gegen das laufende PHP, **vor** der Kettenprüfung. Bei einem Verstoß spielt sie die
Sicherung zurück, entfernt das Wartungsflag und nennt die Verstöße. Schützt auch Installationen
auf 1.6.0, deren Updater `requires` nicht kennt und deshalb getauscht hat.

### 3.3 Der Update-Pfad bleibt auf PHP 8.0 lauffähig

Die zweite Prüfung aus 3.2 wirkt nur, wenn der neue Code auf dem alten PHP überhaupt startet.
Deshalb gilt für die Dateien des Folgeaufrufs:

`public/update/index.php`, `private/helpers/migrations.php`, `config_reader.php`,
`requirements.php`, `updater.php`, `maintenance.php`, `update_source.php`,
`update_package.php`, `update_swap.php`

**keine Syntax, die neuer als PHP 8.0 ist.**

Ein statischer Test zerlegt diese Dateien mit `token_get_all()` und schlägt mindestens an bei:
`enum`, `readonly`, Rückgabetyp `never`, Erzeugung aufrufbarer Objekte per `foo(...)`, `new` in
Parameter-Vorgaben, reinen Schnittmengentypen, eigenständigen Typen `true`/`false`/`null`,
typisierten Klassenkonstanten. Der Test deckt nicht jede Kleinigkeit ab, aber alles, was man
versehentlich schreibt. Die Regel steht zusätzlich in `CLAUDE.md` unter „Konventionen".

Die Grenze bleibt **8.0, auch wenn die Anwendung selbst später mehr verlangt.** Grund: Ab 1.6.1
weist schon der installierte Updater ein Paket mit zu hohen Anforderungen vor dem Tausch ab.
Eine Installation auf genau **1.6.0** kann das nicht — sie tauscht und verlässt sich auf den
Folgeaufruf der neuen Version. Weil sie per Schritt 0 direkt auf jede spätere Version springen
kann, muss der Update-Pfad **jeder** künftigen Version auf PHP 8.0 starten können.

Aufheben lässt sich das nur mit einer bewussten Entscheidung, die dann in
`docs/OPEN-ITEMS.md` steht: dass Installationen auf 1.6.0 nicht mehr per Schritt 0, sondern nur
von Hand direkt aktualisiert werden. Die Anwendung außerhalb des Update-Pfads darf höhere
PHP-Versionen verlangen — `requires` sorgt dafür, dass sie nie auf einem zu alten PHP landet.

### 3.4 Keine Migration bindet die `config.php` ein

Migration 1.2.3 liest `AUTO_CHECKIN_TOLERANCE_HOURS` künftig über den Leser als Text.

`config_reader.php` bekommt eine allgemeine Funktion für den **Rohwert eines aktiven `define`**:
`configLegacyDefineRaw(string $text, string $name)` — `true`/`false`, ganze Zahl, String-Literal
als Zeichenkette, sonst der Ausdruck als Text, `null` wenn nicht aktiv gesetzt. Die bestehende
`configLegacyDemoMode()` wird darauf zurückgeführt, ihr Verhalten ändert sich nicht (die Tests
aus ① halten es fest).

In Migration 1.2.3 gilt: Ein **ganzzahliger** Wert — auch als String-Literal aus Ziffern — wird
übernommen. Jeder andere Wert, etwa ein Ausdruck, ergibt die Vorgabe 2 und eine Warnung. Das
weicht bewusst von `require` ab: Der Text-Leser kann einen Ausdruck nicht auswerten, und
`(int)` machte daraus still die 0 — einen gültigen, aber falschen Wert. Die bestehende Prüfung
auf den Bereich 0–8 bleibt.

Ein statischer Test verbietet in `private/migrations/*.php` jedes `require`/`include` mit
`$configPath`. Er ist die eigentliche Regel und erfasst auch künftige Migrationen.

### 3.5 Der Leser für die alte Form bleibt dauerhaft

- Spec ①, Abschnitt 6: Der Satz über das Entfernen des Altform-Zweigs wird korrigiert. Der
  **Hinweisstreifen** im Dashboard darf später entfallen; der **Leser** bleibt, solange
  `private/migrations/1.5.1.php` in der Kette steht — also dauerhaft.
- `docs/OPEN-ITEMS.md`, „Bewusst entschieden": „Jede Version ist direkt von jedem älteren Stand
  erreichbar", mit den Regeln aus 3.3 und 3.4 sowie: Migrationsdateien werden nie gelöscht, der
  Leser behält die alte Form.
- `tests/suites/config_reader.php`: Die Tests zur alten Form bekommen einen Kommentar, warum sie
  nicht entfernt werden dürfen.

## 4. Belege

**Durchstich** `tests/db/verify_updater_e2e.php` bekommt zwei Fälle. Die Versionen leitet der
Prüfer künftig aus `HEAD` ab, statt 1.6.0/1.6.1 fest zu verdrahten — sonst kollidiert der
eingebaute Probesprung mit dem echten Manifest-Eintrag von 1.6.1.

- **Fall E:** Installation aus `HEAD`, Paket aus `HEAD` mit nächster Version, passendem
  Manifest-Eintrag und `"php": "99.0.0"`. Erwartet: **vor** dem Tausch abgewiesen, Meldung nennt
  die PHP-Anforderung, nichts verändert, kein Wartungsflag, keine Sicherung.
- **Fall F:** Installation aus dem **Tag `v1.6.0`**, Paket wie in E. Der Updater von 1.6.0 kennt
  `requires` nicht und tauscht; der neue Code im Folgeaufruf erkennt den Verstoß und spielt
  zurück. Erwartet: Tausch „erfolgreich", Folgeaufruf abgewiesen mit Meldung zur
  PHP-Anforderung, `version.json` wieder 1.6.0, Wartungsflag entfernt.

**Unit-Tests** für `requirements.php` (fehlende Angabe, PHP zu alt, Erweiterung fehlt, alles
erfüllt) und für `configLegacyDefineRaw()`.

**Statische Tests** aus 3.3 und 3.4.

**Datenbankteil des Direktsprungs:** `tests/db/verify_migration_chain.php` fährt die Kette von
1.0.0 an weiter, jetzt mit der umgestellten Migration 1.2.3. Der vorhandene Fall mit einem
verworfenen Bestandswert muss unverändert grün bleiben.

## 5. Versionierung

1.6.1 mit leerer Migration `private/migrations/1.6.0.php` (1.6.0 → 1.6.1), Manifest-Eintrag,
`version.json` samt `requires`, `?v=` in den vier HTML-Dateien, Abschnitt im `CHANGELOG.md`.

## 6. Bewusst nicht enthalten

| Verworfen | Grund |
|---|---|
| MySQL/MariaDB-Mindestversion in `requires` | Die Anforderung hat sich seit Beginn nicht geändert; MariaDB meldet Versionen in eigenem Format. Nachrüstbar, wenn es je nötig wird |
| `curl`, `zip` in `requires` | Nur für den Weg über GitHub nötig, dort schon geprüft |
| Echte Syntaxprüfung mit einer zweiten PHP-Installation | Download und dauerhafte Pflege nur für diesen Zweck |
| Nachträgliche Absicherung des Updaters von 1.6.0 vor dem Tausch | Nicht möglich — der Code liegt beim Verein. Abgefangen wird im Folgeaufruf (3.2) |
