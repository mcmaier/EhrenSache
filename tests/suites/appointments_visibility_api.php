<?php
/**
 * Gruppengrenze beim Abruf der Terminliste.
 *
 * Ein Mitglied sieht nur Termine, deren Terminart einer seiner Gruppen
 * zugeordnet ist (Testplan APT-GET-2). Der Parameter member_id grenzt die
 * Liste fuer Verwalter auf die Gruppen eines bestimmten Mitglieds ein --
 * fuer die Rolle user darf er die Grenze nicht verschieben, sonst liest ein
 * Mitglied mit einer fremden ID die Termine fremder Gruppen.
 *
 * Die Welt hier ist eine eigene Gruppe mit Terminart, Mitglied und Termin.
 * Das Mitglied des Kontos "user" gehoert ihr nicht an.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function avCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");
    $id = (int) $res['body']['id'];
    assertTrue($id > 0, "{$resource} lieferte keine brauchbare ID: " . $res['raw']);

    return $id;
}

function avDelete(string $resource, ?int $id): void
{
    if ($id !== null) {
        apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    }
}

/** @return int[] appointment_ids der Liste */
function avListIds(string $role, array $query): array
{
    $res = apiRequest('GET', 'appointments', ['token' => apiToken($role), 'query' => $query]);
    assertStatus(200, $res, "Terminliste fuer {$role} nicht abrufbar");
    assertTrue(is_array($res['body']), 'Terminliste ist kein Array: ' . $res['raw']);

    return array_map(static fn ($a) => (int) $a['appointment_id'], $res['body']);
}

test('Fremde member_id verschiebt die Gruppengrenze fuer user nicht', function () {
    assertTrue(apiMemberId('user') !== null, 'Das Konto "user" braucht ein verknuepftes Mitglied');

    $suffix = uniqid();
    $group = $type = $member = $appointment = null;

    try {
        $group  = avCreate('member_groups', ['group_name' => "AV {$suffix}"]);
        $type   = avCreate('appointment_types', [
            'type_name'  => "AV {$suffix}",
            'is_default' => 0,
            'color'      => '#667eea',
            'group_ids'  => [$group],
        ]);
        $member = avCreate('members', [
            'name'      => 'Av',
            'surname'   => "Fremd {$suffix}",
            'active'    => 1,
            'group_ids' => [$group],
        ]);
        $appointment = avCreate('appointments', [
            'title'      => 'AV-Termin fremde Gruppe',
            'date'       => '2026-11-15',
            'start_time' => '19:00',
            'type_id'    => $type,
        ]);

        $filter = ['from_date' => '2026-11-15', 'to_date' => '2026-11-15'];

        // Gegenprobe: Der Verwalter sieht den Termin ueber die member_id.
        assertTrue(in_array($appointment, avListIds('admin', $filter + ['member_id' => $member]), true),
                   'Admin muss den Termin ueber member_id des Gruppenmitglieds sehen');

        // Ohne Parameter sieht das Mitglied ihn nicht -- das galt schon immer.
        assertTrue(!in_array($appointment, avListIds('user', $filter), true),
                   'user sieht einen Termin einer fremden Gruppe');

        // Der eigentliche Fall: fremde member_id als user.
        assertTrue(!in_array($appointment, avListIds('user', $filter + ['member_id' => $member]), true),
                   'user sieht ueber eine fremde member_id die Termine fremder Gruppen');
    } finally {
        avDelete('appointments', $appointment);
        avDelete('members', $member);
        avDelete('appointment_types', $type);
        avDelete('member_groups', $group);
    }
});

test('Eigene member_id liefert fuer user weiter die eigenen Termine', function () {
    // Die Check-in-PWA ruft die Liste mit der eigenen member_id ab
    // (public/checkin/js/app.js, loadAppointments). Das muss gleich bleiben.
    $own = apiMemberId('user');
    assertTrue($own !== null, 'Das Konto "user" braucht ein verknuepftes Mitglied');

    $filter = ['from_date' => '2026-01-01', 'to_date' => '2026-12-31'];
    $ohne = avListIds('user', $filter);
    $mit  = avListIds('user', $filter + ['member_id' => $own]);

    sort($ohne);
    sort($mit);
    assertSame($ohne, $mit, 'Mit eigener member_id muss dieselbe Liste kommen wie ohne');
});
