# `config.php` auf reine Daten — Design

**Datum:** 2026-09-10
**Status:** entworfen, nicht umgesetzt
**Vorhaben ① von zwei** — ② Integrierter Updater (Halbautomat), noch nicht entworfen

---

## 1. Ziel

`private/config/config.php` enthält heute Programmcode. Nach dieser Umstellung enthält sie
ausschließlich Daten: Zugangsdaten, Tabellenpräfix und zwei optionale Schalter. Aller Code
wandert in Dateien unterhalb von `private/helpers/`, die im Repository liegen und deshalb bei
jedem Update ganz normal mit ausgetauscht werden.

Das ist kein Selbstzweck. Es ist die Voraussetzung dafür, dass ein Updater die Dateien einer
Installation ersetzen kann, ohne an `config.php` PHP-Code per regulärem Ausdruck
zurechtzubiegen.

## 2. Ausgangslage

`config.php` wird nie überschrieben — richtig so, sie hält die Zugangsdaten der Installation.
Die Folge ist, dass der **Code** darin bei jedem Release still veraltet. Genau deshalb patcht
`private/migrations/1.0.0.php` sie in Schritt 7 per `preg_replace`: erst das Feld `$prefix`,
dann die Methode `table()`. Das ist Textmanipulation an fremdformatiertem PHP und geht
irgendwann schief.

Bei der Bestandsaufnahme am 2026-09-10 zeigte sich, dass der Eingriff kleiner ausfällt als
befürchtet:

| Element in `config.php` | Tatsächliche Nutzung |
|---|---|
| `class Database` (`getConnection()`, `table()`) | `new Database()` an **vier** Stellen: `public/api/api.php`, `public/reset_password.php`, `public/verify_email.php`, `private/demo/seed.php` |
| `getMailConfig()` | **nirgends aufgerufen.** `private/helpers/mailer.php` und `private/handlers/settings.php` lesen `mail_config.php` selbst ein |
| `getBaseUrl()` / `BASE_URL` | 16 Verwendungen, alle konsumieren nur die Konstante |
| `AUTO_CHECKIN_TOLERANCE_HOURS` | seit 1.2.3 in `system_settings`; die Konstante ist laut `private/helpers/utils.php:141` nur noch Rückfall für nicht migrierte Installationen |
| Zugangsdaten, `$prefix`, `DEMO_MODE` | die eigentlichen Daten |

Eingebunden wird die Datei an fünf echten Stellen. Installer und Update-Assistent behandeln
sie als **Text**, nicht als Programm.

Zwei Funde, die mit abgeräumt werden:

- **`getMailConfig()` ist bewusst zur Leiche gemacht worden.** `tests/suites/mailer_unit.php`
  verbietet in Z. 117–125 aktiv, sie direkt aufzurufen statt `loadMailConfig()` — nagelt in
  Z. 158 und Z. 163 aber gleichzeitig ihre Existenz fest. Die Funktion darf weg, die beiden
  Assertions müssen sich dabei umdrehen.
- **`public/install/index.php` patcht `public/api/api.php`.** Es ersetzt per `str_replace`
  ein `require_once 'config.php';`, das es in `api.php` seit Langem nicht mehr gibt — dort
  steht schon der volle Pfad. Toter Code, aber genau das Muster, das einem Updater gefährlich
  wird: eine Programmdatei unter `public/` zur Laufzeit umschreiben, die der Updater beim
  nächsten Lauf überschreibt.

## 3. Der Glücksfall, auf dem der Entwurf ruht

**`public/update/index.php` bindet `config.php` nie ein.** Es liest die vier Werte per
regulärem Ausdruck aus dem Dateitext (`parseConfig()`) und baut sein PDO selbst
(`connectDb()`). Der Assistent überlebt damit eine `config.php`, die zum laufenden Code nicht
mehr passt.

Deshalb ist der Ablauf tragbar, den die README ohnehin beschreibt: Dateien hochladen → die
Anwendung ist für ein paar Minuten nicht benutzbar → der Assistent schreibt `config.php` um →
die Anwendung läuft.

Ein harter Schnitt wäre damit möglich — die alte Klassenform muss aus Sicht des Assistenten
nirgends weiterleben. Dass sie es trotzdem tut, ist eine bewusste Entscheidung zugunsten des
Updaters aus Vorhaben ②; die Begründung steht in Abschnitt 6.

## 4. Zielbild

### 4.1 `private/config/config.php` (nicht im Repository)

```php
<?php
// Copyright-Header wie in allen PHP-Dateien
return [
    'db' => [
        'host'   => '…',
        'name'   => '…',
        'user'   => '…',
        'pass'   => '…',
        'prefix' => '…',
    ],
    'base_url'  => null,   // null = automatisch erkennen
    'demo_mode' => false,
];
```

`AUTO_CHECKIN_TOLERANCE_HOURS` entfällt ersatzlos. Der Rückfall in `utils.php:141` greift nur,
solange der Wert nicht in `system_settings` steht — und dorthin hat ihn Migration 1.2.3
übernommen, die jede Installation durchlaufen haben muss, um überhaupt hier anzukommen. Das
gilt unabhängig davon, in welcher Form ihre `config.php` vorliegt: Der Migrationsstand steckt
in der Datenbank, nicht in der Konfigurationsdatei.

### 4.2 `private/helpers/config_reader.php` (neu, im Repository)

Der **einzige** Ort, an dem eine Konfigurationsdatei gelesen wird. Nach Leitplanke 3 aus
`docs/project_history.md` („ein Weg pro Sache") benutzen ihn Bootstrap, Migration und
Update-Assistent gemeinsam.

Er kennt beide Formen und bindet die Datei dabei **nie ein**, sondern liest sie als Text:

- enthält `return [` → neue Form, `require` liefert das Array
- enthält `class Database` → alte Form; die fünf Werte plus ein **aktives**
  `define('BASE_URL', …)` und `define('DEMO_MODE', …)` werden per regulärem Ausdruck
  herausgezogen, auskommentierte Zeilen bleiben unberücksichtigt

Das Nicht-Einbinden ist wesentlich. Ein `require` der alten Datei würde `class Database`
definieren und mit der neuen `database.php` kollidieren.

`parseConfig()` aus `public/update/index.php` entfällt und geht hier auf. Sonst laufen die
beiden Fassungen wieder auseinander — das ist bereits einmal passiert, siehe das Duplikat
zwischen `private/helpers/migrations.php` und Schritt 3 des Assistenten in
`docs/OPEN-ITEMS.md`.

### 4.3 `private/helpers/bootstrap.php` (neu, im Repository)

1. lädt die Konfiguration über `config_reader`,
2. **füllt fehlende Schlüssel mit Defaults**,
3. definiert `BASE_URL` und `DEMO_MODE` als Konstanten wie bisher,
4. bindet `database.php` ein.

Punkt 2 ist die eigentliche Pointe des ganzen Vorhabens: Sobald der Bootstrap Defaults kennt,
bricht eine alte `config.php` nicht mehr, wenn ein neuer Schlüssel dazukommt. Ein Schalter in
einer künftigen Version braucht dann **gar keinen** Eingriff in die Datei des Vereins mehr —
der Default greift. Nur ein echter Pflichtwert ohne sinnvollen Default, wie es `$prefix`
seinerzeit war, bräuchte je wieder eine Migration. Das Patchen von PHP-Code entfällt dauerhaft.

### 4.4 `private/helpers/database.php` (neu, im Repository)

Klasse `Database` mit `getConnection()` und `table()`, inhaltlich unverändert — sie bezieht
ihre Werte jetzt aus dem Konfigurationsarray statt aus eigenen privaten Feldern.

Alle `$db->table(…)`-Aufrufe im Bestand bleiben unberührt. Das war das Auswahlkriterium
gegenüber einem reinen Array-Zugriff, der hunderte Aufrufstellen berührt hätte.

### 4.5 Aufrufer

Die fünf Einbindungsstellen ersetzen `require .../config/config.php` durch
`require .../helpers/bootstrap.php`. `private/config/config_example.php` wird zum
Array-Template; der Installer ersetzt darin weiterhin die fünf Platzhalter.

## 5. Migration

Die Migration läuft im bestehenden Assistenten, also nach einem Upload von Hand. Das muss sie
auch — der Updater aus Vorhaben ② setzt auf ihrem Ergebnis auf.

1. Alte `config.php` über `config_reader` lesen.
2. Kopie sichern als `private/config/config.php.bak-<version>`.
3. Neue Array-Fassung schreiben, mit übernommenem `base_url` und `demo_mode`, sofern dort
   aktiv gesetzt.
4. **Gegenlesen:** die neu geschriebene Datei laden und prüfen, dass ein Array mit allen fünf
   DB-Schlüsseln zurückkommt. Schlägt das fehl → Sicherung zurückspielen, Warnung ins
   Protokoll.
5. Kein Schreibrecht → keine Änderung, Warnung mit dem fertigen Dateiinhalt zum Kopieren.

## 6. Notfallpfad und Hinweisstreifen

Schritt 5 ist der Grund für einen Notfallpfad. Konnte die Migration nicht schreiben, erwartet
der Bootstrap ein Array und bekommt keins — die Installation stünde vor einer weißen Seite.
Beim Updater aus Vorhaben ②, der den Dateitausch selbst auslöst, wöge das schwer.

**Der Bootstrap benutzt denselben `config_reader` und kommt deshalb mit der alten Form ohne
Weiteres klar.** Die Anwendung läuft weiter.

Der Preis ist bekannt und akzeptiert: Das nimmt den Druck von der Umstellung, Installationen
können in der Altform weiterlaufen, und es sind wieder zwei Formate im Feld. Dagegen steht der
Hinweisstreifen:

- **Ort:** nicht haftend, im Inhaltsbereich oberhalb des Dashboards, mit der vorhandenen
  Klasse `.alert-warning` aus `public/css/components/modals.css`.
- **Publikum:** ausschließlich Rolle `admin`. Ein Vereinsmitglied kann mit dem Hinweis nichts
  anfangen und würde nur beunruhigt.
- **Text:** nennt Problem und Weg — Konfiguration liegt in der alten Form, Update-Assistent
  aufrufen oder `config.php` beschreibbar machen.
- **Transport:** Die Ressource `version` liefert bereits `php_version` und `server_time` und
  wird von `public/js/modules/ui.js:782` ohnehin abgerufen; der Systemzustand gehört dorthin.
  Sie bekommt ein Feld `config_format`, das nur für `admin` gesetzt wird. `getVersion()` in
  `private/helpers/version.php` braucht dafür die Rolle als Parameter, die im Router
  verfügbar ist.

**Bewusst nicht wiederverwendet** wird das Band aus `public/css/components/demo-banner.css`
samt `theme.js`. Zwei Gründe: Es richtet sich an jeden Besucher, nicht an den Admin; und
`--demo-banner-h` kennt genau einen Wert, zwei haftende Bänder übereinander würden Sidebar und
Mobilmenü verschieben.

Der Altform-Zweig in `config_reader` und der Hinweisstreifen fliegen zwei Minor-Versionen
später wieder heraus.

## 7. Abrissliste

- `getMailConfig()` aus `config_example.php` und dem Config-Template
- die beiden Assertions in `tests/suites/mailer_unit.php` (Z. 158, 163) — sie drehen sich um:
  die Funktion darf nicht mehr existieren
- der tote `str_replace`-Patch auf `api.php` in `public/install/index.php`
- `parseConfig()` in `public/update/index.php` → geht in `config_reader.php` auf
- `AUTO_CHECKIN_TOLERANCE_HOURS` aus dem Config-Template

## 8. Tests

| Ebene | Prüft |
|---|---|
| `config_reader` (Unit) | beide Formen; aktives gegen auskommentiertes `define`; fehlende Datei; unlesbare Datei |
| Migration (DB) | alte Config → neue Form; `base_url` und `demo_mode` übernommen; Sicherung existiert; Gegenlesen schlägt bei kaputtem Ergebnis an und rollt zurück |
| Bootstrap (Unit) | Defaults füllen sich; Altform wird erkannt; `BASE_URL` und `DEMO_MODE` kommen wie bisher an |
| `mailer_unit` (Regression) | `getMailConfig()` existiert nicht mehr |

Das Muster für die DB-Ebene steht in `tests/db/verify_schema_convergence.php`, das schon heute
mit einer Kopie der Konfiguration arbeitet und die echte Datei nicht anfasst.

## 9. Versionsbindung

Der Sprung ist ein **Minor**. Die Umstellung bricht kein Datenformat und keine API, verschiebt
aber Programmcode zwischen Dateien und ändert eine Datei, die ein Verein selbst angefasst
haben könnte — sichtbar genug, dass ein Admin das Changelog liest, ohne einen Bruch zu
behaupten, den es nicht gibt.

Die konkrete Nummer steht bei Entwurfsabschluss noch nicht fest: 1.5.0 ist für andere Features
vorgesehen, und ob diese vorher erscheinen, ist offen. Die Bindung erfolgt bei der Umsetzung
gegen den dann aktuellen Stand von `version.json`. Einzusetzen ist sie an genau diesen Stellen:

- `version.json` — neue Zielversion
- `CHANGELOG.md` — Abschnitt zur neuen Version
- `private/migrations/manifest.php` — ein Eintrag, dessen `from` dem `to` des Vorgängers
  entspricht
- `private/migrations/<from>.php` — Dateiname und Funktionsname `migrate_<from>`
- Der Name der Sicherung aus Abschnitt 5, Schritt 2
- `public/index.html` — `?v=<version>` an den CSS-Links

## 10. Ausblick: Vorhaben ②

Der integrierte Updater als **Halbautomat**: Er fragt
`https://api.github.com/repos/mcmaier/EhrenSache/releases/latest` ab, lädt und entpackt das
Paket auf Knopfdruck, ersetzt die Dateien und leitet dann in den bestehenden Assistenten.
Sicherung und Bestätigung bleiben beim Admin.

Punkte, die dort zu klären sind und hier nur festgehalten werden: Signaturprüfung des Pakets
(ohne sie ist der Nachladeweg eine Kette bis zur Codeausführung), Schreibrecht des PHP-Prozesses
auf die Programmdateien — was `docs/project_history.md` als Randbedingung ausdrücklich
ausschließt und deshalb einen Preflight mit sauberem Rückfall braucht —, Wartungsmodus während
des Laufs, Ausschlussliste für `private/config/`, `public/uploads/`, `install.lock` und die
`.htaccess`-Sperren, sowie die Frage, welches ZIP verwendet wird: der von GitHub erzeugte
`zipball_url` respektiert `.gitattributes export-ignore`, packt aber alles in einen
Wrapper-Ordner.

Das Vorhaben ist inzwischen entworfen: `2026-09-11-integrierter-updater-design.md`. Ein Punkt
dieses Ausblicks hat sich dabei erledigt — ein **atomarer Tausch** ist nicht möglich, weil der
Web-Root auf `public/` zeigt und der Updater die Hosting-Konfiguration nicht umschalten kann.
Es bleibt beim dateiweisen Ersetzen, abgesichert über Preflight, Sicherung und Rückweg.
