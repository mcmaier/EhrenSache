<?php
/**
 * Untergruppen-Gliederung ueber die API (1.8.0).
 *
 * Spec: docs/superpowers/specs/2026-09-16-untergruppen-gliederung-design.md,
 * Abschnitt 5.1. attendance_list liefert je Mitglied `groups` (die Gruppen
 * des Termins) und `subgroups` (alle als Untergruppe markierten Gruppen des
 * Mitglieds, unabhaengig von der Terminart).
 *
 * Muster wie tests/suites/responses_api.php (dort rsWorld, rsCreate,
 * rsDropWorld). Hilfsfunktionen hier mit ug-Praefix, weil alle Suiten einen
 * PHP-Prozess teilen und Funktionsnamen eindeutig sein muessen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function ugCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden: " . $res['raw']);
    $id = (int) $res['body']['id'];
    assertTrue($id > 0, "{$resource} lieferte keine brauchbare ID: " . $res['raw']);

    return $id;
}

function ugDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

/**
 * Welt aus Gruppe (haengt an der Terminart), Terminart, Mitglied (in der
 * Gruppe) und einem Termin heute. Untergruppen kommen je Test einzeln dazu,
 * weil die Tests unterschiedlich viele brauchen (ugAddGroupToMember).
 */
function ugWorld(string $label): array
{
    $suffix = uniqid();
    $world  = ['group' => null, 'type' => null, 'member' => null, 'appointment' => null, 'extraGroups' => []];

    try {
        $world['group'] = ugCreate('member_groups', ['group_name' => "UG {$label} Gruppe {$suffix}"]);
        $world['type']  = ugCreate('appointment_types', [
            'type_name'  => "UG {$label} {$suffix}",
            'is_default' => false,
            'color'      => '#667eea',
            'group_ids'  => [$world['group']],
        ]);
        $world['member'] = ugCreate('members', [
            'name'      => 'Ug',
            'surname'   => "Test {$label} {$suffix}",
            'active'    => 1,
            'group_ids' => [$world['group']],
        ]);
        $world['appointment'] = ugCreate('appointments', [
            'title'      => 'UG-Termin',
            'date'       => date('Y-m-d'),
            'start_time' => '19:00:00',
            'type_id'    => $world['type'],
        ]);
    } catch (Throwable $e) {
        ugDropWorld($world);
        throw $e;
    }

    return $world;
}

function ugMemberGroupIds(int $memberId): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    assertTrue(isset($res['body']['groups']) && is_array($res['body']['groups']),
        "members lieferte kein groups-Array fuer Mitglied {$memberId}: " . $res['raw']);

    return array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups']);
}

function ugSetMemberGroups(int $memberId, array $groupIds): void
{
    assertStatus(200, apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
        'body'  => ['group_ids' => $groupIds],
    ]), "Gruppen von Mitglied {$memberId} konnten nicht gesetzt werden");
}

/**
 * Legt eine weitere Gruppe an (Untergruppe oder gewoehnliche Gruppe -- nie
 * an die Terminart der Welt gehaengt) und haengt das Mitglied der Welt
 * hinein. Wird von ugDropWorld() mit aufgeraeumt.
 */
function ugAddGroupToMember(array &$world, string $label, bool $isSubgroup, int $sortOrder): int
{
    $suffix  = uniqid();
    $groupId = ugCreate('member_groups', [
        'group_name'  => "UG {$label} {$suffix}",
        'is_subgroup' => $isSubgroup,
        'sort_order'  => $sortOrder,
    ]);
    $world['extraGroups'][] = $groupId;

    $current = ugMemberGroupIds($world['member']);
    ugSetMemberGroups($world['member'], array_values(array_unique(array_merge($current, [$groupId]))));

    return $groupId;
}

function ugDropWorld(array $world): void
{
    if ($world['appointment'] !== null) {
        ugDelete('appointments', $world['appointment']);
    }
    if ($world['member'] !== null) {
        ugDelete('members', $world['member']);
    }
    if ($world['type'] !== null) {
        ugDelete('appointment_types', $world['type']);
    }
    foreach ($world['extraGroups'] ?? [] as $groupId) {
        ugDelete('member_groups', $groupId);
    }
    if ($world['group'] !== null) {
        ugDelete('member_groups', $world['group']);
    }
}

/** Holt die attendance_list-Zeile genau eines Mitglieds zu einem Termin. */
function ugAttendanceRow(int $appointmentId, int $memberId): array
{
    $res = apiRequest('GET', 'attendance_list', ['token' => apiToken('admin'), 'query' => ['appointment_id' => $appointmentId]]);
    assertStatus(200, $res);

    $rows = array_values(array_filter($res['body']['members'], static fn ($m) => (int) $m['member_id'] === $memberId));
    assertSame(1, count($rows), "Mitglied {$memberId} muss genau einmal in der Anwesenheitsliste stehen: " . $res['raw']);

    return $rows[0];
}

test('attendance_list: Untergruppen kommen mit, auch ohne Bezug zur Terminart', function () {
    $welt = ugWorld('Basis');
    try {
        $subgroupId = ugAddGroupToMember($welt, 'Register', true, 20);

        $zeile = ugAttendanceRow($welt['appointment'], $welt['member']);

        assertTrue(is_array($zeile['groups'] ?? null), 'groups muss ein Array sein');
        assertSame([$welt['group']], array_map(static fn ($g) => (int) $g['group_id'], $zeile['groups']),
            'groups muss genau die Terminart-Gruppe enthalten');

        assertTrue(is_array($zeile['subgroups'] ?? null), 'subgroups muss ein Array sein');
        assertSame(1, count($zeile['subgroups']), 'subgroups muss genau die Untergruppe enthalten');
        assertSame($subgroupId, (int) $zeile['subgroups'][0]['group_id']);
        assertSame(20, (int) $zeile['subgroups'][0]['sort_order']);
    } finally {
        ugDropWorld($welt);
    }
});

test('attendance_list: zwei Untergruppen kommen beide, das Mitglied nur einmal', function () {
    $welt = ugWorld('Zwei');
    try {
        $spaet = ugAddGroupToMember($welt, 'Spaet', true, 20);
        $frueh = ugAddGroupToMember($welt, 'Frueh', true, 10);

        // ugAttendanceRow prueft bereits, dass das Mitglied genau einmal vorkommt.
        $zeile = ugAttendanceRow($welt['appointment'], $welt['member']);

        $ids = array_map(static fn ($g) => (int) $g['group_id'], $zeile['subgroups']);
        assertSame([$frueh, $spaet], $ids, 'beide Untergruppen, sortiert nach sort_order (10 vor 20)');
    } finally {
        ugDropWorld($welt);
    }
});

test('attendance_list: eine nicht markierte Gruppe steht nicht in subgroups', function () {
    $welt = ugWorld('Gewoehnlich');
    try {
        $gewoehnlich = ugAddGroupToMember($welt, 'Gewoehnlich', false, 0);

        $zeile = ugAttendanceRow($welt['appointment'], $welt['member']);

        $ids = array_map(static fn ($g) => (int) $g['group_id'], $zeile['subgroups']);
        assertTrue(!in_array($gewoehnlich, $ids, true), 'nicht markierte Gruppe darf nicht in subgroups stehen');
        assertSame([], $zeile['subgroups'], 'subgroups muss leer bleiben, wenn keine Untergruppe zugeordnet ist');
    } finally {
        ugDropWorld($welt);
    }
});
