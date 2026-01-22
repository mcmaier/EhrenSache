/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

const DEBUG = false;

const debug = {
  log:  (...args) => DEBUG && console.log(...args),
  warn: (...args) => DEBUG && console.warn(...args),
  error:(...args) => DEBUG && console.error(...args)
};

// ========================================
// KONFIGURATION
// ========================================
// Ermittle automatisch den korrekten Basis-Pfad
const API_BASE = (() => {
    const origin = window.location.origin;
    const pathname = window.location.pathname;
    
    // Extrahiere Basis-Pfad (alles vor /checkin/)
    // z.B. /ehrenzeit/checkin/ → /ehrenzeit/
    const match = pathname.match(/^(.*?)\/checkin\//);
    const basePath = match ? match[1] : '';
    
    debug.log('PWA API Base:', basePath);

    return `${origin}${basePath}/api/api.php`;    
})();

// ========================================
// STATE MANAGEMENT
// ========================================

const UI_STATE = {
    IDLE: 'idle',           // Ruhezustand
    QR_SCANNING: 'qr',      // QR-Scanner aktiv
    NFC_SCANNING: 'nfc'     // NFC-Scanner aktiv
};


let appearanceSettings = {
    organization_name: 'EhrenSache',
    primary_color: '#1F5FBF',
    background_color: '#f8f9fa'
};

let currentUIState = UI_STATE.IDLE;
let apiToken = null;
let userData = null;
let html5QrCode = null;
let isScanning = false;
let isNFCScanning = false;
let clockInterval = null;
let appointments = [];
let appointmentTypes = [];
let deleteExceptionId = null;
let nfcAbortController = null;
let nfcAvailable = false;
let currentStatsYear = new Date().getFullYear();
let currentEditAppointmentId = null;

// In checkin/index.html oder checkin/app.js
    if ('serviceWorker' in navigator) {
        //const currentPath = window.location.pathname;
        //const basePath = currentPath.substring(0, currentPath.lastIndexOf('/') + 1);

        navigator.serviceWorker.register('./checkin/service-worker.js', {
            scope: './checkin/'
        })
        .then(reg => debug.log('✓ Service Worker registriert:', reg.scope))
        .catch(err => debug.log('✗ Service Worker Fehler:', err));        
    }

// ========================================
// DOM ELEMENTS (werden nach DOMContentLoaded gesetzt)
// ========================================
const screens = {
    login: document.getElementById('loginScreen'),
    main: document.getElementById('mainScreen')
};

let elements = {};

// ========================================
// INITIALIZATION
// ========================================
document.addEventListener('DOMContentLoaded', function() {

    loadAppearanceSettings();

    // DOM Elements cachen
    elements = {
        emailInput: document.getElementById('emailInput'),
        passwordInput: document.getElementById('passwordInput'),
        saveLoginCheckbox: document.getElementById('saveLoginCheckbox'),
        //showTokenLoginButton: document.getElementById('showTokenLoginButton'),
        //tokenLoginSection: document.getElementById('tokenLoginSection'),
        //apiTokenInput: document.getElementById('apiTokenInput'),
        //tokenLoginButton: document.getElementById('tokenLoginButton'),
        loginScreen: document.getElementById('loginScreen'),
        mainScreen: document.getElementById('mainScreen'),
        loginForm: document.getElementById('loginForm'),
        loginError: document.getElementById('loginError'),
        logoutBtn: document.getElementById('logoutBtn'),
        userName: document.getElementById('userName'),
        userRole: document.getElementById('userRole'),
        currentDate: document.getElementById('currentDate'),
        currentTime: document.getElementById('currentTime'),
        scanButton: document.getElementById('scanButton'),
        statusMessage: document.getElementById('statusMessage'),
        qrReader: document.getElementById('qr-reader'),
        history: document.getElementById('history'),
        historyList: document.getElementById('historyList'),
        offlineIndicator: document.getElementById('offlineIndicator'),
        manualCodeBtn: document.getElementById('manualCodeBtn'),
        exceptionBtn: document.getElementById('exceptionBtn'),
        manualCodeModal: document.getElementById('manualCodeModal'),
        closeManualCodeBtn: document.getElementById('closeManualCodeBtn'),
        submitManualCodeBtn: document.getElementById('submitManualCodeBtn'),
        manualCode: document.getElementById('manualCode'),
        exceptionModal: document.getElementById('exceptionModal'),        
        closeExceptionBtn: document.getElementById('closeExceptionBtn'),
        submitExceptionBtn: document.getElementById('submitExceptionBtn'),
        exceptionAppointment: document.getElementById('exceptionAppointment'),
        exceptionReason: document.getElementById('exceptionReason'),
        confirmDeleteModal: document.getElementById('confirmDeleteModal'),
        closeConfirmDeleteBtn: document.getElementById('closeConfirmDeleteBtn'),
        submitConfirmDeleteBtn: document.getElementById('submitConfirmDeleteBtn'),
        stopScanButton: document.getElementById('stopScanButton'),        
        nfcButton: document.getElementById('nfcButton'),
        toDashboardBtn: document.getElementById('toDashboardBtn'),
        checkinDivider: document.getElementById('checkinDivider'),
        nfcScannerContainer: document.getElementById('nfcScannerContainer'),
        scannerContainer: document.getElementById('scannerContainer')
    };

    // Event Listeners
    elements.loginForm.addEventListener('submit', handleLogin);
    //elements.showTokenLoginButton.addEventListener('click', toggleTokenLogin);
    //elements.tokenLoginButton.addEventListener('click', handleTokenLogin);
    elements.logoutBtn.addEventListener('click', requestLogout);
    elements.scanButton.addEventListener('click', toggleScanner);
    elements.manualCodeBtn.addEventListener('click', openManualCodeInput);
    elements.exceptionBtn.addEventListener('click', openExceptionModal);
    elements.closeManualCodeBtn.addEventListener('click', closeManualCodeModal);
    elements.submitManualCodeBtn.addEventListener('click', submitManualCode);
    elements.closeExceptionBtn.addEventListener('click', closeExceptionModal);
    elements.submitExceptionBtn.addEventListener('click', submitException);  
    elements.closeConfirmDeleteBtn.addEventListener('click', closeConfirmDeleteModal);
    elements.submitConfirmDeleteBtn.addEventListener('click', submitConfirmDelete);   
    elements.stopScanButton.addEventListener('click', toggleScanner);      
    elements.nfcButton.addEventListener('click', toggleNFCReader);        
    toDashboardBtn.addEventListener('click', handleDashboardNavigation);


    // Enter-Taste im Code-Input
    elements.manualCode.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            submitManualCode();
        }
    });

    checkNFCSupport();

    // Auto-Login prüfen
    checkAutoLogin();

    // Online/Offline Detection
    window.addEventListener('online', () => debug.log('Online'));
    window.addEventListener('offline', showOfflineIndicator);
});

// ========================================
// API HELPER
// ========================================
async function apiCall(resource, method = 'GET', data = null, params = {}) {
   
    const url = new URL(API_BASE);

    url.searchParams.append('resource', resource);   
    
    for (const [key, value] of Object.entries(params)) {
        url.searchParams.append(key, value);
    }

    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${apiToken}`,
            'X-API-Key': apiToken
        },
        credentials: 'omit'
    };

    if (data && (method === 'POST' || method === 'PUT')) {
        options.body = JSON.stringify(data);
    }

    try {
        debug.log('API Call:', method, url.toString());
        const response = await fetch(url, options);

        // Parse Response Body
        let responseData = null;

        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            responseData = await response.json();
        }

        // Erfolgreiche Antwort
        if (response.ok) {
            return {
                success: true,
                status: response.status,
                data: responseData
            };
        }

        // Fehlerhafte Antwort
        let errorMessage = responseData?.message || `HTTP ${response.status}`;

        // Spezifische Fehlermeldungen
        switch (response.status) {
            case 401:
                errorMessage = 'Anmeldedaten ungültig';
                break;
            case 403:
                errorMessage = 'Keine Berechtigung für diese Aktion';
                break;
            case 404:
                errorMessage = 'Ressource nicht gefunden';
                break;
            case 409:
                errorMessage = responseData?.message || 'Konflikt - Eintrag existiert bereits';
                break;
            case 422:
                errorMessage = responseData?.message || 'Ungültige Eingabedaten';
                break;
            case 429:
                errorMessage = 'Zu viele Anfragen - bitte warten';
                break;
            case 500:
                errorMessage = 'Serverfehler - bitte später erneut versuchen';
                break;
        }

         return {
            success: false,
            status: response.status,
            error: errorMessage,
            data: responseData
        };
    } catch (error) {
        debug.error('API Error:', error);
        showOfflineIndicator();

         return {
            success: false,
            status: 0,
            error: 'Verbindungsfehler',
            data: null
        };
    }
}

// ========================================
// APPEARANCE
// ========================================

async function loadAppearanceSettings() {
    try {
        const url = new URL(API_BASE);
        url.searchParams.append('resource', 'appearance');
        
        const response = await fetch(url);
        if (response.ok) {
            const data = await response.json();
            appearanceSettings = data.settings;
            applyAppearanceSettings();
        }
    } catch (error) {
        debug.error('Appearance Settings laden fehlgeschlagen:', error);
        // Fallback auf Standardwerte
        applyAppearanceSettings();
    }
}

function applyAppearanceSettings() {
    // Titel setzen
    document.title = appearanceSettings.organization_name || 'EhrenSache';
    
    // Alle Elemente mit class="org-name" aktualisieren
    document.querySelectorAll('.org-name').forEach(el => {
        el.textContent = appearanceSettings.organization_name || 'EhrenSache';
    });
    
    // Logo anzeigen
    if (appearanceSettings.organization_logo) {
        // Pfad relativ zur PWA anpassen (ein Verzeichnis höher)
        const logoPath = `../${appearanceSettings.organization_logo}`;
        
        document.querySelectorAll('.pwa-org-logo').forEach(img => {
            img.src = logoPath;
            img.style.display = 'block';                        
        });
    } else {
        // Kein Logo -> Fallback auf Standard oder Emoji
        document.querySelectorAll('.pwa-org-logo').forEach(img => {
            img.src = '../assets/logo-default.png';
            img.style.display = 'block';
        });
    }

    // CSS-Variablen setzen
    if (appearanceSettings.primary_color) {
        document.documentElement.style.setProperty('--primary-color', appearanceSettings.primary_color);
    }
    if (appearanceSettings.secondary_color) {
        document.documentElement.style.setProperty('--secondary-color', appearanceSettings.secondary_color);
    }
    if (appearanceSettings.background_color) {
        document.documentElement.style.setProperty('--background-color', appearanceSettings.background_color);
    }
}

// ========================================
// AUTHENTICATION
// ========================================
async function handleLogin(e) {
    e.preventDefault();
    
    const email = elements.emailInput.value.trim();
    const password = elements.passwordInput.value;
    
    if (!email || !password) {
        showError('Bitte E-Mail und Passwort eingeben');
        return;
    }
    
    elements.loginError.classList.remove('active');
    
    try {
        // Login via API
        const result = await apiCall('auth','POST',{email: email, password: password});

        debug.log("Login response:", result);

        if (!result.success) {
            const error = result;
            throw new Error(error.message || 'Login fehlgeschlagen');
        }    
        
        // Speichere Token
        apiToken = result.data.token;
        
        if (elements.saveLoginCheckbox.checked) {
            // Speichere Token (Base64 kodiert)
            localStorage.setItem('api_token', btoa(apiToken));
        }
        
        debug.log('✓ Login erfolgreich');
        
        // Lade Daten
        await loadAppointmentTypes();
        await loadUserData();        
        //await loadHistory();
        await initTabs();
        await initYearNavigation();    
        // Anwesenheitsliste initialisieren
        //await initAttendanceList();

        debug.log("Showing main screen");        
        showScreen('main');
        startClock();
        
    } catch (error) {
        debug.error('Login Fehler:', error);
        showError(error.message || 'Ungültige Anmeldedaten');
    }

}


// ========================================
// TOKEN LOGIN (FALLBACK)
// ========================================
/*
async function handleTokenLogin() {
    const token = elements.apiTokenInput.value.trim();
    
    if (!token) {
        showError('Bitte Token eingeben');
        return;
    }
    
    elements.loginError.classList.remove('active');
    
    try {
        apiToken = token;
        
        // Test API-Verbindung
        await apiCall('me');
        debug.log('✓ Token-Login erfolgreich');
        
        // Speichere Token
        localStorage.setItem('api_token', btoa(token));
        
        // Lade Daten
        await loadAppointmentTypes();
        await loadUserData();
        //await loadHistory();
        await initTabs();
        await initYearNavigation();
        // Anwesenheitsliste initialisieren
        //await initAttendanceList();
        
        debug.log("Showing main screen");        
        showScreen('main');
        startClock();
        
    } catch (error) {
        debug.error('Token-Login Fehler:', error);
        showError('Ungültiger Token');
        apiToken = null;
    }
}
*/

// ========================================
// AUTO LOGIN
// ========================================
async function checkAutoLogin() {
    const savedToken = localStorage.getItem('api_token');
    
    if (savedToken) {
        try {
            apiToken = atob(savedToken);
        
            // Teste Token
            const result = await apiCall('me');

            if (!result.success) {
                const error = result;
                throw new Error(error.message || 'Login fehlgeschlagen');
            }  

            debug.log('✓ Auto-Login erfolgreich');
                
            // Lade Daten
            await loadAppointmentTypes();
            await loadUserData();
            //await loadHistory();
            await initTabs();
            await initYearNavigation();      
            // Anwesenheitsliste initialisieren
            //await initAttendanceList();  

            debug.log("Showing main screen");
                
            showScreen('main');
            startClock();
            
        } catch (error) {
            debug.log('Auto-Login fehlgeschlagen:', error);
            // Token ungültig - zeige Login
            localStorage.removeItem('api_token');
            apiToken = null;
        }
    }
}

//=========================================
// ERROR DISPLAY
// ========================================
function showError(message) {
    elements.loginError.textContent = message;
    elements.loginError.classList.add('active');
}

// ========================================
// TOGGLE TOKEN LOGIN
// ========================================
/*function toggleTokenLogin() {
    const section = elements.tokenLoginSection;
    if (section.style.display === 'none') {
        section.style.display = 'block';
        elements.showTokenLoginButton.textContent = 'Mit E-Mail anmelden';
    } else {
        section.style.display = 'none';
        elements.showTokenLoginButton.textContent = 'Mit Token anmelden';
    }
}*/

async function handleLogout() {
    await stopScannerIfRunning();
    await stopNFCReader();

    localStorage.removeItem('api_token');
    apiToken = null;
    userData = null;
    
    elements.loginForm.reset();
    elements.emailInput.value = '';
    elements.passwordInput.value = '';
    elements.loginError.classList.remove('active');

    /*
    // Reset Token-Login (falls sichtbar)
    if (elements.tokenLoginSection) {
        elements.tokenLoginSection.style.display = 'none';
        elements.showTokenLoginButton.textContent = 'Mit Token anmelden';
    }*/
    
    showScreen('login');
    stopClock();

    debug.log('✓ Abgemeldet');
}

async function loadUserData() {
let success = false;

    try {
        const result = await apiCall('me');
        if (!result.success) {
            throw new Error(result.error);
        }

        const meData = result.data;
        userData = meData;
        
        if (meData.member_id) {
            try {
                const result = await apiCall('members', 'GET', null, { id: meData.member_id });

                if (!result.success) {
                            throw new Error(result.error);
                        }
                
                const member = result.data;
                
                if (member) {
                    elements.userName.textContent = `${member.name} ${member.surname}`;
                    
                    // Rollentext mit Manager-Unterstützung
                    let roleText = 'Mitglied';
                    if (meData.role === 'admin') roleText = 'Administrator';
                    else if (meData.role === 'manager') roleText = 'Manager';
                    
                    if (member.member_number) {
                        elements.userRole.textContent = `${roleText} • Nr. ${member.member_number}`;
                    } else {
                        elements.userRole.textContent = roleText;
                    }
                    success = true;
                    
                    // Anwesenheitsliste initialisieren wenn Admin/Manager
                    await initAttendanceList();
                    
                    return success;
                }
            } catch (error) {
                debug.log('Member-Daten konnten nicht geladen werden:', error);
            }
        }
        else
        {
            showMessage('Kein Mitglied mit diesem Benutzer verknüpft. Bitte Administrator kontaktieren.', 'error');
            return false;
        }
        
        // Fallback
        if (meData.email && meData.email !== 'token-auth') {
            elements.userName.textContent = meData.email;
        } else if (meData.user_id) {
            elements.userName.textContent = `User #${meData.user_id}`;
        } else {
            elements.userName.textContent = 'Benutzer';
        }
        
        // Rollentext mit Manager-Unterstützung
        let roleText = 'Mitglied';
        if (meData.role === 'admin') roleText = 'Administrator';
        else if (meData.role === 'manager') roleText = 'Manager';
        elements.userRole.textContent = roleText;

        success = true;
        
    } catch (error) {
        debug.log('User-Daten konnten nicht geladen werden:', error);
        elements.userName.textContent = 'Benutzer';
        elements.userRole.textContent = 'Mitglied';
    }

    return success;
}

// ========================================
// ATTENDANCE LIST (Admin/Manager)
// ========================================

async function initAttendanceList() {

    // Prüfe ob Benutzer Admin oder Manager ist
    if (!userData || (userData.role !== 'admin' && userData.role !== 'manager')) {
        // Tab ausblenden falls vorhanden
        const tab = document.querySelector('[data-tab="attendance-list"]');
        if (tab) tab.style.display = 'none';
        return;
    }

    const tab = document.querySelector('[data-tab="attendance-list"]');    
    if (tab) {
        tab.style.display = 'flex';      
    }
    
    // Filter Listener
    const filterSelect = document.getElementById('attendanceAppointmentFilter');
    if (filterSelect && !filterSelect.dataset.listenerAdded) {
        filterSelect.addEventListener('change', loadAttendanceList);
        filterSelect.dataset.listenerAdded = 'true';
    }

    // Refresh Button
    const btnRefresh = document.getElementById('btnRefreshAttendance');
    if (btnRefresh && !btnRefresh.dataset.listenerAdded) {
        btnRefresh.addEventListener('click', async () => {
            await refreshAttendanceList();
        });
        btnRefresh.dataset.listenerAdded = 'true';
    }
    
    // Create Appointment Button
    const btnCreate = document.getElementById('btnCreateAppointment');
    if (btnCreate && !btnCreate.dataset.listenerAdded) {
        btnCreate.addEventListener('click', showCreateAppointmentModal);
        btnCreate.dataset.listenerAdded = 'true';
    }
    
    // Edit Appointment Button
    const btnEdit = document.getElementById('btnEditAppointment');
    if (btnEdit && !btnEdit.dataset.listenerAdded) {
        btnEdit.addEventListener('click', showEditAppointmentModal);
        btnEdit.dataset.listenerAdded = 'true';
    }

     // Modal Cancel Button
    const btnCancelAppointment = document.getElementById('btnCancelAppointment');
    if (btnCancelAppointment && !btnCancelAppointment.dataset.listenerAdded) {
        btnCancelAppointment.addEventListener('click', () => {
            document.getElementById('appointmentModal').classList.remove('active');
        });
        btnCancelAppointment.dataset.listenerAdded = 'true';
    }
    
    // Modal Click Outside
    const appointmentModal = document.getElementById('appointmentModal');
    if (appointmentModal && !appointmentModal.dataset.listenerAdded) {
        appointmentModal.addEventListener('click', (e) => {
            if (e.target.id === 'appointmentModal') {
                appointmentModal.classList.remove('active');
            }
        });
        appointmentModal.dataset.listenerAdded = 'true';
    }
    
    // Appointment Form Submit
    const appointmentForm = document.getElementById('appointmentForm');
    if (appointmentForm && !appointmentForm.dataset.listenerAdded) {
        appointmentForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = {
                title: document.getElementById('appointmentTitle').value,
                date: document.getElementById('appointmentDate').value,
                start_time: document.getElementById('appointmentTime').value,
                type_id: document.getElementById('appointmentType').value,                
            };
            
            try {
                if (currentEditAppointmentId) {
                    // Update                    
                    const result = await apiCall('appointments', 'PUT', formData, { id: currentEditAppointmentId });
                    if(result.success) {showMessage('Termin aktualisiert', 'success');}
                    else{showMessage(result.error,'error');}
                } else {
                    // Create
                    const result = await apiCall('appointments', 'POST', formData);
                    if(result.success) {showMessage('Termin erstellt', 'success');}
                    else{showMessage(result.error,'error');}
                }
                
                // Modal schließen
                document.getElementById('appointmentModal').classList.remove('active');
                
                // Liste aktualisieren
                await loadAttendanceAppointments();
                if(currentEditAppointmentId)
                {
                    refreshAttendanceList();
                }
                
            } catch (error) {
                debug.log('Fehler beim Speichern:', error);
                showMessage('Fehler beim Speichern', 'error');
            }
        });
        appointmentForm.dataset.listenerAdded = 'true';
    }
}

async function loadAttendanceAppointments() {
    try {
        // Hole Termine der nächsten 7 Tage (inkl. heute)
        const today = new Date();
        const nextWeek = new Date(today);
        nextWeek.setDate(nextWeek.getDate() + 3);


        const result = await apiCall('appointments', 'GET', null, {
            from_date: formatDate(today),
            to_date: formatDate(nextWeek)
        });
        
        if (!result.success) {
            throw new Error(result.error);
            }

        appointments = result.data;

        if (!appointments || !Array.isArray(appointments)) {
            appointments = [];
        }
        
        // Filter: Nur Termine die in Toleranz sind
        const toleranceHours = 6;
        const now = new Date();
        
        const relevantAppointments = appointments.filter(apt => {
            const aptDateTime = new Date(`${apt.date}T${apt.start_time}`);
            const diffHours = Math.abs(now - aptDateTime) / (1000 * 60 * 60);
            return diffHours <= toleranceHours;
        });
        
        // Dropdown befüllen
        const select = document.getElementById('attendanceAppointmentFilter');
        select.innerHTML = '<option value="">Termin wählen...</option>';
        
        relevantAppointments.forEach(apt => {
            const option = document.createElement('option');
            option.value = apt.appointment_id;
            option.textContent = `${apt.title} - ${formatDate(new Date(apt.date))} ${apt.start_time}`;
            select.appendChild(option);
        });
        
        if (relevantAppointments.length === 0) {
            select.innerHTML = '<option value="">Keine aktuellen Termine</option>';
        }
        
    } catch (error) {
        debug.log('Fehler beim Laden der Termine:', error);
        showMessage('Fehler beim Laden der Termine', 'error');
    }
}

async function loadAttendanceList() {
    const appointmentId = document.getElementById('attendanceAppointmentFilter').value;
    const content = document.getElementById('attendanceListContent');
    const btnRefresh = document.getElementById('btnRefreshAttendance');
    const btnCreate = document.getElementById('btnCreateAppointment');
    const btnEdit = document.getElementById('btnEditAppointment');    
    
    if (!appointmentId) {
        content.innerHTML = '<div class="info-box"><p>Bitte wähle einen Termin aus.</p></div>';
        btnRefresh.style.display = 'none';
        btnCreate.style.display = 'block';
        btnEdit.style.display = 'none';        
        currentEditAppointmentId = null;
        return;
    }

    // Speichere gewählten Termin
    currentEditAppointmentId = appointmentId;
    
    try {
        content.innerHTML = '<div class="loading">Lädt...</div>';
        
        const result = await apiCall('attendance_list', 'GET', null, {
            appointment_id: appointmentId
        });

        if (!result.success) {
            throw new Error(result.error);
        }
        
        if (!result.data) {
            throw new Error('Keine Daten erhalten');
        }
        renderAttendanceList(result.data);

        // Buttons anzeigen
        btnRefresh.style.display = 'block';
        btnCreate.style.display = 'none';
        btnEdit.style.display = 'block';
        
    } catch (error) {
        debug.log('Fehler beim Laden der Anwesenheitsliste:', error);
        content.innerHTML = '<div class="error-box"><p>Fehler beim Laden der Liste</p></div>';

        btnRefresh.style.display = 'none';
        btnCreate.style.display = 'block';
        btnEdit.style.display = 'none';        
        currentEditAppointmentId = null;

        showMessage('Fehler beim Laden der Anwesenheitsliste', 'error');
    }
}

function refreshAttendanceList()
{
    debug.log("Refreshing Attendance List");
    if(currentEditAppointmentId) {
        const select = document.getElementById('attendanceAppointmentFilter');
        if (select) {
            select.value = currentEditAppointmentId;
            loadAttendanceList();
        }
    }    
}

function renderAttendanceList(data) {
    const content = document.getElementById('attendanceListContent');
    
    if (!data.members || data.members.length === 0) {
        content.innerHTML = '<div class="info-box"><p>Keine Mitglieder für diesen Termin gefunden.</p></div>';
        return;
    }
    
    // Gruppiere Mitglieder nach Gruppen
    const groupedMembers = {};
    data.members.forEach(member => {
        const groups = member.groups || 'Keine Gruppe';
        if (!groupedMembers[groups]) {
            groupedMembers[groups] = [];
        }
        groupedMembers[groups].push(member);
    });
    
let html =``;    
    // Render jede Gruppe
    Object.keys(groupedMembers).sort().forEach(groupName => {
        const members = groupedMembers[groupName];
        
        html += `<div class="group-section">
            <h4 class="group-header">${groupName}</h4>
            <div class="attendance-list">`;
        
        members.forEach(member => {
            const isPresent = member.record_id !== null;
            const statusClass = isPresent ? 'present' : 'absent';
            const statusIcon = isPresent ? '✓' : '○';

            // Format Ankunftszeit
            let arrivalTimeHtml = '';
            if (isPresent && member.arrival_time) {
                const arrivalDate = new Date(member.arrival_time);
                const timeStr = arrivalDate.toLocaleTimeString('de-DE', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                arrivalTimeHtml = `<span class="arrival-time">Ankunft: ${timeStr}</span>`;
            }
            
            html += `
                <div class="attendance-item ${statusClass}" data-member-id="${member.member_id}">
                    <div class="member-info">
                    <span class="status-icon">${statusIcon}</span>
                        <div class="member-info-row">                            
                            <span class="member-name">${member.surname}, ${member.name}</span>
                            ${arrivalTimeHtml}
                        </div>                        
                    </div>
                    <button class="btn-toggle-attendance" 
                            data-member-id="${member.member_id}"
                            data-appointment-id="${data.appointment.appointment_id}"
                            data-record-id="${member.record_id || ''}"
                            data-is-present="${isPresent}">
                        ${isPresent ? '✗' : '✓'}
                    </button>
                </div>`;
        });
        
        html += `</div></div>`;
    });
    
    content.innerHTML = html;
    
    // Event Listener für Toggle-Buttons
    content.querySelectorAll('.btn-toggle-attendance').forEach(btn => {
        btn.addEventListener('click', handleAttendanceToggle);
    });
}

async function handleAttendanceToggle(event) {
    const btn = event.target;
    const memberId = btn.dataset.memberId;
    const appointmentId = btn.dataset.appointmentId;
    const recordId = btn.dataset.recordId;
    const isPresent = btn.dataset.isPresent === 'true';

    // Speichere Scroll-Position
    const scrollContainer = document.querySelector('.attendance-scroll-container');
    const scrollPosition = scrollContainer ? scrollContainer.scrollTop : 0;
    
    btn.disabled = true;

    // Finde das Listenelement
    const listItem = btn.closest('.attendance-item');
    
    try {
        if (isPresent) {
            // Bestätigung vor dem Löschen
            showNavigationConfirm(
                'Anwesenheit entfernen',
                `Anwesenheit wirklich entfernen?`,
                async () => {
                    btn.disabled = true;

                    try{
                        // Entfernen der Anwesenheit
                        const result = await apiCall('records', 'DELETE', null, { id: recordId });

                        if (!result.success) {
                            throw new Error(result.error);
                        }
                        
                        // Optimistisches UI-Update
                        listItem.classList.remove('present');
                        listItem.classList.add('absent');
                        listItem.querySelector('.status-icon').textContent = '○';
                        listItem.querySelector('.status-icon').style.color = '#bdc3c7';
                        btn.textContent = '✓';
                        btn.dataset.isPresent = 'false';
                        btn.dataset.recordId = '';
                        
                        // Entferne Ankunftszeit
                        const arrivalTime = listItem.querySelector('.arrival-time');
                        if (arrivalTime) arrivalTime.remove();
                        
                        showMessage('Anwesenheit entfernt', 'warning');
                        btn.disabled = false;
                    }
                    catch(error) {
                        debug.log('Fehler beim Entfernen:', error);
                        showMessage('Fehler beim Entfernen', 'error');
                        btn.disabled = false;
                    }
                });
        } else {
            // Hinzufügen der Anwesenheit
            const result = await apiCall('records', 'POST', {
                member_id: parseInt(memberId),
                appointment_id: parseInt(appointmentId)
            });

            if (!result.success) {
                throw new Error(result.error);
            }
            
            // Optimistisches UI-Update
            listItem.classList.remove('absent');
            listItem.classList.add('present');
            listItem.querySelector('.status-icon').textContent = '✓';
            listItem.querySelector('.status-icon').style.color = '#27ae60';
            btn.textContent = '✗';
            btn.dataset.isPresent = 'true';
            btn.dataset.recordId = result.id;
            
            // Füge Ankunftszeit hinzu (aus API-Response)
            if (result.data.arrival_time) {
                const arrivalDate = new Date(result.data.arrival_time);
                const timeStr = arrivalDate.toLocaleTimeString('de-DE', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                const arrivalTimeHtml = `<span class="arrival-time">Ankunft: ${timeStr}</span>`;
                listItem.querySelector('.member-info-row').insertAdjacentHTML('beforeend', arrivalTimeHtml);
            }
        }
        
        btn.disabled = false;
        
    } catch (error) {
        debug.log('Fehler beim Umschalten:', error);
        showMessage('Fehler beim Aktualisieren', 'error');
        btn.disabled = false;
    }
}

// ========================================
// DASHBOARD BUTTON
// ========================================

function handleDashboardNavigation() {
    // Prüfe Rolle
    if (!userData) {
        showMessage('Bitte zuerst einloggen', 'error');
        return;
    }
    
    const baseUrl = window.location.origin + window.location.pathname.replace('checkin/index.html', '').replace('checkin/', '');
    const dashboardUrl = baseUrl + 'index.html';
    
    // Bestätigungsdialog
    showNavigationConfirm(
        'Zur Verwaltung wechseln?',
        'Du verlässt die Check-In App',
        () => {
            window.location.href = dashboardUrl;
        }
    );
}

function showNavigationConfirm(title, message, onConfirm) {
    // Modal erstellen
    let modal = document.getElementById('pwaConfirmModal');
    
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'pwaConfirmModal';
        modal.className = 'pwa-confirm-modal';
        modal.innerHTML = `
            <div class="pwa-confirm-content">
                <h3 id="pwaConfirmTitle">Bestätigung</h3>
                <p id="pwaConfirmMessage">Möchtest du fortfahren?</p>
                <div class="pwa-confirm-buttons">
                    <button class="btn-confirm-no" id="pwaConfirmNo">Abbrechen</button>
                    <button class="btn-confirm-yes" id="pwaConfirmYes">Weiter</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Event Listeners
        modal.querySelector('#pwaConfirmNo').addEventListener('click', () => {
            modal.classList.remove('active');
        });
        
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    }
    
    // Content setzen
    document.getElementById('pwaConfirmTitle').textContent = title;
    document.getElementById('pwaConfirmMessage').textContent = message;
    
    // Bestätigung-Handler
    const yesBtn = document.getElementById('pwaConfirmYes');
    const newYesBtn = yesBtn.cloneNode(true);
    yesBtn.parentNode.replaceChild(newYesBtn, yesBtn);
    
    newYesBtn.addEventListener('click', () => {
        modal.classList.remove('active');
        onConfirm();
    });
    
    modal.classList.add('active');
}

// ========================================
// LOGOUT HANDLING
// ========================================
function requestLogout() {
    showNavigationConfirm(
        'Abmelden',
        'Möchten Sie sich wirklich abmelden?',
        async () => {
            await handleLogout();
        },
        () => {
            // Cancel - nichts tun
        }
    );
}

// ========================================
// CLOCK
// ========================================
function startClock() {
    updateClock();
    clockInterval = setInterval(updateClock, 1000);
}

function stopClock() {
    if (clockInterval) {
        clearInterval(clockInterval);
        clockInterval = null;
    }
}

function updateClock() {
    const now = new Date();
    
    const dateStr = now.toLocaleDateString('de-DE', { 
        weekday: 'long', 
        day: 'numeric', 
        month: 'long',
        year: 'numeric'
    });
    
    const timeStr = now.toLocaleTimeString('de-DE', { 
        hour: '2-digit', 
        minute: '2-digit',
        second: '2-digit'
    });
    
    elements.currentDate.textContent = dateStr;
    elements.currentTime.textContent = timeStr;
}

// ========================================
// CHECKIN-API CALL
// ========================================

async function verifyCheckin(code, inputMethod = 'unknown') {
    elements.scanButton.disabled = true;

    const now = new Date();
    const arrivalTime = formatDateTime(now);    

    // Prüfe ob member_id vorhanden (für Admin oder User)
    if (!userData || !userData.member_id) {
        showMessage('Kein Mitglied verknüpft. Bitte Administrator kontaktieren.', 'error');        
        elements.scanButton.disabled = false;
        return;
    }

    try {
        const requestData = {
            totp_code: code,
            arrival_time: arrivalTime,
            source_device: inputMethod,
            member_id: userData.member_id
        };

        // Admin muss member_id explizit mitschicken
        //if (userData.role === 'admin') {
        requestData.member_id = userData.member_id;
        //}
        // User: member_id wird vom Backend automatisch verwendet

        const result = await apiCall('totp_checkin', 'POST', requestData);
        
        if (!result.success) {
            throw new Error(result.error);
        }

        const data = result.data;

        if (data.appointment) {
            const methodText = inputMethod === 'QR' ? '📷 QR-Code' :
                             inputMethod === 'NFC' ? '📱 NFC' :
                             inputMethod === 'CODE' ? '⌨️ Manuell' : '';

            showMessage(methodText + ' eingecheckt!', 'success');
        }
    } catch (error) {
        showMessage('Check-in fehlgeschlagen', 'error');
        debug.error(error.message);
    } finally {
        elements.scanButton.disabled = false;
    }
}

// ========================================
// UI STATE HELPER
// ========================================

function setCheckinUIState(state) {
    currentUIState = state;
    
    switch(state) {
        case UI_STATE.IDLE:
            // Ruhezustand: Alle Optionen anzeigen
            elements.scannerContainer.style.display = 'none';
            elements.stopScanButton.style.display = 'none';
            elements.scanButton.style.display = 'flex';
            elements.manualCodeBtn.style.display = 'flex';
            elements.exceptionBtn.style.display = 'flex';
            elements.checkinDivider.style.display = 'block';            
            elements.nfcScannerContainer.style.display = 'none';  

            // NFC Button wieder zeigen falls NFC verfügbar
            if (nfcAvailable) {                 
                elements.nfcButton.classList.remove('scanning');
                elements.nfcButton.innerHTML = '<span class="icon">📡</span><span>NFC-Tag scannen</span>';
                elements.nfcButton.style.background = '';
                elements.nfcButton.style.display = 'flex';
            }
            
            isScanning = false;
            isNFCScanning = false;
            break;
            
        case UI_STATE.QR_SCANNING:
            // QR-Scanner aktiv: Nur Scanner und Stop-Button
            elements.scannerContainer.style.display = 'block';
            elements.stopScanButton.style.display = 'flex';
            elements.scanButton.style.display = 'none';
            elements.manualCodeBtn.style.display = 'none';
            elements.exceptionBtn.style.display = 'none';
            elements.checkinDivider.style.display = 'none';            
            elements.nfcButton.style.display = 'none';                        
            isScanning = true;
            break;
            
        case UI_STATE.NFC_SCANNING:

            elements.nfcScannerContainer.style.display = 'block';            
            
            // Alle anderen Buttons verstecken
            elements.scanButton.style.display = 'none';
            elements.manualCodeBtn.style.display = 'none';
            elements.exceptionBtn.style.display = 'none';
            elements.checkinDivider.style.display = 'none';
            elements.nfcButton.classList.add('scanning');
            elements.nfcButton.innerHTML = '<span class="icon">⏹️</span><span>NFC-Scan beenden</span>';
            elements.nfcButton.style.background = '#e74c3c';                        
            isNFCScanning = true;

            break;
    }
}

// ========================================
// QR SCANNER
// ========================================
async function toggleScanner() {
    const scannerContainer = document.getElementById('scannerContainer');
    
    if (isScanning) {

        try {
            await html5QrCode.stop();
        }
        catch(error){
            debug.log('Stopp-Error (ignoriert):', error);
        }

        setCheckinUIState(UI_STATE.IDLE);

    } else {
            
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("qr-reader", {
                verbose: false // Zum Debugen aktivieren
            });
        }

        try {
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0,
                rememberLastUsedCamera: true
            };

            await html5QrCode.start(
                { facingMode: "environment" },
                config,
                onQRScanned,
                (errorMessage) => {
                    const msg = String(errorMessage || "");

                    // Alle „normalen“ Scan-Fehler rausfiltern
                    const isParseError =    msg.includes("QR code parse error") ||                   
                                            msg.includes("No barcode or QR code detected") ||
                                            msg.includes("No MultiFormat Readers were able to detect the code");
                    if (!isParseError) {
                        debug.warn("Scan error:", msg);
                    }
                }
            );

            setCheckinUIState(UI_STATE.QR_SCANNING);
            
        } catch (error) {
            debug.error('Kamera-Fehler:', error);

            // Bei Fehler: Alles zurücksetzen
            setCheckinUIState(UI_STATE.IDLE);
            
            let errorMsg = 'Kamera-Zugriff nicht möglich';
            if (error.name === 'NotAllowedError') {
                errorMsg = 'Kamera-Zugriff wurde verweigert. Bitte Berechtigung in den Browser-Einstellungen erteilen.';
            } else if (error.name === 'NotFoundError') {
                errorMsg = 'Keine Kamera gefunden';
            } else if (error.name === 'NotReadableError') {
                errorMsg = 'Kamera wird bereits von einer anderen App verwendet';
            }
            
            showMessage(errorMsg, 'error');
        }
    }
}

async function onQRScanned(decodedText) {
    await stopScannerIfRunning();
    
    // Extrahiere TOTP-Code aus QR
    let totpCode = decodedText;
    
    // Format: "CHECKIN:123456" oder nur "123456"
    if (decodedText.startsWith('CHECKIN:')) {
        totpCode = decodedText.substring(8); // Entferne Prefix
    }
    
    // Validiere 6-stellig
    if (!/^\d{6}$/.test(totpCode)) {
        showMessage('Ungültiges Code-Format (6 Ziffern erwartet)', 'error');
        return;
    }
    
    await verifyCheckin(totpCode, 'QR');
}

// Helper-Funktion zum Stoppen des Scanners
async function stopScannerIfRunning() {
    if (isScanning && html5QrCode) {
        await html5QrCode.stop();

        setCheckinUIState(UI_STATE.IDLE);
    }
}

// ========================================
// MANUAL CODE INPUT
// ========================================
async function openManualCodeInput() {
    // Falls Scanner läuft, erst stoppen
    await stopScannerIfRunning();

    elements.manualCodeModal.classList.add('active');
    elements.manualCode.value = '';
    elements.manualCode.focus();
}

function closeManualCodeModal() {
    elements.manualCodeModal.classList.remove('active');
}

async function submitManualCode() {
    const code = elements.manualCode.value.trim();
    closeManualCodeModal();

    if (!/^\d{6}$/.test(code)) {
        showMessage('Bitte 6-stelligen Code eingeben', 'error');
        return;
    }
        
    await verifyCheckin(code,'CODE');
}


// ========================================
// NFC READER
// ========================================

async function checkNFCSupport() {    
    if ('NDEFReader' in window) {
        try {
            // Prüfe Permissions
            const permissionStatus = await navigator.permissions.query({ name: "nfc" });
                        
            if (permissionStatus.state === "granted" || permissionStatus.state === "prompt") {
                elements.nfcButton.style.display = 'flex';
                nfcAvailable = true;
                debug.log('✓ NFC wird unterstützt');
            } else {
                debug.log('✗ NFC-Berechtigung verweigert');
            }
        } catch (error) {
            debug.log('✗ NFC-Permission-Check fehlgeschlagen:', error);
            // Zeige Button trotzdem - User kann beim ersten Scan Berechtigung erteilen
            elements.nfcButton.style.display = 'flex';
            nfcAvailable = true;
        }
    } else {
        debug.log('✗ NFC wird von diesem Browser nicht unterstützt');
        elements.nfcButton.style.display = 'none';
    }
}

async function toggleNFCReader() {
    if (isNFCScanning) {
        stopNFCReader();
    } else {
        await startNFCReader();
    }
}

async function startNFCReader() {
    
    // Falls QR-Scanner läuft, erst stoppen
    await stopScannerIfRunning();

    if (!('NDEFReader' in window)) {
        showMessage('NFC wird von diesem Gerät nicht unterstützt', 'error');
        return;
    }
        
    try {
        
        const ndef = new NDEFReader();
        nfcAbortController = new AbortController();
        
        await ndef.scan({ signal: nfcAbortController.signal });

        setCheckinUIState(UI_STATE.NFC_SCANNING);                                   
        
        ndef.addEventListener("reading", ({ message, serialNumber }) => {
            debug.log('NFC-Tag erkannt:', serialNumber);
            onNFCTagRead(message, serialNumber);
        }, { signal: nfcAbortController.signal });
        
        ndef.addEventListener("readingerror", () => {
            showMessage('Fehler beim Lesen des NFC-Tags', 'error');
        }, { signal: nfcAbortController.signal });        
        
        
    } catch (error) {
        debug.error('NFC-Fehler:', error);
        
        if (error.name === 'NotAllowedError') {
            showMessage('NFC-Berechtigung wurde verweigert. Bitte erlaube NFC-Zugriff in den Browser-Einstellungen.', 'error');
        } else if (error.name === 'NotSupportedError') {
            showMessage('NFC wird von diesem Gerät nicht unterstützt', 'error');
        } else {
            showMessage('NFC konnte nicht gestartet werden: ' + error.message, 'error');
        }
        
        stopNFCReader();
    }
}

function stopNFCReader() {    

    if(isNFCScanning)
        setCheckinUIState(UI_STATE.IDLE);

    if (nfcAbortController) {
        nfcAbortController.abort();
        nfcAbortController = null;        
                    
        showMessage('NFC-Scan abgebrochen', 'warning');
    }       
}

async function onNFCTagRead(message, serialNumber) {
    debug.log('NFC-Tag gelesen:', { message, serialNumber });
    
    stopNFCReader();
    
    // Versuche NDEF-Records zu lesen
    let checkinCode = null;
    
    for (const record of message.records) {
        debug.log('NDEF Record:', record.recordType, record);
        
        if (record.recordType === "text") {
            const textDecoder = new TextDecoder(record.encoding || 'utf-8');
            const text = textDecoder.decode(record.data);
            debug.log('Text-Record:', text);
            
            // Prüfe ob es ein CHECKIN-Code ist
            if (text.startsWith('CHECKIN:')) {
                checkinCode = text;
                break;
            }
        } else if (record.recordType === "url") {
            const textDecoder = new TextDecoder();
            const url = textDecoder.decode(record.data);
            debug.log('URL-Record:', url);
            
            // Extrahiere Code aus URL (z.B. https://example.com/checkin?code=CHECKIN:123456)
            const match = url.match(/CHECKIN:\d{6}/);
            if (match) {
                checkinCode = match[0];
                break;
            }
        }
    }
    
    if (checkinCode) {
        await verifyCheckin(checkinCod,'NFC');
    } 
}

// ========================================
// EXCEPTION
// ========================================

async function loadAppointmentTypes() {

    try {        
        const result = await apiCall('appointment_types');
        if (!result.success) {
            throw new Error(result.error);
        }
        appointmentTypes =  result.data;       
        debug.log('Terminarten geladen', appointmentTypes);
    }
    catch (error) {
        appointmentTypes = [];
        debug.error('Keine Terminarten gefunden.', error);
    }
}

async function loadAppointments() {
    try {
        const result = await apiCall('appointments','GET',null, {member_id:userData.member_id});

        if (!result.success) {
            throw new Error(result.error);
        }
        appointments = result.data;
        
        const today = new Date();
        today.setHours(0, 0, 0, 0);
            
        // Letzte 3 Tage + Zukunft (für nachträgliche Anträge)
        const sevenDaysAgo = new Date(today);
        sevenDaysAgo.setDate(today.getDate() - 3);
        
        const relevantAppointments = appointments.filter(a => {
            const aptDate = new Date(a.date);
            aptDate.setHours(0, 0, 0, 0);
            return aptDate >= sevenDaysAgo;
        });
        
        elements.exceptionAppointment.innerHTML = '<option value="">Bitte wählen...</option>';
        
        relevantAppointments.forEach(apt => {
            const option = document.createElement('option');
            option.value = apt.appointment_id;
            option.textContent = `${apt.title} (${apt.date} ${apt.start_time})`;
            elements.exceptionAppointment.appendChild(option);
        });
    } catch (error) {
        debug.error('Fehler beim Laden der Termine:', error);
    }
}

async function openExceptionModal() {
    // Falls Scanner läuft, erst stoppen
    await stopScannerIfRunning();

    await loadAppointments();
    elements.exceptionModal.classList.add('active');
    elements.exceptionReason.value = '';
}

function closeExceptionModal() {
    elements.exceptionModal.classList.remove('active');
}

async function submitException() {
    const appointmentId = elements.exceptionAppointment.value;
    const reason = elements.exceptionReason.value.trim();
    
    if (!appointmentId) {
        closeExceptionModal();
        showMessage('Bitte Termin auswählen', 'error');
        return;
    }
    
    if (!reason) {
        closeExceptionModal();
        showMessage('Bitte Begründung angeben', 'error');
        return;
    }

    // Prüfe ob member_id vorhanden
    if (!userData || !userData.member_id) {
        showMessage('Kein Mitglied verknüpft. Bitte Administrator kontaktieren.', 'error');
        return;
    }
    
    try {
        const now = new Date();
        const arrivalTime = formatDateTime(now);             

        const result = await apiCall('exceptions', 'POST', {
            member_id: userData.member_id,
            appointment_id: parseInt(appointmentId),
            exception_type: 'time_correction',
            reason: reason,
            requested_arrival_time: arrivalTime       
        });

        if (!result.success) {
            throw new Error(result.error);
        }
        const data = result.data;

        if (data) {
            showMessage('✓ Antrag erfolgreich gestellt (wartet auf Genehmigung)', 'warning');
            
            closeExceptionModal();
        }
    } catch (error) {
        closeExceptionModal();
        showMessage(error.message || 'Fehler beim Erstellen des Antrags', 'error');
    }
}

// ========================================
// UI HELPERS
// ========================================
function showScreen(screenName) {
    elements.loginScreen.classList.remove('active');
    elements.mainScreen.classList.remove('active');
    
    if (screenName === 'login') {
        elements.loginScreen.classList.add('active');
    } else {
        elements.mainScreen.classList.add('active');
    }
}

function showError(message) {
    elements.loginError.textContent = message;
    elements.loginError.classList.add('active');
}

function showMessage(text, type) {
    elements.statusMessage.textContent = text;
    elements.statusMessage.className = `status-message ${type}`;

    setTimeout(() => {
        elements.statusMessage.className = 'status-message';
    }, 5000);
}

function showOfflineIndicator() {
    elements.offlineIndicator.classList.add('active');
    setTimeout(() => {
        elements.offlineIndicator.classList.remove('active');
    }, 3000);
}

// Lädt History beim Login
async function loadHistory() {    
        
    try {
        // Lade letzte 10 Records
        let result = await apiCall('records','GET',null,{ member_id: userData.member_id });
        if (!result.success) {
            throw new Error(result.error);
        }
        let records = result.data;
        
        // Lade offene Exceptions
        result = await apiCall('exceptions', 'GET', null, { member_id: userData.member_id,status: 'pending' });
         if (!result.success) {
            throw new Error(result.error);
        }

        let exceptions = result.data;
        
        // Kombiniere und sortiere nach Datum (neueste zuerst)
        const combined = [
            ...records.slice(0, 10).map(r => ({
                type: 'record',
                data: r,
                timestamp: new Date(r.arrival_time)
            })),
            ...exceptions.map(e => ({
                type: 'exception',
                data: e,
                timestamp: new Date(e.created_at)
            }))
        ];

        combined.sort((a, b) => b.timestamp - a.timestamp);       
        
        // Debug
        debug.log("Loading History", combined);
        
        // Zeige die letzten 10 Einträge
        renderHistory(combined.slice(0, 10));
        
    } catch (error) {
        debug.error('Fehler beim Laden der History:', error);
        elements.historyList.innerHTML = '<div class="history-empty">Fehler beim Laden der Historie</div>';
    }
}

// Rendert History-Liste
function renderHistory(items) {
    elements.historyList.innerHTML = '';
    
    if (items.length === 0) {
        elements.historyList.innerHTML = '<div class="history-empty">Noch keine Aktivitäten</div>';
        return;
    }
    
    items.forEach(item => {
        if (item.type === 'record') {
            addRecordToHistory(item.data);
        } else {
            addExceptionToHistory(item.data);
        }
    });
}

// Fügt Record zur History hinzu
function addRecordToHistory(record) {
    const item = document.createElement('div');
    item.className = 'history-item verified';    
    
    const arrivalTime = new Date(record.arrival_time);
    const dateStr = arrivalTime.toLocaleDateString('de-DE', { 
        day: '2-digit', 
        month: '2-digit',
        year: 'numeric'
    });
    const timeStr = arrivalTime.toLocaleTimeString('de-DE', { 
        hour: '2-digit', 
        minute: '2-digit' 
    });
    
    const statusText = translateStatus(record.status);

    // Appointment Type Badge hinzufügen (falls vorhanden)
    let typeBadge = '';
    if (record.appointment_type_name) {
        const color = getTypeColor(record.appointment_type_name);
        typeBadge = `<span class="type-badge" style="background: ${color}; color: white;">${record.appointment_type_name}</span>`;
    }
    
    item.innerHTML = `
        <div class="time">📍 ${dateStr} ${timeStr}</div>
        <div class="appointment">${record.title}</div>
        <span class="status verified">✓ ${statusText}</span>
        ${typeBadge}
    `;
    
    elements.historyList.appendChild(item);
}

// Fügt Exception zur History hinzu
function addExceptionToHistory(exception) {
    const item = document.createElement('div');

    const canDelete = exception.status === 'pending';
    item.className = canDelete ? 'history-item pending has-delete' : 'history-item pending';
    
    const createdAt = new Date(exception.created_at);
    const dateStr = createdAt.toLocaleDateString('de-DE', { 
        day: '2-digit', 
        month: '2-digit',
        year: 'numeric'
    });
    const timeStr = createdAt.toLocaleTimeString('de-DE', { 
        hour: '2-digit', 
        minute: '2-digit' 
    });
    
    const typeText = exception.exception_type === 'absence' ? 'Entschuldigung' : 'Zeitkorrektur';
    const statusText = translateExceptionStatus(exception.status);

    // Appointment Type Badge hinzufügen (falls vorhanden)
    let typeBadge = '';
    if (exception.appointment_type_name) {
        const color = getTypeColor(exception.appointment_type_name);
        typeBadge = `<span class="type-badge" style="background: ${color}; color: white;">${exception.appointment_type_name}</span>`;
    }

    // Delete-Button nur bei pending
    const deleteBtn = canDelete 
        ? `<button class="delete-btn" onclick="deleteException(${exception.exception_id})">🗑️ Löschen</button>`
        : '';
    
    item.innerHTML = `        
        <div class="time">📋 ${dateStr} ${timeStr} ${deleteBtn}</div>
        <div class="appointment">${exception.appointment_title}</div>
        <span class="status pending">${statusText} - ${typeText}</span>
        ${typeBadge}
        
    `;
    
    elements.historyList.appendChild(item);
}

// ========================================
// APPOINTMENT MODAL
// ========================================

async function showCreateAppointmentModal() {
    currentEditAppointmentId = null;
    document.getElementById('appointmentModalTitle').textContent = 'Termin anlegen';
    
    // Lade Terminarten
    await loadAppointmentTypes();
    
    // Formular zurücksetzen
    document.getElementById('appointmentForm').reset();
    
    // Zeige Modal
    document.getElementById('appointmentModal').classList.add('active');
}

async function showEditAppointmentModal() {
    const appointmentId = document.getElementById('attendanceAppointmentFilter').value;
    if (!appointmentId) return;
    
    currentEditAppointmentId = appointmentId;
    document.getElementById('appointmentModalTitle').textContent = 'Termin bearbeiten';
    
    try {
        // Lade Terminarten
        await loadAppointmentTypes();
        
        // Lade Termin-Daten
        const result = await apiCall('appointments', 'GET', null, { id: appointmentId });
         if (!result.success) {
            throw new Error(result.error);
        }

        const appointment = result.data;
        
        if (appointment) {
            document.getElementById('appointmentTitle').value = appointment.title || '';
            document.getElementById('appointmentDate').value = appointment.date || '';
            document.getElementById('appointmentTime').value = appointment.start_time || '';
            document.getElementById('appointmentType').value = appointment.type_id || '';            
        }
        
        // Zeige Modal
        document.getElementById('appointmentModal').classList.add('active');
        
    } catch (error) {
        debug.log('Fehler beim Laden des Termins:', error);
        showMessage('Fehler beim Laden des Termins', 'error');
    }
}

async function loadAppointmentTypes() {
    try {
        const result = await apiCall('appointment_types', 'GET');

        if (!result.success) {
                    throw new Error(result.error);
                }

        appointmentTypes = result.data;
        
        const select = document.getElementById('appointmentType');
        select.innerHTML = '<option value="">Bitte wählen...</option>';
        
        if (appointmentTypes && Array.isArray(appointmentTypes)) {
            appointmentTypes.forEach(type => {
                const option = document.createElement('option');
                option.value = type.type_id;
                option.textContent = type.type_name;
                select.appendChild(option);
            });
        }
    } catch (error) {
        debug.log('Fehler beim Laden der Terminarten:', error);
    }
}


// ========================================
// CONFIRMATION MODAL
// ========================================

// Wrapper für Exception löschen
async function deleteException(exceptionId) 
{    
    deleteExceptionId = exceptionId;
    openConfirmDeleteModal();    
        
}

async function openConfirmDeleteModal(exceptionId) {

    elements.confirmDeleteModal.classList.add('active');
    elements.closeConfirmDeleteBtn.focus();
}

function closeConfirmDeleteModal() {
    deleteExceptionId = null;
    elements.confirmDeleteModal.classList.remove('active');
}

async function submitConfirmDelete() {
    
    if(!deleteExceptionId)
        return;    

    try {
        const result =  await apiCall('exceptions', 'DELETE', null, { id: deleteExceptionId });

         if (!result.success) {
            throw new Error(result.error);
        }
        
        showMessage('✓ Antrag erfolgreich gelöscht', 'success');
        
        // History neu laden
        await loadHistory();
        
    } catch (error) {
        debug.error('Fehler beim Löschen:', error);
        showMessage(error.message || 'Fehler beim Löschen', 'error');
    }

    deleteExceptionId = null;
    elements.confirmDeleteModal.classList.remove('active');
}


// Fügt neuen Eintrag nach Check-in hinzu (prepend)
function addNewActivityToHistory(data) {
    // Erstelle temporären Eintrag für sofortiges Feedback
    const item = document.createElement('div');
    item.className = data.pending ? 'history-item pending' : 'history-item verified';
    
    const now = new Date();
    const timeStr = now.toLocaleTimeString('de-DE', { 
        hour: '2-digit', 
        minute: '2-digit' 
    });

    // Appointment Type Badge hinzufügen (falls vorhanden)
    let typeBadge = '';
    if (data.appointment_type_name) {
        const color = getTypeColor(data.appointment_type_name);
        typeBadge = `<span class="type-badge" style="background: ${color}; color: white;">${data.appointment_type_name}</span>`;
    }
    
    const statusBadge = data.pending 
        ? '<span class="status pending">⏳ Antrag ausstehend</span>'
        : '<span class="status verified">✓ Verifiziert</span>';
    
    item.innerHTML = `
        <div class="time">🆕 Gerade eben (${timeStr})</div>
        <div class="appointment">${data.appointment?.title || 'Unbekannter Termin'}</div>
        ${statusBadge}
        ${typeBadge}
    `;
    
    // Füge am Anfang ein
    elements.historyList.insertBefore(item, elements.historyList.firstChild);
    
    // Begrenze auf 10 Einträge
    while (elements.historyList.children.length > 10) {
        elements.historyList.removeChild(elements.historyList.lastChild);
    }
}

// ========================================
// TAB MANAGEMENT
// ========================================
function initTabs() {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const targetTab = button.dataset.tab;
            
            // Deaktiviere alle
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Aktiviere gewählten Tab
            button.classList.add('active');
            document.querySelector(`.tab-content[data-tab="${targetTab}"]`).classList.add('active');
            
            // Scanner stoppen wenn Tab gewechselt wird
            if (targetTab !== 'checkin') {
                stopScannerIfRunning();
                stopNFCReader();
            }

            // Lade Daten wenn nötig
            if (targetTab === 'stats') {
                debug.log("Loading Stats");
                loadStatistics();
            }
            else if(targetTab === 'history')
            {
                debug.log("Loading History");
                loadHistory();
            }
            else if(targetTab === 'attendance-list')
            {
                debug.log("Loading Attendance List");
                loadAttendanceAppointments().then(() => {
                    // Stelle gespeicherte Auswahl wieder her
                    if (currentEditAppointmentId) {
                        const select = document.getElementById('attendanceAppointmentFilter');
                        if (select) {
                            select.value = currentEditAppointmentId;
                            // Trigger das change Event um die Liste zu laden
                            loadAttendanceList();
                        }
                    }
                });
            }                                    
        });
    });
}


// ========================================
// STATISTICS LOADING
// ========================================

async function loadStatistics() {
    const statsLoading = document.getElementById('statsLoading');
    const statsContent = document.getElementById('statsContent');
    
    statsLoading.style.display = 'block';
    statsContent.style.display = 'none';
    
  try {
        // Hole Member-spezifische Statistik
        if (!userData || !userData.member_id) {
            throw new Error('Keine Member-ID verfügbar');
        }
        
       // Hole Statistik für aktuelles Jahr
        const result = await apiCall('statistics', 'GET', null, { 
            member_id: userData.member_id,
            year: currentStatsYear
        });

         if (!result.success) {
            throw new Error(result.error);
        }

        const stats = result.data;
        
        // Debug
        debug.log('Statistics:', stats); 

        // Zeige Jahr an
        document.getElementById('currentYear').textContent = currentStatsYear;
        
        // Zeige Statistiken an
        displayStatistics(stats);
        
        // Zeige Inhalt
        statsLoading.style.display = 'none';
        statsContent.style.display = 'block';
        
    } catch (error) {
        debug.error('Fehler beim Laden der Statistik:', error);
        showMessage('Statistik konnte nicht geladen werden', 'error');
        statsLoading.innerHTML = '<p style="color: #e74c3c;">❌ Fehler beim Laden</p>';
    }
}

// ========================================
// STATISTICS DISPLAY
// ========================================
function displayStatistics(stats) {
    if (!stats || !stats.summary) {
        document.getElementById('statAttendanceRate').textContent = '0%';
        document.getElementById('statTotalAppointments').textContent = '0';
        document.getElementById('groupsList').innerHTML = '<p style="text-align: center; color: #999; padding: 20px;">Keine Daten verfügbar</p>';
        return;
    }
    
    const summary = stats.summary;
    
    // 1. Anwesenheitsquote
    const attendanceRate = summary.overall_average || 0;
    document.getElementById('statAttendanceRate').textContent = `${attendanceRate.toFixed(1)}%`;
    
    // 2. Gesamtanzahl Termine
    const totalAppointments = summary.total_appointments || 0;
    document.getElementById('statTotalAppointments').textContent = totalAppointments;
    
    // 4. Gruppen-Übersicht
    displayGroupStats(stats);
}


// ========================================
// GROUP STATS DISPLAY
// ========================================
function displayGroupStats(stats) {
    const groupsList = document.getElementById('groupsList');
    
    // Prüfe ob groups vorhanden sind
    if (!stats.statistics  || stats.statistics.length === 0) {
        groupsList.innerHTML = `
            <div class="groups-empty">
                <div class="empty-icon">📊</div>
                <p>Keine Gruppendaten für ${currentStatsYear} verfügbar</p>
            </div>
        `;
        return;
    }
    
    // Sortiere Gruppen nach Namen
    const sortedGroups = [...stats.statistics].sort((a, b) => 
        a.group_name.localeCompare(b.group_name)
    );
    
    // Rendere Gruppen-Liste - verwende Daten direkt aus API
    groupsList.innerHTML = sortedGroups.map(group => {
        // Die API liefert bereits die Daten für das angemeldete Mitglied
        const member = group.members[0]; // Nur ein Mitglied (das angemeldete)        
        const groupName = group.group_name || 'Ohne Gruppe';
        const appointments = member.total_appointments || 0;
        const attended = member.attended || 0;
        const attendanceRate = member.attendance_rate || 0;
        
        // Farbe basierend auf Quote
        let rateColor = '#27ae60'; // Grün (>= 75%)
        if (attendanceRate < 50) {
            rateColor = '#e74c3c'; // Rot
        } else if (attendanceRate < 75) {
            rateColor = '#f39c12'; // Orange
        }
        
        return `
            <div class="group-item">
                <div class="group-header">
                    <div class="group-name">${groupName}</div>
                    <div class="group-rate" style="color: ${rateColor};">${attendanceRate.toFixed(1)}%</div>
                </div>
                <div class="group-details">
                    <span class="group-stat">📅 ${appointments} Termin${appointments !== 1 ? 'e' : ''}</span>
                    <span class="group-stat">✓ ${attended} anwesend</span>
                </div>
            </div>
        `;
    }).join('');
}

// ========================================
// YEAR NAVIGATION
// ========================================
function initYearNavigation() {
    document.getElementById('prevYear').addEventListener('click', async () => {
        currentStatsYear--;
        await loadStatistics();
    });
    
    document.getElementById('nextYear').addEventListener('click', async () => {
            currentStatsYear++;
            await loadStatistics();
    });    
}

// ========================================
// UTILITY
// ========================================

function translateStatus(status) {
    const translations = {
        'present': 'Anwesend',
        'excused': 'Entschuldigt'
    };
    return translations[status] || status;
}

function translateExceptionStatus(status) {
    const translations = {
        'pending': 'Ausstehend',
        'approved': 'Genehmigt',
        'rejected': 'Abgelehnt'
    };
    return translations[status] || status;
}

function formatDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    
    return `${year}-${month}-${day}`;
}

function formatDateTime(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

function getTypeColor(typeName) {
    if (!typeName || appointmentTypes.length === 0) {
        return '#95a5a6'; // Fallback Grau
    }
    
    // Finde Type in appointmentTypes Array
    const type = appointmentTypes.find(t => 
        t.type_name === typeName || 
        t.type_name.toLowerCase() === typeName.toLowerCase()
    );
    
    return type ? type.color : '#95a5a6'; // Fallback wenn nicht gefunden
}