<?php
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

// ---- Entschuldigungspflicht --------------------------------------------------

test('responseExcuseAction: ohne Pflicht entsteht nie ein Antrag', function () {
    assertSame('none', responseExcuseAction(false, null, 'no', null, false));
    assertSame('none', responseExcuseAction(false, 'no', 'yes', null, false));
});

test('responseExcuseAction: Absage legt an oder verknuepft einen eigenen Antrag', function () {
    assertSame('create', responseExcuseAction(true, null, 'no', null, false));
    assertSame('create', responseExcuseAction(true, 'yes', 'no', null, false));
    assertSame('link',   responseExcuseAction(true, 'yes', 'no', null, true));
});

test('responseExcuseAction: offene Absage bekommt die neue Begruendung, entschiedene bleibt', function () {
    assertSame('update_reason', responseExcuseAction(true, 'no', 'no', 'pending', false));
    assertSame('keep',          responseExcuseAction(true, 'no', 'no', 'approved', false));
    assertSame('keep',          responseExcuseAction(true, 'no', 'no', 'rejected', false));
});

test('responseExcuseAction: Zusage oder Ruecknahme loescht nur einen offenen Antrag', function () {
    assertSame('delete', responseExcuseAction(true, 'no', 'yes', 'pending', false));
    assertSame('delete', responseExcuseAction(true, 'no', null, 'pending', false), 'null = Ruecknahme');
    assertSame('keep',   responseExcuseAction(true, 'no', 'maybe', 'approved', false));
    assertSame('none',   responseExcuseAction(true, 'yes', 'maybe', null, false));
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
