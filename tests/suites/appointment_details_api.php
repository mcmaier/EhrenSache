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
 * Ort und Ende am Termin ueber die API (FI-23).
 *
 * Jeder Test baut sich eine eigene Terminart, damit die Dublettenpruefung
 * der Termine (je Terminart im Toleranzfenster) nicht zwischen Tests greift.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** @return array{group: int, type: int} */
function adWorld(): array
{
    $suffix = uniqid();
    $group = apiRequest('POST', 'member_groups', ['token' => apiToken('admin'),
        'body' => ['group_name' => "AD {$suffix}"]]);
    assertStatus(201, $group);
    $type = apiRequest('POST', 'appointment_types', ['token' => apiToken('admin'),
        'body' => ['type_name' => "AD {$suffix}", 'is_default' => 0, 'color' => '#667eea',
                   'group_ids' => [(int) $group['body']['id']]]]);
    assertStatus(201, $type);

    return ['group' => (int) $group['body']['id'], 'type' => (int) $type['body']['id']];
}

function adDropWorld(array $world, array $appointmentIds): void
{
    foreach ($appointmentIds as $id) {
        apiRequest('DELETE', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    }
    apiRequest('DELETE', 'appointment_types', ['token' => apiToken('admin'), 'query' => ['id' => $world['type']]]);
    apiRequest('DELETE', 'member_groups', ['token' => apiToken('admin'), 'query' => ['id' => $world['group']]]);
}

function adPost(array $world, array $extra): array
{
    return apiRequest('POST', 'appointments', ['token' => apiToken('admin'), 'body' => array_merge([
        'title' => 'AD-Termin', 'date' => '2026-11-20', 'start_time' => '19:30', 'type_id' => $world['type'],
    ], $extra)]);
}

/**
 * Erwartet 400. Legt der Server den Termin trotzdem an, wird seine ID fuer
 * das Aufraeumen gemerkt -- sonst bliebe bei einem Fehlschlag ein Termin
 * ohne Terminart in der Datenbank liegen.
 */
function adExpect400(array $res, array &$ids, string $msg): void
{
    if (($res['status'] ?? 0) === 201 && isset($res['body']['id'])) {
        $ids[] = (int) $res['body']['id'];
    }
    assertStatus(400, $res, $msg);
}

function adGet(int $id): array
{
    $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    assertStatus(200, $res);
    return $res['body'];
}

function adPut(int $id, array $body): array
{
    return apiRequest('PUT', 'appointments', ['token' => apiToken('admin'), 'query' => ['id' => $id], 'body' => $body]);
}

test('Anlegen mit Ort und Ende, Teil-Update laesst beide stehen', function () {
    $world = adWorld();
    $ids = [];
    try {
        $res = adPost($world, ['location' => ' Stadthalle ', 'end_time' => '22:00']);
        assertStatus(201, $res);
        $ids[] = $id = (int) $res['body']['id'];

        $apt = adGet($id);
        assertSame('Stadthalle', $apt['location'], 'Ort muss getrimmt gespeichert sein');
        assertSame('22:00:00', $apt['end_time']);

        assertStatus(200, adPut($id, ['title' => 'Nur der Titel']));
        $apt = adGet($id);
        assertSame('Stadthalle', $apt['location'], 'Teil-Update ohne location darf den Ort nicht loeschen');
        assertSame('22:00:00', $apt['end_time'], 'Teil-Update ohne end_time darf das Ende nicht loeschen');
    } finally {
        adDropWorld($world, $ids);
    }
});

test('null und Leerwert loeschen Ort und Ende', function () {
    $world = adWorld();
    $ids = [];
    try {
        $res = adPost($world, ['location' => 'Probelokal', 'end_time' => '21:00']);
        assertStatus(201, $res);
        $ids[] = $id = (int) $res['body']['id'];

        assertStatus(200, adPut($id, ['location' => null, 'end_time' => '']));
        $apt = adGet($id);
        assertSame(null, $apt['location']);
        assertSame(null, $apt['end_time']);
    } finally {
        adDropWorld($world, $ids);
    }
});

test('Ende gleich Beginn wird abgelehnt -- bei POST und gegen den gespeicherten Wert bei PUT', function () {
    $world = adWorld();
    $ids = [];
    try {
        adExpect400(adPost($world, ['end_time' => '19:30']), $ids, 'POST: Ende gleich Beginn');

        $res = adPost($world, ['end_time' => '22:00']);
        assertStatus(201, $res);
        $ids[] = $id = (int) $res['body']['id'];

        assertStatus(400, adPut($id, ['end_time' => '19:30']), 'PUT: Ende gleich gespeichertem Beginn');
        assertStatus(400, adPut($id, ['start_time' => '22:00']), 'PUT: Beginn gleich gespeichertem Ende');
        assertStatus(200, adPut($id, ['end_time' => '01:00']), 'Ende vor dem Beginn ist der Folgetag');
    } finally {
        adDropWorld($world, $ids);
    }
});

test('Ungueltiges Ende und ueberlanger Ort werden abgelehnt', function () {
    $world = adWorld();
    $ids = [];
    try {
        adExpect400(adPost($world, ['end_time' => '25:00']), $ids, 'Ungueltiges Ende');
        adExpect400(adPost($world, ['location' => str_repeat('x', 201), 'date' => '2026-11-21']), $ids,
                    'Ort ueber 200 Zeichen');
    } finally {
        adDropWorld($world, $ids);
    }
});

test('locations=1 liefert Orte fuer Verwalter, 403 fuer user', function () {
    $world = adWorld();
    $ids = [];
    $ort = 'AD-Ort ' . uniqid();
    try {
        $res = adPost($world, ['location' => $ort, 'date' => date('Y-m-d', strtotime('+30 days'))]);
        assertStatus(201, $res);
        $ids[] = (int) $res['body']['id'];

        $liste = apiRequest('GET', 'appointments', ['token' => apiToken('admin'), 'query' => ['locations' => 1]]);
        assertStatus(200, $liste);
        assertTrue(in_array($ort, $liste['body'], true), 'Eingetragener Ort fehlt in der Vorschlagsliste');

        assertStatus(403, apiRequest('GET', 'appointments', ['token' => apiToken('user'), 'query' => ['locations' => 1]]));
    } finally {
        adDropWorld($world, $ids);
    }
});

/**
 * Wie adExpect400, prueft zusaetzlich, dass die Antwort JSON mit einer
 * Meldung ist. Eine Terminart, die es nicht gibt, lieferte bis dahin HTTP 200
 * mit der HTML-Fehlerseite der PDOException.
 */
function adExpect400Json(array $res, array &$ids, string $msg): void
{
    adExpect400($res, $ids, $msg);
    assertTrue(is_string($res['body']['message'] ?? null) && $res['body']['message'] !== '',
               "{$msg}: 400 ohne JSON-Meldung: " . substr($res['raw'], 0, 200));
}

/** POST mit den Standardfeldern, aus denen $drop entfernt wird. */
function adPostWithout(array $world, array $drop): array
{
    $body = ['title' => 'AD-Termin', 'date' => '2026-11-20', 'start_time' => '19:30', 'type_id' => $world['type']];
    foreach ($drop as $feld) {
        unset($body[$feld]);
    }

    return apiRequest('POST', 'appointments', ['token' => apiToken('admin'), 'body' => $body]);
}

test('POST prueft Titel, Datum, Beginn und Terminart vor dem Anlegen', function () {
    $world = adWorld();
    $ids = [];
    try {
        foreach (['title', 'date', 'start_time'] as $feld) {
            adExpect400Json(adPostWithout($world, [$feld]), $ids, "Fehlendes {$feld}");
        }
        adExpect400Json(adPost($world, ['title' => '   ']), $ids, 'Leerer Titel');
        adExpect400Json(adPost($world, ['title' => str_repeat('x', 201)]), $ids, 'Titel ueber 200 Zeichen');
        adExpect400Json(adPost($world, ['title' => ['x']]), $ids, 'Titel als Liste');
        adExpect400Json(adPost($world, ['date' => 'kaputt']), $ids, 'Datum kaputt');
        adExpect400Json(adPost($world, ['date' => '2026-02-30']), $ids, 'Datum, das es nicht gibt');
        adExpect400Json(adPost($world, ['date' => '20.11.2026']), $ids, 'Datum im deutschen Format');
        adExpect400Json(adPost($world, ['start_time' => '25:00']), $ids, 'Beginn 25:00');
        adExpect400Json(adPost($world, ['start_time' => 'abends']), $ids, 'Beginn als Wort');
        adExpect400Json(adPost($world, ['type_id' => 999999999]), $ids, 'Terminart, die es nicht gibt');
        adExpect400Json(adPost($world, ['type_id' => 'abc']), $ids, 'Terminart als Wort');

        $res = adPost($world, ['title' => '  Probe  ', 'start_time' => '19:30:00']);
        assertStatus(201, $res, 'Gueltiger Termin mit HH:MM:SS');
        $ids[] = $id = (int) $res['body']['id'];
        $apt = adGet($id);
        assertSame('Probe', $apt['title'], 'Titel muss getrimmt gespeichert sein');
        assertSame('2026-11-20', $apt['date']);
        assertSame('19:30:00', $apt['start_time']);
    } finally {
        adDropWorld($world, $ids);
    }
});

test('PUT prueft mitgeschickte Kernfelder und laesst den Bestand bei 400 stehen', function () {
    $world = adWorld();
    $ids = [];
    try {
        $res = adPost($world, []);
        assertStatus(201, $res);
        $ids[] = $id = (int) $res['body']['id'];

        $faelle = [
            'Leerer Titel'        => ['title' => ''],
            'Titel null'          => ['title' => null],
            'Datum kaputt'        => ['date' => 'kaputt'],
            'Datum null'          => ['date' => null],
            'Datum 2026-02-30'    => ['date' => '2026-02-30'],
            'Beginn kaputt'       => ['start_time' => '7 Uhr'],
            'Beginn null'         => ['start_time' => null],
            'Terminart unbekannt' => ['type_id' => 999999999],
            'Terminart als Wort'  => ['type_id' => 'abc'],
        ];
        foreach ($faelle as $name => $body) {
            $put = adPut($id, $body);
            assertStatus(400, $put, "PUT: {$name}");
            assertTrue(is_string($put['body']['message'] ?? null), "PUT: {$name} ohne JSON-Meldung");
        }

        $apt = adGet($id);
        assertSame('AD-Termin', $apt['title'], 'Abgelehntes PUT darf den Titel nicht aendern');
        assertSame('2026-11-20', $apt['date']);
        assertSame('19:30:00', $apt['start_time']);
        assertSame($world['type'], (int) $apt['type_id']);

        assertStatus(200, adPut($id, ['date' => '2026-11-27', 'start_time' => '20:00']), 'Gueltiges Teil-Update');
        $apt = adGet($id);
        assertSame('2026-11-27', $apt['date']);
        assertSame('20:00:00', $apt['start_time']);
    } finally {
        adDropWorld($world, $ids);
    }
});

test('Beschreibung muss Text sein, Leerwert wird null', function () {
    $world = adWorld();
    $ids = [];
    try {
        adExpect400Json(adPost($world, ['description' => ['x']]), $ids, 'POST: Beschreibung als Liste');
        adExpect400Json(adPost($world, ['description' => 42]), $ids, 'POST: Beschreibung als Zahl');

        $res = adPost($world, ['description' => '  Noten mitbringen  ']);
        assertStatus(201, $res);
        $ids[] = $id = (int) $res['body']['id'];
        assertSame('Noten mitbringen', adGet($id)['description'], 'Beschreibung muss getrimmt gespeichert sein');

        $put = adPut($id, ['description' => ['x']]);
        assertStatus(400, $put, 'PUT: Beschreibung als Liste');
        assertTrue(is_string($put['body']['message'] ?? null), 'PUT: Beschreibung als Liste ohne JSON-Meldung');
        assertSame('Noten mitbringen', adGet($id)['description'], 'Abgelehntes PUT darf die Beschreibung nicht aendern');

        assertStatus(200, adPut($id, ['description' => '   ']));
        assertSame(null, adGet($id)['description'], 'Leere Beschreibung wird zu null');
    } finally {
        adDropWorld($world, $ids);
    }
});
