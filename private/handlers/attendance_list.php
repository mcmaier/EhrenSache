<?php

// ============================================
// ATTENDANCE LIST Controller (für Manager/Admin)
// ============================================
function handleAttendanceList($db, $method, $id) {
    // Nur GET erlaubt
    if($method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }
    
    // Nur Admin/Manager
    if(!isAdminOrManager()) {
        http_response_code(403);
        echo json_encode(["message" => "Access denied"]);
        exit();
    }
    
    $appointment_id = $_GET['appointment_id'] ?? null;
    
    if(!$appointment_id) {
        http_response_code(400);
        echo json_encode(["message" => "appointment_id required"]);
        exit();
    }
    
    // Hole Termin-Details mit Typ-Gruppen
    $stmt = $db->prepare("
        SELECT a.*, 
               at.type_name, 
               at.color,
               GROUP_CONCAT(DISTINCT atg.group_id) as group_ids
        FROM appointments a
        LEFT JOIN appointment_types at ON a.type_id = at.type_id
        LEFT JOIN appointment_type_groups atg ON at.type_id = atg.type_id
        WHERE a.appointment_id = ?
        GROUP BY a.appointment_id
    ");
    $stmt->execute([$appointment_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if(!$appointment) {
        http_response_code(404);
        echo json_encode(["message" => "Appointment not found"]);
        exit();
    }
    
    // Parse Gruppen-IDs
    $group_ids = $appointment['group_ids'] ? explode(',', $appointment['group_ids']) : [];
    
    if(empty($group_ids)) {
        echo json_encode([
            'appointment' => $appointment,
            'members' => []
        ]);
        exit();
    }
    
    // Hole alle Mitglieder der relevanten Gruppen
    $placeholders = str_repeat('?,', count($group_ids) - 1) . '?';
    $stmt = $db->prepare("
        SELECT DISTINCT 
            m.member_id,
            m.name,
            m.surname,
            m.member_number,
            GROUP_CONCAT(DISTINCT mg.group_name ORDER BY mg.group_name SEPARATOR ', ') as groups,
            r.record_id,
            r.arrival_time,
            r.checkin_source,
            r.status
        FROM members m
        JOIN member_group_assignments mga ON m.member_id = mga.member_id
        JOIN member_groups mg ON mga.group_id = mg.group_id
        LEFT JOIN records r ON m.member_id = r.member_id 
            AND r.appointment_id = ?
        WHERE mga.group_id IN ($placeholders)
            AND m.active = 1
        GROUP BY m.member_id
        ORDER BY m.surname, m.name
    ");
    
    $params = array_merge([$appointment_id], $group_ids);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'appointment' => $appointment,
        'members' => $members
    ]);
}