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
 * Rueckmeldungen beim Verlegen eines Termins zuruecksetzen (OI-124).
 *
 * Jeder Test baut sich eine eigene Welt aus Gruppe, Terminart mit Rueckmeldung
 * und drei Mitgliedern, die zusagen, unsicher sind und absagen. Termine liegen
 * in 2031 -- weit genug in der Zukunft, dass jede Rueckmeldung offen steht.
 *
 * Spec: docs/superpowers/specs/2026-10-06-oi-124-rueckmeldungen-zuruecksetzen-design.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function rrCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");

    return (int) $res['body']['id'];
}

/** @param array<string, mixed> $typeSettings */
function rrWorld(string $label, array $typeSettings = []): array
{
    $suffix = uniqid();
    $world  = ['group' => null, 'type' => null, 'members' => [], 'series' => []];

    try {
        $world['group'] = rrCreate('member_groups', ['group_name' => "RR {$label} {$suffix}"]);
        $world['type']  = rrCreate('appointment_types', array_merge([
            'type_name'         => "RR {$label} {$suffix}",
            'is_default'        => 0,
            'color'             => '#667eea',
            'group_ids'         => [$world['group']],
            'responses_enabled' => 1,
        ], $typeSettings));
        foreach (['Ja', 'Vielleicht', 'Nein'] as $name) {
            $world['members'][] = rrCreate('members', [
                'name'      => $name,
                'surname'   => "RR {$label} {$suffix}",
                'active'    => 1,
                'group_ids' => [$world['group']],
            ]);
        }
    } catch (Throwable $e) {
        rrDropWorld($world);
        throw $e;
    }

    return $world;
}

/** Alle Termine der Terminart in 2031, nach Datum. */
function rrAppointments(array $world): array
{
    $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'),
        'query' => ['type_id' => $world['type'], 'year' => 2031]]);
    assertStatus(200, $res);
    $list = array_values(array_filter($res['body'] ?? [], fn ($a) => (int) $a['type_id'] === $world['type']));
    usort($list, fn ($a, $b) => strcmp($a['date'], $b['date']));

    return $list;
}

function rrDropWorld(array $world): void
{
    $token = apiToken('admin');
    if ($world['type'] !== null) {
        $seriesIds = [];
        foreach (rrAppointments($world) as $apt) {
            if (!empty($apt['series_id'])) {
                $seriesIds[(int) $apt['series_id']] = true;
            }
            apiRequest('DELETE', 'appointments', ['token' => $token, 'query' => ['id' => $apt['appointment_id']]]);
        }
        foreach (array_keys($seriesIds) as $sid) {
            $series = apiRequest('GET', 'appointment_series', ['token' => $token, 'query' => ['id' => $sid]]);
            if ($series['status'] === 200 && !empty($series['body']['start_date'])) {
                apiRequest('DELETE', 'appointment_series', ['token' => $token,
                    'query' => ['id' => $sid, 'from' => $series['body']['start_date']]]);
            }
        }
    }
    foreach ($world['members'] as $memberId) {
        apiRequest('DELETE', 'members', ['token' => $token, 'query' => ['id' => $memberId]]);
    }
    if ($world['type'] !== null) {
        apiRequest('DELETE', 'appointment_types', ['token' => $token, 'query' => ['id' => $world['type']]]);
    }
    if ($world['group'] !== null) {
        apiRequest('DELETE', 'member_groups', ['token' => $token, 'query' => ['id' => $world['group']]]);
    }
}

function rrAppointment(array $world, string $date = '2031-05-06', string $time = '19:00:00'): int
{
    return rrCreate('appointments', [
        'title' => 'RR-Termin', 'date' => $date, 'start_time' => $time, 'type_id' => $world['type'],
    ]);
}

function rrRespond(int $appointmentId, int $memberId, string $status): void
{
    assertStatus(200, apiRequest('PUT', 'appointment_responses', ['token' => apiToken('manager'),
        'query' => ['appointment_id' => $appointmentId, 'member_id' => $memberId],
        // Bei Pflicht-Entschuldigung braucht eine Absage eine Begruendung.
        'body'  => ['status' => $status, 'comment' => $status === 'no' ? 'verhindert' : null]]),
        "Rueckmeldung {$status} fuer Mitglied {$memberId}");
}

/** Ja, Vielleicht, Nein -- in der Reihenfolge der Mitglieder der Welt. */
function rrRespondAll(array $world, int $appointmentId): void
{
    rrRespond($appointmentId, $world['members'][0], 'yes');
    rrRespond($appointmentId, $world['members'][1], 'maybe');
    rrRespond($appointmentId, $world['members'][2], 'no');
}

/** member_id => Zeile der Verwalteransicht (status, excuse_state, ...) */
function rrResponses(int $appointmentId): array
{
    $res = apiRequest('GET', 'appointment_responses', ['token' => apiToken('admin'),
        'query' => ['appointment_id' => $appointmentId]]);
    assertStatus(200, $res);
    $byMember = [];
    foreach ($res['body']['members'] as $m) {
        $byMember[(int) $m['member_id']] = $m;
    }

    return $byMember;
}

/** Status der drei Mitglieder der Welt, in ihrer Reihenfolge. */
function rrStatuses(array $world, int $appointmentId): array
{
    $rows = rrResponses($appointmentId);

    return array_map(fn (int $id) => $rows[$id]['status'] ?? null, $world['members']);
}

function rrPut(int $appointmentId, array $body): array
{
    return apiRequest('PUT', 'appointments', ['token' => apiToken('manager'),
        'query' => ['id' => $appointmentId], 'body' => $body]);
}

function rrGetAppointment(int $appointmentId): array
{
    $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $appointmentId]]);
    assertStatus(200, $res);

    return $res['body'];
}

// ---- Einzeltermin -----------------------------------------------------------

test('PUT appointments: Datum verlegt, Zusagen vorhanden, ohne Angabe -> 409 und nichts geschrieben', function () {
    $world = rrWorld('Frage');
    try {
        $apt = rrAppointment($world);
        rrRespondAll($world, $apt);

        $res = rrPut($apt, ['date' => '2031-05-08']);
        assertStatus(409, $res);
        assertSame('responses_affected', $res['body']['code'] ?? null);
        assertSame(1, $res['body']['appointments']);
        assertSame(2, $res['body']['responses'], 'Zusage und unsicher, nicht die Absage');

        assertSame('2031-05-06', rrGetAppointment($apt)['date'], 'Termin darf nicht verlegt sein');
        assertSame(['yes', 'maybe', 'no'], rrStatuses($world, $apt));
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointments: Beginn verlegt mit reset_responses=true setzt Zusagen und unsicher zurueck', function () {
    $world = rrWorld('Reset', ['responses_require_excuse' => 1]);
    try {
        $apt = rrAppointment($world);
        rrRespondAll($world, $apt);
        $excuseVorher = rrResponses($apt)[$world['members'][2]]['excuse_state'];

        $res = rrPut($apt, ['start_time' => '20:00', 'reset_responses' => true]);
        assertStatus(200, $res);
        assertSame(2, $res['body']['responses_reset']);

        assertSame('20:00:00', rrGetAppointment($apt)['start_time']);
        assertSame([null, null, 'no'], rrStatuses($world, $apt));
        assertSame($excuseVorher, rrResponses($apt)[$world['members'][2]]['excuse_state'],
            'Absage behaelt ihren Antrag');
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointments: reset_responses=false verlegt und behaelt alle Rueckmeldungen', function () {
    $world = rrWorld('Behalten');
    try {
        $apt = rrAppointment($world);
        rrRespondAll($world, $apt);

        $res = rrPut($apt, ['date' => '2031-05-08', 'reset_responses' => false]);
        assertStatus(200, $res);
        assertSame(0, $res['body']['responses_reset']);
        assertSame('2031-05-08', rrGetAppointment($apt)['date']);
        assertSame(['yes', 'maybe', 'no'], rrStatuses($world, $apt));
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointments: Titel, Ort, Ende loesen keine Rueckfrage aus', function () {
    $world = rrWorld('Andere');
    try {
        $apt = rrAppointment($world);
        rrRespondAll($world, $apt);

        $res = rrPut($apt, ['title' => 'Neu', 'location' => 'Aula', 'end_time' => '22:00']);
        assertStatus(200, $res);
        assertSame(0, $res['body']['responses_reset']);
        assertSame(['yes', 'maybe', 'no'], rrStatuses($world, $apt));
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointments: unveraendert mitgeschicktes Datum und Beginn fragen nicht', function () {
    $world = rrWorld('Gleich');
    try {
        $apt = rrAppointment($world);
        rrRespondAll($world, $apt);

        // Der Dialog schickt immer alle Felder mit, auch unveraenderte.
        $res = rrPut($apt, ['title' => 'RR-Termin', 'date' => '2031-05-06', 'start_time' => '19:00',
                            'type_id' => $world['type']]);
        assertStatus(200, $res);
        assertSame(['yes', 'maybe', 'no'], rrStatuses($world, $apt));
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointments: nur Absagen vorhanden -> verlegt ohne Rueckfrage', function () {
    $world = rrWorld('NurNein');
    try {
        $apt = rrAppointment($world);
        rrRespond($apt, $world['members'][2], 'no');

        $res = rrPut($apt, ['date' => '2031-05-08']);
        assertStatus(200, $res);
        assertSame('2031-05-08', rrGetAppointment($apt)['date']);
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointments: reset_responses ausser true/false -> 400, nichts geschrieben', function () {
    $world = rrWorld('Falsch');
    try {
        $apt = rrAppointment($world);
        rrRespondAll($world, $apt);

        foreach (['ja', 1, null] as $falsch) {
            assertStatus(400, rrPut($apt, ['date' => '2031-05-08', 'reset_responses' => $falsch]),
                'Wert ' . var_export($falsch, true));
        }
        assertSame('2031-05-06', rrGetAppointment($apt)['date']);
        assertSame(['yes', 'maybe', 'no'], rrStatuses($world, $apt));
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointments: Dublette geht vor -- 409 ohne code', function () {
    $world = rrWorld('Dublette');
    try {
        $apt = rrAppointment($world, '2031-05-06');
        rrAppointment($world, '2031-05-08');
        rrRespondAll($world, $apt);

        $res = rrPut($apt, ['date' => '2031-05-08']);
        assertStatus(409, $res);
        assertTrue(!isset($res['body']['code']), 'Dublette darf nicht als responses_affected kommen: ' . $res['raw']);
        assertTrue(isset($res['body']['conflict']), 'Dublette liefert conflict');
    } finally {
        rrDropWorld($world);
    }
});

// ---- Serie: dieser und alle folgenden -----------------------------------------

/** Woechentliche Serie dienstags vom 04.03. bis 01.04.2031 (fuenf Termine). */
function rrSeries(array $world): int
{
    $res = apiRequest('POST', 'appointment_series', ['token' => apiToken('admin'), 'body' => [
        'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU', 'start_date' => '2031-03-04', 'until' => '2031-04-01',
        'title' => 'RR-Probe', 'type_id' => $world['type'], 'start_time' => '19:30', 'end_time' => '22:00',
        'location' => 'Probelokal', 'description' => null,
    ]]);
    assertStatus(201, $res);

    return (int) $res['body']['series_id'];
}

function rrAptOn(array $world, string $date): array
{
    foreach (rrAppointments($world) as $apt) {
        if ($apt['date'] === $date) {
            return $apt;
        }
    }
    throw new RuntimeException("Kein Termin am {$date}");
}

function rrSeriesPut(int $seriesId, array $body): array
{
    return apiRequest('PUT', 'appointment_series', ['token' => apiToken('manager'),
        'query' => ['id' => $seriesId], 'body' => $body]);
}

/** Haengt eine Anwesenheit an den Termin -- danach wird er beim Verlegen abgeloest. */
function rrAddRecord(int $appointmentId, int $memberId): void
{
    assertStatus(201, apiRequest('POST', 'records', ['token' => apiToken('admin'),
        'body' => ['member_id' => $memberId, 'appointment_id' => $appointmentId]]));
}

test('PUT appointment_series: Beginn ab Datum verlegt, ohne Angabe -> 409 und nichts geschrieben', function () {
    $world = rrWorld('SerieFrage');
    try {
        $sid = rrSeries($world);
        $a = (int) rrAptOn($world, '2031-03-11')['appointment_id'];
        $b = (int) rrAptOn($world, '2031-03-18')['appointment_id'];
        $vorher = (int) rrAptOn($world, '2031-03-04')['appointment_id'];
        rrRespond($a, $world['members'][0], 'yes');
        rrRespond($b, $world['members'][1], 'maybe');
        rrRespond($b, $world['members'][2], 'no');
        rrRespond($vorher, $world['members'][0], 'yes');   // vor from_date: zaehlt nicht

        $res = rrSeriesPut($sid, ['from_date' => '2031-03-11', 'start_time' => '20:00']);
        assertStatus(409, $res);
        assertSame('responses_affected', $res['body']['code'] ?? null);
        assertSame(2, $res['body']['appointments']);
        assertSame(2, $res['body']['responses']);

        assertSame('19:30:00', rrAptOn($world, '2031-03-11')['start_time'], 'Termin darf nicht verlegt sein');
        assertSame('yes', rrResponses($a)[$world['members'][0]]['status']);
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointment_series: true setzt zurueck, abgeloeste Termine behalten ihre Zusagen', function () {
    $world = rrWorld('SerieReset');
    try {
        $sid = rrSeries($world);
        $a = (int) rrAptOn($world, '2031-03-11')['appointment_id'];
        $b = (int) rrAptOn($world, '2031-03-18')['appointment_id'];
        $mitDaten = (int) rrAptOn($world, '2031-03-25')['appointment_id'];
        rrRespond($a, $world['members'][0], 'yes');
        rrRespond($b, $world['members'][1], 'maybe');
        rrRespond($b, $world['members'][2], 'no');
        rrRespond($mitDaten, $world['members'][0], 'yes');
        rrAddRecord($mitDaten, $world['members'][0]);

        $res = rrSeriesPut($sid, ['from_date' => '2031-03-11', 'start_time' => '20:00', 'reset_responses' => true]);
        assertStatus(200, $res);
        assertSame(2, $res['body']['responses_reset']);

        assertSame('20:00:00', rrAptOn($world, '2031-03-11')['start_time']);
        assertSame(null, rrResponses($a)[$world['members'][0]]['status']);
        assertSame(null, rrResponses($b)[$world['members'][1]]['status']);
        assertSame('no', rrResponses($b)[$world['members'][2]]['status'], 'Absage bleibt');

        assertSame(1, (int) rrAptOn($world, '2031-03-25')['is_detached'], 'Termin mit Anwesenheit wird abgeloest');
        assertSame('yes', rrResponses($mitDaten)[$world['members'][0]]['status'], 'abgeloest: Zusage bleibt');
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointment_series: false verlegt und behaelt die Rueckmeldungen', function () {
    $world = rrWorld('SerieBehalten');
    try {
        $sid = rrSeries($world);
        $a = (int) rrAptOn($world, '2031-03-11')['appointment_id'];
        rrRespond($a, $world['members'][0], 'yes');

        $res = rrSeriesPut($sid, ['from_date' => '2031-03-11', 'start_time' => '20:00', 'reset_responses' => false]);
        assertStatus(200, $res);
        assertSame(0, $res['body']['responses_reset']);
        assertSame('20:00:00', rrAptOn($world, '2031-03-11')['start_time']);
        assertSame('yes', rrResponses($a)[$world['members'][0]]['status']);
    } finally {
        rrDropWorld($world);
    }
});

test('PUT appointment_series: Ort aendern fragt nicht, ungueltige Angabe -> 400', function () {
    $world = rrWorld('SerieAndere');
    try {
        $sid = rrSeries($world);
        $a = (int) rrAptOn($world, '2031-03-11')['appointment_id'];
        rrRespond($a, $world['members'][0], 'yes');

        $res = rrSeriesPut($sid, ['from_date' => '2031-03-11', 'location' => 'Aula']);
        assertStatus(200, $res);
        assertSame(0, $res['body']['responses_reset']);
        assertSame('yes', rrResponses($a)[$world['members'][0]]['status']);

        assertStatus(400, rrSeriesPut($sid, ['from_date' => '2031-03-11', 'start_time' => '20:00',
                                             'reset_responses' => 'x']));
        assertSame('19:30:00', rrAptOn($world, '2031-03-11')['start_time']);
    } finally {
        rrDropWorld($world);
    }
});
