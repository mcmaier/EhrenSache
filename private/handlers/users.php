<?php

// ============================================
// USERS Controller
// ============================================
function handleUsers($db, $method, $id, $authUserId) {
    switch($method) {
        case 'GET':
            if($id) {
                // EINZELNER USER
                if(!isAdmin() && ($authUserId != $id)) {
                    http_response_code(403);
                    echo json_encode([
                        "message" => "Access denied",
                        "hint" => "You can only view your own user data"
                    ]);
                    return;
                }
                
                // Prüfe ob User oder Device
                $roleStmt = $db->prepare("SELECT role FROM users WHERE user_id = ?");
                $roleStmt->execute([$id]);
                $role = $roleStmt->fetchColumn();
                
                if(!$role) {
                    http_response_code(404);
                    echo json_encode(["message" => "User not found"]);
                    return;
                }
                
                $isDevice = ($role === 'device');
                
                if($isDevice) {
                    // GERÄT - Direkt aus users Tabelle
                    $stmt = $db->prepare("
                        SELECT 
                            user_id,
                            device_name,
                            device_type,
                            is_active,
                            totp_secret,
                            api_token,
                            api_token_expires_at,
                            created_at
                        FROM users
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    // MENSCH - Aus View
                    $stmt = $db->prepare("
                        SELECT 
                            user_id,
                            email,
                            user_name,
                            email_verified,
                            account_status,
                            role,
                            is_active,
                            member_id,
                            pending_member_id,
                            created_at,
                            member_number,
                            member_name,
                            member_surname,
                            pending_member_number,
                            pending_member_name,
                            pending_member_surname,
                            status_text,
                            api_token,
                            api_token_expires_at,
                            role_name
                        FROM v_users_extended
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                
                if(!$user) {
                    http_response_code(404);
                    echo json_encode(["message" => "User not found"]);
                    return;
                }
                
                // Sensitive Daten filtern für Nicht-Admins
                if(!isAdmin()) {
                    unset($user['totp_secret']);
                    
                    if($user['user_id'] != $authUserId) {
                        unset($user['api_token']);
                        unset($user['api_token_expires_at']);
                    }
                }
                
                echo json_encode($user);
                
            } else {
                // LISTE ALLER USER
                if(!isAdmin()) {
                    http_response_code(403);
                    echo json_encode([
                        "message" => "Access denied",
                        "hint" => "Admin Access required"
                    ]);
                    return;
                }
                
                try {
                    $userType = $_GET['user_type'] ?? 'human';
                    $search = $_GET['search'] ?? null;
                    
                    $params = [];
                    
                    // MENSCHEN
                    if($userType === 'human') {
                        $query = "SELECT 
                            user_id,
                            email,
                            user_name,
                            email_verified,
                            account_status,
                            role,
                            is_active,
                            member_id,
                            pending_member_id,                            
                            created_at,
                            member_number,
                            member_name,
                            member_surname,
                            pending_member_number,
                            pending_member_name,
                            pending_member_surname,
                            status_text,
                            role_name
                        FROM v_users_extended 
                        WHERE role IN ('admin', 'manager', 'user')";
                        
                        /*
                        // Status-Filter
                        if(isset($_GET['status']) && in_array($_GET['status'], ['pending', 'active', 'suspended'])) {
                            $query .= " AND account_status = ?";
                            $params[] = $_GET['status'];
                        }
                        
                        // Role-Filter
                        if(isset($_GET['role']) && in_array($_GET['role'], ['admin', 'manager', 'user'])) {
                            $query .= " AND role = ?";
                            $params[] = $_GET['role'];
                        }
                        
                        // Search
                        if($search) {
                            $query .= " AND (email LIKE ? OR user_name LIKE ? OR member_number LIKE ?)";
                            $searchTerm = '%' . $search . '%';
                            $params[] = $searchTerm;
                            $params[] = $searchTerm;
                            $params[] = $searchTerm;
                        }
                        
                        
                        // is_active Filter
                        if(isset($_GET['is_active'])) {
                            $query .= " AND is_active = ?";
                            $params[] = intval($_GET['is_active']);
                        }*/
                        
                        // Sortierung
                        $query .= " ORDER BY created_at DESC";
                        
                    } 
                    // GERÄTE
                    else if($userType === 'device') {
                        $query = "SELECT 
                            u.user_id,
                            u.device_name,
                            u.device_type,
                            u.is_active,
                            u.totp_secret,
                            u.api_token,
                            u.api_token_expires_at,
                            u.created_at,
                            CASE u.device_type
                                WHEN 'totp_location' THEN 'TOTP-Station'
                                WHEN 'auth_device' THEN 'Auth-Gerät'
                                ELSE 'Gerät'
                            END AS device_type_name,
                            CASE 
                                WHEN u.is_active = 1 THEN 'Aktiv'
                                ELSE 'Inaktiv'
                            END AS status_text
                        FROM users u
                        WHERE u.role = 'device'";
                        
                        /*
                        // Device-Type Filter
                        if(isset($_GET['device_type']) && in_array($_GET['device_type'], ['totp_location', 'auth_device'])) {
                            $query .= " AND u.device_type = ?";
                            $params[] = $_GET['device_type'];
                        }                        
                        
                        // is_active Filter
                        if(isset($_GET['is_active'])) {
                            $query .= " AND u.is_active = ?";
                            $params[] = intval($_GET['is_active']);
                        }*/
                        
                        // Sortierung
                        $query .= " ORDER BY u.created_at DESC";
                    }
                    
                    // Daten abrufen
                    $stmt = $db->prepare($query);
                    $stmt->execute($params);
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode($users);
                    
                    /*
                    // STATUS COUNTS (nur für Menschen)
                    $statusCounts = null;
                    if($userType === 'human') {
                        $countStmt = $db->query(
                            "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN account_status = 'pending' THEN 1 ELSE 0 END) as pending,
                                SUM(CASE WHEN account_status = 'active' THEN 1 ELSE 0 END) as active,
                                SUM(CASE WHEN account_status = 'suspended' THEN 1 ELSE 0 END) as suspended
                             FROM users 
                             WHERE role IN ('admin', 'manager', 'user')"
                        );
                        $statusCounts = $countStmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    // DEVICE TYPE COUNTS (nur für Geräte)
                    $deviceTypeCounts = null;
                    if($userType === 'device') {
                        $countStmt = $db->query(
                            "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN device_type = 'totp_location' THEN 1 ELSE 0 END) as totp_location,
                                SUM(CASE WHEN device_type = 'auth_device' THEN 1 ELSE 0 END) as auth_device,
                                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive
                             FROM users 
                             WHERE role = 'device'"
                        );
                        $deviceTypeCounts = $countStmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'users' => $users,
                        'user_type' => $userType,
                        'status_counts' => $statusCounts,
                        'device_type_counts' => $deviceTypeCounts
                    ]);
                    */
                    
                } catch (Exception $e) {
                    error_log("Get users error: " . $e->getMessage());
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Fehler beim Abfragen'
                    ]);
                }
            }


            /*
            if($id) {
                if(!isAdmin() && ($authUserId != $id))
                {
                    http_response_code(403);
                    echo json_encode([
                        "message" => "Access denied",
                        "hint" => "You can only view your own user data"
                    ]);
                    return;
                }                

                $stmt = $db->prepare("SELECT u.user_id, u.email, u.role, u.is_active,  
                                    u.member_id, u.device_name, u.device_type, u.created_at, u.totp_secret, u.api_token, u.api_token_expires_at,
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
                if(!isAdmin()) {
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
                if(!isAdmin()) {
                    http_response_code(403);
                    echo json_encode([
                        "message" => "Access denied",
                        "hint" => "Admin Access required"
                    ]);
                    return;
                }

                try{
                    $userType = $_GET['user_type'] ?? 'human'; // 'human' oder 'device

                    $query = "SELECT * FROM v_users_extended WHERE 1=1";
                    $params = [];
                    
                    // Typ-Filter
                    if($userType === 'human') {
                        $query .= " AND role IN ('admin', 'manager', 'user')";
                    } else if($userType === 'device') {
                        $query .= " AND role = 'device'";
                    }
                    
                    // Status-Filter (nur für Menschen)
                    if($userType === 'human') {
                        $status = $_GET['status'] ?? null;
                        if($status && in_array($status, ['pending', 'active', 'suspended'])) {
                            $query .= " AND account_status = ?";
                            $params[] = $status;
                        }
                    }
                    
                    // Device-Type Filter (nur für Geräte)
                    if($userType === 'device') {
                        $deviceType = $_GET['device_type'] ?? null;
                        if($deviceType && in_array($deviceType, ['totp_location', 'auth_device'])) {
                            $query .= " AND device_type = ?";
                            $params[] = $deviceType;
                        }
                    }
                    
                    // is_active Filter (für beide)
                    if(isset($_GET['is_active'])) {
                        $query .= " AND is_active = ?";
                        $params[] = intval($_GET['is_active']);
                    }
                    
                    $stmt = $db->query($query);

                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode($users);
                }
                catch (Exception $e) {
                        error_log("Get user error: " . $e->getMessage());
                        http_response_code(500);
                        echo json_encode(['message' => 'Fehler beim Abfragen']);
                    }
            }*/
            break;
            
        case 'POST':
            $rawData = json_decode(file_get_contents("php://input"));

            if(isset($rawData->action))
            {
                $action = $rawData->action;
                
                if($action === 'create_device') 
                {
                    createDevice($db, $authUserId);
                } 
                else if($action === 'resend_verification')   
                {
                    resendVerificationEmail($db, $rawData->user_id);
                }            
                else {
                    http_response_code(400);
                    echo json_encode(['message' => 'Invalid action']);
                    exit();
                }
            }
            else {
                requireAdmin();                

                // Nur erlaubte Felder extrahieren
                $allowedFields = ['email', 'name', 'password', 'role', 'member_id'];
                $data = new stdClass();
                foreach($allowedFields as $field) {
                    if(isset($rawData->$field)) {
                        $data->$field = $rawData->$field;
                    }
                }

                // Validierung
                if(!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
                    http_response_code(400);
                    echo json_encode(['message' => 'Ungültige Email-Adresse']);
                    return;
                }

                // Prüfe ob E-Mail bereits existiert
                $checkStmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
                $checkStmt->execute([$data->email]);
                if($checkStmt->fetch()) {
                    http_response_code(409);
                    echo json_encode([
                        "message" => "Diese E-Mail-Adresse ist bereits registriert"
                    ]);
                    return;
                }
                
                if(empty($data->password)) {
                    http_response_code(400);
                    echo json_encode(["message" => "Passwort darf nicht leer sein"]);
                    return;
                }

                if(strlen($data->password) < 6) {
                    http_response_code(400);
                    echo json_encode(['message' => 'Passwort muss mindestens 6 Zeichen lang sein']);
                    return;
                }

                $role = $data->role ?? 'user';
        
                if(!in_array($role, ['admin', 'manager', 'user'])) {
                    $role = 'user';
                }

                $password_hash = password_hash($data->password, PASSWORD_DEFAULT);                

                // Generiere API-Token
                $api_token = bin2hex(random_bytes(24));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
                                
                try{
                    $stmt = $db->prepare("INSERT INTO users 
                                ( 
                                email, 
                                password_hash, 
                                name,
                                role,
                                member_id,
                                is_active,
                                account_status,
                                email_verified,
                                created_at, 
                                api_token, 
                                api_token_expires_at)
                                VALUES (?, ?, ?, ?, ?, 1, 'active', 1, NOW(), ?, ?)");

                    if($stmt->execute([                    
                        $data->email, 
                        $password_hash, 
                        $data->name ?? NULL,
                        $role, 
                        $data->member_id ?? NULL,
                        $api_token,
                        $expiresAt              
                    ])) {
                        http_response_code(201);
                        echo json_encode([
                            "message" => "User created", 
                            "id" => $db->lastInsertId()
                        ]);
                    } else {
                        http_response_code(500);
                        echo json_encode(["message" => "Failed to create user"]);
                        exit();
                    }

                } 
                catch (Exception $e) {
                        $db->rollBack();
                        error_log("Create user error: " . $e->getMessage());
                        http_response_code(500);
                        echo json_encode(['message' => 'Fehler beim Erstellen']);
                        exit();
                    }
            }
            break;
            
        case 'PUT':            
            $rawData = json_decode(file_get_contents("php://input"));

            // Zugriffskontrolle: Nur Admin oder eigener Account
            if(!isAdmin() && ($authUserId != $id)) {
                http_response_code(403);
                echo json_encode([
                    "message" => "Access denied",
                ]);
                return;
            }

            // Hole aktuelle User-Daten (um role zu prüfen)
            $currentUserStmt = $db->prepare("SELECT role, email FROM users WHERE user_id = ?");
            $currentUserStmt->execute([$id]);
            $currentUser = $currentUserStmt->fetch(PDO::FETCH_ASSOC);

            if(!$currentUser) {
                http_response_code(404);
                echo json_encode(["message" => "User not found"]);
                break;
            }

            $isDevice = ($currentUser['role'] === 'device');

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['email', 'name', 'password', 'device_name', 'role', 'member_id','is_active','device_type','totp_secret','account_status'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($rawData->$field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // User darf eigene Rolle nicht ändern
            if(!isAdmin()) {
                unset($data->role);
                unset($data->member_id);
            }

            // Email-Validierung (nur wenn Email geändert wird)
            if(isset($data->email) && $data->email !== $currentUser['email']) {
                // Nur für Menschen (Geräte haben keine Email)
                if(!$isDevice) {
                    if(!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
                        http_response_code(400);
                        echo json_encode(["message" => "Ungültige Email-Adresse"]);
                        break;
                    }
                    
                    // Prüfe ob Email bereits existiert
                    $checkStmt = $db->prepare("SELECT user_id FROM users 
                                            WHERE email = ? AND user_id != ?");
                    $checkStmt->execute([$data->email, $id]);
                    if($checkStmt->fetch()) {
                        http_response_code(409);
                        echo json_encode([
                            "message" => "Diese E-Mail-Adresse ist bereits registriert"
                        ]);
                        break;
                    }
                } else {
                    // Geräte dürfen keine Email haben
                    http_response_code(400);
                    echo json_encode(["message" => "Geräte dürfen keine Email-Adresse haben"]);
                    break;
                }
            }                      
            
            // Member-ID Validierung (nur wenn geändert wird)
            if (isset($data->member_id) && $data->member_id !== null) {
                // Prüfe ob Member-ID bereits von anderem User verwendet wird
                $checkMemberStmt = $db->prepare(
                    "SELECT user_id, name, email 
                    FROM users 
                    WHERE member_id = ? AND user_id != ?"
                );
                $checkMemberStmt->execute([$data->member_id, $id]);
                $existingUser = $checkMemberStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingUser) {
                    http_response_code(409);
                    echo json_encode([
                        "success" => false,
                        "message" => "Dieses Mitglied ist bereits mit einem anderen Benutzer verknüpft"
                    ]);
                    return;
                }
                
                // Prüfe ob Member-ID existiert
                $checkMemberExists = $db->prepare(
                    "SELECT member_id FROM members WHERE member_id = ?"
                );
                $checkMemberExists->execute([$data->member_id]);
                
                if (!$checkMemberExists->fetch()) {
                    http_response_code(404);
                    echo json_encode([
                        "success" => false,
                        "message" => "Mitglied nicht gefunden"
                    ]);
                    return;
                }
            }

           // Dynamisches Update je nach vorhandenen Feldern
            $updateFields = [];
            $updateParams = [];

            // MENSCHEN (admin, manager, user)
            if(!$isDevice) {
                // Email (optional)
                if(isset($rawData->email)) {
                    $updateFields[] = "email = ?";
                    $updateParams[] = $data->email;
                }

                // Name (optional)
                if(isset($data->name)) {
                    $updateFields[] = "name = ?";
                    $updateParams[] = $data->name;
                }
                
                // Passwort (optional, nur wenn nicht leer)
                if(isset($data->password) && !empty($data->password)) {
                    if(strlen($data->password) < 6) {
                        http_response_code(400);
                        echo json_encode(["message" => "Passwort muss mindestens 8 Zeichen lang sein"]);
                        break;
                    }
                    $updateFields[] = "password_hash = ?";
                    $updateParams[] = password_hash($data->password, PASSWORD_DEFAULT);
                }                        

                // Nur Admin darf diese Felder ändern
                if(isAdmin()) {
                    if(isset($data->role) && in_array($data->role, ['admin', 'manager', 'user'])) {
                        $updateFields[] = "role = ?";
                        $updateParams[] = $data->role;
                    }

                    if(isset($data->member_id)) {
                        $updateFields[] = "member_id = ?";
                        $updateParams[] = $data->member_id ?: NULL;
                    }
                    else
                    {
                        $updateFields[] = "member_id = ?";
                        $updateParams[] =  NULL;
                    }
                    
                    if(isset($data->account_status) && in_array($data->account_status, ['pending', 'active', 'suspended'])) {
                        $updateFields[] = "account_status = ?";
                        $updateParams[] = $data->account_status;
                    }                    
                    
                    if(isset($data->is_active)) {
                        $updateFields[] = "is_active = ?";
                        $updateParams[] = $data->is_active ? 1 : 0;
                    }
                }
            }
            // GERÄTE (device)
            else
            {
                requireAdmin();

                // Device Name
                if(isset($data->device_name)) {
                    $updateFields[] = "device_name = ?";
                    $updateParams[] = $data->device_name;
                }

                // Device Type
                if(isset($data->device_type)) {
                    $updateFields[] = "device_type = ?";
                    $updateParams[] = $data->device_type;
                }
                    
                // TOTP Secret (nur für totp_location)
                if(isset($data->totp_secret)) {                    
                    $updateFields[] = "totp_secret = ?";
                    $updateParams[] = $data->totp_secret;
                }
                
                // is_active
                if(isset($data->is_active)) {
                    $updateFields[] = "is_active = ?";
                    $updateParams[] = $data->is_active ? 1 : 0;
                }                
                
                // Geräte dürfen nie Email oder Passwort haben
                if(isset($data->email) || isset($data->password)) {
                    http_response_code(400);
                    echo json_encode(["message" => "Geräte können keine Email oder Passwort haben"]);
                    break;
                }
            }
            
            if(empty($updateFields)) {
                http_response_code(400);
                echo json_encode(["message" => "Keine Daten zum Aktualisieren"]);
                break;
            }
            
            $updateParams[] = $id; // WHERE user_id = ?
            
            $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE user_id = ?";

            try {

                $stmt = $db->prepare($sql);
            
                if($stmt->execute($updateParams)) {
                    echo json_encode([
                        "success" => true,
                        "message" => $isDevice ? "Gerät aktualisiert" : "Benutzer aktualisiert"
                    ]);
                    exit();
                } else {
                    http_response_code(500);
                    echo json_encode(["message" => "Update fehlgeschlagen"]);
                    exit();
                }
            }
            catch(PDOException $e)
            {
                error_log("User update error: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(["message" => "Datenbankfehler beim Update"]);
                exit();
            }
            break;            
            
        case 'DELETE':
            // Nur Admin darf User löschen     
            requireAdmin();  
            // Eigenen Account nicht löschen
            if($authUserId == $id) {
                http_response_code(400);
                echo json_encode([
                    "message" => "Cannot delete your own account",
                    "hint" => "Ask another administrator to delete your account"
                ]);
                exit();
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

// Gerät erstellen (nur Admin)
function createDevice($db, $authUserId) {

    // Prüfen ob Admin    
    if(!isAdmin()) {
        http_response_code(403);
        echo json_encode(['message' => 'Nur Admins können Geräte erstellen']);
        exit();
    }
    
    $data = json_decode(file_get_contents("php://input"));
    
    // Validierung
    if(empty($data->device_name)) {
        http_response_code(400);
        echo json_encode(['message' => 'Gerätename erforderlich']);
        exit();
    }
    
    $device_type = $data->device_type ?? 'totp_location';

    if(!in_array($device_type, ['totp_location', 'auth_device'])) {
        http_response_code(400);
        echo json_encode(['message' => 'Ungültiger Gerätetyp']);
        exit();
    }

    // API-Token generieren
    $apiToken = bin2hex(random_bytes(32));
    $tokenExpires = date('Y-m-d H:i:s', strtotime('+10 years')); // Geräte-Tokens lange gültig

    // TOTP-Secret für totp_location Geräte
    $totpSecret = null;
    if($device_type === 'totp_location') {
        require_once __DIR__ . '/../helpers/totp.php';
        $totpSecret = TOTP::generateSecret();
    }
    
    try {        
        $db->beginTransaction();                     
        // Gerät anlegen (OHNE Email, OHNE Passwort)
        $stmt = $db->prepare(
            "INSERT INTO users (
                device_name, 
                role, 
                device_type, 
                is_active, 
                account_status,
                email_verified,
                api_token, 
                api_token_expires_at,
                totp_secret,
                created_at
            ) VALUES (?, 'device', ?, 1, 'active', 1, ?, ?, ?, NOW())"
        );
        
        $stmt->execute([
            $data->device_name,
            $device_type,
            $apiToken,
            $tokenExpires,
            $totpSecret
        ]);
        
        $deviceId = $db->lastInsertId();
        
        $db->commit();
        
        // Response mit Token (nur einmal sichtbar!)
        echo json_encode([
            'success' => true,
            'message' => 'Gerät erfolgreich erstellt',
            'device' => [
                'user_id' => $deviceId,
                'device_name' => $data->device_name,
                'device_type' => $device_type,
                'api_token' => $apiToken,
                'totp_secret' => $totpSecret
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Create device error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Fehler beim Erstellen des Geräts']);
        exit();
    }
}

function handleUserActivation($db, $method, $authUserRole) {
    // Nur Admins
    requireAdmin();
    
    if($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['message' => 'Method not allowed']);
        exit();
    }
    
    $data = json_decode(file_get_contents("php://input"));
    $userId = intval($data->user_id ?? 0);
    $memberId = isset($data->member_id) ? intval($data->member_id) : null;
    
    if(!$userId) {
        http_response_code(400);
        echo json_encode(['message' => 'User ID erforderlich']);
        exit();
    }

    try{            
        // User-Daten holen
        $stmt = $db->prepare(
            "SELECT email, name, email_verified, pending_member_id 
             FROM users 
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['message' => 'User nicht gefunden']);
            exit();
        }
        
        // Email muss verifiziert sein
        if (!$user['email_verified']) {
            http_response_code(400);
            echo json_encode(['message' => 'Email noch nicht verifiziert']);
            exit();
        }
        
        // Member-ID: Entweder aus Request oder pending_member_id
        $finalMemberId = $memberId ?? $user['pending_member_id'];
        
        $db->beginTransaction();
        
        // User aktivieren: account_status UND is_active setzen
        $stmt = $db->prepare(
            "UPDATE users 
             SET account_status = 'active',
                 is_active = 1,
                 member_id = ?,
                 pending_member_id = NULL
             WHERE user_id = ?"
        );
        $stmt->execute([$finalMemberId, $userId]);        
        $db->commit();
                     
        // Member-Daten für Mail auslesen
        $stmt = $db->prepare(
            "SELECT m.member_number, m.name, m.surname 
            FROM members m 
            WHERE m.member_id = ?"
        );
        $stmt->execute([$finalMemberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        // Member-Info zusammenbauen
        $memberInfo = $member 
        ? $member['member_number'] . ' - ' . $member['name'] . ' ' . $member['surname']
        : 'Kein Mitglied verknüpft';
            
        // Mail-Status prüfen
        $mailer = new Mailer(getMailConfig(), $db);
        $mailStatus = $mailer->checkMailStatus('activation');

        if($mailStatus)
        {

            $subject = "Account aktiviert - EhrenSache";
            $body = "Hallo {$user['name']},\n\nIhr Account wurde aktiviert.\n";
            $body .= "Login: " . BASE_URL . "/login.html\n";
            
            $mailer->sendActivationEmail($user['email'],$user['name'],$memberInfo);            
        }
                        
        echo json_encode([
            'success' => true,
            'message' => 'Benutzer erfolgreich aktiviert'
        ]);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("User activation error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Fehler bei der Aktivierung']);
        exit();
    }
}

function handleUserStatus($db, $method, $authUserRole) {
    // Nur Admins
    requireAdmin();
    
    if($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['message' => 'Method not allowed']);
        exit();
    }
    
    $data = json_decode(file_get_contents("php://input"));
    $userId = intval($data->user_id ?? 0);
    $status = $data->status ?? '';
    
    if(!$userId || !in_array($status, ['active', 'suspended'])) {
        http_response_code(400);
        echo json_encode(['message' => 'Ungültige Parameter']);
        exit();
    }
    
    try {
        $isActive = ($status === 'active') ? 1 : 0;
        
        $stmt = $db->prepare(
            "UPDATE users 
             SET account_status = ?, 
                 is_active = ? 
             WHERE user_id = ?"
        );
        $stmt->execute([$status, $isActive, $userId]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['message' => 'User nicht gefunden']);
            exit();
        }
        
        echo json_encode([
            'success' => true,
            'message' => $status === 'active' 
                ? 'Benutzer aktiviert' 
                : 'Benutzer gesperrt'
        ]);
        
    } catch (Exception $e) {
        error_log("Update status error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Fehler beim Aktualisieren']);
        exit();
    }
}


// ============================================
// VERIFIKATIONS-MAIL ERNEUT SENDEN
// ============================================

function resendVerificationEmail($db, $userId) {
    // Nur Admin darf das
    requireAdmin();
    
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID erforderlich']);
        return;
    }
    
    try {
        // User-Daten holen
        $stmt = $db->prepare(
            "SELECT email, name, account_status, email_verified 
             FROM users 
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User nicht gefunden']);
            return;
        }
        
        // Prüfen ob User bereits verifiziert ist
        if ($user['email_verified']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Email-Adresse ist bereits verifiziert'
            ]);
            return;
        }
        
        // Prüfen ob User pending ist
        if ($user['account_status'] !== 'pending') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Account ist bereits aktiviert oder gesperrt'
            ]);
            return;
        }
        
        // Mail-System prüfen
        $mailer = new Mailer(getMailConfig(), $db);
        $mailStatus = $mailer->checkMailStatus('registration');
        
        if (!$mailStatus['enabled']) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => $mailStatus['message']
            ]);
            return;
        }
        
        // Alten Token löschen/deaktivieren (optional)
        $stmt = $db->prepare(
            "UPDATE email_verification_tokens 
             SET used = 1 
             WHERE user_id = ? AND used = 0"
        );
        $stmt->execute([$userId]);
        
        // Neuen Token generieren
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24h
        
        $stmt = $db->prepare(
            "INSERT INTO email_verification_tokens (user_id, token, expires_at) 
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$userId, $tokenHash, $expiresAt]);
        
        // Email senden
        $emailSent = $mailer->sendVerificationEmail(
            $user['email'], 
            $user['name'] ?? '', 
            $token
        );
        
        if (!$emailSent) {
            error_log("Resend verification email failed for user_id: $userId");
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Email konnte nicht versendet werden. Prüfen Sie die Mail-Einstellungen.'
            ]);
            return;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Verifikations-Email wurde erneut versendet'
        ]);
        
    } catch (Exception $e) {
        error_log("Resend verification error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Versenden der Email'
        ]);
    }
}

?>