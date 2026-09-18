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

test('Anlegen: Felder falschen Typs liefern 400 mit sauberem JSON', function () {
    $world = asWorld();
    try {
        foreach (['start_date' => ['2031-03-04'], 'until' => ['2031-04-01']] as $field => $value) {
            $res = asSeriesPost(asSeriesBody($world, [$field => $value]), ['preview' => 1]);
            assertStatus(400, $res, $field);
            assertTrue(is_array($res['body']) && isset($res['body']['message']),
                "{$field}: Antwort muss gueltiges JSON mit message sein, war: " . substr($res['raw'], 0, 200));
        }

        $body = asSeriesBody($world);
        unset($body['rrule']);
        $res = asSeriesPost($body, ['preview' => 1]);
        assertStatus(400, $res);
        assertSame('Die Regel fehlt', $res['body']['message'] ?? null);

        $res = asSeriesPost(asSeriesBody($world, ['rrule' => ['FREQ=WEEKLY']]), ['preview' => 1]);
        assertStatus(400, $res);
        assertSame('Die Regel fehlt', $res['body']['message'] ?? null);
    } finally {
        asDropWorld($world);
    }
});

test('Anlegen: Terminart als Kommazahl liefert 400', function () {
    $world = asWorld();
    try {
        // Der ganzzahlige Anteil ist eine vorhandene Terminart -- darf nicht still abgeschnitten werden.
        $res = asSeriesPost(asSeriesBody($world, ['type_id' => $world['type'] + 0.7]));
        assertStatus(400, $res);
        assertSame('Ungültige Terminart', $res['body']['message'] ?? null);
        assertSame([], asAppointments($world));
    } finally {
        asDropWorld($world);
    }
});

test('Vorschau ist sicher: jeder Wert ausser 0 schreibt nichts', function () {
    $world = asWorld();
    try {
        foreach (['true', 'yes', '2'] as $value) {
            $res = asSeriesPost(asSeriesBody($world), ['preview' => $value]);
            assertStatus(200, $res, "preview={$value}");
            assertSame(5, $res['body']['count'] ?? null, "preview={$value}");
        }
        assertSame([], asAppointments($world), 'Die Vorschau darf nichts anlegen');

        assertStatus(201, asSeriesPost(asSeriesBody($world), ['preview' => '0']));
        assertSame(5, count(asAppointments($world)));
    } finally {
        asDropWorld($world);
    }
});

test('Anlegen: kollidieren alle Daten, gibt es 409 und keine Serie', function () {
    $world = asWorld();
    try {
        foreach (['2031-03-04', '2031-03-11', '2031-03-18', '2031-03-25', '2031-04-01'] as $date) {
            assertStatus(201, asPostAppointment($world, ['date' => $date, 'title' => 'Belegt']));
        }

        $res = asSeriesPost(asSeriesBody($world));
        assertStatus(409, $res);
        assertSame(5, count($res['body']['skipped'] ?? []));

        $apts = asAppointments($world);
        assertSame(5, count($apts));
        foreach ($apts as $apt) {
            assertSame(null, $apt['series_id'], 'Kein Termin darf einer Serie zugeordnet sein');
        }
    } finally {
        asDropWorld($world);
    }
});

test('Anlegen: Ende genau an der 12-Monats-Grenze ist erlaubt, ein Tag mehr nicht', function () {
    $world = asWorld();
    try {
        assertStatus(200, asSeriesPost(asSeriesBody($world, ['until' => '2032-03-04']), ['preview' => 1]));
        assertStatus(400, asSeriesPost(asSeriesBody($world, ['until' => '2032-03-05']), ['preview' => 1]));
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

// ---- Einzeltermine und Beenden -----------------------------------------------

/** Legt die Standardserie (5 Dienstage 04.03.–01.04.2031) an und liefert ihre ID. */
function asCreateSeries(array $world, array $extra = []): int
{
    $res = asSeriesPost(asSeriesBody($world, $extra));
    assertStatus(201, $res);

    return (int) $res['body']['series_id'];
}

function asAptOn(array $world, string $date): array
{
    foreach (asAppointments($world) as $apt) {
        if ($apt['date'] === $date) {
            return $apt;
        }
    }
    throw new RuntimeException("Kein Termin am {$date}");
}

/** Haengt eine Anwesenheit an den Termin -- danach gilt er als "mit Daten". */
function asAddRecord(int $appointmentId): void
{
    $members = apiRequest('GET', 'members', ['token' => apiToken('admin')]);
    assertStatus(200, $members);
    $res = apiRequest('POST', 'records', ['token' => apiToken('admin'),
        'body' => ['member_id' => (int) $members['body'][0]['member_id'], 'appointment_id' => $appointmentId]]);
    assertStatus(201, $res);
}

test('Einzel-PUT auf einen Serientermin loest ihn ab', function () {
    $world = asWorld();
    try {
        asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-11');
        $res = apiRequest('PUT', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => $apt['appointment_id']], 'body' => ['title' => 'Sonderprobe']]);
        assertStatus(200, $res);
        assertSame(1, (int) asAptOn($world, '2031-03-11')['is_detached']);
        assertSame(0, (int) asAptOn($world, '2031-03-18')['is_detached']);
    } finally {
        asDropWorld($world);
    }
});

test('Einzel-DELETE vermerkt das Datum in exdates, die Serie bleibt', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-18');
        assertStatus(200, apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => $apt['appointment_id']]]));
        $series = asSeriesGet($sid);
        assertSame(['2031-03-18'], $series['exdates']);
        assertSame(4, $series['appointment_count']);
    } finally {
        asDropWorld($world);
    }
});

test('Beenden ab Datum loescht folgende ohne Daten und loest die mit Daten ab', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        asAddRecord((int) asAptOn($world, '2031-03-25')['appointment_id']);

        $res = apiRequest('DELETE', 'appointment_series', ['token' => apiToken('admin'),
            'query' => ['id' => $sid, 'from' => '2031-03-18']]);
        assertStatus(200, $res);
        assertSame(2, $res['body']['removed'], '18.03. und 01.04. ohne Daten');
        assertSame(['2031-03-25'], array_column($res['body']['detached'], 'date'));
        assertSame(false, $res['body']['series_deleted']);

        assertSame(['2031-03-04', '2031-03-11', '2031-03-25'], array_column(asAppointments($world), 'date'));
        assertSame(1, (int) asAptOn($world, '2031-03-25')['is_detached']);
        assertSame('2031-03-17', asSeriesGet($sid)['until']);
    } finally {
        asDropWorld($world);
    }
});

test('Beenden ab dem ersten Termin ohne Daten entfernt auch die Serie', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $res = apiRequest('DELETE', 'appointment_series', ['token' => apiToken('admin'),
            'query' => ['id' => $sid, 'from' => '2031-03-04']]);
        assertStatus(200, $res);
        assertSame(5, $res['body']['removed']);
        assertSame(true, $res['body']['series_deleted']);
        assertStatus(404, apiRequest('GET', 'appointment_series', ['token' => apiToken('admin'), 'query' => ['id' => $sid]]));
    } finally {
        asDropWorld($world);
    }
});

test('Beenden ab dem Serienbeginn mit Daten: Serie entfaellt, Termin mit Daten bleibt als Einzeltermin', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        asAddRecord((int) asAptOn($world, '2031-03-04')['appointment_id']);
        $res = apiRequest('DELETE', 'appointment_series', ['token' => apiToken('admin'),
            'query' => ['id' => $sid, 'from' => '2031-03-04']]);
        assertStatus(200, $res);
        assertSame(4, $res['body']['removed']);
        assertSame(true, $res['body']['series_deleted']);
        $apt = asAptOn($world, '2031-03-04');
        assertSame(null, $apt['series_id']);
        assertSame(0, (int) $apt['is_detached']);
    } finally {
        asDropWorld($world);
    }
});

test('Beenden: Datum ausserhalb der Serie liefert 400', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        foreach (['2031-03-03', '2031-04-02', 'morgen'] as $from) {
            assertStatus(400, apiRequest('DELETE', 'appointment_series', ['token' => apiToken('admin'),
                'query' => ['id' => $sid, 'from' => $from]]), $from);
        }
    } finally {
        asDropWorld($world);
    }
});

// ---- Abloesen nur bei echter Aenderung (Review-Nachtrag) ---------------------

test('PUT ohne Felder auf einen Serientermin loest ihn nicht ab', function () {
    $world = asWorld();
    try {
        asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-11');
        $res = apiRequest('PUT', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => $apt['appointment_id']], 'body' => []]);
        assertStatus(200, $res);
        assertSame(0, (int) asAptOn($world, '2031-03-11')['is_detached']);
    } finally {
        asDropWorld($world);
    }
});

test('PUT mit unveraenderten Werten (Zeit nur anders geschrieben) loest nicht ab', function () {
    $world = asWorld();
    try {
        asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-11');
        $res = apiRequest('PUT', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => $apt['appointment_id']], 'body' => [
                'title' => 'AS-Probe', 'type_id' => $world['type'], 'description' => null,
                'date' => '2031-03-11', 'start_time' => '19:30', 'end_time' => '22:00',
                'location' => 'Probelokal',
            ]]);
        assertStatus(200, $res);
        assertSame(0, (int) asAptOn($world, '2031-03-11')['is_detached'],
            '19:30 gegen gespeichertes 19:30:00 ist keine Aenderung');
    } finally {
        asDropWorld($world);
    }
});

test('PUT auf einen gewoehnlichen Termin bleibt ohne Serienbezug und is_detached', function () {
    $world = asWorld();
    try {
        $post = asPostAppointment($world);
        assertStatus(201, $post);
        assertStatus(200, apiRequest('PUT', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => (int) $post['body']['id']], 'body' => ['title' => 'Geaendert']]));
        $apt = asAptOn($world, '2031-03-04');
        assertSame(null, $apt['series_id']);
        assertSame(0, (int) $apt['is_detached']);
    } finally {
        asDropWorld($world);
    }
});

test('PUT mit 409 (Verschieben in eine Dublette) loest nicht ab', function () {
    $world = asWorld();
    try {
        asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-11');
        $res = apiRequest('PUT', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => $apt['appointment_id']], 'body' => ['date' => '2031-03-04']]);
        assertStatus(409, $res);
        assertSame(0, (int) asAptOn($world, '2031-03-11')['is_detached']);
    } finally {
        asDropWorld($world);
    }
});

test('Werden alle Termine einzeln geloescht, bleibt die Serie fuer "fortsetzen" bestehen', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $start = asSeriesGet($sid)['start_date'];
        foreach (asAppointments($world) as $apt) {
            assertStatus(200, apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'),
                'query' => ['id' => $apt['appointment_id']]]));
        }
        $series = asSeriesGet($sid);
        assertSame(0, $series['appointment_count']);
        assertSame(5, count($series['exdates']));

        // Ohne verbleibenden Termin findet asDropWorld die Serie nicht mehr --
        // dasselbe Beenden-ab-Serienbeginn wie dort selbst abraeumen.
        apiRequest('DELETE', 'appointment_series', ['token' => apiToken('admin'),
            'query' => ['id' => $sid, 'from' => $start]]);
    } finally {
        asDropWorld($world);
    }
});

test('Beenden ab dem 2. Termin nach Einzel-DELETE des 1. entfernt die Serie vollstaendig', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $first = asAptOn($world, '2031-03-04');
        assertStatus(200, apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => $first['appointment_id']]]));

        $res = apiRequest('DELETE', 'appointment_series', ['token' => apiToken('admin'),
            'query' => ['id' => $sid, 'from' => '2031-03-11']]);
        assertStatus(200, $res);
        assertSame(4, $res['body']['removed']);
        assertSame(true, $res['body']['series_deleted']);
    } finally {
        asDropWorld($world);
    }
});

test('Ein bereits abgeloester Termin nach dem Beenden-Datum bleibt unberuehrt', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $detachTarget = asAptOn($world, '2031-03-18');
        assertStatus(200, apiRequest('PUT', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => $detachTarget['appointment_id']], 'body' => ['title' => 'Sonderprobe']]));
        assertSame(1, (int) asAptOn($world, '2031-03-18')['is_detached']);

        $res = apiRequest('DELETE', 'appointment_series', ['token' => apiToken('admin'),
            'query' => ['id' => $sid, 'from' => '2031-03-11']]);
        assertStatus(200, $res);
        assertSame(3, $res['body']['removed'], '11.03., 25.03. und 01.04. ohne Daten -- 18.03. ist abgeloest');

        $stillThere = asAptOn($world, '2031-03-18');
        assertSame($sid, (int) $stillThere['series_id']);
        assertSame(1, (int) $stillThere['is_detached']);
    } finally {
        asDropWorld($world);
    }
});

test('Beenden: from als Array erzeugt keine PHP-Warnung und liefert 400', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $res = apiRequest('DELETE', 'appointment_series', ['token' => apiToken('admin'),
            'query' => ['id' => $sid, 'from[]' => '2031-03-04']]);
        assertStatus(400, $res);
    } finally {
        asDropWorld($world);
    }
});

// ---- Dieser und alle folgenden (PUT) -----------------------------------------

function asSeriesPut(int $seriesId, array $body): array
{
    return apiRequest('PUT', 'appointment_series', ['token' => apiToken('admin'),
        'query' => ['id' => $seriesId], 'body' => $body]);
}

test('PUT aendert die folgenden Termine an Ort und Stelle, IDs bleiben', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $before = array_column(asAppointments($world), 'appointment_id', 'date');

        $res = asSeriesPut($sid, ['from_date' => '2031-03-18', 'location' => 'Aula', 'start_time' => '19:00']);
        assertStatus(200, $res);
        assertSame(3, $res['body']['updated']);
        assertSame([], $res['body']['detached']);

        $after = asAppointments($world);
        assertSame($before, array_column($after, 'appointment_id', 'date'), 'IDs unveraendert');
        foreach ($after as $apt) {
            $changed = $apt['date'] >= '2031-03-18';
            assertSame($changed ? 'Aula' : 'Probelokal', $apt['location'], $apt['date']);
            assertSame($changed ? '19:00:00' : '19:30:00', $apt['start_time'], $apt['date']);
        }
        $series = asSeriesGet($sid);
        assertSame('Aula', $series['location'], 'Die Vorlage folgt');
        assertSame('19:00:00', $series['start_time']);
    } finally {
        asDropWorld($world);
    }
});

test('PUT laesst abgeloeste Termine aus', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-25');
        assertStatus(200, apiRequest('PUT', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => $apt['appointment_id']], 'body' => ['title' => 'Sonderprobe']]));

        $res = asSeriesPut($sid, ['from_date' => '2031-03-04', 'title' => 'Gesamtprobe']);
        assertStatus(200, $res);
        assertSame(4, $res['body']['updated']);
        assertSame('Sonderprobe', asAptOn($world, '2031-03-25')['title']);
        assertSame('Gesamtprobe', asAptOn($world, '2031-04-01')['title']);
    } finally {
        asDropWorld($world);
    }
});

test('PUT: ein Termin, der dadurch kollidieren wuerde, bleibt unveraendert und wird abgeloest', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        assertStatus(201, asPostAppointment($world, ['date' => '2031-03-25', 'start_time' => '08:00', 'title' => 'Konzert']));

        $res = asSeriesPut($sid, ['from_date' => '2031-03-18', 'start_time' => '08:00']);
        assertStatus(200, $res);
        assertSame(2, $res['body']['updated']);
        assertSame(['2031-03-25'], array_column($res['body']['detached'], 'date'));

        $kept = null;
        foreach (asAppointments($world) as $a) {
            if ($a['date'] === '2031-03-25' && $a['title'] === 'AS-Probe') {
                $kept = $a;
            }
        }
        assertTrue($kept !== null);
        assertSame('19:30:00', $kept['start_time']);
        assertSame(1, (int) $kept['is_detached']);
    } finally {
        asDropWorld($world);
    }
});

test('PUT prueft Vorlage und Datum', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        assertStatus(400, asSeriesPut($sid, ['from_date' => '2031-02-01', 'title' => 'X']), 'Datum vor der Serie');
        assertStatus(400, asSeriesPut($sid, ['from_date' => '2031-03-11', 'title' => '']), 'leerer Titel');
        assertStatus(400, asSeriesPut($sid, ['from_date' => '2031-03-11', 'end_time' => '19:30']), 'Ende = Beginn');
        $none = asSeriesPut($sid, ['from_date' => '2031-03-11']);
        assertStatus(200, $none);
        assertSame(0, $none['body']['updated']);
    } finally {
        asDropWorld($world);
    }
});
