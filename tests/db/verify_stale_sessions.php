<?php
/**
 * EhrenSache - Verifikation des automatischen Abschlusses überfälliger Sitzungen
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Nicht Teil von tests/run.php, weil eine Sitzung nur dann überfällig wird,
 * wenn ihr Beginn weit genug zurückliegt — und dafür muss start_time direkt
 * in der Datenbank verschoben werden.
 *
 * Bewusst so und nicht über einen Endpunkt: Eine Testhintertür im Handler
 * wäre Produktionscode, der nur für Tests existiert. Der Zugriff auf die
 * Datenbank gehört in einen Test, nicht in die Anwendung.
 *
 * Aufruf:
 *   php tests/db/verify_stale_sessions.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
 *
 * Die Zugänge der Anwendung kommen aus tests/config.php.
 */
declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Aufruf: php tests/db/verify_stale_sessions.php <dsn-mit-dbname> <user> <password> <prefix>\n");
    fwrite(STDERR, "Beispiel: php tests/db/verify_stale_sessions.php \"mysql:host=127.0.0.1;port=3306;dbname=ehrensache\" root \"\" ez_\n");
    exit(2);
}

[$_, $dsn, $dbUser, $dbPass, $prefix] = $argv;

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api.php';

$pdo = new PDO($dsn . ';charset=utf8mb4', $dbUser, $dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** Setzt eine Systemeinstellung über die API. */
function setSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

/** Verschiebt den Beginn einer laufenden Sitzung in die Vergangenheit. */
function backdate(PDO $pdo, string $prefix, int $sessionId, int $hours): void
{
    $pdo->prepare("UPDATE {$prefix}work_sessions
                   SET start_time = DATE_SUB(NOW(), INTERVAL ? HOUR)
                   WHERE session_id = ?")
        ->execute([$hours, $sessionId]);
}

function sessionRow(PDO $pdo, string $prefix, int $sessionId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM {$prefix}work_sessions WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

// ============================================================================
setSetting('worktime_enabled', '1');
setSetting('worktime_max_session_hours', '12');

$activityName = 'Stale-Test ' . uniqid();
$res = apiRequest('POST', 'activity_types', [
    'token' => apiToken('admin'),
    'body'  => ['activity_name' => $activityName],
]);
assertStatus(201, $res, 'Taetigkeitsart konnte nicht angelegt werden');
$activityId = (int) $res['body']['id'];

/** Beendet eine ggf. laufende Sitzung des Test-Users. */
$stopRunning = static function (): void {
    $res = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['running' => 1]]);
    if (is_array($res['body'] ?? null)) {
        apiRequest('POST', 'work_sessions', ['token' => apiToken('user'), 'body' => ['action' => 'stop']]);
    }
};

$startSession = static function () use ($activityId): int {
    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId],
    ]);
    assertStatus(201, $res, 'Sitzung konnte nicht gestartet werden');

    return (int) $res['body']['session']['session_id'];
};

$createdSessions = [];

test('Eine Sitzung innerhalb der Obergrenze bleibt unangetastet', function () use ($pdo, $prefix, $stopRunning, $startSession, &$createdSessions) {
    $stopRunning();
    $id = $startSession();
    $createdSessions[] = $id;

    backdate($pdo, $prefix, $id, 5);   // 5 von 12 Stunden

    $res = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['running' => 1]]);
    assertStatus(200, $res);
    assertTrue(is_array($res['body']), 'Die Sitzung haette weiterlaufen muessen');
    assertSame($id, (int) $res['body']['session_id']);

    $stopRunning();
});

test('Eine ueberfaellige Sitzung wird beim naechsten Zugriff geschlossen', function () use ($pdo, $prefix, $stopRunning, $startSession, &$createdSessions) {
    $stopRunning();
    $id = $startSession();
    $createdSessions[] = $id;

    backdate($pdo, $prefix, $id, 20);  // 20 von 12 Stunden

    $res = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['running' => 1]]);
    assertStatus(200, $res);
    assertSame(null, $res['body'], 'Die Sitzung haette geschlossen werden muessen');

    $row = sessionRow($pdo, $prefix, $id);
    assertTrue($row !== null && $row['end_time'] !== null, 'end_time fehlt');
    assertSame('submitted', $row['status'], 'Sie muss zur Freigabe vorgelegt werden, nicht zaehlen');
});

test('Die gekappte Dauer entspricht genau der Obergrenze', function () use ($pdo, $prefix, &$createdSessions) {
    $id  = end($createdSessions);
    $row = sessionRow($pdo, $prefix, (int) $id);

    $minutes = (strtotime($row['end_time']) - strtotime($row['start_time'])) / 60;
    assertSame(720.0, (float) $minutes, 'Erwartet: 12 Stunden = 720 Minuten');
});

test('Der automatische Abschluss steht in der Auditspur', function () use ($pdo, $prefix, &$createdSessions) {
    $id   = (int) end($createdSessions);
    $stmt = $pdo->prepare("SELECT changes FROM {$prefix}work_session_log
                           WHERE session_id = ? AND action = 'update'
                           ORDER BY log_id DESC LIMIT 1");
    $stmt->execute([$id]);
    $changes = json_decode((string) $stmt->fetchColumn(), true);

    assertTrue(isset($changes['auto_closed']), 'Vermerk auto_closed fehlt');
    assertSame('submitted', $changes['status']['new'] ?? null);
});

test('Nach dem automatischen Abschluss ist ein neuer Start moeglich', function () use ($startSession, $stopRunning, &$createdSessions) {
    $id = $startSession();
    $createdSessions[] = $id;
    $stopRunning();
});

test('Eine ueberfaellige Sitzung blockiert den Start nicht', function () use ($pdo, $prefix, $stopRunning, $startSession, &$createdSessions) {
    $stopRunning();
    $stale = $startSession();
    $createdSessions[] = $stale;
    backdate($pdo, $prefix, $stale, 30);

    // Direkt starten, ohne vorher zu lesen: der Start muss die alte Sitzung
    // selbst schliessen, sonst greift der Unique-Index und liefert 409.
    $id = $startSession();
    $createdSessions[] = $id;

    $row = sessionRow($pdo, $prefix, $stale);
    assertTrue($row['end_time'] !== null, 'Die ueberfaellige Sitzung wurde nicht geschlossen');

    $stopRunning();
});

// --- Aufraeumen --------------------------------------------------------------
$stopRunning();
foreach (array_unique($createdSessions) as $sessionId) {
    apiRequest('DELETE', 'work_sessions', ['token' => apiToken('admin'), 'query' => ['id' => $sessionId]]);
}
apiRequest('DELETE', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $activityId]]);

exit(harnessSummary());
