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
// APPOINTMENT_RESPONSES Controller (FI-1)
// Spec: docs/superpowers/specs/2026-09-14-terminrueckmeldung-design.md
// ============================================

function handleAppointmentResponses($db, $database, $method, $authUserId, $authUserRole, $authMemberId)
{
    if ($authUserRole === 'device') {
        responsesFail(403, 'Geräte haben keinen Zugriff auf Rückmeldungen');
        return;
    }

    $now          = date('Y-m-d H:i:s');
    $isManager    = in_array($authUserRole, ['admin', 'manager'], true);
    $authMemberId = $authMemberId === null ? null : (int) $authMemberId;

    // Einmal je Anfrage gelesen, nicht je Termin -- responsesPayload() bekommt
    // den Wert durchgereicht, statt selbst systemSetting() aufzurufen.
    $globalHours = responseDeadlineHours(null, systemSetting(
        $db, $database, 'response_deadline_hours', (string) RESPONSE_DEADLINE_DEFAULT_HOURS
    ));

    switch ($method) {
        case 'GET':
            if (isset($_GET['upcoming'])) {
                responsesGetUpcoming($db, $database, $authMemberId, $now, $globalHours);
            } else {
                responsesGetOne($db, $database, $isManager, $authMemberId, $now, $globalHours);
            }
            return;

        case 'PUT':
            responsesPut($db, $database, (int) $authUserId, $isManager, $authMemberId, $now, $globalHours);
            return;

        case 'DELETE':
            responsesDelete($db, $database, $isManager, $authMemberId, $now);
            return;

        default:
            responsesFail(405, 'Methode nicht erlaubt');
    }
}

function responsesFail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
}

/** null = nicht angegeben, -1 = ungueltig, sonst die positive Zahl. */
function responsesQueryInt(string $key): ?int
{
    $raw = $_GET[$key] ?? null;
    if ($raw === null || $raw === '') {
        return null;
    }
    if (!is_string($raw)) {
        return -1;   // z. B. appointment_id[]=1 -- ein Array, keine Zahl
    }

    return (ctype_digit($raw) && (int) $raw > 0) ? (int) $raw : -1;
}

function responsesGetUpcoming($db, $database, ?int $memberId, string $now, int $globalHours): void
{
    if ($memberId === null) {
        echo json_encode(['appointments' => []]);
        return;
    }

    $items = [];
    foreach (responsesFetchUpcomingIds($db, $database, $memberId, $now) as $appointmentId) {
        $apt = responsesFetchAppointment($db, $database, $appointmentId);
        if ($apt !== null) {
            // Auch Admin und Manager bekommen hier die Sicht des Mitglieds:
            // Die Liste ist zum Antworten da, die Planung sitzt im Dashboard.
            $items[] = responsesPayload($db, $database, $apt, false, $memberId, $now, $globalHours);
        }
    }

    echo json_encode(['appointments' => $items], JSON_UNESCAPED_UNICODE);
}

function responsesGetOne($db, $database, bool $isManager, ?int $memberId, string $now, int $globalHours): void
{
    $appointmentId = responsesQueryInt('appointment_id');
    if ($appointmentId === null || $appointmentId < 0) {
        responsesFail(400, 'appointment_id fehlt oder ist ungültig');
        return;
    }

    $apt = responsesFetchAppointment($db, $database, $appointmentId);
    if ($apt === null) {
        responsesFail(404, 'Termin nicht gefunden');
        return;
    }
    if ((int) $apt['responses_enabled'] !== 1) {
        responsesFail(409, 'Für diese Terminart sind keine Rückmeldungen vorgesehen');
        return;
    }

    if (!$isManager && $memberId === null) {
        responsesFail(403, 'Mit diesem Benutzerkonto ist kein Mitglied verknüpft');
        return;
    }

    $payload = responsesPayload($db, $database, $apt, $isManager, $memberId, $now, $globalHours);

    if (!$isManager && !$payload['expected']) {
        responsesFail(403, 'Für dieses Mitglied ist zu diesem Termin keine Rückmeldung vorgesehen');
        return;
    }

    if ($isManager && ($_GET['format'] ?? '') === 'html') {
        responsesRenderPrint($db, $database, $payload);
        return;   // renderReport() beendet die Anfrage normalerweise per exit()
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

/**
 * Antwortstruktur eines Termins (Spec 6.1).
 *
 * @param ?int $viewerMemberId Mitglied, dessen Antwort als "own" erscheint
 * @param int $globalHours Globale Frist-Einstellung, einmal je Anfrage gelesen
 */
function responsesPayload($db, $database, array $apt, bool $isManager, ?int $viewerMemberId, string $now, int $globalHours): array
{
    $appointmentId = (int) $apt['appointment_id'];
    $hours = responseDeadlineHours($apt['response_deadline_hours'], (string) $globalHours);
    $deadline = responseDeadline($apt['date'], $apt['start_time'], $hours);
    $started  = responseHasStarted($apt['date'], $apt['start_time'], $now);

    $expected       = responsesDedupeExpected(responsesFetchExpected($db, $database, $appointmentId));
    $responses      = responsesFetchForAppointment($db, $database, $appointmentId);
    $expectedIds    = array_keys($expected);
    $statusByMember = array_map(static fn ($r) => $r['status'], $responses);

    $payload = [
        'appointment' => [
            'appointment_id' => $appointmentId,
            'title'          => $apt['title'],
            'date'           => $apt['date'],
            'start_time'     => $apt['start_time'],
            'type_id'        => $apt['type_id'] === null ? null : (int) $apt['type_id'],
            'type_name'      => $apt['type_name'],
            'color'          => $apt['color'],
        ],
        'settings' => [
            'names_visible'  => (int) $apt['responses_names_visible'] === 1,
            'require_excuse' => (int) $apt['responses_require_excuse'] === 1,
            'deadline_hours' => $hours,
            'deadline'       => $deadline,
        ],
        'started'  => $started,
        'expected' => $viewerMemberId !== null && isset($expected[$viewerMemberId]),
        'own'      => null,
        'summary'  => responseSummary($expectedIds, $statusByMember),
    ];

    if ($viewerMemberId !== null && isset($responses[$viewerMemberId])) {
        $own = $responses[$viewerMemberId];
        $payload['own'] = [
            'status'            => $own['status'],
            'comment'           => $own['comment'],
            'status_changed_at' => $own['status_changed_at'],
            'is_late'           => responseIsLate($own['status_changed_at'], $deadline),
            'excuse_state'      => $own['excuse_state'],
        ];
    }

    if ($isManager) {
        $present = $started ? responsesFetchPresentMemberIds($db, $database, $appointmentId) : [];
        $presentLookup = array_flip($present);

        $members = [];
        foreach ($expected as $memberId => $m) {
            $r = $responses[$memberId] ?? null;
            $members[] = [
                'member_id'         => $memberId,
                'name'              => $m['name'],
                'surname'           => $m['surname'],
                'group_name'        => $m['group_name'],
                'status'            => $r['status'] ?? null,
                'comment'           => $r['comment'] ?? null,
                'status_changed_at' => $r['status_changed_at'] ?? null,
                'is_late'           => $r === null ? null : responseIsLate($r['status_changed_at'], $deadline),
                'excuse_state'      => $r['excuse_state'] ?? null,
                'present'           => $started ? isset($presentLookup[$memberId]) : null,
            ];
        }
        $payload['members'] = $members;

        if ($started) {
            $payload['comparison'] = array_map('count', responseComparison($expectedIds, $statusByMember, $present));
        }
    } elseif ($payload['settings']['names_visible']) {
        // Bemerkungen, Zeitpunkte und Antraege anderer sieht ein Mitglied nie (Spec 3.6).
        $payload['members'] = array_values(array_map(static fn ($m, $id) => [
            'member_id'  => $id,
            'name'       => $m['name'],
            'surname'    => $m['surname'],
            'group_name' => $m['group_name'],
            'status'     => $responses[$id]['status'] ?? null,
        ], $expected, array_keys($expected)));
    }

    return $payload;
}

/** Druckansicht der Besetzung ueber renderReport() -- beendet die Anfrage. */
function responsesRenderPrint($db, $database, array $payload): void
{
    $labels = ['yes' => 'Zusage', 'no' => 'Absage', 'maybe' => 'Unsicher'];
    $apt    = $payload['appointment'];

    $byGroup = [];
    foreach ($payload['members'] as $m) {
        $status = $m['status'] === null ? 'keine Antwort' : $labels[$m['status']];
        if ($m['is_late']) {
            $status .= ' (kurzfristig)';
        }
        $byGroup[$m['group_name']][] = [
            $m['surname'] . ', ' . $m['name'],
            $status,
            (string) ($m['comment'] ?? ''),
            $m['status_changed_at'] === null ? '' : date('d.m.Y H:i', strtotime($m['status_changed_at'])),
        ];
    }

    $sections = [];
    foreach ($byGroup as $groupName => $rows) {
        $sections[] = [
            'heading' => $groupName,
            'columns' => ['Name', 'Rückmeldung', 'Bemerkung', 'Zeitpunkt'],
            'rows'    => $rows,
        ];
    }

    $s = $payload['summary'];
    renderReport($db, $database, [
        'title'    => 'Rückmeldungen: ' . $apt['title'],
        'period'   => date('d.m.Y', strtotime($apt['date'])) . ', ' . substr($apt['start_time'], 0, 5) . ' Uhr',
        'sections' => $sections,
        'notes'    => [
            "Zusage {$s['yes']} · Unsicher {$s['maybe']} · Absage {$s['no']} · keine Antwort {$s['open']}",
            'Frist: ' . date('d.m.Y H:i', strtotime($payload['settings']['deadline'])) . ' Uhr',
        ],
    ]);
}

/**
 * Fuer wen geschrieben wird. Sendet bei einem Fehler die Antwort selbst und liefert null.
 *
 * @return ?array{member_id: int, for_other: bool}
 */
function responsesResolveTarget($db, $database, bool $isManager, ?int $authMemberId): ?array
{
    $memberParam = responsesQueryInt('member_id');

    if ($memberParam !== null) {
        if (!$isManager) {
            responsesFail(403, 'Nur Admin und Manager dürfen für andere Mitglieder eintragen');
            return null;
        }
        if ($memberParam < 0) {
            responsesFail(400, 'member_id ist ungültig');
            return null;
        }
        $prefix = $database->table('');
        $stmt = $db->prepare("SELECT 1 FROM {$prefix}members WHERE member_id = ?");
        $stmt->execute([$memberParam]);
        if ($stmt->fetchColumn() === false) {
            responsesFail(404, 'Mitglied nicht gefunden');
            return null;
        }

        return ['member_id' => $memberParam, 'for_other' => true];
    }

    if ($authMemberId === null) {
        responsesFail(403, 'Mit diesem Benutzerkonto ist kein Mitglied verknüpft');
        return null;
    }

    return ['member_id' => $authMemberId, 'for_other' => false];
}

/**
 * Termin, Ziel und Erwartung pruefen -- gemeinsam fuer PUT und DELETE.
 *
 * @return ?array{apt: array, member_id: int}
 */
function responsesPrepareWrite($db, $database, bool $isManager, ?int $authMemberId, string $now,
                               bool $requireEnabled): ?array
{
    $appointmentId = responsesQueryInt('appointment_id');
    if ($appointmentId === null || $appointmentId < 0) {
        responsesFail(400, 'appointment_id fehlt oder ist ungültig');
        return null;
    }

    $apt = responsesFetchAppointment($db, $database, $appointmentId);
    if ($apt === null) {
        responsesFail(404, 'Termin nicht gefunden');
        return null;
    }
    if ($requireEnabled && (int) $apt['responses_enabled'] !== 1) {
        responsesFail(409, 'Für diese Terminart sind keine Rückmeldungen vorgesehen');
        return null;
    }

    $target = responsesResolveTarget($db, $database, $isManager, $authMemberId);
    if ($target === null) {
        return null;
    }

    $expected = responsesDedupeExpected(responsesFetchExpected($db, $database, $appointmentId));
    if (!isset($expected[$target['member_id']])) {
        responsesFail(403, 'Für dieses Mitglied ist zu diesem Termin keine Rückmeldung vorgesehen');
        return null;
    }

    // Nach Beginn nur noch Verwalter fuer ein Mitglied, etwa nach einem Anruf (Spec 5.2).
    if (!$target['for_other'] && responseHasStarted($apt['date'], $apt['start_time'], $now)) {
        responsesFail(409, 'Der Termin hat bereits begonnen');
        return null;
    }

    return ['apt' => $apt, 'member_id' => $target['member_id']];
}

function responsesPut($db, $database, int $authUserId, bool $isManager, ?int $authMemberId, string $now,
                      int $globalHours): void
{
    $ctx = responsesPrepareWrite($db, $database, $isManager, $authMemberId, $now, true);
    if ($ctx === null) {
        return;
    }
    $apt           = $ctx['apt'];
    $memberId      = $ctx['member_id'];
    $appointmentId = (int) $apt['appointment_id'];

    $data       = json_decode((string) file_get_contents('php://input'), true);
    $status     = is_array($data) ? ($data['status'] ?? null) : null;
    $commentRaw = is_array($data) ? ($data['comment'] ?? null) : null;

    $error = responseInputError($status, $commentRaw);
    if ($error !== null) {
        responsesFail(400, $error);
        return;
    }
    $comment       = responseNormalizeComment($commentRaw);
    $requireExcuse = (int) $apt['responses_require_excuse'] === 1;

    if ($requireExcuse && $status === 'no' && $comment === null) {
        responsesFail(422, 'Eine Absage zu diesem Termin braucht eine Begründung');
        return;
    }

    $prefix = $database->table('');
    $db->beginTransaction();

    try {
        // Termin zuerst sperren: Ohne diese Sperre nehmen zwei gleichzeitige
        // Erst-PUTs auf denselben Termin je eine Luecken-Sperre in uq_response
        // auf eine noch nicht vorhandene Zeile, und beide INSERTs verklemmen
        // sich gegenseitig (Deadlock 1213). Die Termin-Sperre serialisiert
        // Schreibzugriffe je Termin und macht daraus ein Warten statt eines
        // Deadlocks.
        $db->prepare("SELECT appointment_id FROM {$prefix}appointments WHERE appointment_id = ? FOR UPDATE")
           ->execute([$appointmentId]);

        $existing   = responsesFetchOneForUpdate($db, $database, $appointmentId, $memberId);
        $ownAbsence = responsesFetchOwnAbsence($db, $database, $appointmentId, $memberId);

        $exceptionId = $existing['exception_id'] ?? null;
        $action = responseExcuseAction($requireExcuse, $existing['status'] ?? null, $status,
                                       $existing['excuse_state'] ?? null, $ownAbsence !== null);

        switch ($action) {
            case 'create':
                $db->prepare("INSERT INTO {$prefix}exceptions
                              (member_id, appointment_id, exception_type, reason,
                               requested_arrival_time, status, created_by)
                              VALUES (?, ?, 'absence', ?, NULL, 'pending', ?)")
                   ->execute([$memberId, $appointmentId, $comment, $authUserId]);
                $exceptionId = (int) $db->lastInsertId();
                break;

            case 'link':
                $exceptionId = (int) $ownAbsence['exception_id'];
                break;

            case 'update_reason':
                $db->prepare("UPDATE {$prefix}exceptions SET reason = ?
                              WHERE exception_id = ? AND status = 'pending'")
                   ->execute([$comment, $exceptionId]);
                break;

            case 'delete':
                $stmt = $db->prepare("DELETE FROM {$prefix}exceptions
                              WHERE exception_id = ? AND status = 'pending'");
                $stmt->execute([$exceptionId]);
                // Nur loesen, wenn wirklich geloescht wurde -- ist der Antrag
                // inzwischen genehmigt (nicht mehr 'pending'), hat eine
                // gleichzeitige Genehmigung gewonnen, und die Verknuepfung bleibt.
                if ($stmt->rowCount() > 0) {
                    $exceptionId = null;
                }
                break;
        }

        $statusChangedAt = ($existing !== null && $existing['status'] === $status)
            ? $existing['status_changed_at']
            : $now;

        $db->prepare("INSERT INTO {$prefix}appointment_responses
                      (appointment_id, member_id, status, comment, exception_id, status_changed_at, updated_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE status = VALUES(status), comment = VALUES(comment),
                          exception_id = VALUES(exception_id),
                          status_changed_at = VALUES(status_changed_at), updated_at = VALUES(updated_at)")
           ->execute([$appointmentId, $memberId, $status, $comment, $exceptionId, $statusChangedAt, $now]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('appointment_responses PUT: ' . $e->getMessage());
        responsesFail(500, 'Rückmeldung konnte nicht gespeichert werden');
        return;
    }

    // "own" traegt die Antwort des betroffenen Mitglieds, auch wenn ein Verwalter schrieb.
    echo json_encode(responsesPayload($db, $database, $apt, $isManager, $memberId, $now, $globalHours), JSON_UNESCAPED_UNICODE);
}

function responsesDelete($db, $database, bool $isManager, ?int $authMemberId, string $now): void
{
    $ctx = responsesPrepareWrite($db, $database, $isManager, $authMemberId, $now, false);
    if ($ctx === null) {
        return;
    }
    $apt           = $ctx['apt'];
    $memberId      = $ctx['member_id'];
    $appointmentId = (int) $apt['appointment_id'];
    $prefix        = $database->table('');

    $db->beginTransaction();

    try {
        // Termin zuerst sperren -- derselbe Grund wie in responsesPut(): Ohne
        // diese Sperre koennen gleichzeitige Schreibzugriffe auf denselben
        // Termin ueber die Luecken-Sperren in uq_response in einen Deadlock
        // laufen. Die Termin-Sperre serialisiert sie stattdessen.
        $db->prepare("SELECT appointment_id FROM {$prefix}appointments WHERE appointment_id = ? FOR UPDATE")
           ->execute([$appointmentId]);

        $existing = responsesFetchOneForUpdate($db, $database, $appointmentId, $memberId);
        if ($existing === null) {
            $db->rollBack();
            responsesFail(404, 'Keine Rückmeldung vorhanden');
            return;
        }

        $action = responseExcuseAction((int) $apt['responses_require_excuse'] === 1,
                                       $existing['status'], null, $existing['excuse_state'], false);
        if ($action === 'delete') {
            $db->prepare("DELETE FROM {$prefix}exceptions WHERE exception_id = ? AND status = 'pending'")
               ->execute([(int) $existing['exception_id']]);
        }

        $db->prepare("DELETE FROM {$prefix}appointment_responses WHERE response_id = ?")
           ->execute([(int) $existing['response_id']]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('appointment_responses DELETE: ' . $e->getMessage());
        responsesFail(500, 'Rückmeldung konnte nicht zurückgenommen werden');
        return;
    }

    echo json_encode(['message' => 'Rückmeldung zurückgenommen'], JSON_UNESCAPED_UNICODE);
}
