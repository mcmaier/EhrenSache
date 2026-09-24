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
 * OI-89: Kommende Termine sind nicht "fehlend".
 *
 * attendance_list kennzeichnet jede Zeile mit appointment_started; die Grenze
 * ist der Beginn des Check-in-Fensters (Startzeit minus
 * checkin_tolerance_hours) -- dieselbe Regel wie im Kalender.
 *
 * Jede Welt baut eigene Gruppe, Terminart und Mitglieder.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function upCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");

    return (int) $res['body']['id'];
}

function upDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

/** @return array{group:int|null,type:int|null,members:int[],appointments:int[]} */
function upWorld(string $label, int $memberCount = 2): array
{
    $suffix = uniqid();
    $world  = ['group' => null, 'type' => null, 'members' => [], 'appointments' => []];

    try {
        $world['group'] = upCreate('member_groups', ['group_name' => "UP {$label} {$suffix}"]);
        $world['type']  = upCreate('appointment_types', [
            'type_name' => "UP {$label} {$suffix}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => [$world['group']],
        ]);
        for ($i = 1; $i <= $memberCount; $i++) {
            $world['members'][] = upCreate('members', [
                'name' => 'Up', 'surname' => "Test {$label} {$i} {$suffix}",
                'active' => 1, 'group_ids' => [$world['group']],
            ]);
        }
    } catch (Throwable $e) {
        upDropWorld($world);
        throw $e;
    }

    return $world;
}

function upDropWorld(array $world): void
{
    foreach ($world['appointments'] as $id) {
        upDelete('appointments', $id);
    }
    foreach ($world['members'] as $id) {
        upDelete('members', $id);
    }
    if ($world['type'] !== null) {
        upDelete('appointment_types', $world['type']);
    }
    if ($world['group'] !== null) {
        upDelete('member_groups', $world['group']);
    }
}

/** Termin zum Zeitpunkt $timestamp (Sekunden), Minuten gerundet. */
function upAppointmentAt(array &$world, int $timestamp): int
{
    $id = upCreate('appointments', [
        'title' => 'UP-Termin', 'date' => date('Y-m-d', $timestamp),
        'start_time' => date('H:i:00', $timestamp), 'type_id' => $world['type'],
    ]);
    $world['appointments'][] = $id;

    return $id;
}

function upLeadHours(): int
{
    $res = apiRequest('GET', 'settings', ['token' => apiToken('admin'), 'query' => ['scope' => 'client']]);
    assertStatus(200, $res);

    return (int) ($res['body']['settings']['checkin_tolerance_hours'] ?? 2);
}

function upListByAppointment(int $appointmentId): array
{
    $res = apiRequest('GET', 'attendance_list', ['token' => apiToken('admin'),
        'query' => ['appointment_id' => $appointmentId]]);
    assertStatus(200, $res);

    return $res['body'];
}

// ---- Modus je Termin --------------------------------------------------------

test('attendance_list je Termin: kommender Termin traegt appointment_started = 0', function () {
    $world = upWorld('TerminKommend');
    try {
        $apt  = upAppointmentAt($world, strtotime('+5 days 19:00'));
        $body = upListByAppointment($apt);

        assertSame(0, $body['appointment']['appointment_started'], 'Termin');
        assertTrue(count($body['members']) === 2, 'Beide Mitglieder erwartet: ' . json_encode($body['members']));
        foreach ($body['members'] as $m) {
            assertSame(0, $m['appointment_started'], 'Zeile ' . $m['member_id']);
        }
    } finally {
        upDropWorld($world);
    }
});

test('attendance_list je Termin: vergangener Termin traegt appointment_started = 1', function () {
    $world = upWorld('TerminVorbei');
    try {
        $apt  = upAppointmentAt($world, strtotime('-3 days 19:00'));
        $body = upListByAppointment($apt);

        assertSame(1, $body['appointment']['appointment_started']);
        foreach ($body['members'] as $m) {
            assertSame(1, $m['appointment_started']);
        }
    } finally {
        upDropWorld($world);
    }
});

test('attendance_list je Termin: im Check-in-Fenster gilt der Termin als begonnen', function () {
    $lead  = upLeadHours();
    $world = upWorld('TerminFenster');
    try {
        // Beginnt erst in (lead - 1) Stunden plus einer halben -- Fenster ist offen.
        $offen = upAppointmentAt($world, time() + max(0, $lead * 3600 - 1800));
        // Beginnt in (lead + 2) Stunden -- Fenster noch zu.
        $zu    = upAppointmentAt($world, time() + $lead * 3600 + 7200);

        assertSame(1, upListByAppointment($offen)['appointment']['appointment_started'],
            'Im Check-in-Fenster (Vorlauf ' . $lead . ' h) muss der Termin als begonnen gelten');
        assertSame(0, upListByAppointment($zu)['appointment']['appointment_started'],
            'Vor dem Check-in-Fenster ist der Termin kommend');
    } finally {
        upDropWorld($world);
    }
});

// ---- Modus je Mitglied ------------------------------------------------------

test('attendance_list je Mitglied: jede Zeile traegt appointment_started', function () {
    $world = upWorld('Mitglied', 1);
    try {
        // Beide im laufenden Jahr, sonst faellt einer aus dem Jahresfilter.
        $jetzt   = time();
        $jahr    = (int) date('Y', $jetzt);
        $vorbei  = upAppointmentAt($world, max(strtotime("{$jahr}-01-01 19:00"), $jetzt - 86400 * 3));
        $kommend = upAppointmentAt($world, min(strtotime("{$jahr}-12-31 19:00"), $jetzt + 86400 * 3));
        if (date('Y', $jetzt + 86400 * 3) !== (string) $jahr || date('Y', $jetzt - 86400 * 3) !== (string) $jahr) {
            // Jahreswechsel in Reichweite: Die Zeitpunkte oben sind geklemmt,
            // der kommende kann dann schon vorbei sein. Nicht aussagekraeftig.
            return;
        }

        $res = apiRequest('GET', 'attendance_list', ['token' => apiToken('admin'),
            'query' => ['member_id' => $world['members'][0], 'year' => $jahr]]);
        assertStatus(200, $res);

        $byId = [];
        foreach ($res['body']['appointments'] as $row) {
            assertTrue(array_key_exists('appointment_started', $row), 'appointment_started fehlt: ' . json_encode($row));
            $byId[(int) $row['appointment_id']] = $row['appointment_started'];
        }
        assertSame(1, $byId[$vorbei] ?? null, 'Vergangener Termin');
        assertSame(0, $byId[$kommend] ?? null, 'Kommender Termin');
    } finally {
        upDropWorld($world);
    }
});

// ---- Kalender: derselbe Vorlauf --------------------------------------------

test('GET appointments include=attendance: Zahlen ab dem Check-in-Fenster', function () {
    $lead  = upLeadHours();
    $world = upWorld('Kalender', 1);
    try {
        $offenTs = time() + max(0, $lead * 3600 - 1800);
        $zuTs    = time() + $lead * 3600 + 7200;
        $offen   = upAppointmentAt($world, $offenTs);
        $zu      = upAppointmentAt($world, $zuTs);

        if (date('Y', $offenTs) !== date('Y', $zuTs)) {
            return; // Silvesterabend: die beiden Termine liegen in zwei Jahren
        }

        $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'), 'query' => [
            'include' => 'attendance',
            'year'    => (int) date('Y', $offenTs),
        ]]);
        assertStatus(200, $res);

        $rows = [];
        foreach ($res['body'] as $row) {
            $rows[(int) $row['appointment_id']] = $row;
        }
        assertTrue(isset($rows[$offen], $rows[$zu]), 'Termine fehlen in der Liste');
        assertTrue($rows[$offen]['attendance'] !== null, 'Im Check-in-Fenster muss es Zahlen geben');
        assertSame(null, $rows[$zu]['attendance'], 'Vor dem Check-in-Fenster keine Zahlen');
    } finally {
        upDropWorld($world);
    }
});
