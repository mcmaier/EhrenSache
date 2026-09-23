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

require_once __DIR__ . '/../../private/helpers/maintenance.php';

function maintenanceProbePath(): string
{
    return sys_get_temp_dir() . '/es_maint_' . uniqid() . '.lock';
}

test('maintenanceActive: ohne Flag keine Wartung', function () {
    assertSame(false, maintenanceActive(maintenanceProbePath()));
});

test('maintenanceActive: ein frisches Flag sperrt', function () {
    $pfad = maintenanceProbePath();
    maintenanceBegin($pfad, 1000);

    assertSame(true, maintenanceActive($pfad, 1000));
    assertSame(true, maintenanceActive($pfad, 1000 + MAINTENANCE_MAX_AGE_SECONDS - 1));

    maintenanceEnd($pfad);
});

test('maintenanceActive: nach der Hoechstdauer gibt ein liegengebliebenes Flag frei', function () {
    // Ein abgebrochener Lauf darf die Installation nicht dauerhaft stilllegen.
    $pfad = maintenanceProbePath();
    maintenanceBegin($pfad, 1000);

    assertSame(false, maintenanceActive($pfad, 1000 + MAINTENANCE_MAX_AGE_SECONDS));

    maintenanceEnd($pfad);
});

test('maintenanceActive: ein unlesbares Flag sperrt nicht', function () {
    $pfad = maintenanceProbePath();
    file_put_contents($pfad, 'kaputt');

    assertSame(false, maintenanceActive($pfad, time()));

    unlink($pfad);
});

test('maintenanceEnd entfernt das Flag und vertraegt einen zweiten Aufruf', function () {
    $pfad = maintenanceProbePath();
    maintenanceBegin($pfad);
    maintenanceEnd($pfad);
    maintenanceEnd($pfad);

    assertSame(false, is_file($pfad));
});
