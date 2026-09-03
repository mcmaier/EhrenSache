<?php
/**
 * EhrenSache - Hilfsfunktionen der Zeiterfassung
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Der obere Teil ist seiteneffektfrei und ohne Datenbank testbar
 * (tests/suites/worktime_unit.php). Der untere Teil berührt die Datenbank.
 */

// worktimeSetting() leitet seit 1.2.4 an systemSetting() in utils.php weiter.
// Die Abhängigkeit wird hier deklariert und nicht der Ladereihenfolge in
// api.php überlassen: worktime_unit.php lädt diese Datei allein.
require_once __DIR__ . '/utils.php';

// ============================================
// REINE LOGIK
// ============================================

/**
 * Nettodauer einer Sitzung in Minuten, oder null solange sie läuft.
 *
 * @param array<string, mixed> $session
 */
function sessionDurationMinutes(array $session): ?int
{
    if (empty($session['end_time'])) {
        return null;
    }

    $start = strtotime((string) $session['start_time']);
    $end   = strtotime((string) $session['end_time']);

    if ($start === false || $end === false || $end <= $start) {
        return 0;
    }

    $gross = (int) floor(($end - $start) / 60);
    $net   = $gross - (int) ($session['break_minutes'] ?? 0);

    return max(0, $net);
}

/**
 * Prüft die Eingabe eines manuellen Eintrags.
 *
 * @param array<string, mixed> $in
 * @return array<int, string> Liste der Fehlermeldungen, leer wenn gültig
 */
function validateManualSession(array $in, bool $requireNote, int $nowTs): array
{
    $errors = [];

    if (empty($in['activity_id'])) {
        $errors[] = 'activity_id ist erforderlich';
    }

    $start = isset($in['start_time']) ? strtotime((string) $in['start_time']) : false;
    $end   = isset($in['end_time'])   ? strtotime((string) $in['end_time'])   : false;

    if ($start === false) {
        $errors[] = 'start_time fehlt oder ist kein gültiger Zeitpunkt';
    }
    if ($end === false) {
        $errors[] = 'end_time fehlt oder ist kein gültiger Zeitpunkt';
    }

    if ($start !== false && $end !== false) {
        if ($end <= $start) {
            $errors[] = 'end_time muss nach start_time liegen';
        }
        if ($start > $nowTs || $end > $nowTs) {
            $errors[] = 'Zeiten dürfen nicht in der Zukunft liegen';
        }

        $break = (int) ($in['break_minutes'] ?? 0);
        if ($break < 0) {
            $errors[] = 'break_minutes darf nicht negativ sein';
        } elseif ($end > $start) {
            $gross = (int) floor(($end - $start) / 60);
            if ($break >= $gross) {
                $errors[] = 'break_minutes muss kleiner als die Bruttodauer sein';
            }
        }
    }

    if ($requireNote && trim((string) ($in['note'] ?? '')) === '') {
        $errors[] = 'Eine Notiz ist erforderlich';
    }

    return $errors;
}

/**
 * Läuft die Sitzung länger als erlaubt?
 *
 * @param array<string, mixed> $session
 */
function isSessionStale(array $session, int $maxHours, int $nowTs): bool
{
    if (!empty($session['end_time'])) {
        return false;
    }

    $start = strtotime((string) $session['start_time']);
    if ($start === false) {
        return false;
    }

    return ($nowTs - $start) >= $maxHours * 3600;
}

/** Endzeit, auf die eine überfällige Sitzung gekappt wird. */
function staleEndTime(string $startTime, int $maxHours): string
{
    $start = strtotime($startTime);

    return date('Y-m-d H:i:s', $start + $maxHours * 3600);
}

/** Obergrenze einer Auswertung in Monaten. */
const WORKTIME_MAX_PERIOD_MONTHS = 24;

/** Deutsche Monatsnamen -- bewusst als Tabelle statt ueber setlocale(). */
const WORKTIME_MONTH_NAMES = [
    1 => 'Januar',   2 => 'Februar', 3 => 'März',      4 => 'April',
    5 => 'Mai',      6 => 'Juni',    7 => 'Juli',      8 => 'August',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
];

/**
 * Prueft ein Datum im Format YYYY-MM-DD streng.
 *
 * createFromFormat allein genuegt nicht: Es laesst '2026-02-30' durch und
 * rollt still auf den 2. Maerz weiter. Der Rueckvergleich faengt das ab.
 *
 * @throws InvalidArgumentException
 */
function worktimeParseDate(string $value, string $field): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException(
            "Ungültiges Datum in '{$field}': erwartet wird JJJJ-MM-TT."
        );
    }

    return $date;
}

/**
 * Loest die Zeitraumparameter einer Auswertung auf.
 *
 * Vorrang hat from/to; fehlen beide, gilt das Jahr. Fehlt eines von beiden,
 * begrenzt das Jahr die offene Seite -- ein einseitig offener Zeitraum wuerde
 * sonst stillschweigend den gesamten Datenbestand ziehen.
 *
 * Der Ruecknahmewert traegt neben den Grenzen auch Beschriftung und
 * Dateinamen-Slug, damit beide an genau einer Stelle entstehen. Ohne sie ist
 * ein gespeichertes Monats-CSV von einem Jahres-CSV nicht zu unterscheiden.
 *
 * @throws InvalidArgumentException bei ungueltiger oder widerspruechlicher Eingabe
 * @return array{from: string, to: string, label: string, slug: string}
 */
function worktimeResolvePeriod(?string $from, ?string $to, ?int $year): array
{
    $from = ($from === null || $from === '') ? null : $from;
    $to   = ($to   === null || $to   === '') ? null : $to;

    $fromDate = $from !== null ? worktimeParseDate($from, 'from') : null;
    $toDate   = $to   !== null ? worktimeParseDate($to,   'to')   : null;

    // Das Jahr begrenzt jede Seite, die offen geblieben ist. Fehlt es, ergibt
    // es sich aus der gesetzten Grenze -- sonst gilt das laufende Jahr.
    if ($year === null) {
        $year = (int) ($fromDate ?? $toDate ?? new DateTimeImmutable())->format('Y');
    }

    if ($year < 1970 || $year > 2200) {
        throw new InvalidArgumentException("Ungültiges Jahr: {$year}.");
    }

    $fromDate = $fromDate ?? new DateTimeImmutable(sprintf('%04d-01-01', $year));
    $toDate   = $toDate   ?? new DateTimeImmutable(sprintf('%04d-12-31', $year));

    if ($toDate < $fromDate) {
        throw new InvalidArgumentException('Das Ende des Zeitraums liegt vor seinem Beginn.');
    }

    // Obergrenze, damit ein versehentliches from=1970-01-01 nicht den
    // gesamten Bestand in eine Druckansicht rendert.
    $latest = $fromDate->modify('+' . WORKTIME_MAX_PERIOD_MONTHS . ' months')->modify('-1 day');
    if ($toDate > $latest) {
        throw new InvalidArgumentException(
            'Der Zeitraum umfasst mehr als ' . WORKTIME_MAX_PERIOD_MONTHS . ' Monate.'
        );
    }

    return [
        'from'  => $fromDate->format('Y-m-d'),
        'to'    => $toDate->format('Y-m-d'),
        'label' => worktimePeriodLabel($fromDate, $toDate),
        'slug'  => worktimePeriodSlug($fromDate, $toDate),
    ];
}

/** Lesbare Beschriftung eines Zeitraums fuer Bericht und Summenzeile. */
function worktimePeriodLabel(DateTimeImmutable $from, DateTimeImmutable $to): string
{
    if (worktimeIsFullYear($from, $to)) {
        return 'Jahr ' . $from->format('Y');
    }

    if (worktimeIsFullMonth($from, $to)) {
        return WORKTIME_MONTH_NAMES[(int) $from->format('n')] . ' ' . $from->format('Y');
    }

    return $from->format('d.m.Y') . ' – ' . $to->format('d.m.Y');
}

/** Kurzform eines Zeitraums fuer Dateinamen. */
function worktimePeriodSlug(DateTimeImmutable $from, DateTimeImmutable $to): string
{
    if (worktimeIsFullYear($from, $to)) {
        return $from->format('Y');
    }

    if (worktimeIsFullMonth($from, $to)) {
        return $from->format('Y-m');
    }

    return $from->format('Y-m-d') . '_' . $to->format('Y-m-d');
}

/** Deckt der Zeitraum genau ein Kalenderjahr ab? */
function worktimeIsFullYear(DateTimeImmutable $from, DateTimeImmutable $to): bool
{
    return $from->format('m-d') === '01-01'
        && $to->format('m-d')   === '12-31'
        && $from->format('Y')   === $to->format('Y');
}

/** Deckt der Zeitraum genau einen Kalendermonat ab? */
function worktimeIsFullMonth(DateTimeImmutable $from, DateTimeImmutable $to): bool
{
    return $from->format('d') === '01'
        && $from->format('Y-m') === $to->format('Y-m')
        && $to->format('Y-m-d') === $to->format('Y-m-t');
}

// ============================================
// DATENBANKZUGRIFF
// ============================================

/**
 * Liest eine worktime-Einstellung aus system_settings.
 *
 * Der Rumpf steht seit 1.2.4 in utils.php als systemSetting(); diese Funktion
 * bleibt als Weiterleitung, damit die vorhandenen Aufrufer unverändert bleiben.
 */
function worktimeSetting($db, $database, string $key, string $default): string
{
    return systemSetting($db, $database, $key, $default);
}

/** Ist die Zeiterfassung freigeschaltet? */
function isWorktimeEnabled($db, $database): bool
{
    return worktimeSetting($db, $database, 'worktime_enabled', '0') === '1';
}

/**
 * Antwortet mit 404 und beendet, wenn das Feature aus ist.
 * Bewusst 404 und nicht 403: ein abgeschaltetes Feature soll nicht einmal
 * verraten, dass es existiert.
 */
function requireWorktimeEnabled($db, $database): void
{
    if (!isWorktimeEnabled($db, $database)) {
        http_response_code(404);
        echo json_encode(["message" => "Endpoint not found"]);
        exit();
    }
}

/**
 * Die laufende Sitzung eines Mitglieds, oder null.
 *
 * @return array<string, mixed>|null
 */
function getRunningSession($db, $database, int $memberId): ?array
{
    $prefix = $database->table('');
    $stmt   = $db->prepare("
        SELECT ws.*, at.activity_name, at.color, at.verification
        FROM {$prefix}work_sessions ws
        LEFT JOIN {$prefix}activity_types at ON ws.activity_id = at.activity_id
        WHERE ws.member_id = ? AND ws.end_time IS NULL
        LIMIT 1
    ");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/**
 * Schreibt einen Eintrag in die Auditspur.
 *
 * @param array<string, mixed> $changes Form: ['feld' => ['old' => ..., 'new' => ...]]
 */
function logSessionChange($db, $database, int $sessionId, ?int $userId, string $action, array $changes = []): void
{
    $prefix = $database->table('');
    $stmt   = $db->prepare("
        INSERT INTO {$prefix}work_session_log (session_id, changed_by, action, changes)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $sessionId,
        $userId,
        $action,
        $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * Bildet die Differenz zweier Datensätze für die Auditspur.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return array<string, array{old: mixed, new: mixed}>
 */
function sessionChangeSet(array $before, array $after): array
{
    $changes = [];
    foreach ($after as $key => $newValue) {
        $oldValue = $before[$key] ?? null;
        if ((string) $oldValue !== (string) $newValue) {
            $changes[$key] = ['old' => $oldValue, 'new' => $newValue];
        }
    }

    return $changes;
}

/** Ergänzt einen Datensatz um die berechnete Dauer. */
function withDuration(array $session): array
{
    $session['duration_minutes'] = sessionDurationMinutes($session);
    $session['is_running']       = empty($session['end_time']);
    $session['is_paused']        = !empty($session['break_started_at']);

    return $session;
}

/**
 * Schließt eine überfällige Sitzung: gekappt auf start_time + Obergrenze,
 * Status auf 'submitted', damit sie beim Mitglied zur Korrektur und beim
 * Manager zur Freigabe landet statt automatisch zu zählen.
 *
 * @param array<string, mixed> $session
 * @return bool true, wenn geschlossen wurde
 */
function closeStaleSession($db, $database, array $session, ?int $userId): bool
{
    $maxHours = (int) worktimeSetting($db, $database, 'worktime_max_session_hours', '12');

    if ($maxHours <= 0 || !isSessionStale($session, $maxHours, time())) {
        return false;
    }

    $prefix  = $database->table('');
    $endTime = staleEndTime((string) $session['start_time'], $maxHours);

    $db->prepare("UPDATE {$prefix}work_sessions
                  SET end_time = ?, break_started_at = NULL, status = 'submitted'
                  WHERE session_id = ? AND end_time IS NULL")
       ->execute([$endTime, $session['session_id']]);

    logSessionChange($db, $database, (int) $session['session_id'], $userId, 'update', [
        'auto_closed' => ['old' => null, 'new' => true],
        'end_time'    => ['old' => null, 'new' => $endTime],
        'status'      => ['old' => $session['status'], 'new' => 'submitted'],
    ]);

    return true;
}

// ============================================
// AUSWERTUNG
// ============================================

/**
 * SQL-Ausdruck für den Nachweisgrad einer Sitzung.
 *
 * stundenbelegt = Start UND Ende an einer Station belegt; erst dann ist die
 * DAUER belegt und nicht bloß die Anwesenheit zu Beginn.
 */
function worktimeProofExpression(string $alias = 'ws'): string
{
    return "CASE
                WHEN {$alias}.start_location_name IS NOT NULL
                 AND {$alias}.end_location_name   IS NOT NULL THEN 'hours'
                WHEN {$alias}.start_location_name IS NOT NULL THEN 'start'
                ELSE 'none'
            END";
}

/**
 * SQL-Bedingung für den Auswertungszeitraum. Erwartet zwei Parameter:
 * from und to aus worktimeResolvePeriod().
 *
 * Halboffen statt BETWEEN: `BETWEEN '2026-01-01' AND '2026-01-31'` schneidet
 * alles ab, was am 31. nach 00:00:00 beginnt. An einer Jahresgrenze faellt das
 * fast nie auf, an einer Monatsgrenze jeden Monat einmal. Die Spalte bleibt
 * dabei unveraendert auf der linken Seite und damit indextauglich -- anders
 * als beim frueheren YEAR(ws.start_time).
 */
function worktimePeriodCondition(string $alias = 'ws'): string
{
    return "{$alias}.start_time >= ? AND {$alias}.start_time < DATE_ADD(?, INTERVAL 1 DAY)";
}

/** SQL-Ausdruck für die Nettodauer in Minuten. */
function worktimeDurationExpression(string $alias = 'ws'): string
{
    return "GREATEST(0, TIMESTAMPDIFF(MINUTE, {$alias}.start_time, {$alias}.end_time)
                        - {$alias}.break_minutes)";
}

/**
 * Stunden je Mitglied für einen Zeitraum, aufgeschlüsselt nach Tätigkeitsart
 * und Nachweisgrad. Gezählt wird ausschließlich, was bestätigt und beendet ist.
 *
 * @param array{from: string, to: string} $period  aus worktimeResolvePeriod()
 * @param int|null $memberId  Auf ein Mitglied einschränken
 * @return array{summary: array<string, int>, members: array<int, array<string, mixed>>}
 */
function worktimeStatistics($db, $database, array $period, ?int $memberId = null): array
{
    $prefix   = $database->table('');
    $duration = worktimeDurationExpression();
    $proof    = worktimeProofExpression();

    $where  = "ws.status = 'confirmed' AND ws.end_time IS NOT NULL
               AND " . worktimePeriodCondition();
    $params = [$period['from'], $period['to']];

    if ($memberId !== null) {
        $where .= " AND ws.member_id = ?";
        $params[] = $memberId;
    }

    $stmt = $db->prepare("
        SELECT ws.member_id,
               m.name, m.surname, m.member_number,
               ws.activity_id, at.activity_name,
               {$proof} AS proof,
               COUNT(*)        AS sessions,
               SUM({$duration}) AS minutes
        FROM {$prefix}work_sessions ws
        LEFT JOIN {$prefix}members m         ON ws.member_id  = m.member_id
        LEFT JOIN {$prefix}activity_types at ON ws.activity_id = at.activity_id
        WHERE {$where}
        GROUP BY ws.member_id, ws.activity_id, proof
        ORDER BY m.surname, m.name, at.activity_name
    ");
    $stmt->execute($params);

    $members = [];
    $summary = ['total_minutes' => 0, 'hours_proven' => 0, 'start_proven' => 0, 'unproven' => 0,
                'sessions' => 0];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id      = (int) $row['member_id'];
        $minutes = (int) $row['minutes'];
        $count   = (int) $row['sessions'];

        if (!isset($members[$id])) {
            $members[$id] = [
                'member_id'     => $id,
                'name'          => $row['name'],
                'surname'       => $row['surname'],
                'member_number' => $row['member_number'],
                'worked_minutes' => 0,
                'sessions'      => 0,
                'by_proof'      => ['hours' => 0, 'start' => 0, 'none' => 0],
                'by_activity'   => [],
            ];
        }

        $members[$id]['worked_minutes'] += $minutes;
        $members[$id]['sessions']       += $count;
        $members[$id]['by_proof'][$row['proof']] += $minutes;

        $activityId = (int) $row['activity_id'];
        if (!isset($members[$id]['by_activity'][$activityId])) {
            $members[$id]['by_activity'][$activityId] = [
                'activity_id'   => $activityId,
                'activity_name' => $row['activity_name'],
                'minutes'       => 0,
                'sessions'      => 0,
            ];
        }
        $members[$id]['by_activity'][$activityId]['minutes']  += $minutes;
        $members[$id]['by_activity'][$activityId]['sessions'] += $count;

        $summary['total_minutes'] += $minutes;
        $summary['sessions']      += $count;
        $summary[$row['proof'] === 'hours' ? 'hours_proven'
                : ($row['proof'] === 'start' ? 'start_proven' : 'unproven')] += $minutes;
    }

    // Assoziative Zwischenschluessel entfernen, damit JSON Arrays liefert
    foreach ($members as &$member) {
        $member['by_activity'] = array_values($member['by_activity']);
    }
    unset($member);

    return ['summary' => $summary, 'members' => array_values($members)];
}

/**
 * Summen je Tätigkeitsart für einen Zeitraum — Grundlage des
 * Verwendungsnachweises gegenüber Fördergebern.
 *
 * @param array{from: string, to: string} $period  aus worktimeResolvePeriod()
 * @return array<int, array<string, mixed>>
 */
function worktimeByActivity($db, $database, array $period): array
{
    $prefix   = $database->table('');
    $duration = worktimeDurationExpression();
    $proof    = worktimeProofExpression();

    $stmt = $db->prepare("
        SELECT at.activity_id, at.activity_name, at.verification,
               {$proof} AS proof,
               COUNT(*)         AS sessions,
               COUNT(DISTINCT ws.member_id) AS members,
               SUM({$duration}) AS minutes
        FROM {$prefix}work_sessions ws
        LEFT JOIN {$prefix}activity_types at ON ws.activity_id = at.activity_id
        WHERE ws.status = 'confirmed' AND ws.end_time IS NOT NULL
          AND " . worktimePeriodCondition() . "
        GROUP BY at.activity_id, proof
        ORDER BY at.activity_name
    ");
    $stmt->execute([$period['from'], $period['to']]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Stunden je Termin — beantwortet "Was hat diese Veranstaltung an
 * ehrenamtlicher Arbeit gekostet?".
 *
 * Sitzungen ohne Terminbezug erscheinen gesammelt als eine Zeile ohne Termin;
 * sie einfach wegzulassen wuerde die Gesamtsumme still verfaelschen.
 *
 * @param array{from: string, to: string} $period  aus worktimeResolvePeriod()
 * @return array<int, array<string, mixed>>
 */
function worktimeByAppointment($db, $database, array $period): array
{
    $prefix   = $database->table('');
    $duration = worktimeDurationExpression();
    $proof    = worktimeProofExpression();

    $stmt = $db->prepare("
        SELECT a.appointment_id, a.title, a.date, a.start_time,
               at.type_name AS appointment_type,
               {$proof} AS proof,
               COUNT(*)                     AS sessions,
               COUNT(DISTINCT ws.member_id) AS members,
               SUM({$duration})             AS minutes
        FROM {$prefix}work_sessions ws
        LEFT JOIN {$prefix}appointments a       ON ws.appointment_id = a.appointment_id
        LEFT JOIN {$prefix}appointment_types at ON a.type_id = at.type_id
        WHERE ws.status = 'confirmed' AND ws.end_time IS NOT NULL
          AND " . worktimePeriodCondition() . "
        GROUP BY a.appointment_id, proof
        ORDER BY a.date IS NULL, a.date, a.start_time
    ");
    $stmt->execute([$period['from'], $period['to']]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
