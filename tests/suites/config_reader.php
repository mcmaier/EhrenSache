<?php
/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/config_reader.php';

// Die Tests zur alten Klassenform bleiben dauerhaft: migrate_1_5_1() und der
// Update-Assistent lesen die config.php einer Installation bis 1.5.1 ueber diesen
// Leser. Ohne ihn waere der direkte Sprung von dort auf eine spaetere Version
// unmoeglich (docs/superpowers/specs/2026-09-14-direktsprung-requires-design.md).

/** Legt eine Konfigurationsdatei mit dem gegebenen Inhalt an und gibt den Pfad zurueck. */
function configReaderFixture(string $inhalt): string
{
    $pfad = tempnam(sys_get_temp_dir(), 'escfg') . '.php';
    file_put_contents($pfad, $inhalt);
    return $pfad;
}

/** Minimale alte Klassenform; $vorspann steht vor der Klasse. */
function configReaderLegacy(string $vorspann = '', string $prefix = 'es_'): string
{
    return "<?php\n" . $vorspann
        . "class Database {\n"
        . "    private \$host = \"localhost\";\n"
        . "    private \$db_name = \"ehrensache\";\n"
        . "    private \$username = \"root\";\n"
        . "    private \$password = \"geheim\";\n"
        . "    private \$prefix =\"{$prefix}\";\n}\n";
}

test('readConfigFile erkennt die neue Array-Form', function () {
    $pfad = configReaderFixture('<?php return ['
        . '"db" => ["host" => "h", "name" => "n", "user" => "u", "pass" => "p", "prefix" => "es_"],'
        . '"base_url" => "https://beispiel.test", "demo_mode" => true];');

    $cfg = readConfigFile($pfad);
    unlink($pfad);

    assertSame(CONFIG_FORMAT_ARRAY, $cfg['format']);
    assertSame('es_', $cfg['db']['prefix']);
    assertSame('https://beispiel.test', $cfg['base_url']);
    assertSame(true, $cfg['demo_mode']);
});

test('readConfigFile erkennt die alte Klassenform', function () {
    $pfad = configReaderFixture(configReaderLegacy());

    $cfg = readConfigFile($pfad);
    unlink($pfad);

    assertSame(CONFIG_FORMAT_LEGACY, $cfg['format']);
    assertSame('localhost', $cfg['db']['host']);
    assertSame('ehrensache', $cfg['db']['name']);
    assertSame('root', $cfg['db']['user']);
    assertSame('geheim', $cfg['db']['pass']);
    assertSame('es_', $cfg['db']['prefix']);
});

test('readConfigFile bindet die Klassenform nie ein, auch mit return [ darin', function () {
    // Wuerde der Leser require benutzen, existierte die Klasse danach und
    // kollidierte mit private/helpers/database.php. Das "return []" in der
    // Funktion darf den Leser nicht dazu verleiten, die Datei als Array-Form
    // zu laden -- die alte Form wird deshalb zuerst erkannt.
    $pfad = configReaderFixture(configReaderLegacy(
        "class KonfigurationsLeserProbe {}\nfunction konfigurationsLeserProbe() {\n    return [];\n}\n"
    ));

    $cfg = readConfigFile($pfad);
    unlink($pfad);

    assertSame(CONFIG_FORMAT_LEGACY, $cfg['format']);
    assertSame(false, class_exists('KonfigurationsLeserProbe', false));
});

test('readConfigFile uebernimmt ein aktives BASE_URL-Literal', function () {
    $pfad = configReaderFixture(configReaderLegacy("define('BASE_URL', 'https://verein.test');\n"));

    $cfg = readConfigFile($pfad);
    unlink($pfad);

    assertSame('https://verein.test', $cfg['base_url']);
});

test('readConfigFile ignoriert auskommentierte defines', function () {
    $pfad = configReaderFixture(configReaderLegacy(
        "// define('BASE_URL', 'http://localhost/ehrensache');\n"
        . "# define('DEMO_MODE', true);\n"
        . "/*\ndefine('BASE_URL', 'http://im-blockkommentar.test');\n*/\n"
    ));

    $cfg = readConfigFile($pfad);
    unlink($pfad);

    assertSame(null, $cfg['base_url']);
    assertSame(null, $cfg['demo_mode']);
});

test('readConfigFile liest die echte Vorlage von 1.5.1 richtig', function () {
    // Die Vorlage endet mit define('BASE_URL', getBaseUrl()); -- aktiv, aber
    // ein Ausdruck. Wer das als Wert nimmt, schreibt 'getBaseUrl(' in jede
    // migrierte config.php und bricht alle Mail-Links.
    $cfg = readConfigFile(__DIR__ . '/../fixtures/config_legacy_1_5_1.php');

    assertSame(CONFIG_FORMAT_LEGACY, $cfg['format']);
    assertSame(null, $cfg['base_url'], 'Der Ausdruck getBaseUrl() wurde als URL uebernommen');
    assertSame(null, $cfg['demo_mode'], 'DEMO_MODE steht in der Vorlage nur auskommentiert');
    assertSame('your_host', $cfg['db']['host']);
    assertSame('your_database', $cfg['db']['name']);
    assertSame('your_username', $cfg['db']['user']);
    assertSame('your_password', $cfg['db']['pass']);
    assertSame('your_prefix', $cfg['db']['prefix']);
});

test('readConfigFile behaelt den Rohwert von DEMO_MODE', function () {
    // demoModeActive() behandelt jeden Wert ausser false als eingeschaltet.
    // Der Leser darf daraus kein Boolean machen, sonst waere 0 still "aus".
    $faelle = [
        "define('DEMO_MODE', true);"    => true,
        "define('DEMO_MODE', FALSE);"   => false,
        "define('DEMO_MODE', 0);"       => 0,
        "define('DEMO_MODE', 1);"       => 1,
        "define('DEMO_MODE', 'false');" => 'false',
    ];

    foreach ($faelle as $zeile => $erwartet) {
        $pfad = configReaderFixture(configReaderLegacy($zeile . "\n"));
        $cfg  = readConfigFile($pfad);
        unlink($pfad);
        assertSame($erwartet, $cfg['demo_mode'], "Fall: {$zeile}");
    }
});

test('readConfigFile meldet eine fehlende Datei', function () {
    $cfg = readConfigFile(sys_get_temp_dir() . '/gibt-es-nicht-' . uniqid() . '.php');

    assertSame(CONFIG_FORMAT_MISSING, $cfg['format']);
    assertSame(null, $cfg['demo_mode']);
});

test('readConfigFile meldet eine unbrauchbare Datei', function () {
    $pfad = configReaderFixture("<?php\n// hier steht nichts Verwertbares\n");

    $cfg = readConfigFile($pfad);
    unlink($pfad);

    assertSame(CONFIG_FORMAT_UNKNOWN, $cfg['format']);
});

test('renderConfigFile erzeugt eine Datei, die readConfigFile wieder versteht', function () {
    foreach ([false, 0, true, null] as $demo) {
        $pfad = configReaderFixture(renderConfigFile([
            'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => "p'\"\\\$x",
                     'prefix' => 'es_'],
            'base_url'  => null,
            'demo_mode' => $demo,
        ]));

        $cfg = readConfigFile($pfad);
        unlink($pfad);

        assertSame(CONFIG_FORMAT_ARRAY, $cfg['format']);
        assertSame("p'\"\\\$x", $cfg['db']['pass']);
        assertSame(null, $cfg['base_url']);
        assertSame($demo, $cfg['demo_mode'], 'demo_mode ' . var_export($demo, true));
    }
});

test('configWithDefaults fuellt fehlende Schluessel', function () {
    // So sieht eine config.php aus, wenn eine spaetere Version einen Schalter
    // ergaenzt, den diese Installation noch nicht kennt. Sie darf nicht brechen.
    $cfg = configWithDefaults([
        'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => 'p', 'prefix' => 'es_'],
        'format' => CONFIG_FORMAT_ARRAY,
    ]);

    assertSame(null, $cfg['base_url']);
    assertSame(null, $cfg['demo_mode']);
    assertSame(null, $cfg['demo_station_token']);
    assertSame('es_', $cfg['db']['prefix']);
});

test('configWithDefaults bringt demo_station_token auf String oder null', function () {
    assertSame('abc', configWithDefaults(['demo_station_token' => '  abc '])['demo_station_token']);
    assertSame(null, configWithDefaults(['demo_station_token' => '   '])['demo_station_token']);
    assertSame(null, configWithDefaults(['demo_station_token' => 123])['demo_station_token']);
});

test('configWithDefaults laesst gesetzte Werte unangetastet', function () {
    $cfg = configWithDefaults([
        'db' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => 'p', 'prefix' => 'es_'],
        'base_url'  => 'https://verein.test',
        'demo_mode' => 0,
        'format'    => CONFIG_FORMAT_ARRAY,
    ]);

    assertSame('https://verein.test', $cfg['base_url']);
    assertSame(0, $cfg['demo_mode']);
});

test('guessBaseUrl baut die Adresse aus dem Request', function () {
    // Wie die fruehere getBaseUrl(): zwei Ebenen ueber SCRIPT_NAME. Von
    // /ehrensache/public/api/api.php sind das /ehrensache/public. Das ist das
    // Verhalten bis 1.5.1 und wird unveraendert festgehalten.
    $sicherung = $_SERVER;

    $_SERVER['HTTPS']       = 'on';
    $_SERVER['HTTP_HOST']   = 'verein.test';
    $_SERVER['SCRIPT_NAME'] = '/ehrensache/public/api/api.php';
    assertSame('https://verein.test/ehrensache/public', guessBaseUrl());

    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_HOST']   = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/public/api/api.php';
    assertSame('http://localhost/public', guessBaseUrl());

    $_SERVER = $sicherung;
});

test('configLegacyDefineRaw liefert den Rohwert eines beliebigen defines', function () {
    $text = "<?php\n"
        . "// define('AUTO_CHECKIN_TOLERANCE_HOURS', 7);\n"
        . "define('AUTO_CHECKIN_TOLERANCE_HOURS', 4);\n"
        . "define('ALS_TEXT', '3');\n"
        . "define('AUSDRUCK', getTol());\n";

    assertSame(4, configLegacyDefineRaw($text, 'AUTO_CHECKIN_TOLERANCE_HOURS'));
    assertSame('3', configLegacyDefineRaw($text, 'ALS_TEXT'));
    assertSame('getTol()', configLegacyDefineRaw($text, 'AUSDRUCK'));
    assertSame(null, configLegacyDefineRaw($text, 'FEHLT'));
});
