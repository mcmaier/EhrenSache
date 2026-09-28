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

test('station: ein Token-Aufruf legt gar keine Session an (OI-25)', function () {
    // Bis 1.13.0 entstand hier eine echte Session; dieser Test hielt fest, dass
    // deren Cookie allein keine Tuer oeffnet. Seit OI-25 gibt es die Session
    // nicht mehr -- die Token-Daten stehen nur im Arbeitsspeicher der Anfrage.
    // Das ist die staerkere Zusicherung: kein Cookie, und damit auch kein
    // Rauswurf einer parallel angemeldeten Dashboard-Sitzung.
    $res = stationGet('status');
    assertStatus(200, $res);

    assertSame(null, $res['set_cookie'],
        'Token-Aufruf setzt wieder ein Session-Cookie: ' . (string) $res['set_cookie']);

    // Die Gegenprobe bleibt: ohne Token kein Zugang.
    assertStatus(401, apiRequest('GET', 'station', ['query' => ['action' => 'status']]),
        'station war ohne Token erreichbar');
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
    assertTrue(!array_key_exists('totp_secret', $res['body']),
        'auth_device darf das Secret nicht ausliefern');
    assertSame(false, $res['body']['has_totp_secret'], 'Secret muss beim Typwechsel verworfen werden');

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

    try {
        $get = apiRequest('GET', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => $deviceId],
        ]);
        assertStatus(200, $get);
        assertSame(0, (int) $get['body']['is_active'], 'is_active=false muss uebernommen werden');
    } finally {
        // Aufraeumen — das ist das einzige Geraet, das dieser Test anfasst.
        $del = apiRequest('DELETE', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => $deviceId],
        ]);
        assertStatus(200, $del, 'Test-Geraet konnte nicht geloescht werden');
    }
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

    try {
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
        assertTrue(!array_key_exists('pin_hash', $mine[0]), 'pin_hash darf auch dem admin nicht ausgeliefert werden');
        assertTrue(!array_key_exists('pin_updated_at', $mine[0]), 'pin_updated_at darf auch dem admin nicht ausgeliefert werden');
    } finally {
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
    }
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
    try {
        assertStatus(409, stationSetPin('2580'), 'PUT members mit pin haette bei abgeschalteter Anmeldung 409 liefern muessen');
    } finally {
        // Fuer nachfolgende Tests (und die Entwicklungsinstanz) wieder einschalten.
        stationSetSetting('station_pin_enabled', '1');
    }
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

/**
 * T4: haelt fest, ob stationAppointment() diesen Lauf tatsaechlich etwas
 * angelegt hat — nach demselben Muster wie stationSessionId(). Der
 * Aufraeum-Test darf stationAppointment() nicht blind aufrufen: wuerde
 * dieser Test als allererster in der Datei laufen (z. B. gezielt einzeln
 * gestartet), legte er Termin und Terminart erst dort an, nur um sie
 * sofort wieder zu loeschen — funktional harmlos, aber irrefuehrend als
 * "Aufraeumen".
 */
function stationAppointmentBuilt(?bool $set = null): bool
{
    static $built = false;
    if ($set !== null) {
        $built = $set;
    }
    return $built;
}

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

    stationAppointmentBuilt(true);

    return $apt = ['appointment_id' => (int) $res['body']['id'], 'type_id' => $typeId];
}

/** Merkt sich den beim Kiosk-Checkin angelegten/aktualisierten Record fuer I-2. */
function stationRecordId(?int $set = null): ?int
{
    static $id = null;
    if ($set !== null) {
        $id = $set;
    }
    return $id;
}

test('station: identify mit falscher PIN → 401, einheitliche Meldung', function () {
    enableStationPin();
    $res = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '9999']);
    assertStatus(401, $res);
    assertSame('Invalid member number or PIN', $res['body']['message']);

    // Die unbekannte Nummer muss je Lauf eine andere sein. Die Sperre zaehlt
    // Fehlversuche je Mitgliedsnummer (5 in 15 Minuten) und kennt auch
    // unbekannte Nummern -- absichtlich, damit sich Nummern nicht durchprobieren
    // lassen. Eine feste Zeichenkette sammelt daher ueber Laeufe hinweg an: Ab
    // dem sechsten Lauf binnen 15 Minuten antwortet der Server 423 statt 401,
    // und der Test meldet rot, obwohl nichts kaputt ist.
    $res = stationPost('identify', ['member_number' => 'gibt-es-nicht-' . uniqid(), 'pin' => '2580']);
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
    assertSame(null, $res['body']['checkin_candidate']['record_status'], 'noch kein Record vorhanden');
});

// K4: member_number/pin duerfen auch als skalarer JSON-Nicht-String kommen.
// Die Mitgliedsnummer beginnt mit "ST" (nie rein numerisch) — getestet wird
// daher die PIN als JSON-Zahl.
test('station: identify akzeptiert die PIN als JSON-Zahl statt als String', function () {
    $res = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => 2580]);
    assertStatus(200, $res);
    assertSame('Kiosk', $res['body']['member']['name']);
});

test('station: checkin schreibt einen Record mit Quelle station_pin', function () {
    $res = stationPost('checkin', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertStatus(201, $res);
    assertSame('station_pin', $res['body']['checkin_source']);
    assertSame(kioskDevice()['device_name'], $res['body']['source_device']);
    assertSame(kioskDevice()['device_name'], $res['body']['location_name']);
    assertSame(stationAppointment()['appointment_id'], (int) $res['body']['appointment_id']);
    stationRecordId((int) $res['body']['record_id']);

    $again = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertSame(true, $again['body']['checkin_candidate']['already_checked_in']);
    assertSame('present', $again['body']['checkin_candidate']['record_status']);
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

// W2/E12: Eine unbekannte Nummer hat keine member_id und damit keinen
// Mitglieds-Zaehler — sie muss trotzdem nach fuenf Versuchen sperren, sonst
// waere das Sperrverhalten selbst ein Existenz-Orakel (bekannte Nummer
// sperrt, unbekannte nicht). Frische, zufaellige Nummer, damit dieser Test
// unabhaengig von anderen Laeufen ist.
test('station: unbekannte Nummer sperrt nach fuenf Fehlversuchen wie eine bekannte', function () {
    $number = 'NX' . uniqid();
    for ($i = 0; $i < 5; $i++) {
        assertStatus(401, stationPost('identify', ['member_number' => $number, 'pin' => '0001']));
    }
    $res = stationPost('identify', ['member_number' => $number, 'pin' => '0001']);
    assertStatus(423, $res, 'sechster Versuch auf dieselbe unbekannte Nummer muss gesperrt sein');
    assertSame('Too many attempts', $res['body']['message']);
});

test('station: identify bei abgeschalteter PIN-Anmeldung → 409', function () {
    stationSetSetting('station_pin_enabled', '0');
    try {
        $res = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
        assertStatus(409, $res);
    } finally {
        stationSetSetting('station_pin_enabled', '1');
    }
});

// ---- I-2: ein Stempel schlaegt eine bestehende Entschuldigung ---------------

test('station: checkin macht aus einem entschuldigten Record einen praesenten', function () {
    $memberId      = stationMember()['member_id'];
    $appointmentId = stationAppointment()['appointment_id'];
    $recordId      = stationRecordId();

    // Bestehende arrival_time mitfuehren: records.php PUT verlangt member_id,
    // appointment_id, arrival_time und status vollstaendig im Body.
    $existing = apiRequest('GET', 'records', [
        'token' => apiToken('admin'),
        'query' => ['id' => $recordId],
    ]);
    assertStatus(200, $existing);

    assertStatus(200, apiRequest('PUT', 'records', [
        'token' => apiToken('admin'),
        'query' => ['id' => $recordId],
        'body'  => [
            'member_id'      => $memberId,
            'appointment_id' => $appointmentId,
            'arrival_time'   => $existing['body']['arrival_time'],
            'status'         => 'excused',
        ],
    ]), 'Record konnte nicht auf excused gesetzt werden');

    $identify = stationPost('identify', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertStatus(200, $identify);
    assertSame(false, $identify['body']['checkin_candidate']['already_checked_in'],
        'ein entschuldigter Termin gilt am Kiosk weiterhin als offen');
    assertSame('excused', $identify['body']['checkin_candidate']['record_status']);

    $res = stationPost('checkin', ['member_number' => stationMember()['member_number'], 'pin' => '2580']);
    assertStatus(200, $res);
    assertSame('updated', $res['body']['record_action'], 'ein Stempel muss die Entschuldigung ueberschreiben');

    $after = apiRequest('GET', 'records', ['token' => apiToken('admin'), 'query' => ['id' => $recordId]]);
    assertStatus(200, $after);
    assertSame('present', $after['body']['status']);
    assertSame('station_pin', $after['body']['checkin_source']);
});

// ---- Phase 2: Arbeitszeit am Kiosk ------------------------------------------

/**
 * T4: haelt fest, ob stationWorkFixture() diesen Lauf tatsaechlich etwas
 * angelegt hat — nach demselben Muster wie stationSessionId().
 */
function stationWorkFixtureBuilt(?bool $set = null): bool
{
    static $built = false;
    if ($set !== null) {
        $built = $set;
    }
    return $built;
}

/**
 * Gruppe + Taetigkeitsart (Nachweis 'start') fuer das Testmitglied. Am Kiosk
 * gilt der Kiosk-Name als Ortsnachweis (E8), darum darf die nachweispflichtige
 * Art ohne TOTP-Code starten.
 *
 * T2: schaltet worktime_enabled EIN und laesst es an — wie enableStationPin()
 * bleibt das fuer die Entwicklungsinstanz bestehen, es wird bewusst nirgends
 * wieder zurueckgesetzt.
 *
 * @return array{group_id: int, activity_id: int}
 */
function stationWorkFixture(): array
{
    static $fx = null;
    if ($fx !== null) {
        return $fx;
    }

    stationSetSetting('worktime_enabled', '1');

    $group = apiRequest('POST', 'member_groups', [
        'token' => apiToken('admin'),
        'body'  => ['group_name' => 'Kiosk-Testgruppe ' . uniqid()],
    ]);
    assertStatus(201, $group, 'Gruppe konnte nicht angelegt werden');
    $groupId = (int) $group['body']['id'];

    $assign = apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => stationMember()['member_id']],
        'body'  => ['group_ids' => [$groupId]],
    ]);
    assertStatus(200, $assign);

    $activity = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => 'Kiosk-Taetigkeit ' . uniqid(),
                    'verification' => 'start', 'group_ids' => [$groupId]],
    ]);
    assertStatus(201, $activity, 'Taetigkeitsart konnte nicht angelegt werden');

    stationWorkFixtureBuilt(true);

    return $fx = ['group_id' => $groupId, 'activity_id' => (int) $activity['body']['id']];
}

/** Merkt sich die am Kiosk gestartete Sitzung fuers Aufraeumen. */
function stationSessionId(?int $set = null): ?int
{
    static $id = null;
    if ($set !== null) {
        $id = $set;
    }
    return $id;
}

function stationCreds(): array
{
    return ['member_number' => stationMember()['member_number'], 'pin' => '2580'];
}

test('station: identify nennt die erlaubten Taetigkeitsarten', function () {
    $fx  = stationWorkFixture();
    $res = stationPost('identify', stationCreds());
    assertStatus(200, $res);
    assertSame(true, $res['body']['worktime_enabled']);
    $ids = array_map(static fn ($a) => (int) $a['activity_id'], $res['body']['activities']);
    assertTrue(in_array($fx['activity_id'], $ids, true), 'Test-Taetigkeitsart erwartet');
    assertSame(null, $res['body']['running_session']);
});

test('station: work_start ohne activity_id → 400', function () {
    stationWorkFixture();   // T5: darf nicht von der Reihenfolge anderer Tests abhaengen
    assertStatus(400, stationPost('work_start', stationCreds()));
});

// W3: activity_id muss eine positive Ganzzahl sein (int oder Ziffernstring).
test('station: work_start mit activity_id als Text → 400', function () {
    stationWorkFixture();
    $res = stationPost('work_start', stationCreds() + ['activity_id' => 'abc']);
    assertStatus(400, $res);
    assertSame('activity_id must be a positive integer', $res['body']['message']);
});

test('station: work_start mit activity_id als Array → 400', function () {
    stationWorkFixture();
    $res = stationPost('work_start', stationCreds() + ['activity_id' => [1]]);
    assertStatus(400, $res);
    assertSame('activity_id must be a positive integer', $res['body']['message']);
});

test('station: work_start startet mit Quelle station und Kiosk als Ort', function () {
    $fx  = stationWorkFixture();
    $res = stationPost('work_start', stationCreds() + ['activity_id' => $fx['activity_id']]);
    assertStatus(201, $res);
    $s = $res['body']['session'];
    assertSame('station', $s['source']);
    assertSame(kioskDevice()['device_name'], $s['start_location_name']);
    assertSame('confirmed', $s['status']);
    // T3: created_by muss das Kiosk-Geraet sein, nie das Mitglied selbst —
    // die member_id stammt aus der PIN-Pruefung, nicht aus dem Request.
    assertSame(kioskDevice()['user_id'], (int) $s['created_by']);
    stationSessionId((int) $s['session_id']);
});

test('station: zweiter work_start → 409', function () {
    $fx  = stationWorkFixture();
    assertStatus(409, stationPost('work_start', stationCreds() + ['activity_id' => $fx['activity_id']]));
});

test('station: work_pause und work_resume', function () {
    $res = stationPost('work_pause', stationCreds());
    assertStatus(200, $res);
    assertSame(true, $res['body']['session']['is_paused']);

    $res = stationPost('work_resume', stationCreds());
    assertStatus(200, $res);
    assertSame(false, $res['body']['session']['is_paused']);
});

test('station: work_stop trotz Notizpflicht (P1), Kiosk als Endort', function () {
    stationSetSetting('worktime_require_note', '1');
    try {
        $res = stationPost('work_stop', stationCreds());
        assertStatus(200, $res);
        $s = $res['body']['session'];
        assertTrue(!empty($s['end_time']), 'end_time gesetzt');
        assertSame(kioskDevice()['device_name'], $s['end_location_name']);
        assertSame('confirmed', $s['status'], 'mit beiden Orten bleibt die Sitzung bestaetigt');
    } finally {
        stationSetSetting('worktime_require_note', '0');
    }
});

test('station: work_stop ohne laufende Sitzung → 409', function () {
    assertStatus(409, stationPost('work_stop', stationCreds()));
});

test('station: work_* bei abgeschalteter Zeiterfassung → 404', function () {
    $fx = stationWorkFixture();
    stationSetSetting('worktime_enabled', '0');
    try {
        $res = stationPost('work_start', stationCreds() + ['activity_id' => $fx['activity_id']]);
        assertStatus(404, $res);
    } finally {
        stationSetSetting('worktime_enabled', '1');
    }
});

// ---- Auth-Geraete haben kein Secret -----------------------------------------

test('users: auth_device lehnt totp_action generate ab, clear und GET liefern kein Secret', function () {
    $name   = 'Test-Auth ' . uniqid();
    $create = apiRequest('POST', 'users', [
        'token' => apiToken('admin'),
        'body'  => [
            'action'      => 'create_device',
            'device_name' => $name,
            'device_type' => 'auth_device',
        ],
    ]);
    assertStatus(200, $create, 'Auth-Geraet konnte nicht angelegt werden');
    $deviceId = (int) $create['body']['device']['user_id'];

    try {
        assertStatus(400, apiRequest('PUT', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => $deviceId],
            'body'  => ['totp_action' => 'generate'],
        ]), 'Auth-Geraete duerfen kein Secret generieren');

        assertStatus(200, apiRequest('PUT', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => $deviceId],
            'body'  => ['totp_action' => 'clear'],
        ]));

        $get = apiRequest('GET', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => $deviceId],
        ]);
        assertStatus(200, $get);
        assertTrue(!array_key_exists('totp_secret', $get['body']), 'auth_device darf kein totp_secret ausliefern');
    } finally {
        // Eigenes Geraet — betrifft weder den Suiten-Kiosk noch andere Tests.
        assertStatus(200, apiRequest('DELETE', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => $deviceId],
        ]), 'Test-Geraet konnte nicht geloescht werden');
    }

    // Der Suiten-Kiosk bleibt unveraendert ein Kiosk mit Secret.
    $kioskCheck = kioskGet();
    assertStatus(200, $kioskCheck);
    assertSame('kiosk', $kioskCheck['body']['device_type']);
    assertSame(true, $kioskCheck['body']['has_totp_secret']);
});

// ---- OI-27: Mitgliedschaftszeitraeume am Kiosk -----------------------------
//
// Der Stempel prueft dieselbe Aktivitaet wie Statistik und Anwesenheitslogik:
// members.active UND der heutige Tag im Zeitraum aus membership_dates. Vorher
// genuegte active = 1 -- ein Mitglied mit abgelaufenem Zeitraum konnte also
// weiter stempeln, zaehlte aber in keiner Auswertung.
//
// Diese Tests bringen ihr eigenes Mitglied mit, statt stationMember() zu
// benutzen: Sie legen Mitgliedschaftszeitraeume an, und das gemeinsame
// Testmitglied traegt sie sonst in die uebrigen Tests weiter.

/**
 * Legt ein Mitglied mit PIN und optionalem Zeitraum an, fuehrt $fn damit aus
 * und raeumt danach beides wieder ab.
 *
 * @param ?array{0: string, 1: ?string} $zeitraum start_date und end_date
 */
function oi27WithMember(?array $zeitraum, callable $fn): void
{
    $nummer = 'OI27' . substr(uniqid(), -6);
    $res    = apiRequest('POST', 'members', ['token' => apiToken('admin'), 'body' => [
        'name' => 'Kiosk', 'surname' => 'Zeitraum ' . $nummer,
        'member_number' => $nummer, 'active' => 1,
    ]]);
    assertStatus(201, $res, 'Testmitglied konnte nicht angelegt werden: ' . $res['raw']);
    $memberId = (int) $res['body']['id'];

    try {
        // Die PIN nimmt erst PUT entgegen, das Anlegen kennt das Feld nicht.
        assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['pin' => '2580']]),
            'PIN konnte nicht gesetzt werden');

        if ($zeitraum !== null) {
            $zr = apiRequest('POST', 'membership_dates', ['token' => apiToken('admin'), 'body' => [
                'member_id' => $memberId, 'start_date' => $zeitraum[0], 'end_date' => $zeitraum[1],
            ]]);
            assertStatus(201, $zr, 'Zeitraum konnte nicht angelegt werden: ' . $zr['raw']);
        }

        $fn($nummer, $memberId);
    } finally {
        apiRequest('DELETE', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    }
}

test('station: Mitglied ausserhalb seines Zeitraums kann nicht stempeln (OI-27)', function () {
    enableStationPin();
    oi27WithMember([date('Y-m-d', strtotime('-2 years')), date('Y-m-d', strtotime('-1 day'))],
        function (string $nummer) {
            $res = stationPost('identify', ['member_number' => $nummer, 'pin' => '2580']);
            assertStatus(401, $res, 'Abgelaufener Zeitraum muss den Stempel verhindern');
            assertSame('Invalid member number or PIN', $res['body']['message'],
                'Die Meldung bleibt einheitlich -- sie verraet nicht, woran es lag');
        });
});

test('station: Zeitraum, der erst spaeter beginnt, gilt noch nicht (OI-27)', function () {
    enableStationPin();
    oi27WithMember([date('Y-m-d', strtotime('+7 days')), null], function (string $nummer) {
        assertStatus(401, stationPost('identify', ['member_number' => $nummer, 'pin' => '2580']),
            'Ein erst kuenftig beginnender Zeitraum darf noch nicht stempeln lassen');
    });
});

test('station: Mitglied im laufenden Zeitraum stempelt weiterhin (OI-27)', function () {
    enableStationPin();
    oi27WithMember([date('Y-m-d', strtotime('-1 day')), null], function (string $nummer) {
        $res = stationPost('identify', ['member_number' => $nummer, 'pin' => '2580']);
        assertStatus(200, $res, 'Offener Zeitraum ab gestern muss gelten: ' . $res['raw']);
        // identify liefert bewusst nur Vor- und Nachnamen, keine member_id.
        assertSame('Zeitraum ' . $nummer, $res['body']['member']['surname']);
    });
});

test('station: ohne Zeitraeume bleibt es bei members.active (OI-27)', function () {
    enableStationPin();
    // Kein membership_dates-Eintrag: Das Mitglied gilt wie in der Statistik als
    // immer aktiv -- der haeufigste Fall in einem Verein.
    oi27WithMember(null, function (string $nummer) {
        assertStatus(200, stationPost('identify', ['member_number' => $nummer, 'pin' => '2580']),
            'Ohne Zeitraeume muss der Stempel unveraendert gehen');
    });
});

// ---- OI-101: station fuer auth_device (PIN am Hardware-Terminal) -----------
//
// Das Terminal bedient beide Vertrauensmodelle: Finger und Karte ueber
// auto_checkin (das Geraet buergt), Nummer + PIN ueber station (der Server
// prueft). An station darf es nur status, identify und checkin.

/**
 * Legt einmal je Lauf ein auth_device an; der Aufraeum-Test loescht es.
 *
 * @return array{user_id: int, api_token: string, device_name: string}
 */
function authDevice(): array
{
    static $device = null;
    if ($device !== null) {
        return $device;
    }

    $name = 'Test-Terminal ' . uniqid();
    $res  = apiRequest('POST', 'users', [
        'token' => apiToken('admin'),
        'body'  => [
            'action'      => 'create_device',
            'device_name' => $name,
            'device_type' => 'auth_device',
        ],
    ]);
    assertStatus(200, $res, 'Auth-Geraet konnte nicht angelegt werden');

    authDeviceBuilt(true);

    return $device = [
        'user_id'     => (int) $res['body']['device']['user_id'],
        'api_token'   => (string) $res['body']['device']['api_token'],
        'device_name' => $name,
    ];
}

/** Haelt fest, ob authDevice() in diesem Lauf etwas angelegt hat (Muster T4). */
function authDeviceBuilt(?bool $set = null): bool
{
    static $built = false;
    if ($set !== null) {
        $built = $set;
    }
    return $built;
}

function authDeviceToken(): string
{
    return authDevice()['api_token'];
}

test('station: status mit auth_device-Token (OI-101)', function () {
    enableStationPin();
    $res = stationGet('status', authDeviceToken());
    assertStatus(200, $res);
    assertSame(authDevice()['device_name'], $res['body']['device_name']);
    assertSame(false, $res['body']['totp_enabled'], 'ein auth_device hat kein Secret');
    assertSame(true, $res['body']['pin_enabled']);
    assertSame(false, $res['body']['worktime_enabled'],
        'Arbeitszeit ist am Terminal nicht freigegeben, status darf sie nicht anbieten');
});

test('station: auth_device bekommt keinen Stations-Code (OI-101)', function () {
    $res = stationGet('totp', authDeviceToken());
    assertStatus(403, $res);
    assertSame('Action not available for this device type', $res['body']['message']);
});

test('station: auth_device darf identify, ohne Taetigkeitsarten (OI-101)', function () {
    stationWorkFixture();   // Zeiterfassung an, Testmitglied mit Taetigkeitsart
    $res = stationPost('identify', stationCreds(), authDeviceToken());
    assertStatus(200, $res);
    assertSame('Kiosk', $res['body']['member']['name']);
    assertSame(false, $res['body']['worktime_enabled']);
    assertSame([], $res['body']['activities']);
    assertSame(null, $res['body']['running_session']);
});

test('station: checkin am auth_device traegt station_pin und den Geraetenamen (OI-101)', function () {
    enableStationPin();
    $appointmentId = stationAppointment()['appointment_id'];
    oi27WithMember(null, function (string $nummer) use ($appointmentId) {
        $res = stationPost('checkin', ['member_number' => $nummer, 'pin' => '2580'], authDeviceToken());
        assertStatus(201, $res, 'Check-in am Terminal: ' . $res['raw']);
        assertSame('station_pin', $res['body']['checkin_source']);
        assertSame(authDevice()['device_name'], $res['body']['source_device']);
        assertSame(authDevice()['device_name'], $res['body']['location_name']);
        assertSame($appointmentId, (int) $res['body']['appointment_id']);
    });
});

test('station: work_* am auth_device → 403 (OI-101)', function () {
    $fx = stationWorkFixture();
    foreach (['work_start', 'work_pause', 'work_resume', 'work_stop'] as $action) {
        $res = stationPost($action, stationCreds() + ['activity_id' => $fx['activity_id']], authDeviceToken());
        // Greift die Sperre nicht, entsteht eine echte Sitzung -- dem
        // Aufraeum-Test melden, sonst bleibt sie samt Mitglied liegen.
        if ($res['status'] === 201 && isset($res['body']['session']['session_id'])) {
            stationSessionId((int) $res['body']['session']['session_id']);
        }
        assertStatus(403, $res, "{$action} muss am Terminal gesperrt sein");
        assertSame('Action not available for this device type', $res['body']['message']);
    }
});

test('station: gesperrte Action verbraucht keinen PIN-Versuch (OI-101)', function () {
    enableStationPin();
    oi27WithMember(null, function (string $nummer) {
        // Sechs Versuche mit falscher PIN -- laege die Typpruefung hinter der
        // PIN-Pruefung, waere die Nummer danach gesperrt (5 in 15 Minuten).
        for ($i = 0; $i < 6; $i++) {
            assertStatus(403, stationPost('work_start',
                ['member_number' => $nummer, 'pin' => '0001', 'activity_id' => 1], authDeviceToken()));
        }
        assertStatus(200, stationPost('identify', ['member_number' => $nummer, 'pin' => '2580'], authDeviceToken()),
            'Die gesperrten Aufrufe duerfen keinen Fehlversuch gezaehlt haben');
    });
});

test('station: auth_device behaelt auto_checkin (OI-101)', function () {
    // Unbekannte Nummer: die Kiosk-Sperre in api.php wuerde 403 liefern, der
    // Handler selbst antwortet mit 404.
    $res = apiRequest('POST', 'auto_checkin', [
        'token' => authDeviceToken(),
        'body'  => ['member_number' => 'NX' . uniqid(), 'arrival_time' => date('Y-m-d H:i:s')],
    ]);
    assertStatus(404, $res, 'auth_device muss auto_checkin weiter erreichen: ' . $res['raw']);
});

test('station: totp_location bleibt an station abgewiesen (OI-101)', function () {
    $res = apiRequest('POST', 'users', [
        'token' => apiToken('admin'),
        'body'  => [
            'action'      => 'create_device',
            'device_name' => 'Test-Standort ' . uniqid(),
            'device_type' => 'totp_location',
            'totp_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        ],
    ]);
    assertStatus(200, $res, 'totp_location konnte nicht angelegt werden');
    $deviceId = (int) $res['body']['device']['user_id'];

    try {
        $token = (string) $res['body']['device']['api_token'];
        assertStatus(403, stationGet('status', $token));
        assertStatus(403, stationPost('identify', stationCreds(), $token));
    } finally {
        assertStatus(200, apiRequest('DELETE', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => $deviceId],
        ]), 'Test-Standort konnte nicht geloescht werden');
    }
});

// ---- OI-102/OI-103: auto_checkin vom Terminal -------------------------------
//
// Finger und Karte laufen ueber auto_checkin: das Geraet buergt fuer die
// Identitaet. Der Record soll den Geraetenamen als Ort tragen (OI-102), und
// ein nicht aktives Mitglied darf nicht mehr einchecken (OI-103) -- mit einer
// Antwort, an der das Terminal die Zuordnung als verwaist erkennt.

/** POST auto_checkin mit dem Terminal-Token. */
function terminalCheckin(string $nummer, ?string $arrival = null): array
{
    return apiRequest('POST', 'auto_checkin', [
        'token' => authDeviceToken(),
        'body'  => ['member_number' => $nummer, 'arrival_time' => $arrival ?? date('Y-m-d H:i:s'),
                    'source_device' => 'device_auth'],
    ]);
}

test('auto_checkin: Terminal schreibt seinen Geraetenamen als Ort (OI-102)', function () {
    stationAppointment();
    oi27WithMember(null, function (string $nummer) {
        $res = terminalCheckin($nummer);
        assertStatus(201, $res, 'Check-in per Finger/Karte: ' . $res['raw']);
        assertSame('device_auth', $res['body']['checkin_source']);
        assertSame(authDevice()['device_name'], $res['body']['location_name']);
    });
});

test('auto_checkin: Mitglied ausserhalb seines Zeitraums → 404 member_inactive (OI-103)', function () {
    stationAppointment();
    oi27WithMember([date('Y-m-d', strtotime('-2 years')), date('Y-m-d', strtotime('-1 day'))],
        function (string $nummer) {
            $res = terminalCheckin($nummer);
            assertStatus(404, $res, 'Abgelaufener Zeitraum muss abgewiesen werden: ' . $res['raw']);
            assertSame('member_inactive', $res['body']['reason'] ?? null);
        });
});

test('auto_checkin: kuenftiger Zeitraum gilt noch nicht (OI-103)', function () {
    stationAppointment();
    oi27WithMember([date('Y-m-d', strtotime('+7 days')), null], function (string $nummer) {
        $res = terminalCheckin($nummer);
        assertStatus(404, $res, $res['raw']);
        assertSame('member_inactive', $res['body']['reason'] ?? null);
    });
});

test('auto_checkin: members.active = 0 → 404 member_inactive (OI-103)', function () {
    stationAppointment();
    oi27WithMember(null, function (string $nummer, int $memberId) {
        assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['active' => 0]]), 'Mitglied liess sich nicht deaktivieren');
        $res = terminalCheckin($nummer);
        assertStatus(404, $res, $res['raw']);
        assertSame('member_inactive', $res['body']['reason'] ?? null);
    });
});

test('auto_checkin: unbekannte Nummer bleibt "Member not found" ohne reason (OI-103)', function () {
    $res = terminalCheckin('NX' . uniqid());
    assertStatus(404, $res);
    assertSame('Member not found', $res['body']['message']);
    assertTrue(!array_key_exists('reason', $res['body']), 'unbekannt und inaktiv muessen unterscheidbar bleiben');
});

test('auto_checkin: Stichtag ist das Datum der arrival_time, nicht heute (OI-103)', function () {
    // Zeitraum endete gestern, der nachgereichte Check-in ist von gestern: Das
    // Mitglied war an dem Tag aktiv. Ohne passenden Termin und ohne
    // Auto-Anlage antwortet der Server dann 409 -- die Aktivpruefung ist also
    // bestanden, ohne dass der Test einen Termin anlegen muss.
    $before = apiRequest('GET', 'settings', ['token' => apiToken('admin')]);
    $prev   = (string) ($before['body']['settings']['checkin_auto_create_appointment'] ?? '1');
    stationSetSetting('checkin_auto_create_appointment', '0');
    try {
        oi27WithMember([date('Y-m-d', strtotime('-2 years')), date('Y-m-d', strtotime('-1 day'))],
            function (string $nummer) {
                $res = terminalCheckin($nummer, date('Y-m-d', strtotime('-1 day')) . ' 03:17:00');
                assertStatus(409, $res, 'Am Tag der Ankunft war das Mitglied aktiv: ' . $res['raw']);
                assertSame('no_matching_appointment', $res['body']['reason'] ?? null);
            });
    } finally {
        stationSetSetting('checkin_auto_create_appointment', $prev);
    }
});

// ---- Terminal-Spec 13.1 Punkt 3: Mitgliederliste fuer Geraete ---------------
//
// Das Terminal gleicht nach jedem Abruf seine Zuordnungen gegen die Liste ab
// und markiert fehlende Nummern als verwaist. Die Liste muss deshalb dieselbe
// Regel wie Kiosk und Statistik anwenden (OI-27), nicht nur members.active.

/** Mitgliedsnummern aus GET members mit dem Terminal-Token. */
function terminalMemberNumbers(): array
{
    $res = apiRequest('GET', 'members', ['token' => authDeviceToken()]);
    assertStatus(200, $res);
    return array_column($res['body'], 'member_number');
}

test('members: Geraet sieht Mitglied im laufenden Zeitraum', function () {
    oi27WithMember([date('Y-m-d', strtotime('-1 day')), null], function (string $nummer) {
        assertTrue(in_array($nummer, terminalMemberNumbers(), true), 'aktives Mitglied fehlt in der Geraeteliste');
    });
});

test('members: Geraet sieht kein Mitglied mit abgelaufenem Zeitraum', function () {
    oi27WithMember([date('Y-m-d', strtotime('-2 years')), date('Y-m-d', strtotime('-1 day'))],
        function (string $nummer) {
            assertTrue(!in_array($nummer, terminalMemberNumbers(), true),
                'ausgetretenes Mitglied darf nicht in der Geraeteliste stehen');
        });
});

test('members: Geraet sieht kein Mitglied mit active = 0', function () {
    oi27WithMember(null, function (string $nummer, int $memberId) {
        assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['active' => 0]]));
        assertTrue(!in_array($nummer, terminalMemberNumbers(), true),
            'inaktives Mitglied darf nicht in der Geraeteliste stehen');
    });
});

// ---- Aufraeumen: bleibt der LETZTE Test der Datei ---------------------------
// Spaetere Tasks fuegen ihre Tests VOR diesem Block ein.

test('station: Aufraeumen — Kiosk loeschen', function () {
    if (stationSessionId() !== null) {
        assertStatus(200, apiRequest('DELETE', 'work_sessions', ['token' => apiToken('admin'),
                                                                 'query' => ['id' => stationSessionId()]]));
    }

    // T4: nur aufraeumen, was dieser Lauf tatsaechlich angelegt hat — ein
    // blinder Aufruf von stationWorkFixture()/stationAppointment() wuerde
    // an dieser Stelle sonst selbst noch etwas anlegen, nur um es sofort
    // wieder zu loeschen.
    if (stationWorkFixtureBuilt()) {
        $fx = stationWorkFixture();
        assertStatus(200, apiRequest('DELETE', 'activity_types', ['token' => apiToken('admin'),
                                                                  'query' => ['id' => $fx['activity_id']]]));
        assertStatus(200, apiRequest('DELETE', 'member_groups', ['token' => apiToken('admin'),
                                                                 'query' => ['id' => $fx['group_id']]]));
    }

    if (stationAppointmentBuilt()) {
        $apt = stationAppointment();
        assertStatus(200, apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'),
                                                                'query' => ['id' => $apt['appointment_id']]]));
        assertStatus(200, apiRequest('DELETE', 'appointment_types', ['token' => apiToken('admin'),
                                                                     'query' => ['id' => $apt['type_id']]]));
    }

    $m = apiRequest('DELETE', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => stationMember()['member_id']],
    ]);
    assertStatus(200, $m, 'Testmitglied konnte nicht geloescht werden');

    if (authDeviceBuilt()) {
        assertStatus(200, apiRequest('DELETE', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => authDevice()['user_id']],
        ]), 'Test-Terminal konnte nicht geloescht werden');
    }

    $res = apiRequest('DELETE', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => kioskDevice()['user_id']],
    ]);
    assertStatus(200, $res, 'Test-Kiosk konnte nicht geloescht werden');
});
