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
// WORK_SESSIONS Controller
// ============================================
function handleWorkSessions($db, $database, $method, $id, $authUserId, $authUserRole, $authMemberId, $isTokenAuth) {

    requireWorktimeEnabled($db, $database);

    // Geräte haben keinen Zugriff: eine Station kann keine Tätigkeitsart
    // erfragen, und Geräte-Timer erzeugten Karteileichen.
    if(isDevice()) {
        http_response_code(403);
        echo json_encode(["message" => "Devices cannot access work sessions"]);
        return;
    }

    switch($method) {
        case 'GET':
            workSessionsGet($db, $database, $id, $authUserRole, $authMemberId);
            break;

        default:
            http_response_code(405);
            echo json_encode(["message" => "Method not allowed"]);
    }
}

/** Basis-SELECT mit den Feldern, die jede Sicht braucht. */
function workSessionsSelect($prefix) {
    return "SELECT ws.*,
                   at.activity_name, at.color, at.verification,
                   m.name, m.surname, m.member_number,
                   a.title AS appointment_title, a.date AS appointment_date
            FROM {$prefix}work_sessions ws
            LEFT JOIN {$prefix}activity_types at ON ws.activity_id = at.activity_id
            LEFT JOIN {$prefix}members m         ON ws.member_id   = m.member_id
            LEFT JOIN {$prefix}appointments a    ON ws.appointment_id = a.appointment_id";
}

function workSessionsGet($db, $database, $id, $authUserRole, $authMemberId) {
    $prefix = $database->table('');

    // Einzelsatz
    if($id) {
        $stmt = $db->prepare(workSessionsSelect($prefix) . " WHERE ws.session_id = ?");
        $stmt->execute([$id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$session) {
            http_response_code(404);
            echo json_encode(["message" => "Session not found"]);
            return;
        }

        if(!isAdminOrManager() && (int)$session['member_id'] !== (int)$authMemberId) {
            http_response_code(403);
            echo json_encode(["message" => "Access denied"]);
            return;
        }

        echo json_encode(withDuration($session));
        return;
    }

    // Eigene laufende Sitzung
    if(!empty($_GET['running'])) {
        if(!$authMemberId) {
            echo json_encode(null);
            return;
        }
        $running = getRunningSession($db, $database, (int)$authMemberId);
        echo json_encode($running === null ? null : withDuration($running));
        return;
    }

    // Liste
    $memberId = $_GET['member_id'] ?? null;

    if(!isAdminOrManager()) {
        // Normale Nutzer sehen ausschliesslich eigene Sitzungen.
        // Manager und Admin sehen alle — konsistent mit records, exceptions
        // und statistics, wo es ebenfalls keine Gruppengrenze fuer Manager gibt.
        if(!$authMemberId) {
            echo json_encode([]);
            return;
        }
        $memberId = $authMemberId;
    }

    $sql    = workSessionsSelect($prefix) . " WHERE 1=1";
    $params = [];

    if($memberId) {
        $sql .= " AND ws.member_id = ?";
        $params[] = $memberId;
    }
    if(!empty($_GET['year'])) {
        $sql .= " AND YEAR(ws.start_time) = ?";
        $params[] = $_GET['year'];
    }
    if(!empty($_GET['month']) && !empty($_GET['year'])) {
        $sql .= " AND MONTH(ws.start_time) = ?";
        $params[] = $_GET['month'];
    }
    if(!empty($_GET['from_date'])) {
        $sql .= " AND ws.start_time >= ?";
        $params[] = $_GET['from_date'] . ' 00:00:00';
    }
    if(!empty($_GET['to_date'])) {
        $sql .= " AND ws.start_time <= ?";
        $params[] = $_GET['to_date'] . ' 23:59:59';
    }
    if(!empty($_GET['activity_id'])) {
        $sql .= " AND ws.activity_id = ?";
        $params[] = $_GET['activity_id'];
    }
    if(!empty($_GET['appointment_id'])) {
        $sql .= " AND ws.appointment_id = ?";
        $params[] = $_GET['appointment_id'];
    }
    if(!empty($_GET['status'])) {
        $sql .= " AND ws.status = ?";
        $params[] = $_GET['status'];
    }
    if(isset($_GET['open']) && $_GET['open'] == '1') {
        $sql .= " AND ws.end_time IS NULL";
    }

    $sql .= " ORDER BY ws.start_time DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $rows = array_map('withDuration', $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode($rows);
}

?>
