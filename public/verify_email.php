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


header("Content-Type: text/html; charset=UTF-8");

require_once '../private/config/config.php';
require_once '../private/helpers/mailer.php';
require_once '../private/helpers/branding.php';

session_start();

$database = new Database();
$db = $database->getConnection();

$branding = getBrandingSettings($db);

// Token aus URL
$token = $_GET['token'] ?? '';

if (empty($token) || !ctype_xdigit($token) || strlen($token) !== 64) {
    showError('Ungültiger Verifikations-Link',$branding);
    exit();
}

try {
    // Token hashen (wie in DB gespeichert)
    $tokenHash = hash('sha256', $token);
    
    // Token aus DB holen
    $stmt = $db->prepare(
        "SELECT evt.user_id, u.name, u.email 
         FROM email_verification_tokens evt
         JOIN users u ON evt.user_id = u.user_id
         WHERE evt.token = ? 
         AND evt.used = 0 
         AND evt.expires_at > NOW()"
    );
    $stmt->execute([$tokenHash]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        showError('Token ungültig oder abgelaufen', 'Der Verifikations-Link ist nicht mehr gültig oder wurde bereits verwendet.',$branding);
        exit();
    }
    
    // User als verifiziert markieren
    $db->beginTransaction();
    
    $stmt = $db->prepare(
        "UPDATE users 
         SET email_verified = 1 
         WHERE user_id = ?"
    );
    $stmt->execute([$result['user_id']]);
    
    // Token als verwendet markieren
    $stmt = $db->prepare(
        "UPDATE email_verification_tokens 
         SET used = 1 
         WHERE token = ?"
    );
    $stmt->execute([$tokenHash]);
    
    $db->commit();

    // Mail-Status prüfen BEVOR User gesucht wird
    $mailer = new Mailer(getMailConfig(), $db);
    $mailStatus = $mailer->checkMailStatus();

    if (!$mailStatus['enabled']) {
        exit();
    }

    // Admin benachrichtigen
    // Hole Benutzerinformationen für die Admin-Benachrichtigung
    $user_stmt = $db->prepare("SELECT name, email FROM users WHERE user_id = ?");
    $user_stmt->execute([$result['user_id']]);
    $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

    // Benachrichtige alle Admins
    $admin_stmt = $db->prepare("SELECT email FROM users WHERE role = 'admin' AND is_active = 1");
    $admin_stmt->execute();

    while ($admin = $admin_stmt->fetch(PDO::FETCH_ASSOC)) {
        $subject = "Neue Registrierung: {$user_data['name']}";
        $message = "Ein neuer Benutzer hat seine E-Mail-Adresse bestätigt:\n\n";
        $message .= "Name: {$user_data['name']}\n";
        $message .= "E-Mail: {$user_data['email']}\n\n";
        $message .= "Bitte aktivieren Sie den Benutzer über die Mitgliederverwaltung.";
        
        $mailer->send($admin['email'], $subject, $message);
    }
    
    // Erfolg anzeigen
    showSuccess($result['name'],$branding);
    exit();
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Email verification error: " . $e->getMessage());
    showError('Fehler bei der Verifikation', 'Ein technischer Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.',$branding);
}

// ============================================
// HTML OUTPUT FUNCTIONS
// ============================================

function showSuccess($name, $branding) {
    
    $brandingCSS = getBrandingCSS($branding);
    $brandingLogo = getBrandingLogo($branding);
    $orgName = htmlspecialchars($branding['organization_name']) 
    ?>
    <!DOCTYPE html>
    <html lang='de'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>E-Mail bestätigt - <?= $orgName ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            <?= $brandingCSS ?>

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
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 500;
                transition: background 0.3s;
            }
            .info-box {
                background: #f8f9fa;
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
            <?= $brandingLogo ?>
            <div class='success-icon'></div>
            <h1>Email erfolgreich bestätigt!</h1>
            <p>Vielen Dank <strong><?= htmlspecialchars($name) ?></strong>.</p>
            <p>Ihre Email-Adresse wurde erfolgreich verifiziert.</p>
            
            <div class='info-box'>
                <p><strong>Nächste Schritte:</strong></p>
                <p>• Ein Administrator wird Ihren Account in Kürze freischalten</p>
                <p>• Ihr Account wird mit Ihrem Mitgliedsprofil verknüpft</p>
                <p>• Sie erhalten eine Email, sobald Ihr Account aktiviert wurde</p>
            </div>
            
            <a href='login.html' class='button'>Zur Login-Seite</a>
        </div>
    </body>
    </html>
    <?php
}

function showError($title, $message = '', $branding) {
    $brandingCSS = getBrandingCSS($branding);
    $brandingLogo = getBrandingLogo($branding);
    $orgName = htmlspecialchars($branding['organization_name'])
    ?>
    <!DOCTYPE html>
    <html lang='de'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Fehler - <?= $orgName ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            <?= $brandingCSS ?>
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
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 500;
                transition: background 0.3s;
            }
            .info-box {
                background: #f8f9fa;
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
            <?= $brandingLogo ?>
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