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
// APPOINTMENTS Controller
// ============================================
function handleAppointments($db, $database, $method, $id) {
 
    $prefix = $database->table('');

    switch($method) {
        case 'GET':
            if($id) {
                $stmt = $db->prepare("SELECT a.*, 
                                        at.type_name, 
                                        at.color,
                                        at.description as type_description
                                        FROM {$prefix}appointments a
                                        LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
                                        WHERE a.appointment_id = ?");
                $stmt->execute([$id]);
                $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
                if($appointment) {
                    echo json_encode($appointment);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Appointment not found"]);
                }
            } 
            else {
                // Liste mit optionalen Filtern
                $year = $_GET['year'] ?? null;
                $month = $_GET['month'] ?? null;
                $from_date = $_GET['from_date'] ?? null;
                $to_date = $_GET['to_date'] ?? null;
                $type_id = $_GET['type_id'] ?? null;
                $member_id = $_GET['member_id'] ?? null;
                
                // Baue Query dynamisch - MIT Alias für type_description
                $sql = "SELECT a.*, 
                        at.type_name, 
                        at.color, 
                        at.type_id,
                        at.description as type_description
                        FROM {$prefix}appointments a
                        LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
                        WHERE 1=1";
                $params = [];
                
                // Gruppen-Filterung für User
                if(!isAdminOrManager() || isset($member_id)) {
                    // Hole member_id des Users
                    $userStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
                    $userStmt->execute([getCurrentUserId()]);
                    $userMemberId = $userStmt->fetchColumn();

                    if($member_id !== null)
                    {
                        $userMemberId = $member_id;
                    }
                    
                    if($userMemberId) {
                        // Hole Gruppen des Mitglieds
                        $groupStmt = $db->prepare("SELECT group_id FROM {$prefix}member_group_assignments WHERE member_id = ?");
                        $groupStmt->execute([$userMemberId]);
                        $userGroupIds = $groupStmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        
                        if(empty($userGroupIds)) {
                            // Kein Gruppe zugeordnet → keine Termine sichtbar
                            echo json_encode([]);
                            return;
                        }
                        
                        // Filterung: Nur Termine deren Terminart zu den User-Gruppen passt
                        $placeholders = str_repeat('?,', count($userGroupIds) - 1) . '?';
                        $sql .= " AND EXISTS (
                            SELECT 1 FROM {$prefix}appointment_type_groups atg
                            WHERE atg.type_id = a.type_id
                            AND atg.group_id IN ($placeholders)
                        )";
                        $params = array_merge($params, $userGroupIds);
                    } else {
                        // User hat kein verknüpftes Mitglied → keine Termine
                        echo json_encode([]);
                        return;
                    }
                }
                
                if($year) {
                    $sql .= " AND YEAR(a.date) = ?";
                    $params[] = $year;
                }
                
                if($month && $year) {
                    $sql .= " AND MONTH(a.date) = ?";
                    $params[] = $month;
                }
                
                if($from_date) {
                    $sql .= " AND a.date >= ?";
                    $params[] = $from_date;
                }
                
                if($to_date) {
                    $sql .= " AND a.date <= ?";
                    $params[] = $to_date;
                }

                if($type_id) {
                    $sql .= " AND at.type_id = ?";
                    $params[] = $type_id;
                }
                
                $sql .= " ORDER BY a.date DESC, a.start_time";

                if(count($params) > 0) {
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                } else {
                    $stmt = $db->query($sql);
                }

                //$stmt = $db->query("SELECT * FROM appointments ORDER BY date DESC, start_time");
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;
            
        case 'POST':
            requireAdminOrManager();

            $rawData = json_decode(file_get_contents("php://input"));
            
            // Nur erlaubte Felder extrahieren
            $allowedFields = ['title', 'description','type_id', 'date', 'start_time','created_by'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($rawData->$field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // Hole Standard-Terminart
            $typeStmt = $db->query("SELECT type_id FROM {$prefix}appointment_types WHERE is_default = 1 LIMIT 1");
            $defaultType = $typeStmt->fetch(PDO::FETCH_ASSOC);
            $typeId = $defaultType ? $defaultType['type_id'] : null;

            if(isSet($data->type_id) && ($data->type_id !== null))
            {
                $typeId = $data->type_id;
            }

            // Prüfe ob bereits ein Termin in der Toleranz existiert
            $tolerance = AUTO_CHECKIN_TOLERANCE_HOURS;
            $toleranceSeconds = $tolerance * 3600;  // Stunden in Sekunden

            $newDateTime = $data->date . ' ' . $data->start_time;

            $checkStmt = $db->prepare("
                SELECT appointment_id, title, start_time, date,
                    ABS(TIMESTAMPDIFF(SECOND, CONCAT(date, ' ', start_time), ?)) as time_diff
                FROM {$prefix}appointments 
                WHERE date = ?
                AND type_id = ?
                HAVING time_diff <= ?
            ");
            
            $checkStmt->execute([$newDateTime, $data->date, $typeId, $toleranceSeconds]);
            
            $conflict = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if($conflict) {
            http_response_code(409);
            echo json_encode([
                "message" => "Ein Termin dieser Art existiert bereits im Toleranzbereich von ±{$tolerance}h",
                "conflict" => [
                    "title" => $conflict['title'],
                    "date" => $conflict['date'],
                    "time" => $conflict['start_time'],
                    "time_diff_seconds" => $conflict['time_diff']
                ],
                "hint" => "Bestehender Termin: \"{$conflict['title']}\" am {$conflict['date']} um {$conflict['start_time']} Uhr"
            ]);
            break;
            }
            
                  
            $stmt = $db->prepare("INSERT INTO {$prefix}appointments (title, type_id, description, date, 
                                  start_time, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $createdBy = getCurrentUserId(); 
            if($stmt->execute([$data->title, $typeId, $data->description ?? null, $data->date, 
                               $data->start_time, $createdBy])) {
                http_response_code(201);
                echo json_encode(["message" => "Appointment created", "id" => $db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create appointment"]);
            }
            break;
            
        case 'PUT':
            requireAdminOrManager();

            $rawData = json_decode(file_get_contents("php://input"));
            
            // Nur erlaubte Felder extrahieren
            $allowedFields = ['title', 'type_id', 'description', 'date', 'start_time','id'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($rawData->$field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // Prüfe ob bereits ein anderer Termin der gleichen Art in der Toleranzzeit existiert
            $tolerance = AUTO_CHECKIN_TOLERANCE_HOURS;
            $toleranceSeconds = $tolerance * 3600;
            
            $newDateTime = $data->date . ' ' . $data->start_time;
            
            $checkStmt = $db->prepare("
                SELECT appointment_id, title, start_time, date,
                    ABS(TIMESTAMPDIFF(SECOND, CONCAT(date, ' ', start_time), ?)) as time_diff
                FROM {$prefix}appointments 
                WHERE date = ?
                AND type_id = ?
                AND appointment_id != ?
                HAVING time_diff <= ?
            ");
            
            $checkStmt->execute([$newDateTime, $data->date, $data->type_id ?? null, $id, $toleranceSeconds]);
            
            $conflict = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if($conflict) {
                http_response_code(409);
                echo json_encode([
                    "message" => "Ein Termin dieser Art existiert bereits im Toleranzbereich von ±{$tolerance}h",
                    "conflict" => [
                        "title" => $conflict['title'],
                        "date" => $conflict['date'],
                        "time" => $conflict['start_time'],
                        "time_diff_seconds" => $conflict['time_diff']
                    ],
                    "hint" => "Bestehender Termin: \"{$conflict['title']}\" am {$conflict['date']} um {$conflict['start_time']} Uhr"
                ]);
                break;
            }

            $description = null;
            if(isset($data->description))
            {
                $description = $data->description;
            }

            $stmt = $db->prepare("UPDATE {$prefix}appointments SET title=?, type_id=?, description=?, date=?, 
                                  start_time=? WHERE appointment_id=?");
            if($stmt->execute([$data->title, $data->type_id ?? null, $description, $data->date, 
                              $data->start_time, $id])) {
                echo json_encode(["message" => "Appointment updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update appointment"]);
            }
            break;
            
        case 'DELETE':
            requireAdminOrManager();
            
            // Lösche zuerst abhängige Datensätze
            $db->prepare("DELETE FROM {$prefix}records WHERE appointment_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM {$prefix}exceptions WHERE appointment_id = ?")->execute([$id]);
            
            // Dann den Termin selbst
            $stmt = $db->prepare("DELETE FROM {$prefix}appointments WHERE appointment_id = ?");
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "Appointment deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete appointment"]);
            }
            break;
    }
}

?>