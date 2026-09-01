<?php
declare(strict_types=1);

require_once __DIR__ . '/../../private/helpers/worktime.php';

// ---- sessionDurationMinutes -------------------------------------------------

test('sessionDurationMinutes liefert null fuer eine laufende Sitzung', function () {
    assertSame(null, sessionDurationMinutes([
        'start_time' => '2026-09-01 09:00:00',
        'end_time' => null,
        'break_minutes' => 0,
    ]));
});

test('sessionDurationMinutes zieht die Pause ab', function () {
    assertSame(210, sessionDurationMinutes([
        'start_time' => '2026-09-01 09:00:00',
        'end_time' => '2026-09-01 13:00:00',
        'break_minutes' => 30,
    ]));
});

test('sessionDurationMinutes ohne Pause', function () {
    assertSame(240, sessionDurationMinutes([
        'start_time' => '2026-09-01 09:00:00',
        'end_time' => '2026-09-01 13:00:00',
        'break_minutes' => 0,
    ]));
});

test('sessionDurationMinutes wird nie negativ', function () {
    assertSame(0, sessionDurationMinutes([
        'start_time' => '2026-09-01 09:00:00',
        'end_time' => '2026-09-01 09:30:00',
        'break_minutes' => 90,
    ]));
});

test('sessionDurationMinutes rechnet ueber Mitternacht', function () {
    assertSame(120, sessionDurationMinutes([
        'start_time' => '2026-09-01 23:00:00',
        'end_time' => '2026-09-02 01:00:00',
        'break_minutes' => 0,
    ]));
});

// ---- validateManualSession --------------------------------------------------

function validSessionInput(): array
{
    return [
        'activity_id'   => 1,
        'start_time'    => '2026-08-31 09:00:00',
        'end_time'      => '2026-08-31 12:00:00',
        'break_minutes' => 30,
        'note'          => 'Buehnenaufbau',
    ];
}

$nowTs = strtotime('2026-09-01 12:00:00');

test('validateManualSession akzeptiert eine gueltige Eingabe', function () use ($nowTs) {
    assertSame([], validateManualSession(validSessionInput(), false, $nowTs));
});

test('validateManualSession verlangt eine Taetigkeitsart', function () use ($nowTs) {
    $in = validSessionInput();
    unset($in['activity_id']);
    assertSame(1, count(validateManualSession($in, false, $nowTs)));
});

test('validateManualSession lehnt Ende vor Beginn ab', function () use ($nowTs) {
    $in = validSessionInput();
    $in['end_time'] = '2026-08-31 08:00:00';
    assertTrue(count(validateManualSession($in, false, $nowTs)) > 0);
});

test('validateManualSession lehnt Ende gleich Beginn ab', function () use ($nowTs) {
    $in = validSessionInput();
    $in['end_time'] = $in['start_time'];
    assertTrue(count(validateManualSession($in, false, $nowTs)) > 0);
});

test('validateManualSession lehnt eine Pause ab, die die Bruttodauer erreicht', function () use ($nowTs) {
    $in = validSessionInput();
    $in['break_minutes'] = 180;
    assertTrue(count(validateManualSession($in, false, $nowTs)) > 0);
});

test('validateManualSession lehnt eine negative Pause ab', function () use ($nowTs) {
    $in = validSessionInput();
    $in['break_minutes'] = -1;
    assertTrue(count(validateManualSession($in, false, $nowTs)) > 0);
});

test('validateManualSession lehnt Zeiten in der Zukunft ab', function () use ($nowTs) {
    $in = validSessionInput();
    $in['start_time'] = '2026-09-02 09:00:00';
    $in['end_time']   = '2026-09-02 12:00:00';
    assertTrue(count(validateManualSession($in, false, $nowTs)) > 0);
});

test('validateManualSession erzwingt eine Notiz, wenn verlangt', function () use ($nowTs) {
    $in = validSessionInput();
    $in['note'] = '   ';
    assertSame(0, count(validateManualSession($in, false, $nowTs)));
    assertSame(1, count(validateManualSession($in, true, $nowTs)));
});

test('validateManualSession lehnt unparsbare Zeitangaben ab', function () use ($nowTs) {
    $in = validSessionInput();
    $in['start_time'] = 'gestern irgendwann';
    assertTrue(count(validateManualSession($in, false, $nowTs)) > 0);
});

// ---- isSessionStale ---------------------------------------------------------

test('isSessionStale ist false fuer eine beendete Sitzung', function () {
    assertSame(false, isSessionStale(
        ['start_time' => '2026-08-01 09:00:00', 'end_time' => '2026-08-01 10:00:00'],
        12,
        strtotime('2026-09-01 12:00:00')
    ));
});

test('isSessionStale ist false innerhalb der Obergrenze', function () {
    assertSame(false, isSessionStale(
        ['start_time' => '2026-09-01 09:00:00', 'end_time' => null],
        12,
        strtotime('2026-09-01 12:00:00')
    ));
});

test('isSessionStale ist true jenseits der Obergrenze', function () {
    assertSame(true, isSessionStale(
        ['start_time' => '2026-09-01 09:00:00', 'end_time' => null],
        2,
        strtotime('2026-09-01 12:00:00')
    ));
});

test('isSessionStale ist true genau auf der Grenze', function () {
    assertSame(true, isSessionStale(
        ['start_time' => '2026-09-01 09:00:00', 'end_time' => null],
        3,
        strtotime('2026-09-01 12:00:00')
    ));
});

// ---- staleEndTime -----------------------------------------------------------

test('staleEndTime kappt auf Start plus Obergrenze', function () {
    assertSame('2026-09-01 21:00:00', staleEndTime('2026-09-01 09:00:00', 12));
});
