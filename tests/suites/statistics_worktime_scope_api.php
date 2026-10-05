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
 * Arbeitszeit in der Statistik: nur die eigenen Stunden.
 *
 * statistics?include=worktime haengt einen Block mit Stunden je Mitglied an.
 * Fuer Nicht-Verwalter ist er auf das eigene Mitglied begrenzt. Ein Konto ohne
 * verknuepftes Mitglied hat keine eigenen Stunden -- es darf auch keine
 * fremden sehen.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function swsGetSetting(string $key): string
{
    $res = apiRequest('GET', 'settings', ['token' => apiToken('admin')]);
    assertStatus(200, $res);
    foreach ($res['body']['settings'] as $row) {
        if ($row['setting_key'] === $key) {
            return (string) $row['setting_value'];
        }
    }

    return '0';
}

function swsSetSetting(string $key, string $value): void
{
    $res = apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]);
    assertStatus(200, $res, "Einstellung '{$key}' konnte nicht gesetzt werden");
}

test('Konto ohne Mitglied sieht in der Statistik keine fremden Arbeitszeiten', function () {
    $vorher = swsGetSetting('worktime_enabled');
    swsSetSetting('worktime_enabled', '1');

    $email    = 'ohne-mitglied-' . uniqid() . '@example.invalid';
    $password = 'Pruefung-' . bin2hex(random_bytes(6));
    $userId   = null;

    try {
        $jahr = (int) date('Y');

        // Gegenprobe: Der Admin sieht fuer das Jahr Stunden. Sonst bewiese ein
        // leerer Block beim Konto ohne Mitglied nichts.
        $admin = apiRequest('GET', 'statistics', ['token' => apiToken('admin'),
            'query' => ['year' => $jahr, 'include' => 'worktime']]);
        assertStatus(200, $admin);
        assertTrue(count($admin['body']['worktime']['members'] ?? []) > 0,
            "Vorbedingung: Im Jahr {$jahr} gibt es keine Arbeitszeitsitzungen, der Test waere ohne Aussage");

        $created = apiRequest('POST', 'users', ['token' => apiToken('admin'), 'body' => [
            'email' => $email, 'password' => $password, 'role' => 'user', 'name' => 'Ohne Mitglied']]);
        assertTrue(in_array($created['status'], [200, 201], true),
            'Testkonto konnte nicht angelegt werden: ' . substr($created['raw'], 0, 200));
        $userId = (int) ($created['body']['id'] ?? $created['body']['user_id'] ?? 0);
        assertTrue($userId > 0, 'Antwort ohne Kennung des Testkontos: ' . substr($created['raw'], 0, 200));

        $auth = apiRequest('POST', 'auth', ['body' => ['email' => $email, 'password' => $password]]);
        assertStatus(200, $auth, 'Anmeldung des Testkontos');
        assertSame(null, $auth['body']['user']['member_id'] ?? null, 'Vorbedingung: Konto ohne Mitglied');
        $token = (string) $auth['body']['token'];

        $res = apiRequest('GET', 'statistics', ['token' => $token,
            'query' => ['year' => $jahr, 'include' => 'worktime']]);
        assertStatus(200, $res);
        $mitglieder = $res['body']['worktime']['members'] ?? [];
        assertSame([], $mitglieder,
            'Konto ohne Mitglied sieht ' . count($mitglieder) . ' Mitglieder mit ihren Arbeitszeiten');
        assertSame(0, (int) ($res['body']['worktime']['summary']['total_minutes'] ?? 0),
            'Konto ohne Mitglied sieht eine Summe fremder Arbeitszeit');
    } finally {
        if ($userId) {
            apiRequest('DELETE', 'users', ['token' => apiToken('admin'), 'query' => ['id' => $userId]]);
        }
        swsSetSetting('worktime_enabled', $vorher);
    }
});

test('Mitglied sieht in der Statistik nur die eigenen Arbeitszeiten', function () {
    $vorher = swsGetSetting('worktime_enabled');
    swsSetSetting('worktime_enabled', '1');
    try {
        $res = apiRequest('GET', 'statistics', ['token' => apiToken('user'),
            'query' => ['year' => (int) date('Y'), 'include' => 'worktime']]);
        assertStatus(200, $res);
        $eigene = apiMemberId('user');
        foreach ($res['body']['worktime']['members'] ?? [] as $m) {
            assertSame($eigene, (int) $m['member_id'], 'Fremdes Mitglied im Arbeitszeitblock');
        }
    } finally {
        swsSetSetting('worktime_enabled', $vorher);
    }
});

test('Konto ohne Mitglied sieht auch bei abgeschalteter Anwesenheit keine fremden Arbeitszeiten', function () {
    // Seit OI-62, Etappe 2 hat statistics einen eigenen Pfad: Ist die
    // Anwesenheit aus, liefert include=worktime nur den Arbeitszeitblock.
    // Derselbe Schutz muss dort greifen.
    $vorherWt  = swsGetSetting('worktime_enabled');
    $vorherAtt = swsGetSetting('attendance_enabled');
    swsSetSetting('worktime_enabled', '1');

    $email    = 'ohne-mitglied-att-' . uniqid() . '@example.invalid';
    $password = 'Pruefung-' . bin2hex(random_bytes(6));
    $userId   = null;

    try {
        $jahr = (int) date('Y');

        $created = apiRequest('POST', 'users', ['token' => apiToken('admin'), 'body' => [
            'email' => $email, 'password' => $password, 'role' => 'user', 'name' => 'Ohne Mitglied']]);
        assertTrue(in_array($created['status'], [200, 201], true),
            'Testkonto konnte nicht angelegt werden: ' . substr($created['raw'], 0, 200));
        $userId = (int) ($created['body']['id'] ?? $created['body']['user_id'] ?? 0);
        assertTrue($userId > 0, 'Antwort ohne Kennung des Testkontos: ' . substr($created['raw'], 0, 200));

        $auth = apiRequest('POST', 'auth', ['body' => ['email' => $email, 'password' => $password]]);
        assertStatus(200, $auth, 'Anmeldung des Testkontos');
        assertSame(null, $auth['body']['user']['member_id'] ?? null, 'Vorbedingung: Konto ohne Mitglied');
        $token = (string) $auth['body']['token'];

        swsSetSetting('attendance_enabled', '0');

        // Gegenprobe im selben Pfad: Der Admin sieht Stunden.
        $admin = apiRequest('GET', 'statistics', ['token' => apiToken('admin'),
            'query' => ['year' => $jahr, 'include' => 'worktime']]);
        assertStatus(200, $admin);
        assertTrue(!array_key_exists('statistics', $admin['body']),
            'Vorbedingung: Mit abgeschalteter Anwesenheit muss der verkuerzte Pfad antworten');
        assertTrue(count($admin['body']['worktime']['members'] ?? []) > 0,
            "Vorbedingung: Im Jahr {$jahr} gibt es keine Arbeitszeitsitzungen, der Test waere ohne Aussage");

        $res = apiRequest('GET', 'statistics', ['token' => $token,
            'query' => ['year' => $jahr, 'include' => 'worktime']]);
        assertStatus(200, $res);
        $mitglieder = $res['body']['worktime']['members'] ?? [];
        assertSame([], $mitglieder,
            'Konto ohne Mitglied sieht ohne Anwesenheit ' . count($mitglieder) . ' Mitglieder mit ihren Arbeitszeiten');
        assertSame(0, (int) ($res['body']['worktime']['summary']['total_minutes'] ?? 0),
            'Konto ohne Mitglied sieht ohne Anwesenheit eine Summe fremder Arbeitszeit');
    } finally {
        swsSetSetting('attendance_enabled', $vorherAtt === '' ? '1' : $vorherAtt);
        if ($userId) {
            apiRequest('DELETE', 'users', ['token' => apiToken('admin'), 'query' => ['id' => $userId]]);
        }
        swsSetSetting('worktime_enabled', $vorherWt);
    }
});
