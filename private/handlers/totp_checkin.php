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
// TOTP CHECK-IN Controller
// Authentifizierung via TOTP-Code (6-stellig)
// Unterstützt: QR-Scan, NFC, manuelle Eingabe
// ============================================

function handleTotpCheckin($db, $request_method, $authUserId, $authUserRole, $authMemberId, $isTokenAuth)
{      
    if($request_method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        return;
    }

    $data = json_decode(file_get_contents("php://input"));

    // Validierung
    if(!isset($data->totp_code) || !isset($data->arrival_time)) {
        http_response_code(400);
        echo json_encode([
            "message" => "totp_code and arrival_time are required",
            "example" => [
                "totp_code" => "123456",
                "arrival_time" => "2025-12-05 18:15:00"
            ]
        ]);
        return;
    }

    $totpCode = trim($data->totp_code);
    $sourceDevice = $data->source_device ?? null;

    //error_log("Auto-Checkin Source-Device: {$sourceDevice}");

    // Validiere 6-stelliger numerischer Code
    if(!preg_match('/^\d{6}$/', $totpCode)) {
        http_response_code(400);
        echo json_encode([
            "message" => "Invalid TOTP code format",
            "hint" => "Code must be exactly 6 digits"
        ]);
        return;
    }        
    
    // Hole alle aktiven TOTP-Location Devices aus users Tabelle
    $stmt = $db->query("SELECT u.user_id, u.email, u.device_type, u.totp_secret
                        FROM users u
                        WHERE u.role = 'device' 
                        AND u.device_type = 'totp_location'
                        AND u.is_active = 1
                        AND u.totp_secret IS NOT NULL");
    
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if(empty($locations)) {
        http_response_code(400);
        echo json_encode([  "message" => "No active TOTP device configured",
                            "hint" => "Admin must configure device with TOTP secret"]);
        return;
    }

    $validLocation = null;
    
    foreach($locations as $location) {
    // Erstelle TOTP-Objekt mit Secret
    $totp = new TOTP($location['totp_secret']);

    if($totp->verify($totpCode, null, 1)) {
        $validLocation = $location;
        break;
    }
}
    
    if($validLocation) {
        //error_log("Valid check-in from location: " . $validLocation);
        // Code gültig → Auto-Checkin mit verified Flag
        handleAutoCheckin($db, 'POST', $authUserId, $authUserRole, $authMemberId, $isTokenAuth, 'user_totp',
                                                                        [
                                                                            'location_name' => $validLocation['email'],
                                                                            'device_name' => $sourceDevice
                                                                        ]
                                                                        );
    } else {
        http_response_code(401);
        echo json_encode([
            "message" => "Invalid or expired TOTP code",
            "hint" => "Code might be outdated (max 90s valid)",
            "tested_locations" => count($locations)
        ]);
    }
}

?>
