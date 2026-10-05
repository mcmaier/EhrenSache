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

test('GET settings nennt jeden Schalter, auch ohne Zeile in der Datenbank', function () {
    $res = apiRequest('GET', 'settings', ['token' => apiToken('admin')]);
    assertStatus(200, $res);
    $keys = array_column($res['body']['settings'], 'setting_key');
    foreach (FEATURES as $f) {
        assertTrue(in_array($f['setting'], $keys, true), "{$f['setting']} fehlt in GET settings");
    }
});

/** Admin legt an, liefert die id (201 erwartet). Muster: rsCreate() in responses_api.php. */
function fsCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");
    return (int) $res['body']['id'];
}

function fsDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

/**
 * Gruppe, Terminart (mit Rückmeldung und Entschuldigungspflicht), ein Mitglied
 * mit Nummer in der Gruppe und ein Termin, der vor zehn Minuten begonnen hat.
 * Anlegen nur bei eingeschalteter Terminplanung (Default). Muster: rsWorld().
 *
 * @return array{group: ?int, type: ?int, member: ?int, member_number: string, appointment: ?int}
 */
function fsWorld(string $label): array
{
    $suffix = substr(uniqid(), -6);
    $w = ['group' => null, 'type' => null, 'member' => null,
          'member_number' => 'FS' . $suffix, 'appointment' => null];
    try {
        $w['group'] = fsCreate('member_groups', ['group_name' => "FS {$label} {$suffix}"]);
        $w['type']  = fsCreate('appointment_types', [
            'type_name' => "FS {$label} {$suffix}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => [$w['group']], 'responses_enabled' => 1, 'responses_require_excuse' => 1,
        ]);
        $w['member'] = fsCreate('members', [
            'name' => 'Fs', 'surname' => "Test {$label} {$suffix}", 'member_number' => $w['member_number'],
            'active' => 1, 'group_ids' => [$w['group']],
        ]);
        $beginn = time() - 600;
        $w['appointment'] = fsCreate('appointments', [
            'title' => "FS-Termin {$label}", 'type_id' => $w['type'],
            'date'  => date('Y-m-d', $beginn), 'start_time' => date('H:i:s', $beginn),
        ]);
    } catch (Throwable $e) {
        fsDropWorld($w);
        throw $e;
    }
    return $w;
}

/** Löscht in umgekehrter Reihenfolge; der Termin nimmt Records und Anträge mit (ON DELETE CASCADE). */
function fsDropWorld(array $w): void
{
    if ($w['appointment'] !== null) {
        fsDelete('appointments', $w['appointment']);
    }
    if ($w['member'] !== null) {
        fsDelete('members', $w['member']);
    }
    if ($w['type'] !== null) {
        fsDelete('appointment_types', $w['type']);
    }
    if ($w['group'] !== null) {
        fsDelete('member_groups', $w['group']);
    }
}

test('Anwesenheit aus: statistics 403, mit include=worktime nur der Arbeitszeitblock', function () {
    $user = apiToken('user');
    fsWith('worktime_enabled', '1', function () use ($user) {
        $res = apiRequest('GET', 'statistics', ['token' => $user, 'query' => ['include' => 'worktime']]);
        assertStatus(200, $res);
        assertTrue(array_key_exists('summary', $res['body']), 'Gegenprobe: mit Anwesenheit kommt die volle Statistik');
    });
    fsWith('attendance_enabled', '0', function () use ($user) {
        fsAssertDisabled(apiRequest('GET', 'statistics', ['token' => $user]), 'attendance', 'GET statistics');
        fsWith('worktime_enabled', '1', function () use ($user) {
            $res = apiRequest('GET', 'statistics', ['token' => $user, 'query' => ['include' => 'worktime']]);
            assertStatus(200, $res);
            $keys = array_keys($res['body']);
            sort($keys);
            assertSame(['warning', 'worktime', 'year'], $keys, 'Ohne Anwesenheit nur der Arbeitszeitblock');
            assertTrue(is_array($res['body']['worktime']), 'Der Block worktime fehlt');
        });
        fsWith('worktime_enabled', '0', function () use ($user) {
            fsAssertDisabled(apiRequest('GET', 'statistics', ['token' => $user, 'query' => ['include' => 'worktime']]),
                'worktime', 'GET statistics include=worktime bei Zeiterfassung aus');
        });
    });
});

test('Anwesenheit aus: statistics_report antwortet 403', function () {
    fsWith('attendance_enabled', '0', function () {
        fsAssertDisabled(apiRequest('GET', 'statistics_report', ['token' => apiToken('admin')]), 'attendance', 'GET statistics_report');
    });
});

test('Export und Import: Typen abgeschalteter Funktionen antworten 403', function () {
    $admin = apiToken('admin');
    fsWith('attendance_enabled', '0', function () use ($admin) {
        fsAssertDisabled(apiRequest('GET', 'export', ['token' => $admin, 'query' => ['type' => 'records']]), 'attendance', 'export records');
        $ap = apiRequest('GET', 'export', ['token' => $admin, 'query' => ['type' => 'appointments']]);
        assertTrue($ap['status'] !== 403, 'export appointments haengt nicht an der Anwesenheit');
        foreach (['records', 'extract_appointments'] as $typ) {
            fsAssertDisabled(apiRequest('POST', 'import', ['token' => $admin, 'query' => ['type' => $typ]]), 'attendance', "import {$typ}");
        }
        assertStatus(400, apiRequest('POST', 'import', ['token' => $admin, 'query' => ['type' => 'appointments']]),
            'import appointments ohne Datei: 400 (keine Datei), nicht 403');
    });
    fsWith('appointments_enabled', '0', function () use ($admin) {
        fsAssertDisabled(apiRequest('GET', 'export', ['token' => $admin, 'query' => ['type' => 'appointments']]), 'appointments', 'export appointments');
        fsAssertDisabled(apiRequest('POST', 'import', ['token' => $admin, 'query' => ['type' => 'appointments']]), 'appointments', 'import appointments');
        fsAssertDisabled(apiRequest('GET', 'export', ['token' => $admin, 'query' => ['type' => 'records']]), 'attendance', 'export records bei Terminplanung aus');
    });
});

test('available_years enthaelt ein Jahr, in dem es nur Arbeitszeit gibt', function () {
    fsWith('worktime_enabled', '1', function () {
        $jahr  = 2001;
        $jahre = function (): array {
            $res = apiRequest('GET', 'available_years', ['token' => apiToken('admin')]);
            assertStatus(200, $res);
            return array_map('intval', $res['body']);
        };
        assertTrue(!in_array($jahr, $jahre(), true),
            "Voraussetzung: {$jahr} steht schon in der Jahresliste (Termine in {$jahr}?) -- Test braucht ein leeres Jahr");

        $w = fsWorld('Jahre');
        $activity = null;
        $session  = null;
        try {
            $activity = fsCreate('activity_types', ['activity_name' => 'FS-Jahr ' . uniqid(), 'group_ids' => [$w['group']]]);
            $res = apiRequest('POST', 'work_sessions', ['token' => apiToken('admin'), 'body' => [
                'member_id' => $w['member'], 'activity_id' => $activity,
                'start_time' => "{$jahr}-03-01 10:00:00", 'end_time' => "{$jahr}-03-01 11:00:00",
                'break_minutes' => 0, 'note' => 'OI-62',
            ]]);
            assertStatus(201, $res, 'Sitzung konnte nicht angelegt werden');
            $session = (int) $res['body']['session']['session_id'];

            assertTrue(in_array($jahr, $jahre(), true), "{$jahr} fehlt, obwohl dort eine Arbeitszeitsitzung liegt");
            fsWith('worktime_enabled', '0', function () use ($jahr, $jahre) {
                assertTrue(!in_array($jahr, $jahre(), true), 'Ohne Zeiterfassung zaehlen ihre Jahre nicht');
            });
        } finally {
            if ($session !== null) {
                fsDelete('work_sessions', $session);
            }
            if ($activity !== null) {
                fsDelete('activity_types', $activity);
            }
            fsDropWorld($w);
        }
    });
});

/** Kiosk mit Gerätenamen; der Aufrufer löscht ihn (DELETE users). */
function fsKiosk(): array
{
    $res = apiRequest('POST', 'users', ['token' => apiToken('admin'), 'body' => [
        'action' => 'create_device', 'device_name' => 'FS-Kiosk ' . uniqid(),
        'device_type' => 'kiosk', 'totp_enabled' => false,
    ]]);
    assertStatus(200, $res, 'Kiosk konnte nicht angelegt werden');
    return ['user_id' => (int) $res['body']['device']['user_id'],
            'token'   => (string) $res['body']['device']['api_token']];
}

function fsStation(string $method, string $action, string $token, ?array $body = null): array
{
    $opts = ['token' => $token, 'query' => ['action' => $action]];
    if ($body !== null) {
        $opts['body'] = $body;
    }
    return apiRequest($method, 'station', $opts);
}

test('Station: Anwesenheit aus -- status meldet es, identify ohne Kandidat, checkin 403', function () {
    fsWith('station_pin_enabled', '1', function () {
        $w = fsWorld('Station');
        $kiosk = null;
        try {
            assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
                'query' => ['id' => $w['member']], 'body' => ['pin' => '2580']]), 'PIN konnte nicht gesetzt werden');
            $kiosk = fsKiosk();
            $creds = ['member_number' => $w['member_number'], 'pin' => '2580'];

            $status = fsStation('GET', 'status', $kiosk['token']);
            assertStatus(200, $status);
            assertSame(true, $status['body']['attendance_enabled'] ?? null, 'status muss attendance_enabled melden');
            $id = fsStation('POST', 'identify', $kiosk['token'], $creds);
            assertStatus(200, $id);
            assertTrue($id['body']['checkin_candidate'] !== null, 'Gegenprobe: Terminkandidat erwartet');

            fsWith('attendance_enabled', '0', function () use ($kiosk, $creds) {
                $status = fsStation('GET', 'status', $kiosk['token']);
                assertStatus(200, $status);
                assertSame(false, $status['body']['attendance_enabled'] ?? null);
                $id = fsStation('POST', 'identify', $kiosk['token'], $creds);
                assertStatus(200, $id, 'identify bleibt erreichbar (Arbeitszeit)');
                assertSame(null, $id['body']['checkin_candidate'], 'Ohne Anwesenheit kein Terminkandidat');
                fsAssertDisabled(fsStation('POST', 'checkin', $kiosk['token'], $creds), 'attendance', 'POST station checkin');
            });
        } finally {
            if ($kiosk !== null) {
                fsDelete('users', $kiosk['user_id']);
            }
            fsDropWorld($w);
        }
    });
});

test('Anwesenheit aus: appointments liefert bei include=attendance keine Zahlen', function () {
    $w = fsWorld('Kalender');
    try {
        $zeile = function () use ($w): array {
            $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'), 'query' => [
                'year' => (int) date('Y', time() - 600), 'include' => 'attendance']]);
            assertStatus(200, $res);
            foreach ($res['body'] as $row) {
                if ((int) $row['appointment_id'] === $w['appointment']) {
                    return $row;
                }
            }
            throw new RuntimeException('Testtermin fehlt in der Liste');
        };
        assertTrue(array_key_exists('attendance', $zeile()), 'Gegenprobe: mit Anwesenheit traegt der Termin das Feld attendance');
        fsWith('attendance_enabled', '0', function () use ($zeile) {
            $row = $zeile();
            assertTrue(!array_key_exists('attendance', $row) && !array_key_exists('own_attendance', $row),
                'Ohne Anwesenheit keine Anwesenheitszahlen');
        });
    } finally {
        fsDropWorld($w);
    }
});

test('my_open_items: Antraege entfallen ohne Anwesenheit und ohne Terminplanung', function () {
    $w = fsWorld('Offen');
    try {
        $userMember = apiMemberId('user');
        assertTrue($userMember !== null, 'Das Testkonto user braucht ein verknuepftes Mitglied');
        fsCreate('exceptions', ['member_id' => $userMember, 'appointment_id' => $w['appointment'],
                                'exception_type' => 'absence', 'reason' => 'OI-62 Test']);
        $arten = function () use ($w): array {
            $res = apiRequest('GET', 'my_open_items', ['token' => apiToken('user')]);
            assertStatus(200, $res);
            $treffer = array_filter($res['body']['items'], fn ($i) => ($i['appointment_id'] ?? null) === $w['appointment']);
            return array_values(array_column($treffer, 'kind'));
        };
        assertSame(['exception'], $arten(), 'Gegenprobe: der Antrag steht in den offenen Punkten');
        fsWith('attendance_enabled', '0', function () use ($arten) {
            assertSame([], $arten(), 'Ohne Anwesenheit keine Antraege');
        });
        // Ohne Terminplanung gilt dasselbe (Anwesenheit setzt sie voraus);
        // die Rueckmeldepunkte prueft der Test darunter an einem kuenftigen Termin.
        fsWith('appointments_enabled', '0', function () use ($arten) {
            assertSame([], $arten(), 'Ohne Terminplanung keine Antraege');
        });
    } finally {
        fsDropWorld($w);   // der Termin nimmt den Antrag mit
    }
});

/**
 * Welt für offene Rückmeldepunkte: Gruppe des Testkontos user, Terminart mit
 * Rückmeldung, ein Termin in zehn Tagen (innerhalb des 14-Tage-Horizonts).
 * Muster: oiWithWorld() in open_items_api.php; eigene Namen wegen des
 * gemeinsamen Prozesses beim Gesamtlauf.
 */
function fsWithOpenResponse(callable $fn): void
{
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Das Testkonto user braucht ein verknuepftes Mitglied');
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    $original = array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups'] ?? []);

    $suffix = substr(uniqid(), -6);
    $group = $type = $appointment = null;
    try {
        $group = fsCreate('member_groups', ['group_name' => "FS Offen {$suffix}"]);
        $type  = fsCreate('appointment_types', [
            'type_name' => "FS Offen {$suffix}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => [$group], 'responses_enabled' => 1, 'response_deadline_hours' => 24,
        ]);
        $appointment = fsCreate('appointments', [
            'title' => 'FS-Offen', 'type_id' => $type,
            'date'  => date('Y-m-d', strtotime('+10 days')), 'start_time' => '19:30',
        ]);
        assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['group_ids' => array_values(array_unique(array_merge($original, [$group])))]]));
        $fn($appointment);
    } finally {
        apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['group_ids' => $original]]);
        if ($appointment !== null) {
            fsDelete('appointments', $appointment);
        }
        if ($type !== null) {
            fsDelete('appointment_types', $type);
        }
        if ($group !== null) {
            fsDelete('member_groups', $group);
        }
    }
}

test('my_open_items: Rueckmeldepunkte entfallen ohne Terminplanung', function () {
    fsWithOpenResponse(function (int $appointment) {
        $punkt = function () use ($appointment): ?array {
            $res = apiRequest('GET', 'my_open_items', ['token' => apiToken('user')]);
            assertStatus(200, $res);
            foreach ($res['body']['items'] as $i) {
                if ($i['kind'] === 'response' && (int) ($i['appointment_id'] ?? 0) === $appointment) {
                    return $i;
                }
            }
            return null;
        };
        assertTrue($punkt() !== null, 'Gegenprobe: Mit Terminplanung steht die offene Rueckmeldung in den Punkten');
        fsWith('appointments_enabled', '0', function () use ($punkt) {
            assertSame(null, $punkt(), 'Ohne Terminplanung kein Rueckmeldepunkt');
        });
    });
});

test('Anwesenheit aus: Rueckmeldungen ohne Abgleich und ohne Entschuldigungsantrag', function () {
    $w = fsWorld('Rueckmeldung');
    $admin = apiToken('admin');
    $query = ['appointment_id' => $w['appointment'], 'member_id' => $w['member']];
    try {
        // Gegenprobe mit Anwesenheit: Absage legt einen Antrag an, Abgleich nach Beginn vorhanden
        $put = apiRequest('PUT', 'appointment_responses', ['token' => $admin, 'query' => $query,
            'body' => ['status' => 'no', 'comment' => 'krank']]);
        assertStatus(200, $put);
        assertSame('pending', $put['body']['own']['excuse_state'] ?? null, 'Gegenprobe: Entschuldigungsantrag erwartet');
        $get = apiRequest('GET', 'appointment_responses', ['token' => $admin, 'query' => ['appointment_id' => $w['appointment']]]);
        assertStatus(200, $get);
        assertTrue(array_key_exists('comparison', $get['body']), 'Gegenprobe: Abgleich nach Beginn erwartet');
        assertStatus(200, apiRequest('DELETE', 'appointment_responses', ['token' => $admin, 'query' => $query]));

        fsWith('attendance_enabled', '0', function () use ($admin, $query, $w) {
            $put = apiRequest('PUT', 'appointment_responses', ['token' => $admin, 'query' => $query,
                'body' => ['status' => 'no']]);
            assertStatus(200, $put, 'Absage ohne Begruendung: die Pflicht ruht ohne Anwesenheit');
            assertTrue(array_key_exists('excuse_state', $put['body']['own'] ?? []) && $put['body']['own']['excuse_state'] === null, 'Kein Entschuldigungsantrag ohne Anwesenheit');
            assertSame(false, $put['body']['settings']['require_excuse'] ?? null, 'require_excuse ruht');
            $get = apiRequest('GET', 'appointment_responses', ['token' => $admin, 'query' => ['appointment_id' => $w['appointment']]]);
            assertStatus(200, $get);
            assertTrue(!array_key_exists('comparison', $get['body']), 'Kein Abgleich mit Anwesenden ohne Anwesenheit');
            foreach ($get['body']['members'] ?? [] as $m) {
                assertTrue(array_key_exists('present', $m) && $m['present'] === null, 'present muss ohne Anwesenheit null sein');
            }
        });
    } finally {
        fsDropWorld($w);
    }
});

test('Terminplanung aus: work_sessions mit appointment_id antwortet 403 mit field', function () {
    fsWith('worktime_enabled', '1', function () {
        fsWith('appointments_enabled', '0', function () {
            $admin = apiToken('admin');
            $body  = ['activity_id' => 1, 'start_time' => '2001-03-01 10:00:00',
                      'end_time' => '2001-03-01 11:00:00', 'appointment_id' => 1];
            $post = apiRequest('POST', 'work_sessions', ['token' => $admin, 'body' => $body]);
            fsAssertDisabled($post, 'appointments', 'POST work_sessions mit appointment_id');
            assertSame('appointment_id', $post['body']['field'] ?? null, 'POST: field fehlt');
            $put = apiRequest('PUT', 'work_sessions', ['token' => $admin, 'query' => ['id' => 999999999], 'body' => $body]);
            fsAssertDisabled($put, 'appointments', 'PUT work_sessions mit appointment_id');
            assertSame('appointment_id', $put['body']['field'] ?? null, 'PUT: field fehlt');
        });
    });
});

test('Terminplanung aus: leeres appointment_id loest eine bestehende Verknuepfung nicht', function () {
    fsWith('worktime_enabled', '1', function () {
        $w = fsWorld('Verknuepfung');
        $activity = null;
        $session  = null;
        try {
            $activity = fsCreate('activity_types', ['activity_name' => 'FS-Taetigkeit ' . uniqid(), 'group_ids' => [$w['group']]]);
            $felder = ['member_id' => $w['member'], 'activity_id' => $activity,
                       'start_time' => '2001-03-01 10:00:00', 'end_time' => '2001-03-01 11:00:00',
                       'break_minutes' => 0, 'note' => 'OI-62'];
            $res = apiRequest('POST', 'work_sessions', ['token' => apiToken('admin'),
                'body' => $felder + ['appointment_id' => $w['appointment']]]);
            assertStatus(201, $res, 'Sitzung mit Termin konnte nicht angelegt werden');
            $session = (int) $res['body']['session']['session_id'];

            fsWith('appointments_enabled', '0', function () use ($session, $felder, $w) {
                $put = apiRequest('PUT', 'work_sessions', ['token' => apiToken('admin'),
                    'query' => ['id' => $session], 'body' => $felder + ['appointment_id' => null]]);
                assertStatus(200, $put, 'Korrektur eines alten Clients (appointment_id: null) muss gelingen');
                $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('admin'), 'query' => ['id' => $session]]);
                assertStatus(200, $get);
                assertSame($w['appointment'], (int) $get['body']['appointment_id'], 'Die Verknuepfung muss stehen bleiben');
            });
        } finally {
            if ($session !== null) {
                fsDelete('work_sessions', $session);
            }
            if ($activity !== null) {
                fsDelete('activity_types', $activity);
            }
            fsDropWorld($w);
        }
    });
});

/**
 * Welt fuer Infokarten der PWA: Gruppe des Testkontos user, Terminart OHNE
 * Rueckmeldung, ein Termin in zehn Tagen und ein eigener Entschuldigungsantrag
 * dazu. Muster: fsWithOpenResponse().
 */
function fsWithInfoAbsence(callable $fn): void
{
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Das Testkonto user braucht ein verknuepftes Mitglied');
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    $original = array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups'] ?? []);

    $suffix = substr(uniqid(), -6);
    $group = $type = $appointment = $exception = null;
    try {
        $group = fsCreate('member_groups', ['group_name' => "FS Info {$suffix}"]);
        $type  = fsCreate('appointment_types', [
            'type_name' => "FS Info {$suffix}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => [$group], 'responses_enabled' => 0,
        ]);
        $appointment = fsCreate('appointments', [
            'title' => 'FS-Info', 'type_id' => $type,
            'date'  => date('Y-m-d', strtotime('+10 days')), 'start_time' => '19:30',
        ]);
        $exception = fsCreate('exceptions', [
            'member_id' => $memberId, 'appointment_id' => $appointment,
            'exception_type' => 'absence', 'reason' => 'FS-Info',
        ]);
        assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['group_ids' => array_values(array_unique(array_merge($original, [$group])))]]));
        $fn($appointment);
    } finally {
        apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $memberId], 'body' => ['group_ids' => $original]]);
        if ($exception !== null) {
            fsDelete('exceptions', $exception);
        }
        if ($appointment !== null) {
            fsDelete('appointments', $appointment);
        }
        if ($type !== null) {
            fsDelete('appointment_types', $type);
        }
        if ($group !== null) {
            fsDelete('member_groups', $group);
        }
    }
}

test('PWA-Terminliste: own_absence der Infokarten entfaellt ohne Anwesenheit', function () {
    fsWithInfoAbsence(function (int $appointment) {
        $karte = function () use ($appointment): array {
            $res = apiRequest('GET', 'appointment_responses', ['token' => apiToken('user'),
                'query' => ['upcoming' => 1, 'with_info' => 1]]);
            assertStatus(200, $res);
            foreach ($res['body']['appointments'] as $item) {
                if ((int) $item['appointment']['appointment_id'] === $appointment) {
                    return $item;
                }
            }
            throw new RuntimeException('Infokarte fehlt in der Antwort');
        };

        $an = $karte();
        assertTrue(array_key_exists('own_absence', $an), 'Gegenprobe: own_absence ist Teil der Antwortform');
        assertTrue($an['own_absence'] !== null, 'Gegenprobe: Mit Anwesenheit steht der eigene Antrag an der Karte');

        fsWith('attendance_enabled', '0', function () use ($karte) {
            $aus = $karte();
            assertTrue(array_key_exists('own_absence', $aus), 'Form bleibt stabil: Schluessel own_absence vorhanden');
            assertSame(null, $aus['own_absence'], 'Ohne Anwesenheit keine Entschuldigung an der Karte');
        });
    });
});
