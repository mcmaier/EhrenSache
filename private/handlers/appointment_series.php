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

    // Vorschau schlaegt sicher fehl: jeder gesetzte Wert ausser '0' (preview=true,
    // preview=yes ...) rechnet nur und schreibt nichts.
    $preview = isset($_GET['preview']) && $_GET['preview'] !== '0';
    $action  = $_GET['action'] ?? null;

    try {
        $prefix = $database->table('');
        $tol    = checkinToleranceHours($db, $database);
        $region = systemSetting($db, $database, 'holiday_region', '');

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

            case 'PUT':
                seriesHandleUpdateFollowing($db, $prefix, $series, $raw, $tol);
                return;

            case 'DELETE':
                // is_string statt (string)-Cast: from[]=... liefert ein Array,
                // dessen Cast eine PHP-Warnung ausloest (samt Server-Pfad im
                // Log) statt einfach als ungueltiges Datum durchzufallen.
                $from = $_GET['from'] ?? '';
                seriesHandleEndFrom($db, $prefix, $series, is_string($from) ? $from : '');
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
    if ($raw['type_id'] === null) {
        return null;
    }
    $typeId = seriesParseTypeId($raw['type_id']);
    if ($typeId === null) {
        return 'Ungültige Terminart';
    }
    if (!seriesTypeExists($db, $prefix, $typeId)) {
        return 'Die Terminart existiert nicht';
    }
    $raw['type_id'] = $typeId;

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

/** Liegt $date gueltig in [start_date, until] der Serie? */
function seriesDateInRange(string $date, array $series): bool
{
    return seriesIsValidDate($date) && $date >= $series['start_date'] && $date <= $series['until'];
}

function seriesHandleEndFrom(PDO $db, string $prefix, array $series, string $from): void
{
    if (!seriesDateInRange($from, $series)) {
        seriesRespond(400, ['message' => 'Das Datum liegt nicht innerhalb der Serie']);
        return;
    }

    $db->beginTransaction();
    $result = seriesEndFrom($db, $prefix, $series, $from);
    $db->commit();

    seriesRespond(200, $result);
}

/**
 * "Dieser und alle folgenden" ohne Regelaenderung: Vorlage und die folgenden,
 * nicht abgeloesten Termine an Ort und Stelle aendern. IDs bleiben, damit
 * Rueckmeldungen und Anwesenheiten haengen bleiben.
 */
function seriesHandleUpdateFollowing(PDO $db, string $prefix, array $series, array $raw, int $tol): void
{
    // is_string statt (string)-Cast: ein nicht-stringer from_date-Wert im
    // JSON-Body (z. B. ein Array) soll als ungueltiges Datum durchfallen statt
    // eine PHP-Warnung (samt Server-Pfad im Log) auszuloesen.
    $fromRaw = $raw['from_date'] ?? '';
    $from = is_string($fromRaw) ? $fromRaw : '';
    if (!seriesDateInRange($from, $series)) {
        seriesRespond(400, ['message' => 'Das Datum liegt nicht innerhalb der Serie']);
        return;
    }

    $provided = array_values(array_intersect(SERIES_TEMPLATE_FIELDS, array_keys($raw)));
    if ($provided === []) {
        seriesRespond(200, ['updated' => 0, 'detached' => []]);
        return;
    }

    $merged = array_merge(seriesTemplateOf($series), array_intersect_key($raw, array_flip($provided)));
    [$template, $fehler] = seriesNormalizeTemplate($merged);
    if ($fehler === null && $template['type_id'] !== null && !seriesTypeExists($db, $prefix, $template['type_id'])) {
        $fehler = 'Die Terminart existiert nicht';
    }
    if ($fehler !== null) {
        seriesRespond(400, ['message' => $fehler]);
        return;
    }

    $checkConflicts = in_array('start_time', $provided, true) || in_array('type_id', $provided, true);
    $setClause = implode(', ', array_map(fn (string $f): string => "{$f} = ?", $provided));
    $setValues = array_map(fn (string $f) => $template[$f], $provided);

    $db->beginTransaction();

    $db->prepare("UPDATE {$prefix}appointment_series
                  SET title = ?, type_id = ?, description = ?, start_time = ?, end_time = ?, location = ?
                  WHERE series_id = ?")
       ->execute([$template['title'], $template['type_id'], $template['description'], $template['start_time'],
                  $template['end_time'], $template['location'], $series['series_id']]);

    $update = $db->prepare("UPDATE {$prefix}appointments SET {$setClause} WHERE appointment_id = ?");
    $detach = $db->prepare("UPDATE {$prefix}appointments SET is_detached = 1 WHERE appointment_id = ?");
    $updated  = 0;
    $detached = [];

    foreach (seriesFollowing($db, $prefix, $series['series_id'], $from) as $row) {
        if ($checkConflicts) {
            $conflict = findAppointmentConflict($db, $prefix, $row['date'], $template['start_time'],
                                                $template['type_id'], $tol, [$row['appointment_id']]);
            if ($conflict !== null) {
                $detach->execute([$row['appointment_id']]);
                $detached[] = ['appointment_id' => $row['appointment_id'], 'date' => $row['date'],
                               'reason' => 'conflict', 'conflict' => ['title' => $conflict['title'],
                               'start_time' => $conflict['start_time']]];
                continue;
            }
        }
        $update->execute(array_merge($setValues, [$row['appointment_id']]));
        $updated++;
    }

    $db->commit();

    seriesRespond(200, ['updated' => $updated, 'detached' => $detached]);
}
