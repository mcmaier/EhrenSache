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
// MEMBERS Controller
// ============================================

function handleMembers($db, $database, $method, $id, $authUserId, $authUserRole, $authMemberId) {
    
    require_once __DIR__ . '/../helpers/member_activity.php';

    $prefix = $database->table('');

    switch($method) {
        case 'GET':
            if($id) {  
                // Zugriffskontrolle: Nur Admin können alle Infos lesen  
                if(isAdminOrManager())                
                {
                    $stmt = $db->prepare("SELECT * FROM {$prefix}members WHERE member_id = ?");
                    $stmt->execute([$id]);
                    $member = $stmt->fetch(PDO::FETCH_ASSOC);

                    if($member) {
                        // Lade zugehörige Gruppen
                        $groupStmt = $db->prepare(" SELECT g.group_id, g.group_name 
                                                    FROM {$prefix}member_groups g
                                                    INNER JOIN {$prefix}member_group_assignments mga ON g.group_id = mga.group_id
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
                    $stmt = $db->prepare("
                        SELECT m.name, m.surname, m.member_number,
                               GROUP_CONCAT(mga.group_id SEPARATOR ', ') as group_ids
                        FROM {$prefix}members m
                        LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
                        WHERE m.member_id = ?
                        GROUP BY m.member_id
                    ");
                    $stmt->execute([$memberId]);
                    $member = $stmt->fetch(PDO::FETCH_ASSOC);

                    $warning = null;
                    if ($id != $memberId) {
                        $warning = "member_id ignored - you can only get your own linked member number (ID: $memberId)";
                    }

                    if ($member) {
                        echo json_encode([
                            "name"          => $member['name'],
                            "surname"       => $member['surname'],
                            "member_number" => $member['member_number'],
                            "group_ids"     => $member['group_ids'],
                            "warning"       => $warning
                        ]);
                    } else {
                        http_response_code(404);
                        echo json_encode(["message" => "Member not found"]);
                    }
                }                       
            } 
            else
            {
                // Parameter
                $group_id = $_GET['group_id'] ?? null;
                $year = isset($_GET['year']) ? intval($_GET['year']) : null;
                $date = $_GET['date'] ?? null;
                $include_inactive = $_GET['include_inactive'] ?? 'false';

                // $date auf gültiges YYYY-MM-DD validieren
                if ($date !== null) {
                    $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
                    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                        $date = null;
                    }
                }

                // Include_inactive nur für Admin/Manager
                $includeInactive = (isAdminOrManager() && $include_inactive === 'true');

                // Datum für Aktivitätsprüfung
                $yearRangeCheck = false;
                $checkDate = null;
                if ($date) {
                    $checkDate = "'$date'"; // Spezifisches Datum (bereits als Y-m-d validiert)
                } elseif ($year) {
                    $yearRangeCheck = true;
                    $yearStart = "'" . $year . "-01-01'";
                    $yearEnd   = "'" . $year . "-12-31'";
                }

                $activityFilter = '';
                $activityFlag = 'm.active';

                if ($yearRangeCheck && !$includeInactive) {
                    // Filter: War im Jahr IRGENDWANN aktiv
                    $activityFilter = "AND (
                        m.active = 1
                        AND (
                            -- Keine membership_dates → immer aktiv
                            NOT EXISTS (
                                SELECT 1 FROM {$prefix}membership_dates md 
                                WHERE md.member_id = m.member_id
                            )
                            OR
                            -- Hat membership_dates → Zeitraum überlappt mit Jahr
                            EXISTS (
                                SELECT 1 FROM {$prefix}membership_dates md
                                WHERE md.member_id = m.member_id
                                AND md.start_date <= $yearEnd
                                AND (md.end_date IS NULL OR md.end_date >= $yearStart)
                            )
                        )
                    )";
                }

                if ($yearRangeCheck) {
                    // Flag: War im Jahr aktiv (für Anzeige)
                    $activityFlag = "CASE 
                        WHEN (
                            m.active = 1
                            AND (
                                NOT EXISTS (
                                    SELECT 1 FROM {$prefix}membership_dates md 
                                    WHERE md.member_id = m.member_id
                                )
                                OR
                                EXISTS (
                                    SELECT 1 FROM {$prefix}membership_dates md
                                    WHERE md.member_id = m.member_id
                                    AND md.start_date <= $yearEnd
                                    AND (md.end_date IS NULL OR md.end_date >= $yearStart)
                                )
                            )
                        ) THEN 1 
                        ELSE 0 
                    END";
                } elseif ($checkDate) {
                    // Spezifisches Datum
                    $activityWhere = getMemberActivityWhere('m', $checkDate, false);
                    
                    if (!$includeInactive) {
                        $activityFilter = "AND ($activityWhere)";
                    }
                    
                    $activityFlag = "CASE WHEN ($activityWhere) THEN 1 ELSE 0 END";
                }

                // Zugriffskontrolle: Nur Admin können alle Infos lesen
                if(isAdminOrManager())    
                {
                    $params = [];
                    
                    if($group_id)
                    {
                        $sql = "SELECT m.*, g.group_id, g.group_name,    
                                        $activityFlag as is_active_in_period                              
                                    FROM {$prefix}members m
                                    LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
                                    LEFT JOIN {$prefix}member_groups g ON mga.group_id = g.group_id
                                    WHERE mga.group_id = ?
                                    $activityFilter
                                    GROUP BY m.member_id ORDER BY m.surname, m.name";
                                
                        $params[] = $group_id;                                    
                    }
                    else
                    {
                        $sql = "SELECT m.*,
                                        GROUP_CONCAT(g.group_id SEPARATOR ', ') as group_ids,
                                        GROUP_CONCAT(g.group_name SEPARATOR ', ') as group_names,
                                        $activityFlag as is_active_in_period
                                    FROM {$prefix}members m
                                    LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
                                    LEFT JOIN {$prefix}member_groups g ON mga.group_id = g.group_id
                                    WHERE 1=1
                                        $activityFilter
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
                    $sql = "SELECT name, surname, member_number 
                            FROM {$prefix}members m
                            WHERE 1=1
                                $activityFilter
                            ORDER BY surname, name";

                    //$stmt = $db->query("SELECT name, surname, member_number FROM {$prefix}members ORDER BY surname, name");
                    
                    $stmt = $db->query($sql);
                    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                else
                {
                    // Liste aller Mitglieder ohne weitere Infos
                    $sql = "SELECT name, surname 
                            FROM {$prefix}members m
                            WHERE 1=1
                                $activityFilter
                            ORDER BY surname, name";
                    
                    $stmt = $db->query($sql);
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

            // Pflichtfeld-Prüfung
            if (empty($cleanData->name ?? null) || empty($cleanData->surname ?? null)) {
                http_response_code(400);
                echo json_encode(["message" => "name und surname sind Pflichtfelder"]);
                break;
            }

            // Prüfe ob member_number bereits existiert (falls angegeben)
            if(isset($cleanData->member_number) && !empty($cleanData->member_number)) {
                $checkStmt = $db->prepare("SELECT member_id FROM {$prefix}members WHERE member_number = ?");
                $checkStmt->execute([$cleanData->member_number]);
                if($checkStmt->fetch()) {
                    http_response_code(409);
                    echo json_encode([
                        "message" => "Diese Mitgliedsnummer ist bereits vergeben"
                    ]);
                    break;
                }
            }

            $stmt = $db->prepare("INSERT INTO {$prefix}members (name, surname, member_number, active) 
                                  VALUES (?, ?, ?, ?)");
            if($stmt->execute([$cleanData->name, $cleanData->surname, $cleanData->member_number ?? null, 
                               $cleanData->active ?? true])) {
                $memberId = $db->lastInsertId();
                // Speichere Gruppen-Zuordnungen
                if(isset($cleanData->group_ids) && is_array($cleanData->group_ids)) {
                    $groupStmt = $db->prepare("INSERT INTO {$prefix}member_group_assignments (member_id, group_id) VALUES (?, ?)");
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

            // Erlaubte Felder
            $allowedFields = ['name', 'surname', 'member_number', 'active', 'group_ids'];
            $cleanData = new stdClass();
            foreach ($allowedFields as $field) {
                if (isset($data->$field)) {
                    $cleanData->$field = $data->$field;
                }
            }

            // member_number-Duplikat prüfen (wenn angegeben)
            if (isset($cleanData->member_number) && !empty($cleanData->member_number)) {
                $checkStmt = $db->prepare("SELECT member_id FROM {$prefix}members
                                           WHERE member_number = ? AND member_id != ?");
                $checkStmt->execute([$cleanData->member_number, $id]);
                if ($checkStmt->fetch()) {
                    http_response_code(409);
                    echo json_encode(["message" => "Diese Mitgliedsnummer ist bereits vergeben", "field" => "member_number"]);
                    break;
                }
            }

            // Dynamisches UPDATE: nur gelieferte Felder
            $updatable = ['name', 'surname', 'member_number', 'active'];
            $setParts  = [];
            $params    = [];
            foreach ($updatable as $field) {
                if (isset($cleanData->$field)) {
                    $setParts[] = "$field = ?";
                    $params[]   = ($field === 'member_number' && empty($cleanData->$field)) ? null : $cleanData->$field;
                }
            }

            if (empty($setParts) && !isset($cleanData->group_ids)) {
                http_response_code(400);
                echo json_encode(["message" => "Keine gültigen Felder zum Aktualisieren angegeben"]);
                break;
            }

            if (!empty($setParts)) {
                $params[] = $id;
                $stmt = $db->prepare("UPDATE {$prefix}members SET " . implode(', ', $setParts) . " WHERE member_id = ?");
                $stmt->execute($params);

                if ($stmt->rowCount() === 0) {
                    // Prüfen ob member existiert
                    $exists = $db->prepare("SELECT member_id FROM {$prefix}members WHERE member_id = ?");
                    $exists->execute([$id]);
                    if (!$exists->fetch()) {
                        http_response_code(404);
                        echo json_encode(["message" => "Member not found"]);
                        break;
                    }
                }
            }

            // Gruppen-Zuordnungen aktualisieren (wenn group_ids geliefert)
            if (isset($cleanData->group_ids)) {
                $db->prepare("DELETE FROM {$prefix}member_group_assignments WHERE member_id = ?")->execute([$id]);
                if (is_array($cleanData->group_ids)) {
                    $groupStmt = $db->prepare("INSERT INTO {$prefix}member_group_assignments (member_id, group_id) VALUES (?, ?)");
                    foreach ($cleanData->group_ids as $groupId) {
                        $groupStmt->execute([$id, $groupId]);
                    }
                }
            }

            echo json_encode(["message" => "Member updated"]);
            break;
            
        case 'DELETE':
            requireAdminOrManager();

            // Nur inaktive Mitglieder löschen
            $checkStmt = $db->prepare("SELECT active FROM {$prefix}members WHERE member_id = ?");
            $checkStmt->execute([$id]);
            $member = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if($member && $member['active'] == 1) {
                http_response_code(400);
                echo json_encode([
                    "message" => "Aktives Mitglied zuerst deaktivieren."
                ]);
                break;
            }

            // Lösche zuerst abhängige Datensätze
            $db->prepare("DELETE FROM {$prefix}records WHERE member_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM {$prefix}exceptions WHERE member_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM {$prefix}membership_dates WHERE member_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM {$prefix}member_group_assignments WHERE member_id = ?")->execute([$id]);

            $db->prepare("UPDATE {$prefix}users SET member_id = NULL WHERE member_id = ?")->execute([$id]);
            
            // Dann das Mitglied selbst
            $stmt = $db->prepare("DELETE FROM {$prefix}members WHERE member_id = ?");
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "Member and all associated data deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete member"]);
            }
            break;
    }
}

?>