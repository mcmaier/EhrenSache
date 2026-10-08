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
 * FI-24: Freigaben in der Check-in-App — statische Gegenproben.
 * Laufzeitverhalten prueft der Browser-Durchgang (Task 5).
 */

function prJs(): string
{
    return (string) sourceCode(dirname(__DIR__, 2) . '/public/checkin/js/app.js');
}

function prFunktion(string $name): string
{
    $js    = prJs();
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');
    if (preg_match('/
(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }

    return substr($js, $start);
}

test('Antragszeile ist eine gemeinsame Funktion, die Liste nutzt sie', function () {
    $zeile = prFunktion('requestItemHtml');
    assertTrue(str_contains($zeile, 'escapeHtml('), 'Antragszeile maskiert nicht');
    assertTrue(str_contains($zeile, 'btn-request-decide'), 'Antragszeile hat keine Entscheidungsknoepfe');
    assertTrue(str_contains($zeile, 'Eigener Antrag'), 'Hinweis fuer den eigenen Antrag fehlt');
    assertTrue(str_contains(prFunktion('attendanceRequestsHtml'), 'requestItemHtml('),
        'Die Anwesenheitsliste nutzt die gemeinsame Antragszeile nicht');
});

test('Zeitantrag zeigt die erfasste Ankunft', function () {
    $zeile = prFunktion('requestItemHtml');
    assertTrue(str_contains($zeile, 'recorded_arrival_time') && str_contains($zeile, 'keine Erfassung'),
        'Erfasste Ankunft bzw. „keine Erfassung“ fehlt');
});
