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
 * Ressource my_open_items (FI-17) gegen die Testinstanz.
 *
 * Jeder Test baut eine eigene Welt aus Gruppe und Terminart und haengt das
 * Mitglied des Kontos "user" fuer die Dauer des Tests in diese Gruppe -- wie
 * tests/suites/responses_api.php. Termindaten liegen relativ zu heute, damit
 * Fristen zu jeder Tageszeit tragen (Lehre aus 5035a35).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function oiCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");

    return (int) $res['body']['id'];
}

function oiDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

function oiDate(int $days): string
{
    return date('Y-m-d', strtotime("{$days} days"));
}

/** @param array<string, mixed> $typeSettings */
function oiWorld(array $typeSettings = ['responses_enabled' => 1]): array
{
    $suffix = uniqid();
    $world  = ['group' => null, 'type' => null, 'appointments' => [], 'exceptions' => [], 'sessions' => [],
               'activity' => null];
    $world['group'] = oiCreate('member_groups', ['group_name' => "OI {$suffix}"]);
    $world['type']  = oiCreate('appointment_types', array_merge([
        'type_name' => "OI {$suffix}", 'is_default' => 0, 'color' => '#667eea',
        'group_ids' => [$world['group']],
    ], $typeSettings));

    return $world;
}

function oiAppointment(array &$world, string $date, string $time = '19:30'): int
{
    $id = oiCreate('appointments', ['title' => 'OI-Termin', 'date' => $date, 'start_time' => $time,
                                    'type_id' => $world['type']]);
    $world['appointments'][] = $id;

    return $id;
}

function oiDropWorld(array $world): void
{
    foreach ($world['exceptions'] as $id) {
        oiDelete('exceptions', $id);
    }
    foreach ($world['sessions'] as $id) {
        oiDelete('work_sessions', $id);
    }
    foreach ($world['appointments'] as $id) {
        oiDelete('appointments', $id);
    }
    if ($world['activity'] !== null) {
        oiDelete('activity_types', $world['activity']);
    }
    oiDelete('appointment_types', $world['type']);
    oiDelete('member_groups', $world['group']);
}

function oiMemberGroups(int $memberId): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);

    return array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups'] ?? []);
}

function oiSetMemberGroups(int $memberId, array $groupIds): void
{
    assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
        'query' => ['id' => $memberId], 'body' => ['group_ids' => $groupIds]]));
}

/** Fuehrt $fn mit Welt und user-Mitglied aus, raeumt danach alles ab. */
function oiWithWorld(array $typeSettings, callable $fn): void
{
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');
    $original = oiMemberGroups($memberId);
    $world    = oiWorld($typeSettings);

    try {
        oiSetMemberGroups($memberId, array_values(array_unique(array_merge($original, [$world['group']]))));
        $fn($world, $memberId);
    } finally {
        oiSetMemberGroups($memberId, $original);
        oiDropWorld($world);
    }
}

function oiItems(string $role = 'user'): array
{
    $res = apiRequest('GET', 'my_open_items', ['token' => apiToken($role)]);
    assertStatus(200, $res);

    return $res['body'];
}

/** Punkte einer Art zu den Terminen/IDs der Welt. */
function oiFind(array $body, string $kind, string $field, int $id): ?array
{
    foreach ($body['items'] as $item) {
        if ($item['kind'] === $kind && (int) ($item[$field] ?? 0) === $id) {
            return $item;
        }
    }

    return null;
}

test('my_open_items: offene Rueckmeldung erscheint mit Frist', function () {
    oiWithWorld(['responses_enabled' => 1, 'response_deadline_hours' => 24], function (array &$world) {
        $id   = oiAppointment($world, oiDate(10));
        $item = oiFind(oiItems(), 'response', 'appointment_id', $id);
        assertTrue($item !== null, 'Offene Rueckmeldung fehlt');
        assertSame('open', $item['state']);
        assertSame('OI-Termin', $item['title']);
        assertTrue(!empty($item['deadline']), 'Frist fehlt');
    });
});

test('my_open_items: Rueckmeldung ausserhalb des 14-Tage-Horizonts erscheint nicht', function () {
    oiWithWorld(['responses_enabled' => 1, 'response_deadline_hours' => 24], function (array &$world) {
        $id = oiAppointment($world, oiDate(20));
        assertSame(null, oiFind(oiItems(), 'response', 'appointment_id', $id),
            'Rueckmeldung in 20 Tagen liegt ausserhalb OPEN_ITEMS_RESPONSE_DAYS');
    });
});

test('my_open_items: beantwortet (auch unsicher) ist nicht offen', function () {
    oiWithWorld(['responses_enabled' => 1], function (array &$world) {
        $id = oiAppointment($world, oiDate(10));
        assertStatus(200, apiRequest('PUT', 'appointment_responses', ['token' => apiToken('user'),
            'query' => ['appointment_id' => $id], 'body' => ['status' => 'maybe']]));
        assertSame(null, oiFind(oiItems(), 'response', 'appointment_id', $id));
    });
});

test('my_open_items: abgelaufene Frist ist nicht offen', function () {
    // Termin in 2 Tagen, Frist 72 h vorher -- seit gestern vorbei.
    oiWithWorld(['responses_enabled' => 1, 'response_deadline_hours' => 72], function (array &$world) {
        $id = oiAppointment($world, oiDate(2));
        assertSame(null, oiFind(oiItems(), 'response', 'appointment_id', $id));
    });
});

test('my_open_items: Terminart ohne Rueckmeldung liefert keinen Punkt', function () {
    oiWithWorld(['responses_enabled' => 0], function (array &$world) {
        $id = oiAppointment($world, oiDate(10));
        assertSame(null, oiFind(oiItems(), 'response', 'appointment_id', $id));
    });
});

test('my_open_items: wartender und abgelehnter Antrag', function () {
    oiWithWorld(['responses_enabled' => 0], function (array &$world, int $memberId) {
        $a1 = oiAppointment($world, oiDate(10));
        $a2 = oiAppointment($world, oiDate(11));
        $ids = [];
        foreach ([$a1, $a2] as $aid) {
            $res = apiRequest('POST', 'exceptions', ['token' => apiToken('user'), 'body' => [
                'member_id' => $memberId, 'appointment_id' => $aid,
                'exception_type' => 'absence', 'reason' => 'OI-Test']]);
            assertStatus(201, $res);
            $ids[$aid] = (int) $res['body']['id'];
            $world['exceptions'][] = $ids[$aid];
        }
        assertStatus(200, apiRequest('PUT', 'exceptions', ['token' => apiToken('admin'),
            'query' => ['id' => $ids[$a2]], 'body' => ['status' => 'rejected']]));

        $body    = oiItems();
        $pending = oiFind($body, 'exception', 'id', $ids[$a1]);
        $reject  = oiFind($body, 'exception', 'id', $ids[$a2]);
        assertTrue($pending !== null && $pending['state'] === 'pending', 'Wartender Antrag fehlt');
        assertTrue($reject !== null && $reject['state'] === 'rejected', 'Abgelehnter Antrag fehlt');
        assertTrue(!empty($reject['decided_at']), 'Ablehnung ohne decided_at');
        assertTrue(!array_key_exists('reason', $reject), 'Freitext darf nicht in der Antwort stehen');
    });
});

test('my_open_items: wartende beendete Arbeitszeit ja, laufende nein', function () {
    assertStatus(200, apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
        'body' => ['setting_key' => 'worktime_enabled', 'setting_value' => '1']]));
    oiWithWorld(['responses_enabled' => 0], function (array &$world) {
        $world['activity'] = oiCreate('activity_types', ['activity_name' => 'OI ' . uniqid(),
                                                          'group_ids' => [$world['group']]]);
        $res = apiRequest('POST', 'work_sessions', ['token' => apiToken('user'), 'body' => [
            'activity_id' => $world['activity'],
            'start_time'  => date('Y-m-d', strtotime('-1 day')) . ' 10:00:00',
            'end_time'    => date('Y-m-d', strtotime('-1 day')) . ' 12:15:00',
        ]]);
        assertStatus(201, $res);
        // POST work_sessions antwortet mit {"session": {...}}, nicht mit "id".
        $sessionId = (int) $res['body']['session']['session_id'];
        $world['sessions'][] = $sessionId;

        $item = oiFind(oiItems(), 'work_session', 'id', $sessionId);
        assertTrue($item !== null && $item['state'] === 'pending', 'Wartende Arbeitszeit fehlt');
        assertSame(135, (int) $item['duration_minutes']);

        assertStatus(200, apiRequest('PUT', 'work_sessions', ['token' => apiToken('admin'),
            'query' => ['id' => $sessionId], 'body' => ['action' => 'reject']]));
        $item = oiFind(oiItems(), 'work_session', 'id', $sessionId);
        assertTrue($item !== null && $item['state'] === 'rejected', 'Abgelehnte Arbeitszeit fehlt');

        // "laufende nein": eine noch laufende Sitzung (end_time IS NULL) darf
        // nicht erscheinen, weder als wartend noch als abgelehnt.
        $start = apiRequest('POST', 'work_sessions', ['token' => apiToken('user'),
            'body' => ['action' => 'start', 'activity_id' => $world['activity']]]);
        assertStatus(201, $start, 'user hat bereits eine laufende Sitzung -- Testvoraussetzung verletzt');
        $runningId = (int) $start['body']['session']['session_id'];
        $world['sessions'][] = $runningId;

        $running = oiFind(oiItems(), 'work_session', 'id', $runningId);
        assertTrue($running === null, 'Laufende Arbeitszeit darf keinen offenen Punkt erzeugen');

        assertStatus(200, apiRequest('POST', 'work_sessions', ['token' => apiToken('user'),
            'body' => ['action' => 'stop']]));
    });
});

test('my_open_items: Arbeitszeit aus -> keine Arbeitszeitpunkte', function () {
    $set = static fn (string $v) => assertStatus(200, apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
        'body' => ['setting_key' => 'worktime_enabled', 'setting_value' => $v]]));
    $set('0');
    try {
        foreach (oiItems()['items'] as $item) {
            assertTrue($item['kind'] !== 'work_session', 'Arbeitszeitpunkt trotz ausgeschalteter Arbeitszeit');
        }
    } finally {
        $set('1');
    }
});

test('my_open_items: Manager sieht keine Punkte des user-Mitglieds', function () {
    oiWithWorld(['responses_enabled' => 1], function (array &$world, int $memberId) {
        $id = oiAppointment($world, oiDate(10));

        $ownItem = oiFind(oiItems('user'), 'response', 'appointment_id', $id);
        assertTrue($ownItem !== null, 'Vorbedingung: user muesste den Punkt selbst sehen');

        if (apiMemberId('manager') === $memberId) {
            return; // gleiches Mitglied waere kein aussagekraeftiger Fall
        }
        $body = oiItems('manager');
        assertSame(null, oiFind($body, 'response', 'appointment_id', $id),
            'Manager bekommt fremde Punkte -- my_open_items ist keine Arbeitsliste');
    });
});

test('my_open_items: POST 405, Geraet 403', function () {
    assertStatus(405, apiRequest('POST', 'my_open_items', ['token' => apiToken('user'), 'body' => []]));

    $res = apiRequest('POST', 'users', ['token' => apiToken('admin'), 'body' => [
        'action' => 'create_device', 'device_name' => 'OI-Geraet ' . uniqid(),
        'device_type' => 'auth_device', 'totp_enabled' => false]]);
    assertStatus(200, $res, 'Testgeraet konnte nicht angelegt werden');
    $deviceId = (int) $res['body']['device']['user_id'];
    try {
        assertStatus(403, apiRequest('GET', 'my_open_items',
            ['token' => (string) $res['body']['device']['api_token']]));
    } finally {
        apiRequest('DELETE', 'users', ['token' => apiToken('admin'), 'query' => ['id' => $deviceId]]);
    }
});

test('my_open_items: Antwortform und Zaehlung', function () {
    $body = oiItems();
    assertSame(true, $body['member']);
    assertTrue(is_array($body['items']));
    assertSame(['open', 'pending', 'rejected'], array_keys($body['counts']));
    $sum = 0;
    foreach ($body['counts'] as $n) {
        $sum += $n;
    }
    assertSame(count($body['items']), $sum);
});

test('my_open_items: ohne Mitglied 200 mit member:false (statisch)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/private/handlers/my_open_items.php');
    $start = strpos($src, '$authMemberId === null');
    assertTrue($start !== false, 'Handler prueft das fehlende Mitglied nicht');
    $branch = substr($src, $start, (int) strpos($src, 'return;', $start) - $start);
    assertTrue(str_contains($branch, "'member' => false"), 'Ohne Mitglied fehlt member:false');
    assertTrue(!str_contains($branch, 'http_response_code'), 'Ohne Mitglied darf kein Fehlerstatus gesetzt werden');
});
