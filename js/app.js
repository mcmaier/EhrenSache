import { apiCall, setCurrentUser, setCsrfToken, setInitialLoad, currentUser, isAdmin} from './modules/api.js';
import { initAuth, showLogin } from './modules/auth.js';
import { showDashboard, initNavigation, createMobileMenuButton} from './modules/ui.js';
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

    // Erstelle Button (nachdem Status klar ist)
    createMobileMenuButton();
}

export async function loadAllData() {
    const section = sessionStorage.getItem('currentSection') || 'einstellungen';
    
    //Aktive Section zuerst laden
    if(section === 'einstellungen') {
        await loadSettings();
    }
    else if (section === 'mitglieder') {
        await loadMembers(true);
    } else if (section === 'termine') {
        await loadAppointments(true);
    } else if (section === 'anwesenheit') {
        await loadRecordFilters();
        await loadRecords();
    } else if (section === 'antraege') {
        await loadExceptions();
    } else if (section === 'benutzer' && isAdmin) {
        await loadUsers();
    }

        //Rest im Hintergrund laden (nicht-blockierend)
        setTimeout(() => {
            if (section !== 'mitglieder') loadMembers(true);
            if (section !== 'termine') loadAppointments(true);
            if (section !== 'anwesenheit') {
                loadRecordFilters();
                loadRecords();
            }
            if (section !== 'antraege') loadExceptions();
            if (isAdmin && section !== 'benutzer') loadUsers();
            if (section !== 'einstellungen') loadSettings();
        }, 100);
}

// Start
window.addEventListener('load', init);
