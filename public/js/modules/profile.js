/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { apiCall } from './api.js';
import { showToast, showConfirm, dataCache, invalidateCache } from './ui.js';
import { loadUserData } from './users.js';
import { debug } from '../app.js'
import { API_BASE } from '../config.js';

// ============================================
// PROFLE
// Reference:
// import {} from './profile.js'
// ============================================

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadProfile(forceReload = false) {

    await loadUserData(forceReload);

    const userDetails = dataCache.userData.data.userDetails; 

    // Account-Informationen
    document.getElementById('profile_email').value = userDetails.email;
    const roleText = userDetails.role === 'admin' ? 'Administrator' :
                     userDetails.role === 'manager' ? 'Manager' :
                     userDetails.role === 'device' ? 'Gerät' :
                     'Benutzer';
    document.getElementById('profile_role').value = roleText;

    // Verkn�pftes Mitglied anzeigen
    const memberInfoDiv = document.getElementById('profile_member_info');
    const memberInput = document.getElementById('profile_member');
    
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
    const tokenInput = document.getElementById('profile_token');
    const toggleBtn = document.getElementById('toggleProfileTokenBtn');
    const expiryInfo = document.getElementById('profileTokenExpiryInfo');
    
    // API-Token
    if (userDetails.api_token) {
        document.getElementById('profile_token').value = userDetails.api_token;

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
                : `<span style="color: var(--text-dark);">Gültig bis: ${expiresText}</span>`;
            expiryInfo.style.display = 'block';
        } else {
            expiryInfo.style.display = 'none';
        }
    } else {
        tokenInput.value = '';
        expiryInfo.style.display = 'none';
    }

    // Stations-PIN nur zeigen, wenn freigeschaltet und ein Mitglied verknuepft ist
    const card = document.getElementById('profilePinCard');
    try {
        const res = await apiCall('settings', 'GET', null, { scope: 'client' });
        const s   = res?.settings || {};
        const enabled = s.station_pin_enabled === '1' && !!userDetails.member_id;
        card.style.display = enabled ? 'block' : 'none';
        document.getElementById('new_pin_hint').textContent =
            `${parseInt(s.station_pin_min_length || '4', 10)}–8 Ziffern, keine Folge wie 1234, keine Wiederholung wie 0000`;
    } catch (e) {
        card.style.display = 'none';
    }
}


// ============================================
// CRUD FUNCTIONS
// ============================================

export function initProfileEventHandler()
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

    const changePinForm = document.getElementById('changePinForm');
    if (changePinForm) {
        changePinForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            await handlePinChange();
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
    
    if (newPassword.length < 8) {
        showToast('Das Passwort muss mindestens 8 Zeichen lang sein', 'error');
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

export async function handlePinChange() {
    const currentPassword = document.getElementById('pin_current_password').value;
    const newPin          = document.getElementById('new_pin').value;
    const confirmPin      = document.getElementById('confirm_pin').value;

    if (newPin !== confirmPin) {
        showToast('Die PINs stimmen nicht überein', 'error');
        return;
    }
    if (!/^\d{4,8}$/.test(newPin)) {
        showToast('Die PIN besteht aus 4 bis 8 Ziffern', 'error');
        return;
    }

    const result = await apiCall('change_pin', 'POST', {
        current_password: currentPassword,
        new_pin: newPin
    });

    document.getElementById('changePinForm').reset();
    if (result && result.message === 'PIN changed successfully') {
        showToast('PIN gespeichert', 'success');
    } else {
        showToast(result?.message || 'PIN konnte nicht gespeichert werden', 'error');
    }
}

// ============================================
// TOKEN MANAGEMENT
// ============================================

export function copyProfileToken() {
    const tokenInput = document.getElementById('profile_token');
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


// Token-Funktionen f�r Profile
export function toggleProfileTokenVisibility() {
    
    const tokenInput = document.getElementById('profile_token');
    const toggleBtn = document.getElementById('toggleProfileTokenBtn');

    if (tokenInput.type === 'password') {
        tokenInput.type = 'text';
        toggleBtn.textContent = '🙈'; // Auge durchgestrichen
    } else {
        tokenInput.type = 'password';
        toggleBtn.textContent = '👁️'; // Auge offen
    }
}

export async function regenerateProfileToken() {
    const confirmed = await showConfirm(
        'Token wirklich neu generieren? Der alte Token wird ungültig!',
        'Token neu generieren'
    );
    
    if (confirmed) {
        // Ohne user_id ? eigener Token
        const result = await apiCall('regenerate_token', 'POST', {});
        
        if (result && result.api_token) {
            document.getElementById('profile_token').value = result.api_token;
            
            // Expiry-Info aktualisieren
            if (result.expires_at) {
                const expiresAt = new Date(result.expires_at);
                const expiresText = expiresAt.toLocaleDateString('de-DE', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
                document.getElementById('profileTokenExpiryInfo').textContent = `Gültig bis: ${expiresText}`;
            }

            invalidateCache('userData');
            
            showToast('Neuer Token generiert!', 'success');
        }
    }
}

// ============================================
// DSGVO DATA EXPORT
// ============================================

export async function downloadMyData(format = 'json') {

    const confirmed = await showConfirm('Ihre persönlichen Daten herunterladen?\n\nEnthält: Stammdaten, Anwesenheiten, Ausnahmen, Gruppenzugehörigkeiten','Download bestätigen');
    
    format = document.getElementById("profile_user_data_format").value;

    if(confirmed)
    {
        try {
            const url = new URL(API_BASE, window.location.origin);
            url.searchParams.append('resource', 'my_data');
            url.searchParams.append('format', format);
            
            // CSRF-Token für Session-Auth
            const csrfToken = sessionStorage.getItem('csrf_token');
            if(csrfToken) {
                url.searchParams.append('csrf_token', csrfToken);
            }
            
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'include'
            });
            
            if(!response.ok) {
                throw new Error('Export fehlgeschlagen');
            }
            
            // Datei herunterladen
            const blob = await response.blob();
            const downloadUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = downloadUrl;
            a.download = `meine_daten_${new Date().toISOString().split('T')[0]}.${format}`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(downloadUrl);
            
            showToast('Daten erfolgreich exportiert');
            
        } catch(error) {
            showToast('Fehler beim Export: ' + error.message, 'error');
        }
    }
}

// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

window.regenerateProfileToken = regenerateProfileToken;
window.copyProfileToken = copyProfileToken;
window.toggleProfileTokenVisibility = toggleProfileTokenVisibility;
window.downloadMyData = downloadMyData;