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

/**
 * Anwesenheitszahlen je Termin (Spec 2026-09-22-kalender-anwesenheit).
 *
 * "Erwartet" folgt derselben Regel wie Anwesenheitsliste und Statistik. Die
 * Soll-Menge (Gruppen der Terminart mal Gruppenzuordnung des Mitglieds,
 * eingeschraenkt auf den Aktivzeitraum zum Termindatum) steht in
 * expected_pairs.php (expectedPairsSql()); attendanceExpectedMemberIds() liest
 * sie von dort. Gezaehlt wird in zwei Abfragen fuer die ganze Liste, im selben Stil wie responsesAttachSummaries() in responses.php --
 * die beiden Helfer stehen nebeneinander, responses.php bindet diesen hier
 * zusaetzlich ein (fuer attendanceExpectedMemberIds()).
 */
declare(strict_types=1);

// Diese Datei ruft getMemberActivityWhere() nicht mehr selbst auf (die Joins
// leben in expected_pairs.php). Der Include bleibt der Ladereihenfolge wegen:
// Aufrufer wie handlers/appointments.php binden nur diesen Helfer ein und
// verlassen sich darauf, dass member_activity.php mitkommt.
require_once __DIR__ . '/member_activity.php';

const ATTENDANCE_PRESENT = 'present';
const ATTENDANCE_EXCUSED = 'excused';
const ATTENDANCE_MISSING = 'missing';

/**
 * Hat der Termin begonnen? Erst dann gibt es eine Anwesenheit (OI-89).
 *
 * "Begonnen" heisst: Das Check-in-Fenster ist offen, also Startzeit minus
 * $leadHours (Einstellung checkin_tolerance_hours, checkinToleranceHours()).
 * auto_checkin.php erfasst symmetrisch um den Start -- in der Aufbauphase
 * liegen schon Erfassungen vor, der Termin darf dann nicht "kommend" heissen.
 *
 * Dieselbe Regel steht als SQL in attendanceStartedSql(). $now kommt
 * bevorzugt von der Datenbankuhr (attendanceDbNow(), OI-60), damit beide
 * gleich umschlagen.
 */
function attendanceHasStarted(array $appointment, int $leadHours = 0, ?string $now = null): bool
{
    $date = (string) ($appointment['date'] ?? '');
    if ($date === '') {
        return false;
    }
    $start = strtotime($date . ' ' . (string) ($appointment['start_time'] ?? '00:00:00'));
    if ($start === false) {
        return false;
    }
    $opens = date('Y-m-d H:i:s', $start - $leadHours * 3600);

    return $opens <= ($now ?? date('Y-m-d H:i:s'));
}

/**
 * SQL-Fassung von attendanceHasStarted(): Startzeitpunkt gegen NOW() der
 * Datenbank. Ersetzt ATTENDANCE_STARTED_CUTOFF_SQL, das nur nach Datum
 * schnitt.
 *
 * $leadHours ist ein int, $alias wird geprueft -- die Verkettung ist deshalb
 * unbedenklich.
 */
function attendanceStartedSql(int $leadHours, string $alias = 'a'): string
{
    if (preg_match('/^[a-z_][a-z0-9_]*$/i', $alias) !== 1) {
        throw new InvalidArgumentException('Ungueltiger Tabellenalias: ' . $alias);
    }

    return "TIMESTAMP({$alias}.date, {$alias}.start_time) <= NOW() + INTERVAL {$leadHours} HOUR";
}

/** Uhrzeit der Datenbank, damit PHP- und SQL-Fassung gleich umschlagen (OI-60). */
function attendanceDbNow($db): string
{
    return (string) $db->query('SELECT NOW()')->fetchColumn();
}

/** Rohstatus eines Records auf die drei Werte der Anzeige abbilden. */
function attendanceStatusOf(?string $recordStatus): string
{
    if ($recordStatus === ATTENDANCE_PRESENT) {
        return ATTENDANCE_PRESENT;
    }
    if ($recordStatus === ATTENDANCE_EXCUSED) {
        return ATTENDANCE_EXCUSED;
    }

    return ATTENDANCE_MISSING;
}

/**
 * @param array<int, bool>   $expected Mitglieder, die erwartet werden
 * @param array<int, string> $status   Rohstatus je Mitglied aus records
 * @return array{expected:int,present:int,excused:int,missing:int}
 */
function attendanceCounts(array $expected, array $status): array
{
    $present = 0;
    $excused = 0;

    foreach (array_keys($expected) as $memberId) {
        $value = attendanceStatusOf($status[$memberId] ?? null);
        if ($value === ATTENDANCE_PRESENT) {
            $present++;
        } elseif ($value === ATTENDANCE_EXCUSED) {
            $excused++;
        }
    }

    $expectedCount = count($expected);

    // Anwesenheiten von Mitgliedern, die nicht (mehr) erwartet werden, zaehlen
    // nicht mit -- missing kann deshalb nie negativ werden.
    return [
        'expected' => $expectedCount,
        'present'  => $present,
        'excused'  => $excused,
        'missing'  => max(0, $expectedCount - $present - $excused),
    ];
}

/**
 * Erwartete Mitglieder je Termin, aus der Soll-Menge (expected_pairs.php).
 * Ein Mitglied in zwei Gruppen derselben Terminart zaehlt einmal.
 *
 * Gemeinsame Abfrage fuer attendanceAttachSummaries() unten und
 * responsesAttachSummaries() in responses.php. Die Regel steht seit
 * 2026-10-01 nur noch in expectedPairsSql() -- auch die Statistik liest dort.
 *
 * $memberId grenzt auf ein Mitglied ein (OI-98): Wer nur den eigenen Status
 * braucht, rechnet nicht die Soll-Menge des ganzen Vereins.
 *
 * @param array<int, int> $appointmentIds
 * @return array<int, array<int, bool>> appointmentId => [memberId => true]
 */
function attendanceExpectedMemberIds(PDO $db, $database, array $appointmentIds, ?int $memberId = null): array
{
    $expectedBy = [];
    if ($appointmentIds === []) {
        return $expectedBy;
    }

    require_once __DIR__ . '/expected_pairs.php';

    $filter = ['appointment_ids' => $appointmentIds];
    if ($memberId !== null) {
        $filter['member_id'] = $memberId;
    }
    [$epSql, $epParams] = expectedPairsSql($database, $filter);

    $stmt = $db->prepare("SELECT DISTINCT ep.appointment_id, ep.member_id FROM ({$epSql}) ep");
    $stmt->execute($epParams);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $expectedBy[(int) $row['appointment_id']][(int) $row['member_id']] = true;
    }

    return $expectedBy;
}

/**
 * Haengt je Termin die Anwesenheit an:
 *   Verwalter: attendance = {expected, present, excused, missing}
 *   sonst:     own_attendance = 'present'|'excused'|'missing'|null
 * Termine, die noch nicht begonnen haben, bekommen null.
 */
function attendanceAttachSummaries($db, $database, array $appointments, ?int $viewerMemberId,
                                   bool $forManager): array
{
    $ids = [];
    if ($appointments !== []) {
        $leadHours = checkinToleranceHours($db, $database);
        $now       = attendanceDbNow($db);
    }
    foreach ($appointments as $appointment) {
        if (attendanceHasStarted($appointment, $leadHours, $now)) {
            $ids[] = (int) $appointment['appointment_id'];
        }
    }

    $expectedBy = [];
    $statusBy   = [];

    // Mitglieder sehen nur den eigenen Status (unten). Soll-Menge und Records
    // aller anderen braucht es dafuer nicht -- bei 500 Mitgliedern und 600
    // Terminen im Jahr kostete das rund 260 ms je Jahresabruf (OI-98). Ohne
    // verknuepftes Mitglied gibt es nichts zu zaehlen.
    $onlyMember = $forManager ? null : $viewerMemberId;

    if ($ids !== [] && ($forManager || $onlyMember !== null)) {
        $prefix       = $database->table('');
        $expectedBy   = attendanceExpectedMemberIds($db, $database, $ids, $onlyMember);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql    = "SELECT appointment_id, member_id, status
                   FROM {$prefix}records
                   WHERE appointment_id IN ({$placeholders})";
        $params = $ids;
        if ($onlyMember !== null) {
            $sql     .= " AND member_id = ?";
            $params[] = $onlyMember;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusBy[(int) $row['appointment_id']][(int) $row['member_id']] = (string) $row['status'];
        }
    }

    $started = array_flip($ids);

    foreach ($appointments as &$appointment) {
        $appointmentId = (int) $appointment['appointment_id'];

        if (!isset($started[$appointmentId])) {
            if ($forManager) {
                $appointment['attendance'] = null;
            } else {
                $appointment['own_attendance'] = null;
            }
            continue;
        }

        $expected = $expectedBy[$appointmentId] ?? [];
        $status   = $statusBy[$appointmentId] ?? [];

        if ($forManager) {
            $appointment['attendance'] = attendanceCounts($expected, $status);
            continue;
        }

        // Mitglieder sehen nur den eigenen Status, nie Zahlen ueber andere.
        $appointment['own_attendance'] = ($viewerMemberId !== null && isset($expected[$viewerMemberId]))
            ? attendanceStatusOf($status[$viewerMemberId] ?? null)
            : null;
    }
    unset($appointment);

    return $appointments;
}
