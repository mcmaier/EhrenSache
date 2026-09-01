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

/** Legt eine Taetigkeitsart an und liefert ihre id. */
function createActivityType(string $name): int
{
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => $name],
    ]);
    assertStatus(201, $res, "Anlegen von '{$name}' fehlgeschlagen");

    return (int) $res['body']['id'];
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

/**
 * Legt einen Termin am heutigen Tag an und liefert seine id.
 * Die Uhrzeit wird zufaellig gewaehlt, weil appointments einen
 * Konflikt-Check gegen zeitnahe Termine hat.
 */
function createTodayAppointment(string $title): int
{
    $time = sprintf('%02d:%02d:00', random_int(1, 4), random_int(0, 59));
    $res  = apiRequest('POST', 'appointments', [
        'token' => apiToken('admin'),
        'body'  => [
            'title'      => $title,
            'date'       => date('Y-m-d'),
            'start_time' => $time,
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
