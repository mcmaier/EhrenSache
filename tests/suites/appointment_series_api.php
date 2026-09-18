<?php
/**
 * Terminserien ueber die API (FI-7) und die gemeinsamen Terminregeln.
 *
 * Jeder Test baut sich eine eigene Terminart, damit die Dublettenpruefung
 * (je Terminart im Toleranzfenster) nicht zwischen Tests greift. Alle Daten
 * liegen im Jahr 2031, fern vom Bestand der Testdatenbank.
 *
 * Spec: docs/superpowers/specs/2026-09-18-terminserien-feiertage-design.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** @return array{group: int, type: int} */
function asWorld(): array
{
    $suffix = uniqid();
    $group = apiRequest('POST', 'member_groups', ['token' => apiToken('admin'),
        'body' => ['group_name' => "AS {$suffix}"]]);
    assertStatus(201, $group);
    $type = apiRequest('POST', 'appointment_types', ['token' => apiToken('admin'),
        'body' => ['type_name' => "AS {$suffix}", 'is_default' => 0, 'color' => '#667eea',
                   'group_ids' => [(int) $group['body']['id']]]]);
    assertStatus(201, $type);

    return ['group' => (int) $group['body']['id'], 'type' => (int) $type['body']['id']];
}

/** Alle Termine der Terminart in 2031. */
function asAppointments(array $world): array
{
    $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'),
        'query' => ['type_id' => $world['type'], 'year' => 2031]]);
    assertStatus(200, $res);
    $list = array_values(array_filter($res['body'] ?? [], fn ($a) => (int) $a['type_id'] === $world['type']));
    usort($list, fn ($a, $b) => strcmp($a['date'], $b['date']));

    return $list;
}

/** Raeumt Termine der Terminart, ihre Serien und die Welt ab. */
function asDropWorld(array $world): void
{
    $token = apiToken('admin');
    $seriesIds = [];
    foreach (asAppointments($world) as $apt) {
        if (!empty($apt['series_id'])) {
            $seriesIds[(int) $apt['series_id']] = true;
        }
        apiRequest('DELETE', 'appointments', ['token' => $token, 'query' => ['id' => $apt['appointment_id']]]);
    }
    foreach (array_keys($seriesIds) as $sid) {
        apiRequest('DELETE', 'appointment_series', ['token' => $token, 'query' => ['id' => $sid, 'from' => '2031-01-01']]);
    }
    apiRequest('DELETE', 'appointment_types', ['token' => $token, 'query' => ['id' => $world['type']]]);
    apiRequest('DELETE', 'member_groups', ['token' => $token, 'query' => ['id' => $world['group']]]);
}

function asPostAppointment(array $world, array $extra = []): array
{
    return apiRequest('POST', 'appointments', ['token' => apiToken('admin'), 'body' => array_merge([
        'title' => 'AS-Termin', 'date' => '2031-03-04', 'start_time' => '19:30', 'type_id' => $world['type'],
    ], $extra)]);
}

// ---- Dublettenpruefung (herausgeloest, Antwort unveraendert) -------------------

test('POST: Dublette derselben Terminart liefert 409 mit message, conflict und hint', function () {
    $world = asWorld();
    try {
        assertStatus(201, asPostAppointment($world));
        $dup = asPostAppointment($world, ['title' => 'Zweiter']);
        assertStatus(409, $dup);
        assertSame(['title', 'date', 'time', 'time_diff_seconds'], array_keys($dup['body']['conflict']));
        assertTrue(str_starts_with($dup['body']['message'], 'Ein Termin dieser Art existiert bereits'));
        assertTrue(str_starts_with($dup['body']['hint'], 'Bestehender Termin: "AS-Termin"'));
    } finally {
        asDropWorld($world);
    }
});

test('PUT: Verschieben in eine Dublette liefert 409, der Termin selbst zaehlt nicht', function () {
    $world = asWorld();
    try {
        assertStatus(201, asPostAppointment($world));
        $other = asPostAppointment($world, ['date' => '2031-03-05']);
        assertStatus(201, $other);
        $id = (int) $other['body']['id'];

        $self = apiRequest('PUT', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $id],
            'body' => ['title' => 'Umbenannt', 'start_time' => '19:30']]);
        assertStatus(200, $self, 'Der Termin kollidiert nicht mit sich selbst');

        $dup = apiRequest('PUT', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $id],
            'body' => ['date' => '2031-03-04']]);
        assertStatus(409, $dup);
    } finally {
        asDropWorld($world);
    }
});
