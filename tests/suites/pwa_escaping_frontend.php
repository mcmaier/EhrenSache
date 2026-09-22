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
    $zeilen = file($file, FILE_IGNORE_NEW_LINES) ?: [];

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
    $js = (string) file_get_contents($peRoot . '/public/checkin/js/app.js');

    foreach (['getTypeColor', 'activityDot'] as $funktion) {
        $start = strpos($js, "function {$funktion}(");
        assertTrue($start !== false, "{$funktion}() nicht gefunden");
        $rumpf = substr($js, $start, (strpos($js, "\n}", $start) ?: $start + 800) - $start);

        assertTrue(str_contains($rumpf, 'safeHexColor('),
            "{$funktion}() gibt eine Farbe aus der Datenbank ungeprueft weiter");
    }
});
