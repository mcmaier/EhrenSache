// ========================================
// KONFIGURATION
// ========================================
const API_BASE = 'http://localhost/members/api/api.php';

// ========================================
// STATE MANAGEMENT
// ========================================
let apiToken = null;
let userData = null;
let html5QrCode = null;
let isScanning = false;
let clockInterval = null;
let appointments = [];
let deleteExceptionId = null;

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
    // DOM Elements cachen
    elements = {
        loginScreen: document.getElementById('loginScreen'),
        mainScreen: document.getElementById('mainScreen'),
        loginForm: document.getElementById('loginForm'),
        apiTokenInput: document.getElementById('apiToken'),
        saveTokenCheckbox: document.getElementById('saveToken'),
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
        refreshHistoryButton: document.getElementById('refreshHistoryButton')
    };

    // Event Listeners
    elements.loginForm.addEventListener('submit', handleLogin);
    elements.logoutBtn.addEventListener('click', handleLogout);
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
    elements.refreshHistoryButton.addEventListener('click', loadHistory);   
    
    // Enter-Taste im Code-Input
    elements.manualCode.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            submitManualCode();
        }
    });

    // Prüfe gespeicherten Token
    const savedToken = localStorage.getItem('api_token');
    if (savedToken) {
        try {
            const decodedToken = atob(savedToken);
            elements.apiTokenInput.value = decodedToken;
            elements.saveTokenCheckbox.checked = true;
            
            // Auto-Login versuchen
            handleLogin(new Event('submit'));
        } catch (error) {
            console.error('Fehler beim Laden des gespeicherten Tokens');
        }
    }

    // Online/Offline Detection
    window.addEventListener('online', () => console.log('Online'));
    window.addEventListener('offline', showOfflineIndicator);
});

// ========================================
// API HELPER
// ========================================
async function apiCall(resource, method = 'GET', data = null, params = {}) {
    const url = new URL(API_BASE);
    url.searchParams.append('resource', resource);

    let connectionStatus = true;
    
    for (const [key, value] of Object.entries(params)) {
        url.searchParams.append(key, value);
    }

    // WICHTIG: Token auch als URL-Parameter (Fallback für Apache)
    if (apiToken) {
        url.searchParams.append('api_token', apiToken);
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
        console.log('API Call:', method, url.toString());
        const response = await fetch(url, options);
        
        if (!response.ok) {
            if (response.status === 401) {
                throw new Error('Token ungültig oder abgelaufen');                
            }
            else
            {         
                connectionStatus = false; 
                throw new Error(`HTTP ${response.status}`);
            }
        }
        
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);

        if(!connectionStatus) {
            showOfflineIndicator();
            connectionStatus = true;
        }

        throw error;
    }
}

// ========================================
// AUTHENTICATION
// ========================================
async function handleLogin(e) {
    e.preventDefault();
    
    const token = elements.apiTokenInput.value.trim();
    
    if (!token) {
        showError('Bitte Token eingeben');
        return;
    }
    
    elements.loginError.classList.remove('active');
    
    try {
        apiToken = token;
        
        //Test API Verbindung
        //await apiCall('records');
        if(await loadUserData() != true)
        {
            throw new Error('Benutzer konnte nicht geladen werden');       
        }
        
        if (elements.saveTokenCheckbox.checked) {
            localStorage.setItem('api_token', btoa(token));
        }
                
        await loadHistory();

        showScreen('main');        
        startClock();
        
    } catch (error) {
        console.error('Login Fehler:', error);
        showError('Ungültiger Token oder keine Verbindung zur API');
        apiToken = null;
    }
}

async function handleLogout() {
    await stopScannerIfRunning();

    localStorage.removeItem('api_token');
    apiToken = null;
    userData = null;
	currentRecords = [];
    
    elements.loginForm.reset();
    elements.loginError.classList.remove('active');
    
    showScreen('login');
    stopClock();
}

async function loadUserData() {
let success = false;

    try {
        const meData = await apiCall('me');
        userData = meData;
        
        if (meData.member_id) {
            try {
                const member = await apiCall('members', 'GET', null, { id: meData.member_id });
                
                if (member) {
                    elements.userName.textContent = `${member.name} ${member.surname}`;
                    
                    const roleText = meData.role === 'admin' ? 'Administrator' : 'Mitglied';
                    if (member.member_number) {
                        elements.userRole.textContent = `${roleText} • Nr. ${member.member_number}`;
                    } else {
                        elements.userRole.textContent = roleText;
                    }
                    success = true;
                    return success;
                }
            } catch (error) {
                console.log('Member-Daten konnten nicht geladen werden:', error);
            }
        }
        
        if (meData.email && meData.email !== 'token-auth') {
            elements.userName.textContent = meData.email;
        } else if (meData.user_id) {
            elements.userName.textContent = `User #${meData.user_id}`;
        } else {
            elements.userName.textContent = 'Benutzer';
        }
        
        const roleText = meData.role === 'admin' ? 'Administrator' : 'Mitglied';
        elements.userRole.textContent = roleText;

        success = true;
        
    } catch (error) {
        console.log('User-Daten konnten nicht geladen werden:', error);
        elements.userName.textContent = 'Benutzer';
        elements.userRole.textContent = 'Mitglied';
    }

    return success;
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
// QR SCANNER
// ========================================
async function toggleScanner() {
    const scannerContainer = document.getElementById('scannerContainer');
    //const container = document.querySelector('.container');
    
    if (isScanning) {
        await html5QrCode.stop();
        scannerContainer.style.display = 'none';
        elements.stopScanButton.style.display = 'none'; // Stop-Button verstecken
        elements.scanButton.style.display = 'flex'; // Scan-Button zeigen
        elements.scanButton.classList.remove('scanning');
        elements.scanButton.innerHTML = '<span class="icon">📷</span><span>QR-Code scannen</span>';
        isScanning = false;
    } else {
            scannerContainer.style.display = 'block';
            elements.stopScanButton.style.display = 'flex'; // Stop-Button zeigen
            elements.scanButton.style.display = 'none'; // Scan-Button verstecken            
            isScanning = true;

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
                    // Ignoriere "NotFoundException" (kein QR gefunden)
                    if (!errorMessage.includes("NotFoundException")) {
                        console.log("Scan:", errorMessage);
                    }
                }
            );
            
        } catch (error) {
            console.error('Kamera-Fehler:', error);
            
            let errorMsg = 'Kamera-Zugriff nicht möglich';
            if (error.name === 'NotAllowedError') {
                errorMsg = 'Kamera-Zugriff wurde verweigert. Bitte Berechtigung in den Browser-Einstellungen erteilen.';
            } else if (error.name === 'NotFoundError') {
                errorMsg = 'Keine Kamera gefunden';
            } else if (error.name === 'NotReadableError') {
                errorMsg = 'Kamera wird bereits von einer anderen App verwendet';
            }
            
            showMessage(errorMsg, 'error');
            //scannerContainer.style.display = 'none';

            stopScannerIfRunning();
        }
    }
}

async function onQRScanned(decodedText) {

    await stopScannerIfRunning();

    if (!decodedText.startsWith('CHECKIN:')) {
        showMessage('Ungültiger QR-Code Format', 'error');
        return;
    }

    await verifyCheckin(decodedText);
}

async function verifyCheckin(qrCode) {
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
            qr_code: qrCode,
            arrival_time: arrivalTime
        };

        // Admin muss member_id explizit mitschicken
        if (userData.role === 'admin') {
            requestData.member_id = userData.member_id;
        }
        // User: member_id wird vom Backend automatisch verwendet

        const data = await apiCall('checkin_verify', 'POST', requestData);

        if (data.appointment) {
            showMessage('✓ Erfolgreich eingecheckt!', 'success');
            addNewActivityToHistory({
                appointment: data.appointment,
                verified: true,
                pending: false
            });
        }
    } catch (error) {
        showMessage('Check-in fehlgeschlagen', 'error');
        console.error(error.message);
    } finally {
        elements.scanButton.disabled = false;
    }
}

// Helper-Funktion zum Stoppen des Scanners
async function stopScannerIfRunning() {
    if (isScanning && html5QrCode) {
        await html5QrCode.stop();
        document.getElementById('scannerContainer').style.display = 'none';
        elements.stopScanButton.style.display = 'none';
        elements.scanButton.style.display = 'flex';
        elements.scanButton.classList.remove('scanning');
        elements.scanButton.innerHTML = '<span class="icon">📷</span><span>QR-Code scannen</span>';
        isScanning = false;
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
        
    await verifyCheckin(`CHECKIN:${code}`);
}

// ========================================
// EXCEPTION
// ========================================

async function loadAppointments() {
    try {
        appointments = await apiCall('appointments');
        
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
        console.error('Fehler beim Laden der Termine:', error);
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
        showMessage('Bitte Termin auswählen', 'error');
        return;
    }
    
    if (!reason) {
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

        const data = await apiCall('exceptions', 'POST', {
            member_id: userData.member_id,
            appointment_id: parseInt(appointmentId),
            exception_type: 'time_correction',
            reason: reason,
            requested_arrival_time: arrivalTime       
        });

        if (data) {
            showMessage('✓ Antrag erfolgreich gestellt (wartet auf Genehmigung)', 'warning');
            
            const apt = appointments.find(a => a.appointment_id == appointmentId);
            addNewActivityToHistory({
                appointment: apt,
                verified: false,
                pending: true
            });
            
            closeExceptionModal();
        }
    } catch (error) {
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
        const records = await apiCall('records');
        
        // Lade offene Exceptions
        const exceptions = await apiCall('exceptions', 'GET', null, { status: 'pending' });
        
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
        
        // Zeige die letzten 10 Einträge
        renderHistory(combined.slice(0, 10));
        
    } catch (error) {
        console.error('Fehler beim Laden der History:', error);
        elements.historyList.innerHTML = '<div class="history-empty">Fehler beim Laden der Historie</div>';
    }
}

// Rendert History-Liste
function renderHistory(items) {
    elements.historyList.innerHTML = '';
    
    if (items.length === 0) {
        elements.historyList.innerHTML = '<div class="history-empty">Noch keine Aktivitäten</div>';
        elements.history.style.display = 'block';
        return;
    }
    
    items.forEach(item => {
        if (item.type === 'record') {
            addRecordToHistory(item.data);
        } else {
            addExceptionToHistory(item.data);
        }
    });
    
    elements.history.style.display = 'block';
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
    
    item.innerHTML = `
        <div class="time">📍 ${dateStr} ${timeStr}</div>
        <div class="appointment">${record.title}</div>
        <span class="status verified">✓ ${statusText}</span>
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

    // Delete-Button nur bei pending
    const deleteBtn = canDelete 
        ? `<button class="delete-btn" onclick="deleteException(${exception.exception_id})">🗑️ Löschen</button>`
        : '';
    
    item.innerHTML = `        
        <div class="time">📋 ${dateStr} ${timeStr} ${deleteBtn}</div>
        <div class="appointment">${exception.title}</div>
        <span class="status pending">${statusText} - ${typeText}</span>
        
    `;
    
    elements.historyList.appendChild(item);
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
        await apiCall('exceptions', 'DELETE', null, { id: deleteExceptionId });
        
        showMessage('✓ Antrag erfolgreich gelöscht', 'success');
        
        // History neu laden
        await loadHistory();
        
    } catch (error) {
        console.error('Fehler beim Löschen:', error);
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
    
    const statusBadge = data.pending 
        ? '<span class="status pending">⏳ Antrag ausstehend</span>'
        : '<span class="status verified">✓ Verifiziert</span>';
    
    item.innerHTML = `
        <div class="time">🆕 Gerade eben (${timeStr})</div>
        <div class="appointment">${data.appointment?.title || 'Unbekannter Termin'}</div>
        ${statusBadge}
    `;
    
    // Füge am Anfang ein
    elements.historyList.insertBefore(item, elements.historyList.firstChild);
    
    // Begrenze auf 10 Einträge
    while (elements.historyList.children.length > 10) {
        elements.historyList.removeChild(elements.historyList.lastChild);
    }
    
    elements.history.style.display = 'block';
}

// ========================================
// UTILITY
// ========================================

function translateStatus(status) {
    const translations = {
        'present': 'Anwesend',
        'absent': 'Abwesend',
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

function formatDateTime(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}