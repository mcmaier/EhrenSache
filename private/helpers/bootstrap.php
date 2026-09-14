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
 * Einstiegspunkt für alles, was Konfiguration braucht. Ersetzt das frühere
 * require auf private/config/config.php.
 *
 * Fehlende Schlüssel füllt configWithDefaults() -- eine bestehende config.php
 * bricht deshalb nicht, wenn eine spätere Version einen Schalter ergänzt.
 *
 * Bewusst keine globale $database: seed.php wird auch aus cron.php eingebunden,
 * und nach einem früheren require_once existierte eine hier gesetzte Variable im
 * Gültigkeitsbereich des Aufrufers nicht. appConfig() gilt überall.
 */
declare(strict_types=1);

require_once __DIR__ . '/config_reader.php';
require_once __DIR__ . '/database.php';

// Überschreibbar, damit tests/suites/bootstrap.php den Bootstrap gegen eine
// eigene Datei laden kann.
if (!defined('CONFIG_PATH_DEFAULT')) {
    define('CONFIG_PATH_DEFAULT', __DIR__ . '/../config/config.php');
}

/** Die Konfiguration dieser Installation, einmal gelesen. */
function appConfig(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = readConfigFile(CONFIG_PATH_DEFAULT);
    }
    return $cfg;
}

if (!defined('BASE_URL')) {
    define('BASE_URL', appConfig()['base_url'] ?? guessBaseUrl());
}

// Rohwert durchreichen: demoModeActive() unterscheidet "nicht gesetzt", false,
// true und unsaubere Werte. null heißt nicht gesetzt -- dann keine Konstante.
if (appConfig()['demo_mode'] !== null && !defined('DEMO_MODE')) {
    define('DEMO_MODE', appConfig()['demo_mode']);
}
