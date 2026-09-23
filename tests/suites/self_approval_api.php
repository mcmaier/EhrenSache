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
 * Selbstgenehmigung von Anträgen (OI-87).
 *
 * Regel: Den eigenen Antrag genehmigt niemand, solange es ein anderes aktives
 * Konto mit Rolle admin oder manager gibt. Maßgeblich ist das Konto, nicht ein
 * verknüpftes Mitglied — freigeben darf auch ein Admin ohne eigenes Mitglied.
 * Ablehnen und Löschen bleiben erlaubt.
 *
 * Der Testbestand hat admin (ohne Mitglied) und manager (mit Mitglied), also
 * gibt es immer einen zweiten Freigebenden. Den Fall „einziger Verwalter" deckt
 * die Einheitenprüfung ab; ihn hier herzustellen hieße, das Admin-Konto
 * stillzulegen.
 */

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** Ein vergangener Termin mit eigener Terminart — ohne Dublettenrisiko. */
function saTempAppointment(string $token, string $suffix): array
{
    $group = apiRequest('POST', 'member_groups', ['token' => $token,
        'body' => ['group_name' => "SA {$suffix}"]]);
    assertStatus(201, $group);
    $groupId = (int) $group['body']['id'];

    $type = apiRequest('POST', 'appointment_types', ['token' => $token, 'body' => [
        'type_name' => "SA {$suffix}", 'is_default' => 0, 'color' => '#667eea',
        'group_ids' => [$groupId]]]);
    assertStatus(201, $type);
    $typeId = (int) $type['body']['id'];

    $apt = apiRequest('POST', 'appointments', ['token' => $token, 'body' => [
        'title' => 'SA-Test', 'date' => date('Y-m-d', strtotime('-3 days')),
        'start_time' => '19:00:00', 'type_id' => $typeId]]);
    assertStatus(201, $apt);

    return ['appointment' => (int) $apt['body']['id'], 'type' => $typeId, 'group' => $groupId];
}

function saDrop(string $token, array $welt): void
{
    apiRequest('DELETE', 'appointments', ['token' => $token, 'query' => ['id' => $welt['appointment']]]);
    apiRequest('DELETE', 'appointment_types', ['token' => $token, 'query' => ['id' => $welt['type']]]);
    apiRequest('DELETE', 'member_groups', ['token' => $token, 'query' => ['id' => $welt['group']]]);
}

test('Den eigenen Antrag genehmigt ein Manager nicht, solange ein zweiter Verwalter da ist (OI-87)', function () {
    $admin   = apiToken('admin');
    $manager = apiToken('manager');
    $member  = apiMemberId('manager');
    assertTrue($member !== null, 'Das Konto "manager" braucht ein verknuepftes Mitglied');

    $welt = saTempAppointment($admin, uniqid());

    try {
        $antrag = apiRequest('POST', 'exceptions', ['token' => $manager, 'body' => [
            'member_id' => $member, 'appointment_id' => $welt['appointment'],
            'exception_type' => 'absence', 'reason' => 'SA-Test', 'status' => 'pending']]);
        assertStatus(201, $antrag);
        $id = (int) $antrag['body']['id'];

        $selbst = apiRequest('PUT', 'exceptions', ['token' => $manager,
            'query' => ['id' => $id], 'body' => ['status' => 'approved']]);
        assertStatus(403, $selbst, 'Selbstgenehmigung trotz zweitem Verwalter');

        // Der Antrag steht danach unveraendert offen
        $stand = apiRequest('GET', 'exceptions', ['token' => $admin, 'query' => ['id' => $id]]);
        assertSame('pending', $stand['body']['status'], 'Der Status darf sich nicht geaendert haben');

        // Ein anderer Verwalter darf
        assertStatus(200, apiRequest('PUT', 'exceptions', ['token' => $admin,
            'query' => ['id' => $id], 'body' => ['status' => 'approved']]),
            'Der zweite Verwalter muss genehmigen duerfen');
    } finally {
        saDrop($admin, $welt);
    }
});

test('Den eigenen Antrag darf man ablehnen und loeschen (OI-87)', function () {
    $admin   = apiToken('admin');
    $manager = apiToken('manager');
    $member  = apiMemberId('manager');
    $welt = saTempAppointment($admin, uniqid());

    try {
        $antrag = apiRequest('POST', 'exceptions', ['token' => $manager, 'body' => [
            'member_id' => $member, 'appointment_id' => $welt['appointment'],
            'exception_type' => 'absence', 'reason' => 'SA-Test', 'status' => 'pending']]);
        assertStatus(201, $antrag);
        $id = (int) $antrag['body']['id'];

        assertStatus(200, apiRequest('PUT', 'exceptions', ['token' => $manager,
            'query' => ['id' => $id], 'body' => ['status' => 'rejected']]),
            'Ablehnen des eigenen Antrags bleibt erlaubt');

        assertStatus(200, apiRequest('DELETE', 'exceptions', ['token' => $manager,
            'query' => ['id' => $id]]), 'Loeschen des eigenen Antrags bleibt erlaubt');
    } finally {
        saDrop($admin, $welt);
    }
});

test('attendance_list sagt, ob die Selbstgenehmigung gesperrt ist (OI-87)', function () {
    $admin = apiToken('admin');
    $welt  = saTempAppointment($admin, uniqid());

    try {
        $res = apiRequest('GET', 'attendance_list', ['token' => $admin,
            'query' => ['appointment_id' => $welt['appointment']]]);
        assertStatus(200, $res);
        assertTrue(array_key_exists('self_approval_blocked', $res['body']),
            'Das Flag fehlt — die Oberflaechen koennen die Regel sonst nicht spiegeln');
        assertSame(true, $res['body']['self_approval_blocked'],
            'Im Testbestand gibt es admin und manager, also einen zweiten Verwalter');
    } finally {
        saDrop($admin, $welt);
    }
});

test('Die Antragsliste kennzeichnet eine Selbstgenehmigung (OI-87)', function () {
    $admin   = apiToken('admin');
    $manager = apiToken('manager');
    $member  = apiMemberId('manager');
    $welt = saTempAppointment($admin, uniqid());

    try {
        // Vom Verwalter fuer sich selbst angelegt und von ihm genehmigt gibt es
        // nicht mehr; hier genehmigt der Admin — das ist keine Selbstgenehmigung.
        $antrag = apiRequest('POST', 'exceptions', ['token' => $manager, 'body' => [
            'member_id' => $member, 'appointment_id' => $welt['appointment'],
            'exception_type' => 'absence', 'reason' => 'SA-Test', 'status' => 'pending']]);
        $id = (int) $antrag['body']['id'];
        assertStatus(200, apiRequest('PUT', 'exceptions', ['token' => $admin,
            'query' => ['id' => $id], 'body' => ['status' => 'approved']]));

        $liste = apiRequest('GET', 'exceptions', ['token' => $admin, 'query' => ['id' => $id]]);
        assertStatus(200, $liste);
        assertTrue(array_key_exists('self_approved', $liste['body']),
            'Das Feld self_approved fehlt');
        assertSame(false, (bool) $liste['body']['self_approved'],
            'Der Admin ist nicht das Mitglied des Antrags');
    } finally {
        saDrop($admin, $welt);
    }
});
