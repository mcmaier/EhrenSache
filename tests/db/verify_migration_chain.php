<?php
/**
 * EhrenSache - Verifikation der Migrationskette gegen eine echte Datenbank
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Nicht Teil von tests/run.php, weil eine laufende Datenbank und ein Konto mit
 * CREATE-DATABASE-Recht nötig sind. Bildet nach, was public/update/index.php in
 * Schritt 3 tut, und prüft das Ergebnis.
 *
 * Aufruf:
 *   php tests/db/verify_migration_chain.php "mysql:host=127.0.0.1;port=3306" root ""
 *
 * Legt die Wegwerf-Datenbank `ehrensache_chaintest` an und entfernt sie am Ende
 * wieder. Bestehende Datenbanken werden nicht berührt; die echte
 * private/config/config.php wird nicht angefasst — die Migration schreibt in
 * eine Kopie unter tests/db/tmp/.
 */
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Aufruf: php tests/db/verify_migration_chain.php <dsn> <user> [password]\n");
    fwrite(STDERR, "Beispiel: php tests/db/verify_migration_chain.php \"mysql:host=127.0.0.1;port=3306\" root \"\"\n");
    exit(2);
}

$dsnBase = rtrim($argv[1], ';');
$dbUser  = $argv[2];
$dbPass  = $argv[3] ?? '';

$repo = dirname(__DIR__, 2);
require_once $repo . '/private/helpers/migrations.php';

const DB     = 'ehrensache_chaintest';
const PREFIX = 'ez_';

$tmpDir = __DIR__ . '/tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}

function db(?string $name = null): PDO
{
    global $dsnBase, $dbUser, $dbPass;
    $dsn = $dsnBase . ';charset=utf8mb4' . ($name !== null ? ";dbname={$name}" : '');
    $pdo = new PDO($dsn, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function resetDb(): void
{
    $pdo = db();
    $pdo->exec('DROP DATABASE IF EXISTS `' . DB . '`');
    $pdo->exec('CREATE DATABASE `' . DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

/** Spielt das Setup-Schema mit dem gegebenen Prefix ein. */
function applySchema(PDO $pdo, string $prefix): void
{
    global $repo;
    $sql = file_get_contents($repo . '/private/setup/ehrensache_db.sql');
    $pdo->exec(str_replace('{PREFIX}', $prefix, $sql));
}

/**
 * Frische config.php im Stand 1.0.0 (ohne $prefix, ohne table()).
 * Die Migration schreibt in diese Datei — niemals in die echte.
 */
function testConfigPath(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }

    $path = __DIR__ . '/tmp/config_under_test.php';
    file_put_contents($path, <<<'PHP'
<?php
class Database {
    private $host = "localhost";
    private $db_name = "ehrensache";
    private $username = "user";
    private $password = "pass";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                                  $this->username, $this->password);
        } catch(PDOException $e) {
            exit();
        }
        return $this->conn;
    }
}

PHP);

    return $path;
}

/** Bildet Schritt 3 des Update-Wizards nach. */
function runWizardStep3(PDO $pdo, string $prefix, string $targetVersion): array
{
    global $repo;
    $log  = [];
    $warn = [];

    $fromVer  = detectDbVersion($pdo, $prefix);
    $manifest = loadMigrationManifest($repo . '/private/migrations/manifest.php');
    $chain    = resolveMigrationChain(normalizeDetectedVersion($fromVer), $targetVersion, $manifest);

    ensureSchemaVersionTable($pdo, $prefix);
    stampSchemaVersion($pdo, $prefix, normalizeDetectedVersion($fromVer));

    if ($chain === []) {
        $log[] = "bereits auf Stand {$targetVersion}";
    }

    foreach ($chain as $step) {
        require_once $repo . '/private/migrations/' . $step['file'];

        if (!function_exists($step['function'])) {
            throw new RuntimeException("Funktion {$step['function']}() fehlt in {$step['file']}");
        }

        $log[]  = "{$step['from']} -> {$step['to']} ({$step['file']})";
        $result = ($step['function'])($pdo, $prefix, testConfigPath());
        $log    = array_merge($log, array_map('strip_tags', $result['log']));
        $warn   = array_merge($warn, array_map('strip_tags', $result['warnings']));

        stampSchemaVersion($pdo, $prefix, $step['to']);
        $log[] = "gestempelt: {$step['to']}";
    }

    return ['from' => $fromVer, 'log' => $log, 'warnings' => $warn];
}

$fails = 0;

function check(string $label, $expected, $actual): void
{
    global $fails;
    $ok = $expected === $actual;
    if (!$ok) {
        $fails++;
    }
    echo ($ok ? '  PASS  ' : '  FAIL  '), $label, "\n";
    if (!$ok) {
        echo '        erwartet ', var_export($expected, true),
             ', erhalten ', var_export($actual, true), "\n";
    }
}

// ============================================================================
$target = json_decode(file_get_contents($repo . '/version.json'), true)['version'];

echo "Datenbank: {$dsnBase}\n";
echo "Zielversion: {$target}\n\n";

// --- UPD-1: Neuinstallation stempelt ihre Version ---------------------------
echo "UPD-1: Neuinstallation\n";
resetDb();
$pdo = db(DB);
applySchema($pdo, PREFIX);

// Der Installer stempelt nach dem Schema-Import; hier dieselbe Logik.
$pdo->prepare('INSERT IGNORE INTO `' . PREFIX . 'schema_version` (version) VALUES (?)')
    ->execute([$target]);

check('schema_version enthaelt genau die installierte Version', [$target], readSchemaVersions($pdo, PREFIX));
check('detectDbVersion meldet die installierte Version', $target, detectDbVersion($pdo, PREFIX));

// --- UPD-2: Wizard bei aktuellem Stand --------------------------------------
echo "\nUPD-2: Wizard bei aktuellem Stand\n";
$res = runWizardStep3($pdo, PREFIX, $target);
check('nichts zu tun', ["bereits auf Stand {$target}"], $res['log']);
check('keine Warnungen', [], $res['warnings']);

// --- UPD-3: Wizard bei Stand 1.0.0 ------------------------------------------
echo "\nUPD-3: Wizard bei Stand 1.0.0\n";
resetDb();
$pdo = db(DB);
applySchema($pdo, '');                      // Tabellen OHNE Prefix
$pdo->exec('DROP TABLE IF EXISTS `schema_version`');

check('Ausgangslage wird als 1.0.0 erkannt', '1.0.0', detectDbVersion($pdo, PREFIX));

$res = runWizardStep3($pdo, PREFIX, $target);
check('Kette startet bei 1.0.0', '1.0.0', $res['from']);

// Erwartung aus dem Manifest ableiten, damit dieser Test bei jeder neuen
// Migration mitwaechst statt zu veralten.
$manifest = loadMigrationManifest($repo . '/private/migrations/manifest.php');
$expectedChain = resolveMigrationChain('1.0.0', $target, $manifest);

foreach ($expectedChain as $step) {
    check("Schritt {$step['from']} -> {$step['to']} wurde ausgefuehrt", true,
          in_array("{$step['from']} -> {$step['to']} ({$step['file']})", $res['log'], true));
    check("Version {$step['to']} wurde gestempelt", true,
          in_array("gestempelt: {$step['to']}", $res['log'], true));
}

$expectedVersions = array_merge(['1.0.0'], array_column($expectedChain, 'to'));
sort($expectedVersions);

$versions = readSchemaVersions($pdo, PREFIX);
sort($versions);
check('schema_version enthaelt Ausgangsstand und jede Zwischenversion', $expectedVersions, $versions);

check('Tabellen wurden auf das Prefix umbenannt', true,
      (bool) $pdo->query("SHOW TABLES LIKE '" . PREFIX . "users'")->rowCount());

$cfg = file_get_contents(testConfigPath());
check('config.php erhielt das Feld $prefix', true, str_contains($cfg, 'private $prefix = "' . PREFIX . '"'));
check('config.php erhielt die Methode table()', true, str_contains($cfg, 'function table('));

// --- UPD-4: zweiter Lauf ist folgenlos --------------------------------------
echo "\nUPD-4: zweiter Lauf\n";
$res = runWizardStep3($pdo, PREFIX, $target);
check('zweiter Lauf meldet nichts zu tun', ["bereits auf Stand {$target}"], $res['log']);
check('zweiter Lauf ohne Warnungen', [], $res['warnings']);

// --- UPD-5: unbestimmbare Version -------------------------------------------
echo "\nUPD-5: leere Datenbank\n";
resetDb();
$pdo = db(DB);
check('detectDbVersion meldet unbekannt', 'unbekannt', detectDbVersion($pdo, PREFIX));

$threw = false;
try {
    runWizardStep3($pdo, PREFIX, $target);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'konnte nicht bestimmt werden');
}
check('Wizard bricht mit klarer Meldung ab', true, $threw);

// --- Aufraeumen --------------------------------------------------------------
db()->exec('DROP DATABASE IF EXISTS `' . DB . '`');
@unlink(testConfigPath());
echo "\nWegwerf-Datenbank " . DB . " entfernt.\n";

echo "\n", ($fails === 0 ? 'ALLE PRUEFUNGEN BESTANDEN' : "{$fails} PRUEFUNG(EN) FEHLGESCHLAGEN"), "\n";
exit($fails === 0 ? 0 : 1);
