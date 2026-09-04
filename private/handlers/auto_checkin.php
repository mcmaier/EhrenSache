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
// AUTO CHECK-IN Controller
// ============================================
/**
 * Darf dieses Mitglied zu einem Termin dieser Terminart?
 *
 * Regel unveraendert aus der Auswahlschleife uebernommen: Ohne Terminart oder
 * ohne Gruppenbindung der Terminart ist ein Termin fuer alle zugaenglich;
 * sonst muss das Mitglied in einer der genannten Gruppen sein.
 *
 * Die Frage stellt sich seit 1.2.4 an zwei Stellen — beim Suchen eines
 * passenden Termins und beim Pruefen eines vom Client gewaehlten. Zwei Kopien
 * derselben Rechtepruefung laufen erfahrungsgemaess auseinander.
 */
function memberMayAttendAppointment($db, $prefix, $memberId, $typeId) {
    if(!$typeId) {
        return true;
    }

    $typeGroupsStmt = $db->prepare("
        SELECT group_id
        FROM {$prefix}appointment_type_groups
        WHERE type_id = ?
    ");
    $typeGroupsStmt->execute([$typeId]);
    $groupIds = $typeGroupsStmt->fetchAll(PDO::FETCH_COLUMN);

    if(empty($groupIds)) {
        return true;
    }

    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $memberGroupStmt = $db->prepare("
        SELECT 1
        FROM {$prefix}member_group_assignments
        WHERE member_id = ?
        AND group_id IN ({$placeholders})
    ");
    $memberGroupStmt->execute(array_merge([$memberId], $groupIds));

    return (bool) $memberGroupStmt->fetch();
}

/**
 * Sucht den Termin, dem ein Check-in zu diesem Zeitpunkt zugeordnet wird.
 *
 * Alle Termine im Toleranzfenster, nach zeitlicher Naehe sortiert; ein Termin
 * einer Standard-Gruppe schlaegt einen Termin einer Spezialgruppe. Die Logik
 * stammt unveraendert aus handleAutoCheckin() und wird seit 1.3.0 auch von der
 * Station (identify, checkin) gebraucht.
 *
 * @return array<string, mixed>|null Terminzeile mit appointment_id, title, date,
 *                                   start_time, type_id, type_name — oder null
 */
function findCheckinAppointment($db, $prefix, int $memberId, string $timestamp, int $toleranceSeconds): ?array
{
    $sql = "SELECT a.appointment_id, a.title, a.date, a.start_time, a.type_id, at.type_name,
                ABS(TIMESTAMPDIFF(SECOND,
                CONCAT(a.date, ' ', a.start_time),
                ?)) as time_diff_seconds
            FROM {$prefix}appointments a
            LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
            WHERE
                ABS(TIMESTAMPDIFF(SECOND,
                CONCAT(a.date, ' ', a.start_time),
                ?)) <= ?
            ORDER BY time_diff_seconds ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute([$timestamp, $timestamp, $toleranceSeconds]);
    $potentialAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $matchedAppointment  = null;
    $fallbackAppointment = null; // Für nicht-Standard-Gruppen

    foreach($potentialAppointments as $appointment) {
        if(!memberMayAttendAppointment($db, $prefix, $memberId, $appointment['type_id'])) {
            continue;
        }

        // Ohne Terminart: kein Filter, Termin für alle.
        if(!$appointment['type_id']) {
            $matchedAppointment = $appointment;
            break;
        }

        // Traegt die Terminart eine Standard-Gruppe, gilt der Termin als der
        // regulaere und wird sofort genommen. Sonst nur als Rueckfall — ein
        // Termin einer Spezialgruppe soll den der Gesamtgruppe nicht verdraengen.
        $typeGroupsStmt = $db->prepare("
            SELECT mg.is_default
            FROM {$prefix}appointment_type_groups atg
            LEFT JOIN {$prefix}member_groups mg ON atg.group_id = mg.group_id
            WHERE atg.type_id = ?
        ");
        $typeGroupsStmt->execute([$appointment['type_id']]);
        $restrictedGroups = $typeGroupsStmt->fetchAll(PDO::FETCH_ASSOC);

        if(empty($restrictedGroups)) {
            $matchedAppointment = $appointment;
            break;
        }

        $hasDefaultGroup = false;
        foreach($restrictedGroups as $grp) {
            if($grp['is_default']) {
                $hasDefaultGroup = true;
                break;
            }
        }

        if($hasDefaultGroup) {
            $matchedAppointment = $appointment;
            break;
        }

        if(!$fallbackAppointment) {
            $fallbackAppointment = $appointment;
        }
    }

    if(!$matchedAppointment && $fallbackAppointment) {
        $matchedAppointment = $fallbackAppointment;
    }

    return $matchedAppointment ?: null;
}

/**
 * Schreibt den Anwesenheitseintrag — oder zieht einen bestehenden vor, wenn
 * die neue Ankunftszeit frueher liegt. Antwortet nicht selbst; der Aufrufer
 * ergaenzt Terminangaben und gibt aus.
 *
 * $arrivalTimestamp ist die UNGERUNDETE Ankunftszeit ('Y-m-d H:i:s'): Die
 * Auto-Anlage eines Termins rundet auf fuenf Minuten, der Eintrag selbst
 * traegt weiterhin die Sekunde des Stempels.
 *
 * @return array{status: int, body: array<string, mixed>}
 */
function writeCheckinRecord($db, $prefix, int $memberId, int $appointmentId, string $arrivalTimestamp,
                            string $checkinSource, ?string $sourceDevice, ?string $locationName): array
{
    $checkStmt = $db->prepare("SELECT record_id, arrival_time FROM {$prefix}records
                               WHERE member_id = ? AND appointment_id = ?");
    $checkStmt->execute([$memberId, $appointmentId]);
    $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

    $common = [
        "appointment_id" => $appointmentId,
        "member_id"      => $memberId,
        "checkin_source" => $checkinSource,
        "source_device"  => $sourceDevice,
        "location_name"  => $locationName,
    ];

    if($existingRecord) {
        $arrivalTime  = new DateTime($arrivalTimestamp);
        $existingTime = new DateTime($existingRecord['arrival_time']);

        if($arrivalTime < $existingTime) {
            $db->prepare("UPDATE {$prefix}records
                          SET arrival_time = ?, status = 'present', checkin_source = ?,
                              source_device = ?, location_name = ?
                          WHERE record_id = ?")
               ->execute([$arrivalTimestamp, $checkinSource, $sourceDevice, $locationName,
                          $existingRecord['record_id']]);
            $recordAction = 'updated';
        } else {
            $recordAction = 'unchanged';
        }

        return ['status' => 200, 'body' => array_merge([
            "message"       => "Check-in " . $recordAction,
            "record_action" => $recordAction,
            "record_id"     => (int)$existingRecord['record_id'],
        ], $common)];
    }

    $insertStmt = $db->prepare("INSERT INTO {$prefix}records
                                (member_id, appointment_id, arrival_time, status,
                                 checkin_source, source_device, location_name)
                                VALUES (?, ?, ?, 'present', ?, ?, ?)");
    if(!$insertStmt->execute([$memberId, $appointmentId, $arrivalTimestamp,
                              $checkinSource, $sourceDevice, $locationName])) {
        return ['status' => 500, 'body' => ["message" => "Failed to create check-in"]];
    }

    return ['status' => 201, 'body' => array_merge([
        "message"       => "Check-in successful",
        "record_action" => "created",
        "record_id"     => (int)$db->lastInsertId(),
    ], $common)];
}

function handleAutoCheckin($db, $database, $method, $authUserId, $authUserRole, $authMemberId, $isTokenAuth, $checkinSource = 'auto_checkin', $sourceInfo = []) {
    if($method !== 'POST') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        return;
    }
    
    $prefix = $database->table('');
    
    $data = json_decode(file_get_contents("php://input"));
    
    // Validierung
    if(!isset($data->arrival_time)) {
        http_response_code(400);
        echo json_encode(["message" => "arrival_time is required"]);
        return;
    }

    /*
    error_log("=== MEMBER ID DETERMINATION ===");
    error_log("authUserRole: $authUserRole");
    error_log("authMemberId: " . ($authMemberId ?? 'NULL'));
    error_log("data->member_id: " . (isset($data->member_id) ? $data->member_id : 'NOT SET'));    
    */
    
     // Member-ID bestimmen
    if(isAdminOrManager() || isDevice()) {
        // Admin/Device kann für beliebige member_id/member_number einchecken
        if(!isset($data->member_id) && !isset($data->member_number)) {
            http_response_code(400);
            echo json_encode([
                "message" => "Either member_id or member_number is required"
            ]);
            return;
        }
        
        // Resolve member_id
        if(isset($data->member_number)) {
            $memberId = resolveMemberIdByNumber($db, $database, $data->member_number);
        } else {
            $memberId = intval($data->member_id);
            
            // Validiere member_id
            $checkMember = $db->prepare("SELECT member_id FROM {$prefix}members WHERE member_id = ?");
            $checkMember->execute([$memberId]);
            if(!$checkMember->fetchColumn()) {
                $memberId = null;
            }
        }
        
        if(!$memberId) {
            http_response_code(404);
            echo json_encode([
                "message" => "Member not found",
                "searched_for" => $data->member_number ?? $data->member_id
            ]);
            return;
        }
        
        // Bestimme Source für Response
        if(isDevice()) {
            $authType = 'device_auth';
            $checkinSource = 'device_auth';
        } else {
            $authType = $isTokenAuth ? 'admin_token' : 'admin_session';
        }
        
    } else {
        // User kann nur für verknüpftes Mitglied einchecken
        if(!$authMemberId || ($authMemberId === null)) {
            http_response_code(403);
            echo json_encode([
                "message" => "No member linked to your account",
                "hint" => "Contact administrator"
            ]);
            return;
        }
        
        $memberId = $authMemberId;
        $authType = $isTokenAuth ? 'user_token' : 'user_session';
        //error_log("USER: Using authMemberId: $memberId");
        
        // Warnung wenn andere member_id angegeben wurde
        $warning = null;
        if(isset($data->member_id) && $data->member_id != $memberId) {
            $warning = "member_id ignored - you can only check-in for your linked member (ID: $memberId)";
        }
    }    


    // Konvertiere arrival_time zu DateTime
    try {
        $arrivalTime = new DateTime($data->arrival_time);
    } catch(Exception $e) {
        http_response_code(400);
        echo json_encode([
            "message" => "Invalid arrival_time format",
            "expected" => "YYYY-MM-DD HH:MM:SS",
            "example" => date('Y-m-d H:i:s')
        ]);
        return;
    }
    
    $arrivalDate = $arrivalTime->format('Y-m-d');
    $arrivalTimeStr = $arrivalTime->format('H:i:s'); 
    $timestamp = $arrivalTime->format('Y-m-d H:i:s');
        
    // Zeittoleranz: Einstellung schlaegt Konstante, ein mitgeschickter Wert
    // schlaegt beides. Der Request-Parameter bleibt fuer Geraete bestehen.
    $configuredTolerance = checkinToleranceHours($db, $database);
    $tolerance = isset($data->tolerance_hours) ? intval($data->tolerance_hours) : $configuredTolerance;

    // Begrenze Toleranz auf sinnvollen Bereich
    if($tolerance < 0 || $tolerance > 8) {
        $tolerance = $configuredTolerance;
    }

    $toleranceSeconds = $tolerance * 3600;

    //DEBUG Info Zeitfenster
    /*
    error_log("=== AUTO-CHECKIN DEBUG ===");
    error_log("Arrival: {$arrivalDate} {$arrivalTimeStr}");
    error_log("Tolerance: {$tolerance} hours");
    error_log("Member ID: {$memberId}");
    */    

    // ===========================================
    // Vom Client gewaehlter Termin
    // ===========================================
    //
    // Die Tagesgrenze ist keine Bequemlichkeit, sondern die Sicherung: Ohne sie
    // koennte ein Mitglied der Rolle 'user' eine beliebige appointment_id des
    // Jahres schicken und sich rueckwirkend anwesend melden. Bis 1.2.3 war das
    // nur deshalb ausgeschlossen, weil allein das Toleranzfenster die Auswahl
    // traf. Sie gilt fuer alle Rollen — Admin und Manager korrigieren ueber
    // records, nicht ueber diesen Endpunkt.
    $chosenAppointment = null;

    if(isset($data->appointment_id) && $data->appointment_id) {
        $chosenStmt = $db->prepare("
            SELECT a.appointment_id, a.title, a.date, a.start_time, a.type_id
            FROM {$prefix}appointments a
            WHERE a.appointment_id = ?
        ");
        $chosenStmt->execute([intval($data->appointment_id)]);
        $chosenAppointment = $chosenStmt->fetch(PDO::FETCH_ASSOC);

        if(!$chosenAppointment) {
            http_response_code(404);
            echo json_encode([
                "message" => "Unknown appointment_id",
                "reason"  => "appointment_not_found"
            ]);
            return;
        }

        if(!memberMayAttendAppointment($db, $prefix, $memberId, $chosenAppointment['type_id'])) {
            http_response_code(403);
            echo json_encode([
                "message" => "Termin gehoert zu einer anderen Gruppe",
                "reason"  => "appointment_not_permitted"
            ]);
            return;
        }

        if($chosenAppointment['date'] !== $arrivalDate) {
            http_response_code(409);
            echo json_encode([
                "message" => "Termin liegt an einem anderen Tag",
                "reason"  => "appointment_wrong_day",
                "hint"    => "Anwesenheit wird am Tag des Termins erfasst"
            ]);
            return;
        }

        // Die Tagesgrenze allein reicht nicht: Innerhalb desselben Tages liesse
        // sich sonst fuer einen laengst vergangenen (oder noch bevorstehenden)
        // Termin einchecken, unabhaengig von der tatsaechlichen Uhrzeit.
        // Gemeldet am 2026-09-03 — ein Termin um 10:00 nahm noch um 16:47 einen
        // Check-in an. Dieselbe Toleranz gilt hier wie bei der automatischen
        // Suche, nur eben auf genau einen Termin angewandt statt auf die Suche.
        $chosenAppointmentTime = new DateTime($chosenAppointment['date'] . ' ' . $chosenAppointment['start_time']);
        $chosenDiffSeconds = abs($arrivalTime->getTimestamp() - $chosenAppointmentTime->getTimestamp());

        if($chosenDiffSeconds > $toleranceSeconds) {
            http_response_code(409);
            echo json_encode([
                "message" => "Termin liegt außerhalb des Zeitfensters",
                "reason"  => "appointment_outside_tolerance",
                "hint"    => "Zeitfenster: ±{$tolerance} Stunde(n)"
            ]);
            return;
        }
    }

    $matchedAppointment = findCheckinAppointment($db, $prefix, (int)$memberId, $timestamp, $toleranceSeconds);

    // Eine bewusste Wahl schlaegt die automatische Suche.
    if($chosenAppointment) {
        $matchedAppointment = $chosenAppointment;
    }

    if($matchedAppointment) {
        // Passender Termin gefunden
        $appointmentId = $matchedAppointment['appointment_id'];
        $action = 'matched';
        //error_log("==> Final match: Appointment #{$appointmentId}");
    } else {
        // Kein passender Termin.
        //
        // Ob jetzt einer angelegt wird, entscheidet der Verein. Bis 1.2.3
        // geschah das immer und unbemerkt — mit der Folge, dass ein Fehlscan
        // einen Termin erzeugte, der in der Statistik bei jedem anderen
        // Mitglied der Gruppe als Fehlzeit auftauchte.
        if(!checkinCreatesAppointments($db, $database)) {
            http_response_code(409);
            echo json_encode([
                "message" => "Kein passender Termin gefunden",
                "reason"  => "no_matching_appointment",
                "hint"    => "Bitte beim Vorstand melden"
            ]);
            return;
        }

        // Erstelle automatischen Termin
        //error_log("==> No match found, creating auto appointment");

        // Runde auf 5-Minuten-Schritte
        $minutes = (int)$arrivalTime->format('i');
        $roundedMinutes = round($minutes / 5) * 5;
        
        // Setze gerundete Zeit
        $arrivalTime->setTime(
            (int)$arrivalTime->format('H'),
            $roundedMinutes,
            0
        );

         // Hole Standard-Terminart
        $typeStmt = $db->query("SELECT type_id FROM {$prefix}appointment_types WHERE is_default = 1 LIMIT 1");
        $defaultType = $typeStmt->fetch(PDO::FETCH_ASSOC);
        $typeId = $defaultType ? $defaultType['type_id'] : null;


        // Kein passender Termin - erstelle automatisch einen
        $autoTitle = "Automatisch erstellter Termin";
        $timeWithoutSeconds = $arrivalTime->format('H:i:s');
        
        $createStmt = $db->prepare("INSERT INTO {$prefix}appointments
                                    (title, type_id, description, date, start_time, created_by, is_auto_created)
                                    VALUES (?, ?, ?, ?, ?, ?, 1)");
        
        $createStmt->execute([
            $autoTitle,
            $typeId,
            "Erstellt durch Zeiterfassung",
            $arrivalDate,
            $timeWithoutSeconds,
            $authUserId
        ]);
        
        $appointmentId = $db->lastInsertId();
        $action = 'created';
        
        $matchedAppointment = [
            'appointment_id'  => $appointmentId,
            'title'           => $autoTitle,
            'date'            => $arrivalDate,
            'start_time'      => $timeWithoutSeconds,
            'is_auto_created' => 1
        ];
    }    
    
    $sourceDevice = $data->source_device ?? null;
    if(!$sourceDevice) {
        $sourceDevice = $sourceInfo['source_device'] ?? null;
    }
    $locationName = $sourceInfo['location_name'] ?? null;

    // Bei Device: Hole Device-Info aus users Tabelle (unveraendert uebernommen)
    if(isDevice()) {
        $deviceStmt = $db->prepare("SELECT email, device_type FROM {$prefix}users WHERE user_id = ?");
        $deviceStmt->execute([$authUserId]);
        $deviceInfo = $deviceStmt->fetch(PDO::FETCH_ASSOC);
        if($deviceInfo) {
            $locationName = $deviceInfo['email'];
        }
    }

    $result = writeCheckinRecord($db, $prefix, (int)$memberId, (int)$appointmentId, $timestamp,
                                 $checkinSource, $sourceDevice, $locationName);

    $body = $result['body'];

    // Termin-Angaben und Warnung nur bei Erfolg ergaenzen — die 500-Antwort
    // bei fehlgeschlagenem INSERT trug schon vor der Extraktion nur "message".
    if($result['status'] !== 500) {
        $body['appointment_action'] = $action;
        $body['appointment']        = $matchedAppointment;
        if($result['status'] === 201) {
            $body['warning'] = $warning ?? null;
        }
    }

    http_response_code($result['status']);
    echo json_encode($body);
}

?>