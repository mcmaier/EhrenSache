/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { apiCall, setCurrentUser, currentUser, setCsrfToken} from './api.js';
import { loadAllData, showScreen, showToast, showDashboard, updateUIForRole, initAllYearFilters, initEventHandlers} from './ui.js';
import {debug} from '../app.js'

// ============================================
// AUTH
// Reference:
// import {} from './auth.js'
// ============================================

let apiFailureCount = 0;
const MAX_API_FAILURES = 3;

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
        setCsrfToken(null);
        
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
