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
// KALENDER-ABO (FI-8)
// ============================================
// calendar_feed: Verwaltung des eigenen Abos (angemeldet, im Router).
// calendar:      der Feed selbst (oeffentlich, frueher Ausstieg in api.php).
// Spec: docs/superpowers/specs/2026-10-07-kalender-abo-design.md

require_once __DIR__ . '/../helpers/ical.php';

/** last_fetched_at wird hoechstens so oft geschrieben (Sekunden). */
const CALENDAR_FEED_FETCH_RESOLUTION = 3600;

function calendarFeedTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function calendarFeedUrl(string $token): string
{
    return rtrim(BASE_URL, '/') . '/api/calendar/' . $token . '.ics';
}

/**
 * @return array{active: bool, member_linked: bool, hide_declined: bool, created_at: ?string, last_fetched_at: ?string}
 */
function calendarFeedStatus(PDO $db, string $prefix, int $userId, bool $memberLinked): array
{
    $stmt = $db->prepare("SELECT hide_declined, created_at, last_fetched_at FROM {$prefix}calendar_feeds WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'active'          => $row !== false,
        'member_linked'   => $memberLinked,
        'hide_declined'   => $row !== false ? ((int) $row['hide_declined'] === 1) : true,
        'created_at'      => $row !== false ? $row['created_at'] : null,
        'last_fetched_at' => $row !== false ? $row['last_fetched_at'] : null,
    ];
}

function handleCalendarFeed($db, $database, string $method, int $authUserId, ?int $authMemberId): void
{
    $prefix = $database->table('');

    if (isDevice()) {
        http_response_code(403);
        echo json_encode(['message' => 'Geräte haben kein Kalender-Abo'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $memberLinked = $authMemberId !== null;

    switch ($method) {
        case 'GET':
            echo json_encode(calendarFeedStatus($db, $prefix, $authUserId, $memberLinked));
            return;

        case 'POST':
            if (!$memberLinked) {
                http_response_code(409);
                echo json_encode([
                    'message' => 'Das Kalender-Abo braucht ein verknüpftes Mitglied',
                    'code'    => 'NO_MEMBER',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }

            // Ersetzen behaelt hide_declined; der alte Link ist mit dem neuen Hash sofort tot.
            $token = bin2hex(random_bytes(32));
            $db->prepare("INSERT INTO {$prefix}calendar_feeds (user_id, token_hash, hide_declined, created_at, last_fetched_at)
                          VALUES (?, ?, 1, ?, NULL)
                          ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash),
                                                  created_at = VALUES(created_at),
                                                  last_fetched_at = NULL")
               ->execute([$authUserId, calendarFeedTokenHash($token), date('Y-m-d H:i:s')]);

            $status = calendarFeedStatus($db, $prefix, $authUserId, true);
            $url    = calendarFeedUrl($token);
            http_response_code(201);
            echo json_encode([
                'url'           => $url,
                'webcal_url'    => preg_replace('#^https?://#', 'webcal://', $url),
                'created_at'    => $status['created_at'],
                'hide_declined' => $status['hide_declined'],
            ]);
            return;

        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);
            $hide = is_array($data) && isset($data['hide_declined'])
                ? filter_var($data['hide_declined'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : null;
            if ($hide === null) {
                http_response_code(400);
                echo json_encode(['message' => 'hide_declined (true/false) fehlt'], JSON_UNESCAPED_UNICODE);
                return;
            }

            if (!calendarFeedStatus($db, $prefix, $authUserId, $memberLinked)['active']) {
                http_response_code(404);
                echo json_encode(['message' => 'Kein Kalender-Abo vorhanden'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $db->prepare("UPDATE {$prefix}calendar_feeds SET hide_declined = ? WHERE user_id = ?")
               ->execute([$hide ? 1 : 0, $authUserId]);
            echo json_encode(calendarFeedStatus($db, $prefix, $authUserId, $memberLinked));
            return;

        case 'DELETE':
            $db->prepare("DELETE FROM {$prefix}calendar_feeds WHERE user_id = ?")->execute([$authUserId]);
            echo json_encode(['message' => 'Kalender-Abo beendet'], JSON_UNESCAPED_UNICODE);
            return;

        default:
            http_response_code(405);
            echo json_encode(['message' => 'Method not allowed']);
    }
}

/**
 * Inhaber eines Abo-Tokens, nur wenn der Abruf liefern darf: Konto aktiv und
 * freigeschaltet, Mitglied verknuepft, kein Geraet. Sonst null -- api.php zaehlt
 * den Abruf dann in die Rate-Grenze fuer Unangemeldete (Abschnitt 6.2).
 *
 * @return array{user_id: int|string, member_id: int|string, hide_declined: int|string}|null
 */
function calendarFeedOwner(PDO $db, string $prefix, $token): ?array
{
    if (!is_string($token) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }

    $stmt = $db->prepare("SELECT f.user_id, u.member_id, f.hide_declined
                          FROM {$prefix}calendar_feeds f
                          JOIN {$prefix}users u ON u.user_id = f.user_id
                          WHERE f.token_hash = ?
                            AND u.is_active = 1
                            AND u.account_status = 'active'
                            AND u.member_id IS NOT NULL
                            AND u.role <> 'device'");
    $stmt->execute([calendarFeedTokenHash($token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

/** Einheitliche Antwort fuer jeden Fall, in dem es keinen Feed gibt -- sie verraet nicht, warum. */
function calendarFeedNotFound(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
}

/** @param array<string, mixed>|null $owner Ergebnis von calendarFeedOwner() */
function handleCalendarDownload($db, $database, string $method, ?array $owner): void
{
    // api.php setzt CORS-Header fuer alle Aufrufe. Der Feed braucht keinen
    // Zugriff aus fremden Seiten (Kalender-Apps rufen direkt ab) -- mit dem
    // Token in der URL soll ihn auch kein Skript einer anderen Seite lesen
    // koennen (vgl. OI-126). Gilt fuer jede Antwort, auch 404 und 405.
    header_remove('Access-Control-Allow-Origin');
    header_remove('Access-Control-Allow-Methods');
    header_remove('Access-Control-Allow-Headers');
    header_remove('Access-Control-Allow-Credentials');

    if ($method !== 'GET' && $method !== 'HEAD') {
        http_response_code(405);
        header('Allow: GET, HEAD');
        header('Content-Type: text/plain; charset=utf-8');
        return;
    }

    // Abgeschaltet: 404 wie ein unbekannter Link, nicht FEATURE_DISABLED (Spec).
    if ($owner === null || !isFeatureEnabled($db, $database, 'calendar_feed')) {
        calendarFeedNotFound();
        return;
    }

    $prefix   = $database->table('');
    $memberId = (int) $owner['member_id'];
    $rows     = [];

    // Dieselbe Sichtbarkeitsregel wie die Terminliste, fuer jede Rolle (Spec).
    $vis = appointmentGroupVisibility($db, $prefix, $memberId);
    if ($vis !== null) {
        // COALESCE um r.status: ohne Rueckmeldung ist r.status NULL, und
        // NOT(... AND NULL) waere NULL -- der Termin fiele still heraus.
        $sql = "SELECT a.appointment_id, a.title, a.description, a.location, a.date,
                       a.start_time, a.end_time, at.type_name,
                       COALESCE(at.responses_enabled, 0) AS responses_enabled,
                       r.status AS response_status, r.comment AS response_comment
                FROM {$prefix}appointments a
                LEFT JOIN {$prefix}appointment_types at ON at.type_id = a.type_id
                LEFT JOIN {$prefix}appointment_responses r
                       ON r.appointment_id = a.appointment_id AND r.member_id = ?
                WHERE a.date BETWEEN ? AND ?
                  AND a.is_auto_created = 0"
             . $vis[0]
             . " AND NOT (? = 1 AND COALESCE(at.responses_enabled, 0) = 1 AND COALESCE(r.status, '') = 'no')
                ORDER BY a.date, a.start_time";
        $params = array_merge(
            [$memberId, date('Y-m-d', strtotime('-3 months')), date('Y-m-d', strtotime('+12 months'))],
            $vis[1],
            [(int) $owner['hide_declined']]
        );
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $db->prepare("UPDATE {$prefix}calendar_feeds SET last_fetched_at = ?
                  WHERE user_id = ? AND (last_fetched_at IS NULL OR last_fetched_at < ?)")
       ->execute([date('Y-m-d H:i:s'), $owner['user_id'], date('Y-m-d H:i:s', time() - CALENDAR_FEED_FETCH_RESOLUTION)]);

    $org     = trim(systemSetting($db, $database, 'organization_name', ''));
    $calName = $org !== '' ? $org . ' – Termine' : 'Termine';
    $host    = parse_url(BASE_URL, PHP_URL_HOST) ?: 'ehrensache';
    $body    = icalBuildCalendar($calName, $rows, $host, new DateTimeImmutable('now', new DateTimeZone('UTC')));

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="termine.ics"');
    header('Cache-Control: private, max-age=0');
    header('X-Robots-Tag: noindex');

    if ($method === 'GET') {
        echo $body;
    }
}
