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
// EXCEPTIONS Controller
// ============================================
function handleExceptions($db, $database, $method, $id) {

    $prefix = $database->table('');

    switch($method) {
        case 'GET':
            if($id) {
                // Einzelne Exception mit allen Details
                $stmt = $db->prepare("SELECT e.*, 
                                     m.name, m.surname, 
                                     a.title as appointment_title, a.date as appointment_date, a.start_time as appointment_start,
                                     at.type_name as appointment_type_name,
                                     at.type_id as appointment_type_id,
                                     u1.email as created_by_email,
                                     u2.email as approved_by_email
                                     FROM {$prefix}exceptions e 
                                     JOIN {$prefix}members m ON e.member_id = m.member_id 
                                     JOIN {$prefix}appointments a ON e.appointment_id = a.appointment_id 
                                     LEFT JOIN {$prefix}users u1 ON e.created_by = u1.user_id
                                     LEFT JOIN {$prefix}users u2 ON e.approved_by = u2.user_id
                                     LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
                                     WHERE e.exception_id = ?");

                $stmt->execute([$id]);
                $exception = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // User dürfen nur ihre eigenen Exceptions sehen
                if(!isAdminOrManager()) {
                    $userStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
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
                $year = $_GET['year'] ?? null;
                
                // User sehen nur ihre eigenen Exceptions
                if(!isAdminOrManager()) {
                    $userStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
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
                        a.title as appointment_title, a.date as appointment_date, a.start_time as appointment_start_time,
                        at.type_id as appointment_type_id, at.type_name as appointment_type_name,
                        u1.email as created_by_email,
                        u2.email as approved_by_email
                        FROM {$prefix}exceptions e 
                        JOIN {$prefix}members m ON e.member_id = m.member_id 
                        JOIN {$prefix}appointments a ON e.appointment_id = a.appointment_id 
                        LEFT JOIN {$prefix}users u1 ON e.created_by = u1.user_id
                        LEFT JOIN {$prefix}users u2 ON e.approved_by = u2.user_id
                        LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
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

                if($year) {
                    $sql .= " AND YEAR(a.date) = ?";
                    $params[] = $year;
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
            
            // Pflichtfeld-Prüfung
            if (empty($data->member_id ?? null) || empty($data->appointment_id ?? null) ||
                empty($data->exception_type ?? null) || empty($data->reason ?? null)) {
                http_response_code(400);
                echo json_encode(["message" => "member_id, appointment_id, exception_type und reason sind Pflichtfelder"]);
                return;
            }
            // exception_type auf erlaubte Werte prüfen
            if (!in_array($data->exception_type, ['absence', 'time_correction'])) {
                http_response_code(400);
                echo json_encode(["message" => "exception_type muss 'absence' oder 'time_correction' sein"]);
                return;
            }

            // Prüfe ob User für sich selbst oder Admin für andere
            $requesting_member_id = $data->member_id;
            
            if(!isAdminOrManager()) {
                // User dürfen nur für sich selbst Anträge stellen
                $userStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
                $userStmt->execute([getCurrentUserId()]);
                $userMemberId = $userStmt->fetchColumn();
                
                if($requesting_member_id != $userMemberId) {
                    http_response_code(403);
                    echo json_encode(["message" => "You can only create requests for yourself"]);
                    return;
                }
            }

            // Zu einem Termin reicht ein Antrag je Art. Ohne diese Grenze stellt
            // dasselbe Mitglied über die Absage der Terminrückmeldung und über
            // den Antragsdialog zwei echte Anträge, die einzeln beschieden
            // werden müssten. Ein abgelehnter Antrag blockiert nicht -- dieselbe
            // Regel wie in responseExcuseAction(): die Ablehnung zählt wie kein
            // Antrag, sonst gäbe es nach einem Nein keinen zweiten Versuch.
            $duplicateStmt = $db->prepare("SELECT exception_id FROM {$prefix}exceptions
                                           WHERE member_id = ? AND appointment_id = ?
                                             AND exception_type = ? AND status <> 'rejected'
                                           LIMIT 1");
            $duplicateStmt->execute([$data->member_id, $data->appointment_id, $data->exception_type]);
            $existingId = $duplicateStmt->fetchColumn();

            if($existingId !== false) {
                http_response_code(409);
                echo json_encode([
                    "message"      => "Zu diesem Termin gibt es bereits einen Antrag dieser Art",
                    "exception_id" => (int) $existingId
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            $stmt = $db->prepare("INSERT INTO {$prefix}exceptions
                                  (member_id, appointment_id, exception_type, reason,
                                   requested_arrival_time, status, created_by)
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $requested_time = isset($data->requested_arrival_time) ? $data->requested_arrival_time : null;
            // Den Status aus dem Anfragekoerper duerfen nur Verwalter setzen --
            // sonst koennte sich ein Mitglied seinen eigenen Antrag selbst genehmigen.
            $status = isAdminOrManager() ? ($data->status ?? 'pending') : 'pending';

            // Die beantragte Ankunft muss zum Termin passen. Ohne diese Grenze
            // liesse sich für einen 20-Uhr-Termin 17:00 beantragen — eine
            // Pünktlichkeit, die niemand nachprüfen kann. Das Fenster ist
            // dasselbe wie beim Check-in.
            if (($data->exception_type ?? '') === 'time_correction' && $requested_time !== null
                && !arrivalWithinAppointmentWindow($db, $database, (int) $data->appointment_id,
                                                   (string) $requested_time,
                                                   checkinToleranceHours($db, $database))) {
                http_response_code(400);
                echo json_encode([
                    "message" => "Die angegebene Ankunftszeit liegt zu weit vom Termin entfernt"
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Eine Ankunft, die noch nicht stattgefunden hat, laesst sich nicht
            // nachtraeglich beantragen (OI-82). Eine Entschuldigung im Voraus
            // bleibt erlaubt -- die Terminrueckmeldung legt genau die an.
            if (($data->exception_type ?? '') === 'time_correction'
                && timeCorrectionTooEarly($db, $database, (int) $data->appointment_id,
                                          $requested_time !== null ? (string) $requested_time : null,
                                          checkinToleranceHours($db, $database))) {
                http_response_code(400);
                echo json_encode([
                    "message" => "Ein Zeitantrag ist erst möglich, wenn die Ankunft schon stattgefunden hat"
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

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
            $data = (object) (json_decode(file_get_contents("php://input")) ?? []);
            $vorhanden = get_object_vars($data);
            
            // Hole Exception Info. reason und requested_arrival_time gehoeren
            // dazu, seit ein PUT nur schreibt, was er mitschickt (OI-69).
            $checkStmt = $db->prepare("SELECT member_id, status, appointment_id, exception_type,
                                              reason, requested_arrival_time
                                       FROM {$prefix}exceptions WHERE exception_id = ?");
            $checkStmt->execute([$id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if(!$existing) {
                http_response_code(404);
                echo json_encode(["message" => "Exception not found"]);
                return;
            }

            // Was der Request nicht mitschickt, bleibt stehen (OI-69). Vorher
            // schrieben beide Zweige reason und requested_arrival_time
            // bedingungslos: Ein PUT, das nur genehmigen oder ablehnen wollte,
            // loeschte die Begruendung des Antragstellers und im Fall einer
            // Zeitkorrektur auch die beantragte Uhrzeit -- also genau das, was
            // die Entscheidung nachvollziehbar macht.
            $wirkReason = array_key_exists('reason', $vorhanden)
                ? $data->reason : $existing['reason'];
            $wirkRequestedTime = array_key_exists('requested_arrival_time', $vorhanden)
                ? $data->requested_arrival_time : $existing['requested_arrival_time'];
            $wirkStatus = array_key_exists('status', $vorhanden)
                ? $data->status : $existing['status'];

            // Dieselbe Grenze wie beim Anlegen, hier für beide Pfade: Das
            // Mitglied bessert seinen Antrag nach, der Admin korrigiert ihn vor
            // der Freigabe. Der Termin kommt aus dem Bestand, nicht aus dem
            // Anfragekörper — er lässt sich nachträglich nicht wechseln.
            $neueWunschzeit = $wirkRequestedTime;

            //
            // Eine Ablehnung ist ausgenommen: Sie erzeugt nichts, und ein Antrag
            // zu einem inzwischen verschobenen Termin muss sich bescheiden lassen.
            if ($existing['exception_type'] === 'time_correction' && $neueWunschzeit !== null
                && $wirkStatus !== 'rejected'
                && !arrivalWithinAppointmentWindow($db, $database, (int) $existing['appointment_id'],
                                                   (string) $neueWunschzeit,
                                                   checkinToleranceHours($db, $database))) {
                http_response_code(400);
                echo json_encode([
                    "message" => "Die angegebene Ankunftszeit liegt zu weit vom Termin entfernt"
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Dieselbe Grenze wie beim Anlegen (OI-82). Sie gilt auch für die
            // Genehmigung eines Antrags, der vor dieser Prüfung angelegt wurde --
            // nicht aber für die Ablehnung, aus demselben Grund wie oben.
            if ($existing['exception_type'] === 'time_correction' && $wirkStatus !== 'rejected'
                && timeCorrectionTooEarly($db, $database, (int) $existing['appointment_id'],
                                          $neueWunschzeit !== null ? (string) $neueWunschzeit : null,
                                          checkinToleranceHours($db, $database))) {
                http_response_code(400);
                echo json_encode([
                    "message" => "Ein Zeitantrag ist erst möglich, wenn die Ankunft schon stattgefunden hat"
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // User dürfen nur ihre eigenen pending Anträge bearbeiten
            if(!isAdminOrManager()) {
                $userStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
                $userStmt->execute([getCurrentUserId()]);
                $userMemberId = $userStmt->fetchColumn();
                
                if($existing['member_id'] != $userMemberId || $existing['status'] != 'pending') {
                    http_response_code(403);
                    echo json_encode(["message" => "Access denied"]);
                    return;
                }
                
                // User Update (nur Reason und requested_arrival_time)
                if (empty($data->reason ?? null)) {
                    http_response_code(400);
                    echo json_encode(["message" => "reason ist ein Pflichtfeld"]);
                    return;
                }
                $stmt = $db->prepare("UPDATE {$prefix}exceptions
                                      SET reason = ?, requested_arrival_time = ?
                                      WHERE exception_id = ?");
                $stmt->execute([
                    $wirkReason,
                    $wirkRequestedTime,
                    $id
                ]);
            } else {
                // Admin Update (inkl. Status ändern)
                $stmt = $db->prepare("UPDATE {$prefix}exceptions 
                                      SET reason = ?, 
                                          requested_arrival_time = ?,
                                          status = ?,
                                          approved_by = ?,
                                          approved_at = ?
                                      WHERE exception_id = ?");
                
                $approved_by = null;
                $approved_at = null;
                
                if($wirkStatus != 'pending') {
                    $approved_by = getCurrentUserId();
                    $approved_at = date('Y-m-d H:i:s');
                }
                
                $stmt->execute([
                    $wirkReason, 
                    $wirkRequestedTime,
                    $wirkStatus,
                    $approved_by,
                    $approved_at,
                    $id
                ]);
                
                // Bei Genehmigung einer Zeitkorrektur: Record erstellen/aktualisieren.
                // Die Art kommt aus dem Bestand, nicht aus dem Anfragekoerper --
                // sie laesst sich so wenig nachtraeglich wechseln wie der Termin,
                // und ein PUT ohne exception_type haette sonst gar keine
                // Nachbehandlung ausgeloest.
                if($wirkStatus === 'approved')
                {                    
                    if($existing['exception_type'] === 'time_correction') {
                        handleApprovedTimeCorrection($db, $database, $id, $data);
                    }
                    elseif($existing['exception_type'] === 'absence') {
                        handleApprovedAbsence($db, $database, $id, $data);
                    }
                }
            }
            
            echo json_encode(["message" => "Exception updated"]);
            break;
            
        case 'DELETE':
            // User dürfen nur ihre eigenen pending Anträge löschen
            // Admin darf alles löschen
            if (!$id) {
                http_response_code(400);
                echo json_encode(["message" => "id ist erforderlich"]);
                return;
            }

            $checkStmt = $db->prepare("SELECT member_id, status FROM {$prefix}exceptions WHERE exception_id = ?");
            $checkStmt->execute([$id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if(!$existing) {
                http_response_code(404);
                echo json_encode(["message" => "Exception not found"]);
                return;
            }
            
            if(!isAdminOrManager()) {
                $userStmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
                $userStmt->execute([getCurrentUserId()]);
                $userMemberId = $userStmt->fetchColumn();
                
                if($existing['member_id'] != $userMemberId || $existing['status'] != 'pending') {
                    http_response_code(403);
                    echo json_encode(["message" => "Access denied"]);
                    return;
                }
            }
            
            $stmt = $db->prepare("DELETE FROM {$prefix}exceptions WHERE exception_id = ?");
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