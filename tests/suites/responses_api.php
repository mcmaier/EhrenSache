<?php
/**
 * Terminrueckmeldung ueber die API.
 *
 * Jeder Test baut sich eine eigene Welt aus Gruppe, Terminart und Mitglied,
 * wie tests/suites/punctuality_api.php. Braucht ein Test das Mitglied des
 * Kontos "user", haengt er es fuer die Dauer des Tests zusaetzlich in die
 * Gruppe der Welt und stellt die alten Gruppen im finally wieder her.
 *
 * Termine liegen je Welt an verschiedenen Tagen: Zwei Termine derselben Art
 * im Toleranzfenster lehnt appointments.php als Dublette ab.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function rsCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");
    $id = (int) $res['body']['id'];
    assertTrue($id > 0, "{$resource} lieferte keine brauchbare ID: " . $res['raw']);

    return $id;
}

function rsDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

/** @param array<string, mixed> $typeSettings Felder der Terminart, z. B. responses_enabled */
function rsWorld(string $label, array $typeSettings = []): array
{
    $suffix = uniqid();
    $world  = ['group' => null, 'type' => null, 'member' => null, 'appointments' => []];

    try {
        $world['group'] = rsCreate('member_groups', ['group_name' => "RS {$label} {$suffix}"]);
        $world['type']  = rsCreate('appointment_types', array_merge([
            'type_name'  => "RS {$label} {$suffix}",
            'is_default' => 0,
            'color'      => '#667eea',
            'group_ids'  => [$world['group']],
        ], $typeSettings));
        $world['member'] = rsCreate('members', [
            'name'      => 'Rs',
            'surname'   => "Test {$label} {$suffix}",
            'active'    => 1,
            'group_ids' => [$world['group']],
        ]);
    } catch (Throwable $e) {
        rsDropWorld($world);
        throw $e;
    }

    return $world;
}

function rsAppointment(array &$world, string $date, string $time): int
{
    $id = rsCreate('appointments', [
        'title'      => 'RS-Termin',
        'date'       => $date,
        'start_time' => $time,
        'type_id'    => $world['type'],
    ]);
    $world['appointments'][] = $id;

    return $id;
}

function rsDropWorld(array $world): void
{
    foreach ($world['appointments'] as $appointmentId) {
        rsDelete('appointments', $appointmentId);
    }
    if ($world['member'] !== null) {
        rsDelete('members', $world['member']);
    }
    if ($world['type'] !== null) {
        rsDelete('appointment_types', $world['type']);
    }
    if ($world['group'] !== null) {
        rsDelete('member_groups', $world['group']);
    }
}

function rsDateInDays(int $days): string
{
    return date('Y-m-d', strtotime("{$days} days"));
}

function rsMemberGroupIds(int $memberId): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    assertTrue(isset($res['body']['groups']) && is_array($res['body']['groups']),
        "members lieferte kein groups-Array fuer Mitglied {$memberId}: " . $res['raw']);

    return array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups']);
}

function rsSetMemberGroups(int $memberId, array $groupIds): void
{
    assertStatus(200, apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
        'body'  => ['group_ids' => $groupIds],
    ]), "Gruppen von Mitglied {$memberId} konnten nicht gesetzt werden");
}

/** Haengt das Mitglied des Kontos "user" fuer $fn in die Gruppe der Welt. */
function rsWithUserInWorld(array $world, callable $fn): void
{
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    $original   = rsMemberGroupIds($memberId);
    $bodyError  = null;

    try {
        rsSetMemberGroups($memberId, array_values(array_unique(array_merge($original, [$world['group']]))));
        $fn($memberId);
    } catch (Throwable $e) {
        $bodyError = $e;
    } finally {
        try {
            rsSetMemberGroups($memberId, $original);
        } catch (Throwable $restoreError) {
            if ($bodyError !== null) {
                throw new RuntimeException(
                    'Testkoerper: ' . $bodyError->getMessage()
                    . ' | Wiederherstellung der Gruppen: ' . $restoreError->getMessage()
                );
            }
            throw $restoreError;
        }
    }

    if ($bodyError !== null) {
        throw $bodyError;
    }
}

function rsType(int $typeId): array
{
    $res = apiRequest('GET', 'appointment_types', ['token' => apiToken('admin'), 'query' => ['id' => $typeId]]);
    assertStatus(200, $res);

    return $res['body'];
}

test('appointment_types: neue Terminart traegt die Rueckmeldungs-Einstellungen', function () {
    $welt = rsWorld('Typ', [
        'responses_enabled' => 1, 'responses_names_visible' => 1,
        'responses_require_excuse' => 0, 'response_deadline_hours' => 168,
    ]);

    try {
        $typ = rsType($welt['type']);
        assertSame(1,   (int) $typ['responses_enabled']);
        assertSame(1,   (int) $typ['responses_names_visible']);
        assertSame(0,   (int) $typ['responses_require_excuse']);
        assertSame(168, (int) $typ['response_deadline_hours']);
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_types: PUT ohne die Felder setzt nichts zurueck', function () {
    $welt = rsWorld('TypPut', ['responses_enabled' => 1, 'response_deadline_hours' => 48]);

    try {
        assertStatus(200, apiRequest('PUT', 'appointment_types', [
            'token' => apiToken('admin'),
            'query' => ['id' => $welt['type']],
            'body'  => ['type_name' => 'RS umbenannt ' . uniqid(), 'color' => '#667eea',
                        'is_default' => 0, 'group_ids' => [$welt['group']]],
        ]));

        $typ = rsType($welt['type']);
        assertSame(1,  (int) $typ['responses_enabled']);
        assertSame(48, (int) $typ['response_deadline_hours']);
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_types: leere Frist heisst global, ungueltige wird abgewiesen', function () {
    $welt = rsWorld('TypFrist', ['responses_enabled' => 1, 'response_deadline_hours' => 48]);
    $body = ['type_name' => 'RS Frist ' . uniqid(), 'color' => '#667eea',
             'is_default' => 0, 'group_ids' => [$welt['group']]];

    try {
        $res = apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']], 'body' => $body + ['response_deadline_hours' => 721]]);
        assertStatus(400, $res, '721 Stunden liegen ueber der Grenze');
        assertSame(48, (int) rsType($welt['type'])['response_deadline_hours']);

        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']], 'body' => $body + ['response_deadline_hours' => '']]));
        assertSame(null, rsType($welt['type'])['response_deadline_hours']);
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_types: Frist 0 bleibt 0, responses_enabled schaltet sich ab', function () {
    $welt = rsWorld('TypNull', ['responses_enabled' => 1, 'response_deadline_hours' => 5]);
    $body = ['type_name' => 'RS Null ' . uniqid(), 'color' => '#667eea',
             'is_default' => 0, 'group_ids' => [$welt['group']]];

    try {
        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']], 'body' => $body + ['response_deadline_hours' => 0]]));
        $typ = rsType($welt['type']);
        assertTrue($typ['response_deadline_hours'] !== null, 'Frist 0 darf nicht als null gespeichert werden');
        assertSame(0, (int) $typ['response_deadline_hours']);

        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']], 'body' => $body + ['responses_enabled' => 0]]));
        assertSame(0, (int) rsType($welt['type'])['responses_enabled']);
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_types: PUT auf unbekannte Terminart liefert 404', function () {
    $res = apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
        'query' => ['id' => 999999999],
        'body'  => ['type_name' => 'RS Phantom ' . uniqid(), 'color' => '#667eea',
                    'is_default' => 0, 'group_ids' => []]]);
    assertStatus(404, $res, 'unbekannte Terminart muss 404 liefern');
});

test('settings: Frist ausserhalb 0..720 wird abgewiesen', function () {
    foreach (['721', '-1', 'zwei'] as $wert) {
        $res = apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
            'body' => ['setting_key' => 'response_deadline_hours', 'setting_value' => $wert]]);
        assertStatus(400, $res, "Wert {$wert}");
    }
});
