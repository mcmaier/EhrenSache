/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

const DEBUG = true;

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
let tickTimer = null;
let appointments = [];
let appointmentTypes = [];
let checkinAppointments = [];
let clientSettings = { checkin_auto_create_appointment: '1', checkin_tolerance_hours: '2' };
let deleteExceptionId = null;
let nfcAbortController = null;
let nfcAvailable = false;
let currentStatsYear = new Date().getFullYear();
let currentEditAppointmentId = null;

// In checkin/index.html oder checkin/app.js
    if ('serviceWorker' in navigator) {
        //const currentPath = window.location.pathname;
        //const basePath = currentPath.substring(0, currentPath.lastIndexOf('/') + 1);

        navigator.serviceWorker.register('./service-worker.js', {
            scope: './'
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
    // Ausweg aus dem laufenden Sucher, in beiden Absichten. openManualCodeInput
    // stoppt die Kamera selbst, bevor das Eingabefeld erscheint.
    document.getElementById('scanManualBtn')?.addEventListener('click', openManualCodeInput);
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
// EREIGNISSE EINMALIG BINDEN
// ========================================

/**
 * Haengt ein Ereignis genau einmal an ein Element.
 *
 * Die init*-Funktionen laufen bei JEDER Anmeldung erneut — die Elemente im
 * Dokument bleiben dabei dieselben. Ein zweites addEventListener mit einer
 * frisch erzeugten Arrow-Function haengt dort einen weiteren Handler an; nur
 * identische Funktionsreferenzen weist der Browser von sich aus ab. Nach einem
 * Zyklus Abmelden → Anmelden schickte deshalb ein Klick auf „Start" zwei
 * Anfragen, und einer auf „nächstes Jahr" sprang zwei Jahre weit.
 *
 * Gemerkt wird am Element selbst, nicht in einer Liste im Skript: So gilt die
 * Sperre auch, wenn das Element ausgetauscht wird. Die Idee stammt aus
 * initAttendanceList(), wo sie bis 1.3.1 siebenmal von Hand als
 * `dataset.listenerAdded` ausgeschrieben stand.
 *
 * Ein Element bekommt je Ereignisart genau einen Handler — mehr braucht keine
 * der Ansichten, und das haelt den Schluessel einfach.
 */
function bindOnce(el, type, handler) {
    if (!el) return;

    const bound = (el.dataset.boundEvents || '').split(' ').filter(Boolean);
    if (bound.includes(type)) return;

    el.addEventListener(type, handler);
    bound.push(type);
    el.dataset.boundEvents = bound.join(' ');
}

// ========================================
// API HELPER
// ========================================

/**
 * Uebersetzt Servermeldungen, statt sie durch einen Statustext zu ersetzen.
 *
 * Schluessel ist die englische Servermeldung, NICHT der HTTP-Status: Ein 403
 * traegt mehrere Ursachen — Nachweispflicht der Taetigkeitsart und fremde
 * Mitgliedergruppe —, die Meldung dagegen ist eindeutig. Beide landeten vorher
 * unter „Keine Berechtigung fuer diese Aktion", was in keinem der Faelle
 * zutrifft.
 *
 * Damit sind die Servertexte eine Schnittstelle. Wer einen aendert, aendert ihn
 * hier mit; ein Test in tests/suites/worktime_api.php sichert den Wortlaut des
 * Gruppen-403 ab.
 */
const SERVER_MESSAGES = {
    'A session is already running':
        'Es läuft bereits eine Zeiterfassung. Bitte zuerst beenden.',
    'This activity type requires a TOTP code to start':
        'Diese Tätigkeit verlangt beim Start den QR-Code der Station.',
    'This activity type requires a TOTP code to stop':
        'Diese Tätigkeit verlangt auch beim Beenden den QR-Code der Station.',
    'Activity type not allowed for this member':
        'Diese Tätigkeit ist für deine Gruppe nicht vorgesehen.',
    'Invalid or expired TOTP code':
        'Der Code ist ungültig oder abgelaufen.',
    'No TOTP station configured':
        'Es ist keine Station eingerichtet. Bitte an die Verwaltung wenden.',
    'Activity type is retired':
        'Diese Tätigkeit wird nicht mehr angeboten.',
    'A note is required to stop a session':
        'Für diese Erfassung ist eine Notiz erforderlich.',
    'No running session':
        'Es läuft gerade keine Zeiterfassung.',
    'No member linked to your account':
        'Dein Konto ist mit keinem Mitglied verknüpft. Bitte an die Verwaltung wenden.',
    'Session is still running':
        'Die Sitzung läuft noch — erst beenden, dann korrigieren.',

    // Tritt auf, wenn die Auswahl im Geraet veraltet ist — etwa weil ein
    // Administrator die Taetigkeit geloescht hat, waehrend die App offen lag.
    // Ohne Eintrag bliebe hier die englische Rohmeldung stehen: Status 400 ist
    // im Statuswerk unten auskommentiert.
    'Unknown activity_id':
        'Diese Tätigkeit gibt es nicht mehr. Bitte die App neu laden.',
    'Unknown appointment_id':
        'Diesen Termin gibt es nicht mehr. Bitte die App neu laden.',
    'Kein passender Termin gefunden':
        'Kein passender Termin gefunden. Bitte beim Vorstand melden.',
    'Termin liegt an einem anderen Tag':
        'Dieser Termin ist nicht von heute. Bitte die App neu laden.',
    'Termin gehoert zu einer anderen Gruppe':
        'Dieser Termin ist für deine Gruppe nicht vorgesehen.',
    'Termin liegt außerhalb des Zeitfensters':
        'Dieser Termin liegt zeitlich zu weit entfernt. Bitte den passenden Termin wählen.'
};

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

        // Eine bekannte Servermeldung ist genauer als jeder Statustext.
        const translated = SERVER_MESSAGES[responseData?.message];
        if (translated) {
            // Die Rohmeldung bleibt im Protokoll, sonst ist sie fuer die
            // Fehlersuche verloren.
            debug.log('Servermeldung:', responseData.message);

            return {
                success: false,
                status: response.status,
                error: translated,
                data: responseData
            };
        }

        // Spezifische Fehlermeldungen
        switch (response.status) {
            /*case 400:
                break;
            case 401:                
                errorMessage = 'Anmeldedaten ungültig oder Token abgelaufen';
                break;*/
            case 403:
                errorMessage = 'Keine Berechtigung für diese Aktion';
                break;
            case 404:
                errorMessage = 'Ressource nicht gefunden';
                break;
            case 409:
                errorMessage = 'Konflikt - Eintrag existiert bereits';
                break;
            case 422:
                errorMessage = 'Ungültige Eingabedaten';
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

// ========================================
// CHECK-IN: TERMINWAHL
// ========================================

/**
 * Liest die zwei Einstellungen, die der Client kennen muss.
 *
 * Ohne sie kuendigt der Hinweistext unter der Terminauswahl womoeglich etwas
 * an, das nicht eintritt. Schlaegt der Aufruf fehl, bleiben die Vorgaben
 * stehen — sie entsprechen dem Verhalten vor 1.2.4.
 */
async function loadClientSettings() {
    const result = await apiCall('settings', 'GET', null, { scope: 'client' });

    if (result.success && result.data && result.data.settings) {
        clientSettings = { ...clientSettings, ...result.data.settings };
    } else {
        debug.error('Client-Einstellungen nicht ladbar:', result.error);
    }
}

/**
 * Termine des heutigen Tages fuer die Check-in-Auswahl.
 *
 * Bewusst nur heute, anders als bei der Arbeitszeit: Anwesenheit ist an den
 * Tag gebunden, und der Server weist einen Termin an einem anderen Tag mit 409
 * ab.
 *
 * Das Datum kommt aus der LOKALEN Zeit. toISOString() liefert UTC und zeigte
 * bis d7ee191 abends ab 22:00 MESZ den Folgetag an.
 */
async function loadCheckinAppointments() {
    const select = document.getElementById('checkinAppointment');
    if (!select || !userData || !userData.member_id) return;

    const now = new Date();
    const heute = `${now.getFullYear()}-`
                + `${String(now.getMonth() + 1).padStart(2, '0')}-`
                + `${String(now.getDate()).padStart(2, '0')}`;

    const result = await apiCall('appointments', 'GET', null, {
        member_id: userData.member_id,
        from_date: heute,
        to_date: heute
    });

    checkinAppointments = (result.success && Array.isArray(result.data)) ? result.data : [];

    if (!result.success) {
        debug.error('Termine fuer den Check-in nicht ladbar:', result.error);
    }

    renderCheckinAppointmentOptions();
}

function renderCheckinAppointmentOptions() {
    const select = document.getElementById('checkinAppointment');
    const hint   = document.getElementById('checkinAppointmentHint');
    if (!select) return;

    const previous = select.value;

    // Nach Naehe zur aktuellen Uhrzeit: der wahrscheinlichste Termin steht oben.
    const now = Date.now();
    const options = checkinAppointments.slice().sort((a, b) =>
        Math.abs(new Date(`${a.date}T${a.start_time}`).getTime() - now)
        - Math.abs(new Date(`${b.date}T${b.start_time}`).getTime() - now));

    select.innerHTML = '<option value="">Termin wählen …</option>'
        + options.map(apt =>
            `<option value="${apt.appointment_id}">`
            + `${escapeHtml(apt.title)} · ${apt.start_time.substring(0, 5)}`
            + `</option>`).join('');

    select.value = previous;

    // Nur vorbelegen, wenn noch keine Auswahl steht — weder eine
    // wiederhergestellte (previous) noch eine, deren Termin nicht mehr in
    // der Liste ist (dann setzt der Browser value bereits auf '').
    if (!select.value) {
        const closest = findClosestCheckinAppointment();
        if (closest) {
            select.value = String(closest.appointment_id);
        }
    }

    if (hint) {
        hint.textContent = clientSettings.checkin_auto_create_appointment === '1'
            ? 'Ohne Auswahl wird der passende Termin gesucht — findet sich keiner, wird ein neuer angelegt.'
            : 'Ohne Auswahl wird der passende Termin gesucht — findet sich keiner, schlägt der Check-in fehl.';
    }

    renderCheckinSuggestion();
}

/**
 * Zeitlich naechster Termin im Toleranzfenster, oder null.
 *
 * Spiegelt die serverseitige Regel aus auto_checkin.php, ohne die
 * Standard-Gruppen-Prioritaet bei mehreren Kandidaten im selben Fenster —
 * fuer einen Hinweis ausreichend, verbindlich bleibt ohnehin der Server.
 */
function findClosestCheckinAppointment() {
    const parsedTolerance = parseInt(clientSettings.checkin_tolerance_hours, 10);
    const toleranceHours = Number.isNaN(parsedTolerance) ? 2 : parsedTolerance;
    const toleranceMs = toleranceHours * 60 * 60 * 1000;
    const now = Date.now();

    let closest = null;
    let closestDiff = Infinity;

    for (const apt of checkinAppointments) {
        const diff = Math.abs(new Date(`${apt.date}T${apt.start_time}`).getTime() - now);
        if (diff <= toleranceMs && diff < closestDiff) {
            closest = apt;
            closestDiff = diff;
        }
    }

    return closest;
}

/**
 * Zeigt oder verbirgt den Banner ueber den Buttons, passend zum aktuellen
 * Stand der Terminauswahl — nicht nur zum ersten automatischen Treffer.
 */
function renderCheckinSuggestion() {
    const banner = document.getElementById('checkinSuggestion');
    const select = document.getElementById('checkinAppointment');
    if (!banner || !select) return;

    const chosen = checkinAppointments.find(
        apt => String(apt.appointment_id) === select.value
    );

    if (chosen) {
        banner.textContent =
            `📍 Du checkst ein für: ${chosen.title}, ${chosen.start_time.substring(0, 5)} Uhr`;
        banner.hidden = false;
    } else {
        banner.hidden = true;
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
            const error = result.error;
            throw new Error(error || 'Login fehlgeschlagen');
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
        await loadClientSettings();
        await loadCheckinAppointments();
        //await loadHistory();
        await initTabs();
        await initYearNavigation();    
        // Anwesenheitsliste initialisieren
        //await initAttendanceList();

        debug.log("Showing main screen");        
        showScreen('main');
        startTicker();
        
    } catch (error) {
        debug.error('Login Fehler:', error);
        showError(error.message || 'Ungültige Anmeldedaten oder Token abgelaufen');
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
        startTicker();
        
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
            await loadClientSettings();
            await loadCheckinAppointments();
            //await loadHistory();
            await initTabs();
            await initYearNavigation();      
            // Anwesenheitsliste initialisieren
            //await initAttendanceList();  

            debug.log("Showing main screen");
                
            showScreen('main');
            startTicker();
            
        } catch (error) {
            debug.log('Auto-Login fehlgeschlagen:', error);
            // Token ungültig - zeige Login
            localStorage.removeItem('api_token');
            apiToken = null;
        }
    }
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

/**
 * Raeumt alles ab, was zum abgemeldeten Mitglied gehoert.
 *
 * Die PWA laeuft ohne Reload weiter: Ohne dieses Aufraeumen behaelt die
 * naechste Anmeldung auf demselben Geraet die Ansichten der vorigen. Verlauf,
 * Statistik und Anwesenheitsliste stehen gerendert da, und der zuletzt
 * geoeffnete Tab zeigt sie unveraendert weiter — fremde Daten, bis erst ein
 * Reload dazwischenkommt.
 */
function resetSessionState() {
    userData = null;

    appointments = [];
    appointmentTypes = [];
    checkinAppointments = [];
    deleteExceptionId = null;
    currentEditAppointmentId = null;
    currentStatsYear = new Date().getFullYear();

    worktimeSession = null;
    worktimeActivities = [];
    worktimeAppointments = [];
    renderWorktime();   // blendet laufende Sitzung und ihre Leiste aus

    ['historyList', 'attendanceListContent', 'groupsList',
     'worktimeStatsBody'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = '';
    });

    const statsContent = document.getElementById('statsContent');
    if (statsContent) statsContent.style.display = 'none';

    // Der Arbeitszeitblock entscheidet sich beim naechsten Laden neu. Bis
    // dahin bleibt er verborgen, statt die Stunden des Vorgaengers zu zeigen.
    const worktimeStats = document.getElementById('worktimeStats');
    if (worktimeStats) worktimeStats.style.display = 'none';

    // Auswahlfelder tragen die Termine und Taetigkeiten des vorigen Mitglieds.
    ['checkinAppointment', 'exceptionAppointment', 'attendanceAppointmentFilter',
     'worktimeActivity', 'worktimeAppointment'].forEach(id => {
        const select = document.getElementById(id);
        if (select) select.innerHTML = '';
    });

    // Zurueck auf den ersten Tab: initTabs() setzt beim naechsten Login nur die
    // Ereignisse, nicht die Auswahl — sonst startet sie dort, wo die vorige
    // Sitzung aufgehoert hat.
    document.querySelectorAll('.tab-button').forEach(b =>
        b.classList.toggle('active', b.dataset.tab === 'capture'));
    document.querySelectorAll('.tab-content').forEach(c =>
        c.classList.toggle('active', c.dataset.tab === 'capture'));
}

async function handleLogout() {
    await stopScannerIfRunning();
    await stopNFCReader();

    localStorage.removeItem('api_token');
    apiToken = null;

    resetSessionState();

    elements.loginForm.reset();
    elements.emailInput.value = '';
    elements.passwordInput.value = '';
    elements.loginError.classList.remove('active');

    // Offene Dialoge schliessen. Solange die Modals faelschlich IM mainScreen
    // lagen, verschwanden sie mit ihm; seit die Verschachtelung stimmt, sind
    // sie davon unabhaengig und stuenden sonst ueber dem Anmeldebildschirm.
    document.querySelectorAll('.modal.active, .pwa-confirm-modal.active')
        .forEach(m => m.classList.remove('active'));

    showScreen('login');
    stopTicker();

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

                    // Zeiterfassung initialisieren; blendet sich selbst aus,
                    // wenn das Feature nicht freigeschaltet ist
                    await initWorktime();

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
    
    // Die Sperre gegen mehrfaches Binden steckt seit 1.3.1 in bindOnce();
    // vorher stand sie hier siebenmal von Hand als dataset.listenerAdded.
    bindOnce(document.getElementById('attendanceAppointmentFilter'), 'change',
        loadAttendanceList);

    bindOnce(document.getElementById('btnRefreshAttendance'), 'click', async () => {
        await refreshAttendanceList();
    });

    bindOnce(document.getElementById('btnCreateAppointment'), 'click',
        showCreateAppointmentModal);

    bindOnce(document.getElementById('btnEditAppointment'), 'click',
        showEditAppointmentModal);

    const appointmentModal = document.getElementById('appointmentModal');

    bindOnce(document.getElementById('btnCancelAppointment'), 'click', () => {
        appointmentModal?.classList.remove('active');
    });

    // Klick neben den Dialog schliesst ihn
    bindOnce(appointmentModal, 'click', (e) => {
        if (e.target.id === 'appointmentModal') {
            appointmentModal.classList.remove('active');
        }
    });

    bindOnce(document.getElementById('appointmentForm'), 'submit', submitAppointmentForm);
}

/**
 * Speichert den Termin aus dem Dialog der Anwesenheitsliste.
 *
 * Steht als benannte Funktion neben initAttendanceList(), nicht als Arrow im
 * bindOnce()-Aufruf: Der Rumpf ist laenger als die uebrige Bindung zusammen
 * und verdeckte dort, was die Funktion sonst noch tut.
 */
async function submitAppointmentForm(e) {
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
        //
        // Fenster aus den Systemeinstellungen statt einer eigenen Zahl. Hier
        // stand bis 1.2.4 eine 6, waehrend der Server mit 2 rechnete.
        const parsedTolerance = parseInt(clientSettings.checkin_tolerance_hours, 10);
        const toleranceHours = Number.isNaN(parsedTolerance) ? 2 : parsedTolerance;
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
// MASTER-TICK — eine Quelle fuer Uhr und Arbeitszeit
// ========================================
// Ein gemeinsamer Tick fuer alle Sekundenanzeigen, ausgerichtet an der
// Systemsekunde: alles springt im selben Moment um, und zwar dann, wenn die
// Sekunde real wechselt. Zwei getrennte Intervalle liefen dagegen um bis zu
// einer Sekunde versetzt — je nachdem, wann sie gestartet wurden.
//
// setTimeout statt setInterval, weil setInterval kumulativ driftet: jede
// Verzoegerung des Callbacks addiert sich auf, die Anzeige laeuft mit der
// Zeit weg. Hier wird die Wartezeit vor jedem Tick neu aus der Systemuhr
// berechnet, ein Rueckstand kann sich also nicht ansammeln.
function startTicker() {
    stopTicker();
    tick();
}

function stopTicker() {
    if (tickTimer) {
        clearTimeout(tickTimer);
        tickTimer = null;
    }
}

function tick() {
    updateClock();

    if (worktimeSession && worktimeSession.is_running) {
        updateWorktimeElapsed();
    }

    // Bis zum naechsten Sekundenwechsel warten. Die 8 ms Zugabe fangen einen
    // minimal zu frueh feuernden Timer ab — ohne sie zeigte derselbe Wert
    // gelegentlich zweimal und die Anzeige stotterte.
    tickTimer = setTimeout(tick, 1000 - (Date.now() % 1000) + 8);
}

// Mobile Browser drosseln oder frieren Timer im Hintergrund ein. Beim
// Zurueckkehren wird deshalb neu ausgerichtet, damit die Anzeige nicht
// nachhinkt.
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && tickTimer) startTicker();
});

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

/**
 * Liefert einen eingelesenen Code an den Zweck der sichtbaren Ansicht.
 * Alle drei Eingabewege (QR, NFC, manuell) laufen hierdurch.
 *
 * Frueher entschied das eine Modulvariable, die der Zeiterfassung gehoerte,
 * sobald sie einen Code angefordert hatte. Sie war unsichtbar, hatte kein
 * Zeitlimit und ueberlebte den Tabwechsel: Wer den Start abbrach und danach
 * ganz normal einchecken wollte, startete mit seinem Scan versehentlich eine
 * Arbeitszeitsitzung. Der Zustand ist ersatzlos entfallen — der Zweck ergibt
 * sich daraus, welche Ansicht offen ist, und die sieht das Mitglied.
 */
async function deliverTotpCode(code, inputMethod) {
    if (isCaptureViewVisible('worktime')) {
        // Laeuft eine Sitzung, kann der Code nur ihr Ende belegen, sonst den
        // Start. Auch dieser Unterschied ist sichtbar: der Timer steht auf dem
        // Schirm oder das Startformular.
        const laeuft = document.getElementById('worktimeRunning');
        const istGestartet = laeuft && getComputedStyle(laeuft).display !== 'none';

        await (istGestartet ? worktimeStop(code) : worktimeStart(code));
        return;
    }

    await verifyCheckin(code, inputMethod);
}

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

        const chosenAppointment = document.getElementById('checkinAppointment')?.value;
        if (chosenAppointment) {
            requestData.appointment_id = parseInt(chosenAppointment, 10);
        }

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

            // Wer einen Termin erzeugt hat, soll das erfahren. Bis 1.2.3 stand
            // dort dieselbe Meldung wie bei einem Treffer.
            const text = data.appointment_action === 'created'
                ? `${methodText} eingecheckt — neuer Termin angelegt: `
                  + `${data.appointment.title}, ${String(data.appointment.start_time).substring(0, 5)} Uhr`
                : `${methodText} eingecheckt: ${data.appointment.title}`;

            showMessage(text, 'success');

            // Der neue Termin gehoert in die Auswahl, ohne dass jemand neu lädt.
            await loadCheckinAppointments();
        }
    } catch (error) {
        showMessage(error.message, 'error');
        debug.error(error.message);
    } finally {
        elements.scanButton.disabled = false;
    }
}

// ========================================
// UI STATE HELPER
// ========================================

/**
 * Beschriftet den Sucher mit dem Zweck des Scans.
 *
 * Der Text steht dauerhaft ueber dem Sucher — anders als eine Meldung, die
 * nach fuenf Sekunden verschwindet und den Nutzer im Unklaren laesst, wofuer
 * der naechste Scan zaehlt.
 *
 * Er wird beim Anzeigen des Suchers gesetzt und nirgends gespeichert: der
 * Zweck ergibt sich aus der Ansicht, in der der Scanner steht, nicht aus einer
 * Variablen, die einen Ansichtswechsel ueberlebt.
 */
function setScannerPurpose(text) {
    const el = document.getElementById('scannerPurpose');
    if (el) el.textContent = text || '';
}

/**
 * Wofuer der Sucher gerade laeuft — abgeleitet aus der sichtbaren Ansicht,
 * derselben Quelle, aus der auch deliverTotpCode() den Zweck nimmt. Anzeige
 * und Wirkung koennen so nicht auseinanderlaufen.
 */
function scannerPurposeText() {
    return isCaptureViewVisible('worktime')
        ? '⏱️ Zeiterfassung starten'
        : '📍 Anwesenheit erfassen';
}

/**
 * Blendet Abbrechen und manuelle Eingabe ein, solange der Sucher laeuft.
 *
 * Beides gehoert zum Scanner, nicht zu einer Absicht: Wer die Zeiterfassung
 * mit Ortsnachweis startet, landete frueher ohne Ausweg im Sucher — kein
 * Abbruch, keine Eingabe von Hand.
 */
function setScannerActionsVisible(visible) {
    const el = document.getElementById('scannerActions');
    if (el) el.style.display = visible ? 'flex' : 'none';
}

function setCheckinUIState(state) {
    currentUIState = state;

    switch(state) {
        case UI_STATE.IDLE:
            // Ruhezustand: Alle Optionen anzeigen
            elements.scannerContainer.style.display = 'none';
            setScannerActionsVisible(false);
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
            //
            setScannerPurpose(scannerPurposeText());
            elements.scannerContainer.style.display = 'block';
            setScannerActionsVisible(true);
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
    
    await deliverTotpCode(totpCode, 'QR');
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
        
    await deliverTotpCode(code, 'CODE');
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
        await deliverTotpCode(checkinCod, 'NFC');
    } 
}

// ========================================
// EXCEPTION
// ========================================

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

        // Arbeitszeiten nur abrufen, wenn das Mitglied ueberhaupt welche
        // erfassen darf — sonst antwortet die Ressource mit 404 und der
        // Abruf waere verschenkt.
        //
        // member_id muss mit: Ohne die Eingrenzung liefert die Ressource einem
        // Admin oder Manager die Sitzungen ALLER Mitglieder — im Dashboard so
        // gewollt, hier falsch. Der Verlauf ist die persoenliche Zeitachse
        // dieses Mitglieds, genau wie records und exceptions darueber.
        let sessions = [];
        if (worktimeActivities.length > 0 && userData.member_id) {
            const ws = await apiCall('work_sessions', 'GET', null, {
                member_id: userData.member_id
            });
            if (ws.success && Array.isArray(ws.data)) {
                sessions = ws.data;
            }
        }

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
            })),
            ...sessions.slice(0, 10).map(s => ({
                type: 'session',
                data: s,
                // Der Beginn, nicht das Ende: eine laufende Sitzung hat noch
                // kein Ende und faende sonst keinen Platz in der Zeitachse.
                timestamp: new Date(String(s.start_time).replace(' ', 'T'))
            }))
        ];

        combined.sort((a, b) => b.timestamp - a.timestamp);

        // Debug
        debug.log("Loading History", combined);

        // Zeige die letzten Einträge. Mit drei Quellen statt zwei waeren zehn
        // je Art zu knapp fuer einen brauchbaren Ueberblick.
        renderHistory(combined.slice(0, 20));
        
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
        } else if (item.type === 'session') {
            addWorkSessionToHistory(item.data);
        } else {
            addExceptionToHistory(item.data);
        }
    });
}

/**
 * Fuegt eine Arbeitszeitsitzung in die Zeitachse ein.
 *
 * Zweiter Nutzen neben der Uebersicht: Eine vergessene laufende Sitzung wird
 * hier sichtbar, auch wenn niemand die Arbeitszeit-Ansicht oeffnet.
 */
/**
 * Minuten als „3:45 h" — die Form, in der Vereine ueber Stunden sprechen.
 *
 * Dieselbe Regel wie formatMinutes() in public/js/modules/worktime.js. Die
 * PWA ist ein eigenstaendiges Skript ohne Zugriff auf dessen Module, deshalb
 * steht sie hier ein zweites Mal. Weichen die beiden je voneinander ab, zeigen
 * Dashboard und PWA fuer denselben Bestand verschiedene Stunden.
 */
function formatMinutes(minutes) {
    const m = parseInt(minutes, 10) || 0;
    const h = Math.floor(m / 60);
    return `${h}:${String(m % 60).padStart(2, '0')} h`;
}

/**
 * Fussnote unter der Jahressumme.
 *
 * Sie erklaert die Luecke zwischen dem, was das Mitglied erfasst hat, und dem,
 * was zaehlt: In die Summe gehen nur bestaetigte Sitzungen ein. Ohne diesen
 * Hinweis liest sich eine „0:00 h" nach einem frischen Nachtrag als Fehler.
 *
 * Leerer String heisst: keine Fussnote. Eine Zeile „nichts offen" waere
 * Rauschen fuer den Normalfall.
 */
function worktimeStatsNote(pendingMinutes, pendingCount, rejectedCount) {
    const teile = [];

    if (pendingCount > 0) {
        // Dativ nach „aus": im Plural „Eintraegen", im Singular „Eintrag".
        const eintraege = pendingCount === 1 ? '1 Eintrag' : `${pendingCount} Einträgen`;
        const verb      = pendingCount === 1 ? 'wartet' : 'warten';
        teile.push(`${formatMinutes(pendingMinutes)} aus ${eintraege} ${verb} auf Freigabe`);
    }

    if (rejectedCount > 0) {
        teile.push(rejectedCount === 1
            ? '1 Eintrag abgelehnt'
            : `${rejectedCount} Einträge abgelehnt`);
    }

    return teile.join(' · ');
}

/**
 * Farbe einer Taetigkeitsart aus der bereits geladenen Liste.
 *
 * Der worktime-Block der Statistik liefert im by_activity keine Farbe mit;
 * sie steht in worktimeActivities. Faellt die Taetigkeit dort heraus — etwa
 * weil das Mitglied die Gruppe inzwischen verlassen hat —, greift der
 * Standardton von activityDot().
 */
function activityColor(activityId) {
    const treffer = worktimeActivities.find(
        a => String(a.activity_id) === String(activityId));

    return treffer ? treffer.color : null;
}

/**
 * Farbpunkt einer Taetigkeitsart — dieselbe Auszeichnung wie im Dashboard,
 * damit dieselbe Tätigkeit in beiden Oberflaechen gleich aussieht.
 */
function activityDot(color) {
    return `<span class="activity-dot" style="background: ${escapeHtml(color || '#1F5FBF')}"></span>`;
}

function addWorkSessionToHistory(session) {
    const item = document.createElement('div');
    const laeuft = !session.end_time;

    item.className = session.status === 'confirmed'
        ? 'history-item verified'
        : 'history-item pending';

    const start = new Date(String(session.start_time).replace(' ', 'T'));
    const dateStr = start.toLocaleDateString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric'
    });
    const timeStr = start.toLocaleTimeString('de-DE', {
        hour: '2-digit', minute: '2-digit'
    });

    const dauer = laeuft
        ? 'läuft'
        : `${session.duration_minutes} Min.`
          + ((parseInt(session.break_minutes, 10) || 0) > 0
             ? ` · ${session.break_minutes} Min. Pause` : '');

    // Solange die Sitzung laeuft, sagt der Freigabestatus nichts: sie ist noch
    // nicht abgeschlossen. „läuft" allein ist die ehrlichere Auskunft.
    const status = laeuft
        ? ''
        : `<span class="status ${session.status === 'confirmed' ? 'verified' : 'pending'}">`
          + `${translateWorkSessionStatus(session.status)}</span>`;

    const note = session.note
        ? `<div class="history-note">${escapeHtml(session.note)}</div>` : '';

    // Nur beendete Sitzungen: Eine laufende wird gestoppt, nicht korrigiert.
    // Die Eigentuemerfrage stellt sich nicht — der Verlauf holt work_sessions
    // mit dem eigenen member_id und zeigt ausschliesslich eigene Sitzungen.
    const korrigieren = laeuft
        ? ''
        : `<button class="history-correct-btn"
                   onclick="openWorkSessionModal(${session.session_id})">✎ Korrigieren</button>`;

    worktimeHistorySessions[session.session_id] = session;

    item.innerHTML = `
        <div class="time">⏱️ ${dateStr} ${timeStr} ${korrigieren}</div>
        <div class="appointment">${activityDot(session.color)}${escapeHtml(session.activity_name || 'Tätigkeit')}</div>
        <div class="history-duration">${escapeHtml(dauer)}</div>
        ${note}
        ${status}
    `;

    elements.historyList.appendChild(item);
}

// ========================================
// ARBEITSZEIT KORRIGIEREN UND NACHTRAGEN
// ========================================

/** Datenbankzeit „2026-09-01 08:00:00" → Feldwert „2026-09-01T08:00". */
function toDateTimeLocal(value) {
    return String(value || '').replace(' ', 'T').substring(0, 16);
}

/** Feldwert „2026-09-01T08:00" → „2026-09-01 08:00" fuer den Server. */
function fromDateTimeLocal(value) {
    return String(value || '').replace('T', ' ');
}

/**
 * Fuellt die Taetigkeitsauswahl des Modals.
 *
 * Eine Sitzung kann zu einer Taetigkeit gehoeren, die das Mitglied heute nicht
 * mehr waehlen darf — etwa nach einem Gruppenwechsel. Fehlt sie in der Liste,
 * wird sie ergaenzt: Sonst spraenge die Auswahl still auf eine fremde
 * Taetigkeit, und das Speichern schriebe eine falsche.
 */
function fillWorkSessionActivities(session) {
    const select = document.getElementById('workSessionActivity');
    if (!select) return;

    const liste = worktimeActivities.slice();

    if (session && session.activity_id
        && !liste.some(a => String(a.activity_id) === String(session.activity_id))) {
        liste.unshift({
            activity_id:   session.activity_id,
            activity_name: session.activity_name || 'Tätigkeit'
        });
    }

    select.innerHTML = liste
        .map(a => `<option value="${a.activity_id}">${escapeHtml(a.activity_name)}</option>`)
        .join('');

    if (session && session.activity_id) {
        select.value = String(session.activity_id);
    }
}

/**
 * Fuellt die Terminauswahl des Modals passend zur gewaehlten Taetigkeit.
 *
 * Beim Oeffnen wird die Sitzung mitgegeben: Fehlt ihr Termin in der
 * gefilterten Liste, wird er ergaenzt und vorgewaehlt. Sonst stuende die
 * Auswahl still auf „kein Termin", und das Speichern loeste eine Zuordnung,
 * die niemand loesen wollte.
 *
 * Beim Wechsel der Taetigkeit passiert das bewusst NICHT: Dort hat das
 * Mitglied selbst gehandelt, und ein Termin, der zur neuen Taetigkeit nicht
 * passt, gehoert auch nicht mehr dazu.
 */
function fillWorkSessionAppointments(previous = '', session = null) {
    const select = document.getElementById('workSessionAppointment');
    if (!select) return;

    const options = worktimeAppointmentsFor(document.getElementById('workSessionActivity')?.value);

    if (session && previous
        && !options.some(a => String(a.appointment_id) === String(previous))) {
        options.unshift({
            appointment_id: previous,
            title:          session.appointment_title || 'Termin',
            date:           session.appointment_date || '',
            start_time:     ''
        });
    }

    select.innerHTML = worktimeAppointmentOptionsHtml(options);

    if (previous && options.some(a => String(a.appointment_id) === String(previous))) {
        select.value = String(previous);
    }
}

/**
 * Oeffnet das Modal.
 *
 * Ohne sessionId ist es ein Nachtrag (POST), mit sessionId eine Korrektur
 * (PUT). Die Werte kommen aus dem bereits geladenen Verlauf — ein zweiter
 * Abruf waere verschenkt.
 */
function openWorkSessionModal(sessionId = null) {
    const modal = document.getElementById('workSessionModal');
    if (!modal) return;

    const session = sessionId ? worktimeHistorySessions[sessionId] : null;

    if (sessionId && !session) {
        showMessage('Der Eintrag ist nicht mehr geladen. Bitte den Verlauf neu öffnen.', 'error');
        return;
    }

    document.getElementById('workSessionId').value = sessionId || '';
    document.getElementById('workSessionModalTitle').textContent =
        session ? 'Zeit korrigieren' : 'Zeit nachtragen';

    fillWorkSessionActivities(session);

    document.getElementById('workSessionStart').value =
        session ? toDateTimeLocal(session.start_time) : '';
    document.getElementById('workSessionEnd').value =
        session ? toDateTimeLocal(session.end_time) : '';
    document.getElementById('workSessionBreak').value =
        session ? (parseInt(session.break_minutes, 10) || 0) : 0;
    document.getElementById('workSessionNote').value = session ? (session.note || '') : '';

    fillWorkSessionAppointments(session ? session.appointment_id : '', session);

    // Der Hinweis nennt die Folge vor dem Speichern, nicht danach. Der Zusatz
    // zum Ortsnachweis nur, wenn es einen zu verlieren gibt.
    const hatNachweis = !!(session
        && (session.start_location_name || session.end_location_name));

    document.getElementById('workSessionHint').textContent = hatNachweis
        ? 'Die Änderung geht erneut in die Freigabe. Wird Beginn oder Ende geändert, '
          + 'entfällt der Ortsnachweis dieser Zeit.'
        : 'Erfasste Zeiten gelten erst nach Freigabe durch einen Manager.';

    showWorkSessionErrors([]);
    modal.classList.add('active');
}

function closeWorkSessionModal() {
    const modal = document.getElementById('workSessionModal');
    if (modal) modal.classList.remove('active');
}

/** Zeigt die Meldungen des Servers; eine leere Liste blendet den Kasten aus. */
function showWorkSessionErrors(messages) {
    const box = document.getElementById('workSessionErrors');
    if (!box) return;

    if (!messages || messages.length === 0) {
        box.hidden = true;
        box.innerHTML = '';
        return;
    }

    box.hidden = false;
    box.innerHTML = '<ul>'
        + messages.map(m => `<li>${escapeHtml(m)}</li>`).join('')
        + '</ul>';
}

/**
 * Speichert Nachtrag oder Korrektur.
 *
 * Der Server ist die pruefende Instanz: Ende vor Beginn, Zeiten in der
 * Zukunft, zu lange Pause und die Notizpflicht entscheidet er, nicht dieses
 * Formular. Seine Meldungen stehen in errors[] und werden gezeigt — „Validation
 * failed" allein waere fuer ein Mitglied wertlos.
 */
async function saveWorkSession() {
    const id      = document.getElementById('workSessionId').value;
    const termin  = document.getElementById('workSessionAppointment').value;

    const body = {
        activity_id:    parseInt(document.getElementById('workSessionActivity').value, 10),
        start_time:     fromDateTimeLocal(document.getElementById('workSessionStart').value),
        end_time:       fromDateTimeLocal(document.getElementById('workSessionEnd').value),
        break_minutes:  parseInt(document.getElementById('workSessionBreak').value, 10) || 0,
        note:           document.getElementById('workSessionNote').value.trim(),
        // Leer heisst hier bewusst „Zuordnung loesen": Das Formular zeigt den
        // aktuellen Stand, also ist die fehlende Auswahl eine Aussage.
        appointment_id: termin ? parseInt(termin, 10) : null
    };

    const result = id
        ? await apiCall('work_sessions', 'PUT', body, { id: id })
        : await apiCall('work_sessions', 'POST', body);

    if (!result.success) {
        const meldungen = Array.isArray(result.data?.errors)
            ? result.data.errors
            : [result.error];

        showWorkSessionErrors(meldungen);
        return;
    }

    closeWorkSessionModal();
    showMessage(id ? 'Korrektur gespeichert — wartet auf Freigabe'
                   : 'Zeit nachgetragen — wartet auf Freigabe', 'success');

    await loadHistory();
    await loadWorktimeState();
}

window.openWorkSessionModal = openWorkSessionModal;

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
// ZEITERFASSUNG
// ========================================

/** Minimales HTML-Escaping fuer Werte aus der Datenbank. */
function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

let worktimeSession = null;

// Die Sitzungen, die der Verlauf gerade zeigt — nach session_id. Das
// Korrekturmodal fuellt seine Felder daraus, statt sie erneut zu holen.
let worktimeHistorySessions = {};
let worktimeActivities = [];

// Termine des laufenden Jahres. Gehalten, damit ein Wechsel der Taetigkeitsart
// die Auswahl neu aufbauen kann, ohne erneut zu laden.
let worktimeAppointments = [];

/**
 * Prueft, ob die Zeiterfassung freigeschaltet ist, und blendet den Tab ein.
 * Ist das Feature aus, antwortet die Ressource mit 404 — dann bleibt der
 * Tab verborgen und nichts weiter passiert.
 */
async function initWorktime() {
    const result = await apiCall('activity_types', 'GET');

    if (!result.success) {
        debug.log('Zeiterfassung nicht verfuegbar:', result.error);
        return;
    }

    // Es gibt keinen eigenen Zeit-Tab mehr, den man einblenden koennte: ob die
    // Arbeitszeit ueberhaupt zur Wahl steht, entscheidet availableIntents()
    // anhand genau dieser Liste.
    worktimeActivities = result.data || [];

    const select = document.getElementById('worktimeActivity');
    if (select) {
        // Das Schloss steht schon in der aufgeklappten Liste: die Nachweispflicht
        // ist bei der Wahl zu sehen, nicht erst beim gescheiterten Start.
        select.innerHTML = worktimeActivities
            .map(a => {
                const lock = (a.verification && a.verification !== 'none') ? '🔒 ' : '';
                return `<option value="${a.activity_id}">${lock}${escapeHtml(a.activity_name)}</option>`;
            })
            .join('');

        bindOnce(select, 'change', () => {
            renderWorktimeActivityHint();
            // Die Terminarten der neuen Taetigkeit grenzen die Terminliste
            // anders ein; die getroffene Auswahl bleibt erhalten, solange sie
            // noch passt.
            renderWorktimeAppointmentOptions(
                document.getElementById('worktimeAppointment')?.value || '');
        });
        renderWorktimeActivityHint();
    }

    // bindOnce, weil initWorktime() bei jeder Anmeldung erneut laeuft.
    bindOnce(document.getElementById('worktimeManualBtn'), 'click',
             () => openWorkSessionModal());
    bindOnce(document.getElementById('closeWorkSessionBtn'), 'click', closeWorkSessionModal);
    bindOnce(document.getElementById('saveWorkSessionBtn'), 'click', saveWorkSession);
    bindOnce(document.getElementById('workSessionActivity'), 'change',
             () => fillWorkSessionAppointments(
                 document.getElementById('workSessionAppointment')?.value || ''));

    bindOnce(document.getElementById('worktimeAppointment'), 'change',
        renderWorktimeAppointmentHint);

    // Ohne Wrapper bekaeme worktimeStart das Event-Objekt als totpCode
    bindOnce(document.getElementById('worktimeStartBtn'), 'click', () => worktimeStart());
    bindOnce(document.getElementById('worktimePauseBtn'), 'click', worktimeTogglePause);
    bindOnce(document.getElementById('worktimeStopBtn'), 'click', () => worktimeStop());
    bindOnce(document.getElementById('worktimeStopForceBtn'), 'click', () => {
        // Das eigene Modal der App statt confirm(): blockierende Browserdialoge
        // werden in einer installierten PWA teils unterdrueckt — genau daran
        // ist der urspruengliche prompt() fuer die Notiz gescheitert.
        showNavigationConfirm(
            'Ohne Ortsnachweis beenden?',
            'Der Eintrag zählt dann nicht sofort, sondern muss von einem Manager '
            + 'freigegeben werden.',
            () => worktimeStop(null, true)
        );
    });

    // Laeuft gerade eine Sitzung? Diese eine Frage wird beim Start gestellt,
    // damit die Leiste sofort steht. Die uebrigen Abrufe von
    // loadWorktimeState() — Termine und erfasste Zeiten — kann sich der Start
    // sparen, sie werden erst beim Oeffnen der Ansicht gebraucht.
    await loadRunningSession();
}

/**
 * Holt nur die laufende Sitzung und aktualisiert die Leiste.
 *
 * Ohne diesen Abruf beim Start erfuhr niemand von einer laufenden Sitzung,
 * der nicht zufaellig die Arbeitszeit-Ansicht oeffnete — eine vergessene
 * Sitzung lief so bis zur Obergrenze weiter.
 */
async function loadRunningSession() {
    const result = await apiCall('work_sessions', 'GET', null, { running: 1 });
    worktimeSession = result.success ? result.data : null;

    renderRunningBar();

    if (worktimeSession && worktimeSession.is_running) {
        updateWorktimeElapsed();
    }
}

/** Holt den Zustand IMMER vom Server — nie aus dem Browser-Speicher. */
async function loadWorktimeState() {
    const result = await apiCall('work_sessions', 'GET', null, { running: 1 });
    worktimeSession = result.success ? result.data : null;

    renderWorktime();

    // Nur im Leerlauf sichtbar — waehrend einer laufenden Sitzung ist die
    // Auswahl ausgeblendet und ein Abruf waere verschenkt.
    if (!(worktimeSession && worktimeSession.is_running)) {
        await loadWorktimeAppointments();
    }
}

function renderWorktime() {
    const idle = document.getElementById('worktimeIdle');
    const running = document.getElementById('worktimeRunning');
    if (!idle || !running) return;

    if (worktimeSession && worktimeSession.is_running) {
        idle.style.display = 'none';
        running.style.display = '';

        document.getElementById('worktimeActivityName').innerHTML =
            activityDot(worktimeSession.color)
            + escapeHtml(worktimeSession.activity_name || 'Tätigkeit');

        const pauseBtn = document.getElementById('worktimePauseBtn');
        pauseBtn.querySelector('span:last-child').textContent =
            worktimeSession.is_paused ? 'Weiter' : 'Pause';
        pauseBtn.querySelector('.icon').textContent =
            worktimeSession.is_paused ? '▶️' : '⏸️';

        const timer = document.querySelector('.worktime-timer');
        if (timer) timer.classList.toggle('paused', !!worktimeSession.is_paused);

        // Der Ausweg erscheint nur, wenn zum Beenden ein Nachweis verlangt wird
        const forceBtn = document.getElementById('worktimeStopForceBtn');
        if (forceBtn) {
            forceBtn.style.display =
                worktimeSession.verification === 'start_end' ? '' : 'none';
        }

        const breakMinutes = parseInt(worktimeSession.break_minutes, 10) || 0;
        const breakInfo = document.getElementById('worktimeBreakInfo');

        if (worktimeSession.is_paused) {
            breakInfo.textContent = breakMinutes > 0
                ? `⏸ Pausiert · bisher ${breakMinutes} Min. Pause`
                : '⏸ Pausiert';
        } else {
            breakInfo.textContent = breakMinutes > 0
                ? `Pause bisher: ${breakMinutes} Min.` : '';
        }

        updateWorktimeElapsed();
    } else {
        idle.style.display = '';
        running.style.display = 'none';
    }

    renderRunningBar();
}

/**
 * Die Leiste ueber allen Tabs. Folgt demselben worktimeSession wie die
 * Ansicht, damit beide nicht auseinanderlaufen koennen.
 */
function renderRunningBar() {
    const bar = document.getElementById('runningSessionBar');
    if (!bar) return;

    const laeuft = !!(worktimeSession && worktimeSession.is_running);
    bar.hidden = !laeuft;

    if (!laeuft) return;

    const name = document.getElementById('runningSessionActivity');
    if (name) {
        name.innerHTML = (worktimeSession.is_paused ? '⏸ ' : '⏱️ ')
            + activityDot(worktimeSession.color)
            + escapeHtml(worktimeSession.activity_name || 'Tätigkeit');
    }
}

function updateWorktimeElapsed() {
    if (!worktimeSession || !worktimeSession.is_running) return;

    // Angezeigt wird die NETTO-Zeit: brutto minus bereits gezaehlter Pausen
    // minus der gerade laufenden Pause. Waehrend einer Pause heben sich die
    // beiden letzten Terme gegen die verstreichende Zeit auf, die Anzeige
    // steht also still — sonst zaehlte sie etwas hoch, das nicht erfasst wird.
    const start = new Date(String(worktimeSession.start_time).replace(' ', 'T'));
    let seconds = Math.floor((Date.now() - start.getTime()) / 1000);

    seconds -= (parseInt(worktimeSession.break_minutes, 10) || 0) * 60;

    if (worktimeSession.is_paused && worktimeSession.break_started_at) {
        const pauseStart = new Date(String(worktimeSession.break_started_at).replace(' ', 'T'));
        seconds -= Math.floor((Date.now() - pauseStart.getTime()) / 1000);
    }

    seconds = Math.max(0, seconds);

    const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
    const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
    const sec = String(seconds % 60).padStart(2, '0');

    const zeit = `${h}:${m}:${sec}`;

    const el = document.getElementById('worktimeElapsed');
    if (el) el.textContent = zeit;

    // Dieselbe Zeit in der Leiste: ein Ticker, zwei Anzeigen — zwei Ticker
    // wuerden mit der Zeit auseinanderlaufen.
    const bar = document.getElementById('runningSessionElapsed');
    if (bar) bar.textContent = zeit;
}

function showWorktimeStatus(message, isError = false) {
    const el = document.getElementById('worktimeStatus');
    if (!el) return;
    el.textContent = message;
    el.style.color = isError ? '#c62828' : '';
    setTimeout(() => { el.textContent = ''; }, 4000);
}

/** Nachweispflicht der aktuell gewaehlten Taetigkeitsart. */
function selectedActivityVerification() {
    const id = document.getElementById('worktimeActivity')?.value;
    const activity = worktimeActivities.find(a => String(a.activity_id) === String(id));

    return activity ? (activity.verification || 'none') : 'none';
}

/**
 * Was die Nachweispflicht konkret verlangt. 'start' und 'start_end'
 * unterscheiden sich fuer das Mitglied spuerbar: einmal scannen oder zweimal.
 */
const PROOF_HINTS = {
    start: 'Für diese Tätigkeit ist beim Start ein QR-Code der Station nötig.',
    start_end: 'Für diese Tätigkeit ist beim Start und beim Beenden ein QR-Code '
        + 'der Station nötig.'
};

function renderWorktimeActivityHint() {
    const hint = document.getElementById('worktimeActivityHint');
    if (!hint) return;

    const text = PROOF_HINTS[selectedActivityVerification()];

    hint.textContent = text ? `🔒 ${text}` : '';
    hint.hidden = !text;
}

function renderWorktimeAppointmentHint() {
    const hint = document.getElementById('worktimeAppointmentHint');
    const select = document.getElementById('worktimeAppointment');
    if (!hint || !select) return;

    // Bis 1.2.2 erzeugte der Start zugleich einen Anwesenheitseintrag. Das ist
    // entfallen: Arbeit fuer einen Termin ist keine Anwesenheit bei ihm. Der
    // Hinweis sagt das ausdruecklich, weil die alte Kopplung fuer Mitglieder
    // sichtbar war und ihr Wegfall sonst wie ein Fehler wirkt.
    hint.textContent = select.value
        ? 'Die Stunden werden diesem Termin zugerechnet. Ein Check-in entsteht dadurch '
          + 'nicht — dafür ist die Anwesenheitserfassung da.'
        : '';
    hint.hidden = !select.value;
}

async function worktimeStart(totpCode = null) {
    const activityId = document.getElementById('worktimeActivity')?.value;
    if (!activityId) {
        showWorktimeStatus('Bitte eine Tätigkeit wählen', true);
        return;
    }

    // Verlangt die Taetigkeitsart einen Ortsnachweis, wird zuerst ein Code
    // geholt. Der Server prueft das ohnehin erneut — das hier erspart dem
    // Mitglied nur den Fehlschlag.
    //
    // Der Sucher geht HIER auf, in der Arbeitszeit-Ansicht. Frueher wurde das
    // Mitglied in den Check-in-Tab geschickt und dort der Zweck des naechsten
    // Scans heimlich umgebogen — daher die Verwechslungen.
    if (!totpCode && selectedActivityVerification() !== 'none') {
        toggleScanner();
        return;
    }

    const body = { action: 'start', activity_id: parseInt(activityId, 10) };

    const appointmentId = document.getElementById('worktimeAppointment')?.value;
    if (appointmentId) body.appointment_id = parseInt(appointmentId, 10);
    if (totpCode) body.totp_code = totpCode;

    const result = await apiCall('work_sessions', 'POST', body);

    if (!result.success) {
        showWorktimeStatus('Start fehlgeschlagen: ' + result.error, true);
        await loadWorktimeState();
        return;
    }

    worktimeSession = result.data.session;

    // Kein Tabwechsel mehr noetig: der Scan fand in dieser Ansicht statt.
    renderWorktime();

    showWorktimeStatus(worktimeSession.start_location_name
        ? `Gestartet · Ort belegt: ${worktimeSession.start_location_name}`
        : 'Zeiterfassung gestartet');
}

async function worktimeTogglePause() {
    const action = worktimeSession && worktimeSession.is_paused ? 'resume' : 'pause';
    const result = await apiCall('work_sessions', 'POST', { action });

    if (!result.success) {
        showWorktimeStatus('Aktion fehlgeschlagen: ' + result.error, true);
        await loadWorktimeState();
        return;
    }

    worktimeSession = result.data.session;
    renderWorktime();
}

async function worktimeStop(totpCode = null, force = false) {
    // Bewusst ein Eingabefeld statt prompt(): ein blockierender Dialog wird in
    // einer installierten PWA teils unterdrueckt und ist auf Mobilgeraeten
    // unbrauchbar.
    const noteField = document.getElementById('worktimeNote');
    const note = noteField ? noteField.value.trim() : '';

    // Verlangt die laufende Sitzung einen Nachweis zum Beenden, wird zuerst
    // ein Code geholt — es sei denn, das Mitglied hat bewusst ohne gewaehlt.
    if (!totpCode && !force && worktimeSession && worktimeSession.verification === 'start_end') {
        toggleScanner();
        return;
    }

    const body = { action: 'stop', note };
    if (totpCode) body.totp_code = totpCode;
    if (force) body.force = true;

    const result = await apiCall('work_sessions', 'POST', body);

    if (!result.success) {
        showWorktimeStatus('Stoppen fehlgeschlagen: ' + result.error, true);
        await loadWorktimeState();
        return;
    }

    if (noteField) noteField.value = '';

    const session = result.data.session;
    const minutes = session.duration_minutes;
    const zeit = `${minutes} ${minutes === 1 ? 'Minute' : 'Minuten'}`;

    showWorktimeStatus(session.status === 'submitted'
        ? `Beendet ohne Nachweis: ${zeit} — wartet auf Freigabe`
        : `Beendet: ${zeit} erfasst`);

    worktimeSession = null;
    renderWorktime();
}
/**
 * Termine des heutigen Tages in die optionale Auswahl fuellen.
 *
 * Holt bewusst selbst vom Server, statt die globale Liste `appointments`
 * mitzubenutzen: die wird je nach zuletzt besuchtem Tab mit einem anderen
 * Zeitraum und einem anderen Mitgliedsfilter ueberschrieben.
 *
 * Das Tagesdatum wird lokal gebildet — `toISOString()` liefert UTC und haette
 * abends (MESZ ab 22:00) bereits den Folgetag geliefert, genau dann also,
 * wenn Vereinsarbeit stattfindet.
 */
async function loadWorktimeAppointments() {
    const select = document.getElementById('worktimeAppointment');
    if (!select) return;

    const previous = select.value;

    // Alle Termine des laufenden Jahres, nicht nur die heutigen.
    //
    // Bis 1.2.2 fragte diese Stelle from_date = to_date = heute ab. Das war
    // die falsche Einschraenkung: Der Terminbezug dient der Aufwandsbetrachtung,
    // und Vorbereitung findet vor der Veranstaltung statt, Nachbereitung danach.
    // Genau diese Stunden zeigt der Bericht "nach Termin" — und genau sie
    // liessen sich nicht zuordnen.
    let appointments = [];

    if (userData && userData.member_id) {
        const result = await apiCall('appointments', 'GET', null, {
            member_id: userData.member_id,
            year: new Date().getFullYear()
        });

        if (result.success && Array.isArray(result.data)) {
            appointments = result.data;
        } else {
            debug.error('Termine fuer die Zeiterfassung nicht ladbar:', result.error);
        }
    }

    worktimeAppointments = appointments;
    renderWorktimeAppointmentOptions(previous);
}

/**
 * Termine, die zu einer Taetigkeit passen — gefiltert nach ihren Terminarten,
 * naechstgelegene zuerst.
 *
 * Steht getrennt vom Auswahlfeld, weil zwei Stellen dieselbe Liste brauchen:
 * die Idle-Ansicht des Erfassen-Tabs und das Korrekturmodal.
 *
 * Die Eingrenzung ist leer, wenn die Taetigkeitsart keine Terminarten nennt —
 * das bedeutet "keine Einschraenkung", nicht "keine Termine".
 */
function worktimeAppointmentsFor(activityId) {
    const activity = worktimeActivities.find(a => String(a.activity_id) === String(activityId));
    const allowed  = (activity?.appointment_type_ids || []).map(Number);

    let options = worktimeAppointments.slice();

    if (allowed.length) {
        options = options.filter(a => allowed.includes(Number(a.type_id)));
    }

    // Naechstgelegene zuerst: Arbeit wird einem Termin in ihrer Naehe
    // zugeordnet, nicht dem ersten des Jahres.
    const now = Date.now();
    options.sort((a, b) =>
        Math.abs(new Date(String(a.date)).getTime() - now)
        - Math.abs(new Date(String(b.date)).getTime() - now));

    return options;
}

/** Baut die Optionen eines Terminfeldes aus einer fertigen Liste. */
function worktimeAppointmentOptionsHtml(options) {
    return '<option value="">— kein Termin —</option>'
        + options.map(a => {
            const datum = formatDateShortDe(a.date);
            const zeit  = String(a.start_time || '').substring(0, 5);
            return `<option value="${a.appointment_id}">`
                 + `${datum} ${escapeHtml(a.title)}${zeit ? ` (${zeit})` : ''}</option>`;
        }).join('');
}

function renderWorktimeAppointmentOptions(previous = '') {
    const select = document.getElementById('worktimeAppointment');
    if (!select) return;

    const options = worktimeAppointmentsFor(document.getElementById('worktimeActivity')?.value);

    select.innerHTML = worktimeAppointmentOptionsHtml(options);

    // Auswahl ueberlebt ein Neuladen, solange der Termin noch in der Liste steht
    if (previous && options.some(a => String(a.appointment_id) === previous)) {
        select.value = previous;
    }

    // Ein programmatisch gesetztes value loest kein change aus
    renderWorktimeAppointmentHint();
}

/** Datum als TT.MM., ohne Zeitzonenversatz — die PWA hat wenig Breite. */
function formatDateShortDe(isoDateString) {
    const parts = String(isoDateString).slice(0, 10).split('-');
    return parts.length === 3 ? `${parts[2]}.${parts[1]}.` : '';
}

// ========================================
// ERFASSEN-TAB: ABSICHT VOR WERKZEUG
// ========================================

// Die drei Ansichten des Erfassen-Tabs und ihre Container.
const CAPTURE_VIEWS = {
    chooser:    'captureChooser',
    attendance: 'captureAttendance',
    worktime:   'captureWorktime'
};

/** Ist diese Ansicht gerade sichtbar? Einzige Quelle fuer den Zweck eines Scans. */
function isCaptureViewVisible(view) {
    const el = document.getElementById(CAPTURE_VIEWS[view]);
    return !!el && !el.hidden;
}

/**
 * Welche Absichten stehen diesem Mitglied offen?
 *
 * Anwesenheit immer. Arbeitszeit nur, wenn das Feature freigeschaltet ist UND
 * mindestens eine Taetigkeitsart in den eigenen Gruppen liegt — genau das
 * steht nach initWorktime() in worktimeActivities.
 */
function availableIntents() {
    const intents = ['attendance'];

    if (worktimeActivities.length > 0) {
        intents.push('worktime');
    }

    return intents;
}

/**
 * Zeigt eine Ansicht des Erfassen-Tabs.
 *
 * Beim Verlassen der Anwesenheit werden Scanner und NFC gestoppt: ein
 * weiterlaufender Sucher in einer unsichtbaren Ansicht wuerde Kamera und Akku
 * beanspruchen und koennte einen Code entgegennehmen, den niemand erwartet.
 */
function showCaptureView(view) {
    Object.entries(CAPTURE_VIEWS).forEach(([name, id]) => {
        const el = document.getElementById(id);
        if (el) el.hidden = (name !== view);
    });

    if (view !== 'attendance') {
        stopScannerIfRunning();
        stopNFCReader();
    }

    if (view === 'worktime') {
        loadWorktimeState();
    }

    if (view === 'attendance') {
        // Die Liste wird nicht neu geladen — nur der Banner an die aktuelle
        // Auswahl und das fortgeschrittene "jetzt" angeglichen.
        renderCheckinSuggestion();
    }
}

/**
 * Einstieg in den Erfassen-Tab: fragt nur, wenn es etwas zu fragen gibt.
 *
 * Wer nur eine Absicht hat — der Regelfall nach der Gruppenbindung — landet
 * direkt beim Werkzeug und zahlt keinen zusaetzlichen Klick.
 */
function enterCaptureTab() {
    const intents = availableIntents();

    showCaptureView(intents.length > 1 ? 'chooser' : intents[0]);
}

function initCaptureTab() {
    document.querySelectorAll('.capture-tile').forEach(tile => {
        bindOnce(tile, 'click', () => showCaptureView(tile.dataset.intent));
    });

    // Die Leiste ist der Weg zurueck zur laufenden Sitzung, aus jedem Tab.
    bindOnce(document.getElementById('runningSessionBar'), 'click', () => {
        document.querySelector('.tab-button[data-tab="capture"]')?.click();
        showCaptureView('worktime');
    });

    bindOnce(document.getElementById('checkinAppointment'), 'change', renderCheckinSuggestion);

    enterCaptureTab();
}

// ========================================
// TAB MANAGEMENT
// ========================================
function initTabs() {
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');

    // Der Erfassen-Tab ist beim Start offen. initWorktime() lief in
    // loadUserData() bereits durch, worktimeActivities ist also gefuellt und
    // availableIntents() liefert die richtige Antwort.
    initCaptureTab();

    tabButtons.forEach(button => {
        bindOnce(button, 'click', () => {
            const targetTab = button.dataset.tab;
            
            // Deaktiviere alle
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Aktiviere gewählten Tab
            button.classList.add('active');
            document.querySelector(`.tab-content[data-tab="${targetTab}"]`).classList.add('active');
            
            // Scanner stoppen, sobald der Erfassen-Tab verlassen wird.
            // Aufraeumen einer Code-Umleitung entfaellt: es gibt keine mehr.
            if (targetTab !== 'capture') {
                stopScannerIfRunning();
                stopNFCReader();
            }

            // Lade Daten wenn nötig
            if (targetTab === 'capture') {
                debug.log("Entering Capture");
                enterCaptureTab();
            }
            else if (targetTab === 'stats') {
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
        
        // Die Arbeitszeit kommt aus zwei Quellen: die bestaetigte Jahressumme
        // aus derselben Funktion, die auch den Verwendungsnachweis rechnet
        // (statistics?include=worktime), die Fussnote aus den Rohzeilen. Beide
        // nur, wenn das Mitglied ueberhaupt Zeiten erfassen darf — dieselbe
        // Bedingung, an der schon der Verlauf haengt.
        const zeigtArbeitszeit = worktimeActivities.length > 0;

        const params = {
            member_id: userData.member_id,
            year: currentStatsYear
        };
        if (zeigtArbeitszeit) {
            params.include = 'worktime';
        }

        const zeitAbruf = zeigtArbeitszeit
            ? apiCall('work_sessions', 'GET', null, {
                  member_id: userData.member_id,
                  year: currentStatsYear
              })
            : Promise.resolve(null);

        // Parallel, damit der Tab nicht zweimal nacheinander wartet.
        const [result, sessions] = await Promise.all([
            apiCall('statistics', 'GET', null, params),
            zeitAbruf
        ]);

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

        // Der Arbeitszeitblock haengt an einem zweiten Abruf. Scheitert der,
        // bleibt die Hauptzahl stehen und nur die Fussnote fehlt — ein
        // Nebenabruf darf die Seite nicht kippen.
        displayWorktimeStats(
            zeigtArbeitszeit ? (stats.worktime || null) : null,
            (sessions && sessions.success && Array.isArray(sessions.data))
                ? sessions.data
                : []
        );

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

/**
 * Zeigt die Arbeitszeit des Jahres.
 *
 * Die grosse Zahl ist die bestaetigte Summe — dieselbe, die in den
 * Verwendungsnachweis eingeht. Die Fussnote darunter erklaert, was noch nicht
 * mitzaehlt. Beides zusammen beantwortet „wie viel habe ich geleistet" ohne
 * die Frage offen zu lassen, wo die fehlenden Stunden geblieben sind.
 *
 * @param worktime  Block aus statistics?include=worktime, oder null, wenn das
 *                  Mitglied gar keine Zeiten erfassen darf
 * @param sessions  Rohzeilen aus work_sessions fuer dasselbe Jahr
 */
function displayWorktimeStats(worktime, sessions) {
    const box  = document.getElementById('worktimeStats');
    const body = document.getElementById('worktimeStatsBody');
    if (!box || !body) return;

    // Kein Block fuer Mitglieder ohne Zeiterfassung: Ein dauerhaftes „0:00 h"
    // waere fuer sie nur Rauschen.
    if (!worktime) {
        box.style.display = 'none';
        body.innerHTML = '';
        return;
    }

    const summary   = worktime.summary || {};
    const minuten   = parseInt(summary.total_minutes, 10) || 0;
    const sitzungen = parseInt(summary.sessions, 10) || 0;

    // Dieselbe Statusaufteilung wie updateWorktimeStats() im Dashboard:
    // bestaetigt zaehlt, eingereicht wartet, abgelehnt zaehlt nie.
    const wartend = sessions.filter(s => s.status === 'submitted' && s.end_time);
    const wartendeMinuten = wartend.reduce(
        (summe, s) => summe + (parseInt(s.duration_minutes, 10) || 0), 0);
    const abgelehnt = sessions.filter(s => s.status === 'rejected').length;

    box.style.display = 'block';

    // Ein Jahr ganz ohne Sitzungen bekommt eine ruhige Zeile statt einer Null
    // ueber einer leeren Liste.
    if (minuten === 0 && wartend.length === 0 && abgelehnt === 0) {
        body.innerHTML =
            `<div class="worktime-empty">Keine Stunden in ${currentStatsYear}</div>`;
        return;
    }

    const note = worktimeStatsNote(wartendeMinuten, wartend.length, abgelehnt);

    // Bei gesetztem member_id enthaelt members hoechstens einen Eintrag; leer
    // ist es genau dann, wenn im Jahr nichts bestaetigt wurde.
    const aktivitaeten = ((worktime.members || [])[0] || {}).by_activity || [];

    const zeilen = [...aktivitaeten]
        .sort((a, b) => (parseInt(b.minutes, 10) || 0) - (parseInt(a.minutes, 10) || 0))
        .map(a => `
            <div class="worktime-activity">
                <span>${activityDot(activityColor(a.activity_id))}${escapeHtml(a.activity_name || 'Tätigkeit')}</span>
                <span class="worktime-activity-time">${formatMinutes(a.minutes)}</span>
            </div>`)
        .join('');

    body.innerHTML = `
        <div class="worktime-total">${formatMinutes(minuten)}</div>
        <div class="worktime-total-label">bestätigt · ${sitzungen} ${sitzungen === 1 ? 'Sitzung' : 'Sitzungen'}</div>
        ${note ? `<div class="worktime-note">${note}</div>` : ''}
        ${zeilen ? `<div class="worktime-activities">${zeilen}</div>` : ''}
    `;
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
    bindOnce(document.getElementById('prevYear'), 'click', async () => {
        currentStatsYear--;
        await loadStatistics();
    });

    bindOnce(document.getElementById('nextYear'), 'click', async () => {
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

/**
 * Antrag und Arbeitszeit durchlaufen dieselbe Freigabe, hiessen im Verlauf
 * aber verschieden („Ausstehend" gegen „Wartet auf Freigabe"). In einer
 * gemeinsamen Zeitachse liest sich das wie zwei verschiedene Sachverhalte,
 * darum ein Wortlaut fuer beide.
 *
 * „Anwesend" und „Entschuldigt" bleiben, wie sie sind: sie beschreiben nicht
 * den Bearbeitungsstand, sondern die Tatsache selbst.
 */
const FREIGABE_STATUS = {
    'pending':   'Wartet auf Freigabe',
    'submitted': 'Wartet auf Freigabe',
    'approved':  'Bestätigt',
    'confirmed': 'Bestätigt',
    'rejected':  'Abgelehnt'
};

function translateExceptionStatus(status) {
    return FREIGABE_STATUS[status] || status;
}

function translateWorkSessionStatus(status) {
    return FREIGABE_STATUS[status] || status;
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