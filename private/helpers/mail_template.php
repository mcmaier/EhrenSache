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

require_once 'branding.php';

class EmailTemplate {
    private static $templatesPath = __DIR__ . '/../email_templates/';
    
    public static function render($templateName, $variables = [], $pdo = null) {
        // Branding laden (falls PDO übergeben)
        $branding = [];

        if ($pdo) {
            $branding = getBrandingSettings($pdo);
        }

        // Base-Template laden
        $baseTemplate = file_get_contents(self::$templatesPath . 'base.html');
        
        // Content-Template laden
        $contentTemplate = file_get_contents(self::$templatesPath . $templateName . '.html');
        
        // Standard-Variablen
        $defaultVars = [
            'ORGANIZATION_NAME' => $branding['organization_name'] ?? 'Mein Verein',            
            'ORGANIZATION_LOGO' => !empty($branding['organization_logo']) ? $branding['organization_logo'] : 'assets/logo-default.png',
            'PRIMARY_COLOR' => $branding['primary_color'] ?? '#1F5FBF',
            'SECONDARY_COLOR' => $branding['secondary_color'] ?? '#4CAF50',
            'CURRENT_YEAR' => date('Y')
        ];
        
        $variables = array_merge($defaultVars, $variables);
        
        // Content-Variablen ersetzen
        foreach ($variables as $key => $value) {
            $contentTemplate = str_replace('{{' . $key . '}}', htmlspecialchars($value), $contentTemplate);
        }
        
        // Content in Base einsetzen
        $html = str_replace('{{CONTENT}}', $contentTemplate, $baseTemplate);
        
        // Base-Variablen ersetzen
        foreach ($variables as $key => $value) {
            $html = str_replace('{{' . $key . '}}', htmlspecialchars($value), $html);
        }
        
        return $html;
    }
}