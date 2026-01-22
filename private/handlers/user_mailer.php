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
// USER REGISTRATION & PASSWORT MAIL Handler
// ============================================

// Registrierung (öffentlich zugänglich)
function registerNewUser($db) {
    require_once __DIR__ . '/../helpers/rate_limiter.php';

    $rateLimiter = new RateLimiter($db);

    $data = json_decode(file_get_contents("php://input"));
    
    // IP-basiertes Rate Limiting
    if (!$rateLimiter->canSubmitForm('registration', 5, 3600)) {
        http_response_code(429);
        echo json_encode([
            'message' => 'Zu viele Anfragen von Ihrer IP-Adresse. Bitte versuchen Sie es später erneut.'
        ]);
        return;
    }          

    if(!isSet($data->email) || !filter_var($data->email, FILTER_VALIDATE_EMAIL))
    {
        http_response_code(400);
        echo json_encode(['message' => 'Ungültige Email-Adresse']);
        exit();
    }

    $email = $data->email;
    $name = null;
    if(isSet($data->name))
    {
        $name = $data->name;
    }

    // Email-spezifisches Rate Limiting
    if (!$rateLimiter->canSendEmail($email, 'registration', 3, 3600)) {
        http_response_code(429);
        echo json_encode([
            'message' => 'Zu viele Registrierungsversuche. Bitte versuchen Sie es in einer Stunde erneut.'
        ]);
        return;
    }            
    
    if(!isSet($data->password) || strlen($data->password) < 8) {
        http_response_code(400);
        echo json_encode(["message" => "Passwort muss mindestens 8 Zeichen lang sein"]);
        exit();
    }
    
    // Email bereits registriert?
    $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if($stmt->fetch()) {
        // Sicherheit: gleiche Response (keine Email-Enumeration)
        sleep(1);
        echo json_encode(["success" => true, "message" => "Registrierung erfolgreich. Bitte Email prüfen."]);
        exit();
    }    
    
    try {        
        $db->beginTransaction();

        // Generiere API-Token
        $api_token = bin2hex(random_bytes(24));
        $apiTokenExpiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
        
        // User anlegen
        $passwordHash = password_hash($data->password, PASSWORD_DEFAULT);
        $stmt = $db->prepare(
            "INSERT INTO users (email, password_hash, name, role, is_active, email_verified, account_status, api_token, api_token_expires_at, created_at) 
             VALUES (?, ?, ?, 'user',0, 0, 'pending', ?, ?, NOW())"
        );

        $stmt->execute([$email, $passwordHash, $name, $api_token, $apiTokenExpiresAt]);
        $userId = $db->lastInsertId();

        $db->commit();  

        // Mail-Status prüfen BEVOR Token erstellt wird
        $mailer = new Mailer(getMailConfig(), $db);
        $mailStatus = $mailer->checkMailStatus('registration');        
        
        if (!$mailStatus['enabled']) {
            // User wurde erstellt, aber Mail kann nicht gesendet werden
            http_response_code(201); // Created
            return [
                'success' => true,
                'partial' => true, // Kennzeichnet teilweisen Erfolg
                'message' => 'Registrierung erfolgreich, aber E-Mail-Versand nicht möglich: ' . $mailStatus['message'],
                'user_id' => $userId,
                'email_required' => true // Frontend weiß: Email-Verifikation nötig
            ];
        }
        
        // Verifikations-Token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        
        $stmt = $db->prepare(
            "INSERT INTO email_verification_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
        );
        $stmt->execute([$userId, $tokenHash, $expiresAt]);
                  
        $emailSent = $mailer->sendVerificationEmail($data->email, $data->name ?? '', $token, $db);

        if (!$emailSent) {
            error_log("Verification email could not be sent to: " . $data->email);
            
            http_response_code(201);
            return [
                'success' => true,
                'partial' => true,
                'message' => 'Registrierung erfolgreich, aber E-Mail konnte nicht versendet werden. Bitte kontaktieren Sie einen Administrator.',
                'user_id' => $userId
            ];
        }

        http_response_code(201);
        return [
            'success' => true,
            'message' => 'Registrierung erfolgreich! Bitte prüfen Sie Ihre Email zur Bestätigung.',
            'user_id' => $userId
        ];
        exit();
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Registration error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Registrierung fehlgeschlagen']);
        exit();
    }
}

function handlePasswordResetRequest($db, $method) {

    if($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['message' => 'Method not allowed']);
        return;
    }    
    
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->email)) {
        http_response_code(400);
        echo json_encode(["message" => "Email required"]);
        return;
    }

    $email = filter_var($data->email ?? '', FILTER_VALIDATE_EMAIL);
    
    if(!$email) {
        http_response_code(400);
        echo json_encode(['message' => 'Ungültige Email']);
        return;
    }

    // Mail-Status prüfen BEVOR User gesucht wird
    $mailer = new Mailer(getMailConfig(), $db);
    $mailStatus = $mailer->checkMailStatus('password_reset');
    
    if (!$mailStatus['enabled']) {
        http_response_code(503); // Service Unavailable
        echo json_encode([
            'success' => false,
            'message' => $mailStatus['message']
        ]);
        return;
    }

    $rateLimiter = new RateLimiter($db);
    
    // Email Rate Limit: 3 Resets pro Stunde
    if (!$rateLimiter->canSendEmail($data->email, 'password_reset', 3, 3600)) {
        http_response_code(429);
        echo json_encode([
            'message' => 'Zu viele Passwort-Reset-Anfragen. Bitte versuchen Sie es in einer Stunde erneut.'
        ]);
        return;
    }

    // IP Rate Limit: 10 Anfragen pro Stunde
    if (!$rateLimiter->canSubmitForm('password_reset', 10, 3600)) {
        http_response_code(429);
        echo json_encode([
            'message' => 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.'
        ]);
        return;
    }
    
    // User existiert?
    $stmt = $db->prepare("SELECT user_id, email, name FROM users WHERE email = ? AND account_status = 'active'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if(!$user) {
        sleep(1); // Timing-Attack verhindern
        echo json_encode(['success' => true, 'message' => 'Falls Email existiert, wurde Link gesendet']);
        return;
    }
    
    try {
        // Token generieren
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        
        $stmt = $db->prepare(
            "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)"
        );
        $stmt->execute([$user['user_id'], $tokenHash, $expiresAt]);
                
        // Mail senden
        $emailSent = $mailer->sendPasswordResetEmail($user['email'], $user['name'], $token, $db);
        
        if (!$emailSent) {
            error_log("Password reset email could not be sent to: " . $email);
            
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'E-Mail konnte nicht versendet werden. Bitte versuchen Sie es später erneut.'
            ]);
            return;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Passwort-Reset-Link wurde per Email versendet'
        ]);
        
    } catch (Exception $e) {
        error_log("Password reset error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Fehler beim Versenden']);
    }
}

?>