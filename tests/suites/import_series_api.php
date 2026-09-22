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
 * CSV-Import und Terminserien (OI-75).
 *
 * Ein Import, der einen Serientermin tatsächlich ändert, löst ihn aus der
 * Serie -- wie ein Einzel-PUT. Ein Reimport derselben Werte ändert nichts und
 * lässt ihn in der Serie. Alle Daten liegen im Jahr 2031, jeder Test baut sich
 * eine eigene Terminart.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/api.php';

if (!extension_loaded('curl')) {
    return;
}

/** @return array{group: int, type: int, type_name: string} */
function isWorld(): array
{
    $suffix = uniqid();
    $group = apiRequest('POST', 'member_groups', ['token' => apiToken('admin'),
        'body' => ['group_name' => "IS {$suffix}"]]);
    assertStatus(201, $group);
    $type = apiRequest('POST', 'appointment_types', ['token' => apiToken('admin'),
        'body' => ['type_name' => "IS {$suffix}", 'is_default' => 0, 'color' => '#667eea',
                   'group_ids' => [(int) $group['body']['id']]]]);
    assertStatus(201, $type);

    return ['group' => (int) $group['body']['id'], 'type' => (int) $type['body']['id'],
            'type_name' => "IS {$suffix}"];
}

/** Termine der Terminart in 2031, nach Datum sortiert. */
function isAppointments(array $world): array
{
    $res = apiRequest('GET', 'appointments', ['token' => apiToken('admin'),
        'query' => ['type_id' => $world['type'], 'year' => 2031]]);
    assertStatus(200, $res);
    $list = array_values(array_filter($res['body'] ?? [], fn ($a) => (int) $a['type_id'] === $world['type']));
    usort($list, fn ($a, $b) => strcmp($a['date'], $b['date']));

    return $list;
}

function isAptOn(array $world, string $date): array
{
    foreach (isAppointments($world) as $apt) {
        if ($apt['date'] === $date) {
            return $apt;
        }
    }
    throw new RuntimeException("Kein Termin am {$date}");
}

function isDropWorld(array $world): void
{
    $token = apiToken('admin');
    $seriesIds = [];
    foreach (isAppointments($world) as $apt) {
        if (!empty($apt['series_id'])) {
            $seriesIds[(int) $apt['series_id']] = true;
        }
        apiRequest('DELETE', 'appointments', ['token' => $token, 'query' => ['id' => $apt['appointment_id']]]);
    }
    foreach (array_keys($seriesIds) as $sid) {
        $series = apiRequest('GET', 'appointment_series', ['token' => $token, 'query' => ['id' => $sid]]);
        if ($series['status'] !== 200 || empty($series['body']['start_date'])) {
            continue;
        }
        apiRequest('DELETE', 'appointment_series', ['token' => $token,
            'query' => ['id' => $sid, 'from' => $series['body']['start_date']]]);
    }
    apiRequest('DELETE', 'appointment_types', ['token' => $token, 'query' => ['id' => $world['type']]]);
    apiRequest('DELETE', 'member_groups', ['token' => $token, 'query' => ['id' => $world['group']]]);
}

/** Wöchentliche Serie dienstags, drei Termine ab 2031-03-04. */
function isSeries(array $world): int
{
    $res = apiRequest('POST', 'appointment_series', ['token' => apiToken('admin'), 'body' => [
        'rrule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU', 'start_date' => '2031-03-04', 'until' => '2031-03-18',
        'title' => 'IS-Probe', 'type_id' => $world['type'], 'start_time' => '19:30', 'end_time' => '22:00',
        'location' => 'Probelokal', 'description' => null,
    ]]);
    assertStatus(201, $res);

    return (int) $res['body']['series_id'];
}

/**
 * Lädt eine Termin-CSV über POST import hoch.
 *
 * @param array<int, array<string, string>> $rows
 */
function isImport(array $rows): array
{
    $header = array_keys($rows[0]);
    $lines = [implode(';', $header)];
    foreach ($rows as $row) {
        $lines[] = implode(';', array_map(fn ($col) => $row[$col], $header));
    }
    $tmp = tempnam(sys_get_temp_dir(), 'isimp');
    file_put_contents($tmp, implode("\n", $lines) . "\n");

    try {
        $cfg = testConfig();
        $ch = curl_init(rtrim($cfg['base_url'], '/') . '/api/api.php?resource=import&type=appointments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json',
                                              'Authorization: Bearer ' . apiToken('admin')]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($tmp, 'text/csv', 'termine.csv')]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } finally {
        unlink($tmp);
    }

    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
}

/** Eine CSV-Zeile für den Serientermin am Datum, Werte wie von der Serie angelegt. */
function isRow(array $world, string $date, array $extra = []): array
{
    return array_merge([
        'date' => $date, 'start_time' => '19:30:00', 'title' => 'IS-Probe', 'type_name' => $world['type_name'],
        'description' => '', 'end_time' => '22:00:00', 'location' => 'Probelokal',
    ], $extra);
}

test('import: ein geänderter Serientermin löst sich aus der Serie', function () {
    $world = isWorld();
    try {
        isSeries($world);
        $res = isImport([isRow($world, '2031-03-11', ['title' => 'IS-Probe verlegt'])]);
        assertStatus(200, $res);
        assertSame(1, (int) $res['body']['updated'], $res['raw']);

        $apt = isAptOn($world, '2031-03-11');
        assertSame('IS-Probe verlegt', $apt['title']);
        assertSame(1, (int) $apt['is_detached'], 'Der geänderte Termin muss abgelöst sein');
        assertSame(0, (int) isAptOn($world, '2031-03-18')['is_detached'], 'Nicht importierte Termine bleiben in der Serie');
    } finally {
        isDropWorld($world);
    }
});

test('import: eine geänderte Ortsangabe allein löst ebenfalls ab', function () {
    $world = isWorld();
    try {
        isSeries($world);
        assertStatus(200, isImport([isRow($world, '2031-03-11', ['location' => 'Aula'])]));
        assertSame(1, (int) isAptOn($world, '2031-03-11')['is_detached']);
    } finally {
        isDropWorld($world);
    }
});

test('import: ein Reimport derselben Werte lässt den Termin in der Serie', function () {
    $world = isWorld();
    try {
        isSeries($world);
        // Zeiten ohne Sekunden und leere Beschreibung: andere Schreibweise, gleicher Wert.
        $res = isImport([isRow($world, '2031-03-11', ['start_time' => '19:30', 'end_time' => '22:00'])]);
        assertStatus(200, $res);
        assertSame(1, (int) $res['body']['updated'], $res['raw']);
        assertSame(0, (int) isAptOn($world, '2031-03-11')['is_detached'], 'Unveränderter Reimport darf nicht ablösen');
    } finally {
        isDropWorld($world);
    }
});

test('import: eine CSV ohne Ort und Ende lässt beide stehen und löst nicht ab', function () {
    $world = isWorld();
    try {
        isSeries($world);
        $row = isRow($world, '2031-03-11');
        unset($row['location'], $row['end_time']);
        assertStatus(200, isImport([$row]));

        $apt = isAptOn($world, '2031-03-11');
        assertSame('Probelokal', $apt['location']);
        assertSame(0, (int) $apt['is_detached']);
    } finally {
        isDropWorld($world);
    }
});
