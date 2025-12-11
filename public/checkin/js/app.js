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
    
    console.log('PWA API Base:', basePath);

    return `${origin}${basePath}/api/api.php`;    
})();

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
let nfcAbortController = null;
let isNFCScanning = false;

// In checkin/index.html oder checkin/app.js
    if ('serviceWorker' in navigator) {
        //const currentPath = window.location.pathname;
        //const basePath = currentPath.substring(0, currentPath.lastIndexOf('/') + 1);

        navigator.serviceWorker.register('./checkin/service-worker.js', {
            scope: './checkin/'
        })
        .then(reg => console.log('✓ Service Worker registriert:', reg.scope))
        .catch(err => console.log('✗ Service Worker Fehler:', err));        
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
        refreshHistoryButton: document.getElementById('refreshHistoryButton'),
        nfcButton: document.getElementById('nfcButton'),
        toDashboardBtn: document.getElementById('toDashboardBtn')
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
    elements.nfcButton.addEventListener('click', toggleNFCReader);        
    toDashboardBtn.addEventListener('click', handleDashboardNavigation);

    // Enter-Taste im Code-Input
    elements.manualCode.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            submitManualCode();
        }
    });

    checkNFCSupport();

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

    /*
    // Token auch als URL-Parameter (Fallback für Apache)
    if (apiToken) {
        url.searchParams.append('api_token', apiToken);
    }*/

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
        //console.log('API Call:', method, url.toString());
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
        
        // Fallback
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
            source_device: inputMethod
        };

        // Admin muss member_id explizit mitschicken
        if (userData.role === 'admin') {
            requestData.member_id = userData.member_id;
        }
        // User: member_id wird vom Backend automatisch verwendet

        const data = await apiCall('totp_checkin', 'POST', requestData);

        const methodText = '✗';

        if (data.appointment) {
            const methodText = inputMethod === 'QR' ? '📷 QR-Code' :
                             inputMethod === 'NFC' ? '📱 NFC' :
                             inputMethod === 'CODE' ? '⌨️ Manuell' : '';

            showMessage(methodText + ' eingecheckt!', 'success');
            addNewActivityToHistory({
                appointment: data.appointment,
                verified: true,
                pending: false,
                method: inputMethod
            });
        }
    } catch (error) {
        showMessage('Check-in fehlgeschlagen', 'error');
        console.error(error.message);
    } finally {
        elements.scanButton.disabled = false;
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
            console.log('Stopp-Error (ignoriert):', error);
        }

        scannerContainer.style.display = 'none';
        elements.stopScanButton.style.display = 'none'; // Stop-Button verstecken
        elements.scanButton.style.display = 'flex'; // Scan-Button zeigen
        elements.scanButton.classList.remove('scanning');
        elements.scanButton.innerHTML = '<span class="icon">📷</span><span>QR-Code scannen</span>';
        isScanning = false;
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
                        console.warn("Scan error:", msg);
                    }
                }
            );

            scannerContainer.style.display = 'block';
            elements.stopScanButton.style.display = 'flex'; // Stop-Button zeigen
            elements.scanButton.style.display = 'none'; // Scan-Button verstecken            
            isScanning = true;
            
        } catch (error) {
            console.error('Kamera-Fehler:', error);

            // Bei Fehler: Alles zurücksetzen
            scannerContainer.style.display = 'none';
            elements.stopScanButton.style.display = 'none';
            elements.scanButton.style.display = 'flex';
            isScanning = false;
            
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
                console.log('✓ NFC wird unterstützt');
            } else {
                console.log('✗ NFC-Berechtigung verweigert');
            }
        } catch (error) {
            console.log('✗ NFC-Permission-Check fehlgeschlagen:', error);
            // Zeige Button trotzdem - User kann beim ersten Scan Berechtigung erteilen
            elements.nfcButton.style.display = 'flex';
        }
    } else {
        console.log('✗ NFC wird von diesem Browser nicht unterstützt');
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
    if (!('NDEFReader' in window)) {
        showMessage('NFC wird von diesem Gerät nicht unterstützt', 'error');
        return;
    }
    
    // Falls QR-Scanner läuft, erst stoppen
    await stopScannerIfRunning();
    
    try {
        const ndef = new NDEFReader();
        nfcAbortController = new AbortController();
        
        await ndef.scan({ signal: nfcAbortController.signal });
        
        isNFCScanning = true;
        elements.nfcButton.classList.add('scanning');
        elements.nfcButton.innerHTML = '<span class="icon">⏹️</span><span>NFC-Scan beenden</span>';
        elements.nfcButton.style.background = '#e74c3c';
        
        showMessage('📡 Halte ein NFC-Tag an die Rückseite des Geräts...', 'info');
        
        ndef.addEventListener("reading", ({ message, serialNumber }) => {
            console.log('NFC-Tag erkannt:', serialNumber);
            onNFCTagRead(message, serialNumber);
        }, { signal: nfcAbortController.signal });
        
        ndef.addEventListener("readingerror", () => {
            showMessage('Fehler beim Lesen des NFC-Tags', 'error');
        }, { signal: nfcAbortController.signal });
        
    } catch (error) {
        console.error('NFC-Fehler:', error);
        
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
    if (nfcAbortController) {
        nfcAbortController.abort();
        nfcAbortController = null;
    }
    
    isNFCScanning = false;
    elements.nfcButton.classList.remove('scanning');
    elements.nfcButton.innerHTML = '<span class="icon">📡</span><span>NFC-Tag scannen</span>';
    elements.nfcButton.style.background = '';
    
    showMessage('NFC-Scan beendet', 'info');
}

async function onNFCTagRead(message, serialNumber) {
    console.log('NFC-Tag gelesen:', { message, serialNumber });
    
    stopNFCReader();
    
    // Versuche NDEF-Records zu lesen
    let checkinCode = null;
    
    for (const record of message.records) {
        console.log('NDEF Record:', record.recordType, record);
        
        if (record.recordType === "text") {
            const textDecoder = new TextDecoder(record.encoding || 'utf-8');
            const text = textDecoder.decode(record.data);
            console.log('Text-Record:', text);
            
            // Prüfe ob es ein CHECKIN-Code ist
            if (text.startsWith('CHECKIN:')) {
                checkinCode = text;
                break;
            }
        } else if (record.recordType === "url") {
            const textDecoder = new TextDecoder();
            const url = textDecoder.decode(record.data);
            console.log('URL-Record:', url);
            
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
        let records = await apiCall('records');
        
        // Lade offene Exceptions
        let exceptions = await apiCall('exceptions', 'GET', null, { status: 'pending' });
        
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

    // Appointment Type Badge hinzufügen (falls vorhanden)
    let typeBadge = '';
    if (record.appointment_type_name) {
        const typeClass = record.appointment_type_name.toLowerCase().replace(/\s+/g, '-');
        typeBadge = `<span class="type-badge ${typeClass}">${record.appointment_type_name}</span>`;
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
        const typeClass = exception.appointment_type_name.toLowerCase().replace(/\s+/g, '-');
        typeBadge = `<span class="type-badge ${typeClass}">${exception.appointment_type_name}</span>`;
    }

    // Delete-Button nur bei pending
    const deleteBtn = canDelete 
        ? `<button class="delete-btn" onclick="deleteException(${exception.exception_id})">🗑️ Löschen</button>`
        : '';
    
    item.innerHTML = `        
        <div class="time">📋 ${dateStr} ${timeStr} ${deleteBtn}</div>
        <div class="appointment">${exception.title}</div>
        <span class="status pending">${statusText} - ${typeText}</span>
        ${typeBadge}
        
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

    // Appointment Type Badge hinzufügen (falls vorhanden)
    let typeBadge = '';
    if (data.appointment_type_name) {
        const typeClass = data.appointment_type_name.toLowerCase().replace(/\s+/g, '-');
        typeBadge = `<span class="type-badge ${typeClass}">${data.appointment_type_name}</span>`;
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
    
    elements.history.style.display = 'block';
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

function formatDateTime(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}