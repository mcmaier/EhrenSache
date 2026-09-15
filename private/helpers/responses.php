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
// TERMINRUECKMELDUNG (FI-1)
// ============================================
//
// Spec: docs/superpowers/specs/2026-09-14-terminrueckmeldung-design.md
//
// Aufbau wie attendance.php: formende Funktionen entscheiden und kommen ohne
// Datenbank aus (tests/suites/responses_unit.php), holende fuehren SQL aus.

require_once __DIR__ . '/member_activity.php';

const RESPONSE_STATUSES = ['yes', 'no', 'maybe'];

/** Frist, wenn weder Terminart noch Einstellung einen brauchbaren Wert tragen. */
const RESPONSE_DEADLINE_DEFAULT_HOURS = 24;

/** 30 Tage. Laenger im Voraus plant kein Verein eine verbindliche Frist. */
const RESPONSE_DEADLINE_MAX_HOURS = 720;

const RESPONSE_COMMENT_MAX_LENGTH = 255;

/** Vorgaben einer Terminart ohne Rueckmeldung. */
const RESPONSE_TYPE_DEFAULTS = [
    'responses_enabled'        => 0,
    'responses_names_visible'  => 0,
    'responses_require_excuse' => 0,
    'response_deadline_hours'  => null,
];

// ---- formend ---------------------------------------------------------------

/**
 * Stundenzahl aus Einstellung, Datenbank oder Anfrage; null, wenn unbrauchbar.
 */
function responseHoursFromRaw(mixed $raw): ?int
{
    if (is_int($raw)) {
        $value = $raw;
    } elseif (is_string($raw) && preg_match('/^\d+\z/', trim($raw)) === 1) {
        $value = (int) trim($raw);
    } else {
        return null;
    }

    return ($value >= 0 && $value <= RESPONSE_DEADLINE_MAX_HOURS) ? $value : null;
}

/**
 * Wirksame Frist: Terminart vor Einstellung vor Vorgabe.
 */
function responseDeadlineHours(mixed $typeHours, string $globalRaw): int
{
    return responseHoursFromRaw($typeHours)
        ?? responseHoursFromRaw($globalRaw)
        ?? RESPONSE_DEADLINE_DEFAULT_HOURS;
}

/**
 * Zeitpunkt der Frist als 'Y-m-d H:i:s'.
 *
 * Gerechnet in UTC, also ohne Zeitumstellung: Die Zuverlaessigkeit bildet
 * dieselbe Grenze in MySQL mit DATE_SUB auf DATETIME, und das kennt keine
 * Zeitzone. Liefen beide auseinander, waere eine Absage in der Oberflaeche
 * rechtzeitig und in der Statistik nicht.
 */
function responseDeadline(string $date, string $startTime, int $hours): string
{
    $utc = new DateTimeZone('UTC');
    $hours = max(0, $hours);

    return (new DateTimeImmutable("{$date} {$startTime}", $utc))
        ->modify("-{$hours} hours")
        ->format('Y-m-d H:i:s');
}

/**
 * Beide Werte als 'Y-m-d H:i:s' -- der Textvergleich ist dann chronologisch.
 *
 * $statusChangedAt ist lokale Wanduhrzeit, wie sie date('Y-m-d H:i:s') liefert
 * -- das UTC in responseDeadline() ist nur ein Rechenhilfsmittel, keine
 * eigene Zeitbasis.
 */
function responseIsLate(string $statusChangedAt, string $deadline): bool
{
    return $statusChangedAt > $deadline;
}

/**
 * $now ist lokale Wanduhrzeit, wie sie date('Y-m-d H:i:s') liefert -- das UTC
 * in responseDeadline() ist nur ein Rechenhilfsmittel, keine eigene Zeitbasis.
 */
function responseHasStarted(string $date, string $startTime, string $now): bool
{
    return $now >= "{$date} {$startTime}";
}

/** Fehlermeldung zur Eingabe oder null. */
function responseInputError(mixed $status, mixed $comment): ?string
{
    if (!is_string($status) || !in_array($status, RESPONSE_STATUSES, true)) {
        return "status muss 'yes', 'no' oder 'maybe' sein";
    }
    if ($comment !== null && !is_string($comment)) {
        return 'comment muss Text sein';
    }
    if (is_string($comment) && mb_strlen(trim($comment)) > RESPONSE_COMMENT_MAX_LENGTH) {
        return 'Die Bemerkung darf höchstens ' . RESPONSE_COMMENT_MAX_LENGTH . ' Zeichen lang sein';
    }

    return null;
}

function responseNormalizeComment(mixed $comment): ?string
{
    if (!is_string($comment)) {
        return null;
    }
    $comment = trim($comment);

    return $comment === '' ? null : $comment;
}

/**
 * Was mit dem Abwesenheitsantrag geschieht (Spec 5.4).
 *
 * @param ?string $newStatus null heisst: Antwort wird zurueckgenommen
 * @param ?string $excuseState Status des verknuepften Antrags, null ohne
 * @param bool $ownAbsenceExists Mitglied hat schon einen eigenen, nicht abgelehnten Antrag
 * @return 'none'|'create'|'link'|'update_reason'|'delete'|'keep'
 */
function responseExcuseAction(bool $requireExcuse, ?string $oldStatus, ?string $newStatus,
                              ?string $excuseState, bool $ownAbsenceExists): string
{
    if (!$requireExcuse) {
        return 'none';
    }

    if ($newStatus === 'no') {
        if ($excuseState === null) {
            return $ownAbsenceExists ? 'link' : 'create';
        }

        return $excuseState === 'pending' ? 'update_reason' : 'keep';
    }

    if ($oldStatus === 'no' && $excuseState === 'pending') {
        return 'delete';
    }

    return $excuseState === null ? 'none' : 'keep';
}

/**
 * @param array<int, int> $expectedMemberIds
 * @param array<int, string> $statusByMember member_id => status
 * @return array{yes: int, no: int, maybe: int, open: int}
 */
function responseSummary(array $expectedMemberIds, array $statusByMember): array
{
    $summary = ['yes' => 0, 'no' => 0, 'maybe' => 0, 'open' => 0];

    foreach (array_unique($expectedMemberIds) as $memberId) {
        $status = $statusByMember[$memberId] ?? null;
        if ($status !== null && isset($summary[$status]) && $status !== 'open') {
            $summary[$status]++;
        } else {
            $summary['open']++;
        }
    }

    return $summary;
}

/**
 * Gegenueberstellung nach Beginn (Spec 5.6), als Mitglieds-IDs je Feld.
 *
 * @param array<int, int> $expectedMemberIds
 * @param array<int, string> $statusByMember
 * @param array<int, int> $presentMemberIds
 * @return array<string, array<int, int>>
 */
function responseComparison(array $expectedMemberIds, array $statusByMember, array $presentMemberIds): array
{
    $fields = [
        'yes_present' => [], 'yes_absent' => [],
        'no_present' => [], 'no_absent' => [],
        'maybe_present' => [], 'maybe_absent' => [],
        'none_present' => [], 'none_absent' => [],
    ];
    $present = array_flip($presentMemberIds);

    foreach (array_unique($expectedMemberIds) as $memberId) {
        $status = $statusByMember[$memberId] ?? null;
        if (!in_array($status, RESPONSE_STATUSES, true)) {
            $status = 'none';
        }
        $fields[$status . (isset($present[$memberId]) ? '_present' : '_absent')][] = $memberId;
    }

    return $fields;
}

/**
 * Eine Zeile je Mitglied, die erste gewinnt. Die holende Abfrage sortiert nach
 * Gruppenname -- ein Mitglied in zwei Gruppen erscheint unter der ersten.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>> member_id => Zeile
 */
function responsesDedupeExpected(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $memberId = (int) $row['member_id'];
        if (!isset($out[$memberId])) {
            $out[$memberId] = $row;
        }
    }

    return $out;
}

/**
 * Einstellungen der Terminart aus einem Anfragekoerper.
 *
 * Nur mitgeschickte Felder aendern etwas: Das Terminart-PUT ist ein
 * Voll-Update (OI-54), und ein aelterer Client ohne diese Felder darf eine
 * eingeschaltete Rueckmeldung nicht still abschalten.
 *
 * @param array<string, int|null> $current
 * @return array<string, int|null>
 * @throws InvalidArgumentException bei ungueltiger Frist
 */
function responseTypeSettings(object $data, array $current): array
{
    $out = $current;

    foreach (['responses_enabled', 'responses_names_visible', 'responses_require_excuse'] as $flag) {
        if (property_exists($data, $flag)) {
            $out[$flag] = filter_var($data->$flag, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
    }

    if (property_exists($data, 'response_deadline_hours')) {
        $raw = $data->response_deadline_hours;
        if ($raw === null || $raw === '') {
            $out['response_deadline_hours'] = null;
        } else {
            $hours = responseHoursFromRaw(is_int($raw) ? $raw : (is_string($raw) ? $raw : null));
            if ($hours === null) {
                throw new InvalidArgumentException(
                    'response_deadline_hours muss leer oder eine ganze Zahl von 0 bis '
                    . RESPONSE_DEADLINE_MAX_HOURS . ' sein'
                );
            }
            $out['response_deadline_hours'] = $hours;
        }
    }

    return $out;
}

// ---- holend ----------------------------------------------------------------

/** Termin mit den Einstellungen seiner Terminart, oder null. */
function responsesFetchAppointment($db, $database, int $appointmentId): ?array
{
    $prefix = $database->table('');
    $stmt = $db->prepare("
        SELECT a.appointment_id, a.title, a.date, a.start_time, a.type_id,
               t.type_name, t.color,
               COALESCE(t.responses_enabled, 0)        AS responses_enabled,
               COALESCE(t.responses_names_visible, 0)  AS responses_names_visible,
               COALESCE(t.responses_require_excuse, 0) AS responses_require_excuse,
               t.response_deadline_hours
        FROM {$prefix}appointments a
        LEFT JOIN {$prefix}appointment_types t ON t.type_id = a.type_id
        WHERE a.appointment_id = ?
    ");
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * Erwartete Mitglieder: Terminart -> Gruppe -> Mitglied, aktiv am Termindatum.
 * Derselbe Weg wie punctualityScope(). Ein Mitglied in zwei Gruppen kommt
 * zweimal -- entdoppelt wird mit responsesDedupeExpected().
 */
function responsesFetchExpected($db, $database, int $appointmentId): array
{
    $prefix   = $database->table('');
    $activity = getMemberActivityWhere('m', 'a.date');

    $stmt = $db->prepare("
        SELECT m.member_id, m.name, m.surname, g.group_id, g.group_name
        FROM {$prefix}appointments a
        JOIN {$prefix}appointment_type_groups atg ON atg.type_id = a.type_id
        JOIN {$prefix}member_group_assignments mga ON mga.group_id = atg.group_id
        JOIN {$prefix}member_groups g ON g.group_id = mga.group_id
        JOIN {$prefix}members m ON m.member_id = mga.member_id AND {$activity}
        WHERE a.appointment_id = ?
        ORDER BY g.group_name, m.surname, m.name
    ");
    $stmt->execute([$appointmentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Antworten eines Termins, member_id => Zeile, mit dem Status des verknuepften Antrags.
 *
 * @return array<int, array<string, mixed>>
 */
function responsesFetchForAppointment($db, $database, int $appointmentId): array
{
    $prefix = $database->table('');
    $stmt = $db->prepare("
        SELECT r.member_id, r.status, r.comment, r.status_changed_at, r.updated_at,
               r.exception_id, e.status AS excuse_state
        FROM {$prefix}appointment_responses r
        LEFT JOIN {$prefix}exceptions e ON e.exception_id = r.exception_id
        WHERE r.appointment_id = ?
    ");
    $stmt->execute([$appointmentId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['member_id']] = $row;
    }

    return $out;
}

/** @return array<int, int> */
function responsesFetchPresentMemberIds($db, $database, int $appointmentId): array
{
    $prefix = $database->table('');
    $stmt = $db->prepare("SELECT DISTINCT member_id FROM {$prefix}records
                          WHERE appointment_id = ? AND status = 'present'");
    $stmt->execute([$appointmentId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Kommende Termine mit Rueckmeldung, zu denen das Mitglied erwartet ist.
 * Begrenzt auf 50: Die Liste ist zum Antworten da, nicht als Jahresplan.
 *
 * @return array<int, int>
 */
function responsesFetchUpcomingIds($db, $database, int $memberId, string $now): array
{
    $prefix   = $database->table('');
    $activity = getMemberActivityWhere('m', 'a.date');

    $stmt = $db->prepare("
        SELECT DISTINCT a.appointment_id, a.date, a.start_time
        FROM {$prefix}appointments a
        JOIN {$prefix}appointment_types t ON t.type_id = a.type_id AND t.responses_enabled = 1
        JOIN {$prefix}appointment_type_groups atg ON atg.type_id = a.type_id
        JOIN {$prefix}member_group_assignments mga
             ON mga.group_id = atg.group_id AND mga.member_id = ?
        JOIN {$prefix}members m ON m.member_id = mga.member_id AND {$activity}
        WHERE a.date >= DATE(?) AND CONCAT(a.date, ' ', a.start_time) > ?
        ORDER BY a.date, a.start_time
        LIMIT 50
    ");
    $stmt->execute([$memberId, $now, $now]);

    return array_map(static fn ($r) => (int) $r['appointment_id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** Eine Antwort mit Antragsstatus, gesperrt fuer die laufende Transaktion. */
function responsesFetchOneForUpdate($db, $database, int $appointmentId, int $memberId): ?array
{
    $prefix = $database->table('');
    $stmt = $db->prepare("
        SELECT r.response_id, r.status, r.comment, r.status_changed_at, r.exception_id,
               e.status AS excuse_state
        FROM {$prefix}appointment_responses r
        LEFT JOIN {$prefix}exceptions e ON e.exception_id = r.exception_id
        WHERE r.appointment_id = ? AND r.member_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$appointmentId, $memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/** Juengster eigener, nicht abgelehnter Abwesenheitsantrag zum Termin, oder null. */
function responsesFetchOwnAbsence($db, $database, int $appointmentId, int $memberId): ?array
{
    $prefix = $database->table('');
    $stmt = $db->prepare("
        SELECT exception_id, status FROM {$prefix}exceptions
        WHERE appointment_id = ? AND member_id = ?
          AND exception_type = 'absence' AND status <> 'rejected'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$appointmentId, $memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}
