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
 * Ort und Ende eines Termins (FI-23, seit 1.10.0).
 *
 * Beide Felder sind rein informativ: Das Ende geht in keine Auswertung ein,
 * weder in die Anwesenheit noch in die Puenktlichkeit. Liegt es vor dem
 * Beginn, meint es den Folgetag (Nachtauftritt 20:00-01:00).
 *
 * Genutzt von private/handlers/appointments.php (POST, PUT) und
 * private/handlers/import.php (CSV). Dazu kommt die Pruefung der Kernfelder
 * (appointmentNormalizeCore), die nur appointments.php nutzt.
 */
declare(strict_types=1);

const APPOINTMENT_LOCATION_MAX = 200;

/** '19:30' und '19:30:00' auf dieselbe Form bringen. */
function appointmentTimeKey(string $time): string
{
    $time = trim($time);
    return strlen($time) === 5 ? $time . ':00' : $time;
}

/**
 * @param mixed $raw
 * @return array{0: ?string, 1: ?string} [Wert, Fehlermeldung]
 */
function appointmentNormalizeLocation($raw): array
{
    if ($raw === null) {
        return [null, null];
    }
    if (!is_string($raw)) {
        return [null, 'Der Ort muss Text sein'];
    }

    $wert = trim($raw);
    if ($wert === '') {
        return [null, null];
    }
    if (mb_strlen($wert) > APPOINTMENT_LOCATION_MAX) {
        return [null, 'Der Ort darf höchstens ' . APPOINTMENT_LOCATION_MAX . ' Zeichen lang sein'];
    }

    return [$wert, null];
}

/**
 * @param mixed $raw
 * @return array{0: ?string, 1: ?string} [Wert als HH:MM:SS, Fehlermeldung]
 */
function appointmentNormalizeEndTime($raw, string $startTime): array
{
    if ($raw === null) {
        return [null, null];
    }
    if (!is_string($raw)) {
        return [null, 'Das Ende muss eine Uhrzeit sein'];
    }

    $wert = trim($raw);
    if ($wert === '') {
        return [null, null];
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $wert, $m)) {
        return [null, 'Das Ende muss als Uhrzeit HH:MM angegeben werden'];
    }

    $norm = sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');

    // Eine Dauer von null Minuten ist fast immer ein Tippfehler.
    if ($startTime !== '' && $norm === appointmentTimeKey($startTime)) {
        return [null, 'Das Ende darf nicht gleich dem Beginn sein'];
    }

    return [$norm, null];
}

/**
 * Kernfelder eines Termins pruefen: title, date, start_time, type_id.
 *
 * Geprueft wird nur, was in $fields steht -- PUT reicht nur die mitgeschickten
 * Felder herein, POST alle vier (fehlende als null). Bis dahin nahm POST ein
 * Datum "kaputt" an, und eine unbekannte Terminart endete in einer
 * PDOException mit HTML-Fehlerseite statt einer JSON-Antwort.
 *
 * type_id: null oder '' bleibt null -- was das bedeutet (Standard-Terminart
 * bei POST, keine Terminart bei PUT), entscheidet der Aufrufer. Ob die
 * Terminart existiert, prueft er ebenfalls (seriesTypeExists), weil das die
 * Datenbank braucht.
 *
 * Nutzt seriesIsValidDate() (recurrence.php) und seriesParseTypeId()
 * (appointment_series.php), dieselben Regeln wie die Terminserien.
 *
 * @param array<string, mixed> $fields
 * @return array{0: ?array<string, mixed>, 1: ?string} [normalisierte Felder, Fehlermeldung]
 */
function appointmentNormalizeCore(array $fields): array
{
    $out = [];

    if (array_key_exists('title', $fields)) {
        $title = is_string($fields['title']) ? trim($fields['title']) : '';
        if ($title === '') {
            return [null, 'Der Titel ist erforderlich'];
        }
        if (mb_strlen($title) > 200) {
            return [null, 'Der Titel darf höchstens 200 Zeichen lang sein'];
        }
        $out['title'] = $title;
    }

    if (array_key_exists('date', $fields)) {
        $date = $fields['date'];
        if (!is_string($date) || !seriesIsValidDate(trim($date))) {
            return [null, 'Das Datum muss als JJJJ-MM-TT angegeben werden und gültig sein'];
        }
        $out['date'] = trim($date);
    }

    if (array_key_exists('start_time', $fields)) {
        $start = $fields['start_time'];
        if (!is_string($start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', trim($start))) {
            return [null, 'Der Beginn muss als Uhrzeit HH:MM angegeben werden'];
        }
        $out['start_time'] = appointmentTimeKey($start);
    }

    if (array_key_exists('type_id', $fields)) {
        $typeId = $fields['type_id'];
        if ($typeId === null || $typeId === '') {
            $out['type_id'] = null;
        } else {
            $typeId = seriesParseTypeId($typeId);
            if ($typeId === null) {
                return [null, 'Ungültige Terminart'];
            }
            $out['type_id'] = $typeId;
        }
    }

    return [$out, null];
}

/**
 * Ort und Ende einer CSV-Zeile. Nur Spalten, die im Kopf der Datei stehen,
 * erzeugen ein Feld -- eine aeltere Datei ohne sie darf vorhandene Werte
 * beim Aktualisieren nicht loeschen.
 *
 * @param array<string, mixed> $row  Zeile nach array_combine($header, $data)
 * @return array{fields: array<string, ?string>, error: ?string}
 */
function appointmentImportDetails(array $row, string $startTime): array
{
    $fields = [];

    if (array_key_exists('location', $row)) {
        [$wert, $fehler] = appointmentNormalizeLocation((string) $row['location']);
        if ($fehler !== null) {
            return ['fields' => [], 'error' => $fehler];
        }
        $fields['location'] = $wert;
    }

    if (array_key_exists('end_time', $row)) {
        [$wert, $fehler] = appointmentNormalizeEndTime((string) $row['end_time'], $startTime);
        if ($fehler !== null) {
            return ['fields' => [], 'error' => $fehler];
        }
        $fields['end_time'] = $wert;
    }

    return ['fields' => $fields, 'error' => null];
}

/**
 * Bisher verwendete Orte fuer die Vorschlagsliste: letzte zwei Jahre,
 * haeufigste zuerst, hoechstens 50.
 *
 * @return string[]
 */
function appointmentLocationSuggestions(PDO $db, string $prefix): array
{
    $stmt = $db->query("
        SELECT location, COUNT(*) AS n
        FROM {$prefix}appointments
        WHERE location IS NOT NULL
          AND date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
        GROUP BY location
        ORDER BY n DESC, location
        LIMIT 50
    ");

    return array_map(static fn ($r) => (string) $r['location'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}
