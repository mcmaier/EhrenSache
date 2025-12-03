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