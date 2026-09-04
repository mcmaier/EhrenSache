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

/**
 * Fachlogik der Stations-Anmeldung (Kiosk): Einstellungen, PIN-Regeln,
 * Prüfung von Mitgliedsnummer + PIN mit Sperre.
 *
 * Spec: docs/superpowers/specs/2026-09-04-station-pin-kiosk-design.md
 */

const STATION_PIN_MIN_LENGTH_FLOOR = 4;
const STATION_PIN_MAX_LENGTH       = 8;

/** Ist die PIN-Anmeldung an Stationen freigeschaltet? (E13) */
function isStationPinEnabled($db, $database): bool
{
    return systemSetting($db, $database, 'station_pin_enabled', '0') === '1';
}

/** Mindestlänge der PIN, auf 4..8 begrenzt — egal, was in der Einstellung steht. */
function stationPinMinLength($db, $database): int
{
    $value = (int) systemSetting($db, $database, 'station_pin_min_length', '4');

    return max(STATION_PIN_MIN_LENGTH_FLOOR, min(STATION_PIN_MAX_LENGTH, $value));
}

/**
 * Prüft eine PIN gegen die Regeln aus E11: nur Ziffern, Mindestlänge
 * (4..8, aus der Einstellung), höchstens 8, keine Einheitsziffern, keine
 * auf- oder absteigende Folge.
 *
 * Reine Funktion — Unit-Test in tests/suites/station_unit.php.
 *
 * @return string|null Fehlertext für die Oberfläche, null wenn gültig
 */
function validateStationPin(string $pin, int $minLength): ?string
{
    $minLength = max(STATION_PIN_MIN_LENGTH_FLOOR, min(STATION_PIN_MAX_LENGTH, $minLength));

    if (!preg_match('/^\d+$/', $pin)) {
        return 'Die PIN darf nur Ziffern enthalten';
    }
    $length = strlen($pin);
    if ($length < $minLength) {
        return "Die PIN muss mindestens {$minLength} Ziffern haben";
    }
    if ($length > STATION_PIN_MAX_LENGTH) {
        return 'Die PIN darf höchstens ' . STATION_PIN_MAX_LENGTH . ' Ziffern haben';
    }
    if (preg_match('/^(\d)\1+$/', $pin)) {
        return 'Die PIN darf nicht aus lauter gleichen Ziffern bestehen';
    }
    if (stationPinIsSequence($pin)) {
        return 'Die PIN darf keine auf- oder absteigende Zahlenfolge sein';
    }

    return null;
}

/** 1234, 456789, 9876 — Schrittweite genau +1 oder genau −1 über die ganze Länge. */
function stationPinIsSequence(string $pin): bool
{
    $ascending  = true;
    $descending = true;

    for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
        $step = (int) $pin[$i] - (int) $pin[$i - 1];
        if ($step !== 1) {
            $ascending = false;
        }
        if ($step !== -1) {
            $descending = false;
        }
    }

    return $ascending || $descending;
}
