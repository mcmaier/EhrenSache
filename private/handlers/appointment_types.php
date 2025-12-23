<?php

// ============================================
// APPOINTMENT_TYPES Controller
// ============================================
function handleAppointmentTypes($db, $method, $id) {
    
    switch($method) {
        case 'GET':
            if($id) {
                // Einzelne Terminart mit Gruppen
                $stmt = $db->prepare("SELECT * FROM appointment_types WHERE type_id = ?");
                $stmt->execute([$id]);
                $type = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if($type) {
                    // Lade zugehörige Gruppen
                    $groupStmt = $db->prepare("SELECT g.* FROM member_groups g
                                               INNER JOIN appointment_type_groups atg ON g.group_id = atg.group_id
                                               WHERE atg.type_id = ?");
                    $groupStmt->execute([$id]);
                    $type['groups'] = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode($type);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Type not found"]);
                }
            } else {
                // ALLE Types MIT Gruppen
                $stmt = $db->query("SELECT * FROM appointment_types ORDER BY type_name");
                $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Für jeden Type die Gruppen laden
                foreach ($types as &$type) {
                    $stmt = $db->prepare("
                        SELECT g.* 
                        FROM member_groups g
                        INNER JOIN appointment_type_groups atg ON g.group_id = atg.group_id
                        WHERE atg.type_id = ?
                    ");
                    $stmt->execute([$type['type_id']]);
                    $type['groups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                echo json_encode($types);
                return;

            }
            break;
            
        case 'POST':
            requireAdminOrManager();

            $data = json_decode(file_get_contents("php://input"));

            // Wenn is_default=true, setze alle anderen auf false
            if(isset($data->is_default) && $data->is_default) {
                $db->exec("UPDATE appointment_types SET is_default = 0");
            }
            
            $stmt = $db->prepare("INSERT INTO appointment_types 
                                  (type_name, description, is_default, color) 
                                  VALUES (?, ?, ?, ?)");
            if($stmt->execute([
                $data->type_name,
                $data->description ?? null,
                $data->is_default ?? false,
                $data->color ?? '#667eea'
            ])) {
                $typeId = $db->lastInsertId();
                
                // Verknüpfe mit Gruppen
                if(isset($data->group_ids) && is_array($data->group_ids)) {
                    $linkStmt = $db->prepare("INSERT INTO appointment_type_groups (type_id, group_id) VALUES (?, ?)");
                    foreach($data->group_ids as $groupId) {
                        $linkStmt->execute([$typeId, $groupId]);
                    }
                }
                
                http_response_code(201);
                echo json_encode(["message" => "Type created", "id" => $typeId]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create type"]);
            }
            break;
            
        case 'PUT':
            requireAdminOrManager();

            $data = json_decode(file_get_contents("php://input"));

            // Wenn is_default=true, setze alle anderen auf false (außer dieser)
            if(isset($data->is_default) && $data->is_default) {
                $db->prepare("UPDATE appointment_types SET is_default = 0 WHERE type_id != ?")->execute([$id]);
            }
            
            $stmt = $db->prepare("UPDATE appointment_types 
                                  SET type_name = ?, description = ?, is_default = ?, color = ?
                                  WHERE type_id = ?");
            if($stmt->execute([
                $data->type_name,
                $data->description ?? null,
                $data->is_default ?? false,
                $data->color ?? '#667eea',
                $id
            ])) {
                // Aktualisiere Gruppen-Verknüpfungen
                $db->prepare("DELETE FROM appointment_type_groups WHERE type_id = ?")->execute([$id]);
                
                if(isset($data->group_ids) && is_array($data->group_ids)) {
                    $linkStmt = $db->prepare("INSERT INTO appointment_type_groups (type_id, group_id) VALUES (?, ?)");
                    foreach($data->group_ids as $groupId) {
                        $linkStmt->execute([$id, $groupId]);
                    }
                }
                
                echo json_encode(["message" => "Type updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update type"]);
            }
            break;
            
        case 'DELETE':
            requireAdminOrManager();
            
            $stmt = $db->prepare("DELETE FROM appointment_types WHERE type_id = ?");
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "Type deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete type"]);
            }
            break;
    }
}

?>