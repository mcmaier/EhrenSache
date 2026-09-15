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
// PÜNKTLICHKEIT UND ZUVERLÄSSIGKEIT (OI-51)
// ============================================
//
// Zwei getrennte Kennzahlen, niemals verrechnet. Spec:
// docs/superpowers/specs/2026-09-11-puenktlichkeit-und-zuverlaessigkeit-design.md
//
// Aufbau wie attendance.php: Die holenden Funktionen liefern Rohfakten, die
// formenden entscheiden. Alles, was eine Regel ist, steht in den formenden
// Funktionen und ist ohne Datenbank pruefbar (tests/suites/punctuality_unit.php).

require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/responses.php';

/** Unter dieser Zahl gemessener Ankuenfte gibt es keine Quote (Spec 5.1). */
const PUNCTUALITY_MIN_MEASUREMENTS = 5;

/** Verspaetung, ab der ein Ausreisser nicht weiter ins Mittel eingeht (Spec 3.4). */
const PUNCTUALITY_LATE_CAP_MINUTES = 20;

/**
 * Karenz aus dem gespeicherten Wert.
 *
 * Die Einstellungsseite prueft den Bereich, updateSetting() beim Speichern
 * nicht. Alles ausserhalb von -60..60 oder keine ganze Zahl ergibt 0 -- ein
 * von Hand gesetzter Unsinnswert soll die Kennzahl nicht unbemerkt verbiegen.
 */
function punctualityGraceFromSetting(string $raw): int
{
    $raw = trim($raw);

    if (preg_match('/^-?\d+$/', $raw) !== 1) {
        return 0;
    }

    $minutes = (int) $raw;

    return ($minutes < -60 || $minutes > 60) ? 0 : $minutes;
}

/**
 * Formt die Puenktlichkeit aus den Messungen eines Bereichs.
 *
 * Minuten werden abgerundet, auch negativ: 20:00:59 gilt als 20:00, 19:59:30
 * als 19:59. Aggregiert wird ueber Messungen, nicht ueber Personen (Spec 5.3).
 *
 * @param array<int, array{delta_seconds: int|string, checkin_source: string}> $measurements
 * @param int $totalCount   Soll-Paare im Bereich -- Bezugsgroesse der Messabdeckung
 * @param int $graceMinutes Karenz relativ zum Terminbeginn
 */
function punctualityBuild(array $measurements, int $totalCount, int $graceMinutes): array
{
    $measured     = count($measurements);
    $onTime       = 0;
    $late         = 0;
    $lateSum      = 0;
    $selfReported = 0;

    foreach ($measurements as $measurement) {
        $minutes = (int) floor(((int) $measurement['delta_seconds']) / 60);

        if ($minutes <= $graceMinutes) {
            $onTime++;
        }

        // Das Verspaetungsmass misst ab Beginn, nicht ab der Karenz (Spec 3.4).
        if ($minutes > 0) {
            $late++;
            $lateSum += min($minutes, PUNCTUALITY_LATE_CAP_MINUTES);
        }

        if ($measurement['checkin_source'] === 'exception_request') {
            $selfReported++;
        }
    }

    $sufficient = $measured >= PUNCTUALITY_MIN_MEASUREMENTS;

    return [
        'enabled'             => true,
        'sufficient'          => $sufficient,
        'min_measurements'    => PUNCTUALITY_MIN_MEASUREMENTS,
        'measured_count'      => $measured,
        'total_count'         => $totalCount,
        'on_time_count'       => $onTime,
        'rate'                => $sufficient ? attendanceRate($onTime, $measured) : null,
        'late_count'          => $late,
        'avg_late_minutes'    => $late > 0 ? round($lateSum / $late, 1) : null,
        'self_reported_count' => $selfReported,
    ];
}

/**
 * Ausgang eines Soll-Paars aus Mitglied und Termin (Spec 5.2).
 *
 * Reihenfolge ist die Regel:
 *  1. erschienen -- wer kam, war da, auch trotz Abmeldung
 *  2. gibt es eine Abmeldung, entscheidet ihr Zeitpunkt; eine spaeter
 *     genehmigte bleibt verspaetet, auch wenn daraus ein excused-Record wurde
 *  3. sonst zaehlt eine Entschuldigung des Verwalters -- ueber den Zeitpunkt
 *     eines Anrufs weiss das System nichts
 *  4. sonst ausgefallen
 *
 * Bei Terminarten mit Rueckmeldung (`responses_enabled`) gilt statt des
 * Beginns die Frist (Spec Terminrueckmeldung 5.5):
 *  1. erschienen -- wie oben
 *  2. Absage oder Antrag vor der Frist -> excused -- bei Entschuldigungspflicht
 *     (`responses_require_excuse`) zaehlt eine rechtzeitige Absage nur mit
 *     einem verknuepften, nicht abgelehnten Antrag (Entscheidung W3); ein
 *     eigener Antrag vor der Frist zaehlt unabhaengig davon weiter ueber
 *     absence_in_deadline_count
 *  3. Absage oder Antrag, aber erst nach der Frist, oder eine rechtzeitige
 *     Absage ohne den bei Entschuldigungspflicht geforderten gueltigen
 *     Antrag -> missed
 *  4. sonst zaehlt die Entschuldigung des Verwalters, sonst ausgefallen
 * Paare ohne diese Schluessel stammen aus Terminarten ohne Rueckmeldung und
 * laufen nach der Regel oben (1.5.1).
 *
 * @param array{has_present: int|string, has_excused_record: int|string,
 *              absence_count: int|string, absence_in_time_count: int|string,
 *              responses_enabled?: int|string, responses_require_excuse?: int|string,
 *              absence_in_deadline_count?: int|string,
 *              response_no_count?: int|string, response_no_in_time?: int|string,
 *              response_no_in_time_with_request?: int|string} $pair
 * @return 'appeared'|'excused'|'missed'
 */
function reliabilityOutcome(array $pair): string
{
    if ((int) $pair['has_present'] > 0) {
        return 'appeared';
    }

    // Terminart mit Rueckmeldung: Fuer Absage und Antrag gilt die Frist, nicht
    // der Beginn -- sonst zaehlte eine kurzfristige Absage mit Pflicht-
    // Entschuldigung ueber ihren Antrag doch als rechtzeitig
    // (Spec Terminrueckmeldung 3.5). Paare ohne diese Schluessel stammen aus
    // Terminarten ohne Rueckmeldung und laufen unten weiter wie in 1.5.1.
    if ((int) ($pair['responses_enabled'] ?? 0) === 1) {
        // W3: Bei Entschuldigungspflicht zaehlt die rechtzeitige Absage nur mit
        // einem verknuepften, nicht abgelehnten Antrag. Ohne Pflicht bleibt es
        // bei der reinen Rechtzeitigkeit der Absage.
        $requireExcuse = (int) ($pair['responses_require_excuse'] ?? 0);
        $timelyNo = (int) ($pair['response_no_in_time'] ?? 0) > 0
            && ($requireExcuse !== 1 || (int) ($pair['response_no_in_time_with_request'] ?? 0) > 0);

        if ($timelyNo || (int) ($pair['absence_in_deadline_count'] ?? 0) > 0) {
            return 'excused';
        }
        if ((int) ($pair['response_no_count'] ?? 0) > 0 || (int) $pair['absence_count'] > 0) {
            return 'missed';
        }

        return (int) $pair['has_excused_record'] > 0 ? 'excused' : 'missed';
    }

    if ((int) $pair['absence_count'] > 0) {
        return (int) $pair['absence_in_time_count'] > 0 ? 'excused' : 'missed';
    }

    return (int) $pair['has_excused_record'] > 0 ? 'excused' : 'missed';
}

/**
 * Formt die Zuverlaessigkeit aus den Soll-Paaren eines Bereichs.
 *
 * @param array<int, array<string, int|string>> $pairs
 */
function reliabilityBuild(array $pairs): array
{
    $counts = ['appeared' => 0, 'excused' => 0, 'missed' => 0];

    foreach ($pairs as $pair) {
        $counts[reliabilityOutcome($pair)]++;
    }

    $total = count($pairs);

    return [
        'enabled'         => true,
        'total'           => $total,
        'appeared'        => $counts['appeared'],
        'excused_in_time' => $counts['excused'],
        'missed'          => $counts['missed'],
        'rate'            => $total > 0
            ? attendanceRate($counts['appeared'] + $counts['excused'], $total)
            : null,
    ];
}

/**
 * FROM ... WHERE der Soll-Menge und seine Parameter.
 *
 * Derselbe Join wie attendanceFetchMemberTotals(): Terminart -> Gruppe ->
 * Mitglied, aktiv im Jahr, Termin begonnen. Die beiden duerfen nicht
 * auseinanderlaufen -- tests/suites/punctuality_api.php prueft das gegen den
 * Bestand, statt die OI-48-Rechnung fuer eine gemeinsame Funktion aufzubrechen.
 *
 * @param array<int, int> $groupIds nicht leer
 * @return array{0: string, 1: array<int, mixed>}
 */
function punctualityScope($database, array $groupIds, int $year, ?int $memberId,
                          ?int $appointmentTypeId): array
{
    require_once __DIR__ . '/member_activity.php';

    $prefix        = $database->table('');
    $activityWhere = getMemberActivityWhereYear($year, 'm');
    $placeholders  = implode(',', array_fill(0, count($groupIds), '?'));

    $sql = "
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

    $params   = array_values(array_map('intval', $groupIds));
    $params[] = $year;

    if ($memberId !== null) {
        $sql .= " AND m.member_id = ?";
        $params[] = $memberId;
    }
    if ($appointmentTypeId !== null) {
        $sql .= " AND a.type_id = ?";
        $params[] = $appointmentTypeId;
    }

    return [$sql, $params];
}

/**
 * Gemessene Ankuenfte im Bereich, je Record einmal.
 *
 * DISTINCT r.record_id ist die Entdopplung: Erreicht ein Termin ein Mitglied
 * ueber zwei Gruppen, liefert der Join denselben Record zweimal.
 *
 * Gemessen heisst: anwesend, mit Uhrzeit, und nicht aus Import oder Timer
 * (Spec 3.5). Eine genehmigte Zeitkorrektur zaehlt mit -- ihre Herkunft steht
 * als exception_request in der Antwort.
 *
 * @return array<int, array{delta_seconds: string, checkin_source: string}>
 */
function punctualityFetchMeasurements($db, $database, array $groupIds, int $year,
                                      ?int $memberId, ?int $appointmentTypeId): array
{
    if ($groupIds === []) {
        return [];
    }

    [$scope, $params] = punctualityScope($database, $groupIds, $year, $memberId, $appointmentTypeId);

    $stmt = $db->prepare("
        SELECT DISTINCT r.record_id,
               TIMESTAMPDIFF(SECOND, CONCAT(a.date, ' ', a.start_time), r.arrival_time) AS delta_seconds,
               r.checkin_source
        {$scope}
          AND r.status = 'present'
          AND r.arrival_time IS NOT NULL
          AND r.checkin_source NOT IN ('import', 'timer')
    ");
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Soll-Paare im Bereich mit den Fakten, die reliabilityOutcome() braucht.
 *
 * GROUP BY Mitglied und Termin entdoppelt. a.date, a.start_time und a.type_id
 * stehen mit im GROUP BY: MariaDB erkennt die funktionale Abhaengigkeit vom
 * Primaerschluessel unter ONLY_FULL_GROUP_BY nicht, und die Unterabfragen und
 * die Frist lesen alle drei.
 *
 * Abmeldungen nur vom Typ 'absence' -- ohne diesen Filter zaehlten
 * Zeitkorrektur-Antraege als Absage (Spec 5.2). Offene zaehlen wie genehmigte.
 *
 * @return array<int, array<string, string>>
 */
function reliabilityFetchPairs($db, $database, array $groupIds, int $year,
                               ?int $memberId, ?int $appointmentTypeId): array
{
    if ($groupIds === []) {
        return [];
    }

    require_once __DIR__ . '/utils.php';

    $prefix = $database->table('');
    [$scope, $params] = punctualityScope($database, $groupIds, $year, $memberId, $appointmentTypeId);

    $absence = "
        FROM {$prefix}exceptions e
        WHERE e.member_id = m.member_id
          AND e.appointment_id = a.appointment_id
          AND e.exception_type = 'absence'
          AND e.status <> 'rejected'
    ";

    // Frist wie responseDeadline(): Terminart vor Einstellung. DATE_SUB auf
    // DATETIME rechnet ohne Zeitzone, genau wie die PHP-Seite.
    $globalHours = responseDeadlineHours(null, systemSetting(
        $db, $database, 'response_deadline_hours', (string) RESPONSE_DEADLINE_DEFAULT_HOURS
    ));
    $deadline = "DATE_SUB(CONCAT(a.date, ' ', a.start_time), INTERVAL COALESCE(
        (SELECT t.response_deadline_hours FROM {$prefix}appointment_types t WHERE t.type_id = a.type_id), ?
    ) HOUR)";

    $stmt = $db->prepare("
        SELECT m.member_id, a.appointment_id,
               MAX(CASE WHEN r.status = 'present' THEN 1 ELSE 0 END) AS has_present,
               MAX(CASE WHEN r.status = 'excused' THEN 1 ELSE 0 END) AS has_excused_record,
               (SELECT COUNT(*) {$absence}) AS absence_count,
               (SELECT COUNT(*) {$absence}
                  AND e.created_at < CONCAT(a.date, ' ', a.start_time)) AS absence_in_time_count,
               (SELECT COALESCE(MAX(t.responses_enabled), 0)
                  FROM {$prefix}appointment_types t WHERE t.type_id = a.type_id) AS responses_enabled,
               (SELECT COALESCE(MAX(t.responses_require_excuse), 0)
                  FROM {$prefix}appointment_types t WHERE t.type_id = a.type_id) AS responses_require_excuse,
               (SELECT COUNT(*) {$absence}
                  AND e.created_at <= {$deadline}) AS absence_in_deadline_count,
               (SELECT COUNT(*) FROM {$prefix}appointment_responses ar
                 WHERE ar.appointment_id = a.appointment_id AND ar.member_id = m.member_id
                   AND ar.status = 'no') AS response_no_count,
               (SELECT COUNT(*) FROM {$prefix}appointment_responses ar
                 WHERE ar.appointment_id = a.appointment_id AND ar.member_id = m.member_id
                   AND ar.status = 'no' AND ar.status_changed_at <= {$deadline}) AS response_no_in_time,
               (SELECT COUNT(*) FROM {$prefix}appointment_responses ar
                 WHERE ar.appointment_id = a.appointment_id AND ar.member_id = m.member_id
                   AND ar.status = 'no' AND ar.status_changed_at <= {$deadline}
                   AND EXISTS (
                       SELECT 1 FROM {$prefix}exceptions e
                       WHERE e.exception_id = ar.exception_id AND e.member_id = ar.member_id
                         AND e.appointment_id = ar.appointment_id AND e.exception_type = 'absence'
                         AND e.status <> 'rejected'
                   )) AS response_no_in_time_with_request
        {$scope}
        GROUP BY m.member_id, a.appointment_id, a.date, a.start_time, a.type_id
    ");
    // Die drei ? der Frist stehen im SELECT und damit vor den Platzhaltern des Scopes.
    $stmt->execute(array_merge([$globalHours, $globalHours, $globalHours], $params));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Beide Bloecke fuer einen Bereich, jeweils nur wenn eingeschaltet.
 *
 * Ausgeschaltet heisst {"enabled": false} und sonst nichts -- keine Nullwerte,
 * die eine abgeschaltete Kennzahl wie eine leere aussehen liessen (Spec 8).
 *
 * @param int $totalPairs Soll-Paare, aus attendanceFetchMemberTotals() summiert
 * @return array{punctuality: array, reliability: array}
 */
function punctualityBlocks($db, $database, array $groupIds, int $year, ?int $memberId,
                           ?int $appointmentTypeId, int $totalPairs): array
{
    require_once __DIR__ . '/utils.php';

    $blocks = [
        'punctuality' => ['enabled' => false],
        'reliability' => ['enabled' => false],
    ];

    if (systemSetting($db, $database, 'punctuality_enabled', '0') === '1') {
        $grace = punctualityGraceFromSetting(
            systemSetting($db, $database, 'punctuality_grace_minutes', '0')
        );

        $blocks['punctuality'] = punctualityBuild(
            punctualityFetchMeasurements($db, $database, $groupIds, $year, $memberId, $appointmentTypeId),
            $totalPairs,
            $grace
        );
    }

    if (systemSetting($db, $database, 'reliability_enabled', '0') === '1') {
        $blocks['reliability'] = reliabilityBuild(
            reliabilityFetchPairs($db, $database, $groupIds, $year, $memberId, $appointmentTypeId)
        );
    }

    return $blocks;
}

/**
 * Eigene Werte eines Mitglieds je Jahr, fuer die Selbstauskunft.
 *
 * Nur Jahre mit mindestens einem Soll-Termin, und nur wenn wenigstens eine
 * der Kennzahlen eingeschaltet ist -- sonst ein leeres Array.
 *
 * @param array<int, int> $groupIds Gruppen des Mitglieds
 * @return array<int, array{punctuality: array, reliability: array}>
 */
function punctualityByYear($db, $database, int $memberId, array $groupIds): array
{
    require_once __DIR__ . '/utils.php';

    $anyOn = systemSetting($db, $database, 'punctuality_enabled', '0') === '1'
          || systemSetting($db, $database, 'reliability_enabled', '0') === '1';

    if (!$anyOn || $groupIds === []) {
        return [];
    }

    $prefix = $database->table('');
    $years  = $db->query("SELECT DISTINCT YEAR(date) FROM {$prefix}appointments ORDER BY 1")
                 ->fetchAll(PDO::FETCH_COLUMN);

    $result = [];

    foreach ($years as $year) {
        $year   = (int) $year;
        $totals = attendanceFetchMemberTotals($db, $database, $groupIds, $year, $memberId, null);
        $pairs  = array_sum(array_map('intval', array_column($totals, 'total')));

        if ($pairs === 0) {
            continue;
        }

        $result[$year] = punctualityBlocks($db, $database, $groupIds, $year, $memberId, null, $pairs);
    }

    return $result;
}
