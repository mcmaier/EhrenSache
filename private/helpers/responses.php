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
 * Was mit dem Abwesenheitsantrag geschieht (Spec 5.4, Entscheidungen 3b, 4b und A1).
 *
 * Entscheidung 3b: Ein Antrag, den das Mitglied unabhaengig ueber exceptions
 * gestellt hat (nur verknuepft, $excuseCreatedByResponse = false), wird von
 * einer Rueckmeldung nie geaendert oder geloescht -- nur ein Antrag, den die
 * Rueckmeldung selbst angelegt hat.
 *
 * Entscheidung 4b: Ein neuer Antrag entsteht nur bei einem echten Wechsel auf
 * 'no'. Bleibt der Status 'no' (z. B. weil ein Verwalter den zuvor erzeugten
 * Antrag geloescht hat und nur die Bemerkung geaendert wird), entsteht keiner.
 *
 * Entscheidung A1 (Review, ergaenzt am 2026-09-15): Ein abgelehnter Antrag
 * blockiert keinen neuen. Bei einem echten Wechsel auf 'no' zaehlt ein
 * abgelehnter verknuepfter Antrag wie keiner -- es entsteht ein neuer bzw.
 * wird ein eigener, nicht abgelehnter Antrag verknuepft. Wechselt das Mitglied
 * danach von 'no' weg, loest 'unlink' die Verknuepfung; der abgelehnte Antrag
 * selbst bleibt unangetastet. Bei reiner Bemerkungsaenderung ('no' -> 'no')
 * bleibt ein abgelehnter Antrag verknuepft ('keep') -- das ist kein echter
 * Statuswechsel.
 *
 * @param ?string $newStatus null heisst: Antwort wird zurueckgenommen
 * @param ?string $excuseState Status des verknuepften Antrags, null ohne
 * @param bool $ownAbsenceExists Mitglied hat schon einen eigenen, nicht abgelehnten Antrag
 * @param bool $excuseCreatedByResponse Der verknuepfte Antrag wurde von einer Rueckmeldung angelegt, nicht nur verknuepft
 * @return 'none'|'create'|'link'|'update_reason'|'delete'|'unlink'|'keep'
 */
function responseExcuseAction(bool $requireExcuse, ?string $oldStatus, ?string $newStatus,
                              ?string $excuseState, bool $ownAbsenceExists,
                              bool $excuseCreatedByResponse): string
{
    if (!$requireExcuse) {
        return 'none';
    }

    $realChangeToNo = $newStatus === 'no' && $oldStatus !== 'no';

    if ($newStatus === 'no') {
        // A1: ein abgelehnter Antrag zaehlt bei einem echten Statuswechsel wie
        // keiner -- er blockiert weder einen neuen Antrag noch eine Verknuepfung.
        if ($excuseState === null || ($excuseState === 'rejected' && $realChangeToNo)) {
            // 4b: keine echte Statusaenderung -- der alte Antrag wurde entfernt
            // (etwa durch den Verwalter), eine reine Bemerkungsaenderung legt
            // keinen neuen an.
            if (!$realChangeToNo) {
                return 'none';
            }

            return $ownAbsenceExists ? 'link' : 'create';
        }

        if ($excuseState !== 'pending') {
            return 'keep';
        }

        // 3b: nur ein von der Rueckmeldung selbst angelegter Antrag wird mitgezogen.
        return $excuseCreatedByResponse ? 'update_reason' : 'keep';
    }

    if ($oldStatus === 'no' && $excuseState === 'pending') {
        // 3b: nur ein von der Rueckmeldung selbst angelegter Antrag wird geloescht.
        return $excuseCreatedByResponse ? 'delete' : 'keep';
    }

    if ($oldStatus === 'no' && $excuseState === 'rejected') {
        // A1: die Verknuepfung loest sich, der abgelehnte Antrag selbst bleibt bestehen.
        return 'unlink';
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
 * G2: dieselbe zusaetzliche JOIN-Bindung an Mitglied, Termin und
 * exception_type wie responsesFetchOneForUpdate() -- aus demselben Grund
 * (A2): eine wiederverwendete exception_id darf auch hier nie als
 * excuse_state eines fremden Antrags erscheinen.
 *
 * @return array<int, array<string, mixed>>
 */
function responsesFetchForAppointment($db, $database, int $appointmentId): array
{
    $prefix = $database->table('');
    $stmt = $db->prepare("
        SELECT r.member_id, r.status, r.comment, r.status_changed_at, r.updated_at,
               r.exception_id, r.exception_created, e.status AS excuse_state
        FROM {$prefix}appointment_responses r
        LEFT JOIN {$prefix}exceptions e ON e.exception_id = r.exception_id
            AND e.member_id = r.member_id AND e.appointment_id = r.appointment_id
            AND e.exception_type = 'absence'
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

/**
 * Eine Antwort mit Antragsstatus, gesperrt fuer die laufende Transaktion.
 *
 * Die JOIN-Bedingung bindet den verknuepften Antrag zusaetzlich an Mitglied,
 * Termin und exception_type = 'absence' (A2, Review): exception_id ist eine
 * fortlaufende ID -- geht ein Antrag verloren und eine andere Zeile erbt
 * zufaellig dieselbe ID (z. B. nach Loeschen und Neuanlegen), darf sie nie als
 * excuse_state eines fremden Antrags erscheinen. Passt sie nicht, liefert die
 * JOIN-Spalte NULL, exakt wie ohne Verknuepfung.
 */
function responsesFetchOneForUpdate($db, $database, int $appointmentId, int $memberId): ?array
{
    $prefix = $database->table('');
    $stmt = $db->prepare("
        SELECT r.response_id, r.status, r.comment, r.status_changed_at, r.exception_id,
               r.exception_created, e.status AS excuse_state
        FROM {$prefix}appointment_responses r
        LEFT JOIN {$prefix}exceptions e ON e.exception_id = r.exception_id
            AND e.member_id = r.member_id AND e.appointment_id = r.appointment_id
            AND e.exception_type = 'absence'
        WHERE r.appointment_id = ? AND r.member_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$appointmentId, $memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * Haengt jedem Termin 'responses' an: Summen und eigene Antwort, oder null bei
 * Terminarten ohne Rueckmeldung. Zwei Abfragen fuer die ganze Liste, nicht je Termin.
 *
 * 'own' bezieht sich immer auf das Mitglied des angemeldeten Kontos, unabhaengig von
 * einem etwaigen member_id-Filter der Liste, und kann eine Antwort zeigen, die nicht
 * mehr zaehlt, weil das Mitglied nicht mehr erwartet ist.
 *
 * @param array<int, array<string, mixed>> $appointments Zeilen mit responses_enabled
 * @return array<int, array<string, mixed>>
 */
function responsesAttachSummaries($db, $database, array $appointments, ?int $viewerMemberId): array
{
    $ids = [];
    foreach ($appointments as $a) {
        if ((int) ($a['responses_enabled'] ?? 0) === 1) {
            $ids[] = (int) $a['appointment_id'];
        }
    }

    $expectedBy = [];
    $statusBy   = [];

    if ($ids !== []) {
        $prefix       = $database->table('');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $activity     = getMemberActivityWhere('m', 'a.date');

        $stmt = $db->prepare("
            SELECT DISTINCT a.appointment_id, m.member_id
            FROM {$prefix}appointments a
            JOIN {$prefix}appointment_type_groups atg ON atg.type_id = a.type_id
            JOIN {$prefix}member_group_assignments mga ON mga.group_id = atg.group_id
            JOIN {$prefix}members m ON m.member_id = mga.member_id AND {$activity}
            WHERE a.appointment_id IN ({$placeholders})
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $expectedBy[(int) $row['appointment_id']][] = (int) $row['member_id'];
        }

        $stmt = $db->prepare("SELECT appointment_id, member_id, status
                              FROM {$prefix}appointment_responses
                              WHERE appointment_id IN ({$placeholders})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusBy[(int) $row['appointment_id']][(int) $row['member_id']] = $row['status'];
        }
    }

    $enabled = array_flip($ids);
    foreach ($appointments as &$appointment) {
        $appointmentId = (int) $appointment['appointment_id'];
        if (!isset($enabled[$appointmentId])) {
            $appointment['responses'] = null;
            continue;
        }

        $summary = responseSummary($expectedBy[$appointmentId] ?? [], $statusBy[$appointmentId] ?? []);
        $summary['own'] = $viewerMemberId !== null ? ($statusBy[$appointmentId][$viewerMemberId] ?? null) : null;
        // Ob der Betrachter selbst zu diesem Termin erwartet wird -- Grundlage
        // dafuer, ob die Zelle in der Terminliste anklickbar ist (Nicht-Erwartete
        // ohne Verwaltungsrechte sehen nur die Summen, kein Modal).
        $summary['expected'] = $viewerMemberId !== null
            && in_array($viewerMemberId, $expectedBy[$appointmentId] ?? [], true);
        $appointment['responses'] = $summary;
    }
    unset($appointment);

    return $appointments;
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
