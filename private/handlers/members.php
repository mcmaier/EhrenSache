<?php

// ============================================
// MEMBERS Controller
// ============================================

function handleMembers($db, $method, $id, $authUserId, $authUserRole, $authMemberId) {
    switch($method) {
        case 'GET':
            if($id) {  
                // Zugriffskontrolle: Nur Admin können alle Infos lesen  
                if(isAdminOrManager())                
                {
                    $stmt = $db->prepare("SELECT * FROM members WHERE member_id = ?");
                    $stmt->execute([$id]);
                    $member = $stmt->fetch(PDO::FETCH_ASSOC);

                    if($member) {
                        // Lade zugehörige Gruppen
                        $groupStmt = $db->prepare(" SELECT g.group_id, g.group_name 
                                                    FROM member_groups g
                                                    INNER JOIN member_group_assignments mga ON g.group_id = mga.group_id
                                                    WHERE mga.member_id = ?");
                        $groupStmt->execute([$id]);
                        $member['groups'] = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
        
                        echo json_encode($member ?: []);
                    }
                    else {
                        http_response_code(404);
                        echo json_encode(["message" => "Member not found"]);
                    }
                }               
                else{
                    $memberId = $authMemberId; 
                    $stmt = $db->prepare("SELECT name, surname, member_number FROM members WHERE member_id = ?");
                    $stmt->execute([$memberId]);
                    $member = $stmt->fetch(PDO::FETCH_ASSOC);                            

                    $warning = null;
                    if( $id!= $memberId) {
                        $warning = "member_id ignored - you can only get your own linked member number (ID: $memberId)";
                    }

                    if($member)
                    {
                        echo json_encode([  "name" => $member['name'],
                                            "surname" => $member['surname'],
                                            "member_number" => $member['member_number'],
                                            "warning" => $warning]);
                    }
                    else {             
                        http_response_code(404);
                        echo json_encode(["message" => "Member not found"]);
                    }
                }                       
            } 
            else
            {
                // Zugriffskontrolle: Nur Admin können alle Infos lesen
                if(isAdminOrManager())    
                {
                    $group_id = $_GET['group_id'] ?? null;
                    $params = [];
                    
                    if($group_id)
                    {
                        $sql = "SELECT m.*, g.group_id, g.group_name                                   
                                    FROM members m
                                    LEFT JOIN member_group_assignments mga ON m.member_id = mga.member_id
                                    LEFT JOIN member_groups g ON mga.group_id = g.group_id
                                    WHERE mga.group_id = ?
                                    GROUP BY m.member_id                                    
                                    ORDER BY m.surname, m.name";
                                    $params[] = $group_id;                                    
                    }
                    else
                    {
                        $sql = "SELECT m.*,
                                    GROUP_CONCAT(g.group_id SEPARATOR ', ') as group_ids,
                                    GROUP_CONCAT(g.group_name SEPARATOR ', ') as group_names
                                    FROM members m
                                    LEFT JOIN member_group_assignments mga ON m.member_id = mga.member_id
                                    LEFT JOIN member_groups g ON mga.group_id = g.group_id
                                    GROUP BY m.member_id
                                    ORDER BY m.surname, m.name";
                    }

                    if(count($params) > 0) {
                        $stmt = $db->prepare($sql);
                        $stmt->execute($params);
                    } else {
                        $stmt = $db->query($sql);
                    }
             
                    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                else if(isDevice())
                {
                    // Liste aller Mitglieder mit Member_Number für Auto-Checkin
                    $stmt = $db->query("SELECT name, surname, member_number FROM members ORDER BY surname, name");
                                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                else
                {
                    // Liste aller Mitglieder ohne weitere Infos
                    $stmt = $db->query("SELECT name, surname FROM members ORDER BY surname, name");
                                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode($members);
            }
            break;
            
        case 'POST':
            requireAdminOrManager();

            $data = json_decode(file_get_contents("php://input"));

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['name', 'surname', 'member_number', 'active','group_ids'];
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
                $memberId = $db->lastInsertId();
                // Speichere Gruppen-Zuordnungen
                if(isset($cleanData->group_ids) && is_array($cleanData->group_ids)) {
                    $groupStmt = $db->prepare("INSERT INTO member_group_assignments (member_id, group_id) VALUES (?, ?)");
                    foreach($cleanData->group_ids as $groupId) {
                        $groupStmt->execute([$memberId, $groupId]);
                    }
                }
                http_response_code(201);
                echo json_encode(["message" => "Member created", "id" => $db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create member"]);
            }
            break;
            
        case 'PUT':
            requireAdminOrManager();

            $data = json_decode(file_get_contents("php://input"));

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['name', 'surname', 'member_number', 'active', 'group_ids'];
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
            if($stmt->execute([$cleanData->name, $cleanData->surname, $cleanData->member_number ?? null, 
                               $cleanData->active, $id])) {
                // Aktualisiere Gruppen-Zuordnungen
                if(isset($cleanData->group_ids)) {
                    // Lösche alte Zuordnungen
                    $db->prepare("DELETE FROM member_group_assignments WHERE member_id = ?")->execute([$id]);
                    
                    // Füge neue Zuordnungen hinzu
                    if(is_array($cleanData->group_ids)) {
                        $groupStmt = $db->prepare("INSERT INTO member_group_assignments (member_id, group_id) VALUES (?, ?)");
                        foreach($cleanData->group_ids as $groupId) {
                            $groupStmt->execute([$id, $groupId]);
                        }
                    }
                }
                echo json_encode(["message" => "Member updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update member"]);
            }
            break;
            
        case 'DELETE':
            requireAdminOrManager();

            // Lösche zuerst abhängige Datensätze
            $db->prepare("DELETE FROM records WHERE member_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM exceptions WHERE member_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM membership_dates WHERE member_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM member_group_assignments WHERE member_id = ?")->execute([$id]);

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