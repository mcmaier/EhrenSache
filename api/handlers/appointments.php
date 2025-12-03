<?php

// ============================================
// APPOINTMENTS Controller
// ============================================
function handleAppointments($db, $method, $id) {
    switch($method) {
        case 'GET':
            if($id) {
                $stmt = $db->prepare("SELECT * FROM appointments WHERE appointment_id = ?");
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
                
                // Baue Query dynamisch
                $sql = "SELECT * FROM appointments WHERE 1=1";
                $params = [];
                
                if($year) {
                    $sql .= " AND YEAR(date) = ?";
                    $params[] = $year;
                }
                
                if($month && $year) {
                    $sql .= " AND MONTH(date) = ?";
                    $params[] = $month;
                }
                
                if($from_date) {
                    $sql .= " AND date >= ?";
                    $params[] = $from_date;
                }
                
                if($to_date) {
                    $sql .= " AND date <= ?";
                    $params[] = $to_date;
                }
                
                $sql .= " ORDER BY date DESC, start_time";

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
            $rawData = json_decode(file_get_contents("php://input"));
            
            // Nur erlaubte Felder extrahieren
            $allowedFields = ['title', 'description', 'date', 'start_time','created_by'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($rawData->$field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // Prüfe ob bereits ein Termin in der Toleranz existiert
            $tolerance = AUTO_CHECKIN_TOLERANCE_HOURS;
            $toleranceSeconds = $tolerance * 3600;  // Stunden in Sekunden

            $newDateTime = $data->date . ' ' . $data->start_time;

            $checkStmt = $db->prepare("
                SELECT appointment_id, title, start_time, date,
                    ABS(TIMESTAMPDIFF(SECOND, CONCAT(date, ' ', start_time), ?)) as time_diff
                FROM appointments 
                WHERE date = ?
                HAVING time_diff <= ?
            ");
            
            $checkStmt->execute([$newDateTime, $data->date, $toleranceSeconds]);
            
            $conflict = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if($conflict) {
            http_response_code(409);
            echo json_encode([
                "message" => "Ein Termin existiert bereits im Toleranzbereich von ±{$tolerance}h",
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
            
                  
            $stmt = $db->prepare("INSERT INTO appointments (title, description, date, 
                                  start_time, created_by) VALUES (?, ?, ?, ?, ?)");
            $createdBy = getCurrentUserId(); 
            if($stmt->execute([$data->title, $data->description ?? null, $data->date, 
                               $data->start_time, $createdBy])) {
                http_response_code(201);
                echo json_encode(["message" => "Appointment created", "id" => $db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create appointment"]);
            }
            break;
            
        case 'PUT':
            $rawData = json_decode(file_get_contents("php://input"));
            
            // Nur erlaubte Felder extrahieren
            $allowedFields = ['title', 'description', 'date', 'start_time','id'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($rawData->$field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // Prüfe ob bereits ein anderer Termin in der Toleranz existiert
            $tolerance = AUTO_CHECKIN_TOLERANCE_HOURS;
            $toleranceSeconds = $tolerance * 3600;
            
            $newDateTime = $data->date . ' ' . $data->start_time;
            
            $checkStmt = $db->prepare("
                SELECT appointment_id, title, start_time, date,
                    ABS(TIMESTAMPDIFF(SECOND, CONCAT(date, ' ', start_time), ?)) as time_diff
                FROM appointments 
                WHERE date = ?
                AND appointment_id != ?
                HAVING time_diff <= ?
            ");
            
            $checkStmt->execute([$newDateTime, $data->date, $id, $toleranceSeconds]);
            
            $conflict = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if($conflict) {
                http_response_code(409);
                echo json_encode([
                    "message" => "Ein Termin existiert bereits im Toleranzbereich von ±{$tolerance}h",
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

            $stmt = $db->prepare("UPDATE appointments SET title=?, description=?, date=?, 
                                  start_time=? WHERE appointment_id=?");
            if($stmt->execute([$data->title, $data->description, $data->date, 
                              $data->start_time, $id])) {
                echo json_encode(["message" => "Appointment updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update appointment"]);
            }
            break;
            
        case 'DELETE':
            // Lösche zuerst abhängige Datensätze
            $db->prepare("DELETE FROM records WHERE appointment_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM exceptions WHERE appointment_id = ?")->execute([$id]);
            
            // Dann den Termin selbst
            $stmt = $db->prepare("DELETE FROM appointments WHERE appointment_id = ?");
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