/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { apiCall, setCsrfToken} from './api.js';
import { debug } from '../app.js'

// ============================================
// AUTH
// Reference:
// import {} from './auth.js'
// ============================================

let apiFailureCount = 0;
const MAX_API_FAILURES = 3;

let sessionTimeoutInterval = null;
let lastActivityTime = null;
const SESSION_DURATION = 30 * 60 * 1000; // 30 Minuten
const INACTIVITY_WARNING = 5 * 60 * 1000; // Warnung nach 25 Min Inaktivität


// In checkAuth() Funktion anpassen:
export async function checkAuth() {
    try {
        const response = await fetch('/api/auth.php?action=check_session');
        
        if (!response.ok) {
            apiFailureCount++;
            
            if (apiFailureCount >= MAX_API_FAILURES) {
                // API komplett down - Fehlerseite anzeigen
                document.body.innerHTML = `
                    <div style="display: flex; align-items: center; justify-content: center; 
                                height: 100vh; flex-direction: column; font-family: system-ui;">
                        <h1>? Verbindungsfehler</h1>
                        <p>Die API ist nicht erreichbar. Bitte sp�ter erneut versuchen.</p>
                        <button onclick="location.reload()" 
                                style="margin-top: 20px; padding: 10px 20px; cursor: pointer;">
                            Neu laden
                        </button>
                    </div>
                `;
                return null;
            }
            
            throw new Error('API nicht erreichbar');
        }
        
        const data = await response.json();
        
        // Bei Erfolg Counter zur�cksetzen
        apiFailureCount = 0;
        
        return data.authenticated ? data : null;
        
    } catch (error) {
        console.error('Auth check failed:', error);
        return null;
    }
}

// ============================================
// LOGOUT
// ============================================
export async function handleLogout() {
    try {
        await apiCall('logout', 'POST');
    } catch (error) {
        console.error('Logout error:', error);
    } finally {
        // Cleanup
        sessionStorage.removeItem('csrf_token');
        sessionStorage.removeItem('current_user');
        sessionStorage.removeItem('currentSection');
        sessionStorage.removeItem('currentNavTab');
        setCsrfToken(null);

        stopSessionTimeout(); 
        
        // Redirect zu Login
        window.location.href = 'login.html';
    }
}

// ============================================
// EVENT LISTENERS
// ============================================
export function initAuth() {
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }
}


// ============================================
// SESSION TIMEOUT
// ============================================

export function startSessionTimeout() {
    lastActivityTime = Date.now();
    
    if (sessionTimeoutInterval) {
        clearInterval(sessionTimeoutInterval);
    }
    
    sessionTimeoutInterval = setInterval(updateSessionTimeout, 1000);
    
    // Activity-Listener registrieren
    //setupActivityTracking();
    
    updateSessionTimeout();
}

function setupActivityTracking() {
    // Bei jeder Interaktion Timer zurücksetzen
    /*
    const events = ['mousedown', 'keydown', 'scroll', 'touchstart'];
    
    events.forEach(event => {
        document.addEventListener(event, handleUserActivity, { passive: true });
    });
    */
}

function handleUserActivity() {
    lastActivityTime = Date.now();
}

export function resetSessionTimeout() {
    lastActivityTime = Date.now();
    updateSessionTimeout();
}

function updateSessionTimeout() {
    const inactiveTime = Date.now() - lastActivityTime;
    const remaining = SESSION_DURATION - inactiveTime;
    
    if (remaining <= 0) {
        clearInterval(sessionTimeoutInterval);
        showToast('Sitzung wegen Inaktivität abgelaufen', 'error');
        handleLogout();
        return;
    }
    
    const minutes = Math.floor(remaining / 60000);
    const seconds = Math.floor((remaining % 60000) / 1000);
    const timeText = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    
    const timeoutElement = document.getElementById('sessionTimeout');
    const timeoutText = document.getElementById('timeoutText');
    
    if (timeoutText) {
        timeoutText.textContent = timeText;
    }
    
    if (timeoutElement) {
        // Warnung bei < 5 Minuten verbleibend
        if (remaining < INACTIVITY_WARNING) {
            timeoutElement.classList.add('warning');
            timeoutElement.classList.remove('critical');
            
            // Einmalige Warnung bei 5 Minuten
            if (remaining < INACTIVITY_WARNING && remaining > INACTIVITY_WARNING - 1000) {
                showToast('Sitzung läuft in 5 Minuten ab', 'warning');
            }
        }
        
        // Kritisch bei < 2 Minuten
        if (remaining < 2 * 60 * 1000) {
            timeoutElement.classList.add('critical');
            timeoutElement.classList.remove('warning');
        }
        
        // Normal
        if (remaining >= INACTIVITY_WARNING) {
            timeoutElement.classList.remove('warning', 'critical');
        }
    }
}

export function stopSessionTimeout() {
    if (sessionTimeoutInterval) {
        clearInterval(sessionTimeoutInterval);
        sessionTimeoutInterval = null;
    }
    
    // Activity-Listener entfernen
    const events = ['mousedown', 'keydown', 'scroll', 'touchstart'];
    events.forEach(event => {
        document.removeEventListener(event, handleUserActivity);
    });
}