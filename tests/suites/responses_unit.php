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
 * Regeln der Terminrueckmeldung, ohne Datenbank.
 *
 * Spec: docs/superpowers/specs/2026-09-14-terminrueckmeldung-design.md
 */
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/responses.php';

// ---- Frist -----------------------------------------------------------------

test('responseHoursFromRaw nimmt ganze Zahlen von 0 bis 720', function () {
    assertSame(0,   responseHoursFromRaw('0'));
    assertSame(24,  responseHoursFromRaw('24'));
    assertSame(720, responseHoursFromRaw(720));
});

test('responseHoursFromRaw lehnt alles andere ab', function () {
    assertSame(null, responseHoursFromRaw('721'));
    assertSame(null, responseHoursFromRaw('-1'));
    assertSame(null, responseHoursFromRaw('2.5'));
    assertSame(null, responseHoursFromRaw(''));
    assertSame(null, responseHoursFromRaw(null));
    assertSame(null, responseHoursFromRaw('24 Stunden'));
});

test('responseHoursFromRaw trimmt umschliessenden Leerraum, aber nicht innenliegenden', function () {
    assertSame(null, responseHoursFromRaw('2 4'));
    assertSame(48,   responseHoursFromRaw(' 48 '));
});

test('responseDeadlineHours: die Terminart ueberschreibt die globale Frist', function () {
    assertSame(168, responseDeadlineHours('168', '24'));
    assertSame(0,   responseDeadlineHours(0, '24'), '0 ist ein Wert, nicht leer');
});

test('responseDeadlineHours: ohne Wert der Terminart gilt global, sonst 24', function () {
    assertSame(48, responseDeadlineHours(null, '48'));
    assertSame(24, responseDeadlineHours(null, 'kaputt'));
    assertSame(24, responseDeadlineHours('999', ''), 'Unsinn an der Terminart faellt auf global zurueck');
});

test('responseDeadline rechnet in Wanduhrzeit, auch ueber die Zeitumstellung', function () {
    assertSame('2026-09-18 19:30:00', responseDeadline('2026-09-19', '19:30:00', 24));
    assertSame('2026-09-19 19:30:00', responseDeadline('2026-09-19', '19:30:00', 0));
    // 25.10.2026 ist Umstellung auf Winterzeit. MySQL DATE_SUB auf DATETIME
    // kennt keine Zeitzone -- PHP muss dasselbe Ergebnis liefern.
    assertSame('2026-10-24 19:00:00', responseDeadline('2026-10-25', '19:00:00', 24));
});

test('responseDeadline: negative Stunden werden auf 0 gekappt', function () {
    assertSame('2026-09-19 19:30:00', responseDeadline('2026-09-19', '19:30:00', -5));
});

test('responseIsLate: genau auf der Frist ist rechtzeitig', function () {
    $frist = '2026-09-18 19:30:00';
    assertSame(false, responseIsLate('2026-09-18 19:29:59', $frist));
    assertSame(false, responseIsLate('2026-09-18 19:30:00', $frist));
    assertSame(true,  responseIsLate('2026-09-18 19:30:01', $frist));
});

test('responseHasStarted: ab der Startminute begonnen', function () {
    assertSame(false, responseHasStarted('2026-09-19', '19:30:00', '2026-09-19 19:29:59'));
    assertSame(true,  responseHasStarted('2026-09-19', '19:30:00', '2026-09-19 19:30:00'));
});

// ---- Eingabe ---------------------------------------------------------------

test('responseInputError akzeptiert die drei Status mit und ohne Bemerkung', function () {
    foreach (['yes', 'no', 'maybe'] as $status) {
        assertSame(null, responseInputError($status, null));
        assertSame(null, responseInputError($status, 'kommt spaeter'));
    }
});

test('responseInputError lehnt unbekannte Status und zu lange Bemerkungen ab', function () {
    assertTrue(responseInputError('vielleicht', null) !== null);
    assertTrue(responseInputError(null, null) !== null);
    assertTrue(responseInputError('yes', ['kein', 'text']) !== null);
    assertSame(null, responseInputError('yes', str_repeat('ä', 255)), '255 Zeichen, nicht Bytes');
    assertTrue(responseInputError('yes', str_repeat('ä', 256)) !== null);
});

test('responseNormalizeComment macht Leeres zu null', function () {
    assertSame(null, responseNormalizeComment(null));
    assertSame(null, responseNormalizeComment('   '));
    assertSame('Urlaub', responseNormalizeComment('  Urlaub '));
});

// ---- Entschuldigungspflicht (Spec 5.4, Entscheidungen 3b und 4b) -----------
//
// Neuer sechster Parameter $excuseCreatedByResponse (3b): Nur ein Antrag, den
// die Rueckmeldung selbst angelegt hat, darf sie spaeter aendern oder
// loeschen. Ein Antrag, den das Mitglied eigenstaendig ueber exceptions
// gestellt hat, wird nur verknuepft ($excuseCreatedByResponse = false) und
// bleibt danach unangetastet.

test('responseExcuseAction: ohne Pflicht entsteht nie ein Antrag', function () {
    assertSame('none', responseExcuseAction(false, null, 'no', null, false, false));
    assertSame('none', responseExcuseAction(false, 'no', 'yes', null, false, false));
});

test('responseExcuseAction: echter Wechsel auf \'no\' legt an oder verknuepft einen eigenen Antrag', function () {
    assertSame('create', responseExcuseAction(true, null, 'no', null, false, false));
    assertSame('create', responseExcuseAction(true, 'yes', 'no', null, false, false));
    assertSame('link',   responseExcuseAction(true, 'yes', 'no', null, true, false));
});

test('responseExcuseAction: 4b -- \'no\' -> \'no\' ohne Antrag erzeugt keinen neuen', function () {
    // Ersetzt einen frueheren Test, der hier fuer (true, 'no', 'no', null, false)
    // 'create' erwartete: Seit Entscheidung 4b entsteht ein neuer Antrag nur bei
    // einem echten Statuswechsel auf 'no'. Bleibt der Status 'no' -- etwa weil ein
    // Verwalter den zuvor erzeugten Antrag ueber exceptions geloescht hat und das
    // Mitglied danach nur die Bemerkung aendert --, waere ein neuer Antrag eine
    // ungewollte Nebenwirkung der Bemerkungsaenderung.
    assertSame('none', responseExcuseAction(true, 'no', 'no', null, false, false));
    assertSame('none', responseExcuseAction(true, 'no', 'no', null, true, false),
        'gilt auch, wenn das Mitglied anderswo schon einen eigenen Antrag haette');
});

test('responseExcuseAction: 3b -- nur ein von der Rueckmeldung angelegter Antrag bekommt die neue Begruendung', function () {
    assertSame('update_reason', responseExcuseAction(true, 'no', 'no', 'pending', false, true),
        'erzeugter Antrag: offen, wird mitgezogen');
    assertSame('keep', responseExcuseAction(true, 'no', 'no', 'pending', false, false),
        'nur verknuepfter Antrag: offen, bleibt trotzdem unangetastet');
    assertSame('keep', responseExcuseAction(true, 'no', 'no', 'approved', false, true),
        'entschieden -> bleibt, unabhaengig von der Herkunft');
    assertSame('keep', responseExcuseAction(true, 'no', 'no', 'rejected', false, false));
});

test('responseExcuseAction: 3b -- nur ein von der Rueckmeldung angelegter Antrag wird bei Zusage/Ruecknahme geloescht', function () {
    assertSame('delete', responseExcuseAction(true, 'no', 'yes', 'pending', false, true),
        'erzeugter, offener Antrag wird bei Zusage geloescht');
    assertSame('delete', responseExcuseAction(true, 'no', null, 'pending', false, true),
        'null = Ruecknahme, ebenfalls geloescht');
    assertSame('keep', responseExcuseAction(true, 'no', 'yes', 'pending', false, false),
        'nur verknuepfter, offener Antrag bleibt bei Zusage bestehen');
    assertSame('keep', responseExcuseAction(true, 'no', null, 'pending', false, false),
        'nur verknuepfter Antrag bleibt auch bei Ruecknahme bestehen');
    assertSame('keep', responseExcuseAction(true, 'no', 'maybe', 'approved', false, false),
        'entschiedener Antrag bleibt ohnehin immer bestehen');
    assertSame('none', responseExcuseAction(true, 'yes', 'maybe', null, false, false));
});

test('responseExcuseAction: weitere Randfaelle', function () {
    // Erste Absage nach einer Vielleicht-Antwort, Mitglied hat schon einen eigenen Antrag.
    assertSame('link', responseExcuseAction(true, 'maybe', 'no', null, true, false));
});

test('responseExcuseAction: echter Wechsel auf \'no\' mit bestehendem offenem Antrag (Luecke der Wahrheitstabelle)', function () {
    assertSame('update_reason', responseExcuseAction(true, 'yes', 'no', 'pending', false, true),
        'echter Wechsel, von der Rueckmeldung erzeugter Antrag -- Bemerkung wird mitgezogen');
    assertSame('keep', responseExcuseAction(true, 'yes', 'no', 'pending', false, false),
        'echter Wechsel, nur verknuepfter Antrag -- bleibt unangetastet');
});

test('responseExcuseAction: Wechsel auf \'maybe\' mit offenem Antrag bleibt \'keep\' (Luecke der Wahrheitstabelle)', function () {
    assertSame('keep', responseExcuseAction(true, 'yes', 'maybe', 'pending', false, true));
});

// ---- A1 (Review 2026-09-15): ein abgelehnter Antrag blockiert keinen neuen ----

test('responseExcuseAction: A1 -- abgelehnter Antrag zaehlt bei echtem Wechsel auf \'no\' wie keiner', function () {
    assertSame('create', responseExcuseAction(true, 'yes', 'no', 'rejected', false, false),
        'kein eigener Antrag -- ein neuer entsteht, der abgelehnte blockiert nicht');
    assertSame('link', responseExcuseAction(true, 'yes', 'no', 'rejected', true, false),
        'eigener, nicht abgelehnter Antrag vorhanden -- wird verknuepft');
    assertSame('create', responseExcuseAction(true, null, 'no', 'rejected', false, false),
        'erste Antwort ueberhaupt ist ebenfalls ein echter Wechsel');
});

test('responseExcuseAction: A1 -- Wechsel weg von \'no\' bei abgelehntem Antrag loest nur die Verknuepfung', function () {
    assertSame('unlink', responseExcuseAction(true, 'no', 'yes', 'rejected', false, false));
    assertSame('unlink', responseExcuseAction(true, 'no', 'maybe', 'rejected', false, false));
    assertSame('unlink', responseExcuseAction(true, 'no', null, 'rejected', false, false),
        'null = Ruecknahme, ebenfalls unlink');
});

test('responseExcuseAction: A1 -- reine Bemerkungsaenderung bei \'no\' -> \'no\' mit abgelehntem Antrag bleibt \'keep\'', function () {
    assertSame('keep', responseExcuseAction(true, 'no', 'no', 'rejected', false, false),
        'kein echter Statuswechsel -- der abgelehnte Antrag bleibt verknuepft, kein neuer entsteht');
    assertSame('keep', responseExcuseAction(true, 'no', 'no', 'rejected', true, false),
        'gilt auch, wenn das Mitglied anderswo schon einen eigenen Antrag haette');
});

// ---- Summen und Gegenueberstellung ------------------------------------------

test('responseSummary zaehlt nur erwartete Mitglieder, offen = ohne Antwort', function () {
    $summe = responseSummary([1, 2, 3, 4, 5], [1 => 'yes', 2 => 'no', 3 => 'maybe', 9 => 'yes']);
    assertSame(['yes' => 1, 'no' => 1, 'maybe' => 1, 'open' => 2], $summe,
        'Mitglied 9 ist nicht mehr erwartet und zaehlt nicht');
});

test('responseSummary entdoppelt Mitglieder, die ueber zwei Gruppen erwartet werden', function () {
    assertSame(['yes' => 1, 'no' => 0, 'maybe' => 0, 'open' => 0], responseSummary([7, 7], [7 => 'yes']));
});

test('responseComparison ordnet jedes erwartete Mitglied genau einem Feld zu', function () {
    $felder = responseComparison(
        [1, 2, 3, 4, 5, 6, 7],
        [1 => 'yes', 2 => 'yes', 3 => 'no', 4 => 'no', 5 => 'maybe'],
        [1, 3, 5, 6]
    );

    assertSame([1], $felder['yes_present']);
    assertSame([2], $felder['yes_absent']);
    assertSame([3], $felder['no_present']);
    assertSame([4], $felder['no_absent']);
    assertSame([5], $felder['maybe_present']);
    assertSame([],  $felder['maybe_absent']);
    assertSame([6], $felder['none_present']);
    assertSame([7], $felder['none_absent']);
});

test('responseComparison: ein Status ausserhalb RESPONSE_STATUSES faellt auf none', function () {
    $felder = responseComparison([1], [1 => 'bogus'], []);

    assertSame([1], $felder['none_absent']);
    assertSame(
        ['yes_present', 'yes_absent', 'no_present', 'no_absent',
         'maybe_present', 'maybe_absent', 'none_present', 'none_absent'],
        array_keys($felder),
        'kein zusaetzliches Feld bogus_absent'
    );
});

test('responsesDedupeExpected behaelt die erste Zeile je Mitglied', function () {
    $rows = [
        ['member_id' => '4', 'group_name' => 'Blasorchester'],
        ['member_id' => '4', 'group_name' => 'Jugend'],
        ['member_id' => '2', 'group_name' => 'Jugend'],
    ];
    $out = responsesDedupeExpected($rows);

    assertSame([4, 2], array_keys($out));
    assertSame('Blasorchester', $out[4]['group_name']);
});

// ---- Einstellungen der Terminart --------------------------------------------

test('responseTypeSettings uebernimmt nur mitgeschickte Felder', function () {
    $vorher = ['responses_enabled' => 1, 'responses_names_visible' => 0,
               'responses_require_excuse' => 1, 'response_deadline_hours' => 168];

    assertSame($vorher, responseTypeSettings((object) ['type_name' => 'Probe'], $vorher),
        'Ein PUT ohne die Felder darf nichts zuruecksetzen');

    $neu = responseTypeSettings((object) ['responses_enabled' => false, 'response_deadline_hours' => ''], $vorher);
    assertSame(0,    $neu['responses_enabled']);
    assertSame(null, $neu['response_deadline_hours'], 'leer = global');
    assertSame(1,    $neu['responses_require_excuse']);
});

test('responseTypeSettings wirft bei ungueltiger Frist', function () {
    assertThrows(fn () => responseTypeSettings((object) ['response_deadline_hours' => 721], RESPONSE_TYPE_DEFAULTS));
    assertThrows(fn () => responseTypeSettings((object) ['response_deadline_hours' => 2.5], RESPONSE_TYPE_DEFAULTS));
});

test('responseTypeSettings: der String \'false\' schaltet ein Flag aus', function () {
    $neu = responseTypeSettings((object) ['responses_enabled' => 'false'], RESPONSE_TYPE_DEFAULTS);
    assertSame(0, $neu['responses_enabled']);

    $neu = responseTypeSettings((object) ['responses_enabled' => true], RESPONSE_TYPE_DEFAULTS);
    assertSame(1, $neu['responses_enabled']);

    $neu = responseTypeSettings((object) ['responses_enabled' => '1'], RESPONSE_TYPE_DEFAULTS);
    assertSame(1, $neu['responses_enabled']);
});
