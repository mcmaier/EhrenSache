<?php

// ============================================
// USERS Controller
// ============================================
function handleUsers($db, $method, $id) {
    switch($method) {
        case 'GET':
            if($id) {
                $stmt = $db->prepare("SELECT u.user_id, u.email, u.role, u.is_active, 
                                    u.member_id, u.created_at, u.api_token, u.device_type,
                                    m.name, m.surname
                                    FROM users u
                                    LEFT JOIN members m ON u.member_id = m.member_id
                                    WHERE u.user_id = ?");
                $stmt->execute([$id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if(!$user) {                
                    http_response_code(404);
                    echo json_encode(["message" => "User not found"]);
                    return;
                }

                // Token nur für eigenen Account oder Admin sichtbar
                if($_SESSION['role'] !== 'admin' && $_SESSION['user_id'] != $user['user_id']) {
                    unset($user['api_token']); // Anderen Usern den Token nicht zeigen
                }

                echo json_encode($user);              
            
            } else {
                $stmt = $db->query("SELECT u.user_id, u.email, u.role, u.is_active, 
                                u.member_id, u.created_at, u.device_type,
                                m.name, m.surname
                                FROM users u
                                LEFT JOIN members m ON u.member_id = m.member_id
                                ORDER BY u.created_at DESC");
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;
            
        case 'POST':
            $rawData = json_decode(file_get_contents("php://input"));

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['email', 'password', 'role', 'member_id','api_token','device_type'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($rawData->$field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // Prüfe ob E-Mail bereits existiert
            $checkStmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
            $checkStmt->execute([$data->email]);
            if($checkStmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "message" => "Diese E-Mail-Adresse ist bereits registriert",
                    "field" => "email"
                ]);
                break;
            }

            // Generiere API-Token
            $api_token = bin2hex(random_bytes(24));
            
            // Passwort-Hash (nur wenn Passwort gesetzt, Devices brauchen keins)
            $password_hash = 0;
            if(isset($data->password) && !empty($data->password)) {
                $password_hash = password_hash($data->password, PASSWORD_DEFAULT);
            } elseif($data->role !== 'device') {
                // Nicht-Device ohne Passwort → Fehler
                http_response_code(400);
                echo json_encode(["message" => "Passwort ist erforderlich für Benutzer und Admins"]);
                break;
            }

            // Device-Typ (nur für Devices)
            $device_type = null;
            if($data->role === 'device' && isset($data->device_type)) {
                $device_type = $data->device_type;
            }
            
            // Member-ID nur bei User/Admin, nicht bei Device
            $member_id = null;
            if($data->role !== 'device' && isset($data->member_id)) {
                $member_id = $data->member_id;
            }

            $stmt = $db->prepare("INSERT INTO users 
                          (email, password_hash, role, member_id, api_token, api_token_expires_at, device_type) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)");

            if($stmt->execute([
                $data->email, 
                $password_hash, 
                $data->role ?? 'user', 
                $member_id,
                $api_token,
                $api_token_expires_at, 
                $device_type
            ])) {
                http_response_code(201);
                echo json_encode([
                    "message" => "User created", 
                    "id" => $db->lastInsertId(),
                    "api_token" => $api_token,
                    "expires_at" => $api_token_expires_at
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create user"]);
            }
            break;
            
        case 'PUT':
            $rawData = json_decode(file_get_contents("php://input"));

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['email', 'password', 'role', 'member_id','is_active','api_token','device_type'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($rawData->$field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // Prüfe ob E-Mail bereits von anderem User verwendet wird
            $checkStmt = $db->prepare("SELECT user_id FROM users 
                                    WHERE email = ? AND user_id != ?");
            $checkStmt->execute([$data->email, $id]);
            if($checkStmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    "message" => "Diese E-Mail-Adresse / Geräte-Name ist bereits registriert",
                    "field" => "email"
                ]);
                break;
            }

            // Device-Typ
            $device_type = null;
            if($data->role === 'device' && isset($data->device_type)) {
                $device_type = $data->device_type;
            }

            // Member-ID nur bei User/Admin
            $member_id = null;
            if($data->role !== 'device' && isset($data->member_id)) {
                $member_id = $data->member_id;
            }
            
            // Baue Query dynamisch basierend auf Passwort
            if(isset($data->password) && !empty($data->password)) {
                $password_hash = password_hash($data->password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users 
                                    SET email = ?, password_hash = ?, role = ?, member_id = ?, 
                                        is_active = ?, device_type = ?
                                    WHERE user_id = ?");
                $success = $stmt->execute([
                    $data->email, 
                    $password_hash, 
                    $data->role, 
                    $member_id,
                    $data->is_active ?? true,
                    $device_type,
                    $id
                ]);
            } else {
                $stmt = $db->prepare("UPDATE users 
                                    SET email = ?, role = ?, member_id = ?, 
                                        is_active = ?, device_type = ?
                                    WHERE user_id = ?");
                $success = $stmt->execute([
                    $data->email, 
                    $data->role, 
                    $member_id,
                    $data->is_active ?? true,
                    $device_type,
                    $id
                ]);
            }
            
            if($success) {
                echo json_encode(["message" => "User updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update user"]);
            }
            break;
            
        case 'DELETE':
            $stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "User deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete user"]);
            }
            break;
    }
}

?>