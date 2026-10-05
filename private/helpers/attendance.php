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

// Ob ein Termin schon zaehlt, entscheidet attendanceStartedSql() -- dieselbe
// Regel wie in Anwesenheitsliste und Kalender (OI-89): Startzeit minus
// Check-in-Vorlauf gegen die Datenbankuhr. Bis dahin stand hier ein eigener
// Datums-Cutoff, der einen Termin von heute Abend ab Mitternacht zaehlte.
require_once __DIR__ . '/appointment_attendance.php';
require_once __DIR__ . '/utils.php';   // checkinToleranceHours()

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
 * Spalten eines Registers S: die Terminarten von S selbst und die seiner
 * Gruppen P(S) aus subgroup_parents (Spec 2026-10-02, 5.1). Terminarten
 * anderer Gruppen, in denen Registermitglieder zufaellig auch stehen, gehoeren
 * nicht dazu. Haengt nicht vom Jahr ab -- die Spalten sollen beim
 * Jahreswechsel nicht springen, wie bei attendanceGroupTypes().
 *
 * Jede Zeile aus attendanceFetchGroupRows() kommt ueber S oder eine Gruppe
 * aus P(S) (expectedPairsScopeSql) und traegt damit eine Terminart dieser
 * Liste; die Vertragspruefung in attendanceBuildGroup() bleibt gueltig.
 *
 * @return array<int, array{type_id: int, type_name: ?string}>
 */
function attendanceSubgroupTypes($db, $database, int $subgroupId): array
{
    $prefix = $database->table('');

    $stmt = $db->prepare("
        SELECT DISTINCT atg.type_id, at.type_name
        FROM {$prefix}appointment_type_groups atg
        LEFT JOIN {$prefix}appointment_types at ON at.type_id = atg.type_id
        WHERE atg.group_id = ?
           OR atg.group_id IN (
                SELECT sp.group_id FROM {$prefix}subgroup_parents sp WHERE sp.subgroup_id = ?
           )
        ORDER BY at.type_name, atg.type_id
    ");
    $stmt->execute([$subgroupId, $subgroupId]);

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
 * Name, Gruppenart und Sortierung mehrerer Gruppen auf einmal.
 *
 * Eine Abfrage statt einer je Gruppe. Fehlt eine group_id im Ergebnis, gibt
 * es die Gruppe nicht mehr -- der Aufrufer ueberspringt sie dann.
 *
 * parent_group_names: Namen der Gruppen eines Registers aus subgroup_parents,
 * nach Name sortiert (Spec 2026-10-02, 5.1); leer bei gewoehnlichen Gruppen
 * und bei Registern ohne Gruppe.
 *
 * @param array<int, int> $groupIds
 * @return array<int, array{group_name: string, is_subgroup: bool, sort_order: int,
 *                          parent_group_names: array<int, string>}>
 */
function attendanceGroupMeta($db, $database, array $groupIds): array
{
    if ($groupIds === []) {
        return [];
    }

    $prefix       = $database->table('');
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

    $stmt = $db->prepare("
        SELECT group_id, group_name, is_subgroup, sort_order
        FROM {$prefix}member_groups
        WHERE group_id IN ({$placeholders})
    ");
    $ids = array_values(array_map('intval', $groupIds));
    $stmt->execute($ids);

    $meta = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta[(int) $row['group_id']] = [
            'group_name'         => (string) $row['group_name'],
            'is_subgroup'        => (int) $row['is_subgroup'] === 1,
            'sort_order'         => (int) $row['sort_order'],
            'parent_group_names' => [],
        ];
    }

    // Eine zweite Abfrage fuer alle Zuordnungen, nicht eine je Register.
    $parents = $db->prepare("
        SELECT sp.subgroup_id, p.group_name
        FROM {$prefix}subgroup_parents sp
        JOIN {$prefix}member_groups p ON p.group_id = sp.group_id
        WHERE sp.subgroup_id IN ({$placeholders})
        ORDER BY p.group_name, p.group_id
    ");
    $parents->execute($ids);
    foreach ($parents->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int) $row['subgroup_id'];
        if (isset($meta[$sid])) {
            $meta[$sid]['parent_group_names'][] = (string) $row['group_name'];
        }
    }

    return $meta;
}

/**
 * Je Mitglied und Terminart eine Zeile innerhalb einer Gruppe.
 *
 * Der Bereich kommt aus expectedPairsScopeSql(): bei einer gewoehnlichen
 * Gruppe die Paare, die ueber sie kommen, bei einem Register S die Paare, deren
 * Mitglied in S steht und deren Termin ueber S selbst oder eine Gruppe aus
 * P(S) kommt (Spec 2026-10-02, 5.1). DISTINCT auf (Mitglied, Termin) vor dem
 * Join auf records: Ein Termin, der ein Registermitglied ueber S und eine
 * Gruppe aus P(S) zugleich erreicht, zaehlt einmal. Die Gruppierung nach member_id und type_id ist die
 * Voraussetzung, die attendanceBuildGroup() prueft.
 *
 * @return array<int, array<string, mixed>>
 */
function attendanceFetchGroupRows($db, $database, int $groupId, int $year,
                                  ?int $memberId, ?int $appointmentTypeId): array
{
    require_once __DIR__ . '/expected_pairs.php';

    $prefix = $database->table('');
    [$epSql, $epParams]       = expectedPairsSql($database, [
        'year'         => $year,
        'type_id'      => $appointmentTypeId,
        'member_id'    => $memberId,
        'started_lead' => checkinToleranceHours($db, $database),
    ]);
    [$scopeSql, $scopeParams] = expectedPairsScopeSql($database, [$groupId]);

    $sql = "
        SELECT m.member_id, m.name, m.surname, p.type_id,
               COUNT(p.appointment_id)                                   AS total,
               SUM(CASE WHEN r.status = 'present' THEN 1 ELSE 0 END)     AS attended,
               SUM(CASE WHEN r.appointment_id IS NULL THEN 1 ELSE 0 END) AS unexcused
        FROM (
            SELECT DISTINCT ep.member_id, ep.appointment_id, ep.type_id
            FROM ({$epSql}) ep
            WHERE {$scopeSql}
        ) p
        JOIN {$prefix}members m ON m.member_id = p.member_id
        LEFT JOIN {$prefix}records r
             ON r.appointment_id = p.appointment_id AND r.member_id = p.member_id
        GROUP BY m.member_id, p.type_id
        ORDER BY m.surname, m.name, p.type_id
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($epParams, $scopeParams));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Je Mitglied eine Zeile ueber den Bereich mehrerer Gruppen, entdoppelt.
 *
 * DISTINCT (Mitglied, Termin) ist die Entdopplung: Erreicht ein Termin ein
 * Mitglied ueber zwei Gruppen, zaehlt er einmal. Ohne das waeren die
 * Kopfzahlen die Summe der Gruppentabellen.
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

    require_once __DIR__ . '/expected_pairs.php';

    $prefix = $database->table('');
    [$epSql, $epParams]       = expectedPairsSql($database, [
        'year'         => $year,
        'type_id'      => $appointmentTypeId,
        'member_id'    => $memberId,
        'started_lead' => checkinToleranceHours($db, $database),
    ]);
    [$scopeSql, $scopeParams] = expectedPairsScopeSql($database, $groupIds);

    $sql = "
        SELECT p.member_id,
               COUNT(p.appointment_id) AS total,
               SUM(CASE WHEN r.status = 'present' THEN 1 ELSE 0 END) AS attended,
               SUM(CASE WHEN r.status = 'excused' THEN 1 ELSE 0 END) AS excused
        FROM (
            SELECT DISTINCT ep.member_id, ep.appointment_id
            FROM ({$epSql}) ep
            WHERE {$scopeSql}
        ) p
        LEFT JOIN {$prefix}records r
             ON r.appointment_id = p.appointment_id AND r.member_id = p.member_id
        GROUP BY p.member_id
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($epParams, $scopeParams));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Zahl der Termine im Auswertungsbereich, jeder einmal.
 *
 * Gezaehlt werden Termine, zu denen im Bereich jemand erwartet wird -- ein
 * Termin ohne erwartetes Mitglied (alle vor ihm ausgetreten) traegt zu keiner
 * Quote bei und zaehlt deshalb auch hier nicht mehr (Spec 3.5).
 *
 * Mit $memberId zaehlen nur die Termine, zu denen dieses Mitglied erwartet
 * wird -- die Zahl gehoert zu denselben Paaren wie Quote und Tabellenzeilen.
 */
function attendanceDistinctAppointmentCount($db, $database, array $groupIds, int $year,
                                            ?int $memberId, ?int $appointmentTypeId): int
{
    if ($groupIds === []) {
        return 0;
    }

    require_once __DIR__ . '/expected_pairs.php';

    [$epSql, $epParams]       = expectedPairsSql($database, [
        'year'         => $year,
        'member_id'    => $memberId,
        'type_id'      => $appointmentTypeId,
        'started_lead' => checkinToleranceHours($db, $database),
    ]);
    [$scopeSql, $scopeParams] = expectedPairsScopeSql($database, $groupIds);

    $stmt = $db->prepare("SELECT COUNT(DISTINCT ep.appointment_id) FROM ({$epSql}) ep WHERE {$scopeSql}");
    $stmt->execute(array_merge($epParams, $scopeParams));

    return (int) $stmt->fetchColumn();
}

/**
 * Zahl der aktiven Mitglieder im Auswertungsbereich.
 *
 * Mit Gruppenfilter zaehlt, wer irgendwann im Jahr in einer der Gruppen war
 * und aktiv war (Gruppenzeitraum, Spec 2026-10-05, 5.2); ohne Filter alle
 * aktiven Mitglieder des Jahres.
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
        require_once __DIR__ . '/group_history.php';
        $assignments = groupAssignmentsSql($database);
        $overlaps    = groupAssignmentOverlapsYear('mga', $year);
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT mga.member_id)
            FROM {$assignments} mga
            JOIN {$prefix}members m ON mga.member_id = m.member_id
            WHERE mga.group_id IN ({$placeholders})
              AND {$overlaps}
              AND {$activityWhere}
        ");
        $stmt->execute($groupIds);
        return (int) $stmt->fetchColumn();
    }

    $stmt = $db->query("SELECT COUNT(*) FROM {$prefix}members m WHERE {$activityWhere}");
    return (int) $stmt->fetchColumn();
}
