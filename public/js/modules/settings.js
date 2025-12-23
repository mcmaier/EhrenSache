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