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

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/**
 * Aktiv/Inaktiv-Zeitraeume (membership_dates) mit Status (OI-130).
 *
 * Bis hierhin las keine Abfrage `membership_dates.status`: Ein Zeitraum
 * "ab 01.10. Inaktiv" wirkte wie "ab 01.10. aktiv". Die Regel lautet jetzt:
 * Aktiv an einem Tag ist, wer `members.active = 1` hat, an dem Tag in keinem
 * inaktiven Zeitraum liegt und -- sofern es aktive Zeitraeume gibt -- in einem
 * davon. Ohne aktive Zeitraeume gilt das Mitglied ausserhalb der inaktiven als
 * aktiv.
 *
 * Dazu traegt die Mitgliederliste `is_active_today` (Stand heute) fuer die
 * Anzeige im Dashboard; `is_active_in_period` bleibt "irgendwann im Jahr
 * aktiv" fuer die Auswahllisten.
 */

/** Legt ein Testmitglied mit Zeitraeumen an; liefert die member_id. */
function maaMember(string $label, array $periods): int
{
    $res = apiRequest('POST', 'members', [
        'token' => apiToken('admin'),
        'body'  => ['name' => 'MAA ' . $label, 'surname' => 'Aktivitaet ' . uniqid(), 'active' => 1],
    ]);
    assertStatus(201, $res);
    $id = (int) $res['body']['id'];
    maaTrack($id);

    foreach ($periods as [$start, $end, $status]) {
        $p = apiRequest('POST', 'membership_dates', [
            'token' => apiToken('admin'),
            'body'  => ['member_id' => $id, 'start_date' => $start, 'end_date' => $end, 'status' => $status],
        ]);
        assertStatus(201, $p);
    }

    return $id;
}

function maaTrack(?int $id = null): array
{
    static $ids = [];
    if ($id !== null) {
        $ids[] = $id;
    }

    return $ids;
}

/** Zeile des Mitglieds aus der Verwalterliste (include_inactive) fuer ein Jahr. */
function maaRow(int $memberId, int $year): array
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'),
        'query' => ['year' => $year, 'include_inactive' => 'true']]);
    assertStatus(200, $res);
    foreach ($res['body'] as $row) {
        if ((int) $row['member_id'] === $memberId) {
            return $row;
        }
    }
    throw new RuntimeException("Mitglied {$memberId} fehlt in der Liste {$year}");
}

/** Steht das Mitglied in der nach Stichtag gefilterten Liste? */
function maaListedOn(int $memberId, string $date): bool
{
    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['date' => $date]]);
    assertStatus(200, $res);
    foreach ($res['body'] as $row) {
        if ((int) $row['member_id'] === $memberId) {
            return (int) $row['is_active_in_period'] === 1;
        }
    }

    return false;
}

function maaDay(int $offset): string
{
    return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
}

// ============================================
// Stichtag: Status des Zeitraums zaehlt
// ============================================

test('Inaktiv-Zeitraum ab vergangenem Tag: heute inaktiv, davor aktiv', function () {
    $id = maaMember('ab-inaktiv', [[maaDay(-7), null, 'inactive']]);

    assertSame(false, maaListedOn($id, maaDay(0)), 'Mitglied steht trotz Inaktiv-Zeitraum heute in der Liste');
    assertSame(true, maaListedOn($id, maaDay(-30)), 'Vor dem Inaktiv-Zeitraum fehlt das Mitglied');
    assertSame(0, (int) maaRow($id, (int) date('Y'))['is_active_today'], 'is_active_today zeigt aktiv');
});

test('Aktiv-Zeitraum endete gestern: heute inaktiv, gestern aktiv', function () {
    $id = maaMember('beendet', [['2020-01-01', maaDay(-1), 'active']]);

    assertSame(false, maaListedOn($id, maaDay(0)), 'Mitglied steht nach Ende des Zeitraums in der Liste');
    assertSame(true, maaListedOn($id, maaDay(-1)), 'Am letzten Tag fehlt das Mitglied');
    assertSame(0, (int) maaRow($id, (int) date('Y'))['is_active_today'], 'is_active_today zeigt aktiv');
});

test('Inaktiv-Zeitraum innerhalb eines offenen Aktiv-Zeitraums unterbricht ihn', function () {
    $id = maaMember('pause', [['2020-01-01', null, 'active'], [maaDay(-3), maaDay(3), 'inactive']]);

    assertSame(false, maaListedOn($id, maaDay(0)), 'Mitglied ist trotz Pause aktiv');
    assertSame(true, maaListedOn($id, maaDay(5)), 'Nach der Pause fehlt das Mitglied');
    assertSame(true, maaListedOn($id, maaDay(-5)), 'Vor der Pause fehlt das Mitglied');
    assertSame(0, (int) maaRow($id, (int) date('Y'))['is_active_today'], 'is_active_today zeigt aktiv');
});

test('Ohne Zeitraeume bleibt das Mitglied aktiv', function () {
    $id = maaMember('ohne', []);

    assertSame(true, maaListedOn($id, maaDay(0)), 'Mitglied ohne Zeitraum fehlt');
    assertSame(1, (int) maaRow($id, (int) date('Y'))['is_active_today'], 'is_active_today zeigt inaktiv');
});

test('Offener Aktiv-Zeitraum: heute aktiv', function () {
    $id = maaMember('offen', [['2020-01-01', null, 'active']]);

    assertSame(1, (int) maaRow($id, (int) date('Y'))['is_active_today'], 'is_active_today zeigt inaktiv');
});

// ============================================
// Jahr: "irgendwann im Jahr aktiv"
// ============================================

test('Jahr: ganzjaehrig inaktiv ohne Aktiv-Zeitraum -> im Jahr inaktiv, im Folgejahr aktiv', function () {
    $id = maaMember('jahr-inaktiv', [['2021-01-01', '2021-12-31', 'inactive']]);

    assertSame(0, (int) maaRow($id, 2021)['is_active_in_period'], '2021 gilt als aktiv');
    assertSame(1, (int) maaRow($id, 2022)['is_active_in_period'], '2022 gilt als inaktiv');
    assertSame(1, (int) maaRow($id, 2020)['is_active_in_period'], '2020 gilt als inaktiv');
});

test('Jahr: Inaktiv-Zeitraum endet zur Jahresmitte -> im Jahr aktiv', function () {
    $id = maaMember('jahr-halb', [['2019-01-01', null, 'active'], ['2021-01-01', '2021-06-30', 'inactive']]);

    assertSame(1, (int) maaRow($id, 2021)['is_active_in_period'], '2021 gilt als inaktiv');
});

test('Jahr: Inaktiv-Zeitraum beginnt zur Jahresmitte -> im Jahr aktiv, danach inaktiv', function () {
    $id = maaMember('jahr-ab', [['2021-07-01', null, 'inactive']]);

    assertSame(1, (int) maaRow($id, 2021)['is_active_in_period'], '2021 gilt als inaktiv');
    assertSame(0, (int) maaRow($id, 2022)['is_active_in_period'], '2022 gilt als aktiv');
});

test('Jahr: Aktiv-Zeitraum endete im Vorjahr -> im Jahr inaktiv', function () {
    $id = maaMember('jahr-ende', [['2015-01-01', '2020-12-31', 'active']]);

    assertSame(0, (int) maaRow($id, 2021)['is_active_in_period'], '2021 gilt als aktiv');
    assertSame(1, (int) maaRow($id, 2020)['is_active_in_period'], '2020 gilt als inaktiv');
});

test('Jahr: Aktiv-Zeitraum vollstaendig von Inaktiv-Zeitraum ueberdeckt -> inaktiv', function () {
    $id = maaMember('jahr-ueberdeckt', [['2021-03-01', '2021-04-30', 'active'], ['2021-01-01', '2021-12-31', 'inactive']]);

    assertSame(0, (int) maaRow($id, 2021)['is_active_in_period'], '2021 gilt als aktiv');
});

test('Jahr: Aktiv-Zeitraum beginnt zur Jahresmitte -> im Jahr aktiv, im Vorjahr inaktiv', function () {
    $id = maaMember('jahr-beginn', [['2021-05-01', null, 'active']]);

    assertSame(1, (int) maaRow($id, 2021)['is_active_in_period'], '2021 gilt als inaktiv');
    assertSame(0, (int) maaRow($id, 2020)['is_active_in_period'], '2020 gilt als aktiv');
});

test('Jahresfilter ohne include_inactive folgt derselben Regel', function () {
    $id = maaMember('jahr-filter', [['2021-01-01', '2021-12-31', 'inactive']]);

    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['year' => 2021]]);
    assertStatus(200, $res);
    $ids = array_map(fn ($r) => (int) $r['member_id'], $res['body']);
    assertSame(false, in_array($id, $ids, true), 'Ganzjaehrig inaktives Mitglied steht in der Jahresliste');
});

test('Einzelabruf fuer Verwalter traegt is_active_today', function () {
    $id = maaMember('einzel', [[maaDay(-7), null, 'inactive']]);

    $res = apiRequest('GET', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
    assertStatus(200, $res);
    assertSame(0, (int) ($res['body']['is_active_today'] ?? -1), 'is_active_today fehlt oder zeigt aktiv');
});

test('Aufraeumen: die Suite entfernt ihre Mitglieder', function () {
    $rest = [];
    foreach (maaTrack() as $id) {
        $res = apiRequest('DELETE', 'members', ['token' => apiToken('admin'), 'query' => ['id' => $id]]);
        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "member {$id} (HTTP {$res['status']})";
        }
    }
    assertSame([], $rest, 'Nicht entfernt: ' . implode(', ', $rest));
});
