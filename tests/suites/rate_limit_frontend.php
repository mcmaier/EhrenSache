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
 * Die globale Rate-Grenze der API (statische Gegenproben).
 *
 * Sie zählte bis 1.13.0 in der Sitzung: `new RateLimiter()` ohne Datenbank.
 * Wer keine Cookies annimmt, bekam bei jeder Anfrage eine frische, leere
 * Sitzung -- der Zähler stand immer bei null, die Grenze hat also niemanden
 * gebremst (belegt mit 170 Aufrufen ohne einen einzigen 429). Anmeldung,
 * Stations-PIN und Mailversand waren nie betroffen, die zählen schon immer in
 * der Datenbank.
 *
 * Das tatsächliche Sperren prüft `tests/db/verify_rate_limit_global.php` gegen
 * eine echte Datenbank; hier steht, was sich in der Quelle nicht wieder
 * zurückdrehen darf.
 */
declare(strict_types=1);

$rlSource = (string) sourceCode(dirname(__DIR__, 2) . '/public/api/api.php');

test('Rate-Grenze: der Limiter der API bekommt die Datenbank', function () use ($rlSource) {
    assertTrue(preg_match('/\$rateLimiter\s*=\s*new RateLimiter\(\s*\$db\s*,\s*\$database\s*\)/', $rlSource) === 1,
        'api.php baut den Limiter wieder ohne Datenbank -- dann zaehlt er in der Sitzung und bremst niemanden');
    assertSame(0, substr_count($rlSource, 'new RateLimiter()'),
        'irgendwo steht wieder ein Limiter ohne Datenbank');
});

test('Rate-Grenze: sie steht hinter dem Datenbankaufbau', function () use ($rlSource) {
    $dbConnect = strpos($rlSource, '$db = $database->getConnection();');
    $limiter   = strpos($rlSource, '$rateLimiter = new RateLimiter(');
    assertTrue($dbConnect !== false && $limiter !== false, 'Stellen nicht gefunden');
    assertTrue($dbConnect < $limiter,
        'Der Limiter steht wieder vor der Datenbankverbindung und kann sie nicht nutzen');
});

test('Rate-Grenze: sie zaehlt unangemeldete Aufrufe je Adresse', function () use ($rlSource) {
    // Verankert am Code, nicht an der Ueberschrift "// 6.2 RATE LIMITING":
    // Bis OI-107 las der Test 3600 Zeichen ab diesem Kommentar -- ueberwiegend
    // Erklaerungstext, in dem $istAngemeldet und REMOTE_ADDR ebenfalls stehen.
    // Jetzt muss der Zaehlaufruf im Rumpf von if (!$istAngemeldet) liegen.
    assertTrue(preg_match('/^([ \t]*)if\s*\(\s*!\$istAngemeldet\s*\)\s*\{/m', $rlSource, $m, PREG_OFFSET_CAPTURE) === 1,
        'Die Grenze greift nicht ausdruecklich nur fuer Unangemeldete');
    $start = $m[0][1];
    $end   = strpos($rlSource, "\n" . $m[1][0] . '}', $start + 1);
    assertTrue($end !== false, 'Ende des Blocks if (!$istAngemeldet) nicht gefunden');
    $block = substr($rlSource, $start, $end - $start);

    assertTrue(preg_match('/\$rateLimiter->check\(\s*\$_SERVER\[\'REMOTE_ADDR\'\]/', $block) === 1,
        'Gezaehlt wird nicht je Adresse, oder nicht innerhalb von if (!$istAngemeldet)');
});

test('Rate-Grenze: ein unbekannter Token gilt als unangemeldet', function () use ($rlSource) {
    // Sonst liesse sich die Grenze mit immer neuen Zufallstoken umgehen, und
    // das Durchprobieren von Token waere ungebremst.
    $start = strpos($rlSource, '$tokenTraegt =');
    assertTrue($start !== false, 'Zuweisung $tokenTraegt nicht gefunden');
    $block = substr($rlSource, $start, (int) strpos($rlSource, ';', $start) - $start);
    assertTrue(str_contains($block, '$tokenUser !== null'),
        'Ein unbekannter Token faellt nicht auf "unangemeldet" zurueck');

    $start = strpos($rlSource, '$istAngemeldet =');
    $zeile = substr($rlSource, $start, (int) strpos($rlSource, ';', $start) - $start);
    assertTrue(str_contains($zeile, "isset(\$_SESSION['user_id'])"),
        'Die angemeldete Browser-Sitzung fehlt in der Unterscheidung');
});

test('Rate-Grenze: der Token wird nur einmal nachgeschlagen', function () use ($rlSource) {
    assertSame(1, substr_count($rlSource, 'WHERE api_token = ?'),
        'Der Token wird mehrfach aus der Datenbank geholt -- einmal reicht, das Ergebnis wird weitergereicht');
});

test('Rate-Grenze: ein inaktiver oder abgelaufener Token gilt nicht als angemeldet', function () use ($rlSource) {
    // Dass eine Zeile zum Token existiert, genuegt nicht: is_active und das
    // Ablaufdatum entscheiden mit. Sonst koennte ein ausgetretenes Mitglied
    // oder ein ausgemustertes Geraet die API ungebremst anfragen -- der Aufruf
    // laeuft zwar in 401, aber eben beliebig oft (belegt am 2026-09-24 mit
    // 160 Aufrufen ohne einen einzigen 429).
    $start = strpos($rlSource, '$tokenTraegt =');
    assertTrue($start !== false, 'Die Pruefung $tokenTraegt fehlt');
    $block = substr($rlSource, $start, (int) strpos($rlSource, ';', $start) - $start);

    assertTrue(str_contains($block, "is_active"), 'is_active wird nicht geprueft');
    assertTrue(str_contains($block, 'api_token_expires_at'), 'Das Ablaufdatum wird nicht geprueft');
    assertTrue(str_contains($rlSource, '$istAngemeldet = $tokenTraegt'),
        'Die Grenze haengt nicht an der vollstaendigen Pruefung');
});
