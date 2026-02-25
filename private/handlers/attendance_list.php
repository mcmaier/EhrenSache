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
// ATTENDANCE LIST Controller (für Manager/Admin)
// ============================================
function handleAttendanceList($db, $database, $method, $id) {

    require_once __DIR__ . '/../helpers/member_activity.php';
    $prefix = $database->table('');

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

    // Check: Ist User Admin/Manager?
    //$includeInactive = isAdminOrManager();
    $includeInactive = null;
    
    
    $appointment_id = $_GET['appointment_id'] ?? null;
    $member_id = $_GET['member_id'] ?? null;
    $year = $_GET['year'] ?? (int)date('Y');
    $appointment_type_id = $_GET['type_id'] ?? null;
    
    if((!$appointment_id && !$member_id) || ($appointment_id && $member_id)){
        http_response_code(400);
        echo json_encode(["message" => "appointment_id OR member_id required"]);
        exit();
    }
    
    //Get attendance list by appointment
    if($appointment_id)
    {
        // Hole Termin-Details mit Typ-Gruppen
        $stmt = $db->prepare("
            SELECT a.*, 
                at.type_name, 
                at.color,
                GROUP_CONCAT(DISTINCT atg.group_id) as group_ids
            FROM {$prefix}appointments a
            LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
            LEFT JOIN {$prefix}appointment_type_groups atg ON at.type_id = atg.type_id
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

        // Aktivitäts-WHERE-Clause generieren
        $activityWhere = getMemberActivityWhere('m', 'a.date', $includeInactive);        
        
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
        /*
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
            FROM {$prefix}members m
            JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
            JOIN {$prefix}member_groups mg ON mga.group_id = mg.group_id
            LEFT JOIN {$prefix}records r ON m.member_id = r.member_id 
                AND r.appointment_id = ?
            WHERE mga.group_id IN ($placeholders)
                AND m.active = 1
            GROUP BY m.member_id
            ORDER BY m.surname, m.name
        ");*/

        
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
            FROM {$prefix}members m
            JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
            JOIN {$prefix}member_groups mg ON mga.group_id = mg.group_id
            CROSS JOIN {$prefix}appointments a
            LEFT JOIN {$prefix}records r ON m.member_id = r.member_id 
                AND r.appointment_id = ?
            WHERE mga.group_id IN ($placeholders)
                AND a.appointment_id = ?
                AND ($activityWhere)
            GROUP BY m.member_id
            ORDER BY m.surname, m.name
        ");
        
        $params = array_merge([$appointment_id], $group_ids, [$appointment_id]);
        $stmt->execute($params);
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'appointment' => $appointment,
            'members' => $members
        ]);

        exit();
    }

    //Get attendance list by member
    if($member_id)
    {
        // Hole Member-Details mit seinen Gruppen-IDs
        $stmt = $db->prepare("
            SELECT m.member_id, m.name, m.surname, m.member_number,
                   GROUP_CONCAT(DISTINCT mg.group_name ORDER BY mg.group_name SEPARATOR ', ') as groups,
                   GROUP_CONCAT(DISTINCT mga.group_id) as group_ids
            FROM {$prefix}members m
            LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
            LEFT JOIN {$prefix}member_groups mg ON mga.group_id = mg.group_id
            WHERE m.member_id = ? AND m.active = 1
            GROUP BY m.member_id
        ");
        $stmt->execute([$member_id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if(!$member) {
            http_response_code(404);
            echo json_encode(["message" => "Member not found"]);
            exit();
        }            

        // Parse Member-Gruppen
        $member_group_ids = $member['group_ids'] ? explode(',', $member['group_ids']) : [];
        
        if(empty($member_group_ids)) {
            echo json_encode([
                'member' => $member,
                'year' => $year,
                'appointments' => []
            ]);
            exit();
        }
        
        // WHERE-Clause für type_id aufbauen
        
        $params = [$member_id, $year, $member_id];

        $typeCondition = '';
        if($appointment_type_id) {
            $typeCondition = 'AND a.type_id = ?';
            $params[] = $appointment_type_id;
        }

        $includeInactive = null;

        // Aktivitäts-WHERE-Clause generieren
        $activityWhere = getMemberActivityWhere('m', 'a.date', $includeInactive);    

        // Hole alle Termine des Jahres, die für die Member-Gruppen relevant sind
        $placeholders = str_repeat('?,', count($member_group_ids) - 1) . '?';
        $params = array_merge($params, $member_group_ids);

        $stmt = $db->prepare("
            SELECT 
                a.appointment_id,
                a.title,
                a.date,
                a.start_time,
                a.description,
                at.type_id,
                at.type_name,
                at.color,
                r.record_id,
                r.arrival_time,
                r.checkin_source,
                r.status,
                CASE 
                    WHEN ($activityWhere) THEN 1
                    ELSE 0
                END as member_was_active
            FROM {$prefix}appointments a
            LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
            LEFT JOIN {$prefix}appointment_type_groups atg ON at.type_id = atg.type_id
            CROSS JOIN {$prefix}members m
            LEFT JOIN {$prefix}records r ON a.appointment_id = r.appointment_id 
                AND r.member_id = ?
            WHERE YEAR(a.date) = ?
                AND m.member_id = ?
                $typeCondition
                AND atg.group_id IN ($placeholders)
                AND ($activityWhere)
            GROUP BY a.appointment_id
            ORDER BY a.date DESC, a.start_time DESC
        ");
        
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'member' => $member,
            'year' => $year,
            'appointments' => $appointments
        ]);
        
        exit();
    }
}