import { loadAllData, showLogin, showDashboard, initNavigation, initAllYearFilters, createMobileMenuButton, initModalEscHandler, initPWAQuickAccess, initEventHandlers} from './modules/ui.js';
import { apiCall, setCurrentUser, setCsrfToken, setInitialLoad, currentUser, isAdmin} from './modules/api.js';
import { initAuth } from './modules/auth.js';

// ============================================
// INIT JS
// ============================================

const DEBUG = true;

export const debug = {
  log:  (...args) => DEBUG && console.log(...args),
  warn: (...args) => DEBUG && console.warn(...args),
  error:(...args) => DEBUG && console.error(...args)
};

async function init() {

    initAuth();
    initNavigation();
    initModalEscHandler();
    initPWAQuickAccess();    

    // Check Session beim Laden
    const userData = await apiCall('me');

    // Nach initialem Check: Flag zurücksetzen
    setInitialLoad(false);
    // Erstelle Button (nachdem Status klar ist)
    createMobileMenuButton();
    
    if (userData && userData.user_id) {
        setCurrentUser(userData);
        
        // CSRF-Token holen
        const loginData = await apiCall('login', 'POST', { 
            email: userData.email, 
            password: '' 
        });
        if (loginData?.csrf_token) {
            setCsrfToken(loginData.csrf_token);
        }                                       
        
        //Event Handler Registrieren
        initEventHandlers();
        
        await initAllYearFilters();

        showDashboard();        
        loadAllData();
        
    } else {
        showLogin();
    }   
}

// Start
window.addEventListener('load', init);
