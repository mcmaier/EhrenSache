const redirectKey = 'auth_redirect_count';
const redirectTime = 'auth_last_redirect';

function checkRedirectLoop() {
    const now = Date.now();
    const lastRedirect = parseInt(sessionStorage.getItem(redirectTime) || '0');
    const timeDiff = now - lastRedirect;
    
    // Reset nach 3 Sekunden
    if (timeDiff > 3000) {
        sessionStorage.setItem(redirectKey, '0');
    }
    
    let count = parseInt(sessionStorage.getItem(redirectKey) || '0');
    
    if (count > 5) {
        document.body.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: center; 
                        height: 100vh; flex-direction: column; font-family: system-ui;">
                <h1>⚠️ Redirect-Loop erkannt</h1>
                <p>Es gab ein Problem mit der Authentifizierung.</p>
                <button onclick="sessionStorage.clear(); location.reload()" 
                        style="margin-top: 20px; padding: 10px 20px; cursor: pointer;">
                    Session zurücksetzen und neu laden
                </button>
            </div>
        `;
        throw new Error('Redirect loop detected');
    }
    
    sessionStorage.setItem(redirectKey, (count + 1).toString());
    sessionStorage.setItem(redirectTime, now.toString());
}

// Vor dem Router aufrufen
checkRedirectLoop();

import { loadAllData, showDashboard, initNavigation, initAllYearFilters, createMobileMenuButton, initModalEscHandler, initPWAQuickAccess, initEventHandlers} from './modules/ui.js';
import { apiCall, setCurrentUser, setCsrfToken, setInitialLoad, } from './modules/api.js';
import { initAuth } from './modules/auth.js';
import { applyTheme, renderSystemSettings } from './modules/settings.js';

// ============================================
// INIT JS
// ============================================

const DEBUG = false;

export const debug = {
  log:  (...args) => DEBUG && console.log(...args),
  warn: (...args) => DEBUG && console.warn(...args),
  error:(...args) => DEBUG && console.error(...args)
};

async function checkAuth() {
    try {
        const response = await fetch('api/api.php?resource=me', {
            credentials: 'include'
        });
        
        if (!response.ok) {
            // Nicht eingeloggt → Redirect
            debug.warn('Not authenticated, redirecting to login...');
            window.location.href = 'login.html';
            return false;
        }
        
        const userData = await response.json();
        
        // CSRF-Token aus sessionStorage laden (falls vorhanden)
        const csrfToken = sessionStorage.getItem('csrf_token');
        if (csrfToken) {
            setCsrfToken(csrfToken);
            debug.log('CSRF token loaded from session');
        }
        else {
            debug.warn('No CSRF token in sessionStorage');
        }
        
        // User-Daten setzen
        setCurrentUser(userData);
        
        return true;
        
    } catch (error) {
        console.error('Auth check failed:', error);
        window.location.href = 'login.html';
        return false;
    }
}

async function init() {

    debug.log('Initializing dashboard...');

    // Auth-Check
    const isAuthenticated = await checkAuth();
    if (!isAuthenticated) {
        debug.error('Authentication failed, stopping initialization');
        return; // Stop hier, Redirect läuft bereits
    }

    initAuth();
    initNavigation();
    initModalEscHandler();
    initPWAQuickAccess();    

    // Check Session beim Laden
    //const userData = await apiCall('me');

    // Nach initialem Check: Flag zurücksetzen
    setInitialLoad(false);
    // Erstelle Button (nachdem Status klar ist)
    createMobileMenuButton();
    
    //if (userData && userData.user_id) {
    //    setCurrentUser(userData);
        
        /*
        // CSRF-Token holen
        const loginData = await apiCall('login', 'POST', { 
            email: userData.email, 
            password: '' 
        });
        if (loginData?.csrf_token) {
            setCsrfToken(loginData.csrf_token);
        }    */                                   
        
        //Event Handler Registrieren
        initEventHandlers();
        
        await initAllYearFilters();

        showDashboard();        
        loadAllData();
        
        debug.log('Dashboard initialized successfully');
    //} 
}

// Start
window.addEventListener('load', init);
