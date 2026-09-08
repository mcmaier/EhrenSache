<?php
/**
 * EhrenSache - Verifikation, dass ein vorhandener Ortsnachweis bei
 * Zeitkorrekturen selektiv wegfaellt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Nicht Teil von tests/run.php: Ein vorhandener Ortsnachweis laesst sich nur
 * per SQL erzeugen (er verlangt eine TOTP-Station und deren Secret) — ueber
 * HTTP allein ist der Kernfall nicht erreichbar. Geprueft wird workSessionUpdate()
 * in private/handlers/work_sessions.php, konkret worktimeProofDrop() aus
 * private/helpers/worktime.php.
 *
 * Aufruf:
 *   php tests/db/verify_proof_drop.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
 */
declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Aufruf: php tests/db/verify_proof_drop.php <dsn-mit-dbname> <user> <password> <prefix>\n");
    exit(2);
}

[$_, $dsn, $dbUser, $dbPass, $prefix] = $argv;

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api.php';

$pdo = new PDO($dsn . ';charset=utf8mb4', $dbUser, $dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function setSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

// ============================================================================
setSetting('worktime_enabled', '1');

// Taetigkeitsart ermitteln, die die Testrolle 'user' verwenden darf.
$typesRes = apiRequest('GET', 'activity_types', ['token' => apiToken('user')]);
if ($typesRes['status'] !== 200 || empty($typesRes['body'][0]['activity_id'])) {
    fwrite(STDERR, "Keine Taetigkeitsart fuer die Testrolle 'user' erreichbar — Test nicht durchfuehrbar.\n");
    fwrite(STDERR, "Im Dashboard eine Taetigkeitsart anlegen und der Gruppe des Testnutzers zuordnen.\n");
    exit(3);
}
$activityId = (int) $typesRes['body'][0]['activity_id'];

/**
 * Legt per API eine Sitzung mit fester Zeitspanne an und belegt beide
 * Ortsnachweise anschliessend direkt per SQL — genau der Fall, den kein
 * HTTP-Aufruf allein erzeugen kann.
 */
function createProofedSession(int $activityId): int
{
    global $pdo, $prefix;

    $create = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => [
            'activity_id' => $activityId,
            'start_time'  => '2026-09-01 10:00:00',
            'end_time'    => '2026-09-01 11:00:00',
            'note'        => 'verify_proof_drop',
        ],
    ]);
    assertStatus(201, $create, 'Sitzung konnte nicht angelegt werden');
    $sessionId = (int) $create['body']['session']['session_id'];

    $pdo->prepare("UPDATE {$prefix}work_sessions
                   SET start_location_name = 'Vereinsheim', end_location_name = 'Vereinsheim'
                   WHERE session_id = ?")
        ->execute([$sessionId]);

    return $sessionId;
}

/** Entfernt eine Sitzung wieder — wird auch nach einer fehlgeschlagenen Pruefung aufgerufen. */
function deleteSession(int $sessionId): void
{
    apiRequest('DELETE', 'work_sessions', [
        'token' => apiToken('admin'),
        'query' => ['id' => $sessionId],
    ]);
}

/**
 * Legt eine ortsbelegte Sitzung an, schickt eine Korrektur und prueft, welche
 * Ortsnachweise danach noch stehen. Raeumt in jedem Fall auf.
 *
 * @param array<string, mixed> $correction
 */
function assertProofDrop(
    string $name,
    int $activityId,
    array $correction,
    ?string $expectedStart,
    ?string $expectedEnd
): void {
    test($name, function () use ($activityId, $correction, $expectedStart, $expectedEnd) {
        $sessionId = createProofedSession($activityId);

        try {
            $put = apiRequest('PUT', 'work_sessions', [
                'token' => apiToken('user'),
                'query' => ['id' => $sessionId],
                'body'  => $correction,
            ]);
            assertStatus(200, $put, 'Korrektur wurde abgewiesen');

            $get = apiRequest('GET', 'work_sessions', [
                'token' => apiToken('user'),
                'query' => ['id' => $sessionId],
            ]);
            assertStatus(200, $get, 'Sitzung liess sich nicht zuruecklesen');

            assertSame($expectedStart, $get['body']['start_location_name'], 'start_location_name');
            assertSame($expectedEnd, $get['body']['end_location_name'], 'end_location_name');
        } finally {
            deleteSession($sessionId);
        }
    });
}

// ============================================================================
// Beginn verschoben: der Startnachweis galt fuer 10:00, nicht mehr fuer 06:00.
assertProofDrop(
    'Beginn verschoben verliert den Startnachweis, das Ende behaelt seinen',
    $activityId,
    ['start_time' => '2026-09-01 06:00:00'],
    null,
    'Vereinsheim'
);

// Ende verschoben: spiegelbildlich.
assertProofDrop(
    'Ende verschoben verliert den Endnachweis, der Start behaelt seinen',
    $activityId,
    ['end_time' => '2026-09-01 13:00:00'],
    'Vereinsheim',
    null
);

// Nur die Notiz geaendert: beide Zeitpunkte bleiben, also bleiben beide Nachweise.
assertProofDrop(
    'Eine reine Notizaenderung laesst beide Nachweise unangetastet',
    $activityId,
    ['note' => 'nur Notiz'],
    'Vereinsheim',
    'Vereinsheim'
);

// Derselbe Zeitpunkt, andere Schreibweise: worktimeSameInstant() vergleicht
// per strtotime, nicht als String -- die Nachweise duerfen nicht wegfallen.
assertProofDrop(
    'Derselbe Zeitpunkt in anderer Schreibweise laesst beide Nachweise stehen',
    $activityId,
    ['start_time' => '2026-09-01 10:00'],
    'Vereinsheim',
    'Vereinsheim'
);

exit(harnessSummary());
