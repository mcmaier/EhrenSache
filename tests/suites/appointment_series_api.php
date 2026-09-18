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
    // Beenden ab dem Serienbeginn: ohne verbleibende Termine verschwindet die
    // Serie (seriesEndFrom). from muss im Zeitraum der Serie liegen.
    foreach (array_keys($seriesIds) as $sid) {
        $series = apiRequest('GET', 'appointment_series', ['token' => $token, 'query' => ['id' => $sid]]);
        if ($series['status'] !== 200 || empty($series['body']['start_date'])) {
            continue;
        }
        apiRequest('DELETE', 'appointment_series', ['token' => $token,
            'query' => ['id' => $sid, 'from' => $series['body']['start_date']]]);
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

// ---- Serien anlegen ----------------------------------------------------------

function asSeriesBody(array $world, array $extra = []): array
{
    return array_merge([
        'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU', 'start_date' => '2031-03-04', 'until' => '2031-04-01',
        'title' => 'AS-Probe', 'type_id' => $world['type'], 'start_time' => '19:30', 'end_time' => '22:00',
        'location' => 'Probelokal', 'description' => null,
    ], $extra);
}

function asSeriesPost(array $body, array $query = [], string $role = 'admin'): array
{
    return apiRequest('POST', 'appointment_series', ['token' => apiToken($role), 'query' => $query, 'body' => $body]);
}

function asSeriesGet(int $seriesId): array
{
    $res = apiRequest('GET', 'appointment_series', ['token' => apiToken('admin'), 'query' => ['id' => $seriesId]]);
    assertStatus(200, $res);

    return $res['body'];
}

test('Vorschau liefert alle Daten und schreibt nichts', function () {
    $world = asWorld();
    try {
        $res = asSeriesPost(asSeriesBody($world), ['preview' => 1]);
        assertStatus(200, $res);
        assertSame(['2031-03-04', '2031-03-11', '2031-03-18', '2031-03-25', '2031-04-01'],
            array_column($res['body']['occurrences'], 'date'));
        assertSame(5, $res['body']['count']);
        assertSame([], asAppointments($world), 'Die Vorschau darf nichts anlegen');
    } finally {
        asDropWorld($world);
    }
});

test('Vorschau kennzeichnet Feiertage mit Namen', function () {
    $world = asWorld();
    try {
        $res = asSeriesPost(asSeriesBody($world, ['rrule' => 'FREQ=WEEKLY;BYDAY=FR',
            'start_date' => '2031-04-04', 'until' => '2031-04-25']), ['preview' => 1]);
        assertStatus(200, $res);
        $byDate = array_column($res['body']['occurrences'], null, 'date');
        assertSame('Karfreitag', $byDate['2031-04-11']['holiday']);
        assertSame(null, $byDate['2031-04-04']['holiday']);
    } finally {
        asDropWorld($world);
    }
});

test('Anlegen erzeugt Einzeltermine mit Serienbezug und Vorlage', function () {
    $world = asWorld();
    try {
        $res = asSeriesPost(asSeriesBody($world));
        assertStatus(201, $res);
        assertSame(5, $res['body']['created']);
        assertSame([], $res['body']['skipped']);

        $apts = asAppointments($world);
        assertSame(5, count($apts));
        foreach ($apts as $apt) {
            assertSame((int) $res['body']['series_id'], (int) $apt['series_id']);
            assertSame(0, (int) $apt['is_detached']);
            assertSame('AS-Probe', $apt['title']);
            assertSame('22:00:00', $apt['end_time']);
            assertSame('Probelokal', $apt['location']);
        }

        $series = asSeriesGet((int) $res['body']['series_id']);
        assertSame('FREQ=WEEKLY;INTERVAL=1;BYDAY=TU', $series['rrule']);
        assertSame(5, $series['appointment_count']);
        assertSame(0, $series['detached_count']);
    } finally {
        asDropWorld($world);
    }
});

test('Kollision wird in der Vorschau gezeigt, beim Anlegen ausgelassen und in exdates vermerkt', function () {
    $world = asWorld();
    try {
        assertStatus(201, asPostAppointment($world, ['date' => '2031-03-18', 'title' => 'Konzert']));

        $preview = asSeriesPost(asSeriesBody($world), ['preview' => 1]);
        $byDate = array_column($preview['body']['occurrences'], null, 'date');
        assertSame('Konzert', $byDate['2031-03-18']['conflict']['title'] ?? null);
        assertSame(4, $preview['body']['count']);

        $res = asSeriesPost(asSeriesBody($world));
        assertStatus(201, $res);
        assertSame(4, $res['body']['created']);
        assertSame(['2031-03-18'], array_column($res['body']['skipped'], 'date'));
        assertSame(['2031-03-18'], asSeriesGet((int) $res['body']['series_id'])['exdates']);
    } finally {
        asDropWorld($world);
    }
});

test('Abgewaehlte Daten (exdates) entstehen nicht', function () {
    $world = asWorld();
    try {
        $res = asSeriesPost(asSeriesBody($world, ['exdates' => ['2031-03-11']]));
        assertStatus(201, $res);
        assertSame(4, $res['body']['created']);
        assertTrue(!in_array('2031-03-11', array_column(asAppointments($world), 'date'), true));
    } finally {
        asDropWorld($world);
    }
});

test('Anlegen: ungueltige Definitionen liefern 400 und schreiben nichts', function () {
    $world = asWorld();
    try {
        $cases = [
            'Regel'          => ['rrule' => 'FREQ=DAILY'],
            'Ende vor Beginn'=> ['until' => '2031-03-01'],
            'ueber 12 Monate'=> ['until' => '2032-03-05'],
            'kein Titel'     => ['title' => '  '],
            'Startzeit'      => ['start_time' => '25:00'],
            'Ende = Beginn'  => ['end_time' => '19:30'],
            'exdates'        => ['exdates' => ['2031-02-30']],
            'kein Termin'    => ['rrule' => 'FREQ=MONTHLY;BYDAY=-1WE', 'until' => '2031-03-10'],
            'Terminart'      => ['type_id' => 999999],
        ];
        foreach ($cases as $name => $extra) {
            assertStatus(400, asSeriesPost(asSeriesBody($world, $extra)), $name);
        }
        assertSame([], asAppointments($world));
    } finally {
        asDropWorld($world);
    }
});

test('Serien sind Admin und Manager vorbehalten', function () {
    $world = asWorld();
    try {
        assertStatus(403, asSeriesPost(asSeriesBody($world), ['preview' => 1], 'user'));
        assertStatus(200, asSeriesPost(asSeriesBody($world), ['preview' => 1], 'manager'));
    } finally {
        asDropWorld($world);
    }
});

test('Unbekannte Serie liefert 404', function () {
    $res = apiRequest('GET', 'appointment_series', ['token' => apiToken('admin'), 'query' => ['id' => 99999999]]);
    assertStatus(404, $res);
    assertSame('Serie nicht gefunden', $res['body']['message'] ?? null,
        'Die Serie fehlt, nicht die Ressource');
});
