<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * Setzt eine Systemeinstellung.
 * PUT settings erwartet {setting_key, setting_value} — nicht das Feld direkt.
 */
function setSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

/**
 * Die Zeiterfassung muss fuer diese Suite eingeschaltet sein.
 * Der Schalter wird gesetzt und am Ende NICHT zurueckgenommen —
 * die Suite laeuft gegen eine Entwicklungsinstanz.
 */
function enableWorktime(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    setSetting('worktime_enabled', '1');
    $done = true;
}

/** Legt eine Taetigkeitsart an und liefert ihre id. */
function createActivityType(string $name): int
{
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => $name],
    ]);
    assertStatus(201, $res, "Anlegen von '{$name}' fehlgeschlagen");

    return (int) $res['body']['id'];
}

test('activity_types: Admin kann anlegen und lesen', function () {
    enableWorktime();
    $name = 'Test-Taetigkeit ' . uniqid();
    $id   = createActivityType($name);

    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    assertStatus(200, $res);
    assertSame($name, $res['body']['activity_name']);
    assertSame('none', $res['body']['verification']);
    assertSame(1, (int) $res['body']['is_active']);

    apiRequest('DELETE', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
});

test('activity_types: user darf lesen', function () {
    enableWorktime();
    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('user')]);
    assertStatus(200, $res);
    assertTrue(is_array($res['body']), 'Liste erwartet');
});

test('activity_types: user darf nicht anlegen', function () {
    enableWorktime();
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('user'),
        'body'  => ['activity_name' => 'Verboten'],
    ]);
    assertStatus(403, $res);
});

test('activity_types: leerer Name wird abgewiesen', function () {
    enableWorktime();
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => ''],
    ]);
    assertStatus(400, $res);
});

test('activity_types: ungueltiger verification-Wert wird abgewiesen', function () {
    enableWorktime();
    $res = apiRequest('POST', 'activity_types', [
        'token' => apiToken('admin'),
        'body'  => ['activity_name' => 'Ungueltig', 'verification' => 'vielleicht'],
    ]);
    assertStatus(400, $res);
});

test('activity_types: Admin kann aendern und loeschen', function () {
    enableWorktime();
    $id = createActivityType('Zu aendern ' . uniqid());

    $res = apiRequest('PUT', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['activity_name' => 'Geaendert', 'verification' => 'start', 'is_active' => 0],
    ]);
    assertStatus(200, $res);

    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    assertSame('Geaendert', $res['body']['activity_name']);
    assertSame('start', $res['body']['verification']);

    $res = apiRequest('DELETE', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    assertStatus(200, $res);
});

test('activity_types: user sieht ausgemusterte Arten nicht', function () {
    enableWorktime();
    $id = createActivityType('Ausgemustert ' . uniqid());
    assertStatus(200, apiRequest('PUT', 'activity_types', [
        'token' => apiToken('admin'),
        'query' => ['id' => $id],
        'body'  => ['activity_name' => 'Ausgemustert', 'is_active' => 0],
    ]));

    $res = apiRequest('GET', 'activity_types', ['token' => apiToken('user')]);
    $ids = array_column($res['body'], 'activity_id');
    assertTrue(!in_array((string) $id, array_map('strval', $ids), true),
        'Ausgemusterte Taetigkeitsart darf fuer user nicht sichtbar sein');

    apiRequest('DELETE', 'activity_types', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
});

test('Feature-Schalter: bei worktime_enabled=0 antwortet activity_types mit 404', function () {
    enableWorktime();
    try {
        setSetting('worktime_enabled', '0');
        $res = apiRequest('GET', 'activity_types', ['token' => apiToken('admin')]);
        assertStatus(404, $res, 'Abgeschaltetes Feature muss 404 liefern, nicht 403');
    } finally {
        // Der Schalter muss in jedem Fall zurueck, sonst scheitern alle
        // folgenden Tests an einer abgeschalteten Zeiterfassung.
        setSetting('worktime_enabled', '1');
    }
});
