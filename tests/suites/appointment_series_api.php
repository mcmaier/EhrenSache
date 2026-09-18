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
        assertSame([false, false, false, false, false], array_column($res['body']['occurrences'], 'locked'),
            'Beim Anlegen ist nichts gesperrt');
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

// ---- PUT je Termin: effektive Werte, nicht nur die Vorlage --------------------

test('PUT nach vorherigem Teil-PUT: Ende=Beginn nur fuer die betroffenen Termine, andere bleiben gueltig', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        assertStatus(200, asSeriesPut($sid, ['from_date' => '2031-03-25', 'start_time' => '18:00', 'end_time' => '19:30']));

        $res = asSeriesPut($sid, ['from_date' => '2031-03-04', 'end_time' => '19:30']);
        assertStatus(200, $res);
        assertSame(2, $res['body']['updated']);

        $detachedDates = array_column($res['body']['detached'], 'date');
        sort($detachedDates);
        assertSame(['2031-03-04', '2031-03-11', '2031-03-18'], $detachedDates);
        foreach ($res['body']['detached'] as $d) {
            assertSame('invalid_time', $d['reason'], $d['date']);
        }

        foreach (['2031-03-04', '2031-03-11', '2031-03-18'] as $date) {
            $apt = asAptOn($world, $date);
            assertSame('19:30:00', $apt['start_time'], $date);
            assertSame('22:00:00', $apt['end_time'], $date . ' unveraendert, da abgeloest');
            assertSame(1, (int) $apt['is_detached'], $date);
        }
        foreach (['2031-03-25', '2031-04-01'] as $date) {
            $apt = asAptOn($world, $date);
            assertSame('18:00:00', $apt['start_time'], $date);
            assertSame('19:30:00', $apt['end_time'], $date);
            assertSame(0, (int) $apt['is_detached'], $date);
        }
    } finally {
        asDropWorld($world);
    }
});

test('PUT: Terminart-Wechsel, der kollidieren wuerde, loest nur den betroffenen Termin ab', function () {
    $world = asWorld();
    $otherWorld = asWorld();
    try {
        $sid = asCreateSeries($world);
        $fremd = apiRequest('POST', 'appointments', ['token' => apiToken('admin'), 'body' => [
            'title' => 'Fremdtermin', 'date' => '2031-03-18', 'start_time' => '19:30', 'type_id' => $otherWorld['type'],
        ]]);
        assertStatus(201, $fremd);

        $res = asSeriesPut($sid, ['from_date' => '2031-03-04', 'type_id' => $otherWorld['type']]);
        assertStatus(200, $res);
        assertSame(4, $res['body']['updated']);
        assertSame(['2031-03-18'], array_column($res['body']['detached'], 'date'));
        assertSame('conflict', $res['body']['detached'][0]['reason']);
        assertSame((int) $fremd['body']['id'], $res['body']['detached'][0]['conflict']['appointment_id']);
        assertSame('Fremdtermin', $res['body']['detached'][0]['conflict']['title']);

        $kept = asAptOn($world, '2031-03-18');
        assertSame($world['type'], (int) $kept['type_id'], 'Bleibt bei der alten Terminart');
        assertSame(1, (int) $kept['is_detached']);

        // Nach dem Terminart-Wechsel zaehlen diese Termine zur Welt der neuen
        // Terminart -- asAppointments() filtert je Welt auf ihre eigene type_id.
        foreach (['2031-03-04', '2031-03-11', '2031-03-25', '2031-04-01'] as $date) {
            $apt = asAptOn($otherWorld, $date);
            assertSame($otherWorld['type'], (int) $apt['type_id'], $date);
        }
    } finally {
        asDropWorld($world);
        asDropWorld($otherWorld);
    }
});

test('PUT: eine nicht existierende Terminart liefert 400 und aendert nichts', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $before = asAppointments($world);

        $res = asSeriesPut($sid, ['from_date' => '2031-03-04', 'type_id' => 999999]);
        assertStatus(400, $res);
        assertSame($before, asAppointments($world), 'Nichts geaendert');
    } finally {
        asDropWorld($world);
    }
});

test('PUT: ein Termin mit erfassten Daten wird bei Zeit-/Artaenderung nicht geaendert, sondern abgeloest', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $withData = asAptOn($world, '2031-03-11');
        asAddRecord((int) $withData['appointment_id']);

        $res = asSeriesPut($sid, ['from_date' => '2031-03-04', 'start_time' => '19:00', 'title' => 'Neu']);
        assertStatus(200, $res);
        assertSame(4, $res['body']['updated']);
        assertSame(['2031-03-11'], array_column($res['body']['detached'], 'date'));
        assertSame('has_data', $res['body']['detached'][0]['reason']);

        $unchanged = asAptOn($world, '2031-03-11');
        assertSame('19:30:00', $unchanged['start_time'], 'Nicht geaendert, da abgeloest');
        assertSame('AS-Probe', $unchanged['title'], 'Auch der Titel bleibt, da komplett unangetastet');
        assertSame(1, (int) $unchanged['is_detached']);

        foreach (['2031-03-04', '2031-03-18', '2031-03-25', '2031-04-01'] as $date) {
            $apt = asAptOn($world, $date);
            assertSame('19:00:00', $apt['start_time'], $date);
            assertSame('Neu', $apt['title'], $date);
        }
    } finally {
        asDropWorld($world);
    }
});

test('PUT: ein Titel-Wechsel allein aendert einen Termin mit Daten trotzdem', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $withData = asAptOn($world, '2031-03-11');
        asAddRecord((int) $withData['appointment_id']);

        $res = asSeriesPut($sid, ['from_date' => '2031-03-04', 'title' => 'Umbenannt']);
        assertStatus(200, $res);
        assertSame(5, $res['body']['updated']);
        assertSame([], $res['body']['detached']);
        assertSame('Umbenannt', asAptOn($world, '2031-03-11')['title']);
    } finally {
        asDropWorld($world);
    }
});

test('PUT: from_date als Array erzeugt keine PHP-Warnung und liefert 400', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $res = apiRequest('PUT', 'appointment_series', ['token' => apiToken('admin'),
            'query' => ['id' => $sid], 'body' => ['from_date' => ['2031-03-04'], 'title' => 'X']]);
        assertStatus(400, $res);
    } finally {
        asDropWorld($world);
    }
});

// ---- PUT: nur erfasste Anwesenheit haelt Beginn/Terminart fest ----------------

/** Wie asWorld(), aber die Terminart erlaubt Rueckmeldungen und ein Mitglied kann sie geben. */
function asWorldWithMember(array $typeSettings = []): array
{
    $suffix = uniqid();
    $group = apiRequest('POST', 'member_groups', ['token' => apiToken('admin'),
        'body' => ['group_name' => "ASR {$suffix}"]]);
    assertStatus(201, $group);
    $type = apiRequest('POST', 'appointment_types', ['token' => apiToken('admin'), 'body' => array_merge([
        'type_name' => "ASR {$suffix}", 'is_default' => 0, 'color' => '#667eea',
        'group_ids' => [(int) $group['body']['id']],
    ], $typeSettings)]);
    assertStatus(201, $type);
    $member = apiRequest('POST', 'members', ['token' => apiToken('admin'), 'body' => [
        'name' => 'AS', 'surname' => "Ruem {$suffix}", 'active' => 1, 'group_ids' => [(int) $group['body']['id']],
    ]]);
    assertStatus(201, $member);

    return ['group' => (int) $group['body']['id'], 'type' => (int) $type['body']['id'],
            'member' => (int) $member['body']['id']];
}

function asDropWorldWithMember(array $world): void
{
    apiRequest('DELETE', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $world['member']]]);
    asDropWorld($world);
}

test('PUT: eine Rueckmeldung allein haelt den Beginn nicht fest -- die Serie bewegt sich weiter', function () {
    $world = asWorldWithMember(['responses_enabled' => 1]);
    try {
        $sid = asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-11');
        assertStatus(200, apiRequest('PUT', 'appointment_responses', ['token' => apiToken('admin'),
            'query' => ['appointment_id' => $apt['appointment_id'], 'member_id' => $world['member']],
            'body' => ['status' => 'yes']]));

        $res = asSeriesPut($sid, ['from_date' => '2031-03-04', 'start_time' => '19:00']);
        assertStatus(200, $res);
        assertSame(5, $res['body']['updated']);
        assertSame([], $res['body']['detached']);
        assertSame('19:00:00', asAptOn($world, '2031-03-11')['start_time']);
    } finally {
        asDropWorldWithMember($world);
    }
});

test('PUT: eine erfasste Anwesenheit haelt eine reine Ende-Aenderung nicht ab', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-11');
        asAddRecord((int) $apt['appointment_id']);

        $res = asSeriesPut($sid, ['from_date' => '2031-03-04', 'end_time' => '21:00']);
        assertStatus(200, $res);
        assertSame(5, $res['body']['updated']);
        assertSame([], $res['body']['detached']);
        assertSame('21:00:00', asAptOn($world, '2031-03-11')['end_time']);
    } finally {
        asDropWorld($world);
    }
});

test('PUT: eine erfasste Anwesenheit haelt die Terminart fest', function () {
    $world = asWorld();
    $otherWorld = asWorld();
    try {
        $sid = asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-11');
        asAddRecord((int) $apt['appointment_id']);

        $res = asSeriesPut($sid, ['from_date' => '2031-03-04', 'type_id' => $otherWorld['type']]);
        assertStatus(200, $res);
        assertSame(4, $res['body']['updated']);
        assertSame(['2031-03-11'], array_column($res['body']['detached'], 'date'));
        assertSame('has_data', $res['body']['detached'][0]['reason']);

        $kept = asAptOn($world, '2031-03-11');
        assertSame($world['type'], (int) $kept['type_id'], 'Bleibt bei der alten Terminart');
        assertSame(1, (int) $kept['is_detached']);
    } finally {
        asDropWorld($world);
        asDropWorld($otherWorld);
    }
});

// ---- Split und Fortsetzen ----------------------------------------------------

test('Split: alte Serie endet am Vortag, neue Serie ab dem Datum, Termine mit Daten bleiben abgeloest', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        asAddRecord((int) asAptOn($world, '2031-03-25')['appointment_id']);

        $body = asSeriesBody($world, ['rrule' => 'FREQ=WEEKLY;BYDAY=TH', 'from_date' => '2031-03-18', 'until' => '2031-04-03']);
        unset($body['start_date']);

        $preview = asSeriesPost($body, ['id' => $sid, 'action' => 'split', 'preview' => 1]);
        assertStatus(200, $preview);
        assertSame(['2031-03-20', '2031-03-27', '2031-04-03'], array_column($preview['body']['occurrences'], 'date'));
        assertSame(2, $preview['body']['removes']);
        assertSame(1, $preview['body']['keeps'], 'der 25.03. mit Anwesenheit bleibt abgeloest stehen');
        assertSame(5, count(asAppointments($world)), 'Vorschau schreibt nichts');

        $res = asSeriesPost($body, ['id' => $sid, 'action' => 'split']);
        assertStatus(201, $res);
        assertSame(3, $res['body']['created']);
        assertSame(2, $res['body']['removed']);
        assertSame(['2031-03-25'], array_column($res['body']['detached'], 'date'));
        assertSame(false, $res['body']['series_deleted']);

        assertSame(['2031-03-04', '2031-03-11', '2031-03-20', '2031-03-25', '2031-03-27', '2031-04-03'],
            array_column(asAppointments($world), 'date'));
        assertSame('2031-03-17', asSeriesGet($sid)['until']);
        $new = asSeriesGet((int) $res['body']['series_id']);
        assertSame('2031-03-18', $new['start_date']);
        assertSame('FREQ=WEEKLY;INTERVAL=1;BYDAY=TH', $new['rrule']);
    } finally {
        asDropWorld($world);
    }
});

test('Split ab dem Serienbeginn: alte Serie entfaellt ganz, Termin mit Daten wird Einzeltermin', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        asAddRecord((int) asAptOn($world, '2031-03-11')['appointment_id']);

        $body = asSeriesBody($world, ['rrule' => 'FREQ=WEEKLY;BYDAY=TH', 'from_date' => '2031-03-04', 'until' => '2031-03-27']);
        unset($body['start_date']);

        $res = asSeriesPost($body, ['id' => $sid, 'action' => 'split']);
        assertStatus(201, $res);
        assertSame(4, $res['body']['created']);
        assertSame(4, $res['body']['removed']);
        assertSame(['2031-03-11'], array_column($res['body']['detached'], 'date'));
        assertSame(true, $res['body']['series_deleted']);

        assertStatus(404, apiRequest('GET', 'appointment_series', ['token' => apiToken('admin'), 'query' => ['id' => $sid]]));
        $kept = asAptOn($world, '2031-03-11');
        assertSame(null, $kept['series_id']);
        assertSame(0, (int) $kept['is_detached']);

        $new = asSeriesGet((int) $res['body']['series_id']);
        assertSame('2031-03-04', $new['start_date']);
        assertSame(4, $new['appointment_count']);
        assertSame(['2031-03-06', '2031-03-11', '2031-03-13', '2031-03-20', '2031-03-27'],
            array_column(asAppointments($world), 'date'));
    } finally {
        asDropWorld($world);
    }
});

test('Split: legt die neue Regel keinen Termin an, bleibt die alte Serie unveraendert (409, Rollback)', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $body = asSeriesBody($world, ['rrule' => 'FREQ=WEEKLY;BYDAY=TH', 'from_date' => '2031-03-18',
                                      'until' => '2031-03-27', 'exdates' => ['2031-03-20', '2031-03-27']]);
        unset($body['start_date']);

        assertStatus(409, asSeriesPost($body, ['id' => $sid, 'action' => 'split']));
        assertSame(5, count(asAppointments($world)));
        assertSame('2031-04-01', asSeriesGet($sid)['until']);
    } finally {
        asDropWorld($world);
    }
});

test('Split: Datum ausserhalb der Serie liefert 400', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $body = asSeriesBody($world, ['from_date' => '2031-05-01', 'until' => '2031-06-01']);
        assertStatus(400, asSeriesPost($body, ['id' => $sid, 'action' => 'split']));
        $body = asSeriesBody($world, ['from_date' => ['2031-03-18'], 'until' => '2031-04-01']);
        assertStatus(400, asSeriesPost($body, ['id' => $sid, 'action' => 'split']));
        assertSame(5, count(asAppointments($world)));
    } finally {
        asDropWorld($world);
    }
});

test('Fortsetzen erzeugt nur den neuen Zeitraum und beachtet exdates', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $preview = asSeriesPost(['until' => '2031-04-22'], ['id' => $sid, 'action' => 'extend', 'preview' => 1]);
        assertStatus(200, $preview);
        assertSame(['2031-04-08', '2031-04-15', '2031-04-22'], array_column($preview['body']['occurrences'], 'date'));

        $res = asSeriesPost(['until' => '2031-04-22', 'exdates' => ['2031-04-15']], ['id' => $sid, 'action' => 'extend']);
        assertStatus(200, $res);
        assertSame(2, $res['body']['created']);

        assertSame(['2031-03-04', '2031-03-11', '2031-03-18', '2031-03-25', '2031-04-01', '2031-04-08', '2031-04-22'],
            array_column(asAppointments($world), 'date'));
        $series = asSeriesGet($sid);
        assertSame('2031-04-22', $series['until']);
        assertTrue(in_array('2031-04-15', $series['exdates'], true));
    } finally {
        asDropWorld($world);
    }
});

test('Fortsetzen haelt den Zwei-Wochen-Takt des Serienbeginns', function () {
    $world = asWorld();
    try {
        // Alle zwei Wochen dienstags: 04.03., 18.03., 01.04.; Ende 07.04. (kein Termin in der Woche)
        $sid = asCreateSeries($world, ['rrule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU', 'until' => '2031-04-07']);
        $res = asSeriesPost(['until' => '2031-05-01'], ['id' => $sid, 'action' => 'extend', 'preview' => 1]);
        assertStatus(200, $res);
        assertSame(['2031-04-15', '2031-04-29'], array_column($res['body']['occurrences'], 'date'));
    } finally {
        asDropWorld($world);
    }
});

test('Fortsetzen setzt an until + 1 an', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        // Der einzeln geloeschte 01.04. liegt vor dem neuen Bereich und bleibt weg.
        $apt = asAptOn($world, '2031-04-01');
        assertStatus(200, apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $apt['appointment_id']]]));
        $res = asSeriesPost(['until' => '2031-04-08'], ['id' => $sid, 'action' => 'extend']);
        assertStatus(200, $res);
        assertSame(1, $res['body']['created'], 'nur der 08.04.');
    } finally {
        asDropWorld($world);
    }
});

test('Fortsetzen: Ende nicht nach dem bisherigen oder mehr als 12 Monate liefert 400', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        assertStatus(400, asSeriesPost(['until' => '2031-04-01'], ['id' => $sid, 'action' => 'extend']));
        assertStatus(400, asSeriesPost(['until' => '2032-04-02'], ['id' => $sid, 'action' => 'extend']));
        assertStatus(400, asSeriesPost(['until' => 'bald'], ['id' => $sid, 'action' => 'extend']));
        assertStatus(400, asSeriesPost(['until' => ['2031-04-22']], ['id' => $sid, 'action' => 'extend']));
        assertStatus(400, asSeriesPost(['until' => '2031-04-22', 'exdates' => 'x'], ['id' => $sid, 'action' => 'extend']));
        assertSame('2031-04-01', asSeriesGet($sid)['until']);
        assertSame(5, count(asAppointments($world)));
    } finally {
        asDropWorld($world);
    }
});

test('Split und Fortsetzen sind Admin und Manager vorbehalten', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        assertStatus(403, asSeriesPost(['until' => '2031-04-22'], ['id' => $sid, 'action' => 'extend', 'preview' => 1], 'user'));
        assertStatus(200, asSeriesPost(['until' => '2031-04-22'], ['id' => $sid, 'action' => 'extend', 'preview' => 1], 'manager'));
        $body = asSeriesBody($world, ['from_date' => '2031-03-18']);
        assertStatus(403, asSeriesPost($body, ['id' => $sid, 'action' => 'split', 'preview' => 1], 'user'));
    } finally {
        asDropWorld($world);
    }
});

test('Split uebernimmt Ausfaelle der alten Serie ab dem Datum: ein einzeln geloeschter Termin kehrt nicht zurueck', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-25');
        assertStatus(200, apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $apt['appointment_id']]]));

        $body = asSeriesBody($world, ['from_date' => '2031-03-18', 'start_time' => '20:00']);
        unset($body['start_date']);

        $preview = asSeriesPost($body, ['id' => $sid, 'action' => 'split', 'preview' => 1]);
        assertStatus(200, $preview);
        $excluded = array_column($preview['body']['occurrences'], 'excluded', 'date');
        assertSame(['2031-03-18' => false, '2031-03-25' => true, '2031-04-01' => false], $excluded);
        assertSame(['2031-03-18' => false, '2031-03-25' => true, '2031-04-01' => false],
            array_column($preview['body']['occurrences'], 'locked', 'date'), 'uebernommener Ausfall ist gesperrt');
        assertSame(2, $preview['body']['count']);

        // Ein im Anfragekoerper abgewaehltes Datum ist abgewaehlt, aber nicht gesperrt.
        $own = asSeriesPost(array_merge($body, ['exdates' => ['2031-04-01']]),
            ['id' => $sid, 'action' => 'split', 'preview' => 1]);
        assertStatus(200, $own);
        assertSame(['2031-03-18' => false, '2031-03-25' => true, '2031-04-01' => true],
            array_column($own['body']['occurrences'], 'excluded', 'date'));
        assertSame(['2031-03-18' => false, '2031-03-25' => true, '2031-04-01' => false],
            array_column($own['body']['occurrences'], 'locked', 'date'));

        $res = asSeriesPost($body, ['id' => $sid, 'action' => 'split']);
        assertStatus(201, $res);
        assertSame(2, $res['body']['created']);
        assertSame(['2031-03-04', '2031-03-11', '2031-03-18', '2031-04-01'], array_column(asAppointments($world), 'date'));
        assertSame('20:00:00', asAptOn($world, '2031-03-18')['start_time']);
        assertTrue(in_array('2031-03-25', asSeriesGet((int) $res['body']['series_id'])['exdates'], true));
    } finally {
        asDropWorld($world);
    }
});

test('Split: trifft die neue Regel einen alten Termin mit Daten, wird das Datum ausgelassen und vermerkt', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        asAddRecord((int) asAptOn($world, '2031-03-25')['appointment_id']);

        // Gleicher Wochentag, gleiche Zeit, nur ein neuer Titel: der 25.03. bleibt als abgeloester Termin stehen.
        $body = asSeriesBody($world, ['from_date' => '2031-03-18', 'title' => 'AS-Neu']);
        unset($body['start_date']);

        $preview = asSeriesPost($body, ['id' => $sid, 'action' => 'split', 'preview' => 1]);
        assertStatus(200, $preview);
        $conflicts = array_column($preview['body']['occurrences'], 'conflict', 'date');
        assertSame(null, $conflicts['2031-03-18']);
        assertSame(null, $conflicts['2031-04-01']);
        assertSame((int) asAptOn($world, '2031-03-25')['appointment_id'], (int) $conflicts['2031-03-25']['appointment_id']);
        assertSame(2, $preview['body']['count']);
        assertSame(1, $preview['body']['keeps']);

        $res = asSeriesPost($body, ['id' => $sid, 'action' => 'split']);
        assertStatus(201, $res);
        assertSame(2, $res['body']['created']);
        assertSame(['2031-03-25'], array_column($res['body']['skipped'], 'date'));
        assertSame('conflict', $res['body']['skipped'][0]['reason']);
        assertTrue(in_array('2031-03-25', asSeriesGet((int) $res['body']['series_id'])['exdates'], true));

        $kept = asAptOn($world, '2031-03-25');
        assertSame('AS-Probe', $kept['title']);
        assertSame($sid, (int) $kept['series_id']);
        assertSame(1, (int) $kept['is_detached']);
    } finally {
        asDropWorld($world);
    }
});

test('Fortsetzen beachtet gespeicherte exdates im neuen Bereich', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $apt = asAptOn($world, '2031-03-25');
        assertStatus(200, apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $apt['appointment_id']]]));
        assertStatus(200, apiRequest('DELETE', 'appointment_series', ['token' => apiToken('admin'),
            'query' => ['id' => $sid, 'from' => '2031-03-18']]));
        assertSame('2031-03-17', asSeriesGet($sid)['until']);

        $preview = asSeriesPost(['until' => '2031-04-01'], ['id' => $sid, 'action' => 'extend', 'preview' => 1]);
        assertStatus(200, $preview);
        assertSame(['2031-03-18' => false, '2031-03-25' => true, '2031-04-01' => false],
            array_column($preview['body']['occurrences'], 'excluded', 'date'));
        assertSame(['2031-03-18' => false, '2031-03-25' => true, '2031-04-01' => false],
            array_column($preview['body']['occurrences'], 'locked', 'date'), 'gespeicherter Ausfall ist gesperrt');

        // Ein im Anfragekoerper abgewaehltes Datum ist abgewaehlt, aber nicht gesperrt.
        $own = asSeriesPost(['until' => '2031-04-01', 'exdates' => ['2031-04-01']],
            ['id' => $sid, 'action' => 'extend', 'preview' => 1]);
        assertStatus(200, $own);
        assertSame(['2031-03-18' => false, '2031-03-25' => true, '2031-04-01' => true],
            array_column($own['body']['occurrences'], 'excluded', 'date'));
        assertSame(['2031-03-18' => false, '2031-03-25' => true, '2031-04-01' => false],
            array_column($own['body']['occurrences'], 'locked', 'date'));

        $res = asSeriesPost(['until' => '2031-04-01'], ['id' => $sid, 'action' => 'extend']);
        assertStatus(200, $res);
        assertSame(2, $res['body']['created']);
        assertSame(['2031-03-04', '2031-03-11', '2031-03-18', '2031-04-01'], array_column(asAppointments($world), 'date'));
    } finally {
        asDropWorld($world);
    }
});

test('Fortsetzen einer per Split entstandenen Zwei-Wochen-Serie haelt deren Takt', function () {
    $world = asWorld();
    try {
        $sid = asCreateSeries($world);
        $body = asSeriesBody($world, ['rrule' => 'FREQ=WEEKLY;INTERVAL=2;BYDAY=TU', 'from_date' => '2031-03-11']);
        unset($body['start_date']);
        $split = asSeriesPost($body, ['id' => $sid, 'action' => 'split']);
        assertStatus(201, $split);
        $newId = (int) $split['body']['series_id'];
        assertSame(['2031-03-04', '2031-03-11', '2031-03-25'], array_column(asAppointments($world), 'date'));

        // Takt ab 11.03.: 08.04., 22.04. -- nicht 15.04./29.04. (Takt ab dem neuen Bereich 02.04.)
        $res = asSeriesPost(['until' => '2031-04-30'], ['id' => $newId, 'action' => 'extend', 'preview' => 1]);
        assertStatus(200, $res);
        assertSame(['2031-04-08', '2031-04-22'], array_column($res['body']['occurrences'], 'date'));
    } finally {
        asDropWorld($world);
    }
});
