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

/** Vorgabefarben, falls nichts hinterlegt oder der Wert keine Farbe ist. */
const BRANDING_PRIMARY_DEFAULT   = '#1F5FBF';
const BRANDING_SECONDARY_DEFAULT = '#4CAF50';

/**
 * Gueltige CSS-Hexfarbe oder Vorgabewert.
 *
 * Die Farben stehen als Freitext in system_settings; die Spalte setting_value
 * ist TEXT ohne Format- oder Laengengrenze. getBrandingCSS() setzt sie in einen
 * <style>-Block, und dort traegt Maskierung nicht: Der Inhalt eines
 * style-Elements ist roher Text, Entities werden nicht aufgeloest. Ein Wert darf
 * also gar nicht erst Zeichen enthalten, die in CSS etwas bedeuten. Eine Farbe
 * ist kein Freitext -- deshalb steht hier eine Formatpruefung, keine Maskierung.
 *
 * Gegenstueck zu safeTypeColor() in public/js/modules/utils.js und mit
 * demselben Ausdruck: Nur eine Hexnotation mit 3, 4, 6 oder 8 Stellen besteht.
 * Laengen wie 5 oder 7 sind kein Risiko, ergeben aber ungueltiges CSS, das der
 * Browser wortlos verwirft -- die Seite stuende dann ohne Farbe da statt mit
 * der Vorgabe.
 *
 * Bewusst Rueckfall statt Abweisung: Die Farbe ist Schmuck. Die beiden Seiten,
 * die sie verwenden, sind unangemeldet erreichbar und erledigen etwas, das
 * gelingen muss -- Passwort zuruecksetzen und E-Mail bestaetigen. Ein
 * hinterlegter Unsinnswert darf sie nicht blockieren.
 */
function brandingColor($value, string $fallback): string
{
    $color = is_string($value) ? trim($value) : '';

    return preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color) === 1
        ? $color
        : $fallback;
}

/** Maskiert einen Wert fuer die HTML-Ausgabe, einschliesslich beider Anfuehrungen. */
function brandingEscape($value): string
{
    // ENT_QUOTES ausdruecklich: Bis PHP 8.0 ist ENT_COMPAT die Vorgabe, dort
    // bleibt ' stehen. Die Ausgaben hier stecken in einfachen Anfuehrungen.
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Lade Branding-Einstellungen aus der Datenbank
 */
function getBrandingSettings($pdo, $database) {
    try {

        $prefix = $database->table('');

        $stmt = $pdo->prepare("
            SELECT setting_key, setting_value 
            FROM {$prefix}system_settings 
            WHERE setting_key IN ('organization_name', 'organization_logo', 'primary_color', 'secondary_color')
        ");
        $stmt->execute();
        
        $settings = [
            'organization_name' => 'EhrenSache',
            'organization_logo' => 'assets/logo-default.png',
            'primary_color' => BRANDING_PRIMARY_DEFAULT,
            'secondary_color' => BRANDING_SECONDARY_DEFAULT
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
            'primary_color' => BRANDING_PRIMARY_DEFAULT,
            'secondary_color' => BRANDING_SECONDARY_DEFAULT
        ];
    }
}

/**
 * Generiere CSS mit Branding-Farben
 */
function getBrandingCSS($settings) {
    // Jede Farbe laeuft durch brandingColor(), bevor sie in den Block kommt.
    $primary   = brandingColor($settings['primary_color'] ?? null, BRANDING_PRIMARY_DEFAULT);
    $secondary = brandingColor($settings['secondary_color'] ?? null, BRANDING_SECONDARY_DEFAULT);

    return "
        body {
            background: linear-gradient(135deg, {$primary} 0%, {$secondary} 100%);
        }
        button, .button {
            background: {$primary};
        }
        button:hover, .button:hover {
            filter: brightness(0.9);
        }
        .info-box {
            border-left-color: {$primary};
        }
        input[type=\"password\"]:focus {
            border-color: {$primary};
        }
    ";
}

/**
 * Generiere Logo-HTML
 */
function getBrandingLogo($settings) {
    // Verwende Standard-Logo wenn keins hochgeladen wurde
    $logoPath = !empty($settings['organization_logo'])
        ? brandingEscape($settings['organization_logo'])
        : 'assets/logo-default.png';
    $orgName = brandingEscape($settings['organization_name'] ?? '');
    
    return "
        <div style='text-align: center; margin-bottom: 20px;'>
            <img src='{$logoPath}' alt='{$orgName}' style='max-height: 60px; max-width: 200px; object-fit: contain;'>
        </div>
    ";
}

?>