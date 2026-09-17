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
// APPOINTMENT_TYPES Controller
// ============================================
function handleAppointmentTypes($db, $database, $method, $id) {
    
    $prefix = $database->table('');

    switch($method) {
        case 'GET':
            if($id) {
                // Einzelne Terminart mit Gruppen
                $stmt = $db->prepare("SELECT * FROM {$prefix}appointment_types WHERE type_id = ?");
                $stmt->execute([$id]);
                $type = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if($type) {
                    // Lade zugehörige Gruppen
                    $groupStmt = $db->prepare("SELECT g.* FROM {$prefix}member_groups g
                                               INNER JOIN {$prefix}appointment_type_groups atg ON g.group_id = atg.group_id
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
                $stmt = $db->query("SELECT * FROM {$prefix}appointment_types ORDER BY type_name");
                $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Für jeden Type die Gruppen laden
                foreach ($types as &$type) {
                    $stmt = $db->prepare("
                        SELECT g.* 
                        FROM {$prefix}member_groups g
                        INNER JOIN {$prefix}appointment_type_groups atg ON g.group_id = atg.group_id
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
            requireAdmin();

            $data = json_decode(file_get_contents("php://input"));
            $data = (object) ($data ?? []);

            try {
                $responseSettings = responseTypeSettings($data, RESPONSE_TYPE_DEFAULTS);
            } catch (InvalidArgumentException $e) {
                http_response_code(400);
                echo json_encode(["message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Wenn is_default=true, setze alle anderen auf false
            if(isset($data->is_default) && $data->is_default) {
                $db->exec("UPDATE {$prefix}appointment_types SET is_default = 0");
            }

            $stmt = $db->prepare("INSERT INTO {$prefix}appointment_types
                                  (type_name, description, is_default, color,
                                   responses_enabled, responses_names_visible,
                                   responses_require_excuse, response_deadline_hours)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if($stmt->execute([
                $data->type_name,
                $data->description ?? null,
                $data->is_default ?? false,
                $data->color ?? '#667eea',
                $responseSettings['responses_enabled'],
                $responseSettings['responses_names_visible'],
                $responseSettings['responses_require_excuse'],
                $responseSettings['response_deadline_hours'],
            ])) {
                $typeId = $db->lastInsertId();
                
                // Verknüpfe mit Gruppen
                if(isset($data->group_ids) && is_array($data->group_ids)) {
                    $linkStmt = $db->prepare("INSERT INTO {$prefix}appointment_type_groups (type_id, group_id) VALUES (?, ?)");
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
            requireAdmin();

            $data = json_decode(file_get_contents("php://input"));
            $data = (object) ($data ?? []);

            $currentStmt = $db->prepare("SELECT responses_enabled, responses_names_visible,
                                                responses_require_excuse, response_deadline_hours
                                         FROM {$prefix}appointment_types WHERE type_id = ?");
            $currentStmt->execute([$id]);
            $currentRow = $currentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$currentRow) {
                http_response_code(404);
                echo json_encode(["message" => "Type not found"]);
                return;
            }
            $current = [
                'responses_enabled'        => (int) $currentRow['responses_enabled'],
                'responses_names_visible'  => (int) $currentRow['responses_names_visible'],
                'responses_require_excuse' => (int) $currentRow['responses_require_excuse'],
                'response_deadline_hours'  => $currentRow['response_deadline_hours'] === null
                    ? null : (int) $currentRow['response_deadline_hours'],
            ];

            try {
                $responseSettings = responseTypeSettings($data, $current);
            } catch (InvalidArgumentException $e) {
                http_response_code(400);
                echo json_encode(["message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Wenn is_default=true, setze alle anderen auf false (außer dieser)
            if(isset($data->is_default) && $data->is_default) {
                $db->prepare("UPDATE {$prefix}appointment_types SET is_default = 0 WHERE type_id != ?")->execute([$id]);
            }

            // Nur schreiben, was mitgeschickt wurde (OI-54). Vorher schrieb das
            // UPDATE alle Grundfelder bedingungslos: Ein PUT mit nur der Farbe
            // loeschte die Beschreibung, setzte is_default zurueck und liess
            // type_name leer zuruecke -- MySQL ohne STRICT_TRANS_TABLES nimmt
            // NULL fuer eine NOT NULL-Spalte als leeren String an, der Verlust
            // blieb also unbemerkt. Die Rueckmeldefelder machen es ueber
            // responseTypeSettings() seit 1.7.0 bereits richtig.
            $updateFields = [];
            $updateParams = [];

            foreach (['type_name', 'description', 'color'] as $feld) {
                if (isset($data->$feld)) {
                    $updateFields[] = "{$feld} = ?";
                    $updateParams[] = $data->$feld;
                }
            }

            if (isset($data->is_default)) {
                $updateFields[] = "is_default = ?";
                $updateParams[] = $data->is_default ? 1 : 0;
            }

            $updateFields[] = "responses_enabled = ?";
            $updateParams[] = $responseSettings['responses_enabled'];
            $updateFields[] = "responses_names_visible = ?";
            $updateParams[] = $responseSettings['responses_names_visible'];
            $updateFields[] = "responses_require_excuse = ?";
            $updateParams[] = $responseSettings['responses_require_excuse'];
            $updateFields[] = "response_deadline_hours = ?";
            $updateParams[] = $responseSettings['response_deadline_hours'];

            $updateParams[] = $id;

            $stmt = $db->prepare("UPDATE {$prefix}appointment_types
                                  SET " . implode(', ', $updateFields) . "
                                  WHERE type_id = ?");
            if($stmt->execute($updateParams)) {
                // Gruppen-Verknuepfungen: fehlendes Feld laesst sie unangetastet.
                // Das DELETE lief frueher bedingungslos -- ein PUT ohne
                // group_ids loeste damit still alle Gruppen.
                if(isset($data->group_ids) && is_array($data->group_ids)) {
                    $db->prepare("DELETE FROM {$prefix}appointment_type_groups WHERE type_id = ?")->execute([$id]);

                    $linkStmt = $db->prepare("INSERT INTO {$prefix}appointment_type_groups (type_id, group_id) VALUES (?, ?)");
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
            requireAdmin();

            if (!$id) {
                http_response_code(400);
                echo json_encode(["message" => "id ist erforderlich"]);
                break;
            }
            
            $stmt = $db->prepare("DELETE FROM {$prefix}appointment_types WHERE type_id = ?");
            if($stmt->execute([$id])) {
                if($stmt->rowCount() === 0) {
                    http_response_code(404);
                    echo json_encode(["message" => "Type not found"]);
                    break;
                }
                echo json_encode(["message" => "Type deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete type"]);
            }
            break;
    }
}

?>