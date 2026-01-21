<?php
header("Content-Type: text/html; charset=UTF-8");

require_once '../private/config/config.php';

session_start();

$database = new Database();
$db = $database->getConnection();

$token = $_GET['token'] ?? '';

// Wenn POST: Passwort setzen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirm'] ?? '';
    
    // Validierung
    if (strlen($newPassword) < 8) {
        showForm($token, 'Passwort muss mindestens 8 Zeichen lang sein');
        exit();
    }
    
    if ($newPassword !== $confirmPassword) {
        showForm($token, 'Passwörter stimmen nicht überein');
        exit();
    }
    
    try {
        $tokenHash = hash('sha256', $token);
        
        // Token prüfen
        $stmt = $db->prepare(
            "SELECT prt.user_id, u.email 
             FROM password_reset_tokens prt
             JOIN users u ON prt.user_id = u.user_id
             WHERE prt.token = ? 
             AND prt.used = 0 
             AND prt.expires_at > NOW()"
        );
        $stmt->execute([$tokenHash]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            showError('Token ungültig', 'Der Reset-Link ist abgelaufen oder wurde bereits verwendet.');
            exit();
        }
        
        // Passwort ändern
        $db->beginTransaction();
        
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare(
            "UPDATE users SET password_hash = ? WHERE user_id = ?"
        );
        $stmt->execute([$passwordHash, $result['user_id']]);
        
        // Token als verwendet markieren
        $stmt = $db->prepare(
            "UPDATE password_reset_tokens SET used = 1 WHERE token = ?"
        );
        $stmt->execute([$tokenHash]);
        
        $db->commit();
        
        showSuccess();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Password reset error: " . $e->getMessage());
        showError('Fehler', 'Ein technischer Fehler ist aufgetreten.');
    }
    
    exit();
}

// GET: Formular anzeigen
if (empty($token) || !ctype_xdigit($token) || strlen($token) !== 64) {
    showError('Ungültiger Link');
    exit();
}

// Token validieren
$tokenHash = hash('sha256', $token);

$stmt = $db->prepare(
    "SELECT user_id FROM password_reset_tokens 
     WHERE token = ? AND used = 0 AND expires_at > NOW()"
);
$stmt->execute([$tokenHash]);

if (!$stmt->fetch()) {
    showError('Token ungültig', 'Der Reset-Link ist abgelaufen oder wurde bereits verwendet.');
    exit();
}

showForm($token);

// ============================================
// HTML OUTPUT FUNCTIONS
// ============================================

function showForm($token, $error = '') {
    ?>
    <!DOCTYPE html>
    <html lang='de'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Passwort zurücksetzen - EhrenSache</title>
        <style>
            /* Gleiche Styles wie verify_email.php */
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                max-width: 500px;
                width: 100%;
                padding: 40px;
            }
            h1 { color: #333; margin-bottom: 20px; text-align: center; }
            .form-group { margin-bottom: 20px; }
            label { display: block; margin-bottom: 5px; color: #333; font-weight: 500; }
            input[type="password"] {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 6px;
                font-size: 14px;
            }
            input[type="password"]:focus {
                outline: none;
                border-color: #667eea;
            }
            button {
                width: 100%;
                padding: 12px;
                background: #667eea;
                color: white;
                border: none;
                border-radius: 6px;
                font-size: 16px;
                font-weight: 500;
                cursor: pointer;
                transition: background 0.3s;
            }
            button:hover { background: #5568d3; }
            .error {
                background: #f8d7da;
                border-left: 4px solid #dc3545;
                padding: 12px;
                margin-bottom: 20px;
                color: #721c24;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <h1>Neues Passwort setzen</h1>
            
            <?php if ($error): ?>
                <div class='error'><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <form method='POST'>
                <input type='hidden' name='token' value='<?= htmlspecialchars($token) ?>'>
                
                <div class='form-group'>
                    <label for='password'>Neues Passwort</label>
                    <input type='password' id='password' name='password' 
                           required minlength='8' autocomplete='new-password'>
                    <small style='color: #666;'>Mindestens 8 Zeichen</small>
                </div>
                
                <div class='form-group'>
                    <label for='password_confirm'>Passwort bestätigen</label>
                    <input type='password' id='password_confirm' name='password_confirm' 
                           required minlength='8' autocomplete='new-password'>
                </div>
                
                <button type='submit'>Passwort ändern</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

function showSuccess() {
    ?>
    <!DOCTYPE html>
    <html lang='de'>
    <head>
        <meta charset='UTF-8'>
        <title>Passwort geändert - EhrenSache</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                max-width: 500px;
                width: 100%;
                padding: 40px;
                text-align: center;
            }
            .success-icon {
                width: 80px;
                height: 80px;
                background: #28a745;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
                animation: scaleIn 0.5s ease-out;
            }
            .success-icon::after {
                content: '✓';
                color: white;
                font-size: 48px;
                font-weight: bold;
            }
            @keyframes scaleIn {
                from { transform: scale(0); }
                to { transform: scale(1); }
            }
            h1 {
                color: #333;
                margin-bottom: 15px;
                font-size: 24px;
            }
            p {
                color: #666;
                line-height: 1.6;
                margin-bottom: 10px;
            }
            strong { color: #333; }
            .button {
                display: inline-block;
                margin-top: 30px;
                padding: 12px 30px;
                background: #667eea;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 500;
                transition: background 0.3s;
            }
            .button:hover {
                background: #5568d3;
            }
            .info-box {
                background: #f8f9fa;
                border-left: 4px solid #667eea;
                padding: 15px;
                margin-top: 20px;
                text-align: left;
            }
            .info-box p {
                margin: 0;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='success-icon'></div>
            <h1>Passwort erfolgreich geändert!</h1>
            <p>Sie können sich jetzt mit Ihrem neuen Passwort einloggen.</p>
            <a href='login.html' class='button'>Zum Login</a>
        </div>
    </body>
    </html>
    <?php
}


function showError($title, $message = '') {
    ?>
    <!DOCTYPE html>
    <html lang='de'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Fehler - EhrenSache</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                max-width: 500px;
                width: 100%;
                padding: 40px;
                text-align: center;
            }
            .error-icon {
                width: 80px;
                height: 80px;
                background: #dc3545;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
            }
            .error-icon::after {
                content: '✗';
                color: white;
                font-size: 48px;
                font-weight: bold;
            }
            h1 {
                color: #333;
                margin-bottom: 15px;
                font-size: 24px;
            }
            p {
                color: #666;
                line-height: 1.6;
                margin-bottom: 10px;
            }
            .button {
                display: inline-block;
                margin-top: 30px;
                padding: 12px 30px;
                background: #667eea;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 500;
                transition: background 0.3s;
            }
            .button:hover {
                background: #5568d3;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='error-icon'></div>
            <h1><?= htmlspecialchars($title) ?></h1>
            <?php if ($message): ?>
                <p><?= htmlspecialchars($message) ?></p>
            <?php endif; ?>
            <a href='login.html' class='button'>Zur Login-Seite</a>
        </div>
    </body>
    </html>
    <?php
}

?>