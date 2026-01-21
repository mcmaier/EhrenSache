<?php
session_start();

// Prüfe ob bereits installiert
if (file_exists('../../private/config/install.lock')) {
    die('Installation bereits abgeschlossen. Lösche install.lock zum Neuinstallieren.');
}

$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

// STEP 1: Voraussetzungen prüfen
if ($step == 1) {
    $checks = [
        'PHP Version >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'PDO Extension' => extension_loaded('pdo'),
        'PDO MySQL' => extension_loaded('pdo_mysql'),
        'JSON Extension' => extension_loaded('json'),
        'Session Support' => function_exists('session_start')
    ];
    
    $allPassed = !in_array(false, $checks, true);
}

// STEP 2: Datenbank-Konfiguration
if ($step == 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = $_POST['db_host'] ?? 'localhost';
    $dbName = $_POST['db_name'] ?? '';
    $dbUser = $_POST['db_user'] ?? '';
    $dbPass = $_POST['db_pass'] ?? '';
    
    // Teste Verbindung
    try {
        $dsn = "mysql:host={$dbHost};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Prüfe/Erstelle Datenbank
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");
        
        // Speichere Config
        $_SESSION['db_config'] = [
            'host' => $dbHost,
            'name' => $dbName,
            'user' => $dbUser,
            'pass' => $dbPass
        ];
        
        header('Location: ?step=3');
        exit;
        
    } catch (PDOException $e) {
        $error = "Datenbankverbindung fehlgeschlagen: " . $e->getMessage();
    }
}

// STEP 3: Admin-Account erstellen
if ($step == 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminEmail = $_POST['admin_email'] ?? '';
    $adminPassword = $_POST['admin_password'] ?? '';
    $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';
    
    if (strlen($adminPassword) < 6) {
        $error = "Passwort muss mindestens 6 Zeichen haben";
    } elseif ($adminPassword !== $adminPasswordConfirm) {
        $error = "Passwörter stimmen nicht überein";
    } else {
        $_SESSION['admin_account'] = [
            'email' => $adminEmail,
            'password' => $adminPassword
        ];
        header('Location: ?step=4');
        exit;
    }
}

// STEP 4: Installation durchführen
if ($step == 4) {
    if (!isset($_SESSION['db_config']) || !isset($_SESSION['admin_account'])) {
        header('Location: ?step=1');
        exit;
    }
    
    try {
        $cfg = $_SESSION['db_config'];
        $pdo = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4",
            $cfg['user'],
            $cfg['pass']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Importiere Schema
        $schema = file_get_contents('../../private/setup/ehrensache_db.sql');
        $pdo->exec($schema);
        
        // Erstelle Admin-User
        $admin = $_SESSION['admin_account'];
        $passwordHash = password_hash($admin['password'], PASSWORD_DEFAULT);
        $apiToken = bin2hex(random_bytes(24));
        $tokenExpires = date('Y-m-d H:i:s', strtotime('+1 year'));        
        
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, account_status, api_token, api_token_expires_at) 
                               VALUES (?, ?, 'admin', 1, 'active', ?, ?)");
        $stmt->execute([$admin['email'], $passwordHash, $apiToken, $tokenExpires]);
               
        // Prüfen ob config.php bereits existiert
        if (file_exists('../../private/config/config.php')) {
            die('Installation bereits durchgeführt. Lösche config.php für Neuinstallation.');
        }

        // config.php erstellen aus Template
        $configTemplate = file_get_contents('../../private/config/config_example.php');
        $configContent = str_replace(
            ['your_host','your_database', 'your_username', 'your_password'],
            [$cfg['host'], $cfg['name'], $cfg['user'], $cfg['pass']],
            $configTemplate
        );
        file_put_contents('../../private/config/config.php', $configContent);
        
        // Erstelle Lock-File
        file_put_contents('../../private/config/install.lock', date('Y-m-d H:i:s'));
        
        // Update api.php require_once
        $apiPath = '../api/api.php';
        $apiContent = file_get_contents($apiPath);
        $apiContent = str_replace(
            "require_once 'config.php';",
            "require_once '../../private/config/config.php';",
            $apiContent
        );
        file_put_contents($apiPath, $apiContent);
        
        $success = true;

        if ($success) {
            // Lock-File erstellen
            file_put_contents('../../private/config/install.lock', date('Y-m-d H:i:s'));

            // In Step 4 nach Lock-File:
            $htaccessContent = <<<'HTACCESS'
            # Installation abgeschlossen - Zugriff gesperrt
            Order Deny,Allow
            Deny from all
            HTACCESS;

            file_put_contents(__DIR__ . '/.htaccess', $htaccessContent);
            
            $_SESSION['admin_token'] = $apiToken; 
        }  
        
    } catch (Exception $e) {
        $error = "Installation fehlgeschlagen: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EhrenSache Installation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container { 
            background: white; 
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
        }
        h1 { color: #667eea; margin-bottom: 10px; }
        h2 { color: #333; margin: 20px 0; font-size: 20px; }
        .progress { 
            display: flex; 
            gap: 10px; 
            margin: 20px 0; 
        }
        .progress-step {
            flex: 1;
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
        }
        .progress-step.active { background: #667eea; }
        .progress-step.done { background: #27ae60; }
        .form-group { margin: 20px 0; }
        label { 
            display: block; 
            margin-bottom: 5px; 
            color: #555;
            font-weight: 500;
        }
        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        input:focus { 
            outline: none; 
            border-color: #667eea; 
        }
        .btn {
            background: #667eea;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 10px;
        }
        .btn:hover { background: #5568d3; }
        .error { 
            background: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #c33;
        }
        .success { 
            background: #efe;
            color: #282;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #282;
        }
        .check-list { list-style: none; }
        .check-list li { 
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
        }
        .check-list li.pass { background: #e8f5e9; color: #2e7d32; }
        .check-list li.fail { background: #ffebee; color: #c62828; }
        code { 
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        small { color: #777; display: block; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎯 EhrenSache Installation</h1>
        <p>Schritt-für-Schritt Setup für Shared-Hosting</p>
        
        <div class="progress">
            <div class="progress-step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'done' : '' ?>"></div>
            <div class="progress-step <?= $step >= 2 ? 'active' : '' ?> <?= $step > 2 ? 'done' : '' ?>"></div>
            <div class="progress-step <?= $step >= 3 ? 'active' : '' ?> <?= $step > 3 ? 'done' : '' ?>"></div>
            <div class="progress-step <?= $step >= 4 ? 'active' : '' ?>"></div>
        </div>

        <?php if ($error): ?>
            <div class="error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($step == 1): ?>
            <h2>Schritt 1: Systemprüfung</h2>
            <ul class="check-list">
                <?php foreach ($checks as $name => $passed): ?>
                    <li class="<?= $passed ? 'pass' : 'fail' ?>">
                        <?= $passed ? '✓' : '✗' ?> <?= $name ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            
            <?php if ($allPassed): ?>
                <div class="success">✓ Alle Voraussetzungen erfüllt!</div>
                <a href="?step=2"><button class="btn">Weiter zur Datenbank-Konfiguration</button></a>
            <?php else: ?>
                <div class="error">Bitte kontaktiere deinen Hosting-Provider für fehlende Extensions.</div>
            <?php endif; ?>

        <?php elseif ($step == 2): ?>
            <h2>Schritt 2: Datenbank-Konfiguration</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Datenbank-Host:Port*</label>
                    <input type="text" name="db_host" value="localhost" required>
                    <small>Meist "localhost", check bei deinem Hoster. Ein anderer Port als 3306 muss angegeben werden.</small>
                </div>
                <div class="form-group">
                    <label>Datenbankname*</label>
                    <input type="text" name="db_name" required>
                    <small>Wird automatisch erstellt falls nicht vorhanden</small>
                </div>
                <div class="form-group">
                    <label>Benutzername*</label>
                    <input type="text" name="db_user" required>
                </div>
                <div class="form-group">
                    <label>Passwort*</label>
                    <input type="password" name="db_pass" required>
                </div>
                <button type="submit" class="btn">Verbindung testen & weiter</button>
            </form>

        <?php elseif ($step == 3): ?>
            <h2>Schritt 3: Admin-Account erstellen</h2>
            <form method="POST">
                <div class="form-group">
                    <label>E-Mail-Adresse*</label>
                    <input type="email" name="admin_email" required>
                </div>
                <div class="form-group">
                    <label>Passwort* (min. 8 Zeichen)</label>
                    <input type="password" name="admin_password" required minlength="8">
                </div>
                <div class="form-group">
                    <label>Passwort bestätigen*</label>
                    <input type="password" name="admin_password_confirm" required>
                </div>
                <button type="submit" class="btn">Account erstellen & installieren</button>
            </form>

            <?php elseif ($step == 4 && $success): ?>
                <h2>✅ Installation erfolgreich!</h2>
                <div class="success">
                    <strong>Deine Zugangsdaten:</strong><br>
                    E-Mail: <?= htmlspecialchars($_SESSION['admin_account']['email']) ?><br>
                    API-Token: <code><?= $_SESSION['admin_token'] ?></code>
                </div>                
                
                <h3>Nächste Schritte:</h3>
                <ol style="line-height: 2; margin: 20px 0;">                    
                    <li>⚠️ <strong>/install/ Verzeichnis manuell löschen!</strong></li>
                    <li>📧 <strong>Mail-Server konfigureren.</strong></li>
                </ol>
            
            <a href="../index.html"><button class="btn">Zum Login</button></a>
        <?php endif; ?>
    </div>
</body>
</html>