<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * Legt einmal je Lauf einen Kiosk an. Das Geraet wird im letzten Test der
 * Suite wieder geloescht — es ist das einzige Geraet, das die Suite anfasst.
 *
 * @return array{user_id: int, api_token: string, device_name: string}
 */
function kioskDevice(): array
{
    static $device = null;
    if ($device !== null) {
        return $device;
    }

    $name = 'Test-Kiosk ' . uniqid();
    $res  = apiRequest('POST', 'users', [
        'token' => apiToken('admin'),
        'body'  => [
            'action'       => 'create_device',
            'device_name'  => $name,
            'device_type'  => 'kiosk',
            'totp_enabled' => true,
        ],
    ]);
    assertStatus(200, $res, 'Kiosk konnte nicht angelegt werden');

    return $device = [
        'user_id'     => (int) $res['body']['device']['user_id'],
        'api_token'   => (string) $res['body']['device']['api_token'],
        'device_name' => $name,
    ];
}

function kioskToken(): string
{
    return kioskDevice()['api_token'];
}

/** GET station&action=<action> mit Kiosk-Token (oder einem anderen). */
function stationGet(string $action, ?string $token = null): array
{
    return apiRequest('GET', 'station', [
        'token' => $token ?? kioskToken(),
        'query' => ['action' => $action],
    ]);
}

/** POST station&action=<action> mit Kiosk-Token (oder einem anderen). */
function stationPost(string $action, array $body, ?string $token = null): array
{
    return apiRequest('POST', 'station', [
        'token' => $token ?? kioskToken(),
        'query' => ['action' => $action],
        'body'  => $body,
    ]);
}

// ---- Phase 1: status, totp, Sperre -----------------------------------------

test('station: Kiosk-Geraet liefert kein Secret, nur has_totp_secret', function () {
    $res = apiRequest('GET', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => kioskDevice()['user_id']],
    ]);
    assertStatus(200, $res);
    assertSame('kiosk', $res['body']['device_type']);
    assertTrue(!array_key_exists('totp_secret', $res['body']), 'totp_secret darf nicht ausgeliefert werden');
    assertSame(true, $res['body']['has_totp_secret']);
});

test('station: status mit Kiosk-Token', function () {
    $res = stationGet('status');
    assertStatus(200, $res);
    assertSame(kioskDevice()['device_name'], $res['body']['device_name']);
    assertSame(true, $res['body']['totp_enabled']);
    assertTrue(is_bool($res['body']['pin_enabled']), 'pin_enabled muss bool sein');
    assertTrue(is_int($res['body']['pin_min_length']), 'pin_min_length muss int sein');
    assertTrue(is_bool($res['body']['worktime_enabled']), 'worktime_enabled muss bool sein');
    assertTrue(is_int($res['body']['server_unix']), 'server_unix muss int sein');
});

test('station: totp liefert Code, Folgecode und Fensterende', function () {
    $res = stationGet('totp');
    assertStatus(200, $res);
    assertTrue(preg_match('/^\d{6}$/', (string) $res['body']['code']) === 1, 'code: sechs Ziffern');
    assertTrue(preg_match('/^\d{6}$/', (string) $res['body']['next_code']) === 1, 'next_code: sechs Ziffern');
    assertTrue($res['body']['valid_until'] > $res['body']['now'], 'valid_until nach now');
    assertSame(30, $res['body']['period']);
});

test('station: Nutzer-Token wird abgewiesen', function () {
    assertStatus(403, stationGet('status', apiToken('user')));
});

test('station: Admin-Token wird abgewiesen', function () {
    assertStatus(403, stationGet('status', apiToken('admin')));
});

test('station: Kiosk-Token darf members nicht lesen', function () {
    assertStatus(403, apiRequest('GET', 'members', ['token' => kioskToken()]));
});

test('station: Kiosk-Token darf auto_checkin nicht nutzen', function () {
    $res = apiRequest('POST', 'auto_checkin', [
        'token' => kioskToken(),
        'body'  => ['member_number' => 'X', 'arrival_time' => date('Y-m-d H:i:s')],
    ]);
    assertStatus(403, $res);
});

test('station: unbekannte action', function () {
    assertStatus(400, stationGet('gibt-es-nicht'));
});

// ---- Sicherheit: Session-Cookie und Secret-Verwaltung ----------------------

/** PUT users&id=<kiosk> als Admin. */
function kioskPut(array $body): array
{
    return apiRequest('PUT', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => kioskDevice()['user_id']],
        'body'  => $body,
    ]);
}

/** GET users&id=<kiosk> als Admin. */
function kioskGet(): array
{
    return apiRequest('GET', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => kioskDevice()['user_id']],
    ]);
}

test('station: Session-Cookie eines Token-Aufrufs oeffnet keine Tuer', function () {
    $res = stationGet('status');
    assertStatus(200, $res);

    $setCookie = $res['set_cookie'];
    assertTrue($setCookie !== null, 'Token-Aufruf liefert kein Set-Cookie');
    assertTrue(strpos((string) $setCookie, 'PHPSESSID') !== false,
        'Set-Cookie ohne PHPSESSID: ' . (string) $setCookie);

    $cookie = explode(';', (string) $setCookie)[0];

    assertStatus(401, apiRequest('GET', 'members', ['cookie' => $cookie]),
        'members war allein mit dem Session-Cookie erreichbar');
    assertStatus(401, apiRequest('GET', 'station', [
        'cookie' => $cookie,
        'query'  => ['action' => 'status'],
    ]), 'station war allein mit dem Session-Cookie erreichbar');
});

test('station: totp_action clear und generate steuern das Secret', function () {
    assertStatus(200, kioskPut(['totp_action' => 'clear']));
    assertStatus(404, stationGet('totp'), 'Kiosk ohne Secret darf keinen Code liefern');

    assertStatus(200, kioskPut(['totp_action' => 'generate']));
    assertStatus(200, stationGet('totp'), 'Kiosk mit frischem Secret liefert wieder einen Code');
});

// Beide Requests fuehren ein gueltiges Feld mit (denselben device_name, also
// ohne Wirkung): Sonst waere die 400 nur das "Keine Daten zum Aktualisieren"
// eines still verworfenen Feldes.
test('station: Kiosk nimmt kein Secret aus dem Request', function () {
    assertStatus(400, kioskPut([
        'device_name'  => kioskDevice()['device_name'],
        'totp_secret'  => 'ABCDEFGH',
    ]));
});

test('station: unbekannte totp_action wird abgewiesen', function () {
    assertStatus(400, kioskPut([
        'device_name' => kioskDevice()['device_name'],
        'totp_action' => 'x',
    ]));
});

test('station: Typwechsel verwirft das gespeicherte Secret', function () {
    // totp_location braucht immer ein Secret (siehe eigener Test weiter
    // unten) — der Typwechsel-ohne-Secret wird hier daher gegen auth_device
    // geprueft, das keine solche Anforderung hat.
    assertStatus(200, kioskPut(['device_type' => 'auth_device']));

    $res = kioskGet();
    assertStatus(200, $res);
    assertSame('auth_device', $res['body']['device_type']);
    assertTrue(array_key_exists('totp_secret', $res['body']),
        'auth_device liefert das Feld totp_secret');
    assertSame(null, $res['body']['totp_secret'], 'Secret muss beim Typwechsel verworfen werden');

    // Zurueck zum Kiosk MIT Secret — so, wie spaetere Tests das Geraet erwarten.
    assertStatus(200, kioskPut(['device_type' => 'kiosk', 'totp_action' => 'generate']));

    $res = kioskGet();
    assertStatus(200, $res);
    assertSame('kiosk', $res['body']['device_type']);
    assertTrue(!array_key_exists('totp_secret', $res['body']),
        'Kiosk darf das Secret nicht ausliefern');
    assertSame(true, $res['body']['has_totp_secret']);
});

test('station: totp_location ohne Secret wird abgelehnt', function () {
    // Typwechsel zu totp_location ohne Secret und ohne generate.
    assertStatus(400, kioskPut(['device_type' => 'totp_location']));

    // Ungueltiges Base32-Format.
    assertStatus(400, kioskPut([
        'device_type'  => 'totp_location',
        'totp_secret'  => 'not-base32!',
    ]));

    // Gueltiges Secret (lowercase, wird normalisiert) setzen.
    assertStatus(200, kioskPut([
        'device_type' => 'totp_location',
        'totp_secret' => 'gezdgnbvgy3tqojqgezdgnbvgy3tqojq',
    ]));

    $res = kioskGet();
    assertStatus(200, $res);
    assertSame('totp_location', $res['body']['device_type']);
    assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $res['body']['totp_secret'],
        'Secret muss normalisiert (Grossbuchstaben) gespeichert werden');

    // clear auf einer totp_location ist nicht erlaubt — sie braucht ein Secret.
    assertStatus(400, kioskPut(['totp_action' => 'clear']));

    // Zurueck zum Kiosk MIT frischem Secret — so, wie spaetere Tests das
    // Geraet erwarten.
    assertStatus(200, kioskPut(['device_type' => 'kiosk', 'totp_action' => 'generate']));

    $res = kioskGet();
    assertStatus(200, $res);
    assertSame('kiosk', $res['body']['device_type']);
    assertTrue(!array_key_exists('totp_secret', $res['body']),
        'Kiosk darf das Secret nicht ausliefern');
    assertSame(true, $res['body']['has_totp_secret']);
});

test('station: create_device mit ungueltigem Secret legt nichts an', function () {
    $name = 'Test-Station ' . uniqid();
    $res  = apiRequest('POST', 'users', [
        'token' => apiToken('admin'),
        'body'  => [
            'action'      => 'create_device',
            'device_name' => $name,
            'device_type' => 'totp_location',
            'totp_secret' => 'abc',
        ],
    ]);
    assertStatus(400, $res, 'Ungueltiges Base32-Secret haette abgelehnt werden muessen');

    $list = apiRequest('GET', 'users', [
        'token' => apiToken('admin'),
        'query' => ['user_type' => 'device'],
    ]);
    assertStatus(200, $list);
    $names = array_column($list['body'], 'device_name');
    assertTrue(!in_array($name, $names, true),
        'Geraet mit ungueltigem Secret haette nicht angelegt werden duerfen');
});

// ---- Aufraeumen: bleibt der LETZTE Test der Datei ---------------------------
// Spaetere Tasks fuegen ihre Tests VOR diesem Block ein.

test('station: Aufraeumen — Kiosk loeschen', function () {
    $res = apiRequest('DELETE', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => kioskDevice()['user_id']],
    ]);
    assertStatus(200, $res, 'Test-Kiosk konnte nicht geloescht werden');
});
