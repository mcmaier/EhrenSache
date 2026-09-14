<?php
declare(strict_types=1);

/**
 * Wartungsflag gegen die laufende Installation. Wie version_api.php setzt die
 * Suite voraus, dass base_url aus tests/config.php auf DIESES Arbeitsverzeichnis
 * zeigt -- sie legt das Flag hier ab und erwartet die Wirkung dort.
 */

require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../../private/helpers/maintenance.php';

if (!extension_loaded('curl')) {
    test('HTTP-Suite uebersprungen: curl-Erweiterung fehlt', function () {
        throw new RuntimeException('php_curl aktivieren, sonst laufen die API-Tests nicht');
    });
    return;
}

test('Ein gesetztes Wartungsflag beantwortet jede API-Anfrage mit 503', function () {
    $flag = maintenanceFlagPath();
    // Ein fremdes Flag wird nicht angefasst.
    assertSame(false, is_file($flag), 'Wartungsflag lag schon vor dem Test -- Installation pruefen');

    maintenanceBegin($flag);
    try {
        $res = apiRequest('GET', 'ping');
        assertStatus(503, $res, 'Zeigt base_url auf dieses Arbeitsverzeichnis?');
        assertSame('maintenance', $res['body']['status'] ?? null);
    } finally {
        maintenanceEnd($flag);
    }

    assertStatus(200, apiRequest('GET', 'ping'));
});

test('Ein abgelaufenes Wartungsflag sperrt nicht', function () {
    $flag = maintenanceFlagPath();
    assertSame(false, is_file($flag), 'Wartungsflag lag schon vor dem Test -- Installation pruefen');

    maintenanceBegin($flag, time() - MAINTENANCE_MAX_AGE_SECONDS - 5);
    try {
        assertStatus(200, apiRequest('GET', 'ping'));
    } finally {
        maintenanceEnd($flag);
    }
});
