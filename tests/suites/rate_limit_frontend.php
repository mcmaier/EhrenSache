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

$rlSource = (string) file_get_contents(dirname(__DIR__, 2) . '/public/api/api.php');

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
    $start = strpos($rlSource, '// 6.2 RATE LIMITING');
    assertTrue($start !== false, 'Abschnitt 6.2 nicht gefunden');
    $block = substr($rlSource, $start, 2400);

    assertTrue(str_contains($block, '$istAngemeldet'),
        'Die Unterscheidung angemeldet/unangemeldet fehlt');
    assertTrue(preg_match('/if\s*\(\s*!\$istAngemeldet\s*\)/', $block) === 1,
        'Die Grenze greift nicht ausdruecklich nur fuer Unangemeldete');
    assertTrue(str_contains($block, 'REMOTE_ADDR'), 'Gezaehlt wird nicht je Adresse');
});

test('Rate-Grenze: ein ungueltiger Token gilt als unangemeldet', function () use ($rlSource) {
    // Sonst liesse sich die Grenze mit immer neuen Zufallstoken umgehen, und
    // das Durchprobieren von Token waere ungebremst.
    $start = strpos($rlSource, '$istAngemeldet =');
    assertTrue($start !== false, 'Zuweisung nicht gefunden');
    $zeile = substr($rlSource, $start, (int) strpos($rlSource, ';', $start) - $start);

    assertTrue(str_contains($zeile, '$tokenUser !== null'),
        'Als angemeldet gilt nicht der gefundene Token-Inhaber, sondern etwas anderes');
    assertTrue(str_contains($zeile, "isset(\$_SESSION['user_id'])"),
        'Die angemeldete Browser-Sitzung fehlt in der Unterscheidung');
});

test('Rate-Grenze: der Token wird nur einmal nachgeschlagen', function () use ($rlSource) {
    assertSame(1, substr_count($rlSource, 'WHERE api_token = ?'),
        'Der Token wird mehrfach aus der Datenbank geholt -- einmal reicht, das Ergebnis wird weitergereicht');
});
