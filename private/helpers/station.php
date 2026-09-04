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
