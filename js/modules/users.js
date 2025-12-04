import { apiCall, currentUser, isAdmin } from './api.js';
import { showToast, showConfirm } from './ui.js';


// ============================================
// USERS
// Reference:
// import {} from './users.js'
// ============================================

let currentDeviceConfigs = [];

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadUsers() {
    const users = await apiCall('users');
    
    if (!users) return;
    
    const tbody = document.getElementById('usersTableBody');
    tbody.innerHTML = '';
    
    users.forEach(user => {
        const createdAt = new Date(user.created_at);
        const formattedCreated = createdAt.toLocaleDateString('de-DE');

        // Rollendarstellung
        let roleText;
        if (user.role === 'admin') {
            roleText = 'Admin';
        } else if (user.role === 'device') {
            // Zeige Anzahl der Konfigurationen
            const configCount = user.device_config_count || 0;
            if (configCount === 0) {
                roleText = 'Gerät (nicht konfiguriert)';
            } else if (configCount === 1) {
                roleText = 'Gerät (1 Konfiguration)';
            } else {
                roleText = `Gerät (${configCount} Konfigurationen)`;
            }
        } else {
            roleText = 'Benutzer';
        }
        
        // Mitgliedsname aus JOIN
        let memberName = '-';
        if (user.member_id && user.name && user.surname) {
            memberName = `${user.surname}, ${user.name}`;
        } else if (user.member_id) {
            memberName = `Mitglied #${user.member_id}`;
        }

        // Lösch-Button nicht für den eigenen Account anzeigen
        const deleteBtn = (currentUser && user.user_id !== currentUser.user_id) ? `
            <button class="action-btn btn-delete" onclick="deleteUser(${user.user_id}, '${user.email}')">
                Löschen
            </button>
        ` : '';
        
        const row = `
            <tr>
                <td>${user.user_id}</td>
                <td>${user.email}</td>
                <td><span class="type-badge">${roleText}</span></td>
                <td>${memberName}</td>
                <td>${user.is_active ? 'Aktiv' : 'Inaktiv'}</td>
                <td>${formattedCreated}</td>
                <td>
                    <button class="action-btn btn-edit" onclick="openUserModal(${user.user_id})">
                        Bearbeiten
                    </button>
                    ${deleteBtn}
                </td>
            </tr>
        `;
        tbody.innerHTML += row;
    });
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

function toggleUserRoleFields() {
    const role = document.getElementById('user_role').value;
    const memberGroup = document.getElementById('userMemberGroup');
    const memberSelect = document.getElementById('user_member');
    const passwordGroup = document.querySelector('label[for="user_password"]').parentElement;
    const deviceConfigGroup = document.getElementById('deviceConfigGroup');
    
    if (role === 'device') {
        // Device: Kein Mitglied, kein Passwort, Device-Typ erforderlich
        memberGroup.style.display = 'none';
        memberSelect.required = false;
        memberSelect.value = '';
        
        passwordGroup.style.display = 'none';
        document.getElementById('user_password').required = false;
        
        deviceConfigGroup.style.display = 'block';
        
    } else {
        // Admin/User: Mitglied optional, Passwort erforderlich
        memberGroup.style.display = 'block';
        memberSelect.required = false;
        
        passwordGroup.style.display = 'block';
        const userId = document.getElementById('user_id').value;
        document.getElementById('user_password').required = !userId; // Nur bei Neuanlage
        
        deviceConfigGroup.style.display = 'none';
    }
}

// ============================================
// MODAL FUNCTIONS
// ============================================

export async function openUserModal(userId = null) {
    const modal = document.getElementById('userModal');
    const title = document.getElementById('userModalTitle');
    const deviceConfigGroup = document.getElementById('deviceConfigGroup');
    
    // Lade Mitglieder für Dropdown
    const members = await apiCall('members');
    const memberSelect = document.getElementById('user_member');
    memberSelect.innerHTML = '<option value="">Kein Mitglied</option>';
    
    if (members) {
        members.forEach(member => {
            memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
        });
    }

    if (userId) {
        title.textContent = 'Benutzer bearbeiten';
        await loadUserData(userId);
        document.getElementById('user_password').required = false;  

        //Device Config nur für Geräte laden
        const role = document.getElementById('user_role').value;
        if(role === 'device') {
             deviceConfigGroup.style.display = 'block';
            await loadDeviceConfigs(userId);
        } else {
            deviceConfigGroup.style.display = 'none';
            currentDeviceConfigs = [];
        }
    } else {
        title.textContent = 'Neuer Benutzer';
        document.getElementById('userForm').reset();
        document.getElementById('user_id').value = '';
        document.getElementById('user_active').checked = true;
        document.getElementById('user_password').required = true;
        deviceConfigGroup.style.display = 'none';
        currentDeviceConfigs = [];

        // Token-Gruppe verstecken und zurücksetzen
        const tokenGroup = document.getElementById('userTokenGroup');
        const tokenInput = document.getElementById('user_token');
        const toggleBtn = document.getElementById('toggleUserTokenBtn');
        const expiryInfo = document.getElementById('tokenExpiryInfo');

        tokenGroup.style.display = 'none';
        tokenInput.value = '';
        tokenInput.type = 'password';
        toggleBtn.textContent = '👁️';
        expiryInfo.style.display = 'none';
    }

    // Event Listener für Rollen-Wechsel
    document.getElementById('user_role').addEventListener('change', toggleUserRoleFields);
    toggleUserRoleFields();
    
    modal.classList.add('active');
}


export function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
}

// ============================================
// CRUD FUNCTIONS
// ============================================

export async function loadUserData(userId) {
    const user = await apiCall('users', 'GET', null, { id: userId });
    
    if (user) {
        document.getElementById('user_id').value = user.user_id;
        document.getElementById('user_email').value = user.email;
        document.getElementById('user_role').value = user.role;
        document.getElementById('user_member').value = user.member_id || '';
        document.getElementById('user_active').checked = user.is_active == 1;
        document.getElementById('user_password').value = '';

        // Token-Gruppe und Werte aktualisieren
        const tokenGroup = document.getElementById('userTokenGroup');
        const tokenInput = document.getElementById('user_token');
        const toggleBtn = document.getElementById('toggleUserTokenBtn');
        const expiryInfo = document.getElementById('tokenExpiryInfo');

        // Zeige Token-Gruppe
        tokenGroup.style.display = 'block';
        
        // Token-Wert setzen (auch wenn leer)
        tokenInput.value = user.api_token || '';
        
        // Token als versteckt zurücksetzen
        tokenInput.type = 'password';
        toggleBtn.textContent = '👁️';
        
        // NEU: Ablaufdatum aktualisieren
        if (user.api_token && user.api_token_expires_at) {
            const expiresAt = new Date(user.api_token_expires_at);
            const now = new Date();
            const isExpired = now > expiresAt;
            
            const expiresText = expiresAt.toLocaleDateString('de-DE', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
            
            expiryInfo.innerHTML = isExpired 
                ? `<span style="color: #e74c3c;">⚠️ Abgelaufen am: ${expiresText}</span>`
                : `<span style="color: #7f8c8d;">Gültig bis: ${expiresText}</span>`;
            expiryInfo.style.display = 'block';
        } else if (user.api_token) {
            // Token vorhanden, aber kein Ablaufdatum
            expiryInfo.innerHTML = '<span style="color: #7f8c8d;">Kein Ablaufdatum</span>';
            expiryInfo.style.display = 'block';
        } else {
            // Kein Token vorhanden
            expiryInfo.innerHTML = '<span style="color: #e74c3c;">Kein Token generiert</span>';
            expiryInfo.style.display = 'block';
        }        
        
        toggleUserRoleFields();
    }
}

export async function saveUser() {
    // Form-Validierung prüfen
    const form = document.getElementById('userForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const userId = document.getElementById('user_id').value;
    const password = document.getElementById('user_password').value;
    const role = document.getElementById('user_role').value;
    
    const data = {
        email: document.getElementById('user_email').value,
        role: role,
        is_active: document.getElementById('user_active').checked
    };

    // Mitglied nur bei User/Admin, nicht bei Device
    if (role !== 'device') {
        data.member_id = document.getElementById('user_member').value || null;
    } else {
        data.member_id = null;
    }
    
    // Passwort nur mitschicken wenn gesetzt
    if (password) {
        data.password = password;
    }
    
    let result;
    if (userId) {
        result = await apiCall('users', 'PUT', data, { id: userId });

        // Speichere Device Configs
        if (result) {
            await saveDeviceConfigs(userId);
        }

    } else {
        if (!password && role != 'device') {
            alert('Passwort ist erforderlich für neue Benutzer');
            return;
        }

        // Device braucht kein Passwort
        if (role === 'device') {
            data.password = null;
        } else {
            data.password = password;
        }

        result = await apiCall('users', 'POST', data);

        // Speichere Device Configs für neuen User
        if (result && result.id) {
            await saveDeviceConfigs(result.id);
        }

        // Zeige Token nach Erstellung (besonders wichtig für Devices)
        if (result && result.api_token) {
            showToast('Benutzer erfolgreich erstellt', 'success');
            
            // Modal schließen und neu öffnen mit Token-Anzeige
            setTimeout(async () => {
                await loadUsers(true);
                if (result.id) {
                    await openUserModal(result.id);
                }
            }, 500);
            return;
        }
    }
    
    if (result) {
        closeUserModal();
        await loadUsers(true);

        showToast(
            userId ? 'Benutzer wurde erfolgreich aktualisiert' : 'Benutzer wurde erfolgreich erstellt',
            'success'
        );                
    }
}

export async function deleteUser(userId, email) {
    const confirmed = await showConfirm(
        `Benutzer "${email}" wirklich löschen?`,
        'Benutzer löschen'
    );

    if (confirmed) {
        const result = await apiCall('users', 'DELETE', null, { id: userId });
        if (result) {
            loadUsers();

            showToast(`User "${email}" wurde gelöscht`, 'success');        
        }
    }
}

// ============================================
// TOKEN MANAGEMENT
// ============================================

export function copyUserToken() {
    const tokenInput = document.getElementById('user_token');
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

export function toggleUserTokenVisibility() {
    const tokenInput = document.getElementById('user_token');
    const toggleBtn = document.getElementById('toggleUserTokenBtn');
    
    if (tokenInput.type === 'password') {
        tokenInput.type = 'text';
        toggleBtn.textContent = '🙈'; // Auge durchgestrichen
    } else {
        tokenInput.type = 'password';
        toggleBtn.textContent = '👁️'; // Auge offen
    }
}

export async function regenerateUserToken() {
    const userId = document.getElementById('user_id').value;
    if (!userId) return;
    
    const confirmed = await showConfirm(
        'Token wirklich neu generieren? Der alte Token wird ungültig!',
        'Token neu generieren'
    );
    
    if (confirmed) {
        // Mit user_id → Token für anderen User
        const result = await apiCall('regenerate_token', 'POST', { 
            user_id: parseInt(userId)
        });
        
        if (result && result.api_token) {
            document.getElementById('user_token').value = result.api_token;
            
            // Expiry-Info aktualisieren
            if (result.expires_at) {
                const expiresAt = new Date(result.expires_at);
                const expiresText = expiresAt.toLocaleDateString('de-DE', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
                
                const expiryInfo = document.getElementById('tokenExpiryInfo');
                if (expiryInfo) {
                    expiryInfo.innerHTML = `<span style="color: #7f8c8d;">Gültig bis: ${expiresText}</span>`;
                }
            }
            
            showToast('Neuer Token generiert!', 'success');
        }
    }
}

// ============================================
// DEVICE CONFIG
// ============================================

async function loadDeviceConfigs(userId) {
    const response = await apiCall('device_configs', 'GET', null, { user_id: userId });
    currentDeviceConfigs = response || [];
    renderDeviceConfigs();
}

function renderDeviceConfigs() {
    const container = document.getElementById('deviceConfigsList');
    container.innerHTML = '';
    
    if (currentDeviceConfigs.length === 0) {
        container.innerHTML = '<p style="color: #7f8c8d;">Keine Geräte konfiguriert</p>';
        return;
    }
    
    currentDeviceConfigs.forEach((device, index) => {
        const div = document.createElement('div');
        div.style.cssText = 'border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; border-radius: 5px;';
        div.innerHTML = `
            <div style="margin-bottom: 10px;">
                <label style="display: block; margin-bottom: 5px;">Gerätename</label>
                <input type="text" value="${device.device_name}" 
                       onchange="updateDeviceConfig(${index}, 'device_name', this.value)" 
                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
            </div>
            <div style="margin-bottom: 10px;">
                <label style="display: block; margin-bottom: 5px;">Gerätetyp</label>
                <select onchange="updateDeviceConfig(${index}, 'device_type', this.value)"
                        style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="qr_location" ${device.device_type === 'qr_location' ? 'selected' : ''}>QR Location (TOTP)</option>
                    <option value="rfid" ${device.device_type === 'rfid' ? 'selected' : ''}>RFID</option>
                    <option value="nfc" ${device.device_type === 'nfc' ? 'selected' : ''}>NFC</option>
                    <option value="fingerprint" ${device.device_type === 'fingerprint' ? 'selected' : ''}>Fingerprint</option>
                    <option value="keypad" ${device.device_type === 'keypad' ? 'selected' : ''}>Keypad</option>
                </select>
            </div>
            <div style="margin-bottom: 10px;" id="secretField_${index}">
                <label style="display: block; margin-bottom: 5px;">Location Secret (TOTP)</label>
                <input type="text" value="${device.location_secret || ''}" 
                       onchange="updateDeviceConfig(${index}, 'location_secret', this.value)"
                       placeholder="Base32 Secret für TOTP"
                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;">
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <label style="margin: 0;">
                    <input type="checkbox" ${device.is_active ? 'checked' : ''}
                           onchange="updateDeviceConfig(${index}, 'is_active', this.checked)">
                    Aktiv
                </label>
                <button type="button" class="action-btn btn-delete" 
                        onclick="removeDeviceConfig(${index})">Löschen</button>
            </div>
        `;
        container.appendChild(div);
        
        // Secret-Feld nur für qr_location anzeigen
        if (device.device_type !== 'qr_location') {
            document.getElementById(`secretField_${index}`).style.display = 'none';
        }
    });
}

export function addDeviceConfig() {
    currentDeviceConfigs.push({
        device_config_id: null,
        device_type: 'qr_location',
        device_name: 'Neues Gerät',
        location_secret: null,
        is_active: true
    });
    renderDeviceConfigs();
}

export function updateDeviceConfig(index, field, value) {
    currentDeviceConfigs[index][field] = value;
    
    // Secret-Feld togglen bei Typ-Änderung
    if (field === 'device_type') {
        const secretField = document.getElementById(`secretField_${index}`);
        if (secretField) {
            secretField.style.display = value === 'qr_location' ? 'block' : 'none';
        }
    }
}

export async function removeDeviceConfig(index) {
    const confirmed = await showConfirm(
        `Gerät wirklich entfernen?`,
        'Gerät entfernen'
    );

    if (confirmed) {
        currentDeviceConfigs.splice(index, 1);
        renderDeviceConfigs();
    }
}


async function saveDeviceConfigs(userId) {
    // Hole bestehende Configs
    const existing = await apiCall('device_configs', 'GET', null, { user_id: userId });
    const existingIds = existing ? existing.map(e => e.device_config_id) : [];
    
    // Verarbeite alle aktuellen Configs
    for (const config of currentDeviceConfigs) {
        if (config.device_config_id) {
            // Update
            await apiCall('device_configs', 'PUT', {
                device_type: config.device_type,
                device_name: config.device_name,
                location_secret: config.location_secret,
                is_active: config.is_active
            }, { id: config.device_config_id });
            
            const index = existingIds.indexOf(config.device_config_id);
            if (index > -1) {
                existingIds.splice(index, 1);
            }
        } else {
            // Create
            await apiCall('device_configs', 'POST', {
                user_id: userId,
                device_type: config.device_type,
                device_name: config.device_name,
                location_secret: config.location_secret,
                is_active: config.is_active
            });
        }
    }
    
    // Lösche nicht mehr vorhandene Configs
    for (const deletedId of existingIds) {
        await apiCall('device_configs', 'DELETE', null, { id: deletedId });
    }
}

// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

window.openUserModal = openUserModal;
window.saveUser = saveUser;
window.closeUserModal = () => document.getElementById('userModal').classList.remove('active');
window.deleteUser = deleteUser;
window.regenerateUserToken = regenerateUserToken;
window.copyUserToken = copyUserToken;
window.toggleUserTokenVisibility = toggleUserTokenVisibility;
window.addDeviceConfig = addDeviceConfig;
window.saveDeviceConfigs = saveDeviceConfigs;
window.updateDeviceConfig = updateDeviceConfig;
window.removeDeviceConfig = removeDeviceConfig;