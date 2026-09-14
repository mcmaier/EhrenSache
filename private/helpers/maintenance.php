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
 * Wartungsflag für den Dateitausch des Update-Assistenten.
 *
 * Während der Assistent Dateien einzeln ersetzt, sähe ein paralleler Request eine
 * Mischung aus altem und neuem Code. api.php prüft das Flag deshalb vor allen
 * übrigen Includes und antwortet mit 503.
 *
 * Das Flag trägt den Zeitpunkt seines Setzens. Nach MAINTENANCE_MAX_AGE_SECONDS
 * wird es ignoriert: Ein abgebrochener Lauf darf die Installation nicht dauerhaft
 * stilllegen. Aus demselben Grund sperrt ein unlesbares Flag nicht.
 */
declare(strict_types=1);

const MAINTENANCE_MAX_AGE_SECONDS = 900;

function maintenanceFlagPath(): string
{
    return __DIR__ . '/../config/maintenance.lock';
}

function maintenanceActive(string $path, ?int $now = null): bool
{
    if (!is_file($path)) {
        return false;
    }

    $seit = (int) trim((string) @file_get_contents($path));
    if ($seit <= 0) {
        return false;
    }

    return (($now ?? time()) - $seit) < MAINTENANCE_MAX_AGE_SECONDS;
}

function maintenanceBegin(string $path, ?int $now = null): void
{
    if (@file_put_contents($path, (string) ($now ?? time())) === false) {
        throw new RuntimeException('Das Wartungsflag konnte nicht gesetzt werden: ' . $path);
    }
}

function maintenanceEnd(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
    }
}
