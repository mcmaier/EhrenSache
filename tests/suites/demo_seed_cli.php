<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/demo/seed.php';

// ---- parseOptions ---------------------------------------------------------

test('parseOptions liefert die Vorgaben ohne weitere Argumente', function () {
    $options = parseOptions(['seed.php']);
    assertSame(false, $options['yes']);
    assertSame(20260908, $options['seed']);
    assertSame('probelauf', $options['password']);
    assertSame(false, $options['quiet']);
    assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['reference_date']), 'reference_date im Format YYYY-MM-DD');
});

test('parseOptions liest alle Optionen zusammen richtig', function () {
    $options = parseOptions([
        'seed.php',
        '--yes',
        '--quiet',
        '--seed=7',
        '--reference-date=2025-01-31',
        '--password=geheim',
    ]);
    assertSame(true, $options['yes']);
    assertSame(true, $options['quiet']);
    assertSame(7, $options['seed']);
    assertSame('2025-01-31', $options['reference_date']);
    assertSame('geheim', $options['password']);
});

test('parseOptions wirft bei unbekannter Option', function () {
    assertThrows(fn () => parseOptions(['seed.php', '--unsinn']));
});

test('parseOptions wirft bei ungueltigem Stichtag (falsches Format)', function () {
    assertThrows(fn () => parseOptions(['seed.php', '--reference-date=08.09.2026']));
});

test('parseOptions wirft bei ungueltigem Stichtag (unmoegliches Datum)', function () {
    assertThrows(fn () => parseOptions(['seed.php', '--reference-date=2026-13-45']));
});

// Bekanntes, nicht korrigiertes Verhalten: (int) auf einen nicht-numerischen
// String ergibt 0. --seed=abc wird also stillschweigend zu seed 0, statt
// abzulehnen. Wird hier festgehalten, nicht als Bug behandelt — sollte sich
// das als falsch herausstellen, gehört die Entscheidung nach OPEN-ITEMS.md.
test('parseOptions macht aus einem nicht-numerischen --seed= den Wert 0', function () {
    $options = parseOptions(['seed.php', '--seed=abc']);
    assertSame(0, $options['seed']);
});

// ---- assertSchema -----------------------------------------------------

function demoSeedCliMakeSchemaDb(array $versions): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE test_schema_version (version TEXT NOT NULL)');
    $stmt = $db->prepare('INSERT INTO test_schema_version (version) VALUES (:version)');
    foreach ($versions as $version) {
        $stmt->execute(['version' => $version]);
    }

    return $db;
}

test('assertSchema wirft, wenn schema_version leer ist', function () {
    $db = demoSeedCliMakeSchemaDb([]);
    assertThrows(fn () => assertSchema($db, 'test_'));
});

test('assertSchema wirft bei zu altem Schemastand', function () {
    // 1.7.0 bringt appointment_responses; ohne die Tabelle scheitert writePlan().
    $db = demoSeedCliMakeSchemaDb(['1.6.1']);
    assertThrows(fn () => assertSchema($db, 'test_'));
});

test('assertSchema laesst 1.7.0 durch', function () {
    $db = demoSeedCliMakeSchemaDb(['1.7.0']);
    assertSchema($db, 'test_');
});

test('assertSchema laesst 1.7.1 durch', function () {
    $db = demoSeedCliMakeSchemaDb(['1.7.1']);
    assertSchema($db, 'test_');
});

// Versionen absichtlich in falscher Reihenfolge eingetragen (1.7.0 vor
// 1.6.1): assertSchema sortiert selbst ueber version_compare und darf sich
// nicht auf die Einfuegereihenfolge oder eine string-alphabetische Sortierung
// verlassen (die wuerde bei "1.10.0" vor "1.7.0" zu falschen Ergebnissen
// fuehren).
test('assertSchema ermittelt den hoechsten Stand unabhaengig von der Reihenfolge', function () {
    $db = demoSeedCliMakeSchemaDb(['1.7.0', '1.6.1']);
    assertSchema($db, 'test_');
});

// ---- insertRows / clearAll gegen SQLite im Speicher -----------------------
//
// SQLite statt MySQL: keine Netzwerkverbindung, keine Gefahr fuer die
// Entwicklungsdatenbank. clearAll() ruft `SET FOREIGN_KEY_CHECKS = 0` bzw.
// `= 1` auf -- eine MySQL-Anweisung, die SQLite nicht kennt; exec() wuerde
// dafuer unter ERRMODE_EXCEPTION eine PDOException werfen und clearAll() vor
// den eigentlichen DELETEs abbrechen. Statt die beiden exec()-Aufrufe in
// clearAll() dafuer zu kapseln (und damit in Produktion einen echten
// MySQL-Fehler dort zu verschlucken), bekommt die Testverbindung hier bewusst
// ERRMODE_SILENT: exec() liefert dann `false` statt zu werfen, der Rest von
// clearAll() (die DELETEs) laeuft unveraendert weiter. clearAll() selbst
// bleibt so exakt der Code aus der Aufgabenstellung, ungeaendert.
//
// insertRows() braucht das nicht -- es fuehrt kein MySQL-spezifisches SQL aus
// -- und wird deshalb mit ERRMODE_EXCEPTION geprueft, wie im echten Ablauf.

function demoSeedCliMakeSqliteDb(bool $strict = true): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, $strict ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT);

    return $db;
}

test('insertRows fuegt Zeilen ein und liefert die Anzahl geschriebener Zeilen', function () {
    $db = demoSeedCliMakeSqliteDb();
    $db->exec('CREATE TABLE test_widgets (widget_id INTEGER, name TEXT)');

    $count = insertRows($db, 'test_', 'widgets', [
        ['widget_id' => 1, 'name' => 'Erste'],
        ['widget_id' => 2, 'name' => 'Zweite'],
    ]);

    assertSame(2, $count);
    $stmt = $db->query('SELECT COUNT(*) FROM test_widgets');
    assertSame(2, (int) $stmt->fetchColumn());
    $stmt = $db->query('SELECT name FROM test_widgets ORDER BY widget_id');
    assertSame(['Erste', 'Zweite'], $stmt->fetchAll(PDO::FETCH_COLUMN));
});

test('insertRows liefert 0 und schreibt nichts bei leerem Array', function () {
    $db = demoSeedCliMakeSqliteDb();
    $db->exec('CREATE TABLE test_widgets (widget_id INTEGER, name TEXT)');

    $count = insertRows($db, 'test_', 'widgets', []);

    assertSame(0, $count);
    $stmt = $db->query('SELECT COUNT(*) FROM test_widgets');
    assertSame(0, (int) $stmt->fetchColumn());
});

test('insertRows wirft, wenn eine Folgezeile andere Schluesselreihenfolge traegt', function () {
    $db = demoSeedCliMakeSqliteDb();
    $db->exec('CREATE TABLE test_widgets (widget_id INTEGER, name TEXT)');

    assertThrows(fn () => insertRows($db, 'test_', 'widgets', [
        ['widget_id' => 1, 'name' => 'Erste'],
        ['name' => 'Zweite', 'widget_id' => 2],
    ]));
});

test('insertRows wirft, wenn einer Folgezeile ein Schluessel fehlt', function () {
    $db = demoSeedCliMakeSqliteDb();
    $db->exec('CREATE TABLE test_widgets (widget_id INTEGER, name TEXT)');

    assertThrows(fn () => insertRows($db, 'test_', 'widgets', [
        ['widget_id' => 1, 'name' => 'Erste'],
        ['widget_id' => 2],
    ]));
});

test('insertRows wirft, wenn eine Folgezeile einen zusaetzlichen Schluessel traegt', function () {
    $db = demoSeedCliMakeSqliteDb();
    $db->exec('CREATE TABLE test_widgets (widget_id INTEGER, name TEXT)');

    assertThrows(fn () => insertRows($db, 'test_', 'widgets', [
        ['widget_id' => 1, 'name' => 'Erste'],
        ['widget_id' => 2, 'name' => 'Zweite', 'extra' => 'zu viel'],
    ]));
});

test('clearAll leert alle DEMO_TABLES-Tabellen und laesst Auto-Increment unangetastet', function () {
    // ERRMODE_SILENT: siehe Erklaerung oben. clearAll() bleibt dabei exakt
    // die Funktion aus der Aufgabenstellung, unveraendert.
    $db = demoSeedCliMakeSqliteDb(false);
    foreach (DEMO_TABLES as $table) {
        $db->exec("CREATE TABLE test_{$table} (id INTEGER PRIMARY KEY AUTOINCREMENT)");
        $db->exec("INSERT INTO test_{$table} DEFAULT VALUES");
        $db->exec("INSERT INTO test_{$table} DEFAULT VALUES");
    }

    clearAll($db, 'test_');

    foreach (DEMO_TABLES as $table) {
        $stmt = $db->query("SELECT COUNT(*) FROM test_{$table}");
        assertSame(0, (int) $stmt->fetchColumn(), "Tabelle {$table} wurde nicht geleert");
    }
});

test('clearAll leert eine bereits leere Tabelle klaglos', function () {
    $db = demoSeedCliMakeSqliteDb(false);
    $db->exec('CREATE TABLE test_members (id INTEGER)');
    clearAll($db, 'test_');
    $stmt = $db->query('SELECT COUNT(*) FROM test_members');
    assertSame(0, (int) $stmt->fetchColumn());
});

// ---- Gesamtplan passt zu den Tabellen --------------------------------------
//
// Faengt genau den Fehler, den die Spalten-Ableitung in insertRows() sonst
// still macht: Eine Zeile mit abweichenden Schluesseln wuerde in die
// falschen Spalten geschrieben, statt eine Ausnahme auszuloesen.

test('jeder Abschnitt des Gesamtplans traegt in allen Zeilen dieselben Schluessel in derselben Reihenfolge', function () {
    foreach ([20260908, 1, 42, 151] as $seed) {
        $plan = buildDemoPlan($seed, '2026-09-08');
        foreach ($plan as $section => $rows) {
            if ($section === 'settings') {
                continue; // assoziatives Array (setting_key => Wert), keine Zeilenliste
            }
            if ($rows === []) {
                continue;
            }
            $expectedKeys = array_keys($rows[0]);
            foreach ($rows as $index => $row) {
                assertSame(
                    $expectedKeys,
                    array_keys($row),
                    "Saat {$seed}, Abschnitt '{$section}', Zeile {$index}: Schluessel weichen ab"
                );
            }
        }
    }
});

// ---- showTargetListing ----------------------------------------------------

/**
 * Die Regel ist sicherheitsrelevant und deshalb festgenagelt: Unterdrueckt
 * wird die Zielanzeige nur im Cron-Fall (--quiet zusammen mit --yes). Sobald
 * jemand LOESCHEN tippen soll, erscheint sie -- auch mit --quiet.
 */
test('showTargetListing unterdrueckt nur im Cron-Fall', function () {
    assertSame(false, showTargetListing(true, true), '--yes --quiet: Cron, keine Anzeige');
});

test('showTargetListing zeigt an, sobald zurueckgefragt wird', function () {
    assertSame(true, showTargetListing(false, true), '--quiet ohne --yes: Rueckfrage braucht die Anzeige');
    assertSame(true, showTargetListing(false, false), 'ohne beides');
    assertSame(true, showTargetListing(true, false), '--yes ohne --quiet');
});

// ---- Rueckgabewert fuer den Cron ------------------------------------------

/**
 * Fuehrt $body in einem eigenen PHP-Prozess aus, nachdem seed.php geladen ist.
 *
 * Ein eigener Prozess ist noetig, weil jeder Pruefling mit exit() endet. Der
 * Ablaufblock von seed.php laeuft dabei nicht - es wird keine Datenbank
 * geoeffnet.
 *
 * @return array{code: int, stderr: string}
 */
function demoSeedCliRunExit(string $body): array
{
    $script = tempnam(sys_get_temp_dir(), 'seedexit') . '.php';
    $seed   = var_export(realpath(__DIR__ . '/../../private/demo/seed.php'), true);
    file_put_contents($script, "<?php\nrequire {$seed};\n{$body}\n");

    $proc = proc_open(
        [PHP_BINARY, $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    unlink($script);

    return ['code' => $code, 'stderr' => $stderr];
}

/**
 * Database::getConnection() beendet bei einem Verbindungsfehler mit exit()
 * ohne Code. Ohne Waechter meldete der stuendliche Cron dann Erfolg, obwohl
 * nichts geschrieben wurde - nachgemessen, bevor der Waechter kam.
 */
test('ein ungeplantes exit() ohne Code wird zum Rueckgabewert 1', function () {
    $run = demoSeedCliRunExit("registerExitGuard();\necho '{\"message\":\"Database connection error\"}';\nexit();");
    assertSame(1, $run['code'], 'exit() ohne Code nach registerExitGuard()');
    assertTrue(str_contains($run['stderr'], 'unerwartet beendet'), 'Hinweis auf STDERR');
});

test('ein geplantes Ende behaelt seinen Rueckgabewert', function () {
    $ok = demoSeedCliRunExit("registerExitGuard();\nfinishRun(0);");
    assertSame(0, $ok['code'], 'finishRun(0)');
    assertSame('', $ok['stderr'], 'Erfolg bleibt still');

    $fail = demoSeedCliRunExit("registerExitGuard();\nfinishRun(1);");
    assertSame(1, $fail['code'], 'finishRun(1)');
    assertSame('', $fail['stderr'], 'keine zweite Meldung des Waechters zum geplanten Fehler');
});
