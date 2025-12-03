<?php

// ============================================
// MEMBERS Controller
// ============================================

function handleMembers($db, $method, $id) {
    switch($method) {
        case 'GET':
            if($id) {
                $stmt = $db->prepare("SELECT * FROM members WHERE member_id = ?");
                $stmt->execute([$id]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
                if($member) {
                    echo json_encode($member);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Member not found"]);
                }
            } else {
                $stmt = $db->query("SELECT * FROM members ORDER BY surname, name");
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents("php://input"));

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['name', 'surname', 'member_number', 'active'];
            $cleanData = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($data->$field)) {
                    $cleanData->$field = $data->$field;
                }
            }

            // Prüfe ob member_number bereits existiert (falls angegeben)
            if(isset($cleanData->member_number) && !empty($cleanData->member_number)) {
                $checkStmt = $db->prepare("SELECT member_id FROM members WHERE member_number = ?");
                $checkStmt->execute([$cleanData->member_number]);
                if($checkStmt->fetch()) {
                    http_response_code(409);
                    echo json_encode([
                        "message" => "Diese Mitgliedsnummer ist bereits vergeben",
                        "field" => "member_number"
                    ]);
                    break;
                }
            }

            $stmt = $db->prepare("INSERT INTO members (name, surname, member_number, active) 
                                  VALUES (?, ?, ?, ?)");
            if($stmt->execute([$cleanData->name, $cleanData->surname, $cleanData->member_number ?? null, 
                               $cleanData->active ?? true])) {
                http_response_code(201);
                echo json_encode(["message" => "Member created", "id" => $db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create member"]);
            }
            break;
            
        case 'PUT':
            $data = json_decode(file_get_contents("php://input"));

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['name', 'surname', 'member_number', 'active'];
            $cleanData = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($data->$field)) {
                    $cleanData->$field = $data->$field;
                }
            }

            //Prüfe ob member_number bereits von anderem Mitglied verwendet wird
            if(isset($cleanData->member_number) && !empty($cleanData->member_number)) {
                $checkStmt = $db->prepare("SELECT member_id FROM members 
                                        WHERE member_number = ? AND member_id != ?");
                $checkStmt->execute([$cleanData->member_number, $id]);
                if($checkStmt->fetch()) {
                    http_response_code(409);
                    echo json_encode([
                        "message" => "Diese Mitgliedsnummer ist bereits vergeben",
                        "field" => "member_number"
                    ]);
                    break;
                }
            }

            $stmt = $db->prepare("UPDATE members SET name=?, surname=?, member_number=?, 
                                  active=? WHERE member_id=?");
            if($stmt->execute([$cleanData->name, $cleanData->surname, $cleanData->member_number, 
                               $cleanData->active, $id])) {
                echo json_encode(["message" => "Member updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update member"]);
            }
            break;
            
        case 'DELETE':
            // Lösche zuerst abhängige Datensätze
            $db->prepare("DELETE FROM records WHERE member_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM exceptions WHERE member_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM membership_dates WHERE member_id = ?")->execute([$id]);

            $db->prepare("UPDATE users SET member_id = NULL WHERE member_id = ?")->execute([$id]);
            
            // Dann das Mitglied selbst
            $stmt = $db->prepare("DELETE FROM members WHERE member_id = ?");
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "Member deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete member"]);
            }
            break;
    }
}

?>