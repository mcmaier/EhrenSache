<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * Anlegen von Benutzern samt Mitgliedsverknuepfung.
 *
 * Hintergrund: Der Dialog schickte die im Anlegen-Modal gewaehlte
 * Mitgliedsverknuepfung nicht mit, und der POST-Zweig pruefte sie -- anders
 * als der PUT-Zweig -- ueberhaupt nicht. Beides gehoert zusammen: Sobald die
 * Oberflaeche das Feld sendet, braucht der Server dieselbe Schranke wie beim
 * Bearbeiten, sonst entstehen Doppelverknuepfungen.
 */

/** Sammelt angelegte Benutzer fuer das Aufraeumen am Ende. */
function usersTrack(int $userId): int
{
    static $ids = [];

    if ($userId > 0) {
        $ids[] = $userId;
    }

    return $userId;
}

/** @return array<int, int> */
function usersTracked(): array
{
    $ref = new ReflectionFunction('usersTrack');

    return $ref->getStaticVariables()['ids'] ?? [];
}

/** Eine E-Mail-Adresse, die es garantiert noch nicht gibt. */
function usersMail(string $zweck): string
{
    return 'test-' . $zweck . '-' . uniqid() . '@example.invalid';
}

/**
 * Ein Mitglied, das an KEINEM Benutzer haengt.
 *
 * Ohne das trifft ein Test, der die Verknuepfung prueft, zufaellig ein
 * bereits verknuepftes Mitglied und scheitert an der 409 -- richtig, aber am
 * falschen Grund.
 */
function usersFreeMemberId(): int
{
    $members = apiRequest('GET', 'members', ['token' => apiToken('admin')]);
    assertStatus(200, $members);

    $users = apiRequest('GET', 'users', ['token' => apiToken('admin')]);
    assertStatus(200, $users);

    $liste    = $users['body']['users'] ?? $users['body'];
    $vergeben = [];
    foreach ($liste as $user) {
        if (!empty($user['member_id'])) {
            $vergeben[(int) $user['member_id']] = true;
        }
    }

    foreach (($members['body']['members'] ?? $members['body']) as $member) {
        $id = (int) $member['member_id'];
        if (!isset($vergeben[$id])) {
            return $id;
        }
    }

    throw new RuntimeException('Kein unverknuepftes Mitglied im Bestand — Test nicht durchfuehrbar');
}

/** Legt einen Benutzer an und liefert die Antwort. */
function usersCreate(array $overrides = []): array
{
    $body = array_merge([
        'email'    => usersMail('create'),
        'name'     => 'Testbenutzer',
        'password' => 'probelauf',
        'role'     => 'user',
    ], $overrides);

    return apiRequest('POST', 'users', ['token' => apiToken('admin'), 'body' => $body]);
}

test('users: POST uebernimmt die Mitgliedsverknuepfung', function () {
    $memberId = usersFreeMemberId();

    $res = usersCreate(['member_id' => $memberId]);
    assertStatus(201, $res);
    $userId = usersTrack((int) $res['body']['id']);

    $get = apiRequest('GET', 'users', ['token' => apiToken('admin'), 'query' => ['id' => $userId]]);
    assertStatus(200, $get);
    assertSame($memberId, (int) $get['body']['member_id'],
               'Die beim Anlegen gewaehlte Verknuepfung fehlt');
});

test('users: POST mit bereits verknuepftem Mitglied wird abgewiesen', function () {
    $memberId = usersFreeMemberId();

    $erster = usersCreate(['member_id' => $memberId]);
    assertStatus(201, $erster);
    usersTrack((int) $erster['body']['id']);

    $zweiter = usersCreate(['member_id' => $memberId]);
    assertStatus(409, $zweiter);
    usersTrack((int) ($zweiter['body']['id'] ?? 0));
});

test('users: POST mit unbekanntem Mitglied wird abgewiesen', function () {
    $res = usersCreate(['member_id' => 999999]);
    assertStatus(404, $res);
    usersTrack((int) ($res['body']['id'] ?? 0));
});

test('users: POST ohne Mitglied bleibt unverknuepft', function () {
    $res = usersCreate();
    assertStatus(201, $res);
    $userId = usersTrack((int) $res['body']['id']);

    $get = apiRequest('GET', 'users', ['token' => apiToken('admin'), 'query' => ['id' => $userId]]);
    assertStatus(200, $get);
    assertTrue(empty($get['body']['member_id']), 'Ohne Auswahl darf keine Verknuepfung entstehen');
});

test('Aufraeumen: die Suite entfernt die angelegten Benutzer', function () {
    $rest = [];

    foreach (usersTracked() as $userId) {
        if ($userId <= 0) {
            continue;
        }

        $res = apiRequest('DELETE', 'users', [
            'token' => apiToken('admin'),
            'query' => ['id' => $userId],
        ]);

        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "user {$userId} (HTTP {$res['status']})";
        }
    }

    assertSame([], $rest, 'Nicht alle Testbenutzer konnten entfernt werden');
});
