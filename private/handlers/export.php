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

function handleExport($db, $database, $request_method, $authUserRole) {
    if ($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }
    
    requireAdminOrManager();
    
    $type = $_GET['type'] ?? 'members';
    
    switch($type) {
        case 'members':
            exportMembers($db, $database);
            break;
        case 'appointments':
            exportAppointments($db, $database);
            break;
        case 'records':
            exportRecords($db, $database);
            break;
        case 'worktime_member':
            exportWorktimeMember($db, $database);
            break;
        case 'worktime_activity':
            exportWorktimeActivity($db, $database);
            break;
        default:
            http_response_code(400);
            echo json_encode(["message" => "Invalid export type"]);
    }
}

function exportMembers($db, $database) {
    $prefix = $database->table('');

    // Hole alle Mitglieder mit Gruppenzuordnungen
    $stmt = $db->query("
        SELECT m.member_id, m.name, m.surname, m.member_number, m.active,
               GROUP_CONCAT(g.group_name SEPARATOR '|') as group_names
        FROM {$prefix}members m
        LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
        LEFT JOIN {$prefix}member_groups g ON mga.group_id = g.group_id
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

function exportAppointments($db, $database) {
    $year = $_GET['year'] ?? date('Y');

    $prefix = $database->table('');
    
    $stmt = $db->prepare("
        SELECT a.appointment_id, a.date, a.start_time, a.title, a.description,
               at.type_name,
               GROUP_CONCAT(DISTINCT g.group_name SEPARATOR '|') as group_names
        FROM {$prefix}appointments a
        LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
        LEFT JOIN {$prefix}appointment_type_groups atg ON at.type_id = atg.type_id
        LEFT JOIN {$prefix}member_groups g ON atg.group_id = g.group_id
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

function exportRecords($db, $database) {
    $year = $_GET['year'] ?? date('Y');

    $prefix = $database->table('');
    
    $stmt = $db->prepare("
        SELECT r.record_id, r.arrival_time, r.status, r.checkin_source,
               m.name, m.surname, m.member_number,
               a.date as appointment_date, a.title as appointment_title
        FROM {$prefix}records r
        JOIN {$prefix}members m ON r.member_id = m.member_id
        JOIN {$prefix}appointments a ON r.appointment_id = a.appointment_id
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

/** Bezeichnung des Nachweisgrads fuer die CSV. */
function worktimeProofLabel(string $proof): string
{
    switch ($proof) {
        case 'hours': return 'stundenbelegt';
        case 'start': return 'teilbelegt';
        default:      return 'unbelegt';
    }
}

/**
 * Stundennachweis je Person: eine Zeile pro Sitzung, mit Nachweisgrad.
 * Grundlage fuer Ehrenamtskarte und Bescheinigung.
 */
function exportWorktimeMember($db, $database) {
    requireWorktimeEnabled($db, $database);

    $year     = $_GET['year'] ?? date('Y');
    $memberId = $_GET['member_id'] ?? null;
    $prefix   = $database->table('');
    $duration = worktimeDurationExpression();
    $proof    = worktimeProofExpression();

    $where  = "ws.status = 'confirmed' AND ws.end_time IS NOT NULL AND YEAR(ws.start_time) = ?";
    $params = [$year];

    if ($memberId) {
        $where   .= " AND ws.member_id = ?";
        $params[] = $memberId;
    }

    $stmt = $db->prepare("
        SELECT m.name, m.surname, m.member_number,
               at.activity_name,
               ws.start_time, ws.end_time, ws.break_minutes,
               {$duration} AS minutes,
               {$proof}    AS proof,
               ws.start_location_name, ws.end_location_name,
               ws.note, a.title AS appointment_title
        FROM {$prefix}work_sessions ws
        LEFT JOIN {$prefix}members m         ON ws.member_id     = m.member_id
        LEFT JOIN {$prefix}activity_types at ON ws.activity_id   = at.activity_id
        LEFT JOIN {$prefix}appointments a    ON ws.appointment_id = a.appointment_id
        WHERE {$where}
        ORDER BY m.surname, m.name, ws.start_time
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stundennachweis_' . $year . '.csv"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['member_name', 'member_surname', 'member_number', 'activity',
                      'start_time', 'end_time', 'break_minutes', 'minutes', 'hours',
                      'proof', 'start_location', 'end_location', 'appointment', 'note'], ';');

    $sums = [];
    foreach ($rows as $r) {
        $key = $r['member_number'] . '|' . $r['surname'] . '|' . $r['name'];
        $sums[$key] = ($sums[$key] ?? 0) + (int) $r['minutes'];

        fputcsv($output, [
            $r['name'], $r['surname'], $r['member_number'],
            $r['activity_name'],
            $r['start_time'], $r['end_time'], $r['break_minutes'],
            $r['minutes'], number_format($r['minutes'] / 60, 2, ',', ''),
            worktimeProofLabel($r['proof']),
            $r['start_location_name'], $r['end_location_name'],
            $r['appointment_title'], $r['note'],
        ], ';');
    }

    // Jahressummen je Person -- das ist die Zahl, die in eine Bescheinigung geht
    fputcsv($output, [], ';');
    fputcsv($output, ['SUMMEN'], ';');
    fputcsv($output, ['member_number', 'member_surname', 'member_name', 'minutes', 'hours'], ';');

    foreach ($sums as $key => $minutes) {
        [$number, $surname, $name] = explode('|', $key);
        fputcsv($output, [$number, $surname, $name, $minutes,
                          number_format($minutes / 60, 2, ',', '')], ';');
    }

    fclose($output);
    exit();
}

/**
 * Summen je Taetigkeitsart, getrennt nach Nachweisgrad.
 * Grundlage fuer den Verwendungsnachweis gegenueber Foerdergebern: Sichtbar
 * ist, welcher Teil der Summe belegt ist, statt einer Zahl ohne Qualitaet.
 */
function exportWorktimeActivity($db, $database) {
    requireWorktimeEnabled($db, $database);

    $year = (int) ($_GET['year'] ?? date('Y'));
    $rows = worktimeByActivity($db, $database, $year);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="taetigkeiten_' . $year . '.csv"');
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, ['activity', 'verification', 'proof', 'sessions', 'members',
                      'minutes', 'hours'], ';');

    $total = 0;
    foreach ($rows as $r) {
        $total += (int) $r['minutes'];
        fputcsv($output, [
            $r['activity_name'], $r['verification'],
            worktimeProofLabel($r['proof']),
            $r['sessions'], $r['members'],
            $r['minutes'], number_format($r['minutes'] / 60, 2, ',', ''),
        ], ';');
    }

    fputcsv($output, [], ';');
    fputcsv($output, ['GESAMT', '', '', '', '', $total,
                      number_format($total / 60, 2, ',', '')], ';');

    fclose($output);
    exit();
}

?>
