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
 * Puenktlichkeit und Zuverlaessigkeit ueber resource=statistics.
 *
 * Jeder fachliche Test baut sich eine eigene Welt -- Gruppe, Terminart,
 * Mitglied --, statt Bestand zu veraendern: PUT auf Terminarten und Mitglieder
 * ist kein Teil-Update (OI-54), und fremde Termine desselben Mitglieds
 * wuerden die Zahlen verfaelschen. Gefiltert wird auf Gruppe und Mitglied der
 * Welt; damit zaehlen ausschliesslich die hier angelegten Termine.
 *
 * Einstellungen werden je Test gesetzt und im finally auf den Vorzustand
 * zurueckgestellt. Die Vorgabe ist "aus"; eine haengengebliebene Aktivierung
 * wuerde report_api und my_data veraendern.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

function puCreate(string $resource, array $body): int
{
    $res = apiRequest('POST', $resource, ['token' => apiToken('admin'), 'body' => $body]);
    assertStatus(201, $res, "{$resource} konnte nicht angelegt werden");

    // Eine ID 0 laesst jeden Folgeschritt still ins Leere laufen; POST members
    // lieferte sie bis 1.5.1, sobald group_ids mitkamen.
    $id = (int) $res['body']['id'];
    assertTrue($id > 0, "{$resource} lieferte keine brauchbare ID: " . $res['raw']);

    return $id;
}

/** Die ID gehoert in 'query' -- ohne sie meldet DELETE Erfolg und loescht nichts (OI-56). */
function puDelete(string $resource, int $id): void
{
    apiRequest('DELETE', $resource, ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
}

/** Eigene Gruppe, Terminart und Mitglied. Ein Mitglied ohne Mitgliedschaftszeitraum gilt als aktiv. */
function puWorld(string $label): array
{
    $suffix  = uniqid();
    $groupId = puCreate('member_groups', ['group_name' => "PU {$label} {$suffix}"]);

    return [
        'group'        => $groupId,
        'type'         => puCreate('appointment_types', [
            'type_name'  => "PU {$label} {$suffix}",
            'is_default' => 0,
            'color'      => '#667eea',
            'group_ids'  => [$groupId],
        ]),
        'member'       => puCreate('members', [
            'name'      => 'Pu',
            'surname'   => "Test {$label} {$suffix}",
            'active'    => 1,
            'group_ids' => [$groupId],
        ]),
        'appointments' => [],
    ];
}

function puAppointment(array &$world, string $date, string $time): int
{
    $id = puCreate('appointments', [
        'title'      => 'PU-Termin',
        'date'       => $date,
        'start_time' => $time,
        'type_id'    => $world['type'],
    ]);
    $world['appointments'][] = $id;

    return $id;
}

/** Termine zuerst -- der Handler raeumt ihre Records und Ausnahmen mit weg. */
function puDropWorld(array $world): void
{
    foreach ($world['appointments'] as $appointmentId) {
        puDelete('appointments', $appointmentId);
    }
    puDelete('members', $world['member']);
    puDelete('appointment_types', $world['type']);
    puDelete('member_groups', $world['group']);
}

function puSettingValue(string $key): ?string
{
    $res = apiRequest('GET', 'settings', ['token' => apiToken('admin')]);
    foreach ($res['body']['settings'] ?? [] as $setting) {
        if ($setting['setting_key'] === $key) {
            return (string) $setting['setting_value'];
        }
    }

    return null;
}

function puSetSetting(string $key, string $value): void
{
    assertStatus(200, apiRequest('PUT', 'settings', [
        'token' => apiToken('admin'),
        'body'  => ['setting_key' => $key, 'setting_value' => $value],
    ]), "Einstellung {$key} konnte nicht gesetzt werden");
}

/** Fuehrt $fn mit den Einstellungen aus und stellt danach den Vorzustand her. */
function puWithSettings(array $settings, callable $fn): void
{
    $vorher = [];
    foreach ($settings as $key => $value) {
        $vorher[$key] = puSettingValue($key) ?? '0';
        puSetSetting($key, $value);
    }

    try {
        $fn();
    } finally {
        foreach ($vorher as $key => $value) {
            puSetSetting($key, $value);
        }
    }
}

function puStats(array $world, ?string $token = null): array
{
    $res = apiRequest('GET', 'statistics', [
        'token' => $token ?? apiToken('admin'),
        'query' => ['year' => date('Y'), 'group_id' => $world['group'], 'member_id' => $world['member']],
    ]);
    assertStatus(200, $res);

    return $res['body'];
}

test('Ausgeschaltet steht nur enabled:false in der Antwort', function () {
    puWithSettings(['punctuality_enabled' => '0', 'reliability_enabled' => '0'], function () {
        $res = apiRequest('GET', 'statistics', ['token' => apiToken('admin'), 'query' => ['year' => date('Y')]]);
        assertStatus(200, $res);

        assertSame(['enabled' => false], $res['body']['punctuality'],
            'Eine abgeschaltete Kennzahl darf nicht wie eine leere aussehen');
        assertSame(['enabled' => false], $res['body']['reliability']);
    });
});

test('Puenktlichkeit: Quote, Verspaetung, Selbstauskunft und Messabdeckung', function () {
    // Alle Termine am 1. Januar des laufenden Jahres: Er liegt nie in der
    // Zukunft, und die Zeiten haengen nicht an der Uhr des Testlaufs.
    // Drei Stunden Abstand -- zwei Termine derselben Art im Toleranzfenster
    // lehnt der Handler als Dublette ab.
    $tag   = date('Y') . '-01-01';
    $admin = apiToken('admin');
    $welt  = puWorld('Puenktlichkeit');

    try {
        $ankunft = [
            '03:00:00' => '02:55:00',   // 5 Minuten frueher
            '06:00:00' => '06:00:30',   // abgerundet puenktlich
            '09:00:00' => '09:03:00',   // 3 Minuten spaet
            '12:00:00' => '12:45:00',   // 45 Minuten spaet, gekappt auf 20
        ];
        foreach ($ankunft as $beginn => $zeit) {
            $aptId = puAppointment($welt, $tag, $beginn);
            assertStatus(201, apiRequest('POST', 'records', ['token' => $admin, 'body' => [
                'member_id' => $welt['member'], 'appointment_id' => $aptId,
                'arrival_time' => "{$tag} {$zeit}",
            ]]));
        }

        // Selbstauskunft: Zeitkorrektur beantragen und genehmigen. Die
        // Wunschzeit muss beim PUT mit -- sonst loescht der Admin-Zweig sie.
        $apt15 = puAppointment($welt, $tag, '15:00:00');
        $antrag = puCreate('exceptions', [
            'member_id' => $welt['member'], 'appointment_id' => $apt15,
            'exception_type' => 'time_correction', 'reason' => 'PU-Test',
            'requested_arrival_time' => "{$tag} 15:10:00", 'status' => 'pending',
        ]);
        assertStatus(200, apiRequest('PUT', 'exceptions', ['token' => $admin,
            'query' => ['id' => $antrag],
            'body'  => ['exception_type' => 'time_correction', 'reason' => 'PU-Test',
                        'requested_arrival_time' => "{$tag} 15:10:00", 'status' => 'approved'],
        ]));

        // Ohne Uhrzeit: zaehlt als Soll-Termin, aber nicht als Messung.
        $apt18 = puAppointment($welt, $tag, '18:00:00');
        assertStatus(201, apiRequest('POST', 'records', ['token' => $admin, 'body' => [
            'member_id' => $welt['member'], 'appointment_id' => $apt18,
        ]]));

        puWithSettings(['punctuality_enabled' => '1', 'punctuality_grace_minutes' => '0'], function () use ($welt) {
            $p = puStats($welt)['punctuality'];

            // (float): json_encode() macht aus 40.0 eine 40, siehe Vorbemerkung 4.
            assertSame(true, $p['enabled']);
            assertSame(true, $p['sufficient']);
            assertSame(5,    $p['measured_count'], 'Der Eintrag ohne Uhrzeit ist keine Messung');
            assertSame(6,    $p['total_count']);
            assertSame(2,    $p['on_time_count']);
            assertSame(40.0, (float) $p['rate']);
            assertSame(3,    $p['late_count']);
            assertSame(11.0, (float) $p['avg_late_minutes']);
            assertSame(1,    $p['self_reported_count']);
        });

        puWithSettings(['punctuality_enabled' => '1', 'punctuality_grace_minutes' => '-5'], function () use ($welt) {
            assertSame(1, puStats($welt)['punctuality']['on_time_count'],
                'Die Karenz aus der Einstellung wirkt');
        });
    } finally {
        puDropWorld($welt);
    }
});

test('Zuverlaessigkeit: jeder Ausgang in der richtigen Reihenfolge', function () {
    // Eine Meldung "vor Beginn" braucht einen Termin, der beim Anlegen noch
    // bevorsteht, aber schon zur Statistik zaehlt -- also heute spaeter. Zwei
    // davon (20:59 und 23:59), drei Stunden auseinander wegen der
    // Dublettenpruefung. Die uebrigen liegen am 1. Januar.
    //
    // Laeuft der Test am 1. Januar vor 03:01 oder ab 20:57, stimmt die Zeitlage
    // nicht; dann bricht er ab, statt falsch zu pruefen.
    $jetzt = date('H:i');
    if ((date('m-d') === '01-01' && $jetzt < '03:01') || $jetzt >= '20:57') {
        assertTrue(true, 'Zeitlage ungeeignet -- Test uebersprungen');
        return;
    }

    $tag   = date('Y') . '-01-01';
    $admin = apiToken('admin');
    $welt  = puWorld('Zuverlaessigkeit');

    try {
        // (a) erschienen
        $a = puAppointment($welt, $tag, '00:00:00');
        assertStatus(201, apiRequest('POST', 'records', ['token' => $admin, 'body' => [
            'member_id' => $welt['member'], 'appointment_id' => $a, 'arrival_time' => "{$tag} 00:00:00",
        ]]));

        // (c) nach Beginn abgemeldet, dann genehmigt -> excused-Record, trotzdem ausgefallen
        $c = puAppointment($welt, $tag, '03:00:00');
        $abm = puCreate('exceptions', [
            'member_id' => $welt['member'], 'appointment_id' => $c,
            'exception_type' => 'absence', 'reason' => 'PU-Test', 'status' => 'pending',
        ]);
        assertStatus(200, apiRequest('PUT', 'exceptions', ['token' => $admin,
            'query' => ['id' => $abm],
            'body'  => ['exception_type' => 'absence', 'reason' => 'PU-Test', 'status' => 'approved'],
        ]));

        // (d) vom Verwalter direkt entschuldigt, ohne Abmeldung
        $d = puAppointment($welt, $tag, '06:00:00');
        assertStatus(201, apiRequest('POST', 'records', ['token' => $admin, 'body' => [
            'member_id' => $welt['member'], 'appointment_id' => $d, 'status' => 'excused',
        ]]));

        // (e) nichts
        puAppointment($welt, $tag, '09:00:00');

        // (b) rechtzeitig abgemeldet, noch offen
        $b = puAppointment($welt, date('Y-m-d'), '23:59:00');
        puCreate('exceptions', [
            'member_id' => $welt['member'], 'appointment_id' => $b,
            'exception_type' => 'absence', 'reason' => 'PU-Test', 'status' => 'pending',
        ]);

        // (f) Ein Zeitkorrektur-Antrag ist keine Abmeldung. Er muss VOR Beginn
        // liegen, sonst beweist der Test nichts: Ein Antrag nach Beginn waere
        // auch ohne Typfilter "ausgefallen". Nur vor Beginn wuerde ein fehlender
        // Filter ihn zur rechtzeitigen Absage machen.
        //
        // Seit OI-82 muss die beantragte Ankunft schon stattgefunden haben: Der
        // Termin beginnt in einer halben Stunde, angekommen ist das Mitglied vor
        // fuenf Minuten -- frueh da, Check-in gescheitert. Das Fenster wird dafuer
        // auf zwei Stunden festgelegt, sonst haengt der Fall an der Einstellung.
        $fStart   = new DateTimeImmutable('+30 minutes');
        $fArrival = new DateTimeImmutable('-5 minutes');
        $f = puAppointment($welt, $fStart->format('Y-m-d'), $fStart->format('H:i:00'));
        puWithSettings(['checkin_tolerance_hours' => '2'], function () use ($welt, $f, $fArrival) {
            puCreate('exceptions', [
                'member_id' => $welt['member'], 'appointment_id' => $f,
                'exception_type' => 'time_correction', 'reason' => 'PU-Test',
                'requested_arrival_time' => $fArrival->format('Y-m-d H:i:00'), 'status' => 'pending',
            ]);
        });

        puWithSettings(['reliability_enabled' => '1'], function () use ($welt) {
            $r = puStats($welt)['reliability'];

            assertSame(6,    $r['total']);
            assertSame(1,    $r['appeared'],        '(a)');
            assertSame(2,    $r['excused_in_time'], '(b) und (d)');
            assertSame(3,    $r['missed'],          '(c), (e) und (f) -- (f) nur mit Typfilter');
            assertSame(50.0, (float) $r['rate']);
        });
    } finally {
        puDropWorld($welt);
    }
});

test('Zuverlaessigkeit und Anwesenheit rechnen ueber dieselbe Soll-Menge', function () {
    // Gegen den ganzen Bestand, ohne eigene Welt: Genau dort wuerden die zwei
    // Rechnungen auseinanderlaufen, wenn punctualityScope() und
    // attendanceFetchMemberTotals() je unterschiedlich joinen.
    puWithSettings(['punctuality_enabled' => '1', 'reliability_enabled' => '1'], function () {
        $res = apiRequest('GET', 'statistics', ['token' => apiToken('admin'), 'query' => ['year' => date('Y')]]);
        assertStatus(200, $res);

        $s    = $res['body']['summary'];
        $soll = $s['total_present'] + $s['total_excused'] + $s['total_unexcused'];

        assertSame($soll, $res['body']['reliability']['total'], 'Soll-Paare weichen ab');
        assertSame($s['total_present'], $res['body']['reliability']['appeared'], 'Erschienen weicht ab');
        assertSame($soll, $res['body']['punctuality']['total_count']);
    });
});

test('Ein Mitglied sieht nur die eigenen Werte', function () {
    $eigen = apiMemberId('user');
    assertTrue($eigen !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    puWithSettings(['punctuality_enabled' => '1', 'reliability_enabled' => '1'], function () use ($eigen) {
        $alsAdmin = apiRequest('GET', 'statistics', ['token' => apiToken('admin'),
            'query' => ['year' => date('Y'), 'member_id' => $eigen]]);

        // Eine fremde member_id wird fuer die Rolle user ignoriert, nicht abgewiesen.
        $alsUser = apiRequest('GET', 'statistics', ['token' => apiToken('user'),
            'query' => ['year' => date('Y'), 'member_id' => 1]]);

        assertStatus(200, $alsUser);
        assertSame($alsAdmin['body']['reliability'], $alsUser['body']['reliability']);
        assertSame($alsAdmin['body']['punctuality'], $alsUser['body']['punctuality']);
    });
});

test('Die Selbstauskunft enthaelt die eigenen Werte je Jahr, solange eingeschaltet', function () {
    puWithSettings(['punctuality_enabled' => '0', 'reliability_enabled' => '0'], function () {
        $aus = apiRequest('GET', 'my_data', ['token' => apiToken('user')]);
        assertStatus(200, $aus);
        assertSame([], $aus['body']['behavior'], 'Ausgeschaltet enthaelt die Auskunft keine Kennzahl');
    });

    puWithSettings(['punctuality_enabled' => '1', 'reliability_enabled' => '1'], function () {
        $an = apiRequest('GET', 'my_data', ['token' => apiToken('user')]);
        assertStatus(200, $an);

        $jahr = (int) date('Y');
        assertTrue(isset($an['body']['behavior'][$jahr]), 'Das laufende Jahr fehlt in der Auskunft');

        $stats = apiRequest('GET', 'statistics', ['token' => apiToken('user'), 'query' => ['year' => $jahr]]);
        assertSame($stats['body']['reliability'], $an['body']['behavior'][$jahr]['reliability'],
            'Auskunft und Statistik muessen dieselben Werte nennen');

        $csv = apiRequest('GET', 'my_data', ['token' => apiToken('user'), 'query' => ['format' => 'csv']]);
        assertTrue(str_contains($csv['raw'], '[ PÜNKTLICHKEIT UND ZUVERLÄSSIGKEIT ]'),
            'Der CSV-Auskunft fehlt der Abschnitt');
    });
});
