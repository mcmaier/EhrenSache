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
 * Besetzung in der Terminrueckmeldung (Spec 2026-10-02, 6): `staffing` steht in
 * keiner JSON-Antwort mehr; Verwalter bekommen in der Listenantwort der App die
 * Namensliste (`members` mit Untergruppen), der Druck bildet die Besetzung daraus.
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
        $k  = $ids['groups'][] = rstCreate('member_groups', ['group_name' => "RST Kla {$s}", 'is_subgroup' => true, 'sort_order' => 1, 'parent_group_ids' => [$g]]);
        $x  = $ids['groups'][] = rstCreate('member_groups', ['group_name' => "RST Sax {$s}", 'is_subgroup' => true, 'sort_order' => 2, 'parent_group_ids' => [$g]]);
        $t  = $ids['types'][]  = rstCreate('appointment_types', ['type_name' => "RST {$s}", 'is_default' => 0,
            'color' => '#667eea', 'group_ids' => [$g], 'responses_enabled' => 1, 'responses_names_visible' => 1]);

        $t2 = $ids['types'][]  = rstCreate('appointment_types', ['type_name' => "RST ohne Namen {$s}", 'is_default' => 0,
            'color' => '#667eea', 'group_ids' => [$g], 'responses_enabled' => 1, 'responses_names_visible' => 0]);

        $m1 = $ids['members'][] = rstCreate('members', ['name' => 'Rst', 'surname' => "1 {$s}", 'active' => 1, 'group_ids' => [$g, $k]]);
        $m2 = $ids['members'][] = rstCreate('members', ['name' => 'Rst', 'surname' => "2 {$s}", 'active' => 1, 'group_ids' => [$g, $k, $x]]);
        $m3 = $ids['members'][] = rstCreate('members', ['name' => 'Rst', 'surname' => "3 {$s}", 'active' => 1, 'group_ids' => [$g]]);

        // Verwalter und Mitglied in die Gruppe der Welt -- ohne Register.
        rstSetMemberGroups($mgrId, array_values(array_unique(array_merge($mgrVorher, [$g]))));
        rstSetMemberGroups($usrId, array_values(array_unique(array_merge($usrVorher, [$g]))));

        $date = date('Y-m-d', strtotime('+3 days'));
        $apt  = $ids['appointments'][] = rstCreate('appointments', ['title' => "RST {$s}", 'date' => $date,
            'start_time' => '19:00:00', 'type_id' => $t]);
        $apt2 = $ids['appointments'][] = rstCreate('appointments', ['title' => "RST ohne Namen {$s}", 'date' => $date,
            'start_time' => '20:00:00', 'type_id' => $t2]);

        $put1 = apiRequest('PUT', 'appointment_responses', ['token' => apiToken('admin'),
            'query' => ['appointment_id' => $apt, 'member_id' => $m1], 'body' => ['status' => 'yes']]);
        assertStatus(200, $put1);
        assertTrue(!array_key_exists('staffing', $put1['body']), 'staffing darf in der PUT-Antwort nicht mehr stehen');
        assertStatus(200, apiRequest('PUT', 'appointment_responses', ['token' => apiToken('admin'),
            'query' => ['appointment_id' => $apt, 'member_id' => $m2], 'body' => ['status' => 'maybe']]));

        // Einzelantwort, Verwalter
        $one = apiRequest('GET', 'appointment_responses', ['token' => apiToken('manager'), 'query' => ['appointment_id' => $apt]]);
        assertStatus(200, $one);
        assertTrue(!array_key_exists('staffing', $one['body']), 'staffing darf in der Einzelantwort nicht mehr stehen');

        // Listenantwort (Check-in-App), Verwalter: Namensliste fuer die Besetzung
        $list = apiRequest('GET', 'appointment_responses', ['token' => apiToken('manager'), 'query' => ['upcoming' => 1, 'with_info' => 1]]);
        assertStatus(200, $list);
        $item = null;
        $item2 = null;
        foreach ($list['body']['appointments'] as $a) {
            assertTrue(!array_key_exists('staffing', $a), 'staffing darf in der Liste nicht mehr stehen');
            if ((int) $a['appointment']['appointment_id'] === $apt) { $item = $a; }
            if ((int) $a['appointment']['appointment_id'] === $apt2) { $item2 = $a; }
        }
        assertTrue($item !== null, 'Termin fehlt in der Liste des Verwalters');
        assertTrue(is_array($item['members'] ?? null), 'Verwalter bekommt in der Liste keine members');
        $byId = [];
        foreach ($item['members'] as $mm) { $byId[(int) $mm['member_id']] = $mm; }
        foreach ([$m1, $m2, $m3] as $mid) {
            assertTrue(isset($byId[$mid]), "Mitglied {$mid} fehlt in members der Liste");
            $keys = array_keys($byId[$mid]);
            sort($keys);
            assertSame(['group_name', 'groups', 'member_id', 'name', 'status', 'subgroups', 'surname'], $keys,
                'Felder der Namensliste in der Listenantwort');
        }
        assertSame('yes', $byId[$m1]['status']);
        assertSame('maybe', $byId[$m2]['status']);
        assertSame([$k, $x], array_map(static fn ($sg) => (int) $sg['group_id'], $byId[$m2]['subgroups']));
        assertSame([], $byId[$m3]['subgroups']);

        // Terminart ohne "Namen sichtbar": der Verwalter bekommt die Liste trotzdem
        assertTrue($item2 !== null && is_array($item2['members'] ?? null), 'Verwalter: members fehlen bei Terminart ohne sichtbare Namen');

        // Mitglied: nie, auch nicht bei sichtbaren Namen
        $u1 = apiRequest('GET', 'appointment_responses', ['token' => apiToken('user'), 'query' => ['appointment_id' => $apt]]);
        assertStatus(200, $u1);
        assertTrue(!array_key_exists('staffing', $u1['body']), 'Mitglied darf staffing nicht sehen (Einzelantwort)');
        assertTrue(is_array($u1['body']['members'] ?? null), 'Mitglied sieht bei sichtbaren Namen die Liste');
        $u2 = apiRequest('GET', 'appointment_responses', ['token' => apiToken('user'), 'query' => ['upcoming' => 1, 'with_info' => 1]]);
        assertStatus(200, $u2);
        foreach ($u2['body']['appointments'] as $a) {
            assertTrue(!array_key_exists('staffing', $a), 'Mitglied darf staffing nicht sehen (Liste)');
            if ((int) $a['appointment']['appointment_id'] === $apt2) {
                assertTrue(!array_key_exists('members', $a), 'Mitglied ohne sichtbare Namen: members in der Liste');
            }
        }
        $u3 = apiRequest('GET', 'appointment_responses', ['token' => apiToken('user'), 'query' => ['appointment_id' => $apt2]]);
        assertStatus(200, $u3);
        assertTrue(!array_key_exists('members', $u3['body']), 'Mitglied ohne sichtbare Namen: members in der Einzelantwort');

        // PUT-Antwort: Mitglied traegt sich selbst ein -- kein staffing
        $own = apiRequest('PUT', 'appointment_responses', ['token' => apiToken('user'),
            'query' => ['appointment_id' => $apt], 'body' => ['status' => 'yes']]);
        assertStatus(200, $own);
        assertTrue(!array_key_exists('staffing', $own['body']), 'Mitglied darf staffing nicht sehen (PUT-Antwort)');

        // Druck: Besetzung als eigener Abschnitt am Kopf
        $print = apiRequest('GET', 'appointment_responses', ['token' => apiToken('manager'),
            'query' => ['appointment_id' => $apt, 'format' => 'html']]);
        assertStatus(200, $print);
        $html = $print['raw'];
        $posBesetzung = strpos($html, '>Besetzung<');
        $posKla       = strpos($html, "RST Kla {$s}");
        assertTrue($posBesetzung !== false, 'Abschnitt Besetzung fehlt im Druck');
        assertTrue($posKla !== false && $posKla > $posBesetzung, 'Register steht im Besetzungsabschnitt');
        assertTrue(preg_match('#RST Kla ' . preg_quote($s, '#') . '</td>\s*<td>1 von 2</td>#', $html) === 1,
            'Zahl "1 von 2" in der Zeile des ersten Registers fehlt');
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
