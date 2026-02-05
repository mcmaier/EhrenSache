<?php

// ============================================
// MY_DATA - DSGVO Datenexport für User
// ============================================

function handleMyData($db, $database, $request_method, $authUserId)
{
    if($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }    
    
    // User authentifiziert? (Session oder Token)
    if(!$authUserId) {
        http_response_code(401);
        echo json_encode(["message" => "Unauthorized"]);
        exit();
    }

    $prefix = $database->table('');
    
    // Hole member_id des Users
    $stmt = $db->prepare("SELECT member_id FROM {$prefix}users WHERE user_id = ?");
    $stmt->execute([$authUserId]);
    $member_id = $stmt->fetchColumn();
    
    if(!$member_id) {
        http_response_code(404);
        echo json_encode(["message" => "Kein Mitglied mit diesem Benutzer verknüpft"]);
        exit();
    }
    
    // Sammle alle Daten
    $data = [];
    
    // 1. User-Daten
    $stmt = $db->prepare("
        SELECT user_id, email, role, is_active, created_at, member_id
        FROM {$prefix}users 
        WHERE user_id = ?
    ");
    $stmt->execute([$authUserId]);
    $data['user'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 2. Member-Stammdaten
    $stmt = $db->prepare("
        SELECT member_id, name, surname, member_number, active, created_at
        FROM {$prefix}members 
        WHERE member_id = ?
    ");
    $stmt->execute([$member_id]);
    $data['member'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 3. Gruppenzugehörigkeiten
    $stmt = $db->prepare("
        SELECT mg.group_name, mg.description
        FROM {$prefix}member_group_assignments mga
        JOIN {$prefix}member_groups mg ON mga.group_id = mg.group_id
        WHERE mga.member_id = ?
    ");
    $stmt->execute([$member_id]);
    $data['groups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Anwesenheitsdaten
    $stmt = $db->prepare("
        SELECT 
            r.record_id,
            r.arrival_time,
            r.created_at,
            r.status,
            a.title as appointment_title,
            a.date as appointment_date
        FROM {$prefix}records r
        LEFT JOIN {$prefix}appointments a ON r.appointment_id = a.appointment_id
        WHERE r.member_id = ?
        ORDER BY r.arrival_time DESC
    ");
    $stmt->execute([$member_id]);
    $data['records'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Ausnahmen/Anträge
    $stmt = $db->prepare("
        SELECT 
            e.exception_id,
            e.exception_type,
            e.reason,
            e.requested_arrival_time,
            e.status,
            e.created_at,
            e.approved_at,
            a.title as appointment_title,
            a.date as appointment_date
        FROM {$prefix}exceptions e
        LEFT JOIN {$prefix}appointments a ON e.appointment_id = a.appointment_id
        WHERE e.member_id = ?
        ORDER BY e.created_at DESC
    ");
    $stmt->execute([$member_id]);
    $data['exceptions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. Mitgliedschaftszeiträume
    $stmt = $db->prepare("
        SELECT start_date, end_date, status
        FROM {$prefix}membership_dates
        WHERE member_id = ?
        ORDER BY start_date DESC
    ");
    $stmt->execute([$member_id]);
    $data['membership_dates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 7. Metadaten
    $data['export_info'] = [
        'export_date' => date('Y-m-d H:i:s'),
        'format' => 'JSON',
        'gdpr_compliant' => true,
        'data_subject_rights' => 'Art. 15, 20 DSGVO'
    ];
    
    // Format als Parameter (JSON oder CSV)
    $format = $_GET['format'] ?? 'json';
    
    if($format === 'csv') {
        exportAsCSV($data);
    } else {
        // JSON (Standard)
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="meine_daten_' . date('Y-m-d') . '.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

function exportAsCSV($data) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="meine_daten_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM für Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Stammdaten
    fputcsv($output, ['=== STAMMDATEN ===']);
    fputcsv($output, ['Feld', 'Wert']);
    fputcsv($output, ['Name', $data['member']['name'] . ' ' . $data['member']['surname']]);
    fputcsv($output, ['Mitgliedsnummer', $data['member']['member_number'] ?? '-']);
    fputcsv($output, ['E-Mail', $data['user']['email']]);
    fputcsv($output, ['Rolle', $data['user']['role']]);
    fputcsv($output, ['Aktiv', $data['member']['active'] ? 'Ja' : 'Nein']);
    fputcsv($output, []);
    
    // Gruppen
    fputcsv($output, ['=== GRUPPEN ===']);
    fputcsv($output, ['Gruppe']);
    foreach($data['groups'] as $group) {
        fputcsv($output, [$group['group_name']]);
    }
    fputcsv($output, []);
    
    // Anwesenheiten
    fputcsv($output, ['=== ANWESENHEITEN ===']);
    fputcsv($output, ['Datum', 'Ankunft', 'Termin', 'Status']);
    foreach($data['records'] as $record) {
        fputcsv($output, [
            date('d.m.Y', strtotime($record['arrival_time'])),
            date('H:i', strtotime($record['arrival_time'])),
            $record['appointment_title'] ?? '-',
            $record['status'] ?? ''
        ]);
    }
    fputcsv($output, []);
    
    // Ausnahmen
    fputcsv($output, ['=== AUSNAHMEN/ANTRÄGE ===']);
    fputcsv($output, ['Datum', 'Typ', 'Grund', 'Status', 'Termin']);
    foreach($data['exceptions'] as $exception) {
        fputcsv($output, [
            date('d.m.Y', strtotime($exception['created_at'])),
            $exception['exception_type'],
            $exception['reason'],
            $exception['status'],
            $exception['appointment_title'] ?? '-'
        ]);
    }
    
    fclose($output);
}

?>