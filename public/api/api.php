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
// 1. HEADERS
// ============================================

// Headers
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key");
header("Access-Control-Allow-Credentials: true"); 

// ============================================
// 2. INCLUDES
// ============================================

//Module laden
require_once '../../private/config/config.php';
require_once '../../private/helpers/auth.php';
require_once '../../private/helpers/rate_limiter.php';
require_once '../../private/helpers/totp.php';
require_once '../../private/helpers/utils.php';
require_once '../../private/helpers/mailer.php';

// Handler laden
require_once '../../private/handlers/members.php';
require_once '../../private/handlers/appointments.php';
require_once '../../private/handlers/records.php';
require_once '../../private/handlers/exceptions.php';
require_once '../../private/handlers/users.php';
require_once '../../private/handlers/membership_dates.php';
require_once '../../private/handlers/auto_checkin.php';
require_once '../../private/handlers/totp_checkin.php';
require_once '../../private/handlers/regenerate_token.php';
require_once '../../private/handlers/change_password.php';
require_once '../../private/handlers/member_groups.php';
require_once '../../private/handlers/appointment_types.php';
require_once '../../private/handlers/statistics.php';
require_once '../../private/handlers/export.php';
require_once '../../private/handlers/import.php';
require_once '../../private/handlers/settings.php';
require_once '../../private/handlers/user_mailer.php';
require_once '../../private/handlers/attendance_list.php';
require_once '../../private/handlers/my_data.php';


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// 3. REQUEST VARIABLEN
// ============================================
$request_method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';
$id = $_GET['id'] ?? null;

// ============================================
// 4. SESSION-START (nur wo nötig)
// ============================================

// Session-Konfiguration (nur wenn Session gestartet wird)
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);

// Liste der Endpoints die Session brauchen (ohne Token)
$sessionEndpoints = ['login', 'logout', 'register'];

// Prüfe ob API-Token vorhanden ist
$apiToken = null;

if(isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'];
    if(strpos($auth, 'Bearer ') === 0) {
        $apiToken = substr($auth, 7);
    } else {
        $apiToken = $auth;
    }
}

if(!$apiToken && isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiToken = $_SERVER['HTTP_X_API_KEY'];
}

if(!$apiToken && isset($_GET['api_token'])) {
    $apiToken = $_GET['api_token'];
}

// Session NUR starten wenn:
// 1. Kein Token vorhanden UND
// 2. Nicht auf öffentlichem Endpoint (außer login/logout/register)
if (!$apiToken) {
    session_start();
    
    // Session-Timeout prüfen
    if(isset($_SESSION['last_activity']) && 
       (time() - $_SESSION['last_activity'] > 1800)) {
        session_unset();
        session_destroy();
        session_start(); // Neu starten für Error-Response
    }
    $_SESSION['last_activity'] = time();
}

// ============================================
// 5. RATE LIMITING
// ============================================

$rateLimiter = new RateLimiter();

// Identifier: IP + User/Token
$identifier = $_SERVER['REMOTE_ADDR'];
if(isset($_SESSION['user_id'])) {
    $identifier .= '_user_' . $_SESSION['user_id'];
}

// API Rate Limit: 100 Requests pro Minute
if (!$rateLimiter->check($identifier, 'api_request', 100, 60)) {
    http_response_code(429);
    echo json_encode([
        "message" => "Rate limit exceeded",
        "retry_after" => 60
    ]);
    exit();
}

// ============================================
// 6. ÖFFENTLICHE ENDPOINTS
// ============================================

if($resource === 'ping' && $request_method === 'GET') {
    // Prüfe ob config.php existiert
    $configPath = __DIR__ . '/../../private/config/config.php';
    
    if (!file_exists($configPath)) {
        http_response_code(503);
        echo json_encode([
            "status" => "not_installed",
            "message" => "Installation required"
        ]);
        exit();
    }
    
    // Prüfe ob Lock-File existiert
    $lockPath = __DIR__ . '/../../private/config/install.lock';
    
    if (!file_exists($lockPath)) {
        http_response_code(503);
        echo json_encode([
            "status" => "not_installed",
            "message" => "Installation not completed"
        ]);
        exit();
    }
    
    // Prüfe DB-Verbindung
    try {
        require_once $configPath;
        $database = new Database();
        $testDb = $database->getConnection();
        $prefix = $database->table('');

        // Prüfe ob users Tabelle existiert
        $stmt = $testDb->query("SHOW TABLES LIKE '{$prefix}users'");
        $tableExists = $stmt->rowCount() > 0;        
        
        if (!$tableExists) {
            http_response_code(503);
            echo json_encode([
                "status" => "not_installed",
                "message" => "Database schema missing"
            ]);
            exit();
        }
        
        // Alles OK
        echo json_encode([
            "status" => "ok",
            "message" => "System ready",
            "version" => "1.0"
        ]);
        exit();
        
    } catch (Exception $e) {
        http_response_code(503);
        echo json_encode([
            "status" => "not_installed",
            "message" => "Database connection failed"
        ]);
        exit();
    }
}

// ============================================
// 6.1 Datenbank verbinden
// ============================================

//Datenbank verbinden
$database = new Database();
$db = $database->getConnection();
$prefix = $database->table('');

// APPEARANCE
if($resource === 'appearance' && $request_method === 'GET') {
    getAppearance($db, $database);
    exit();
}

// LOGIN
if($resource === 'login' && $request_method === 'POST') {
    // Session wurde oben bereits gestartet
    $data = json_decode(file_get_contents("php://input"));
    echo json_encode(login($db, $database, $data->email, $data->password));
    exit();
}

// PWA LOGIN (Token-basiert)
if($resource === 'auth' && $request_method === 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    echo json_encode(loginWithToken($db, $database, $data->email, $data->password));
    exit();
}

// LOGOUT
if($resource === 'logout' && $request_method === 'POST') {
    // Session wurde oben bereits gestartet
    echo json_encode(logout());
    exit();
}

// REGISTRATION (öffentlich, Session für Rate-Limit)
if($resource === 'register' && $request_method === 'POST') {
    // Session wurde oben bereits gestartet (für Rate-Limiting)
    $result = registerNewUser($db, $database);
    echo json_encode($result);
    exit();    
}


// PASSWORD RESET REQUEST (öffentlich)
if($resource === 'password_reset_request' && $request_method === 'POST') {
    handlePasswordResetRequest($db, $database, $request_method);
    exit();
}

// ============================================
// 7. AUTHENTIFIZIERUNG (geschützte Endpoints)
// ============================================

// Globale Auth-Variablen
$isTokenAuth = false;
$authUserId = null;
$authUserRole = null;
$authMemberId = null;

// Token-Authentifizierung
if($apiToken) {
    //error_log("Token Auth: Token received, length=" . strlen($apiToken));
    
    $stmt = $db->prepare("SELECT user_id, member_id, role, is_active, email, api_token_expires_at
                         FROM {$prefix}users 
                         WHERE api_token = ?");
    $stmt->execute([$apiToken]);
    $tokenUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$tokenUser || !$tokenUser['is_active']) {
        //error_log("Token Auth: Invalid token");
        http_response_code(401);
        echo json_encode(["message" => "Invalid or inactive API token"]);
        exit();
    }

    // Prüfe Token-Ablauf
    if($tokenUser['api_token_expires_at']) {
        $expiresAt = new DateTime($tokenUser['api_token_expires_at']);
        $now = new DateTime();
        
        if($now > $expiresAt) {
            http_response_code(401);
            echo json_encode([
                "message" => "API token expired",
                "expired_at" => $tokenUser['api_token_expires_at'],
                "hint" => "Contact administrator to regenerate token"
            ]);
            exit();
        }
    }
    
    $isTokenAuth = true;
    $authUserId = intval($tokenUser['user_id']);
    $authUserRole = $tokenUser['role'];
    $authMemberId = $tokenUser['member_id'] ? intval($tokenUser['member_id']) : null;
    
    //error_log("Token Auth: SUCCESS - User ID: $authUserId, Role: $authUserRole, Member ID: " . ($authMemberId ?? 'NULL'));

    // Session für Token-Auth NICHT starten, aber Variablen setzen für Kompatibilität
    // (Falls Code $_SESSION abfragt, auch wenn es Token-Auth ist)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['user_id'] = $authUserId;
    $_SESSION['role'] = $authUserRole;
    $_SESSION['email'] = $tokenUser['email'];
    $_SESSION['logged_in'] = true;
    $_SESSION['auth_type'] = 'token';
} else {
    //error_log("Session Auth: No token, checking session");
    
    if(!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        http_response_code(401);
        echo json_encode(["message" => "Unauthorized"]);
        exit();
    }
    
    $authUserId = intval($_SESSION['user_id']);
    $authUserRole = $_SESSION['role'];
    
    $stmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
    $stmt->execute([$authUserId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $authMemberId = $result && $result['member_id'] ? intval($result['member_id']) : null; // ← FIX!
    
    //error_log("Session Auth: User ID: $authUserId, Role: $authUserRole, Member ID: " . ($authMemberId ?? 'NULL'));
}

// ============================================
// ME ENDPOINT
// ============================================

if($resource === 'me' && $request_method === 'GET') {

    if($isTokenAuth) {
        echo json_encode([
            "user_id" => $authUserId,
            "email" => $_SESSION['email'] ?? "token-auth",
            "role" => $authUserRole,
            "member_id" => $authMemberId,
            "auth_type" => "token"
        ]);
    } else {
        echo json_encode([
            "user_id" => $_SESSION['user_id'],
            "email" => $_SESSION['email'],
            "role" => $_SESSION['role'],
            "auth_type" => "session"
        ]);
    }
    exit();
}

// ============================================
// CSRF-SCHUTZ FÜR SESSION-AUTH
// ============================================

// Nur für Session-Auth (nicht Token) und bei modifizierenden Operationen
if(!$isTokenAuth && in_array($request_method, ['POST', 'PUT', 'DELETE'])) {

    //Von CSRF ausgenommen
    $excludedResources = ['login', 'logout', 'auth', 'regenerate_token','import','register','upload-logo'];
    
    // Login und Logout sind ausgenommen
    if(!in_array($resource, $excludedResources)) {
        
        $csrfToken = null;

        if($request_method === 'DELETE') {
                // Bei DELETE: Token aus Query-Parameter
                $csrfToken = $_GET['csrf_token'] ?? null;
        } else {
            // Bei POST/PUT: Token aus Request-Body
            $data = json_decode(file_get_contents("php://input"));
            $csrfToken = $data->csrf_token ?? null;                                
        }
        //error_log("POST/PUT - Session CSRF Token: " . ($_SESSION['csrf_token'] ?? 'NULL'));
        
        if(!$csrfToken || !validateCSRFToken($csrfToken)) {
            //error_log("CSRF VALIDATION FAILED!");
            http_response_code(403);
            echo json_encode(["message" => "Invalid CSRF token"]);
            exit();
        }        
    }
    else if($resource === 'import')
    {
    // CSRF-Token aus $_POST (FormData) lesen, nicht aus JSON-Body
        $csrfToken = $_POST['csrf_token'] ?? null;
        
        if (!$csrfToken || !validateCSRFToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(["message" => "Invalid CSRF token"]);
            exit();
        }
    }
}        

// ============================================
// 10. ROUTING
// ============================================

switch($resource) {
    case 'available_years':
        handleAvailableYears($db, $database, $request_method, $id);
        break;
    case 'members':
        handleMembers($db, $database, $request_method, $id, $authUserId, $authUserRole, $authMemberId);
        break;
    case 'appointments':
        handleAppointments($db, $database, $request_method, $id);
        break;       
    case 'records':
        handleRecords($db, $database, $request_method, $id);
        break;
    case 'exceptions':
        handleExceptions($db, $database, $request_method, $id);
        break;
    case 'users':
        handleUsers($db, $database, $request_method, $id, $authUserId);
        break;
    case 'membership_dates':
        handleMembershipDates($db, $database, $request_method, $id);        
        break;    
    case 'member_groups':
        handleMemberGroups($db, $database, $request_method, $id);
        break;        
    case 'appointment_types':
        handleAppointmentTypes($db, $database, $request_method, $id);
        break;        
    case 'statistics':
        handleStatistics($db, $database, $request_method, $authUserId, $authUserRole, $authMemberId);        
        break;
    case 'auto_checkin':
        handleAutoCheckin($db, $database, $request_method, $authUserId, $authUserRole, $authMemberId, $isTokenAuth);
        break;        
    case 'totp_checkin':
        handleTotpCheckin($db, $database, $request_method, $authUserId, $authUserRole, $authMemberId, $isTokenAuth);
        break;        
    case 'regenerate_token':
        handleTokenRegeneration($db, $database, $request_method, $authUserId, $authUserRole);
        break;                
    case 'change_password':
        handlePasswordChange($db, $database, $request_method, $authUserId);        
        break;
    case 'export':
        handleExport($db, $database, $request_method, $authUserRole);
        break;
    case 'import':
        handleImport($db, $database, $request_method, $authUserRole);
        break;
    case 'settings':
        handleSettings($db, $database, $request_method,$authUserId, $authUserRole);
        break;
    case 'upload-logo':
        uploadLogo($db, $database, $request_method,$authUserId,$authUserRole);
        break;
    case 'attendance_list':
        handleAttendanceList($db, $database, $request_method, $id);
        break;
    case 'activate_user':
        handleUserActivation($db, $database, $request_method, $authUserRole);
        break;    
    case 'user_status':
        handleUserStatus($db, $database, $request_method, $authUserRole);
        break;    
    case 'import_logs':
        handleImportLogs($db, $database, $request_method, $authUserRole, $id);
        break;
    case 'cleanup':
        handleCleanup($db, $database, $request_method, $authUserRole);
        break;
    case 'my_data':
        handleMyData($db, $database, $request_method, $authUserId);
        break;
                
    default:
        http_response_code(404);
        echo json_encode([
            "message" => "Endpoint not found",
            "resource" => $resource
        ]);
        exit();
}

?>
