import { loadAllData, showLogin, showDashboard, initNavigation, initAllYearFilters, createMobileMenuButton, initModalEscHandler, initPWAQuickAccess, currentYear, setCurrentYear, initDataCache, initEventHandlers} from './modules/ui.js';
import { apiCall, setCurrentUser, setCsrfToken, setInitialLoad, currentUser, isAdmin} from './modules/api.js';
import { initAuth } from './modules/auth.js';
import { loadMembers } from './modules/members.js';
import { loadAppointments } from './modules/appointments.js';
import { loadRecords, loadRecordFilters } from './modules/records.js';
import { loadExceptions } from './modules/exceptions.js';
import { loadUsers } from './modules/users.js';
import { loadSettings, initSettings} from './modules/settings.js';
import { loadGroups, loadTypes } from './modules/management.js';
import { initStatistics} from './modules/statistics.js';
import { exportMembers, exportAppointments, exportRecords } from './modules/import_export.js';

// ============================================
// INIT JS
// ============================================

async function init() {

    initAuth();
    initNavigation();
    initSettings();
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

        console.log("== CACHE INIT START ==");

        // Alle Caches befüllen
        await initDataCache();

        console.log("== CACHE INIT END ==");

        showDashboard();        
        await loadAllData();
        //await initAllYearFilters();
        //await initStatistics();  
    } else {
        showLogin();
    }   
}

// Start
window.addEventListener('load', init);
