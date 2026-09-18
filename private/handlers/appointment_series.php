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
// APPOINTMENT_SERIES Controller (FI-7)
// ============================================
declare(strict_types=1);

function seriesRespond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
}

function handleAppointmentSeries($db, $database, $method, $id): void
{
    requireAdminOrManager();

    $prefix  = $database->table('');
    $tol     = checkinToleranceHours($db, $database);
    $region  = systemSetting($db, $database, 'holiday_region', '');
    $preview = ($_GET['preview'] ?? null) === '1';
    $action  = $_GET['action'] ?? null;

    $raw = [];
    if ($method === 'POST' || $method === 'PUT') {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    $series = null;
    if ($id) {
        $series = seriesLoad($db, $prefix, (int) $id);
        if ($series === null) {
            seriesRespond(404, ['message' => 'Serie nicht gefunden']);
            return;
        }
    } elseif (!($method === 'POST' && $action === null)) {
        seriesRespond(400, ['message' => 'id ist erforderlich']);
        return;
    }

    try {
        switch ($method) {
            case 'GET':
                seriesRespond(200, seriesSummary($db, $prefix, $series));
                return;

            case 'POST':
                if ($series === null) {
                    seriesHandleCreate($db, $prefix, $raw, $preview, $tol, $region);
                    return;
                }
                seriesRespond(400, ['message' => 'Unbekannte Aktion']);
                return;

            default:
                seriesRespond(405, ['message' => 'Methode nicht erlaubt']);
                return;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('appointment_series: ' . $e->getMessage());
        seriesRespond(500, ['message' => 'Die Serienaktion ist fehlgeschlagen']);
    }
}

/**
 * Terminart vorbelegen und pruefen -- wie POST appointments: ohne Angabe gilt
 * die Standard-Terminart.
 *
 * @return ?string Fehlermeldung
 */
function seriesResolveType(PDO $db, string $prefix, array &$raw): ?string
{
    if (!isset($raw['type_id']) || $raw['type_id'] === '' ) {
        $raw['type_id'] = seriesDefaultTypeId($db, $prefix);
    }
    if ($raw['type_id'] !== null && is_numeric($raw['type_id'])
        && !seriesTypeExists($db, $prefix, (int) $raw['type_id'])) {
        return 'Die Terminart existiert nicht';
    }

    return null;
}

function seriesHandleCreate(PDO $db, string $prefix, array $raw, bool $preview, int $tol, string $region): void
{
    $fehler = seriesResolveType($db, $prefix, $raw);
    if ($fehler !== null) {
        seriesRespond(400, ['message' => $fehler]);
        return;
    }
    [$def, $fehler] = seriesDefinitionFromRequest($raw);
    if ($fehler !== null) {
        seriesRespond(400, ['message' => $fehler]);
        return;
    }

    $plan = seriesPlan($db, $prefix, $def['rule'], $def['start_date'], $def['until'], $def['exdates'],
                       $def['template'], $tol, $region);
    if ($plan === []) {
        seriesRespond(400, ['message' => 'Die Regel ergibt in diesem Zeitraum keinen Termin']);
        return;
    }
    if ($preview) {
        seriesRespond(200, ['occurrences' => $plan, 'count' => seriesSelectableCount($plan)]);
        return;
    }

    $createdBy = getCurrentUserId() === null ? null : (int) getCurrentUserId();
    $db->beginTransaction();
    $seriesId = seriesInsertRow($db, $prefix, $def, $createdBy);
    $dates = array_column(array_filter($plan, fn (array $o): bool => !$o['excluded']), 'date');
    $result = seriesInsertOccurrences($db, $prefix, $seriesId, $def['template'], $dates, $tol, $createdBy);

    if ($result['created'] === 0) {
        $db->rollBack();
        seriesRespond(409, ['message' => 'Alle Termine der Serie kollidieren mit bestehenden Terminen oder sind abgewählt',
                            'skipped' => $result['skipped']]);
        return;
    }

    seriesSaveExdates($db, $prefix, $seriesId, array_merge($def['exdates'], $result['skipped_dates']));
    $db->commit();

    seriesRespond(201, ['series_id' => $seriesId, 'created' => $result['created'], 'skipped' => $result['skipped']]);
}
