<?php
declare(strict_types=1);

/**
 * Ort und Ende eines Termins (FI-23): Pruefung und Normalisierung.
 *
 * API (POST/PUT) und CSV-Import teilen sich diese Regeln. Ein Ende gleich
 * dem Beginn ist fast immer ein Tippfehler; ein Ende vor dem Beginn ist
 * gueltig und meint den Folgetag.
 */

require_once __DIR__ . '/../../private/helpers/appointment_details.php';

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
