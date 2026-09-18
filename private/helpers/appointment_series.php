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
 * Terminserien (FI-7): lesen, pruefen, planen und schreiben.
 *
 * Eine Serie ist eine Regel plus Vorlage; die Termine selbst sind
 * gewoehnliche appointments mit series_id. is_detached = 1 heisst: sichtbar
 * Teil der Serie, von Serienaktionen aber ausgenommen.
 *
 * Grundsatz: Serienaktionen loeschen nie einen Termin mit erfassten Daten
 * (appointmentHasData()), sie loesen ihn ab und melden ihn.
 *
 * Spec: docs/superpowers/specs/2026-09-18-terminserien-feiertage-design.md
 */
declare(strict_types=1);

const SERIES_TEMPLATE_FIELDS = ['title', 'type_id', 'description', 'start_time', 'end_time', 'location'];

/**
 * Vorlagenfelder pruefen und normalisieren.
 *
 * @return array{0: ?array, 1: ?string} [Vorlage, Fehlermeldung]
 */
function seriesNormalizeTemplate(array $raw): array
{
    $title = is_string($raw['title'] ?? null) ? trim($raw['title']) : '';
    if ($title === '') {
        return [null, 'Der Titel ist erforderlich'];
    }
    if (mb_strlen($title) > 200) {
        return [null, 'Der Titel darf höchstens 200 Zeichen lang sein'];
    }

    $typeId = $raw['type_id'] ?? null;
    if ($typeId === '') {
        $typeId = null;
    }
    if ($typeId !== null) {
        $typeId = seriesParseTypeId($typeId);
        if ($typeId === null) {
            return [null, 'Ungültige Terminart'];
        }
    }

    $description = $raw['description'] ?? null;
    if ($description !== null && !is_string($description)) {
        return [null, 'Die Beschreibung muss Text sein'];
    }
    $description = ($description === null || trim($description) === '') ? null : trim($description);

    $start = $raw['start_time'] ?? null;
    if (!is_string($start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', trim($start))) {
        return [null, 'Die Startzeit muss als Uhrzeit HH:MM angegeben werden'];
    }
    $start = appointmentTimeKey($start);

    [$end, $fehler] = appointmentNormalizeEndTime($raw['end_time'] ?? null, $start);
    if ($fehler !== null) {
        return [null, $fehler];
    }
    [$location, $fehler] = appointmentNormalizeLocation($raw['location'] ?? null);
    if ($fehler !== null) {
        return [null, $fehler];
    }

    return [[
        'title' => $title, 'type_id' => $typeId, 'description' => $description,
        'start_time' => $start, 'end_time' => $end, 'location' => $location,
    ], null];
}

/**
 * Terminart als positive Ganzzahl; 3.7, true oder '5x' sind ungueltig
 * und werden nicht still abgeschnitten.
 *
 * @param mixed $raw
 */
function seriesParseTypeId($raw): ?int
{
    if (is_bool($raw)) {
        return null;
    }
    $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $id === false ? null : $id;
}

/** @return array{0: ?string[], 1: ?string} */
function seriesNormalizeExdates($raw): array
{
    if ($raw === null) {
        return [[], null];
    }
    if (!is_array($raw)) {
        return [null, 'exdates muss eine Liste von Daten sein'];
    }
    foreach ($raw as $date) {
        if (!is_string($date) || !seriesIsValidDate($date)) {
            return [null, 'exdates enthält ein ungültiges Datum'];
        }
    }
    $dates = array_values(array_unique($raw));
    sort($dates);

    return [$dates, null];
}

/**
 * Vollstaendige Seriendefinition aus einem Anfragekoerper.
 *
 * @return array{0: ?array, 1: ?string}
 */
function seriesDefinitionFromRequest(array $raw): array
{
    if (!is_string($raw['rrule'] ?? null) || trim($raw['rrule']) === '') {
        return [null, 'Die Regel fehlt'];
    }
    try {
        $rule = parseRrule($raw['rrule']);
    } catch (InvalidArgumentException $e) {
        return [null, $e->getMessage()];
    }

    // Kein (string)-Cast: ein Array erzeugte eine Warnung samt Serverpfad in der Antwort.
    $start = is_string($raw['start_date'] ?? null) ? $raw['start_date'] : '';
    $until = is_string($raw['until'] ?? null) ? $raw['until'] : '';
    if (!seriesIsValidDate($start)) {
        return [null, 'Ungültiges Anfangsdatum'];
    }
    if (!seriesIsValidDate($until)) {
        return [null, 'Ungültiges Enddatum'];
    }
    if ($until < $start) {
        return [null, 'Das Ende liegt vor dem Beginn'];
    }
    if ($until > seriesMaxUntil($start)) {
        return [null, 'Eine Serie umfasst höchstens ' . SERIES_MAX_MONTHS . ' Monate'];
    }

    [$exdates, $fehler] = seriesNormalizeExdates($raw['exdates'] ?? null);
    if ($fehler !== null) {
        return [null, $fehler];
    }
    [$template, $fehler] = seriesNormalizeTemplate($raw);
    if ($fehler !== null) {
        return [null, $fehler];
    }

    return [[
        'rule' => $rule, 'rrule' => buildRrule($rule), 'start_date' => $start, 'until' => $until,
        'exdates' => $exdates, 'template' => $template,
    ], null];
}

function seriesDefaultTypeId(PDO $db, string $prefix): ?int
{
    $id = $db->query("SELECT type_id FROM {$prefix}appointment_types WHERE is_default = 1 LIMIT 1")->fetchColumn();

    return $id === false ? null : (int) $id;
}

function seriesTypeExists(PDO $db, string $prefix, int $typeId): bool
{
    $stmt = $db->prepare("SELECT 1 FROM {$prefix}appointment_types WHERE type_id = ?");
    $stmt->execute([$typeId]);

    return (bool) $stmt->fetchColumn();
}

function seriesLoad(PDO $db, string $prefix, int $seriesId): ?array
{
    $stmt = $db->prepare("SELECT * FROM {$prefix}appointment_series WHERE series_id = ?");
    $stmt->execute([$seriesId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['series_id'] = (int) $row['series_id'];
    $row['type_id']   = $row['type_id'] === null ? null : (int) $row['type_id'];
    $exdates = json_decode((string) ($row['exdates'] ?? ''), true);
    $row['exdates'] = is_array($exdates) ? $exdates : [];

    return $row;
}

function seriesTemplateOf(array $series): array
{
    return array_intersect_key($series, array_flip(SERIES_TEMPLATE_FIELDS));
}

function seriesSaveExdates(PDO $db, string $prefix, int $seriesId, array $dates): void
{
    $dates = array_values(array_unique($dates));
    sort($dates);
    $db->prepare("UPDATE {$prefix}appointment_series SET exdates = ? WHERE series_id = ?")
       ->execute([json_encode($dates), $seriesId]);
}

function seriesAddExdates(PDO $db, string $prefix, int $seriesId, array $dates): void
{
    $series = seriesLoad($db, $prefix, $seriesId);
    if ($series !== null) {
        seriesSaveExdates($db, $prefix, $seriesId, array_merge($series['exdates'], $dates));
    }
}

function seriesInsertRow(PDO $db, string $prefix, array $def, ?int $createdBy): int
{
    $t = $def['template'];
    $db->prepare("INSERT INTO {$prefix}appointment_series
                    (rrule, start_date, `until`, exdates, title, type_id, description, start_time, end_time, location, created_by)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
       ->execute([$def['rrule'], $def['start_date'], $def['until'], json_encode(array_values($def['exdates'])),
                  $t['title'], $t['type_id'], $t['description'], $t['start_time'], $t['end_time'], $t['location'],
                  $createdBy]);

    return (int) $db->lastInsertId();
}

/**
 * Probelauf: alle Daten der Regel im Zeitraum mit Feiertag, Kollision und
 * Abwahl. Dieselbe Funktion rechnet fuer Vorschau und Schreiben.
 *
 * @param int[]   $excludeIds Termine, die fuer die Kollision nicht zaehlen (Split)
 * @param ?string $anchorDate Beginn der Serie, an dem der Wochentakt haengt (Fortsetzen);
 *                            null = $from
 * @return array<int, array{date: string, holiday: ?string, conflict: ?array, excluded: bool}>
 */
function seriesPlan(PDO $db, string $prefix, array $rule, string $from, string $until, array $exdates,
                    array $template, int $toleranceHours, string $region, array $excludeIds = [],
                    ?string $anchorDate = null): array
{
    $holidays = holidaysBetween($from, $until, $region === '' ? null : $region);
    $excluded = array_flip($exdates);
    $out = [];

    foreach (expandOccurrences($rule, $from, $until, [], $anchorDate) as $date) {
        $conflict = findAppointmentConflict($db, $prefix, $date, $template['start_time'], $template['type_id'],
                                            $toleranceHours, $excludeIds);
        $out[] = [
            'date'     => $date,
            'holiday'  => $holidays[$date] ?? null,
            'conflict' => $conflict === null ? null : [
                'appointment_id' => $conflict['appointment_id'],
                'title'          => $conflict['title'],
                'start_time'     => $conflict['start_time'],
            ],
            'excluded' => isset($excluded[$date]),
        ];
    }

    return $out;
}

/** Zahl der Termine, die ein Schreiben tatsaechlich anlegen wuerde. */
function seriesSelectableCount(array $occurrences): int
{
    return count(array_filter($occurrences, fn (array $o): bool => !$o['excluded'] && $o['conflict'] === null));
}

/**
 * Legt die Termine an. Kollisionen werden erneut geprueft -- die Vorschau ist
 * nur ein Vorschlag -- und ausgelassen.
 *
 * @param string[] $dates
 * @return array{created: int, skipped: array<int, array>, skipped_dates: string[]}
 */
function seriesInsertOccurrences(PDO $db, string $prefix, int $seriesId, array $template, array $dates,
                                 int $toleranceHours, ?int $createdBy): array
{
    $insert = $db->prepare("INSERT INTO {$prefix}appointments
                              (title, type_id, description, location, date, start_time, end_time, created_by, series_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $created = 0;
    $skipped = [];

    foreach ($dates as $date) {
        $conflict = findAppointmentConflict($db, $prefix, $date, $template['start_time'], $template['type_id'],
                                            $toleranceHours);
        if ($conflict !== null) {
            $skipped[] = ['date' => $date, 'reason' => 'conflict', 'conflict' => [
                'appointment_id' => $conflict['appointment_id'],
                'title'          => $conflict['title'],
                'start_time'     => $conflict['start_time'],
            ]];
            continue;
        }
        $insert->execute([$template['title'], $template['type_id'], $template['description'], $template['location'],
                          $date, $template['start_time'], $template['end_time'], $createdBy, $seriesId]);
        $created++;
    }

    return ['created' => $created, 'skipped' => $skipped, 'skipped_dates' => array_column($skipped, 'date')];
}

/** @return array<int, array{appointment_id: int, date: string}> nicht abgeloeste Termine ab $from */
function seriesFollowing(PDO $db, string $prefix, int $seriesId, string $from): array
{
    $stmt = $db->prepare("SELECT appointment_id, date FROM {$prefix}appointments
                          WHERE series_id = ? AND date >= ? AND is_detached = 0 ORDER BY date");
    $stmt->execute([$seriesId, $from]);

    return array_map(fn (array $r): array => ['appointment_id' => (int) $r['appointment_id'], 'date' => $r['date']],
                     $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** @return int[] Termine ab $from, die ein Beenden loeschen wuerde */
function seriesDeletableFollowing(PDO $db, string $prefix, int $seriesId, string $from): array
{
    $ids = [];
    foreach (seriesFollowing($db, $prefix, $seriesId, $from) as $row) {
        if (!appointmentHasData($db, $prefix, $row['appointment_id'])) {
            $ids[] = $row['appointment_id'];
        }
    }

    return $ids;
}

/**
 * Serie ab $from beenden: nicht abgeloeste Termine ab $from ohne Daten
 * loeschen, mit Daten abloesen.
 *
 * - $from nach dem Serienbeginn: until auf den Vortag; die Serie verschwindet
 *   nur, wenn kein Termin mehr auf sie zeigt.
 * - $from am oder vor dem Serienbeginn: die ganze Serie endet. Die Zeile wird
 *   immer geloescht; verbliebene (abgeloeste) Termine werden zu gewoehnlichen
 *   Einzelterminen (series_id = NULL, is_detached = 0) -- ausdruecklich gesetzt,
 *   nicht nur ueber ON DELETE SET NULL, damit kein verwaister Abloese-Merker bleibt.
 *
 * Laeuft in der Transaktion des Aufrufers.
 *
 * @return array{removed: int, detached: array<int, array>, series_deleted: bool}
 */
function seriesEndFrom(PDO $db, string $prefix, array $series, string $from): array
{
    $seriesId = $series['series_id'];
    $removed  = 0;
    $detached = [];

    $delete = $db->prepare("DELETE FROM {$prefix}appointments WHERE appointment_id = ?");
    $detach = $db->prepare("UPDATE {$prefix}appointments SET is_detached = 1 WHERE appointment_id = ?");

    foreach (seriesFollowing($db, $prefix, $seriesId, $from) as $row) {
        if (appointmentHasData($db, $prefix, $row['appointment_id'])) {
            $detach->execute([$row['appointment_id']]);
            $detached[] = ['appointment_id' => $row['appointment_id'], 'date' => $row['date'], 'reason' => 'has_data'];
            continue;
        }
        $delete->execute([$row['appointment_id']]);
        $removed++;
    }

    if ($from <= $series['start_date']) {
        $db->prepare("UPDATE {$prefix}appointments SET series_id = NULL, is_detached = 0 WHERE series_id = ?")
           ->execute([$seriesId]);
        $db->prepare("DELETE FROM {$prefix}appointment_series WHERE series_id = ?")->execute([$seriesId]);

        return ['removed' => $removed, 'detached' => $detached, 'series_deleted' => true];
    }

    $db->prepare("UPDATE {$prefix}appointment_series SET `until` = ? WHERE series_id = ?")
       ->execute([seriesAddDays($from, -1), $seriesId]);

    $count = $db->prepare("SELECT COUNT(*) FROM {$prefix}appointments WHERE series_id = ?");
    $count->execute([$seriesId]);
    $seriesDeleted = false;
    if ((int) $count->fetchColumn() === 0) {
        $db->prepare("DELETE FROM {$prefix}appointment_series WHERE series_id = ?")->execute([$seriesId]);
        $seriesDeleted = true;
    }

    return ['removed' => $removed, 'detached' => $detached, 'series_deleted' => $seriesDeleted];
}

/** Antwort fuer GET: Serie samt Zahl der Termine. */
function seriesSummary(PDO $db, string $prefix, array $series): array
{
    $stmt = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(is_detached), 0) AS detached
                          FROM {$prefix}appointments WHERE series_id = ?");
    $stmt->execute([$series['series_id']]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    return array_merge($series, [
        'appointment_count' => (int) $counts['total'],
        'detached_count'    => (int) $counts['detached'],
    ]);
}
