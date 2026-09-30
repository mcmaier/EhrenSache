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
 * Maskierung in Check-in-PWA und Station (1.11.3): statische Gegenprobe.
 *
 * Ohne CSP (OI-17) muss jede Oberflaeche selbst maskieren. Der Verlauf der PWA
 * setzte Termintitel und Terminart ungeschuetzt in innerHTML ein -- ein Titel,
 * den ein Manager setzt, lief damit im Browser jedes Mitglieds, auch des
 * Admins.
 *
 * Geprueft wird jede Einsetzung eines Textfelds aus der Datenbank. Erlaubt ist
 * sie nur in escapeHtml() oder in einer Zeile, die textContent setzt.
 */

$peRoot = dirname(__DIR__, 2);

/** Felder, die aus der Datenbank kommen und frei beschreibbar sind. */
const PE_TEXT_FIELDS = 'title|appointment_title|appointment_type_name|type_name|group_name|groupName'
    . '|activity_name|description|location|note|reason|comment|device_name|location_name';

/** @return string[] "Datei:Zeile: Inhalt" jeder ungeschuetzten Einsetzung */
function peUnescaped(string $file): array
{
    $funde = [];
    $zeilen = sourceLines($file);

    foreach ($zeilen as $nr => $zeile) {
        // Nur Zeilen mit Markup: Text fuer textContent oder showMessage()
        // braucht keine Maskierung.
        if (str_contains($zeile, 'textContent') || !str_contains($zeile, '<')) {
            continue;
        }
        if (preg_match_all('/\$\{([^}]*)\}/', $zeile, $m)) {
            foreach ($m[1] as $ausdruck) {
                // Ein Funktionsaufruf in der Einsetzung (escapeHtml,
                // responseLocationHtml, ...) ist fuer seine Maskierung selbst
                // verantwortlich.
                if (str_contains($ausdruck, '(')) {
                    continue;
                }
                // Direkter Feldzugriff wie record.title oder data.appointment?.title
                if (preg_match('/\??\.(' . PE_TEXT_FIELDS . ')\b/', $ausdruck)) {
                    $funde[] = basename(dirname($file, 2)) . '/' . basename($file) . ':' . ($nr + 1) . ': ' . trim($zeile);
                }
            }
        }
    }

    return $funde;
}

// Das Dashboard war bei der Durchsicht fuer 1.11.3 sauber; es steht hier,
// damit es so bleibt.
$peDateien = array_merge(
    ['public/checkin/js/app.js', 'public/station/js/app.js'],
    array_map(static fn ($f) => 'public/js/modules/' . basename($f), glob($peRoot . '/public/js/modules/*.js') ?: [])
);

foreach ($peDateien as $datei) {
    test("Keine ungeschuetzten Textfelder in innerHTML: {$datei}", function () use ($peRoot, $datei) {
        $funde = peUnescaped($peRoot . '/' . $datei);
        assertSame([], $funde, "Ungeschuetzte Einsetzung:\n" . implode("\n", $funde));
    });
}

test('Terminfarben gehen nur als Hexwert in ein style-Attribut', function () use ($peRoot) {
    $js = (string) sourceCode($peRoot . '/public/checkin/js/app.js');

    foreach (['getTypeColor', 'activityDot'] as $funktion) {
        $start = strpos($js, "function {$funktion}(");
        assertTrue($start !== false, "{$funktion}() nicht gefunden");
        $rumpf = substr($js, $start, (strpos($js, "\n}", $start) ?: $start + 800) - $start);

        assertTrue(str_contains($rumpf, 'safeHexColor('),
            "{$funktion}() gibt eine Farbe aus der Datenbank ungeprueft weiter");
    }
});

test('PWA prueft Farben wie das Dashboard und nutzt dieselbe Ersatzfarbe (OI-108)', function () use ($peRoot) {
    $js = (string) sourceCode($peRoot . '/public/checkin/js/app.js');
    $start = strpos($js, 'function safeHexColor(');
    assertTrue($start !== false, 'safeHexColor() nicht gefunden');
    $rumpf = substr($js, $start, (int) strpos($js, "\n}", $start) - $start);

    // Nur gueltige CSS-Hexlaengen 3, 4, 6, 8 -- 5 und 7 verwirft der Browser
    // wortlos, der Randakzent fehlte dann ganz statt grau zu erscheinen.
    assertTrue(preg_match('/\/(\^#[^\/]+)\/i/', $rumpf, $m) === 1, 'Hex-Pruefung nicht gefunden');
    $re = '/' . $m[1] . '/i';
    foreach (['#abc', '#abcd', '#1F5FBF', '#1f5fbfcc'] as $gut) {
        assertTrue(preg_match($re, $gut) === 1, "{$gut} wird abgelehnt");
    }
    foreach (['#abcde', '#1F5FBF0', 'red', '#12', '#1F5FBF; x:y'] as $schlecht) {
        assertTrue(preg_match($re, $schlecht) !== 1, "{$schlecht} wird angenommen");
    }
    assertTrue(str_contains($rumpf, "'var(--type-color-none)'"),
        'safeHexColor() faellt nicht auf die gemeinsame Ersatzfarbe zurueck');

    // Keine eigenen Ersatzfarben mehr neben der gemeinsamen
    assertTrue(!str_contains($js, "'#95a5a6'"), 'app.js fuehrt noch die Ersatzfarbe #95a5a6');
    assertTrue(preg_match("/safeHexColor\([^)]*,/", $js) !== 1,
        'safeHexColor() wird noch mit eigener Ersatzfarbe aufgerufen');

    // Derselbe Wert wie im Dashboard -- die PWA laedt variables.css nicht
    $pwaCss = (string) sourceCode($peRoot . '/public/checkin/css/style.css');
    $dashCss = (string) sourceCode($peRoot . '/public/css/variables.css');
    assertTrue(preg_match('/--type-color-none:\s*([^;]+);/', $dashCss, $d) === 1, 'variables.css ohne --type-color-none');
    assertTrue(preg_match('/--type-color-none:\s*([^;]+);/', $pwaCss, $p) === 1, 'checkin/css/style.css ohne --type-color-none');
    assertSame(strtolower(trim($d[1])), strtolower(trim($p[1])), 'Ersatzfarbe der PWA weicht vom Dashboard ab');
});
