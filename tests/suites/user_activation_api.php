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
 * activate_user und user_status (OI-127).
 *
 * activate_user ist die Freigabe einer Registrierung, kein Schalter. Auf ein
 * Geraetekonto angewandt setzte es account_status und versuchte eine Mail an
 * eine leere Adresse; auf ein aktives Konto verschickte es die Aktivierungsmail
 * erneut und ueberschrieb dabei member_id. Beides weist der Handler jetzt ab,
 * bevor er etwas aendert.
 *
 * Konten entstehen direkt in der Datenbank und werden im finally geloescht.
 * Der positive Fall laeuft mit abgeschalteter Aktivierungsmail
 * (mail_activation_enabled = 0), damit kein SMTP-Versuch stattfindet.
 */
require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../../private/helpers/config_reader.php';
require_once __DIR__ . '/../lib/api_doc.php';
require_once __DIR__ . '/../lib/source.php';

if (!extension_loaded('curl')) {
    return;
}

function uaPdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $db  = configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'))['db'];
        $pdo = new PDO(
            'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    return $pdo;
}

function uaTable(string $name): string
{
    return configWithDefaults(readConfigFile(__DIR__ . '/../../private/config/config.php'))['db']['prefix'] . $name;
}

/** Legt ein Konto direkt an und liefert die user_id. */
function uaCreateUser(string $role, string $status, int $isActive, int $emailVerified = 1, ?string $deviceType = null): int
{
    $stmt = uaPdo()->prepare('INSERT INTO ' . uaTable('users') . '
        (email, name, role, is_active, account_status, member_id, api_token, email_verified, device_type)
        VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)');
    $stmt->execute([
        $role === 'device' ? null : 'ua-' . uniqid() . '@example.invalid',
        'UA ' . $role,
        $role,
        $isActive,
        $status,
        bin2hex(random_bytes(24)),
        $emailVerified,
        $deviceType,
    ]);

    return (int) uaPdo()->lastInsertId();
}

/** @return array{account_status: string, is_active: int, member_id: ?int, pending_member_id: ?int} */
function uaUserRow(int $userId): array
{
    $stmt = uaPdo()->prepare('SELECT account_status, is_active, member_id, pending_member_id FROM '
        . uaTable('users') . ' WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException("Konto {$userId} nicht gefunden");
    }

    return [
        'account_status'    => (string) $row['account_status'],
        'is_active'         => (int) $row['is_active'],
        'member_id'         => $row['member_id'] === null ? null : (int) $row['member_id'],
        'pending_member_id' => $row['pending_member_id'] === null ? null : (int) $row['pending_member_id'],
    ];
}

function uaDeleteUser(?int $userId): void
{
    if ($userId !== null) {
        uaPdo()->prepare('DELETE FROM ' . uaTable('users') . ' WHERE user_id = ?')->execute([$userId]);
    }
}

/** Fuehrt $fn mit abgeschalteter Aktivierungsmail aus und stellt den Wert zurueck. */
function uaWithoutActivationMail(callable $fn): void
{
    $pdo  = uaPdo();
    $sel  = $pdo->prepare('SELECT setting_value FROM ' . uaTable('system_settings') . ' WHERE setting_key = ?');
    $sel->execute(['mail_activation_enabled']);
    $vorher = $sel->fetchColumn();
    $set = $pdo->prepare('UPDATE ' . uaTable('system_settings') . ' SET setting_value = ? WHERE setting_key = ?');
    $set->execute(['0', 'mail_activation_enabled']);
    try {
        $fn();
    } finally {
        if ($vorher !== false) {
            $set->execute([$vorher, 'mail_activation_enabled']);
        }
    }
}

function uaActivate(array $body, string $role = 'admin'): array
{
    return apiRequest('POST', 'activate_user', ['token' => apiToken($role), 'body' => $body]);
}

// ---- activate_user ------------------------------------------------------------

test('activate_user: Geraetekonto -> 400, nichts geaendert', function () {
    $id = null;
    try {
        // auth_device traegt email_verified = 1 -- genau dieser Fall lief bisher durch
        $id     = uaCreateUser('device', 'pending', 0, 1, 'auth_device');
        $vorher = uaUserRow($id);
        $res    = uaActivate(['user_id' => $id]);
        assertStatus(400, $res, 'Geraet');
        assertTrue(str_contains((string) ($res['body']['message'] ?? ''), 'Gerät'),
            'Meldung nennt das Geraet nicht: ' . $res['raw']);
        assertSame($vorher, uaUserRow($id), 'Geraetekonto wurde veraendert');
    } finally {
        uaDeleteUser($id);
    }
});

test('activate_user: Geraet ohne verifizierte Mail -> ebenfalls 400 wegen Geraet, nicht wegen Mail', function () {
    $id = null;
    try {
        $id  = uaCreateUser('device', 'active', 1, 0, 'totp_location');
        $res = uaActivate(['user_id' => $id]);
        assertStatus(400, $res);
        assertTrue(str_contains((string) ($res['body']['message'] ?? ''), 'Gerät'),
            'Meldung nennt das Geraet nicht: ' . $res['raw']);
    } finally {
        uaDeleteUser($id);
    }
});

test('activate_user: bereits aktives Konto -> 409, nichts geaendert', function () {
    $id = null;
    try {
        $id     = uaCreateUser('user', 'active', 1);
        $vorher = uaUserRow($id);
        $res    = uaActivate(['user_id' => $id]);
        assertStatus(409, $res, 'aktives Konto');
        assertTrue(($res['body']['message'] ?? '') !== '', 'Meldung fehlt: ' . $res['raw']);
        assertSame($vorher, uaUserRow($id), 'aktives Konto wurde veraendert');
    } finally {
        uaDeleteUser($id);
    }
});

test('activate_user: gesperrtes Konto -> 409 mit Hinweis auf Entsperren, nichts geaendert', function () {
    $id = null;
    try {
        // Mail bleibt an: Ohne die Pruefung liefe hier ein SMTP-Versuch (lokal
        // 127.0.0.1:1025, keine echte Zustellung) und der Status kaeme als 500.
        $id     = uaCreateUser('user', 'suspended', 0);
        $vorher = uaUserRow($id);
        $res    = uaActivate(['user_id' => $id]);
        assertStatus(409, $res, 'gesperrtes Konto');
        assertTrue(str_contains((string) ($res['body']['message'] ?? ''), 'Entsperren'),
            'Meldung verweist nicht auf Entsperren: ' . $res['raw']);
        assertSame($vorher, uaUserRow($id), 'gesperrtes Konto wurde veraendert');
    } finally {
        uaDeleteUser($id);
    }
});

test('activate_user: freigegebenes, aber abgeschaltetes Konto (active, is_active 0) -> 409, nichts geaendert', function () {
    $id = null;
    try {
        $id     = uaCreateUser('user', 'active', 0);
        $vorher = uaUserRow($id);
        $res    = uaActivate(['user_id' => $id]);
        assertStatus(409, $res, 'active/is_active 0');
        assertTrue(str_contains((string) ($res['body']['message'] ?? ''), 'Entsperren'),
            'Meldung verweist nicht auf Entsperren: ' . $res['raw']);
        assertSame($vorher, uaUserRow($id), 'Konto wurde veraendert');
    } finally {
        uaDeleteUser($id);
    }
});

test('activate_user: Registrierung (pending, verifiziert) wird freigegeben', function () {
    $id = null;
    try {
        uaWithoutActivationMail(function () use (&$id) {
            $id  = uaCreateUser('user', 'pending', 0);
            $res = uaActivate(['user_id' => $id]);
            assertStatus(200, $res);
            assertSame(true, $res['body']['success'] ?? null);
            adCheckKeys(adDoc('Registrierung freigeben', 2), $res['body'], 'activate_user', []);
            $row = uaUserRow($id);
            assertSame('active', $row['account_status']);
            assertSame(1, $row['is_active']);
            assertSame(null, $row['pending_member_id']);
        });
    } finally {
        uaDeleteUser($id);
    }
});

test('activate_user: pending ohne bestaetigte Mail -> 400', function () {
    $id = null;
    try {
        $id  = uaCreateUser('user', 'pending', 0, 0);
        assertStatus(400, uaActivate(['user_id' => $id]));
        assertSame('pending', uaUserRow($id)['account_status']);
    } finally {
        uaDeleteUser($id);
    }
});

test('activate_user: ohne user_id 400, unbekannt 404, Manager 403, GET 405', function () {
    assertStatus(400, uaActivate([]));
    assertStatus(404, uaActivate(['user_id' => 999999999]));
    assertStatus(403, uaActivate(['user_id' => 1], 'manager'));
    assertStatus(405, apiRequest('GET', 'activate_user', ['token' => apiToken('admin')]));
});

// ---- user_status --------------------------------------------------------------

test('user_status: sperren und entsperren setzt account_status und is_active', function () {
    $id = null;
    try {
        $id = uaCreateUser('user', 'active', 1);
        $res = apiRequest('POST', 'user_status', ['token' => apiToken('admin'),
            'body' => ['user_id' => $id, 'status' => 'suspended']]);
        assertStatus(200, $res);
        assertSame('Benutzer gesperrt', $res['body']['message'] ?? null);
        adCheckKeys(adDoc('Benutzer sperren und entsperren', 2), $res['body'], 'user_status', []);
        $row = uaUserRow($id);
        assertSame(['suspended', 0], [$row['account_status'], $row['is_active']]);

        $res = apiRequest('POST', 'user_status', ['token' => apiToken('admin'),
            'body' => ['user_id' => $id, 'status' => 'active']]);
        assertStatus(200, $res);
        $row = uaUserRow($id);
        assertSame(['active', 1], [$row['account_status'], $row['is_active']]);

        // Ungueltiger Status und unbekanntes Konto
        assertStatus(400, apiRequest('POST', 'user_status', ['token' => apiToken('admin'),
            'body' => ['user_id' => $id, 'status' => 'pending']]));
        assertStatus(404, apiRequest('POST', 'user_status', ['token' => apiToken('admin'),
            'body' => ['user_id' => 999999999, 'status' => 'active']]));
    } finally {
        uaDeleteUser($id);
    }
});

// ---- API.md: Request-Felder = was der Handler liest -----------------------------

/** Die Felder, die eine Handler-Funktion aus dem Request-Objekt $data liest. */
function uaHandlerFields(string $function): array
{
    $code = sourceCode(__DIR__ . '/../../private/handlers/users.php');
    assertTrue((bool) preg_match('/function\s+' . $function . '\s*\(.*?\n\}/s', $code, $m),
        "{$function}() nicht gefunden");
    preg_match_all('/\$data->(\w+)/', $m[0], $f);
    $felder = array_values(array_unique($f[1]));
    sort($felder);

    return $felder;
}

test('API.md: Request-Beispiele von activate_user und user_status nennen genau die gelesenen Felder', function () {
    foreach ([
        ['Registrierung freigeben', 'handleUserActivation'],
        ['Benutzer sperren und entsperren', 'handleUserStatus'],
    ] as [$heading, $function]) {
        $doc = array_keys(adDoc($heading, 1));
        sort($doc);
        assertSame(uaHandlerFields($function), $doc, "{$heading}: Request-Beispiel weicht vom Handler ab");
    }
});
