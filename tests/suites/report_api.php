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

// ============================================
// BERICHTE: Anwesenheitsbericht als Druckansicht
// ============================================

test('statistics_report: Admin erhaelt eine Berichtsseite', function () {
    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $res);
    assertTrue(strpos($res['raw'], '<!DOCTYPE html>') === 0, 'HTML-Dokument erwartet');
    assertTrue(strpos($res['raw'], 'css/print.css') !== false, 'print.css erwartet');
    assertTrue(strpos($res['raw'], 'Anwesenheitsbericht') !== false, 'Titel erwartet');
});

test('statistics_report: der Bericht enthaelt kein JavaScript', function () {
    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    assertTrue(stripos($res['raw'], '<script') === false, 'kein script-Tag erwartet');
    assertTrue(stripos($res['raw'], 'window.print') === false, 'kein Auto-Druck erwartet');
});

test('statistics_report: nur GET', function () {
    $res = apiRequest('POST', 'statistics_report', [
        'token' => apiToken('admin'),
        'body'  => ['year' => date('Y')],
    ]);
    assertStatus(405, $res);
});

test('statistics_report: die Kennzahlen stehen im Bericht', function () {
    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $res);
    foreach (['Termine gesamt', 'Anwesend', 'Entschuldigt', 'Unentschuldigt',
              'Durchschnittliche Anwesenheitsquote'] as $label) {
        assertTrue(strpos($res['raw'], $label) !== false, "Kennzahl '{$label}' erwartet");
    }
});

test('statistics_report: Bericht und JSON zeigen dieselbe Quote', function () {
    $json = apiRequest('GET', 'statistics', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $json);

    $average  = $json['body']['summary']['overall_average'];
    $expected = number_format((float) $average, 1, ',', '') . ' %';

    $html = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $html);
    assertTrue(strpos($html['raw'], $expected) !== false,
               "Quote {$expected} im Bericht erwartet");
});

test('statistics_report: Bericht und JSON zeigen dieselben Kennzahlen', function () {
    // Der vorige Test prueft nur overall_average. Die Formel, um die es bei
    // 'excused' geht (total - attended - unexcused), steckt aber in den
    // anderen drei Kennzahlen -- die deckt dieser Test ab, indem er die
    // exakte Tabellenzeile (Label + Wert) im Markup sucht, nicht nur die Zahl.
    $json = apiRequest('GET', 'statistics', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $json);
    $summary = $json['body']['summary'];

    $html = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $html);

    $labels = [
        'total_appointments' => 'Termine gesamt',
        'total_present'      => 'Anwesend',
        'total_excused'      => 'Entschuldigt',
        'total_unexcused'    => 'Unentschuldigt',
    ];
    foreach ($labels as $key => $label) {
        $expected = '<td>' . $label . '</td><td>' . $summary[$key] . '</td>';
        assertTrue(strpos($html['raw'], $expected) !== false,
                   "Kennzahl '{$label}' ({$summary[$key]}) im Bericht erwartet");
    }
});

test('statistics_report: Sonderzeichen im Gruppennamen werden maskiert', function () {
    // reportEscape() ist laut eigenem Kommentar die einzige Verteidigungslinie
    // gegen gespeichertes XSS. Bisher indirekt geprueft (kein <script> im
    // Markup) -- hier direkt an einem lebenden Bericht mit einem Namen, der
    // Markup, Et-Zeichen und Anfuehrungszeichen mischt.
    $admin     = apiToken('admin');
    $groupName = '<b>Test</b> & "Co" ' . uniqid();

    $groupRes = apiRequest('POST', 'member_groups', [
        'token' => $admin,
        'body'  => ['group_name' => $groupName],
    ]);
    assertStatus(201, $groupRes, 'Testgruppe konnte nicht angelegt werden');
    $groupId = (int) $groupRes['body']['id'];

    $typeRes = apiRequest('POST', 'appointment_types', [
        'token' => $admin,
        'body'  => ['type_name' => 'XSS-Test-Terminart ' . uniqid(), 'group_ids' => [$groupId]],
    ]);
    assertStatus(201, $typeRes, 'Test-Terminart konnte nicht angelegt werden');
    $typeId = (int) $typeRes['body']['id'];

    try {
        $res = apiRequest('GET', 'statistics_report', [
            'token' => $admin,
            'query' => ['year' => date('Y'), 'group_id' => $groupId],
        ]);
        assertStatus(200, $res);
        assertTrue(
            strpos($res['raw'], '&lt;b&gt;Test&lt;/b&gt; &amp; &quot;Co&quot;') !== false,
            'maskierter Gruppenname erwartet'
        );
        assertTrue(strpos($res['raw'], '<b>Test</b>') === false,
                   'rohes <b> darf nicht im Markup stehen');
    } finally {
        // Aufraeumen -- Terminart vor der Gruppe, wegen appointment_type_groups.
        assertStatus(200, apiRequest('DELETE', 'appointment_types', [
            'token' => $admin, 'query' => ['id' => $typeId],
        ]), 'Test-Terminart konnte nicht geloescht werden');
        assertStatus(200, apiRequest('DELETE', 'member_groups', [
            'token' => $admin, 'query' => ['id' => $groupId],
        ]), 'Testgruppe konnte nicht geloescht werden');
    }
});

// ============================================
// BERICHTE: Terminliste je Person
// ============================================

test('statistics_report: ohne Mitgliedsfilter gibt es keine Terminliste', function () {
    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $res);
    assertTrue(strpos($res['raw'], 'Termine im Einzelnen') === false,
               'ohne Mitgliedsfilter keine Terminliste erwartet');
});

test('statistics_report: mit Mitgliedsfilter erscheint die Terminliste', function () {
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y'), 'member_id' => $memberId],
    ]);
    assertStatus(200, $res);
    assertTrue(strpos($res['raw'], 'Termine im Einzelnen') !== false, 'Terminliste erwartet');
    assertTrue(strpos($res['raw'], 'Herkunft') !== false, 'Spalte Herkunft erwartet');
});

test('statistics_report: die Terminliste deckt genau die gezaehlten Termine ab', function () {
    $memberId = apiMemberId('user');

    $json = apiRequest('GET', 'statistics', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y'), 'member_id' => $memberId],
    ]);
    assertStatus(200, $json);

    // Summe der gezaehlten Termine ueber alle Gruppen des Mitglieds. Mehrere
    // Gruppen koennen an derselben Terminart haengen -- dann zaehlt der Termin
    // in der Liste nur einmal, deshalb wird nach Terminart entdoppelt.
    $perType = [];
    foreach ($json['body']['statistics'] as $group) {
        $typeId = $group['appointment_type_id'];
        $total  = $group['members'][0]['total_appointments'] ?? 0;
        $perType[$typeId] = $total;
    }
    $expected = array_sum($perType);

    $html = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y'), 'member_id' => $memberId],
    ]);
    assertStatus(200, $html);

    // Zeilen der Terminliste zaehlen: der Abschnitt ab der Ueberschrift bis
    // zum schliessenden </table>.
    $start = strpos($html['raw'], 'Termine im Einzelnen');
    assertTrue($start !== false, 'Terminliste erwartet');
    $end     = strpos($html['raw'], '</table>', $start);
    $section = substr($html['raw'], $start, $end - $start);
    $rows    = substr_count($section, '<tr>') - 1; // Kopfzeile abziehen

    assertSame($expected, $rows,
        "Terminliste soll genau die gezaehlten Termine zeigen (Quote rechnet mit {$expected})");
});

test('statistics_report: die Fussnote nennt die ausgewertete Terminart', function () {
    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $res);
    assertTrue(strpos($res['raw'], 'Ausgewertet wurden je Gruppe') !== false,
               'Fussnote zur Terminart erwartet');
});

test('statistics_report: die Herkunftsstufen sind erklaert', function () {
    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y')],
    ]);
    foreach (['gemessen:', 'korrigiert:', 'nachgetragen:'] as $begriff) {
        assertTrue(strpos($res['raw'], $begriff) !== false, "Fussnote '{$begriff}' erwartet");
    }
});

test('statistics_report: entschuldigte Termine tragen keine Ankunftszeit', function () {
    // Im Bestand gibt es genau einen verwertbaren excused-Eintrag; faellt er
    // aus der Auswertung (siehe OI-48), greift dieser Test ins Leere. Er prueft
    // deshalb die Regel, nicht das Vorhandensein: Wo 'Entschuldigt' steht,
    // duerfen Ankunft und Herkunft nicht gefuellt sein.
    $memberId = apiMemberId('user');
    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('admin'),
        'query' => ['year' => date('Y'), 'member_id' => $memberId],
    ]);
    assertStatus(200, $res);

    if (preg_match_all('#<td>Entschuldigt</td><td>([^<]*)</td><td>([^<]*)</td>#', $res['raw'], $m)) {
        foreach ($m[1] as $i => $ankunft) {
            assertSame('', $ankunft, 'entschuldigt: Ankunft muss leer sein');
            assertSame('', $m[2][$i], 'entschuldigt: Herkunft muss leer sein');
        }
    }
});
