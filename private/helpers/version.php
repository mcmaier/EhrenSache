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

function getVersion()
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

    echo json_encode($version);
    exit();
}
?>