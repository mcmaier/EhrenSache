<?php

// ============================================
// EXCEPTIONS Controller
// ============================================
function handleExceptions($db, $method, $id) {
    switch($method) {
        case 'GET':
            if($id) {
                // Einzelne Exception mit allen Details
                $stmt = $db->prepare("SELECT e.*, 
                                     m.name, m.surname, 
                                     a.title, a.date, a.start_time,
                                     u1.email as created_by_email,
                                     u2.email as approved_by_email
                                     FROM exceptions e 
                                     JOIN members m ON e.member_id = m.member_id 
                                     JOIN appointments a ON e.appointment_id = a.appointment_id 
                                     LEFT JOIN users u1 ON e.created_by = u1.user_id
                                     LEFT JOIN users u2 ON e.approved_by = u2.user_id
                                     WHERE e.exception_id = ?");
                $stmt->execute([$id]);
                $exception = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // User dürfen nur ihre eigenen Exceptions sehen
                if($_SESSION['role'] !== 'admin') {
                    $userStmt = $db->prepare("SELECT member_id FROM users WHERE user_id = ?");
                    $userStmt->execute([getCurrentUserId()]);
                    $userMemberId = $userStmt->fetchColumn();
                    
                    if($exception && $exception['member_id'] != $userMemberId) {
                        http_response_code(403);
                        echo json_encode(["message" => "Access denied"]);
                        return;
                    }
                }
                
                if($exception) {
                    echo json_encode($exception);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Exception not found"]);
                }
            } else {
                // Liste mit Filtern
                $status = $_GET['status'] ?? null;
                $type = $_GET['type'] ?? null;
                $member_id = $_GET['member_id'] ?? null;
                
                // User sehen nur ihre eigenen Exceptions
                if($_SESSION['role'] !== 'admin') {
                    $userStmt = $db->prepare("SELECT member_id FROM users WHERE user_id = ?");
                    $userStmt->execute([getCurrentUserId()]);
                    $member_id = $userStmt->fetchColumn();
                    
                    if(!$member_id) {
                        echo json_encode([]);
                        return;
                    }
                }
                
                // Baue Query dynamisch
                $sql = "SELECT e.*, 
                        m.name, m.surname, 
                        a.title, a.date, a.start_time,
                        u1.email as created_by_email,
                        u2.email as approved_by_email
                        FROM exceptions e 
                        JOIN members m ON e.member_id = m.member_id 
                        JOIN appointments a ON e.appointment_id = a.appointment_id 
                        LEFT JOIN users u1 ON e.created_by = u1.user_id
                        LEFT JOIN users u2 ON e.approved_by = u2.user_id
                        WHERE 1=1";
                
                $params = [];
                
                if($status) {
                    $sql .= " AND e.status = ?";
                    $params[] = $status;
                }
                
                if($type) {
                    $sql .= " AND e.exception_type = ?";
                    $params[] = $type;
                }
                
                if($member_id) {
                    $sql .= " AND e.member_id = ?";
                    $params[] = $member_id;
                }
                
                $sql .= " ORDER BY e.created_at DESC";
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;
            
        case 'POST':
            // User können Anträge erstellen (für sich selbst oder Admin für alle)
            $data = json_decode(file_get_contents("php://input"));
            
            // Prüfe ob User für sich selbst oder Admin für andere
            $requesting_member_id = $data->member_id;
            
            if($_SESSION['role'] !== 'admin') {
                // User dürfen nur für sich selbst Anträge stellen
                $userStmt = $db->prepare("SELECT member_id FROM users WHERE user_id = ?");
                $userStmt->execute([getCurrentUserId()]);
                $userMemberId = $userStmt->fetchColumn();
                
                if($requesting_member_id != $userMemberId) {
                    http_response_code(403);
                    echo json_encode(["message" => "You can only create requests for yourself"]);
                    return;
                }
            }
            
            $stmt = $db->prepare("INSERT INTO exceptions 
                                  (member_id, appointment_id, exception_type, reason, 
                                   requested_arrival_time, status, created_by) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $requested_time = isset($data->requested_arrival_time) ? $data->requested_arrival_time : null;
            $status = $data->status ?? 'pending';
            
            if($stmt->execute([
                $data->member_id, 
                $data->appointment_id, 
                $data->exception_type,
                $data->reason, 
                $requested_time,
                $status,
                getCurrentUserId()
            ])) {
                http_response_code(201);
                echo json_encode([
                    "message" => "Exception created", 
                    "id" => $db->lastInsertId()
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create exception"]);
            }
            break;
            
        case 'PUT':
            // Nur Admin darf Status ändern (genehmigen/ablehnen)
            // User dürfen ihre eigenen pending Anträge bearbeiten
            $data = json_decode(file_get_contents("php://input"));
            
            // Hole Exception Info
            $checkStmt = $db->prepare("SELECT member_id, status FROM exceptions WHERE exception_id = ?");
            $checkStmt->execute([$id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if(!$existing) {
                http_response_code(404);
                echo json_encode(["message" => "Exception not found"]);
                return;
            }
            
            // User dürfen nur ihre eigenen pending Anträge bearbeiten
            if($_SESSION['role'] !== 'admin') {
                $userStmt = $db->prepare("SELECT member_id FROM users WHERE user_id = ?");
                $userStmt->execute([getCurrentUserId()]);
                $userMemberId = $userStmt->fetchColumn();
                
                if($existing['member_id'] != $userMemberId || $existing['status'] != 'pending') {
                    http_response_code(403);
                    echo json_encode(["message" => "Access denied"]);
                    return;
                }
                
                // User Update (nur Reason und requested_arrival_time)
                $stmt = $db->prepare("UPDATE exceptions 
                                      SET reason = ?, requested_arrival_time = ? 
                                      WHERE exception_id = ?");
                $stmt->execute([
                    $data->reason, 
                    $data->requested_arrival_time ?? null, 
                    $id
                ]);
            } else {
                // Admin Update (inkl. Status ändern)
                $stmt = $db->prepare("UPDATE exceptions 
                                      SET reason = ?, 
                                          requested_arrival_time = ?,
                                          status = ?,
                                          approved_by = ?,
                                          approved_at = ?
                                      WHERE exception_id = ?");
                
                $approved_by = null;
                $approved_at = null;
                
                if(isset($data->status) && $data->status != 'pending') {
                    $approved_by = getCurrentUserId();
                    $approved_at = date('Y-m-d H:i:s');
                }
                
                $stmt->execute([
                    $data->reason, 
                    $data->requested_arrival_time ?? null,
                    $data->status,
                    $approved_by,
                    $approved_at,
                    $id
                ]);
                
                // Bei Genehmigung einer Zeitkorrektur: Record erstellen/aktualisieren
                if($data->status === 'approved' && $data->exception_type === 'time_correction') {
                    handleApprovedTimeCorrection($db, $id, $data);
                }
            }
            
            echo json_encode(["message" => "Exception updated"]);
            break;
            
        case 'DELETE':
            // User dürfen nur ihre eigenen pending Anträge löschen
            // Admin darf alles löschen
            $checkStmt = $db->prepare("SELECT member_id, status FROM exceptions WHERE exception_id = ?");
            $checkStmt->execute([$id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if(!$existing) {
                http_response_code(404);
                echo json_encode(["message" => "Exception not found"]);
                return;
            }
            
            if($_SESSION['role'] !== 'admin') {
                $userStmt = $db->prepare("SELECT member_id FROM users WHERE user_id = ?");
                $userStmt->execute([getCurrentUserId()]);
                $userMemberId = $userStmt->fetchColumn();
                
                if($existing['member_id'] != $userMemberId || $existing['status'] != 'pending') {
                    http_response_code(403);
                    echo json_encode(["message" => "Access denied"]);
                    return;
                }
            }
            
            $stmt = $db->prepare("DELETE FROM exceptions WHERE exception_id = ?");
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "Exception deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete exception"]);
            }
            break;
    }
}

?>