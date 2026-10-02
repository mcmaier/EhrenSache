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
 * Feld staffing der Terminrueckmeldung (Spec 2026-10-01, 5.1): Verwalter
 * bekommen es in Einzel- und Listenantwort, Mitglieder nie.
 *
 * Die Listenantwort zeigt nur Termine, zu denen das angemeldete Mitglied
 * erwartet wird -- das Mitglied des Testkontos manager wird deshalb fuer die
 * Dauer des Tests in die Gruppe der Welt genommen und danach zurueckgesetzt.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function rstCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");
    $id = (int) ($res['body']['id'] ?? 0);
    assertTrue($id > 0, "{$resource} lieferte keine brauchbare ID: " . $res['raw']);

    return $id;
}

function rstMemberGroups(int $memberId): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);

    return array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups']);
}

function rstSetMemberGroups(int $memberId, array $groupIds): void
{
    assertStatus(200, apiRequest('PUT', 'members', ['token' => apiToken('admin'),
        'query' => ['id' => $memberId], 'body' => ['group_ids' => $groupIds]]));
}

test('staffing: Verwalter bekommt Besetzung je Register, Mitglied nicht', function () {
    $s     = uniqid();
    $ids   = ['appointments' => [], 'members' => [], 'groups' => [], 'types' => []];
    $mgrId = apiMemberId('manager');
    $usrId = apiMemberId('user');
    assertTrue($mgrId !== null && $usrId !== null, 'Testkonten ohne Mitglied');
    $mgrVorher = rstMemberGroups($mgrId);
    $usrVorher = rstMemberGroups($usrId);

    try {
        $g  = $ids['groups'][] = rstCreate('member_groups', ['group_name' => "RST G {$s}"]);
        $k  = $ids['groups'][] = rstCreate('member_groups', ['group_name' => "RST Kla {$s}", 'is_subgroup' => true, 'sort_order' => 1]);
        $x  = $ids['groups'][] = rstCreate('member_groups', ['group_name' => "RST Sax {$s}", 'is_subgroup' => true, 'sort_order' => 2]);
        $t  = $ids['types'][]  = rstCreate('appointment_types', ['type_name' => "RST {$s}", 'is_default' => 0,
            'color' => '#667eea', 'group_ids' => [$g], 'responses_enabled' => 1, 'responses_names_visible' => 1]);

        $m1 = $ids['members'][] = rstCreate('members', ['name' => 'Rst', 'surname' => "1 {$s}", 'active' => 1, 'group_ids' => [$g, $k]]);
        $m2 = $ids['members'][] = rstCreate('members', ['name' => 'Rst', 'surname' => "2 {$s}", 'active' => 1, 'group_ids' => [$g, $k, $x]]);
        $m3 = $ids['members'][] = rstCreate('members', ['name' => 'Rst', 'surname' => "3 {$s}", 'active' => 1, 'group_ids' => [$g]]);

        // Verwalter und Mitglied in die Gruppe der Welt -- ohne Register.
        rstSetMemberGroups($mgrId, array_values(array_unique(array_merge($mgrVorher, [$g]))));
        rstSetMemberGroups($usrId, array_values(array_unique(array_merge($usrVorher, [$g]))));

        $date = date('Y-m-d', strtotime('+3 days'));
        $apt  = $ids['appointments'][] = rstCreate('appointments', ['title' => "RST {$s}", 'date' => $date,
            'start_time' => '19:00:00', 'type_id' => $t]);

        $put1 = apiRequest('PUT', 'appointment_responses', ['token' => apiToken('admin'),
            'query' => ['appointment_id' => $apt, 'member_id' => $m1], 'body' => ['status' => 'yes']]);
        assertStatus(200, $put1);
        assertTrue(is_array($put1['body']['staffing'] ?? null) && $put1['body']['staffing'] !== [],
            'staffing fehlt in der PUT-Antwort des Verwalters');
        assertStatus(200, apiRequest('PUT', 'appointment_responses', ['token' => apiToken('admin'),
            'query' => ['appointment_id' => $apt, 'member_id' => $m2], 'body' => ['status' => 'maybe']]));

        // Einzelantwort, Verwalter
        $one = apiRequest('GET', 'appointment_responses', ['token' => apiToken('manager'), 'query' => ['appointment_id' => $apt]]);
        assertStatus(200, $one);
        $st = $one['body']['staffing'] ?? null;
        assertTrue(is_array($st), 'staffing fehlt in der Einzelantwort: ' . substr($one['raw'], 0, 300));

        $byName = [];
        foreach ($st as $row) { $byName[$row['name'] ?? '(ohne)'] = $row; }
        assertSame(['group_id' => $k, 'name' => "RST Kla {$s}", 'expected' => 2, 'yes' => 1, 'maybe' => 1, 'no' => 0, 'open' => 0, 'shared' => 1],
            $byName["RST Kla {$s}"] ?? null);
        assertSame(['group_id' => $x, 'name' => "RST Sax {$s}", 'expected' => 1, 'yes' => 0, 'maybe' => 1, 'no' => 0, 'open' => 0, 'shared' => 1],
            $byName["RST Sax {$s}"] ?? null);
        // Abschlusszeile: m3 sicher; Verwalter und Testmitglied nur, wenn sie
        // im Bestand keinem Register angehoeren -- deshalb ">= 1", nicht "= 3".
        // Ihre echten Register koennen als weitere Zeilen auftauchen.
        $ohne = $byName['(ohne)'] ?? null;
        assertTrue($ohne !== null && $ohne['expected'] >= 1, 'Abschlusszeile fehlt oder ist leer');
        assertSame(null, $ohne['group_id']);
        assertSame(null, end($st)['group_id'], 'Abschlusszeile steht am Ende');

        // Listenantwort (Check-in-App), Verwalter
        $list = apiRequest('GET', 'appointment_responses', ['token' => apiToken('manager'), 'query' => ['upcoming' => 1, 'with_info' => 1]]);
        assertStatus(200, $list);
        $item = null;
        foreach ($list['body']['appointments'] as $a) {
            if ((int) $a['appointment']['appointment_id'] === $apt) { $item = $a; }
        }
        assertTrue($item !== null, 'Termin fehlt in der Liste des Verwalters');
        assertSame($st, $item['staffing'] ?? null, 'Liste und Einzelantwort muessen dieselbe Besetzung liefern');

        // Mitglied: nie, auch nicht bei sichtbaren Namen
        $u1 = apiRequest('GET', 'appointment_responses', ['token' => apiToken('user'), 'query' => ['appointment_id' => $apt]]);
        assertStatus(200, $u1);
        assertTrue(!array_key_exists('staffing', $u1['body']), 'Mitglied darf staffing nicht sehen (Einzelantwort)');
        $u2 = apiRequest('GET', 'appointment_responses', ['token' => apiToken('user'), 'query' => ['upcoming' => 1, 'with_info' => 1]]);
        assertStatus(200, $u2);
        foreach ($u2['body']['appointments'] as $a) {
            assertTrue(!array_key_exists('staffing', $a), 'Mitglied darf staffing nicht sehen (Liste)');
        }

        // PUT-Antwort: Mitglied traegt sich selbst ein -- kein staffing
        $own = apiRequest('PUT', 'appointment_responses', ['token' => apiToken('user'),
            'query' => ['appointment_id' => $apt], 'body' => ['status' => 'yes']]);
        assertStatus(200, $own);
        assertTrue(!array_key_exists('staffing', $own['body']), 'Mitglied darf staffing nicht sehen (PUT-Antwort)');
    } catch (Throwable $failure) {
        throw $failure;
    } finally {
        // Wiederherstellung darf das Aufraeumen nicht verhindern; ein Fehler
        // wird erst danach geworfen (eine vorhandene Ausnahme hat Vorrang).
        $restoreErrors = [];
        foreach ([[$mgrId, $mgrVorher], [$usrId, $usrVorher]] as [$mid, $groups]) {
            try {
                rstSetMemberGroups($mid, $groups);
            } catch (Throwable $e) {
                $restoreErrors[] = "Gruppen von Mitglied {$mid} nicht wiederhergestellt: " . $e->getMessage();
            }
        }
        foreach ($ids['appointments'] as $id) { apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $id]]); }
        foreach ($ids['members'] as $id)      { apiRequest('DELETE', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $id]]); }
        foreach ($ids['types'] as $id)        { apiRequest('DELETE', 'appointment_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]); }
        foreach ($ids['groups'] as $id)       { apiRequest('DELETE', 'member_groups', ['token' => apiToken('admin'), 'query' => ['id' => $id]]); }
        if ($restoreErrors !== [] && !isset($failure)) {
            throw new RuntimeException(implode('; ', $restoreErrors));
        }
    }
});
