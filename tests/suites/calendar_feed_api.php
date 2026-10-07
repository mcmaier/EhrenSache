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

/** user_id des Testkontos einer Rolle (ueber dessen Token). */
function cfUserId(string $role): int
{
    $stmt = cfPdo()->prepare('SELECT user_id FROM ' . cfPrefix() . 'users WHERE api_token = ?');
    $stmt->execute([apiToken($role)]);

    return (int) $stmt->fetchColumn();
}

function cfDeleteUser(?int $userId): void
{
    if ($userId !== null) {
        cfPdo()->prepare('DELETE FROM ' . cfPrefix() . 'users WHERE user_id = ?')->execute([$userId]);
    }
}

test('calendar_feed: Schalter aus -> 403 FEATURE_DISABLED (alle Methoden)', function () {
    cfWithFeature(function () {
        $bodies = ['GET' => null, 'POST' => [], 'PUT' => ['hide_declined' => false], 'DELETE' => null];
        foreach ($bodies as $method => $body) {
            $res = cfFeed('user', $method, $body);
            assertStatus(403, $res, $method);
            assertSame('FEATURE_DISABLED', $res['body']['code'] ?? null, $method);
        }
    }, '0');
});

test('calendar_feed: ohne Abo inaktiv, Erzeugen liefert URL einmalig, Status aktiv', function () {
    cfWithFeature(function () {
        try {
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
        } finally {
            cfFeed('user', 'DELETE');
        }
    });
});

test('calendar_feed: Datenbank speichert nur den Hash', function () {
    cfWithFeature(function () {
        try {
            $neu = cfFeed('user', 'POST', []);
            assertStatus(201, $neu);
            preg_match('#/([0-9a-f]{64})\.ics$#', $neu['body']['url'], $m);
            $stmt = cfPdo()->prepare('SELECT token_hash FROM ' . cfPrefix() . 'calendar_feeds f WHERE f.user_id = ?');
            $stmt->execute([cfUserId('user')]);
            $hash = (string) $stmt->fetchColumn();
            assertSame(hash('sha256', $m[1]), $hash);
            assertTrue($hash !== $m[1]);
        } finally {
            cfFeed('user', 'DELETE');
        }
    });
});

test('calendar_feed: PUT hide_declined, ohne Abo 404, ungueltig 400', function () {
    cfWithFeature(function () {
        try {
            cfFeed('user', 'DELETE');
            assertStatus(404, cfFeed('user', 'PUT', ['hide_declined' => false]));
            assertStatus(201, cfFeed('user', 'POST', []));
            assertStatus(400, cfFeed('user', 'PUT', ['hide_declined' => 'vielleicht']));
            assertStatus(400, cfFeed('user', 'PUT', ['hide_declined' => null]));
            assertStatus(400, cfFeed('user', 'PUT', []));
            $res = cfFeed('user', 'PUT', ['hide_declined' => false]);
            assertStatus(200, $res);
            assertSame(false, $res['body']['hide_declined']);
            assertSame(false, cfFeed('user', 'GET')['body']['hide_declined']);
            // Ersetzen behaelt die Einstellung
            assertStatus(201, cfFeed('user', 'POST', []));
            assertSame(false, cfFeed('user', 'GET')['body']['hide_declined']);
        } finally {
            cfFeed('user', 'DELETE');
        }
    });
});

test('calendar_feed: DELETE ohne Abo ist ebenfalls Erfolg', function () {
    cfWithFeature(function () {
        cfFeed('user', 'DELETE');
        assertStatus(200, cfFeed('user', 'DELETE'));
    });
});

test('calendar_feed: DELETE des einen Nutzers laesst das Abo eines anderen bestehen', function () {
    cfWithFeature(function () {
        try {
            assertStatus(201, cfFeed('user', 'POST', []));
            assertStatus(201, cfFeed('manager', 'POST', []));
            assertStatus(200, cfFeed('user', 'DELETE'));
            assertSame(false, cfFeed('user', 'GET')['body']['active']);
            assertSame(true, cfFeed('manager', 'GET')['body']['active'], 'Abo des anderen Nutzers wurde mitgeloescht');
        } finally {
            cfFeed('user', 'DELETE');
            cfFeed('manager', 'DELETE');
        }
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

test('calendar_feed: Sitzungsweg verlangt CSRF-Token', function () {
    cfWithFeature(function () {
        $cfg   = testConfig();
        $login = apiRequest('POST', 'login', ['body' => [
            'email' => $cfg['user']['email'], 'password' => $cfg['user']['password'],
        ]]);
        assertStatus(200, $login, 'Anmeldung fehlgeschlagen');
        $cookie = explode(';', (string) $login['set_cookie'])[0];
        $csrf   = (string) $login['body']['csrf_token'];
        try {
            $ohne = apiRequest('POST', 'calendar_feed', ['cookie' => $cookie, 'body' => []]);
            assertStatus(403, $ohne);
            assertSame('Invalid CSRF token', $ohne['body']['message'] ?? null);
            assertStatus(201, apiRequest('POST', 'calendar_feed', ['cookie' => $cookie, 'body' => ['csrf_token' => $csrf]]));
        } finally {
            apiRequest('DELETE', 'calendar_feed', ['cookie' => $cookie, 'query' => ['csrf_token' => $csrf]]);
        }
    });
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

// ---- Feed ------------------------------------------------------------------

/** Ruft eine absolute URL ab. @return array{status: int, body: string, headers: array<string, string>} */
function cfFetch(string $url, string $method = 'GET'): array
{
    $headers = [];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $h) use (&$headers): int {
        $teile = explode(':', $h, 2);
        if (count($teile) === 2) {
            $headers[strtolower(trim($teile[0]))] = trim($teile[1]);
        }

        return strlen($h);
    });
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('Abruf fehlgeschlagen: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body, 'headers' => $headers];
}

/** Feed-URL ueber den Query-Parameter, ohne Rewrite. */
function cfQueryUrl(string $token): string
{
    return rtrim(testConfig()['base_url'], '/') . '/api/api.php?resource=calendar&token=' . $token;
}

function cfTokenFromUrl(string $url): string
{
    assertTrue((bool) preg_match('#/([0-9a-f]{64})\.ics$#', $url, $m), "Keine Feed-URL: {$url}");

    return $m[1];
}

/** Hoechste id in rate_limits -- Ausgangspunkt fuer cfCountApiRequestsSince(). */
function cfMaxRateId(): int
{
    return (int) cfPdo()->query('SELECT COALESCE(MAX(id), 0) FROM ' . cfPrefix() . 'rate_limits')->fetchColumn();
}

/**
 * Neue api_request-Zeilen seit $maxId. Bewusst ueber die id und nicht als
 * Differenz zweier Gesamtzahlen: Der Rate Limiter loescht vor dem Zaehlen alle
 * Zeilen, die aelter als 60 s sind -- die Gesamtzahl kann also zwischen zwei
 * Abrufen sinken, ohne dass etwas falsch laeuft.
 */
function cfCountApiRequestsSince(int $maxId): int
{
    $stmt = cfPdo()->prepare('SELECT COUNT(*) FROM ' . cfPrefix() . "rate_limits WHERE action = 'api_request' AND id > ?");
    $stmt->execute([$maxId]);

    return (int) $stmt->fetchColumn();
}

function cfCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");

    return (int) $res['body']['id'];
}

function cfDelete(string $resource, ?int $id): void
{
    if ($id !== null) {
        apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    }
}

/**
 * Welt: eigene Gruppe mit Terminart (Rueckmeldungen an), fremde Gruppe mit
 * Terminart, das Mitglied von "user" kommt in die eigene Gruppe. Termine:
 * in    (+10 Tage, eigene Gruppe)        -> im Feed
 * fremd (+10 Tage, fremde Gruppe)        -> nie im Feed
 * alt   (-4 Monate)                      -> ausserhalb des Zeitraums
 * nah   (-2 Monate)                      -> im Feed
 * fern  (+13 Monate)                     -> ausserhalb des Zeitraums
 * auto  (+9 Tage, is_auto_created = 1)   -> nie im Feed (nicht +10: Dublettenpruefung ±2h gegen "in")
 * ab    (+11 Tage, Rueckmeldung no)      -> nur ohne hide_declined
 * evtl  (+12 Tage, Rueckmeldung maybe)   -> immer im Feed
 *
 * @param callable(array<string, int>): void $fn
 */
function cfWithWorld(callable $fn): void
{
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Das Testkonto user braucht ein verknuepftes Mitglied');
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    $original = array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups'] ?? []);

    $s = substr(uniqid(), -6);
    $ids = [];
    try {
        $ids['group']  = cfCreate('member_groups', ['group_name' => "CF Eigen {$s}"]);
        $ids['group2'] = cfCreate('member_groups', ['group_name' => "CF Fremd {$s}"]);
        $ids['type']   = cfCreate('appointment_types', [
            'type_name' => "CF Eigen {$s}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => [$ids['group']], 'responses_enabled' => 1, 'response_deadline_hours' => 24,
        ]);
        $ids['type2']  = cfCreate('appointment_types', [
            'type_name' => "CF Fremd {$s}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => [$ids['group2']],
        ]);
        assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId],
            'body'  => ['group_ids' => array_values(array_unique(array_merge($original, [$ids['group']])))]]));

        $termin = static function (string $titel, string $tage, int $type) use ($s): int {
            return cfCreate('appointments', [
                'title' => "CF {$titel} {$s}", 'type_id' => $type,
                'date'  => date('Y-m-d', strtotime($tage)), 'start_time' => '19:30', 'end_time' => '21:00',
                'location' => 'Probenlokal',
            ]);
        };
        $ids['in']    = $termin('in', '+10 days', $ids['type']);
        $ids['fremd'] = $termin('fremd', '+10 days', $ids['type2']);
        $ids['alt']   = $termin('alt', '-4 months', $ids['type']);
        $ids['nah']   = $termin('nah', '-2 months', $ids['type']);
        $ids['fern']  = $termin('fern', '+13 months', $ids['type']);
        $ids['auto']  = $termin('auto', '+9 days', $ids['type']);
        $ids['ab']    = $termin('ab', '+11 days', $ids['type']);
        $ids['evtl']  = $termin('evtl', '+12 days', $ids['type']);
        cfPdo()->prepare('UPDATE ' . cfPrefix() . 'appointments SET is_auto_created = 1 WHERE appointment_id = ?')
               ->execute([$ids['auto']]);

        assertStatus(200, apiRequest('PUT', 'appointment_responses', ['token' => apiToken('user'),
            'query' => ['appointment_id' => $ids['ab']], 'body' => ['status' => 'no', 'comment' => 'Urlaub']]));
        assertStatus(200, apiRequest('PUT', 'appointment_responses', ['token' => apiToken('user'),
            'query' => ['appointment_id' => $ids['evtl']], 'body' => ['status' => 'maybe']]));

        $fn($ids);
    } finally {
        foreach (['in', 'fremd', 'alt', 'nah', 'fern', 'auto', 'ab', 'evtl'] as $k) {
            cfDelete('appointments', $ids[$k] ?? null);
        }
        apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['group_ids' => $original]]);
        cfDelete('appointment_types', $ids['type'] ?? null);
        cfDelete('appointment_types', $ids['type2'] ?? null);
        cfDelete('member_groups', $ids['group'] ?? null);
        cfDelete('member_groups', $ids['group2'] ?? null);
    }
}

function cfHasEvent(string $ics, int $appointmentId): bool
{
    return strpos(str_replace("\r\n ", '', $ics), "UID:appointment-{$appointmentId}@") !== false;
}

test('Feed: Gruppengrenze, Zeitraum, Auto-Termine, abgesagte ausgeblendet', function () {
    cfWithFeature(function () {
        cfWithWorld(function (array $ids) {
            try {
                $neu = cfFeed('user', 'POST', []);
                assertStatus(201, $neu);
                $res = cfFetch($neu['body']['url']);
                assertSame(200, $res['status'], 'Feed ueber die Rewrite-URL nicht abrufbar: ' . substr($res['body'], 0, 300));
                $ics = $res['body'];

                assertTrue(cfHasEvent($ics, $ids['in']), 'Termin der eigenen Gruppe fehlt');
                assertTrue(cfHasEvent($ics, $ids['nah']), 'Termin vor zwei Monaten fehlt');
                assertTrue(cfHasEvent($ics, $ids['evtl']), 'Termin mit „unsicher“ fehlt');
                assertTrue(!cfHasEvent($ics, $ids['fremd']), 'Termin einer fremden Gruppe im Feed');
                assertTrue(!cfHasEvent($ics, $ids['alt']), 'Termin vor vier Monaten im Feed');
                assertTrue(!cfHasEvent($ics, $ids['fern']), 'Termin in dreizehn Monaten im Feed');
                assertTrue(!cfHasEvent($ics, $ids['auto']), 'Auto-Termin im Feed');
                assertTrue(!cfHasEvent($ics, $ids['ab']), 'Abgesagter Termin trotz Standard „ausblenden“ im Feed');
                assertTrue(strpos(str_replace("\r\n ", '', $ics), 'SUMMARY:? CF evtl') !== false, 'Praefix „? “ fehlt');
            } finally {
                cfFeed('user', 'DELETE');
            }
        });
    });
});

test('Feed: hide_declined aus zeigt den abgesagten Termin mit ✗', function () {
    cfWithFeature(function () {
        cfWithWorld(function (array $ids) {
            try {
                $neu = cfFeed('user', 'POST', []);
                assertStatus(200, cfFeed('user', 'PUT', ['hide_declined' => false]));
                $ics = str_replace("\r\n ", '', cfFetch($neu['body']['url'])['body']);
                assertTrue(cfHasEvent($ics, $ids['ab']), 'Abgesagter Termin fehlt trotz hide_declined = false');
                assertTrue(strpos($ics, 'SUMMARY:✗ CF ab') !== false, 'Praefix „✗ “ fehlt');
                assertTrue(strpos($ics, 'Deine Rückmeldung: Abgesagt – Urlaub') !== false, 'Eigener Kommentar fehlt');
            } finally {
                cfFeed('user', 'DELETE');
            }
        });
    });
});

test('Feed: Header, HEAD ohne Rumpf, last_fetched_at gesetzt', function () {
    cfWithFeature(function () {
        try {
            $neu = cfFeed('user', 'POST', []);
            $res = cfFetch($neu['body']['url']);
            assertSame(200, $res['status']);
            assertTrue(strpos($res['headers']['content-type'] ?? '', 'text/calendar') === 0, 'Content-Type: ' . ($res['headers']['content-type'] ?? '-'));
            assertSame('private, max-age=0', $res['headers']['cache-control'] ?? null);
            assertSame('noindex', $res['headers']['x-robots-tag'] ?? null);
            assertTrue(!isset($res['headers']['set-cookie']), 'Feed startet eine Sitzung');
            assertTrue(cfFeed('user', 'GET')['body']['last_fetched_at'] !== null, 'last_fetched_at nicht gesetzt');

            $head = cfFetch($neu['body']['url'], 'HEAD');
            assertSame(200, $head['status']);
            assertSame('', $head['body']);
            assertSame(405, cfFetch($neu['body']['url'], 'POST')['status']);
        } finally {
            cfFeed('user', 'DELETE');
        }
    });
});

test('Feed: Antworten ohne CORS-Header (200, 404, 405)', function () {
    cfWithFeature(function () {
        try {
            $url = cfFeed('user', 'POST', [])['body']['url'];
            $ok  = cfFetch($url);
            assertSame(200, $ok['status']);
            assertTrue(!isset($ok['headers']['access-control-allow-origin']), 'Feed (200) traegt Access-Control-Allow-Origin');
            assertTrue(!isset($ok['headers']['access-control-allow-credentials']), 'Feed (200) traegt Access-Control-Allow-Credentials');

            $weg = cfFetch(cfQueryUrl(str_repeat('c', 64)));
            assertSame(404, $weg['status']);
            assertTrue(!isset($weg['headers']['access-control-allow-origin']), '404 traegt Access-Control-Allow-Origin');

            $post = cfFetch($url, 'POST');
            assertSame(405, $post['status']);
            assertTrue(!isset($post['headers']['access-control-allow-origin']), '405 traegt Access-Control-Allow-Origin');
        } finally {
            cfFeed('user', 'DELETE');
        }
    });
});

test('Feed: 404 bei unbekanntem, falsch geformtem, ersetztem und widerrufenem Token', function () {
    cfWithFeature(function () {
        try {
            assertSame(404, cfFetch(cfQueryUrl(str_repeat('a', 64)))['status']);
            assertSame(404, cfFetch(cfQueryUrl('kurz'))['status']);
            assertSame(404, cfFetch(cfQueryUrl(''))['status']);

            $alt = cfTokenFromUrl(cfFeed('user', 'POST', [])['body']['url']);
            assertSame(200, cfFetch(cfQueryUrl($alt))['status']);
            $neu = cfTokenFromUrl(cfFeed('user', 'POST', [])['body']['url']);
            assertSame(404, cfFetch(cfQueryUrl($alt))['status'], 'Ersetzter Link liefert noch');
            assertSame(200, cfFetch(cfQueryUrl($neu))['status']);
            cfFeed('user', 'DELETE');
            assertSame(404, cfFetch(cfQueryUrl($neu))['status'], 'Widerrufener Link liefert noch');
        } finally {
            cfFeed('user', 'DELETE');
        }
    });
});

test('Feed: Schalter aus -> 404 ohne JSON-Hinweis', function () {
    $token = null;
    try {
        cfWithFeature(function () use (&$token) {
            $token = cfTokenFromUrl(cfFeed('user', 'POST', [])['body']['url']);
        });
        cfWithFeature(function () use ($token) {
            $res = cfFetch(cfQueryUrl($token));
            assertSame(404, $res['status']);
            assertSame('', $res['body']);
        }, '0');
    } finally {
        cfWithFeature(fn () => cfFeed('user', 'DELETE'));
    }
});

test('Feed: deaktiviertes Konto und Konto ohne Mitglied -> 404', function () {
    $user = null;
    try {
        [$user] = cfCreateUser('user', apiMemberId('user'));
        $token = bin2hex(random_bytes(32));
        cfPdo()->prepare('INSERT INTO ' . cfPrefix() . 'calendar_feeds (user_id, token_hash, hide_declined, created_at) VALUES (?, ?, 1, NOW())')
               ->execute([$user, hash('sha256', $token)]);
        cfWithFeature(function () use ($user, $token) {
            assertSame(200, cfFetch(cfQueryUrl($token))['status'], 'Gegenprobe: aktives Konto muss liefern');
            $set = cfPdo()->prepare('UPDATE ' . cfPrefix() . 'users SET is_active = ?, account_status = ?, member_id = ? WHERE user_id = ?');
            $set->execute([0, 'active', apiMemberId('user'), $user]);
            assertSame(404, cfFetch(cfQueryUrl($token))['status'], 'is_active = 0 liefert');
            $set->execute([1, 'suspended', apiMemberId('user'), $user]);
            assertSame(404, cfFetch(cfQueryUrl($token))['status'], 'account_status suspended liefert');
            $set->execute([1, 'active', null, $user]);
            assertSame(404, cfFetch(cfQueryUrl($token))['status'], 'Konto ohne Mitglied liefert');
        });
    } finally {
        cfDeleteUser($user);
    }
});

test('Feed: ungueltige Tokens zaehlen in die Rate-Grenze, gueltige nicht', function () {
    cfWithFeature(function () {
        try {
            $token = cfTokenFromUrl(cfFeed('user', 'POST', [])['body']['url']);

            $maxId = cfMaxRateId();
            assertSame(200, cfFetch(cfQueryUrl($token))['status']);
            assertSame(200, cfFetch(cfQueryUrl($token))['status']);
            assertSame(0, cfCountApiRequestsSince($maxId), 'Gueltiger Feed-Abruf wurde gezaehlt');

            assertSame(404, cfFetch(cfQueryUrl(str_repeat('b', 64)))['status']);
            assertSame(1, cfCountApiRequestsSince($maxId), 'Ungueltiger Feed-Abruf wurde nicht genau einmal gezaehlt');
        } finally {
            cfFeed('user', 'DELETE');
        }
    });
});

test('Schalter aus: Abruf mit gueltigem Token zaehlt in die Rate-Grenze', function () {
    $token = null;
    try {
        cfWithFeature(function () use (&$token) {
            $token = cfTokenFromUrl(cfFeed('user', 'POST', [])['body']['url']);
        });
        cfWithFeature(function () use ($token) {
            $maxId = cfMaxRateId();
            assertSame(404, cfFetch(cfQueryUrl($token))['status']);
            assertSame(1, cfCountApiRequestsSince($maxId), 'Abruf bei ausgeschalteter Funktion wurde nicht genau einmal gezaehlt');
        }, '0');
    } finally {
        cfWithFeature(fn () => cfFeed('user', 'DELETE'));
    }
});

/** @return int[] Gruppen eines Mitglieds (ueber die API, wie cfWithWorld) */
function cfMemberGroups(int $memberId): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);

    return array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups'] ?? []);
}

/** @param int[] $groupIds */
function cfSetMemberGroups(int $memberId, array $groupIds): void
{
    assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
        'query' => ['id' => $memberId], 'body' => ['group_ids' => array_values(array_unique($groupIds))]]));
}

test('Feed: Gruppengrenze gilt auch fuer Verwalter', function () {
    $managerMember = apiMemberId('manager');
    assertTrue($managerMember !== null, 'Das Testkonto manager braucht ein verknuepftes Mitglied');
    assertTrue($managerMember !== apiMemberId('user'), 'manager und user teilen sich ein Mitglied -- Test waere wertlos');

    cfWithFeature(function () use ($managerMember) {
        cfWithWorld(function (array $ids) use ($managerMember) {
            $original = cfMemberGroups($managerMember);
            try {
                assertTrue(!in_array($ids['group2'], $original, true), 'Mitglied des Managers steckt in der Fremdgruppe');
                // Gegenprobe: In die eigene Gruppe aufnehmen, damit der Feed nicht nur
                // deshalb leer ist, weil das Mitglied gar keiner Gruppe angehoert.
                cfSetMemberGroups($managerMember, array_merge($original, [$ids['group']]));

                $neu = cfFeed('manager', 'POST', []);
                assertStatus(201, $neu);
                $res = cfFetch($neu['body']['url']);
                assertSame(200, $res['status']);
                assertTrue(cfHasEvent($res['body'], $ids['in']), 'Gegenprobe: Termin der eigenen Gruppe fehlt im Manager-Feed');
                assertTrue(!cfHasEvent($res['body'], $ids['fremd']), 'Manager-Feed enthaelt Termin einer fremden Gruppe');
            } finally {
                cfFeed('manager', 'DELETE');
                cfSetMemberGroups($managerMember, $original);
            }
        });
    });
});

test('Feed: Absage bei Terminart ohne Rueckmeldungen blendet nicht aus, kein ✗', function () {
    cfWithFeature(function () {
        cfWithWorld(function (array $ids) {
            $s = substr(uniqid(), -6);
            $typeId = $aptId = null;
            try {
                $typeId = cfCreate('appointment_types', [
                    'type_name' => "CF Ohne {$s}", 'is_default' => 0, 'color' => '#667eea',
                    'group_ids' => [$ids['group']], 'responses_enabled' => 0,
                ]);
                $aptId = cfCreate('appointments', [
                    'title' => "CF ohne {$s}", 'type_id' => $typeId,
                    'date'  => date('Y-m-d', strtotime('+14 days')), 'start_time' => '19:30', 'end_time' => '21:00',
                ]);
                // Die API nimmt fuer diese Terminart keine Rueckmeldung an; eine alte
                // Rueckmeldung (Art spaeter umgestellt) kann aber in der Tabelle stehen.
                cfPdo()->prepare('INSERT INTO ' . cfPrefix() . "appointment_responses
                        (appointment_id, member_id, status, status_changed_at, updated_at)
                        VALUES (?, ?, 'no', NOW(), NOW())")
                       ->execute([$aptId, apiMemberId('user')]);

                $neu = cfFeed('user', 'POST', []);
                assertStatus(201, $neu);
                assertSame(true, $neu['body']['hide_declined'], 'Standard hide_declined erwartet');
                $ics = str_replace("\r\n ", '', cfFetch($neu['body']['url'])['body']);
                assertTrue(cfHasEvent($ics, $aptId), 'Termin ohne Rueckmeldungen trotz hide_declined ausgeblendet');
                assertTrue(strpos($ics, "SUMMARY:CF ohne {$s}") !== false, 'SUMMARY ohne Praefix erwartet');
                assertTrue(strpos($ics, "SUMMARY:✗ CF ohne {$s}") === false, 'Praefix ✗ bei Terminart ohne Rueckmeldungen');
            } finally {
                cfFeed('user', 'DELETE');
                if ($aptId !== null) {
                    cfPdo()->prepare('DELETE FROM ' . cfPrefix() . 'appointment_responses WHERE appointment_id = ?')->execute([$aptId]);
                }
                cfDelete('appointments', $aptId);
                cfDelete('appointment_types', $typeId);
            }
        });
    });
});

test('my_data enthaelt den Abo-Status, nie den Hash', function () {
    cfWithFeature(function () {
        try {
            cfFeed('user', 'DELETE');
            $res = apiRequest('GET', 'my_data', ['token' => apiToken('user'), 'query' => ['format' => 'json']]);
            assertStatus(200, $res);
            assertSame(['active' => false], $res['body']['calendar_feed'] ?? null);

            assertStatus(201, cfFeed('user', 'POST', []));
            $res = apiRequest('GET', 'my_data', ['token' => apiToken('user'), 'query' => ['format' => 'json']]);
            $feed = $res['body']['calendar_feed'] ?? [];
            assertSame(true, $feed['active'] ?? null);
            assertTrue(!empty($feed['created_at']), 'created_at fehlt');
            assertTrue(array_key_exists('last_fetched_at', $feed));
            assertSame(true, $feed['hide_declined'] ?? null);
            assertTrue(strpos($res['raw'], 'token_hash') === false, 'my_data enthaelt den Hash');

            $csv = apiRequest('GET', 'my_data', ['token' => apiToken('user'), 'query' => ['format' => 'csv']]);
            assertStatus(200, $csv);
            assertTrue(strpos($csv['raw'], 'Kalender-Abo') !== false, 'CSV nennt das Kalender-Abo nicht');
        } finally {
            cfFeed('user', 'DELETE');
        }
    });
});
