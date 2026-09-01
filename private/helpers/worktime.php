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

// ============================================
// DATENBANKZUGRIFF
// ============================================

/** Liest eine worktime-Einstellung aus system_settings. */
function worktimeSetting($db, $database, string $key, string $default): string
{
    $prefix = $database->table('');
    $stmt   = $db->prepare("SELECT setting_value FROM {$prefix}system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return ($value === false || $value === null) ? $default : (string) $value;
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
