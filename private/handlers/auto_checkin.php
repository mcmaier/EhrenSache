<?php

// ============================================
// AUTO CHECK-IN Controller
// ============================================
function handleAutoCheckin($db, $method, $authUserId, $authUserRole, $authMemberId, $isTokenAuth, $checkinSource = 'auto_checkin', $sourceInfo = []) {
    if($method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        return;
    }
    
    $data = json_decode(file_get_contents("php://input"));
    
    // Validierung
    if(!isset($data->arrival_time)) {
        http_response_code(400);
        echo json_encode(["message" => "arrival_time is required"]);
        return;
    }

    // DEBUG: Zeige Auth-Info
    $debugInfo = [
        "auth_user_id" => $authUserId,
        "auth_user_role" => $authUserRole,
        "auth_member_id" => $authMemberId,
        "is_token_auth" => $isTokenAuth,
        "checkin_source" => $checkinSource
    ];

    //error_log("=== MEMBER ID DETERMINATION ===");
    //error_log("authUserRole: $authUserRole");
    //error_log("authMemberId: " . ($authMemberId ?? 'NULL'));
    //error_log("data->member_id: " . (isset($data->member_id) ? $data->member_id : 'NOT SET'));
    
    
     // Member-ID bestimmen
    if($authUserRole === 'admin' || $authUserRole === 'device') {
        // Admin/Device kann für beliebige member_id/member_number einchecken
        if(!isset($data->member_id) && !isset($data->member_number)) {
            http_response_code(400);
            echo json_encode([
                "message" => "Either member_id or member_number is required",
                "debug" => $debugInfo
            ]);
            return;
        }
        
        // Resolve member_id
        if(isset($data->member_number)) {
            $memberId = resolveMemberIdByNumber($db, $data->member_number);
        } else {
            $memberId = intval($data->member_id);
            
            // Validiere member_id
            $checkMember = $db->prepare("SELECT member_id FROM members WHERE member_id = ?");
            $checkMember->execute([$memberId]);
            if(!$checkMember->fetchColumn()) {
                $memberId = null;
            }
        }
        
        if(!$memberId) {
            http_response_code(404);
            echo json_encode([
                "message" => "Member not found",
                "searched_for" => $data->member_number ?? $data->member_id,
                "debug" => $debugInfo
            ]);
            return;
        }
        
        // Bestimme Source für Response
        if($authUserRole === 'device') {
            $authType = 'device_auth';
        } else {
            $authType = $isTokenAuth ? 'admin_token' : 'admin_session';
        }
        
    } else {
        // User kann nur für verknüpftes Mitglied einchecken
        if(!$authMemberId || ($authMemberId === null)) {
            http_response_code(403);
            echo json_encode([
                "message" => "No member linked to your account",
                "hint" => "Contact administrator",
                "debug" => $debugInfo
            ]);
            return;
        }
        
        $memberId = $authMemberId;
        $authType = $isTokenAuth ? 'user_token' : 'user_session';
        //error_log("USER: Using authMemberId: $memberId");
        
        // Warnung wenn andere member_id angegeben wurde
        $warning = null;
        if(isset($data->member_id) && $data->member_id != $memberId) {
            $warning = "member_id ignored - you can only check-in for your linked member (ID: $memberId)";
        }
    }    


    // Konvertiere arrival_time zu DateTime
    try {
        $arrivalTime = new DateTime($data->arrival_time);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode([
            "message" => "Invalid arrival_time format",
            "expected" => "YYYY-MM-DD HH:MM:SS",
            "example" => date('Y-m-d H:i:s')
        ]);
        return;
    }
    
    $arrivalDate = $arrivalTime->format('Y-m-d');
    $arrivalTimeStr = $arrivalTime->format('H:i:s');    

    // Zeittoleranz aus globaler Config
    $tolerance = isset($data->tolerance_hours) ? intval($data->tolerance_hours) : AUTO_CHECKIN_TOLERANCE_HOURS;
    
    // Begrenze Toleranz auf sinnvollen Bereich
    if($tolerance < 0 || $tolerance > 12) {
        $tolerance = AUTO_CHECKIN_TOLERANCE_HOURS;
    }
    
    // Suche nach passendem Termin
    $toleranceStart = clone $arrivalTime;
    $toleranceStart->modify("-{$tolerance} hours");
    
    $toleranceEnd = clone $arrivalTime;
    $toleranceEnd->modify("+{$tolerance} hours");
    
    $stmt = $db->prepare("SELECT appointment_id, title, date, start_time 
                          FROM appointments 
                          WHERE date = ? 
                          AND start_time BETWEEN ? AND ?
                          ORDER BY ABS(TIMESTAMPDIFF(SECOND, 
                              CONCAT(date, ' ', start_time), 
                              ?)) ASC
                          LIMIT 1");
    
    $stmt->execute([
        $arrivalDate,
        $toleranceStart->format('H:i:s'),
        $toleranceEnd->format('H:i:s'),
        $data->arrival_time
    ]);
    
    $matchedAppointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($matchedAppointment) {
        // Passender Termin gefunden
        $appointmentId = $matchedAppointment['appointment_id'];
        $action = 'matched';
    } else {
        // Runde auf 5-Minuten-Schritte
        $minutes = (int)$arrivalTime->format('i');
        $roundedMinutes = round($minutes / 5) * 5;
        
        // Setze gerundete Zeit (Stunden, Minuten, Sekunden=0)
        $arrivalTime->setTime(
            (int)$arrivalTime->format('H'),
            $roundedMinutes,
            0
        );

         // Hole Standard-Terminart
        $typeStmt = $db->query("SELECT type_id FROM appointment_types WHERE is_default = 1 LIMIT 1");
        $defaultType = $typeStmt->fetch(PDO::FETCH_ASSOC);
        $typeId = $defaultType ? $defaultType['type_id'] : null;


        // Kein passender Termin - erstelle automatisch einen
        $autoTitle = "Auto-Erfassung " . $arrivalTime->format('d.m.Y H:i:s');
        $timeWithoutSeconds = $arrivalTime->format('H:i:s');
        
        $createStmt = $db->prepare("INSERT INTO appointments 
                                    (title, type_id, description, date, start_time, created_by) 
                                    VALUES (?, ?, ?, ?, ?, ?)");
        
        $createStmt->execute([
            $autoTitle,
            $typeId,
            "Automatisch erstellt durch Zeiterfassung",
            $arrivalDate,
            $timeWithoutSeconds,
            $authUserId
        ]);
        
        $appointmentId = $db->lastInsertId();
        $action = 'created';
        
        $matchedAppointment = [
            'appointment_id' => $appointmentId,
            'title' => $autoTitle,
            'date' => $arrivalDate,
            'start_time' => $timeWithoutSeconds
        ];
    }
    
    // Prüfe ob bereits ein Record existiert
    $checkStmt = $db->prepare("SELECT record_id, arrival_time FROM records 
                               WHERE member_id = ? AND appointment_id = ?");
    $checkStmt->execute([$memberId, $appointmentId]);
    $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

    // Bestimme Source-Informationen
    $sourceDevice = $sourceInfo['device_name'] ?? null;
    $locationName = $sourceInfo['location_name'] ?? null;

     // Bei Device: Hole Device-Info aus users Tabelle
    if($authUserRole === 'device' && !$sourceDevice) {
        $deviceStmt = $db->prepare("SELECT email, device_type FROM users WHERE user_id = ?");
        $deviceStmt->execute([$authUserId]);
        $deviceInfo = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        
        if($deviceInfo) {
            $sourceDevice = $deviceInfo['email'] . 
                           ($deviceInfo['device_type'] ? " ({$deviceInfo['device_type']})" : "");
        }
    }
    
    if($existingRecord) {
        // Update bestehenden Record (nur wenn neue Zeit früher ist)
        $existingTime = new DateTime($existingRecord['arrival_time']);
        
        if($arrivalTime < $existingTime) {
            $updateStmt = $db->prepare("UPDATE records 
                                        SET arrival_time = ?, 
                                            status = 'present',
                                            checkin_source = ?,
                                            source_device = ?,
                                            location_name = ?
                                        WHERE record_id = ?");
            $updateStmt->execute([
                $data->arrival_time, 
                $checkinSource,
                $sourceDevice,
                $locationName,
                $existingRecord['record_id']
            ]);
            $recordAction = 'updated';
        } else {
            $recordAction = 'unchanged';
        }
        
        http_response_code(200);
        echo json_encode([
            "message" => "Check-in " . $recordAction,
            "record_action" => $recordAction,
            "appointment_action" => $action,
            "record_id" => $existingRecord['record_id'],
            "appointment_id" => $appointmentId,
            "member_id" => $memberId,
            "checkin_source" => $checkinSource,
            "source_device" => $sourceDevice,
            "location_name" => $locationName,
            "appointment" => $matchedAppointment
        ]);
    } else {
        // Erstelle neuen Record
        $insertStmt = $db->prepare("INSERT INTO records 
                                    (member_id, appointment_id, arrival_time, status, 
                                     checkin_source, source_device, location_name) 
                                    VALUES (?, ?, ?, 'present', ?, ?, ?)");
        
        if($insertStmt->execute([
            $memberId, 
            $appointmentId, 
            $data->arrival_time,
            $checkinSource,
            $sourceDevice,
            $locationName
        ])) {
            http_response_code(201);
            echo json_encode([
                "message" => "Check-in successful",
                "record_action" => "created",
                "appointment_action" => $action,
                "record_id" => $db->lastInsertId(),
                "appointment_id" => $appointmentId,
                "member_id" => $memberId,
                "checkin_source" => $checkinSource,
                "source_device" => $sourceDevice,
                "location_name" => $locationName,
                "appointment" => $matchedAppointment,
                "warning" => isset($warning) ? $warning : null
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Failed to create check-in"]);
        }
    }
}

?>