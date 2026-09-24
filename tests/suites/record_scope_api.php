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
declare(strict_types=1);

/**
 * Der lesende Einzelabruf verrät nicht, dass es den Satz gibt.
 *
 * `GET records|exceptions|work_sessions&id=…` antwortete einem Mitglied bei
 * einem fremden Satz mit 403, bei einer erfundenen Kennung mit 404. Der
 * Unterschied ist die Auskunft: Wer die Kennungen durchzählt, erfährt allein
 * aus dem Rückgabewert, welche belegt sind und welche nicht — ohne je einen
 * Inhalt zu sehen.
 *
 * Seit 1.14.1 sind beide Antworten gleich: 404 mit derselben Meldung. Diese
 * Suite prüft nicht die Zahl allein, sondern die Gleichheit von Antwortstatus
 * und Rumpf — daran scheitert auch eine Fassung, die zwar 404 meldet, aber mit
 * einem verräterischen Text.
 *
 * Schreibende Zugriffe bleiben, wie sie sind: Wer ein PUT oder DELETE auf einen
 * fremden Satz schickt, bekommt weiter 403. Dort ist die Rolle der Grund, nicht
 * die Frage, ob der Satz existiert.
 *
 * Der Einzelabruf von `work_sessions` steht in `worktime_api` — dort liegen die
 * Helfer für die Arbeitszeit.
 */

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** Kennung, die es sicher nicht gibt. */
const RS_FEHLT = 999000111;

/**
 * Ein fremder Anwesenheitssatz und ein fremder Antrag, einmal angelegt.
 *
 * Fremd heißt: für ein anderes Mitglied als das des Testkontos `user`.
 */
function rsWelt(): array
{
    static $welt = null;
    if ($welt !== null) {
        return $welt;
    }

    $admin  = apiToken('admin');
    $suffix = uniqid();

    $group = apiRequest('POST', 'member_groups', ['token' => $admin,
        'body' => ['group_name' => "RS {$suffix}"]]);
    assertStatus(201, $group);
    $groupId = (int) $group['body']['id'];

    $type = apiRequest('POST', 'appointment_types', ['token' => $admin, 'body' => [
        'type_name' => "RS {$suffix}", 'is_default' => 0, 'color' => '#667eea',
        'group_ids' => [$groupId]]]);
    assertStatus(201, $type);
    $typeId = (int) $type['body']['id'];

    $apt = apiRequest('POST', 'appointments', ['token' => $admin, 'body' => [
        'title' => 'RS-Test', 'date' => date('Y-m-d', strtotime('-3 days')),
        'start_time' => '19:00:00', 'type_id' => $typeId]]);
    assertStatus(201, $apt);
    $aptId = (int) $apt['body']['id'];

    $ownMemberId = apiMemberId('user');
    assertTrue($ownMemberId !== null, 'Das Testkonto user hat kein verknuepftes Mitglied');

    $members = apiRequest('GET', 'members', ['token' => $admin]);
    $otherId = null;
    foreach (($members['body'] ?? []) as $m) {
        if ((int) $m['member_id'] !== (int) $ownMemberId) {
            $otherId = (int) $m['member_id'];
            break;
        }
    }
    assertTrue($otherId !== null, 'Kein zweites Mitglied fuer den Test vorhanden');

    $record = apiRequest('POST', 'records', ['token' => $admin, 'body' => [
        'member_id' => $otherId, 'appointment_id' => $aptId, 'status' => 'present']]);
    assertStatus(201, $record);

    $exception = apiRequest('POST', 'exceptions', ['token' => $admin, 'body' => [
        'member_id'      => $otherId,
        'appointment_id' => $aptId,
        'exception_type' => 'absence',
        'reason'         => 'Fremder Antrag fuer den Sichtbarkeitstest']]);
    assertStatus(201, $exception);

    $welt = [
        'group'       => $groupId,
        'type'        => $typeId,
        'appointment' => $aptId,
        'member'      => $otherId,
        'record'      => (int) $record['body']['id'],
        'exception'   => (int) $exception['body']['id'],
    ];

    return $welt;
}

/** Fremd und nicht vorhanden müssen von außen ununterscheidbar sein. */
function rsGleicheAntwort(string $resource, int $fremd): void
{
    $token = apiToken('user');

    $aufFremd = apiRequest('GET', $resource, ['token' => $token, 'query' => ['id' => $fremd]]);
    $aufNichts = apiRequest('GET', $resource, ['token' => $token, 'query' => ['id' => RS_FEHLT]]);

    assertStatus(404, $aufFremd, "{$resource}: der fremde Satz wird noch durch den Status verraten");
    assertSame($aufNichts['status'], $aufFremd['status'],
        "{$resource}: fremd und nicht vorhanden liefern verschiedene Status");
    assertSame(json_encode($aufNichts['body']), json_encode($aufFremd['body']),
        "{$resource}: fremd und nicht vorhanden liefern verschiedene Antworten");
}

test('records: ein fremder Satz antwortet wie ein nicht vorhandener', function () {
    rsGleicheAntwort('records', rsWelt()['record']);
});

test('exceptions: ein fremder Antrag antwortet wie ein nicht vorhandener', function () {
    rsGleicheAntwort('exceptions', rsWelt()['exception']);
});

test('records: der eigene Satz bleibt lesbar', function () {
    $welt  = rsWelt();
    $admin = apiToken('admin');

    $eigen = apiRequest('POST', 'records', ['token' => $admin, 'body' => [
        'member_id' => apiMemberId('user'), 'appointment_id' => $welt['appointment'],
        'status' => 'present']]);
    assertStatus(201, $eigen);
    $eigenId = (int) $eigen['body']['id'];

    $get = apiRequest('GET', 'records', ['token' => apiToken('user'), 'query' => ['id' => $eigenId]]);
    assertStatus(200, $get, 'Der eigene Satz ist mit der neuen Regel mitverschwunden');
    assertSame($eigenId, (int) ($get['body']['record_id'] ?? 0));

    apiRequest('DELETE', 'records', ['token' => $admin, 'query' => ['id' => $eigenId]]);
});

test('records: der Verwalter sieht den fremden Satz weiterhin', function () {
    $get = apiRequest('GET', 'records', ['token' => apiToken('manager'),
        'query' => ['id' => rsWelt()['record']]]);
    assertStatus(200, $get, 'Die neue Regel trifft auch Verwalter');
});

test('exceptions: der Verwalter sieht den fremden Antrag weiterhin', function () {
    $get = apiRequest('GET', 'exceptions', ['token' => apiToken('manager'),
        'query' => ['id' => rsWelt()['exception']]]);
    assertStatus(200, $get, 'Die neue Regel trifft auch Verwalter');
});

test('exceptions: schreibende Zugriffe auf einen fremden Antrag bleiben bei 403', function () {
    $welt  = rsWelt();
    $token = apiToken('user');

    assertStatus(403, apiRequest('PUT', 'exceptions', ['token' => $token,
        'query' => ['id' => $welt['exception']], 'body' => ['reason' => 'Uebernahmeversuch']]),
        'PUT auf einen fremden Antrag');
    assertStatus(403, apiRequest('DELETE', 'exceptions', ['token' => $token,
        'query' => ['id' => $welt['exception']]]),
        'DELETE auf einen fremden Antrag');
});

test('record_scope: aufraeumen', function () {
    $welt  = rsWelt();
    $admin = apiToken('admin');

    apiRequest('DELETE', 'exceptions', ['token' => $admin, 'query' => ['id' => $welt['exception']]]);
    apiRequest('DELETE', 'records', ['token' => $admin, 'query' => ['id' => $welt['record']]]);
    apiRequest('DELETE', 'appointments', ['token' => $admin, 'query' => ['id' => $welt['appointment']]]);
    apiRequest('DELETE', 'appointment_types', ['token' => $admin, 'query' => ['id' => $welt['type']]]);
    apiRequest('DELETE', 'member_groups', ['token' => $admin, 'query' => ['id' => $welt['group']]]);

    assertStatus(404, apiRequest('GET', 'records', ['token' => $admin,
        'query' => ['id' => $welt['record']]]), 'Der Testsatz blieb stehen');
});
