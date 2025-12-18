<?php
// ============================================
// STATISTICS Handler
// ============================================
// In api/handlers/statistics.php (oder neue Datei years.php)
function handleAvailableYears($db, $request_method, $id) {

    if ($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }

    try {
        // Jahre aus verschiedenen Tabellen sammeln
        $stmt = $db->query("
            SELECT DISTINCT YEAR(date) as year 
            FROM appointments 
            WHERE date IS NOT NULL
            UNION
            SELECT DISTINCT YEAR(arrival_time) as year 
            FROM records 
            WHERE arrival_time IS NOT NULL
            ORDER BY year DESC
        ");
        $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Aktuelles Jahr + 1 immer einschließen (für neue Termine)
        $currentYear = (int)date('Y');
        /*$nextYear = $currentYear + 1;*/
        
        if (!in_array($currentYear, $years)) {
            $years[] = $currentYear;
        }
        /*if (!in_array($nextYear, $years)) {
            $years[] = $nextYear;
        }*/
        
        rsort($years); // Absteigend sortieren
        
        http_response_code(200);
        echo json_encode($years); 
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Laden der Jahre: ' . $e->getMessage()
        ]);
    }
}


function handleStatistics($db, $request_method, $authUserId, $authUserRole, $authMemberId) {
    if ($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }
    
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $groupId = isset($_GET['group_id']) ? intval($_GET['group_id']) : null;
    $memberId = isset($_GET['member_id']) ? intval($_GET['member_id']) : null;
    
    // Normale User können nur ihre eigene Statistik sehen
    if ($authUserRole !== 'admin') {        
        // Warnung wenn andere member_id angegeben wurde
        $warning = null;
        if(isset($memberId) && ($memberId != $authMemberId)) {
            $warning = "member_id ignored - you can only request your own statistics";
        }
        $memberId = $authMemberId;
    }
    
    // Neue Stat Funktion zum Test

    /*
    // 1. Termine zählen (1 Query)
    $appointmentsSql = "SELECT COUNT(*) as total FROM appointments WHERE YEAR(date) = ?";
    $stmt = $db->prepare($appointmentsSql);
    $stmt->execute([$year]);
    $totalAppointments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // 2. Mitglieder laden (1 Query mit JOINs)
    $membersSql = "SELECT 
        m.*,
        GROUP_CONCAT(DISTINCT g.group_id) as group_ids,
        GROUP_CONCAT(DISTINCT g.group_name) as group_names
    FROM members m
    LEFT JOIN member_group_assignments mga ON m.member_id = mga.member_id
    LEFT JOIN member_groups g ON mga.group_id = g.group_id
    WHERE m.active = 1";
    
    $params = [];
    if ($memberId) {
        $membersSql .= " AND m.member_id = ?";
        $params[] = $memberId;
    }
    if ($groupId) {
        $membersSql .= " AND g.group_id = ?";
        $params[] = $groupId;
    }
    
    $membersSql .= " GROUP BY m.member_id ORDER BY m.surname, m.name";
    
    $stmt = $db->prepare($membersSql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. ALLE Records für Jahr in EINER Query laden
    $recordsSql = "SELECT 
        r.member_id,
        COUNT(*) as total_records,
        SUM(CASE WHEN r.status IN ('present') THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN r.status = 'excused' THEN 1 ELSE 0 END) as excused
    FROM records r
    WHERE YEAR(r.arrival_time) = ?
    GROUP BY r.member_id";
    
    $stmt = $db->prepare($recordsSql);
    $stmt->execute([$year]);
    
    $recordsByMember = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recordsByMember[$row['member_id']] = $row;
    }

    // 4. Statistiken berechnen
    $totalPresent = 0;
    $totalExcused = 0;
    $totalUnexcused = 0;

    foreach ($members as &$member) {
        $stats = $recordsByMember[$member['member_id']] ?? [
            'present' => 0,
            'excused' => 0,
            'total_records' => 0
        ];
        
        $member['present'] = (int)$stats['present'];
        $member['excused'] = (int)$stats['excused'];
        $member['unexcused'] = $totalAppointments - $member['present'] - $member['excused'];
        
        if ($totalAppointments > 0) {
            $member['attendance_percentage'] = round(($member['present'] / $totalAppointments) * 100);
        } else {
            $member['attendance_percentage'] = 0;
        }
        
        $totalPresent += $member['present'];
        $totalExcused += $member['excused'];
        $totalUnexcused += $member['unexcused'];
    }

    // 5. Gruppiere nach Gruppen
    $groupedStats = [];
    foreach ($members as $member) {
        $groupNames = $member['group_names'] ? explode(',', $member['group_names']) : ['Keine Gruppe'];
        
        foreach ($groupNames as $groupName) {
            $groupName = trim($groupName);
            if (!isset($groupedStats[$groupName])) {
                $groupedStats[$groupName] = [
                    'group_name' => $groupName,
                    'members' => []
                ];
            }
            $groupedStats[$groupName]['members'][] = $member;
        }
    }

    // 6. Response
    $overallAverage = count($members) > 0 && $totalAppointments > 0
        ? round(($totalPresent / (count($members) * $totalAppointments)) * 100)
        : 0;

    echo json_encode([
        'year' => $year,
        'summary' => [
            'total_appointments' => $totalAppointments,
            'total_members' => count($members),
            'total_present' => $totalPresent,
            'total_excused' => $totalExcused,
            'total_unexcused' => $totalUnexcused,
            'overall_average' => $overallAverage
        ],
        'statistics' => array_values($groupedStats)
    ]);
    */

    /* Bisherige Funktion */

    // Gruppen ermitteln
    if ($groupId !== null) {
        // Prüfe Gruppenzugriff
        if (!hasStatisticsGroupAccess($db, $authMemberId, $authUserRole, $groupId)) {
            http_response_code(403);
            echo json_encode(["message" => "No access to this group"]);
            exit();
        }
        $groups = [$groupId];
    } else {
        $groups = getStatisticsGroups($db, $authMemberId, $authUserRole);
    }
    
    $statistics = [];
    $totalAppointments = 0;
    $totalPresent = 0;
    $totalExcused = 0;
    $totalUnexcused = 0;
    $totalPossible = 0;

    foreach ($groups as $gid) {
        // Wenn ein spezifisches Mitglied gewählt wurde, prüfe ob es in dieser Gruppe ist
        if ($memberId !== null) {
            $stmt = $db->prepare("
                SELECT COUNT(*) 
                FROM member_group_assignments 
                WHERE member_id = ? AND group_id = ?
            ");
            $stmt->execute([$memberId, $gid]);
            $isMemberInGroup = $stmt->fetchColumn() > 0;
            
            // Mitglied nicht in dieser Gruppe -> überspringen
            if (!$isMemberInGroup) {
                continue;
            }
        }

        $stats = calculateGroupStatistics($db, $gid, $year, $memberId, $authUserRole);
        if ($stats) {
            $statistics[] = $stats;

            // Sammle Gesamtwerte
            // Termine nur einmal pro Gruppe zählen
            $groupAppointments = 0;
            if (count($stats['members']) > 0) {
                $groupAppointments = $stats['members'][0]['total_appointments'];
                $totalAppointments += $groupAppointments;
            }

            $groupMembers = count($stats['members']);

            // Pro Gruppe: mögliche Anwesenheiten = Termine * Mitglieder
            $totalPossible += ($groupAppointments * $groupMembers);

            foreach ($stats['members'] as $member) {
                $totalPresent += $member['attended'];
                $totalUnexcused += $member['unexcused_absences'];
                $totalExcused += ($member['total_appointments'] - $member['attended'] - $member['unexcused_absences']);
            }
        }
    }

    // Mitglieder korrekt aus DB zählen (keine Duplikate)
    $totalMembers = getActiveMemberCount($db, $groups, $memberId);

    // Gesamtdurchschnitt berechnen
    //$totalPossible = $totalAppointments * $totalMembers;
    $overallAverage = $totalPossible > 0 ? round(($totalPresent / $totalPossible) * 100, 1)  : 0;
    
    echo json_encode([
        "warning" => isset($warning) ? $warning : null,
        'year' => $year,
        'summary' => [
            'total_appointments' => $totalAppointments,
            'total_members' => $totalMembers,
            'total_present' => $totalPresent,
            'total_excused' => $totalExcused,
            'total_unexcused' => $totalUnexcused,
            'overall_average' => $overallAverage
        ],
        'statistics' => $statistics
    ]);
    
}

function getActiveMemberCount($db, $groupIds, $specificMemberId = null) {
    // Wenn ein spezifisches Mitglied angegeben ist
    if ($specificMemberId !== null) {
        return 1;
    }
    
    // Wenn Gruppen angegeben sind
    if (!empty($groupIds)) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT mga.member_id)
            FROM member_group_assignments mga
            JOIN members m ON mga.member_id = m.member_id
            WHERE mga.group_id IN ($placeholders)
            AND m.active = 1
        ");
        $stmt->execute($groupIds);
        return $stmt->fetchColumn();
    }
    
    // Alle aktiven Mitglieder
    $stmt = $db->query("SELECT COUNT(*) FROM members WHERE active = 1");
    return $stmt->fetchColumn();
}

function getStatisticsGroups($db, $memberId, $role) {
    if ($role === 'admin') {
        $stmt = $db->query("SELECT group_id FROM member_groups");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    $stmt = $db->prepare("
        SELECT DISTINCT group_id 
        FROM member_group_assignments 
        WHERE member_id = ?
        ORDER BY group_id
    ");
    $stmt->execute([$memberId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasStatisticsGroupAccess($db, $memberId, $role, $groupId) {
    if ($role === 'admin') {
        return true;
    }
    
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM member_group_assignments 
        WHERE member_id = ? AND group_id = ?
    ");
    $stmt->execute([$memberId, $groupId]);
    return $stmt->fetchColumn() > 0;
}

function calculateGroupStatistics($db, $groupId, $year, $memberId, $role) {
    // Gruppeninfo
    $stmt = $db->prepare("      SELECT atg.type_id, mg.group_name
                                FROM appointment_type_groups atg
                                JOIN member_groups mg ON atg.group_id = mg.group_id
                                WHERE atg.group_id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$group) {
        return null;
    }
    
    // Mitglieder ermitteln
    if ($memberId) {
        $memberIds = [$memberId];
    } else {
        // Nur für Admins: alle Gruppenmitglieder
        $stmt = $db->prepare("
            SELECT DISTINCT mga.member_id, mg.group_name
            FROM member_group_assignments mga
            JOIN members m ON mga.member_id = m.member_id
            LEFT JOIN member_groups mg ON mga.group_id = mg.group_id
            WHERE mga.group_id = ? AND m.active = 1
            ORDER BY m.surname, m.name
        ");
        $stmt->execute([$groupId]);
        $memberIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    $memberStats = [];
    foreach ($memberIds as $mid) {
        $stats = calculateMemberStatistics($db, $mid, $groupId, $year);
        if ($stats) {
            $memberStats[] = $stats;
        }
    }
    
    return [
        'group_id' => $groupId,
        'group_name' => $group['group_name'],
        'members' => $memberStats
    ];
}

function calculateMemberStatistics($db, $memberId, $groupId, $year) {
    // Mitgliedsinfo
    $stmt = $db->prepare("SELECT name, surname FROM members WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        return null;
    }
    
    // Alle Termine der Gruppe im Jahr bis heute (inkl. laufendem Termin)
    $stmt = $db->prepare("
        SELECT appointment_id, date 
        FROM appointments a
        LEFT JOIN appointment_type_groups atg ON a.type_id = atg.type_id        
        WHERE atg.group_id = ? 
        AND YEAR(date) = ?
        AND date <= DATE_ADD(CURDATE(), INTERVAL 2 HOUR)        
        ORDER BY date
    ");
    $stmt->execute([$groupId, $year]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalAppointments = count($appointments);
    $attended = 0;
    $unexcused = 0;
    
    foreach ($appointments as $apt) {
        // Check ob Record existiert
        $stmt = $db->prepare("
            SELECT status 
            FROM records 
            WHERE member_id = ? AND appointment_id = ?
        ");
        $stmt->execute([$memberId, $apt['appointment_id']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record) {
            if ($record['status'] === 'present') {
                $attended++;
            }
            // excused, late zählen nicht als unentschuldigt
        } else {
            // Kein Record = unentschuldigt gefehlt
            $unexcused++;
        }
    }
    
    $attendanceRate = $totalAppointments > 0 
        ? round(($attended / $totalAppointments) * 100, 1) 
        : 0;
    
    return [
        'member_id' => $memberId,
        'member_name' => $member['name'] . ' ' . $member['surname'],
        'total_appointments' => $totalAppointments,
        'attended' => $attended,
        'unexcused_absences' => $unexcused,
        'attendance_rate' => $attendanceRate
    ];
}
?>