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
// STATISTICS Handler
// ============================================
// In api/handlers/statistics.php (oder neue Datei years.php)
function handleAvailableYears($db, $database, $request_method, $id) {

    if ($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }

    $prefix = $database->table('');

    try {
        // Jahre aus verschiedenen Tabellen sammeln
        $stmt = $db->query("
            SELECT DISTINCT YEAR(date) as year 
            FROM {$prefix}appointments 
            WHERE date IS NOT NULL
            UNION
            SELECT DISTINCT YEAR(arrival_time) as year 
            FROM {$prefix}records 
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


/**
 * Rechnet die Anwesenheitsstatistik und gibt sie als Array zurueck.
 *
 * Herausgeloest aus handleStatistics(), damit der Anwesenheitsbericht
 * dieselbe Rechnung benutzt statt einer zweiten. Zwei Aggregationen, die
 * dasselbe behaupten, laufen bei der ersten Aenderung auseinander.
 *
 * Die Funktion rechnet, sie autorisiert nicht: $memberId ist bereits
 * aufgeloest. Wer die eigene Person erzwingen muss, tut das im Handler,
 * dort wo auch das Ausgabeformat entschieden wird. $role und $authMemberId
 * gehen nur in die Gruppenaufloesung ein.
 *
 * @return array{warning: ?string, year: int, worktime: null,
 *               summary: array<string, mixed>, statistics: array<int, mixed>}
 */
function buildStatisticsResult($db, $database, int $year, ?int $groupId, ?int $memberId,
                               ?int $appointmentTypeId, string $role, ?int $authMemberId): array
{
    require_once __DIR__ . '/../helpers/member_activity.php';

    if ($groupId !== null) {
        // Wiederholt bewusst die Pruefung, die handleStatistics() vor dem Aufruf
        // bereits macht: Diese Funktion muss auch ohne vorgelagertes Gate
        // aufrufbar sein -- der Anwesenheitsbericht ruft sie so. Das kostet fuer
        // Nicht-Manager mit Gruppenfilter eine zusaetzliche, indizierte
        // COUNT-Abfrage. Wer hier "bereinigt", macht den Bericht angreifbar.
        if (!hasStatisticsGroupAccess($db, $database, $authMemberId, $role, $groupId)) {
            return [
                'warning'    => 'group not accessible',
                'year'       => $year,
                'worktime'   => null,
                'summary'    => [
                    'total_appointments' => 0, 'total_members' => 0, 'total_present' => 0,
                    'total_excused' => 0, 'total_unexcused' => 0, 'overall_average' => 0,
                ],
                'statistics' => [],
            ];
        }
        $groups = [$groupId];
    } else {
        $groups = getStatisticsGroups($db, $database, $authMemberId, $role);
    }

    $statistics = [];
    $totalAppointments = 0;
    $totalPresent = 0;
    $totalExcused = 0;
    $totalUnexcused = 0;
    $totalPossible = 0;
    $countedAppointmentTypes = [];

    foreach ($groups as $gid) {
        $stats = calculateGroupStatistics($db, $database, $gid, $year, $memberId, $role, $appointmentTypeId);
        if ($stats) {
            $statistics[] = $stats;

            $groupAppointments = 0;
            if (count($stats['members']) > 0) {
                $groupAppointments = $stats['members'][0]['total_appointments'];
            }

            // Termine nur einmal je Terminart zaehlen: Mehrere Gruppen koennen
            // an derselben Terminart haengen.
            if (!isset($countedAppointmentTypes[$stats['appointment_type_id']])) {
                $totalAppointments += $groupAppointments;
                $countedAppointmentTypes[$stats['appointment_type_id']] = true;
            }

            $groupMembers = count($stats['members']);
            $totalPossible += ($groupAppointments * $groupMembers);

            foreach ($stats['members'] as $member) {
                $totalPresent += $member['attended'];
                $totalUnexcused += $member['unexcused_absences'];
                $totalExcused += ($member['total_appointments'] - $member['attended'] - $member['unexcused_absences']);
            }
        }
    }

    $totalMembers = getActiveMemberCount($db, $database, $groups, $year, $memberId);
    $overallAverage = $totalPossible > 0 ? round(($totalPresent / $totalPossible) * 100, 1) : 0;

    return [
        'warning'    => null,
        'year'       => $year,
        'worktime'   => null,
        'summary'    => [
            'total_appointments' => $totalAppointments,
            'total_members'      => $totalMembers,
            'total_present'      => $totalPresent,
            'total_excused'      => $totalExcused,
            'total_unexcused'    => $totalUnexcused,
            'overall_average'    => $overallAverage,
        ],
        'statistics' => $statistics,
    ];
}

function handleStatistics($db, $database, $request_method, $authUserId, $authUserRole, $authMemberId) {
    require_once __DIR__ . '/../helpers/member_activity.php';

    if ($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }

    // (int) auf date('Y'): Ohne den Cast waere $year hier ein String und wuerde
    // erst am Typehint von buildStatisticsResult() stillschweigend umgewandelt.
    // Die Antwort trug in diesem Fall bis 1.4.1 "year":"2026" statt "year":2026.
    // Kein Aufrufer liest das Feld, und API.md beschreibt year nur als
    // Anfrageparameter -- die Umwandlung gehoert trotzdem sichtbar hierher und
    // nicht an eine Funktionsgrenze.
    $year = isset($_GET['year']) ? intval($_GET['year']) : (int) date('Y');
    $groupId = isset($_GET['group_id']) ? intval($_GET['group_id']) : null;
    $memberId = isset($_GET['member_id']) ? intval($_GET['member_id']) : null;
    $appointmentTypeId = isset($_GET['appointment_type_id']) ? intval($_GET['appointment_type_id']) : null;

    $warning = null;

    if (!isAdminOrManager()) {
        // Fremde member_id wird ignoriert, nicht abgewiesen: Eine Fehlermeldung
        // waere ein Orakel darueber, welche IDs existieren.
        if ($memberId !== null && $memberId != $authMemberId) {
            $warning = "member_id ignored - you can only request your own statistics";
        }
        $memberId = $authMemberId;

        if ($groupId !== null && !hasStatisticsGroupAccess($db, $database, $authMemberId, $authUserRole, $groupId)) {
            http_response_code(403);
            echo json_encode(["message" => "No access to this group"]);
            return;
        }
    }

    $result = buildStatisticsResult($db, $database, $year, $groupId, $memberId,
                                    $appointmentTypeId, $authUserRole, $authMemberId);

    // Zeiterfassung als eigener Block. Anwesenheitsquote und geleistete Stunden
    // sind verschiedene Fragen; sie in dieselbe Aggregation zu pressen macht
    // beide unklarer.
    if (isset($_GET['include']) && $_GET['include'] === 'worktime'
        && isWorktimeEnabled($db, $database)) {
        // Die Statistikseite bleibt jahresbasiert. Der Zeitraum ist ein
        // Berichtsparameter der Exporte, siehe worktimeResolvePeriod().
        $result['worktime'] = worktimeStatistics(
            $db, $database, worktimeResolvePeriod(null, null, $year), $memberId
        );
    }

    // $result['warning'] ist an dieser Aufrufstelle stets null: Den einzigen
    // Fall, in dem buildStatisticsResult() selbst eine Warnung setzt (kein
    // Gruppenzugriff), hat der Handler oben schon mit 403 abgefangen. $warning
    // gewinnt hier also immer. Der Null-Coalesce bleibt trotzdem stehen, damit
    // die Zeile richtig bleibt, falls sich das Gate oben einmal aendert.
    $result['warning'] = $warning ?? $result['warning'];

    echo json_encode($result);
}

function getActiveMemberCount($db, $database, $groupIds, $year, $specificMemberId = null) {
    // Wenn ein spezifisches Mitglied angegeben ist
    if ($specificMemberId !== null) {
        return 1;
    }
    
    $prefix = $database->table('');

    // Wenn Gruppen angegeben sind
    if (!empty($groupIds)) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT mga.member_id)
            FROM {$prefix}member_group_assignments mga
            JOIN {$prefix}members m ON mga.member_id = m.member_id
            WHERE mga.group_id IN ($placeholders)
            AND " . getMemberActivityWhereYear($year, 'm') . "
        ");
        $stmt->execute($groupIds);
        return $stmt->fetchColumn();
    }
    
    // Alle aktiven Mitglieder
    $activityWhere = getMemberActivityWhereYear($year, 'm');
    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}members m WHERE {$activityWhere}");
    return $stmt->fetchColumn();
}

function getStatisticsGroups($db, $database, $memberId, $role) {

    $prefix = $database->table('');

    if (isAdminOrManager()) {
        $stmt = $db->query("SELECT group_id FROM {$prefix}member_groups");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }    

    $stmt = $db->prepare("
        SELECT DISTINCT group_id 
        FROM {$prefix}member_group_assignments 
        WHERE member_id = ?
        ORDER BY group_id
    ");
    $stmt->execute([$memberId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasStatisticsGroupAccess($db, $database, $memberId, $role, $groupId) {
    if (isAdminOrManager() ) {
        return true;
    }

    $prefix = $database->table('');
    
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM {$prefix}member_group_assignments 
        WHERE member_id = ? AND group_id = ?
    ");
    $stmt->execute([$memberId, $groupId]);
    return $stmt->fetchColumn() > 0;
}

function calculateGroupStatistics($db, $database, $groupId, $year, $memberId, $role, $appointmentTypeId = null) {
    $prefix = $database->table('');

    // 1 Query: Gruppeninfo (typeId + group_name)
    $stmt = $db->prepare("
        SELECT atg.type_id, mg.group_name
        FROM {$prefix}appointment_type_groups atg
        JOIN {$prefix}member_groups mg ON atg.group_id = mg.group_id
        WHERE atg.group_id = ?
    ");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        return null;
    }

    $typeId = $group['type_id'];

    if ($appointmentTypeId !== null && (int)$typeId !== $appointmentTypeId) {
        return null;
    }

    // 1 Query: Alle Mitglieds-Statistiken datenbanksei­tig aggregieren.
    // Die DB berechnet für jedes Mitglied die Gesamtzahl der Termine,
    // Anwesenheiten (status = 'present') und unentschuldigten Fehlzeiten
    // (kein Record-Eintrag) in einem einzigen LEFT-JOIN-Durchlauf.
    $activityWhere = getMemberActivityWhereYear($year, 'm');

    $sql = "
        SELECT
            m.member_id,
            m.name,
            m.surname,
            COUNT(a.appointment_id)                                       AS total_appointments,
            SUM(CASE WHEN r.appointment_id IS NULL THEN 1 ELSE 0 END)     AS unexcused_absences,
            SUM(CASE WHEN r.status = 'present'     THEN 1 ELSE 0 END)     AS attended
        FROM {$prefix}appointments a
        JOIN {$prefix}member_group_assignments mga ON mga.group_id = ?
        JOIN {$prefix}members m
            ON m.member_id = mga.member_id
            AND {$activityWhere}
        LEFT JOIN {$prefix}records r
            ON r.appointment_id = a.appointment_id
            AND r.member_id = m.member_id
        WHERE a.type_id = ?
          AND YEAR(a.date) = ?
          AND a.date <= DATE_ADD(CURDATE(), INTERVAL 2 HOUR)
    ";

    $params = [$groupId, $typeId, $year];

    if ($memberId !== null) {
        $sql .= " AND m.member_id = ?";
        $params[] = $memberId;
    }

    $sql .= " GROUP BY m.member_id, m.name, m.surname ORDER BY m.surname, m.name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mitglied nicht in dieser Gruppe → Gruppe überspringen
    if ($memberId !== null && empty($rows)) {
        return null;
    }

    $memberStats = [];
    foreach ($rows as $row) {
        $total     = (int)$row['total_appointments'];
        $attended  = (int)$row['attended'];
        $unexcused = (int)$row['unexcused_absences'];
        $memberStats[] = [
            'member_id'          => (int)$row['member_id'],
            'member_name'        => $row['surname'] . ', ' . $row['name'],
            'total_appointments' => $total,
            'attended'           => $attended,
            'unexcused_absences' => $unexcused,
            'attendance_rate'    => $total > 0 ? round(($attended / $total) * 100, 1) : 0,
        ];
    }

    return [
        'group_id'            => $groupId,
        'group_name'          => $group['group_name'],
        'appointment_type_id' => $typeId,
        'members'             => $memberStats,
    ];
}


?>