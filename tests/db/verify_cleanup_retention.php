<?php
/**
 * EhrenSache - Verifikation der Löschfristen der DSGVO-Bereinigung
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Nicht Teil von tests/run.php: Beide geprüften Fälle brauchen ein
 * zurückdatiertes start_time bzw. changed_at, und eine verwaiste Logzeile
 * entsteht über die API überhaupt nur beim Löschen einer Sitzung — also nie
 * mit einem alten changed_at.
 *
 * Geprüft wird:
 *   - eine seit Jahren laufende Sitzung fällt NICHT in die Frist
 *   - eine verwaiste Logzeile jenseits der Auditfrist wird anonymisiert,
 *     nicht gelöscht
 *   - eine verwaiste Logzeile innerhalb der Frist bleibt unangetastet
 *   - die Historie einer jungen Sitzung bleibt unangetastet
 *
 * Aufruf:
 *   php tests/db/verify_cleanup_retention.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
 *
 * Die Zugänge der Anwendung kommen aus tests/config.php.
 */
declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Aufruf: php tests/db/verify_cleanup_retention.php <dsn-mit-dbname> <user> <password> <prefix>\n");
    exit(2);
}

[$_, $dsn, $dbUser, $dbPass, $prefix] = $argv;

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api.php';

$pdo = new PDO($dsn . ';charset=utf8mb4', $dbUser, $dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Fristen, die ausschliesslich die hier angelegten Testdaten erfassen.
 * 100 Jahre für Anwesenheiten und Ausnahmen heisst: der Bestand bleibt
 * unberührt. Niemals kleiner setzen — der Endpunkt kennt kein Undo.
 */
const VCR_YEARS = ['years' => 100, 'years_worktime' => 30, 'years_audit' => 30];

/** Beendet eine laufende Sitzung des Testnutzers, damit der Unique-Index frei ist. */
function vcrStopRunning(): void
{
    $res = apiRequest('GET', 'work_sessions', [
        'token' => apiToken('user'),
        'query' => ['running' => 1],
    ]);
    if (is_array($res['body'] ?? null)) {
        apiRequest('POST', 'work_sessions', [
            'token' => apiToken('user'),
            'body'  => ['action' => 'stop'],
        ]);
    }
}

$memberId = (int) apiMemberId('user');
if ($memberId <= 0) {
    fwrite(STDERR, "Der Testnutzer 'user' ist mit keinem Mitglied verknüpft\n");
    exit(2);
}

$activityId = (int) $pdo->query("SELECT activity_id FROM {$prefix}activity_types LIMIT 1")->fetchColumn();
if ($activityId <= 0) {
    fwrite(STDERR, "Keine Tätigkeitsart vorhanden — Zeiterfassung erst einrichten\n");
    exit(2);
}

vcrStopRunning();

// --- Testdaten anlegen -------------------------------------------------------

$insertSession = $pdo->prepare("
    INSERT INTO {$prefix}work_sessions
        (member_id, activity_id, start_time, end_time, break_minutes, note, status, source)
    VALUES (?, ?, ?, ?, 0, 'Fristen-Test', 'confirmed', 'admin')
");

// Läuft seit 1986 und ist damit weit jenseits der Arbeitszeitfrist.
$insertSession->execute([$memberId, $activityId, '1986-05-04 09:00:00', null]);
$runningOldId = (int) $pdo->lastInsertId();

$insertSession->execute([
    $memberId,
    $activityId,
    date('Y-m-d H:i:s', strtotime('-3 hours')),
    date('Y-m-d H:i:s', strtotime('-1 hours')),
]);
$youngId = (int) $pdo->lastInsertId();

$insertLog = $pdo->prepare("
    INSERT INTO {$prefix}work_session_log (session_id, changed_by, changed_at, action, changes)
    VALUES (?, ?, ?, ?, ?)
");

// Verwaist: diese session_id gibt es in work_sessions nicht (mehr).
$orphanId = (int) $pdo->query("SELECT COALESCE(MAX(session_id), 0) + 100000 FROM {$prefix}work_sessions")->fetchColumn();
$payload  = json_encode(['deleted' => ['old' => ['member_id' => $memberId, 'note' => 'geheim'], 'new' => null]]);

$insertLog->execute([$orphanId, null, '1986-05-04 11:00:00', 'delete', $payload]);
$orphanOldLogId = (int) $pdo->lastInsertId();

$insertLog->execute([$orphanId + 1, null, date('Y-m-d H:i:s'), 'delete', $payload]);
$orphanYoungLogId = (int) $pdo->lastInsertId();

$insertLog->execute([$youngId, null, date('Y-m-d H:i:s'), 'create', $payload]);
$youngLogId = (int) $pdo->lastInsertId();

// --- Bereinigung anstossen ---------------------------------------------------

$res = apiRequest('POST', 'cleanup', ['token' => apiToken('admin'), 'body' => VCR_YEARS]);

test('cleanup antwortet mit 200', function () use ($res) {
    assertStatus(200, $res);
});

/** @return array<string, mixed>|null */
function vcrLogRow(PDO $pdo, string $prefix, int $logId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM {$prefix}work_session_log WHERE log_id = ?");
    $stmt->execute([$logId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

test('eine seit Jahren laufende Sitzung faellt nicht in die Frist', function () use ($pdo, $prefix, $runningOldId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}work_sessions WHERE session_id = ?");
    $stmt->execute([$runningOldId]);
    assertSame(1, (int) $stmt->fetchColumn(),
        'Eine offene Sitzung ist ein Fehlerfall, kein Loeschfall — sie darf nicht stillschweigend verschwinden');
});

test('verwaiste Logzeile jenseits der Frist wird anonymisiert, nicht geloescht',
    function () use ($pdo, $prefix, $orphanOldLogId) {
        $row = vcrLogRow($pdo, $prefix, $orphanOldLogId);
        assertTrue($row !== null, 'Die Zeile wurde geloescht statt anonymisiert');
        assertSame(null, $row['changes'],     'changes haelt die komplette Sitzung samt member_id');
        assertSame(null, $row['changed_by'],  'changed_by benennt den handelnden Nutzer');
        assertSame('delete', $row['action'],  'Die Spur soll weiterhin belegen, dass geloescht wurde');
    });

test('verwaiste Logzeile innerhalb der Frist bleibt unangetastet',
    function () use ($pdo, $prefix, $orphanYoungLogId) {
        $row = vcrLogRow($pdo, $prefix, $orphanYoungLogId);
        assertTrue($row !== null, 'Die Zeile wurde geloescht');
        assertTrue($row['changes'] !== null, 'Innerhalb der Auditfrist bleibt der Inhalt erhalten');
    });

test('die Historie einer jungen Sitzung bleibt unangetastet',
    function () use ($pdo, $prefix, $youngLogId) {
        $row = vcrLogRow($pdo, $prefix, $youngLogId);
        assertTrue($row !== null, 'Die Zeile wurde geloescht');
        assertTrue($row['changes'] !== null, 'Die Historie folgt der Frist ihrer Sitzung');
    });

test('die Antwort zaehlt genau eine anonymisierte Zeile', function () use ($res) {
    assertSame(1, (int) ($res['body']['anonymized_work_session_log'] ?? -1));
});

// --- Testdaten wieder entfernen ----------------------------------------------
//
// Die laufende Sitzung MUSS weg: sie belegt den Unique-Index auf active_member
// und der Testnutzer koennte sonst keinen Timer mehr starten.

$pdo->prepare("DELETE FROM {$prefix}work_session_log WHERE log_id IN (?, ?, ?)")
    ->execute([$orphanOldLogId, $orphanYoungLogId, $youngLogId]);
$pdo->prepare("DELETE FROM {$prefix}work_sessions WHERE session_id IN (?, ?)")
    ->execute([$runningOldId, $youngId]);

exit(harnessSummary());
