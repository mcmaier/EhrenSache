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

// ============================================
// HOLIDAYS Controller (FI-16)
// ============================================
declare(strict_types=1);

const HOLIDAYS_MAX_RANGE_DAYS = 400;

/**
 * Feiertage im Zeitraum fuer das eingestellte Bundesland. Lesen darf jede
 * angemeldete Rolle ausser Geraeten -- den Kalender sieht jeder.
 */
function handleHolidays($db, $database, $method): void
{
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['message' => 'Methode nicht erlaubt'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (isDevice()) {
        http_response_code(403);
        echo json_encode(['message' => 'Zugriff verweigert'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $from = is_string($_GET['from'] ?? null) ? $_GET['from'] : '';
    $to   = is_string($_GET['to'] ?? null) ? $_GET['to'] : '';
    if (!seriesIsValidDate($from) || !seriesIsValidDate($to) || $to < $from
        || seriesDate($from)->diff(seriesDate($to))->days > HOLIDAYS_MAX_RANGE_DAYS) {
        http_response_code(400);
        echo json_encode(['message' => 'Ungültiger Zeitraum (höchstens ' . HOLIDAYS_MAX_RANGE_DAYS . ' Tage)'],
                         JSON_UNESCAPED_UNICODE);
        return;
    }

    $region = systemSetting($db, $database, 'holiday_region', '');
    $region = array_key_exists($region, HOLIDAY_REGIONS) ? $region : null;

    echo json_encode([
        'region'   => $region,
        'holidays' => (object) holidaysBetween($from, $to, $region),
    ], JSON_UNESCAPED_UNICODE);
}
