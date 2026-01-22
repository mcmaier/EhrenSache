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

class RateLimiter {
    private $db;
    private $useDatabase;
    
    /**
     * @param PDO|null $db Datenbank-Connection (null = Session-Modus)
     */
    public function __construct($db = null) {
        $this->db = $db;
        $this->useDatabase = ($db !== null);
    }
    
    /**
     * Prüft ob ein Request erlaubt ist
     * 
     * @param string $identifier Eindeutiger Identifier (IP, Email, User-ID)
     * @param string $action Action-Name (z.B. 'api_request', 'send_email', 'login')
     * @param int $maxAttempts Maximale Anzahl Versuche
     * @param int $windowSeconds Zeitfenster in Sekunden
     * @return bool True wenn erlaubt, False wenn Limit erreicht
     */
    public function check($identifier, $action = 'default', $maxAttempts = 100, $windowSeconds = 60) {
        if ($this->useDatabase) {
            return $this->checkDatabase($identifier, $action, $maxAttempts, $windowSeconds);
        } else {
            return $this->checkSession($identifier, $action, $maxAttempts, $windowSeconds);
        }
    }
    
    /**
     * Gibt verbleibende Versuche zurück
     * 
     * @param string $identifier
     * @param string $action
     * @param int $maxAttempts
     * @param int $windowSeconds
     * @return int Verbleibende Versuche
     */
    public function getRemaining($identifier, $action = 'default', $maxAttempts = 100, $windowSeconds = 60) {
        if ($this->useDatabase) {
            return $this->getRemainingDatabase($identifier, $action, $maxAttempts, $windowSeconds);
        } else {
            return $this->getRemainingSession($identifier, $action, $maxAttempts, $windowSeconds);
        }
    }
    
    // ============================================
    // DATABASE MODE (persistent)
    // ============================================
    
    private function checkDatabase($identifier, $action, $maxAttempts, $windowSeconds) {
        try {
            // Hash für Datenschutz
            $hashedIdentifier = hash('sha256', $identifier . $action);
            
            // Alte Einträge aufräumen (älter als Zeitfenster)
            $cutoffTime = date('Y-m-d H:i:s', time() - $windowSeconds);
            $cleanupStmt = $this->db->prepare(
                "DELETE FROM rate_limits WHERE created_at < ?"
            );
            $cleanupStmt->execute([$cutoffTime]);
            
            // Aktuelle Versuche zählen
            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) as attempt_count 
                 FROM rate_limits 
                 WHERE identifier = ? 
                 AND action = ? 
                 AND created_at >= ?"
            );
            $countStmt->execute([$hashedIdentifier, $action, $cutoffTime]);
            $result = $countStmt->fetch(PDO::FETCH_ASSOC);
            $currentAttempts = $result['attempt_count'];
            
            // Limit erreicht?
            if ($currentAttempts >= $maxAttempts) {
                error_log("RateLimiter: Limit reached for action '$action': $currentAttempts/$maxAttempts");
                return false;
            }
            
            // Versuch registrieren
            $insertStmt = $this->db->prepare(
                "INSERT INTO rate_limits (identifier, action, created_at) VALUES (?, ?, NOW())"
            );
            $insertStmt->execute([$hashedIdentifier, $action]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("RateLimiter DB error: " . $e->getMessage());
            // Bei DB-Fehler: Erlauben (fail-open, um System nicht zu blockieren)
            return true;
        }
    }
    
    private function getRemainingDatabase($identifier, $action, $maxAttempts, $windowSeconds) {
        try {
            $hashedIdentifier = hash('sha256', $identifier . $action);
            $cutoffTime = date('Y-m-d H:i:s', time() - $windowSeconds);
            
            $countStmt = $this->db->prepare(
                "SELECT COUNT(*) as attempt_count 
                 FROM rate_limits 
                 WHERE identifier = ? 
                 AND action = ? 
                 AND created_at >= ?"
            );
            $countStmt->execute([$hashedIdentifier, $action, $cutoffTime]);
            $result = $countStmt->fetch(PDO::FETCH_ASSOC);
            
            return max(0, $maxAttempts - $result['attempt_count']);
            
        } catch (PDOException $e) {
            error_log("RateLimiter DB error: " . $e->getMessage());
            return $maxAttempts;
        }
    }
    
    // ============================================
    // SESSION MODE (fast, non-persistent)
    // ============================================
    
    private function checkSession($identifier, $action, $maxAttempts, $windowSeconds) {
        $key = 'rate_limit_' . hash('sha256', $identifier . $action);
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'count' => 1,
                'start' => time()
            ];
            return true;
        }
        
        $data = $_SESSION[$key];
        $elapsed = time() - $data['start'];
        
        // Zeitfenster abgelaufen -> Reset
        if ($elapsed > $windowSeconds) {
            $_SESSION[$key] = [
                'count' => 1,
                'start' => time()
            ];
            return true;
        }
        
        // Limit erreicht?
        if ($data['count'] >= $maxAttempts) {
            return false;
        }
        
        // Counter erhöhen
        $_SESSION[$key]['count']++;
        return true;
    }
    
    private function getRemainingSession($identifier, $action, $maxAttempts, $windowSeconds) {
        $key = 'rate_limit_' . hash('sha256', $identifier . $action);
        
        if (!isset($_SESSION[$key])) {
            return $maxAttempts;
        }
        
        $data = $_SESSION[$key];
        $elapsed = time() - $data['start'];
        
        if ($elapsed > $windowSeconds) {
            return $maxAttempts;
        }
        
        return max(0, $maxAttempts - $data['count']);
    }
    
    // ============================================
    // CONVENIENCE METHODS
    // ============================================
    
    /**
     * Email-spezifisches Rate Limiting
     */
    public function canSendEmail($email, $action, $maxAttempts = 3, $windowSeconds = 3600) {
        return $this->check($email, "email_$action", $maxAttempts, $windowSeconds);
    }
    
    /**
     * IP-basiertes Rate Limiting für Formulare
     */
    public function canSubmitForm($action, $maxAttempts = 10, $windowSeconds = 3600) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return $this->check($ip, "form_$action", $maxAttempts, $windowSeconds);
    }
    
    /**
     * Login-Versuche limitieren (Email-basiert)
     */
    public function canAttemptLogin($email, $maxAttempts = 5, $windowSeconds = 900) {
        return $this->check($email, 'login_attempt', $maxAttempts, $windowSeconds);
    }
    
    /**
     * Reset Login-Versuche (nach erfolgreichem Login)
     */
    public function resetLoginAttempts($email) {
        if ($this->useDatabase) {
            try {
                $hashedIdentifier = hash('sha256', $email . 'login_attempt');
                $stmt = $this->db->prepare(
                    "DELETE FROM rate_limits WHERE identifier = ? AND action = 'login_attempt'"
                );
                $stmt->execute([$hashedIdentifier]);
            } catch (PDOException $e) {
                error_log("RateLimiter reset error: " . $e->getMessage());
            }
        } else {
            $key = 'rate_limit_' . hash('sha256', $email . 'login_attempt');
            unset($_SESSION[$key]);
        }
    }
}

/*
class RateLimiter {
    private $maxRequests;
    private $timeWindow;
    
    public function __construct($maxRequests = 100, $timeWindow = 60) {
        $this->maxRequests = $maxRequests;
        $this->timeWindow = $timeWindow; // Sekunden
    }
    
    public function check($identifier) {
        $key = 'rate_limit_' . $identifier;
        
        if(!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'count' => 1,
                'start' => time()
            ];
            return true;
        }
        
        $data = $_SESSION[$key];
        $elapsed = time() - $data['start'];
        
        // Zeitfenster abgelaufen -> Reset
        if($elapsed > $this->timeWindow) {
            $_SESSION[$key] = [
                'count' => 1,
                'start' => time()
            ];
            return true;
        }
        
        // Limit erreicht?
        if($data['count'] >= $this->maxRequests) {
            return false;
        }
        
        // Counter erhöhen
        $_SESSION[$key]['count']++;
        return true;
    }
    
    public function getRemainingRequests($identifier) {
        $key = 'rate_limit_' . $identifier;
        if(!isset($_SESSION[$key])) return $this->maxRequests;
        
        $data = $_SESSION[$key];
        $elapsed = time() - $data['start'];
        
        if($elapsed > $this->timeWindow) return $this->maxRequests;
        
        return max(0, $this->maxRequests - $data['count']);
    }
}


class MailRateLimiter {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function canSendEmail($email, $action, $maxAttempts = 3, $windowSeconds = 3600) {
        $identifier = hash('sha256', $email . $action);
        
        // Alte Einträge löschen
        $stmt = $this->pdo->prepare("DELETE FROM rate_limits WHERE expires_at < NOW()");
        $stmt->execute();
        
        // Aktuelle Einträge zählen
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) as count FROM rate_limits 
             WHERE identifier = ? AND action = ? AND expires_at > NOW()"
        );
        $stmt->execute([$identifier, $action]);
        $result = $stmt->fetch();
        
        if ($result['count'] >= $maxAttempts) {
            return false;
        }
        
        // Neuen Eintrag erstellen
        $expiresAt = date('Y-m-d H:i:s', time() + $windowSeconds);
        $stmt = $this->pdo->prepare(
            "INSERT INTO rate_limits (identifier, action, expires_at) VALUES (?, ?, ?)"
        );
        $stmt->execute([$identifier, $action, $expiresAt]);
        
        return true;
    }
    
    // IP-basiertes Rate Limiting für Formulare
    public function canSubmitForm($action, $maxAttempts = 10, $windowSeconds = 3600) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $identifier = hash('sha256', $ip . $action);
        
        $stmt = $this->pdo->prepare("DELETE FROM rate_limits WHERE expires_at < NOW()");
        $stmt->execute();
        
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) as count FROM rate_limits 
             WHERE identifier = ? AND action = ? AND expires_at > NOW()"
        );
        $stmt->execute([$identifier, $action]);
        $result = $stmt->fetch();
        
        if ($result['count'] >= $maxAttempts) {
            return false;
        }
        
        $expiresAt = date('Y-m-d H:i:s', time() + $windowSeconds);
        $stmt = $this->pdo->prepare(
            "INSERT INTO rate_limits (identifier, action, expires_at) VALUES (?, ?, ?)"
        );
        $stmt->execute([$identifier, $action, $expiresAt]);
        
        return true;
    }
}
*/