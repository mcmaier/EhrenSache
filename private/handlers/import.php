<?php
// ============================================
// IMPORT Handler
// ============================================

function handleImport($db, $request_method, $authUserRole) {
    if ($request_method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }
    
    // Nur Admins dürfen importieren
    if ($authUserRole !== 'admin') {
        http_response_code(403);
        echo json_encode(["message" => "Admin access required"]);
        exit();
    }
    
    $type = $_GET['type'] ?? 'members';
    
    // Prüfe ob Datei hochgeladen wurde
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(["message" => "No file uploaded or upload error"]);
        exit();
    }
    
    $file = $_FILES['file'];
    
    // Prüfe Dateityp
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($fileExt !== 'csv') {
        http_response_code(400);
        echo json_encode(["message" => "Only CSV files allowed"]);
        exit();
    }
    
    switch($type) {
        case 'members':
            $result = importMembers($db, $file['tmp_name']);
            break;
        case 'records':
            $result = importRecords($db, $file['tmp_name']);
            break;
        default:
            http_response_code(400);
            echo json_encode(["message" => "Invalid import type"]);
            exit();
    }
    
    echo json_encode($result);
}

// ============================================
// IMPORT MEMBERS
// ============================================

function importMembers($db, $filePath) {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ["success" => false, "message" => "Could not read file"];
    }
    
    // UTF-8 BOM überspringen falls vorhanden
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    
    // Header-Zeile einlesen
    $header = fgetcsv($handle, 0, ';');
    if (!$header || !in_array('name', $header) || !in_array('surname', $header)) {
        fclose($handle);
        return ["success" => false, "message" => "Invalid CSV format - missing required columns"];
    }
    
    $imported = 0;
    $updated = 0;
    $errors = [];
    $rowNumber = 1;
    
    // Cache für Gruppen-Lookup
    $groupCache = [];
    $stmt = $db->query("SELECT group_id, group_name FROM member_groups");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $groupCache[$row['group_name']] = $row['group_id'];
    }
    
    $db->beginTransaction();
    
    try {
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $rowNumber++;
            
            // CSV zu assoziativem Array
            $row = array_combine($header, $data);
            
            // Validierung
            if (empty($row['name']) || empty($row['surname'])) {
                $errors[] = "Row $rowNumber: Name and surname required";
                continue;
            }
            
            $name = trim($row['name']);
            $surname = trim($row['surname']);
            $memberNumber = trim($row['member_number'] ?? '');
            $active = isset($row['active']) ? intval($row['active']) : 1;
            $groups = !empty($row['groups']) ? explode('|', $row['groups']) : [];
            
            $memberId = null;
            
            // Suche nach existierendem Mitglied
            if (!empty($memberNumber)) {
                // Primär: Suche nach member_number
                $stmt = $db->prepare("SELECT member_id FROM members WHERE member_number = ?");
                $stmt->execute([$memberNumber]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $memberId = $existing['member_id'];
                }
            }

            if (!$memberId) {
                // Sekundär: Suche nach Name + Surname
                $stmt = $db->prepare("SELECT member_id FROM members WHERE name = ? AND surname = ?");
                $stmt->execute([$name, $surname]);
                $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($matches) > 1) {
                    // Mehrere Treffer -> member_number erforderlich
                    $errors[] = "Row $rowNumber: Multiple members with name '$name $surname' found - member_number required";
                    continue;
                } elseif (count($matches) === 1) {
                    $memberId = $matches[0]['member_id'];
                }
            }

            // Update oder Insert
            if ($memberId) {
                // Update existierendes Mitglied
                $stmt = $db->prepare("
                    UPDATE members 
                    SET name=?, surname=?, member_number=?, active=?
                    WHERE member_id=?
                ");
                $stmt->execute([$name, $surname, $memberNumber, $active, $memberId]);
                $updated++;
            } else {
                // Neues Mitglied erstellen
                $stmt = $db->prepare("
                    INSERT INTO members (name, surname, member_number, active)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$name, $surname, $memberNumber, $active]);
                $memberId = $db->lastInsertId();
                $imported++;
            }                       
            
            // Gruppenzuordnungen aktualisieren
            if (!empty($groups)) {
                // Alte Zuordnungen löschen
                $stmt = $db->prepare("DELETE FROM member_group_assignments WHERE member_id=?");
                $stmt->execute([$memberId]);
                
                // Neue Zuordnungen erstellen
                $stmt = $db->prepare("INSERT INTO member_group_assignments (member_id, group_id) VALUES (?, ?)");
                foreach ($groups as $groupName) {
                    $groupName = trim($groupName);
                    if (isset($groupCache[$groupName])) {
                        $stmt->execute([$memberId, $groupCache[$groupName]]);
                    } else {
                        $errors[] = "Row $rowNumber: Group '$groupName' not found";
                    }
                }
            }
        }
        
        $db->commit();
        fclose($handle);
        
        return [
            "success" => true,
            "imported" => $imported,
            "updated" => $updated,
            "errors" => $errors
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        fclose($handle);
        return [
            "success" => false,
            "message" => "Import failed: " . $e->getMessage()
        ];
    }
}

// ============================================
// IMPORT RECORDS
// ============================================

function importRecords($db, $filePath) {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ["success" => false, "message" => "Could not read file"];
    }
    
    // UTF-8 BOM überspringen falls vorhanden
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    
    // Header-Zeile einlesen
    $header = fgetcsv($handle, 0, ';');
    if (!$header || !in_array('member_number', $header) || !in_array('arrival_date_time', $header)) {
        fclose($handle);
        return ["success" => false, "message" => "Invalid CSV format - missing required columns (member_number, arrival_date_time)"];
    }
    
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $rowNumber = 1;
    
    $db->beginTransaction();
    
    try {
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $rowNumber++;
            
            // CSV zu assoziativem Array
            $row = array_combine($header, $data);
            
            // Validierung
            if (empty($row['member_number']) || empty($row['arrival_date_time'])) {
                $errors[] = "Row $rowNumber: member_number and arrival_date_time required";
                continue;
            }
            
            $membershipNumber = trim($row['member_number']);            
            $arrivalDateTime = trim($row['arrival_date_time']);
            $status = !empty($row['status']) ? trim($row['status']) : 'present';
            
            // Validiere Status
            if (!in_array($status, ['present', 'excused'])) {
                $errors[] = "Row $rowNumber: Invalid status '$status' (must be present/excused)";
                continue;
            }

            // Parse arrival_date_time zu Datum und Zeit
            try {
                $dateTime = new DateTime($arrivalDateTime);
                $arrivalTime = $dateTime->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $errors[] = "Row $rowNumber: Invalid date/time format '$arrivalTime' (expected: YYYY-MM-DD HH:MM:SS)";
                continue;
            }
            
            // Finde Mitglied anhand member_number
            $stmt = $db->prepare("SELECT member_id FROM members WHERE member_number = ?");
            $stmt->execute([$membershipNumber]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member) {
                $errors[] = "Row $rowNumber: Member with number '$membershipNumber' not found";
                continue;
            }
            $memberId = $member['member_id'];
            
            // Finde nächsten Termin innerhalb der Toleranzzeit
            // Nutze AUTO_CHECKIN_TOLERANCE_HOURS aus config.php
            $toleranceHours = AUTO_CHECKIN_TOLERANCE_HOURS;

            $stmt = $db->prepare("
                SELECT appointment_id, date, start_time,
                    ABS(TIMESTAMPDIFF(MINUTE, CONCAT(date, ' ', start_time), ?)) as time_diff_minutes
                FROM appointments 
                WHERE CONCAT(date, ' ', start_time) BETWEEN 
                    DATE_SUB(?, INTERVAL ? HOUR) AND 
                    DATE_ADD(?, INTERVAL ? HOUR)
                ORDER BY time_diff_minutes ASC
                LIMIT 1
            ");
            $stmt->execute([
                $arrivalDateTime, 
                $arrivalDateTime, 
                $toleranceHours, 
                $arrivalDateTime, 
                $toleranceHours
            ]);

            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appointment) {
                $errors[] = "Row $rowNumber: No appointment found within {$toleranceHours} hours of '$arrivalDateTime'";
                continue;
            }

            $appointmentId = $appointment['appointment_id'];
            
            // Prüfe ob Record bereits existiert
            $stmt = $db->prepare("SELECT record_id FROM records WHERE member_id = ? AND appointment_id = ?");
            $stmt->execute([$memberId, $appointmentId]);
            $existingRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingRecord) {
                // Update existierenden Record
                $stmt = $db->prepare("
                    UPDATE records 
                    SET arrival_time=?, status=?, checkin_source='import'
                    WHERE record_id=?
                ");
                $stmt->execute([$arrivalTime, $status, $existingRecord['record_id']]);
                $updated++;
            } else {
                // Neuen Record erstellen
                $stmt = $db->prepare("
                    INSERT INTO records (member_id, appointment_id, arrival_time, status, checkin_source)
                    VALUES (?, ?, ?, ?, 'import')
                ");
                $stmt->execute([$memberId, $appointmentId, $arrivalTime, $status]);
                $imported++;
            }
        }
        
        $db->commit();
        fclose($handle);
        
        return [
            "success" => true,
            "imported" => $imported,
            "updated" => $updated,
            "skipped" => $skipped,
            "errors" => $errors
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        fclose($handle);
        return [
            "success" => false,
            "message" => "Import failed: " . $e->getMessage()
        ];
    }
}

?>