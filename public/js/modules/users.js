import { apiCall, currentUser, isAdmin } from './api.js';
import { loadMembers } from './members.js';
import { showToast, showConfirm, dataCache, isCacheValid,invalidateCache} from './ui.js';
import { updateModalId } from './utils.js';

// ============================================
// USERS
// Reference:
// import {} from './users.js'
// ============================================

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadUsers(forceReload = false) {

    if(!isAdmin)
    {
        return;
    }

    if (!forceReload && isCacheValid('users')) {
        console.log("Loading USERS from CACHE");
        //renderUsers(dataCache.users.data);
        return dataCache.users.data;
    }

    console.log("Loading USERS from API");
    const users = await apiCall('users');
    
    // Cache speichern
    dataCache.users.data = users;
    dataCache.users.timestamp = Date.now();
    
    return users;
    //renderUsers(dataCache.users.data);
}

export async function loadUserData(forceReload = false) {

    if(!forceReload && isCacheValid('userData'))
    {
        console.log("Loading USER DETAILS (ME) from CACHE");
        return;
    }

    console.log("Loading USER DETAILS (ME) from API");
    const userData = await apiCall('me');
    const userDetails = await apiCall('users', 'GET', null, { id: userData.user_id });
        
    // userData Cache separat speichern
    dataCache.userData.data = { userData, userDetails };
    dataCache.userData.timestamp = Date.now();
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

function renderUsers(userData)
{
    if(!isAdmin)
        return;

const tbody = document.getElementById('usersTableBody');
    tbody.innerHTML = '';
    
    userData.forEach(user => {
        const createdAt = new Date(user.created_at);
        const formattedCreated = createdAt.toLocaleDateString('de-DE');

        // Rollentext mit Device-Type
        let roleText = user.role === 'admin' ? 'Admin' : 
                       user.role === 'user' ? 'Benutzer' : 
                       'Gerät ';
        
        if (user.role === 'device' && user.device_type) {
            const deviceTypes = {
                'totp_location': 'TOTP-Location',
                'auth_device': 'Auth-Device'
            };
            roleText += ` (${deviceTypes[user.device_type] || user.device_type})`;
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

export async function showUserSection(forceReload = false)
{
    console.log("Show User Section ()");
    const allUsers = await loadUsers(forceReload);
    renderUsers(allUsers);
}

function toggleUserRoleFields() {

    const role = document.getElementById('user_role').value;
    const deviceType = document.getElementById('user_device_type').value;
    const memberGroup = document.getElementById('userMemberGroup');
    const memberSelect = document.getElementById('user_member');
    const passwordGroup = document.querySelector('label[for="user_password"]').parentElement;
    const deviceTypeGroup = document.getElementById('userDeviceTypeGroup');
    const totpSecretGroup = document.getElementById('userTotpSecretGroup');
    const tokenGroup = document.getElementById('userTokenGroup');
    
    if (role === 'device') {
        // Device: Kein Mitglied, kein Passwort
        memberGroup.style.display = 'none';
        memberSelect.required = false;
        memberSelect.value = '';
        
        passwordGroup.style.display = 'none';
        document.getElementById('user_password').required = false;
        
        // Device-Type erforderlich
        deviceTypeGroup.style.display = 'block';
        document.getElementById('user_device_type').required = true;
        
        // Hints aktualisieren
        updateDeviceTypeHint(deviceType);
        
        // Felder je nach Device-Type
        if (deviceType === 'totp_location') {
            // TOTP-Location: Secret erforderlich, kein Token
            totpSecretGroup.style.display = 'block';
            document.getElementById('user_totp_secret').required = true;
            tokenGroup.style.display = 'none';
            
        } else if (deviceType === 'auth_device') {
            // Auth-Device: Token erforderlich, kein Secret
            totpSecretGroup.style.display = 'none';
            document.getElementById('user_totp_secret').required = false;
            
            // Token nur bei Bearbeitung anzeigen
            const userId = document.getElementById('user_id').value;
            tokenGroup.style.display = userId ? 'block' : 'none';
            
        } else {
            // Kein Type gewählt
            totpSecretGroup.style.display = 'none';
            tokenGroup.style.display = 'none';
        }
        
    } else {
        // Admin/User: Mitglied optional, Passwort erforderlich
        memberGroup.style.display = 'block';
        memberSelect.required = false;
        
        passwordGroup.style.display = 'block';
        const userId = document.getElementById('user_id').value;
        document.getElementById('user_password').required = !userId;
        
        // Device-Felder verstecken
        deviceTypeGroup.style.display = 'none';
        document.getElementById('user_device_type').required = false;
        totpSecretGroup.style.display = 'none';
        tokenGroup.style.display = userId ? 'block' : 'none';
    }
}
   
function updateDeviceTypeHint(deviceType) {
    const hints = {
        'totp_location': '🔢 Zeigt TOTP-Code (QR/NFC/Display), User authentifizieren sich per App. Benötigt TOTP Secret.',
        'auth_device': '🔐 Authentifiziert Member (z.B. Fingerabdruck, Karte, PIN). Benötigt API-Token.'
    };
    
    const hintElement = document.getElementById('deviceTypeHint');
    hintElement.textContent = hints[deviceType] || '';
    hintElement.style.color = deviceType ? '#667eea' : '#7f8c8d';
}

// ============================================
// MODAL FUNCTIONS
// ============================================

export async function openUserModal(userId = null) {
    const modal = document.getElementById('userModal');
    const title = document.getElementById('userModalTitle');
    
    // Lade Mitglieder für Dropdown

    if(dataCache.members.data.length === 0)
    {
        loadMembers(true);
    }

    const memberSelect = document.getElementById('user_member');
    memberSelect.innerHTML = '<option value="">Kein Mitglied</option>';
    
    if (dataCache.members.data) {
        dataCache.members.data.forEach(member => {
            memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
        });
    }

    // Event Listener EINMAL registrieren (mit removeEventListener zuerst)
    const roleSelect = document.getElementById('user_role');
    const deviceTypeSelect = document.getElementById('user_device_type');
    
    roleSelect.removeEventListener('change', toggleUserRoleFields);
    deviceTypeSelect.removeEventListener('change', toggleUserRoleFields);
    
    roleSelect.addEventListener('change', toggleUserRoleFields);
    deviceTypeSelect.addEventListener('change', toggleUserRoleFields);

    if (userId) {
        title.textContent = 'Benutzer bearbeiten';
        await loadUserFormData(userId);
        document.getElementById('user_password').required = false;
        updateModalId('userModal', userId);

    } else {
        title.textContent = 'Neuer Benutzer';
        document.getElementById('userForm').reset();
        document.getElementById('user_id').value = '';
        document.getElementById('user_active').checked = true;
        document.getElementById('user_password').required = true;

        updateModalId('userModal', null);
        
        // Token-Gruppe verstecken und zurücksetzen
        resetTokenDisplay();
    }

    toggleUserRoleFields();
    modal.classList.add('active');
}

export function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
}

// ============================================
// CRUD FUNCTIONS
// ============================================

export async function loadUserFormData(userId) {
    const user = await apiCall('users', 'GET', null, { id: userId });
    
    if (user) {
        console.log('User loaded from API:', user);

        document.getElementById('user_id').value = user.user_id;
        document.getElementById('user_email').value = user.email;
        document.getElementById('user_role').value = user.role;
        document.getElementById('user_member').value = user.member_id || '';
        document.getElementById('user_active').checked = user.is_active == 1;
        document.getElementById('user_password').value = '';
        document.getElementById('user_device_type').value = user.device_type || '';
        document.getElementById('user_totp_secret').value = user.totp_secret || '';

        // Token-Anzeige aktualisieren
        if (user.api_token) {
            updateTokenDisplay(user.api_token, user.api_token_expires_at);
        } else {
            resetTokenDisplay();
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
        data.device_type = null;
        data.totp_secret = null;
    } else {
        data.member_id = null;
        data.device_type = document.getElementById('user_device_type').value;
        
        if (data.device_type === 'totp_location') {
            data.totp_secret = document.getElementById('user_totp_secret').value || null;
        } else {
            data.totp_secret = null;
        }
    }
    
    // Passwort nur mitschicken wenn gesetzt
    if (password) { 
        data.password = password;
    }

   // console.log('Saving User:', data);
    
    let result;
    if (userId) {
        result = await apiCall('users', 'PUT', data, { id: userId });

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

        // Zeige Token nach Erstellung (besonders wichtig für Auth Device)
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
        showUserSection(true);

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
            showUserSection(true);
            showToast(`User "${email}" wurde gelöscht`, 'success');        
        }
    }
}

// ============================================
// TOKEN MANAGEMENT
// ============================================


function updateTokenDisplay(token, expiresAt) {
    const tokenGroup = document.getElementById('userTokenGroup');
    const tokenInput = document.getElementById('user_token');
    const expiryInfo = document.getElementById('tokenExpiryInfo');
    
    tokenGroup.style.display = 'block';
    tokenInput.value = token;
    tokenInput.type = 'password';
    document.getElementById('toggleUserTokenBtn').textContent = '👁️';
    
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
    const tokenGroup = document.getElementById('userTokenGroup');
    const tokenInput = document.getElementById('user_token');
    const expiryInfo = document.getElementById('tokenExpiryInfo');
    
    tokenGroup.style.display = 'none';
    tokenInput.value = '';
    tokenInput.type = 'password';
    document.getElementById('toggleUserTokenBtn').textContent = '👁️';
    expiryInfo.style.display = 'none';
    expiryInfo.innerHTML = '';
}

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
    
    document.getElementById('user_totp_secret').value = secret;
    showToast('TOTP Secret generiert', 'success');
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
window.generateTotpSecret = generateTotpSecret;
