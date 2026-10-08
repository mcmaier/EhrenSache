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
// MEMBERS Controller
// ============================================

function handleMembers($db, $database, $method, $id, $authUserId, $authMemberId) {
    
    require_once __DIR__ . '/../helpers/member_activity.php';

    $prefix = $database->table('');

    switch($method) {
        case 'GET':
            if($id) {  
                // Zugriffskontrolle: Nur Admin können alle Infos lesen  
                if(isAdminOrManager())                
                {
                    // is_active_today: Stand heute fuer die Anzeige im Dashboard (OI-130)
                    $stmt = $db->prepare("SELECT m.*,
                                                 CASE WHEN (" . memberActiveTodayWhere() . ") THEN 1 ELSE 0 END AS is_active_today
                                          FROM {$prefix}members m WHERE m.member_id = ?");
                    $stmt->execute([$id]);
                    $member = $stmt->fetch(PDO::FETCH_ASSOC);

                    if($member) {
                        // Lade zugehörige Gruppen
                        $groupStmt = $db->prepare(" SELECT g.group_id, g.group_name, mga.valid_from
                                                    FROM {$prefix}member_groups g
                                                    INNER JOIN {$prefix}member_group_assignments mga ON g.group_id = mga.group_id
                                                    WHERE mga.member_id = ?");
                        $groupStmt->execute([$id]);
                        $member['groups'] = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
                        // Beendete Zuordnungen, neueste zuerst (Spec 2026-10-05, 6.1)
                        $historyStmt = $db->prepare("SELECT h.group_id, g.group_name, h.valid_from, h.valid_to
                                                       FROM {$prefix}member_group_history h
                                                       JOIN {$prefix}member_groups g ON g.group_id = h.group_id
                                                      WHERE h.member_id = ?
                                                      ORDER BY h.valid_to DESC, g.group_name");
                        $historyStmt->execute([$id]);
                        $member['group_history'] = array_map(static fn ($h) => [
                            'group_id' => (int) $h['group_id'], 'group_name' => $h['group_name'],
                            'valid_from' => $h['valid_from'], 'valid_to' => $h['valid_to'],
                        ], $historyStmt->fetchAll(PDO::FETCH_ASSOC));
                        $member = memberPublicRow($member);

                        echo json_encode($member ?: []);
                    }
                    else {
                        http_response_code(404);
                        echo json_encode(["message" => "Member not found"]);
                    }
                }               
                else{
                    $memberId = $authMemberId;

                    // Aktiv im Zeitraum wie im Listenabruf der Verwalter: mit
                    // year "irgendwann im Jahr aktiv", sonst das Stammdatum.
                    // Ohne das Feld blieb die Mitgliedsauswahl der
                    // Anwesenheit fuer user leer (OI-91).
                    $ownYear = isset($_GET['year']) ? intval($_GET['year']) : null;
                    $ownActivityFlag = $ownYear
                        ? 'CASE WHEN (' . getMemberActivityWhereYear($ownYear, 'm') . ') THEN 1 ELSE 0 END'
                        : 'm.active';

                    $stmt = $db->prepare("
                        SELECT m.member_id, m.name, m.surname, m.member_number, m.active,
                               GROUP_CONCAT(mga.group_id SEPARATOR ', ') as group_ids,
                               $ownActivityFlag as is_active_in_period,
                               CASE WHEN (" . memberActiveTodayWhere() . ") THEN 1 ELSE 0 END as is_active_today
                        FROM {$prefix}members m
                        LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
                        WHERE m.member_id = ?
                        GROUP BY m.member_id
                    ");
                    $stmt->execute([$memberId]);
                    $member = $stmt->fetch(PDO::FETCH_ASSOC);

                    $warning = null;
                    if ($id != $memberId) {
                        $warning = "member_id ignored - you can only get your own linked member number (ID: $memberId)";
                    }

                    if ($member) {
                        echo json_encode([
                            "member_id"           => (int) $member['member_id'],
                            "name"                => $member['name'],
                            "surname"             => $member['surname'],
                            "member_number"       => $member['member_number'],
                            "active"              => (int) $member['active'],
                            "group_ids"           => $member['group_ids'],
                            "is_active_in_period" => (int) $member['is_active_in_period'],
                            "is_active_today"     => (int) $member['is_active_today'],
                            "warning"             => $warning
                        ]);
                    } else {
                        http_response_code(404);
                        echo json_encode(["message" => "Member not found"]);
                    }
                }                       
            } 
            else
            {
                // Parameter
                $group_id = $_GET['group_id'] ?? null;
                $year = isset($_GET['year']) ? intval($_GET['year']) : null;
                $date = $_GET['date'] ?? null;
                $include_inactive = $_GET['include_inactive'] ?? 'false';

                // $date auf gültiges YYYY-MM-DD validieren
                if ($date !== null) {
                    $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
                    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                        $date = null;
                    }
                }

                // Include_inactive nur für Admin/Manager
                $includeInactive = (isAdminOrManager() && $include_inactive === 'true');

                // Datum für Aktivitätsprüfung
                $yearRangeCheck = false;
                $checkDate = null;
                if ($date) {
                    $checkDate = "'$date'"; // Spezifisches Datum (bereits als Y-m-d validiert)
                } elseif ($year) {
                    $yearRangeCheck = true;
                }

                $activityFilter = '';
                $activityFlag = 'm.active';

                if ($yearRangeCheck) {
                    // War im Jahr IRGENDWANN aktiv -- dieselbe Regel wie
                    // Statistik und Anwesenheitsliste (OI-130: vorher eine
                    // eigene Kopie, die den Status der Zeitraeume nicht kannte)
                    $yearActivity = getMemberActivityWhereYear($year, 'm');

                    if (!$includeInactive) {
                        $activityFilter = "AND ($yearActivity)";
                    }

                    $activityFlag = "CASE WHEN ($yearActivity) THEN 1 ELSE 0 END";
                } elseif ($checkDate) {
                    // Spezifisches Datum
                    $activityWhere = getMemberActivityWhere('m', $checkDate, false);
                    
                    if (!$includeInactive) {
                        $activityFilter = "AND ($activityWhere)";
                    }
                    
                    $activityFlag = "CASE WHEN ($activityWhere) THEN 1 ELSE 0 END";
                }

                // Zugriffskontrolle: Nur Admin können alle Infos lesen
                if(isAdminOrManager())    
                {
                    $params = [];

                    // Stand heute, unabhaengig von year/date: Die Mitgliederliste
                    // im Dashboard zeigt aktiv/inaktiv tagesgenau (OI-130).
                    // is_active_in_period bleibt fuer die Auswahllisten.
                    $todayFlag = "CASE WHEN (" . memberActiveTodayWhere() . ") THEN 1 ELSE 0 END";
                    
                    if($group_id)
                    {
                        $sql = "SELECT m.*, g.group_id, g.group_name,    
                                        $activityFlag as is_active_in_period,
                                        $todayFlag as is_active_today
                                    FROM {$prefix}members m
                                    LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
                                    LEFT JOIN {$prefix}member_groups g ON mga.group_id = g.group_id
                                    WHERE mga.group_id = ?
                                    $activityFilter
                                    GROUP BY m.member_id ORDER BY m.surname, m.name";
                                
                        $params[] = $group_id;                                    
                    }
                    else
                    {
                        $sql = "SELECT m.*,
                                        GROUP_CONCAT(g.group_id SEPARATOR ', ') as group_ids,
                                        GROUP_CONCAT(g.group_name SEPARATOR ', ') as group_names,
                                        $activityFlag as is_active_in_period,
                                        $todayFlag as is_active_today
                                    FROM {$prefix}members m
                                    LEFT JOIN {$prefix}member_group_assignments mga ON m.member_id = mga.member_id
                                    LEFT JOIN {$prefix}member_groups g ON mga.group_id = g.group_id
                                    WHERE 1=1
                                        $activityFilter
                                    GROUP BY m.member_id 
                                    ORDER BY m.surname, m.name";
                    }

                    if(count($params) > 0) {
                        $stmt = $db->prepare($sql);
                        $stmt->execute($params);
                    } else {
                        $stmt = $db->query($sql);
                    }

                    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $members = array_map('memberPublicRow', $members);
                }
                else if(isDevice())
                {
                    // Liste der Mitglieder mit Member_Number für Auto-Checkin.
                    // Ohne year/date gilt die Regel von heute wie am Kiosk
                    // (OI-27): Das Terminal markiert Zuordnungen zu Nummern,
                    // die hier fehlen, als verwaist — ausgetretene Mitglieder
                    // duerfen also nicht in der Liste stehen. Bis dahin lieferte
                    // der Aufruf ohne Parameter alle Mitglieder.
                    $deviceFilter = $activityFilter !== ''
                        ? $activityFilter
                        : "AND (" . getMemberActivityWhere('m', "'" . date('Y-m-d') . "'", false, $database) . ")";

                    $sql = "SELECT name, surname, member_number
                            FROM {$prefix}members m
                            WHERE 1=1
                                $deviceFilter
                            ORDER BY surname, name";

                    //$stmt = $db->query("SELECT name, surname, member_number FROM {$prefix}members ORDER BY surname, name");
                    
                    $stmt = $db->query($sql);
                    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                else
                {
                    // Liste aller Mitglieder ohne weitere Infos
                    $sql = "SELECT name, surname 
                            FROM {$prefix}members m
                            WHERE 1=1
                                $activityFilter
                            ORDER BY surname, name";
                    
                    $stmt = $db->query($sql);
                    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                echo json_encode($members);
            }
            break;
            
        case 'POST':
            requireAdminOrManager();

            $data = json_decode(file_get_contents("php://input"));

            // Nur erlaubte Felder extrahieren
            $allowedFields = ['name', 'surname', 'member_number', 'active','group_ids'];
            $cleanData = new stdClass();
            foreach($allowedFields as $field) {
                if(isset($data->$field)) {
                    $cleanData->$field = $data->$field;
                }
            }

            // Pflichtfeld-Prüfung
            if (empty($cleanData->name ?? null) || empty($cleanData->surname ?? null)) {
                http_response_code(400);
                echo json_encode(["message" => "name und surname sind Pflichtfelder"]);
                break;
            }

            // group_ids: nur Liste ganzer Zahlen (vor jeder Aenderung pruefen)
            if (isset($cleanData->group_ids) && ($groupIdsError = groupsCheckMemberGroupIds($cleanData->group_ids)) !== null) {
                http_response_code(400);
                echo json_encode(["message" => $groupIdsError, "field" => "group_ids"]);
                break;
            }

            // Unbekannte Gruppen vor jeder Schreiboperation abweisen
            if (isset($cleanData->group_ids) && !groupsAllExist($db, $database, $cleanData->group_ids)) {
                http_response_code(400);
                echo json_encode(["message" => "Unbekannte Gruppe", "field" => "group_ids"]);
                break;
            }

            // Umgebende Leerzeichen entfernen, bevor auf Duplikate geprueft
            // und gespeichert wird — sonst waeren "AB1" und "AB1 " zwei
            // "unterschiedliche" Nummern. Leer nach dem Trim faellt wie
            // bisher auf NULL zurueck (siehe INSERT unten).
            if (isset($cleanData->member_number) && is_string($cleanData->member_number)) {
                $cleanData->member_number = trim($cleanData->member_number);
            }

            // Prüfe ob member_number bereits existiert (falls angegeben)
            if(isset($cleanData->member_number) && !empty($cleanData->member_number)) {
                $checkStmt = $db->prepare("SELECT member_id FROM {$prefix}members WHERE member_number = ?");
                $checkStmt->execute([$cleanData->member_number]);
                if($checkStmt->fetch()) {
                    http_response_code(409);
                    echo json_encode([
                        "message" => "Diese Mitgliedsnummer ist bereits vergeben"
                    ]);
                    break;
                }
            }

            // Mitglied und Gruppen in einer Transaktion (Spec 2026-10-05, 4.1)
            $addedGroups = [];
            $groupWarnings = [];
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("INSERT INTO {$prefix}members (name, surname, member_number, active)
                                      VALUES (?, ?, ?, ?)");
                // === '' statt empty(): "0" ist eine gueltige Mitgliedsnummer,
                // wuerde von empty() aber faelschlich als leer behandelt.
                $stmt->execute([$cleanData->name, $cleanData->surname,
                                (($cleanData->member_number ?? '') === '') ? null : $cleanData->member_number,
                                $cleanData->active ?? true]);
                $memberId = $db->lastInsertId();

                // Speichere Gruppen-Zuordnungen
                if(isset($cleanData->group_ids)) {
                    // Mitgliedschaftsregel (Spec 2026-10-02, 4.1): Register ziehen ihre Gruppe nach.
                    $normalized = groupsWithParents($db, $database, $cleanData->group_ids);
                    groupsApplyChange($db, $database, (int) $memberId, $normalized['group_ids'], null);
                    [$addedGroups, $groupWarnings] = groupsRuleReport((int) $memberId, $normalized);
                }

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $isDeadlock = $e instanceof PDOException && in_array($e->errorInfo[1] ?? 0, [1205, 1213]);
                http_response_code($isDeadlock ? 503 : 500);
                echo json_encode(["message" => $isDeadlock
                    ? "Temporärer Datenbankkonflikt. Bitte erneut versuchen."
                    : "Failed to create member"]);
                error_log("POST member failed: " . $e->getMessage());
                break;
            }

            http_response_code(201);
            // $memberId, nicht lastInsertId(): Nach dem Insert der
            // Gruppenzuordnung (ohne AUTO_INCREMENT) liefert es 0.
            echo json_encode(["message" => "Member created", "id" => $memberId,
                              "added_groups" => $addedGroups, "group_warnings" => $groupWarnings]);
            break;
            
        case 'PUT':
            requireAdminOrManager();

            $data = json_decode(file_get_contents("php://input"));

            // Erlaubte Felder
            $allowedFields = ['name', 'surname', 'member_number', 'active', 'group_ids', 'groups_valid_from'];
            $cleanData = new stdClass();
            foreach ($allowedFields as $field) {
                if (isset($data->$field)) {
                    $cleanData->$field = $data->$field;
                }
            }

            // group_ids: nur Liste ganzer Zahlen (vor jeder Aenderung pruefen)
            if (isset($cleanData->group_ids) && ($groupIdsError = groupsCheckMemberGroupIds($cleanData->group_ids)) !== null) {
                http_response_code(400);
                echo json_encode(["message" => $groupIdsError, "field" => "group_ids"]);
                break;
            }

            // groups_valid_from (Spec 2026-10-05, 4.2): nur heute oder Vergangenheit
            $groupsValidFrom = date('Y-m-d');
            if (isset($cleanData->group_ids) && isset($cleanData->groups_valid_from)) {
                if (($validFromError = groupsCheckValidFrom($cleanData->groups_valid_from, date('Y-m-d'))) !== null) {
                    http_response_code(422);
                    echo json_encode(["message" => $validFromError, "field" => "groups_valid_from"]);
                    break;
                }
                $groupsValidFrom = $cleanData->groups_valid_from;
            }

            // Unbekannte Gruppen vor jeder Schreiboperation abweisen
            if (isset($cleanData->group_ids) && !groupsAllExist($db, $database, $cleanData->group_ids)) {
                http_response_code(400);
                echo json_encode(["message" => "Unbekannte Gruppe", "field" => "group_ids"]);
                break;
            }

            // Existenz des Mitglieds vor jeder Schreiboperation (auch nur mit group_ids)
            $existsStmt = $db->prepare("SELECT member_id FROM {$prefix}members WHERE member_id = ?");
            $existsStmt->execute([$id]);
            if (!$existsStmt->fetch()) {
                http_response_code(404);
                echo json_encode(["message" => "Member not found"]);
                break;
            }

            // Umgebende Leerzeichen entfernen — siehe POST weiter oben.
            if (isset($cleanData->member_number) && is_string($cleanData->member_number)) {
                $cleanData->member_number = trim($cleanData->member_number);
            }

            // member_number-Duplikat prüfen (wenn angegeben)
            if (isset($cleanData->member_number) && !empty($cleanData->member_number)) {
                $checkStmt = $db->prepare("SELECT member_id FROM {$prefix}members
                                           WHERE member_number = ? AND member_id != ?");
                $checkStmt->execute([$cleanData->member_number, $id]);
                if ($checkStmt->fetch()) {
                    http_response_code(409);
                    echo json_encode(["message" => "Diese Mitgliedsnummer ist bereits vergeben", "field" => "member_number"]);
                    break;
                }
            }

            // Stations-PIN: setzen (Ziffernfolge) oder loeschen (null / '').
            // property_exists statt isset, weil isset() bei null false liefert.
            $pinAction = null;
            if (is_object($data) && property_exists($data, 'pin')) {
                // Dieselbe Freischaltung wie change_pin, dieselbe Antwort (OI-62);
                // field bleibt, damit das Formular den Fehler am Feld zeigt.
                requireFeature($db, $database, 'station_pin', ['field' => 'pin']);

                if ($data->pin === null || $data->pin === '') {
                    $pinAction = 'clear';
                } elseif (!is_string($data->pin)) {
                    http_response_code(400);
                    echo json_encode(["message" => "Die PIN darf nur Ziffern enthalten", "field" => "pin"]);
                    break;
                } else {
                    $pinError = validateStationPin((string) $data->pin, stationPinMinLength($db, $database));
                    if ($pinError !== null) {
                        http_response_code(400);
                        echo json_encode(["message" => $pinError, "field" => "pin"]);
                        break;
                    }
                    $pinAction = 'set';
                }
            }

            // Dynamisches UPDATE: nur gelieferte Felder
            $updatable = ['name', 'surname', 'member_number', 'active'];
            $setParts  = [];
            $params    = [];
            foreach ($updatable as $field) {
                if (isset($cleanData->$field)) {
                    $setParts[] = "$field = ?";
                    // === '' statt empty(): "0" ist eine gueltige
                    // Mitgliedsnummer, wuerde von empty() faelschlich als
                    // leer behandelt.
                    $params[]   = ($field === 'member_number' && $cleanData->$field === '') ? null : $cleanData->$field;
                }
            }

            if ($pinAction === 'set') {
                $setParts[] = "pin_hash = ?";
                $params[]   = password_hash((string) $data->pin, PASSWORD_DEFAULT);
                $setParts[] = "pin_updated_at = NOW()";
            } elseif ($pinAction === 'clear') {
                $setParts[] = "pin_hash = NULL";
                $setParts[] = "pin_updated_at = NULL";
            }

            if (empty($setParts) && !isset($cleanData->group_ids)) {
                http_response_code(400);
                echo json_encode(["message" => "Keine gültigen Felder zum Aktualisieren angegeben"]);
                break;
            }

            // Stammdaten, PIN-Sperre und Gruppen in einer Transaktion (Spec 2026-10-05, 4.1)
            $addedGroups = [];
            $groupWarnings = [];
            try {
                $db->beginTransaction();

                if (!empty($setParts)) {
                    $params[] = $id;
                    $stmt = $db->prepare("UPDATE {$prefix}members SET " . implode(', ', $setParts) . " WHERE member_id = ?");
                    $stmt->execute($params);
                }

                // P2: Eine neue PIN hebt die Sperre auf — sonst wartet das Mitglied
                // trotz neuer PIN 15 Minuten.
                if ($pinAction !== null) {
                    (new RateLimiter($db, $database))->reset('station_member_' . (int) $id, 'station_pin');
                }

                // Gruppen-Zuordnungen aktualisieren (wenn group_ids geliefert)
                if (isset($cleanData->group_ids)) {
                    // Mitgliedschaftsregel (Spec 2026-10-02, 4.1): Register ziehen ihre Gruppe nach.
                    $normalized = groupsWithParents($db, $database, $cleanData->group_ids);
                    groupsApplyChange($db, $database, (int) $id, $normalized['group_ids'], $groupsValidFrom);
                    [$addedGroups, $groupWarnings] = groupsRuleReport((int) $id, $normalized);
                }

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $isDeadlock = $e instanceof PDOException && in_array($e->errorInfo[1] ?? 0, [1205, 1213]);
                http_response_code($isDeadlock ? 503 : 500);
                echo json_encode(["message" => $isDeadlock
                    ? "Temporärer Datenbankkonflikt. Bitte erneut versuchen."
                    : "Fehler beim Aktualisieren des Mitglieds"]);
                error_log("PUT member $id failed: " . $e->getMessage());
                break;
            }

            echo json_encode(["message" => "Member updated",
                              "added_groups" => $addedGroups, "group_warnings" => $groupWarnings]);
            break;
            
        case 'DELETE':
            requireAdminOrManager();

            if (!$id) {
                http_response_code(400);
                echo json_encode(["message" => "id ist erforderlich"]);
                break;
            }

            // Existenz prüfen
            $checkStmt = $db->prepare("SELECT member_id FROM {$prefix}members WHERE member_id = ?");
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) {
                http_response_code(404);
                echo json_encode(["message" => "Member not found"]);
                break;
            }

            // Hinweis: active-Prüfung entfernt – Mitgliedschaftszeiträume verwalten
            // den Aktivstatus; die Löschbestätigung im Frontend ist ausreichend.

            try {
                $db->beginTransaction();

                $db->prepare("DELETE FROM {$prefix}records                  WHERE member_id = ?")->execute([$id]);
                // Explizit vor exceptions (W2): appointment_responses.exception_id zeigt
                // sonst kurzzeitig auf einen bereits geloeschten Antrag.
                $db->prepare("DELETE FROM {$prefix}appointment_responses    WHERE member_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM {$prefix}exceptions               WHERE member_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM {$prefix}membership_dates         WHERE member_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM {$prefix}member_group_assignments WHERE member_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM {$prefix}member_group_history     WHERE member_id = ?")->execute([$id]);
                $db->prepare("UPDATE {$prefix}users SET member_id = NULL    WHERE member_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM {$prefix}members                  WHERE member_id = ?")->execute([$id]);

                $db->commit();

                echo json_encode(["message" => "Member and all associated data deleted"]);

            } catch (PDOException $e) {
                $db->rollBack();
                // Deadlock (1213) oder Lock-Timeout (1205): Client kann sofort wiederholen
                $isDeadlock = in_array($e->errorInfo[1] ?? 0, [1205, 1213]);
                http_response_code($isDeadlock ? 503 : 500);
                echo json_encode([
                    "message" => $isDeadlock
                        ? "Temporärer Datenbankkonflikt. Bitte erneut versuchen."
                        : "Fehler beim Löschen des Mitglieds",
                ]);
                error_log("DELETE member $id failed: " . $e->getMessage());
            }
            break;
    }
}

/**
 * Entfernt den PIN-Hash aus einer Mitgliedszeile und setzt has_pin.
 * Der Hash verlaesst den Server nie — auch nicht an Administratoren (E11).
 */
function memberPublicRow(array $row): array
{
    $row['has_pin'] = !empty($row['pin_hash']);
    unset($row['pin_hash']);

    return $row;
}

?>