<?php
/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

/**
 * API.md-Schluessel fuer Schreibpfade und Check-in (OI-100).
 *
 * Gegenstueck zu api_doc_keys.php (Lesepfade): Jeder Schreibpfad, fuer den
 * API.md eine Erfolgsantwort als JSON zeigt, wird mit einer eigenen Testwelt
 * aufgerufen und seine Antwort gegen das Beispiel verglichen -- mit denselben
 * Regeln (tests/lib/api_doc.php): Die Doku darf nichts versprechen, was der
 * Server nicht liefert.
 *
 * Die Testwelt: Gruppe, Terminart mit Rueckmeldung, Mitglied mit PIN in der
 * Gruppe, Taetigkeitsart, ein Termin jetzt, ein Kiosk mit Stations-Code und
 * ein Hardware-Terminal (auth_device). Globale Schalter (station_pin_enabled,
 * worktime_enabled) werden gesetzt und am Ende auf den vorherigen Wert
 * zurueckgestellt. Der letzte Test raeumt alles ab und prueft das.
 *
 * Bewusst NICHT aufgerufen, weil der Aufruf ueber die Testwelt hinaus wirkt
 * -- die Suite laeuft auch gegen die gemeinsame Entwicklungsinstanz:
 * - register, password_reset_request: verschicken Mails, eigene Rate-Grenzen
 * - update_check POST: fragt api.github.com (Server-IP geht an GitHub)
 * - upload-logo: loescht die bisherige Logodatei
 * - regenerate_token ohne user_id, change_password: machen die Zugaenge der
 *   Testkonten ungueltig (regenerate_token wird an einem Wegwerf-Geraet geprueft)
 * - settings POST (SMTP): schreibt mail_config.php bzw. verschickt eine Mail
 *
 * Nicht pruefbar, weil API.md dort keine Erfolgsantwort als JSON zeigt: siehe
 * die Liste in OI-100 (docs/OPEN-ITEMS.md).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';
require_once __DIR__ . '/../lib/api_doc.php';

if (!extension_loaded('curl')) {
    return;
}

const AW_PIN = '2580';
// Serien weit in der Zukunft, damit sie mit nichts kollidieren (andere Suiten nutzen 2031)
const AW_SERIES_START = '2032-03-02';

/**
 * Vergleicht eine Antwort mit dem Beispiel unter $heading/$block.
 *
 * @param string[] $ignore Schluessel der obersten Ebene, die nur in einem
 *                         Zustand vorkommen, den der Test nicht herstellt
 *                         (Begruendung jeweils am Aufruf)
 * @param string[] $skipDive siehe adCheckKeys()
 */
function awCheck(array $res, int $status, string $heading, int $block, string $path,
                 array $ignore = [], array $skipDive = []): void
{
    assertStatus($status, $res, "{$path} fuer den Schluessel-Abgleich");
    $doc = adDoc($heading, $block);
    foreach ($ignore as $key) {
        assertTrue(array_key_exists($key, $doc), "ignore '{$key}' steht nicht in API.md -- Ausnahme streichen");
        unset($doc[$key]);
    }
    adCheckKeys($doc, $res['body'], $path, $skipDive);
}

/** Alles, was die Suite anlegt, fuer den Aufraeum-Test. */
function awTrack(string $kind, ?int $id = null): array
{
    static $created = [];
    if ($id !== null) {
        $created[$kind][] = $id;
    }
    return $created[$kind] ?? [];
}

function awAdmin(string $method, string $resource, array $opts = []): array
{
    return apiRequest($method, $resource, array_merge(['token' => apiToken('admin')], $opts));
}

function awSettingValue(string $key): ?string
{
    $res = awAdmin('GET', 'settings');
    foreach ($res['body']['settings'] ?? [] as $setting) {
        if ($setting['setting_key'] === $key) {
            return (string) $setting['setting_value'];
        }
    }
    return null;
}

/** Setzt einen Schalter und merkt sich den vorherigen Wert (einmal je Lauf). */
function awSetting(string $key, string $value): void
{
    $saved = awSavedSettings();
    if (!array_key_exists($key, $saved)) {
        awSavedSettings($key, awSettingValue($key));
    }
    $res = awAdmin('PUT', 'settings', ['body' => ['setting_key' => $key, 'setting_value' => $value]]);
    assertStatus(200, $res, "Einstellung '{$key}'");
}

function awSavedSettings(?string $key = null, ?string $value = null): array
{
    static $saved = [];
    if ($key !== null) {
        $saved[$key] = $value;
    }
    return $saved;
}

/** @return array{group:int,type:int,member:int,member_number:string,activity:int,appointment:int} */
function awWorld(): array
{
    static $world = null;
    if ($world !== null) {
        return $world;
    }
    $suffix = substr(uniqid(), -6);

    // Vor der Taetigkeitsart: Ist die Zeiterfassung aus, antwortet
    // activity_types mit 404 (so gefunden, als der Schalter vorher aus war).
    awSetting('worktime_enabled', '1');

    $group = awAdmin('POST', 'member_groups', ['body' => ['group_name' => "AW {$suffix}"]]);
    assertStatus(201, $group);
    $groupId = awTrack('member_groups', (int) $group['body']['id'])[0];

    $type = awAdmin('POST', 'appointment_types', ['body' => [
        'type_name' => "AW {$suffix}", 'is_default' => 0, 'color' => '#667eea',
        'group_ids' => [$groupId], 'responses_enabled' => 1]]);
    assertStatus(201, $type);
    $typeId = (int) $type['body']['id'];
    awTrack('appointment_types', $typeId);

    $number = 'AW' . $suffix;
    $member = awAdmin('POST', 'members', ['body' => [
        'name' => 'Aw', 'surname' => "Doku {$suffix}", 'member_number' => $number,
        'active' => 1, 'group_ids' => [$groupId]]]);
    assertStatus(201, $member);
    $memberId = (int) $member['body']['id'];
    awTrack('members', $memberId);
    // Die erste Antwort von POST members wird im eigenen Test geprueft;
    // hier genuegt das Anlegen.
    awMemberCreateResponse($member);

    $activity = awAdmin('POST', 'activity_types', ['body' => [
        'activity_name' => "AW {$suffix}", 'group_ids' => [$groupId]]]);
    assertStatus(201, $activity);
    $activityId = (int) $activity['body']['id'];
    awTrack('activity_types', $activityId);

    // Ein Termin jetzt, fuer die Check-in-Pfade
    $apt = awAdmin('POST', 'appointments', ['body' => [
        'title' => 'AW-Termin', 'type_id' => $typeId,
        'date' => date('Y-m-d'), 'start_time' => date('H:i:s')]]);
    assertStatus(201, $apt);
    $aptId = (int) $apt['body']['id'];
    awTrack('appointments', $aptId);
    awAppointmentCreateResponse($apt);

    return $world = ['group' => $groupId, 'type' => $typeId, 'member' => $memberId,
                     'member_number' => $number, 'activity' => $activityId, 'appointment' => $aptId];
}

function awMemberCreateResponse(?array $res = null): ?array
{
    static $saved = null;
    if ($res !== null) {
        $saved = $res;
    }
    return $saved;
}

function awAppointmentCreateResponse(?array $res = null): ?array
{
    static $saved = null;
    if ($res !== null) {
        $saved = $res;
    }
    return $saved;
}

/** Legt ein Geraet an; die Antwort wird fuer "Geraet anlegen" gemerkt. */
function awDevice(string $type): array
{
    static $devices = [];
    if (isset($devices[$type])) {
        return $devices[$type];
    }
    $body = ['action' => 'create_device', 'device_name' => "AW {$type} " . substr(uniqid(), -6),
             'device_type' => $type];
    if ($type === 'kiosk') {
        $body['totp_enabled'] = true;
    }
    $res = awAdmin('POST', 'users', ['body' => $body]);
    assertStatus(200, $res, "Geraet {$type}");
    awTrack('users', (int) $res['body']['device']['user_id']);

    return $devices[$type] = ['user_id' => (int) $res['body']['device']['user_id'],
                              'api_token' => (string) $res['body']['device']['api_token'],
                              'response' => $res];
}

function awStationReady(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    awSetting('station_pin_enabled', '1');
    awSetting('station_pin_min_length', '4');
    $res = awAdmin('PUT', 'members', ['query' => ['id' => awWorld()['member']], 'body' => ['pin' => AW_PIN]]);
    assertStatus(200, $res, 'PIN setzen');
    awPutMemberResponse($res);
    $done = true;
}

function awPutMemberResponse(?array $res = null): ?array
{
    static $saved = null;
    if ($res !== null) {
        $saved = $res;
    }
    return $saved;
}

// ---- Anmeldung ----------------------------------------------------------------

test('API.md-Schluessel (schreibend): Login (Web)', function () {
    $cfg = testConfig();
    $res = apiRequest('POST', 'login', ['body' => ['email' => $cfg['user']['email'], 'password' => $cfg['user']['password']]]);
    awCheck($res, 200, 'Login (Web)', 2, 'login');
});

test('API.md-Schluessel (schreibend): Login (Token)', function () {
    $cfg = testConfig();
    $res = apiRequest('POST', 'auth', ['body' => ['email' => $cfg['user']['email'], 'password' => $cfg['user']['password']]]);
    awCheck($res, 200, 'Login (Token)', 2, 'auth');
});

test('API.md-Schluessel (schreibend): Logout', function () {
    // Eigene Sitzung -- adSessionCookie() cached die der Lesesuite
    $cfg = testConfig();
    $login = apiRequest('POST', 'login', ['body' => ['email' => $cfg['user']['email'], 'password' => $cfg['user']['password']]]);
    assertStatus(200, $login);
    $cookie = explode(';', (string) $login['set_cookie'])[0];
    $res = apiRequest('POST', 'logout', ['cookie' => $cookie]);
    awCheck($res, 200, 'Logout', 1, 'logout');
});

// ---- Mitglieder und Termine ---------------------------------------------------

test('API.md-Schluessel (schreibend): Mitglied erstellen', function () {
    awWorld();
    awCheck(awMemberCreateResponse(), 201, 'Mitglied erstellen', 2, 'members POST');
});

test('API.md-Schluessel (schreibend): Mitglied aktualisieren', function () {
    awStationReady();
    awCheck(awPutMemberResponse(), 200, 'Mitglied aktualisieren', 1, 'members PUT');
});

test('API.md-Schluessel (schreibend): Termin erstellen', function () {
    awWorld();
    awCheck(awAppointmentCreateResponse(), 201, 'Termin erstellen', 2, 'appointments POST');
});

// ---- Terminserien -------------------------------------------------------------

function awSeriesBody(array $extra = []): array
{
    return array_merge([
        'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU', 'start_date' => AW_SERIES_START, 'until' => '2032-04-27',
        'title' => 'AW-Serie', 'type_id' => awWorld()['type'], 'start_time' => '19:30', 'end_time' => '21:00',
        'location' => 'Probelokal', 'description' => null,
    ], $extra);
}

function awSeriesId(?int $set = null): ?int
{
    static $id = null;
    if ($set !== null) {
        $id = $set;
    }
    return $id;
}

test('API.md-Schluessel (schreibend): Serie anlegen -- Vorschau', function () {
    $res = awAdmin('POST', 'appointment_series', ['query' => ['preview' => 1], 'body' => awSeriesBody()]);
    awCheck($res, 200, 'Serie anlegen (und Vorschau)', 1, 'appointment_series preview');
});

test('API.md-Schluessel (schreibend): Serie anlegen', function () {
    $res = awAdmin('POST', 'appointment_series', ['body' => awSeriesBody()]);
    awCheck($res, 201, 'Serie anlegen (und Vorschau)', 2, 'appointment_series POST');
    awTrack('series', awSeriesId((int) $res['body']['series_id']));
});

test('API.md-Schluessel (schreibend): Serie aendern ohne Regelaenderung', function () {
    $res = awAdmin('PUT', 'appointment_series', ['query' => ['id' => awSeriesId()],
        'body' => ['from_date' => '2032-03-16', 'title' => 'AW-Serie geaendert']]);
    awCheck($res, 200, 'Serie ändern: „Dieser und alle folgenden" ohne Regeländerung', 2, 'appointment_series PUT');
});

test('API.md-Schluessel (schreibend): Serie fortsetzen', function () {
    $res = awAdmin('POST', 'appointment_series', ['query' => ['id' => awSeriesId(), 'action' => 'extend'],
        'body' => ['until' => '2032-05-25']]);
    awCheck($res, 200, 'Serie fortsetzen', 2, 'appointment_series extend');
});

test('API.md-Schluessel (schreibend): Regel ab einem Termin aendern (Split)', function () {
    $body = awSeriesBody(['rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TH', 'until' => '2032-05-27']);
    unset($body['start_date']);
    $body['from_date'] = '2032-04-06';
    $res = awAdmin('POST', 'appointment_series', ['query' => ['id' => awSeriesId(), 'action' => 'split'], 'body' => $body]);
    awCheck($res, 201, 'Regel ab einem Termin ändern: „Dieser und alle folgenden" mit Regeländerung (Split)', 1,
        'appointment_series split');
    if (!empty($res['body']['series_id'])) {
        awTrack('series', (int) $res['body']['series_id']);
    }
});

test('API.md-Schluessel (schreibend): Serie ab einem Termin beenden', function () {
    $res = awAdmin('DELETE', 'appointment_series', ['query' => ['id' => awSeriesId(), 'from' => AW_SERIES_START]]);
    awCheck($res, 200, 'Serie ab einem Termin beenden', 1, 'appointment_series DELETE');
});

// ---- Geraete ------------------------------------------------------------------

test('API.md-Schluessel (schreibend): Geraet anlegen', function () {
    // Status 200, nicht 201 -- so antwortet der Handler (API.md nennt keinen Status)
    awCheck(awDevice('kiosk')['response'], 200, 'Gerät anlegen', 2, 'users create_device');
});

test('API.md-Schluessel (schreibend): API-Token neu generieren (Wegwerf-Geraet)', function () {
    // Nur mit user_id eines eigenen Geraets: ohne user_id verloere das
    // Testkonto seinen Token fuer den restlichen Lauf.
    $res = awAdmin('POST', 'regenerate_token', ['body' => ['user_id' => awDevice('totp_location')['user_id']]]);
    awCheck($res, 200, 'API-Token neu generieren', 2, 'regenerate_token');
});

// ---- Station (Kiosk) ----------------------------------------------------------

test('API.md-Schluessel (Check-in): Station -- Status', function () {
    awStationReady();
    $res = apiRequest('GET', 'station', ['token' => awDevice('kiosk')['api_token'], 'query' => ['action' => 'status']]);
    awCheck($res, 200, 'Status', 1, 'station status');
});

test('API.md-Schluessel (Check-in): Station -- Stations-Code', function () {
    $res = apiRequest('GET', 'station', ['token' => awDevice('kiosk')['api_token'], 'query' => ['action' => 'totp']]);
    awCheck($res, 200, 'Stations-Code', 1, 'station totp');
});

test('API.md-Schluessel (Check-in): Station -- identify', function () {
    awStationReady();
    $res = apiRequest('POST', 'station', ['token' => awDevice('kiosk')['api_token'], 'query' => ['action' => 'identify'],
        'body' => ['member_number' => awWorld()['member_number'], 'pin' => AW_PIN]]);
    awCheck($res, 200, 'Anmeldung: identify', 2, 'station identify');
});

// ---- Check-in -----------------------------------------------------------------

function awCheckinBody(): array
{
    return ['member_number' => awWorld()['member_number'], 'appointment_id' => awWorld()['appointment'],
            'arrival_time' => date('Y-m-d H:i:s')];
}

/** Loescht den Record aus einem Check-in, damit der naechste wieder 201 liefert. */
function awDropRecord(array $res): void
{
    if (!empty($res['body']['record_id'])) {
        awAdmin('DELETE', 'records', ['query' => ['id' => (int) $res['body']['record_id']]]);
    }
}

test('API.md-Schluessel (Check-in): Automatischer Check-In (auth_device)', function () {
    $res = apiRequest('POST', 'auto_checkin', ['token' => awDevice('auth_device')['api_token'], 'body' => awCheckinBody()]);
    try {
        awCheck($res, 201, 'Automatischer Check-In', 2, 'auto_checkin');
    } finally {
        awDropRecord($res);
    }
});

test('API.md-Schluessel (Check-in): Check-In mit TOTP-Code', function () {
    // API.md zeigt fuer totp_checkin nur Anfrage und Fehler; die Erfolgsantwort
    // ist laut Text dieselbe wie beim automatischen Check-in.
    $code = apiRequest('GET', 'station', ['token' => awDevice('kiosk')['api_token'], 'query' => ['action' => 'totp']]);
    assertStatus(200, $code, 'Stations-Code fuer totp_checkin');
    $body = awCheckinBody() + ['totp_code' => (string) $code['body']['code']];
    $body['member_id'] = awWorld()['member'];
    unset($body['member_number']);
    $res = awAdmin('POST', 'totp_checkin', ['body' => $body]);
    try {
        awCheck($res, 201, 'Automatischer Check-In', 2, 'totp_checkin');
    } finally {
        awDropRecord($res);
    }
});

// ---- Rueckmeldung -------------------------------------------------------------

test('API.md-Schluessel (schreibend): Rueckmeldung abgeben (Antwort wie "Ein Termin")', function () {
    // Kuenftiger Termin: Rueckmeldungen nimmt der Server nur vor Beginn an
    $apt = awAdmin('POST', 'appointments', ['body' => ['title' => 'AW-Rueckmeldung', 'type_id' => awWorld()['type'],
        'date' => date('Y-m-d', strtotime('+3 days')), 'start_time' => '19:00:00']]);
    assertStatus(201, $apt);
    awTrack('appointments', (int) $apt['body']['id']);

    $res = awAdmin('PUT', 'appointment_responses', [
        'query' => ['appointment_id' => (int) $apt['body']['id'], 'member_id' => awWorld()['member']],
        'body'  => ['status' => 'yes', 'comment' => 'AW']]);
    // comparison gibt es erst nach Beginn (API.md, Abschnitt "Ein Termin")
    awCheck($res, 200, 'Ein Termin', 1, 'appointment_responses PUT', ['comparison']);
});

// ---- Arbeitszeit --------------------------------------------------------------

test('API.md-Schluessel (schreibend): Sitzung steuern und freigeben', function () {
    $start = awAdmin('POST', 'work_sessions', ['body' => ['action' => 'start',
        'activity_id' => awWorld()['activity'], 'member_id' => awWorld()['member']]]);
    $sessionId = (int) ($start['body']['session']['session_id'] ?? 0);
    try {
        awCheck($start, 201, 'Sitzung steuern', 2, 'work_sessions start');

        $stop = awAdmin('POST', 'work_sessions', ['body' => ['action' => 'stop', 'member_id' => awWorld()['member'], 'force' => true]]);
        awCheck($stop, 200, 'Sitzung steuern', 2, 'work_sessions stop');

        $approve = awAdmin('PUT', 'work_sessions', ['query' => ['id' => $sessionId], 'body' => ['action' => 'approve']]);
        awCheck($approve, 200, 'Freigeben / Ablehnen', 2, 'work_sessions approve');
    } finally {
        if ($sessionId > 0) {
            awAdmin('DELETE', 'work_sessions', ['query' => ['id' => $sessionId]]);
        }
    }
});

// ---- Import und Bereinigung ---------------------------------------------------

test('API.md-Schluessel (schreibend): Import (members)', function () {
    $surname = 'AW-Import ' . uniqid();
    $file = tempnam(sys_get_temp_dir(), 'aw');
    file_put_contents($file, "name;surname\nAw;{$surname}\n");

    $cfg = testConfig();
    $ch = curl_init(rtrim($cfg['base_url'], '/') . '/api/api.php?resource=import&type=members');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . apiToken('admin')],
        CURLOPT_POSTFIELDS => ['file' => new CURLFile($file, 'text/csv', 'aw.csv')],
    ]);
    $raw = (string) curl_exec($ch);
    $res = ['status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => json_decode($raw, true), 'raw' => $raw];
    curl_close($ch);
    unlink($file);

    try {
        // skipped/appointments_created gibt es nur bei type=records (API.md, Import)
        awCheck($res, 200, 'Import', 1, 'import', ['skipped', 'appointments_created']);
    } finally {
        foreach (awAdmin('GET', 'members')['body'] ?? [] as $m) {
            if (($m['surname'] ?? '') === $surname) {
                awAdmin('DELETE', 'members', ['query' => ['id' => (int) $m['member_id']]]);
            }
        }
        $logs = awAdmin('GET', 'import_logs')['body'] ?? [];
        foreach ($logs['logs'] ?? $logs as $log) {
            if (($log['filename'] ?? '') === 'aw.csv') {
                awAdmin('DELETE', 'import_logs', ['query' => ['id' => (int) $log['log_id']]]);
            }
        }
    }
});

test('API.md-Schluessel (schreibend): Bestand nach Fristen bereinigen (sichere Fristen)', function () {
    // Fristen, die nichts treffen -- dieselben wie in cleanup_api.php
    $res = awAdmin('POST', 'cleanup', ['body' => ['years' => 100, 'years_worktime' => 30, 'years_audit' => 100]]);
    awCheck($res, 200, 'Bestand nach Fristen bereinigen', 2, 'cleanup');
});

// ---- Loeschen (am Ende: Testmitglied) -----------------------------------------

test('API.md-Schluessel (schreibend): Anwesenheit loeschen (Massenloeschung am Testmitglied)', function () {
    $res = awAdmin('DELETE', 'records', ['query' => ['member_id' => awWorld()['member']]]);
    awCheck($res, 200, 'Anwesenheit löschen', 1, 'records DELETE member_id');
});

test('API.md-Schluessel (schreibend): Mitglied loeschen', function () {
    $res = awAdmin('DELETE', 'members', ['query' => ['id' => awWorld()['member']]]);
    awCheck($res, 200, 'Mitglied löschen', 1, 'members DELETE');
});

test('Aufraeumen: die Schreibsuite entfernt alles und stellt die Schalter zurueck', function () {
    foreach (awTrack('series') as $sid) {
        $s = awAdmin('GET', 'appointment_series', ['query' => ['id' => $sid]]);
        if ($s['status'] === 200 && !empty($s['body']['start_date'])) {
            awAdmin('DELETE', 'appointment_series', ['query' => ['id' => $sid, 'from' => $s['body']['start_date']]]);
        }
    }
    // Uebrige Termine der Terminart (Serie, Check-in, Rueckmeldung)
    foreach ([2032, (int) date('Y'), (int) date('Y', strtotime('+3 days'))] as $year) {
        foreach (awAdmin('GET', 'appointments', ['query' => ['year' => $year]])['body'] ?? [] as $a) {
            if ((int) ($a['type_id'] ?? 0) === awWorld()['type']) {
                awAdmin('DELETE', 'appointments', ['query' => ['id' => (int) $a['appointment_id']]]);
            }
        }
    }
    foreach (['users', 'members', 'activity_types', 'appointment_types', 'member_groups'] as $kind) {
        foreach (awTrack($kind) as $id) {
            awAdmin('DELETE', $kind, ['query' => ['id' => $id]]);
        }
    }
    foreach (awSavedSettings() as $key => $value) {
        if ($value !== null) {
            awAdmin('PUT', 'settings', ['body' => ['setting_key' => $key, 'setting_value' => $value]]);
        }
    }

    // Nachpruefen: nichts von der Testwelt bleibt uebrig
    $rest = [];
    foreach (['activity_types' => 'activity_id', 'appointment_types' => 'type_id', 'member_groups' => 'group_id'] as $kind => $idKey) {
        $list = awAdmin('GET', $kind)['body'] ?? [];
        foreach ($list as $row) {
            if (in_array((int) ($row[$idKey] ?? 0), awTrack($kind), true)) {
                $rest[] = "{$kind} {$row[$idKey]}";
            }
        }
    }
    foreach (awTrack('members') as $id) {
        if (awAdmin('GET', 'members', ['query' => ['id' => $id]])['status'] === 200) {
            $rest[] = "members {$id}";
        }
    }
    foreach (awTrack('users') as $id) {
        if (awAdmin('GET', 'users', ['query' => ['id' => $id]])['status'] === 200) {
            $rest[] = "users {$id}";
        }
    }
    foreach (awSavedSettings() as $key => $value) {
        if ($value !== null && awSettingValue($key) !== $value) {
            $rest[] = "setting {$key}";
        }
    }
    assertSame([], $rest, 'Nicht alles konnte entfernt oder zurueckgestellt werden');
});
