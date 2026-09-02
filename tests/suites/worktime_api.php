<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * Setzt eine Systemeinstellung.
 * PUT settings erwartet {setting_key, setting_value} — nicht das Feld direkt.
 */
function setSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

/**
 * Die Zeiterfassung muss fuer diese Suite eingeschaltet sein.
 * Der Schalter wird gesetzt und am Ende NICHT zurueckgenommen —
 * die Suite laeuft gegen eine Entwicklungsinstanz.
 */
function enableWorktime(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    setSetting('worktime_enabled', '1');
    $done = true;
}

/**
 * Sammelt alles, was die Suite anlegt, damit der Abschlusstest es wieder
 * entfernen kann. Tests, die ihre Spuren nicht beseitigen, muellen die
 * Entwicklungsdatenbank mit jedem Lauf weiter zu.
 */
function trackCreated(string $kind, int $id): int
{
    static $created = ['activity' => [], 'appointment_type' => [], 'group' => []];
    if ($id > 0) {
        $created[$kind][] = $id;
    }

    return $id;
}

/** @return array<int, int> */
function createdIds(string $kind): array
{
    static $dummy = 0;
    $ref = new ReflectionFunction('trackCreated');
    $statics = $ref->getStaticVariables();

    return $statics['created'][$kind] ?? [];
}

/** Legt eine Taetigkeitsart an und liefert ihre id. */
function createActivityType(string $name): int
{
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => $name],
    ]);
    assertStatus(201, $res, "Anlegen von '{$name}' fehlgeschlagen");

    return trackCreated('activity', (int) $res['body']['id']);
}

test('activity_types: Admin kann anlegen und lesen', function () {
    enableWorktime();
    $name = 'Test-Taetigkeit ' . uniqid();
    $id   = createActivityType($name);

    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    assertStatus(200, $res);
    assertSame($name, $res['body']['activity_name']);
    assertSame('none', $res['body']['verification']);
    assertSame(1, (int) $res['body']['is_active']);

    apiRequest('DELETE', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
});

test('activity_types: user darf lesen', function () {
    enableWorktime();
    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('user')]);
    assertStatus(200, $res);
    assertTrue(is_array($res['body']), 'Liste erwartet');
});

test('activity_types: user darf nicht anlegen', function () {
    enableWorktime();
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('user'),
        'body'  => ['activity_name' => 'Verboten'],
    ]);
    assertStatus(403, $res);
});

test('activity_types: leerer Name wird abgewiesen', function () {
    enableWorktime();
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => ''],
    ]);
    assertStatus(400, $res);
});

test('activity_types: ungueltiger verification-Wert wird abgewiesen', function () {
    enableWorktime();
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => 'Ungueltig', 'verification' => 'vielleicht'],
    ]);
    assertStatus(400, $res);
});

test('activity_types: Admin kann aendern und loeschen', function () {
    enableWorktime();
    $id = createActivityType('Zu aendern ' . uniqid());

    $res = apiRequest('PUT', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['activity_name' => 'Geaendert', 'verification' => 'start', 'is_active' => 0],
    ]);
    assertStatus(200, $res);

    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    assertSame('Geaendert', $res['body']['activity_name']);
    assertSame('start', $res['body']['verification']);

    $res = apiRequest('DELETE', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    assertStatus(200, $res);
});

test('activity_types: user sieht ausgemusterte Arten nicht', function () {
    enableWorktime();
    $id = createActivityType('Ausgemustert ' . uniqid());
    assertStatus(200, apiRequest('PUT', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['activity_name' => 'Ausgemustert', 'is_active' => 0],
    ]));

    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('user')]);
    $ids = array_column($res['body'], 'activity_id');
    assertTrue(!in_array((string) $id, array_map('strval', $ids), true),
        'Ausgemusterte Taetigkeitsart darf fuer user nicht sichtbar sein');

    apiRequest('DELETE', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
});

test('Feature-Schalter: bei worktime_enabled=0 antwortet activity_types mit 404', function () {
    enableWorktime();
    try {
        setSetting('worktime_enabled', '0');
        $res = apiRequest('GET', 'activity_types', ['token' => apiToken('admin')]);
        assertStatus(404, $res, 'Abgeschaltetes Feature muss 404 liefern, nicht 403');
    } finally {
        // Der Schalter muss in jedem Fall zurueck, sonst scheitern alle
        // folgenden Tests an einer abgeschalteten Zeiterfassung.
        setSetting('worktime_enabled', '1');
    }
});

test('work_sessions: leere Liste fuer einen Nutzer ohne Sitzungen', function () {
    enableWorktime();
    $res = apiRequest('GET', 'work_sessions', ['token' => apiToken('user')]);
    assertStatus(200, $res);
    assertTrue(is_array($res['body']), 'Liste erwartet');
});

test('work_sessions: running=1 liefert null ohne laufende Sitzung', function () {
    enableWorktime();
    $res = apiRequest('GET', 'work_sessions', [
        'token' => apiToken('user'),
        'query' => ['running' => 1],
    ]);
    assertStatus(200, $res);
    assertSame(null, $res['body']);
});

test('work_sessions: unbekannte id liefert 404', function () {
    enableWorktime();
    $res = apiRequest('GET', 'work_sessions', [
        'token' => apiToken('admin'),
        'query' => ['id' => 999999],
    ]);
    assertStatus(404, $res);
});

test('work_sessions: Zugriff ohne Token wird abgewiesen', function () {
    enableWorktime();
    $res = apiRequest('GET', 'work_sessions');
    assertTrue($res['status'] === 401 || $res['status'] === 403,
        "401 oder 403 erwartet, {$res['status']} erhalten");
});

/** Beendet eine ggf. laufende Sitzung des Test-Users, damit der naechste Start frei ist. */
function stopRunningIfAny(string $role = 'user'): void
{
    $res = apiRequest('GET', 'work_sessions', ['token' => apiToken($role), 'query' => ['running' => 1]]);
    if (is_array($res['body'] ?? null)) {
        apiRequest('POST', 'work_sessions', [
            'token' => apiToken($role),
            'body'  => ['action' => 'stop'],
        ]);
    }
}

test('work_sessions: start legt eine laufende Sitzung an', function () {
    enableWorktime();
    stopRunningIfAny();
    $activityId = createActivityType('Timer-Test ' . uniqid());

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId],
    ]);
    assertStatus(201, $res);

    $run = apiRequest('GET', 'work_sessions', [
        'token' => apiToken('user'),
        'query' => ['running' => 1],
    ]);
    assertStatus(200, $run);
    assertTrue(is_array($run['body']), 'Laufende Sitzung erwartet');
    assertSame(true, $run['body']['is_running']);
    assertSame(false, $run['body']['is_paused']);
    assertSame('timer', $run['body']['source']);
    assertSame('confirmed', $run['body']['status']);
    assertSame(null, $run['body']['duration_minutes']);

    stopRunningIfAny();
});

test('work_sessions: zweiter start bei laufender Sitzung wird abgewiesen', function () {
    enableWorktime();
    stopRunningIfAny();
    $activityId = createActivityType('Doppelstart ' . uniqid());

    assertStatus(201, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId],
    ]));

    $second = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId],
    ]);
    assertStatus(409, $second);

    stopRunningIfAny();
});

test('work_sessions: start mit unbekannter Taetigkeitsart wird abgewiesen', function () {
    enableWorktime();
    stopRunningIfAny();

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => 999999],
    ]);
    assertStatus(400, $res);
});

test('work_sessions: start mit ausgemusterter Taetigkeitsart wird abgewiesen', function () {
    enableWorktime();
    stopRunningIfAny();
    $id = createActivityType('Ausgemustert-Start ' . uniqid());
    assertStatus(200, apiRequest('PUT', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['activity_name' => 'Ausgemustert', 'is_active' => 0],
    ]));

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $id],
    ]);
    assertStatus(400, $res);
});

test('work_sessions: unbekannte action wird abgewiesen', function () {
    enableWorktime();
    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'fliegen'],
    ]);
    assertStatus(400, $res);
});

test('work_sessions: pause, resume und stop laufen durch', function () {
    enableWorktime();
    stopRunningIfAny();
    $activityId = createActivityType('Pausentest ' . uniqid());

    assertStatus(201, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId],
    ]));

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'pause'],
    ]);
    assertStatus(200, $res);
    assertSame(true, $res['body']['session']['is_paused']);

    // Idempotent: nochmal pausieren aendert nichts
    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'pause'],
    ]);
    assertStatus(200, $res);
    assertSame(true, $res['body']['session']['is_paused']);

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'resume'],
    ]);
    assertStatus(200, $res);
    assertSame(false, $res['body']['session']['is_paused']);

    // Idempotent: nochmal fortsetzen aendert nichts
    assertStatus(200, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'resume'],
    ]));

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'stop', 'note' => 'fertig'],
    ]);
    assertStatus(200, $res);
    assertSame(false, $res['body']['session']['is_running']);
    assertSame('fertig', $res['body']['session']['note']);
    assertTrue(is_int($res['body']['session']['duration_minutes']), 'Dauer erwartet');
});

test('work_sessions: pause ohne laufende Sitzung wird abgewiesen', function () {
    enableWorktime();
    stopRunningIfAny();

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'pause'],
    ]);
    assertStatus(409, $res);
});

test('work_sessions: stop beendet eine laufende Pause mit', function () {
    enableWorktime();
    stopRunningIfAny();
    $activityId = createActivityType('Stop-in-Pause ' . uniqid());

    assertStatus(201, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId],
    ]));
    assertStatus(200, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'pause'],
    ]));

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'stop'],
    ]);
    assertStatus(200, $res);
    assertSame(false, $res['body']['session']['is_paused']);
    assertSame(false, $res['body']['session']['is_running']);
});

test('work_sessions: nach dem Stoppen ist ein neuer Start moeglich', function () {
    enableWorktime();
    stopRunningIfAny();
    $activityId = createActivityType('Neustart ' . uniqid());

    assertStatus(201, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'start', 'activity_id' => $activityId],
    ]));
    assertStatus(200, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'stop'],
    ]));
    assertStatus(201, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'start', 'activity_id' => $activityId],
    ]));

    stopRunningIfAny();
});

/** Legt eine Mitgliedergruppe an und liefert ihre id. */
function createGroup(string $name): int
{
    $res = apiRequest('POST', 'member_groups', [
        'token' => apiToken('admin'),
        'body'  => ['group_name' => $name],
    ]);
    assertStatus(201, $res, "Gruppe '{$name}' konnte nicht angelegt werden");

    return trackCreated('group', (int) $res['body']['id']);
}

/**
 * Setzt die Gruppen eines Mitglieds — ersetzend, nicht ergaenzend.
 * members PUT erwartet group_ids als Array und schreibt die Zuordnung neu.
 *
 * @param array<int, int> $groupIds
 */
function setMemberGroups(int $memberId, array $groupIds): void
{
    $res = apiRequest('PUT', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
        'body'  => ['group_ids' => $groupIds],
    ]);
    assertStatus(200, $res, "Gruppen von Mitglied {$memberId} konnten nicht gesetzt werden");
}

/**
 * Liest die aktuellen Gruppen-Ids eines Mitglieds, um sie spaeter wiederherzustellen.
 *
 * GET members?id= liefert fuer Admin/Manager NICHT das Feld group_ids (das gibt es
 * nur in der Mitgliederliste und im "eigene Daten"-Zweig fuer normale User), sondern
 * ein "groups"-Array mit {group_id, group_name} — siehe private/handlers/members.php,
 * Zeilen 25-46 vs. 49-72.
 */
function memberGroupIds(int $memberId): array
{
    $res = apiRequest('GET', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => $memberId],
    ]);
    assertStatus(200, $res);

    return array_map(static fn ($group) => (int) $group['group_id'], $res['body']['groups'] ?? []);
}

/** Ordnet eine Taetigkeitsart genau den angegebenen Gruppen zu. */
function setActivityGroups(int $activityId, array $groupIds, string $name): void
{
    $res = apiRequest('PUT', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $activityId],
        'body'  => ['activity_name' => $name, 'group_ids' => $groupIds],
    ]);
    assertStatus(200, $res, "Gruppen der Taetigkeitsart {$activityId} nicht gesetzt");
}

/**
 * Eigene Terminart fuer die Tests. Die Konfliktpruefung in appointments
 * arbeitet je Terminart — mit einer eigenen Art kollidieren Testtermine
 * nie mit echten Terminen des Vereins.
 */
function testAppointmentTypeId(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $res = apiRequest('POST', 'appointment_types', [
        'token' => apiToken('admin'),
        'body'  => ['type_name' => 'Zeiterfassungstest ' . uniqid()],
    ]);
    assertStatus(201, $res, 'Test-Terminart konnte nicht angelegt werden');

    return $id = trackCreated('appointment_type', (int) $res['body']['id']);
}

/**
 * Legt einen Termin am heutigen Tag an und liefert seine id.
 *
 * Die Uhrzeiten liegen fuenf Stunden auseinander, weil appointments
 * Termine derselben Art im Fenster von +/- AUTO_CHECKIN_TOLERANCE_HOURS
 * (Standard: 2h) als Konflikt abweist.
 */
function createTodayAppointment(string $title): int
{
    static $slot = 0;
    $hour = 1 + ($slot++ * 5);
    assertTrue($hour < 24, 'Zu viele Testtermine an einem Tag');

    $res = apiRequest('POST', 'appointments', [
        'token' => apiToken('admin'),
        'body'  => [
            'title'      => $title,
            'date'       => date('Y-m-d'),
            'start_time' => sprintf('%02d:00:00', $hour),
            'type_id'    => testAppointmentTypeId(),
        ],
    ]);
    assertStatus(201, $res, "Termin '{$title}' konnte nicht angelegt werden");

    return (int) ($res['body']['id'] ?? $res['body']['appointment_id']);
}

/** Liest den records-Eintrag eines Mitglieds zu einem Termin, oder null. */
function findRecord(int $appointmentId, int $memberId): ?array
{
    $res = apiRequest('GET', 'records', [
        'token' => apiToken('admin'),
        'query' => ['appointment_id' => $appointmentId],
    ]);
    foreach (($res['body'] ?? []) as $r) {
        if ((int) $r['member_id'] === $memberId) {
            return $r;
        }
    }

    return null;
}

test('work_sessions: Start mit Termin erzeugt den Check-in (E4)', function () {
    enableWorktime();
    stopRunningIfAny();

    $memberId      = apiMemberId('user');
    $activityId    = createActivityType('Terminbezug ' . uniqid());
    $appointmentId = createTodayAppointment('Zeiterfassungstest ' . uniqid());

    assertSame(null, findRecord($appointmentId, $memberId), 'Vorher darf kein Check-in existieren');

    assertStatus(201, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId, 'appointment_id' => $appointmentId],
    ]));

    $record = findRecord($appointmentId, $memberId);
    assertTrue($record !== null, 'Der Timer-Start haette einen records-Eintrag anlegen muessen');
    assertSame('timer', $record['checkin_source']);

    stopRunningIfAny();
    apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $appointmentId]]);
});

test('work_sessions: Start mit Termin ueberschreibt einen frueheren Check-in nicht (E4)', function () {
    enableWorktime();
    stopRunningIfAny();

    $memberId      = apiMemberId('user');
    $activityId    = createActivityType('Kein-Ueberschreiben ' . uniqid());
    $appointmentId = createTodayAppointment('Frueher Check-in ' . uniqid());

    // Check-in eine Stunde vor dem Timer-Start, ueber den bestehenden Weg
    $early = date('Y-m-d H:i:s', strtotime('-1 hour'));
    assertStatus(201, apiRequest('POST', 'records', [
        'token' => apiToken('admin'),
        'body'  => [
            'member_id'      => $memberId,
            'appointment_id' => $appointmentId,
            'arrival_time'   => $early,
            'status'         => 'present',
        ],
    ]), 'Vorbereitender Check-in fehlgeschlagen');

    $before = findRecord($appointmentId, $memberId);
    assertTrue($before !== null, 'Vorbereitender Check-in fehlt');

    assertStatus(201, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId, 'appointment_id' => $appointmentId],
    ]));

    $after = findRecord($appointmentId, $memberId);
    assertSame($before['arrival_time'], $after['arrival_time'],
        'Ein Timer-Start darf eine frueher erfasste Ankunftszeit nicht ueberschreiben');
    assertSame($before['checkin_source'], $after['checkin_source'],
        'Die urspruengliche Check-in-Quelle muss erhalten bleiben');

    stopRunningIfAny();
    apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $appointmentId]]);
});

test('work_sessions: Start mit unbekanntem Termin wird abgewiesen', function () {
    enableWorktime();
    stopRunningIfAny();
    $activityId = createActivityType('Unbekannter Termin ' . uniqid());

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId, 'appointment_id' => 999999],
    ]);
    assertStatus(400, $res);
});

/** Legt einen manuellen Eintrag an und liefert die Antwort. */
function createManualSession(string $role, int $activityId, array $overrides = []): array
{
    $body = array_merge([
        'activity_id'   => $activityId,
        'start_time'    => date('Y-m-d H:i:s', strtotime('-3 hours')),
        'end_time'      => date('Y-m-d H:i:s', strtotime('-1 hours')),
        'break_minutes' => 15,
        'note'          => 'Nachtrag',
    ], $overrides);

    return apiRequest('POST', 'work_sessions', ['token' => apiToken($role), 'body' => $body]);
}

/** Loescht eine Sitzung als Admin. */
function deleteSession(int $id): void
{
    apiRequest('DELETE', 'work_sessions', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

test('work_sessions: manueller Eintrag landet in submitted', function () {
    enableWorktime();
    $activityId = createActivityType('Manuell ' . uniqid());

    $res = createManualSession('user', $activityId);
    assertStatus(201, $res);

    $id  = (int) $res['body']['session']['session_id'];
    $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['id' => $id]]);
    assertSame('submitted', $get['body']['status']);
    assertSame('manual', $get['body']['source']);
    assertSame(105, $get['body']['duration_minutes']); // 120 minus 15 Minuten Pause

    deleteSession($id);
});

test('work_sessions: manueller Eintrag mit Ende vor Beginn wird abgewiesen', function () {
    enableWorktime();
    $activityId = createActivityType('Ungueltig ' . uniqid());

    $res = createManualSession('user', $activityId, [
        'start_time' => date('Y-m-d H:i:s', strtotime('-1 hours')),
        'end_time'   => date('Y-m-d H:i:s', strtotime('-3 hours')),
    ]);
    assertStatus(400, $res);
});

test('work_sessions: manueller Eintrag in der Zukunft wird abgewiesen', function () {
    enableWorktime();
    $activityId = createActivityType('Zukunft ' . uniqid());

    $res = createManualSession('user', $activityId, [
        'start_time' => date('Y-m-d H:i:s', strtotime('+1 hours')),
        'end_time'   => date('Y-m-d H:i:s', strtotime('+3 hours')),
    ]);
    assertStatus(400, $res);
});

test('work_sessions: manueller Eintrag mit zu langer Pause wird abgewiesen', function () {
    enableWorktime();
    $activityId = createActivityType('Lange Pause ' . uniqid());

    $res = createManualSession('user', $activityId, ['break_minutes' => 180]);
    assertStatus(400, $res);
});

test('work_sessions: Manager gibt frei, Eintrag wird confirmed', function () {
    enableWorktime();
    $activityId = createActivityType('Freigabe ' . uniqid());
    $id = (int) createManualSession('user', $activityId)['body']['session']['session_id'];

    $res = apiRequest('PUT', 'work_sessions', [
        'token' => apiToken('manager'),
        'query' => ['id' => $id],
        'body'  => ['action' => 'approve'],
    ]);
    assertStatus(200, $res);

    $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['id' => $id]]);
    assertSame('confirmed', $get['body']['status']);
    assertTrue(!empty($get['body']['approved_at']), 'approved_at erwartet');

    deleteSession($id);
});

test('work_sessions: Manager kann ablehnen', function () {
    enableWorktime();
    $activityId = createActivityType('Ablehnung ' . uniqid());
    $id = (int) createManualSession('user', $activityId)['body']['session']['session_id'];

    assertStatus(200, apiRequest('PUT', 'work_sessions', [
        'token' => apiToken('manager'), 'query' => ['id' => $id], 'body' => ['action' => 'reject'],
    ]));

    $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['id' => $id]]);
    assertSame('rejected', $get['body']['status']);

    deleteSession($id);
});

test('work_sessions: user darf nicht selbst freigeben', function () {
    enableWorktime();
    $activityId = createActivityType('Selbstfreigabe ' . uniqid());
    $id = (int) createManualSession('user', $activityId)['body']['session']['session_id'];

    $res = apiRequest('PUT', 'work_sessions', [
        'token' => apiToken('user'),
        'query' => ['id' => $id],
        'body'  => ['action' => 'approve'],
    ]);
    assertStatus(403, $res);

    deleteSession($id);
});

test('work_sessions: Aenderung durch das Mitglied entzieht die Bestaetigung', function () {
    enableWorktime();
    $activityId = createActivityType('Entzug ' . uniqid());
    $id = (int) createManualSession('user', $activityId)['body']['session']['session_id'];

    assertStatus(200, apiRequest('PUT', 'work_sessions', [
        'token' => apiToken('manager'), 'query' => ['id' => $id], 'body' => ['action' => 'approve'],
    ]));

    $res = apiRequest('PUT', 'work_sessions', [
        'token' => apiToken('user'),
        'query' => ['id' => $id],
        'body'  => ['note' => 'korrigiert'],
    ]);
    assertStatus(200, $res);

    $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['id' => $id]]);
    assertSame('submitted', $get['body']['status']);
    assertSame('korrigiert', $get['body']['note']);

    deleteSession($id);
});

test('work_sessions: Manager-Aenderung laesst den Status unveraendert', function () {
    enableWorktime();
    $activityId = createActivityType('Managerkorrektur ' . uniqid());
    $id = (int) createManualSession('user', $activityId)['body']['session']['session_id'];

    assertStatus(200, apiRequest('PUT', 'work_sessions', [
        'token' => apiToken('manager'), 'query' => ['id' => $id], 'body' => ['action' => 'approve'],
    ]));
    assertStatus(200, apiRequest('PUT', 'work_sessions', [
        'token' => apiToken('manager'), 'query' => ['id' => $id], 'body' => ['note' => 'geprueft'],
    ]));

    $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['id' => $id]]);
    assertSame('confirmed', $get['body']['status']);
    assertSame('geprueft', $get['body']['note']);

    deleteSession($id);
});

test('work_sessions: eine laufende Sitzung wird nicht per PUT korrigiert', function () {
    enableWorktime();
    stopRunningIfAny();
    $activityId = createActivityType('Laufend-PUT ' . uniqid());

    $start = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'start', 'activity_id' => $activityId],
    ]);
    assertStatus(201, $start);
    $id = (int) $start['body']['session']['session_id'];

    $res = apiRequest('PUT', 'work_sessions', [
        'token' => apiToken('user'), 'query' => ['id' => $id], 'body' => ['note' => 'zu frueh'],
    ]);
    assertStatus(409, $res);

    stopRunningIfAny();
    deleteSession($id);
});

test('work_sessions: Manager darf fremde Sitzung lesen', function () {
    enableWorktime();
    $activityId = createActivityType('Managersicht ' . uniqid());
    $id = (int) createManualSession('user', $activityId)['body']['session']['session_id'];

    $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('manager'), 'query' => ['id' => $id]]);
    assertStatus(200, $get);

    deleteSession($id);
});

test('work_sessions: user darf fremde Sitzung nicht lesen', function () {
    enableWorktime();
    $ownMemberId = apiMemberId('user');

    // Ein fremdes Mitglied suchen. Bewusst nicht ueber die Rolle 'manager':
    // dieser Testaccount hat kein verknuepftes Mitglied, und der Test soll
    // die Rechtepruefung testen, nicht die Testdaten.
    $members  = apiRequest('GET', 'members', ['token' => apiToken('admin')]);
    $otherId  = null;
    foreach (($members['body'] ?? []) as $m) {
        if ((int) $m['member_id'] !== $ownMemberId) {
            $otherId = (int) $m['member_id'];
            break;
        }
    }
    assertTrue($otherId !== null, 'Kein zweites Mitglied fuer den Test vorhanden');

    $activityId = createActivityType('Fremd ' . uniqid());
    $res = createManualSession('admin', $activityId, ['member_id' => $otherId]);
    assertStatus(201, $res);
    $id = (int) $res['body']['session']['session_id'];

    $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['id' => $id]]);
    assertStatus(403, $get);

    deleteSession($id);
});

test('work_sessions: nur Admin darf loeschen', function () {
    enableWorktime();
    $activityId = createActivityType('Loeschen ' . uniqid());
    $id = (int) createManualSession('user', $activityId)['body']['session']['session_id'];

    assertStatus(403, apiRequest('DELETE', 'work_sessions', [
        'token' => apiToken('manager'), 'query' => ['id' => $id],
    ]));
    assertStatus(200, apiRequest('DELETE', 'work_sessions', [
        'token' => apiToken('admin'), 'query' => ['id' => $id],
    ]));
    assertStatus(404, apiRequest('GET', 'work_sessions', [
        'token' => apiToken('admin'), 'query' => ['id' => $id],
    ]));
});

test('work_sessions: Nachtrag eines Mitglieds braucht Freigabe', function () {
    enableWorktime();
    $activityId = createActivityType('Nachtrag user ' . uniqid());
    $res = createManualSession('user', $activityId);
    assertStatus(201, $res);

    $id  = (int) $res['body']['session']['session_id'];
    $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['id' => $id]]);
    assertSame('submitted', $get['body']['status']);
    assertSame('manual',    $get['body']['source']);

    deleteSession($id);
});

test('work_sessions: Nachtrag eines Managers gilt sofort', function () {
    enableWorktime();
    $activityId = createActivityType('Nachtrag manager ' . uniqid());

    // Der Manager ist die freigebende Instanz und genehmigt sich nicht selbst.
    $res = createManualSession('manager', $activityId, ['member_id' => apiMemberId('user')]);
    assertStatus(201, $res);

    $id  = (int) $res['body']['session']['session_id'];
    $get = apiRequest('GET', 'work_sessions', ['token' => apiToken('manager'), 'query' => ['id' => $id]]);
    assertSame('confirmed', $get['body']['status']);
    assertSame('admin',     $get['body']['source'], 'Herkunft muss als admin erkennbar bleiben');
    assertTrue(!empty($get['body']['approved_at']), 'approved_at erwartet');

    deleteSession($id);
});

test('work_sessions: ein Manager-Nachtrag zaehlt sofort in der Auswertung', function () {
    enableWorktime();
    $year       = (int) date('Y');
    $memberId   = apiMemberId('user');
    $activityId = createActivityType('Sofort zaehlend ' . uniqid());

    $vorher = 0;
    $res = apiRequest('GET', 'statistics', [
        'token' => apiToken('admin'),
        'query' => ['year' => $year, 'include' => 'worktime'],
    ]);
    foreach (($res['body']['worktime']['members'] ?? []) as $m) {
        if ((int) $m['member_id'] === $memberId) { $vorher = (int) $m['worked_minutes']; }
    }

    // Zwei Stunden mit 30 Minuten Pause = 90 Minuten netto
    $created = createManualSession('manager', $activityId, [
        'member_id'     => $memberId,
        'start_time'    => date('Y-m-d H:i:s', strtotime('-4 hours')),
        'end_time'      => date('Y-m-d H:i:s', strtotime('-2 hours')),
        'break_minutes' => 30,
    ]);
    assertStatus(201, $created);
    $id = (int) $created['body']['session']['session_id'];

    $nachher = 0;
    $res = apiRequest('GET', 'statistics', [
        'token' => apiToken('admin'),
        'query' => ['year' => $year, 'include' => 'worktime'],
    ]);
    foreach (($res['body']['worktime']['members'] ?? []) as $m) {
        if ((int) $m['member_id'] === $memberId) { $nachher = (int) $m['worked_minutes']; }
    }

    assertSame(90, $nachher - $vorher, 'Ohne Freigabe wuerde die Zeit nicht zaehlen');

    deleteSession($id);
});

test('work_sessions: Start fuer einen kuenftigen Termin erzeugt KEINEN Check-in', function () {
    enableWorktime();
    stopRunningIfAny();

    $memberId   = apiMemberId('user');
    $activityId = createActivityType('Vorbereitung ' . uniqid());

    // Termin in drei Tagen — etwa der Buehnenaufbau fuer ein spaeteres Konzert
    $res = apiRequest('POST', 'appointments', [
        'token' => apiToken('admin'),
        'body'  => [
            'title'      => 'Kuenftig ' . uniqid(),
            'date'       => date('Y-m-d', strtotime('+3 days')),
            'start_time' => '19:00:00',
            'type_id'    => testAppointmentTypeId(),
        ],
    ]);
    assertStatus(201, $res);
    $appointmentId = (int) ($res['body']['id'] ?? $res['body']['appointment_id']);

    assertStatus(201, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId,
                    'appointment_id' => $appointmentId],
    ]));

    // Die Verknuepfung besteht, ein Check-in aber nicht: Sonst waere das
    // Mitglied bei einer Veranstaltung anwesend, die noch nicht war.
    assertSame(null, findRecord($appointmentId, $memberId),
        'Ein kuenftiger Termin darf keinen Check-in erzeugen');

    $run = apiRequest('GET', 'work_sessions', [
        'token' => apiToken('user'), 'query' => ['running' => 1],
    ]);
    assertSame($appointmentId, (int) $run['body']['appointment_id'],
        'Der Terminbezug muss trotzdem gespeichert sein');

    stopRunningIfAny();
    apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $appointmentId]]);
});

test('activity_types: GET liefert die zugeordneten Gruppen mit', function () {
    enableWorktime();

    $groupId    = createGroup('Zeittest-Gruppe ' . uniqid());
    $name       = 'Gruppenprobe ' . uniqid();
    $activityId = createActivityType($name);

    setActivityGroups($activityId, [$groupId], $name);

    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('admin')]);
    assertStatus(200, $res);

    $found = null;
    foreach ($res['body'] as $row) {
        if ((int) $row['activity_id'] === $activityId) {
            $found = $row;
        }
    }

    assertTrue($found !== null, 'Angelegte Taetigkeitsart nicht in der Liste');
    assertTrue(isset($found['groups']), 'Feld groups fehlt');
    assertSame(1, count($found['groups']), 'Genau eine Gruppe erwartet');
    assertSame($groupId, (int) $found['groups'][0]['group_id'], 'Falsche Gruppe');
});

test('activity_types: user sieht nur Arten aus den eigenen Gruppen', function () {
    enableWorktime();

    $memberId = apiMemberId('user');
    $original = memberGroupIds($memberId);

    $meine   = createGroup('Meine ' . uniqid());
    $fremde  = createGroup('Fremde ' . uniqid());

    $sichtbar   = 'Sichtbar ' . uniqid();
    $unsichtbar = 'Unsichtbar ' . uniqid();
    $idA = createActivityType($sichtbar);
    $idB = createActivityType($unsichtbar);

    setActivityGroups($idA, [$meine], $sichtbar);
    setActivityGroups($idB, [$fremde], $unsichtbar);
    setMemberGroups($memberId, [$meine]);

    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('user')]);
    assertStatus(200, $res);

    $ids = array_map(static fn($r) => (int) $r['activity_id'], $res['body']);

    assertTrue(in_array($idA, $ids, true), 'Eigene Taetigkeitsart fehlt');
    assertTrue(!in_array($idB, $ids, true), 'Fremde Taetigkeitsart ist sichtbar');

    setMemberGroups($memberId, $original);
});

test('activity_types: Mitglied ohne passende Gruppe erhaelt [] mit Status 200', function () {
    enableWorktime();

    $memberId = apiMemberId('user');
    $original = memberGroupIds($memberId);

    $leer = createGroup('Ohne Taetigkeiten ' . uniqid());
    setMemberGroups($memberId, [$leer]);

    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('user')]);

    // 200 mit leerer Liste, NICHT 404: der 404 bleibt dem
    // abgeschalteten Feature vorbehalten.
    assertStatus(200, $res, 'Leere Auswahl darf kein 404 sein');
    assertSame(0, count($res['body']), 'Es darf keine Taetigkeitsart sichtbar sein');

    setMemberGroups($memberId, $original);
});

test('activity_types: Admin sieht ohne member_id alles, mit member_id gefiltert', function () {
    enableWorktime();

    $memberId = apiMemberId('user');
    $original = memberGroupIds($memberId);

    $meine  = createGroup('AdminSicht ' . uniqid());
    $fremde = createGroup('AdminFremd ' . uniqid());

    $nameA = 'AdminA ' . uniqid();
    $nameB = 'AdminB ' . uniqid();
    $idA = createActivityType($nameA);
    $idB = createActivityType($nameB);

    setActivityGroups($idA, [$meine], $nameA);
    setActivityGroups($idB, [$fremde], $nameB);
    setMemberGroups($memberId, [$meine]);

    $alle = apiRequest('GET', 'activity_types', ['token' => apiToken('admin')]);
    assertStatus(200, $alle);
    $alleIds = array_map(static fn($r) => (int) $r['activity_id'], $alle['body']);
    assertTrue(in_array($idA, $alleIds, true) && in_array($idB, $alleIds, true),
        'Admin muss ohne Filter alles sehen');

    $gefiltert = apiRequest('GET', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['member_id' => $memberId],
    ]);
    assertStatus(200, $gefiltert);
    $gefilterteIds = array_map(static fn($r) => (int) $r['activity_id'], $gefiltert['body']);
    assertTrue(in_array($idA, $gefilterteIds, true), 'Eigene Art fehlt im gefilterten Abruf');
    assertTrue(!in_array($idB, $gefilterteIds, true), 'Fremde Art im gefilterten Abruf');

    setMemberGroups($memberId, $original);
});

test('Aufraeumen: die Suite entfernt alles, was sie angelegt hat', function () {
    enableWorktime();
    stopRunningIfAny();

    $rest = [];

    foreach (createdIds('activity') as $activityId) {
        // Sitzungen dieser Taetigkeitsart zuerst — ON DELETE RESTRICT
        // verhindert sonst das Loeschen der Art.
        $sessions = apiRequest('GET', 'work_sessions', [
            'token' => apiToken('admin'),
            'query' => ['activity_id' => $activityId],
        ]);
        foreach (($sessions['body'] ?? []) as $session) {
            deleteSession((int) $session['session_id']);
        }

        $res = apiRequest('DELETE', 'activity_types', [
            'token' => apiToken('admin'),
            'query' => ['id' => $activityId],
        ]);
        if ($res['status'] !== 200) {
            $rest[] = "activity_type {$activityId} (HTTP {$res['status']})";
        }
    }

    foreach (createdIds('appointment_type') as $typeId) {
        apiRequest('DELETE', 'appointment_types', [
            'token' => apiToken('admin'),
            'query' => ['id' => $typeId],
        ]);
    }

    foreach (createdIds('group') as $groupId) {
        apiRequest('DELETE', 'member_groups', [
            'token' => apiToken('admin'),
            'query' => ['id' => $groupId],
        ]);
    }

    assertSame([], $rest, 'Nicht alles konnte entfernt werden');
});
