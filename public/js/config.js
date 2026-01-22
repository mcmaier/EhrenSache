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
