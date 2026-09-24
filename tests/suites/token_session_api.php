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
 * Token-Aufrufe und Browser-Sitzungen (OI-25).
 *
 * Ein Aufruf mit Bearer-Token legte bis 1.13.0 eine vollwertige Sitzung an und
 * ueberschrieb dabei eine vorhandene Anmeldung im selben Browser. Der naechste
 * Aufruf nur mit Cookie lief dann in 401 "Token-created session cannot be used
 * without the token" -- aus Sicht des Nutzers ein grundloser Rauswurf.
 *
 * Die Schutzrichtung bleibt: Ein Token darf niemals Rechte einer fremden
 * Sitzung erben, und ein Sitzungscookie aus einem Token-Aufruf darf keine Tuer
 * oeffnen. Beides pruefen die Tests unten mit.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** Meldet sich wie das Dashboard an und liefert das gueltige Sitzungscookie. */
function tsLoginCookie(string $role = 'admin'): string
{
    $cfg = testConfig();
    $res = apiRequest('POST', 'login', ['body' => [
        'email' => $cfg[$role]['email'], 'password' => $cfg[$role]['password'],
    ]]);
    assertStatus(200, $res, "Anmeldung als {$role} fehlgeschlagen");
    assertTrue(!empty($res['set_cookie']), 'Anmeldung lieferte kein Set-Cookie');

    return explode(';', (string) $res['set_cookie'])[0];
}

test('Token-Aufruf laesst eine angemeldete Browser-Sitzung unberuehrt (OI-25)', function () {
    $cookie = tsLoginCookie();
    assertStatus(200, apiRequest('GET', 'me', ['cookie' => $cookie]),
        'Vorbedingung: die Sitzung traegt');

    // Derselbe Browser schickt Cookie UND Token -- etwa ein Aufruf mit
    // ?api_token= in der Adressleiste oder ein Fremdwerkzeug im selben Profil.
    assertStatus(200, apiRequest('GET', 'me', ['cookie' => $cookie, 'token' => apiToken('admin')]));

    $nachher = apiRequest('GET', 'me', ['cookie' => $cookie]);
    assertStatus(200, $nachher,
        'Der Token-Aufruf hat die Dashboard-Sitzung zerstoert: ' . $nachher['raw']);
});

test('Token-Aufruf setzt gar kein Sitzungscookie (OI-25)', function () {
    $res = apiRequest('GET', 'me', ['token' => apiToken('admin')]);
    assertStatus(200, $res);
    assertSame(null, $res['set_cookie'],
        'Ein Token-Aufruf darf keine Sitzung anlegen, also auch kein Cookie setzen');
});

test('Token erbt keine Rechte aus einer fremden Sitzung (OI-25)', function () {
    // Schutzrichtung der urspruenglichen Loesung: Ein Geraete- oder
    // Mitgliedstoken darf nicht mehr duerfen, nur weil im selben Browser ein
    // Admin angemeldet ist.
    $adminCookie = tsLoginCookie('admin');
    $res = apiRequest('GET', 'users', ['cookie' => $adminCookie, 'token' => apiToken('user')]);
    assertStatus(403, $res,
        'Mit user-Token und Admin-Cookie wurde die Benutzerverwaltung geoeffnet: ' . $res['raw']);
});

test('Sitzung und Token nebeneinander bleiben getrennt (OI-25)', function () {
    // Die Sitzung gehoert dem Admin, der Token dem Mitglied: Der Aufruf MIT
    // Token wird als Mitglied bedient, der Aufruf OHNE weiterhin als Admin.
    $adminCookie = tsLoginCookie('admin');
    $cfg = testConfig();

    $alsMitglied = apiRequest('GET', 'me', ['cookie' => $adminCookie, 'token' => apiToken('user')]);
    assertStatus(200, $alsMitglied);
    assertSame($cfg['user']['email'], $alsMitglied['body']['email'],
        'Der Token bestimmt, wer bedient wird -- nicht das Cookie');

    $alsAdmin = apiRequest('GET', 'me', ['cookie' => $adminCookie]);
    assertStatus(200, $alsAdmin);
    assertSame($cfg['admin']['email'], $alsAdmin['body']['email'],
        'Ohne Token gilt wieder die Sitzung des Browsers');
});
