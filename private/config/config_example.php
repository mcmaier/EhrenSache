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

// ============================================
// CONFIG EXAMPLE PHP
// ============================================

// Manuelles define der BASE_URL für Email-Links, API-Calls, etc. -
// Auskommentieren, falls automatische Erkennung nicht funktioniert
//------------------------------------------------------------------
// define('BASE_URL', 'http://localhost/ehrensache');

define('AUTO_CHECKIN_TOLERANCE_HOURS', 2);

class Database {
    private $host = "your_host";
    private $db_name = "your_database";
    private $username = "your_username";
    private $password = "your_password";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, 
                                  $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8mb4");
        } catch(PDOException $e) {
            http_response_code(500);
            echo json_encode(["message" => "Database connection error"]);
            exit();
        }
        return $this->conn;
    }
}

// Helper-Funktion für Mail-Config
function getMailConfig() {
    static $config = null;
    if ($config === null) {
        $config = require 'mail_config.php';
    }
    return $config;
}

function getBaseUrl() {
    static $baseUrl = null;
    
    if ($baseUrl === null) {
        // Protokoll
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            ? 'https' 
            : 'http';
        
        // Host
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Pfad ermitteln (bis /public)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';        
        
        // Entferne /public/... vom Pfad
        $basePath = dirname(dirname($scriptName)); // Zwei Ebenen hoch
        
        // Bereinige mehrfache Slashes
        $basePath = str_replace('//', '/', $basePath);
        
        // Falls am Root
        if ($basePath === '/' || $basePath === '.') {
            $basePath = '';
        }
        
        $baseUrl = $protocol . '://' . $host . $basePath;
    }
    
    return $baseUrl;
}

// Als Konstante definieren (für einfachen Zugriff)
if (!defined('BASE_URL')) {
    define('BASE_URL', getBaseUrl());
}
