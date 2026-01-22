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
// ============================================
// SETTINGS Controller
// ============================================
function handleSettings($db, $method, $authUserId, $authUserRole) {

    // Nur Admins dürfen auf Einstellungen zugreifen
    requireAdmin();

    try {
        switch ($method) {
            case 'GET':
                getSettings($db);

                // Alle Einstellungen abrufen
                /*$stmt = $db->query("SELECT * FROM system_settings ORDER BY category, setting_key");
                $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['settings' => $settings]);*/
                break;

            case 'POST':
                $data = json_decode(file_get_contents("php://input"));
                
                if (!isset($data->action)) {
                    http_response_code(400);
                    echo json_encode(['message' => 'Action required']);
                    return;
                }
                
                switch($data->action) {
                    case 'get_smtp_config':
                        getSmtpConfig();
                        break;
                        
                    case 'save_smtp_config':
                        saveSmtpConfig($db, $data->config);
                        break;
                        
                    case 'test_mail':
                        sendTestMail($db, $data->recipient);
                        break;
                        
                    default:
                        http_response_code(400);
                        echo json_encode(['message' => 'Unknown action']);
                }
            break;

            case 'PUT':
                // Einstellung aktualisieren
                $data = json_decode(file_get_contents('php://input'), true);
                updateSetting($db, $data['setting_key'], $data['setting_value']);                                            
                break;

            default:
                http_response_code(405);
                echo json_encode(['error' => 'Methode nicht erlaubt']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}


function getSettings($db) {
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key");
        $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['settings' => $settings]);
        
    } catch (Exception $e) {
        error_log("Get settings error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Fehler beim Laden']);
    }
}

function updateSetting($db, $key, $value) {

    try {
        if (!isset($key) || !isset($value)) {
            throw new Exception('Fehlende Parameter');
        }

        $stmt = $db->prepare(
            "INSERT INTO system_settings (setting_key, setting_value) 
             VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        
        $stmt->execute([$key, $value]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Einstellung gespeichert'
        ]);
        
    } catch (Exception $e) {
        error_log("Update setting error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Fehler beim Speichern']);
    }
}

function getSmtpConfig() {
    $configPath = __DIR__ . '/../config/mail_config.php';
    
    if (!file_exists($configPath)) {
        echo json_encode([
            'success' => true,
            'config' => [
                'smtp_host' => '',
                'smtp_port' => 587,
                'smtp_encryption' => 'tls',
                'smtp_user' => '',
                'smtp_password_set' => false
            ]
        ]);
        return;
    }
    
    $config = require $configPath;

    $encryption = 'none';
        if ($config['use_tls']) {
            $encryption = ($config['smtp_port'] == 465) ? 'ssl' : 'tls';
        }
    
    echo json_encode([
        'success' => true,
        'config' => [
            'smtp_host' => $config['smtp_host'] ?? '',
            'smtp_port' => $config['smtp_port'] ?? 587,
            'smtp_encryption' => $encryption,
            'smtp_user' => $config['smtp_user'] ?? '',
            'smtp_password_set' => !empty($config['smtp_pass'])
        ]
    ]);
}

function saveSmtpConfig($db, $config) {
    try {
        $configPath = __DIR__ . '/../config/mail_config.php';
        
        // Bestehende Config laden (falls vorhanden)
        $existingConfig = [];
        if (file_exists($configPath)) {
            $existingConfig = require $configPath;
        }

        $encryption = $config->smtp_encryption ?? 'none';
    
        // Encryption in use_tls Flag konvertieren
        $use_tls = ($encryption === 'tls' || $encryption === 'ssl');
        
        // Port-Empfehlung setzen falls leer
        if (empty($config->smtp_port)) {
            $config->smtp_port = ($encryption === 'ssl') ? 465 : 587;
        }
        
        // Neue Werte übernehmen
        $newConfig = [
            'smtp_host' => $config->smtp_host ?? $existingConfig['smtp_host'] ?? '',
            'smtp_port' => intval($config->smtp_port ?? $existingConfig['smtp_port'] ?? 587),
            'smtp_user' => $config->smtp_user ?? $existingConfig['smtp_user'] ?? '',
            'smtp_pass' => !empty($config->smtp_password) 
                ? $config->smtp_password 
                : ($existingConfig['smtp_pass'] ?? ''),
            'use_tls' => $use_tls,
            'from_email' => $existingConfig['from_email'] ?? 'noreply@example.com',
            'from_name' => $existingConfig['from_name'] ?? 'EhrenSache'
        ];
        
        // Config-File schreiben
        $content = "<?php\n";
        $content .= "// Auto-generated mail configuration\n";
        $content .= "// DO NOT EDIT MANUALLY - Use Settings in Admin Panel\n\n";
        $content .= "return [\n";
        $content .= "    'smtp_host' => " . var_export($newConfig['smtp_host'], true) . ",\n";
        $content .= "    'smtp_port' => " . intval($newConfig['smtp_port']) . ",\n";
        $content .= "    'smtp_user' => " . var_export($newConfig['smtp_user'], true) . ",\n";
        $content .= "    'smtp_pass' => " . var_export($newConfig['smtp_pass'], true) . ",\n";
        $content .= "    'from_email' => " . var_export($newConfig['from_email'], true) . ",\n";
        $content .= "    'from_name' => " . var_export($newConfig['from_name'], true) . ",\n";
        $content .= "    'use_tls' => " . ($newConfig['use_tls'] ? 'true' : 'false') . "\n";
        $content .= "];\n";
        
        file_put_contents($configPath, $content);
        chmod($configPath, 0600);
        
        // smtp_configured Flag in DB setzen
        $stmt = $db->prepare(
            "INSERT INTO system_settings (setting_key, setting_value) 
             VALUES ('smtp_configured', '1') 
             ON DUPLICATE KEY UPDATE setting_value = '1'"
        );
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'SMTP-Konfiguration gespeichert'
        ]);
        
    } catch (Exception $e) {
        error_log("Save SMTP config error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Speichern: ' . $e->getMessage()
        ]);
    }
}

function sendTestMail($db, $recipient) {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Ungültige Email-Adresse'
        ]);
        return;
    }
    
    try {
        require_once __DIR__ . '/../helpers/mailer.php';
        
        $mailer = new Mailer(getMailConfig(), $db);
        
        $subject = 'Test-Email von EhrenSache';
        $body = "Dies ist eine Test-Email.\n\n";
        $body .= "Wenn Sie diese Email erhalten, sind die SMTP-Einstellungen korrekt konfiguriert.\n\n";
        $body .= "Zeitstempel: " . date('d.m.Y H:i:s') . "\n";
        $body .= "System: EhrenSache\n";
        
        $success = $mailer->send($recipient, $subject, $body);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Test-Email erfolgreich versendet'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Email-Versand fehlgeschlagen. Pr�fen Sie die Einstellungen und Server-Logs.'
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Test mail error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Fehler: ' . $e->getMessage()
        ]);
    }
}

function getAppearance($db)
{
    try {
    // Nur Appearance-Einstellungen f�r �ffentlichen Zugriff
    $stmt = $db->prepare("
        SELECT setting_key, setting_value 
        FROM system_settings 
        WHERE category = 'appearance' OR setting_key = 'organization_name'
    ");
    $stmt->execute();
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    echo json_encode(['settings' => $settings]);
    
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Fehler beim Laden']);
    }
}


function uploadLogo($db, $method, $authUserId, $authUserRole)
{
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin-Zugriff erforderlich']);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Methode nicht erlaubt']);
        exit;
    }

    try {
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Keine Datei hochgeladen');
        }
    
        $file = $_FILES['logo'];
        
        // Validierung
        $allowedTypes = ['image/png', 'image/jpeg', 'image/svg+xml'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Ungültiger Dateityp');
        }
        
        // 2. Dateiendung prüfen
        $allowedExtensions = ['png', 'jpg', 'jpeg', 'svg'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception('Ungültige Dateiendung');
        }

        if ($file['size'] > 500 * 1024) { // 500KB
            throw new Exception('Datei zu groß (max. 500KB)');
        }

        // Für SVG: XML-Validierung
        if ($mimeType === 'image/svg+xml') {
            $content = file_get_contents($file['tmp_name']);
            
            // Blockiere gefährliche Tags
            $dangerous = ['script', 'onclick', 'onload', 'onerror', 'onmouseover'];
            foreach ($dangerous as $tag) {
                if (stripos($content, $tag) !== false) {
                    throw new Exception('SVG enthält nicht erlaubte Elemente');
                }
            }
            
            // Validiere XML-Struktur
            libxml_use_internal_errors(true);
            $svg = simplexml_load_string($content);
            if ($svg === false) {
                throw new Exception('Ungültige SVG-Datei');
            }
            libxml_clear_errors();
        }

        //error_log("File Check ok.");
        
        // Upload-Verzeichnis
        $uploadDir = '../uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
                
        // Eindeutiger Dateiname
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'logo_' . bin2hex(random_bytes(4)). '.' . $extension;
        $targetPath = $uploadDir . $filename;

        //error_log("File Path: {$targetPath}");

         // Altes Logo löschen (optional)
        $stmt = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'organization_logo'");
        $oldLogo = $stmt->fetchColumn();
        if ($oldLogo && file_exists('../public/' . $oldLogo)) {
            unlink('../public/' . $oldLogo);
        }
        
        // Datei verschieben
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Upload fehlgeschlagen');
        }
        
        // Relativer Pfad für Datenbank
        $relativePath = 'uploads/' . $filename;
        
        echo json_encode([
            'success' => true,
            'path' => $relativePath
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

?>