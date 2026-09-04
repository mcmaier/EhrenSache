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

/** Zeitfenster in Sekunden — von allen Stationen und Verifikationen gemeinsam genutzt. */
const TOTP_PERIOD_SECONDS = 30;

class TOTP {
    private $secret;
    private $period;
    private $digits;

    public function __construct($secret, $period = TOTP_PERIOD_SECONDS, $digits = 6) {
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

/**
 * Löst einen TOTP-Code gegen alle aktiven Stationen auf.
 *
 * Belegt den ORT, nicht die Identität: Wer den Code einreicht, ist bereits über
 * Session oder Token authentifiziert. Der Treffer sagt, an welcher Station die
 * Person war.
 *
 * Herausgelöst aus handleTotpCheckin(), damit totp_checkin und work_sessions
 * dieselbe Auflösung verwenden statt die Schleife zu duplizieren.
 *
 * @return array{user_id: int, location_name: string}|null  null, wenn kein Code passt
 */
function resolveTotpLocation($db, $database, string $code): ?array
{
    if (!preg_match('/^\d{6}$/', trim($code))) {
        return null;
    }

    $prefix = $database->table('');

    $stmt = $db->query("SELECT u.user_id, u.email, u.device_name, u.totp_secret
                        FROM {$prefix}users u
                        WHERE u.role = 'device'
                          AND u.device_type IN ('totp_location', 'kiosk')
                          AND u.is_active = 1
                          AND u.totp_secret IS NOT NULL");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $location) {
        $totp = new TOTP($location['totp_secret']);

        if ($totp->verify(trim($code), null, 1)) {
            return [
                'user_id'       => (int) $location['user_id'],
                'location_name' => $location['device_name'] ?: $location['email'],
            ];
        }
    }

    return null;
}

/** Zählt die aktiven TOTP-Stationen. */
function countTotpLocations($db, $database): int
{
    $prefix = $database->table('');

    return (int) $db->query("SELECT COUNT(*) FROM {$prefix}users
                             WHERE role = 'device' AND device_type IN ('totp_location', 'kiosk')
                               AND is_active = 1 AND totp_secret IS NOT NULL")->fetchColumn();
}

/**
 * Aktueller und nächster Code einer Station — für die Anzeige am Kiosk.
 *
 * Das Secret bleibt auf dem Server. Der Kiosk erhält nur, was ohnehin auf dem
 * Bildschirm steht: den Code, sein Fensterende und den Folgecode, damit der
 * Wechsel ohne Lücke gelingt (Spec E5).
 *
 * Die Periode ist bewusst nicht parametrisierbar: Jede Verifikation
 * (resolveTotpLocation(), work_sessions.php) instanziiert TOTP mit der
 * Standardperiode TOTP_PERIOD_SECONDS — ein abweichender Wert hier würde
 * Codes erzeugen, die nirgends akzeptiert werden.
 *
 * @return array{code: string, next_code: string, valid_until: int, period: int}
 */
function totpCodesForSecret(string $secret, ?int $now = null): array
{
    $now         = $now ?? time();
    $totp        = new TOTP($secret);
    $windowStart = intdiv($now, TOTP_PERIOD_SECONDS) * TOTP_PERIOD_SECONDS;

    return [
        'code'        => $totp->getCode($now),
        'next_code'   => $totp->getCode($windowStart + TOTP_PERIOD_SECONDS),
        'valid_until' => $windowStart + TOTP_PERIOD_SECONDS,
        'period'      => TOTP_PERIOD_SECONDS,
    ];
}
