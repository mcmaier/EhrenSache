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
 * Ort und Ende eines Termins (FI-23): Pruefung und Normalisierung.
 *
 * API (POST/PUT) und CSV-Import teilen sich diese Regeln. Ein Ende gleich
 * dem Beginn ist fast immer ein Tippfehler; ein Ende vor dem Beginn ist
 * gueltig und meint den Folgetag.
 */

require_once __DIR__ . '/../../private/helpers/appointment_details.php';
// appointmentNormalizeCore() nutzt die Datums- und Terminartregeln der Serien.
require_once __DIR__ . '/../../private/helpers/recurrence.php';
require_once __DIR__ . '/../../private/helpers/appointment_series.php';

test('Ort: null und Leerstring werden zu null', function () {
    assertSame([null, null], appointmentNormalizeLocation(null));
    assertSame([null, null], appointmentNormalizeLocation(''));
    assertSame([null, null], appointmentNormalizeLocation('   '));
});

test('Ort wird getrimmt', function () {
    assertSame(['Stadthalle', null], appointmentNormalizeLocation('  Stadthalle '));
});

test('Ort: 200 Zeichen gehen, 201 nicht -- auch mit Umlauten', function () {
    assertSame([str_repeat('ä', 200), null], appointmentNormalizeLocation(str_repeat('ä', 200)));
    [$wert, $fehler] = appointmentNormalizeLocation(str_repeat('ä', 201));
    assertSame(null, $wert);
    assertTrue(is_string($fehler), 'Ueberlanger Ort muss einen Fehler liefern');
});

test('Ort: kein Text ist ein Fehler', function () {
    [, $fehler] = appointmentNormalizeLocation(['x']);
    assertTrue(is_string($fehler));
});

test('Ende: null und Leerstring werden zu null', function () {
    assertSame([null, null], appointmentNormalizeEndTime(null, '19:00'));
    assertSame([null, null], appointmentNormalizeEndTime('', '19:00'));
});

test('Ende wird auf HH:MM:SS gebracht', function () {
    assertSame(['22:00:00', null], appointmentNormalizeEndTime('22:00', '19:30:00'));
    assertSame(['22:15:30', null], appointmentNormalizeEndTime('22:15:30', '19:30'));
});

test('Ende vor dem Beginn ist gueltig (Folgetag)', function () {
    assertSame(['01:00:00', null], appointmentNormalizeEndTime('01:00', '20:00'));
});

test('Ende gleich dem Beginn ist ein Fehler, in jeder Schreibweise', function () {
    [, $a] = appointmentNormalizeEndTime('19:00', '19:00:00');
    [, $b] = appointmentNormalizeEndTime('19:00:00', '19:00');
    assertTrue(is_string($a) && is_string($b));
});

test('Ende: ungueltige Uhrzeiten sind Fehler', function () {
    foreach (['25:00', '19:60', '7:30', 'abends', '19.30'] as $falsch) {
        [, $fehler] = appointmentNormalizeEndTime($falsch, '19:00');
        assertTrue(is_string($fehler), "'{$falsch}' haette abgelehnt werden muessen");
    }
});

test('appointmentTimeKey vereinheitlicht HH:MM und HH:MM:SS', function () {
    assertSame('19:30:00', appointmentTimeKey('19:30'));
    assertSame('19:30:00', appointmentTimeKey('19:30:00'));
});

test('Import: fehlende Spalten erzeugen keine Felder', function () {
    // Eine alte Datei ohne die Spalten darf vorhandene Werte nicht loeschen.
    $erg = appointmentImportDetails(['title' => 'Probe'], '19:00:00');
    assertSame(['fields' => [], 'error' => null], $erg);
});

test('Import: vorhandene Spalten werden normalisiert, leere loeschen', function () {
    $erg = appointmentImportDetails(['location' => ' Probelokal ', 'end_time' => '22:00'], '19:00:00');
    assertSame(['fields' => ['location' => 'Probelokal', 'end_time' => '22:00:00'], 'error' => null], $erg);

    $leer = appointmentImportDetails(['location' => '', 'end_time' => ''], '19:00:00');
    assertSame(['fields' => ['location' => null, 'end_time' => null], 'error' => null], $leer);
});

test('Import: ungueltiges Ende ist ein Zeilenfehler', function () {
    $erg = appointmentImportDetails(['end_time' => '19:00'], '19:00:00');
    assertSame([], $erg['fields']);
    assertTrue(is_string($erg['error']));
});

test('Kernfelder: nur mitgeschickte Felder werden geprueft', function () {
    assertSame([[], null], appointmentNormalizeCore([]));
    assertSame([['title' => 'Probe'], null], appointmentNormalizeCore(['title' => '  Probe ']));
});

test('Kernfelder: Titel fehlt, ist leer, zu lang oder kein Text', function () {
    foreach ([null, '', '   ', ['x'], 42, str_repeat('ä', 201)] as $titel) {
        [$wert, $fehler] = appointmentNormalizeCore(['title' => $titel]);
        assertSame(null, $wert);
        assertTrue(is_string($fehler), 'Titel ' . var_export($titel, true) . ' muss abgelehnt werden');
    }
    assertSame([['title' => str_repeat('ä', 200)], null], appointmentNormalizeCore(['title' => str_repeat('ä', 200)]));
});

test('Kernfelder: Datum nur als echtes JJJJ-MM-TT', function () {
    assertSame([['date' => '2028-02-29'], null], appointmentNormalizeCore(['date' => '2028-02-29']));
    foreach ([null, 'kaputt', '2026-02-30', '2026-2-3', '20.11.2026', '2026-11-20 19:30', 20261120] as $datum) {
        [$wert, $fehler] = appointmentNormalizeCore(['date' => $datum]);
        assertSame(null, $wert);
        assertTrue(is_string($fehler), 'Datum ' . var_export($datum, true) . ' muss abgelehnt werden');
    }
});

test('Kernfelder: Beginn als HH:MM oder HH:MM:SS, normalisiert auf HH:MM:SS', function () {
    assertSame([['start_time' => '19:30:00'], null], appointmentNormalizeCore(['start_time' => '19:30']));
    assertSame([['start_time' => '07:05:30'], null], appointmentNormalizeCore(['start_time' => '07:05:30']));
    foreach ([null, '', '24:00', '7:30', '19:60', 'abends', 1930] as $zeit) {
        [$wert, $fehler] = appointmentNormalizeCore(['start_time' => $zeit]);
        assertSame(null, $wert);
        assertTrue(is_string($fehler), 'Beginn ' . var_export($zeit, true) . ' muss abgelehnt werden');
    }
});

test('Kernfelder: Terminart als positive Ganzzahl, null und Leerwert bleiben null', function () {
    assertSame([['type_id' => null], null], appointmentNormalizeCore(['type_id' => null]));
    assertSame([['type_id' => null], null], appointmentNormalizeCore(['type_id' => '']));
    assertSame([['type_id' => 7], null], appointmentNormalizeCore(['type_id' => '7']));
    foreach (['abc', 0, -1, 3.7, true, '5x'] as $typ) {
        [$wert, $fehler] = appointmentNormalizeCore(['type_id' => $typ]);
        assertSame(null, $wert);
        assertTrue(is_string($fehler), 'Terminart ' . var_export($typ, true) . ' muss abgelehnt werden');
    }
});
