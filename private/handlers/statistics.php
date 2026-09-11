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
    require_once __DIR__ . '/../helpers/attendance.php';

    if ($groupId !== null) {
        // Wiederholt bewusst die Pruefung, die handleStatistics() vor dem Aufruf
        // bereits macht: Diese Funktion muss auch ohne vorgelagertes Gate
        // aufrufbar sein -- der Anwesenheitsbericht ruft sie so.
        if (!hasStatisticsGroupAccess($db, $database, $authMemberId, $role, $groupId)) {
            return [
                'warning'    => 'group not accessible',
                'year'       => $year,
                'worktime'   => null,
                'summary'    => attendanceBuildSummary([], 0, 0),
                'statistics' => [],
            ];
        }
        $groups = [$groupId];
    } else {
        $groups = getStatisticsGroups($db, $database, $authMemberId, $role);
    }

    $groups = array_map('intval', $groups);

    $statistics = [];

    foreach ($groups as $gid) {
        $types = attendanceGroupTypes($db, $database, $gid);

        // Ist auf eine Terminart gefiltert, zaehlt nur sie -- und eine Gruppe,
        // die sie nicht fuehrt, faellt ganz heraus. Ohne diese Filterung
        // erschiene sie mit leerer Mitgliederliste und wiese dabei ihre
        // *eigenen* Terminarten aus statt der angefragten. Das alte
        // calculateGroupStatistics() gab in diesem Fall null zurueck; das
        // Verhalten war beim Umbau zunaechst verlorengegangen.
        if ($appointmentTypeId !== null) {
            $types = array_values(array_filter(
                $types,
                static fn(array $type): bool => (int) $type['type_id'] === $appointmentTypeId
            ));
        }

        // Gruppe ohne (passende) Terminart hat keine Anwesenheit, ueber die
        // sich reden liesse -- sie entfaellt, wie bisher.
        if ($types === []) {
            continue;
        }

        $rows = attendanceFetchGroupRows($db, $database, $gid, $year,
                                         $memberId, $appointmentTypeId);

        // Mitglied nicht in dieser Gruppe -> Gruppe ueberspringen (wie bisher).
        if ($memberId !== null && $rows === []) {
            continue;
        }

        $groupName = attendanceGroupName($db, $database, $gid);
        if ($groupName === null) {
            continue;
        }

        $statistics[] = attendanceBuildGroup($gid, $groupName, $types, $rows);
    }

    $memberTotals = attendanceFetchMemberTotals($db, $database, $groups, $year,
                                                $memberId, $appointmentTypeId);
    $appointments = attendanceDistinctAppointmentCount($db, $database, $groups, $year,
                                                       $appointmentTypeId);
    $memberCount  = attendanceActiveMemberCount($db, $database, $groups, $year, $memberId);

    return [
        'warning'    => null,
        'year'       => $year,
        'worktime'   => null,
        'summary'    => attendanceBuildSummary($memberTotals, $appointments, $memberCount),
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

?>