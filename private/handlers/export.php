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
// EXPORT Handler
// ============================================

function handleExport($db, $request_method, $authUserRole) {
    if ($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }
    
    requireAdminOrManager();
    /*
    // Nur Admins dürfen exportieren
    if ($authUserRole !== 'admin') {
        http_response_code(403);
        echo json_encode(["message" => "Admin access required"]);
        exit();
    }*/
    
    $type = $_GET['type'] ?? 'members';
    
    switch($type) {
        case 'members':
            exportMembers($db);
            break;
        case 'appointments':
            exportAppointments($db);
            break;
        case 'records':
            exportRecords($db);
            break;
        default:
            http_response_code(400);
            echo json_encode(["message" => "Invalid export type"]);
    }
}

function exportMembers($db) {
    // Hole alle Mitglieder mit Gruppenzuordnungen
    $stmt = $db->query("
        SELECT m.member_id, m.name, m.surname, m.member_number, m.active,
               GROUP_CONCAT(g.group_name SEPARATOR '|') as group_names
        FROM members m
        LEFT JOIN member_group_assignments mga ON m.member_id = mga.member_id
        LEFT JOIN member_groups g ON mga.group_id = g.group_id
        GROUP BY m.member_id
        ORDER BY m.surname, m.name
    ");
    
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // CSV Header setzen
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members_export_' . date('Y-m-d') . '.csv"');
    
    // UTF-8 BOM für Excel
    echo "\xEF\xBB\xBF";
    
    // CSV Output
    $output = fopen('php://output', 'w');
    
    // Header-Zeile
    fputcsv($output, ['name', 'surname', 'member_number', 'active', 'groups'], ';');
    
    // Daten
    foreach ($members as $member) {
        fputcsv($output, [
            $member['name'],
            $member['surname'],
            $member['member_number'],
            $member['active'],
            $member['group_names'] ?? ''
        ], ';');
    }
    
    fclose($output);
    exit();
}

function exportAppointments($db) {
    $year = $_GET['year'] ?? date('Y');
    
    $stmt = $db->prepare("
        SELECT a.appointment_id, a.date, a.start_time, a.title, a.description,
               at.type_name,
               GROUP_CONCAT(DISTINCT g.group_name SEPARATOR '|') as group_names
        FROM appointments a
        LEFT JOIN appointment_types at ON a.type_id = at.type_id
        LEFT JOIN appointment_type_groups atg ON at.type_id = atg.type_id
        LEFT JOIN member_groups g ON atg.group_id = g.group_id
        WHERE YEAR(a.date) = ?
        GROUP BY a.appointment_id
        ORDER BY a.date, a.start_time
    ");
    $stmt->execute([$year]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="appointments_export_' . $year . '.csv"');
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['date', 'start_time', 'title', 'type', 'groups', 'description'], ';');
    
    foreach ($appointments as $apt) {
        fputcsv($output, [
            $apt['date'],
            $apt['start_time'],
            $apt['title'],
            $apt['type_name'],
            $apt['group_names'] ?? '',
            $apt['description']
        ], ';');
    }
    
    fclose($output);
    exit();
}

function exportRecords($db) {
    $year = $_GET['year'] ?? date('Y');
    
    $stmt = $db->prepare("
        SELECT r.record_id, r.arrival_time, r.status, r.checkin_source,
               m.name, m.surname, m.member_number,
               a.date as appointment_date, a.title as appointment_title
        FROM records r
        JOIN members m ON r.member_id = m.member_id
        JOIN appointments a ON r.appointment_id = a.appointment_id
        WHERE YEAR(a.date) = ?
        ORDER BY a.date, r.arrival_time
    ");
    $stmt->execute([$year]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="records_export_' . $year . '.csv"');
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['member_name', 'member_surname', 'member_number', 'appointment_date', 'appointment_title', 'arrival_time', 'status', 'checkin_source'], ';');
    
    foreach ($records as $record) {
        fputcsv($output, [
            $record['name'],
            $record['surname'],
            $record['member_number'],
            $record['appointment_date'],
            $record['appointment_title'],
            $record['arrival_time'],
            $record['status'],
            $record['checkin_source']
        ], ';');
    }
    
    fclose($output);
    exit();
}
?>