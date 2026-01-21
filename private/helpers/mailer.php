<?php

class Mailer {
    private $smtp_host;
    private $smtp_port;
    private $smtp_user;
    private $smtp_pass;
    private $from_email;
    private $from_name;
    private $use_tls;
    private $pdo;
    
    public function __construct($config, $pdo = null) {
        $this->smtp_host = $config['smtp_host'];
        $this->smtp_port = $config['smtp_port'];
        $this->smtp_user = $config['smtp_user'];
        $this->smtp_pass = $config['smtp_pass'];
        $this->from_email = $config['from_email'];
        $this->from_name = $config['from_name'];
        $this->use_tls = $config['use_tls'] ?? true;
        $this->pdo = $pdo;

        if ($pdo) {
            $this->loadFromDatabase();
        } else {
            $this->from_email = $config['from_email'];
            $this->from_name = $config['from_name'];
        }
    }

    private function loadFromDatabase() {
        if (!$this->pdo) return;
        
        try {
            $stmt = $this->pdo->query(
                "SELECT setting_key, setting_value 
                 FROM system_settings 
                 WHERE setting_key IN ('mail_from_email', 'mail_from_name')"
            );
            
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $this->from_email = $settings['mail_from_email'] ?? 'noreply@example.com';
            $this->from_name = $settings['mail_from_name'] ?? 'System';
            
        } catch (Exception $e) {
            error_log("Mailer: Could not load settings from DB: " . $e->getMessage());
            $this->from_email = 'noreply@example.com';
            $this->from_name = 'System';
        }
    }

    public function checkMailStatus($mailType = 'general') {
        if (!$this->pdo) {
            return ['enabled' => true, 'message' => '']; // Keine DB = kein Check
        }
        
        try {
            $stmt = $this->pdo->query(
                "SELECT setting_key, setting_value 
                 FROM system_settings 
                 WHERE setting_key IN ('mail_enabled', 'smtp_configured', 
                                      'mail_registration_enabled', 
                                      'mail_password_reset_enabled', 
                                      'mail_activation_enabled')"
            );
            
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // 1. Prüfung: Mail-System generell aktiviert?
            if (!isset($settings['mail_enabled']) || $settings['mail_enabled'] !== '1') {
                return [
                    'enabled' => false,
                    'message' => 'Das E-Mail-System ist vorübergehend deaktiviert. Bitte kontaktieren Sie einen Administrator.'
                ];
            }
            
            // 2. Prüfung: SMTP konfiguriert?
            if (!isset($settings['smtp_configured']) || $settings['smtp_configured'] !== '1') {
                return [
                    'enabled' => false,
                    'message' => 'Der E-Mail-Versand ist noch nicht konfiguriert. Bitte kontaktieren Sie einen Administrator.'
                ];
            }
            
            // 3. Prüfung: Spezifischer Mail-Typ aktiviert?
            $typeMessages = [
                'registration' => [
                    'key' => 'mail_registration_enabled',
                    'message' => 'Registrierungs-E-Mails sind vorübergehend deaktiviert. Bitte versuchen Sie es später erneut oder kontaktieren Sie einen Administrator.'
                ],
                'password_reset' => [
                    'key' => 'mail_password_reset_enabled',
                    'message' => 'Passwort-Reset-E-Mails sind vorübergehend deaktiviert. Bitte kontaktieren Sie einen Administrator.'
                ],
                'activation' => [
                    'key' => 'mail_activation_enabled',
                    'message' => 'Aktivierungs-E-Mails sind vorübergehend deaktiviert. Sie werden benachrichtigt, sobald der Dienst wieder verfügbar ist.'
                ]
            ];
            
            if ($mailType !== 'general' && isset($typeMessages[$mailType])) {
                $config = $typeMessages[$mailType];
                if (!isset($settings[$config['key']]) || $settings[$config['key']] !== '1') {
                    return [
                        'enabled' => false,
                        'message' => $config['message']
                    ];
                }
            }
            
            return ['enabled' => true, 'message' => ''];
            
        } catch (Exception $e) {
            error_log("Mailer: Error checking mail status: " . $e->getMessage());
            return [
                'enabled' => false,
                'message' => 'E-Mail-System vorübergehend nicht verfügbar.'
            ];
        }
    }
    
    public function send($to, $subject, $body, $isHtml = false) {
        // Email-Validierung
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[<>]/', $to)) {
            error_log("Mailer: Invalid email address: $to");
            return false;
        }

        // Status-Check (general)
        $status = $this->checkMailStatus('general');
        if (!$status['enabled']) {
            error_log("Mailer: Mail system disabled - " . $status['message']);
            return false;
        }
        
        // Socket öffnen
        $socket = @fsockopen($this->smtp_host, $this->smtp_port, $errno, $errstr, 30);
        if (!$socket) {
            error_log("Mailer: Connection failed: $errstr ($errno)");
            return false;
        }
        
        stream_set_timeout($socket, 30);
        
        try {
            // SMTP Handshake
            $this->getResponse($socket, '220');
            $this->sendCommand($socket, "EHLO " . $_SERVER['SERVER_NAME'], '250');
            
            // STARTTLS
            if ($this->use_tls && $this->smtp_port == 587) {
                $this->sendCommand($socket, "STARTTLS", '220');
                
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("TLS negotiation failed");
                }
                
                $this->sendCommand($socket, "EHLO " . $_SERVER['SERVER_NAME'], '250');
            }

            // Authentifizierung (nur wenn User/Pass gesetzt)
            if (!empty($this->smtp_user) && !empty($this->smtp_pass)) {
                $this->sendCommand($socket, "AUTH LOGIN", '334');
                $this->sendCommand($socket, base64_encode($this->smtp_user), '334');
                $this->sendCommand($socket, base64_encode($this->smtp_pass), '235');
            }        
            
            // Email senden
            $this->sendCommand($socket, "MAIL FROM: <{$this->from_email}>", '250');
            $this->sendCommand($socket, "RCPT TO: <$to>", '250');
            $this->sendCommand($socket, "DATA", '354');
            
            // Headers
            $headers = "From: {$this->from_name} <{$this->from_email}>\r\n";
            $headers .= "Reply-To: {$this->from_email}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: " . ($isHtml ? "text/html" : "text/plain") . "; charset=UTF-8\r\n";
            $headers .= "X-Mailer: EhrenSache/1.0\r\n";
            
            // Message
            fputs($socket, $headers);
            fputs($socket, "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n\r\n");
            fputs($socket, $body . "\r\n");
            
            $this->sendCommand($socket, ".", '250');
            $this->sendCommand($socket, "QUIT", '221');
            
            fclose($socket);
            return true;
            
        } catch (Exception $e) {
            error_log("Mailer: " . $e->getMessage());
            @fclose($socket);
            return false;
        }
    }
    
    private function sendCommand($socket, $command, $expectedCode) {
        fputs($socket, $command . "\r\n");
        $response = $this->getResponse($socket, $expectedCode);
        return $response;
    }
    
    private function getResponse($socket, $expectedCode = null) {
        $response = '';
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == ' ') break;
        }
        
        if ($expectedCode && substr($response, 0, 3) != $expectedCode) {
            throw new Exception("Expected $expectedCode, got: $response");
        }
        
        return $response;
    }

    public function sendVerificationEmail($to, $name, $token) {
        // Status-Check für Registrierungs-Mails
        $status = $this->checkMailStatus('registration');
        if (!$status['enabled']) {
            error_log("Mailer: Registration mails disabled - " . $status['message']);
            return false;
        }            

        require_once __DIR__ . '/../../private/helpers/mail_template.php';

        $verificationLink = BASE_URL . '/verify_email.php?token=' . $token;

        $subject = 'Email-Adresse bestätigen - EhrenSache';

        $body = EmailTemplate::render('verify_email', [
            'USER_NAME' => $name,
            'VERIFICATION_LINK' => $verificationLink
        ]);
        
        return $this->send($to, $subject, $body, true);
    }

    public function sendPasswordResetEmail($to, $name, $token) {
        // Status-Check für Password-Reset-Mails
        $status = $this->checkMailStatus('password_reset');
        if (!$status['enabled']) {
            error_log("Mailer: Password reset mails disabled - " . $status['message']);
            return false;
        }
        
        require_once __DIR__ . '/../../private/helpers/mail_template.php';

        $resetLink = BASE_URL . '/reset_password.php?token=' . $token;
        
        $subject = 'Passwort zurücksetzen - EhrenSache';
        
        $body = EmailTemplate::render('password_reset', [
            'USER_NAME' => $name,
            'RESET_LINK' => $resetLink
        ]);
        
        return $this->send($to, $subject, $body, true);
    }

    public function sendActivationEmail($to, $name, $memberInfo) {
        // Status-Check für Aktivierungs-Mails
        $status = $this->checkMailStatus('activation');
        if (!$status['enabled']) {
            error_log("Mailer: Activation mails disabled - " . $status['message']);
            return false;
        }
        
        $loginLink = BASE_URL . '/login.html';

        require_once __DIR__ . '/../../private/helpers/mail_template.php';
        
        $subject = 'Account aktiviert - EhrenSache';
        
        $body = EmailTemplate::render('account_activation', [
            'USER_NAME' => $name,
            'USER_EMAIL' => $to,
            'LOGIN_LINK' => BASE_URL . '/login.html',
            'MEMBER_INFO' => $memberInfo
        ]);
                
        return $this->send($to, $subject, $body, true);
    }
}

?>