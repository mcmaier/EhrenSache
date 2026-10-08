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

declare(strict_types=1);

/**
 * FI-24: Zusatzfelder fuer Verwalter in GET exceptions (Liste).
 * Antraege entstehen als admin, Termine in einer eigenen Welt; Aufraeumen im finally.
 */
require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function epCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");

    return (int) $res['body']['id'];
}

function epDelete(string $resource, ?int $id): void
{
    if ($id !== null) {
        apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    }
}

/** @return array<int, array<string, mixed>> offene Antraege, indiziert nach exception_id */
function epPending(string $role): array
{
    $res = apiRequest('GET', 'exceptions', ['token' => apiToken($role), 'query' => ['status' => 'pending']]);
    assertStatus(200, $res);
    $out = [];
    foreach ($res['body'] as $row) {
        $out[(int) $row['exception_id']] = $row;
    }

    return $out;
}

/**
 * Welt: Gruppe + Terminart mit den Mitgliedern von manager und user, zwei vergangene Termine.
 * Antraege: Entschuldigung von manager (eigener Antrag), Zeitantrag von user mit Erfassung,
 * Zeitantrag von user ohne Erfassung (zweiter Termin).
 *
 * @param callable(array<string,int>): void $fn
 */
function epWithWorld(callable $fn): void
{
    $managerMember = apiMemberId('manager');
    $userMember    = apiMemberId('user');
    assertTrue($managerMember !== null && $userMember !== null, 'manager und user brauchen ein Mitglied');

    $s = substr(uniqid(), -6);
    $ids = [];
    $original = [];
    try {
        $ids['group'] = epCreate('member_groups', ['group_name' => "EP {$s}"]);
        $ids['type']  = epCreate('appointment_types', [
            'type_name' => "EP {$s}", 'is_default' => 0, 'color' => '#667eea', 'group_ids' => [$ids['group']],
        ]);
        foreach ([$managerMember, $userMember] as $m) {
            $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $m]]);
            assertStatus(200, $res);
            $original[$m] = array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups'] ?? []);
            assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $m],
                'body' => ['group_ids' => array_values(array_unique(array_merge($original[$m], [$ids['group']])))]]));
        }
        $tag1 = date('Y-m-d', strtotime('-3 days'));
        $tag2 = date('Y-m-d', strtotime('-2 days'));
        $ids['a1'] = epCreate('appointments', ['title' => "EP eins {$s}", 'type_id' => $ids['type'], 'date' => $tag1, 'start_time' => '19:00']);
        $ids['a2'] = epCreate('appointments', ['title' => "EP zwei {$s}", 'type_id' => $ids['type'], 'date' => $tag2, 'start_time' => '19:00']);

        // Erfassung fuer user am ersten Termin (19:20)
        $ids['rec'] = epCreate('records', ['member_id' => $userMember, 'appointment_id' => $ids['a1'],
            'arrival_time' => "{$tag1} 19:20:00"]);

        $ids['ex_own'] = epCreate('exceptions', ['member_id' => $managerMember, 'appointment_id' => $ids['a1'],
            'exception_type' => 'absence', 'reason' => 'EP eigener Antrag', 'status' => 'pending']);
        $ids['ex_rec'] = epCreate('exceptions', ['member_id' => $userMember, 'appointment_id' => $ids['a1'],
            'exception_type' => 'time_correction', 'reason' => 'EP Zug', 'status' => 'pending',
            'requested_arrival_time' => "{$tag1} 19:00:00"]);
        $ids['ex_norec'] = epCreate('exceptions', ['member_id' => $userMember, 'appointment_id' => $ids['a2'],
            'exception_type' => 'time_correction', 'reason' => 'EP ohne', 'status' => 'pending',
            'requested_arrival_time' => "{$tag2} 19:05:00"]);

        $fn($ids);
    } finally {
        foreach (['ex_own', 'ex_rec', 'ex_norec'] as $k) {
            epDelete('exceptions', $ids[$k] ?? null);
        }
        epDelete('records', $ids['rec'] ?? null);
        epDelete('appointments', $ids['a1'] ?? null);
        epDelete('appointments', $ids['a2'] ?? null);
        foreach ($original as $m => $groups) {
            apiRequest('PUT', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $m], 'body' => ['group_ids' => $groups]]);
        }
        epDelete('appointment_types', $ids['type'] ?? null);
        epDelete('member_groups', $ids['group'] ?? null);
    }
}

test('Verwalter: Zusatzfelder vorhanden, erfasste Ankunft und Status', function () {
    epWithWorld(function (array $ids) {
        $rows = epPending('admin');
        foreach (['ex_own', 'ex_rec', 'ex_norec'] as $k) {
            assertTrue(isset($rows[$ids[$k]]), "Antrag {$k} fehlt in der Liste");
            foreach (['self_decision_blocked', 'recorded_arrival_time', 'recorded_status'] as $f) {
                assertTrue(array_key_exists($f, $rows[$ids[$k]]), "Feld {$f} fehlt bei {$k}");
            }
        }
        assertTrue(str_ends_with((string) $rows[$ids['ex_rec']]['recorded_arrival_time'], '19:20:00'),
            'Erfasste Ankunft fehlt: ' . json_encode($rows[$ids['ex_rec']]));
        assertSame('present', $rows[$ids['ex_rec']]['recorded_status']);
        assertSame(null, $rows[$ids['ex_norec']]['recorded_arrival_time']);
        assertSame(null, $rows[$ids['ex_norec']]['recorded_status']);
    });
});

test('Eigener Antrag: blockiert fuer den Antragsteller, nicht fuer andere', function () {
    epWithWorld(function (array $ids) {
        // manager hat ein Mitglied, admin ist zweiter aktiver Verwalter -> eigener Antrag gesperrt
        $asManager = epPending('manager');
        assertSame(true, $asManager[$ids['ex_own']]['self_decision_blocked'], 'Eigener Antrag nicht gesperrt');
        assertSame(false, $asManager[$ids['ex_rec']]['self_decision_blocked'], 'Fremder Antrag gesperrt');
        // admin hat kein Mitglied -> nichts ist sein eigener Antrag
        $asAdmin = epPending('admin');
        assertSame(false, $asAdmin[$ids['ex_own']]['self_decision_blocked']);
    });
});

test('Mitglied: Zusatzfelder fehlen, sonst unveraendert', function () {
    epWithWorld(function (array $ids) {
        $rows = epPending('user');
        assertTrue(isset($rows[$ids['ex_rec']]), 'Eigener Antrag fehlt fuer user');
        assertTrue(!isset($rows[$ids['ex_own']]), 'user sieht fremden Antrag');
        foreach (['self_decision_blocked', 'recorded_arrival_time', 'recorded_status'] as $f) {
            assertTrue(!array_key_exists($f, $rows[$ids['ex_rec']]), "user bekommt {$f}");
        }
    });
});
