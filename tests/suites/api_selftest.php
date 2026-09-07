<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    test('HTTP-Suite uebersprungen: curl-Erweiterung fehlt', function () {
        throw new RuntimeException('php_curl aktivieren, sonst laufen die API-Tests nicht');
    });
    return;
}

test('ping antwortet mit 200', function () {
    $res = apiRequest('GET', 'ping');
    assertStatus(200, $res);
});

test('Login als admin liefert einen Token', function () {
    $token = apiToken('admin');
    assertTrue(is_string($token) && strlen($token) > 20, 'Token sieht nicht plausibel aus');
});

test('Anfrage ohne Token wird abgewiesen', function () {
    $res = apiRequest('GET', 'members');
    assertTrue($res['status'] === 401 || $res['status'] === 403,
        "401 oder 403 erwartet, {$res['status']} erhalten");
});

test('Anfrage mit Token wird angenommen', function () {
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin')]);
    assertStatus(200, $res);
});

test('Alle drei Testrollen koennen sich anmelden', function () {
    foreach (['admin', 'manager', 'user'] as $role) {
        assertTrue(strlen(apiToken($role)) > 20, "Login als '{$role}' fehlgeschlagen");
    }
});

test('Die Rolle user hat ein verknuepftes Mitglied', function () {
    // Ohne verknuepftes Mitglied kann diese Rolle keine Zeit erfassen —
    // die spaeteren Suites waeren dann nicht aussagekraeftig.
    assertTrue(apiMemberId('user') !== null,
        'Testaccount user@example.com hat kein verknuepftes Mitglied');
});

// ---- Auth-Falle aus OI-24 ---------------------------------------------------

/**
 * Meldet sich per Session an und liefert den Cookie-Jar.
 *
 * Die Suite arbeitet sonst mit Bearer-Token. Fuer den Export braucht es aber
 * genau den anderen Weg: Das Dashboard hat keinen Token und haengt an der
 * Session. Das Passwort kommt aus tests/config.php und wird nirgends ausgegeben.
 */
function sessionJar(string $role = 'admin'): string
{
    $cfg  = testConfig();
    $jar  = tempnam(sys_get_temp_dir(), 'sess');
    $base = rtrim($cfg['base_url'], '/') . '/api/api.php';

    $ch = curl_init($base . '?resource=login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'email'    => $cfg[$role]['email'],
            'password' => $cfg[$role]['password'],
        ]),
    ]);
    $ok = curl_getinfo(($r = curl_exec($ch)) !== false ? $ch : $ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    assertSame(200, $ok, "Session-Login als {$role} fehlgeschlagen");

    return $jar;
}

/** GET auf die API mit Cookie-Jar und frei waehlbaren Headern. */
function sessionGet(string $query, string $jar, array $headers = []): array
{
    $cfg = testConfig();
    $ch  = curl_init(rtrim($cfg['base_url'], '/') . '/api/api.php?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'raw' => $body];
}

test('Export laeuft ueber die Session, ohne Authorization-Header', function () {
    // Der Weg, den das Dashboard nach dem Fix von OI-24 geht. Vorher schickte
    // getAuthHeaders() ein "Bearer null" mit und brachte genau diesen Aufruf
    // zu Fall.
    $jar = sessionJar('admin');

    foreach (['members', 'appointments', 'records'] as $type) {
        $res = sessionGet('resource=export&type=' . $type . '&year=' . date('Y'), $jar);
        assertSame(200, $res['status'], "Export '{$type}' ueber die Session fehlgeschlagen");
        assertTrue(strpos($res['raw'], '{') !== 0,
            "Export '{$type}' lieferte JSON statt CSV");
    }

    @unlink($jar);
});

test('Ein leerer Bearer-Header verdraengt die Session und wird abgewiesen', function () {
    // Haelt die Ursache von OI-24 fest: api.php startet die Session nur, wenn
    // KEIN Token mitkommt. Ein gueltig aussehender, aber leerer Header laeuft
    // deshalb in die Token-Pruefung und scheitert dort — trotz Anmeldung.
    // Schlaegt dieser Test fehl, hat sich das Server-Verhalten geaendert und
    // getAuthHeaders() darf neu bewertet werden.
    $jar = sessionJar('admin');

    $res = sessionGet('resource=export&type=members', $jar,
                      ['Authorization: Bearer null']);

    assertSame(401, $res['status'],
        'Erwartet wird 401 — ein leerer Token-Header darf nicht stillschweigend zur Session zurueckfallen');

    @unlink($jar);
});
