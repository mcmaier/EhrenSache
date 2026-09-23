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
 * Die Farbschwellen der Anwesenheitsquote als Einstellung (OI-55).
 *
 * Zwei Dinge sind zu halten: Der Server nimmt nur ganze Zahlen von 1 bis 99
 * an, und die Statistik liefert die Werte im Payload mit — jede Rolle sieht
 * die Statistik, aber nur Admins duerfen Einstellungen lesen.
 */

/** Setzt eine Schwelle und liefert die Antwort. */
function rbPut(string $key, $wert): array
{
    return apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $wert],
    ]);
}

/** Liest die Baender aus der Statistik-Antwort einer Rolle. */
function rbBands(string $rolle): array
{
    $res = apiRequest('GET', 'statistics', ['token' => apiToken($rolle)]);
    assertStatus(200, $res);
    assertTrue(isset($res['body']['rate_bands']), 'rate_bands fehlt im Statistik-Payload');

    return $res['body']['rate_bands'];
}

test('statistics: die Baender kommen im Payload mit', function () {
    $bands = rbBands('admin');

    foreach (['mid', 'fair', 'good'] as $name) {
        assertTrue(isset($bands[$name]), "Band {$name} fehlt");
        assertTrue(is_int($bands[$name]), "Band {$name} muss eine Zahl sein");
    }

    assertTrue($bands['mid'] < $bands['fair'] && $bands['fair'] < $bands['good'],
               'Die Baender muessen aufsteigend geliefert werden');
});

test('statistics: auch ein user bekommt die Baender', function () {
    // Der eigentliche Grund fuer den Weg ueber den Payload: settings ist
    // Admins vorbehalten, die Statistik sehen alle Rollen.
    $bands = rbBands('user');
    assertTrue(isset($bands['mid']), 'Ein user bekommt keine Baender');
});

test('settings: eine geaenderte Schwelle erscheint in der Statistik', function () {
    $vorher = rbBands('admin');

    try {
        assertStatus(200, rbPut('rate_threshold_mid', '25'));
        assertSame(25, rbBands('admin')['mid'], 'Die neue Schwelle kommt nicht an');
    } finally {
        assertStatus(200, rbPut('rate_threshold_mid', (string) $vorher['mid']));
    }

    assertSame($vorher['mid'], rbBands('admin')['mid'], 'Ausgangswert nicht wiederhergestellt');
});

test('settings: Schwellen ausserhalb 1..99 werden abgewiesen', function () {
    foreach (['0', '100', '-5', 'viel', '', '40,5'] as $unbrauchbar) {
        $res = rbPut('rate_threshold_fair', $unbrauchbar);
        assertStatus(400, $res, "Wert '{$unbrauchbar}' haette abgewiesen werden muessen");
    }
});

test('settings: ein Wert mit Leerzeichen wird normalisiert gespeichert', function () {
    $vorher = rbBands('admin');

    try {
        assertStatus(200, rbPut('rate_threshold_fair', ' 55 '));
        assertSame(55, rbBands('admin')['fair'], 'Der Wert kam nicht als Zahl an');
    } finally {
        assertStatus(200, rbPut('rate_threshold_fair', (string) $vorher['fair']));
    }
});
