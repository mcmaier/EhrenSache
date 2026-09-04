/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { apiCall, currentUser, isAdmin } from './api.js';
import { showToast, showConfirm, dataCache, isCacheValid,invalidateCache} from './ui.js';
import { updateModalId } from './utils.js';
import {debug} from '../app.js'

// ============================================
// DEVICES
// Reference:
// import {} from './devices.js'
// ============================================

let currentDevicesPage = 1;
const devicesPerPage = 25;
let allFilteredDevices = [];

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadDevices(forceReload = false) {

    if(!isAdmin)
    {
        return;
    }

    if (!forceReload && isCacheValid('devices')) {
        debug.log("Loading DEVICES from CACHE");        
        return dataCache.devices.data;
    }

    debug.log("Loading DEVICES from API");
    const devices = await apiCall('users','GET',null, { user_type: 'device' });
    
    // Cache speichern
    dataCache.devices.data = devices;
    dataCache.devices.timestamp = Date.now();
    
    return devices;    
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

function renderDevices(devices, page = 1)
{
    debug.log("Render Devices()");

    if(!isAdmin)
        return;

    const tbody = document.getElementById('devicesTableBody');
    tbody.innerHTML = '';

    updateDeviceStats(devices);

    // Alle Devices speichern für Pagination
    allFilteredDevices = devices;
    currentDevicesPage = page;

    // Pagination berechnen
    const totalDevices = devices.length;
    const totalPages = Math.ceil(totalDevices / devicesPerPage);
    const startIndex = (page - 1) * devicesPerPage;
    const endIndex = startIndex + devicesPerPage;
    const pageDevices = devices.slice(startIndex, endIndex);


    debug.log(`Rendering page ${page}/${totalPages} (${pageDevices.length} of ${totalDevices} devices)`);
    
    // DocumentFragment für Performance
    const fragment = document.createDocumentFragment();
    
    pageDevices.forEach(device => {
        const tr = document.createElement('tr');        

        const createdAt = new Date(device.created_at);
        const formattedCreated = createdAt.toLocaleDateString('de-DE');

        // Typ-Badge
        const typeText = {
            'totp_location': '🔢 TOTP-Station',
            'auth_device':   '🔐 Auth-Gerät',
            'kiosk':         '🖥️ Virtuelle Station'
        }[device.device_type] || '❓ Unbekannt';

        // Status
        const statusBadge = device.is_active 
            ? '<span class="badge badge-success">Aktiv</span>' 
            : '<span class="badge badge-inactive">Inaktiv</span>';

        // Token Expiry
        const tokenExpiry = device.api_token_expires_at 
            ? new Date(device.api_token_expires_at).toLocaleDateString('de-DE')
            : '-';            
                            
            tr.innerHTML = `
                <td>${device.device_name}</td>
                <td>${typeText}</td>
                <td>${statusBadge}</td>
                <td>${tokenExpiry}</td>
                <td>${formattedCreated}</td>
                <td class="actions-cell">
                    <button class="action-btn btn-icon btn-edit" onclick="openDeviceModal(${device.user_id})">✎</button>
                    <button class="action-btn btn-icon btn-delete" onclick="deleteDevice(${device.user_id}, '${device.device_name}')">🗑</button>
                </td> `;                     
                fragment.appendChild(tr);
        });
        
    tbody.innerHTML = '';
    tbody.appendChild(fragment);
    
    // Pagination Controls
    renderDevicesPagination(page, totalPages, totalDevices); 
}


function renderDevicesPagination(currentPage, totalPages, totalDevices) {
    const container = document.getElementById('devicesPagination');
    if (!container) return;
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const startDevice = (currentPage - 1) * devicesPerPage + 1;
    const endDevice = Math.min(currentPage * devicesPerPage, totalDevices);
    
    let html = `
        <div class="pagination-container">
            <div class="pagination-info">
                Zeige ${startDevice} - ${endDevice} von ${totalDevices} Einträgen
            </div>
            <div class="pagination-buttons">
    `;

    
    if (totalPages <= 5) {
        // Wenige Seiten (≤5): Alle Seitenzahlen ohne Pfeile
        for (let i = 1; i <= totalPages; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToDevicesPage(${i})">${i}</button>`;
        }
    } 
    else {

        // Erste Seite Button
        if (currentPage > 1) {
            //html += `<button onclick="goToDevicesPage(1)" title="Erste Seite">
            //            «
            //        </button>`;
            html += `<button onclick="goToDevicesPage(${currentPage - 1})" title="Vorherige Seite">
                        ‹
                    </button>`;
        }
        
        // Seitenzahlen (max 5 anzeigen)
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            html += `<button onclick="goToDevicesPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToDevicesPage(${i})">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
            html += `<button onclick="goToDevicesPage(${totalPages})">${totalPages}</button>`;
        }
        
        // Letzte Seite Button
        if (currentPage < totalPages) {
            html += `<button onclick="goToDevicesPage(${currentPage + 1})" title="Nächste Seite">
                        ›
                    </button>`;
            //html += `<button onclick="goToDevicesPage(${totalPages})" title="Letzte Seite">
            //            »
            //        </button>`;
        }
    }    
    html += `
            </div>
        </div>
    `;
    
    container.innerHTML = html;
}

function updateDeviceStats(devices) {        
    const activeDevices = devices.filter(u => u.is_active === 1 || u.is_active === true).length;    
    const inactiveDevices = devices.length - activeDevices;
    
    document.getElementById('statActiveDevices').textContent = activeDevices;
    document.getElementById('statInactiveDevices').textContent = inactiveDevices;    
}

// Global für onclick
window.goToDevicesPage = function(page) {

    // Aktuelle Scroll-Position der Tabelle speichern
    const tableContainer = document.querySelector('.data-table')?.parentElement;
    const scrollBefore = tableContainer?.scrollTop || 0;

    renderDevices(allFilteredDevices, page);
    
    // KEIN automatisches Scrollen - Position beibehalten
    // ODER: Sanft zur Tabelle scrollen
    if (scrollBefore === 0) {
        // Nur scrollen wenn Device nicht gescrollt hat
        const paginationElement = document.getElementById('devicesPagination');
        if (paginationElement) {
            paginationElement.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'nearest' 
            });
        }
    }
}

export async function showDeviceSection(forceReload = false, page = 1)
{
    debug.log("Show Device Section ()");

    //applyDeviceFilters(forceReload, page);
    
    const allDevices = await loadDevices(forceReload);
    renderDevices(allDevices, 1);
    
}

export async function applyDeviceFilters(forceReload = false, page = 1) {
    debug.log('applyDeviceFilters called');
    
    // Devices laden (aus Cache wenn möglich)
    const allDevices = await loadDevices(forceReload);
    debug.log('Loaded devices:', allDevices.length);

    // Rendern (nur wenn auf Device-Section) - Reset auf Seite 1
    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'benutzer') {
        renderDevices(allDevices, page);
        debug.log('Devices rendered');
    }
    
    return allDevices;
}

export function filterDevices(devices, filters = {}) {
    debug.log("filterDevices() called with filters:", filters);
    
    if (!devices || devices.length === 0) return [];
    
    let filtered = [...devices];
    
    // Filter: Rolle
    if (filters.role && filters.role !== '') {
        filtered = filtered.filter(u => u.role === filters.role);
        debug.log(`After role filter (${filters.role}):`, filtered.length);
    }
    
    // Filter: Status (aktiv/inaktiv)
    if (filters.status && filters.status !== '') {
        if (filters.status === 'active') {
            filtered = filtered.filter(u => u.is_active === 1 || u.is_active === true);
        } else if (filters.status === 'inactive') {
            filtered = filtered.filter(u => u.is_active === 0 || u.is_active === false);
        }
        debug.log(`After status filter (${filters.status}):`, filtered.length);
    }
    
    debug.log(`Final filtered devices:`, filtered.length);
    return filtered;
}

export async function initDevicesEventHandlers()
{
    debug.log("Trying to register Device Event Handler. IsAdmin?", isAdmin);
    if (!isAdmin) return;

        // Filter-Änderungen
        document.getElementById('filterDeviceRole')?.addEventListener('change', () => {
            applyDeviceFilters();
        });
        
        document.getElementById('filterDeviceStatus')?.addEventListener('change', () => {
            applyDeviceFilters();
        });
        
        // Reset-Button (optional)
        document.getElementById('resetDeviceFilters')?.addEventListener('click', () => {
            document.getElementById('filterDeviceRole').value = '';
            document.getElementById('filterDeviceStatus').value = '';
            //document.getElementById('filterGroup').value = '';
            applyDeviceFilters();
        });
    

    // Devices laden und anzeigen
    //await applyDeviceFilters();
}


// ============================================
// DEVICE MODAL FUNCTIONS
// ============================================

async function openDeviceModal(deviceId = null) {
    const modal = document.getElementById('deviceModal');
    const title = document.getElementById('deviceModalTitle');

    // Event Listener EINMAL registrieren (mit removeEventListener zuerst)
    const typeSelect = document.getElementById('device_type');    
    typeSelect.removeEventListener('change', toggleDeviceTypeFields);
    typeSelect.addEventListener('change', toggleDeviceTypeFields);

    if (deviceId) {
        title.textContent = 'Gerät bearbeiten';

        const device = await apiCall('users', 'GET', null, { id: deviceId });
        debug.log('Devices loaded from API:', device);
        
        document.getElementById('device_id').value = device.user_id;
        document.getElementById('device_name').value = device.device_name;   
        document.getElementById('device_type').value = device.device_type || '';
        document.getElementById('device_active').checked = device.is_active == 1;      
        document.getElementById('device_totp_secret').value = device.totp_secret || '';

        const kioskBox = document.getElementById('device_kiosk_totp');
        kioskBox.checked = !!device.has_totp_secret;
        kioskBox.dataset.had = device.has_totp_secret ? '1' : '0';

        // Token-Anzeige aktualisieren
        if (device.api_token) {
            updateTokenDisplay(device.api_token, device.api_token_expires_at);
        } else {
            resetTokenDisplay();
        }
        updateModalId('deviceModal', deviceId);

    } else {
        title.textContent = 'Neues Gerät';
        document.getElementById('deviceForm').reset();

        document.getElementById('device_id').value = '';
        document.getElementById('device_active').checked = 1;

        const kioskBox = document.getElementById('device_kiosk_totp');
        kioskBox.checked = true;
        kioskBox.dataset.had = '0';

        updateModalId('deviceModal', null);
        
        // Token-Gruppe verstecken und zurücksetzen
        resetTokenDisplay();
    }
            
    // Hints aktualisieren
    toggleDeviceTypeFields();

    modal.classList.add('active');
}

function closeDeviceModal() {
        document.getElementById('deviceModal').classList.remove('active');
}


function toggleDeviceTypeFields() {
    const deviceType = document.getElementById('device_type').value;
    const deviceId   = document.getElementById('device_id').value;
    const editing    = deviceId !== '';
    const totpGroup  = document.getElementById('totpSecretGroup');
    const kioskGroup = document.getElementById('kioskTotpGroup');
    const tokenGroup = document.getElementById('apiTokenGroup');
    const secret     = document.getElementById('device_totp_secret');

    totpGroup.style.display  = deviceType === 'totp_location' ? 'block' : 'none';
    secret.required          = deviceType === 'totp_location';
    kioskGroup.style.display = deviceType === 'kiosk' ? 'block' : 'none';
    // Token nur bei Bearbeitung anzeigen — beim Anlegen kommt er in der Antwort
    tokenGroup.style.display = (deviceType === 'auth_device' || deviceType === 'kiosk') && editing ? 'block' : 'none';

    const hints = {
        'totp_location': '🔢 Zeigt TOTP-Code (QR/NFC/Display), Benutzer authentifizieren sich per App. Benötigt TOTP Secret.',
        'auth_device':   '🔐 Authentifiziert Benutzer selbst (z.B. Fingerabdruck, Karte) und meldet die Mitgliedsnummer. Benötigt API-Token.',
        'kiosk':         '🖥️ Tablet im Kiosk-Modus: zeigt den Stations-Code und nimmt Mitgliedsnummer + PIN entgegen. Benötigt API-Token.'
    };

    const hintElement = document.getElementById('deviceTypeHint');
    hintElement.textContent = hints[deviceType] || '';
    hintElement.style.color = deviceType ? '#667eea' : '#7f8c8d';
}

// ============================================
// CRUD FUNCTIONS
// ============================================

export async function saveDevice() {
    // Form-Validierung prüfen
    const form = document.getElementById('deviceForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const deviceId = document.getElementById('device_id').value;
    const editing  = deviceId !== '';

    const data = {
        device_name: document.getElementById('device_name').value,
        is_active: document.getElementById('device_active').checked,
        device_type: document.getElementById('device_type').value,
    };

    if (data.device_type === 'totp_location') {
        data.totp_secret = document.getElementById('device_totp_secret').value || null;
    } else if (data.device_type === 'kiosk') {
        // Das Secret wird nie mitgeschickt; nur der Wunsch, es zu haben.
        const box = document.getElementById('device_kiosk_totp');
        if (editing) {
            const had = box.dataset.had === '1';
            if (box.checked && !had) data.totp_action = 'generate';
            if (!box.checked && had) data.totp_action = 'clear';
        } else {
            data.totp_enabled = box.checked;
        }
    }

    debug.log('Saving Device:', data);

    let result;
    if (editing) {
        result = await apiCall('users', 'PUT', data, { id: deviceId });

    } else {
        data.action = 'create_device';
        result = await apiCall('users', 'POST', data);
    }

    if (result.success) {
        const deviceType = result.device?.device_type;
        const showsToken = !editing && (deviceType === 'kiosk' || deviceType === 'auth_device') && result.device?.user_id;

        if (showsToken) {
            // Der Token steckt nur in dieser Antwort; danach nur noch ueber
            // das Bearbeiten-Modal (apiTokenGroup) abrufbar — also gleich
            // dorthin wechseln, statt ihn in einem Wegwerf-Dialog zu zeigen.
            closeDeviceModal();
            await showDeviceSection(true, currentDevicesPage);
            await openDeviceModal(result.device.user_id);
            showToast('Gerät angelegt – Token jetzt kopieren', 'success');
        } else {
            closeDeviceModal();
            showDeviceSection(true, currentDevicesPage);
            showToast(
                editing ? 'Gerät wurde erfolgreich aktualisiert' : 'Gerät wurde erfolgreich erstellt',
                'success'
            );
        }
    }
}

export async function deleteDevice(deviceId, name) {
    const confirmed = await showConfirm(
        `Gerät "${name}" wirklich löschen?`,
        'Gerät löschen'
    );

    if (confirmed) {
        const result = await apiCall('users', 'DELETE', null, { id: deviceId });
        if (result.success) {
            showDeviceSection(true, currentDevicesPage);
            showToast(`Gerät wurde gelöscht`, 'success');        
        }
    }
}

// ============================================
// TOKEN MANAGEMENT
// ============================================


function updateTokenDisplay(token, expiresAt) {
    const tokenGroup = document.getElementById('apiTokenGroup');
    const tokenInput = document.getElementById('device_token');
    const expiryInfo = document.getElementById('deviceTokenExpiryInfo');
    
    tokenGroup.style.display = 'block';
    tokenInput.value = token;
    tokenInput.type = 'password';
    document.getElementById('toggleDeviceTokenBtn').textContent = '👁️';
    
    if (expiresAt) {
        const expiresDate = new Date(expiresAt);
        const now = new Date();
        const isExpired = now > expiresDate;
        
        const expiresText = expiresDate.toLocaleDateString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
        
        expiryInfo.innerHTML = isExpired 
            ? `<span style="color: #e74c3c;">⚠️ Abgelaufen am: ${expiresText}</span>`
            : `<span style="color: #7f8c8d;">Gültig bis: ${expiresText}</span>`;
        expiryInfo.style.display = 'block';
    } else if (token) {
        expiryInfo.innerHTML = '<span style="color: #7f8c8d;">Kein Ablaufdatum</span>';
        expiryInfo.style.display = 'block';
    }
}

function resetTokenDisplay() {
    const tokenGroup = document.getElementById('apiTokenGroup');
    const tokenInput = document.getElementById('device_token');
    const expiryInfo = document.getElementById('deviceTokenExpiryInfo');
    
    tokenGroup.style.display = 'none';
    tokenInput.value = '';
    tokenInput.type = 'password';
    document.getElementById('toggleDeviceTokenBtn').textContent = '👁️';
    expiryInfo.style.display = 'none';
    expiryInfo.innerHTML = '';
}

export function copyDeviceToken() {
    const tokenInput = document.getElementById('device_token');
    if (tokenInput) {
        tokenInput.select();
        tokenInput.setSelectionRange(0, 99999);
        
        navigator.clipboard.writeText(tokenInput.value).then(() => {
            showToast('Token in Zwischenablage kopiert!', 'success');
        }).catch(() => {
            document.execCommand('copy');
            showToast('Token kopiert!', 'success');
        });
    }
}

export function toggleDeviceTokenVisibility() {
    const tokenInput = document.getElementById('device_token');
    const toggleBtn = document.getElementById('toggleDeviceTokenBtn');
    
    if (tokenInput.type === 'password') {
        tokenInput.type = 'text';
        toggleBtn.textContent = '🙈'; // Auge durchgestrichen
    } else {
        tokenInput.type = 'password';
        toggleBtn.textContent = '👁️'; // Auge offen
    }
}

export async function regenerateDeviceToken() {
    const deviceId = document.getElementById('device_id').value;
    if (!deviceId) return;
    
    const confirmed = await showConfirm(
        'Token wirklich neu generieren? Der alte Token wird ungültig!',
        'Token neu generieren'
    );
    
    if (confirmed) {
        // Mit device_id → Token für anderen Device
        const result = await apiCall('regenerate_token', 'POST', { 
            user_id: parseInt(deviceId)
        });
        
        if (result && result.api_token) {
            updateTokenDisplay(result.api_token, result.expires_at);            
            showToast('Neuer Token generiert!', 'success');
        }
    }
}

export function generateTotpSecret() {
    // Generiere zufälliges Base32-Secret (32 Zeichen)
    const base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let secret = '';
    
    for (let i = 0; i < 32; i++) {
        secret += base32chars[Math.floor(Math.random() * 32)];
    }
    
    document.getElementById('device_totp_secret').value = secret;
    showToast('TOTP Secret generiert', 'success');
}

// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

window.openDeviceModal = openDeviceModal;
window.saveDevice = saveDevice;
window.closeDeviceModal = closeDeviceModal;
window.deleteDevice = deleteDevice;
window.regenerateDeviceToken = regenerateDeviceToken;
window.copyDeviceToken = copyDeviceToken;
window.toggleDeviceTokenVisibility = toggleDeviceTokenVisibility;
window.generateTotpSecret = generateTotpSecret;
window.applyDeviceFilters = applyDeviceFilters;
