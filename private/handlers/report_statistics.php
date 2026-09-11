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
// ANWESENHEITSBERICHT (Druckansicht)
// ============================================

/**
 * Anwesenheitsbericht als druckbare HTML-Seite.
 *
 * Jahresbasiert wie die Statistik-Sektion, deren Filter er uebernimmt. Ein
 * freier Zeitraum wuerde die Statistik-SQL umbauen; Bericht und Bildschirm
 * koennten dann verschiedene Zahlen zeigen.
 *
 * Kein CSV: Anwesenheitsdaten liefert export&type=records bereits.
 */
function handleStatisticsReport($db, $database, $request_method, $authUserRole, $authMemberId): void
{
    if ($request_method !== 'GET') {
        http_response_code(405);
        echo json_encode(["message" => "Method not allowed"]);
        exit();
    }

    $year     = isset($_GET['year'])      ? intval($_GET['year'])      : (int) date('Y');
    $groupId  = isset($_GET['group_id'])  ? intval($_GET['group_id'])  : null;
    $memberId = isset($_GET['member_id']) ? intval($_GET['member_id']) : null;

    if (!isAdminOrManager()) {
        // Fremde member_id wird ignoriert, nicht abgewiesen -- dieselbe Linie
        // wie handleStatistics(). Eine Fehlermeldung waere ein Orakel darueber,
        // welche IDs existieren.
        $memberId = $authMemberId;

        if ($memberId === null) {
            http_response_code(403);
            echo json_encode(["message" => "Kein Mitglied mit diesem Benutzer verknüpft"],
                             JSON_UNESCAPED_UNICODE);
            exit();
        }

        if ($groupId !== null
            && !hasStatisticsGroupAccess($db, $database, $authMemberId, $authUserRole, $groupId)) {
            http_response_code(403);
            echo json_encode(["message" => "No access to this group"]);
            exit();
        }
    }

    // Benannte Argumente an der neuen Aufrufstelle: Drei benachbarte ?int-
    // Parameter sind bei Vertauschung fuer PHP nicht erkennbar, und der Fehler
    // waere ein Bericht ueber die falsche Person.
    $result = buildStatisticsResult(
        $db, $database,
        year:              $year,
        groupId:           $groupId,
        memberId:          $memberId,
        appointmentTypeId: null,
        role:              $authUserRole,
        authMemberId:      $authMemberId
    );

    $sections = [statisticsReportSummarySection($result['summary'])];

    foreach ($result['statistics'] as $group) {
        $sections[] = statisticsReportGroupSection($group);
    }

    if ($memberId !== null) {
        // Terminarten aus demselben Ergebnis, aus dem die Quoten stammen --
        // nicht neu abgeleitet, siehe statisticsReportAppointments().
        $typeIds = [];
        foreach ($result['statistics'] as $group) {
            $typeIds[(int) $group['appointment_type_id']] = true;
        }

        $appointments = statisticsReportAppointments(
            $db, $database, $memberId, $year, array_keys($typeIds)
        );
        $sections[] = statisticsReportDetailSection($appointments);
    }

    renderReport($db, $database, [
        'title'    => 'Anwesenheitsbericht',
        'period'   => 'Jahr ' . $year,
        'sections' => $sections,
        'notes'    => statisticsReportNotes($result['statistics']),
    ]);
}

/** Kennzahlen des Gesamtergebnisses, zweispaltig. */
function statisticsReportSummarySection(array $summary): array
{
    return [
        'heading' => null,
        'class'   => 'report-summary',
        'columns' => ['Kennzahl', 'Wert'],
        'rows'    => [
            ['Termine gesamt',   (string) $summary['total_appointments']],
            ['Anwesend',         (string) $summary['total_present']],
            ['Entschuldigt',     (string) $summary['total_excused']],
            ['Unentschuldigt',   (string) $summary['total_unexcused']],
            ['Durchschnittliche Anwesenheitsquote', statisticsReportRate($summary['overall_average'])],
        ],
        'empty'   => 'Keine Kennzahlen für dieses Jahr.',
    ];
}

/** Ein Abschnitt je Gruppe: eine Zeile je Mitglied. */
function statisticsReportGroupSection(array $group): array
{
    $rows = [];

    foreach ($group['members'] as $member) {
        $rows[] = [
            $member['member_name'],
            (string) $member['total_appointments'],
            (string) $member['attended'],
            (string) $member['excused'],
            (string) $member['unexcused_absences'],
            statisticsReportRate($member['attendance_rate']),
        ];
    }

    return [
        'heading' => $group['group_name'],
        'columns' => ['Mitglied', 'Termine', 'Anwesend', 'Entschuldigt', 'Unentschuldigt', 'Quote'],
        'rows'    => $rows,
        'empty'   => 'Für dieses Jahr sind in dieser Gruppe keine Termine erfasst.',
    ];
}

/** Quote mit deutschem Komma und Prozentzeichen. */
function statisticsReportRate($rate): string
{
    return number_format((float) $rate, 1, ',', '') . ' %';
}

/**
 * Termine eines Mitglieds im Jahr, mit Status und Herkunft der Ankunftszeit.
 *
 * Die Terminarten kommen als Parameter herein, aus demselben Ergebnis, aus dem
 * auch die Quoten stammen. Das ist der Kern dieser Funktion: Eine eigene
 * Ableitung wuerde eine andere Menge treffen als die Rechnung darueber -- die
 * Zuordnung Gruppe -> Terminart ist im Bestand nicht eindeutig (OI-48) --, und
 * auf dem Blatt staende eine Liste, die der Quote widerspricht.
 *
 * Kein DISTINCT noetig: Ueber die Terminarten gefiltert erscheint jeder Termin
 * genau einmal, ohne Umweg ueber die Gruppenzuordnung.
 *
 * @param array<int, int> $typeIds Terminarten, ueber die gerechnet wurde
 * @return array<int, array<string, mixed>>
 */
function statisticsReportAppointments($db, $database, int $memberId, int $year, array $typeIds): array
{
    if ($typeIds === []) {
        return [];
    }

    require_once __DIR__ . '/../helpers/member_activity.php';
    require_once __DIR__ . '/../helpers/attendance.php';

    $prefix        = $database->table('');
    $activityWhere = getMemberActivityWhereYear($year, 'm');
    $placeholders  = implode(',', array_fill(0, count($typeIds), '?'));

    $sql = "
        SELECT
            a.appointment_id,
            a.date,
            a.start_time,
            a.title,
            at.type_name,
            r.arrival_time,
            r.status,
            r.checkin_source,
            (SELECT COUNT(*) FROM {$prefix}exceptions e
              WHERE e.member_id      = m.member_id
                AND e.appointment_id = a.appointment_id
                AND e.exception_type = 'time_correction'
                AND e.status         = 'approved') AS corrected
        FROM {$prefix}appointments a
        JOIN {$prefix}appointment_types at ON at.type_id = a.type_id
        JOIN {$prefix}members m
            ON m.member_id = ?
            AND {$activityWhere}
        LEFT JOIN {$prefix}records r
            ON r.appointment_id = a.appointment_id
            AND r.member_id     = m.member_id
        WHERE a.type_id IN ({$placeholders})
          AND YEAR(a.date) = ?
          AND a.date <= " . ATTENDANCE_STARTED_CUTOFF_SQL . "
        ORDER BY a.date, a.start_time
    ";

    $params = array_merge([$memberId], $typeIds, [$year]);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Die drei Herkunftsstufen als Konstanten.
 *
 * Sie stehen in der Tabelle UND in den Fussnoten, die sie erklaeren. Als
 * Literale an beiden Stellen wuerde eine Umbenennung die Fussnote auf einen
 * Begriff zeigen lassen, den die Tabelle nicht mehr kennt. Dieses Muster --
 * dieselbe Angabe zweimal erzeugt statt einmal weitergereicht -- hat in diesem
 * Zweig bereits zweimal zugeschlagen: bei der Formel fuer 'Entschuldigt' und
 * bei der Statistik-Aggregation selbst.
 */
const REPORT_ORIGIN_MEASURED   = 'gemessen';
const REPORT_ORIGIN_CORRECTED  = 'korrigiert';
const REPORT_ORIGIN_BACKFILLED = 'nachgetragen';

/**
 * Herkunft einer Ankunftszeit.
 *
 * arrival_time ist nicht durchgehend eine Messung: Legt ein Admin einen
 * Eintrag ohne Zeit an, setzt records.php die Startzeit des Termins -- der
 * Datensatz ist damit konstruiert puenktlich. Diese Uhrzeit ununterschieden
 * auf einen Nachweis zu drucken, behauptet eine Messung, die nie stattfand.
 *
 * 'korrigiert' schlaegt 'gemessen': Eine genehmigte Zeitkorrektur ueber-
 * schreibt arrival_time, laesst checkin_source aber unveraendert
 * (handleApprovedTimeCorrection() in helpers/utils.php). Ohne diese
 * Vorrangregel truege eine korrigierte Zeit weiter das Etikett der
 * urspruenglichen Messung.
 *
 * Uebergangsloesung: Kuenftig traegt records eine eigene Spalte dafuer.
 */
function statisticsReportOrigin(?string $source, int $corrected): string
{
    if ($corrected > 0) {
        return REPORT_ORIGIN_CORRECTED;
    }

    if (in_array($source, ['station_pin', 'device_auth', 'user_totp', 'auto_checkin'], true)) {
        return REPORT_ORIGIN_MEASURED;
    }

    return REPORT_ORIGIN_BACKFILLED;
}

/** Terminliste eines Mitglieds als Abschnitt. */
function statisticsReportDetailSection(array $appointments): array
{
    $rows = [];

    foreach ($appointments as $row) {
        $status = 'Unentschuldigt';
        if ($row['status'] === 'present') {
            $status = 'Anwesend';
        } elseif ($row['status'] === 'excused') {
            $status = 'Entschuldigt';
        }

        // Ankunft nur bei tatsaechlicher Anwesenheit. Eine Ankunftszeit fuer
        // jemanden, der nicht da war, ist keine Angabe, sondern ein Artefakt.
        $arrival = '';
        $origin  = '';
        if ($row['status'] === 'present' && !empty($row['arrival_time'])) {
            $arrival = date('H:i', strtotime((string) $row['arrival_time']));
            $origin  = statisticsReportOrigin($row['checkin_source'], (int) $row['corrected']);
        }

        $rows[] = [
            date('d.m.Y', strtotime((string) $row['date'])),
            $row['title'] ?? '',
            $row['type_name'] ?? '',
            $status,
            $arrival,
            $origin,
        ];
    }

    return [
        'heading' => 'Termine im Einzelnen',
        'columns' => ['Datum', 'Termin', 'Terminart', 'Status', 'Ankunft', 'Herkunft'],
        'rows'    => $rows,
        'empty'   => 'Für dieses Jahr sind keine Termine erfasst.',
    ];
}

/**
 * Fussnoten des Anwesenheitsberichts.
 *
 * Ohne die erste Zeile behauptet der Bericht Vollstaendigkeit fuer ein Jahr,
 * das noch laeuft. Ohne die dritte verschweigt er, dass automatisch erzeugte
 * Eintraege mitzaehlen (OI-20).
 *
 * @param array<int, array<string, mixed>> $statistics Gruppenergebnisse aus
 *                                                      buildStatisticsResult()
 * @return array<int, string>
 */
function statisticsReportNotes(array $statistics): array
{
    $notes = [
        'Gezählt werden nur Termine, die zum Zeitpunkt der Erstellung bereits begonnen haben.',
        'Termine ohne Eintrag gelten als unentschuldigt.',
        'Automatisch erzeugte Check-ins zählen wie erfasste Anwesenheiten.',
        'Der Bericht berücksichtigt die Zeiträume der Mitgliedschaft.',
    ];

    // Ohne diese Zeile bliebe unsichtbar, dass je Gruppe nur eine einzige
    // Terminart in die Quote eingeht (OI-48) -- das Blatt soll ohne Vorwissen
    // erkennbar machen, worauf die Zahl beruht. Deshalb liefert
    // calculateGroupStatistics() das Feld appointment_type_name mit.
    if ($statistics !== []) {
        $perGroup = [];
        foreach ($statistics as $group) {
            $typeName   = $group['appointment_type_name'] ?? null;
            $perGroup[] = $group['group_name'] . ' — ' . ($typeName ?? 'ohne Terminart');
        }
        $notes[] = 'Ausgewertet wurden je Gruppe die Termine einer Terminart: '
            . implode('; ', $perGroup) . '.';
    }

    $notes[] = REPORT_ORIGIN_MEASURED . ': Die Ankunftszeit wurde bei der Anmeldung an einer Station oder in der App '
        . 'aufgezeichnet.';
    $notes[] = REPORT_ORIGIN_CORRECTED . ': Die Ankunftszeit wurde auf Antrag geändert und genehmigt.';
    // Bewusst offen formuliert: Neben Eintraegen von Hand und aus dem Import
    // faellt hierunter auch die Quelle 'timer' aus Installationen vor 1.2.3.
    // Deren Zeitstempel stammt von einer Maschine, misst aber den Beginn einer
    // Arbeitssitzung statt der Ankunft am Termin. "Von Hand erfasst" waere fuer
    // diese Zeilen schlicht falsch, "gemessen" waere irrefuehrend.
    $notes[] = REPORT_ORIGIN_BACKFILLED . ': Die Ankunftszeit wurde nicht bei der Anmeldung zu diesem Termin '
        . 'aufgezeichnet — sie wurde von Hand erfasst, eingelesen oder stammt aus einem anderen '
        . 'Vorgang; sie entspricht gegebenenfalls der Startzeit des Termins und ist dann keine '
        . 'Messung.';

    return $notes;
}
