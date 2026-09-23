<?php
/**
 * Anwesenheitszahlen je Termin an GET appointments (Kalender, Schritt 2a).
 *
 * Spec: docs/superpowers/specs/2026-09-22-kalender-anwesenheit-design.md
 *
 * Jede Welt baut eigene Gruppe, Terminart und Mitglied -- die Zahlen haengen
 * an der Gruppenzuordnung, fremde Mitglieder wuerden sie verfaelschen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function caCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");

    return (int) $res['body']['id'];
}

function caDelete(string $resource, int $id): void
{
    $res = apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    if (in_array($resource, ['members', 'member_groups'], true)) {
        assertStatus(200, $res, "{$resource} {$id} konnte nicht geloescht werden");
    }
}

/** Wie rsMemberGroupIds() in responses_api.php. */
function caMemberGroupIds(int $memberId): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    assertTrue(isset($res['body']['groups']) && is_array($res['body']['groups']),
        "members lieferte kein groups-Array fuer Mitglied {$memberId}: " . $res['raw']);

    return array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups']);
}

/** Wie rsSetMemberGroups() in responses_api.php. */
function caSetMemberGroups(int $memberId, array $groupIds): void
{
    assertStatus(200, apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
        'body'  => ['group_ids' => $groupIds],
    ]), "Gruppen von Mitglied {$memberId} konnten nicht gesetzt werden");
}

/** @return array{group:int,type:int,members:int[],appointments:int[]} */
function caWorld(string $label, int $memberCount = 3): array
{
    $suffix = uniqid();
    $world = ['group' => null, 'type' => null, 'members' => [], 'appointments' => []];

    try {
        $world['group'] = caCreate('member_groups', ['group_name' => "CA {$label} {$suffix}"]);
        $world['type']  = caCreate('appointment_types', [
            'type_name' => "CA {$label} {$suffix}", 'is_default' => 0, 'color' => '#667eea',
            'group_ids' => [$world['group']],
        ]);
        for ($i = 1; $i <= $memberCount; $i++) {
            $world['members'][] = caCreate('members', [
                'name' => 'Ca', 'surname' => "Test {$label} {$i} {$suffix}",
                'active' => 1, 'group_ids' => [$world['group']],
            ]);
        }
    } catch (Throwable $e) {
        caDropWorld($world);
        throw $e;
    }

    return $world;
}

function caDropWorld(array $world): void
{
    foreach ($world['appointments'] as $id) {
        caDelete('appointments', $id);
    }
    foreach ($world['members'] as $id) {
        caDelete('members', $id);
    }
    if ($world['type'] !== null) {
        caDelete('appointment_types', $world['type']);
    }
    if ($world['group'] !== null) {
        caDelete('member_groups', $world['group']);
    }
}

function caDateInDays(int $days): string
{
    return date('Y-m-d', strtotime("{$days} days"));
}

function caAppointment(array &$world, string $date, string $time = '19:00:00'): int
{
    $id = caCreate('appointments', ['title' => 'CA-Termin', 'date' => $date,
                                    'start_time' => $time, 'type_id' => $world['type']]);
    $world['appointments'][] = $id;

    return $id;
}

function caRecord(int $appointmentId, int $memberId, string $status): void
{
    $res = apiRequest('POST', 'records', ['token' => apiToken('admin'),
        'body' => ['member_id' => $memberId, 'appointment_id' => $appointmentId, 'status' => $status]]);
    assertStatus(201, $res);
}

/** Termin aus GET appointments des Jahres, mit den angeforderten Zusaetzen. */
function caFetch(int $appointmentId, string $date, string $role = 'admin', array $extraQuery = []): ?array
{
    $res = apiRequest('GET', 'appointments', ['token' => apiToken($role),
        'query' => array_merge(['year' => (int) substr($date, 0, 4)], $extraQuery)]);
    assertStatus(200, $res);
    foreach ($res['body'] as $apt) {
        if ((int) $apt['appointment_id'] === $appointmentId) {
            return $apt;
        }
    }

    return null;
}

// ---- Zahlen fuer Verwalter ----------------------------------------------------------

test('include=attendance zaehlt anwesend, entschuldigt und fehlend', function () {
    $world = caWorld('Zahlen');
    try {
        $tag = caDateInDays(-2);
        $apt = caAppointment($world, $tag);
        caRecord($apt, $world['members'][0], 'present');
        caRecord($apt, $world['members'][1], 'excused');

        $row = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertTrue($row !== null, 'Termin fehlt in der Liste');
        assertTrue(array_key_exists('attendance', $row), 'attendance fehlt in der Antwort');
        assertSame(['expected' => 3, 'present' => 1, 'excused' => 1, 'missing' => 1], $row['attendance']);
    } finally {
        caDropWorld($world);
    }
});

test('Kuenftige Termine tragen keine Zahlen', function () {
    $world = caWorld('Zukunft');
    try {
        $tag = caDateInDays(3);
        $apt = caAppointment($world, $tag);

        $row = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertTrue($row !== null, 'Termin fehlt in der Liste');
        assertTrue(array_key_exists('attendance', $row), 'attendance fehlt in der Antwort');
        assertSame(null, $row['attendance']);
    } finally {
        caDropWorld($world);
    }
});

test('Ohne include bleibt die Antwort unveraendert', function () {
    $world = caWorld('Ohne');
    try {
        $tag = caDateInDays(-1);
        $apt = caAppointment($world, $tag);

        $row = caFetch($apt, $tag, 'admin');
        assertTrue(!array_key_exists('attendance', $row), 'attendance darf ohne include nicht erscheinen');
        assertTrue(!array_key_exists('own_attendance', $row), 'own_attendance darf ohne include nicht erscheinen');
    } finally {
        caDropWorld($world);
    }
});

test('Ohne Zeitraum keine Zahlen (Kostengrenze wie bei den Rueckmeldungen)', function () {
    $world = caWorld('Zeitraum');
    try {
        $tag = caDateInDays(-1);
        $apt = caAppointment($world, $tag);

        $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'),
            'query' => ['include' => 'attendance']]);
        assertStatus(200, $res);
        $gefunden = false;
        foreach ($res['body'] as $row) {
            if ((int) $row['appointment_id'] === $apt) {
                $gefunden = true;
                assertTrue(!array_key_exists('attendance', $row),
                    'Ohne Jahres- oder Datumsfilter werden keine Zahlen angehaengt');
            }
        }
        assertTrue($gefunden, 'Termin fehlt in der ungefilterten Liste -- der Test praeft sonst nichts');
    } finally {
        caDropWorld($world);
    }
});

// ---- Rollen -------------------------------------------------------------------------

test('Ein Mitglied sieht nur den eigenen Status, keine Zahlen', function () {
    $world = caWorld('Rolle');
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Testkonto user hat kein verknuepftes Mitglied');

    // Das Mitglied des Testkontos zusaetzlich in die Gruppe der Welt nehmen und
    // am Ende wieder herausnehmen -- sonst waere es gar nicht erwartet. Wie
    // rsWithUserInWorld() in responses_api.php: die urspruengliche Zuordnung
    // wird gelesen, geprueft (eine leere Liste waere ein Fehler -- das Konto
    // muss Gruppen haben) und am Ende exakt wiederhergestellt und gegengeprueft,
    // statt eine fehlgeschlagene Wiederherstellung stillschweigend durchzulassen.
    $gruppenVorher = caMemberGroupIds($memberId);
    assertTrue($gruppenVorher !== [], "Testkonto user (Mitglied {$memberId}) hat keine Gruppe");

    try {
        caSetMemberGroups($memberId, array_values(array_unique(array_merge($gruppenVorher, [$world['group']]))));

        $tag = caDateInDays(-2);
        $apt = caAppointment($world, $tag);
        caRecord($apt, $memberId, 'excused');

        $row = caFetch($apt, $tag, 'user', ['include' => 'attendance']);
        assertTrue($row !== null, 'Termin fehlt in der Mitgliedersicht');
        assertTrue(array_key_exists('own_attendance', $row), 'own_attendance fehlt in der Antwort');
        assertSame('excused', $row['own_attendance']);
        assertTrue(!array_key_exists('attendance', $row), 'Ein Mitglied darf keine Zahlen ueber andere sehen');

        $verwalter = caFetch($apt, $tag, 'manager', ['include' => 'attendance']);
        assertTrue($verwalter !== null, 'Termin fehlt in der Verwaltersicht');
        assertTrue(array_key_exists('attendance', $verwalter), 'attendance fehlt in der Antwort');
        assertSame(4, $verwalter['attendance']['expected'], 'Manager zaehlt alle erwarteten Mitglieder');
        assertSame(1, $verwalter['attendance']['excused']);
        assertTrue(!array_key_exists('own_attendance', $verwalter));
    } finally {
        caSetMemberGroups($memberId, $gruppenVorher);
        $gruppenNachher = caMemberGroupIds($memberId);
        sort($gruppenVorher);
        sort($gruppenNachher);
        assertSame($gruppenVorher, $gruppenNachher,
            "Gruppen von Mitglied {$memberId} nach der Wiederherstellung veraendert");
        caDropWorld($world);
    }
});

test('Ohne Anwesenheit gilt das Mitglied als fehlend, ohne Erwartung als null', function () {
    $world = caWorld('Fehlend');
    try {
        $tag = caDateInDays(-2);
        $apt = caAppointment($world, $tag);

        // Das Testkonto user ist nicht in der Gruppe der Welt -- also nicht erwartet.
        $fremd = caFetch($apt, $tag, 'user', ['include' => 'attendance']);
        assertTrue($fremd === null || $fremd['own_attendance'] === null,
            'Nicht erwartete Mitglieder bekommen keinen Status');

        $row = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertTrue($row !== null, 'Termin fehlt in der Liste');
        assertTrue(array_key_exists('attendance', $row), 'attendance fehlt in der Antwort');
        assertSame(3, $row['attendance']['missing'], 'Ohne Records fehlen alle Erwarteten');
    } finally {
        caDropWorld($world);
    }
});

test('Ein erst spaeter aktives Mitglied zaehlt beim frueheren Termin nicht', function () {
    $world = caWorld('Aktiv');
    try {
        $tag = caDateInDays(-10);
        $apt = caAppointment($world, $tag);

        // Eintritt nach dem Termin: membership_dates ab morgen.
        $res = apiRequest('POST', 'membership_dates', ['token' => apiToken('admin'), 'body' => [
            'member_id' => $world['members'][2], 'start_date' => caDateInDays(1), 'end_date' => null]]);
        assertStatus(201, $res);

        $row = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertTrue($row !== null, 'Termin fehlt in der Liste');
        assertTrue(array_key_exists('attendance', $row), 'attendance fehlt in der Antwort');
        assertSame(2, $row['attendance']['expected'], 'Das spaeter eingetretene Mitglied zaehlt nicht mit');
    } finally {
        caDropWorld($world);
    }
});

// ---- Spec-Regeln fuer "erwartet" -----------------------------------------------------

test('Ein Mitglied in zwei Gruppen derselben Terminart zaehlt nur einmal', function () {
    $world = caWorld('Doppelgruppe', 2);
    $zweiteGruppe = null;
    try {
        $zweiteGruppe = caCreate('member_groups', ['group_name' => 'CA Doppelgruppe zweite ' . uniqid()]);

        // Terminart um die zweite Gruppe erweitern (Voll-Update, beide Gruppen mitschicken).
        $resType = apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $world['type']],
            'body' => ['group_ids' => [$world['group'], $zweiteGruppe]]]);
        assertStatus(200, $resType);

        // Erstes Mitglied zusaetzlich in die zweite Gruppe nehmen -- es ist jetzt
        // ueber beide Gruppen der Terminart erwartet.
        $resMember = apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $world['members'][0]],
            'body' => ['group_ids' => [$world['group'], $zweiteGruppe]]]);
        assertStatus(200, $resMember);

        $tag = caDateInDays(-2);
        $apt = caAppointment($world, $tag);

        $row = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertTrue($row !== null, 'Termin fehlt in der Liste');
        assertTrue(array_key_exists('attendance', $row), 'attendance fehlt in der Antwort');
        assertSame(2, $row['attendance']['expected'],
            'Das Mitglied in beiden Gruppen der Terminart zaehlt trotzdem nur einmal');
    } finally {
        if ($zweiteGruppe !== null) {
            caDelete('member_groups', $zweiteGruppe);
        }
        caDropWorld($world);
    }
});

test('Ein Record eines nicht erwarteten Mitglieds veraendert die Zahlen nicht', function () {
    $world  = caWorld('Fremdrecord', 3);
    $fremde = caWorld('FremdrecordFremd', 1);
    try {
        $tag = caDateInDays(-2);
        $apt = caAppointment($world, $tag);
        caRecord($apt, $world['members'][0], 'present');

        $vorher = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertTrue($vorher !== null, 'Termin fehlt in der Liste');
        assertTrue(array_key_exists('attendance', $vorher), 'attendance fehlt in der Antwort');

        // Record eines Mitglieds aus einer fremden Gruppe/Terminart auf denselben Termin --
        // das Mitglied ist an diesem Termin nicht erwartet.
        caRecord($apt, $fremde['members'][0], 'present');

        $nachher = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertTrue($nachher !== null, 'Termin fehlt in der Liste');
        assertTrue(array_key_exists('attendance', $nachher), 'attendance fehlt in der Antwort');
        assertSame($vorher['attendance'], $nachher['attendance'],
            'Ein Record eines nicht erwarteten Mitglieds darf expected/present/excused/missing nicht aendern');
        assertTrue($nachher['attendance']['missing'] >= 0, 'missing darf nie negativ werden');
    } finally {
        caDropWorld($fremde);
        caDropWorld($world);
    }
});

// ---- Abgleich mit attendance_list ---------------------------------------------------

test('Die Zahlen stimmen mit der Anwesenheitsliste desselben Termins ueberein', function () {
    $world = caWorld('Abgleich', 4);
    try {
        $tag = caDateInDays(-3);
        $apt = caAppointment($world, $tag);
        caRecord($apt, $world['members'][0], 'present');
        caRecord($apt, $world['members'][1], 'present');
        caRecord($apt, $world['members'][2], 'excused');

        $liste = apiRequest('GET', 'attendance_list', ['token' => apiToken('admin'),
            'query' => ['appointment_id' => $apt]]);
        assertStatus(200, $liste);
        assertTrue(array_key_exists('members', $liste['body']), 'members fehlt in der Anwesenheitsliste');
        $mitglieder = $liste['body']['members'];

        $present = 0;
        $excused = 0;
        foreach ($mitglieder as $m) {
            if (($m['status'] ?? null) === 'present') $present++;
            if (($m['status'] ?? null) === 'excused') $excused++;
        }

        $row = caFetch($apt, $tag, 'admin', ['include' => 'attendance']);
        assertTrue($row !== null, 'Termin fehlt in der Liste');
        assertTrue(array_key_exists('attendance', $row), 'attendance fehlt in der Antwort');
        assertSame(count($mitglieder), $row['attendance']['expected'], 'Erwartete Mitglieder');
        assertSame($present, $row['attendance']['present']);
        assertSame($excused, $row['attendance']['excused']);
        assertSame(count($mitglieder) - $present - $excused, $row['attendance']['missing']);
    } finally {
        caDropWorld($world);
    }
});

test('attendance_list liefert weiterhin offene Antraege je Mitglied', function () {
    $world = caWorld('Antraege', 1);
    try {
        $tag = caDateInDays(-2);
        $apt = caAppointment($world, $tag);

        $res = apiRequest('GET', 'attendance_list', ['token' => apiToken('admin'),
            'query' => ['appointment_id' => $apt]]);
        assertStatus(200, $res);
        assertTrue(array_key_exists('members', $res['body']), 'members fehlt in der Anwesenheitsliste');
        assertTrue(count($res['body']['members']) > 0,
            'Kein Mitglied in der Anwesenheitsliste -- der Test praeft sonst nichts');
        assertTrue(array_key_exists('pending_exceptions', $res['body']['members'][0]),
            'pending_exceptions (seit 1.12.0) muss erhalten bleiben -- OI-87 und die PWA bauen darauf');
    } finally {
        caDropWorld($world);
    }
});
