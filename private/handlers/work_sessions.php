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

        case 'POST':
            $data   = json_decode(file_get_contents("php://input"));
            $action = $data->action ?? null;

            switch($action) {
                case 'start':
                    workSessionStart($db, $database, $data, $authUserId, $authMemberId);
                    break;
                case 'pause':
                    workSessionPause($db, $database, $data, $authUserId, $authMemberId);
                    break;
                case 'resume':
                    workSessionResume($db, $database, $data, $authUserId, $authMemberId);
                    break;
                case 'stop':
                    workSessionStop($db, $database, $data, $authUserId, $authMemberId);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(["message" => "Unknown or missing action",
                                      "allowed" => ['start', 'pause', 'resume', 'stop']]);
            }
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

/**
 * Bestimmt das Mitglied, für das gebucht wird.
 * Admin und Manager dürfen ein fremdes member_id angeben, alle anderen nicht.
 */
function workSessionTargetMember($data, $authMemberId) {
    if(isAdminOrManager() && !empty($data->member_id)) {
        return (int)$data->member_id;
    }

    return $authMemberId ? (int)$authMemberId : null;
}

function workSessionStart($db, $database, $data, $authUserId, $authMemberId) {
    $prefix   = $database->table('');
    $memberId = workSessionTargetMember($data, $authMemberId);

    if(!$memberId) {
        http_response_code(403);
        echo json_encode(["message" => "No member linked to your account",
                          "hint"    => "Contact administrator"]);
        return;
    }

    if(empty($data->activity_id)) {
        http_response_code(400);
        echo json_encode(["message" => "activity_id is required"]);
        return;
    }

    // Tätigkeitsart muss existieren und nutzbar sein
    $stmt = $db->prepare("SELECT activity_id, is_active FROM {$prefix}activity_types WHERE activity_id = ?");
    $stmt->execute([(int)$data->activity_id]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$activity) {
        http_response_code(400);
        echo json_encode(["message" => "Unknown activity_id"]);
        return;
    }
    if(!$activity['is_active']) {
        http_response_code(400);
        echo json_encode(["message" => "Activity type is retired"]);
        return;
    }

    // Termin prüfen, falls angegeben
    $appointmentId = null;
    if(!empty($data->appointment_id)) {
        $stmt = $db->prepare("SELECT appointment_id FROM {$prefix}appointments WHERE appointment_id = ?");
        $stmt->execute([(int)$data->appointment_id]);
        if(!$stmt->fetchColumn()) {
            http_response_code(400);
            echo json_encode(["message" => "Unknown appointment_id"]);
            return;
        }
        $appointmentId = (int)$data->appointment_id;
    }

    // Bereits laufende Sitzung?
    $running = getRunningSession($db, $database, $memberId);
    if($running !== null) {
        http_response_code(409);
        echo json_encode([
            "message" => "A session is already running",
            "session" => withDuration($running)
        ]);
        return;
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("INSERT INTO {$prefix}work_sessions
                              (member_id, activity_id, appointment_id, start_time,
                               status, source, created_by)
                              VALUES (?, ?, ?, NOW(), 'confirmed', 'timer', ?)");
        $stmt->execute([$memberId, (int)$data->activity_id, $appointmentId, $authUserId]);
        $sessionId = (int)$db->lastInsertId();

        // Bei Terminbezug den Anwesenheits-Eintrag miterzeugen.
        // ON DUPLICATE KEY UPDATE mit einer Zuweisung auf sich selbst: ein
        // frueherer Check-in behaelt seine arrival_time.
        if($appointmentId !== null) {
            $db->prepare("INSERT INTO {$prefix}records
                          (member_id, appointment_id, arrival_time, status, checkin_source)
                          VALUES (?, ?, NOW(), 'present', 'timer')
                          ON DUPLICATE KEY UPDATE record_id = record_id")
               ->execute([$memberId, $appointmentId]);
        }

        logSessionChange($db, $database, $sessionId, $authUserId, 'create', [
            'source'      => ['old' => null, 'new' => 'timer'],
            'activity_id' => ['old' => null, 'new' => (int)$data->activity_id],
        ]);

        $db->commit();

        $session = getRunningSession($db, $database, $memberId);

        http_response_code(201);
        echo json_encode(["message" => "Session started",
                          "session" => $session === null ? null : withDuration($session)]);

    } catch (PDOException $e) {
        if($db->inTransaction()) {
            $db->rollBack();
        }
        // Der Unique-Index auf active_member greift, wenn zwei Anfragen
        // gleichzeitig starten wollen.
        http_response_code(409);
        echo json_encode(["message" => "A session is already running"]);
    }
}

/**
 * Holt die laufende Sitzung oder antwortet mit 409.
 * Liefert null, wenn bereits geantwortet wurde.
 */
function workSessionRequireRunning($db, $database, $data, $authMemberId) {
    $memberId = workSessionTargetMember($data, $authMemberId);

    if(!$memberId) {
        http_response_code(403);
        echo json_encode(["message" => "No member linked to your account"]);
        return null;
    }

    $running = getRunningSession($db, $database, $memberId);
    if($running === null) {
        http_response_code(409);
        echo json_encode(["message" => "No running session"]);
        return null;
    }

    return $running;
}

function workSessionPause($db, $database, $data, $authUserId, $authMemberId) {
    $prefix  = $database->table('');
    $running = workSessionRequireRunning($db, $database, $data, $authMemberId);
    if($running === null) { return; }

    // Bereits pausiert: idempotent, keine Aenderung
    if(!empty($running['break_started_at'])) {
        echo json_encode(["message" => "Already paused",
                          "session" => withDuration($running)]);
        return;
    }

    $db->prepare("UPDATE {$prefix}work_sessions SET break_started_at = NOW() WHERE session_id = ?")
       ->execute([$running['session_id']]);

    logSessionChange($db, $database, (int)$running['session_id'], $authUserId, 'update',
                     ['break_started_at' => ['old' => null, 'new' => 'NOW()']]);

    $updated = getRunningSession($db, $database, (int)$running['member_id']);
    echo json_encode(["message" => "Paused", "session" => withDuration($updated)]);
}

function workSessionResume($db, $database, $data, $authUserId, $authMemberId) {
    $prefix  = $database->table('');
    $running = workSessionRequireRunning($db, $database, $data, $authMemberId);
    if($running === null) { return; }

    // Keine laufende Pause: idempotent, keine Aenderung
    if(empty($running['break_started_at'])) {
        echo json_encode(["message" => "Not paused",
                          "session" => withDuration($running)]);
        return;
    }

    // Pausendauer serverseitig aufaddieren, damit kein Clientwert einfliesst
    $db->prepare("UPDATE {$prefix}work_sessions
                  SET break_minutes = break_minutes
                      + GREATEST(0, TIMESTAMPDIFF(MINUTE, break_started_at, NOW())),
                      break_started_at = NULL
                  WHERE session_id = ?")
       ->execute([$running['session_id']]);

    $updated = getRunningSession($db, $database, (int)$running['member_id']);

    logSessionChange($db, $database, (int)$running['session_id'], $authUserId, 'update',
                     ['break_minutes' => ['old' => (int)$running['break_minutes'],
                                          'new' => (int)$updated['break_minutes']]]);

    echo json_encode(["message" => "Resumed", "session" => withDuration($updated)]);
}

function workSessionStop($db, $database, $data, $authUserId, $authMemberId) {
    $prefix  = $database->table('');
    $running = workSessionRequireRunning($db, $database, $data, $authMemberId);
    if($running === null) { return; }

    $requireNote = worktimeSetting($db, $database, 'worktime_require_note', '0') === '1';
    $note        = isset($data->note) ? trim((string)$data->note) : '';

    if($requireNote && $note === '') {
        http_response_code(400);
        echo json_encode(["message" => "A note is required to stop a session"]);
        return;
    }

    // Eine laufende Pause wird zuerst beendet und aufaddiert.
    $db->prepare("UPDATE {$prefix}work_sessions
                  SET break_minutes = break_minutes + IF(break_started_at IS NULL, 0,
                          GREATEST(0, TIMESTAMPDIFF(MINUTE, break_started_at, NOW()))),
                      break_started_at = NULL,
                      end_time = NOW(),
                      note = COALESCE(NULLIF(?, ''), note)
                  WHERE session_id = ?")
       ->execute([$note, $running['session_id']]);

    $stmt = $db->prepare(workSessionsSelect($prefix) . " WHERE ws.session_id = ?");
    $stmt->execute([$running['session_id']]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    logSessionChange($db, $database, (int)$running['session_id'], $authUserId, 'update',
                     ['end_time' => ['old' => null, 'new' => $session['end_time']]]);

    echo json_encode(["message" => "Stopped", "session" => withDuration($session)]);
}

?>
