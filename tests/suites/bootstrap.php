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

require_once __DIR__ . '/../../private/helpers/database.php';

/**
 * Laedt den Bootstrap in einem eigenen PHP-Prozess gegen die gegebene
 * Konfiguration und liefert BASE_URL, DEMO_MODE und demoModeActive().
 *
 * Der Bootstrap definiert beim Laden Konstanten und laesst sich deshalb nicht
 * in diesen Prozess einbinden. Im Kindprozess wird geprueft, was wirklich
 * zaehlt: welche Konstanten am Ende stehen und was demoModeActive() daraus macht.
 */
function bootstrapProbe(string $configInhalt): array
{
    $wurzel = realpath(__DIR__ . '/../..');
    $config = tempnam(sys_get_temp_dir(), 'esboot') . '.php';
    $skript = tempnam(sys_get_temp_dir(), 'esrun') . '.php';
    file_put_contents($config, $configInhalt);
    file_put_contents($skript, '<?php'
        . "\ndefine('CONFIG_PATH_DEFAULT', " . var_export($config, true) . ');'
        . "\nrequire " . var_export($wurzel . '/private/helpers/bootstrap.php', true) . ';'
        . "\nrequire " . var_export($wurzel . '/private/helpers/demo_mode.php', true) . ';'
        . "\necho json_encode(["
        . "'base_url' => BASE_URL,"
        . "'demo_defined' => defined('DEMO_MODE'),"
        . "'demo_value' => defined('DEMO_MODE') ? DEMO_MODE : null,"
        . "'demo_active' => demoModeActive(),"
        . "'prefix' => appConfig()['db']['prefix'],"
        . "]);\n");

    // Array-Form von proc_open: kein Umweg ueber cmd.exe, dessen Anfuehrungs-
    // zeichen-Regeln Pfade mit Leerzeichen unter Windows zerlegen.
    $prozess = proc_open([PHP_BINARY, $skript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre);
    $ausgabe = stream_get_contents($rohre[1]);
    $fehler  = stream_get_contents($rohre[2]);
    fclose($rohre[1]);
    fclose($rohre[2]);
    proc_close($prozess);
    unlink($config);
    unlink($skript);

    $werte = json_decode((string) $ausgabe, true);
    assertTrue(is_array($werte), "Kindprozess lieferte kein JSON:\n" . $ausgabe . $fehler);
    return is_array($werte) ? $werte : [];
}

test('Database liefert den Tabellennamen mit Praefix, ohne zu verbinden', function () {
    $datenbank = new Database([
        'host' => 'h', 'name' => 'n', 'user' => 'u', 'pass' => 'p', 'prefix' => 'es_',
    ]);

    assertSame('es_members', $datenbank->table('members'));
    assertSame('es_', $datenbank->table(''));
});

test('Bootstrap mit der Vorlage von 1.5.1: BASE_URL wird ermittelt, nicht aus dem Ausdruck gelesen', function () {
    $werte = bootstrapProbe((string) file_get_contents(__DIR__ . '/../fixtures/config_legacy_1_5_1.php'));

    assertTrue(strpos($werte['base_url'], 'getBaseUrl') === false, 'BASE_URL: ' . $werte['base_url']);
    assertTrue(strpos($werte['base_url'], 'http') === 0, 'BASE_URL: ' . $werte['base_url']);
    assertSame(false, $werte['demo_defined']);
    assertSame(false, $werte['demo_active']);
    assertSame('your_prefix', $werte['prefix']);
});

test('Bootstrap uebernimmt base_url aus der Array-Form', function () {
    $werte = bootstrapProbe("<?php return ['db' => ['prefix' => 'es_'], 'base_url' => 'https://verein.test'];");

    assertSame('https://verein.test', $werte['base_url']);
    assertSame(false, $werte['demo_defined']);
});

test('Bootstrap: demo_mode false heisst aus', function () {
    $werte = bootstrapProbe("<?php return ['db' => [], 'demo_mode' => false];");

    assertSame(false, $werte['demo_active']);
});

test('Bootstrap: demo_mode true heisst an', function () {
    $werte = bootstrapProbe("<?php return ['db' => [], 'demo_mode' => true];");

    assertSame(true, $werte['demo_active']);
});

test('Bootstrap: ein unsauberer demo_mode faellt zur sicheren Seite', function () {
    // 0 ist kein bewusstes "aus". demoModeActive() behandelt es als an; der
    // Bootstrap darf den Wert auf dem Weg dorthin nicht zu false glaetten.
    $werte = bootstrapProbe("<?php return ['db' => [], 'demo_mode' => 0];");

    assertSame(true, $werte['demo_defined']);
    assertSame(0, $werte['demo_value']);
    assertSame(true, $werte['demo_active']);
});

test('Bootstrap: ein aktives DEMO_MODE in der alten Form bleibt an', function () {
    // Die Zeile steht hinter der Klasse. Die Datei wird nie ausgefuehrt, nur
    // gelesen -- die Position spielt fuer den Leser keine Rolle.
    $werte = bootstrapProbe((string) file_get_contents(__DIR__ . '/../fixtures/config_legacy_1_5_1.php')
        . "\ndefine('DEMO_MODE', true);\n");

    assertSame(true, $werte['demo_active']);
});
