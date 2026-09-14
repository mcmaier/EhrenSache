<?php
/**
 * EhrenSache - Prueft die Migration auf die neue Konfigurationsform (1.5.1 -> 1.6.0).
 *
 * Die echte private/config/config.php wird NICHT angefasst: Die Migration
 * bekommt Kopien im Temp-Verzeichnis. Muster wie in
 * tests/db/verify_schema_convergence.php. Eine Datenbank braucht die
 * Migration nicht; sie bekommt eine SQLite-Verbindung im Speicher, weil die
 * Signatur ein PDO verlangt.
 *
 * Aufruf: php tests/db/verify_config_migration.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/config_reader.php';
require_once __DIR__ . '/../../private/migrations/1.5.1.php';

$fehler = 0;
$melde  = static function (bool $ok, string $text) use (&$fehler): void {
    echo ($ok ? '  OK   ' : '  FEHL ') . $text . "\n";
    if (!$ok) {
        $fehler++;
    }
};
$pdo       = new PDO('sqlite::memory:');
$vorlage   = (string) file_get_contents(__DIR__ . '/../fixtures/config_legacy_1_5_1.php');
$neueKopie = static function (string $inhalt): string {
    $pfad = sys_get_temp_dir() . '/es_cfgmig_' . uniqid() . '.php';
    file_put_contents($pfad, $inhalt);
    return $pfad;
};
$aufraeumen = static function (string $pfad): void {
    @unlink($pfad);
    @unlink($pfad . '.bak-1.5.1');
};

// --- Fall 1: die unveraenderte Vorlage, wie jede Installation sie hat --------
echo "Fall 1: Vorlage von 1.5.1\n";
$kopie    = $neueKopie($vorlage);
$ergebnis = migrate_1_5_1($pdo, 'your_prefix', $kopie);
$neu      = readConfigFile($kopie);

$melde($neu['format'] === CONFIG_FORMAT_ARRAY,    'config.php liegt danach in der Array-Form vor');
$melde($neu['db']['prefix'] === 'your_prefix',    'Praefix uebernommen');
$melde($neu['db']['pass'] === 'your_password',    'Passwort uebernommen');
$melde($neu['base_url'] === null,                 'base_url bleibt automatisch -- getBaseUrl() nicht als Wert gelesen');
$melde($neu['demo_mode'] === null,                'demo_mode bleibt ungesetzt');
$melde(strpos((string) file_get_contents($kopie), 'getBaseUrl') === false, 'Kein getBaseUrl im Ergebnis');
$melde(is_file($kopie . '.bak-1.5.1'),            'Sicherung wurde angelegt');
$melde(@file_get_contents($kopie . '.bak-1.5.1') === $vorlage, 'Sicherung ist unveraendert');
$melde($ergebnis['warnings'] === [],              'Keine Warnung im Erfolgsfall');
$aufraeumen($kopie);

// --- Fall 2: aktives BASE_URL-Literal und DEMO_MODE bleiben erhalten ----------
echo "Fall 2: gesetzte Schalter\n";
$kopie = $neueKopie(str_replace(
    "// define('DEMO_MODE', true);",
    "define('DEMO_MODE', 1);\ndefine('BASE_URL', 'https://verein.test');",
    $vorlage
));
migrate_1_5_1($pdo, 'your_prefix', $kopie);
$neu = readConfigFile($kopie);

$melde($neu['base_url'] === 'https://verein.test', 'Aktives BASE_URL-Literal uebernommen');
$melde($neu['demo_mode'] === 1,                    'DEMO_MODE mit Rohwert 1 uebernommen, nicht zu true geglaettet');
$aufraeumen($kopie);

// --- Fall 3: bereits neue Form bleibt unangetastet ----------------------------
echo "Fall 3: schon umgestellt\n";
$kopie  = $neueKopie(renderConfigFile([
    'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => 'p', 'prefix' => 'neu_'],
    'base_url' => null, 'demo_mode' => false,
]));
$vorher = file_get_contents($kopie);
migrate_1_5_1($pdo, 'neu_', $kopie);

$melde(file_get_contents($kopie) === $vorher, 'Eine bereits umgestellte Datei bleibt unveraendert');
$melde(!is_file($kopie . '.bak-1.5.1'),       'Keine ueberfluessige Sicherung');
$aufraeumen($kopie);

// --- Fall 4: fehlende Datei meldet eine Warnung -------------------------------
echo "Fall 4: keine Datei\n";
$ergebnis = migrate_1_5_1($pdo, 'es_', sys_get_temp_dir() . '/gibt-es-nicht-' . uniqid() . '.php');
$melde($ergebnis['warnings'] !== [], 'Fehlende Datei erzeugt eine Warnung');

echo $fehler === 0 ? "\nAlles in Ordnung.\n" : "\n{$fehler} Pruefung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
