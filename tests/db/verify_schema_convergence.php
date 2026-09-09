<?php
/**
 * EhrenSache - Verifikation, dass die Migrationskette beim selben Schema
 * ankommt wie eine Neuinstallation
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Ergaenzt verify_migration_chain.php, ersetzt es nicht. Jenes prueft die
 * Mechanik des Assistenten -- Versionserkennung, Ausfuehrung, Stempelung,
 * Umbenennung auf das Praefix, Folgenlosigkeit eines zweiten Laufs -- spielt
 * dafuer aber immer das HEUTIGE Schema ein und nennt es 1.0.0. Migrationen,
 * die Spalten hinzufuegen, laufen dort also gegen ein Schema, das diese
 * Spalten bereits hat.
 *
 * Dieses Skript setzt am anderen Ende an: Es startet mit dem ECHTEN Schema aus
 * dem Tag v1.1.3 und fragt, ob ein Verein, der von dort aktualisiert, beim
 * selben Schema ankommt wie einer, der heute frisch installiert. Die
 * dev-Datenbank ist dafuer kein Beleg -- dort lief jede Migration einmal, zu
 * ihrer Entstehungszeit, auf dem Schema jenes Moments.
 *
 * Weg A  1.1.3-Schema aus dem Tag v1.1.3, mit Beispieldaten gefuellt, dann die
 *        Kette bis zur Zielversion aus version.json
 * Weg B  das heutige private/setup/ehrensache_db.sql, frisch
 *
 * Verglichen werden Tabellen, Spalten (Typ, Nullbarkeit, Vorgabe, EXTRA,
 * Generierungsausdruck) und Indizes. Die Spaltenreihenfolge bleibt bewusst
 * aussen vor: ALTER TABLE haengt hinten an, die Anwendung spricht Spalten
 * ausschliesslich beim Namen an.
 *
 * Nicht Teil von tests/run.php: Es braucht ein Konto mit CREATE-DATABASE-Recht
 * und legt zwei Wegwerf-Datenbanken an, die es danach wieder entfernt. Die
 * echte private/config/config.php wird nicht angefasst -- die Kette bekommt
 * eine Kopie.
 *
 * Aufruf:
 *   php tests/db/verify_schema_convergence.php "mysql:host=127.0.0.1;port=3306" root "" ez_
 *
 * Optionen:
 *   --keep   Die beiden Datenbanken nach dem Lauf stehen lassen (Fehlersuche)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript laeuft nur ueber die Kommandozeile.\n");
}

$behalten   = in_array('--keep', $argv, true);
$argumente  = array_values(array_filter($argv, static function ($a) { return $a !== '--keep'; }));

if (count($argumente) < 5) {
    fwrite(STDERR, "Aufruf: php tests/db/verify_schema_convergence.php <dsn-ohne-dbname> <user> <password> <prefix> [--keep]\n");
    exit(2);
}

[$_, $dsn, $dbUser, $dbPass, $prefix] = $argumente;

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../../private/helpers/migrations.php';

const DB_AKTUALISIERT = 'ehrensache_migtest_a';
const DB_FRISCH       = 'ehrensache_migtest_b';
const START_VERSION   = '1.1.3';

$wurzel      = dirname(__DIR__, 2);
$versionJson = json_decode((string) file_get_contents($wurzel . '/version.json'), true);
$zielVersion = (string) ($versionJson['version'] ?? '');

if ($zielVersion === '') {
    fwrite(STDERR, "version.json nennt keine Version.\n");
    exit(2);
}

/**
 * Die Migration 1.0.0 schreibt in die Konfiguration, 1.2.3 liest eine Konstante
 * daraus. Beides darf die echte Datei nicht beruehren, also bekommt die Kette
 * eine Kopie.
 */
function konfigurationsKopie(string $wurzel): string
{
    $original = $wurzel . '/private/config/config.php';
    $kopie    = sys_get_temp_dir() . '/ehrensache_migtest_config.php';

    if (is_file($original)) {
        copy($original, $kopie);
    } else {
        file_put_contents($kopie, "<?php\n// Platzhalter fuer den Kettentest\n");
    }

    return $kopie;
}

/** DSN ohne Datenbankauswahl -- die Datenbanken entstehen erst. */
function dsnOhneDatenbank(string $dsn): string
{
    return (string) preg_replace('/;?dbname=[^;]*/', '', $dsn);
}

function verbindung(string $dsn, string $user, string $pass, string $datenbank = ''): PDO
{
    $ziel = dsnOhneDatenbank($dsn) . ($datenbank !== '' ? ";dbname={$datenbank}" : '');

    $pdo = new PDO($ziel . ';charset=utf8mb4', $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function frischeDatenbank(PDO $server, string $name): void
{
    $server->exec("DROP DATABASE IF EXISTS `{$name}`");
    $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

/** Spielt ein Schema ein -- so, wie es der Installer tut. */
function schemaEinspielen(PDO $pdo, string $sql, string $prefix): void
{
    $pdo->exec(str_replace('{PREFIX}', $prefix, $sql));
}

/** Das 1.1.3-Schema stammt aus dem Tag, nicht aus dem Arbeitsbaum. */
function schemaAusTag(string $wurzel, string $tag): string
{
    $befehl = sprintf('git -C %s show %s 2>&1',
                      escapeshellarg($wurzel),
                      escapeshellarg($tag . ':private/setup/ehrensache_db.sql'));
    $sql = (string) shell_exec($befehl);

    if (stripos($sql, 'CREATE TABLE') === false) {
        fwrite(STDERR, "Schema aus {$tag} nicht lesbar:\n{$sql}\n");
        exit(2);
    }

    return $sql;
}

/**
 * Leere Tabellen verstecken genau die Fehler, die beim Umbau von Daten
 * auftreten -- eine NOT-NULL-Spalte ohne Vorgabe faellt erst auf, wenn Zeilen
 * da sind.
 */
function beispieldatenAnlegen(PDO $pdo, string $prefix): void
{
    // Die Spalten sind die von 1.1.3, nicht die von heute: members hiess die
    // Aktivkennzeichnung noch `active`, und appointments kannte keine type_id.
    $pdo->exec("INSERT INTO {$prefix}users (email, name, password_hash, role, is_active, account_status)
                VALUES ('admin@example.test', 'Admin', 'x', 'admin', 1, 'active')");

    $pdo->exec("INSERT INTO {$prefix}member_groups (group_name, is_default)
                VALUES ('Blasorchester', 1), ('Vorstand', 0)");
    $pdo->exec("INSERT INTO {$prefix}appointment_types (type_name, is_default)
                VALUES ('Probe', 1), ('Konzert', 0)");

    $mitglied = $pdo->prepare("INSERT INTO {$prefix}members (member_number, name, surname, active)
                               VALUES (?, ?, ?, ?)");
    for ($i = 1; $i <= 5; $i++) {
        // Ein inaktives Mitglied ist dabei: Die Mitgliedschaftszeitraeume
        // entstehen erst unterwegs und leiten sich aus diesem Feld ab.
        $mitglied->execute(["M00{$i}", "Vorname{$i}", "Nachname{$i}", $i === 5 ? 0 : 1]);
    }

    $pdo->exec("INSERT INTO {$prefix}member_group_assignments (member_id, group_id)
                SELECT m.member_id, 1 FROM {$prefix}members m");

    $pdo->exec("INSERT INTO {$prefix}appointments (title, date, start_time, created_by)
                VALUES ('Gesamtprobe', '2026-03-01', '19:30:00', 1),
                       ('Fruehjahrskonzert', '2026-03-15', '19:00:00', 1)");

    $pdo->exec("INSERT INTO {$prefix}records (member_id, appointment_id, arrival_time, status)
                SELECT m.member_id, 1, '2026-03-01 19:28:00', 'present'
                FROM {$prefix}members m LIMIT 3");

    $pdo->exec("INSERT INTO {$prefix}exceptions
                    (member_id, appointment_id, exception_type, reason, status, created_by)
                VALUES (1, 2, 'absence', 'Urlaub', 'pending', 1),
                       (2, 1, 'time_correction', 'Stau', 'approved', 1)");
}

/** Faehrt die Kette so, wie der Update-Wizard es tut. */
function ketteFahren(PDO $pdo, string $prefix, string $ziel, string $konfig, string $wurzel): string
{
    $manifest = loadMigrationManifest($wurzel . '/private/migrations/manifest.php');
    $von      = detectDbVersion($pdo, $prefix);
    $schritte = resolveMigrationChain($von, $ziel, $manifest);

    echo 'Kette ab ' . $von . ': ' . count($schritte) . " Schritt(e)\n";

    foreach ($schritte as $schritt) {
        require_once $wurzel . '/private/migrations/' . $schritt['file'];

        if (!function_exists($schritt['function'])) {
            throw new RuntimeException("Funktion {$schritt['function']}() fehlt in {$schritt['file']}");
        }

        $ergebnis = ($schritt['function'])($pdo, $prefix, $konfig);
        stampSchemaVersion($pdo, $prefix, $schritt['to']);

        $warnungen = count($ergebnis['warnings'] ?? []);
        printf("  %-8s -> %-8s %s\n", $schritt['from'], $schritt['to'],
               $warnungen ? "({$warnungen} Warnung(en))" : '');

        foreach ($ergebnis['warnings'] ?? [] as $warnung) {
            echo '      ! ' . strip_tags((string) $warnung) . "\n";
        }
    }

    return (string) latestSchemaVersion(readSchemaVersions($pdo, $prefix));
}

/** Spalten je Tabelle, Reihenfolge bewusst ignoriert. */
function spaltenLesen(PDO $pdo, string $datenbank): array
{
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
                COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?"
    );
    $stmt->execute([$datenbank]);

    $struktur = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
        $struktur[$zeile['TABLE_NAME']][$zeile['COLUMN_NAME']] = sprintf(
            '%s | null=%s | default=%s | extra=%s | generiert=%s',
            strtolower((string) $zeile['COLUMN_TYPE']),
            $zeile['IS_NULLABLE'],
            $zeile['COLUMN_DEFAULT'] ?? '-',
            strtolower(trim((string) $zeile['EXTRA'])),
            preg_replace('/\s+/', ' ', (string) $zeile['GENERATION_EXPRESSION']) ?: '-'
        );
    }

    foreach ($struktur as &$spalten) {
        ksort($spalten);
    }
    unset($spalten);
    ksort($struktur);

    return $struktur;
}

/** Indizes je Tabelle. Der Name zaehlt, die Spaltenfolge innerhalb des Index auch. */
function indizesLesen(PDO $pdo, string $datenbank): array
{
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
    );
    $stmt->execute([$datenbank]);

    $roh = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
        $roh[$zeile['TABLE_NAME']][$zeile['INDEX_NAME']]['unique']    = $zeile['NON_UNIQUE'] ? 'nein' : 'ja';
        $roh[$zeile['TABLE_NAME']][$zeile['INDEX_NAME']]['spalten'][] = $zeile['COLUMN_NAME'];
    }

    $struktur = [];
    foreach ($roh as $tabelle => $indizes) {
        foreach ($indizes as $name => $teil) {
            $struktur[$tabelle][$name] = sprintf('unique=%s | (%s)',
                                                 $teil['unique'], implode(', ', $teil['spalten']));
        }
        ksort($struktur[$tabelle]);
    }
    ksort($struktur);

    return $struktur;
}

/** Beschreibt den Unterschied zweier Zuordnungen; leerer String heisst gleich. */
function unterschied(array $aktualisiert, array $frisch): string
{
    $zeilen = [];

    foreach ($frisch as $schluessel => $erwartet) {
        if (!array_key_exists($schluessel, $aktualisiert)) {
            $zeilen[] = "  fehlt nach dem Update: {$schluessel} ({$erwartet})";
        } elseif ($aktualisiert[$schluessel] !== $erwartet) {
            $zeilen[] = "  {$schluessel}\n      frisch:       {$erwartet}"
                      . "\n      aktualisiert: {$aktualisiert[$schluessel]}";
        }
    }

    foreach ($aktualisiert as $schluessel => $vorhanden) {
        if (!array_key_exists($schluessel, $frisch)) {
            $zeilen[] = "  nur nach dem Update da: {$schluessel} ({$vorhanden})";
        }
    }

    return $zeilen ? "\n" . implode("\n", $zeilen) : '';
}

// ============================================================================

$konfig = konfigurationsKopie($wurzel);
$server = verbindung($dsn, $dbUser, $dbPass);

echo 'Kettentest, Praefix ' . $prefix . ': ' . START_VERSION . " -> {$zielVersion}\n";
echo '  Weg A  ' . DB_AKTUALISIERT . " (aus " . START_VERSION . " aktualisiert)\n";
echo '  Weg B  ' . DB_FRISCH . " (frisch installiert)\n\n";

try {
    frischeDatenbank($server, DB_AKTUALISIERT);
    frischeDatenbank($server, DB_FRISCH);

    $a = verbindung($dsn, $dbUser, $dbPass, DB_AKTUALISIERT);
    $b = verbindung($dsn, $dbUser, $dbPass, DB_FRISCH);

    schemaEinspielen($a, schemaAusTag($wurzel, 'v' . START_VERSION), $prefix);
    beispieldatenAnlegen($a, $prefix);
    ensureSchemaVersionTable($a, $prefix);
    stampSchemaVersion($a, $prefix, START_VERSION);

    schemaEinspielen($b, (string) file_get_contents($wurzel . '/private/setup/ehrensache_db.sql'), $prefix);

    $erreicht = ketteFahren($a, $prefix, $zielVersion, $konfig, $wurzel);
    echo "\n";

    test('Die Kette fuehrt von ' . START_VERSION . " bis {$zielVersion}",
    function () use ($erreicht, $zielVersion) {
        assertSame($zielVersion, $erreicht, 'Endstand der Kette');
    });

    $spaltenA = spaltenLesen($a, DB_AKTUALISIERT);
    $spaltenB = spaltenLesen($b, DB_FRISCH);
    $indizesA = indizesLesen($a, DB_AKTUALISIERT);
    $indizesB = indizesLesen($b, DB_FRISCH);

    test('Beide Wege erzeugen dieselben Tabellen', function () use ($spaltenA, $spaltenB) {
        $nurFrisch       = array_diff(array_keys($spaltenB), array_keys($spaltenA));
        $nurAktualisiert = array_diff(array_keys($spaltenA), array_keys($spaltenB));

        assertSame('', implode(', ', $nurFrisch), 'Tabellen, die nach dem Update fehlen');
        assertSame('', implode(', ', $nurAktualisiert), 'Tabellen, die es nur nach dem Update gibt');
    });

    foreach (array_keys($spaltenB) as $tabelle) {
        test("Spalten stimmen ueberein: {$tabelle}",
        function () use ($tabelle, $spaltenA, $spaltenB) {
            assertSame('', unterschied($spaltenA[$tabelle] ?? [], $spaltenB[$tabelle]),
                       "Spalten in {$tabelle}");
        });

        test("Indizes stimmen ueberein: {$tabelle}",
        function () use ($tabelle, $indizesA, $indizesB) {
            assertSame('', unterschied($indizesA[$tabelle] ?? [], $indizesB[$tabelle] ?? []),
                       "Indizes in {$tabelle}");
        });
    }
} finally {
    if ($behalten) {
        echo "\nDatenbanken bleiben stehen (--keep): " . DB_AKTUALISIERT . ', ' . DB_FRISCH . "\n";
    } else {
        $server->exec('DROP DATABASE IF EXISTS `' . DB_AKTUALISIERT . '`');
        $server->exec('DROP DATABASE IF EXISTS `' . DB_FRISCH . '`');
    }
    @unlink($konfig);
}

exit(harnessSummary());
