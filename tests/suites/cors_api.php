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
 * OI-126: Die API sendet keine CORS-Header.
 *
 * Dashboard, Check-in-App und Station laufen auf demselben Ursprung wie die API,
 * das IoT-Terminal ist kein Browser. Bis 1.22.x setzte api.php trotzdem
 * Access-Control-Allow-Origin: * zusammen mit Allow-Credentials: true -- eine
 * Kombination, die Browser verwerfen. Die Suite haelt fest, dass keiner der vier
 * Koepfe zurueckkehrt und OPTIONS (wie jede andere unbekannte Methode) mit 405
 * als JSON abgewiesen wird -- weder 5xx noch ein leeres 200.
 */
require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * Roher Abruf mit allen Antwortkoepfen (Namen klein geschrieben).
 *
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function corsFetch(string $method, string $query, ?string $token = null, array $extraHeaders = []): array
{
    $url     = rtrim(testConfig()['base_url'], '/') . '/api/api.php?' . $query;
    $headers = [];
    $ch      = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $send = array_merge(['Accept: application/json'], $extraHeaders);
    if ($token !== null) {
        $send[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $send);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $h) use (&$headers): int {
        $teile = explode(':', $h, 2);
        if (count($teile) === 2) {
            $headers[strtolower(trim($teile[0]))] = trim($teile[1]);
        }

        return strlen($h);
    });
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('Abruf fehlgeschlagen: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body, 'headers' => $headers];
}

/** @param array{headers: array<string, string>} $res */
function corsAssertNone(array $res, string $was): void
{
    foreach (['access-control-allow-origin', 'access-control-allow-credentials',
              'access-control-allow-methods', 'access-control-allow-headers'] as $kopf) {
        assertTrue(!isset($res['headers'][$kopf]), "{$was} traegt {$kopf}: " . ($res['headers'][$kopf] ?? ''));
    }
}

test('CORS: ping (oeffentlich) ohne Access-Control-Header', function () {
    $res = corsFetch('GET', 'resource=ping', null, ['Origin: https://fremde-seite.example']);
    assertSame(200, $res['status']);
    corsAssertNone($res, 'ping');
});

test('CORS: angemeldeter Abruf ohne Access-Control-Header', function () {
    $res = corsFetch('GET', 'resource=members', apiToken('admin'), ['Origin: https://fremde-seite.example']);
    assertSame(200, $res['status']);
    corsAssertNone($res, 'GET members (Token)');
});

test('CORS: abgewiesener Abruf (401) ohne Access-Control-Header', function () {
    $res = corsFetch('GET', 'resource=members', null, ['Origin: https://fremde-seite.example']);
    assertTrue(in_array($res['status'], [401, 403], true), "401/403 erwartet, {$res['status']} erhalten");
    corsAssertNone($res, '401');
});

test('CORS: OPTIONS (Preflight) und andere fremde Methoden antworten 405 als JSON, ohne Access-Control-Header', function () {
    // Bis 1.22.x beantwortete ein Sonderzweig OPTIONS mit leerem 200. Ohne ihn
    // lieferten Handler ohne default-Zweig (members, users, ...) ebenfalls ein
    // leeres 200 -- deshalb weist api.php fremde Methoden vor allem anderen ab.
    $preflight = ['Origin: https://fremde-seite.example', 'Access-Control-Request-Method: POST'];
    foreach ([
        ['OPTIONS', 'resource=members', null],
        ['OPTIONS', 'resource=members', apiToken('admin')],
        ['OPTIONS', 'resource=users', apiToken('admin')],
        ['OPTIONS', 'resource=ping', null],
        ['OPTIONS', '', null],
        ['PATCH', 'resource=members', apiToken('admin')],
    ] as [$method, $query, $token]) {
        $res = corsFetch($method, $query, $token, $preflight);
        $was = "{$method} ?{$query}" . ($token !== null ? ' (Token)' : '');
        assertSame(405, $res['status'], "{$was}: " . substr($res['body'], 0, 200));
        assertSame(['message' => 'Method not allowed'], json_decode($res['body'], true), "{$was}: Rumpf");
        assertSame('GET, HEAD, POST, PUT, DELETE', $res['headers']['allow'] ?? null, "{$was}: Allow-Kopf");
        corsAssertNone($res, $was);
    }
});
