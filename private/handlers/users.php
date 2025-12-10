<?php

// ============================================
// USERS Controller
// ============================================
function handleUsers($db, $method, $id) {
    switch($method) {
        case 'GET':
            if($id) {

                // Zugriffskontrolle: Nur Admin oder eigener Account
                if($_SESSION['role'] !== 'admin' && $_SESSION['user_id'] != $id) {
                    http_response_code(403);
                    echo json_encode([
                        "message" => "Access denied",
                        "hint" => "You can only view your own user data"
                    ]);
                    return;
                }

                $stmt = $db->prepare("SELECT u.user_id, u.email, u.role, u.is_active, 
                                    u.member_id, u.device_type, u.created_at, u.totp_secret, u.api_token, u.api_token_expires_at,
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

                // Sensitive Daten filtern für Nicht-Admins
                if($_SESSION['role'] !== 'admin') {
                    // User sieht nur eigene Daten, aber ohne sensitive Infos von Devices
                    unset($user['totp_secret']); // TOTP Secret verstecken
                    
                    // Nur eigenen API-Token zeigen
                    if($user['user_id'] != $_SESSION['user_id']) {
                        unset($user['api_token']);
                        unset($user['api_token_expires_at']);
                    }
                }

                echo json_encode($user);              
            
            } else {
                // Liste aller User                
                // Zugriffskontrolle: Nur Admin darf alle User sehen
                if($_SESSION['role'] !== 'admin') {
                    http_response_code(403);
                    echo json_encode([
                        "message" => "Access denied",
                        "hint" => "Only administrators can list all users"
                    ]);
                    return;
                }
                
                $stmt = $db->query("SELECT u.user_id, u.email, u.role, u.device_type, u.is_active, u.member_id, u.created_at,
                                m.name, m.surname
                                FROM users u
                                LEFT JOIN members m ON u.member_id = m.member_id
                                ORDER BY u.created_at DESC");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode($users);
            }
            break;
            
        case 'POST':
            $rawData = json_decode(file_get_contents("php://input"));

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['is_active','email', 'password', 'role', 'member_id','device_type','totp_secret'];
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
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
            
            // Validierung für Devices
            if($data->role === 'device') {
                // Device-Type erforderlich
                if(!isset($data->device_type)) {
                    http_response_code(400);
                    echo json_encode(["message" => "device_type is required for devices"]);
                    break;
                }

                // TOTP Secret (für totp_location) - TOTP-Location braucht Secret
                $totp_secret = NULL;
                if(($data->role === 'device') && ($data->device_type === 'totp_location')) {

                    if(!empty($data->totp_secret))
                    {
                        $totp_secret = $data->totp_secret;
                        $api_token = NULL;
                        $expiresAt = NULL;
                    }
                    else{
                        http_response_code(400);
                        echo json_encode([
                        "message" => "totp_secret is required for totp_location devices",
                        "hint" => "Generate a Base32 secret"
                        ]);
                        break;
                    }                    
                }                
                
                // Für Geräte kein Passwort und keine Member-ID
                $password_hash = 0;
                $member_id = NULL;
                
            } else {
                // Admin/User: Passwort erforderlich, member_id optional
                if(empty($data->password)) {
                    http_response_code(400);
                    echo json_encode(["message" => "Password is required for users and admins"]);
                    break;
                }
                $password_hash = password_hash($data->password, PASSWORD_DEFAULT);
                $member_id = $data->member_id ?? NULL;
            }

            $stmt = $db->prepare("INSERT INTO users 
                          (is_active, email, password_hash, role, device_type, member_id, api_token, api_token_expires_at, totp_secret ) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            if($stmt->execute([
                $data->is_active ?? 1,
                $data->email, 
                $password_hash, 
                $data->role ?? 'user', 
                $data->device_type ?? NULL,
                $member_id,
                $api_token,
                $expiresAt,                
                $totp_secret ?? NULL
            ])) {
                http_response_code(201);
                echo json_encode([
                    "message" => "User created", 
                    "id" => $db->lastInsertId()
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create user"]);
            }
            break;
            
        case 'PUT':
            $rawData = json_decode(file_get_contents("php://input"));

            // Zugriffskontrolle: Nur Admin oder eigener Account
            if($_SESSION['role'] !== 'admin' && $_SESSION['user_id'] != $id) {
                http_response_code(403);
                echo json_encode([
                    "message" => "Access denied",
                    "hint" => "You can only update your own user data"
                ]);
                return;
            }

            // User darf eigene Rolle/Device-Type nicht ändern
            if($_SESSION['role'] !== 'admin') {
                unset($data->role);
                unset($data->device_type);
                unset($data->member_id);
                unset($data->totp_secret);
                unset($data->is_active);
            }

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['email', 'password', 'role', 'member_id','is_active','device_type','totp_secret'];
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

           // Dynamisches Update je nach vorhandenen Feldern
            $updateFields = [];
            $updateParams = [];
            
            if(isset($data->email)) {
                $updateFields[] = "email = ?";
                $updateParams[] = $data->email;
            }
            
            if(isset($data->password) && !empty($data->password)) {
                $updateFields[] = "password_hash = ?";
                $updateParams[] = password_hash($data->password, PASSWORD_DEFAULT);
            }
            
            // Nur Admin darf diese Felder ändern
            if($_SESSION['role'] === 'admin') {
                if(isset($data->role)) {
                    $updateFields[] = "role = ?";
                    $updateParams[] = $data->role;
                }
                
                if(isset($data->device_type)) {
                    $updateFields[] = "device_type = ?";
                    $updateParams[] = $data->device_type;
                }
                
                if(isset($data->member_id)) {
                    $updateFields[] = "member_id = ?";
                    $updateParams[] = $data->member_id ?: NULL;
                }
                
                if(isset($data->totp_secret)) {
                    $updateFields[] = "totp_secret = ?";
                    $updateParams[] = $data->totp_secret ?: NULL;
                }
                
                if(isset($data->is_active)) {
                    $updateFields[] = "is_active = ?";
                    $updateParams[] = $data->is_active ? 1 : 0;
                }
            }
            
            if(empty($updateFields)) {
                http_response_code(400);
                echo json_encode(["message" => "No valid fields to update"]);
                break;
            }
            
            $updateParams[] = $id; // WHERE user_id = ?
            
            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE user_id = ?";
            $stmt = $db->prepare($sql);
            
            if($stmt->execute($updateParams)) {
                echo json_encode(["message" => "User updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update user"]);
            }
            break;
            
            if($success) {
                echo json_encode(["message" => "User updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update user"]);
            }
            break;
            
        case 'DELETE':
            // Nur Admin darf User löschen            
            // Eigenen Account nicht löschen
            if($_SESSION['user_id'] == $id) {
                http_response_code(400);
                echo json_encode([
                    "message" => "Cannot delete your own account",
                    "hint" => "Ask another administrator to delete your account"
                ]);
                return;
            }
            
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