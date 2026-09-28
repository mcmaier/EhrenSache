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
    
    /**
     * Setzt eine Vorlage zusammen.
     *
     * Maskiert wird ueber brandingEscape() statt htmlspecialchars() ohne
     * Optionen. Das ist Sorgfalt, keine Behebung: Mehrere Platzhalter landen in
     * einem Attributwert -- src="{{BASE_URL}}/{{ORGANIZATION_LOGO}}" in
     * base.html sowie href="{{LOGIN_LINK}}", href="{{RESET_LINK}}" und
     * href="{{VERIFICATION_LINK}}" in den Inhaltsvorlagen --, doch alle
     * Attribute der Vorlagen stehen in DOPPELTEN Anfuehrungen, und die " maskiert
     * schon ENT_COMPAT, die Vorgabe bis PHP 8.0. Ausbrechen konnte hier also
     * nichts. Drei Gruende gibt es trotzdem:
     *
     * 1. Eine Vorgabe, die zwischen PHP 8.0 und 8.1 wechselt, taugt nicht als
     *    Verlass -- version.json laesst 8.0 zu.
     * 2. Traegt eine Vorlage spaeter ein Attribut in einfachen Anfuehrungen,
     *    greift die Maskierung ohne ENT_QUOTES dort nicht.
     * 3. htmlspecialchars(null) ist seit PHP 8.1 verworfen; brandingEscape()
     *    wandelt vorher.
     *
     * Die Stelle mit Freitext dahinter ist der Logopfad: Er steht als
     * organization_logo in system_settings.
     */
    public static function render($templateName, $variables = [], $pdo = null, $database = null) {
        // Branding laden (falls PDO übergeben)
        $branding = [];

        if ($pdo) {
            $branding = getBrandingSettings($pdo, $database);
        }

        // Base-Template laden
        $baseTemplate = file_get_contents(self::$templatesPath . 'base.html');
        
        // Content-Template laden
        $contentTemplate = file_get_contents(self::$templatesPath . $templateName . '.html');
        
        // Standard-Variablen
        $defaultVars = [
            'ORGANIZATION_NAME' => $branding['organization_name'] ?? 'Mein Verein',
            'ORGANIZATION_LOGO' => !empty($branding['organization_logo']) ? $branding['organization_logo'] : 'assets/logo-default.png',
            'PRIMARY_COLOR' => $branding['primary_color'] ?? BRANDING_PRIMARY_DEFAULT,
            'SECONDARY_COLOR' => $branding['secondary_color'] ?? BRANDING_SECONDARY_DEFAULT,
            'CURRENT_YEAR' => date('Y')
        ];

        $variables = array_merge($defaultVars, $variables);

        // Die beiden Farben laufen durch brandingColor(), nicht durch die
        // Maskierung: In base.html stehen sie in einem <style>-Block, und dort
        // traegt Maskierung nicht -- der Inhalt eines style-Elements ist roher
        // Text, Entities loest der Parser nicht auf. Ein Elementausbruch ist
        // damit ausgeschlossen (das < wuerde zur Entity), eine CSS-Einschleusung
        // wie "#abc; background: url(...)" waere es nicht. Eine Farbe ist kein
        // Freitext, deshalb Formatpruefung statt Maskierung -- dieselbe Schranke
        // wie in getBrandingCSS().
        //
        // Nach dem Zusammenfuehren, nicht davor: So gilt die Pruefung auch fuer
        // eine Farbe, die ein Aufrufer in $variables mitgibt.
        $variables['PRIMARY_COLOR']   = brandingColor($variables['PRIMARY_COLOR'] ?? null, BRANDING_PRIMARY_DEFAULT);
        $variables['SECONDARY_COLOR'] = brandingColor($variables['SECONDARY_COLOR'] ?? null, BRANDING_SECONDARY_DEFAULT);

        // Content-Variablen ersetzen
        foreach ($variables as $key => $value) {
            $contentTemplate = str_replace('{{' . $key . '}}', brandingEscape($value), $contentTemplate);
        }

        // Content in Base einsetzen
        $html = str_replace('{{CONTENT}}', $contentTemplate, $baseTemplate);

        // Base-Variablen ersetzen
        foreach ($variables as $key => $value) {
            $html = str_replace('{{' . $key . '}}', brandingEscape($value), $html);
        }

        return $html;
    }
}