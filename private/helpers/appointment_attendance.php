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
 * "Erwartet" folgt derselben Regel wie Anwesenheitsliste und Statistik:
 * Gruppen der Terminart (appointment_type_groups) mal Gruppenzuordnung des
 * Mitglieds, eingeschraenkt auf den Aktivzeitraum zum Termindatum
 * (getMemberActivityWhere). Gezaehlt wird in zwei Abfragen fuer die ganze
 * Liste -- Vorbild ist responsesAttachSummaries() in responses.php.
 */
declare(strict_types=1);

// getMemberActivityWhere() wird hier gebraucht. responses.php bindet
// member_activity.php bereits vor diesem Helfer ein, aber require_once ist
// unschaedlich und macht die Datei von der Einbindereihenfolge unabhaengig.
require_once __DIR__ . '/member_activity.php';

const ATTENDANCE_PRESENT = 'present';
const ATTENDANCE_EXCUSED = 'excused';
const ATTENDANCE_MISSING = 'missing';

/** Hat der Termin begonnen? Erst dann gibt es eine Anwesenheit. */
function attendanceHasStarted(array $appointment, ?string $now = null): bool
{
    $date = (string) ($appointment['date'] ?? '');
    if ($date === '') {
        return false;
    }
    $start = $date . ' ' . (string) ($appointment['start_time'] ?? '00:00:00');

    return $start <= ($now ?? date('Y-m-d H:i:s'));
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
 * Erwartete Mitglieder je Termin: Gruppen der Terminart
 * (appointment_type_groups) mal Gruppenzuordnung des Mitglieds, eingeschraenkt
 * auf den Aktivzeitraum zum Termindatum (getMemberActivityWhere). Ein
 * Mitglied in zwei Gruppen derselben Terminart zaehlt wegen SELECT DISTINCT
 * nur einmal.
 *
 * Gemeinsame Abfrage fuer attendanceAttachSummaries() unten und
 * responsesAttachSummaries() in responses.php -- beide fuehrten bislang
 * dieselbe SQL doppelt.
 *
 * @param array<int, int> $appointmentIds
 * @return array<int, array<int, bool>> appointmentId => [memberId => true]
 */
function attendanceExpectedMemberIds(PDO $db, string $prefix, array $appointmentIds): array
{
    $expectedBy = [];
    if ($appointmentIds === []) {
        return $expectedBy;
    }

    $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
    $activity     = getMemberActivityWhere('m', 'a.date');

    $stmt = $db->prepare("
        SELECT DISTINCT a.appointment_id, m.member_id
        FROM {$prefix}appointments a
        JOIN {$prefix}appointment_type_groups atg ON atg.type_id = a.type_id
        JOIN {$prefix}member_group_assignments mga ON mga.group_id = atg.group_id
        JOIN {$prefix}members m ON m.member_id = mga.member_id AND ({$activity})
        WHERE a.appointment_id IN ({$placeholders})
    ");
    $stmt->execute($appointmentIds);
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
    foreach ($appointments as $appointment) {
        if (attendanceHasStarted($appointment)) {
            $ids[] = (int) $appointment['appointment_id'];
        }
    }

    $expectedBy = [];
    $statusBy   = [];

    if ($ids !== []) {
        $prefix       = $database->table('');
        $expectedBy   = attendanceExpectedMemberIds($db, $prefix, $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $db->prepare("SELECT appointment_id, member_id, status
                              FROM {$prefix}records
                              WHERE appointment_id IN ({$placeholders})");
        $stmt->execute($ids);
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
