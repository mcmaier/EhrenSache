/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

async function loadTheme() {    

    try {
        // Direkte fetch-Anfrage OHNE api.js (kein Session-Check!)
        const response = await fetch('api/api.php?resource=appearance', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json'
            }
        });

        if (!response.ok) {
            throw new Error('Theme konnte nicht geladen werden');
        }
        
        const data = await response.json();
        const settings = data.settings;

        applyTheme(settings); 

        // Settings im SessionStorage cachen (60 Minuten)
        sessionStorage.setItem('theme-settings', JSON.stringify(settings));
        sessionStorage.setItem('theme-loaded', Date.now());        
        
    } catch (error) {
        console.error('Theme-Laden fehlgeschlagen:', error);
    }
}


async function applyTheme(settings) {
    const root = document.documentElement;
    
    // CSS-Variablen
    if (settings.primary_color) {
        root.style.setProperty('--primary-color', settings.primary_color);
    }
    if (settings.secondary_color) {
        root.style.setProperty('--secondary-color', settings.secondary_color);
    }        
    if (settings.background_color) {
        root.style.setProperty('--background-color', settings.background_color);
    }
    
    // Organisations-Name
    if (settings.organization_name) {
        document.title = settings.organization_name;
        document.querySelectorAll('.org-name').forEach(el => {
            el.textContent = settings.organization_name;
        });
    }
    
    if(settings.privacy_policy_url)
    {
        const privacyGroup = document.getElementById('privacyPolicyGroup');
        const privacyLink = document.getElementById('privacyPolicyLink');

        try
        {            
            privacyLink.href = settings.privacy_policy_url;
            privacyGroup.style.display = 'block';
        }
        catch(error)
        {}
    }

    // Logo anzeigen
    const logoSelectors = '.org-logo, .login-logo';
    if (settings.organization_logo) {
        document.querySelectorAll(logoSelectors).forEach(img => {
            img.src = settings.organization_logo;
            img.style.display = 'block';
        });
    } else {
        // Fallback auf Standard-Logo
        document.querySelectorAll(logoSelectors).forEach(img => {
            img.src = 'assets/logo-default.png';
            img.style.display = 'block';
        });
    }
}


// Prüfen ob Cache aktuell ist (< 60 Min alt)
function isThemeCacheValid() {

    return false;

    const loadTime = sessionStorage.getItem('theme-loaded');
    if (!loadTime) return false;
    
    const age = Date.now() - parseInt(loadTime);
    return age < 60 * 60 * 1000; // 60 Minuten
}

// Theme initialisieren
if (isThemeCacheValid()) {
    // Aus Cache laden (sofort)
    const settings = JSON.parse(sessionStorage.getItem('theme-settings'));
    applyTheme(settings);
} else {
    // Frisch von API laden
    await loadTheme();
}

export { loadTheme, applyTheme }