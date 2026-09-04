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

// ---- Aufraeumen: bleibt der LETZTE Test der Datei ---------------------------
// Spaetere Tasks fuegen ihre Tests VOR diesem Block ein.

test('station: Aufraeumen — Kiosk loeschen', function () {
    $res = apiRequest('DELETE', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => kioskDevice()['user_id']],
    ]);
    assertStatus(200, $res, 'Test-Kiosk konnte nicht geloescht werden');
});
