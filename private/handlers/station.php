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
// STATION Controller — virtuelle Station (Kiosk)
// ============================================
//
// Zwei Vertrauensmodelle, bewusst getrennt (Spec 2.1):
//   auto_checkin mit device_auth: das GERAET buergt fuer die Identitaet.
//   station:                     der SERVER prueft Mitgliedsnummer + PIN,
//                                das Geraet ist nur Tastatur und Bildschirm.
// Ein gestohlenes Kiosk-Token darf deshalb ohne PIN eines Mitglieds nichts
// bewirken; api.php laesst Kiosk-Token nur an diese Ressource.

function handleStation($db, $database, $method, $authUserId, $authUserRole, $authDeviceType)
{
    if ($authUserRole !== 'device' || $authDeviceType !== 'kiosk') {
        http_response_code(403);
        echo json_encode(["message" => "Kiosk device token required"]);
        return;
    }

    $prefix = $database->table('');

    $stmt = $db->prepare("SELECT user_id, device_name, totp_secret, is_active
                          FROM {$prefix}users WHERE user_id = ?");
    $stmt->execute([$authUserId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device || (int) $device['is_active'] !== 1) {
        http_response_code(403);
        echo json_encode(["message" => "Device is inactive"]);
        return;
    }

    $action = $_GET['action'] ?? '';

    if ($method === 'GET') {
        switch ($action) {
            case 'status':
                stationStatus($db, $database, $device);
                return;
            case 'totp':
                stationTotp($device);
                return;
        }
    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input")) ?: new stdClass();
        if (!is_object($data)) {
            $data = new stdClass();
        }

        if (!isStationPinEnabled($db, $database)) {
            http_response_code(409);
            echo json_encode(["message" => "Station PIN login is disabled"]);
            return;
        }

        $member = stationRequireMember($db, $database, $device, $data);
        if ($member === null) {
            return; // bereits geantwortet
        }

        switch ($action) {
            case 'identify':
                stationIdentify($db, $database, $device, $member);
                return;
            case 'checkin':
                stationCheckin($db, $database, $device, $member);
                return;
            case 'work_start':
            case 'work_pause':
            case 'work_resume':
            case 'work_stop':
                stationWork($db, $database, $device, $member, $action, $data);
                return;
        }
    } else {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        return;
    }

    http_response_code(400);
    echo json_encode([
        "message" => "Unknown action",
        "allowed" => ["GET status", "GET totp", "POST identify", "POST checkin",
                      "POST work_start", "POST work_pause", "POST work_resume", "POST work_stop"],
    ]);
}

/** Konfiguration und Uhrzeit — beim Start des Kiosks und alle fuenf Minuten. */
function stationStatus($db, $database, array $device)
{
    echo json_encode([
        'device_name'      => $device['device_name'],
        'totp_enabled'     => !empty($device['totp_secret']),
        'pin_enabled'      => isStationPinEnabled($db, $database),
        'pin_min_length'   => stationPinMinLength($db, $database),
        'worktime_enabled' => isWorktimeEnabled($db, $database),
        'server_time'      => date('Y-m-d H:i:s'),
        'server_unix'      => time(),
    ]);
}

/**
 * Aktueller und naechster Stations-Code. Das Secret bleibt hier (E5).
 * `now` reist mit, damit der Kiosk die Restlaufzeit gegen die Serveruhr
 * rechnet und nicht gegen die eigene.
 */
function stationTotp(array $device)
{
    if (empty($device['totp_secret'])) {
        http_response_code(404);
        echo json_encode(["message" => "This station shows no TOTP code"]);
        return;
    }

    $codes        = totpCodesForSecret($device['totp_secret']);
    $codes['now'] = time();
    echo json_encode($codes);
}

/**
 * Prueft member_number + pin aus dem Body. Antwortet selbst bei Fehlern und
 * liefert dann null. Die Meldung ist fuer falsche Nummer, falsche PIN,
 * fehlende PIN, inaktives Mitglied und mehrdeutige Nummer dieselbe (E12).
 * Eine gesperrte Station wird als solche benannt — das verraet nichts ueber
 * Mitglieder und erspart fuenf Leuten die Suche nach dem eigenen Fehler.
 */
function stationRequireMember($db, $database, array $device, $data): ?array
{
    $number = is_string($data->member_number ?? null) ? trim($data->member_number) : '';
    $pin    = is_string($data->pin ?? null) ? $data->pin : '';

    if ($number === '' || $pin === '') {
        http_response_code(400);
        echo json_encode(["message" => "member_number and pin are required"]);
        return null;
    }

    $failure = null;
    $limiter = new RateLimiter($db, $database);
    $member  = stationAuthenticate($db, $database, $limiter, (int) $device['user_id'], $number, $pin, $failure);

    if ($member === null) {
        error_log("station: authentication failed ({$failure}) at device {$device['user_id']}");
        if ($failure === 'device_locked') {
            http_response_code(423);
            echo json_encode(["message"     => "Station temporarily locked",
                              "retry_after" => STATION_PIN_LOCK_SECONDS]);
        } elseif ($failure === 'locked') {
            http_response_code(423);
            echo json_encode(["message"     => "Too many attempts",
                              "retry_after" => STATION_PIN_LOCK_SECONDS]);
        } else {
            http_response_code(401);
            echo json_encode(["message" => "Invalid member number or PIN"]);
        }
        return null;
    }

    return $member;
}

/** Erste Antwort nach Nummer + PIN: wer bin ich, was kann ich hier tun. */
function stationIdentify($db, $database, array $device, array $member)
{
    $prefix    = $database->table('');
    $timestamp = (new DateTime())->format('Y-m-d H:i:s');

    $candidate = null;
    $matched   = findCheckinAppointment($db, $prefix, $member['member_id'], $timestamp,
                                        checkinToleranceHours($db, $database) * 3600);
    if ($matched !== null) {
        $stmt = $db->prepare("SELECT record_id FROM {$prefix}records WHERE member_id = ? AND appointment_id = ?");
        $stmt->execute([$member['member_id'], $matched['appointment_id']]);
        $candidate = [
            'appointment_id'     => (int) $matched['appointment_id'],
            'title'              => $matched['title'],
            'date'               => $matched['date'],
            'start_time'         => $matched['start_time'],
            'already_checked_in' => (bool) $stmt->fetchColumn(),
        ];
    }

    $worktime   = isWorktimeEnabled($db, $database);
    $running    = null;
    $activities = [];

    if ($worktime) {
        // Eine ueberfaellige Sitzung wird hier geschlossen — wie an jedem
        // anderen Einstiegspunkt, an dem das Mitglied aktiv wird.
        $running = getRunningSessionChecked($db, $database, $member['member_id'], (int) $device['user_id']);

        $stmt = $db->prepare("SELECT DISTINCT at.activity_id, at.activity_name, at.color,
                                              at.is_default, at.verification
                              FROM {$prefix}activity_types at
                              INNER JOIN {$prefix}activity_type_groups atg ON atg.activity_id = at.activity_id
                              INNER JOIN {$prefix}member_group_assignments mga ON mga.group_id = atg.group_id
                              WHERE mga.member_id = ? AND at.is_active = 1
                              ORDER BY at.is_default DESC, at.activity_name");
        $stmt->execute([$member['member_id']]);
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'member'            => ['name' => $member['name'], 'surname' => $member['surname']],
        'checkin_candidate' => $candidate,
        'worktime_enabled'  => $worktime,
        'running_session'   => $running === null ? null : withDuration($running),
        'activities'        => $activities,
    ]);
}

/**
 * Anwesenheit stempeln. Terminwahl serverseitig (E9), keine Auto-Anlage am
 * Kiosk. Quelle station_pin, Ort = Kiosk-Name (E7, E8).
 */
function stationCheckin($db, $database, array $device, array $member)
{
    $prefix    = $database->table('');
    $timestamp = (new DateTime())->format('Y-m-d H:i:s');

    $matched = findCheckinAppointment($db, $prefix, $member['member_id'], $timestamp,
                                      checkinToleranceHours($db, $database) * 3600);
    if ($matched === null) {
        http_response_code(404);
        echo json_encode(["message" => "Kein passender Termin gefunden",
                          "reason"  => "no_matching_appointment"]);
        return;
    }

    $result = writeCheckinRecord($db, $prefix, $member['member_id'], (int) $matched['appointment_id'],
                                 $timestamp, 'station_pin', $device['device_name'], $device['device_name']);

    $body = $result['body'];
    $body['appointment'] = [
        'appointment_id' => (int) $matched['appointment_id'],
        'title'          => $matched['title'],
        'date'           => $matched['date'],
        'start_time'     => $matched['start_time'],
    ];

    http_response_code($result['status']);
    echo json_encode($body);
}

/**
 * Zeiterfassung am Kiosk. Die member_id stammt aus der PIN-Pruefung, nie aus
 * dem Request; created_by ist das Kiosk-Geraet. Quelle 'station', Ort =
 * Kiosk-Name an Start und Ende (E7, E8), Notiz optional (P1).
 */
function stationWork($db, $database, array $device, array $member, string $action, $data)
{
    requireWorktimeEnabled($db, $database);   // 404 und Ende, wenn die Zeiterfassung aus ist

    $deviceUserId = (int) $device['user_id'];
    $memberId     = $member['member_id'];
    $override     = [
        'source'        => 'station',
        'location'      => $device['device_name'],
        'note_optional' => true,
    ];

    // Nur activity_id wird durchgereicht. workSessionTargetMember() nimmt fuer
    // Geraete ohnehin $authMemberId — trotzdem kein fremdes Feld weiterleiten.
    $payload = (object) ['activity_id' => $data->activity_id ?? null];

    switch ($action) {
        case 'work_start':
            workSessionStart($db, $database, $payload, $deviceUserId, $memberId, $override);
            return;
        case 'work_pause':
            workSessionPause($db, $database, $payload, $deviceUserId, $memberId);
            return;
        case 'work_resume':
            workSessionResume($db, $database, $payload, $deviceUserId, $memberId);
            return;
        case 'work_stop':
            workSessionStop($db, $database, $payload, $deviceUserId, $memberId, $override);
            return;
    }
}

?>
