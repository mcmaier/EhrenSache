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

/**
 * Der einzige Ort, an dem eine Konfigurationsdatei gelesen oder geschrieben wird.
 * Benutzt von bootstrap.php, der Migration, dem Installer und public/update/index.php.
 *
 * Die alte Klassenform wird NIE eingebunden: Ein require würde class Database
 * definieren und mit private/helpers/database.php kollidieren. Deshalb liest
 * dieser Leser sie als Text -- und prüft sie vor der Array-Form, damit ein
 * "return [" irgendwo in einer alten Datei nicht zum require verleitet.
 *
 * Der Update-Assistent liest die Zugangsdaten seit Langem auf dieselbe Weise aus
 * dem Text. Eine Installation, bei der er sich damit verbinden konnte, liest
 * dieser Leser also nachweislich richtig.
 */
declare(strict_types=1);

const CONFIG_FORMAT_ARRAY   = 'array';    // neue Form: return [...]
const CONFIG_FORMAT_LEGACY  = 'legacy';   // alte Form: class Database mit privaten Feldern
const CONFIG_FORMAT_MISSING = 'missing';  // Datei existiert nicht
const CONFIG_FORMAT_UNKNOWN = 'unknown';  // Datei existiert, ist aber keine von beiden

/**
 * Liest eine Konfigurationsdatei und liefert sie normalisiert:
 *   ['db' => [host, name, user, pass, prefix], 'base_url', 'demo_mode', 'format']
 *
 * base_url:  Zeichenkette oder null (= automatisch ermitteln)
 * demo_mode: der Rohwert, oder null (= nicht gesetzt). Bewusst kein Boolean,
 *            siehe demoModeActive() in private/helpers/demo_mode.php.
 *
 * Wirft nie. Der Aufrufer entscheidet anhand von 'format', was ein Fehlschlag bedeutet.
 */
function readConfigFile(string $path): array
{
    $leer = configWithDefaults(['format' => CONFIG_FORMAT_MISSING]);

    if (!is_file($path)) {
        return $leer;
    }

    $text = file_get_contents($path);
    if ($text === false) {
        return $leer;
    }

    // Zuerst die alte Form -- sie darf unter keinen Umständen eingebunden werden.
    if (preg_match('/\bclass\s+Database\b/', $text)) {
        $feld = static function (string $name) use ($text): string {
            return preg_match('/private\s+\$' . $name . '\s*=\s*"([^"]*)"/', $text, $m) ? $m[1] : '';
        };

        return configWithDefaults([
            'db' => [
                'host'   => $feld('host'),
                'name'   => $feld('db_name'),
                'user'   => $feld('username'),
                'pass'   => $feld('password'),
                'prefix' => $feld('prefix'),
            ],
            'base_url'  => configLegacyBaseUrl($text),
            'demo_mode' => configLegacyDemoMode($text),
            'format'    => CONFIG_FORMAT_LEGACY,
        ]);
    }

    // Irgendwo "return [", nicht nur am Zeilenanfang: "<?php return [...];" in
    // einer Zeile ist genauso gültig. Locker zu suchen ist hier ungefährlich,
    // weil die alte Form oben bereits abgefangen ist.
    if (preg_match('/\breturn\s*(\[|array\s*\()/', $text)) {
        // Die Migration schreibt diese Datei und liest sie im selben Request
        // gegen. Mit abgeschalteter Zeitstempelprüfung im Opcache bekäme sie
        // sonst die zwischengespeicherte alte Fassung zurück.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
        $werte = require $path;
        if (is_array($werte)) {
            return configWithDefaults($werte + ['format' => CONFIG_FORMAT_ARRAY]);
        }
    }

    return configWithDefaults(['format' => CONFIG_FORMAT_UNKNOWN]);
}

/**
 * Ergänzt fehlende Schlüssel. Der einzige Ort, an dem Defaults stehen.
 *
 * Liegt hier und nicht im Bootstrap, weil der Bootstrap beim Laden Konstanten
 * definiert; diese Funktion ist seiteneffektfrei und damit direkt prüfbar.
 */
function configWithDefaults(array $cfg): array
{
    $baseUrl = $cfg['base_url'] ?? null;

    return [
        'db' => [
            'host'   => (string) ($cfg['db']['host']   ?? ''),
            'name'   => (string) ($cfg['db']['name']   ?? ''),
            'user'   => (string) ($cfg['db']['user']   ?? ''),
            'pass'   => (string) ($cfg['db']['pass']   ?? ''),
            'prefix' => (string) ($cfg['db']['prefix'] ?? ''),
        ],
        'base_url'  => is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
        'demo_mode' => array_key_exists('demo_mode', $cfg) ? $cfg['demo_mode'] : null,
        'format'    => $cfg['format'] ?? CONFIG_FORMAT_UNKNOWN,
    ];
}

/** Zeilen ohne Kommentare: Blockkommentare entfernt, //- und #-Zeilen übersprungen. */
function configLegacyActiveLines(string $text): array
{
    $ohneBloecke = (string) preg_replace('~/\*.*?\*/~s', '', $text);
    $zeilen = [];
    foreach (preg_split('/\R/', $ohneBloecke) as $zeile) {
        $getrimmt = ltrim($zeile);
        if ($getrimmt === '' || strpos($getrimmt, '//') === 0 || strpos($getrimmt, '#') === 0) {
            continue;
        }
        $zeilen[] = $getrimmt;
    }
    return $zeilen;
}

/**
 * Ein aktives define('BASE_URL', '...') mit einem String-Literal als Wert.
 *
 * Nur Literale zählen. Die Vorlage bis 1.5.1 endet mit
 *   define('BASE_URL', getBaseUrl());
 * -- aktiv, aber ein Ausdruck. Das heißt „automatisch ermitteln" und ergibt null.
 */
function configLegacyBaseUrl(string $text): ?string
{
    foreach (configLegacyActiveLines($text) as $zeile) {
        if (preg_match('/define\s*\(\s*[\'"]BASE_URL[\'"]\s*,\s*([\'"])(.*?)\1\s*\)/', $zeile, $m)
            && $m[2] !== '') {
            return $m[2];
        }
    }
    return null;
}

/**
 * Der Rohwert eines aktiven define('DEMO_MODE', ...), oder null wenn keins da ist.
 *
 * true/false und ganze Zahlen werden zu ihrem PHP-Typ, ein String-Literal zur
 * Zeichenkette. Alles andere bleibt als Text stehen -- das ist ein Wert ungleich
 * false und schaltet den Modus damit zur sicheren Seite ein, wie demoModeActive()
 * es für unsaubere Werte vorsieht.
 *
 * @return mixed
 */
function configLegacyDemoMode(string $text)
{
    foreach (configLegacyActiveLines($text) as $zeile) {
        if (!preg_match('/define\s*\(\s*[\'"]DEMO_MODE[\'"]\s*,\s*(.+?)\s*\)\s*;/', $zeile, $m)) {
            continue;
        }
        $roh = trim($m[1]);
        if (strcasecmp($roh, 'true') === 0) {
            return true;
        }
        if (strcasecmp($roh, 'false') === 0) {
            return false;
        }
        if (preg_match('/^-?\d+$/', $roh)) {
            return (int) $roh;
        }
        if (preg_match('/^([\'"])(.*)\1$/s', $roh, $s)) {
            return $s[2];
        }
        return $roh;
    }
    return null;
}

/**
 * Ermittelt die Basis-URL aus dem Request. Inhaltlich unverändert aus der
 * früheren getBaseUrl() in config.php übernommen.
 */
function guessBaseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Zwei Ebenen hoch, wie bisher
    $basePath = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = str_replace('//', '/', $basePath);
    if ($basePath === '/' || $basePath === '.' || $basePath === '\\') {
        $basePath = '';
    }

    return $protocol . '://' . $host . $basePath;
}

/** Erzeugt den Inhalt einer config.php in der neuen Form. */
function renderConfigFile(array $cfg): string
{
    $cfg = configWithDefaults($cfg);
    $w   = static fn($wert): string => var_export($wert, true);

    return "<?php\n\n"
        . "/**\n"
        . " * EhrenSache - Konfiguration dieser Installation\n"
        . " *\n"
        . " * Diese Datei enthält ausschließlich Daten. Der Programmcode dazu liegt in\n"
        . " * private/helpers/bootstrap.php und private/helpers/database.php und wird\n"
        . " * bei jedem Update mit ausgetauscht.\n"
        . " *\n"
        . " * Fehlende Schlüssel sind unkritisch: Der Bootstrap füllt sie mit Defaults.\n"
        . " */\n\n"
        . "return [\n"
        . "    'db' => [\n"
        . "        'host'   => " . $w($cfg['db']['host'])   . ",\n"
        . "        'name'   => " . $w($cfg['db']['name'])   . ",\n"
        . "        'user'   => " . $w($cfg['db']['user'])   . ",\n"
        . "        'pass'   => " . $w($cfg['db']['pass'])   . ",\n"
        . "        'prefix' => " . $w($cfg['db']['prefix']) . ",\n"
        . "    ],\n\n"
        . "    // null = Basis-URL automatisch aus dem Request ermitteln.\n"
        . "    'base_url' => " . $w($cfg['base_url']) . ",\n\n"
        . "    // Demo-Modus für öffentlich erreichbare Installationen.\n"
        . "    // false oder null = aus. Jeder andere Wert gilt absichtlich als an,\n"
        . "    // siehe private/helpers/demo_mode.php.\n"
        . "    'demo_mode' => " . $w($cfg['demo_mode']) . ",\n"
        . "];\n";
}
