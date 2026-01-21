import { apiCall, isAdmin } from './api.js';
import { showToast } from './ui.js';
import { debug } from '../app.js';

let systemSettings = {};
let hasUnsavedChanges = false;

export let globalPaginationValue = 25;

// ============================================
// DATA FUNCTIONS
// ============================================

export async function loadSystemSettings() {
    if (!isAdmin) return;
    
    debug.log("Loading SYSTEM SETTINGS from API");
    const response = await apiCall('settings');
    systemSettings = {};
    
    // Array zu Object umwandeln für einfachen Zugriff
    response.settings.forEach(setting => {
        systemSettings[setting.setting_key] = setting.setting_value;
    });
    
    return systemSettings;
}

export async function loadPublicSettings() {
    debug.log("Loading PUBLIC SETTINGS from API");
    const response = await apiCall('appearance');
    return response.settings;
}

// ============================================
// RENDER FUNCTIONS
// ============================================

export async function renderSystemSettings() {
    if (!isAdmin) return;
    
    const settings = await loadSystemSettings();
    
    // Felder befüllen
    Object.keys(settings).forEach(key => {
        const input = document.querySelector(`[data-key="${key}"]`);
        if (input) {
            if (input.type === 'checkbox') {
                input.checked = settings[key] === '1';
            } else {
                input.value = settings[key];
            }
        }
    });

    // SMTP Status anzeigen
    updateSmtpStatus(settings.smtp_configured === '1');

    // Reset unsaved changes flag
    hasUnsavedChanges = false;
    updateSaveButtonState();
    
    // Event-Listener für Änderungen (nur markieren, nicht speichern)
    document.querySelectorAll('.setting-input').forEach(input => {
        input.removeEventListener('input', markAsChanged);
        input.addEventListener('input', markAsChanged);
    });

    // Event-Listener für Speichern-Button
    const saveBtn = document.getElementById('saveSettingsBtn');
    if (saveBtn) {
        saveBtn.removeEventListener('click', saveAllSettings);
        saveBtn.addEventListener('click', saveAllSettings);
    }
}

function updateSmtpStatus(configured) {
    const icon = document.getElementById('smtpStatusIcon');
    const text = document.getElementById('smtpStatusText');
    
    if (configured) {
        icon.textContent = '✅';
        text.textContent = 'SMTP ist konfiguriert und einsatzbereit';
        text.style.color = '#28a745';
    } else {
        icon.textContent = '⚠️';
        text.textContent = 'SMTP noch nicht konfiguriert - E-Mail-Versand nicht möglich';
        text.style.color = '#ffc107';
    }
}

function markAsChanged() {
    const input = event.target;
    
    // Validierung für Number-Inputs
    if (input.type === 'number') {
        const value = parseInt(input.value);
        const min = parseInt(input.min);
        const max = parseInt(input.max);
        
        if (isNaN(value) || value < min || value > max) {
            input.classList.add('invalid');
            input.setCustomValidity(`Wert muss zwischen ${min} und ${max} liegen`);
            return; // Nicht als geändert markieren
        } else {
            input.classList.remove('invalid');
            input.setCustomValidity('');
        }
    }
    
    hasUnsavedChanges = true;
    updateSaveButtonState();
}


function updateSaveButtonState() {
    const saveBtn = document.getElementById('saveSettingsBtn');
    if (saveBtn) {
        if (hasUnsavedChanges) {
            saveBtn.classList.add('has-changes');
            saveBtn.textContent = '💾 Speichern *';
        } else {
            saveBtn.classList.remove('has-changes');
            saveBtn.textContent = '💾 Speichern';
        }
    }
}

async function saveAllSettings() {
    const inputs = document.querySelectorAll('.setting-input');
    const updates = [];

    // Validierung vor dem Speichern
    let hasErrors = false;
    inputs.forEach(input => {
        if (input.type === 'number') {
            const value = parseInt(input.value);
            const min = parseInt(input.min);
            const max = parseInt(input.max);
            
            if (isNaN(value) || value < min || value > max) {
                input.classList.add('invalid');
                hasErrors = true;
            }
        }
    });
    
    if (hasErrors) {
        showToast('Bitte korrigiere die ungültigen Eingaben', 'error');
        return;
    }
    
    inputs.forEach(input => {
        const key = input.dataset.key;
        let value;
        
        if (input.type === 'checkbox') {
            value = input.checked ? '1' : '0';
        } else {
            value = input.value;
        }
        
        // Nur speichern wenn Wert sich geändert hat
        if (systemSettings[key] !== value) {
            updates.push({ key, value });
        }
    });
    
    if (updates.length === 0) {
        showToast('Keine Änderungen zum Speichern', 'info');
        return;
    }
    
    try {
        // Alle Änderungen nacheinander speichern
        for (const update of updates) {
            await apiCall('settings', 'PUT', {
                setting_key: update.key,
                setting_value: update.value
            });
            
            // Lokalen Cache aktualisieren
            systemSettings[update.key] = update.value;
            
            // Theme-Einstellungen sofort anwenden
            applyThemeSetting(update.key, update.value);
        }
        
        hasUnsavedChanges = false;
        updateSaveButtonState();
        showToast(`${updates.length} Einstellung(en) gespeichert`, 'success');
        
    } catch (error) {
        showToast('Fehler beim Speichern', 'error');
    }
}

function applyThemeSetting(key, value) {
    const root = document.documentElement;
    
    if (key === 'primary_color') {
        root.style.setProperty('--primary-color', value);
    } else if (key === 'background_color') {
        root.style.setProperty('--background-color', value);
    } else if (key === 'organization_name') {
        document.title = "EhrenSache - " + value;
    }
    else if(key === 'pagination_limit')
    {
        debug.log("Pagination Value changed:",value);
        globalPaginationValue = value;
    }
}

// ============================================
// THEME (öffentlich)
// ============================================

export async function applyTheme() {
    const settings = await loadPublicSettings();
    const root = document.documentElement;
    
    if (settings.primary_color) {
        root.style.setProperty('--primary-color', settings.primary_color);
    }
    if (settings.background_color) {
        root.style.setProperty('--background-color', settings.background_color);
    }
    if (settings.organization_name) {
        document.title = settings.organization_name;
    }
}


// ============================================
// SMTP CONFIGURATION MODAL
// ============================================

window.openSmtpConfigModal = async function() {
    const modal = document.getElementById('smtpConfigModal');
    modal.classList.add('active');
    //modal.style.display = 'block';
        
    // Aktuelle SMTP-Config laden (ohne Passwort)
    try {
        const response = await apiCall('settings', 'POST', {
            action: 'get_smtp_config'
        });
        
        if (response.success && response.config) {
            document.getElementById('smtp_host').value = response.config.smtp_host || '';
            document.getElementById('smtp_port').value = response.config.smtp_port || 587;
            document.getElementById('smtp_encryption').value = response.config.smtp_encryption || 'tls';
            document.getElementById('smtp_user').value = response.config.smtp_user || '';
            // Passwort wird NICHT geladen (Sicherheit)
            document.getElementById('smtp_password').value = '';
            document.getElementById('smtp_password').placeholder = response.config.smtp_password_set 
                ? '••••••••' 
                : 'Passwort eingeben';
        }
    } catch (error) {
        debug.error('Error loading SMTP config:', error);
    }    
};

window.closeSmtpConfigModal = function() {
    document.getElementById('smtpConfigModal').classList.remove('active');
    document.getElementById('smtpConfigForm').reset();
};

window.saveSmtpConfig = async function() {
    const smtpData = {
        smtp_host: document.getElementById('smtp_host').value.trim(),
        smtp_port: parseInt(document.getElementById('smtp_port').value),
        smtp_encryption: document.getElementById('smtp_encryption').value,
        smtp_user: document.getElementById('smtp_user').value.trim(),
        smtp_password: document.getElementById('smtp_password').value
    };
    
    // Validierung
    if (!smtpData.smtp_host) {
        showToast('SMTP Server erforderlich', 'error');
        return;
    }
    
    if (isNaN(smtpData.smtp_port) || smtpData.smtp_port < 1 || smtpData.smtp_port > 65535) {
        showToast('Ungültiger Port', 'error');
        return;
    }
    
    try {
        const response = await apiCall('settings', 'POST', {
            action: 'save_smtp_config',
            config: smtpData
        });
        
        if (response.success) {
            showToast('SMTP-Konfiguration gespeichert', 'success');
            closeSmtpConfigModal();
            
            // SMTP Status aktualisieren
            updateSmtpStatus(true);
            
            // smtp_configured in systemSettings aktualisieren
            systemSettings.smtp_configured = '1';
        } else {
            showToast('Fehler: ' + response.message, 'error');
        }
    } catch (error) {
        showToast('Fehler beim Speichern: ' + error.message, 'error');
    }
};

// ============================================
// TEST MAIL
// ============================================

window.sendTestMail = async function() {
    const recipientInput = document.getElementById('test_mail_recipient');
    const recipient = recipientInput.value.trim();
    const testBtn = document.getElementById('testMailBtn');
    
    if (!recipient) {
        showToast('Bitte Email-Adresse eingeben', 'warning');
        recipientInput.focus();
        return;
    }
    
    if (!recipient.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        showToast('Ungültige Email-Adresse', 'error');
        recipientInput.focus();
        return;
    }
    
    // Prüfen ob SMTP konfiguriert ist
    if (systemSettings.smtp_configured !== '1') {
        showToast('SMTP muss zuerst konfiguriert werden', 'warning');
        return;
    }
    
    const originalText = testBtn.textContent;
    
    try {
        testBtn.disabled = true;
        testBtn.textContent = '📤 Senden...';
        
        const response = await apiCall('settings', 'POST', {
            action: 'test_mail',
            recipient: recipient
        });
        
        if (response.success) {
            showToast('Test-Email versendet! Bitte Posteingang prüfen.', 'success');
            recipientInput.value = '';
        } else {
            showToast('Fehler beim Versand: ' + response.message, 'error');
        }
        
    } catch (error) {
        debug.error('Test mail error:', error);
        showToast('Fehler: ' + error.message, 'error');
    } finally {
        testBtn.disabled = false;
        testBtn.textContent = originalText;
    }
};

// ============================================
// PASSWORD VISIBILITY TOGGLE
// ============================================

window.togglePasswordVisibility = function(inputId) {
    const input = document.getElementById(inputId);
    const button = event.target.closest('button');
    
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = '🙈';
    } else {
        input.type = 'password';
        button.textContent = '👁️';
    }
};
