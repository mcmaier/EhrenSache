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
 * Statische Gegenproben: Terminfarbe als Randakzent statt Badge (OI-94).
 *
 * Spec: docs/superpowers/specs/2026-09-24-terminfarbe-randakzent-design.md
 */

$taRoot = dirname(__DIR__, 2);

function taFile(string $root, string $rel): string
{
    $path = $root . '/' . $rel;
    assertTrue(is_file($path), "{$rel} fehlt");

    return (string) file_get_contents($path);
}

/** Rumpf einer JS-Funktion ab ihrer Signatur bis zur schliessenden Klammer in Spalte 0. */
function taFunctionBody(string $js, string $signature): string
{
    assertSame(1, substr_count($js, $signature), "{$signature} kommt nicht genau einmal vor");
    $start = strpos($js, $signature);
    $next = preg_match('/\n\}/', $js, $m, PREG_OFFSET_CAPTURE, $start) ? $m[0][1] : strlen($js);

    return substr($js, $start, $next - $start);
}

test('safeTypeColor prueft Farben mit einer auf ^# verankerten Hex-Whitelist gueltiger Laenge', function () use ($taRoot) {
    $js = taFile($taRoot, 'public/js/modules/utils.js');
    $body = taFunctionBody($js, 'export function safeTypeColor(');

    // Nicht auf den genauen Wortlaut pruefen (bricht bei jeder harmlosen
    // Umformatierung), sondern das Regex-Literal aus dem Rumpf herausloesen
    // und mit echten Werten befeuern -- das prueft die Absicht: eine auf ^#
    // verankerte, nur mit $ abgeschlossene Hex-Whitelist.
    assertTrue((bool) preg_match('/\/\^#[^\/]+\/i/', $body, $m),
        'Whitelist-Regex nicht gefunden (erwartet: auf ^# verankert, mit $ abgeschlossen, Hexstellen)');
    // JS-Regex-Literal (/.../i) als PCRE-Pattern weiterverwenden -- Zeichen-
    // klassen und Quantoren sind zwischen JS und PCRE hier identisch.
    $phpPattern = '~' . substr($m[0], 1, -2) . '~i';

    // Gueltige CSS-Hex-Notation: 3, 4, 6 oder 8 Stellen.
    foreach (['#abc', '#abcd', '#aabbcc', '#aabbccdd'] as $valid) {
        assertTrue((bool) preg_match($phpPattern, $valid), "{$valid} muss die Whitelist bestehen");
    }
    // Ungueltige CSS-Hex-Laengen (5, 7) und Nicht-Hex-Werte muessen durchfallen --
    // sonst haelt der Browser den Wert fuer sicher, verwirft ihn aber wortlos als
    // ungueltiges CSS und der Termin steht ganz ohne Streifen da (schlechter als
    // die graue Ersatzfarbe).
    foreach (['#abcde', '#abcdefa', '', 'abc', '#gggggg', 'red; background:url(x)'] as $invalid) {
        assertTrue(!preg_match($phpPattern, $invalid), "{$invalid} darf die Whitelist nicht bestehen");
    }

    assertTrue(str_contains($body, 'var(--type-color-none)') || str_contains($body, "'--type-color-none'")
        || str_contains($body, 'type-color-none'),
        'Ungueltige oder fehlende Farbe muss auf die gemeinsame Ersatzfarbe fallen');
});

test('Die Ersatzfarbe steht in variables.css', function () use ($taRoot) {
    $css = taFile($taRoot, 'public/css/variables.css');
    assertTrue((bool) preg_match('/--type-color-none:\s*[^;]+;/', $css),
        'Ersatzfarbe fehlt als Variable');
});

test('Keine hart codierte Ersatzfarbe mehr bei der Terminart', function () use ($taRoot) {
    // #667eea steht in beiden Dateien ausschliesslich als Ersatzfarbe der
    // Terminart (appointments.js:305 und :888, records.js:1832) -- dort darf
    // es dateiweit verschwinden.
    foreach (['public/js/modules/appointments.js', 'public/js/modules/records.js'] as $rel) {
        $js = taFile($taRoot, $rel);
        assertTrue(!str_contains($js, '#667eea'), "{$rel}: #667eea muss der Variablen weichen");
    }

    // #95a5a6 traegt dagegen zwei fremde Dinge: das Schildchen "automatisch
    // angelegt" (appointments.js:277) und die Erfassungsmethode "Auto"
    // (records.js:1054). Beide bleiben -- geprueft wird nur die Terminart.
    $records = taFile($taRoot, 'public/js/modules/records.js');
    $badge = taFunctionBody($records, 'function createAppointmentTypeBadge(');
    assertTrue(!str_contains($badge, '#95a5a6'),
        'Das Schildchen der Terminart muss die gemeinsame Ersatzfarbe nutzen');
});
