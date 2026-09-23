<?php
/**
 * Anwesenheitszahlen je Termin an GET appointments (Kalender, Schritt 2a).
 *
 * Spec: docs/superpowers/specs/2026-09-22-kalender-anwesenheit-design.md
 *
 * Jede Welt baut eigene Gruppe, Terminart und Mitglied -- die Zahlen haengen
 * an der Gruppenzuordnung, fremde Mitglieder wuerden sie verfaelschen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function caCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");

    return (int) $res['body']['id'];
}

function caDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

/** @return array{group:int,type:int,members:int[],appointments:int[]} */
function caWorld(string $label, int $memberCount = 3): array
{
    $suffix = uniqid();
    $world = ['group' => null, 'type' => null, 'members' => [], 'appointments' => []];

    try {
        $world['group'] = caCreate('member_groups', ['group_name' => "CA {$label} {$suffix}"]);
        $world['type']  = caCreate('appointment_types', [
            'type_name' => "CA {$label} {$suffix}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => [$world['group']],
        ]);
        for ($i = 1; $i <= $memberCount; $i++) {
            $world['members'][] = caCreate('members', [
                'name' => 'Ca', 'surname' => "Test {$label} {$i} {$suffix}",
                'active' => 1, 'group_ids' => [$world['group']],
            ]);
        }
    } catch (Throwable $e) {
        caDropWorld($world);
        throw $e;
    }

    return $world;
}

function caDropWorld(array $world): void
{
    foreach ($world['appointments'] as $id) {
        caDelete('appointments', $id);
    }
    foreach ($world['members'] as $id) {
        caDelete('members', $id);
    }
    if ($world['type'] !== null) {
        caDelete('appointment_types', $world['type']);
    }
    if ($world['group'] !== null) {
        caDelete('member_groups', $world['group']);
    }
}

function caDateInDays(int $days): string
{
    return date('Y-m-d', strtotime("{$days} days"));
}

function caAppointment(array &$world, string $date, string $time = '19:00:00'): int
{
    $id = caCreate('appointments', ['title' => 'CA-Termin', 'date' => $date,
                                    'start_time' => $time, 'type_id' => $world['type']]);
    $world['appointments'][] = $id;

    return $id;
}

function caRecord(int $appointmentId, int $memberId, string $status): void
{
    $res = apiRequest('POST', 'records', ['token' => apiToken('admin'),
        'body' => ['member_id' => $memberId, 'appointment_id' => $appointmentId, 'status' => $status]]);
    assertStatus(201, $res);
}

/** Termin aus GET appointments des Jahres, mit den angeforderten Zusaetzen. */
function caFetch(int $appointmentId, string $date, string $role = 'admin', array $extraQuery = []): ?array
{
    $res = apiRequest('GET', 'appointments', ['token' => apiToken($role),
        'query' => array_merge(['year' => (int) substr($date, 0, 4)], $extraQuery)]);
    assertStatus(200, $res);
    foreach ($res['body'] as $apt) {
        if ((int) $apt['appointment_id'] === $appointmentId) {
            return $apt;
        }
    }

    return null;
}

// ---- Zahlen fuer Verwalter ----------------------------------------------------------

test('include=attendance zaehlt anwesend, entschuldigt und fehlend', function () {
    $world = caWorld('Zahlen');
    try {
        $tag = caDateInDays(-2);
        $apt = caAppointment($world, $tag);
        caRecord($apt, $world['members'][0], 'present');
        caRecord($apt, $world['members'][1], 'excused');

        $row = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertTrue($row !== null, 'Termin fehlt in der Liste');
        assertSame(['expected' => 3, 'present' => 1, 'excused' => 1, 'missing' => 1], $row['attendance']);
    } finally {
        caDropWorld($world);
    }
});

test('Kuenftige Termine tragen keine Zahlen', function () {
    $world = caWorld('Zukunft');
    try {
        $tag = caDateInDays(3);
        $apt = caAppointment($world, $tag);

        $row = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertSame(null, $row['attendance']);
    } finally {
        caDropWorld($world);
    }
});

test('Ohne include bleibt die Antwort unveraendert', function () {
    $world = caWorld('Ohne');
    try {
        $tag = caDateInDays(-1);
        $apt = caAppointment($world, $tag);

        $row = caFetch($apt, $tag, 'admin');
        assertTrue(!array_key_exists('attendance', $row), 'attendance darf ohne include nicht erscheinen');
        assertTrue(!array_key_exists('own_attendance', $row), 'own_attendance darf ohne include nicht erscheinen');
    } finally {
        caDropWorld($world);
    }
});

test('Ohne Zeitraum keine Zahlen (Kostengrenze wie bei den Rueckmeldungen)', function () {
    $world = caWorld('Zeitraum');
    try {
        $tag = caDateInDays(-1);
        $apt = caAppointment($world, $tag);

        $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'),
            'query' => ['include' => 'attendance']]);
        assertStatus(200, $res);
        foreach ($res['body'] as $row) {
            if ((int) $row['appointment_id'] === $apt) {
                assertTrue(!array_key_exists('attendance', $row),
                    'Ohne Jahres- oder Datumsfilter werden keine Zahlen angehaengt');
            }
        }
    } finally {
        caDropWorld($world);
    }
});
