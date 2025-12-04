import { loadAllData, showLogin, showDashboard, initNavigation, createMobileMenuButton} from './modules/ui.js';
import { apiCall, setCurrentUser, setCsrfToken, setInitialLoad, currentUser, isAdmin} from './modules/api.js';
import { initAuth } from './modules/auth.js';
import { loadMembers } from './modules/members.js';
import { loadAppointments } from './modules/appointments.js';
import { loadRecords, loadRecordFilters } from './modules/records.js';
import { loadExceptions } from './modules/exceptions.js';
import { loadUsers } from './modules/users.js';
import { loadSettings, initSettings} from './modules/settings.js';

// ============================================
// INIT JS
// ============================================

async function init() {
    initAuth();
    initNavigation();
    initSettings();

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
        
        showDashboard();
        loadAllData();
    } else {
        showLogin();
    }   
}

// Start
window.addEventListener('load', init);
