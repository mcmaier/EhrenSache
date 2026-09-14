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
 * Meldet den Systemzustand.
 *
 * config_format und update_available nur für Rolle admin: Liegt config.php
 * noch in der alten Klassenform vor oder ist aus der letzten Update-Prüfung
 * eine neuere Version bekannt, zeigt das Dashboard dem Admin einen Hinweis.
 * Ein Vereinsmitglied kann damit nichts anfangen und würde nur beunruhigt.
 */
function getVersion(?string $role = null, $db = null, $database = null)
{
    $versionFile = __DIR__ . '/../../version.json';
    if (!file_exists($versionFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Version file not found']);
        exit;
    }

    $version = json_decode(file_get_contents($versionFile), true);
    $version['server_time'] = date('Y-m-d H:i:s');
    $version['php_version'] = PHP_VERSION;

    if ($role === 'admin') {
        $version['config_format'] = appConfig()['format'];
        if ($db !== null && $database !== null) {
            $version['update_available'] = updateCheckStatus($db, $database, (string) $version['version'])['update_available'];
        }
    }

    echo json_encode($version);
    exit();
}
?>