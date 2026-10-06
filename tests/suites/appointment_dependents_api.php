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
 * GET appointments?id=X&dependents=1 (OI-125): zaehlt, was DELETE mitloescht.
 * Die Check-in-App nennt die Zahlen in der Rueckfrage vor dem Loeschen und
 * verweist bei Erfassungen aufs Dashboard.
 *
 * Jeder Test baut sich eine eigene Gruppe, Terminart und ein Mitglied, damit
 * die Dublettenpruefung (Terminart im Toleranzfenster) nicht zwischen Tests
 * greift und nichts am Bestand haengt.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** @return array{group: int, type: int, member: int, appointment: int} */
function depWorld(): array
{
    $suffix = uniqid();
    $group = apiRequest('POST', 'member_groups', ['token' => apiToken('admin'),
        'body' => ['group_name' => "DEP {$suffix}"]]);
    assertStatus(201, $group);
    $type = apiRequest('POST', 'appointment_types', ['token' => apiToken('admin'), 'body' => [
        'type_name' => "DEP {$suffix}", 'is_default' => 0, 'color' => '#667eea',
        'responses_enabled' => 1, 'group_ids' => [(int) $group['body']['id']],
    ]]);
    assertStatus(201, $type);
    $member = apiRequest('POST', 'members', ['token' => apiToken('admin'), 'body' => [
        'name' => 'DEP', 'surname' => "Zaehlung {$suffix}", 'active' => 1,
        'group_ids' => [(int) $group['body']['id']],
    ]]);
    assertStatus(201, $member);
    $apt = apiRequest('POST', 'appointments', ['token' => apiToken('admin'), 'body' => [
        'title' => 'DEP-Termin', 'date' => '2031-05-14', 'start_time' => '19:30',
        'type_id' => (int) $type['body']['id'],
    ]]);
    assertStatus(201, $apt);

    return ['group' => (int) $group['body']['id'], 'type' => (int) $type['body']['id'],
            'member' => (int) $member['body']['id'], 'appointment' => (int) $apt['body']['id']];
}

function depDropWorld(array $world): void
{
    $admin = apiToken('admin');
    apiRequest('DELETE', 'appointments', ['token' => $admin, 'query' => ['id' => $world['appointment']]]);
    apiRequest('DELETE', 'members', ['token' => $admin, 'query' => ['id' => $world['member']]]);
    apiRequest('DELETE', 'appointment_types', ['token' => $admin, 'query' => ['id' => $world['type']]]);
    apiRequest('DELETE', 'member_groups', ['token' => $admin, 'query' => ['id' => $world['group']]]);
}

function depFetch(int $appointmentId, string $role = 'admin'): array
{
    return apiRequest('GET', 'appointments', ['token' => apiToken($role),
        'query' => ['id' => $appointmentId, 'dependents' => 1]]);
}

test('dependents zaehlt Erfassungen, Rueckmeldungen und Antraege', function () {
    $world = depWorld();
    try {
        $leer = depFetch($world['appointment']);
        assertStatus(200, $leer);
        assertSame(['records' => 0, 'responses' => 0, 'exceptions' => 0], $leer['body']['dependents']);

        assertStatus(200, apiRequest('PUT', 'appointment_responses', ['token' => apiToken('admin'),
            'query' => ['appointment_id' => $world['appointment'], 'member_id' => $world['member']],
            'body' => ['status' => 'yes']]));
        assertStatus(201, apiRequest('POST', 'exceptions', ['token' => apiToken('admin'), 'body' => [
            'member_id' => $world['member'], 'appointment_id' => $world['appointment'],
            'exception_type' => 'absence', 'reason' => 'DEP-Test',
        ]]));
        assertStatus(201, apiRequest('POST', 'records', ['token' => apiToken('admin'), 'body' => [
            'member_id' => $world['member'], 'appointment_id' => $world['appointment'],
        ]]));

        $voll = depFetch($world['appointment']);
        assertStatus(200, $voll);
        $dep = $voll['body']['dependents'];
        assertSame(1, $dep['records'], 'Erfassung nicht gezaehlt');
        assertSame(1, $dep['exceptions'], 'Antrag nicht gezaehlt');
        // Eine Entschuldigung kann die vorhandene Rueckmeldung umstellen, legt
        // aber keine zweite an (eine Zeile je Mitglied und Termin).
        assertSame(1, $dep['responses'], 'Rueckmeldung nicht gezaehlt');
    } finally {
        depDropWorld($world);
    }
});

test('dependents ist Verwaltern vorbehalten', function () {
    $world = depWorld();
    try {
        assertStatus(200, depFetch($world['appointment'], 'manager'), 'Manager darf zaehlen');
        assertStatus(403, depFetch($world['appointment'], 'user'), 'Mitglied bekommt keine Zahlen');
    } finally {
        depDropWorld($world);
    }
});

test('ohne dependents bleibt der Einzelabruf unveraendert', function () {
    $world = depWorld();
    try {
        $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'),
            'query' => ['id' => $world['appointment']]]);
        assertStatus(200, $res);
        assertTrue(!array_key_exists('dependents', $res['body']), 'dependents ohne Anforderung geliefert');
        assertSame('DEP-Termin', $res['body']['title']);
    } finally {
        depDropWorld($world);
    }
});

test('dependents zu einem unbekannten Termin antwortet 404', function () {
    assertStatus(404, depFetch(999999999));
});
