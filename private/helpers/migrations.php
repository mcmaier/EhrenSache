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

/**
 * Bildet das Ergebnis von detectDbVersion() auf einen kettenfähigen Versionsstring ab.
 *
 * '1.1.x' bedeutet: Prefix-Tabellen vorhanden, aber keine schema_version-Tabelle.
 * Das trifft Installationen, die zwischen 1.1.0 und 1.1.3 frisch eingerichtet wurden.
 * Schemaseitig sind diese Stände gleichwertig zu 1.1.3, weil ehrensache_db.sql dort
 * bereits alles anlegt, was die Migration 1.0.0 nachrüstet.
 *
 * @throws RuntimeException wenn die Version nicht bestimmbar ist
 */
function normalizeDetectedVersion(string $detected): string
{
    if ($detected === '1.1.x') {
        return '1.1.3';
    }
    if ($detected === '' || $detected === 'unbekannt') {
        throw new RuntimeException(
            'Die installierte Datenbankversion konnte nicht bestimmt werden. '
            . 'Bitte die Datenbank prüfen, bevor ein Update ausgeführt wird.'
        );
    }

    return $detected;
}

/**
 * Lädt das Migrationsmanifest.
 *
 * @return array<int, array{from: string, to: string, file: string, function: string}>
 * @throws RuntimeException wenn die Datei fehlt oder kein Array liefert
 */
function loadMigrationManifest(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException("Migrationsmanifest nicht gefunden: {$path}");
    }

    $manifest = require $path;

    if (!is_array($manifest)) {
        throw new RuntimeException("Migrationsmanifest liefert kein Array: {$path}");
    }

    return $manifest;
}
