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
                case null:
                    workSessionCreateManual($db, $database, $data, $authUserId, $authMemberId);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(["message" => "Unknown action",
                                      "allowed" => ['start', 'pause', 'resume', 'stop']]);
            }
            break;

        case 'PUT':
            if(!$id) {
                http_response_code(400);
                echo json_encode(["message" => "id is required"]);
                return;
            }
            $data = json_decode(file_get_contents("php://input"));
            workSessionUpdate($db, $database, $id, $data, $authUserId, $authMemberId);
            break;

        case 'DELETE':
            requireAdmin();
            if(!$id) {
                http_response_code(400);
                echo json_encode(["message" => "id is required"]);
                return;
            }
            workSessionDelete($db, $database, $id, $authUserId);
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
        $running = getRunningSessionChecked($db, $database, (int)$authMemberId, null);
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
    $stmt = $db->prepare("SELECT activity_id, is_active, verification
                          FROM {$prefix}activity_types WHERE activity_id = ?");
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

    // Ortsnachweis. Ein mitgesendeter Code wird IMMER aufgeloest und
    // festgehalten, auch wenn die Taetigkeitsart ihn nicht verlangt (E10):
    // festzuhalten, welche Stunden ortsbelegt sind, ist wertvoller als ein
    // Gate, das sich durch Wahl einer anderen Taetigkeitsart umgehen laesst.
    $startLocation = null;
    $verification  = $activity['verification'] ?? 'none';
    $code          = isset($data->totp_code) ? trim((string)$data->totp_code) : '';

    if($code !== '') {
        if(countTotpLocations($db, $database) === 0) {
            http_response_code(409);
            echo json_encode(["message" => "No TOTP station configured",
                              "hint"    => "Ask an administrator to set one up"]);
            return;
        }

        $resolved = resolveTotpLocation($db, $database, $code);
        if($resolved === null) {
            http_response_code(401);
            echo json_encode(["message" => "Invalid or expired TOTP code"]);
            return;
        }
        $startLocation = $resolved['location_name'];

    } elseif($verification !== 'none') {
        // Kein force-Weg beim Start: Ein unbelegter Start bei
        // nachweispflichtiger Taetigkeit soll gar nicht erst als Timer laufen.
        // Wer keinen Code hat, erfasst nachtraeglich — das geht in die Freigabe.
        if(countTotpLocations($db, $database) === 0) {
            http_response_code(409);
            echo json_encode(["message" => "No TOTP station configured",
                              "hint"    => "This activity type requires a location proof"]);
            return;
        }

        http_response_code(403);
        echo json_encode([
            "message"      => "This activity type requires a TOTP code to start",
            "verification" => $verification,
            "hint"         => "Scan the station code, or record the time afterwards"
        ]);
        return;
    }

    // Termin prüfen, falls angegeben
    $appointmentId   = null;
    $appointmentToday = false;

    if(!empty($data->appointment_id)) {
        $stmt = $db->prepare("SELECT appointment_id, date FROM {$prefix}appointments
                              WHERE appointment_id = ?");
        $stmt->execute([(int)$data->appointment_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

        if(!$appointment) {
            http_response_code(400);
            echo json_encode(["message" => "Unknown appointment_id"]);
            return;
        }

        $appointmentId    = (int)$appointment['appointment_id'];
        $appointmentToday = ($appointment['date'] === date('Y-m-d'));
    }

    // Bereits laufende Sitzung? Eine ueberfaellige wird dabei geschlossen,
    // damit ein vergessener Stopp den naechsten Start nicht blockiert.
    $running = getRunningSessionChecked($db, $database, $memberId, $authUserId);
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
                               start_location_name, status, source, created_by)
                              VALUES (?, ?, ?, NOW(), ?, 'confirmed', 'timer', ?)");
        $stmt->execute([$memberId, (int)$data->activity_id, $appointmentId,
                        $startLocation, $authUserId]);
        $sessionId = (int)$db->lastInsertId();

        // Bei Terminbezug den Anwesenheits-Eintrag miterzeugen.
        // ON DUPLICATE KEY UPDATE mit einer Zuweisung auf sich selbst: ein
        // frueherer Check-in behaelt seine arrival_time.
        // Der Check-in entsteht NUR, wenn der Termin heute ist. Zeit laesst sich
        // auch fuer eine kuenftige Veranstaltung erfassen — Buehnenaufbau am
        // Donnerstag fuer das Konzert am Samstag. Daraus einen Check-in zu
        // machen wuerde das Mitglied als anwesend bei etwas fuehren, das noch
        // gar nicht stattgefunden hat.
        if($appointmentId !== null && $appointmentToday) {
            // Ist der Start ortsbelegt, ist der Check-in ein user_totp und
            // traegt den Stationsnamen — sonst ein schlichter timer-Eintrag.
            $checkinSource = $startLocation !== null ? 'user_totp' : 'timer';

            $db->prepare("INSERT INTO {$prefix}records
                          (member_id, appointment_id, arrival_time, status,
                           checkin_source, location_name)
                          VALUES (?, ?, NOW(), 'present', ?, ?)
                          ON DUPLICATE KEY UPDATE record_id = record_id")
               ->execute([$memberId, $appointmentId, $checkinSource, $startLocation]);
        }

        logSessionChange($db, $database, $sessionId, $authUserId, 'create', [
            'source'              => ['old' => null, 'new' => 'timer'],
            'activity_id'         => ['old' => null, 'new' => (int)$data->activity_id],
            'start_location_name' => ['old' => null, 'new' => $startLocation],
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

        // Nur der Unique-Index auf active_member ist ein Konflikt — er greift,
        // wenn zwei Anfragen gleichzeitig starten wollen. Jeder andere
        // Datenbankfehler als 409 auszugeben, verbirgt echte Fehler hinter
        // einer plausiblen Meldung.
        if($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(["message" => "A session is already running"]);
            return;
        }

        error_log("work_sessions start failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["message" => "Session konnte nicht gestartet werden"]);
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

    $running = getRunningSessionChecked($db, $database, $memberId, null);
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

    // Ortsnachweis beim Beenden
    $endLocation  = null;
    $verification = $running['verification'] ?? 'none';
    $code         = isset($data->totp_code) ? trim((string)$data->totp_code) : '';
    $force        = !empty($data->force);
    $downgrade    = false;

    if($code !== '') {
        $resolved = resolveTotpLocation($db, $database, $code);
        if($resolved === null) {
            http_response_code(401);
            echo json_encode(["message" => "Invalid or expired TOTP code"]);
            return;
        }
        $endLocation = $resolved['location_name'];

    } elseif($verification === 'start_end') {
        if(!$force) {
            // Anders als beim Start gibt es hier einen Ausweg: Ist die Station
            // abgebaut oder endet der Einsatz woanders, duerfte das Mitglied
            // sonst gar nicht stoppen und saesse bis zum automatischen
            // Abschluss in einer laufenden Sitzung fest.
            http_response_code(409);
            echo json_encode([
                "message" => "This activity type requires a TOTP code to stop",
                "hint"    => "Send force: true to stop without one; the entry then needs approval"
            ]);
            return;
        }
        // Ohne Nachweis beendet: der Eintrag zaehlt erst nach Freigabe.
        $downgrade = true;
    }

    // Eine laufende Pause wird zuerst beendet und aufaddiert.
    $db->prepare("UPDATE {$prefix}work_sessions
                  SET break_minutes = break_minutes + IF(break_started_at IS NULL, 0,
                          GREATEST(0, TIMESTAMPDIFF(MINUTE, break_started_at, NOW()))),
                      break_started_at = NULL,
                      end_time = NOW(),
                      note = COALESCE(NULLIF(?, ''), note),
                      end_location_name = ?,
                      status = IF(? = 1, 'submitted', status)
                  WHERE session_id = ?")
       ->execute([$note, $endLocation, $downgrade ? 1 : 0, $running['session_id']]);

    $stmt = $db->prepare(workSessionsSelect($prefix) . " WHERE ws.session_id = ?");
    $stmt->execute([$running['session_id']]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    $changes = ['end_time' => ['old' => null, 'new' => $session['end_time']]];
    if($endLocation !== null) {
        $changes['end_location_name'] = ['old' => null, 'new' => $endLocation];
    }
    if($downgrade) {
        $changes['status'] = ['old' => 'confirmed', 'new' => 'submitted'];
        $changes['stopped_without_proof'] = ['old' => null, 'new' => true];
    }

    logSessionChange($db, $database, (int)$running['session_id'], $authUserId, 'update', $changes);

    echo json_encode(["message" => "Stopped", "session" => withDuration($session)]);
}

function workSessionCreateManual($db, $database, $data, $authUserId, $authMemberId) {
    $prefix   = $database->table('');
    $memberId = workSessionTargetMember($data, $authMemberId);

    if(!$memberId) {
        http_response_code(403);
        echo json_encode(["message" => "No member linked to your account"]);
        return;
    }

    $requireNote = worktimeSetting($db, $database, 'worktime_require_note', '0') === '1';

    $input = [
        'activity_id'   => $data->activity_id   ?? null,
        'start_time'    => $data->start_time    ?? null,
        'end_time'      => $data->end_time      ?? null,
        'break_minutes' => $data->break_minutes ?? 0,
        'note'          => $data->note          ?? '',
    ];

    $errors = validateManualSession($input, $requireNote, time());
    if($errors !== []) {
        http_response_code(400);
        echo json_encode(["message" => "Validation failed", "errors" => $errors]);
        return;
    }

    $stmt = $db->prepare("SELECT activity_id FROM {$prefix}activity_types WHERE activity_id = ?");
    $stmt->execute([(int)$input['activity_id']]);
    if(!$stmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(["message" => "Unknown activity_id"]);
        return;
    }

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

    // Manuelle Eintraege gelten erst nach Freigabe — es sei denn, die freigebende
    // Instanz legt sie selbst an. Manager und Admin muessen sich nicht selbst
    // genehmigen; dieselbe Regel gilt bereits fuer ihre Aenderungen.
    $isApprover = isAdminOrManager();
    $source     = $isApprover ? 'admin' : 'manual';
    $status     = $isApprover ? 'confirmed' : 'submitted';

    $stmt = $db->prepare("INSERT INTO {$prefix}work_sessions
                          (member_id, activity_id, appointment_id, start_time, end_time,
                           break_minutes, note, status, source, created_by,
                           approved_by, approved_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $memberId,
        (int)$input['activity_id'],
        $appointmentId,
        date('Y-m-d H:i:s', strtotime((string)$input['start_time'])),
        date('Y-m-d H:i:s', strtotime((string)$input['end_time'])),
        (int)$input['break_minutes'],
        trim((string)$input['note']) !== '' ? trim((string)$input['note']) : null,
        $status,
        $source,
        $authUserId,
        $isApprover ? $authUserId : null,
        $isApprover ? date('Y-m-d H:i:s') : null
    ]);
    $sessionId = (int)$db->lastInsertId();

    logSessionChange($db, $database, $sessionId, $authUserId, 'create',
                     ['source' => ['old' => null, 'new' => $source],
                      'status' => ['old' => null, 'new' => $status]]);

    $stmt = $db->prepare(workSessionsSelect($prefix) . " WHERE ws.session_id = ?");
    $stmt->execute([$sessionId]);

    http_response_code(201);
    echo json_encode(["message" => "Session created",
                      "session" => withDuration($stmt->fetch(PDO::FETCH_ASSOC))]);
}

function workSessionUpdate($db, $database, $id, $data, $authUserId, $authMemberId) {
    $prefix = $database->table('');

    $stmt = $db->prepare("SELECT * FROM {$prefix}work_sessions WHERE session_id = ?");
    $stmt->execute([$id]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$before) {
        http_response_code(404);
        echo json_encode(["message" => "Session not found"]);
        return;
    }

    $isOwn      = (int)$before['member_id'] === (int)$authMemberId;
    $isApprover = isAdminOrManager();

    if(!$isOwn && !$isApprover) {
        http_response_code(403);
        echo json_encode(["message" => "Access denied"]);
        return;
    }

    $action = $data->action ?? null;

    // Freigeben / Ablehnen: nur Manager und Admin
    if($action === 'approve' || $action === 'reject') {
        if(!$isApprover) {
            http_response_code(403);
            echo json_encode(["message" => "Only managers can approve or reject"]);
            return;
        }

        $newStatus = $action === 'approve' ? 'confirmed' : 'rejected';
        $db->prepare("UPDATE {$prefix}work_sessions
                      SET status = ?, approved_by = ?, approved_at = NOW()
                      WHERE session_id = ?")
           ->execute([$newStatus, $authUserId, $id]);

        logSessionChange($db, $database, (int)$id, $authUserId, $action,
                         ['status' => ['old' => $before['status'], 'new' => $newStatus]]);

        echo json_encode(["message" => $action === 'approve' ? "Session approved" : "Session rejected"]);
        return;
    }

    // Eine laufende Sitzung wird nicht ueber diesen Weg korrigiert:
    // dafuer gibt es stop.
    if(empty($before['end_time']) && empty($data->end_time)) {
        http_response_code(409);
        echo json_encode(["message" => "Session is still running",
                          "hint"    => "Stop it before correcting"]);
        return;
    }

    // Inhaltliche Korrektur
    $requireNote = worktimeSetting($db, $database, 'worktime_require_note', '0') === '1';

    $input = [
        'activity_id'   => $data->activity_id   ?? $before['activity_id'],
        'start_time'    => $data->start_time    ?? $before['start_time'],
        'end_time'      => $data->end_time      ?? $before['end_time'],
        'break_minutes' => $data->break_minutes ?? $before['break_minutes'],
        'note'          => $data->note          ?? $before['note'],
    ];

    $errors = validateManualSession($input, $requireNote, time());
    if($errors !== []) {
        http_response_code(400);
        echo json_encode(["message" => "Validation failed", "errors" => $errors]);
        return;
    }

    // Eine Aenderung durch das Mitglied entzieht die Bestaetigung.
    // Manager und Admin sind die freigebende Instanz und muessen sich
    // nicht selbst genehmigen.
    $newStatus = $isApprover ? $before['status'] : 'submitted';

    $db->prepare("UPDATE {$prefix}work_sessions
                  SET activity_id = ?, start_time = ?, end_time = ?,
                      break_minutes = ?, note = ?, status = ?
                  WHERE session_id = ?")
       ->execute([
           (int)$input['activity_id'],
           date('Y-m-d H:i:s', strtotime((string)$input['start_time'])),
           date('Y-m-d H:i:s', strtotime((string)$input['end_time'])),
           (int)$input['break_minutes'],
           trim((string)$input['note']) !== '' ? trim((string)$input['note']) : null,
           $newStatus,
           $id
       ]);

    $stmt = $db->prepare("SELECT * FROM {$prefix}work_sessions WHERE session_id = ?");
    $stmt->execute([$id]);
    $after = $stmt->fetch(PDO::FETCH_ASSOC);

    logSessionChange($db, $database, (int)$id, $authUserId, 'update',
                     sessionChangeSet($before, $after));

    echo json_encode(["message" => "Session updated", "session" => withDuration($after)]);
}

function workSessionDelete($db, $database, $id, $authUserId) {
    $prefix = $database->table('');

    $stmt = $db->prepare("SELECT * FROM {$prefix}work_sessions WHERE session_id = ?");
    $stmt->execute([$id]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$before) {
        http_response_code(404);
        echo json_encode(["message" => "Session not found"]);
        return;
    }

    // Zuerst protokollieren, dann loeschen: der Logeintrag haelt den
    // letzten Stand fest und ueberlebt die Loeschung (kein Fremdschluessel).
    logSessionChange($db, $database, (int)$id, $authUserId, 'delete',
                     ['deleted' => ['old' => $before, 'new' => null]]);

    $db->prepare("DELETE FROM {$prefix}work_sessions WHERE session_id = ?")->execute([$id]);

    echo json_encode(["message" => "Session deleted"]);
}

/**
 * Wie getRunningSession(), schliesst aber eine ueberfaellige Sitzung zuvor ab.
 * Der Aufruf gehoert an jeden Einstiegspunkt, an dem das Mitglied aktiv wird —
 * ein Cronjob ist nicht noetig, weil eine vergessene Sitzung erst dann
 * relevant wird, wenn jemand wieder etwas tut.
 */
function getRunningSessionChecked($db, $database, $memberId, $authUserId) {
    $running = getRunningSession($db, $database, (int)$memberId);

    if($running !== null && closeStaleSession($db, $database, $running, $authUserId)) {
        return null;
    }

    return $running;
}

?>
