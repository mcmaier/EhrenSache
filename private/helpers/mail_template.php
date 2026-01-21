<?php
class EmailTemplate {
    private static $templatesPath = __DIR__ . '/../email_templates/';
    
    public static function render($templateName, $variables = []) {
        // Base-Template laden
        $baseTemplate = file_get_contents(self::$templatesPath . 'base.html');
        
        // Content-Template laden
        $contentTemplate = file_get_contents(self::$templatesPath . $templateName . '.html');
        
        // Standard-Variablen
        $defaultVars = [
            'ORGANIZATION_NAME' => 'EhrenSache',
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