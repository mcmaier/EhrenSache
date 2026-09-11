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

declare(strict_types=1);

// ============================================
// ANWESENHEITSAUSWERTUNG
//
// Die Datei trennt zwei Sorten Funktionen:
//
//   holend  -- fuehrt SQL aus, gibt rohe Zeilen zurueck
//   formend -- nimmt Zeilen entgegen, gibt die Ergebnisstruktur zurueck
//
// Die formenden tragen die Logik, an der OI-48 hing: Welche Terminarten
// eine Gruppe hat und wie sie zusammengefasst werden. Sie kommen ohne
// Datenbank aus und sind deshalb in tests/suites/statistics_unit.php mit
// handgeschriebenen Zeilen pruefbar -- auch fuer Faelle, die in keinem
// Datenbestand vorkommen.
// ============================================

/**
 * Karenz, mit der ein Termin noch als "bereits begonnen" zaehlt.
 *
 * Stand bis 1.5.0 an fuenf Stellen hart codiert. Der Wert hat ueberall
 * dieselbe Bedeutung, unabhaengig von der Abfrage, in der er steht -- anders
 * als die Datums- und Jahresbedingungen daneben, die zum jeweiligen
 * Abfragekontext gehoeren und bewusst ausgeschrieben bleiben.
 *
 * Kein Nutzerwert -- die Verkettung im SQL ist deshalb unbedenklich.
 */
const ATTENDANCE_STARTED_CUTOFF_SQL = 'DATE_ADD(CURDATE(), INTERVAL 2 HOUR)';

/** Quote in Prozent, eine Nachkommastelle, ohne Division durch null. */
function attendanceRate(int $attended, int $total): float
{
    return $total > 0 ? round(($attended / $total) * 100, 1) : 0.0;
}

/**
 * Formt die Zeilen einer Gruppe zum Ergebnisblock.
 *
 * $types ist die vollstaendige, bereits sortierte Terminartliste der Gruppe.
 * Sie bestimmt Zahl und Reihenfolge der Eintraege in by_type -- auch fuer
 * Terminarten ohne Termine im Jahr. Ohne das wechselten die Spalten der
 * Oberflaeche mit dem Jahresfilter.
 *
 * $rows kommt aus attendanceFetchGroupRows(): je Mitglied und Terminart eine
 * Zeile mit total, attended und unexcused. 'excused' wird hier gerechnet, aus
 * denselben drei Zahlen -- eine Quelle, wie seit 1.5.0.
 *
 * @param array<int, array{type_id: int, type_name: ?string}> $types
 * @param array<int, array<string, mixed>> $rows
 */
function attendanceBuildGroup(int $groupId, string $groupName, array $types, array $rows): array
{
    // Bekannte Terminarten als Nachschlagetabelle. Die beiden Pruefungen
    // darunter sind Vertragspruefungen, keine Fehlerbehandlung: Das SQL
    // liefert nur Zeilen zu Terminarten dieser Gruppe, und es gruppiert nach
    // member_id und type_id. Verletzt eine Zeile das, ist die Voraussetzung
    // kaputt -- und genau dann soll es auffallen statt zu verschwinden.
    // Stiller Datenverlust ist der Fehler, den dieses Vorhaben behebt (OI-48).
    $knownTypes = [];
    foreach ($types as $type) {
        $knownTypes[(int) $type['type_id']] = true;
    }

    $byMember = [];

    foreach ($rows as $row) {
        $id     = (int) $row['member_id'];
        $typeId = (int) $row['type_id'];

        if (!isset($knownTypes[$typeId])) {
            throw new InvalidArgumentException(
                "Zeile mit Terminart {$typeId}, die nicht zur Gruppe {$groupId} gehoert"
            );
        }

        if (!isset($byMember[$id])) {
            $byMember[$id] = [
                'member_id'   => $id,
                'member_name' => $row['surname'] . ', ' . $row['name'],
                'per_type'    => [],
            ];
        }

        if (isset($byMember[$id]['per_type'][$typeId])) {
            throw new InvalidArgumentException(
                "Doppelte Zeile fuer Mitglied {$id} und Terminart {$typeId}"
            );
        }

        $total     = (int) $row['total'];
        $attended  = (int) $row['attended'];
        $unexcused = (int) $row['unexcused'];

        $byMember[$id]['per_type'][$typeId] = [
            'total_appointments' => $total,
            'attended'           => $attended,
            'unexcused_absences' => $unexcused,
            'excused'            => max(0, $total - $attended - $unexcused),
        ];
    }

    $members = [];

    foreach ($byMember as $entry) {
        $sum = ['total_appointments' => 0, 'attended' => 0,
                'unexcused_absences' => 0, 'excused' => 0];
        $byType = [];

        foreach ($types as $type) {
            $typeId = (int) $type['type_id'];
            $values = $entry['per_type'][$typeId]
                ?? ['total_appointments' => 0, 'attended' => 0,
                    'unexcused_absences' => 0, 'excused' => 0];

            foreach ($sum as $key => $_) {
                $sum[$key] += $values[$key];
            }

            $byType[] = [
                'type_id'            => $typeId,
                'type_name'          => $type['type_name'],
                'total_appointments' => $values['total_appointments'],
                'attended'           => $values['attended'],
                'excused'            => $values['excused'],
                'unexcused_absences' => $values['unexcused_absences'],
                'attendance_rate'    => attendanceRate($values['attended'],
                                                       $values['total_appointments']),
            ];
        }

        $members[] = [
            'member_id'          => $entry['member_id'],
            'member_name'        => $entry['member_name'],
            'total_appointments' => $sum['total_appointments'],
            'attended'           => $sum['attended'],
            'excused'            => $sum['excused'],
            'unexcused_absences' => $sum['unexcused_absences'],
            'attendance_rate'    => attendanceRate($sum['attended'], $sum['total_appointments']),
            'by_type'            => $byType,
        ];
    }

    return [
        'group_id'          => $groupId,
        'group_name'        => $groupName,
        'appointment_types' => $types,
        'members'           => $members,
    ];
}

/**
 * Formt die Kopfzahlen aus den bereits entdoppelten Mitgliedszeilen.
 *
 * $memberTotals kommt aus attendanceFetchMemberTotals(). Die Entdopplung
 * geschieht dort im SQL ueber COUNT(DISTINCT ...) -- diese Funktion summiert
 * nur. Ein Unit-Test kann die Entdopplung hier deshalb nicht belegen; er
 * wuerde pruefen, dass Addition addiert. Dafuer ist ein HTTP-Test vorgesehen,
 * der einen Termin ueber zwei Gruppen an dasselbe Mitglied fuehrt.
 *
 * 'unexcused' wird als total - attended - excused gerechnet. Die Richtung ist
 * umgekehrt zu attendanceBuildGroup(): Dort ist 'kein Eintrag vorhanden'
 * direkt zaehlbar, hier nicht, weil ein fehlender Eintrag keine Termin-ID
 * beisteuert, die COUNT(DISTINCT ...) zaehlen koennte.
 *
 * @param array<int, array{member_id: int, total: int, attended: int, excused: int}> $memberTotals
 */
function attendanceBuildSummary(array $memberTotals, int $appointmentCount, int $memberCount): array
{
    $possible  = 0;
    $present   = 0;
    $excused   = 0;
    $unexcused = 0;

    foreach ($memberTotals as $row) {
        $total    = (int) $row['total'];
        $attended = (int) $row['attended'];
        $exc      = (int) $row['excused'];

        $possible  += $total;
        $present   += $attended;
        $excused   += $exc;
        $unexcused += max(0, $total - $attended - $exc);
    }

    return [
        'total_appointments' => $appointmentCount,
        'total_members'      => $memberCount,
        'total_present'      => $present,
        'total_excused'      => $excused,
        'total_unexcused'    => $unexcused,
        'overall_average'    => attendanceRate($present, $possible),
    ];
}

/**
 * Schraenkt die Terminartliste einer Gruppe auf eine angefragte Terminart ein.
 *
 * Ohne Filter bleibt die Liste, wie sie ist. Mit Filter bleibt hoechstens ein
 * Eintrag uebrig -- und bleibt keiner uebrig, fuehrt die Gruppe diese Terminart
 * nicht und faellt beim Aufrufer ganz heraus.
 *
 * Das stand zunaechst im Handler und war dort falsch: Die Gruppe blieb mit
 * leerer Mitgliederliste stehen und wies dabei ihre *eigenen* Terminarten aus
 * statt der angefragten. Hier ist es pruefbar.
 *
 * @param array<int, array{type_id: int, type_name: ?string}> $types
 * @return array<int, array{type_id: int, type_name: ?string}>
 */
function attendanceFilterTypes(array $types, ?int $appointmentTypeId): array
{
    if ($appointmentTypeId === null) {
        return $types;
    }

    return array_values(array_filter(
        $types,
        static fn(array $type): bool => (int) $type['type_id'] === $appointmentTypeId
    ));
}

// ============================================
// HOLEND
// ============================================

/**
 * Alle Terminarten einer Gruppe, sortiert.
 *
 * LEFT JOIN, damit eine verwaiste type_id die Zeile nicht schluckt: Der Join
 * dient der Beschriftung, nicht der Auswahl. type_name ist dann null und wird
 * von der Oberflaeche als "ohne Terminart" ausgegeben.
 *
 * @return array<int, array{type_id: int, type_name: ?string}>
 */
function attendanceGroupTypes($db, $database, int $groupId): array
{
    $prefix = $database->table('');

    $stmt = $db->prepare("
        SELECT atg.type_id, at.type_name
        FROM {$prefix}appointment_type_groups atg
        LEFT JOIN {$prefix}appointment_types at ON at.type_id = atg.type_id
        WHERE atg.group_id = ?
        ORDER BY at.type_name, atg.type_id
    ");
    $stmt->execute([$groupId]);

    $types = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $types[] = [
            'type_id'   => (int) $row['type_id'],
            'type_name' => $row['type_name'],
        ];
    }

    return $types;
}

/**
 * Namen mehrerer Gruppen auf einmal, als Zuordnung group_id => group_name.
 *
 * Eine Abfrage statt einer je Gruppe: Der Name haengt weder an Terminart noch
 * an Jahr, er muss nicht in der Gruppenschleife geholt werden. Fehlt eine
 * group_id im Ergebnis, gibt es die Gruppe nicht mehr -- der Aufrufer
 * ueberspringt sie dann.
 *
 * @param array<int, int> $groupIds
 * @return array<int, string>
 */
function attendanceGroupNames($db, $database, array $groupIds): array
{
    if ($groupIds === []) {
        return [];
    }

    $prefix       = $database->table('');
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

    $stmt = $db->prepare("
        SELECT group_id, group_name
        FROM {$prefix}member_groups
        WHERE group_id IN ({$placeholders})
    ");
    $stmt->execute($groupIds);

    $names = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $names[(int) $row['group_id']] = (string) $row['group_name'];
    }

    return $names;
}

/**
 * Je Mitglied und Terminart eine Zeile innerhalb einer Gruppe.
 *
 * Der Join auf appointment_type_groups ist an die feste group_id gebunden --
 * dadurch entsteht kein Faecher, jeder Termin trifft jedes Mitglied der Gruppe
 * genau einmal. Die Gruppierung nach member_id und type_id ist die
 * Voraussetzung, die attendanceBuildGroup() prueft: keine doppelte Zeile fuer
 * dieselbe Kombination.
 *
 * @return array<int, array<string, mixed>>
 */
function attendanceFetchGroupRows($db, $database, int $groupId, int $year,
                                  ?int $memberId, ?int $appointmentTypeId): array
{
    require_once __DIR__ . '/member_activity.php';

    $prefix        = $database->table('');
    $activityWhere = getMemberActivityWhereYear($year, 'm');

    $sql = "
        SELECT m.member_id, m.name, m.surname, a.type_id,
               COUNT(a.appointment_id)                                   AS total,
               SUM(CASE WHEN r.status = 'present' THEN 1 ELSE 0 END)     AS attended,
               SUM(CASE WHEN r.appointment_id IS NULL THEN 1 ELSE 0 END) AS unexcused
        FROM {$prefix}appointments a
        JOIN {$prefix}appointment_type_groups atg
             ON atg.type_id = a.type_id AND atg.group_id = ?
        JOIN {$prefix}member_group_assignments mga ON mga.group_id = atg.group_id
        JOIN {$prefix}members m ON m.member_id = mga.member_id AND {$activityWhere}
        LEFT JOIN {$prefix}records r
             ON r.appointment_id = a.appointment_id AND r.member_id = m.member_id
        WHERE YEAR(a.date) = ?
          AND a.date <= " . ATTENDANCE_STARTED_CUTOFF_SQL . "
    ";

    $params = [$groupId, $year];

    if ($memberId !== null) {
        $sql .= " AND m.member_id = ?";
        $params[] = $memberId;
    }
    if ($appointmentTypeId !== null) {
        $sql .= " AND a.type_id = ?";
        $params[] = $appointmentTypeId;
    }

    $sql .= " GROUP BY m.member_id, a.type_id ORDER BY m.surname, m.name, a.type_id";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Je Mitglied eine Zeile, ueber alle Gruppen, entdoppelt.
 *
 * COUNT(DISTINCT a.appointment_id) ist die Entdopplung: Erreicht ein Termin
 * ein Mitglied ueber zwei Gruppen, zaehlt er einmal. Ohne das waeren die
 * Kopfzahlen die Summe der Gruppentabellen und wuerden die Wirklichkeit
 * uebersteigen.
 *
 * @param array<int, int> $groupIds
 * @return array<int, array<string, mixed>>
 */
function attendanceFetchMemberTotals($db, $database, array $groupIds, int $year,
                                     ?int $memberId, ?int $appointmentTypeId): array
{
    if ($groupIds === []) {
        return [];
    }

    require_once __DIR__ . '/member_activity.php';

    $prefix        = $database->table('');
    $activityWhere = getMemberActivityWhereYear($year, 'm');
    $placeholders  = implode(',', array_fill(0, count($groupIds), '?'));

    $sql = "
        SELECT m.member_id,
               COUNT(DISTINCT a.appointment_id) AS total,
               COUNT(DISTINCT CASE WHEN r.status = 'present' THEN a.appointment_id END) AS attended,
               COUNT(DISTINCT CASE WHEN r.status = 'excused' THEN a.appointment_id END) AS excused
        FROM {$prefix}appointments a
        JOIN {$prefix}appointment_type_groups atg ON atg.type_id = a.type_id
        JOIN {$prefix}member_group_assignments mga
             ON mga.group_id = atg.group_id AND mga.group_id IN ({$placeholders})
        JOIN {$prefix}members m ON m.member_id = mga.member_id AND {$activityWhere}
        LEFT JOIN {$prefix}records r
             ON r.appointment_id = a.appointment_id AND r.member_id = m.member_id
        WHERE YEAR(a.date) = ?
          AND a.date <= " . ATTENDANCE_STARTED_CUTOFF_SQL . "
    ";

    $params = $groupIds;
    $params[] = $year;

    if ($memberId !== null) {
        $sql .= " AND m.member_id = ?";
        $params[] = $memberId;
    }
    if ($appointmentTypeId !== null) {
        $sql .= " AND a.type_id = ?";
        $params[] = $appointmentTypeId;
    }

    $sql .= " GROUP BY m.member_id";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Zahl der Termine im Auswertungsbereich, jeder einmal. */
function attendanceDistinctAppointmentCount($db, $database, array $groupIds, int $year,
                                            ?int $appointmentTypeId): int
{
    if ($groupIds === []) {
        return 0;
    }

    $prefix       = $database->table('');
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

    $sql = "
        SELECT COUNT(DISTINCT a.appointment_id)
        FROM {$prefix}appointments a
        JOIN {$prefix}appointment_type_groups atg
             ON atg.type_id = a.type_id AND atg.group_id IN ({$placeholders})
        WHERE YEAR(a.date) = ?
          AND a.date <= " . ATTENDANCE_STARTED_CUTOFF_SQL . "
    ";

    $params = $groupIds;
    $params[] = $year;

    if ($appointmentTypeId !== null) {
        $sql .= " AND a.type_id = ?";
        $params[] = $appointmentTypeId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/**
 * Zahl der aktiven Mitglieder im Auswertungsbereich.
 *
 * Unveraendert uebernommen aus getActiveMemberCount() in handlers/statistics.php.
 */
function attendanceActiveMemberCount($db, $database, array $groupIds, int $year,
                                     ?int $memberId): int
{
    if ($memberId !== null) {
        return 1;
    }

    require_once __DIR__ . '/member_activity.php';

    $prefix        = $database->table('');
    $activityWhere = getMemberActivityWhereYear($year, 'm');

    if (!empty($groupIds)) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT mga.member_id)
            FROM {$prefix}member_group_assignments mga
            JOIN {$prefix}members m ON mga.member_id = m.member_id
            WHERE mga.group_id IN ({$placeholders})
              AND {$activityWhere}
        ");
        $stmt->execute($groupIds);
        return (int) $stmt->fetchColumn();
    }

    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}members m WHERE {$activityWhere}");
    return (int) $stmt->fetchColumn();
}
