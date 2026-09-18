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
 * Regeln, die fuer Einzeltermine und Terminserien gleich gelten.
 *
 * findAppointmentConflict() ist die bis 1.10.0 doppelt in appointments.php
 * (POST und PUT) stehende Dublettenpruefung: gleiches Datum, gleiche
 * Terminart, Abstand hoechstens die Check-in-Toleranz.
 */
declare(strict_types=1);

/**
 * @param int[] $excludeIds Termine, die nicht als Kollision zaehlen
 * @return array{appointment_id: int, title: string, date: string, start_time: string, time_diff: int}|null
 */
function findAppointmentConflict(PDO $db, string $prefix, string $date, string $startTime, $typeId,
                                 int $toleranceHours, array $excludeIds = []): ?array
{
    // Ohne Terminart gibt es keine Dublette -- wie bisher: type_id = NULL trifft in SQL nie.
    if ($typeId === null || $typeId === '') {
        return null;
    }

    $sql = "SELECT appointment_id, title, start_time, date,
                   ABS(TIMESTAMPDIFF(SECOND, CONCAT(date, ' ', start_time), ?)) AS time_diff
            FROM {$prefix}appointments
            WHERE date = ? AND type_id = ?";
    $params = [$date . ' ' . $startTime, $date, $typeId];

    if ($excludeIds !== []) {
        $sql .= ' AND appointment_id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
        foreach ($excludeIds as $excludeId) {
            $params[] = (int) $excludeId;
        }
    }

    $sql .= ' HAVING time_diff <= ? ORDER BY time_diff LIMIT 1';
    $params[] = $toleranceHours * 3600;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'appointment_id' => (int) $row['appointment_id'],
        'title'          => (string) $row['title'],
        'date'           => (string) $row['date'],
        'start_time'     => (string) $row['start_time'],
        'time_diff'      => (int) $row['time_diff'],
    ];
}

/** Antwortkoerper der 409 bei einer Dublette -- dieselben Schluessel wie bis 1.10.0. */
function appointmentConflictBody(array $conflict, int $toleranceHours): array
{
    return [
        'message'  => "Ein Termin dieser Art existiert bereits im Toleranzbereich von ±{$toleranceHours}h",
        'conflict' => [
            'title'             => $conflict['title'],
            'date'              => $conflict['date'],
            'time'              => $conflict['start_time'],
            'time_diff_seconds' => $conflict['time_diff'],
        ],
        'hint'     => "Bestehender Termin: \"{$conflict['title']}\" am {$conflict['date']} um {$conflict['start_time']} Uhr",
    ];
}

/**
 * Vergleicht ein von PUT appointments gesendetes Feld mit dem gespeicherten
 * Wert, um zu entscheiden, ob sich ein Serientermin ablöst (FI-7) --
 * unveränderte Werte in anderer Schreibweise (Zeit ohne Sekunden, leerer
 * String statt NULL) zählen dabei nicht als Änderung.
 *
 * @param mixed $sent   Wert wie vom Aufrufer bereits normalisiert (location,
 *                       end_time) bzw. roh aus dem Request (die übrigen Felder)
 * @param mixed $stored Wert aus der Datenbank (Bestand)
 */
function appointmentFieldChanged(string $field, $sent, $stored): bool
{
    switch ($field) {
        case 'start_time':
        case 'end_time':
            $sentKey   = $sent === null ? null : appointmentTimeKey((string) $sent);
            $storedKey = $stored === null ? null : appointmentTimeKey((string) $stored);

            return $sentKey !== $storedKey;

        case 'type_id':
            $sentId   = ($sent === null || $sent === '') ? null : (int) $sent;
            $storedId = $stored === null ? null : (int) $stored;

            return $sentId !== $storedId;

        case 'description':
        case 'location':
            $sentVal   = ($sent === null || $sent === '') ? null : $sent;
            $storedVal = ($stored === null || $stored === '') ? null : $stored;

            return $sentVal !== $storedVal;

        default: // title, date
            return (string) $sent !== (string) $stored;
    }
}

/**
 * Haengen an dem Termin erfasste Daten? Solche Termine loeschen
 * Serienaktionen nie, sie loesen sie aus der Serie.
 */
function appointmentHasData(PDO $db, string $prefix, int $appointmentId): bool
{
    foreach (['records', 'appointment_responses', 'exceptions', 'work_sessions'] as $table) {
        $stmt = $db->prepare("SELECT 1 FROM {$prefix}{$table} WHERE appointment_id = ? LIMIT 1");
        $stmt->execute([$appointmentId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}
