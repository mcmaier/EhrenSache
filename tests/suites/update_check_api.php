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
 * Ressource update_check. Der POST fragt GitHub und wird hier nur auf die
 * Rechte geprueft -- ein Test, der das Netz braucht, gehoert nach
 * tests/db/verify_update_github.php.
 */

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    test('HTTP-Suite uebersprungen: curl-Erweiterung fehlt', function () {
        throw new RuntimeException('php_curl aktivieren, sonst laufen die API-Tests nicht');
    });
    return;
}

test('update_check liefert dem Admin den gespeicherten Stand', function () {
    $res = apiRequest('GET', 'update_check', ['token' => apiToken('admin')]);
    assertStatus(200, $res);

    foreach (['installed', 'last_checked', 'latest', 'update_available'] as $schluessel) {
        assertTrue(array_key_exists($schluessel, $res['body'] ?? []), "Schluessel {$schluessel} fehlt");
    }
    $erwartet = json_decode((string) file_get_contents(__DIR__ . '/../../version.json'), true)['version'];
    assertSame($erwartet, $res['body']['installed']);
});

test('update_check ist fuer manager und user gesperrt', function () {
    foreach (['manager', 'user'] as $rolle) {
        assertStatus(403, apiRequest('GET', 'update_check', ['token' => apiToken($rolle)]), "GET {$rolle}");
    }
});

test('Die Pruefung per POST bleibt manager verwehrt, bevor GitHub gefragt wird', function () {
    assertStatus(403, apiRequest('POST', 'update_check', ['token' => apiToken('manager'), 'body' => []]));
});

test('update_check kennt nur GET und POST', function () {
    assertStatus(405, apiRequest('PUT', 'update_check', ['token' => apiToken('admin'), 'body' => []]));
});
