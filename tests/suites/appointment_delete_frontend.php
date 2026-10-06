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
 * Loeschen eines Termins im Dashboard nennt, was mit verloren geht (Folge aus
 * OI-125): Erfassungen, Rueckmeldungen, Antraege -- aus
 * GET appointments?id=X&dependents=1. Gilt fuer den Einzeltermin und fuer
 * „Nur dieser“ bei Serien; „Dieser und alle folgenden“ zaehlt der Server nicht.
 */

$adlRoot = dirname(__DIR__, 2);

/** Rumpf von function $name( bis zur naechsten Funktion auf oberster Ebene. */
function adlFunktion(string $js, string $name): string
{
    $start = strpos($js, 'function ' . $name . '(');
    assertTrue($start !== false, $name . '() nicht gefunden');

    if (preg_match('/\n(?:export\s+)?(?:async\s+)?function\s/', $js, $m, PREG_OFFSET_CAPTURE, $start + 1)) {
        return substr($js, $start, $m[0][1] - $start);
    }
    return substr($js, $start);
}

function adlAppointmentsJs(string $root): string
{
    return (string) sourceCode($root . '/public/js/modules/appointments.js');
}

test('Dashboard: Loeschen holt die Zaehlung vor jeder Rueckfrage', function () use ($adlRoot) {
    $rumpf = adlFunktion(adlAppointmentsJs($adlRoot), 'deleteAppointment');
    $zaehlen = strpos($rumpf, 'appointmentDeleteConsequences(');
    $wahl    = strpos($rumpf, 'showChoice(');
    $frage   = strpos($rumpf, 'showConfirm(');
    assertTrue($zaehlen !== false && $wahl !== false && $frage !== false,
        'Zaehlung, Serienauswahl oder Rueckfrage fehlt');
    assertTrue($zaehlen < $wahl && $zaehlen < $frage, 'Die Zahlen muessen vor der ersten Frage feststehen');
});

test('Dashboard: beide Rueckfragen tragen die Folgen', function () use ($adlRoot) {
    $rumpf = adlFunktion(adlAppointmentsJs($adlRoot), 'deleteAppointment');
    // Der Satz steht in beiden Meldungen: Auswahl bei Serien und Einzelrueckfrage.
    assertTrue((bool) preg_match('/showChoice\(\s*`[^`]*\$\{[^}]*folgen[^}]*\}[^`]*`/', $rumpf),
        'Die Serienauswahl nennt nicht, was an diesem Termin haengt');
    assertTrue((bool) preg_match('/showConfirm\(\s*`[^`]*\$\{[^}]*folgen[^}]*\}[^`]*`/', $rumpf),
        'Die Rueckfrage zum Einzeltermin nennt die Folgen nicht');
});

test('Dashboard: Zaehlung fragt dependents ab und schweigt bei Fehlern', function () use ($adlRoot) {
    $rumpf = adlFunktion(adlAppointmentsJs($adlRoot), 'appointmentDeleteConsequences');
    assertTrue(str_contains($rumpf, 'dependents: 1'), 'dependents wird nicht angefordert');
    assertTrue(str_contains($rumpf, 'silentStatuses'), 'Ein gescheiterter Abruf darf keinen Fehler-Toast zeigen');
    assertTrue(str_contains($rumpf, "return ''") || str_contains($rumpf, 'return null'),
        'Ohne Zahlen muss die Rueckfrage wie bisher funktionieren');
});

test('Dashboard: Text nennt Erfassungen, Rueckmeldungen und Antraege mit Einzahl', function () use ($adlRoot) {
    $rumpf = adlFunktion(adlAppointmentsJs($adlRoot), 'appointmentDependentsList');
    foreach (["'1 Erfassung'", 'Erfassungen', "'1 Rückmeldung'", 'Rückmeldungen', "'1 Antrag'", 'Anträge'] as $wort) {
        assertTrue(str_contains($rumpf, $wort), "{$wort} fehlt");
    }
    // Erfassungen zuerst: Sie sind die eigentlichen Daten des Vereins.
    assertTrue(strpos($rumpf, 'Erfassung') < strpos($rumpf, 'Rückmeldung'), 'Erfassungen gehoeren an den Anfang');
});
