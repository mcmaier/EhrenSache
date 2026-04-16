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

// ── Hilfsfunktionen ──────────────────────────────────────────────────────────

/** Liest die relevanten Werte aus config.php via Regex (sicher für 1.0.0 ohne $prefix). */
function parseConfig(string $file): array
{
    if (!file_exists($file)) return [];
    $c = file_get_contents($file);
    $result = [];
    foreach (['host', 'db_name', 'username', 'password', 'prefix'] as $key) {
        $result[$key] = preg_match('/private\s+\$' . $key . '\s*=\s*"([^"]*)"/', $c, $m) ? $m[1] : '';
    }
    return $result;
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

/** Prüft ob eine Tabelle in der aktuellen DB existiert. */
function tableExists(PDO $pdo, string $table): bool
{
    return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->rowCount();
}

/** Ermittelt die installierte DB-Version anhand der vorhandenen Tabellen. */
function detectDbVersion(PDO $pdo, string $prefix): string
{
    // Schema-Versions-Tabelle (mit Prefix, wie sie die Migration anlegt)
    if (!empty($prefix) && tableExists($pdo, $prefix . 'schema_version')) {
        $row = $pdo->query("SELECT version FROM `{$prefix}schema_version`
                            ORDER BY applied_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row['version'] ?? '1.1.x';
    }
    // Kein Prefix: schema_version ohne Prefix
    if (empty($prefix) && tableExists($pdo, 'schema_version')) {
        $row = $pdo->query("SELECT version FROM `schema_version`
                            ORDER BY applied_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row['version'] ?? '1.1.x';
    }
    // Alte Tabellen ohne Prefix vorhanden → 1.0.0
    if (tableExists($pdo, 'users') && !tableExists($pdo, $prefix . 'users')) {
        return '1.0.0';
    }
    // Prefix-Tabellen ohne Schema-Versions-Tabelle → kurz nach 1.1.0 installiert
    if (!empty($prefix) && tableExists($pdo, $prefix . 'users')) {
        return '1.1.x';
    }
    return 'unbekannt';
}

/** Gibt die Ziel-Version aus version.json zurück. */
function getTargetVersion(): string
{
    if (!file_exists(VERSION_PATH)) return '1.1.3';
    $v = json_decode(file_get_contents(VERSION_PATH), true);
    return $v['version'] ?? '1.1.3';
}

// ── Schritt-Logik ─────────────────────────────────────────────────────────────

$step  = (int) ($_GET['step'] ?? 1);
$error = '';

// ── SCHRITT 1: Systemprüfung ─────────────────────────────────────────────────
$checks       = [];
$configValues = [];
$dbVersion    = 'unbekannt';
$targetVersion = getTargetVersion();

if ($step >= 1) {
    $checks = [
        'PHP >= 7.4'       => version_compare(PHP_VERSION, '7.4.0', '>='),
        'PDO Extension'    => extension_loaded('pdo'),
        'PDO MySQL'        => extension_loaded('pdo_mysql'),
        'install.lock'     => file_exists(LOCK_PATH),
        'config.php'       => file_exists(CONFIG_PATH),
        'Schreibrecht config.php' => file_exists(CONFIG_PATH) && is_writable(CONFIG_PATH),
    ];

    if ($checks['config.php']) {
        $configValues = parseConfig(CONFIG_PATH);
        try {
            $pdo       = connectDb($configValues);
            $dbVersion = detectDbVersion($pdo, $configValues['prefix'] ?? '');
            $checks['Datenbankverbindung'] = true;
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
    $configCfg = parseConfig(CONFIG_PATH);

    try {
        $pdo = connectDb($configCfg);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        require_once MIGRATION_PATH . '1.0.0.php';
        $result       = migrate_1_0_0($pdo, $prefix, CONFIG_PATH);
        $migrationLog  = $result['log'];
        $migrationWarn = $result['warnings'];
        $migrationOk   = true;

        // Update-Wizard wieder sperren
        $htaccessContent = "# Update abgeschlossen – Zugriff gesperrt\nOrder Deny,Allow\nDeny from all\n";
        file_put_contents(HTACCESS_PATH, $htaccessContent);

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
          // SCHRITT 1: Systemprüfung
          // ═══════════════════════════════════════════════════════════════
    if ($step == 1): ?>

        <h2>Schritt 1: Systemprüfung</h2>

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

        <?php if ($dbVersion !== '1.0.0' && $dbVersion !== 'unbekannt' && strpos($dbVersion, '1.0') === false): ?>
            <div class="warn-box">
                &#9888; Die erkannte Datenbankversion <strong><?= htmlspecialchars($dbVersion) ?></strong>
                weicht von der erwarteten Ausgangsversion (1.0.0) ab.
                Das Update kann trotzdem ausgeführt werden – alle Schritte sind idempotent.
            </div>
        <?php endif; ?>

        <?php if ($allChecksPassed): ?>
            <a href="?step=2"><button class="btn">Weiter zur Konfiguration</button></a>
        <?php else: ?>
            <div class="error-box">Bitte behebe die oben genannten Probleme, bevor du fortfährst.</div>
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
                <li>&#10133; Neue Tabelle: <code>import_logs</code></li>
                <li>&#10133; Neue Spalte: <code>appointments.type_id</code></li>
                <li>&#10133; Neue Indizes auf <code>records</code>, <code>appointments</code>, <code>member_group_assignments</code></li>
                <li>&#128260; system_settings: ENUM <code>appearance</code> → <code>public</code></li>
                <li>&#10133; Neue Einstellung: <code>privacy_policy_url</code></li>
                <li>&#128260; View <code>v_users_extended</code> → <code><?= htmlspecialchars(($configValues['prefix'] ?? '') . 'v_users_extended') ?></code></li>
                <li>&#10133; Schema-Versionstabelle anlegen &amp; Version eintragen</li>
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
