/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */


// theme.js - wird auf JEDER Seite geladen
import { apiCall } from './modules/api.js';

async function loadTheme() {
    try {
        const response = await apiCall('appearance','GET');
        const settings = response.settings;
        
        // CSS-Variablen setzen
        const root = document.documentElement;
        
        if (settings.primary_color) {
            root.style.setProperty('--primary-color', settings.primary_color);
        }
        if (settings.background_color) {
            root.style.setProperty('--background-color', settings.background_color);
        }
        if (settings.organization_name) {
            document.title = settings.organization_name;
            // Alle Elemente mit class="org-name" aktualisieren
            document.querySelectorAll('.org-name').forEach(el => {
                el.textContent = settings.organization_name;
            });
        }
        
        // Settings im SessionStorage cachen (60 Minuten)
        sessionStorage.setItem('theme-settings', JSON.stringify(settings));
        sessionStorage.setItem('theme-loaded', Date.now());
        
    } catch (error) {
        console.error('Theme-Laden fehlgeschlagen:', error);
    }
}

// Prüfen ob Cache aktuell ist (< 60 Min alt)
function isThemeCacheValid() {

    const loadTime = sessionStorage.getItem('theme-loaded');
    if (!loadTime) return false;
    
    const age = Date.now() - parseInt(loadTime);
    return age < 60 * 60 * 1000; // 60 Minuten
}

// Theme initialisieren
if (isThemeCacheValid()) {
    // Aus Cache laden (sofort)
    const settings = JSON.parse(sessionStorage.getItem('theme-settings'));
    const root = document.documentElement;
    if (settings.primary_color) root.style.setProperty('--primary-color', settings.primary_color);
    if (settings.background_color) root.style.setProperty('--background-color', settings.background_color);

    if (settings.organization_name) {
            document.title = settings.organization_name;
            // Alle Elemente mit class="org-name" aktualisieren
            document.querySelectorAll('.org-name').forEach(el => {
                el.textContent = settings.organization_name;
            });
        }
} else {
    // Frisch von API laden
    await loadTheme();
}

export { loadTheme };