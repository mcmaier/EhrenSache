import { TOAST_DURATION } from '../config.js';
import {apiCall, isAdmin, currentUser, setCurrentUser} from './api.js';
import {loadSettings} from './settings.js';
import {loadUsers} from'./users.js';
import {loadAppointments} from'./appointments.js';
import {loadExceptions, loadExceptionFilters} from'./exceptions.js';
import {loadRecords, loadRecordFilters} from'./records.js';
import {loadMembers} from'./members.js';
import {loadGroups, loadTypes} from './management.js';
import {loadStatistics, initStatistics, reloadStatisticsFilters} from './statistics.js';


// ============================================
// UI
// Reference:
// import {} from './ui.js'
// ============================================

// UI Helper Funktionen
export function showScreen(screenName) {
    const screens = {
        login: document.getElementById('loginPage'),
        main: document.getElementById('dashboard')
    };
    
    Object.values(screens).forEach(screen => screen.classList.remove('active'));
    screens[screenName].classList.add('active');
}


// Separate Funktion für Button-Erstellung
export function createMobileMenuButton() {

    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    mobileMenuBtn.setAttribute('aria-label', 'Menu');
    mobileMenuBtn.innerHTML = `
        <span></span>
        <span></span>
        <span></span>
    `;
    
    // Initial IMMER versteckt
    mobileMenuBtn.style.display = 'none';
    document.body.appendChild(mobileMenuBtn);
    
    const sidebar = document.querySelector('.sidebar');
    const sidebarOverlay = document.createElement('div');
    sidebarOverlay.className = 'sidebar-overlay';
    document.body.appendChild(sidebarOverlay);
    
    // Toggle Funktion
    mobileMenuBtn.addEventListener('click', function() {
        sidebar.classList.toggle('mobile-open');
        sidebarOverlay.classList.toggle('active');
        this.classList.toggle('active');
    });
    
    // Schließen bei Overlay-Click
    sidebarOverlay.addEventListener('click', function() {
        sidebar.classList.remove('mobile-open');
        this.classList.remove('active');
        mobileMenuBtn.classList.remove('active');
    });
    
    // Responsive Handler
    window.addEventListener('resize', updateMobileMenuVisibility);
}

export function toggleMobileMenuButton()
{
    // Mobile Menu Toggle - nach window.load Event
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.querySelector('.sidebar');

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
            this.classList.toggle('active');
        });
        
        // Schließe Sidebar bei Click außerhalb
        document.addEventListener('click', function(e) {
            if (!sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('mobile-open');
                mobileMenuBtn.classList.remove('active');
            }
        });
    }
}

export function showToast(message, type = 'info', duration = TOAST_DURATION) {
    const container = document.getElementById('toastContainer');
    
    // Icons je nach Typ
    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.style.position = 'relative';
    
    toast.innerHTML = `
        <div class="toast-icon">${icons[type] || icons.info}</div>
        <div class="toast-content">
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">×</button>
        ${duration > 0 ? '<div class="toast-progress"></div>' : ''}
    `;
    
    container.appendChild(toast);
    
    // Limitiere auf max 3 Toasts
    while(container.children.length > 3) {
        container.firstChild.remove();
    }
    
    // Auto-Remove nach duration
    if(duration > 0) {
        setTimeout(() => {
            toast.classList.add('removing');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
}

export async function showConfirm(message, title = 'Bestätigung') {
    return new Promise((resolve) => {
        const modal = document.getElementById('confirmModal');
        const messageEl = document.getElementById('confirmMessage');
        const titleEl = document.getElementById('confirmTitle');
        const okBtn = document.getElementById('confirmOk');
        const cancelBtn = document.getElementById('confirmCancel');
        
        messageEl.textContent = message;
        titleEl.textContent = title;
        
        modal.classList.add('active');
        
        // Focus auf Abbrechen-Button (sicherer)
        cancelBtn.focus();
        
        function cleanup() {
            modal.classList.remove('active');
            okBtn.replaceWith(okBtn.cloneNode(true));  // Remove event listeners
            cancelBtn.replaceWith(cancelBtn.cloneNode(true));
            
            // Neue Referenzen holen nach cloneNode
            const newOkBtn = document.getElementById('confirmOk');
            const newCancelBtn = document.getElementById('confirmCancel');
        }
        
        // OK geklickt
        document.getElementById('confirmOk').addEventListener('click', function handler() {
            cleanup();
            resolve(true);
        }, { once: true });
        
        // Abbrechen geklickt
        document.getElementById('confirmCancel').addEventListener('click', function handler() {
            cleanup();
            resolve(false);
        }, { once: true });
        
        // ESC-Taste
        function escHandler(e) {
            if (e.key === 'Escape') {
                cleanup();
                document.removeEventListener('keydown', escHandler);
                resolve(false);
            }
        }
        document.addEventListener('keydown', escHandler);
    });
}

export function updateUIForRole() {
    // Buttons mit data-role="admin" nur für Admins anzeigen
    const adminButtons = document.querySelectorAll('[data-role="admin"]');
    adminButtons.forEach(btn => {
        btn.style.display = isAdmin ? 'block' : 'none';
    });
    
    // Filter-Leiste anpassen
    if (!isAdmin) {
        const filterMember = document.getElementById('filterMember');
        if (filterMember) {
            filterMember.style.display = 'none';
            const label = document.querySelector('label[for="filterMember"]');
            if (label) label.style.display = 'none';
        }
    }
}

// Separate Funktion für Sichtbarkeit
export function updateMobileMenuVisibility() {
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (!mobileMenuBtn) return;
    
    const isLoggedIn = document.getElementById('dashboard').classList.contains('active');
    const isMobile = window.innerWidth <= 768;
    
    mobileMenuBtn.style.display = (isLoggedIn && isMobile) ? 'flex' : 'none';
}

// UI Helper
export function showLogin() {
    document.getElementById('loginPage').style.display = 'flex';
    document.getElementById('dashboard').classList.remove('active');
    document.getElementById('loginForm').reset();
    setCurrentUser(null);

    // Verstecke Button IMMER beim Login
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
        mobileMenuBtn.style.display = 'none';
    }
    
    // Schließe Sidebar falls offen
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
}

// Nach Login/Session-Check setzen
export function showDashboard() {
    document.getElementById('loginPage').style.display = 'none';
    document.getElementById('dashboard').classList.add('active');
    
    if (currentUser) {
        document.getElementById('currentUser').textContent = currentUser.email;
        document.getElementById('userRole').textContent = `(${currentUser.role})`;
        
        // UI anpassen nach Rolle
        updateUIForRole();
        updateTableHeaders();

        // Setze aktive Navigation basierend auf gespeicherter Section
        let section = sessionStorage.getItem('currentSection');
        
        // Validiere Section für User
        if (!section || (section === 'mitglieder' && !isAdmin)) {
            section = 'einstellungen';
            sessionStorage.setItem('currentSection', section);
        }

        const userTerminInfo = document.getElementById('userTerminInfo');
        if (userTerminInfo) {
            userTerminInfo.style.display = isAdmin ? 'none' : 'block';
        }
        
        // Aktiviere entsprechende Navigation
        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
        
        const navItem = document.querySelector(`.nav-item[data-section="${section}"]`);
        const contentSection = document.getElementById(section);
        
        if (navItem && contentSection) {
            navItem.classList.add('active');
            contentSection.classList.add('active');
        }

        // Zeige Button falls Mobile
        updateMobileMenuVisibility();
    }
}    

// Funktion um Tabellen-Header dynamisch anzupassen
export function updateTableHeaders() {
    const tables = [
        { id: 'membersTableBody', headers: ['Name', 'Vorname', 'Mitgliedsnummer', 'Gruppen','Status'] },
        { id: 'appointmentsTableBody', headers: ['Termin', 'Terminart', 'Beschreibung',] },
        { id: 'recordsTableBody', headers: ['Termin', 'Terminart', 'Mitglied', 'Ankunftszeit', 'Status','Quelle'] },
        { id: 'exceptionsTableBody', headers: ['Typ', 'Mitglied', 'Termin', 'Begründung', 'Gewünschte Zeit', 'Status', 'Erstellt am'] }
    ];
    
    tables.forEach(table => {
        const tbody = document.getElementById(table.id);
        const thead = tbody.closest('table').querySelector('thead tr');
        
        // Entferne alle th
        thead.innerHTML = '';
        
        // Füge Header hinzu
        table.headers.forEach(header => {
            thead.innerHTML += `<th>${header}</th>`;
        });
        
        // Füge Aktionen-Spalte nur für Admins hinzu
        if (isAdmin || (table.id === 'exceptionsTableBody')) {
            thead.innerHTML += '<th>Aktionen</th>';
        }
    });
}

export async function initNavigation() {
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', function() {
            document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
            document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
            
            this.classList.add('active');
            const section = this.getAttribute('data-section');
            document.getElementById(section).classList.add('active');
            
             // Speichere aktuelle Section
            sessionStorage.setItem('currentSection', section);

            loadAllData();

            // Schließe Sidebar auf Mobile nach Klick auf Menu Item
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.querySelector('.sidebar-overlay');
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            
            if (sidebar && sidebar.classList.contains('mobile-open')) {
                sidebar.classList.remove('mobile-open');
                if (overlay) overlay.classList.remove('active');
                if (mobileMenuBtn) mobileMenuBtn.classList.remove('active');
            }
        });
    });
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
        await loadRecords(true);
    } else if (section === 'antraege') {
        await loadExceptionFilters();
        await loadExceptions(true);
    } else if (section === 'benutzer' && isAdmin) {
        await loadUsers();
    }
    else if(section === 'verwaltung' && isAdmin) {
        await loadGroups();
        await loadTypes();
    }
    else if (section === 'statistik') {
        await reloadStatisticsFilters();
        await loadStatistics();
    }

        //Rest im Hintergrund laden (nicht-blockierend)
        setTimeout(() => {
            if (section !== 'mitglieder') loadMembers(true);
            if (section !== 'termine') loadAppointments(true);
            if (section !== 'anwesenheit') {
                loadRecordFilters();
                loadRecords(true);
            }
            if (section !== 'antraege') {
                loadExceptions(true);
                loadExceptionFilters();
            }
            if (isAdmin && section !== 'benutzer') loadUsers();
            if (section !== 'einstellungen') loadSettings();
            if (isAdmin && section !== 'verwaltung') {
                loadGroups();
                loadTypes();
            }
            if(section != 'statistik')
            {
                reloadStatisticsFilters();
                loadStatistics();
            }
        }, 100);
}

export function initModalEscHandler() {
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // Finde alle aktiven Modals (außer Confirmation-Modal)
            const activeModals = document.querySelectorAll('.modal.active:not(#confirmModal)');
            
            // Schließe das zuletzt geöffnete Modal
            if (activeModals.length > 0) {
                const lastModal = activeModals[activeModals.length - 1];
                lastModal.classList.remove('active');
            }
        }
    });
}


// ============================================
// PWA QUICK ACCESS
// ============================================

export function initPWAQuickAccess() {
    const openPWABtn = document.getElementById('openPWABtn');
    if (!openPWABtn) return;
    
    openPWABtn.addEventListener('click', () => {
        const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
        const baseUrl = window.location.origin + window.location.pathname.replace('index.html', '');
        const pwaUrl = baseUrl + 'checkin/';
        
        if (isMobile) {
            // Direkt öffnen auf Mobile
            window.open(pwaUrl, '_blank');
        } else {
            // QR-Code auf Desktop
            showPWAQRCode(pwaUrl);
        }
    });
}

function showPWAQRCode(url) {
    // Modal erstellen falls nicht vorhanden
    let modal = document.getElementById('qrModal');
    
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'qrModal';
        modal.className = 'qr-modal';
        modal.innerHTML = `
            <div class="qr-modal-content">
                <h2>📱 Check-In App öffnen</h2>
                <div id="qrcode"></div>
                <p>Scanne den QR-Code mit deinem Smartphone</p>
                <p style="font-size: 12px; margin-top: 10px;">
                    <strong>Oder kopiere:</strong><br>
                    <input type="text" value="${url}" readonly 
                           style="width: 100%; padding: 8px; margin-top: 5px; font-size: 12px; text-align: center;">
                </p>
                <button class="btn-close-qr">Schließen</button>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Close-Handler
        modal.querySelector('.btn-close-qr').addEventListener('click', () => {
            modal.classList.remove('active');
        });
        
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    }
    
    // QR-Code generieren (benötigt qrcode.js)
    const qrContainer = document.getElementById('qrcode');
    qrContainer.innerHTML = ''; // Clear previous
    
    // Prüfe ob QRCode Library verfügbar
    if (typeof QRCode !== 'undefined') {
        new QRCode(qrContainer, {
            text: url,
            width: 200,
            height: 200,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
    } else {
        // Fallback: Text-Link
        qrContainer.innerHTML = `
            <p style="color: #e74c3c; margin: 20px;">
                QR-Code Library fehlt<br>
                <a href="${url}" target="_blank" style="color: #667eea;">
                    Direkt zur App →
                </a>
            </p>
        `;
    }
    
    modal.classList.add('active');
}
