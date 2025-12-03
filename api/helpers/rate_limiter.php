<?php
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