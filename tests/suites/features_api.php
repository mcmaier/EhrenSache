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

/**
 * Feature-Schalter über HTTP (OI-62): abgeschaltet antwortet jeder gesperrte
 * Pfad 403 FEATURE_DISABLED, me meldet den Stand, my_data bleibt erreichbar.
 *
 * Jeder Test stellt den vorgefundenen Schalterstand im finally wieder her —
 * ein abgebrochener Lauf hat schon einmal Schalter verstellt hinterlassen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function fsSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

function fsGetSetting(string $key): string
{
    // GET settings (Admin) liefert {"settings": [{"setting_key": …, "setting_value": …}, …]}
    $res = apiRequest('GET', 'settings', ['token' => apiToken('admin')]);
    assertStatus(200, $res);
    foreach ($res['body']['settings'] as $row) {
        if ($row['setting_key'] === $key) {
            return (string) $row['setting_value'];
        }
    }
    throw new RuntimeException("Einstellung {$key} nicht gefunden");
}

/** Führt $fn mit dem Schalter auf $value aus und stellt den alten Stand wieder her. */
function fsWith(string $key, string $value, callable $fn): void
{
    $vorher = fsGetSetting($key);
    fsSetting($key, $value);
    try {
        $fn();
    } finally {
        fsSetting($key, $vorher);
    }
}

function fsAssertDisabled(array $res, string $feature, string $pfad): void
{
    assertStatus(403, $res, "{$pfad} muss abgeschaltet 403 liefern");
    assertSame('FEATURE_DISABLED', $res['body']['code'] ?? null, "{$pfad}: Kennung fehlt");
    assertSame($feature, $res['body']['feature'] ?? null, "{$pfad}: Funktion fehlt");
}

test('Zeiterfassung aus: activity_types, work_sessions und Exporte antworten 403', function () {
    fsWith('worktime_enabled', '0', function () {
        $admin = apiToken('admin');
        fsAssertDisabled(apiRequest('GET', 'activity_types', ['token' => $admin]), 'worktime', 'GET activity_types');
        fsAssertDisabled(apiRequest('GET', 'work_sessions', ['token' => $admin]), 'worktime', 'GET work_sessions');
        fsAssertDisabled(apiRequest('GET', 'activity_types', ['token' => apiToken('user')]), 'worktime', 'GET activity_types als user');
        foreach (['worktime_member', 'worktime_activity', 'worktime_appointment'] as $typ) {
            fsAssertDisabled(apiRequest('GET', 'export', ['token' => $admin,
                'query' => ['type' => $typ, 'from' => date('Y') . '-01-01', 'to' => date('Y') . '-12-31']]),
                'worktime', "export {$typ}");
        }
    });
});

test('Zeiterfassung an: activity_types antwortet wieder 200', function () {
    fsWith('worktime_enabled', '1', function () {
        assertStatus(200, apiRequest('GET', 'activity_types', ['token' => apiToken('admin')]));
    });
});

test('Zeiterfassung aus: my_data liefert die Arbeitszeiten weiter (Auskunft)', function () {
    fsWith('worktime_enabled', '0', function () {
        $res = apiRequest('GET', 'my_data', ['token' => apiToken('user')]);
        assertStatus(200, $res, 'my_data darf an keinem Schalter haengen');
        assertTrue(array_key_exists('work_sessions', $res['body']),
            'my_data muss work_sessions auch bei abgeschalteter Zeiterfassung enthalten');
    });
});

test('Stations-PIN aus: change_pin antwortet 403', function () {
    fsWith('station_pin_enabled', '0', function () {
        fsAssertDisabled(apiRequest('POST', 'change_pin', ['token' => apiToken('user'),
            'body' => ['current_password' => 'x', 'new_pin' => '2580']]), 'station_pin', 'POST change_pin');
    });
});

test('me meldet alle Funktionen mit ihrem Stand', function () {
    fsWith('worktime_enabled', '0', function () {
        $res = apiRequest('GET', 'me', ['token' => apiToken('user')]);
        assertStatus(200, $res);
        $f = $res['body']['features'] ?? null;
        assertTrue(is_array($f), 'me muss features liefern');
        assertSame(['worktime', 'station_pin', 'punctuality', 'reliability'], array_keys($f));
        assertSame(false, $f['worktime']);
    });
    fsWith('worktime_enabled', '1', function () {
        $res = apiRequest('GET', 'me', ['token' => apiToken('user')]);
        assertSame(true, $res['body']['features']['worktime'] ?? null);
    });
});

test('settings scope=client fuehrt station_pin_enabled nicht mehr', function () {
    $res = apiRequest('GET', 'settings', ['token' => apiToken('user'), 'query' => ['scope' => 'client']]);
    assertStatus(200, $res);
    assertTrue(!array_key_exists('station_pin_enabled', $res['body']['settings'] ?? []),
        'Der Schalter kommt seit OI-62 ueber me, nicht mehr ueber scope=client');
    assertTrue(array_key_exists('station_pin_min_length', $res['body']['settings'] ?? []),
        'station_pin_min_length bleibt in scope=client');
});
