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
// MEMBERSHIP_DATES Controller
// ============================================
function handleMembershipDates($db, $database, $method, $id) {    

    $prefix = $database->table('');

    switch($method) {
        case 'GET':
            requireAdminOrManager();

            if($id) {
                // Einzelner Zeitraum
                $stmt = $db->prepare("SELECT md.*, m.name, m.surname 
                                     FROM {$prefix}membership_dates md
                                     JOIN {$prefix}members m ON md.member_id = m.member_id
                                     WHERE md.membership_date_id = ?");
                $stmt->execute([$id]);
                $membershipDate = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if($membershipDate) {
                    echo json_encode($membershipDate);
                } else {
                    http_response_code(404);
                    echo json_encode(["message" => "Membership date not found"]);
                }
            } else {
                // Liste - optional gefiltert nach member_id
                $member_id = $_GET['member_id'] ?? null;
                
                if($member_id) {
                    $stmt = $db->prepare("SELECT md.*, m.name, m.surname 
                                         FROM {$prefix}membership_dates md
                                         JOIN {$prefix}members m ON md.member_id = m.member_id
                                         WHERE md.member_id = ?
                                         ORDER BY md.start_date DESC");
                    $stmt->execute([$member_id]);
                } else {
                    $stmt = $db->query("SELECT md.*, m.name, m.surname 
                                       FROM {$prefix}membership_dates md
                                       JOIN {$prefix}members m ON md.member_id = m.member_id
                                       ORDER BY md.start_date DESC");
                }
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            break;
            
        case 'POST':
            requireAdminOrManager();
            $data = json_decode(file_get_contents("php://input"));
            
            // Validierung
            if(!isset($data->member_id) || !isset($data->start_date)) {
                http_response_code(400);
                echo json_encode(["message" => "member_id and start_date are required"]);
                return;
            }
            
            $stmt = $db->prepare("INSERT INTO {$prefix}membership_dates 
                                  (member_id, start_date, end_date, status) 
                                  VALUES (?, ?, ?, ?)");
            
            if($stmt->execute([
                $data->member_id,
                $data->start_date,
                $data->end_date ?? null,
                $data->status ?? 'active'
            ])) {
                http_response_code(201);
                echo json_encode([
                    "message" => "Membership date created",
                    "id" => $db->lastInsertId()
                ]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to create membership date"]);
            }
            break;
            
        case 'PUT':
            requireAdminOrManager();
            $data = json_decode(file_get_contents("php://input"));
            
            $stmt = $db->prepare("UPDATE {$prefix}membership_dates 
                                  SET start_date = ?, end_date = ?, status = ?
                                  WHERE membership_date_id = ?");
            
            if($stmt->execute([
                $data->start_date,
                $data->end_date ?? null,
                $data->status ?? 'active',
                $id
            ])) {
                echo json_encode(["message" => "Membership date updated"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to update membership date"]);
            }
            break;
            
        case 'DELETE':
            requireAdminOrManager();
            $stmt = $db->prepare("DELETE FROM {$prefix}membership_dates WHERE membership_date_id = ?");
            
            if($stmt->execute([$id])) {
                echo json_encode(["message" => "Membership date deleted"]);
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed to delete membership date"]);
            }
            break;
    }
}

?>