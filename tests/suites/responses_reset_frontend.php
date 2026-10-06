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
 * Rueckfrage zu Rueckmeldungen beim Verlegen eines Termins (OI-124), Quelltext.
 *
 * Der Server fragt per 409 responses_affected zurueck. Ein Client, der das nicht
 * behandelt, zeigt den Nutzern nur "Konflikt" und kann keinen Termin mit Zusagen
 * mehr verlegen. Deshalb hier fuer beide Clients, die Termine bearbeiten.
 */
declare(strict_types=1);

$rrfRoot = dirname(__DIR__, 2);

/** Rumpf einer JS-Funktion ab ihrer Deklaration bis zur naechsten Top-Level-Funktion. */
function rrfFunctionBody(string $js, string $name): string
{
    $start = strpos($js, "function {$name}(");
    assertTrue($start !== false, "Funktion {$name} fehlt");
    $next = preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 10)
        ? $m[0][1] : strlen($js);

    return substr($js, $start, $next - $start);
}

test('Dashboard: saveAppointment fragt bei responses_affected und wiederholt mit reset_responses', function () use ($rrfRoot) {
    $js = sourceCode($rrfRoot . '/public/js/modules/appointments.js');

    $helper = rrfFunctionBody($js, 'putAskingAboutResponses');
    assertTrue(str_contains($helper, "'responses_affected'"), 'Code der Rueckfrage wird nicht geprueft');
    assertTrue(str_contains($helper, 'reset_responses'), 'Wiederholung ohne reset_responses');
    assertTrue(str_contains($helper, 'silentStatuses: [409]'), 'Rueckfrage erschiene als Fehler-Toast');
    assertTrue(str_contains($helper, 'showChoice('), 'keine Rueckfrage an den Nutzer');

    $save = rrfFunctionBody($js, 'saveAppointment');
    assertTrue(str_contains($save, "putAskingAboutResponses('appointments'"), 'Einzel-PUT ohne Rueckfrage');
    assertTrue(str_contains($save, "putAskingAboutResponses('appointment_series'"), 'Serien-PUT ohne Rueckfrage');
    assertTrue(!preg_match("/apiCall\\('appointments',\\s*'PUT'/", $save), 'direkter PUT an der Rueckfrage vorbei');
});

test('Check-in-App: submitAppointmentForm fragt bei responses_affected und wiederholt mit reset_responses', function () use ($rrfRoot) {
    $js = sourceCode($rrfRoot . '/public/checkin/js/app.js');

    $submit = rrfFunctionBody($js, 'submitAppointmentForm');
    assertTrue(str_contains($submit, "'responses_affected'"), 'Code der Rueckfrage wird nicht geprueft');
    assertTrue(str_contains($submit, 'askResponsesReset('), 'keine Rueckfrage an den Nutzer');
    assertTrue(str_contains($submit, 'reset_responses'), 'Wiederholung ohne reset_responses');

    $ask = rrfFunctionBody($js, 'askResponsesReset');
    assertTrue(!preg_match('/\son[a-z]+\s*=\s*"/i', $ask), 'Inline-Handler im Modal (CSP)');
    assertTrue(str_contains($ask, '.textContent'), 'Meldung muss als Text gesetzt werden');
});
