<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/totp.php';
require_once __DIR__ . '/../../private/helpers/rate_limiter.php';

// ---- totpCodesForSecret --------------------------------------------------
// RFC 6238, Anhang B: Secret "12345678901234567890" (Base32
// GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ), SHA1, T = 59 s → 94287082, sechsstellig 287082.
const STATION_RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

test('totpCodesForSecret liefert den RFC-6238-Code fuer T=59', function () {
    $codes = totpCodesForSecret(STATION_RFC_SECRET, 59);
    assertSame('287082', $codes['code']);
});

test('totpCodesForSecret nennt das Fensterende und die Periode', function () {
    $codes = totpCodesForSecret(STATION_RFC_SECRET, 59);
    assertSame(60, $codes['valid_until']);
    assertSame(30, $codes['period']);
});

test('totpCodesForSecret liefert als next_code den Code des Folgefensters', function () {
    $codes = totpCodesForSecret(STATION_RFC_SECRET, 59);
    // Verifiziert mit: (new TOTP('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'))->getCode(60)
    assertSame('359152', $codes['next_code']);
    assertSame('359152', totpCodesForSecret(STATION_RFC_SECRET, 89)['code'], 'Folgefenster reicht bis 89 s');
});

test('totpCodesForSecret an der Fenstergrenze T=60', function () {
    $codes = totpCodesForSecret(STATION_RFC_SECRET, 60);
    assertSame('359152', $codes['code']);
    assertSame(90, $codes['valid_until']);
});

test('totpCodesForSecret ohne Zeitstempel nimmt die Serverzeit', function () {
    $before = time();
    $codes  = totpCodesForSecret(STATION_RFC_SECRET);
    assertTrue(preg_match('/^\d{6}$/', $codes['code']) === 1, 'sechs Ziffern erwartet');
    assertTrue($codes['valid_until'] > $before && $codes['valid_until'] <= $before + 30, 'valid_until liegt im naechsten Fenster');
});

// ---- RateLimiter::reset ---------------------------------------------------
// Session-Modus: ohne PDO zaehlt der Limiter in $_SESSION. Im CLI ist das ein
// gewoehnliches Array — genau richtig fuer einen Test ohne Datenbank.

test('RateLimiter::reset hebt eine erreichte Sperre auf', function () {
    $_SESSION = [];
    $limiter  = new RateLimiter();

    assertTrue($limiter->check('m1', 'station_pin', 2, 900));
    assertTrue($limiter->check('m1', 'station_pin', 2, 900));
    assertSame(false, $limiter->check('m1', 'station_pin', 2, 900), 'dritter Versuch muss scheitern');

    $limiter->reset('m1', 'station_pin');
    assertTrue($limiter->check('m1', 'station_pin', 2, 900), 'nach reset wieder erlaubt');
});

test('RateLimiter::reset trifft nur das genannte Kennzeichen', function () {
    $_SESSION = [];
    $limiter  = new RateLimiter();

    $limiter->check('a', 'station_pin', 1, 900);
    $limiter->check('b', 'station_pin', 1, 900);
    $limiter->reset('a', 'station_pin');

    assertTrue($limiter->check('a', 'station_pin', 1, 900));
    assertSame(false, $limiter->check('b', 'station_pin', 1, 900));
});

test('RateLimiter::reset trifft nur die genannte Action, nicht andere Actions desselben Kennzeichens', function () {
    $_SESSION = [];
    $limiter  = new RateLimiter();

    assertTrue($limiter->check('x', 'a1', 1, 900));
    assertSame(false, $limiter->check('x', 'a1', 1, 900), 'a1 bereits ausgeschoepft');
    assertTrue($limiter->check('x', 'a2', 1, 900));
    assertSame(false, $limiter->check('x', 'a2', 1, 900), 'a2 bereits ausgeschoepft');

    $limiter->reset('x', 'a1');

    assertTrue($limiter->check('x', 'a1', 1, 900), 'a1 nach reset wieder erlaubt');
    assertSame(false, $limiter->check('x', 'a2', 1, 900), 'a2 bleibt gesperrt');
});
