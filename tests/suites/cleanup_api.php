<?php
/**
 * DSGVO-Bereinigung ueber resource=cleanup.
 *
 * Deckt ab: die Rollenschranke, die Untergrenze der Fristen und das Loeschen
 * alter Arbeitszeitsitzungen samt ihrer Aenderungshistorie.
 *
 * ACHTUNG — diese Suite loescht. Sie laeuft deshalb ausschliesslich mit
 * Fristen, die ihre eigenen Testdaten treffen und sonst nichts:
 * 100 Jahre fuer Anwesenheiten, Ausnahmen und die verwaiste Historie,
 * 30 Jahre fuer die Arbeitszeit. Die Testsitzung wird auf 1986 datiert.
 * Niemals eine kleine Frist eintragen — der Endpunkt kennt kein Undo.
 *
 * Die Anonymisierung verwaister Logzeilen und der Schutz laufender Sitzungen
 * brauchen ein zurueckdatiertes changed_at bzw. start_time und stehen deshalb
 * in tests/db/verify_cleanup_retention.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** Fristen, die garantiert nur die Testdaten dieser Suite erfassen. */
const CL_SAFE_YEARS = ['years' => 100, 'years_worktime' => 30, 'years_audit' => 100];

function clSetSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

function clEnableWorktime(): void
{
    static $done = false;
    if (!$done) {
        clSetSetting('worktime_enabled', '1');
        $done = true;
    }
}

/** Eine Taetigkeitsart fuer die Testsitzungen, an die Gruppen des Testnutzers gebunden. */
function clActivityId(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $memberRes = apiRequest('GET', 'members', [
        'token' => apiToken('admin'),
        'query' => ['id' => (int) apiMemberId('user')],
    ]);
    assertStatus(200, $memberRes);
    $groupIds = array_map(
        static fn ($group) => (int) $group['group_id'],
        $memberRes['body']['groups'] ?? []
    );

    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => 'Cleanup-Test ' . uniqid(), 'group_ids' => $groupIds],
    ]);
    assertStatus(201, $res, 'Taetigkeitsart fuer die Cleanup-Tests konnte nicht angelegt werden');

    return $id = (int) $res['body']['id'];
}

/**
 * Legt einen Nachtrag fuer das Testmitglied an und liefert die session_id.
 * Der Admin traegt stellvertretend ein, damit die Gruppenpruefung entfaellt.
 */
function clCreateSession(string $startsAt, string $endsAt): int
{
    clEnableWorktime();

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('admin'),
        'body'  => [
            'member_id'     => (int) apiMemberId('user'),
            'activity_id'   => clActivityId(),
            'start_time'    => $startsAt,
            'end_time'      => $endsAt,
            'break_minutes' => 0,
            'note'          => 'Cleanup-Test',
        ],
    ]);
    assertStatus(201, $res, 'Testsitzung konnte nicht angelegt werden');

    return (int) $res['body']['session']['session_id'];
}

/** @param array<string, int> $overrides */
function clCleanup(array $overrides = [], string $role = 'admin'): array
{
    return apiRequest('POST', 'cleanup', [
        'token' => apiToken($role),
        'body'  => array_merge(CL_SAFE_YEARS, $overrides),
    ]);
}

function clSessionExists(int $sessionId): bool
{
    $res = apiRequest('GET', 'work_sessions', [
        'token' => apiToken('admin'),
        'query' => ['id' => $sessionId],
    ]);

    return $res['status'] === 200;
}

test('cleanup: user darf nicht bereinigen', function () {
    assertStatus(403, clCleanup([], 'user'));
});

test('cleanup: manager darf nicht bereinigen', function () {
    assertStatus(403, clCleanup([], 'manager'));
});

// Die Fristpruefung selbst steht in cleanup_unit.php. Sie hier abzuschicken
// hiesse, den Endpunkt mit genau dem Wert aufzurufen, der ohne Pruefung alles
// bis heute loescht — dieser Test hat am 2026-09-04 die Entwicklungsdaten
// zerstoert. Der API-Test unten prueft nur noch, dass die Pruefung greift,
// und schickt dafuer einen Wert, der auch ungeprueft nichts erfassen wuerde.
test('cleanup: ungueltige Frist wird abgewiesen', function () {
    assertStatus(400, clCleanup(['years' => 'drei']),
        'Eine nicht numerische Frist muss vor jedem DELETE abgewiesen werden');
});

test('cleanup: Antwort nennt einen Stichtag je Tabellengruppe', function () {
    $res = clCleanup();
    assertStatus(200, $res);

    assertSame(date('Y-m-d', strtotime('-100 years')), $res['body']['cutoff_date']);
    assertSame(date('Y-m-d', strtotime('-30 years')),  $res['body']['cutoff_date_worktime']);
    assertSame(date('Y-m-d', strtotime('-100 years')), $res['body']['cutoff_date_audit']);
});

test('cleanup: alte Sitzung wird samt Historie geloescht', function () {
    $sessionId = clCreateSession('1986-05-04 09:00:00', '1986-05-04 11:00:00');
    assertTrue(clSessionExists($sessionId), 'Testsitzung wurde nicht angelegt');

    $res = clCleanup();
    assertStatus(200, $res);

    assertTrue((int) $res['body']['deleted_work_sessions'] >= 1,
        'Die 1986 datierte Sitzung haette geloescht werden muessen');
    assertTrue((int) $res['body']['deleted_work_session_log'] >= 1,
        'Zur geloeschten Sitzung gehoert mindestens der create-Eintrag der Historie');
    assertTrue(!clSessionExists($sessionId), 'Sitzung ist nach der Bereinigung noch da');
});

test('cleanup: junge Sitzung ueberlebt die Bereinigung', function () {
    $sessionId = clCreateSession(
        date('Y-m-d H:i:s', strtotime('-3 hours')),
        date('Y-m-d H:i:s', strtotime('-1 hours'))
    );

    assertStatus(200, clCleanup());
    assertTrue(clSessionExists($sessionId), 'Eine heutige Sitzung darf nie in die Frist fallen');

    apiRequest('DELETE', 'work_sessions', [
        'token' => apiToken('admin'),
        'query' => ['id' => $sessionId],
    ]);
});

test('cleanup: die Bereinigung loescht ihre eigene Spur nicht als verwaist mit', function () {
    // Der delete-Eintrag des Aufraeumens von eben ist frisch: er darf beim
    // naechsten Lauf nicht anonymisiert werden, solange die Auditfrist laeuft.
    $res = clCleanup();
    assertStatus(200, $res);
    assertSame(0, (int) $res['body']['anonymized_work_session_log'],
        'Innerhalb der Auditfrist darf keine Logzeile angefasst werden');
});

test('cleanup: aufraeumen der Testtaetigkeitsart', function () {
    $res = apiRequest('DELETE', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => clActivityId()],
    ]);
    assertStatus(200, $res);
});
