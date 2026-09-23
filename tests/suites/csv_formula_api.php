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
 * Freitext aus der Datenbank darf in keiner CSV als Formel ankommen (OI-59).
 *
 * Tabellenkalkulationen werten eine Zelle, die mit `=`, `+`, `-` oder `@`
 * beginnt, beim Oeffnen einer CSV-Datei als Formel aus (CWE-1236). Betroffen
 * sind alle Felder, die Mitglieder oder Manager selbst setzen und die
 * unveraendert in eine Datei gelangen -- Namen, Terminstitel, Bemerkungen,
 * Ausnahmegruende.
 *
 * Das Risiko trifft den Oeffnenden, nicht den Schreibenden: typischerweise ein
 * Vorstandsmitglied, das den Export prueft. Innerhalb von EhrenSache entsteht
 * keine Rechteausweitung, die Wirkung entsteht ausschliesslich ausserhalb.
 *
 * Geprueft wird der Weg, nicht die Funktion: echte Datensaetze mit einem
 * Formelzeichen am Anfang, abgerufen ueber die echten Exporte.
 */

/** Sammelt Angelegtes fuer das Aufraeumen. */
function csvTrack(string $art, int $id): int
{
    static $ids = ['member' => [], 'appointment' => [], 'appointment_type' => []];

    assertTrue(array_key_exists($art, $ids), "csvTrack(): unbekannte Art '{$art}'");

    if ($id > 0) {
        $ids[$art][] = $id;
    }

    return $id;
}

/** @return array<int, int> */
function csvTracked(string $art): array
{
    $ref = new ReflectionFunction('csvTrack');

    return $ref->getStaticVariables()['ids'][$art] ?? [];
}

/** Holt einen Export als Zeilenliste, ohne BOM. */
function csvZeilen(string $type, array $query = []): array
{
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('admin'),
        'query' => array_merge(['type' => $type, 'year' => date('Y')], $query),
    ]);
    assertStatus(200, $res, "Export '{$type}' nicht abrufbar");

    $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $res['raw']);

    return preg_split('/\r\n|\n|\r/', $raw) ?: [];
}

/** Die erste Zeile, die den Suchtext enthaelt. */
function csvZeileMit(array $zeilen, string $suchtext): ?string
{
    foreach ($zeilen as $zeile) {
        if (strpos($zeile, $suchtext) !== false) {
            return $zeile;
        }
    }

    return null;
}

test('export members: ein Name mit fuehrendem = kommt maskiert an', function () {
    $marke = 'CSVPROBE' . strtoupper(substr(uniqid(), -6));
    $name  = '=HYPERLINK("http://example.invalid";"' . $marke . '")';

    $res = apiRequest('POST', 'members', [
        'token' => apiToken('admin'),
        'body'  => [
            'name'          => $name,
            'surname'       => 'Formelprobe',
            'member_number' => 'CSV' . substr(uniqid(), -5),
            'active'        => 1,
        ],
    ]);
    assertStatus(201, $res);
    csvTrack('member', (int) $res['body']['id']);

    $zeile = csvZeileMit(csvZeilen('members'), $marke);
    assertTrue($zeile !== null, 'Das angelegte Mitglied steht nicht im Export');

    $felder = str_getcsv($zeile, ';');
    assertTrue(
        strpos($felder[0], "'") === 0,
        "Die Zelle beginnt unmaskiert mit einem Formelzeichen: {$felder[0]}"
    );
    assertTrue(
        strpos($felder[0], $marke) !== false,
        'Der Inhalt selbst muss erhalten bleiben, nur die Auswertung darf nicht greifen'
    );
});

test('export appointments: ein Titel mit fuehrendem = kommt maskiert an', function () {
    $marke = 'CSVTERMIN' . strtoupper(substr(uniqid(), -6));

    $art = apiRequest('POST', 'appointment_types', [
        'token' => apiToken('admin'),
        'body'  => ['type_name' => 'CSV-Probeart ' . uniqid()],
    ]);
    assertStatus(201, $art);
    $artId = csvTrack('appointment_type', (int) $art['body']['id']);

    $res = apiRequest('POST', 'appointments', [
        'token' => apiToken('admin'),
        'body'  => [
            'title'      => '=1+1 ' . $marke,
            'type_id'    => $artId,
            'date'       => date('Y-m-d', strtotime('+70 days')),
            'start_time' => '18:15:00',
        ],
    ]);
    assertStatus(201, $res);
    csvTrack('appointment', (int) $res['body']['id']);

    $zeile = csvZeileMit(csvZeilen('appointments'), $marke);
    assertTrue($zeile !== null, 'Der angelegte Termin steht nicht im Export');

    $felder = str_getcsv($zeile, ';');
    $titel  = $felder[2] ?? '';
    assertTrue(
        strpos($titel, "'") === 0,
        "Der Titel beginnt unmaskiert mit einem Formelzeichen: {$titel}"
    );
});

test('export appointments: eine gewoehnliche Zeile bleibt unveraendert', function () {
    // Gegenprobe: Die Maskierung darf nur greifen, wo sie muss. Sonst traegt
    // jede zweite Zelle ein Apostroph, das die Empfaenger zu sehen bekommen.
    $marke = 'CSVKLAR' . strtoupper(substr(uniqid(), -6));

    $art = apiRequest('POST', 'appointment_types', [
        'token' => apiToken('admin'),
        'body'  => ['type_name' => 'CSV-Klarart ' . uniqid()],
    ]);
    assertStatus(201, $art);
    $artId = csvTrack('appointment_type', (int) $art['body']['id']);

    $res = apiRequest('POST', 'appointments', [
        'token' => apiToken('admin'),
        'body'  => [
            'title'      => 'Probe ' . $marke,
            'type_id'    => $artId,
            'date'       => date('Y-m-d', strtotime('+71 days')),
            'start_time' => '18:30:00',
        ],
    ]);
    assertStatus(201, $res);
    csvTrack('appointment', (int) $res['body']['id']);

    $zeile = csvZeileMit(csvZeilen('appointments'), $marke);
    assertTrue($zeile !== null, 'Der angelegte Termin steht nicht im Export');

    $felder = str_getcsv($zeile, ';');
    assertSame('Probe ' . $marke, $felder[2] ?? '',
        'Ein harmloser Titel darf kein Apostroph bekommen');
});

test('Aufraeumen: die Suite entfernt alles, was sie angelegt hat', function () {
    $rest = [];

    foreach (csvTracked('appointment') as $id) {
        $res = apiRequest('DELETE', 'appointments', [
            'token' => apiToken('admin'), 'query' => ['id' => $id],
        ]);
        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "appointment {$id} (HTTP {$res['status']})";
        }
    }

    foreach (csvTracked('appointment_type') as $id) {
        $res = apiRequest('DELETE', 'appointment_types', [
            'token' => apiToken('admin'), 'query' => ['id' => $id],
        ]);
        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "appointment_type {$id} (HTTP {$res['status']})";
        }
    }

    foreach (csvTracked('member') as $id) {
        $res = apiRequest('DELETE', 'members', [
            'token' => apiToken('admin'), 'query' => ['id' => $id],
        ]);
        if (!in_array($res['status'], [200, 404], true)) {
            $rest[] = "member {$id} (HTTP {$res['status']})";
        }
    }

    assertSame([], $rest, 'Nicht alles konnte entfernt werden');
});
