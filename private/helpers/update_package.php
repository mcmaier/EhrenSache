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
 * Das heruntergeladene Paket: entpacken, plausibilisieren, Migrationskette prüfen.
 */
declare(strict_types=1);

require_once __DIR__ . '/update_source.php';
require_once __DIR__ . '/migrations.php';

const UPDATE_REQUIRED_FILES = [
    'version.json',
    'public/api/api.php',
    'private/migrations/manifest.php',
    'private/helpers/migrations.php',
];

/** Ein Archivname ohne .., ohne absoluten Pfad, ohne Backslash und Nullbyte. */
function updateEntryNameSafe(string $name): bool
{
    if ($name === '' || strpos($name, "\0") !== false || strpos($name, '\\') !== false) {
        return false;
    }
    if ($name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) {
        return false;
    }
    foreach (explode('/', rtrim($name, '/')) as $teil) {
        if ($teil === '..' || $teil === '.') {
            return false;
        }
    }
    return true;
}

/** Gemeinsamer oberster Ordner aller Einträge (mit / am Ende), sonst ''. */
function updateCommonWrapper(array $names): string
{
    $kopf = null;
    foreach ($names as $name) {
        $pos = strpos($name, '/');
        if ($pos === false) {
            return '';
        }
        $dieser = substr($name, 0, $pos + 1);
        if ($kopf === null) {
            $kopf = $dieser;
        } elseif ($kopf !== $dieser) {
            return '';
        }
    }
    return $kopf ?? '';
}

/**
 * Entpackt Eintrag für Eintrag nach $targetDir. Alle Namen werden geprüft,
 * BEVOR die erste Datei geschrieben wird -- ZipArchive::extractTo() verlässt
 * sich nicht auf solche Prüfungen. GitHubs Wrapper-Ordner wird abgestreift.
 */
function updateExtractPackage(string $zipFile, string $targetDir): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Die PHP-Erweiterung zip fehlt');
    }
    if (file_exists($targetDir)) {
        throw new RuntimeException('Zielverzeichnis für das Paket existiert bereits: ' . $targetDir);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('Das ZIP-Archiv lässt sich nicht öffnen');
    }

    try {
        $namen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!updateEntryNameSafe($name)) {
                throw new RuntimeException("Unzulässiger Pfad im Archiv: {$name}");
            }
            $namen[] = $name;
        }

        $wrapper = updateCommonWrapper($namen);

        foreach ($namen as $name) {
            $rel = $wrapper === '' ? $name : substr($name, strlen($wrapper));
            if ($rel === '') {
                continue;
            }
            $ziel = $targetDir . '/' . $rel;

            if (substr($name, -1) === '/') {
                if (!is_dir($ziel) && !@mkdir($ziel, 0775, true)) {
                    throw new RuntimeException("Verzeichnis nicht anlegbar: {$rel}");
                }
                continue;
            }

            $dir = dirname($ziel);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                throw new RuntimeException("Verzeichnis nicht anlegbar: {$rel}");
            }

            $ein = $zip->getStream($name);
            $aus = @fopen($ziel, 'wb');
            if ($ein === false || $aus === false) {
                throw new RuntimeException("Datei nicht entpackbar: {$rel}");
            }
            stream_copy_to_stream($ein, $aus);
            fclose($ein);
            fclose($aus);
        }
    } finally {
        $zip->close();
    }
}

/** Liste der Gründe, warum das Paket nicht eingespielt werden darf; leer = in Ordnung. */
function updateValidatePackage(string $root, string $installedVersion): array
{
    $fehler = [];
    foreach (UPDATE_REQUIRED_FILES as $rel) {
        if (!is_file("{$root}/{$rel}")) {
            $fehler[] = "Das Paket enthält keine {$rel} – es ist kein EhrenSache-Paket oder unvollständig.";
        }
    }

    $version = updateReadVersionFile($root);
    if ($version === null) {
        $fehler[] = 'Die Version des Pakets ist nicht lesbar.';
    } elseif (!version_compare($version, $installedVersion, '>')) {
        $fehler[] = "Das Paket (Version {$version}) ist nicht neuer als die installierte Version {$installedVersion}.";
    }

    return $fehler;
}

/**
 * Führt die Migrationskette von der Datenbankversion bis $targetVersion durch?
 * null = ja, sonst die Meldung von resolveMigrationChain().
 *
 * Geprüft wird mit der gerade GELADENEN Kettenlogik. Vor dem Tausch ist das die
 * installierte, im Folgerequest nach dem Tausch die neue (Spezifikation, 8.1/8.2).
 */
function updateCheckChain(string $dbVersion, string $targetVersion, string $manifestPath): ?string
{
    try {
        resolveMigrationChain(
            normalizeDetectedVersion($dbVersion),
            $targetVersion,
            loadMigrationManifest($manifestPath)
        );
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}
