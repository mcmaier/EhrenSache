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
