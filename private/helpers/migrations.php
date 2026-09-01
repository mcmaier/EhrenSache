<?php
/**
 * EhrenSache - Migrationshilfen
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */
declare(strict_types=1);

/**
 * Höchste Version aus einer Liste von Versionsstrings.
 *
 * Bewusst über version_compare statt über eine Sortierung nach applied_at:
 * die Migration schreibt mehrere Zeilen in derselben Sekunde, und eine
 * String-Sortierung stellt '1.10.0' vor '1.9.0'.
 *
 * @param array<int, mixed> $versions
 */
function latestSchemaVersion(array $versions): ?string
{
    $latest = null;
    foreach ($versions as $v) {
        if (!is_string($v) || $v === '') {
            continue;
        }
        if ($latest === null || version_compare($v, $latest, '>')) {
            $latest = $v;
        }
    }

    return $latest;
}
