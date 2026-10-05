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
require_once __DIR__ . '/../../private/helpers/features.php';

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
    // Bestandsinstallationen kennen die Zeilen der Schalter aus Etappe 2 nicht;
    // dann gilt der Default aus FEATURES (OI-62, Etappe 2).
    foreach (FEATURES as $f) {
        if ($f['setting'] === $key) {
            return $f['default'];
        }
    }
    throw new RuntimeException("Einstellung {$key} nicht gefunden");
}

/**
 * Führt $fn mit dem Schalter auf $value aus und stellt den alten Stand wieder her.
 * Scheitert $fn UND die Wiederherstellung, nennt die Meldung beides — das
 * Zurücksetzen darf den eigentlichen Fehler nicht verdecken.
 */
function fsWith(string $key, string $value, callable $fn): void
{
    $vorher = fsGetSetting($key);
    fsSetting($key, $value);
    $fehler = null;
    try {
        $fn();
    } catch (Throwable $e) {
        $fehler = $e;
    }
    try {
        fsSetting($key, $vorher);
    } catch (Throwable $restore) {
        throw new RuntimeException(
            "Schalter {$key} konnte nicht zurückgesetzt werden: " . $restore->getMessage()
            . ($fehler ? ' | ursprünglicher Fehler: ' . $fehler->getMessage() : ''),
            0,
            $fehler ?? $restore
        );
    }
    if ($fehler) {
        throw $fehler;
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
        assertStatus(200, apiRequest('GET', 'work_sessions', ['token' => apiToken('admin')]));
        $res = apiRequest('GET', 'export', ['token' => apiToken('admin'),
            'query' => ['type' => 'worktime_member', 'from' => date('Y') . '-01-01', 'to' => date('Y') . '-12-31']]);
        assertTrue(($res['body']['code'] ?? null) !== 'FEATURE_DISABLED', 'export worktime_member darf bei eingeschalteter Zeiterfassung nicht gesperrt sein');
        assertTrue($res['status'] !== 403, 'export worktime_member liefert 403');
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

test('Stations-PIN an: change_pin wird nicht von der Funktionssperre abgewiesen', function () {
    fsWith('station_pin_enabled', '1', function () {
        $res = apiRequest('POST', 'change_pin', ['token' => apiToken('user'),
            'body' => ['current_password' => 'sicher-falsch', 'new_pin' => '2580']]);
        assertTrue(($res['body']['code'] ?? null) !== 'FEATURE_DISABLED', 'change_pin darf bei eingeschalteter Anmeldung nicht FEATURE_DISABLED liefern');
    });
});

test('Stations-PIN aus: change_pin antwortet 403', function () {
    fsWith('station_pin_enabled', '0', function () {
        fsAssertDisabled(apiRequest('POST', 'change_pin', ['token' => apiToken('user'),
            'body' => ['current_password' => 'x', 'new_pin' => '2580']]), 'station_pin', 'POST change_pin');
    });
});

test('me meldet alle Funktionen mit ihrem Stand', function () {
    $meFeatures = function (): array {
        $res = apiRequest('GET', 'me', ['token' => apiToken('user')]);
        assertStatus(200, $res);
        $f = $res['body']['features'] ?? null;
        assertTrue(is_array($f), 'me muss features liefern');
        assertSame(array_keys(FEATURES), array_keys($f));
        return $f;
    };
    foreach (FEATURES as $key => $def) {
        fsWith($def['setting'], '0', function () use ($key, $meFeatures) {
            assertSame(false, $meFeatures()[$key], "features.{$key} muss bei Schalter aus false sein");
        });
        fsWith($def['setting'], '1', function () use ($key, $meFeatures) {
            assertSame(true, $meFeatures()[$key], "features.{$key} muss bei Schalter an true sein");
        });
    }
});

test('settings scope=client fuehrt station_pin_enabled nicht mehr', function () {
    $res = apiRequest('GET', 'settings', ['token' => apiToken('user'), 'query' => ['scope' => 'client']]);
    assertStatus(200, $res);
    assertTrue(!array_key_exists('station_pin_enabled', $res['body']['settings'] ?? []),
        'Der Schalter kommt seit OI-62 ueber me, nicht mehr ueber scope=client');
    assertTrue(array_key_exists('station_pin_min_length', $res['body']['settings'] ?? []),
        'station_pin_min_length bleibt in scope=client');
});

// ---- Etappe 2: Terminplanung und Anwesenheit ----------------------------------

function fsMeFeatures(): array
{
    $res = apiRequest('GET', 'me', ['token' => apiToken('user')]);
    assertStatus(200, $res);
    return $res['body']['features'] ?? [];
}

test('Terminplanung aus: alle Termin- und Anwesenheitsressourcen antworten 403', function () {
    fsWith('appointments_enabled', '0', function () {
        $admin = apiToken('admin');
        $alle  = array_merge(FEATURES['appointments']['resources'], FEATURES['attendance']['resources']);
        assertSame(10, count($alle), 'Liste der Ressourcen hat sich geaendert -- Test nachziehen');
        foreach ($alle as $r) {
            // feature nennt den gefragten Schluessel, nicht die Voraussetzung
            fsAssertDisabled(apiRequest('GET', $r, ['token' => $admin]), featureForResource($r), "GET {$r}");
        }
    });
});

test('me meldet abhaengige Funktionen als aus, auch wenn ihre eigene Einstellung an ist', function () {
    fsWith('punctuality_enabled', '1', function () {
        fsWith('reliability_enabled', '1', function () {
            fsWith('appointments_enabled', '0', function () {
                $f = fsMeFeatures();
                foreach (['appointments', 'attendance', 'punctuality', 'reliability'] as $k) {
                    assertSame(false, $f[$k] ?? null, "features.{$k} bei Terminplanung aus");
                }
            });
            fsWith('attendance_enabled', '0', function () {
                $f = fsMeFeatures();
                assertSame(true, $f['appointments'] ?? null, 'Terminplanung bleibt an');
                foreach (['attendance', 'punctuality', 'reliability'] as $k) {
                    assertSame(false, $f[$k] ?? null, "features.{$k} bei Anwesenheit aus");
                }
            });
        });
    });
});

test('Terminplanung aus: my_data bleibt erreichbar (Auskunft)', function () {
    fsWith('appointments_enabled', '0', function () {
        $res = apiRequest('GET', 'my_data', ['token' => apiToken('user')]);
        assertStatus(200, $res, 'my_data darf an keinem Schalter haengen');
    });
});

test('Anwesenheit aus: Anwesenheitsressourcen 403, Terminressourcen 200', function () {
    fsWith('attendance_enabled', '0', function () {
        $admin = apiToken('admin');
        foreach (FEATURES['attendance']['resources'] as $r) {
            fsAssertDisabled(apiRequest('GET', $r, ['token' => $admin]), 'attendance', "GET {$r}");
        }
        assertStatus(200, apiRequest('GET', 'appointments', ['token' => $admin]));
        assertStatus(200, apiRequest('GET', 'appointment_types', ['token' => $admin]));
    });
});
