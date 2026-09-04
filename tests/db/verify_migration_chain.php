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

// --- Migration 1.2.0: Gruppenbindung der Taetigkeitsarten -------------------
check('Tabelle activity_type_groups wurde angelegt', true,
      (bool) $pdo->query("SHOW TABLES LIKE '" . PREFIX . "activity_type_groups'")->rowCount());

// Der Bestand muss ALLEN Gruppen zugeordnet sein, sonst verschwaenden
// Taetigkeiten in bestehenden Installationen.
$arten   = (int) $pdo->query("SELECT COUNT(*) FROM `" . PREFIX . "activity_types`")->fetchColumn();
$gruppen = (int) $pdo->query("SELECT COUNT(*) FROM `" . PREFIX . "member_groups`")->fetchColumn();
$zuord   = (int) $pdo->query("SELECT COUNT(*) FROM `" . PREFIX . "activity_type_groups`")->fetchColumn();

check('jede Taetigkeitsart ist jeder Gruppe zugeordnet', $arten * $gruppen, $zuord);

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

// --- UPD-6: Loeschfrist wandert von dsgvo-cleanup-years mit ------------------
//
// Testplan DL-M7. Ein Verein, der die Frist von Hand auf 7 Jahre gestellt hat,
// darf nach dem Update nicht stillschweigend wieder bei 3 stehen.
echo "\nUPD-6: Bestandsfrist beim Sprung auf 1.2.5\n";
resetDb();
$pdo = db(DB);
applySchema($pdo, PREFIX);

// Ausgangslage 1.2.4 herstellen: die drei Schluessel aus dem 1.2.5-Schema
// wieder entfernen und den alten mit einem eigenen Wert setzen.
$pdo->exec("DELETE FROM `" . PREFIX . "system_settings`
             WHERE setting_key IN ('cleanup_years_records','cleanup_years_worktime','cleanup_years_audit')");
$pdo->prepare('INSERT INTO `' . PREFIX . "system_settings`
                   (setting_key, setting_value, setting_type, category, description)
               VALUES ('dsgvo-cleanup-years', ?, 'number', 'general', 'Loeschfrist in Jahren')")
    ->execute(['7']);
$pdo->exec('DELETE FROM `' . PREFIX . 'schema_version`');
$pdo->prepare('INSERT INTO `' . PREFIX . 'schema_version` (version) VALUES (?)')->execute(['1.2.4']);

check('Ausgangslage wird als 1.2.4 erkannt', '1.2.4', detectDbVersion($pdo, PREFIX));

$res = runWizardStep3($pdo, PREFIX, $target);

/** Liest eine Einstellung aus der Wegwerf-Datenbank. */
$setting = static function (PDO $pdo, string $key) {
    $stmt = $pdo->prepare('SELECT setting_value FROM `' . PREFIX . 'system_settings` WHERE setting_key = ?');
    $stmt->execute([$key]);

    return $stmt->fetchColumn();
};

check('eigener Wert 7 wandert nach cleanup_years_records', '7', $setting($pdo, 'cleanup_years_records'));
check('cleanup_years_worktime steht auf der Vorgabe 3',     '3', $setting($pdo, 'cleanup_years_worktime'));
check('cleanup_years_audit steht auf der Vorgabe 1',        '1', $setting($pdo, 'cleanup_years_audit'));
check('alter Schluessel ist entfernt', false, $setting($pdo, 'dsgvo-cleanup-years'));

// runWizardStep3() legt die Protokollzeilen durch strip_tags — die <code>-Tags
// der Migration stehen hier also nicht mehr drin.
$protokoll = implode("\n", $res['log']);
check('Protokoll meldet die Uebernahme', true,
      str_contains($protokoll, 'cleanup_years_records auf 7 gesetzt')
      && str_contains($protokoll, 'Wert aus dsgvo-cleanup-years übernommen'));
check('Protokoll meldet die Entfernung des alten Schluessels', true,
      str_contains($protokoll, 'Alter Schlüssel dsgvo-cleanup-years entfernt'));
check('kein unbrauchbarer Wert, also keine Warnung', [], $res['warnings']);

// Unbrauchbarer Bestandswert: Vorgabe statt Uebernahme, mit Warnung.
resetDb();
$pdo = db(DB);
applySchema($pdo, PREFIX);
$pdo->exec("DELETE FROM `" . PREFIX . "system_settings`
             WHERE setting_key IN ('cleanup_years_records','cleanup_years_worktime','cleanup_years_audit')");
$pdo->prepare('INSERT INTO `' . PREFIX . "system_settings`
                   (setting_key, setting_value, setting_type, category, description)
               VALUES ('dsgvo-cleanup-years', ?, 'number', 'general', 'Loeschfrist in Jahren')")
    ->execute(['0']);
$pdo->exec('DELETE FROM `' . PREFIX . 'schema_version`');
$pdo->prepare('INSERT INTO `' . PREFIX . 'schema_version` (version) VALUES (?)')->execute(['1.2.4']);

$res = runWizardStep3($pdo, PREFIX, $target);

check('unbrauchbare Bestandsfrist faellt auf 3 zurueck', '3', $setting($pdo, 'cleanup_years_records'));
check('unbrauchbare Bestandsfrist erzeugt eine Warnung', 1, count($res['warnings']));

// Neuer Schluessel steht schon da. Im Feld kann das nicht vorkommen — wohl
// aber auf einem Entwicklungsstand, auf dem die 1.2.5-Oberflaeche gegen eine
// 1.2.4-Datenbank lief und die Schluessel beim Speichern angelegt hat.
// INSERT IGNORE laesst den vorhandenen Wert stehen; das Protokoll darf dann
// nicht behaupten, es haette den alten uebernommen.
resetDb();
$pdo = db(DB);
applySchema($pdo, PREFIX);
$pdo->prepare('UPDATE `' . PREFIX . "system_settings` SET setting_value = ? WHERE setting_key = 'cleanup_years_records'")
    ->execute(['9']);
$pdo->prepare('INSERT INTO `' . PREFIX . "system_settings`
                   (setting_key, setting_value, setting_type, category, description)
               VALUES ('dsgvo-cleanup-years', ?, 'number', 'general', 'Loeschfrist in Jahren')")
    ->execute(['7']);
$pdo->exec('DELETE FROM `' . PREFIX . 'schema_version`');
$pdo->prepare('INSERT INTO `' . PREFIX . 'schema_version` (version) VALUES (?)')->execute(['1.2.4']);

$res       = runWizardStep3($pdo, PREFIX, $target);
$protokoll = implode("\n", $res['log']);

check('vorhandener Wert 9 bleibt stehen', '9', $setting($pdo, 'cleanup_years_records'));
check('Protokoll behauptet keine Uebernahme', false, str_contains($protokoll, 'übernommen'));
check('Protokoll nennt den vorhandenen Wert', true,
      str_contains($protokoll, 'cleanup_years_records existiert bereits'));
check('verworfener Bestandswert erzeugt eine Warnung', 1, count($res['warnings']));

// --- Aufraeumen --------------------------------------------------------------
db()->exec('DROP DATABASE IF EXISTS `' . DB . '`');
@unlink(testConfigPath());
echo "\nWegwerf-Datenbank " . DB . " entfernt.\n";

echo "\n", ($fails === 0 ? 'ALLE PRUEFUNGEN BESTANDEN' : "{$fails} PRUEFUNG(EN) FEHLGESCHLAGEN"), "\n";
exit($fails === 0 ? 0 : 1);
