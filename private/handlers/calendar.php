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
