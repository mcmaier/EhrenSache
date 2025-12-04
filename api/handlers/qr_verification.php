<?php
// ============================================
// QR Verification Controller
// ============================================

function handleQRVerification($db, $request_method, $authUserId, $authUserRole, $authMemberId, $isTokenAuth)
{      
    // Neuer Endpoint: QR-Code verifizieren
    if($request_method === 'POST') 
    {
        $data = json_decode(file_get_contents("php://input"));

        if(!isset($data->arrival_time))
        {
            http_response_code(400);
            echo json_encode(["message" => "No arrival time given"]);
            exit();
        }
        
        // QR-Code Format: "CHECKIN:123456"
        if(!isset($data->qr_code) || !preg_match('/^CHECKIN:(\d{6})$/', $data->qr_code, $matches)) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid QR format"]);
            exit();
        }
        
        $code = $matches[1];
        
        // Hole alle aktiven QR-Location Devices mit Secrets
        $stmt = $db->query("SELECT dc.device_config_id, dc.device_name, dc.location_secret, u.email
                            FROM device_configs dc
                            JOIN users u ON dc.user_id = u.user_id
                            WHERE dc.device_type = 'qr_location' 
                            AND dc.is_active = 1 
                            AND dc.location_secret IS NOT NULL
                            AND u.is_active = 1");
        
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(empty($devices)) {
            http_response_code(400);
            echo json_encode([  "message" => "No active QR device configured",
                                "hint" => "Admin must configure device with TOTP secret"]);
            exit();
        }

        $validLocation = null;
        
        foreach($devices as $device) {
        // Erstelle TOTP-Objekt mit Secret
        $totp = new TOTP($device['location_secret']);

        if($totp->verify($code, null, 1)) {
            $validLocation = $device;
            break;
        }
    }
        
        if($validLocation) {
            //error_log("Valid check-in from location: " . $validLocation);
            // Code gültig → Auto-Checkin mit verified Flag
            handleAutoCheckin($db, 'POST', $authUserId, $authUserRole, $authMemberId, $isTokenAuth, 'qr_verified',
                                                                            [
                                                                                'location_name' => $validLocation['email'],
                                                                                'device_name' => $validLocation['device_name']
                                                                            ]
                                                                            );
        } else {
            http_response_code(401);
            echo json_encode([
                "message" => "Invalid or expired location code",
                "hint" => "QR-Code might be outdated (max 90s valid)"
            ]);
        }
    }
}

?>
