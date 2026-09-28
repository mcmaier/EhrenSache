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
 * Reihenfolge der Anwesenheitsliste im Dashboard.
 *
 * GET records sortierte nur nach Datum, dann nach Ankunft. Zwei Termine am
 * selben Tag liefen dadurch ineinander, und alle Eintraege ohne Ankunftszeit
 * standen gesammelt alphabetisch am Tagesende, gleich zu welchem Termin sie
 * gehoerten. Verlangt ist: Termin (neuester zuerst), darin Ankunft (neueste
 * zuerst, fehlende zuletzt), erst bei gleicher Zeit alphabetisch.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

const ORD_DATE = '2031-04-10';

function ordTempAppointment(string $token, string $title, string $startTime): int
{
    $res = apiRequest('POST', 'appointments', [
        'token' => $token,
        'body'  => ['title' => $title, 'date' => ORD_DATE, 'start_time' => $startTime],
    ]);
    assertStatus(201, $res, 'Testtermin konnte nicht angelegt werden');

    return (int) $res['body']['id'];
}

function ordRecord(string $token, int $aptId, int $memberId, ?string $time): void
{
    $body = ['member_id' => $memberId, 'appointment_id' => $aptId];
    if ($time !== null) {
        $body['arrival_time'] = ORD_DATE . ' ' . $time;
    }
    $res = apiRequest('POST', 'records', ['token' => $token, 'body' => $body]);
    assertStatus(201, $res, 'Test-Record konnte nicht angelegt werden');
}

test('GET records: gleicher Tag -- erst Termin, dann Ankunft, dann Name', function () {
    $token = apiToken('admin');

    // Drei Mitglieder mit verschiedenen Nachnamen, alphabetisch geordnet
    $res = apiRequest('GET', 'members', ['token' => $token]);
    assertStatus(200, $res);
    $bySurname = [];
    foreach ($res['body'] as $m) {
        $bySurname[$m['surname']] ??= (int) $m['member_id'];
    }
    ksort($bySurname, SORT_STRING);
    assertTrue(count($bySurname) >= 3, 'Drei Mitglieder mit verschiedenen Nachnamen noetig');
    [$m1, $m2, $m3] = array_slice(array_values($bySurname), 0, 3);

    // Mehr als 2 h Abstand, sonst lehnt appointments.php den zweiten als
    // Dublette ab (409)
    $early = $late = 0;
    try {
        $early = ordTempAppointment($token, 'Sortierung frueh', '14:00:00');
        $late  = ordTempAppointment($token, 'Sortierung spaet', '20:00:00');

        // Frueher Termin: m3 und m1 zur selben Zeit, m2 ohne Ankunft
        ordRecord($token, $early, $m3, '13:55:00');
        ordRecord($token, $early, $m1, '13:55:00');
        ordRecord($token, $early, $m2, null);
        // Spaeter Termin: m1 ohne Ankunft, m2 vor m3 angekommen
        ordRecord($token, $late, $m1, null);
        ordRecord($token, $late, $m2, '19:50:00');
        ordRecord($token, $late, $m3, '19:58:00');

        $list = apiRequest('GET', 'records', [
            'token' => $token,
            'query' => ['from_date' => ORD_DATE, 'to_date' => ORD_DATE],
        ]);
        assertStatus(200, $list);

        $actual = array_map(
            fn($r) => [(int) $r['appointment_id'], (int) $r['member_id']],
            array_values(array_filter($list['body'],
                fn($r) => in_array((int) $r['appointment_id'], [$early, $late], true)))
        );
        $expected = [
            [$late,  $m3],   // 19:58
            [$late,  $m2],   // 19:50
            [$late,  $m1],   // ohne Ankunft
            [$early, $m1],   // 13:55, alphabetisch vor m3
            [$early, $m3],   // 13:55
            [$early, $m2],   // ohne Ankunft
        ];
        assertSame($expected, $actual,
            'Eintraege eines Termins muessen zusammenstehen, neuester Termin zuerst');
    } finally {
        foreach (array_filter([$early, $late]) as $id) {
            apiRequest('DELETE', 'appointments', ['token' => $token, 'query' => ['id' => $id]]);
        }
    }
});
