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
// IMPORT Handler
// ============================================

function handleImport($db, $request_method, $authUserRole) {
    if ($request_method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }
    
    // Nur Admins dürfen importieren
    requireAdmin();
    
    $type = $_GET['type'] ?? 'members';
    
    // Prüfe ob Datei hochgeladen wurde
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(["message" => "No file uploaded or upload error"]);
        exit();
    }
    
    $file = $_FILES['file'];
    
    /*
    // Prüfe Dateityp
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($fileExt !== 'csv') {
        http_response_code(400);
        echo json_encode(["message" => "Only CSV files allowed"]);
        exit();
    }*/
    //-------

    // 1. GRÖSSEN-LIMIT (10MB)
    $maxSize = 5 * 1024 * 1024;
    if($file['size'] > $maxSize) {
        http_response_code(413);
        echo json_encode([
            "message" => "File too large",
            "max_size" => "5MB",
            "uploaded_size" => round($file['size'] / 1024 / 1024, 2) . "MB"
        ]);
        return;
    }
    
    // 2. MIME-TYPE PRÜFUNG (Server-seitig!)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedMimes = ['text/plain', 'text/csv', 'application/csv'];
    if(!in_array($mimeType, $allowedMimes)) {
        http_response_code(400);
        echo json_encode([
            "message" => "Invalid file type",
            "detected" => $mimeType,
            "allowed" => "CSV only"
        ]);
        return;
    }
    
    // 3. DATEIINHALT PRÜFEN (keine PHP-Tags!)
    $content = file_get_contents($file['tmp_name']);
    
    if(preg_match('/<\?php|<\?=|<script/i', $content)) {
        http_response_code(400);
        echo json_encode([
            "message" => "File contains forbidden code",
            "hint" => "PHP/Script tags not allowed"
        ]);
        return;
    }
    
    // 4. DATEINAMEN SÄUBERN
    $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    
    // Nur erlaubte Extensions
    if(strtolower($extension) !== 'csv') {
        http_response_code(400);
        echo json_encode(["message" => "Only .csv files allowed"]);
        return;
    }
    
    // Gefährliche Zeichen entfernen
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $originalName);
    $safeName = substr($safeName, 0, 50); // Max 50 Zeichen
    
    // 5. ZUFÄLLIGER DATEINAME (verhindert Überschreiben)
    $randomName = bin2hex(random_bytes(8));
    $finalName = $randomName . '_' . $safeName . '.csv';
    
    // 6. UPLOAD-ORDNER AUSSERHALB WEBROOT
    $uploadDir = __DIR__ . '/../uploads/';
    
    // Erstelle Ordner falls nicht vorhanden
    if(!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $targetPath = $uploadDir . $finalName;
    
    // 7. VERSCHIEBEN (nicht kopieren!)
    if(!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode(["message" => "Failed to save file"]);
        return;
    }

    //------- $file['tmp_name']
    
    switch($type) {
        case 'members':
            $result = importMembers($db, $targetPath);
            break;
        case 'records':
            $result = importRecords($db, $targetPath);
            break;
        case 'appointments':
            $result = importAppointments($db, $targetPath);
            break;
        case 'extract_appointments':
            $minRecords = isset($_POST['min_records']) ? (int)$_POST['min_records'] : 5;
            $roundMinutes = isset($_POST['round_minutes']) ? (int)$_POST['round_minutes'] : 15;
            $toleranceHours = isset($_POST['tolerance_hours']) ? (int)$_POST['tolerance_hours'] : null;
    
            $result = extractAppointments($db, $targetPath, $minRecords, $roundMinutes, $toleranceHours);
            break;

        default:
            http_response_code(400);
            echo json_encode(["message" => "Invalid import type"]);
            // Datei nach Verarbeitung LÖSCHEN
            unlink($targetPath);
            exit();    
        }

    // Datei nach Verarbeitung LÖSCHEN
    unlink($targetPath);
    
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

            // Überspringe leere Zeilen
            if (empty($data) || (count($data) === 1 && empty($data[0]))) {
                continue;
            }
            
            // Prüfe ob Anzahl der Spalten passt
            if (count($header) !== count($data)) {
                $errors[] = "Row $rowNumber: Column count mismatch (expected " . count($header) . ", got " . count($data) . ")";
                continue;
            }
            
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
// IMPORT APPOINTMENTS
// ============================================

function importAppointments($db, $filePath) {
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
    if (!$header || !in_array('date', $header) || !in_array('start_time', $header) || !in_array('title', $header) || !in_array('type_name', $header)) {
        fclose($handle);
        return ["success" => false, "message" => "Invalid CSV format - missing required columns (date, start_time, title, type_name)"];
    }
    
    $imported = 0;
    $updated = 0;
    $errors = [];
    $rowNumber = 1;
    
    // Cache für Terminarten-Lookup
    $typeCache = [];
    $stmt = $db->query("SELECT type_id, type_name FROM appointment_types");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $typeCache[$row['type_name']] = $row['type_id'];
    }
    
    $db->beginTransaction();
    
    try {
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $rowNumber++;
            
            // Überspringe leere Zeilen
            if (empty($data) || (count($data) === 1 && empty($data[0]))) {
                continue;
            }
            
            // Prüfe ob Anzahl der Spalten passt
            if (count($header) !== count($data)) {
                $errors[] = "Row $rowNumber: Column count mismatch (expected " . count($header) . ", got " . count($data) . ")";
                continue;
            }
            
            // CSV zu assoziativem Array
            $row = array_combine($header, $data);
            
            // Validierung
            if (empty($row['date']) || empty($row['start_time']) || empty($row['title']) || empty($row['type_name'])) {
                $errors[] = "Row $rowNumber: date, start_time, title and type_name required";
                continue;
            }
            
            $date = trim($row['date']);
            $startTime = trim($row['start_time']);
            $title = trim($row['title']);
            $typeName = trim($row['type_name']);
            $description = trim($row['description'] ?? '');
            
            // Validiere Datum
            $dateObj = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
                $errors[] = "Row $rowNumber: Invalid date format '$date' (expected: YYYY-MM-DD)";
                continue;
            }
            
            // Validiere Zeit
            $timeObj = DateTime::createFromFormat('H:i:s', $startTime);
            if (!$timeObj) {
                // Versuche H:i Format
                $timeObj = DateTime::createFromFormat('H:i', $startTime);
                if ($timeObj) {
                    $startTime = $timeObj->format('H:i:s');
                } else {
                    $errors[] = "Row $rowNumber: Invalid time format '$startTime' (expected: HH:MM:SS or HH:MM)";
                    continue;
                }
            }
            
            // Finde Terminart
            if (!isset($typeCache[$typeName])) {
                $errors[] = "Row $rowNumber: Appointment type '$typeName' not found";
                continue;
            }
            $typeId = $typeCache[$typeName];
            
            // Prüfe ob Termin bereits existiert (gleicher Typ, Datum und Zeit)
            $stmt = $db->prepare("
                SELECT appointment_id 
                FROM appointments 
                WHERE type_id = ? AND date = ? AND start_time = ?
            ");
            $stmt->execute([$typeId, $date, $startTime]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existierenden Termin
                $stmt = $db->prepare("
                    UPDATE appointments 
                    SET title=?, description=?
                    WHERE appointment_id=?
                ");
                $stmt->execute([$title, $description, $existing['appointment_id']]);
                $updated++;
            } else {
                // Neuen Termin erstellen
                $stmt = $db->prepare("
                    INSERT INTO appointments (type_id, date, start_time, title, description)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$typeId, $date, $startTime, $title, $description]);
                $imported++;
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
            
            // Überspringe leere Zeilen
            if (empty($data) || (count($data) === 1 && empty($data[0]))) {
                continue;
            }
            
            // Prüfe ob Anzahl der Spalten passt
            if (count($header) !== count($data)) {
                $errors[] = "Row $rowNumber: Column count mismatch (expected " . count($header) . ", got " . count($data) . ")";
                continue;
            }

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

// ============================================
// EXTRACT APPOINTMENTS from RECORD-FILE
// ============================================

function extractAppointments($db, $filePath, $minRecords = 5, $roundMinutes = 15, $toleranceHours = null) {
    if ($toleranceHours === null) {
        $toleranceHours = AUTO_CHECKIN_TOLERANCE_HOURS;
    }
    
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
    if (!$header || !in_array('arrival_date_time', $header)) {
        fclose($handle);
        return ["success" => false, "message" => "Invalid CSV format - missing required column (arrival_date_time)"];
    }
    
    // Sammle alle Zeitstempel
    $timestamps = [];
    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        // Überspringe leere Zeilen
        if (empty($data) || (count($data) === 1 && empty($data[0]))) {
            continue;
        }
        
        $row = array_combine($header, $data);
        if (!empty($row['arrival_date_time'])) {
            $timestamps[] = trim($row['arrival_date_time']);
        }
    }
    fclose($handle);
    
    // Gruppiere nach Datum
    $dateGroups = [];
    foreach ($timestamps as $ts) {
        try {
            $dateTime = new DateTime($ts);
            $date = $dateTime->format('Y-m-d');
            $time = $dateTime->format('H:i:s');
            
            if (!isset($dateGroups[$date])) {
                $dateGroups[$date] = [];
            }
            $dateGroups[$date][] = $time;
        } catch (Exception $e) {
            // Überspringe ungültige Zeitstempel
            continue;
        }
    }
    
    // Analysiere jeden Tag
    $suggestions = [];
    
    foreach ($dateGroups as $date => $times) {
        sort($times);
        
        // Clustering: Finde Zeitfenster mit mindestens $minRecords innerhalb $toleranceHours
        $clusters = [];
        
        foreach ($times as $time) {
            $placed = false;
            
            foreach ($clusters as &$cluster) {
                $clusterStart = strtotime("$date {$cluster['times'][0]}");
                $clusterEnd = strtotime("$date " . end($cluster['times']));
                $currentTime = strtotime("$date $time");
                
                // Prüfe ob innerhalb Toleranz zum Cluster
                if (abs($currentTime - $clusterStart) <= $toleranceHours * 3600 ||
                    abs($currentTime - $clusterEnd) <= $toleranceHours * 3600) {
                    $cluster['times'][] = $time;
                    $placed = true;
                    break;
                }
            }
            
            if (!$placed) {
                $clusters[] = ['times' => [$time]];
            }
        }        
        // Filtere Cluster nach Mindestanzahl
        foreach ($clusters as $cluster) {
            if (count($cluster['times']) >= $minRecords) {
                // Berechne Startzeit basierend auf Median oder 75. Perzentil
                // Annahme: Die meisten kommen VOR dem Event, daher höheren Wert nehmen
                $times = $cluster['times'];
                $count = count($times);
                
                // Nutze 25. Perzentil 
                $percentileIndex =  floor($count * 0.25);
                
                $suggestedTime = $times[$percentileIndex];
                $timeObj = DateTime::createFromFormat('H:i:s', $suggestedTime);
                
                // Runde AUFWÄRTS auf $roundMinutes
                // Wenn Event um 19:12 beginnt → 19:15 (nicht 19:00)
                $minutes = (int)$timeObj->format('i');
                $roundedMinutes = ceil($minutes / $roundMinutes) * $roundMinutes;
                
                // Überlauf behandeln (z.B. 55 Min + Rundung auf 60)
                if ($roundedMinutes >= 60) {
                    $timeObj->modify('+1 hour');
                    $roundedMinutes = 0;
                }
                
                $timeObj->setTime((int)$timeObj->format('H'), $roundedMinutes, 0);
                
                $suggestions[] = [
                    'date' => $date,
                    'start_time' => $timeObj->format('H:i:s'),
                    'record_count' => count($times),
                    'time_range' => [
                        'earliest' => $times[0],
                        'latest' => end($times)
                    ]
                ];
            }
        }
        

        // Filtere Cluster nach Mindestanzahl
        /*
        foreach ($clusters as $cluster) {
            if (count($cluster['times']) >= $minRecords) {
                // Berechne gerundete Startzeit (nimm früheste Zeit)
                $firstTime = $cluster['times'][0];
                $timeObj = DateTime::createFromFormat('H:i:s', $firstTime);
                
                // Runde auf $roundMinutes
                $minutes = (int)$timeObj->format('i');
                $roundedMinutes = floor($minutes / $roundMinutes) * $roundMinutes;
                $timeObj->setTime((int)$timeObj->format('H'), $roundedMinutes, 0);
                
                $suggestions[] = [
                    'date' => $date,
                    'start_time' => $timeObj->format('H:i:s'),
                    'record_count' => count($cluster['times']),
                    'time_range' => [
                        'earliest' => $cluster['times'][0],
                        'latest' => end($cluster['times'])
                    ]
                ];
            }
        }*/
    }
    
    // Sortiere nach Datum
    usort($suggestions, function($a, $b) {
        return strcmp($a['date'] . $a['start_time'], $b['date'] . $b['start_time']);
    });
    
    return [
        'success' => true,
        'parameters' => [
            'min_records' => $minRecords,
            'round_minutes' => $roundMinutes,
            'tolerance_hours' => $toleranceHours
        ],
        'total_records' => count($timestamps),
        'suggestions' => $suggestions
    ];
}

?>