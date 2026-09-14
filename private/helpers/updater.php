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
 * Orchestrierung des Updaters in zwei Phasen.
 *
 * updaterApply()  entpackt, plausibilisiert, prüft die Migrationskette mit der
 *                 INSTALLIERTEN Logik, macht den Preflight, setzt das
 *                 Wartungsflag, sichert und tauscht.
 * updaterVerify() läuft in einem FOLGEREQUEST mit dem getauschten Code und
 *                 prüft die Kette erneut. Scheitert das, spielt sie die
 *                 Sicherung zurück (Spezifikation 8.2). Es geht dabei nicht um
 *                 frühere Erkennung -- der Assistent bemerkte die Lücke ohnehin,
 *                 bevor er die Datenbank anfasst --, sondern darum, dass der
 *                 Updater die Ausnahme fängt und den Rückweg geht.
 */
declare(strict_types=1);

require_once __DIR__ . '/maintenance.php';
require_once __DIR__ . '/update_source.php';
require_once __DIR__ . '/update_package.php';
require_once __DIR__ . '/update_swap.php';

/** Fehlende Voraussetzungen für den Weg über GitHub. */
function updateEnvironmentErrors(): array
{
    $fehler = [];
    if (!function_exists('curl_init')) {
        $fehler[] = 'Die PHP-Erweiterung curl fehlt – der Assistent kann das Paket nicht selbst holen.';
    }
    if (!class_exists('ZipArchive')) {
        $fehler[] = 'Die PHP-Erweiterung zip fehlt – der Assistent kann das Paket nicht entpacken.';
    }
    return $fehler;
}

function updaterResult(bool $ok, array $errors, array $log, ?string $backupDir, ?string $version): array
{
    return ['ok' => $ok, 'errors' => $errors, 'log' => $log, 'backup_dir' => $backupDir, 'version' => $version];
}

/**
 * @param array{install_root:string, zip:string, db_version:string, tmp_root:string,
 *              backup_root:string, maintenance_file:string, now?:int} $ctx
 */
function updaterApply(array $ctx): array
{
    $log         = [];
    $now         = (int) ($ctx['now'] ?? time());
    $root        = $ctx['install_root'];
    $installiert = updateReadVersionFile($root) ?? '0.0.0';
    $paket       = $ctx['tmp_root'] . '/paket-' . date('Ymd-His', $now) . '-' . bin2hex(random_bytes(3));

    try {
        updateExtractPackage($ctx['zip'], $paket);
    } catch (RuntimeException $e) {
        updateRemoveTree($paket);
        return updaterResult(false, ['Paket unbrauchbar: ' . $e->getMessage()], $log, null, null);
    }
    $log[] = 'Paket entpackt';

    $fehler = updateValidatePackage($paket, $installiert);
    if ($fehler !== []) {
        updateRemoveTree($paket);
        return updaterResult(false, $fehler, $log, null, null);
    }
    $ziel  = (string) updateReadVersionFile($paket);
    $log[] = "Paket enthält Version {$ziel}";

    $kette = updateCheckChain($ctx['db_version'], $ziel, $paket . '/private/migrations/manifest.php');
    if ($kette !== null) {
        updateRemoveTree($paket);
        return updaterResult(false, [
            "Das Paket ist fehlerhaft geschnürt – die Migrationskette führt nicht bis {$ziel}: {$kette}",
        ], $log, null, null);
    }
    $log[] = "Migrationskette {$ctx['db_version']} → {$ziel} vollständig";

    $plan      = updateBuildPlan($paket, $root);
    $backupDir = $ctx['backup_root'] . '/' . $installiert . '-' . date('Ymd-His', $now);
    $fehler    = updatePreflight($plan, $root, $backupDir);
    if ($fehler !== []) {
        updateRemoveTree($paket);
        $fehler[] = 'Es wurde nichts verändert. Das Update lässt sich wie bisher von Hand einspielen (README, Abschnitt „Update").';
        return updaterResult(false, $fehler, $log, null, null);
    }
    $log[] = count($plan['copy']) . ' Dateien zu schreiben, ' . count($plan['delete']) . ' veraltete zu entfernen';

    try {
        maintenanceBegin($ctx['maintenance_file'], $now);
    } catch (RuntimeException $e) {
        updateRemoveTree($paket);
        return updaterResult(false, [$e->getMessage()], $log, null, null);
    }

    try {
        updateBackup($plan, $root, $backupDir, [
            'from' => $installiert, 'to' => $ziel, 'db_version' => $ctx['db_version'], 'package_root' => $paket,
        ]);
    } catch (RuntimeException $e) {
        maintenanceEnd($ctx['maintenance_file']);
        updateRemoveTree($paket);
        return updaterResult(false, ['Sicherung fehlgeschlagen, nichts getauscht: ' . $e->getMessage()], $log, null, null);
    }
    $log[] = 'Sicherung angelegt: ' . $backupDir;

    try {
        updateApply($plan, $paket, $root);
    } catch (RuntimeException $e) {
        $rueckweg = updateRollback($backupDir, $root);
        if ($rueckweg === []) {
            maintenanceEnd($ctx['maintenance_file']);
            updateRemoveTree($paket);
            return updaterResult(false, [
                'Tausch abgebrochen: ' . $e->getMessage(),
                "Sicherung zurückgespielt – die Installation steht wieder auf {$installiert}.",
            ], $log, $backupDir, null);
        }
        return updaterResult(false, array_merge([
            'Tausch abgebrochen: ' . $e->getMessage(),
            'Der Rückweg ist unvollständig. Die Anwendung bleibt im Wartungsmodus.',
            "Die Sicherung liegt unter {$backupDir}.",
        ], $rueckweg), $log, $backupDir, null);
    }
    $log[] = "Dateien auf {$ziel} getauscht";

    return updaterResult(true, [], $log, $backupDir, $ziel);
}

/**
 * @param array{install_root:string, backup_dir:string, maintenance_file:string} $ctx
 */
function updaterVerify(array $ctx): array
{
    $plan = updateReadBackupPlan($ctx['backup_dir']);
    if ($plan === null) {
        return updaterResult(false, ['plan.json der Sicherung fehlt oder ist unlesbar: ' . $ctx['backup_dir']], [], $ctx['backup_dir'], null);
    }

    // Das entpackte Paket nur entfernen, wenn es wirklich im Arbeitsverzeichnis liegt.
    $aufraeumen = static function () use ($ctx, $plan): void {
        $arbeit = rtrim(str_replace('\\', '/', $ctx['install_root']), '/') . '/private/.update-tmp/';
        if (strpos(str_replace('\\', '/', $plan['package_root']), $arbeit) === 0) {
            updateRemoveTree($plan['package_root']);
        }
    };

    $kette = updateCheckChain($plan['db_version'], $plan['to'], $ctx['install_root'] . '/private/migrations/manifest.php');
    if ($kette === null) {
        maintenanceEnd($ctx['maintenance_file']);
        $aufraeumen();
        return updaterResult(true, [], ["Migrationskette mit dem neuen Code geprüft: {$plan['db_version']} → {$plan['to']}"], $ctx['backup_dir'], $plan['to']);
    }

    $rueckweg = updateRollback($ctx['backup_dir'], $ctx['install_root']);
    if ($rueckweg === []) {
        maintenanceEnd($ctx['maintenance_file']);
        $aufraeumen();
        return updaterResult(false, [
            "Die Prüfung mit dem neuen Code ist fehlgeschlagen: {$kette}",
            "Sicherung zurückgespielt – die Installation steht wieder auf {$plan['from']}.",
        ], [], $ctx['backup_dir'], null);
    }

    return updaterResult(false, array_merge([
        "Die Prüfung mit dem neuen Code ist fehlgeschlagen: {$kette}",
        'Der Rückweg ist unvollständig. Die Anwendung bleibt im Wartungsmodus.',
        "Die Sicherung liegt unter {$ctx['backup_dir']}.",
    ], $rueckweg), [], $ctx['backup_dir'], null);
}
