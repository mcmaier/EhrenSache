<?php

// ============================================
// RECORDS Controller
// ============================================
function handleRecords($db, $method, $id) {
    switch($method) {
        case 'GET': 
            if($id) {
                // Einzelner Record
                $stmt = $db->prepare("SELECT r.*, m.name, m.surname, a.title 
                                     FROM records r 
                                     JOIN members m ON r.member_id = m.member_id 
                                     JOIN appointments a ON r.appointment_id = a.appointment_id 
                                     WHERE r.record_id = ?");
                $stmt->execute([$id]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // User dürfen nur ihre eigenen Records sehen
                if($_SESSION['role'] !== 'admin') {
                    // Hole member_id des Users
                    $userStmt = $db->prepare("SELECT member_id FROM users WHERE user_id = ?");
                    $userStmt->execute([getCurrentUserId()]);
                    $userMemberId = $userStmt->fetchColumn();
                    
                    if($record && $record['member_id'] != $userMemberId) {
                        http_response_code(403);
                        echo json_encode(["message" => "Access denied"]);
                        return;
                    }
                }
                
                if($record) {
                    echo json_encode($record);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Record not found"]);
                }
            } else {
                // Liste mit erweiterten Filtern
                $appointment_id = $_GET['appointment_id'] ?? null;
                $member_id = $_GET['member_id'] ?? null;
                $year = $_GET['year'] ?? null;
                $month = $_GET['month'] ?? null;
                $from_date = $_GET['from_date'] ?? null;
                $to_date = $_GET['to_date'] ?? null;
                $status = $_GET['status'] ?? null;
                
                // User sehen nur ihre eigenen Records
                if($_SESSION['role'] !== 'admin') {
                    $userStmt = $db->prepare("SELECT member_id FROM users WHERE user_id = ?");
                    $userStmt->execute([getCurrentUserId()]);
                    $member_id = $userStmt->fetchColumn();
                    
                    if(!$member_id) {
                        echo json_encode([]);
                        return;
                    }
                }
                 // Dynamische Query-Erstellung
                $sql = "SELECT r.*, 
                        m.name, m.surname, m.member_number, 
                        a.title, a.date, a.start_time,
                        r.checkin_source, r.source_device, r.location_name
                        FROM records r 
                        JOIN members m ON r.member_id = m.member_id 
                        JOIN appointments a ON r.appointment_id = a.appointment_id 
                        WHERE 1=1";                
                $params = [];
                
                // Filter: Termin
                if($appointment_id) {
                    $sql .= " AND r.appointment_id = ?";
                    $params[] = $appointment_id;
                }
                
                // Filter: Mitglied
                if($member_id) {
                    $sql .= " AND r.member_id = ?";
                    $params[] = $member_id;
                }
                
                // Filter: Jahr
                if($year) {
                    $sql .= " AND YEAR(a.date) = ?";
                    $params[] = $year;
                }
                
                // Filter: Monat (nur mit Jahr sinnvoll)
                if($month && $year) {
                    $sql .= " AND MONTH(a.date) = ?";
                    $params[] = $month;
                }
                
                // Filter: Datum-Bereich (von)
                if($from_date) {
                    $sql .= " AND a.date >= ?";
                    $params[] = $from_date;
                }
                
                // Filter: Datum-Bereich (bis)
                if($to_date) {
                    $sql .= " AND a.date <= ?";
                    $params[] = $to_date;
                }
                
                // Filter: Status
                if($status) {
                    $sql .= " AND r.status = ?";
                    $params[] = $status;
                }
                
                $sql .= " ORDER BY a.date DESC, r.arrival_time DESC";
                
                // Query ausführen
                if(count($params) > 0) {
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                } else {
                    $stmt = $db->query($sql);
                }
                
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents("php://input"));
            
            // Prüfe ob bereits ein Record für dieses Mitglied + Termin existiert
            $checkStmt = $db->prepare("SELECT record_id FROM records 
                                    WHERE member_id = ? AND appointment_id = ?");
            $checkStmt->execute([$data->member_id, $data->appointment_id]);
            
            if($checkStmt->fetch()) {
                http_response_code(409); // Conflict
                echo json_encode([
                    "message" => "Record already exists for this member and appointment",
                    "hint" => "Use PUT to update the existing record"
                ]);
                break;
            }
            
            $stmt = $db->prepare("INSERT INTO records (member_id, appointment_id, 
                                arrival_time, status) VALUES (?, ?, ?, ?)");
            if($stmt->execute([$data->member_id, $data->appointment_id, 
                            $data->arrival_time, $data->status ?? 'present'])) {
                http_response_code(201);
                echo json_encode(["message" => "Record created", "id" => $db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create record"]);
            }
        
            break;
            
        case 'PUT':
            $data = json_decode(file_get_contents("php://input"));
            $stmt = $db->prepare("UPDATE records SET arrival_time=?, status=? 
                                  WHERE record_id=?");
            if($stmt->execute([$data->arrival_time, $data->status, $id])) {
                echo json_encode(["message" => "Record updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update record"]);
            }
            break;
            
        case 'DELETE':
            $stmt = $db->prepare("DELETE FROM records WHERE record_id = ?");
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "Record deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete record"]);
            }
            break;
    }
}

?>