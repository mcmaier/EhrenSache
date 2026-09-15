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
    } elseif (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
        $value = (int) $raw;
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

    return (new DateTimeImmutable("{$date} {$startTime}", $utc))
        ->modify("-{$hours} hours")
        ->format('Y-m-d H:i:s');
}

/** Beide Werte als 'Y-m-d H:i:s' -- der Textvergleich ist dann chronologisch. */
function responseIsLate(string $statusChangedAt, string $deadline): bool
{
    return $statusChangedAt > $deadline;
}

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
        $status = $statusByMember[$memberId] ?? 'none';
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
            $out[$flag] = !empty($data->$flag) ? 1 : 0;
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
