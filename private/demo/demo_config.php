<?php
/**
 * EhrenSache Demo-Modus Konfiguration
 * 
 * Inkludiere diese Datei am Anfang der API-Handler um Demo-Einschränkungen zu aktivieren.
 * 
 * Verwendung:
 *   require_once __DIR__ . '/../config/demo_config.php';
 *   checkDemoRestrictions('delete_member');
 */

// Demo-Modus aktivieren/deaktivieren
define('DEMO_MODE', true);
define('DEMO_RESET_INTERVAL', 1800); // 30 Minuten in Sekunden

/**
 * Gesperrte Aktionen im Demo-Modus
 */
const DEMO_BLOCKED_ACTIONS = [
    // Löschungen
    'delete_member',
    'delete_appointment',
    'delete_group',
    'delete_user',
    
    // Kritische Änderungen
    'change_password',
    'system_settings',
];

/**
 * Eingeschränkte Aktionen (nur für Manager+)
 */
const DEMO_RESTRICTED_ACTIONS = [
    'create_user',
    'modify_user_role',
    'system_settings_update',
];

/**
 * Prüft ob eine Aktion im Demo-Modus erlaubt ist
 * 
 * @param string $action Aktionsname (z.B. 'delete_member')
 * @param bool $throw Bei true wird Exception geworfen, sonst false zurückgegeben
 * @return bool True wenn erlaubt, false wenn gesperrt
 * @throws Exception Wenn $throw=true und Aktion gesperrt
 */
function checkDemoRestrictions($action, $throw = false) {
    if (!DEMO_MODE) {
        return true; // Demo-Modus nicht aktiv
    }
    
    // Gesperrte Aktionen
    if (in_array($action, DEMO_BLOCKED_ACTIONS)) {
        if ($throw) {
            http_response_code(403);
            throw new Exception('Diese Funktion ist im Demo-Modus deaktiviert. Lade EhrenSache herunter für volle Funktionen.');
        }
        return false;
    }
    
    // Eingeschränkte Aktionen (nur für Manager+)
    if (in_array($action, DEMO_RESTRICTED_ACTIONS)) {
        session_start();
        if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'manager'])) {
            if ($throw) {
                http_response_code(403);
                throw new Exception('Diese Funktion erfordert Manager-Rechte im Demo-Modus.');
            }
            return false;
        }
    }
    
    return true;
}

/**
 * Gibt JSON-Error zurück für gesperrte Aktionen
 */
function demoBlockedResponse() {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Demo-Modus: Diese Funktion ist deaktiviert',
        'message' => '🔒 Diese Funktion ist im Demo-Modus nicht verfügbar.'
    ]);
    exit;
}

/**
 * Wrapper für DELETE-Requests im Demo-Modus
 */
function handleDemoDelete($action = 'delete') {
    if (DEMO_MODE) {
        demoBlockedResponse($action);
    }
}

/**
 * Gibt Demo-Info zurück
 */
function getDemoInfo() {
    if (!DEMO_MODE) {
        return null;
    }
    
    $lastResetFile = __DIR__ . '/../last_reset.txt';
    $lastResetTime = file_exists($lastResetFile) ? (int)file_get_contents($lastResetFile) : time();
    $minutesSinceReset = floor((time() - $lastResetTime) / 60);
    $nextResetMinutes = 30 - ($minutesSinceReset % 30);
    
    return [
        'demo_mode' => true,
        'last_reset' => date('Y-m-d H:i:s', $lastResetTime),
        'next_reset_in_minutes' => $nextResetMinutes,
        'blocked_actions' => DEMO_BLOCKED_ACTIONS,
        'message' => 'Demo-Modus aktiv: Einige Funktionen sind eingeschränkt.'
    ];
}

// ============================================
// BEISPIEL-VERWENDUNG IN API-HANDLERN
// ============================================

/*
// In api/members/delete.php:
require_once __DIR__ . '/../../config/demo_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    handleDemoDelete('delete_member'); // Blockt automatisch
    
    // ... Rest des Delete-Codes ...
}


// In api/appointments/delete.php:
require_once __DIR__ . '/../../config/demo_config.php';

if (!checkDemoRestrictions('delete_appointment')) {
    demoBlockedResponse('delete_appointment');
}
// ... Rest des Codes ...


// In api/system/info.php:
require_once __DIR__ . '/../../config/demo_config.php';

$response = [
    'version' => '1.0.0',
    'organization' => 'Demo Musikverein',
];

// Demo-Info hinzufügen
if (DEMO_MODE) {
    $response['demo'] = getDemoInfo();
}

echo json_encode($response);
*/