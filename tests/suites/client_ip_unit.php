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
 * Besucheradresse hinter einem Reverse-Proxy oder CDN (private/helpers/client_ip.php).
 *
 * Grundsatz: Ein Weiterleitungs-Header zählt nur, wenn die Verbindung von einer
 * Adresse kommt, die in config.php als vertrauenswürdiger Proxy eingetragen ist.
 * Sonst könnte jeder Besucher den Header selbst setzen und sich je Anfrage eine
 * neue Adresse geben.
 */

require_once __DIR__ . '/../../private/helpers/client_ip.php';

$ciCf = ['173.245.48.0/20', '2400:cb00::/32'];

test('Ohne vertrauenswuerdige Proxys gilt REMOTE_ADDR, Header werden ignoriert', function () {
    $server = [
        'REMOTE_ADDR'           => '203.0.113.7',
        'HTTP_CF_CONNECTING_IP' => '198.51.100.1',
        'HTTP_X_FORWARDED_FOR'  => '198.51.100.2',
    ];
    assertSame('203.0.113.7', clientIp($server, []));
});

test('Header von einer nicht eingetragenen Adresse werden ignoriert', function () use ($ciCf) {
    // Der Fall, gegen den die Liste schuetzt: ein Besucher setzt den Header selbst.
    $server = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'];
    assertSame('203.0.113.7', clientIp($server, $ciCf));
});

test('Hinter einem eingetragenen Proxy gilt CF-Connecting-IP', function () use ($ciCf) {
    $server = ['REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'];
    assertSame('198.51.100.1', clientIp($server, $ciCf));
});

test('IPv6-Proxy und IPv6-Besucher', function () use ($ciCf) {
    $server = ['REMOTE_ADDR' => '2400:cb00:2049::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::42'];
    assertSame('2001:db8::42', clientIp($server, $ciCf));
});

test('Ohne CF-Header gilt der rechteste nicht vertrauenswuerdige Eintrag aus X-Forwarded-For', function () use ($ciCf) {
    // Links stehen Angaben, die der Besucher selbst mitschicken kann; rechts haengt
    // jeder Proxy die Adresse an, von der er die Anfrage bekam.
    $server = [
        'REMOTE_ADDR'          => '173.245.48.10',
        'HTTP_X_FORWARDED_FOR' => '10.9.9.9, 198.51.100.5, 173.245.48.11',
    ];
    assertSame('198.51.100.5', clientIp($server, $ciCf));
});

test('Ungueltige Headerwerte fallen auf REMOTE_ADDR zurueck', function () use ($ciCf) {
    foreach (['nonsense', '', '198.51.100.1, x', '999.1.1.1'] as $wert) {
        $server = ['REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => $wert];
        assertSame('173.245.48.10', clientIp($server, $ciCf), "Wert '{$wert}'");
    }
});

test('Einzeladresse ohne Praefix wird als exakte Adresse verglichen', function () {
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'];
    assertSame('198.51.100.9', clientIp($server, ['127.0.0.1']));
    assertSame('127.0.0.2', clientIp(['REMOTE_ADDR' => '127.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'], ['127.0.0.1']));
});

test('ipInRanges: Grenzen eines Bereichs', function () {
    assertTrue(ipInRanges('173.245.48.0', ['173.245.48.0/20']));
    assertTrue(ipInRanges('173.245.63.255', ['173.245.48.0/20']));
    assertTrue(!ipInRanges('173.245.64.0', ['173.245.48.0/20']));
    assertTrue(!ipInRanges('2400:cb01::1', ['2400:cb00::/32']));
    // Familien mischen sich nicht.
    assertTrue(!ipInRanges('173.245.48.1', ['2400:cb00::/32']));
    assertTrue(!ipInRanges('kaputt', ['173.245.48.0/20']));
});

test('Fehlendes REMOTE_ADDR liefert unknown', function () {
    assertSame('unknown', clientIp([], ['127.0.0.1']));
});

test('Beide Zaehlstellen lesen die Adresse ueber currentClientIp()', function () {
    // Der Rate Limiter zaehlt an zwei Stellen nach Adresse. Liest eine davon
    // REMOTE_ADDR direkt, zaehlt sie hinter einem Proxy alle Besucher zusammen.
    $root = dirname(__DIR__, 2);
    foreach (['public/api/api.php', 'private/helpers/rate_limiter.php'] as $rel) {
        $code = sourceCode($root . '/' . $rel);
        assertTrue(!str_contains($code, "\$_SERVER['REMOTE_ADDR']"), "{$rel} liest REMOTE_ADDR direkt");
        assertTrue(str_contains($code, 'currentClientIp()'), "{$rel} nutzt currentClientIp() nicht");
    }
});
