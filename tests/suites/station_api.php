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

    // Eine totp_location, die nur Name/is_active aendert (kein totp_action,
    // kein totp_secret), muss das gespeicherte Secret unangetastet lassen.
    assertStatus(200, kioskPut(['device_name' => kioskDevice()['device_name']]));

    $res = kioskGet();
    assertStatus(200, $res);
    assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $res['body']['totp_secret'],
        'Secret darf ohne totp_action/totp_secret nicht veraendert werden');

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

test('station: create_device mit is_active=false legt inaktives Geraet an', function () {
    $name = 'Test-Inaktiv ' . uniqid();
    $res  = apiRequest('POST', 'users', [
        'token' => apiToken('admin'),
        'body'  => [
            'action'      => 'create_device',
            'device_name' => $name,
            'device_type' => 'auth_device',
            'is_active'   => false,
        ],
    ]);
    assertStatus(200, $res, 'Inaktives Geraet haette angelegt werden muessen');
    $deviceId = (int) $res['body']['device']['user_id'];

    $get = apiRequest('GET', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => $deviceId],
    ]);
    assertStatus(200, $get);
    assertSame(0, (int) $get['body']['is_active'], 'is_active=false muss uebernommen werden');

    // Aufraeumen — das ist das einzige Geraet, das dieser Test anfasst.
    $del = apiRequest('DELETE', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => $deviceId],
    ]);
    assertStatus(200, $del, 'Test-Geraet konnte nicht geloescht werden');
});

test('station: totp_secret als Array wird sauber mit 400 abgelehnt, kein TypeError', function () {
    // Der Kiosk ist an dieser Stelle wieder ein kiosk (siehe Tests oben) —
    // fuer den wird ein mitgeschicktes Secret ohnehin abgelehnt, aber das
    // muss weiterhin ein sauberes 400 mit JSON-Body sein, kein PHP-Fatal
    // durch trim() auf einem Array.
    $res = kioskPut(['totp_secret' => ['a']]);
    assertStatus(400, $res, 'Array-Secret haette mit 400 abgelehnt werden muessen');
    assertTrue(is_array($res['body']), 'Antwort muss ein gueltiger JSON-Body sein');

    $name = 'Test-Typfehler ' . uniqid();
    $res  = apiRequest('POST', 'users', [
        'token' => apiToken('admin'),
        'body'  => [
            'action'      => 'create_device',
            'device_name' => $name,
            'device_type' => 'totp_location',
            'totp_secret' => ['a'],
        ],
    ]);
    assertStatus(400, $res, 'Array-Secret haette beim Anlegen mit 400 abgelehnt werden muessen');
    assertTrue(is_array($res['body']), 'Antwort muss ein gueltiger JSON-Body sein');

    $list = apiRequest('GET', 'users', [
        'token' => apiToken('admin'),
        'query' => ['user_type' => 'device'],
    ]);
    assertStatus(200, $list);
    $names = array_column($list['body'], 'device_name');
    assertTrue(!in_array($name, $names, true),
        'Geraet mit Array-Secret haette nicht angelegt werden duerfen');
});

// ---- Phase 2: Einstellungen -------------------------------------------------

/** Setzt eine Systemeinstellung (PUT settings erwartet {setting_key, setting_value}). */
function stationSetSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

/** Schaltet die PIN-Anmeldung fuer die Suite ein (bleibt danach an — Entwicklungsinstanz). */
function enableStationPin(): void
{
    static $done = false;
    if (!$done) {
        stationSetSetting('station_pin_enabled', '1');
        stationSetSetting('station_pin_min_length', '4');
        $done = true;
    }
}

test('settings: Client-Scope liefert station_pin_enabled und station_pin_min_length', function () {
    enableStationPin();
    $res = apiRequest('GET', 'settings', ['token' => apiToken('user'), 'query' => ['scope' => 'client']]);
    assertStatus(200, $res);
    assertSame('1', $res['body']['settings']['station_pin_enabled']);
    assertSame('4', $res['body']['settings']['station_pin_min_length']);
});

test('station: status spiegelt die eingeschaltete PIN-Anmeldung', function () {
    enableStationPin();
    $res = stationGet('status');
    assertStatus(200, $res);
    assertSame(true, $res['body']['pin_enabled']);
    assertSame(4, $res['body']['pin_min_length']);
});

// ---- Phase 2: Testmitglied --------------------------------------------------

/**
 * Legt einmal je Lauf ein Mitglied mit eindeutiger Nummer an. Wird im
 * Aufraeum-Test geloescht — samt seiner Records und Sitzungen, die alle aus
 * dieser Suite stammen.
 *
 * @return array{member_id: int, member_number: string}
 */
function stationMember(): array
{
    static $member = null;
    if ($member !== null) {
        return $member;
    }

    $number = 'ST' . substr(uniqid(), -6);
    $res    = apiRequest('POST', 'members', [
        'token' => apiToken('admin'),
        'body'  => ['name' => 'Kiosk', 'surname' => 'Testmitglied ' . $number,
                    'member_number' => $number, 'active' => 1],
    ]);
    assertStatus(201, $res, 'Testmitglied konnte nicht angelegt werden');

    return $member = ['member_id' => (int) $res['body']['id'], 'member_number' => $number];
}

/** Setzt (oder loescht mit null) die PIN des Testmitglieds als Admin. */
function stationSetPin(?string $pin): array
{
    return apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => stationMember()['member_id']],
        'body'  => ['pin' => $pin],
    ]);
}

test('members: Admin setzt eine PIN, has_pin wird true, pin_hash bleibt verborgen', function () {
    enableStationPin();
    assertStatus(200, stationSetPin('2580'));

    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'),
                                          'query' => ['id' => stationMember()['member_id']]]);
    assertStatus(200, $res);
    assertSame(true, $res['body']['has_pin']);
    assertTrue(!array_key_exists('pin_hash', $res['body']), 'pin_hash darf nicht ausgeliefert werden');
    assertTrue(!empty($res['body']['pin_updated_at']), 'pin_updated_at gesetzt');
});

test('members: Liste liefert has_pin und keinen pin_hash', function () {
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'),
                                          'query' => ['include_inactive' => 'true']]);
    assertStatus(200, $res);
    $mine = array_values(array_filter($res['body'],
        static fn ($m) => (int) $m['member_id'] === stationMember()['member_id']));
    assertTrue(count($mine) === 1, 'Testmitglied in der Liste erwartet');
    assertSame(true, $mine[0]['has_pin']);
    assertTrue(!array_key_exists('pin_hash', $mine[0]), 'pin_hash darf nicht in der Liste stehen');
});

test('members: ungueltige PIN wird mit 400 abgewiesen', function () {
    $res = stationSetPin('1234');
    assertStatus(400, $res);
    assertSame('pin', $res['body']['field']);
});

test('members: PIN loeschen setzt has_pin auf false', function () {
    assertStatus(200, stationSetPin(null));
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'),
                                          'query' => ['id' => stationMember()['member_id']]]);
    assertSame(false, $res['body']['has_pin']);
    // fuer die folgenden Tests wieder setzen
    assertStatus(200, stationSetPin('2580'));
});

test('members: user darf keine PIN setzen', function () {
    $res = apiRequest('PUT', 'members', [
        'token' => apiToken('user'),
        'query' => ['id' => stationMember()['member_id']],
        'body'  => ['pin' => '2580'],
    ]);
    assertStatus(403, $res);
});

// ---- Phase 2: change_pin ----------------------------------------------------

test('change_pin: user mit Mitglied setzt eigene PIN', function () {
    enableStationPin();
    if (apiMemberId('user') === null) {
        throw new RuntimeException('Testrolle user braucht ein verknuepftes Mitglied');
    }
    $cfg = testConfig();
    $res = apiRequest('POST', 'change_pin', [
        'token' => apiToken('user'),
        'body'  => ['current_password' => $cfg['user']['password'], 'new_pin' => '2580'],
    ]);
    assertStatus(200, $res);
});

test('change_pin: falsches Passwort → 403', function () {
    $res = apiRequest('POST', 'change_pin', [
        'token' => apiToken('user'),
        'body'  => ['current_password' => 'falsch-' . uniqid(), 'new_pin' => '2580'],
    ]);
    assertStatus(403, $res);
});

test('change_pin: ungueltige PIN → 400', function () {
    $cfg = testConfig();
    $res = apiRequest('POST', 'change_pin', [
        'token' => apiToken('user'),
        'body'  => ['current_password' => $cfg['user']['password'], 'new_pin' => '1111'],
    ]);
    assertStatus(400, $res);
});

test('change_pin: Kiosk-Token → 403', function () {
    $res = apiRequest('POST', 'change_pin', [
        'token' => kioskToken(),
        'body'  => ['current_password' => 'x', 'new_pin' => '2580'],
    ]);
    assertStatus(403, $res);
});

// ---- Phase 2: member_groups darf pin_hash nicht ausliefern -----------------

test('member_groups: kein pin_hash ueber die Gruppenansicht', function () {
    // Testmitglied hat an dieser Stelle eine PIN gesetzt (siehe oben).
    $memberId = stationMember()['member_id'];

    $create = apiRequest('POST', 'member_groups', [
        'token' => apiToken('admin'),
        'body'  => ['group_name' => 'Kiosk-Leak ' . uniqid()],
    ]);
    assertStatus(201, $create, 'Testgruppe konnte nicht angelegt werden');
    $groupId = (int) $create['body']['id'];

    assertStatus(200, apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
        'body'  => ['group_ids' => [$groupId]],
    ]), 'Testmitglied konnte der Testgruppe nicht zugeordnet werden');

    $asUser = apiRequest('GET', 'member_groups', [
        'token' => apiToken('user'),
        'query' => ['id' => $groupId],
    ]);
    assertStatus(200, $asUser);
    foreach ($asUser['body']['members'] as $row) {
        assertTrue(!array_key_exists('pin_hash', $row), 'pin_hash darf einem user nicht ausgeliefert werden');
        assertTrue(!array_key_exists('pin_updated_at', $row), 'pin_updated_at darf einem user nicht ausgeliefert werden');
    }

    $asAdmin = apiRequest('GET', 'member_groups', [
        'token' => apiToken('admin'),
        'query' => ['id' => $groupId],
    ]);
    assertStatus(200, $asAdmin);
    $mine = array_values(array_filter($asAdmin['body']['members'],
        static fn ($m) => (int) $m['member_id'] === $memberId));
    assertTrue(count($mine) === 1, 'Testmitglied in der Gruppenansicht erwartet');
    assertSame(true, $mine[0]['has_pin']);
    assertTrue(!array_key_exists('pin_hash', $mine[0]), 'pin_hash darf auch dem admin nicht ausgeliefert werden');

    // Aufraeumen: Zuordnung und Testgruppe wieder entfernen.
    assertStatus(200, apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
        'body'  => ['group_ids' => []],
    ]));
    assertStatus(200, apiRequest('DELETE', 'member_groups', [
        'token' => apiToken('admin'),
        'query' => ['id' => $groupId],
    ]));
});

// ---- Phase 2: members PUT mit ungueltigem Body ------------------------------

test('members: PUT mit leerem Body -> 400 statt Fatal', function () {
    $memberId = stationMember()['member_id'];

    $empty = apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
    ]);
    assertStatus(400, $empty, 'Leerer Body haette 400 liefern muessen, nicht Fatal Error');
    assertTrue(is_array($empty['body']), 'Antwort muss ein gueltiger JSON-Body sein');

    $arrayBody = apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
        'body'  => [],
    ]);
    assertStatus(400, $arrayBody, 'Body als JSON-Array haette 400 liefern muessen, nicht Fatal Error');
    assertTrue(is_array($arrayBody['body']), 'Antwort muss ein gueltiger JSON-Body sein');
});

// ---- Phase 2: PIN bei abgeschalteter Anmeldung, Selbstauskunft, Geraetefilter

test('members: PIN bei abgeschalteter Anmeldung -> 409', function () {
    stationSetSetting('station_pin_enabled', '0');
    assertStatus(409, stationSetPin('2580'), 'PUT members mit pin haette bei abgeschalteter Anmeldung 409 liefern muessen');

    // Fuer nachfolgende Tests (und die Entwicklungsinstanz) wieder einschalten.
    stationSetSetting('station_pin_enabled', '1');
    assertStatus(200, stationSetPin('2580'));
});

test('my_data: Selbstauskunft nennt has_pin ohne Hash', function () {
    $res = apiRequest('GET', 'my_data', ['token' => apiToken('user')]);
    assertStatus(200, $res);
    assertTrue(is_bool($res['body']['member']['has_pin']), 'has_pin muss bool sein');
    assertTrue(!array_key_exists('pin_hash', $res['body']['member']), 'pin_hash darf in der Selbstauskunft nicht auftauchen');

    $csv = apiRequest('GET', 'my_data', ['token' => apiToken('user'), 'query' => ['format' => 'csv']]);
    assertStatus(200, $csv);
    assertTrue(strpos((string) $csv['raw'], 'Stations-PIN gesetzt') !== false,
        'CSV-Export muss den Stations-PIN-Status enthalten');
});

test('users: device_type-Filter kennt kiosk', function () {
    $res = apiRequest('GET', 'users', [
        'token' => apiToken('admin'),
        'query' => ['user_type' => 'device', 'device_type' => 'kiosk'],
    ]);
    assertStatus(200, $res);
    assertTrue(count($res['body']) >= 1, 'Mindestens der Suiten-Kiosk wird erwartet');
    foreach ($res['body'] as $row) {
        assertSame('kiosk', $row['device_type']);
    }
});

// ---- Phase 2: identify / checkin -------------------------------------------

/** Termin JETZT mit eigener, gruppenfreier Terminart — beides wird aufgeraeumt. */
function stationAppointment(): array
{
    static $apt = null;
    if ($apt !== null) {
        return $apt;
    }

    $type = apiRequest('POST', 'appointment_types', [
        'token' => apiToken('admin'),
        'body'  => ['type_name' => 'Kiosk-Test ' . uniqid()],
    ]);
    assertStatus(201, $type, 'Terminart konnte nicht angelegt werden');
    $typeId = (int) $type['body']['id'];

    $res = apiRequest('POST', 'appointments', [
        'token' => apiToken('admin'),
        'body'  => ['title' => 'Kiosk-Testtermin', 'type_id' => $typeId,
                    'date' => date('Y-m-d'), 'start_time' => date('H:i:s')],
    ]);
    assertStatus(201, $res, 'Termin konnte nicht angelegt werden');

    return $apt = ['appointment_id' => (int) $res['body']['id'], 'type_id' => $typeId];
}

test('station: identify mit falscher PIN → 401, einheitliche Meldung', function () {
    enableStationPin();
    $res = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '9999']);
    assertStatus(401, $res);
    assertSame('Invalid member number or PIN', $res['body']['message']);

    $res = stationPost('identify', ['member_number' => 'gibt-es-nicht', 'pin' => '2580']);
    assertStatus(401, $res);
    assertSame('Invalid member number or PIN', $res['body']['message']);
});

test('station: identify ohne PIN → 400', function () {
    assertStatus(400, stationPost('identify', ['member_number' => stationMember()['member_number']]));
});

test('station: identify liefert Mitglied, Terminkandidat und Zeiterfassungsstand', function () {
    stationAppointment();
    $res = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertStatus(200, $res);
    assertSame('Kiosk', $res['body']['member']['name']);
    assertTrue(array_key_exists('running_session', $res['body']), 'running_session fehlt');
    assertTrue(is_array($res['body']['activities']), 'activities fehlt');
    assertTrue($res['body']['checkin_candidate'] !== null, 'Terminkandidat erwartet');
    assertSame(stationAppointment()['appointment_id'], (int) $res['body']['checkin_candidate']['appointment_id']);
    assertSame(false, $res['body']['checkin_candidate']['already_checked_in']);
});

test('station: checkin schreibt einen Record mit Quelle station_pin', function () {
    $res = stationPost('checkin', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertStatus(201, $res);
    assertSame('station_pin', $res['body']['checkin_source']);
    assertSame(kioskDevice()['device_name'], $res['body']['source_device']);
    assertSame(kioskDevice()['device_name'], $res['body']['location_name']);
    assertSame(stationAppointment()['appointment_id'], (int) $res['body']['appointment_id']);

    $again = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertSame(true, $again['body']['checkin_candidate']['already_checked_in']);
});

test('station: zweiter checkin ist unchanged', function () {
    $res = stationPost('checkin', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertStatus(200, $res);
    assertSame('unchanged', $res['body']['record_action']);
});

test('station: Sperre nach fuenf Fehlversuchen, Admin-PIN hebt sie auf', function () {
    for ($i = 0; $i < 5; $i++) {
        assertStatus(401, stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '0001']));
    }
    $res = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertStatus(423, $res, 'sechster Versuch muss gesperrt sein');
    assertSame('Too many attempts', $res['body']['message']);

    assertStatus(200, stationSetPin('2580'));   // P2
    assertStatus(200, stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '2580']));
});

test('station: identify bei abgeschalteter PIN-Anmeldung → 409', function () {
    stationSetSetting('station_pin_enabled', '0');
    $res = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertStatus(409, $res);
    stationSetSetting('station_pin_enabled', '1');
});

// ---- Aufraeumen: bleibt der LETZTE Test der Datei ---------------------------
// Spaetere Tasks fuegen ihre Tests VOR diesem Block ein.

test('station: Aufraeumen — Kiosk loeschen', function () {
    $apt = stationAppointment();
    assertStatus(200, apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'),
                                                            'query' => ['id' => $apt['appointment_id']]]));
    assertStatus(200, apiRequest('DELETE', 'appointment_types', ['token' => apiToken('admin'),
                                                                 'query' => ['id' => $apt['type_id']]]));

    $m = apiRequest('DELETE', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => stationMember()['member_id']],
    ]);
    assertStatus(200, $m, 'Testmitglied konnte nicht geloescht werden');

    $res = apiRequest('DELETE', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => kioskDevice()['user_id']],
    ]);
    assertStatus(200, $res, 'Test-Kiosk konnte nicht geloescht werden');
});
