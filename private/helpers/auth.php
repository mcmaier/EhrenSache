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
// auth.php
// ============================================

function login($db, $database, $email, $password) {

    $prefix = $database->table('');

    $rateLimiter = new RateLimiter($db, $database);

    // Login-Versuche prüfen
    if (!$rateLimiter->canAttemptLogin($email, 5, 900)) {
        return [
            "success" => false,
            "message" => "Zu viele Login-Versuche. Bitte versuchen Sie es in 15 Minuten erneut.",
            "code"    => "RATE_LIMIT_EXCEEDED"
        ];
    }
        
    $stmt = $db->prepare("SELECT user_id, email, password_hash, role, is_active, account_status
                          FROM {$prefix}users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($user && password_verify($password, $user['password_hash'])) {
        if(!$user['is_active']) {
            return ["success" => false, "message" => "Account deaktiviert", "code" => "ACCOUNT_INACTIVE"];
        }

        if ($user['account_status'] === 'suspended') {
            return [
                "success" => false,
                "message" => "Account wurde gesperrt",
                "code"    => "ACCOUNT_SUSPENDED"
            ];
        }
        
        if ($user['account_status'] === 'pending') {
            return [
                "success" => false,
                "message" => "Account wurde noch nicht aktiviert",
                "code"    => "ACCOUNT_PENDING"
            ];
        }
        
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['created_at'] = time(); 
        
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

    return ["success" => false, "message" => "Ungültige Anmeldedaten", "code" => "INVALID_CREDENTIALS"];
}


function loginWithToken($db, $database, $email, $password) {

    $prefix = $database->table('');

    // Rate Limiting – identisch zum Session-Login (Email-basiert, DB-gespeichert)
    $rateLimiter = new RateLimiter($db, $database);
    if (!$rateLimiter->canAttemptLogin($email, 5, 900)) {
        http_response_code(429);
        return [
            "success" => false,
            "message" => "Zu viele Login-Versuche. Bitte versuchen Sie es in 15 Minuten erneut.",
            "code"    => "RATE_LIMIT_EXCEEDED"
        ];
    }

    try {
        $stmt = $db->prepare("
            SELECT user_id, email, password_hash, role, member_id, api_token, api_token_expires_at, is_active
            FROM {$prefix}users
            WHERE email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(401);
            return ["success" => false, "message" => "Ungültige Anmeldedaten", "code" => "INVALID_CREDENTIALS"];
        }

        if (!$user['is_active']) {
            http_response_code(403);
            return ["success" => false, "message" => "Account ist deaktiviert", "code" => "ACCOUNT_INACTIVE"];
        }

        if (!password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            return ["success" => false, "message" => "Ungültige Anmeldedaten", "code" => "INVALID_CREDENTIALS"];
        }

        // Bei Erfolg: Login-Zähler zurücksetzen
        $rateLimiter->resetLoginAttempts($email);

        // Token ausstellen oder vorhandenes zurückgeben
        $token = $user['api_token'];
        $tokenExpired = empty($user['api_token_expires_at']) || strtotime($user['api_token_expires_at']) < time();

        if (empty($token) || $tokenExpired) {
            $token = bin2hex(random_bytes(24));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
            $db->prepare("UPDATE {$prefix}users SET api_token = ?, api_token_expires_at = ? WHERE user_id = ?")
               ->execute([$token, $expiresAt, $user['user_id']]);
        }

        http_response_code(200);
        return [
            "success"  => true,
            "message"  => "Login erfolgreich",
            "token"    => $token,
            "user"     => [
                "user_id"   => (int)$user['user_id'],
                "email"     => $user['email'],
                "role"      => $user['role'],
                "member_id" => $user['member_id'] ? (int)$user['member_id'] : null
            ]
        ];

    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        http_response_code(500);
        return ["success" => false, "message" => "Serverfehler beim Login", "code" => "SERVER_ERROR"];
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

function requireRole($allowedRoles) {
    requireAuth();

    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht authentifiziert']);
        exit;
    }
    
    // Wenn Array übergeben wurde, prüfe ob Rolle enthalten ist
    if (is_array($allowedRoles)) {
        if (!in_array($_SESSION['role'], $allowedRoles)) {
            http_response_code(403);
            echo json_encode(['error' => 'Zugriff verweigert']);
            exit;
        }
    } else {
        // Einzelne Rolle
        if ($_SESSION['role'] !== $allowedRoles) {
            http_response_code(403);
            echo json_encode(['error' => 'Zugriff verweigert']);
            exit;
        }
    }
}

function requireAdminOrManager()
{
    if(!isAdminOrManager())
    {
        http_response_code(403);
        echo json_encode(['error' => 'Zugriff verweigert']);
        exit;
    }
}

function requireAdmin()
{
    if(!isAdmin())
    {
        http_response_code(403);
        echo json_encode(['error' => 'Zugriff verweigert']);
        exit;
    }
}

function requireDevice()
{
    if(!isDevice())
    {
        http_response_code(403);
        echo json_encode(['error' => 'Zugriff verweigert']);
        exit;
    }
}

function isAdminOrManager() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'manager']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isDevice() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'device';
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

function getSessionDebugInfo($method) {
    if($method !== 'GET')
    {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }

    // Nur für eingeloggte User
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }

    // Session-Informationen sammeln
    $sessionInfo = [
        'session_id' => session_id(),
        'session_name' => session_name(),
        'session_status' => session_status(), // 0=disabled, 1=none, 2=active
        'cookie_lifetime' => ini_get('session.cookie_lifetime'),
        'gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
        'session_data' => $_SESSION,
        'last_activity' => $_SESSION['last_activity'] ?? null,
        'current_time' => time(),
        'time_since_activity' => isset($_SESSION['last_activity']) 
            ? time() - $_SESSION['last_activity'] 
            : null,
        'remaining_seconds' => isset($_SESSION['last_activity']) 
            ? (int)ini_get('session.gc_maxlifetime') - (time() - $_SESSION['last_activity'])
            : null
    ];

    echo json_encode([
        'success' => true,
        'data' => $sessionInfo
    ]);
}

?>