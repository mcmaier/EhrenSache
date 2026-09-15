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
