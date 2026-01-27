<?php

/**
 * EhrenSache Demo Reset Script (PHP)
 * 
 * Führt das SQL-Reset-Skript aus und loggt den Vorgang.
 * Für Cron-Job: * /30 * * * * /usr/bin/php /pfad/zu/demo_reset.php
 */

require_once '../config/config.php';

// Konfiguration
define('SQL_FILE', 'demo_reset.sql');
define('LOG_FILE', 'demo_reset.log');
define('LAST_RESET_FILE', 'last_reset.txt');

// Nur via CLI erlauben (Sicherheit)
if (php_sapi_name() !== 'cli' && !isset($_GET['secret_key'])) {
    die('Access denied. Use CLI or provide secret key.');
}


/**
 * Logging-Funktion
 */
function logMessage($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message\n";
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
    echo $logEntry;
}

/**
 * Demo-Datenbank zurücksetzen
 */
function resetDatabase() {
    try {
        logMessage('Starting database reset...');
        
        // SQL-Datei prüfen
        if (!file_exists(SQL_FILE)) {
            throw new Exception('SQL file not found: ' . SQL_FILE);
        }
        
        // Datenbankverbindung
        $database = new Database();
        $db = $database->getConnection();
        
        logMessage('Database connection established');
        
        // SQL-Datei einlesen
        $sql = file_get_contents(SQL_FILE);
        if ($sql === false) {
            throw new Exception('Could not read SQL file');
        }
        
        logMessage('SQL file loaded (' . strlen($sql) . ' bytes)');
        
        // SQL ausführen (Multi-Query)
        $db->exec($sql);
        
        logMessage('SQL executed successfully');
        
        // Statistiken abrufen
        $stats = [];
        $tables = ['members', 'appointments', 'records', 'member_groups', 'exceptions'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $stats[$table] = $stmt->fetch()['count'];
        }
        
        logMessage('Statistics: ' . json_encode($stats));
        
        // Zeitstempel speichern
        file_put_contents(LAST_RESET_FILE, time());
        logMessage('Timestamp saved to ' . LAST_RESET_FILE);
        
        // Erfolg
        logMessage('Database reset completed successfully!', 'SUCCESS');
        
        return [
            'success' => true,
            'message' => 'Demo database reset successfully',
            'timestamp' => date('Y-m-d H:i:s'),
            'stats' => $stats
        ];
        
    } catch (PDOException $e) {
        logMessage('Database error: ' . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ];
        
    } catch (Exception $e) {
        logMessage('Error: ' . $e->getMessage(), 'ERROR');
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Zeitpunkt des letzten Resets ermitteln
 */
function getLastResetTime() {
    if (file_exists(LAST_RESET_FILE)) {
        $timestamp = (int)file_get_contents(LAST_RESET_FILE);
        return date('Y-m-d H:i:s', $timestamp);
    }
    return 'Never';
}

// ============================================
// HAUPTPROGRAMM
// ============================================

logMessage('=== Demo Reset Script Started ===');
logMessage('Last reset: ' . getLastResetTime());

$result = resetDatabase();

if (php_sapi_name() !== 'cli') {
    // Web-Aufruf: JSON ausgeben
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
} else {
    // CLI: Ergebnis ausgeben
    echo "\n";
    echo "========================================\n";
    echo "Demo Reset Complete\n";
    echo "========================================\n";
    if ($result['success']) {
        echo "✓ Status: SUCCESS\n";
        echo "✓ Time: " . $result['timestamp'] . "\n";
        echo "\nStatistics:\n";
        foreach ($result['stats'] as $table => $count) {
            echo "  - $table: $count\n";
        }
    } else {
        echo "✗ Status: FAILED\n";
        echo "✗ Error: " . $result['error'] . "\n";
    }
    echo "========================================\n";
}

logMessage('=== Demo Reset Script Finished ===');