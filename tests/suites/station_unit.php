<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/totp.php';

// ---- totpCodesForSecret --------------------------------------------------
// RFC 6238, Anhang B: Secret "12345678901234567890" (Base32
// GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ), SHA1, T = 59 s → 94287082, sechsstellig 287082.
const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

test('totpCodesForSecret liefert den RFC-6238-Code fuer T=59', function () {
    $codes = totpCodesForSecret(RFC_SECRET, 59);
    assertSame('287082', $codes['code']);
});

test('totpCodesForSecret nennt das Fensterende und die Periode', function () {
    $codes = totpCodesForSecret(RFC_SECRET, 59);
    assertSame(60, $codes['valid_until']);
    assertSame(30, $codes['period']);
});

test('totpCodesForSecret liefert als next_code den Code des Folgefensters', function () {
    $codes = totpCodesForSecret(RFC_SECRET, 59);
    $totp  = new TOTP(RFC_SECRET);
    assertSame($totp->getCode(60), $codes['next_code']);
    assertSame($totp->getCode(89), $codes['next_code'], 'Folgefenster reicht bis 89 s');
});

test('totpCodesForSecret ohne Zeitstempel nimmt die Serverzeit', function () {
    $codes = totpCodesForSecret(RFC_SECRET);
    assertTrue(preg_match('/^\d{6}$/', $codes['code']) === 1, 'sechs Ziffern erwartet');
    assertTrue($codes['valid_until'] > time() - 1, 'valid_until liegt nicht in der Vergangenheit');
});
