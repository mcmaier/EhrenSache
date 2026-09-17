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
    $world  = ['group' => null, 'type' => null, 'member' => null, 'appointment' => null,
               'extraGroups' => [], 'typeGroups' => []];

    try {
        $world['group']      = ugCreate('member_groups', ['group_name' => "UG {$label} Gruppe {$suffix}"]);
        $world['typeGroups'] = [$world['group']];
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

/**
 * Haengt eine weitere Gruppe direkt der Terminart der Welt an (volles Update
 * von group_ids, wie appointment_types.php es verlangt) und nimmt das
 * Mitglied der Welt mit hinein -- sonst waere es fuer die neue Gruppe gar
 * nicht erwartet. Bildet den Bugbericht nach: eine Terminart mit einer
 * gewoehnlichen Gruppe UND einem direkt zugeordneten Register.
 */
function ugAddGroupToType(array &$world, string $label, bool $isSubgroup, int $sortOrder): int
{
    $groupId = ugCreate('member_groups', [
        'group_name'  => "UG {$label} " . uniqid(),
        'is_subgroup' => $isSubgroup,
        'sort_order'  => $sortOrder,
    ]);
    $world['extraGroups'][] = $groupId;
    $world['typeGroups'][]  = $groupId;

    assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
        'query' => ['id' => $world['type']],
        'body'  => ['group_ids' => $world['typeGroups']],
    ]), "Gruppe {$groupId} konnte nicht an die Terminart gehaengt werden");

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

/**
 * Haengt das Mitglied des Kontos "user" fuer $fn in die Gruppe der Welt.
 * Wie rsWithUserInWorld() in responses_api.php -- eigene Kopie, weil jede
 * Suite fuer sich allein laufen koennen muss (tests/run.php subgroups_api
 * laedt responses_api.php nicht mit).
 */
function ugWithUserInWorld(array $world, callable $fn): void
{
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    $original  = ugMemberGroupIds($memberId);
    $bodyError = null;

    try {
        ugSetMemberGroups($memberId, array_values(array_unique(array_merge($original, [$world['group']]))));
        $fn($memberId);
    } catch (Throwable $e) {
        $bodyError = $e;
    } finally {
        try {
            ugSetMemberGroups($memberId, $original);
        } catch (Throwable $restoreError) {
            if ($bodyError !== null) {
                throw new RuntimeException(
                    'Testkoerper: ' . $bodyError->getMessage()
                    . ' | Wiederherstellung der Gruppen: ' . $restoreError->getMessage()
                );
            }
            throw $restoreError;
        }
    }

    if ($bodyError !== null) {
        throw $bodyError;
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

// ----------------------------------------------------------------------
// Regression: eine Gruppe, die zugleich als Untergruppe markiert UND einer
// Terminart direkt zugeordnet ist, darf nicht in beiden Stufen erscheinen
// (Doppelanzeige, im manuellen Test gefunden -- Terminart "Aktive" plus
// mehrere Register). Die beiden Faelle des Bugberichts: gemischt (Gruppe UND
// Register an derselben Terminart) und die Registerprobe (Terminart hat NUR
// ein Register).
// ----------------------------------------------------------------------

test('attendance_list: Gruppe und direkt zugeordnetes Register stehen nur in ihrer eigenen Stufe', function () {
    $welt = ugWorld('Gemischt');
    try {
        $registerId = ugAddGroupToType($welt, 'Register', true, 10);

        $zeile = ugAttendanceRow($welt['appointment'], $welt['member']);

        assertSame([$welt['group']], array_map(static fn ($g) => (int) $g['group_id'], $zeile['groups']),
            'groups darf nur die gewoehnliche Gruppe enthalten, nicht das direkt zugeordnete Register');
        assertSame([$registerId], array_map(static fn ($g) => (int) $g['group_id'], $zeile['subgroups']),
            'subgroups muss das direkt zugeordnete Register enthalten');
    } finally {
        ugDropWorld($welt);
    }
});

/**
 * Registerprobe: eine Terminart mit NUR einem Register zugeordnet, keine
 * gewoehnliche Gruppe -- anders als ugWorld(), dessen Terminart-Gruppe nie
 * als Untergruppe markiert ist.
 */
function ugWorldSubgroupOnly(string $label): array
{
    $suffix = uniqid();
    $world  = ['group' => null, 'type' => null, 'member' => null, 'appointment' => null,
               'extraGroups' => [], 'typeGroups' => []];

    try {
        $world['group'] = ugCreate('member_groups', [
            'group_name'  => "UG {$label} Register {$suffix}",
            'is_subgroup' => true,
        ]);
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

test('attendance_list: Registerprobe -- Terminart mit nur einem Register liefert das erwartete Mitglied, groups bleibt leer', function () {
    $welt = ugWorldSubgroupOnly('Registerprobe');
    try {
        $zeile = ugAttendanceRow($welt['appointment'], $welt['member']);

        assertSame([], $zeile['groups'], 'groups muss leer bleiben, wenn die einzige zugeordnete Gruppe ein Register ist');
        assertSame([$welt['group']], array_map(static fn ($g) => (int) $g['group_id'], $zeile['subgroups']),
            'subgroups muss das Register enthalten');
    } finally {
        ugDropWorld($welt);
    }
});

test('appointment_responses: erwartete Mitglieder tragen Gruppen und Untergruppen', function () {
    $welt = ugWorld('Antwort');
    try {
        $welt['sub'] = ugAddGroupToMember($welt, 'Antwort-Sub', true, 15);

        // Terminart auf Rueckmeldung mit sichtbaren Namen stellen
        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']],
            'body'  => ['type_name' => 'UG-Antwort-Art', 'group_ids' => [$welt['group']],
                        'responses_enabled' => 1, 'responses_names_visible' => 1]]));

        $res = apiRequest('GET', 'appointment_responses', ['token' => apiToken('admin'),
                                                          'query' => ['appointment_id' => $welt['appointment']]]);
        assertStatus(200, $res);

        $treffer = array_values(array_filter($res['body']['members'],
            static fn ($m) => (int) $m['member_id'] === $welt['member']));
        assertSame(1, count($treffer), 'das Mitglied steht genau einmal in der Antwort');
        assertSame(1, count($treffer[0]['subgroups']));
        assertSame($welt['sub'], (int) $treffer[0]['subgroups'][0]['group_id']);
        assertSame($welt['group'], (int) $treffer[0]['groups'][0]['group_id']);
    } finally {
        ugDropWorld($welt);
    }
});

test('appointment_responses: ohne sichtbare Namen bleiben Gruppen und Untergruppen aus', function () {
    $welt = ugWorld('Verdeckt');
    try {
        $welt['sub'] = ugAddGroupToMember($welt, 'Verdeckt-Sub', true, 5);

        // Rueckmeldung aktiv, aber Namen NICHT sichtbar -- also auch keine
        // Zugehoerigkeiten, weder fuer die eigene Rueckmeldung noch als member.
        //
        // Achtung, Tautologie: Ohne responses_names_visible baut
        // responsesPayload() ueberhaupt keine 'members'-Liste (weder der
        // Admin/Manager- noch der Namen-Zweig greift), also kann sie auch
        // keine groups/subgroups enthalten -- unabhaengig davon, ob
        // groupsAttachToMembers() ueberhaupt existiert oder korrekt arbeitet.
        // Dieser Test waere schon vor deren Einfuehrung gruen gewesen. Er
        // bleibt trotzdem stehen, weil er einen dritten, heute nicht
        // existierenden Pfad absichert: eine kuenftige Aenderung, die
        // 'members' auch ohne Namensfreigabe fuellt (etwa fuer eine neue
        // Zusammenfassung), OHNE die Sichtbarkeitspruefung fuer
        // groups/subgroups nachzuziehen. Der Pfad, den dieser Commit
        // tatsaechlich neu versorgt -- Mitgliedskonto MIT Freigabe --, wird
        // vom naechsten Test geprueft.
        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']],
            'body'  => ['type_name' => 'UG-Verdeckt-Art', 'group_ids' => [$welt['group']],
                        'responses_enabled' => 1, 'responses_names_visible' => 0]]));

        ugWithUserInWorld($welt, function () use ($welt) {
            $res = apiRequest('GET', 'appointment_responses', ['token' => apiToken('user'),
                                                              'query' => ['appointment_id' => $welt['appointment']]]);
            assertStatus(200, $res);
            assertTrue(!isset($res['body']['members']),
                'ohne responses_names_visible darf ein Mitgliedskonto keine Mitgliederliste sehen');
        });
    } finally {
        ugDropWorld($welt);
    }
});

test('appointment_responses: Mitgliedskonto mit Freigabe sieht Gruppen und Untergruppen', function () {
    $welt = ugWorld('Freigegeben');
    try {
        $welt['sub'] = ugAddGroupToMember($welt, 'Freigegeben-Sub', true, 8);

        // Rueckmeldung aktiv UND Namen sichtbar -- genau der Pfad, den
        // responsesPayload() seit diesem Vorhaben mit groupsAttachToMembers()
        // versorgt (vorher gab es dort nur member_id, Name, Gruppe, Status).
        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']],
            'body'  => ['type_name' => 'UG-Freigegeben-Art', 'group_ids' => [$welt['group']],
                        'responses_enabled' => 1, 'responses_names_visible' => 1]]));

        ugWithUserInWorld($welt, function () use ($welt) {
            $res = apiRequest('GET', 'appointment_responses', ['token' => apiToken('user'),
                                                              'query' => ['appointment_id' => $welt['appointment']]]);
            assertStatus(200, $res);
            assertTrue(isset($res['body']['members']) && is_array($res['body']['members']),
                'mit responses_names_visible muss ein Mitgliedskonto die Mitgliederliste sehen: ' . $res['raw']);

            $treffer = array_values(array_filter($res['body']['members'],
                static fn ($m) => (int) $m['member_id'] === $welt['member']));
            assertSame(1, count($treffer), 'das erwartete Mitglied steht genau einmal in der Liste');
            assertSame(1, count($treffer[0]['subgroups'] ?? []));
            assertSame($welt['sub'], (int) $treffer[0]['subgroups'][0]['group_id']);
            assertSame($welt['group'], (int) $treffer[0]['groups'][0]['group_id']);
        });
    } finally {
        ugDropWorld($welt);
    }
});

test('appointment_responses: Gruppe und direkt zugeordnetes Register stehen nur in ihrer eigenen Stufe', function () {
    $welt = ugWorld('AntwortGemischt');
    try {
        $registerId = ugAddGroupToType($welt, 'AntwortRegister', true, 10);

        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']],
            'body'  => ['type_name' => 'UG-Antwort-Gemischt', 'group_ids' => $welt['typeGroups'],
                        'responses_enabled' => 1, 'responses_names_visible' => 1]]));

        $res = apiRequest('GET', 'appointment_responses', ['token' => apiToken('admin'),
                                                          'query' => ['appointment_id' => $welt['appointment']]]);
        assertStatus(200, $res);

        $treffer = array_values(array_filter($res['body']['members'],
            static fn ($m) => (int) $m['member_id'] === $welt['member']));
        assertSame(1, count($treffer), 'das erwartete Mitglied steht genau einmal in der Antwort');
        assertSame([$welt['group']], array_map(static fn ($g) => (int) $g['group_id'], $treffer[0]['groups']),
            'groups darf nur die gewoehnliche Gruppe enthalten, nicht das direkt zugeordnete Register');
        assertSame([$registerId], array_map(static fn ($g) => (int) $g['group_id'], $treffer[0]['subgroups']),
            'subgroups muss das direkt zugeordnete Register enthalten');
    } finally {
        ugDropWorld($welt);
    }
});

test('appointment_responses: Registerprobe -- Terminart mit nur einem Register liefert das erwartete Mitglied, groups bleibt leer', function () {
    $welt = ugWorldSubgroupOnly('AntwortRegisterprobe');
    try {
        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']],
            'body'  => ['type_name' => 'UG-Antwort-Registerprobe', 'group_ids' => [$welt['group']],
                        'responses_enabled' => 1, 'responses_names_visible' => 1]]));

        $res = apiRequest('GET', 'appointment_responses', ['token' => apiToken('admin'),
                                                          'query' => ['appointment_id' => $welt['appointment']]]);
        assertStatus(200, $res);

        $treffer = array_values(array_filter($res['body']['members'],
            static fn ($m) => (int) $m['member_id'] === $welt['member']));
        assertSame(1, count($treffer), 'das erwartete Mitglied steht genau einmal in der Antwort');
        assertSame([], $treffer[0]['groups'], 'groups muss leer bleiben, wenn die Terminart nur ein Register hat');
        assertSame([$welt['group']], array_map(static fn ($g) => (int) $g['group_id'], $treffer[0]['subgroups']),
            'subgroups muss das Register enthalten');
    } finally {
        ugDropWorld($welt);
    }
});

/**
 * GET settings liefert `{"settings": [{"setting_key":..., "setting_value":...}, ...]}`
 * -- eine Liste, kein flaches Objekt. Erst gegen die echte API geprueft, dann
 * hier nachgebildet, statt die Form zu raten.
 */
function ugSettingValue(string $key): string
{
    $res = apiRequest('GET', 'settings', ['token' => apiToken('admin')]);
    assertStatus(200, $res);

    $treffer = array_values(array_filter($res['body']['settings'] ?? [],
        static fn ($s) => ($s['setting_key'] ?? null) === $key));

    return (string) ($treffer[0]['setting_value'] ?? '');
}

test('settings: subgroup_label wird normalisiert und begrenzt', function () {
    $vorher = ugSettingValue('subgroup_label');

    try {
        assertStatus(200, apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
            'body' => ['setting_key' => 'subgroup_label', 'setting_value' => '  Register ']]));
        assertSame('Register', ugSettingValue('subgroup_label'), 'getrimmt gespeichert');

        assertStatus(200, apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
            'body' => ['setting_key' => 'subgroup_label', 'setting_value' => '   ']]));
        assertSame('Untergruppe', ugSettingValue('subgroup_label'), 'leer ergibt die Vorgabe');

        assertStatus(400, apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
            'body' => ['setting_key' => 'subgroup_label',
                       'setting_value' => str_repeat('A', 60)]]),
            'zu lang wird abgewiesen, nicht stillschweigend gekuerzt');

        assertStatus(200, apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
            'body' => ['setting_key' => 'subgroup_label', 'setting_value' => "Regi\nster"]]));
        assertSame('Register', ugSettingValue('subgroup_label'),
            'Steuerzeichen und Zeilenumbruch fallen weg, nicht roh gespeichert');
    } finally {
        // Geht ueber denselben Pruefpfad wie oben: ein leeres $vorher (Einstellung
        // existierte noch nicht) normalisiert sich dabei selbst zur Vorgabe --
        // kein Sonderfall noetig, damit der Zustand danach brauchbar bleibt.
        apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
            'body' => ['setting_key' => 'subgroup_label', 'setting_value' => $vorher]]);
    }
});

// ----------------------------------------------------------------------
// Untergruppe und Standardgruppe schliessen sich aus (1.8.0). Eine
// Untergruppe als Standard wuerde jedes neue Mitglied ungefragt einem
// Register zuordnen.
// ----------------------------------------------------------------------

/** Die group_id der aktuell gespeicherten Standardgruppe, falls vorhanden. */
function ugCurrentDefaultGroupId(): ?int
{
    $res = apiRequest('GET', 'member_groups', ['token' => apiToken('admin')]);
    assertStatus(200, $res);

    foreach ($res['body'] as $group) {
        if ((int) $group['is_default'] === 1) {
            return (int) $group['group_id'];
        }
    }

    return null;
}

/** Voller Datensatz einer Gruppe, als Grundlage fuer ugRestoreGroup(). */
function ugSnapshotGroup(int $groupId): array
{
    $res = apiRequest('GET', 'member_groups', ['token' => apiToken('admin'), 'query' => ['id' => $groupId]]);
    assertStatus(200, $res);

    return $res['body'];
}

/**
 * Schreibt eine Gruppe wieder auf einen fruehren Stand zurueck. group_name/
 * description/is_default sind ein Voll-Update (s. API.md) -- deshalb wird
 * hier immer der komplette Snapshot mitgeschickt, nicht nur is_default.
 */
function ugRestoreGroup(array $snapshot): void
{
    assertStatus(200, apiRequest('PUT', 'member_groups', ['token' => apiToken('admin'),
        'query' => ['id' => $snapshot['group_id']],
        'body'  => [
            'group_name'  => $snapshot['group_name'],
            'description' => $snapshot['description'],
            'is_default'  => (bool) $snapshot['is_default'],
            'is_subgroup' => (bool) $snapshot['is_subgroup'],
            'sort_order'  => (int) $snapshot['sort_order'],
        ],
    ]), 'Wiederherstellung der vorherigen Standardgruppe fehlgeschlagen');
}

test('member_groups POST: Untergruppe und Standardgruppe zusammen wird mit 400 abgewiesen', function () {
    $res = apiRequest('POST', 'member_groups', ['token' => apiToken('admin'), 'body' => [
        'group_name'  => 'UG Konflikt POST ' . uniqid(),
        'is_subgroup' => true,
        'is_default'  => true,
    ]]);
    assertStatus(400, $res, 'is_subgroup und is_default gemeinsam beim Anlegen muessen abgewiesen werden');
});

test('member_groups PUT: Untergruppe und Standardgruppe zusammen wird mit 400 abgewiesen, beide Felder gesendet', function () {
    $groupId = ugCreate('member_groups', ['group_name' => 'UG Konflikt PUT ' . uniqid()]);

    try {
        $res = apiRequest('PUT', 'member_groups', ['token' => apiToken('admin'),
            'query' => ['id' => $groupId],
            'body'  => ['group_name' => 'UG Konflikt PUT geaendert', 'is_subgroup' => true, 'is_default' => true],
        ]);
        assertStatus(400, $res, 'is_subgroup und is_default gemeinsam beim Aendern muessen abgewiesen werden');
    } finally {
        ugDelete('member_groups', $groupId);
    }
});

test('member_groups PUT: is_subgroup=1 wird abgewiesen, wenn die Gruppe bereits gespeicherte Standardgruppe ist', function () {
    // Nur is_subgroup wird gesendet -- is_default fehlt im Koerper. Die
    // Pruefung muss trotzdem greifen, weil sie gegen den gespeicherten Wert
    // geht, nicht nur gegen das gesendete Feld.
    $vorherigerDefaultId = ugCurrentDefaultGroupId();
    $vorherigerDefault    = $vorherigerDefaultId !== null ? ugSnapshotGroup($vorherigerDefaultId) : null;

    $suffix  = uniqid();
    $groupId = ugCreate('member_groups', [
        'group_name' => "UG Default-Bestand {$suffix}",
        'is_default' => true,
    ]);

    try {
        $res = apiRequest('PUT', 'member_groups', ['token' => apiToken('admin'),
            'query' => ['id' => $groupId],
            'body'  => ['group_name' => "UG Default-Bestand {$suffix}", 'is_subgroup' => true],
        ]);
        assertStatus(400, $res,
            'is_subgroup=1 gegen eine bereits gespeicherte Standardgruppe muss abgewiesen werden, auch ohne gesendetes is_default');

        $nachher = ugSnapshotGroup($groupId);
        assertSame(1, (int) $nachher['is_default'], 'is_default darf nach dem abgewiesenen PUT unveraendert bleiben');
        assertSame(0, (int) $nachher['is_subgroup'], 'is_subgroup darf nach dem abgewiesenen PUT nicht gesetzt worden sein');
    } finally {
        ugDelete('member_groups', $groupId);
        if ($vorherigerDefault !== null) {
            ugRestoreGroup($vorherigerDefault);
        }
    }
});

test('member_groups PUT: is_default=1 wird abgewiesen, wenn die Gruppe bereits gespeicherte Untergruppe ist', function () {
    // Spiegelbild des vorigen Tests: nur is_default wird gesendet, is_subgroup
    // steht bereits im Bestand.
    $suffix  = uniqid();
    $groupId = ugCreate('member_groups', [
        'group_name'  => "UG Sub-Bestand {$suffix}",
        'is_subgroup' => true,
    ]);

    try {
        $res = apiRequest('PUT', 'member_groups', ['token' => apiToken('admin'),
            'query' => ['id' => $groupId],
            'body'  => ['group_name' => "UG Sub-Bestand {$suffix}", 'is_default' => true],
        ]);
        assertStatus(400, $res,
            'is_default=1 gegen eine bereits gespeicherte Untergruppe muss abgewiesen werden, auch ohne gesendetes is_subgroup');

        $nachher = ugSnapshotGroup($groupId);
        assertSame(0, (int) $nachher['is_default'], 'is_default darf nach dem abgewiesenen PUT nicht gesetzt worden sein');
        assertSame(1, (int) $nachher['is_subgroup'], 'is_subgroup darf nach dem abgewiesenen PUT unveraendert bleiben');
    } finally {
        ugDelete('member_groups', $groupId);
    }
});

test('member_groups PUT: is_subgroup allein bleibt weiter erlaubt, wenn kein Standard-Konflikt besteht', function () {
    $groupId = ugCreate('member_groups', ['group_name' => 'UG Ok ' . uniqid()]);

    try {
        $res = apiRequest('PUT', 'member_groups', ['token' => apiToken('admin'),
            'query' => ['id' => $groupId],
            'body'  => ['group_name' => 'UG Ok geaendert', 'is_subgroup' => true],
        ]);
        assertStatus(200, $res, 'is_subgroup allein, ohne Standard-Konflikt, muss weiter funktionieren');
    } finally {
        ugDelete('member_groups', $groupId);
    }
});
