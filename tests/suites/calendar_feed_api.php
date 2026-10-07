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

/**
 * Kalender-Abo (FI-8): Verwaltung (calendar_feed) und Feed (calendar).
 *
 * Konten fuer Randfaelle (Geraet, ohne Mitglied, deaktiviert) entstehen direkt in
 * der Datenbank und werden am Ende geloescht; calendar_feeds faellt per CASCADE mit.
 * Der Schalter calendar_feed_enabled wird je Test gesetzt und zurueckgestellt.
 */
require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../../private/helpers/config_reader.php';
require_once __DIR__ . '/../../private/helpers/features.php';

if (!extension_loaded('curl')) {
    return;
}

function cfPdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'));
        $db  = $cfg['db'];
        $pdo = new PDO(
            'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    return $pdo;
}

function cfPrefix(): string
{
    return configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'))['db']['prefix'];
}

function cfGetSetting(string $key): string
{
    $res = apiRequest('GET', 'settings', ['token' => apiToken('admin')]);
    assertStatus(200, $res);
    foreach ($res['body']['settings'] as $row) {
        if ($row['setting_key'] === $key) {
            return (string) $row['setting_value'];
        }
    }
    throw new RuntimeException("Einstellung {$key} nicht gefunden");
}

function cfSetSetting(string $key, string $value): void
{
    assertStatus(200, apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]), "Einstellung {$key} nicht gesetzt");
}

/** Fuehrt $fn mit eingeschaltetem (oder $value) Kalender-Abo aus und stellt den Schalter zurueck. */
function cfWithFeature(callable $fn, string $value = '1'): void
{
    $vorher = cfGetSetting('calendar_feed_enabled');
    cfSetSetting('calendar_feed_enabled', $value);
    try {
        $fn();
    } finally {
        cfSetSetting('calendar_feed_enabled', $vorher);
    }
}

function cfFeed(string $role, string $method, ?array $body = null): array
{
    $opts = ['token' => apiToken($role)];
    if ($body !== null) {
        $opts['body'] = $body;
    }

    return apiRequest($method, 'calendar_feed', $opts);
}

/**
 * Legt ein Konto direkt in der Datenbank an und liefert [user_id, api_token].
 * $memberId null = ohne Mitglied.
 *
 * @return array{0: int, 1: string}
 */
function cfCreateUser(string $role, ?int $memberId, int $isActive = 1, string $status = 'active'): array
{
    $token = bin2hex(random_bytes(24));
    $stmt  = cfPdo()->prepare('INSERT INTO ' . cfPrefix() . 'users
        (email, name, role, is_active, account_status, member_id, api_token, api_token_expires_at, email_verified, device_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 1, ?)');
    $stmt->execute([
        $role === 'device' ? null : 'cf-' . uniqid() . '@example.invalid',
        'CF ' . $role,
        $role,
        $isActive,
        $status,
        $memberId,
        $token,
        $role === 'device' ? 'totp_location' : null,
    ]);

    return [(int) cfPdo()->lastInsertId(), $token];
}

function cfDeleteUser(?int $userId): void
{
    if ($userId !== null) {
        cfPdo()->prepare('DELETE FROM ' . cfPrefix() . 'users WHERE user_id = ?')->execute([$userId]);
    }
}

test('calendar_feed: Schalter aus -> 403 FEATURE_DISABLED', function () {
    cfWithFeature(function () {
        $res = cfFeed('user', 'GET');
        assertStatus(403, $res);
        assertSame('FEATURE_DISABLED', $res['body']['code'] ?? null);
    }, '0');
});

test('calendar_feed: ohne Abo inaktiv, Erzeugen liefert URL einmalig, Status aktiv', function () {
    cfWithFeature(function () {
        cfFeed('user', 'DELETE');
        $res = cfFeed('user', 'GET');
        assertStatus(200, $res);
        assertSame(false, $res['body']['active']);
        assertSame(true, $res['body']['member_linked']);
        assertSame(true, $res['body']['hide_declined'], 'Standard: abgesagte ausblenden');

        $neu = cfFeed('user', 'POST', []);
        assertStatus(201, $neu);
        assertTrue((bool) preg_match('#/api/calendar/[0-9a-f]{64}\.ics$#', (string) $neu['body']['url']),
            'URL hat nicht die Form …/api/calendar/<64 hex>.ics: ' . $neu['raw']);
        assertTrue(strpos((string) $neu['body']['webcal_url'], 'webcal://') === 0);
        assertSame(substr($neu['body']['url'], strpos($neu['body']['url'], '://')),
                   substr($neu['body']['webcal_url'], strpos($neu['body']['webcal_url'], '://')));

        $status = cfFeed('user', 'GET');
        assertSame(true, $status['body']['active']);
        assertSame(null, $status['body']['last_fetched_at']);
        assertTrue(!array_key_exists('url', $status['body']), 'GET darf den Link nicht erneut liefern');
        assertTrue(strpos($status['raw'], '"token') === false, 'GET verraet Token oder Hash');
        cfFeed('user', 'DELETE');
    });
});

test('calendar_feed: Datenbank speichert nur den Hash', function () {
    cfWithFeature(function () {
        $neu = cfFeed('user', 'POST', []);
        assertStatus(201, $neu);
        preg_match('#/([0-9a-f]{64})\.ics$#', $neu['body']['url'], $m);
        $stmt = cfPdo()->prepare('SELECT token_hash FROM ' . cfPrefix() . 'calendar_feeds f
            JOIN ' . cfPrefix() . 'users u ON u.user_id = f.user_id WHERE u.member_id = ?');
        $stmt->execute([apiMemberId('user')]);
        $hash = (string) $stmt->fetchColumn();
        assertSame(hash('sha256', $m[1]), $hash);
        assertTrue($hash !== $m[1]);
        cfFeed('user', 'DELETE');
    });
});

test('calendar_feed: PUT hide_declined, ohne Abo 404, ungueltig 400', function () {
    cfWithFeature(function () {
        cfFeed('user', 'DELETE');
        assertStatus(404, cfFeed('user', 'PUT', ['hide_declined' => false]));
        assertStatus(201, cfFeed('user', 'POST', []));
        assertStatus(400, cfFeed('user', 'PUT', ['hide_declined' => 'vielleicht']));
        assertStatus(400, cfFeed('user', 'PUT', []));
        $res = cfFeed('user', 'PUT', ['hide_declined' => false]);
        assertStatus(200, $res);
        assertSame(false, $res['body']['hide_declined']);
        assertSame(false, cfFeed('user', 'GET')['body']['hide_declined']);
        // Ersetzen behaelt die Einstellung
        assertStatus(201, cfFeed('user', 'POST', []));
        assertSame(false, cfFeed('user', 'GET')['body']['hide_declined']);
        cfFeed('user', 'DELETE');
    });
});

test('calendar_feed: DELETE ohne Abo ist ebenfalls Erfolg', function () {
    cfWithFeature(function () {
        cfFeed('user', 'DELETE');
        assertStatus(200, cfFeed('user', 'DELETE'));
    });
});

test('calendar_feed: Geraet 403, Konto ohne Mitglied 409 (GET meldet member_linked false)', function () {
    $device = $ohne = null;
    try {
        [$device, $deviceToken] = cfCreateUser('device', null);
        [$ohne, $ohneToken]     = cfCreateUser('user', null);
        cfWithFeature(function () use ($deviceToken, $ohneToken) {
            assertStatus(403, apiRequest('POST', 'calendar_feed', ['token' => $deviceToken, 'body' => []]));
            $res = apiRequest('POST', 'calendar_feed', ['token' => $ohneToken, 'body' => []]);
            assertStatus(409, $res);
            assertSame('NO_MEMBER', $res['body']['code'] ?? null);
            $get = apiRequest('GET', 'calendar_feed', ['token' => $ohneToken]);
            assertStatus(200, $get);
            assertSame(false, $get['body']['member_linked']);
            assertSame(false, $get['body']['active']);
        });
    } finally {
        cfDeleteUser($device);
        cfDeleteUser($ohne);
    }
});

test('calendar_feed: Konto loeschen entfernt das Abo (CASCADE)', function () {
    $user = null;
    try {
        [$user, $token] = cfCreateUser('user', apiMemberId('user'));
        cfWithFeature(function () use ($token) {
            assertStatus(201, apiRequest('POST', 'calendar_feed', ['token' => $token, 'body' => []]));
        });
    } finally {
        cfDeleteUser($user);
    }
    $stmt = cfPdo()->prepare('SELECT COUNT(*) FROM ' . cfPrefix() . 'calendar_feeds WHERE user_id = ?');
    $stmt->execute([$user]);
    assertSame(0, (int) $stmt->fetchColumn());
});
