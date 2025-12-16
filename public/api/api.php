<?php

//Module laden
require_once '../../private/config.php';
require_once '../../private/helpers/auth.php';
require_once '../../private/helpers/rate_limiter.php';
require_once '../../private/helpers/totp.php';
require_once '../../private/helpers/utils.php';

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

// Headers
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key");
header("Access-Control-Allow-Credentials: true"); 

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================
// RATE LIMITS
// ============================================

// Rate Limiting für API-Requests
$rateLimiter = new RateLimiter(100, 60); // 100 Requests pro Minute

// Identifier: IP + User/Token
$identifier = $_SERVER['REMOTE_ADDR'];
if(isset($_SESSION['user_id'])) {
    $identifier .= '_user_' . $_SESSION['user_id'];
}

if(!$rateLimiter->check($identifier)) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        "message" => "Rate limit exceeded",
        "retry_after" => 60
    ]);
    exit();
}

// Identifier: IP + User/Token
$identifier = $_SERVER['REMOTE_ADDR'];
if(isset($_SESSION['user_id'])) {
    $identifier .= '_user_' . $_SESSION['user_id'];
}

if(!$rateLimiter->check($identifier)) {
    http_response_code(429); // Too Many Requests
    echo json_encode([
        "message" => "Rate limit exceeded",
        "retry_after" => 60
    ]);
    exit();
}


// ============================================
// REQUEST
// ============================================

$request_method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';
$id = $_GET['id'] ?? null;

// ============================================
// PING ENDPOINT (vor Auth)
// ============================================
if($resource === 'ping' && $request_method === 'GET') {
    // Prüfe ob config.php existiert
    $configPath = __DIR__ . '/../../private/config.php';
    
    if (!file_exists($configPath)) {
        http_response_code(503);
        echo json_encode([
            "status" => "not_installed",
            "message" => "Installation required"
        ]);
        exit();
    }
    
    // Prüfe ob Lock-File existiert
    $lockPath = __DIR__ . '/../../private/install.lock';
    
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
        
        // Prüfe ob users Tabelle existiert
        $stmt = $testDb->query("SHOW TABLES LIKE 'users'");
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
// INIT
// ============================================

//Datenbank verbinden
$database = new Database();
$db = $database->getConnection();

// Globale Auth-Variablen
$isTokenAuth = false;
$authUserId = null;
$authUserRole = null;
$authMemberId = null;

// ============================================
// ÖFFENTLICHE ENDPOINTS (keine Auth nötig)
// ============================================

if($resource === 'login' && $request_method === 'POST') {
    session_start();
    $data = json_decode(file_get_contents("php://input"));
    echo json_encode(login($db, $data->email, $data->password));
    exit();
}

if($resource === 'logout' && $request_method === 'POST') {
    session_start();
    echo json_encode(logout());
    exit();
}

// ============================================
// AUTHENTIFIZIERUNG FÜR GESCHÜTZTE ENDPOINTS
// ============================================

// Prüfe auf API-Token in verschiedenen Quellen
$apiToken = null;

// Authorization Header (Standard)
if(isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'];
    if(strpos($auth, 'Bearer ') === 0) {
        $apiToken = substr($auth, 7);
    } else {
        $apiToken = $auth;
    }
}

// X-API-Key Header
if(!$apiToken && isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiToken = $_SERVER['HTTP_X_API_KEY'];
}

//Token als URL Parameter
if(!$apiToken && isset($_GET['api_token'])) {
    $apiToken = $_GET['api_token'];
}

// Token-Authentifizierung
if($apiToken) {
    //error_log("Token Auth: Token received, length=" . strlen($apiToken));
    
    $stmt = $db->prepare("SELECT user_id, member_id, role, is_active, email, api_token_expires_at
                         FROM users 
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
    $authMemberId = $tokenUser['member_id'] ? intval($tokenUser['member_id']) : null; // ← FIX!
    
   //error_log("Token Auth: SUCCESS - User ID: $authUserId, Role: $authUserRole, Member ID: " . ($authMemberId ?? 'NULL'));
    
    $_SESSION['user_id'] = $authUserId;
    $_SESSION['role'] = $authUserRole;
    $_SESSION['email'] = $tokenUser['email'];
    $_SESSION['logged_in'] = true;
    $_SESSION['auth_type'] = 'token';
} else {
    //error_log("Session Auth: No token, checking session");
    session_start();
    
    if(!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        http_response_code(401);
        echo json_encode(["message" => "Unauthorized"]);
        exit();
    }
    
    $authUserId = intval($_SESSION['user_id']);
    $authUserRole = $_SESSION['role'];
    
    $stmt = $db->prepare("SELECT member_id FROM users WHERE user_id = ?");
    $stmt->execute([$authUserId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC); // ← fetch() statt fetchColumn()!
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
            "email" => "token-auth", // Email nicht verfügbar ohne Session
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
    $excludedResources = ['login', 'logout', 'regenerate_token','import'];
    
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
// ADMIN-CHECKS
// ============================================

$adminOnlyResources = [
    'members' => ['POST', 'PUT', 'DELETE'],
    'appointments' => ['POST', 'PUT', 'DELETE'],
    'records' => ['POST', 'PUT', 'DELETE'],
    'users' => ['POST', 'PUT', 'DELETE'],
    'membership_dates' => ['GET', 'POST', 'PUT', 'DELETE'],
    'member_groups' => ['POST', 'PUT', 'DELETE'],
    'appointment_types' => ['POST', 'PUT', 'DELETE']
];

foreach($adminOnlyResources as $res => $methods) {
    if($resource === $res && in_array($request_method, $methods)) {
        if($authUserRole !== 'admin') {
            http_response_code(403);
            echo json_encode(["message" => "Admin access required"]);
            exit();
        }
    }
}

// ============================================
// ROUTING
// ============================================

switch($resource) {
    case 'available_years':
        handleAvailableYears($db, $request_method, $id);
        break;
    case 'members':
        handleMembers($db, $request_method, $id);
        break;
    case 'appointments':
        handleAppointments($db, $request_method, $id);
        break;    
    case 'records':
        handleRecords($db, $request_method, $id);
        break;
    case 'exceptions':
        handleExceptions($db, $request_method, $id);
        break;
    case 'users':
        handleUsers($db, $request_method, $id);
        break;
    case 'membership_dates':
        handleMembershipDates($db, $request_method, $id);        
        break;    
    case 'member_groups':
        handleMemberGroups($db, $request_method, $id);
        break;        
    case 'appointment_types':
        handleAppointmentTypes($db, $request_method, $id);
        break;        
    case 'statistics':
        handleStatistics($db, $request_method, $authUserId, $authUserRole, $authMemberId);        
        break;
    case 'auto_checkin':
        handleAutoCheckin($db, $request_method, $authUserId, $authUserRole, $authMemberId, $isTokenAuth);
        exit();        
    case 'totp_checkin':
        handleTotpCheckin($db, $request_method, $authUserId, $authUserRole, $authMemberId, $isTokenAuth);
        exit();        
    case 'regenerate_token':
        handleTokenRegeneration($db, $request_method, $authUserId, $authUserRole);
        exit();                
    case 'change_password':
        handlePasswordChange($db, $request_method, $authUserId);        
        exit();
    case 'export':
        handleExport($db, $request_method, $authUserRole);
        exit();
    case 'import':
        handleImport($db, $request_method, $authUserRole);
        exit();
                
    default:
        http_response_code(404);
        echo json_encode([
            "message" => "Endpoint not found",
            "resource" => $resource
        ]);
        exit();
}

?>

