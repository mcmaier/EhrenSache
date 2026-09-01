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

function handleTotpCheckin($db, $database, $request_method, $authUserId, $authUserRole, $authMemberId, $isTokenAuth)
{      
    if($request_method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        return;
    }
    
    $prefix = $database->table('');

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
            "message" => "Ungültiges Code-Format",
            "hint" => "Code must be exactly 6 digits"
        ]);
        return;
    }        
    
    // Auflösung liegt in private/helpers/totp.php, damit work_sessions
    // dieselbe Logik nutzt statt die Schleife zu duplizieren.
    if(countTotpLocations($db, $database) === 0) {
        http_response_code(400);
        echo json_encode([  "message" => "Keine TOTP-Stationen konfiguriert.",
                            "hint" => "Admin must configure device with TOTP secret"]);
        return;
    }

    $validLocation = resolveTotpLocation($db, $database, $totpCode);

    if($validLocation) {
        //error_log("Valid check-in from location: " . $validLocation);
        // Code gültig → Auto-Checkin mit verified Flag
        handleAutoCheckin($db, $database, 'POST', $authUserId, $authUserRole, $authMemberId, $isTokenAuth, 'user_totp',
                                                                        [
                                                                            'location_name' => $validLocation['location_name'],
                                                                            'device_name' => $sourceDevice
                                                                        ]
                                                                        );
    } else {
        http_response_code(401);
        echo json_encode([
            "message" => "Ungültiger oder abgelaufener TOTP Code",
            "tested_locations" => countTotpLocations($db, $database)
        ]);
    }
}

?>
