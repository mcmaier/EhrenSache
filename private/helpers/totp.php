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
 * TOTP (Time-based One-Time Password) - RFC 6238
 * Vanilla PHP Implementation (keine Dependencies)
 */

class TOTP {
    private $secret;
    private $period;
    private $digits;
    
    public function __construct($secret, $period = 30, $digits = 6) {
        $this->secret = $secret;
        $this->period = $period;
        $this->digits = $digits;
    }
    
    /**
     * Generiert aktuellen TOTP-Code
     */
    public function getCode($timestamp = null) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        $counter = floor($timestamp / $this->period);
        return $this->generateHOTP($counter);
    }
    
    /**
     * Verifiziert Code mit Zeittoleranz
     * @param string $code - Eingegebener Code
     * @param int $window - Anzahl Zeitfenster vor/zurück (default: 1 = ±30s)
     */
    public function verify($code, $timestamp = null, $window = 1) {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        $counter = floor($timestamp / $this->period);
        
        // Prüfe aktuelles + benachbarte Zeitfenster
        for ($i = -$window; $i <= $window; $i++) {
            if ($this->generateHOTP($counter + $i) === $code) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * HOTP-Algorithmus (RFC 4226)
     */
    private function generateHOTP($counter) {
        // Base32 dekodieren
        $secret = $this->base32Decode($this->secret);
        
        // Counter als 8-Byte Big-Endian
        $counterBytes = pack('N*', 0) . pack('N*', $counter);
        
        // HMAC-SHA1
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);
        
        // Dynamic Truncation
        $offset = ord($hash[19]) & 0x0f;
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );
        
        // Auf gewünschte Stellenzahl kürzen
        $code = $code % pow(10, $this->digits);
        
        return str_pad($code, $this->digits, '0', STR_PAD_LEFT);
    }
    
    /**
     * Base32 Decoder (RFC 4648)
     */
    private function base32Decode($input) {
        $input = strtoupper($input);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;
        
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            if ($char === '=') break;
            
            $val = strpos($alphabet, $char);
            if ($val === false) continue;
            
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            
            if ($bitsLeft >= 8) {
                $output .= chr(($buffer >> ($bitsLeft - 8)) & 0xff);
                $bitsLeft -= 8;
            }
        }
        
        return $output;
    }
    
    /**
     * Generiert zufälligen Base32-Secret
     */
    public static function generateSecret($length = 16) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }
}
