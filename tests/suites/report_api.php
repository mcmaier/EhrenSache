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
