<?php
/**
 * EhrenSache - Verifikation von Statistik und Export der Zeiterfassung
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Nicht Teil von tests/run.php: Um Sitzungen mit Ortsnachweis herzustellen,
 * braucht es gültige TOTP-Codes und damit das Secret der Station.
 *
 * Aufruf:
 *   php tests/db/verify_worktime_reporting.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
 */
declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Aufruf: php tests/db/verify_worktime_reporting.php <dsn-mit-dbname> <user> <password> <prefix>\n");
    exit(2);
}

[$_, $dsn, $dbUser, $dbPass, $prefix] = $argv;

$repo = dirname(__DIR__, 2);
require_once $repo . '/private/helpers/totp.php';
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

setSetting('worktime_enabled', '1');

$station = $pdo->query("SELECT device_name, email, totp_secret FROM {$prefix}users
                        WHERE role = 'device' AND device_type = 'totp_location'
                          AND is_active = 1 AND totp_secret IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$station) {
    fwrite(STDERR, "Keine aktive TOTP-Station — Test nicht durchfuehrbar.\n");
    exit(3);
}

$stationName = $station['device_name'] ?: $station['email'];
$totp        = new TOTP($station['totp_secret']);
$year        = (int) date('Y');
$memberId    = apiMemberId('user');

// --- Testdaten aufbauen -----------------------------------------------------
$activityA = null;
$activityB = null;
$sessionIds = [];

function createActivity(string $name): int
{
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => $name],
    ]);
    assertStatus(201, $res, "Taetigkeitsart '{$name}' konnte nicht angelegt werden");

    return (int) $res['body']['id'];
}

function stopRunning(): void
{
    $res = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['running' => 1]]);
    if (is_array($res['body'] ?? null)) {
        apiRequest('POST', 'work_sessions', [
            'token' => apiToken('user'), 'body' => ['action' => 'stop', 'force' => true],
        ]);
    }
}

/**
 * Legt eine beendete, bestätigte Sitzung mit gewünschtem Nachweisgrad an.
 * Die Zeiten werden anschließend in der Datenbank gesetzt, weil der Timer
 * bewusst nur NOW() schreibt.
 */
function makeSession(PDO $pdo, string $prefix, TOTP $totp, int $activityId,
                     string $proof, int $minutes, int $breakMinutes = 0): int
{
    stopRunning();

    $body = ['action' => 'start', 'activity_id' => $activityId];
    if ($proof !== 'none') {
        $body['totp_code'] = $totp->getCode();
    }

    $start = apiRequest('POST', 'work_sessions', ['token' => apiToken('user'), 'body' => $body]);
    assertStatus(201, $start, "Start fuer Nachweisgrad '{$proof}' fehlgeschlagen");
    $id = (int) $start['body']['session']['session_id'];

    $stopBody = ['action' => 'stop'];
    if ($proof === 'hours') {
        $stopBody['totp_code'] = $totp->getCode();
    }
    assertStatus(200, apiRequest('POST', 'work_sessions',
        ['token' => apiToken('user'), 'body' => $stopBody]));

    // Dauer und Pause setzen; Status auf confirmed, damit die Sitzung zaehlt
    $pdo->prepare("UPDATE {$prefix}work_sessions
                   SET start_time = DATE_SUB(NOW(), INTERVAL ? MINUTE),
                       end_time   = NOW(),
                       break_minutes = ?,
                       status = 'confirmed'
                   WHERE session_id = ?")
        ->execute([$minutes + $breakMinutes, $breakMinutes, $id]);

    return $id;
}

/**
 * Kennzahlen des Mitglieds fuer ein Jahr.
 *
 * Der Test misst Differenzen statt absoluter Summen: In einer benutzten
 * Datenbank liegen bereits Sitzungen, und ein Test, der eine leere Tabelle
 * voraussetzt, schlaegt aus dem falschen Grund fehl.
 *
 * @return array{minutes: int, sessions: int, proof: array<string, int>}
 */
function memberTotals(int $year, int $memberId): array
{
    $res = apiRequest('GET', 'statistics', [
        'token' => apiToken('admin'),
        'query' => ['year' => $year, 'include' => 'worktime'],
    ]);
    assertStatus(200, $res);

    foreach (($res['body']['worktime']['members'] ?? []) as $m) {
        if ((int) $m['member_id'] === $memberId) {
            return [
                'minutes'  => (int) $m['worked_minutes'],
                'sessions' => (int) $m['sessions'],
                'proof'    => [
                    'hours' => (int) $m['by_proof']['hours'],
                    'start' => (int) $m['by_proof']['start'],
                    'none'  => (int) $m['by_proof']['none'],
                ],
            ];
        }
    }

    return ['minutes' => 0, 'sessions' => 0, 'proof' => ['hours' => 0, 'start' => 0, 'none' => 0]];
}

$before = memberTotals($year, $memberId);

$activityA = createActivity('Auswertung A ' . uniqid());
$activityB = createActivity('Auswertung B ' . uniqid());

// A: 60 Min stundenbelegt, 30 Min teilbelegt   -> 90
// B: 45 Min unbelegt (mit 15 Min Pause)        -> 45
$sessionIds[] = makeSession($pdo, $prefix, $totp, $activityA, 'hours', 60);
$sessionIds[] = makeSession($pdo, $prefix, $totp, $activityA, 'start', 30);
$sessionIds[] = makeSession($pdo, $prefix, $totp, $activityB, 'none',  45, 15);

// Eine nicht bestaetigte Sitzung, die NICHT zaehlen darf
$pending = makeSession($pdo, $prefix, $totp, $activityB, 'none', 999);
$sessionIds[] = $pending;
$pdo->prepare("UPDATE {$prefix}work_sessions SET status = 'submitted' WHERE session_id = ?")
    ->execute([$pending]);

echo "Station: {$stationName}, Mitglied: {$memberId}, Jahr: {$year}\n\n";

// --- Statistik ---------------------------------------------------------------
test('statistics ohne include liefert keinen worktime-Block', function () use ($year) {
    $res = apiRequest('GET', 'statistics', [
        'token' => apiToken('admin'), 'query' => ['year' => $year],
    ]);
    assertStatus(200, $res);
    assertSame(null, $res['body']['worktime']);
});

test('statistics mit include=worktime summiert nur bestaetigte Sitzungen', function () use ($year, $memberId, $before) {
    $after = memberTotals($year, $memberId);

    // 60 + 30 + 45 = 135; die submitted-Sitzung mit 999 Min zaehlt nicht mit
    assertSame(135, $after['minutes'] - $before['minutes']);
    assertSame(3,   $after['sessions'] - $before['sessions']);
});

test('statistics weist die Stunden nach Nachweisgrad getrennt aus', function () use ($year, $memberId, $before) {
    $after = memberTotals($year, $memberId);

    assertSame(60, $after['proof']['hours'] - $before['proof']['hours'], 'stundenbelegt');
    assertSame(30, $after['proof']['start'] - $before['proof']['start'], 'teilbelegt');
    assertSame(45, $after['proof']['none']  - $before['proof']['none'],  'unbelegt');
});

test('statistics schluesselt nach Taetigkeitsart auf', function () use ($year, $memberId, $activityA, $activityB) {
    $res = apiRequest('GET', 'statistics', [
        'token' => apiToken('admin'),
        'query' => ['year' => $year, 'include' => 'worktime'],
    ]);

    $mine = null;
    foreach ($res['body']['worktime']['members'] as $m) {
        if ((int) $m['member_id'] === $memberId) { $mine = $m; }
    }

    $byId = [];
    foreach ($mine['by_activity'] as $a) { $byId[(int) $a['activity_id']] = (int) $a['minutes']; }

    assertSame(90, $byId[$activityA] ?? 0, 'Taetigkeit A');
    assertSame(45, $byId[$activityB] ?? 0, 'Taetigkeit B');
});

test('Ein Nutzer sieht im worktime-Block nur sich selbst', function () use ($year, $memberId) {
    $res = apiRequest('GET', 'statistics', [
        'token' => apiToken('user'),
        'query' => ['year' => $year, 'include' => 'worktime'],
    ]);
    assertStatus(200, $res);

    foreach ($res['body']['worktime']['members'] as $m) {
        assertSame($memberId, (int) $m['member_id'], 'Fremdes Mitglied im worktime-Block');
    }
});

test('Bei abgeschalteter Zeiterfassung bleibt der worktime-Block leer', function () use ($year) {
    try {
        setSetting('worktime_enabled', '0');
        $res = apiRequest('GET', 'statistics', [
            'token' => apiToken('admin'),
            'query' => ['year' => $year, 'include' => 'worktime'],
        ]);
        assertStatus(200, $res);
        assertSame(null, $res['body']['worktime']);
    } finally {
        setSetting('worktime_enabled', '1');
    }
});

// --- Export ------------------------------------------------------------------
test('Export worktime_member liefert CSV mit Nachweisgrad und Summen', function () use ($year, $memberId) {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('admin'),
        'query' => ['type' => 'worktime_member', 'year' => $year, 'member_id' => $memberId],
    ]);
    assertStatus(200, $res);

    $csv = $res['raw'];
    assertTrue(strpos($csv, 'member_number;activity') !== false
            || strpos($csv, 'member_name;member_surname') !== false, 'Kopfzeile fehlt');
    assertTrue(strpos($csv, 'stundenbelegt') !== false, 'Nachweisgrad stundenbelegt fehlt');
    assertTrue(strpos($csv, 'teilbelegt')    !== false, 'Nachweisgrad teilbelegt fehlt');
    assertTrue(strpos($csv, 'unbelegt')      !== false, 'Nachweisgrad unbelegt fehlt');
    assertTrue(strpos($csv, 'SUMMEN')        !== false, 'Summenblock fehlt');

    // Die drei angelegten Sitzungen als Einzelzeilen. Auf die Jahressumme zu
    // pruefen waere falsch: sie enthaelt auch aeltere Sitzungen des Mitglieds.
    assertTrue(strpos($csv, ';60;1,00;')  !== false, 'Zeile ueber 60 Minuten fehlt');
    assertTrue(strpos($csv, ';30;0,50;')  !== false, 'Zeile ueber 30 Minuten fehlt');
    assertTrue(strpos($csv, ';45;0,75;')  !== false, 'Zeile ueber 45 Minuten fehlt');
});

test('Export worktime_member enthaelt keine unbestaetigten Sitzungen', function () use ($year, $memberId) {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('admin'),
        'query' => ['type' => 'worktime_member', 'year' => $year, 'member_id' => $memberId],
    ]);
    assertTrue(strpos($res['raw'], '999') === false,
        'Die submitted-Sitzung darf nicht im Nachweis stehen');
});

test('Export worktime_activity summiert je Taetigkeitsart', function () use ($year) {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('admin'),
        'query' => ['type' => 'worktime_activity', 'year' => $year],
    ]);
    assertStatus(200, $res);

    $csv = $res['raw'];
    assertTrue(strpos($csv, 'Auswertung A') !== false, 'Taetigkeit A fehlt');
    assertTrue(strpos($csv, 'Auswertung B') !== false, 'Taetigkeit B fehlt');
    assertTrue(strpos($csv, 'GESAMT')       !== false, 'Gesamtzeile fehlt');
});

test('Export der Zeiterfassung ist Managern und Admins vorbehalten', function () use ($year) {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'worktime_member', 'year' => $year],
    ]);
    assertStatus(403, $res);
});

// --- Auskunft ----------------------------------------------------------------
test('my_data enthaelt die eigenen Sitzungen und deren Historie', function () {
    $res = apiRequest('GET', 'my_data', ['token' => apiToken('user')]);
    assertStatus(200, $res);

    assertTrue(isset($res['body']['work_sessions']), 'work_sessions fehlt in der Auskunft');
    assertTrue(count($res['body']['work_sessions']) > 0, 'Keine Sitzungen in der Auskunft');
    assertTrue(isset($res['body']['work_session_log']), 'work_session_log fehlt in der Auskunft');
    assertTrue(count($res['body']['work_session_log']) > 0, 'Keine Historie in der Auskunft');
});

// --- Aufraeumen --------------------------------------------------------------
stopRunning();
foreach ([$activityA, $activityB] as $activityId) {
    $sessions = apiRequest('GET', 'work_sessions', [
        'token' => apiToken('admin'), 'query' => ['activity_id' => $activityId],
    ]);
    foreach (($sessions['body'] ?? []) as $session) {
        apiRequest('DELETE', 'work_sessions',
            ['token' => apiToken('admin'), 'query' => ['id' => (int) $session['session_id']]]);
    }
    $res = apiRequest('DELETE', 'activity_types',
        ['token' => apiToken('admin'), 'query' => ['id' => $activityId]]);
    if ($res['status'] !== 200) {
        fwrite(STDERR, "Warnung: activity_type {$activityId} blieb zurueck (HTTP {$res['status']})\n");
    }
}

exit(harnessSummary());
