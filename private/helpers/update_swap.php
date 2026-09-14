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
 * Dateitausch des Updaters: Plan, Preflight, Sicherung, Tausch, Rückweg.
 *
 * Kein atomarer Tausch -- der Web-Root zeigt auf public/, ein Verzeichnis lässt
 * sich nicht umschalten. Die Absicherung kommt deshalb aus dem vollständigen
 * Preflight vor der ersten Änderung und aus der Sicherung jeder ersetzten oder
 * gelöschten Datei.
 *
 * Löschregel: Nur veraltete .php- und .js-Dateien, nur in Verzeichnissen unterhalb
 * der Wurzel, in denen das Paket Einträge hat, nie unter UPDATE_NEVER_DELETE_IN
 * und nie, was die .gitattributes der Installation auf export-ignore setzt.
 * Verzeichnisse werden nie gelöscht.
 */
declare(strict_types=1);

require_once __DIR__ . '/update_package.php';

/** Nie überschrieben, nie gelöscht. Ein Eintrag mit / am Ende gilt für alles darunter. */
const UPDATE_NEVER_TOUCH = [
    'private/config/config.php',
    'private/config/mail_config.php',
    'private/config/install.lock',
    'private/config/maintenance.lock',
    'public/update/.htaccess',
    'public/install/.htaccess',
    'public/uploads/',
    'private/uploads/',
    'private/backup/',
    'private/.update-tmp/',
];

/** Verzeichnisse, in denen aktualisiert, aber nichts gelöscht wird. */
const UPDATE_NEVER_DELETE_IN = ['private/config/'];

const UPDATE_DELETABLE_EXTENSIONS = ['php', 'js'];

function updatePathListed(string $rel, array $liste): bool
{
    foreach ($liste as $eintrag) {
        if (substr($eintrag, -1) === '/' ? strpos($rel, $eintrag) === 0 : $rel === $eintrag) {
            return true;
        }
    }
    return false;
}

/** Muster aus .gitattributes, die export-ignore tragen. */
function updateReadExportIgnore(string $installRoot): array
{
    $datei = $installRoot . '/.gitattributes';
    if (!is_file($datei)) {
        return [];
    }

    $muster = [];
    foreach ((array) file($datei, FILE_IGNORE_NEW_LINES) as $zeile) {
        $zeile = trim((string) $zeile);
        if ($zeile === '' || $zeile[0] === '#') {
            continue;
        }
        $teile = preg_split('/\s+/', $zeile);
        if (in_array('export-ignore', array_slice($teile, 1), true)) {
            $muster[] = $teile[0];
        }
    }
    return $muster;
}

/**
 * gitattributes-Muster: mit / am Ende ein Verzeichnis, ohne / ein Dateiname auf
 * jeder Ebene, mit / ein Pfad ab der Wurzel.
 */
function updateMatchesExportIgnore(string $rel, string $muster): bool
{
    $muster = ltrim($muster, '/');
    if ($muster === '') {
        return false;
    }
    if (substr($muster, -1) === '/') {
        return strpos($rel, $muster) === 0;
    }
    if (strpos($muster, '/') === false) {
        return fnmatch($muster, basename($rel));
    }
    return fnmatch($muster, $rel, FNM_PATHNAME);
}

/** Alle Dateien unter $root als relative Pfade mit /, sortiert. */
function updateListFiles(string $root): array
{
    $basis = rtrim(str_replace('\\', '/', $root), '/');
    $liste = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $datei) {
        if ($datei->isFile()) {
            $liste[] = substr(str_replace('\\', '/', $datei->getPathname()), strlen($basis) + 1);
        }
    }
    sort($liste);
    return $liste;
}

/** @return array{copy: list<string>, delete: list<string>} */
function updateBuildPlan(string $packageRoot, string $installRoot): array
{
    $ignore  = updateReadExportIgnore($installRoot);
    $paket   = updateListFiles($packageRoot);
    $imPaket = array_flip($paket);

    $copy     = [];
    $betreten = [];
    foreach ($paket as $rel) {
        if (updatePathListed($rel, UPDATE_NEVER_TOUCH)) {
            continue;
        }
        $copy[] = $rel;
        for ($dir = dirname($rel); $dir !== '.' && $dir !== ''; $dir = dirname($dir)) {
            $betreten[$dir] = true;
        }
    }

    $delete = [];
    foreach (array_keys($betreten) as $dir) {
        if (!is_dir("{$installRoot}/{$dir}")) {
            continue;
        }
        foreach (scandir("{$installRoot}/{$dir}") ?: [] as $name) {
            $rel = "{$dir}/{$name}";
            if ($name === '.' || $name === '..' || !is_file("{$installRoot}/{$rel}") || isset($imPaket[$rel])) {
                continue;
            }
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), UPDATE_DELETABLE_EXTENSIONS, true)) {
                continue;
            }
            if (updatePathListed($rel, UPDATE_NEVER_TOUCH) || updatePathListed($rel, UPDATE_NEVER_DELETE_IN)) {
                continue;
            }
            foreach ($ignore as $muster) {
                if (updateMatchesExportIgnore($rel, $muster)) {
                    continue 2;
                }
            }
            $delete[] = $rel;
        }
    }

    sort($delete);
    return ['copy' => $copy, 'delete' => $delete];
}

/** Lässt sich $dir anlegen oder beschreiben? */
function updateDirCreatable(string $dir): bool
{
    while (!file_exists($dir)) {
        $eltern = dirname($dir);
        if ($eltern === $dir) {
            return false;
        }
        $dir = $eltern;
    }
    return is_dir($dir) && is_writable($dir);
}

/**
 * Vollständiger Preflight: jede Zieldatei, jedes Zielverzeichnis, die Sicherung.
 * Leer = der Tausch kann beginnen. Ein Tausch, der bei Datei 100 scheitert,
 * hinterließe eine Installation aus zwei Versionen.
 */
function updatePreflight(array $plan, string $installRoot, string $backupDir): array
{
    $fehler = [];
    foreach ($plan['copy'] as $rel) {
        $ziel = "{$installRoot}/{$rel}";
        if (file_exists($ziel)) {
            if (!is_file($ziel) || !is_writable($ziel)) {
                $fehler[] = "Nicht beschreibbar: {$rel}";
            }
        } elseif (!updateDirCreatable(dirname($ziel))) {
            $fehler[] = 'Verzeichnis nicht anlegbar: ' . dirname($rel);
        }
    }
    foreach ($plan['delete'] as $rel) {
        if (!is_writable(dirname("{$installRoot}/{$rel}"))) {
            $fehler[] = "Nicht löschbar: {$rel}";
        }
    }
    if (!updateDirCreatable($backupDir)) {
        $fehler[] = 'Sicherungsverzeichnis nicht anlegbar: ' . $backupDir;
    }
    return array_values(array_unique($fehler));
}

function updateCopyFile(string $von, string $nach): void
{
    $dir = dirname($nach);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Verzeichnis nicht anlegbar: {$dir}");
    }
    if (!@copy($von, $nach)) {
        throw new RuntimeException("Datei nicht kopierbar: {$nach}");
    }
}

/**
 * Sichert jede Datei, die ersetzt oder gelöscht wird, nach $backupDir/files/ und
 * schreibt plan.json -- die Grundlage für den Rückweg, auch aus einem Folgerequest.
 */
function updateBackup(array $plan, string $installRoot, string $backupDir, array $meta): array
{
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0775, true)) {
        throw new RuntimeException('Sicherungsverzeichnis nicht anlegbar: ' . $backupDir);
    }

    $ueberschrieben = [];
    $neu            = [];
    foreach ($plan['copy'] as $rel) {
        if (is_file("{$installRoot}/{$rel}")) {
            updateCopyFile("{$installRoot}/{$rel}", "{$backupDir}/files/{$rel}");
            $ueberschrieben[] = $rel;
        } else {
            $neu[] = $rel;
        }
    }
    foreach ($plan['delete'] as $rel) {
        updateCopyFile("{$installRoot}/{$rel}", "{$backupDir}/files/{$rel}");
    }

    $eintrag = ['format' => 1] + $meta + [
        'overwritten' => $ueberschrieben,
        'created'     => $neu,
        'deleted'     => $plan['delete'],
    ];
    $json = json_encode($eintrag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents("{$backupDir}/plan.json", $json) === false) {
        throw new RuntimeException('plan.json der Sicherung nicht schreibbar');
    }
    return $eintrag;
}

function updateApply(array $plan, string $packageRoot, string $installRoot): void
{
    foreach ($plan['copy'] as $rel) {
        updateCopyFile("{$packageRoot}/{$rel}", "{$installRoot}/{$rel}");
    }
    foreach ($plan['delete'] as $rel) {
        $pfad = "{$installRoot}/{$rel}";
        if (is_file($pfad) && !@unlink($pfad)) {
            throw new RuntimeException("Datei nicht löschbar: {$rel}");
        }
    }
}

/** plan.json einer Sicherung, geprüft; null wenn unbrauchbar oder mit unsicheren Pfaden. */
function updateReadBackupPlan(string $backupDir): ?array
{
    $eintrag = json_decode((string) @file_get_contents($backupDir . '/plan.json'), true);
    if (!is_array($eintrag) || ($eintrag['format'] ?? null) !== 1) {
        return null;
    }
    foreach (['from', 'to', 'db_version', 'package_root'] as $schluessel) {
        if (!is_string($eintrag[$schluessel] ?? null)) {
            return null;
        }
    }
    foreach (['overwritten', 'created', 'deleted'] as $schluessel) {
        if (!is_array($eintrag[$schluessel] ?? null)) {
            return null;
        }
        foreach ($eintrag[$schluessel] as $rel) {
            if (!is_string($rel) || !updateEntryNameSafe($rel)) {
                return null;
            }
        }
    }
    return $eintrag;
}

/** Spielt die Sicherung zurück und entfernt neu angelegte Dateien. Leer = vollständig. */
function updateRollback(string $backupDir, string $installRoot): array
{
    $eintrag = updateReadBackupPlan($backupDir);
    if ($eintrag === null) {
        return ["plan.json der Sicherung fehlt, ist unlesbar oder enthält unzulässige Pfade: {$backupDir}"];
    }

    $fehler = [];
    foreach (array_merge($eintrag['overwritten'], $eintrag['deleted']) as $rel) {
        try {
            updateCopyFile("{$backupDir}/files/{$rel}", "{$installRoot}/{$rel}");
        } catch (RuntimeException $e) {
            $fehler[] = $e->getMessage();
        }
    }
    foreach ($eintrag['created'] as $rel) {
        $pfad = "{$installRoot}/{$rel}";
        if (is_file($pfad) && !@unlink($pfad)) {
            $fehler[] = "Neu angelegte Datei nicht entfernbar: {$rel}";
        }
    }
    return $fehler;
}

function updateRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $eintrag) {
        $eintrag->isDir() ? @rmdir($eintrag->getPathname()) : @unlink($eintrag->getPathname());
    }
    @rmdir($dir);
}
