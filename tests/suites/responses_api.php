<?php
/**
 * Terminrueckmeldung ueber die API.
 *
 * Jeder Test baut sich eine eigene Welt aus Gruppe, Terminart und Mitglied,
 * wie tests/suites/punctuality_api.php. Braucht ein Test das Mitglied des
 * Kontos "user", haengt er es fuer die Dauer des Tests zusaetzlich in die
 * Gruppe der Welt und stellt die alten Gruppen im finally wieder her.
 *
 * Termine liegen je Welt an verschiedenen Tagen: Zwei Termine derselben Art
 * im Toleranzfenster lehnt appointments.php als Dublette ab.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function rsCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");
    $id = (int) $res['body']['id'];
    assertTrue($id > 0, "{$resource} lieferte keine brauchbare ID: " . $res['raw']);

    return $id;
}

function rsDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

/** @param array<string, mixed> $typeSettings Felder der Terminart, z. B. responses_enabled */
function rsWorld(string $label, array $typeSettings = []): array
{
    $suffix = uniqid();
    $world  = ['group' => null, 'type' => null, 'member' => null, 'appointments' => []];

    try {
        $world['group'] = rsCreate('member_groups', ['group_name' => "RS {$label} {$suffix}"]);
        $world['type']  = rsCreate('appointment_types', array_merge([
            'type_name'  => "RS {$label} {$suffix}",
            'is_default' => 0,
            'color'      => '#667eea',
            'group_ids'  => [$world['group']],
        ], $typeSettings));
        $world['member'] = rsCreate('members', [
            'name'      => 'Rs',
            'surname'   => "Test {$label} {$suffix}",
            'active'    => 1,
            'group_ids' => [$world['group']],
        ]);
    } catch (Throwable $e) {
        rsDropWorld($world);
        throw $e;
    }

    return $world;
}

function rsAppointment(array &$world, string $date, string $time): int
{
    $id = rsCreate('appointments', [
        'title'      => 'RS-Termin',
        'date'       => $date,
        'start_time' => $time,
        'type_id'    => $world['type'],
    ]);
    $world['appointments'][] = $id;

    return $id;
}

function rsDropWorld(array $world): void
{
    foreach ($world['appointments'] as $appointmentId) {
        rsDelete('appointments', $appointmentId);
    }
    if ($world['member'] !== null) {
        rsDelete('members', $world['member']);
    }
    if ($world['type'] !== null) {
        rsDelete('appointment_types', $world['type']);
    }
    if ($world['group'] !== null) {
        rsDelete('member_groups', $world['group']);
    }
}

function rsDateInDays(int $days): string
{
    return date('Y-m-d', strtotime("{$days} days"));
}

function rsMemberGroupIds(int $memberId): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $memberId]]);
    assertStatus(200, $res);
    assertTrue(isset($res['body']['groups']) && is_array($res['body']['groups']),
        "members lieferte kein groups-Array fuer Mitglied {$memberId}: " . $res['raw']);

    return array_map(static fn ($g) => (int) $g['group_id'], $res['body']['groups']);
}

function rsSetMemberGroups(int $memberId, array $groupIds): void
{
    assertStatus(200, apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
        'body'  => ['group_ids' => $groupIds],
    ]), "Gruppen von Mitglied {$memberId} konnten nicht gesetzt werden");
}

/** Haengt das Mitglied des Kontos "user" fuer $fn in die Gruppe der Welt. */
function rsWithUserInWorld(array $world, callable $fn): void
{
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    $original   = rsMemberGroupIds($memberId);
    $bodyError  = null;

    try {
        rsSetMemberGroups($memberId, array_values(array_unique(array_merge($original, [$world['group']]))));
        $fn($memberId);
    } catch (Throwable $e) {
        $bodyError = $e;
    } finally {
        try {
            rsSetMemberGroups($memberId, $original);
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

function rsType(int $typeId): array
{
    $res = apiRequest('GET', 'appointment_types', ['token' => apiToken('admin'), 'query' => ['id' => $typeId]]);
    assertStatus(200, $res);

    return $res['body'];
}

/** Wie puSettingValue() in punctuality_api.php, nur mit dem Praefix dieser Suite. */
function rsSettingValue(string $key): ?string
{
    $res = apiRequest('GET', 'settings', ['token' => apiToken('admin')]);
    foreach ($res['body']['settings'] ?? [] as $setting) {
        if ($setting['setting_key'] === $key) {
            return (string) $setting['setting_value'];
        }
    }

    return null;
}

function rsPut(string $role, int $appointmentId, array $body, ?int $memberId = null): array
{
    $query = ['appointment_id' => $appointmentId];
    if ($memberId !== null) {
        $query['member_id'] = $memberId;
    }

    return apiRequest('PUT', 'appointment_responses', ['token' => apiToken($role), 'query' => $query, 'body' => $body]);
}

function rsDeleteResponse(string $role, int $appointmentId, ?int $memberId = null): array
{
    $query = ['appointment_id' => $appointmentId];
    if ($memberId !== null) {
        $query['member_id'] = $memberId;
    }

    return apiRequest('DELETE', 'appointment_responses', ['token' => apiToken($role), 'query' => $query]);
}

function rsWithSettings(array $settings, callable $fn): void
{
    $vorher = [];
    foreach ($settings as $key => $value) {
        $vorher[$key] = rsSettingValue($key) ?? '0';
        assertStatus(200, apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
            'body' => ['setting_key' => $key, 'setting_value' => $value]]));
    }

    try {
        $fn();
    } finally {
        foreach ($vorher as $key => $value) {
            apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
                'body' => ['setting_key' => $key, 'setting_value' => $value]]);
        }
    }
}

test('appointment_types: neue Terminart traegt die Rueckmeldungs-Einstellungen', function () {
    $welt = rsWorld('Typ', [
        'responses_enabled' => 1, 'responses_names_visible' => 1,
        'responses_require_excuse' => 0, 'response_deadline_hours' => 168,
    ]);

    try {
        $typ = rsType($welt['type']);
        assertSame(1,   (int) $typ['responses_enabled']);
        assertSame(1,   (int) $typ['responses_names_visible']);
        assertSame(0,   (int) $typ['responses_require_excuse']);
        assertSame(168, (int) $typ['response_deadline_hours']);
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_types: PUT ohne die Felder setzt nichts zurueck', function () {
    $welt = rsWorld('TypPut', ['responses_enabled' => 1, 'response_deadline_hours' => 48]);

    try {
        assertStatus(200, apiRequest('PUT', 'appointment_types', [
            'token' => apiToken('admin'),
            'query' => ['id' => $welt['type']],
            'body'  => ['type_name' => 'RS umbenannt ' . uniqid(), 'color' => '#667eea',
                        'is_default' => 0, 'group_ids' => [$welt['group']]],
        ]));

        $typ = rsType($welt['type']);
        assertSame(1,  (int) $typ['responses_enabled']);
        assertSame(48, (int) $typ['response_deadline_hours']);
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_types: leere Frist heisst global, ungueltige wird abgewiesen', function () {
    $welt = rsWorld('TypFrist', ['responses_enabled' => 1, 'response_deadline_hours' => 48]);
    $body = ['type_name' => 'RS Frist ' . uniqid(), 'color' => '#667eea',
             'is_default' => 0, 'group_ids' => [$welt['group']]];

    try {
        $res = apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']], 'body' => $body + ['response_deadline_hours' => 721]]);
        assertStatus(400, $res, '721 Stunden liegen ueber der Grenze');
        assertSame(48, (int) rsType($welt['type'])['response_deadline_hours']);

        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']], 'body' => $body + ['response_deadline_hours' => '']]));
        assertSame(null, rsType($welt['type'])['response_deadline_hours']);
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_types: Frist 0 bleibt 0, responses_enabled schaltet sich ab', function () {
    $welt = rsWorld('TypNull', ['responses_enabled' => 1, 'response_deadline_hours' => 5]);
    $body = ['type_name' => 'RS Null ' . uniqid(), 'color' => '#667eea',
             'is_default' => 0, 'group_ids' => [$welt['group']]];

    try {
        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']], 'body' => $body + ['response_deadline_hours' => 0]]));
        $typ = rsType($welt['type']);
        assertTrue($typ['response_deadline_hours'] !== null, 'Frist 0 darf nicht als null gespeichert werden');
        assertSame(0, (int) $typ['response_deadline_hours']);

        assertStatus(200, apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['type']], 'body' => $body + ['responses_enabled' => 0]]));
        assertSame(0, (int) rsType($welt['type'])['responses_enabled']);
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_types: PUT auf unbekannte Terminart liefert 404', function () {
    $res = apiRequest('PUT', 'appointment_types', ['token' => apiToken('admin'),
        'query' => ['id' => 999999999],
        'body'  => ['type_name' => 'RS Phantom ' . uniqid(), 'color' => '#667eea',
                    'is_default' => 0, 'group_ids' => []]]);
    assertStatus(404, $res, 'unbekannte Terminart muss 404 liefern');
});

test('settings: Frist ausserhalb 0..720 wird abgewiesen', function () {
    $vorher = rsSettingValue('response_deadline_hours') ?? '24';

    try {
        // '721' als Text und 721 als JSON-Zahl muessen beide abgelehnt werden --
        // responseHoursFromRaw() prueft is_int() vor dem Regex auf Strings.
        foreach (['721', '-1', 'zwei', '24.5', 721] as $wert) {
            $res = apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
                'body' => ['setting_key' => 'response_deadline_hours', 'setting_value' => $wert]]);
            assertStatus(400, $res, "Wert {$wert}");
        }
        assertSame($vorher, rsSettingValue('response_deadline_hours'),
            'abgewiesene Werte duerfen die gespeicherte Frist nicht veraendern');

        foreach (['0', '720', 48] as $wert) {
            assertStatus(200, apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
                'body' => ['setting_key' => 'response_deadline_hours', 'setting_value' => $wert]]), "Wert {$wert}");
        }
    } finally {
        assertStatus(200, apiRequest('PUT', 'settings', ['token' => apiToken('admin'),
            'body' => ['setting_key' => 'response_deadline_hours', 'setting_value' => $vorher]]));
    }
});

// ---- appointment_responses: Lesen -----------------------------------------

function rsGet(string $role, array $query): array
{
    return apiRequest('GET', 'appointment_responses', ['token' => apiToken($role), 'query' => $query]);
}

test('appointment_responses: unbekannte Ressource ist es nicht mehr, auch nicht im Demo-Modus', function () {
    $res = rsGet('admin', ['appointment_id' => 999999999]);
    assertStatus(404, $res, 'Ein unbekannter Termin ist 404, nicht "unbekannte Ressource"');
});

test('appointment_responses: appointment_id ohne Zahl ist 400', function () {
    assertStatus(400, rsGet('user', ['appointment_id' => 'abc']));
});

test('appointment_responses: Terminart ohne Rueckmeldung ist 409', function () {
    $welt = rsWorld('Aus');
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');
        assertStatus(409, rsGet('admin', ['appointment_id' => $apt]));
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_responses: Mitglied ausserhalb der Gruppen bekommt 403', function () {
    $welt = rsWorld('Fremd', ['responses_enabled' => 1]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');
        assertStatus(403, rsGet('user', ['appointment_id' => $apt]));
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_responses: Verwalter sieht alle Erwarteten, Mitglied ohne Freigabe nur Summen', function () {
    $welt = rsWorld('Sicht', ['responses_enabled' => 1, 'response_deadline_hours' => 24]);
    try {
        $tag = rsDateInDays(3);
        $apt = rsAppointment($welt, $tag, '19:00:00');

        rsWithUserInWorld($welt, function (int $userMember) use ($apt, $welt, $tag) {
            $admin = rsGet('manager', ['appointment_id' => $apt]);
            assertStatus(200, $admin);
            assertSame(['yes' => 0, 'no' => 0, 'maybe' => 0, 'open' => 2], $admin['body']['summary']);
            assertSame(2, count($admin['body']['members']));
            assertSame(false, $admin['body']['started']);
            assertTrue(!array_key_exists('comparison', $admin['body']), 'Vor Beginn keine Gegenueberstellung');
            assertSame(date('Y-m-d', strtotime("{$tag} -1 day")) . ' 19:00:00', $admin['body']['settings']['deadline']);

            $user = rsGet('user', ['appointment_id' => $apt]);
            assertStatus(200, $user);
            assertSame(true, $user['body']['expected']);
            assertSame(null, $user['body']['own']);
            assertTrue(!array_key_exists('members', $user['body']), 'Ohne Freigabe keine Namen');
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('appointment_responses: upcoming listet nur Termine mit Rueckmeldung, zu denen man erwartet ist', function () {
    $mit  = rsWorld('UpMit', ['responses_enabled' => 1]);
    $ohne = rsWorld('UpOhne');
    try {
        $kommend  = rsAppointment($mit, rsDateInDays(4), '19:00:00');
        $vorbei   = rsAppointment($mit, rsDateInDays(-4), '19:00:00');
        $ohneRm   = rsAppointment($ohne, rsDateInDays(4), '19:00:00');

        rsWithUserInWorld($mit, function () use ($ohne, $kommend, $vorbei, $ohneRm) {
            rsWithUserInWorld($ohne, function () use ($kommend, $vorbei, $ohneRm) {
                $res = rsGet('user', ['upcoming' => 1]);
                assertStatus(200, $res);
                $ids = array_map(static fn ($i) => (int) $i['appointment']['appointment_id'], $res['body']['appointments']);

                assertTrue(in_array($kommend, $ids, true), 'Kommender Termin fehlt');
                assertTrue(!in_array($vorbei, $ids, true), 'Vergangener Termin gehoert nicht hinein');
                assertTrue(!in_array($ohneRm, $ids, true), 'Terminart ohne Rueckmeldung gehoert nicht hinein');
            });
        });
    } finally {
        rsDropWorld($mit);
        rsDropWorld($ohne);
    }
});

// ---- appointment_responses: Schreiben --------------------------------------

test('PUT: Mitglied sagt zu, aendert die Bemerkung, sagt ab', function () {
    $welt = rsWorld('Put', ['responses_enabled' => 1, 'response_deadline_hours' => 0]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');

        rsWithUserInWorld($welt, function () use ($apt) {
            $res = rsPut('user', $apt, ['status' => 'yes']);
            assertStatus(200, $res);
            assertSame('yes', $res['body']['own']['status']);
            assertSame(false, $res['body']['own']['is_late'], 'Frist 0, Termin in drei Tagen');
            assertSame(1, $res['body']['summary']['yes']);
            $zeitpunkt = $res['body']['own']['status_changed_at'];

            sleep(1);
            $res = rsPut('user', $apt, ['status' => 'yes', 'comment' => 'komme 10 Minuten spaeter']);
            assertStatus(200, $res);
            assertSame('komme 10 Minuten spaeter', $res['body']['own']['comment']);
            assertSame($zeitpunkt, $res['body']['own']['status_changed_at'],
                'Eine reine Bemerkungsaenderung verschiebt den Statuszeitpunkt nicht');

            sleep(1);
            $res = rsPut('user', $apt, ['status' => 'no']);
            assertStatus(200, $res);
            assertTrue($res['body']['own']['status_changed_at'] > $zeitpunkt, 'Statuswechsel setzt den Zeitpunkt neu');
            assertSame(null, $res['body']['own']['comment'], 'Ohne comment im Koerper ist die Bemerkung leer');
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('PUT: nach der Frist gespeichert und als kurzfristig markiert', function () {
    $welt = rsWorld('Spaet', ['responses_enabled' => 1, 'response_deadline_hours' => 720]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');
        rsWithUserInWorld($welt, function () use ($apt) {
            $res = rsPut('user', $apt, ['status' => 'no']);
            assertStatus(200, $res);
            assertSame(true, $res['body']['own']['is_late']);
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('PUT: Eingaben und Rechte werden geprueft', function () {
    $welt = rsWorld('Rechte', ['responses_enabled' => 1]);
    try {
        $kommend = rsAppointment($welt, rsDateInDays(3), '19:00:00');
        $begonnen = rsAppointment($welt, rsDateInDays(-3), '19:00:00');

        assertStatus(403, rsPut('user', $kommend, ['status' => 'yes']), 'nicht erwartet');

        rsWithUserInWorld($welt, function () use ($kommend, $begonnen, $welt) {
            assertStatus(400, rsPut('user', $kommend, ['status' => 'vielleicht']));
            assertStatus(400, rsPut('user', $kommend, ['status' => 'yes', 'comment' => str_repeat('x', 256)]));
            assertStatus(409, rsPut('user', $begonnen, ['status' => 'yes']), 'Mitglied nach Beginn');
            assertStatus(403, rsPut('user', $kommend, ['status' => 'yes'], $welt['member']), 'user fuer andere');

            $nachtrag = rsPut('manager', $begonnen, ['status' => 'no', 'comment' => 'angerufen'], $welt['member']);
            assertStatus(200, $nachtrag, 'Manager darf nach Beginn fuer ein Mitglied eintragen');
            assertSame(true, $nachtrag['body']['started']);
            assertTrue(array_key_exists('comparison', $nachtrag['body']), 'Nach Beginn gibt es die Gegenueberstellung');
            assertSame(1, $nachtrag['body']['comparison']['no_absent']);
        });

        assertStatus(404, rsPut('manager', $kommend, ['status' => 'yes'], 999999999), 'unbekanntes Mitglied');
    } finally {
        rsDropWorld($welt);
    }
});

test('PUT: Konto ohne Mitglied kann nicht fuer sich antworten', function () {
    $ohneMitglied = null;
    foreach (['admin', 'manager'] as $rolle) {
        if (apiMemberId($rolle) === null) {
            $ohneMitglied = $rolle;
            break;
        }
    }
    if ($ohneMitglied === null) {
        assertTrue(true, 'Kein Testkonto ohne Mitglied -- Test uebersprungen');
        return;
    }

    $welt = rsWorld('OhneMitglied', ['responses_enabled' => 1]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');
        assertStatus(403, rsPut($ohneMitglied, $apt, ['status' => 'yes']));
    } finally {
        rsDropWorld($welt);
    }
});

test('PUT: Entschuldigungspflicht erzeugt, loescht und bewahrt den Antrag', function () {
    $welt = rsWorld('Pflicht', ['responses_enabled' => 1, 'responses_require_excuse' => 1]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');

        rsWithUserInWorld($welt, function (int $userMember) use ($apt) {
            assertStatus(422, rsPut('user', $apt, ['status' => 'no']), 'Absage ohne Begruendung');

            $res = rsPut('user', $apt, ['status' => 'no', 'comment' => 'Urlaub']);
            assertStatus(200, $res);
            assertSame('pending', $res['body']['own']['excuse_state']);

            $antraege = static function () use ($userMember, $apt): array {
                $liste = apiRequest('GET', 'exceptions', ['token' => apiToken('admin'),
                    'query' => ['member_id' => $userMember, 'type' => 'absence']]);
                assertStatus(200, $liste);

                return array_values(array_filter($liste['body'],
                    static fn ($e) => (int) $e['appointment_id'] === $apt));
            };
            assertSame(1, count($antraege()));
            assertSame('Urlaub', $antraege()[0]['reason']);

            assertStatus(200, rsPut('user', $apt, ['status' => 'yes']));
            assertSame(0, count($antraege()), 'Zusage loescht den offenen Antrag');

            assertStatus(200, rsPut('user', $apt, ['status' => 'no', 'comment' => 'doch Urlaub']));
            $antrag = $antraege()[0];
            assertStatus(200, apiRequest('PUT', 'exceptions', ['token' => apiToken('admin'),
                'query' => ['id' => (int) $antrag['exception_id']],
                'body'  => ['exception_type' => 'absence', 'reason' => 'doch Urlaub', 'status' => 'approved']]));

            $res = rsPut('user', $apt, ['status' => 'yes']);
            assertStatus(200, $res);
            assertSame('approved', $res['body']['own']['excuse_state'], 'Genehmigter Antrag bleibt verknuepft');
            assertSame(1, count($antraege()), 'Genehmigter Antrag bleibt bestehen');
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('DELETE: Ruecknahme entfernt Antwort und offenen Antrag', function () {
    $welt = rsWorld('Loeschen', ['responses_enabled' => 1, 'responses_require_excuse' => 1]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');

        rsWithUserInWorld($welt, function (int $userMember) use ($apt) {
            assertStatus(404, rsDeleteResponse('user', $apt), 'Nichts zum Zuruecknehmen');
            assertStatus(200, rsPut('user', $apt, ['status' => 'no', 'comment' => 'krank']));
            assertStatus(200, rsDeleteResponse('user', $apt));

            $nachher = rsGet('user', ['appointment_id' => $apt]);
            assertSame(null, $nachher['body']['own']);

            $liste = apiRequest('GET', 'exceptions', ['token' => apiToken('admin'),
                'query' => ['member_id' => $userMember, 'type' => 'absence']]);
            assertSame([], array_values(array_filter($liste['body'],
                static fn ($e) => (int) $e['appointment_id'] === $apt)));
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('Namen fuer Mitglieder: nur mit Freigabe, Bemerkungen nie', function () {
    $welt = rsWorld('Namen', ['responses_enabled' => 1, 'responses_names_visible' => 1]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');
        assertStatus(200, rsPut('manager', $apt, ['status' => 'no', 'comment' => 'privat'], $welt['member']));

        rsWithUserInWorld($welt, function () use ($apt, $welt) {
            $res = rsGet('user', ['appointment_id' => $apt]);
            assertStatus(200, $res);
            $zeile = array_values(array_filter($res['body']['members'],
                static fn ($m) => $m['member_id'] === $welt['member']))[0];

            assertSame('no', $zeile['status']);
            foreach (['comment', 'status_changed_at', 'is_late', 'excuse_state', 'present'] as $verbotenesFeld) {
                assertTrue(!array_key_exists($verbotenesFeld, $zeile),
                    "Feld '{$verbotenesFeld}' darf fuer Mitglieder nie erscheinen");
            }
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('Zuverlaessigkeit: rechtzeitige Absage zaehlt, kurzfristige und Antrag nach der Frist nicht', function () {
    // Termine heute spaeter: noch nicht begonnen, zaehlen aber schon zur
    // Statistik (vgl. punctuality_api.php). Ab 20:57 stimmt die Zeitlage nicht.
    if (date('H:i') >= '20:57') {
        assertTrue(true, 'Zeitlage ungeeignet -- Test uebersprungen');
        return;
    }

    $frist0   = rsWorld('Frist0', ['responses_enabled' => 1, 'response_deadline_hours' => 0]);
    $frist720 = rsWorld('Frist720', ['responses_enabled' => 1, 'response_deadline_hours' => 720]);
    $heute    = date('Y-m-d');

    try {
        $a = rsAppointment($frist0, $heute, '23:59:00');
        assertStatus(200, rsPut('manager', $a, ['status' => 'no'], $frist0['member']));

        $b = rsAppointment($frist720, $heute, '23:59:00');
        assertStatus(200, rsPut('manager', $b, ['status' => 'no'], $frist720['member']));

        $c = rsAppointment($frist720, $heute, '20:59:00');
        rsCreate('exceptions', [
            'member_id' => $frist720['member'], 'appointment_id' => $c,
            'exception_type' => 'absence', 'reason' => 'RS-Test', 'status' => 'pending',
        ]);

        rsWithSettings(['reliability_enabled' => '1'], function () use ($frist0, $frist720) {
            $stats = static function (array $welt): array {
                $res = apiRequest('GET', 'statistics', ['token' => apiToken('admin'),
                    'query' => ['year' => date('Y'), 'group_id' => $welt['group'], 'member_id' => $welt['member']]]);
                assertStatus(200, $res);

                return $res['body']['reliability'];
            };

            $r0 = $stats($frist0);
            assertSame(1, $r0['total']);
            assertSame(1, $r0['excused_in_time'], 'Absage vor der Frist 0');

            $r720 = $stats($frist720);
            assertSame(2, $r720['total']);
            assertSame(0, $r720['excused_in_time']);
            assertSame(2, $r720['missed'], 'kurzfristige Absage und Antrag nach der Frist');
        });
    } finally {
        rsDropWorld($frist0);
        rsDropWorld($frist720);
    }
});

test('Zuverlaessigkeit: globale Frist greift, wenn die Terminart keine eigene hat; Antrag vor der Frist zaehlt', function () {
    // Gleiche Zeitlage-Absicherung wie beim Test oben -- Termine heute spaeter.
    if (date('H:i') >= '20:57') {
        assertTrue(true, 'Zeitlage ungeeignet -- Test uebersprungen');
        return;
    }

    $weltG = rsWorld('Global', ['responses_enabled' => 1]);   // keine eigene Frist -> global
    $weltA = rsWorld('Antrag', ['responses_enabled' => 1, 'response_deadline_hours' => 0]);
    $heute = date('Y-m-d');

    try {
        $g = rsAppointment($weltG, $heute, '23:59:00');
        assertStatus(200, rsPut('manager', $g, ['status' => 'no'], $weltG['member']));

        $a = rsAppointment($weltA, $heute, '22:59:00');
        rsCreate('exceptions', [
            'member_id' => $weltA['member'], 'appointment_id' => $a,
            'exception_type' => 'absence', 'reason' => 'RS-Test', 'status' => 'pending',
        ]);

        $stats = static function (array $welt): array {
            $res = apiRequest('GET', 'statistics', ['token' => apiToken('admin'),
                'query' => ['year' => date('Y'), 'group_id' => $welt['group'], 'member_id' => $welt['member']]]);
            assertStatus(200, $res);

            return $res['body']['reliability'];
        };

        rsWithSettings(['reliability_enabled' => '1', 'response_deadline_hours' => '0'], function () use ($weltG, $weltA, $stats) {
            $rG = $stats($weltG);
            assertSame(1, $rG['excused_in_time'], 'Globale Frist 0 -- Absage vor Terminbeginn zaehlt rechtzeitig');

            $rA = $stats($weltA);
            assertSame(1, $rA['excused_in_time'], 'Antrag vor der globalen Frist 0 zaehlt rechtzeitig');
        });

        rsWithSettings(['reliability_enabled' => '1', 'response_deadline_hours' => '720'], function () use ($weltG, $weltA, $stats) {
            $rG = $stats($weltG);
            assertSame(1, $rG['missed'], 'Globale Frist 720 ist nun verstrichen');
            assertSame(0, $rG['excused_in_time']);

            // Die Terminart von A traegt eine eigene Frist (0) -- die globale
            // Einstellung wirkt hier nicht, der Antrag bleibt rechtzeitig.
            $rA = $stats($weltA);
            assertSame(1, $rA['excused_in_time'], 'Terminart-eigene Frist ueberstimmt die globale Einstellung');
            assertSame(0, $rA['missed']);
        });
    } finally {
        rsDropWorld($weltG);
        rsDropWorld($weltA);
    }
});

test('PUT: bereits vorhandener eigener Antrag wird verknuepft, nicht verdoppelt, aber nie veraendert (3b)', function () {
    // Spec 5.4, Pfad 'link', Entscheidung 3b: Existiert schon ein eigener, nicht
    // abgelehnter Abwesenheitsantrag zum Termin (z. B. ueber exceptions direkt
    // gestellt), haengt sich die Absage daran an, statt einen zweiten Antrag
    // anzulegen -- aendert oder loescht ihn danach aber nie: Der Antrag gehoert
    // dem Mitglied, nicht der Rueckmeldung. Nur ein von der Rueckmeldung selbst
    // angelegter Antrag wird mitgezogen bzw. geloescht (siehe responses_unit.php).
    $welt = rsWorld('Verknuepft', ['responses_enabled' => 1, 'responses_require_excuse' => 1]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');

        rsWithUserInWorld($welt, function (int $userMember) use ($apt) {
            rsCreate('exceptions', [
                'member_id' => $userMember, 'appointment_id' => $apt,
                'exception_type' => 'absence', 'reason' => 'RS-eigener-Antrag', 'status' => 'pending',
            ]);

            $antraege = static function () use ($userMember, $apt): array {
                $liste = apiRequest('GET', 'exceptions', ['token' => apiToken('admin'),
                    'query' => ['member_id' => $userMember, 'type' => 'absence']]);
                assertStatus(200, $liste);

                return array_values(array_filter($liste['body'],
                    static fn ($e) => (int) $e['appointment_id'] === $apt));
            };
            assertSame(1, count($antraege()), 'Vorbedingung: genau der eine, direkt angelegte Antrag');

            $res = rsPut('user', $apt, ['status' => 'no', 'comment' => 'RS-Absage']);
            assertStatus(200, $res);
            assertSame('pending', $res['body']['own']['excuse_state']);
            assertSame(1, count($antraege()), 'Verknuepfung darf keinen zweiten Antrag anlegen');
            assertSame('RS-eigener-Antrag', $antraege()[0]['reason'], 'Verknuepfter Antrag behaelt seine Begruendung');

            // 3b: eine geaenderte Bemerkung bei weiterhin 'no' darf den nur
            // verknuepften Antrag nicht veraendern.
            $res = rsPut('user', $apt, ['status' => 'no', 'comment' => 'RS-Absage geaendert']);
            assertStatus(200, $res);
            assertSame('RS-Absage geaendert', $res['body']['own']['comment'], 'Die Rueckmeldung selbst speichert die neue Bemerkung');
            assertSame(1, count($antraege()));
            assertSame('RS-eigener-Antrag', $antraege()[0]['reason'], 'Der verknuepfte Antrag behaelt seine eigene Begruendung');

            // 3b: eine Zusage loescht einen nur verknuepften Antrag nicht.
            assertStatus(200, rsPut('user', $apt, ['status' => 'yes']));
            assertSame(1, count($antraege()), 'Zusage darf einen nur verknuepften Antrag nicht loeschen');
            assertSame('pending', $antraege()[0]['status'], 'bleibt unveraendert offen');
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('PUT: nach geloeschtem, von der Rueckmeldung erzeugtem Antrag legt eine reine Bemerkungsaenderung keinen neuen an (4b)', function () {
    // Spec 5.4, Entscheidung 4b: Loescht ein Verwalter den von der Rueckmeldung
    // erzeugten Antrag direkt ueber exceptions, setzt resp_exception_fk (ON
    // DELETE SET NULL) die Verknuepfung zurueck. Bleibt der Status 'no' und
    // aendert sich nur die Bemerkung, ist das kein echter Statuswechsel -- es
    // entsteht kein neuer Antrag. Erst ein echter Wechsel auf 'no' legt wieder
    // einen an.
    $welt = rsWorld('Geloescht', ['responses_enabled' => 1, 'responses_require_excuse' => 1]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');

        rsWithUserInWorld($welt, function (int $userMember) use ($apt) {
            $antraege = static function () use ($userMember, $apt): array {
                $liste = apiRequest('GET', 'exceptions', ['token' => apiToken('admin'),
                    'query' => ['member_id' => $userMember, 'type' => 'absence']]);
                assertStatus(200, $liste);

                return array_values(array_filter($liste['body'],
                    static fn ($e) => (int) $e['appointment_id'] === $apt));
            };

            $res = rsPut('user', $apt, ['status' => 'no', 'comment' => 'Erkaeltung']);
            assertStatus(200, $res);
            assertSame('pending', $res['body']['own']['excuse_state']);
            assertSame(1, count($antraege()), 'Vorbedingung: der von der Rueckmeldung erzeugte Antrag');

            assertStatus(200, apiRequest('DELETE', 'exceptions', ['token' => apiToken('admin'),
                'query' => ['id' => (int) $antraege()[0]['exception_id']]]));
            assertSame(0, count($antraege()), 'Vorbedingung: Antrag vom Verwalter geloescht');

            $res = rsPut('user', $apt, ['status' => 'no', 'comment' => 'Erkaeltung, immer noch']);
            assertStatus(200, $res);
            assertSame(null, $res['body']['own']['excuse_state'], 'keine Verknuepfung mehr');
            assertSame(0, count($antraege()), 'reine Bemerkungsaenderung darf keinen neuen Antrag anlegen');

            assertStatus(200, rsPut('user', $apt, ['status' => 'yes']));
            $res = rsPut('user', $apt, ['status' => 'no', 'comment' => 'jetzt doch']);
            assertStatus(200, $res);
            assertSame('pending', $res['body']['own']['excuse_state']);
            assertSame(1, count($antraege()), 'echter Wechsel auf no legt einen neuen Antrag an');
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('PUT: Terminart ohne Rueckmeldung bleibt 409, auch fuer ein Mitglied per member_id', function () {
    $welt = rsWorld('AusPut');
    try {
        $apt = rsAppointment($welt, rsDateInDays(3), '19:00:00');
        assertStatus(409, rsPut('manager', $apt, ['status' => 'yes'], $welt['member']));
    } finally {
        rsDropWorld($welt);
    }
});

test('appointments: Liste traegt Summen und die eigene Antwort, ohne Rueckmeldung null', function () {
    $mit  = rsWorld('ListeMit', ['responses_enabled' => 1]);
    $ohne = rsWorld('ListeOhne');
    try {
        $tag  = rsDateInDays(5);
        $aptM = rsAppointment($mit, $tag, '19:00:00');
        $aptO = rsAppointment($ohne, $tag, '19:00:00');

        rsWithUserInWorld($mit, function () use ($aptM, $aptO, $tag) {
            assertStatus(200, rsPut('user', $aptM, ['status' => 'maybe']));

            $liste = apiRequest('GET', 'appointments', ['token' => apiToken('admin'),
                'query' => ['year' => substr($tag, 0, 4)]]);
            assertStatus(200, $liste);
            $byId = [];
            foreach ($liste['body'] as $row) {
                $byId[(int) $row['appointment_id']] = $row;
            }

            assertSame(['yes' => 0, 'no' => 0, 'maybe' => 1, 'open' => 1, 'own' => null, 'expected' => false],
                       $byId[$aptM]['responses'], 'Admin hat hier keine eigene Antwort und ist nicht erwartet');
            assertSame(null, $byId[$aptO]['responses']);

            $alsUser = apiRequest('GET', 'appointments', ['token' => apiToken('user'),
                'query' => ['year' => substr($tag, 0, 4)]]);
            $eigen = array_values(array_filter($alsUser['body'],
                static fn ($r) => (int) $r['appointment_id'] === $aptM))[0];
            assertSame('maybe', $eigen['responses']['own']);
            assertSame(true, $eigen['responses']['expected'], 'Das Testmitglied ist in dieser Welt erwartet');
        });
    } finally {
        rsDropWorld($mit);
        rsDropWorld($ohne);
    }
});

test('appointments: ohne Datumsfilter bleibt die Liste wie vor der Rueckmeldung, kein Schluessel responses', function () {
    // Die Check-in-PWA ruft die Liste mit member_id allein ueber die ganze Historie
    // ab -- dort waeren die je Termin korrelierten Unterabfragen zu teuer, deshalb
    // bleibt der Schluessel dort ganz weg (nicht etwa null).
    $welt = rsWorld('OhneJahr', ['responses_enabled' => 1]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(5), '19:00:00');

        rsWithUserInWorld($welt, function (int $userMember) use ($apt) {
            $liste = apiRequest('GET', 'appointments', ['token' => apiToken('user'),
                'query' => ['member_id' => $userMember]]);
            assertStatus(200, $liste);
            $zeile = array_values(array_filter($liste['body'],
                static fn ($r) => (int) $r['appointment_id'] === $apt))[0] ?? null;
            assertTrue($zeile !== null, 'Termin muss in der ungebundenen Liste stehen');
            assertTrue(!array_key_exists('responses', $zeile),
                'Ohne Jahres-/Datumsfilter darf der Schluessel responses gar nicht erst auftauchen');
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('appointments: Antwort eines nicht mehr erwarteten Mitglieds zaehlt nicht mehr mit', function () {
    // Der Zaehler bildet die aktuell erwarteten Mitglieder ab: Verlaesst ein
    // Mitglied die Gruppe der Terminart, faellt seine Antwort aus der Summe,
    // obwohl die Antwort in der Datenbank stehen bleibt.
    $welt = rsWorld('Austritt', ['responses_enabled' => 1]);
    $ersatzGruppe = null;
    try {
        $tag = rsDateInDays(5);
        $apt = rsAppointment($welt, $tag, '19:00:00');

        assertStatus(200, rsPut('manager', $apt, ['status' => 'yes'], $welt['member']));

        $leer = apiRequest('PUT', 'members', ['token' => apiToken('admin'),
            'query' => ['id' => $welt['member']], 'body' => ['group_ids' => []]]);
        if ($leer['status'] === 200) {
            assertSame([], rsMemberGroupIds($welt['member']));
        } else {
            // PUT lehnt eine leere Gruppenliste ab -- Ersatzgruppe statt Austritt ins Leere.
            $ersatzGruppe = rsCreate('member_groups', ['group_name' => 'RS Ersatz ' . uniqid()]);
            rsSetMemberGroups($welt['member'], [$ersatzGruppe]);
        }

        $liste = apiRequest('GET', 'appointments', ['token' => apiToken('admin'),
            'query' => ['year' => substr($tag, 0, 4)]]);
        assertStatus(200, $liste);
        $byId = [];
        foreach ($liste['body'] as $row) {
            $byId[(int) $row['appointment_id']] = $row;
        }
        assertSame(0, $byId[$apt]['responses']['yes'], 'Antwort zaehlt nicht mehr, Mitglied nicht mehr erwartet');
        assertSame(0, $byId[$apt]['responses']['open']);

        $einzeln = apiRequest('GET', 'appointment_responses', ['token' => apiToken('admin'),
            'query' => ['appointment_id' => $apt]]);
        assertStatus(200, $einzeln);
        assertSame(0, $einzeln['body']['summary']['yes']);
    } finally {
        rsDropWorld($welt);
        if ($ersatzGruppe !== null) {
            rsDelete('member_groups', $ersatzGruppe);
        }
    }
});

test('my_data: eigene Rueckmeldungen in JSON und CSV', function () {
    $welt = rsWorld('Auskunft', ['responses_enabled' => 1]);
    try {
        $apt = rsAppointment($welt, rsDateInDays(6), '19:00:00');
        rsWithUserInWorld($welt, function () use ($apt) {
            assertStatus(200, rsPut('user', $apt, ['status' => 'yes', 'comment' => 'RS-Auskunft']));

            $json = apiRequest('GET', 'my_data', ['token' => apiToken('user')]);
            assertStatus(200, $json);
            $kommentare = array_column($json['body']['appointment_responses'], 'comment');
            assertTrue(in_array('RS-Auskunft', $kommentare, true), 'Rueckmeldung fehlt in der Auskunft');

            $csv = apiRequest('GET', 'my_data', ['token' => apiToken('user'), 'query' => ['format' => 'csv']]);
            assertTrue(str_contains($csv['raw'], '=== TERMINRÜCKMELDUNGEN ==='), 'CSV-Abschnitt fehlt');
            assertTrue(str_contains($csv['raw'], 'Status geändert'), 'Spalte Status geaendert fehlt');
            assertTrue(str_contains($csv['raw'], 'RS-Auskunft'));
        });
    } finally {
        rsDropWorld($welt);
    }
});

test('cleanup: Rueckmeldungen alter Termine werden mit geloescht', function () {
    // ACHTUNG, wie cleanup_api.php: nur mit Fristen, die ausschliesslich die
    // eigenen Testdaten treffen. Der alte Termin liegt 1920, die Frist 100 Jahre.
    $welt = rsWorld('Cleanup', ['responses_enabled' => 1]);
    try {
        $alt = rsAppointment($welt, '1920-05-01', '19:00:00');
        assertStatus(200, rsPut('manager', $alt, ['status' => 'yes'], $welt['member']));

        // Gegenprobe: ein junger Termin in derselben Welt ueberlebt.
        $jung = rsAppointment($welt, rsDateInDays(10), '19:00:00');
        assertStatus(200, rsPut('manager', $jung, ['status' => 'yes'], $welt['member']));

        $res = apiRequest('POST', 'cleanup', ['token' => apiToken('admin'),
            'body' => ['years' => 100, 'years_worktime' => 30, 'years_audit' => 100]]);
        assertStatus(200, $res);
        assertTrue((int) $res['body']['deleted_appointment_responses'] >= 1,
            'Die Rueckmeldung zum Termin von 1920 haette geloescht werden muessen');

        $alteAntwort = rsGet('admin', ['appointment_id' => $alt]);
        assertStatus(200, $alteAntwort);
        assertSame(0, $alteAntwort['body']['summary']['yes'], 'Alte Rueckmeldung haette geloescht werden muessen');

        $jungeAntwort = rsGet('admin', ['appointment_id' => $jung]);
        assertStatus(200, $jungeAntwort);
        assertSame(1, $jungeAntwort['body']['summary']['yes'], 'Junge Rueckmeldung haette ueberleben muessen');
    } finally {
        rsDropWorld($welt);
    }
});
