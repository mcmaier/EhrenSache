// ============================================
// AUTOMATISCHE API-PFAD ERKENNUNG
// ============================================

function getApiBase() {
    const origin = window.location.origin;
    const pathname = window.location.pathname;
    
    // Finde den Base-Path (bis /public/ oder Root)
    let basePath;
    
    if (pathname.includes('/public/')) {
        // Shared Hosting: /members/public/
        basePath = pathname.substring(0, pathname.indexOf('/public/') + 8);
    } else if (pathname.includes('/members/')) {
        // Lokal XAMPP: /members/
        basePath = pathname.substring(0, pathname.indexOf('/members/') + 9);
    } else {
        // Root-Installation
        basePath = '/';
    }
    
    // Stelle sicher dass Pfad mit / endet
    if (!basePath.endsWith('/')) {
        basePath += '/';
    }
    
    return origin + basePath + 'api/api.php';
    /*
    // Hole aktuellen Pfad
    let basePath = window.location.pathname;
    
    // Entferne Dateinamen (index.html, etc.)
    if (basePath.endsWith('.html')) {
        basePath = basePath.substring(0, basePath.lastIndexOf('/') + 1);
    }
    
    // Stelle sicher dass Pfad mit / endet
    if (!basePath.endsWith('/')) {
        basePath += '/';
    }
    
    // Füge api/api.php hinzu
    return basePath + 'api/api.php';
    */
}


// Konfiguration
export const API_BASE = getApiBase();
export const AUTO_CHECKIN_TOLERANCE_HOURS = 2;
export const TOAST_DURATION = 3000;
export const SESSION_TIMEOUT = 3600000;

export const config = {
    apiBase: API_BASE,
    autoCheckinTolerance: AUTO_CHECKIN_TOLERANCE_HOURS,
    toastDuration: TOAST_DURATION,
    sessionTimeout: SESSION_TIMEOUT
};
