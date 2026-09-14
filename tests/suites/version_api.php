<?php
declare(strict_types=1);

/**
 * Ressource version: Das Feld config_format verraet, in welcher Form
 * private/config/config.php vorliegt. Es ist fuer den Hinweisstreifen im
 * Dashboard gedacht und darf nur die Rolle admin erreichen.
 */

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    test('HTTP-Suite uebersprungen: curl-Erweiterung fehlt', function () {
        throw new RuntimeException('php_curl aktivieren, sonst laufen die API-Tests nicht');
    });
    return;
}

test('version meldet dem Admin die Form der Konfigurationsdatei', function () {
    $res = apiRequest('GET', 'version', ['token' => apiToken('admin')]);
    assertStatus(200, $res);

    assertTrue(array_key_exists('config_format', $res['body'] ?? []), 'config_format fehlt fuer admin');
    assertTrue(
        in_array($res['body']['config_format'], ['array', 'legacy', 'missing', 'unknown'], true),
        'Unbekannter Wert: ' . var_export($res['body']['config_format'], true)
    );
});

test('version verschweigt config_format gegenueber manager und user', function () {
    foreach (['manager', 'user'] as $rolle) {
        $res = apiRequest('GET', 'version', ['token' => apiToken($rolle)]);
        assertStatus(200, $res, "Rolle {$rolle}");
        assertTrue(
            !array_key_exists('config_format', $res['body'] ?? []),
            "config_format wurde an die Rolle {$rolle} ausgeliefert"
        );
    }
});

test('version liefert weiterhin die Versionsnummer', function () {
    $res = apiRequest('GET', 'version', ['token' => apiToken('user')]);
    assertStatus(200, $res);

    $erwartet = json_decode((string) file_get_contents(__DIR__ . '/../../version.json'), true)['version'];
    assertSame($erwartet, $res['body']['version'] ?? null);
});
