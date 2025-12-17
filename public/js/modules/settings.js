import { apiCall, currentUser, isAdmin } from './api.js';
import { showToast, showConfirm, isCacheValid, dataCache, invalidateCache } from './ui.js';
import { loadUserData } from './users.js';
import {datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime } from './utils.js';
import {debug} from '../app.js'

// ============================================
// SETTINGS
// Reference:
// import {} from './settings.js'
// ============================================

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadSettings(forceReload = false) {

    await loadUserData(forceReload);

    const userDetails = dataCache.userData.data.userDetails; 

    // Account-Informationen
    document.getElementById('settings_email').value = userDetails.email;
    const roleText = userDetails.role === 'admin' ? 'Administrator' :
                     userDetails.role === 'device' ? 'Gerät' :
                     'Benutzer';
    document.getElementById('settings_role').value = roleText;

    // Verknüpftes Mitglied anzeigen
    const memberInfoDiv = document.getElementById('settings_member_info');
    const memberInput = document.getElementById('settings_member');
    
    if (userDetails.member_id) {
        // Hole Mitglieds-Details
        const member = await apiCall('members', 'GET', null, { id: userDetails.member_id });
        
        if (member) {
            memberInfoDiv.style.display = 'block';
            memberInput.value = `${member.surname}, ${member.name}`;
            
            // Optional: Mitgliedsnummer anzeigen
            if (member.member_number) {
                memberInput.value += ` (${member.member_number})`;
            }
        } else {
            memberInfoDiv.style.display = 'none';
        }
    } else {
        memberInfoDiv.style.display = 'none';
    }

     //API-Token neu laden und versteckt anzeigen
    const tokenInput = document.getElementById('settings_token');
    const toggleBtn = document.getElementById('toggleSettingsTokenBtn');
    const expiryInfo = document.getElementById('settingsTokenExpiryInfo');
    
    // API-Token
    if (userDetails.api_token) {
        document.getElementById('settings_token').value = userDetails.api_token;

        // Token als versteckt zurücksetzen
        tokenInput.type = 'password';
        toggleBtn.textContent = '👁️';
        
        // Ablaufdatum anzeigen
        if (userDetails.api_token_expires_at) {
            const expiresAt = new Date(userDetails.api_token_expires_at);
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
        } else {
            expiryInfo.style.display = 'none';
        }
    } else {
        tokenInput.value = '';
        expiryInfo.style.display = 'none';    
    }
}


// ============================================
// CRUD FUNCTIONS
// ============================================

export function initSettingsEventHandler()
{
    if (changePasswordForm) {
        changePasswordForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            
            try {
                await handlePasswordChange(e);
            } catch (error) {
                debug.error('Fehler beim Passwort ändern:', error);
            }
        });
    } 
}

export async function handlePasswordChange(e)
{            
    const currentPassword = document.getElementById('current_password').value;
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
        
    // Validierung
    if (newPassword !== confirmPassword) {
        showToast('Die Passwörter stimmen nicht überein', 'error');
        return;
    }
    
    if (newPassword.length < 6) {
        showToast('Das Passwort muss mindestens 6 Zeichen lang sein', 'error');
        return;
    }
    
    // API-Call zum Passwort ändern
    const result = await apiCall('change_password', 'POST', {
        current_password: currentPassword,
        new_password: newPassword
    });
    
    if (result && result.message) {
        document.getElementById('changePasswordForm').reset();
        showToast('Passwort erfolgreich geändert', 'success');
    } else {
        document.getElementById('changePasswordForm').reset();
        showToast(result?.message || 'Passwort konnte nicht geändert werden', 'error');
    }
}

// ============================================
// TOKEN MANAGEMENT
// ============================================

export function copySettingsToken() {
    const tokenInput = document.getElementById('settings_token');
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


// Token-Funktionen für Settings
export function toggleSettingsTokenVisibility() {
    
    const tokenInput = document.getElementById('settings_token');
    const toggleBtn = document.getElementById('toggleSettingsTokenBtn');

    if (tokenInput.type === 'password') {
        tokenInput.type = 'text';
        toggleBtn.textContent = '🙈'; // Auge durchgestrichen
    } else {
        tokenInput.type = 'password';
        toggleBtn.textContent = '👁️'; // Auge offen
    }
}

export async function regenerateSettingsToken() {
    const confirmed = await showConfirm(
        'Token wirklich neu generieren? Der alte Token wird ungültig!',
        'Token neu generieren'
    );
    
    if (confirmed) {
        // Ohne user_id → eigener Token
        const result = await apiCall('regenerate_token', 'POST', {});
        
        if (result && result.api_token) {
            document.getElementById('settings_token').value = result.api_token;
            
            // Expiry-Info aktualisieren
            if (result.expires_at) {
                const expiresAt = new Date(result.expires_at);
                const expiresText = expiresAt.toLocaleDateString('de-DE', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
                document.getElementById('settingsTokenExpiryInfo').textContent = `Gültig bis: ${expiresText}`;
            }

            invalidateCache('userData');
            
            showToast('Neuer Token generiert!', 'success');
        }
    }
}

// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

window.regenerateSettingsToken = regenerateSettingsToken;
window.copySettingsToken = copySettingsToken;
window.toggleSettingsTokenVisibility = toggleSettingsTokenVisibility;

