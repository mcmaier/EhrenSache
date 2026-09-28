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

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * PUT users aendert die Mitgliedsverknuepfung nur, wenn member_id mitkommt.
 *
 * Der Admin-Zweig schrieb member_id bedingungslos: Fehlte der Schluessel im
 * Request, setzte er NULL. Ein PUT, das nur account_status, is_active oder den
 * Namen aendern wollte, trennte damit still den Benutzer von seinem Mitglied --
 * danach fehlten ihm Check-in, eigene Statistik und Rueckmeldungen. Die
 * Oberflaeche schickt member_id bei jedem Speichern mit und war deshalb nicht
 * betroffen, wohl aber jeder API-Verbraucher (Klasse wie OI-54, siehe
 * partial_update_api.php).
 *
 * Ein ausdrueckliches "member_id": null trennt die Verknuepfung weiterhin.
 */

function umlCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");
    $id = (int) $res['body']['id'];
    assertTrue($id > 0, "{$resource} lieferte keine brauchbare ID: " . $res['raw']);

    return $id;
}

function umlDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

/**
 * Legt ein frisches Mitglied und einen damit verknuepften Benutzer an, reicht
 * beide an $fn weiter und raeumt danach auf -- auch wenn $fn scheitert.
 */
function umlWithLinkedUser(callable $fn): void
{
    $suffix   = uniqid();
    $memberId = null;
    $userId   = null;

    try {
        $memberId = umlCreate('members', ['name' => 'UML', 'surname' => "Verknuepft {$suffix}"]);
        $userId   = umlCreate('users', [
            'email'     => "uml-{$suffix}@example.invalid",
            'password'  => 'uml-' . bin2hex(random_bytes(6)),
            'name'      => "UML {$suffix}",
            'role'      => 'user',
            'member_id' => $memberId,
        ]);
        assertSame($memberId, umlMemberIdOf($userId), 'Ausgangslage: Benutzer ist nicht verknuepft');

        $fn($userId, $memberId);
    } finally {
        if ($userId !== null) {
            umlDelete('users', $userId);
        }
        if ($memberId !== null) {
            umlDelete('members', $memberId);
        }
    }
}

function umlMemberIdOf(int $userId): ?int
{
    $res = apiRequest('GET', 'users', ['token' => apiToken('admin'), 'query' => ['id' => $userId]]);
    assertStatus(200, $res);

    return isset($res['body']['member_id']) ? (int) $res['body']['member_id'] : null;
}

function umlPut(int $userId, array $body): array
{
    return apiRequest('PUT', 'users', [
        'token' => apiToken('admin'),
        'query' => ['id' => $userId],
        'body'  => $body,
    ]);
}

test('users: PUT nur mit account_status laesst member_id stehen', function () {
    umlWithLinkedUser(function (int $userId, int $memberId) {
        assertStatus(200, umlPut($userId, ['account_status' => 'suspended']));
        assertSame($memberId, umlMemberIdOf($userId));
    });
});

test('users: PUT nur mit is_active laesst member_id stehen', function () {
    umlWithLinkedUser(function (int $userId, int $memberId) {
        assertStatus(200, umlPut($userId, ['is_active' => 0]));
        assertSame($memberId, umlMemberIdOf($userId));
    });
});

test('users: PUT nur mit name laesst member_id stehen', function () {
    umlWithLinkedUser(function (int $userId, int $memberId) {
        assertStatus(200, umlPut($userId, ['name' => 'UML umbenannt']));
        assertSame($memberId, umlMemberIdOf($userId));
    });
});

test('users: PUT mit "member_id": null trennt die Verknuepfung', function () {
    umlWithLinkedUser(function (int $userId, int $memberId) {
        assertStatus(200, umlPut($userId, ['member_id' => null]));
        assertSame(null, umlMemberIdOf($userId));
    });
});

test('users: PUT mit anderer member_id haengt die Verknuepfung um', function () {
    umlWithLinkedUser(function (int $userId, int $memberId) {
        $otherId = umlCreate('members', ['name' => 'UML', 'surname' => 'Umgehaengt ' . uniqid()]);
        try {
            assertStatus(200, umlPut($userId, ['member_id' => $otherId]));
            assertSame($otherId, umlMemberIdOf($userId));
        } finally {
            // Erst loesen, sonst haengt der Benutzer beim Aufraeumen am
            // geloeschten Mitglied.
            umlPut($userId, ['member_id' => null]);
            umlDelete('members', $otherId);
        }
    });
});

test('users: PUT ohne verwertbares Feld antwortet 400 und laesst member_id stehen', function () {
    umlWithLinkedUser(function (int $userId, int $memberId) {
        assertStatus(400, umlPut($userId, []));
        assertSame($memberId, umlMemberIdOf($userId));
    });
});
