<?php
// ============================================
// auth.php
// ============================================

function login($db, $email, $password) {

    
    // Rate Limiting für Login
    $key = 'login_attempts_' . $email;
    
    if(!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'locked_until' => 0];
    }
    
    // Account gesperrt?
    if($_SESSION[$key]['locked_until'] > time()) {
        $remaining = $_SESSION[$key]['locked_until'] - time();
        return [
            "success" => false, 
            "message" => "Too many failed attempts. Try again in {$remaining} seconds"
        ];
    }
    
    $stmt = $db->prepare("SELECT user_id, email, password_hash, role, is_active 
                          FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($user && password_verify($password, $user['password_hash'])) {
        if(!$user['is_active']) {
            return ["success" => false, "message" => "Account deactivated"];
        }
        
        // Reset Login-Versuche
        unset($_SESSION[$key]);
        
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        
        session_regenerate_id(true);

        // CSRF-Token generieren
        $csrfToken = generateCSRFToken();
        
        return ["success" => true, "user" => [
            "user_id" => $user['user_id'],
            "email" => $user['email'],
            "role" => $user['role']],
            "csrf_token" => $csrfToken 
        ];
    }
    
    // Fehlversuch zählen
    $_SESSION[$key]['count']++;
    
    // Nach 5 Versuchen: 15 Minuten sperren
    if($_SESSION[$key]['count'] >= 5) {
        $_SESSION[$key]['locked_until'] = time() + (15 * 60);
        return [
            "success" => false, 
            "message" => "Too many failed attempts. Account locked for 15 minutes"
        ];
    }
    
    return ["success" => false, "message" => "Invalid credentials"];
}


function loginWithToken($db, $email, $password) {    

    // Rate Limiting Check
    $ip = $_SERVER['REMOTE_ADDR'];
    $cacheFile = sys_get_temp_dir() . "/login_attempts_" . md5($ip);
    $maxAttempts = 5;
    $resetTime = 300;
    
    if (file_exists($cacheFile)) {
        $fileTime = filemtime($cacheFile);

        if (time() - $fileTime > $resetTime) {
                unlink($cacheFile); // Altes Limit löschen
                $attempts = 0;
            } else {
                $attempts = (int)file_get_contents($cacheFile);
                if ($attempts >= $maxAttempts) {
                    http_response_code(429);
                    return ["success" => false, "message" => "Zu viele Login-Versuche. Bitte warten Sie 5 Minuten."];
                }
            }
        } 
        else {
            $attempts = 0;
        }

        // Versuch zählen (und Datei mit aktuellem Zeitstempel speichern)
        $attempts++;
        file_put_contents($cacheFile, $attempts);
        touch($cacheFile, time() + $resetTime); // TTL setzen    

    try {
        // Hole User aus DB
        $stmt = $db->prepare("
            SELECT user_id, email, password_hash, role, member_id, api_token, is_active 
            FROM users 
            WHERE email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(401);
            return ["success" => false, "message" => "Ungültige Anmeldedaten"];
        }

        // Prüfe ob Account aktiv
        if (!$user['is_active']) {
            http_response_code(403);
            return ["success" => false, "message" => "Account ist deaktiviert"];
        }

        // Verifiziere Passwort
        if (!password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            return ["success" => false, "message" => "Ungültige Anmeldedaten"];
        }

        // Gib/Generiere Token
        $token = $user['api_token'];
        
        if (empty($token)) {
            // Generiere neuen Token
            $token = bin2hex(random_bytes(32));
            
            $stmt = $db->prepare("UPDATE users SET api_token = ? WHERE user_id = ?");
            $stmt->execute([$token, $user['user_id']]);
        }

        // Bei Erfolg: Reset attempts
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        http_response_code(200);
        return [
            "success" => true,
            "message" => "Login erfolgreich",
            "token" => $token,
            "user" => [
                "user_id" => (int)$user['user_id'],
                "email" => $user['email'],
                "role" => $user['role'],
                "member_id" => $user['member_id'] ? (int)$user['member_id'] : null
            ]
        ];

    } catch (PDOException $e) {      
        error_log("Login error: " . $e->getMessage());
        http_response_code(500);
        return ["success" => false, "message" => "Serverfehler beim Login"];
    }
}

function logout() {
    session_destroy();
    return ["success" => true, "message" => "Logged out"];
}

function isAuthenticated() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function requireAuth() {
    if(!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(["message" => "Unauthorized"]);
        exit();
    }
}

function requireRole($role) {
    requireAuth();
    if($_SESSION['role'] !== $role) {
        http_response_code(403);
        echo json_encode(["message" => "Forbidden"]);
        exit();
    }
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function generateCSRFToken() {
    if(!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'], $token);
}

?>