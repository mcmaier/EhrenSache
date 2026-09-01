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

/**
 * Bestimmt die Migrationsschritte, die von $from nach $to auszuführen sind.
 *
 * @param array<int, array{from: string, to: string, file: string, function: string}> $manifest
 * @return array<int, array{from: string, to: string, file: string, function: string}>
 * @throws RuntimeException bei einer Lücke oder einem nicht vorwärts führenden Schritt
 */
function resolveMigrationChain(string $from, string $to, array $manifest): array
{
    if (version_compare($from, $to, '>=')) {
        return [];
    }

    $byFrom = [];
    foreach ($manifest as $step) {
        $byFrom[$step['from']] = $step;
    }

    $chain   = [];
    $current = $from;

    while (version_compare($current, $to, '<')) {
        if (!isset($byFrom[$current])) {
            throw new RuntimeException(
                "Keine Migration ab Version {$current} vorhanden. "
                . 'Das Manifest hat eine Lücke oder die Datenbankversion ist unerwartet.'
            );
        }

        $step = $byFrom[$current];

        // Schutz vor einer Endlosschleife durch ein fehlerhaftes Manifest
        if (version_compare($step['to'], $current, '<=')) {
            throw new RuntimeException(
                "Migration {$step['file']} führt von {$current} nicht vorwärts."
            );
        }

        $chain[] = $step;
        $current = $step['to'];
    }

    return $chain;
}

/** Legt die schema_version-Tabelle an, falls sie fehlt. */
function ensureSchemaVersionTable(PDO $pdo, string $prefix): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$prefix}schema_version` (
            `version`    VARCHAR(20) NOT NULL,
            `applied_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** Trägt eine erreichte Version ein. Mehrfachaufruf ist folgenlos. */
function stampSchemaVersion(PDO $pdo, string $prefix, string $version): void
{
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO `{$prefix}schema_version` (`version`) VALUES (?)"
    );
    $stmt->execute([$version]);
}

/**
 * Liest alle eingetragenen Versionen.
 *
 * @return array<int, string>
 */
function readSchemaVersions(PDO $pdo, string $prefix): array
{
    $rows = $pdo->query("SELECT version FROM `{$prefix}schema_version`")
                ->fetchAll(PDO::FETCH_COLUMN);

    return is_array($rows) ? $rows : [];
}
