<?php

// ============================================
// MEMBERSHIP_DATES Controller
// ============================================
function handleDeviceConfigs($db, $method, $id) {

// Nur Admins dürfen Device-Configs verwalten
    if($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(["message" => "Admin access required"]);
        return;
    }
    
    switch($method) {
        case 'GET':
            if($id) {
                // Einzelne Config
                $stmt = $db->prepare("SELECT dc.*, u.email 
                                     FROM device_configs dc
                                     JOIN users u ON dc.user_id = u.user_id
                                     WHERE dc.device_config_id = ?");
                $stmt->execute([$id]);
                $config = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if($config) {
                    echo json_encode($config);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Device config not found"]);
                }
            } else {
                // Liste - optional gefiltert nach user_id
                $user_id = $_GET['user_id'] ?? null;
                
                if($user_id) {
                    $stmt = $db->prepare("SELECT dc.*, u.email 
                                         FROM device_configs dc
                                         JOIN users u ON dc.user_id = u.user_id
                                         WHERE dc.user_id = ?
                                         ORDER BY dc.created_at DESC");
                    $stmt->execute([$user_id]);
                } else {
                    $stmt = $db->query("SELECT dc.*, u.email 
                                       FROM device_configs dc
                                       JOIN users u ON dc.user_id = u.user_id
                                       ORDER BY dc.created_at DESC");
                }
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents("php://input"));
            
            // Validierung
            if(!isset($data->user_id) || !isset($data->device_type)) {
                http_response_code(400);
                echo json_encode(["message" => "user_id and device_type are required"]);
                return;
            }
            
            $stmt = $db->prepare("INSERT INTO device_configs 
                                  (user_id, device_type, device_name, location_secret, is_active) 
                                  VALUES (?, ?, ?, ?, ?)");
            
            if($stmt->execute([
                $data->user_id,
                $data->device_type,
                $data->device_name ?? 'Unnamed Device',
                $data->location_secret ?? null,
                $data->is_active ?? true
            ])) {
                http_response_code(201);
                echo json_encode([
                    "message" => "Device config created",
                    "id" => $db->lastInsertId()
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create device config"]);
            }
            break;
            
        case 'PUT':
            $data = json_decode(file_get_contents("php://input"));
            
            $stmt = $db->prepare("UPDATE device_configs 
                                  SET device_type = ?, 
                                      device_name = ?, 
                                      location_secret = ?,
                                      is_active = ?
                                  WHERE device_config_id = ?");
            
            if($stmt->execute([
                $data->device_type,
                $data->device_name ?? 'Unnamed Device',
                $data->location_secret ?? null,
                $data->is_active ?? true,
                $id
            ])) {
                echo json_encode(["message" => "Device config updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update device config"]);
            }
            break;
            
        case 'DELETE':
            $stmt = $db->prepare("DELETE FROM device_configs WHERE device_config_id = ?");
            
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "Device config deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete device config"]);
            }
            break;
    }
}

?>