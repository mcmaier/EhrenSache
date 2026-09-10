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
    // Gezaehlt wird die Datumsspalte, nicht <tr>: Das Muster kommt nur in
    // Datenzeilen vor, nie in der Kopfzeile -- kein Abzug noetig. Eine
    // spaetere Summen- oder Zwischenzeile im Abschnitt wuerde diesen Test
    // sonst rot faerben, ohne dass am Bericht etwas falsch waere.
    $rows = preg_match_all('#<td>\d{2}\.\d{2}\.\d{4}</td>#', $section);

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

// ============================================
// EXPORT: Stundennachweis fuer die eigene Person
// ============================================

test('export: user erhaelt den eigenen Stundennachweis als HTML', function () {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'worktime_member', 'format' => 'html', 'year' => date('Y')],
    ]);
    assertStatus(200, $res);
    assertTrue(strpos($res['raw'], 'Stundennachweis') !== false, 'Titel erwartet');
});

test('export: user ohne format bekommt kein CSV', function () {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'worktime_member', 'year' => date('Y')],
    ]);
    assertStatus(403, $res);
});

test('export: user bekommt auch mit format=csv kein CSV', function () {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'worktime_member', 'format' => 'csv', 'year' => date('Y')],
    ]);
    assertStatus(403, $res);
});

test('export: user darf keine Summen nach Taetigkeit holen', function () {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'worktime_activity', 'format' => 'html', 'year' => date('Y')],
    ]);
    assertStatus(403, $res);
});

test('export: user darf keine Summen nach Termin holen', function () {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'worktime_appointment', 'format' => 'html', 'year' => date('Y')],
    ]);
    assertStatus(403, $res);
});

test('export: user darf keine Mitgliederliste holen', function () {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'members'],
    ]);
    assertStatus(403, $res);
});

test('export: user darf keine Anwesenheitsliste holen', function () {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'records'],
    ]);
    assertStatus(403, $res);
});

test('export: user darf keine Terminliste holen', function () {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'appointments'],
    ]);
    assertStatus(403, $res);
});

test('export: user darf keinen unbekannten Typ holen', function () {
    $res = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'gibt_es_nicht', 'format' => 'html'],
    ]);
    // 403, nicht 400: Ein unbekannter Typ ist fuer diese Rolle zuerst einmal
    // verboten. Die Typpruefung ist nicht ihre Sache.
    assertStatus(403, $res);
});

test('export: eine fremde member_id im Stundennachweis wird ignoriert', function () {
    $ownId = apiMemberId('user');
    assertTrue($ownId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    $ohne = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'worktime_member', 'format' => 'html', 'year' => date('Y')],
    ]);
    $mit = apiRequest('GET', 'export', [
        'token' => apiToken('user'),
        'query' => ['type' => 'worktime_member', 'format' => 'html', 'year' => date('Y'),
                    'member_id' => (string) ($ownId + 1)],
    ]);

    // Kein 403: Der Parameter wird ignoriert, nicht abgewiesen. Eine
    // Fehlermeldung waere ein Orakel darueber, welche IDs existieren.
    assertStatus(200, $mit);

    // Und er darf wirklich wirkungslos sein -- nicht nur den Status, auch den
    // Inhalt. Das Erstellungsdatum faellt raus, es koennte zwischen den beiden
    // Abrufen umspringen.
    $norm = fn(string $html) => preg_replace('/Erstellt am [0-9.]+/', '', $html);
    assertSame($norm($ohne['raw']), $norm($mit['raw']),
               'fremde member_id darf den Bericht nicht veraendern');
});

test('export: der Stundennachweis eines Managers bleibt vollstaendig', function () {
    // Gegenprobe zur Einschraenkung: Was fuer user beschnitten wird, muss fuer
    // manager unveraendert funktionieren -- auch als CSV.
    $html = apiRequest('GET', 'export', [
        'token' => apiToken('manager'),
        'query' => ['type' => 'worktime_member', 'format' => 'html', 'year' => date('Y')],
    ]);
    assertStatus(200, $html);

    $csv = apiRequest('GET', 'export', [
        'token' => apiToken('manager'),
        'query' => ['type' => 'worktime_member', 'year' => date('Y')],
    ]);
    assertStatus(200, $csv);
    assertTrue(strpos($csv['raw'], 'member_name') !== false, 'CSV-Kopfzeile erwartet');
});

// ============================================
// BERICHTE: Anwesenheitsbericht in der Rolle user
// ============================================
//
// Die Rechtelogik in handleStatisticsReport() ist bereits gebaut und wurde
// beim Review von Hand nachgestellt -- alle bisherigen Tests dieser Ressource
// liefen aber mit dem Admin-Token. Die folgenden Tests verankern das
// Verhalten fuer user (und als Gegenprobe fuer manager), damit ein spaeterer
// Umbau es nicht stillschweigend aendern kann.

test('statistics_report: user bekommt den eigenen Bericht', function () {
    $memberId = apiMemberId('user');
    assertTrue($memberId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('user'),
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $res);
    assertTrue(strpos($res['raw'], '<!DOCTYPE html>') === 0, 'HTML-Dokument erwartet');
    assertTrue(strpos($res['raw'], 'Anwesenheitsbericht') !== false, 'Titel erwartet');
    // Fuer diese Rolle ist die Person immer festgelegt (memberId = authMemberId
    // in handleStatisticsReport()), die Terminliste erscheint deshalb immer --
    // auch ganz ohne member_id-Parameter.
    assertTrue(strpos($res['raw'], 'Termine im Einzelnen') !== false, 'Terminliste erwartet');
});

test('statistics_report: eine fremde member_id bleibt wirkungslos', function () {
    $ownId = apiMemberId('user');
    assertTrue($ownId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    // Eine echte, existierende fremde ID besorgen -- nicht einfach eigene+1,
    // die muss nicht existieren. Wuerde sie nicht existieren, prueft dieser
    // Test gar nichts (member_id waere so oder so wirkungslos).
    $admin   = apiToken('admin');
    $members = apiRequest('GET', 'members', ['token' => $admin]);
    assertStatus(200, $members);
    $foreignId = null;
    foreach ($members['body'] as $m) {
        if ((int) $m['member_id'] !== $ownId) {
            $foreignId = (int) $m['member_id'];
            break;
        }
    }
    assertTrue($foreignId !== null, 'Bestand braucht mindestens ein zweites Mitglied');

    $ohne = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('user'),
        'query' => ['year' => date('Y')],
    ]);
    $mit = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('user'),
        'query' => ['year' => date('Y'), 'member_id' => $foreignId],
    ]);

    // Ein assertStatus(200) allein waere hier kein Beweis: Er waere auch gruen,
    // wenn der Bericht tatsaechlich die fremde Person zeigte. Deshalb den
    // Inhalt vergleichen, nicht nur den Status. Das Erstellungsdatum faellt
    // vorher raus, es koennte zwischen den beiden Abrufen umspringen.
    assertStatus(200, $mit);
    $norm = fn(string $html) => preg_replace('/Erstellt am [0-9.]+/', '', $html);
    assertSame($norm($ohne['raw']), $norm($mit['raw']),
               'fremde member_id darf den Bericht nicht veraendern');
});

test('statistics_report: eine fremde group_id wird abgewiesen', function () {
    $ownId = apiMemberId('user');
    assertTrue($ownId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    // Gruppen des Testmitglieds als admin ermitteln, um daraus eine echte
    // fremde group_id abzuleiten -- nicht geraten.
    $admin  = apiToken('admin');
    $detail = apiRequest('GET', 'members', ['token' => $admin, 'query' => ['id' => $ownId]]);
    assertStatus(200, $detail);
    $ownGroupIds = array_map(fn($g) => (int) $g['group_id'], $detail['body']['groups'] ?? []);

    $groups = apiRequest('GET', 'member_groups', ['token' => $admin]);
    assertStatus(200, $groups);
    $foreignGroupId = null;
    foreach ($groups['body'] as $g) {
        if (!in_array((int) $g['group_id'], $ownGroupIds, true)) {
            $foreignGroupId = (int) $g['group_id'];
            break;
        }
    }
    assertTrue($foreignGroupId !== null,
        'Bestand braucht eine Gruppe, der das Testmitglied nicht angehoert');

    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('user'),
        'query' => ['year' => date('Y'), 'group_id' => $foreignGroupId],
    ]);
    assertStatus(403, $res);
});

test('statistics_report: mit der eigenen group_id liefert der Bericht 200', function () {
    // Gegenstueck zum vorigen Test: Die Ablehnung darf sich nicht auf jede
    // group_id erstrecken, nur auf fremde -- sonst waere hasStatisticsGroupAccess()
    // versehentlich zu streng geworden.
    $ownId = apiMemberId('user');
    assertTrue($ownId !== null, 'Testkonto user braucht ein verknuepftes Mitglied');

    $admin  = apiToken('admin');
    $detail = apiRequest('GET', 'members', ['token' => $admin, 'query' => ['id' => $ownId]]);
    assertStatus(200, $detail);
    $ownGroupIds = array_map(fn($g) => (int) $g['group_id'], $detail['body']['groups'] ?? []);
    assertTrue($ownGroupIds !== [], 'Testmitglied braucht mindestens eine eigene Gruppe');

    $res = apiRequest('GET', 'statistics_report', [
        'token' => apiToken('user'),
        'query' => ['year' => date('Y'), 'group_id' => $ownGroupIds[0]],
    ]);
    assertStatus(200, $res);
});

test('statistics_report: manager sieht weiterhin alle Mitglieder', function () {
    // Gegenprobe zur Einschraenkung fuer user: Wer bereits alles sehen darf,
    // muss das auch nach dieser Aenderung noch duerfen. Zwei tatsaechliche
    // Mitgliedsnamen aus der JSON-Antwort im Bericht wiederfinden, statt nur
    // auf 200 zu pruefen -- ein Bericht mit nur der eigenen Person waere sonst
    // auch gruen.
    $manager = apiToken('manager');

    $json = apiRequest('GET', 'statistics', [
        'token' => $manager,
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $json);
    $names = [];
    foreach ($json['body']['statistics'] as $group) {
        foreach ($group['members'] as $m) {
            $names[$m['member_name']] = true;
        }
    }
    $names = array_keys($names);
    assertTrue(count($names) > 1, 'Bestand braucht mehr als ein Mitglied fuer diese Pruefung');

    $res = apiRequest('GET', 'statistics_report', [
        'token' => $manager,
        'query' => ['year' => date('Y')],
    ]);
    assertStatus(200, $res);
    assertTrue(strpos($res['raw'], $names[0]) !== false, "Mitglied '{$names[0]}' im Bericht erwartet");
    assertTrue(strpos($res['raw'], $names[1]) !== false, "Mitglied '{$names[1]}' im Bericht erwartet");
});
