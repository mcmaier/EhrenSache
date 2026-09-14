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
 * Cron-Einstieg für den stündlichen Reset der Demo — ohne Argumente.
 *
 * Aufruf:
 *   php private/demo/cron.php
 *
 * Gleichbedeutend mit `php private/demo/seed.php --yes --quiet`. Viele
 * Aufgabenplaner im Shared Hosting nehmen nur einen Dateipfad an und keine
 * Argumente. Diese Datei setzt die beiden, die der Cron nicht übergeben kann,
 * und lässt seed.php den Rest tun.
 *
 * Die Rückfrage in seed.php bleibt damit für jeden Aufruf von Hand erhalten —
 * aufgehoben wird sie nur auf diesem einen, dokumentierten Pfad.
 *
 * Rückgabewert 0 bei Erfolg, 1 bei einem Fehler; Fehler gehen auf STDERR.
 */
declare(strict_types=1);

define('DEMO_SEED_ENTRY', true);

$argv = [__DIR__ . '/seed.php', '--yes', '--quiet'];
$argc = count($argv);

require __DIR__ . '/seed.php';

// Hierher gelangt der Ablauf nur, wenn seed.php den Einstieg nicht erkannt hat:
// Der Ablaufblock dort endet in jedem Fall mit exit(). Das passiert, wenn eine
// ältere seed.php ohne die DEMO_SEED_ENTRY-Bedingung auf dem Server liegt —
// ohne diese Zeilen liefe der Cron dann stündlich still und erfolgreich durch,
// ohne je etwas zurückzusetzen.
fwrite(STDERR, "cron.php: seed.php hat den Cron-Einstieg nicht erkannt, der Demo-Bestand wurde nicht geschrieben.\n");
fwrite(STDERR, "Vermutlich liegt eine seed.php ohne DEMO_SEED_ENTRY auf dem Server.\n");
exit(1);
