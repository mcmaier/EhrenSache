<?php
/**
 * EhrenSache - Verifikation des Ortsnachweises am Timer
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 *
 * Nicht Teil von tests/run.php: Gültige TOTP-Codes lassen sich nur mit dem
 * Secret der Station erzeugen, und das steht in der Datenbank.
 *
 * Aufruf:
 *   php tests/db/verify_totp_location.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
 */
declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Aufruf: php tests/db/verify_totp_location.php <dsn-mit-dbname> <user> <password> <prefix>\n");
    exit(2);
}

[$_, $dsn, $dbUser, $dbPass, $prefix] = $argv;

$repo = dirname(__DIR__, 2);
require_once $repo . '/private/helpers/totp.php';
require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api.php';

$pdo = new PDO($dsn . ';charset=utf8mb4', $dbUser, $dbPass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/** Minimaler Ersatz für die Database-Klasse aus config.php. */
final class TestDatabase
{
    public function __construct(private string $prefix) {}

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }
}
$database = new TestDatabase($prefix);

function setSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

/** Legt eine Tätigkeitsart mit gegebener Nachweispflicht an. */
function createActivity(string $name, string $verification): int
{
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => $name, 'verification' => $verification],
    ]);
    assertStatus(201, $res, "Taetigkeitsart '{$name}' konnte nicht angelegt werden");

    return (int) $res['body']['id'];
}

function stopRunning(): void
{
    $res = apiRequest('GET', 'work_sessions', ['token' => apiToken('user'), 'query' => ['running' => 1]]);
    if (is_array($res['body'] ?? null)) {
        apiRequest('POST', 'work_sessions', [
            'token' => apiToken('user'),
            'body'  => ['action' => 'stop', 'force' => true],
        ]);
    }
}

// ============================================================================
setSetting('worktime_enabled', '1');

// Station ermitteln
$station = $pdo->query("SELECT user_id, device_name, email, totp_secret
                        FROM {$prefix}users
                        WHERE role = 'device' AND device_type = 'totp_location'
                          AND is_active = 1 AND totp_secret IS NOT NULL
                        LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$station) {
    fwrite(STDERR, "Keine aktive TOTP-Station in der Datenbank — Test nicht durchfuehrbar.\n");
    fwrite(STDERR, "Im Dashboard unter Geraete eine Station mit TOTP-Secret anlegen.\n");
    exit(3);
}

$expectedName = $station['device_name'] ?: $station['email'];
$totp         = new TOTP($station['totp_secret']);

echo "Station: {$expectedName} (user_id {$station['user_id']})\n\n";

$createdActivities = [];
$createdSessions   = [];

// ---- resolveTotpLocation direkt ------------------------------------------
test('resolveTotpLocation findet die Station zu einem gueltigen Code', function () use ($pdo, $database, $totp, $expectedName) {
    $result = resolveTotpLocation($pdo, $database, $totp->getCode());

    assertTrue($result !== null, 'Gueltiger Code wurde nicht aufgeloest');
    assertSame($expectedName, $result['location_name']);
});

test('resolveTotpLocation liefert den Geraetenamen, nicht das leere email-Feld', function () use ($station, $expectedName) {
    // Die Check-Constraint erzwingt email IS NULL fuer Geraetekonten. Der
    // fruehere Code schrieb genau dieses Feld nach records.location_name und
    // damit immer NULL.
    assertSame(null, $station['email']);
    assertTrue($expectedName !== null && $expectedName !== '', 'Stationsname ist leer');
});

test('resolveTotpLocation weist einen falschen Code ab', function () use ($pdo, $database, $totp) {
    $wrong = str_pad((string) ((((int) $totp->getCode()) + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    assertSame(null, resolveTotpLocation($pdo, $database, $wrong));
});

test('resolveTotpLocation weist ein falsches Format ab', function () use ($pdo, $database) {
    assertSame(null, resolveTotpLocation($pdo, $database, 'abcdef'));
    assertSame(null, resolveTotpLocation($pdo, $database, '12345'));
    assertSame(null, resolveTotpLocation($pdo, $database, ''));
});

test('resolveTotpLocation akzeptiert ein abgelaufenes Zeitfenster nicht', function () use ($pdo, $database, $totp) {
    // Fuenf Zeitfenster zurueck liegt weit ausserhalb der Toleranz von einem
    $old = $totp->getCode(time() - 5 * 30);
    assertSame(null, resolveTotpLocation($pdo, $database, $old));
});

// ---- verification = 'none' -------------------------------------------------
test('Ohne Nachweispflicht wird ein mitgesendeter Code trotzdem festgehalten', function () use ($totp, $expectedName, &$createdActivities, &$createdSessions) {
    stopRunning();
    $activityId = createActivity('Frei mit Code ' . uniqid(), 'none');
    $createdActivities[] = $activityId;

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId, 'totp_code' => $totp->getCode()],
    ]);
    assertStatus(201, $res);
    $createdSessions[] = (int) $res['body']['session']['session_id'];

    assertSame($expectedName, $res['body']['session']['start_location_name']);
    stopRunning();
});

// ---- verification = 'start' ------------------------------------------------
test('Nachweispflicht start: Start ohne Code wird abgewiesen', function () use (&$createdActivities) {
    stopRunning();
    $activityId = createActivity('Start-Pflicht ' . uniqid(), 'start');
    $createdActivities[] = $activityId;

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId],
    ]);
    assertStatus(403, $res);
});

test('Nachweispflicht start: Start mit ungueltigem Code wird abgewiesen', function () use (&$createdActivities) {
    stopRunning();
    $activityId = end($createdActivities);

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId, 'totp_code' => '000000'],
    ]);
    assertStatus(401, $res);
});

test('Nachweispflicht start: Start mit gueltigem Code belegt den Ort', function () use ($totp, $expectedName, &$createdActivities, &$createdSessions) {
    stopRunning();
    $activityId = end($createdActivities);

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId, 'totp_code' => $totp->getCode()],
    ]);
    assertStatus(201, $res);
    $createdSessions[] = (int) $res['body']['session']['session_id'];

    assertSame($expectedName, $res['body']['session']['start_location_name']);
    assertSame('confirmed', $res['body']['session']['status']);
});

test('Nachweispflicht start: Stoppen braucht keinen Code', function () {
    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'stop'],
    ]);
    assertStatus(200, $res);
    assertSame(null, $res['body']['session']['end_location_name']);
    assertSame('confirmed', $res['body']['session']['status']);
});

// ---- verification = 'start_end' --------------------------------------------
test('Nachweispflicht start_end: Stopp ohne Code wird abgewiesen', function () use ($totp, &$createdActivities, &$createdSessions) {
    stopRunning();
    $activityId = createActivity('Start-Ende-Pflicht ' . uniqid(), 'start_end');
    $createdActivities[] = $activityId;

    $start = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId, 'totp_code' => $totp->getCode()],
    ]);
    assertStatus(201, $start);
    $createdSessions[] = (int) $start['body']['session']['session_id'];

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'stop'],
    ]);
    assertStatus(409, $res);
});

test('Nachweispflicht start_end: force beendet ohne Nachweis und entzieht die Bestaetigung', function () {
    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'stop', 'force' => true],
    ]);
    assertStatus(200, $res);
    assertSame(null, $res['body']['session']['end_location_name']);
    assertSame('submitted', $res['body']['session']['status'],
        'Ohne Nachweis beendet muss zur Freigabe zurueckfallen');
});

test('Nachweispflicht start_end: Stopp mit gueltigem Code belegt Start und Ende', function () use ($totp, $expectedName, &$createdActivities, &$createdSessions) {
    stopRunning();
    $activityId = end($createdActivities);

    $start = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId, 'totp_code' => $totp->getCode()],
    ]);
    assertStatus(201, $start);
    $createdSessions[] = (int) $start['body']['session']['session_id'];

    $res = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'stop', 'totp_code' => $totp->getCode()],
    ]);
    assertStatus(200, $res);
    assertSame($expectedName, $res['body']['session']['start_location_name']);
    assertSame($expectedName, $res['body']['session']['end_location_name']);
    assertSame('confirmed', $res['body']['session']['status'],
        'Vollstaendig belegt darf die Bestaetigung behalten');
});

// ---- Pause verlangt nie einen Code (E12) -----------------------------------
test('Pause und Weiter verlangen auch bei start_end keinen Code', function () use ($totp, &$createdActivities, &$createdSessions) {
    stopRunning();
    $activityId = end($createdActivities);

    $start = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId, 'totp_code' => $totp->getCode()],
    ]);
    assertStatus(201, $start);
    $createdSessions[] = (int) $start['body']['session']['session_id'];

    assertStatus(200, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'pause'],
    ]));
    assertStatus(200, apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'), 'body' => ['action' => 'resume'],
    ]));

    stopRunning();
});

// ---- Terminbezug bei Ortsnachweis ------------------------------------------
test('Ortsbelegter Start erzeugt einen user_totp-Check-in mit Stationsnamen', function () use ($pdo, $prefix, $totp, $expectedName, &$createdActivities, &$createdSessions) {
    stopRunning();
    $activityId = end($createdActivities);
    $memberId   = apiMemberId('user');

    // Termin des heutigen Tages mit eigener Terminart, damit die
    // Konfliktpruefung nicht mit echten Terminen kollidiert.
    $typeRes = apiRequest('POST', 'appointment_types', [
        'token' => apiToken('admin'),
        'body'  => ['type_name' => 'Ortsnachweistest ' . uniqid()],
    ]);
    assertStatus(201, $typeRes);
    $typeId = (int) $typeRes['body']['id'];

    $aptRes = apiRequest('POST', 'appointments', [
        'token' => apiToken('admin'),
        'body'  => ['title' => 'Ortsnachweis ' . uniqid(), 'date' => date('Y-m-d'),
                    'start_time' => '06:00:00', 'type_id' => $typeId],
    ]);
    assertStatus(201, $aptRes);
    $appointmentId = (int) ($aptRes['body']['id'] ?? $aptRes['body']['appointment_id']);

    $start = apiRequest('POST', 'work_sessions', [
        'token' => apiToken('user'),
        'body'  => ['action' => 'start', 'activity_id' => $activityId,
                    'appointment_id' => $appointmentId, 'totp_code' => $totp->getCode()],
    ]);
    assertStatus(201, $start);
    $createdSessions[] = (int) $start['body']['session']['session_id'];

    $stmt = $pdo->prepare("SELECT checkin_source, location_name FROM {$prefix}records
                           WHERE appointment_id = ? AND member_id = ?");
    $stmt->execute([$appointmentId, $memberId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    assertTrue($record !== false, 'Kein records-Eintrag entstanden');
    assertSame('user_totp', $record['checkin_source'],
        'Ein ortsbelegter Start ist ein TOTP-Check-in, kein blosser timer-Eintrag');
    assertSame($expectedName, $record['location_name']);

    stopRunning();
    apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $appointmentId]]);
    apiRequest('DELETE', 'appointment_types', ['token' => apiToken('admin'), 'query' => ['id' => $typeId]]);
});

// --- Aufraeumen --------------------------------------------------------------
// Bewusst ueber die Taetigkeitsart statt ueber gemerkte Session-Ids: Entsteht
// eine Sitzung in einem Test, der danach scheitert, wird ihre Id nie notiert —
// der Harness faengt die Exception, die Zeile danach laeuft nicht mehr. Die
// Taetigkeitsart ist dagegen vor dem riskanten Aufruf registriert.
stopRunning();

foreach (array_unique($createdActivities) as $activityId) {
    $sessions = apiRequest('GET', 'work_sessions', [
        'token' => apiToken('admin'),
        'query' => ['activity_id' => $activityId],
    ]);
    foreach (($sessions['body'] ?? []) as $session) {
        apiRequest('DELETE', 'work_sessions', [
            'token' => apiToken('admin'),
            'query' => ['id' => (int) $session['session_id']],
        ]);
    }

    // ON DELETE RESTRICT: die Art geht erst, wenn keine Sitzung mehr haengt
    $res = apiRequest('DELETE', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $activityId],
    ]);
    if ($res['status'] !== 200) {
        fwrite(STDERR, "Warnung: activity_type {$activityId} blieb zurueck (HTTP {$res['status']})\n");
    }
}

exit(harnessSummary());
