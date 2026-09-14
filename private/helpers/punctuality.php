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

// ============================================
// PÜNKTLICHKEIT UND ZUVERLÄSSIGKEIT (OI-51)
// ============================================
//
// Zwei getrennte Kennzahlen, niemals verrechnet. Spec:
// docs/superpowers/specs/2026-09-11-puenktlichkeit-und-zuverlaessigkeit-design.md
//
// Aufbau wie attendance.php: Die holenden Funktionen liefern Rohfakten, die
// formenden entscheiden. Alles, was eine Regel ist, steht in den formenden
// Funktionen und ist ohne Datenbank pruefbar (tests/suites/punctuality_unit.php).

require_once __DIR__ . '/attendance.php';

/** Unter dieser Zahl gemessener Ankuenfte gibt es keine Quote (Spec 5.1). */
const PUNCTUALITY_MIN_MEASUREMENTS = 5;

/** Verspaetung, ab der ein Ausreisser nicht weiter ins Mittel eingeht (Spec 3.4). */
const PUNCTUALITY_LATE_CAP_MINUTES = 20;

/**
 * Karenz aus dem gespeicherten Wert.
 *
 * Die Einstellungsseite prueft den Bereich, updateSetting() beim Speichern
 * nicht. Alles ausserhalb von -60..60 oder keine ganze Zahl ergibt 0 -- ein
 * von Hand gesetzter Unsinnswert soll die Kennzahl nicht unbemerkt verbiegen.
 */
function punctualityGraceFromSetting(string $raw): int
{
    $raw = trim($raw);

    if (preg_match('/^-?\d+$/', $raw) !== 1) {
        return 0;
    }

    $minutes = (int) $raw;

    return ($minutes < -60 || $minutes > 60) ? 0 : $minutes;
}

/**
 * Formt die Puenktlichkeit aus den Messungen eines Bereichs.
 *
 * Minuten werden abgerundet, auch negativ: 20:00:59 gilt als 20:00, 19:59:30
 * als 19:59. Aggregiert wird ueber Messungen, nicht ueber Personen (Spec 5.3).
 *
 * @param array<int, array{delta_seconds: int|string, checkin_source: string}> $measurements
 * @param int $totalCount   Soll-Paare im Bereich -- Bezugsgroesse der Messabdeckung
 * @param int $graceMinutes Karenz relativ zum Terminbeginn
 */
function punctualityBuild(array $measurements, int $totalCount, int $graceMinutes): array
{
    $measured     = count($measurements);
    $onTime       = 0;
    $late         = 0;
    $lateSum      = 0;
    $selfReported = 0;

    foreach ($measurements as $measurement) {
        $minutes = (int) floor(((int) $measurement['delta_seconds']) / 60);

        if ($minutes <= $graceMinutes) {
            $onTime++;
        }

        // Das Verspaetungsmass misst ab Beginn, nicht ab der Karenz (Spec 3.4).
        if ($minutes > 0) {
            $late++;
            $lateSum += min($minutes, PUNCTUALITY_LATE_CAP_MINUTES);
        }

        if ($measurement['checkin_source'] === 'exception_request') {
            $selfReported++;
        }
    }

    $sufficient = $measured >= PUNCTUALITY_MIN_MEASUREMENTS;

    return [
        'enabled'             => true,
        'sufficient'          => $sufficient,
        'min_measurements'    => PUNCTUALITY_MIN_MEASUREMENTS,
        'measured_count'      => $measured,
        'total_count'         => $totalCount,
        'on_time_count'       => $onTime,
        'rate'                => $sufficient ? attendanceRate($onTime, $measured) : null,
        'late_count'          => $late,
        'avg_late_minutes'    => $late > 0 ? round($lateSum / $late, 1) : null,
        'self_reported_count' => $selfReported,
    ];
}

/**
 * Ausgang eines Soll-Paars aus Mitglied und Termin (Spec 5.2).
 *
 * Reihenfolge ist die Regel:
 *  1. erschienen -- wer kam, war da, auch trotz Abmeldung
 *  2. gibt es eine Abmeldung, entscheidet ihr Zeitpunkt; eine spaeter
 *     genehmigte bleibt verspaetet, auch wenn daraus ein excused-Record wurde
 *  3. sonst zaehlt eine Entschuldigung des Verwalters -- ueber den Zeitpunkt
 *     eines Anrufs weiss das System nichts
 *  4. sonst ausgefallen
 *
 * @param array{has_present: int|string, has_excused_record: int|string,
 *              absence_count: int|string, absence_in_time_count: int|string} $pair
 * @return 'appeared'|'excused'|'missed'
 */
function reliabilityOutcome(array $pair): string
{
    if ((int) $pair['has_present'] > 0) {
        return 'appeared';
    }

    if ((int) $pair['absence_count'] > 0) {
        return (int) $pair['absence_in_time_count'] > 0 ? 'excused' : 'missed';
    }

    return (int) $pair['has_excused_record'] > 0 ? 'excused' : 'missed';
}

/**
 * Formt die Zuverlaessigkeit aus den Soll-Paaren eines Bereichs.
 *
 * @param array<int, array<string, int|string>> $pairs
 */
function reliabilityBuild(array $pairs): array
{
    $counts = ['appeared' => 0, 'excused' => 0, 'missed' => 0];

    foreach ($pairs as $pair) {
        $counts[reliabilityOutcome($pair)]++;
    }

    $total = count($pairs);

    return [
        'enabled'         => true,
        'total'           => $total,
        'appeared'        => $counts['appeared'],
        'excused_in_time' => $counts['excused'],
        'missed'          => $counts['missed'],
        'rate'            => $total > 0
            ? attendanceRate($counts['appeared'] + $counts['excused'], $total)
            : null,
    ];
}
