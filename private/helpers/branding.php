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
 * Lade Branding-Einstellungen aus der Datenbank
 */
function getBrandingSettings($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT setting_key, setting_value 
            FROM system_settings 
            WHERE setting_key IN ('organization_name', 'organization_logo', 'primary_color', 'secondary_color')
        ");
        $stmt->execute();
        
        $settings = [
            'organization_name' => 'Mein Verein',
            'organization_logo' => 'assets/logo-default.png',
            'primary_color' => '#1F5FBF',
            'secondary_color' => '#4CAF50'
        ];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['setting_value'])) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        
        return $settings;
        
    } catch (Exception $e) {
        // Fallback auf Standardwerte
        return [
            'organization_name' => 'Mein Verein',
            'organization_logo' => 'assets/logo-default.png',
            'primary_color' => '#1F5FBF',
            'secondary_color' => '#4CAF50'
        ];
    }
}

/**
 * Generiere CSS mit Branding-Farben
 */
function getBrandingCSS($settings) {
    return "
        body { 
            background: linear-gradient(135deg, {$settings['primary_color']} 0%, {$settings['secondary_color']} 100%);
        }
        button, .button {
            background: {$settings['primary_color']};
        }
        button:hover, .button:hover {
            filter: brightness(0.9);
        }
        .info-box {
            border-left-color: {$settings['primary_color']};
        }
        input[type=\"password\"]:focus {
            border-color: {$settings['primary_color']};
        }
    ";
}

/**
 * Generiere Logo-HTML
 */
function getBrandingLogo($settings) {
    // Verwende Standard-Logo wenn keins hochgeladen wurde
    $logoPath = !empty($settings['organization_logo']) 
        ? htmlspecialchars($settings['organization_logo'])
        : 'assets/logo-default.png';
    $orgName = htmlspecialchars($settings['organization_name']);
    
    return "
        <div style='text-align: center; margin-bottom: 20px;'>
            <img src='{$logoPath}' alt='{$orgName}' style='max-height: 60px; max-width: 200px; object-fit: contain;'>
        </div>
    ";
}

?>