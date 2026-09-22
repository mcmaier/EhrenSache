<?php

/**
 * EhrenSache - Update-Assistent
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

session_start();

define('CONFIG_PATH',    __DIR__ . '/../../private/config/config.php');
define('LOCK_PATH',      __DIR__ . '/../../private/config/install.lock');
define('MIGRATION_PATH', __DIR__ . '/../../private/migrations/');
define('VERSION_PATH',   __DIR__ . '/../../version.json');
define('HTACCESS_PATH',  __DIR__ . '/.htaccess');

require_once __DIR__ . '/../../private/helpers/migrations.php';
require_once __DIR__ . '/../../private/helpers/config_reader.php';
require_once __DIR__ . '/../../private/helpers/updater.php';
require_once __DIR__ . '/../../private/helpers/requirements.php';

define('INSTALL_ROOT',       dirname(__DIR__, 2));
define('UPDATE_TMP_ROOT',    INSTALL_ROOT . '/private/.update-tmp');
define('UPDATE_BACKUP_ROOT', INSTALL_ROOT . '/private/backup');

// ── Hilfsfunktionen ──────────────────────────────────────────────────────────

/**
 * Liest config.php über den gemeinsamen Leser -- in der alten Klassenform
 * (auch 1.0.0 ohne $prefix) wie in der Array-Form ab 1.6.0 -- und liefert die
 * flachen Schlüssel, mit denen dieser Assistent arbeitet.
 *
 * Bis 1.5.1 stand hier eine eigene Regex-Fassung. Nach der Migration auf 1.6.0
 * hätte sie die umgeschriebene Datei nicht mehr lesen können.
 */
function readWizardConfig(string $file): array
{
    $cfg = readConfigFile($file);
    return [
        'host'     => $cfg['db']['host'],
        'db_name'  => $cfg['db']['name'],
        'username' => $cfg['db']['user'],
        'password' => $cfg['db']['pass'],
        'prefix'   => $cfg['db']['prefix'],
        'format'   => $cfg['format'],
    ];
}

/** Verbindet zur DB anhand der geparsten Config-Werte. */
function connectDb(array $cfg): PDO
{
    $pdo = new PDO(
        "mysql:host={$cfg['host']};dbname={$cfg['db_name']};charset=utf8mb4",
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

// tableExists() und detectDbVersion() liegen in private/helpers/migrations.php,
// damit sie gegen eine echte Datenbank testbar sind: diese Datei gibt beim
// Einbinden HTML aus und laesst sich nicht in einen Test laden.

/** Gibt die Ziel-Version aus version.json zurück. */
function getTargetVersion(): string
{
    if (!file_exists(VERSION_PATH)) return '1.1.3';
    $v = json_decode(file_get_contents(VERSION_PATH), true);
    return $v['version'] ?? '1.1.3';
}

// ── Schritt-Logik ─────────────────────────────────────────────────────────────

$step  = (int) ($_GET['step'] ?? 0);
$error = '';

// ── SCHRITT 0: Dateien von GitHub holen ─────────────────────────────────────
//
// Zwei Phasen in zwei Requests. POST tauscht die Dateien und leitet weiter; der
// Folgerequest läuft mit dem GETAUSCHTEN Code und prüft die Migrationskette ein
// zweites Mal (Spezifikation 8.2). Der Weg von Hand bleibt: „Dateien bereits
// hochgeladen" führt direkt zu Schritt 1.
$updateInfo   = null;
$updateErrors = [];
$updateLog    = [];

if (empty($_SESSION['update_csrf'])) {
    $_SESSION['update_csrf'] = bin2hex(random_bytes(16));
}

if ($step === 0 && ($_GET['phase'] ?? '') === 'verify') {
    $backupDir = (string) ($_SESSION['update_backup_dir'] ?? '');
    unset($_SESSION['update_backup_dir']);
    $backupNorm = str_replace('\\', '/', $backupDir);
    $wurzelNorm = str_replace('\\', '/', UPDATE_BACKUP_ROOT) . '/';

    if ($backupDir === '' || strpos($backupNorm, $wurzelNorm) !== 0) {
        $updateErrors[] = 'Kein laufendes Update gefunden. Bitte von vorn beginnen.';
    } else {
        $ergebnis = updaterVerify([
            'install_root'     => INSTALL_ROOT,
            'backup_dir'       => $backupDir,
            'maintenance_file' => maintenanceFlagPath(),
        ]);
        if ($ergebnis['ok']) {
            $_SESSION['update_done'] = ['version' => $ergebnis['version'], 'backup_dir' => $backupDir];
            header('Location: ?step=1');
            exit;
        }
        $updateErrors = $ergebnis['errors'];
    }
}

if ($step === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['update_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $updateErrors[] = 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.';
    } else {
        @set_time_limit(300);
        $updateErrors = updateEnvironmentErrors();

        if ($updateErrors === []) {
            try {
                $release     = updateFetchLatest();
                $installiert = updateReadVersionFile(INSTALL_ROOT) ?? '0.0.0';
                $aktion      = (string) ($_POST['action'] ?? '');

                if ($aktion === 'check') {
                    $updateInfo = $release + ['available' => updateAvailable($installiert, $release)];
                } elseif ($aktion === 'apply') {
                    // Die Adresse kommt nie aus dem Formular, sondern aus einem
                    // frischen Abruf; das Formular bestätigt nur die Version.
                    if (($_POST['expected'] ?? '') !== $release['version']) {
                        throw new RuntimeException('Die neueste Version hat sich inzwischen geändert – bitte erneut abfragen.');
                    }
                    if (updateAvailable($installiert, $release) === null) {
                        throw new RuntimeException("Version {$release['version']} ist nicht neuer als die installierte {$installiert}.");
                    }

                    $cfg       = readWizardConfig(CONFIG_PATH);
                    $dbVersion = detectDbVersion(connectDb($cfg), $cfg['prefix']);

                    if (!is_dir(UPDATE_TMP_ROOT) && !@mkdir(UPDATE_TMP_ROOT, 0775, true)) {
                        throw new RuntimeException('Arbeitsverzeichnis nicht anlegbar: private/.update-tmp');
                    }
                    $zip = UPDATE_TMP_ROOT . '/paket.zip';
                    updateDownloadPackage($release['zipball_url'], $zip);

                    $ergebnis = updaterApply([
                        'install_root'     => INSTALL_ROOT,
                        'zip'              => $zip,
                        'db_version'       => $dbVersion,
                        'tmp_root'         => UPDATE_TMP_ROOT,
                        'backup_root'      => UPDATE_BACKUP_ROOT,
                        'maintenance_file' => maintenanceFlagPath(),
                    ]);
                    @unlink($zip);

                    if ($ergebnis['ok']) {
                        $_SESSION['update_backup_dir'] = $ergebnis['backup_dir'];
                        header('Location: ?step=0&phase=verify');
                        exit;
                    }
                    $updateErrors = $ergebnis['errors'];
                    $updateLog    = $ergebnis['log'];
                }
            } catch (Throwable $e) {
                $updateErrors[] = $e->getMessage();
            }
        }
    }
}

// ── SCHRITT 1: Systemprüfung ─────────────────────────────────────────────────
$checks       = [];
$configValues = [];
$dbVersion    = 'unbekannt';
$targetVersion = getTargetVersion();

if ($step >= 1) {
    // Anforderungen der hochgeladenen bzw. getauschten Version aus version.json.
    $checks = requirementsChecks(requirementsRead(INSTALL_ROOT)) + [
        'install.lock'     => file_exists(LOCK_PATH),
        'config.php'       => file_exists(CONFIG_PATH),
        'Schreibrecht config.php' => file_exists(CONFIG_PATH) && is_writable(CONFIG_PATH),
    ];

    if ($checks['config.php']) {
        $configValues = readWizardConfig(CONFIG_PATH);
        try {
            $pdo       = connectDb($configValues);
            $dbVersion = detectDbVersion($pdo, $configValues['prefix'] ?? '');
            $checks['Datenbankverbindung'] = true;

            $kettenLabel = "Migrationskette {$dbVersion} → {$targetVersion}";
            try {
                resolveMigrationChain(normalizeDetectedVersion($dbVersion), $targetVersion,
                    loadMigrationManifest(MIGRATION_PATH . 'manifest.php'));
                $checks[$kettenLabel] = true;
            } catch (RuntimeException $e) {
                $checks[$kettenLabel] = false;
                $error = htmlspecialchars($e->getMessage());
            }
        } catch (Exception $e) {
            $checks['Datenbankverbindung'] = false;
            $error = "DB-Verbindung fehlgeschlagen: " . htmlspecialchars($e->getMessage());
        }
    } else {
        $checks['Datenbankverbindung'] = false;
    }
}

// ── SCHRITT 2: Konfiguration (POST) ──────────────────────────────────────────
if ($step == 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPrefix = trim($_POST['prefix'] ?? $configValues['prefix'] ?: '');
    $confirmed = !empty($_POST['backup_confirmed']);

    if (!$confirmed) {
        $error = "Bitte bestätige, dass du ein Backup erstellt hast.";
        $step  = 2;
    } else {
        $_SESSION['update_prefix']  = $newPrefix;
        $_SESSION['update_from']    = $dbVersion;
        $_SESSION['update_to']      = $targetVersion;
        header('Location: ?step=3');
        exit;
    }
}

$plannedChain = [];
if ($step == 2 && isset($pdo)) {
    try {
        $plannedChain = resolveMigrationChain(normalizeDetectedVersion($dbVersion), $targetVersion,
            loadMigrationManifest(MIGRATION_PATH . 'manifest.php'));
    } catch (RuntimeException $e) {
        $error = htmlspecialchars($e->getMessage());
    }
}

// ── SCHRITT 3: Migration ausführen ───────────────────────────────────────────
$migrationLog  = [];
$migrationWarn = [];
$migrationOk   = false;

if ($step == 3 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (empty($_SESSION['update_prefix']) && $_SESSION['update_prefix'] !== '') {
        header('Location: ?step=1');
        exit;
    }

    $prefix    = $_SESSION['update_prefix'];
    $fromVer   = $_SESSION['update_from'] ?? 'unbekannt';
    $configCfg = readWizardConfig(CONFIG_PATH);

    try {
        $pdo = connectDb($configCfg);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $manifest = loadMigrationManifest(MIGRATION_PATH . 'manifest.php');
        $chain    = resolveMigrationChain(
            normalizeDetectedVersion($fromVer),
            $targetVersion,
            $manifest
        );

        ensureSchemaVersionTable($pdo, $prefix);

        // Ausgangsstand festhalten, damit die Historie vollstaendig ist
        stampSchemaVersion($pdo, $prefix, normalizeDetectedVersion($fromVer));

        if ($chain === []) {
            $migrationLog[] = "Die Datenbank ist bereits auf Stand <strong>{$targetVersion}</strong> – nichts zu tun.";
        }

        // NICHT $step als Laufvariable: das ist die Nummer des Wizard-Schritts
        // aus dem Skriptkopf. Wird sie hier überschrieben, trifft weiter unten
        // keine der Bedingungen $step == 1|2|3 mehr und die Ergebnisseite
        // bleibt leer — ohne Fehlermeldung, obwohl die Migration lief.
        foreach ($chain as $migrationStep) {
            require_once MIGRATION_PATH . $migrationStep['file'];

            if (!function_exists($migrationStep['function'])) {
                throw new RuntimeException(
                    "Funktion {$migrationStep['function']}() fehlt in {$migrationStep['file']}"
                );
            }

            $migrationLog[] = "<strong>{$migrationStep['from']} → {$migrationStep['to']}</strong> "
                            . "({$migrationStep['file']})";

            $result        = ($migrationStep['function'])($pdo, $prefix, CONFIG_PATH);
            $migrationLog  = array_merge($migrationLog, $result['log']);
            $migrationWarn = array_merge($migrationWarn, $result['warnings']);

            stampSchemaVersion($pdo, $prefix, $migrationStep['to']);
            $migrationLog[] = "Schema-Version auf <strong>{$migrationStep['to']}</strong> gesetzt";
        }

        $migrationOk = true;

        // Update-Wizard wieder sperren.
        //
        // Wortgleich mit der Fassung im Repository, inklusive der Anleitung zum
        // Wiederöffnen: Ohne sie steht nach dem ersten Update nirgends mehr,
        // wie man den Assistenten für das nächste erreichbar macht.
        // tests/suites/htaccess_locks.php hält beide Fassungen zusammen.
        $htaccessContent = <<<'HTACCESS'
            # EhrenSache Update-Assistent
            # Zugriff standardmäßig gesperrt.
            # Zum Aktivieren des Updates diese Datei leeren oder umbenennen.
            # Nach dem Update wird der Zugriff automatisch wieder gesperrt.

            # Zwei Syntaxen, damit die Sperre auf jedem Apache greift: 2.4 kennt
            # Require, 2.2 kennt es nicht. Ohne den Waechter quittiert ein 2.4 ohne
            # mod_access_compat die alte Form mit HTTP 500 statt 403.
            <IfModule mod_authz_core.c>
                Require all denied
            </IfModule>
            <IfModule !mod_authz_core.c>
                Order Deny,Allow
                Deny from all
            </IfModule>
            HTACCESS;

        // Geschrieben wird die Sperre erst am Ende der Datei, nach der
        // Ergebnisseite (OI-23).

        unset($_SESSION['update_prefix'], $_SESSION['update_from'], $_SESSION['update_to']);

    } catch (Exception $e) {
        $error = "Migration fehlgeschlagen: " . htmlspecialchars($e->getMessage());
        $migrationOk = false;
    }
}

$allChecksPassed = !in_array(false, $checks, true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EhrenSache Update</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #1F5FBF 0%, #4CAF50 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 640px;
            width: 100%;
        }
        h1 { color: #1F5FBF; margin-bottom: 6px; font-size: 24px; }
        h2 { color: #333; margin: 24px 0 12px; font-size: 18px; }
        .subtitle { color: #666; margin-bottom: 20px; font-size: 14px; }

        .progress { display: flex; gap: 8px; margin: 20px 0; }
        .progress-step { flex: 1; height: 4px; background: #e0e0e0; border-radius: 2px; }
        .progress-step.active { background: #1F5FBF; }
        .progress-step.done   { background: #4CAF50; }

        .check-list { list-style: none; margin: 12px 0; }
        .check-list li {
            padding: 10px 14px;
            margin: 4px 0;
            border-radius: 6px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .check-list li.pass { background: #e8f5e9; color: #2e7d32; }
        .check-list li.fail { background: #ffebee; color: #c62828; }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #1F5FBF;
            padding: 14px;
            border-radius: 6px;
            margin: 16px 0;
            font-size: 14px;
        }
        .warn-box {
            background: #fff8e1;
            border-left: 4px solid #f9a825;
            padding: 14px;
            border-radius: 6px;
            margin: 16px 0;
            font-size: 14px;
        }
        .error-box {
            background: #ffebee;
            border-left: 4px solid #c62828;
            padding: 14px;
            border-radius: 6px;
            margin: 16px 0;
            font-size: 14px;
        }
        .success-box {
            background: #e8f5e9;
            border-left: 4px solid #2e7d32;
            padding: 14px;
            border-radius: 6px;
            margin: 16px 0;
            font-size: 14px;
        }

        .form-group { margin: 16px 0; }
        label { display: block; margin-bottom: 5px; color: #555; font-weight: 500; font-size: 14px; }
        input[type=text] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        input[type=text]:focus { outline: none; border-color: #1F5FBF; }
        small { color: #777; display: block; margin-top: 4px; font-size: 12px; }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fff3e0;
            border: 1px solid #ffb74d;
            border-radius: 6px;
            padding: 14px;
            margin: 16px 0;
            cursor: pointer;
        }
        .checkbox-group input { margin-top: 2px; flex-shrink: 0; }
        .checkbox-group label { color: #e65100; font-weight: 600; cursor: pointer; margin: 0; }

        .plan-list { list-style: none; margin: 8px 0; }
        .plan-list li {
            padding: 6px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
            color: #444;
            display: flex;
            gap: 8px;
        }
        .plan-list li:last-child { border-bottom: none; }

        .log-list { list-style: none; margin: 8px 0; max-height: 320px; overflow-y: auto; }
        .log-list li {
            padding: 5px 8px;
            font-size: 13px;
            border-radius: 4px;
            margin: 2px 0;
        }
        .log-list li.ok   { color: #2e7d32; }
        .log-list li.warn { color: #e65100; }

        .btn {
            background: #1F5FBF;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            width: 100%;
            margin-top: 12px;
            transition: background .2s;
        }
        .btn:hover { background: #1a4fa8; }
        .btn:disabled { background: #90a4ae; cursor: not-allowed; }
        .btn-secondary {
            background: white;
            color: #1F5FBF;
            border: 1px solid #1F5FBF;
            padding: 10px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
        }

        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
            font-size: 12px;
        }

        .version-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
        }
        .version-old { background: #ffebee; color: #c62828; }
        .version-new { background: #e8f5e9; color: #2e7d32; }
        .version-current { background: #e3f2fd; color: #1565c0; }
        .version-arrow { color: #666; margin: 0 6px; }
    </style>
</head>
<body>
<div class="container">
    <h1>EhrenSache Update</h1>
    <p class="subtitle">Datenbank-Migration auf Version <?= htmlspecialchars($targetVersion) ?></p>

    <div class="progress">
        <div class="progress-step <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>"></div>
        <div class="progress-step <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>"></div>
        <div class="progress-step <?= $step >= 3 ? 'active' : '' ?>"></div>
    </div>

    <?php if ($error): ?>
        <div class="error-box">&#10060; <?= $error ?></div>
    <?php endif; ?>

    <?php // ═══════════════════════════════════════════════════════════════
          // SCHRITT 0: Dateien von GitHub holen
          // ═══════════════════════════════════════════════════════════════
    if ($step == 0): ?>

        <h2>Schritt 0: Dateien aktualisieren</h2>

        <?php // Rot nur, wenn eine neuere Version tatsächlich bekannt ist -- vorher weiß der
              // Assistent das nicht, und eine aktuelle Installation ist nicht veraltet.
              $veraltet = $updateInfo !== null && $updateInfo['available'] !== null; ?>
        <div class="info-box">
            <strong>Installierte Version:</strong>
            <span class="version-badge <?= $veraltet ? 'version-old' : 'version-current' ?>"><?= htmlspecialchars(updateReadVersionFile(INSTALL_ROOT) ?? 'unbekannt') ?></span>
            <?php if ($veraltet): ?>
                <span class="version-arrow">&#8594;</span>
                <span class="version-badge version-new"><?= htmlspecialchars($updateInfo['version']) ?></span>
            <?php endif; ?>
        </div>

        <?php foreach ($updateErrors as $zeile): ?>
            <div class="error-box">&#10060; <?= htmlspecialchars($zeile) ?></div>
        <?php endforeach; ?>

        <?php if ($updateLog !== []): ?>
            <ul class="log-list">
                <?php foreach ($updateLog as $zeile): ?>
                    <li><?= htmlspecialchars($zeile) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($updateInfo === null): ?>
            <p>
                Der Assistent kann das neueste Paket selbst von GitHub holen, prüfen, jede ersetzte
                Datei sichern und die Dateien tauschen. Dabei nimmt dieser Server Kontakt zu GitHub auf.
            </p>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['update_csrf']) ?>">
                <input type="hidden" name="action" value="check">
                <button type="submit" class="btn">Neueste Version abfragen</button>
            </form>
        <?php elseif ($updateInfo['available'] !== null): ?>
            <div class="success-box">
                Version <strong><?= htmlspecialchars($updateInfo['version']) ?></strong> ist verfügbar
                (veröffentlicht am <?= htmlspecialchars(substr($updateInfo['published_at'], 0, 10)) ?>).
            </div>
            <div class="warn-box">
                &#9888; Vorher die Datenbank sichern. Während des Tauschs ist die Anwendung einige
                Sekunden im Wartungsmodus.
            </div>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['update_csrf']) ?>">
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="expected" value="<?= htmlspecialchars($updateInfo['version']) ?>">
                <button type="submit" class="btn">Update auf <?= htmlspecialchars($updateInfo['version']) ?> einspielen</button>
            </form>
        <?php else: ?>
            <div class="info-box">
                Diese Installation ist aktuell (neueste Version auf GitHub:
                <?= htmlspecialchars($updateInfo['version']) ?>).
            </div>
        <?php endif; ?>

        <p style="margin-top: 20px;">
            <a href="?step=1"><button class="btn btn-secondary">Dateien bereits hochgeladen – weiter zur Systemprüfung</button></a>
        </p>

    <?php // ═══════════════════════════════════════════════════════════════
          // SCHRITT 1: Systemprüfung
          // ═══════════════════════════════════════════════════════════════
    elseif ($step == 1): ?>

        <h2>Schritt 1: Systemprüfung</h2>

        <?php if (!empty($_SESSION['update_done'])): ?>
            <div class="success-box">
                &#10003; Dateien auf Version <strong><?= htmlspecialchars((string) $_SESSION['update_done']['version']) ?></strong>
                aktualisiert. Sicherung der ersetzten Dateien:
                <code><?= htmlspecialchars((string) $_SESSION['update_done']['backup_dir']) ?></code>
            </div>
            <?php unset($_SESSION['update_done']); ?>
        <?php endif; ?>

        <ul class="check-list">
            <?php foreach ($checks as $label => $passed): ?>
                <li class="<?= $passed ? 'pass' : 'fail' ?>">
                    <?= $passed ? '&#10003;' : '&#10007;' ?> <?= htmlspecialchars($label) ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (isset($pdo)): ?>
        <div class="info-box">
            <strong>Erkannte Version:</strong>
            <span class="version-badge version-old"><?= htmlspecialchars($dbVersion) ?></span>
            <span class="version-arrow">&#8594;</span>
            <span class="version-badge version-new"><?= htmlspecialchars($targetVersion) ?></span>
            <?php if ($configValues['prefix'] !== ''): ?>
                &nbsp;&nbsp;<strong>Prefix:</strong> <code><?= htmlspecialchars($configValues['prefix'] ?: '(keiner)') ?></code>
            <?php else: ?>
                &nbsp;&nbsp;<strong>Prefix in config.php:</strong> <em>nicht vorhanden (v1.0.0)</em>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($allChecksPassed): ?>
            <a href="?step=2"><button class="btn">Weiter zur Konfiguration</button></a>
        <?php else: ?>
            <div class="error-box">
                Bitte behebe die oben genannten Probleme, bevor du fortfährst. Läuft der Webspace
                noch auf PHP 7.4, stellst du die Version meist selbst in der Hosting-Verwaltung um;
                die bestehende Installation läuft bis dahin unverändert weiter.
            </div>
        <?php endif; ?>

    <?php // ═══════════════════════════════════════════════════════════════
          // SCHRITT 2: Konfiguration & Backup-Bestätigung
          // ═══════════════════════════════════════════════════════════════
    elseif ($step == 2): ?>

        <h2>Schritt 2: Konfiguration &amp; Backup</h2>

        <div class="warn-box">
            &#9888; <strong>Wichtig:</strong> Erstelle vor dem Update ein vollständiges
            Backup deiner Datenbank! Das Update kann nicht automatisch rückgängig gemacht werden.
        </div>

        <form method="POST">
            <?php if ($configValues['prefix'] === ''): ?>
            <div class="form-group">
                <label>Tabellen-Prefix für die neue Version</label>
                <input type="text" name="prefix"
                       value="es_"
                       pattern="[a-z0-9_]*" placeholder="es_">
                <small>
                    Alle Tabellen werden von <code>users</code>, <code>members</code>, … auf
                    <code>{prefix}users</code>, <code>{prefix}members</code>, … umbenannt
                    und config.php wird um das Prefix-Feld ergänzt.
                    Leer lassen für keinen Prefix.
                </small>
            </div>
            <?php else: ?>
            <input type="hidden" name="prefix" value="<?= htmlspecialchars($configValues['prefix']) ?>">
            <div class="info-box">
                <strong>Prefix:</strong> <code><?= htmlspecialchars($configValues['prefix']) ?></code>
                (aus config.php übernommen)
            </div>
            <?php endif; ?>

            <h2>Geplante Änderungen</h2>
            <ul class="plan-list">
                <?php if ($configValues['prefix'] === ''): ?>
                <li>&#128260; Alle Tabellen umbenennen (Prefix hinzufügen)</li>
                <li>&#128260; config.php: Feld <code>$prefix</code> ergänzen</li>
                <?php endif; ?>
                <?php foreach ($plannedChain as $kettenSchritt): ?>
                <li>&#10133; Migration <?= htmlspecialchars($kettenSchritt['from']) ?> → <?= htmlspecialchars($kettenSchritt['to']) ?>
                    <small>(<code><?= htmlspecialchars($kettenSchritt['file']) ?></code>)</small></li>
                <?php endforeach; ?>
                <?php if ($plannedChain === []): ?>
                <li>Keine Migration nötig – die Datenbank steht auf <?= htmlspecialchars($targetVersion) ?>.</li>
                <?php endif; ?>
                <li>&#10133; Schema-Version eintragen</li>
            </ul>

            <div class="checkbox-group">
                <input type="checkbox" name="backup_confirmed" id="backup_confirmed" required>
                <label for="backup_confirmed">
                    Ich habe ein vollständiges Datenbank-Backup erstellt und bin bereit,
                    die Migration durchzuführen.
                </label>
            </div>

            <button type="submit" class="btn">Migration jetzt starten</button>
        </form>

    <?php // ═══════════════════════════════════════════════════════════════
          // SCHRITT 3: Ergebnis
          // ═══════════════════════════════════════════════════════════════
    elseif ($step == 3): ?>

        <?php if ($migrationOk): ?>

            <h2>&#10003; Migration erfolgreich</h2>
            <div class="success-box">
                Die Datenbank wurde erfolgreich auf Version
                <strong><?= htmlspecialchars($targetVersion) ?></strong> migriert.
                Der Update-Assistent ist jetzt wieder gesperrt.
            </div>

            <?php if ($migrationWarn): ?>
            <div class="warn-box">
                <strong>Warnungen:</strong>
                <ul class="log-list" style="margin-top:8px;">
                    <?php foreach ($migrationWarn as $w): ?>
                        <li class="warn">&#9888; <?= $w ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <h2>Protokoll</h2>
            <ul class="log-list">
                <?php foreach ($migrationLog as $entry): ?>
                    <li class="ok">&#10003; <?= $entry ?></li>
                <?php endforeach; ?>
            </ul>

            <a href="../index.html"><button class="btn">Zur Anwendung</button></a>

        <?php else: ?>

            <h2>&#10060; Migration fehlgeschlagen</h2>
            <div class="error-box"><?= $error ?></div>

            <?php if ($migrationLog): ?>
            <h2>Protokoll (bis zum Fehler)</h2>
            <ul class="log-list">
                <?php foreach ($migrationLog as $entry): ?>
                    <li class="ok">&#10003; <?= $entry ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <div class="warn-box">
                Stelle das Datenbank-Backup wieder her und prüfe den Fehler, bevor du
                die Migration erneut versuchst.
            </div>
            <a href="?step=1"><button class="btn">Zum Anfang</button></a>

        <?php endif; ?>

    <?php endif; ?>
</div>
</body>
</html>
<?php
// Erst jetzt sperren (OI-23): Stand die Sperre vor der Ausgabe, war bei einem
// Fehler beim Rendern der Assistent zu und das Ergebnis nirgends mehr zu sehen.
// Reine LF-Zeilenenden: Unter Windows mit core.autocrlf=true traegt schon das
// Heredoc CRLF; zusammen mit dem angehaengten "\n" entstand eine gemischte
// Datei, die Git nach jedem Update als geaendert meldete.
if ($migrationOk) {
    file_put_contents(HTACCESS_PATH, str_replace(["\r\n", "\r"], "\n", $htaccessContent) . "\n");
}
