// ============================================
// AUTOMATISCHE API-PFAD ERKENNUNG
// ============================================

function getApiBase() {
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
