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
            // Vorschlagsliste fuer das Ortsfeld (FI-23). Nur fuer die Rollen,
            // die Termine anlegen -- Orte sind Planungsdaten.
            if (isset($_GET['locations'])) {
                if (!isAdminOrManager()) {
                    http_response_code(403);
                    echo json_encode(["message" => "Nur für Admin und Manager"], JSON_UNESCAPED_UNICODE);
                    break;
                }
                echo json_encode(appointmentLocationSuggestions($db, $prefix), JSON_UNESCAPED_UNICODE);
                break;
            }

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
                        at.description as type_description,
                        COALESCE(at.responses_enabled, 0) as responses_enabled
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

                    // Eine fremde member_id duerfen nur Verwalter setzen. Fuer
                    // alle anderen bleibt es beim eigenen Mitglied -- sonst
                    // liest ein Mitglied mit einer fremden ID die Termine
                    // fremder Gruppen. Die PWA schickt die eigene ID mit;
                    // fuer sie aendert sich dadurch nichts.
                    if($member_id !== null && isAdminOrManager())
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
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Summen nur bei eingegrenztem Zeitraum anhaengen: Ohne Jahres- oder
                // Datumsfilter laeuft die Liste ueber die ganze Historie (so ruft die
                // Check-in-PWA sie mit member_id allein ab) -- dort waeren die je Termin
                // korrelierten Aktivitaets-Unterabfragen zu teuer. Die PWA holt sich
                // Rueckmeldungen stattdessen ueber resource=appointment_responses&upcoming=1.
                if ($year || $from_date || $to_date) {
                    // Eigene Antwort je Termin: das Mitglied des angemeldeten Kontos,
                    // auch bei Admin und Manager, wenn eines verknuepft ist.
                    $viewerStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
                    $viewerStmt->execute([getCurrentUserId()]);
                    $viewerMemberId = $viewerStmt->fetchColumn();

                    $rows = responsesAttachSummaries(
                        $db, $database, $rows,
                        $viewerMemberId ? (int) $viewerMemberId : null
                    );
                }

                echo json_encode($rows);
            }
            break;
            
        case 'POST':
            requireAdminOrManager();

            $rawData = json_decode(file_get_contents("php://input"));
            
            // Nur erlaubte Felder extrahieren
            $allowedFields = ['title', 'description','type_id', 'date', 'start_time','created_by', 'location', 'end_time'];
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

            // Ort und Ende (FI-23) pruefen, bevor irgendetwas geschrieben wird.
            [$location, $fehler] = appointmentNormalizeLocation($data->location ?? null);
            $endTime = null;
            if ($fehler === null) {
                [$endTime, $fehler] = appointmentNormalizeEndTime($data->end_time ?? null, (string) ($data->start_time ?? ''));
            }
            if ($fehler !== null) {
                http_response_code(400);
                echo json_encode(["message" => $fehler], JSON_UNESCAPED_UNICODE);
                break;
            }

            // Dublettenpruefung: gleiche Terminart im Toleranzfenster (appointment_rules.php).
            $tolerance = checkinToleranceHours($db, $database);
            $conflict = findAppointmentConflict($db, $prefix, (string) $data->date, (string) $data->start_time,
                                                $typeId, $tolerance);
            if ($conflict) {
                http_response_code(409);
                echo json_encode(appointmentConflictBody($conflict, $tolerance));
                break;
            }

            $stmt = $db->prepare("INSERT INTO {$prefix}appointments (title, type_id, description, location, date,
                                  start_time, end_time, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $createdBy = getCurrentUserId();
            if($stmt->execute([$data->title, $typeId, $data->description ?? null, $location, $data->date,
                               $data->start_time, $endTime, $createdBy])) {
                http_response_code(201);
                echo json_encode(["message" => "Appointment created", "id" => $db->lastInsertId()]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create appointment"]);
            }
            break;
            
        case 'PUT':
            requireAdminOrManager();

            $rawData = (object) (json_decode(file_get_contents("php://input")) ?? []);

            // Nur erlaubte Felder extrahieren. property_exists statt isset:
            // Ein ausdrueckliches null ist eine Angabe ("Beschreibung loeschen"),
            // ein fehlendes Feld ist keine. isset() warf beides in denselben
            // Topf und machte das Loeschen einer Angabe unmoeglich.
            $allowedFields = ['title', 'type_id', 'description', 'date', 'start_time', 'location', 'end_time'];
            $data = new stdClass();
            foreach($allowedFields as $field) {
                if(property_exists($rawData, $field)) {
                    $data->$field = $rawData->$field;
                }
            }

            // Bestand lesen: Was der Request nicht mitschickt, bleibt stehen.
            // Bis 1.9.0 war das eine Vollersetzung -- ein PUT, das nur das
            // Datum aendern wollte, nullte Titel und Terminart (OI-69). Die
            // schwerere Folge war die Terminart: Ein Termin ohne type_id hat
            // keine Gruppenzuordnung mehr, verschwindet aus den Listen der
            // Mitglieder und zaehlt in keiner Auswertung mehr mit.
            $bestandStmt = $db->prepare("SELECT title, type_id, description, date, start_time, end_time
                                         FROM {$prefix}appointments WHERE appointment_id = ?");
            $bestandStmt->execute([$id]);
            $bestand = $bestandStmt->fetch(PDO::FETCH_ASSOC);

            if(!$bestand) {
                http_response_code(404);
                echo json_encode(["message" => "Appointment not found"]);
                break;
            }

            // Prüfe ob bereits ein anderer Termin der gleichen Art in der Toleranzzeit existiert

            // Geprüft wird gegen den Zustand NACH dem Update, nicht gegen den
            // Anfragekörper: Fehlt ein Feld, gilt der gespeicherte Wert. Vorher
            // verglich die Prüfung bei einem Teil-Update gegen " " und
            // type_id = NULL -- und NULL trifft in SQL nie, die Dublettenprüfung
            // fiel also still aus.
            $wirkDate  = $data->date       ?? $bestand['date'];
            $wirkTime  = $data->start_time ?? $bestand['start_time'];
            $wirkType  = array_key_exists('type_id', get_object_vars($data))
                ? $data->type_id : $bestand['type_id'];

            // Ort und Ende (FI-23). Geprueft wird gegen den wirksamen Beginn;
            // aendert der Request nur den Beginn, zaehlt das gespeicherte Ende.
            $gesendet = get_object_vars($data);
            if (array_key_exists('location', $gesendet)) {
                [$data->location, $fehler] = appointmentNormalizeLocation($data->location);
                if ($fehler !== null) {
                    http_response_code(400);
                    echo json_encode(["message" => $fehler], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            if (array_key_exists('end_time', $gesendet)) {
                [$data->end_time, $fehler] = appointmentNormalizeEndTime($data->end_time, (string) $wirkTime);
                if ($fehler !== null) {
                    http_response_code(400);
                    echo json_encode(["message" => $fehler], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            $wirkEnd = array_key_exists('end_time', $gesendet) ? $data->end_time : $bestand['end_time'];
            if ($wirkEnd !== null && appointmentTimeKey((string) $wirkEnd) === appointmentTimeKey((string) $wirkTime)) {
                http_response_code(400);
                echo json_encode(["message" => "Das Ende darf nicht gleich dem Beginn sein"], JSON_UNESCAPED_UNICODE);
                break;
            }

            $tolerance = checkinToleranceHours($db, $database);
            $conflict = findAppointmentConflict($db, $prefix, (string) $wirkDate, (string) $wirkTime,
                                                $wirkType, $tolerance, [(int) $id]);
            if ($conflict) {
                http_response_code(409);
                echo json_encode(appointmentConflictBody($conflict, $tolerance));
                break;
            }

            // Nur schreiben, was mitgeschickt wurde -- dasselbe Muster wie bei
            // members, appointment_types und activity_types (OI-54).
            // description und type_id duerfen ausdruecklich auf NULL gesetzt
            // werden, deshalb array_key_exists statt isset.
            $vorhanden = get_object_vars($data);
            $updateFields = [];
            $updateParams = [];

            foreach (['title', 'type_id', 'description', 'date', 'start_time', 'location', 'end_time'] as $feld) {
                if (array_key_exists($feld, $vorhanden)) {
                    $updateFields[] = "{$feld} = ?";
                    $updateParams[] = $data->$feld;
                }
            }

            if (empty($updateFields)) {
                echo json_encode(["message" => "Appointment updated"]);
                break;
            }

            $updateParams[] = $id;
            $stmt = $db->prepare("UPDATE {$prefix}appointments SET " . implode(', ', $updateFields)
                                 . " WHERE appointment_id = ?");

            if($stmt->execute($updateParams)) {
                echo json_encode(["message" => "Appointment updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update appointment"]);
            }
            break;
            
        case 'DELETE':
            requireAdminOrManager();

            if (!$id) {
                http_response_code(400);
                echo json_encode(["message" => "id ist erforderlich"]);
                break;
            }
            
            // Lösche zuerst abhängige Datensätze
            $db->prepare("DELETE FROM {$prefix}records WHERE appointment_id = ?")->execute([$id]);
            // Explizit vor exceptions (W2): appointment_responses.exception_id zeigt
            // sonst kurzzeitig auf einen bereits geloeschten Antrag.
            $db->prepare("DELETE FROM {$prefix}appointment_responses WHERE appointment_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM {$prefix}exceptions WHERE appointment_id = ?")->execute([$id]);
            
            // Dann den Termin selbst
            $stmt = $db->prepare("DELETE FROM {$prefix}appointments WHERE appointment_id = ?");
            if($stmt->execute([$id])) {
                if($stmt->rowCount() === 0) {
                    // execute() liefert true, auch wenn keine Zeile traf --
                    // eine erfundene oder bereits geloeschte ID sah bisher
                    // wie ein Erfolg aus.
                    http_response_code(404);
                    echo json_encode(["message" => "Appointment not found"]);
                } else {
                    echo json_encode(["message" => "Appointment deleted"]);
                }
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete appointment"]);
            }
            break;
    }
}

?>