/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { API_BASE } from '../config.js';
import { apiCall, isAdmin } from './api.js';
import { showConfirm, showToast, dataCache, updateSubgroupLabelElements } from './ui.js';
import { escapeHtml } from './utils.js';
import { debug } from '../app.js';
import { applyTheme } from '../theme.js';


let systemSettings = {};
const SETTINGS_TAB_KEY = 'settingsTab';
let settingsTabsInitialized = false;
let hasUnsavedChanges = false;
let logoUploadInitialized = false;
let colorResetInitialized = false;

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

    // Für subgroupLabel() etc. auch außerhalb der Einstellungen zugänglich machen
    dataCache.settings.data = systemSettings;
    dataCache.settings.timestamp = Date.now();

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

    // Gruppendialog, Gruppenliste etc. mit dem aktuellen Wort versehen
    updateSubgroupLabelElements();

    // Logo-Vorschau anzeigen
    if (settings.organization_logo) {
        const preview = document.getElementById('logo-preview');
        const removeBtn = document.getElementById('remove-logo-btn');

        if (preview && removeBtn) {
            preview.src = settings.organization_logo;
            preview.style.display = 'block';
            removeBtn.style.display = 'inline-block';
        }
    }

    // Logo-Upload nur EINMAL initialisieren
    if (!logoUploadInitialized) {
        setupLogoUpload();
        logoUploadInitialized = true;
    }

    // Color-Reset Buttons nur EINMAL initialisieren
    if (!colorResetInitialized) {
        setupColorReset();
        colorResetInitialized = true;
    }

    // SMTP Status anzeigen
    updateSmtpStatus(settings.smtp_configured === '1');

    // Stoerungen des Rate Limiting sichtbar machen (OI-28)
    updateRateLimiterStatus(settings);

    // Reset unsaved changes flag
    hasUnsavedChanges = false;
    updateSaveButtonState();
    clearTabMarks('has-changes');
    clearTabMarks('has-error');

    // Untertabs: Verdrahtung einmalig, danach den zuletzt gewaehlten Tab
    setupSettingsTabs();
    let gemerkt = null;
    try {
        gemerkt = sessionStorage.getItem(SETTINGS_TAB_KEY);
    } catch (e) {
        gemerkt = null;
    }
    showSettingsTab(gemerkt);

    applyFeatureSwitchState();

    // Event-Listener für Änderungen (nur markieren, nicht speichern)
    document.querySelectorAll('.setting-input').forEach(input => {
        input.removeEventListener('input', markAsChanged);
        input.addEventListener('input', markAsChanged);
        input.removeEventListener('change', markAsChanged);
        input.addEventListener('change', markAsChanged);
    });

    // Event-Listener für Speichern-Button
    const saveBtn = document.getElementById('saveSettingsBtn');
    if (saveBtn) {
        saveBtn.removeEventListener('click', saveAllSettings);
        saveBtn.addEventListener('click', saveAllSettings);
    }

    // Stand der Update-Prüfung anzeigen, ohne GitHub zu fragen
    loadUpdateStatus();
}

/**
 * Untertabs der Einstellungsseite.
 *
 * Der gewaehlte Tab lebt in sessionStorage — die Anwendung kennt keine
 * Adress-Navigation (der Bereich steht in sessionStorage['currentSection']),
 * und Hash-Routing nur fuer diese Seite waeren zwei Zustandsmodelle
 * nebeneinander. Siehe Spec 3.4.
 */
function setupSettingsTabs() {
    if (settingsTabsInitialized) {
        return;
    }

    document.querySelectorAll('.settings-tab-btn').forEach(btn => {
        btn.addEventListener('click', () => showSettingsTab(btn.dataset.settingsTab));
    });

    settingsTabsInitialized = true;
}

/** Schaltet auf einen Tab um. Unbekannter Schluessel → erster Tab. */
export function showSettingsTab(key) {
    const buttons = Array.from(document.querySelectorAll('.settings-tab-btn'));
    const panels  = Array.from(document.querySelectorAll('.settings-panel'));

    if (buttons.length === 0) {
        return;
    }

    const gueltig = buttons.some(b => b.dataset.settingsTab === key)
        ? key
        : buttons[0].dataset.settingsTab;

    buttons.forEach(b => b.classList.toggle('active', b.dataset.settingsTab === gueltig));
    panels.forEach(p => p.classList.toggle('active', p.dataset.settingsPanel === gueltig));

    try {
        sessionStorage.setItem(SETTINGS_TAB_KEY, gueltig);
    } catch (e) {
        // Privater Modus ohne sessionStorage: der Tab wird dann nicht gemerkt,
        // die Seite funktioniert trotzdem.
    }
}

/** Der Tab, in dem ein Feld liegt. */
function tabOfInput(input) {
    const panel = input ? input.closest('.settings-panel') : null;

    return panel ? panel.dataset.settingsPanel : null;
}

/** Setzt die Markierung eines Tabs. */
function markTab(key, klasse, an) {
    const btn = document.querySelector(`.settings-tab-btn[data-settings-tab="${key}"]`);
    if (btn) {
        btn.classList.toggle(klasse, an);
    }
}

function clearTabMarks(klasse) {
    document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove(klasse));
}

/** Fuer ui.js: Ist im Einstellungsbereich etwas ungespeichert? */
export function hasUnsavedSettings() {
    return hasUnsavedChanges;
}

/**
 * Graut die Parameter eines abgeschalteten Bereichs aus.
 *
 * Muster aus Spec 3.2: Die erste Karte eines Tabs traegt den Schalter, die
 * abhaengigen Felder stehen darunter. `disabled` statt blasser Farbe — sonst
 * wird ein Wert gespeichert, den niemand liest.
 */
const FEATURE_SWITCHES = {
    worktime_enabled:    ['worktime_max_session_hours', 'worktime_require_note'],
    station_pin_enabled: ['station_pin_min_length'],
    punctuality_enabled: ['punctuality_grace_minutes'],
};

function applyFeatureSwitchState() {
    Object.entries(FEATURE_SWITCHES).forEach(([schalter, abhaengige]) => {
        const box = document.querySelector(`[data-key="${schalter}"]`);
        if (!box) {
            return;
        }

        abhaengige.forEach(key => {
            const feld = document.querySelector(`[data-key="${key}"]`);
            if (!feld) {
                return;
            }

            feld.disabled = !box.checked;
            const karte = feld.closest('.settings-card');
            if (karte) {
                karte.classList.toggle('is-disabled', !box.checked);
            }
        });
    });
}

function setupColorReset() {
    const resetButtons = document.querySelectorAll('.btn-reset-color');

    // Standardwerte aus CSS-Variablen
    const defaults = {
        'setting_primary_color': getComputedStyle(document.documentElement)
            .getPropertyValue('--primary-color').trim() || '#1F5FBF',
        'setting_secondary_color': getComputedStyle(document.documentElement)
            .getPropertyValue('--secondary-color').trim() || '#4CAF50',
        'setting_background_color': getComputedStyle(document.documentElement)
            .getPropertyValue('--background-color').trim() || '#f8f9fa'
    };
    
    resetButtons.forEach(button => {
        button.addEventListener('click', function() {
            const inputId = this.dataset.input;
            const defaultValue = this.dataset.default;
            const input = document.getElementById(inputId);
            
            if (input) {
                input.value = defaultValue;
                markAsChanged();
                showToast('Standardfarbe wiederhergestellt', 'info');
            }
        });
    });
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

function markAsChanged(event) {
    
    if (!event || !event.target) {
        hasUnsavedChanges = true;
        updateSaveButtonState();
        return;
    }

    const input = event.target;
    
    // Validierung für Number-Inputs
    if (input.type === 'number') {
        const raw = input.value.trim();
        const value = parseInt(raw);
        const min = parseInt(input.min);
        const max = parseInt(input.max);

        if (!/^-?\d+$/.test(raw) || isNaN(value) || value < min || value > max) {
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

    // Der Punkt am Reiter zeigt, wo etwas offen ist — mit Tabs ist die
    // Aenderung sonst unsichtbar, sobald jemand weiterblaettert.
    const tab = tabOfInput(input);
    if (tab) {
        markTab(tab, 'has-changes', true);
    }

    if (input.type === 'checkbox' && Object.prototype.hasOwnProperty.call(FEATURE_SWITCHES, input.dataset.key)) {
        applyFeatureSwitchState();
    }
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

/**
 * Die drei Farbschwellen muessen aufsteigend sein (Spec 3.7).
 *
 * Der Server kann das nicht erzwingen: Jede Einstellung wird einzeln per PUT
 * geschrieben, ein Zwischenstand verletzt die Regel zwangslaeufig. Geprueft
 * wird deshalb hier, bevor irgendetwas hinausgeht; der lesende Helfer auf dem
 * Server sortiert zusaetzlich defensiv.
 */
function rateThresholdsOrdered() {
    const felder = ['rate_threshold_mid', 'rate_threshold_fair', 'rate_threshold_good']
        .map(key => document.querySelector(`[data-key="${key}"]`));

    if (felder.some(f => !f)) {
        return true;
    }

    const werte = felder.map(f => parseInt(f.value, 10));
    if (werte.some(w => isNaN(w))) {
        return true; // Leere Felder faengt die Bereichspruefung ab
    }

    const inOrdnung = werte[0] < werte[1] && werte[1] < werte[2];

    if (!inOrdnung) {
        felder.forEach(f => {
            f.classList.add('invalid');
            markTab(tabOfInput(f), 'has-error', true);
        });
    }

    return inOrdnung;
}

async function saveAllSettings() {
    const inputs = document.querySelectorAll('.setting-input');
    const updates = [];

    // Alte Markierungen weg: Was jetzt rot wird, stammt aus diesem Versuch.
    document.querySelectorAll('.setting-input.invalid').forEach(i => i.classList.remove('invalid'));
    clearTabMarks('has-error');

    // Validierung vor dem Speichern
    let hasErrors = false;

    inputs.forEach(input => {
        const key = input.dataset.key;

        if(!key) {
            debug.warn('Input ohne data-key gefunden:', input);
            return;
        }

        let value;

        if (input.type === 'checkbox') {
            value = input.checked ? '1' : '0';
        }

        else if (input.type === 'number') {
            const raw = input.value.trim();
            value = parseInt(raw);
            const min = parseInt(input.min);
            const max = parseInt(input.max);

            if (!/^-?\d+$/.test(raw) || isNaN(value) || value < min || value > max) {
                input.classList.add('invalid');
                markTab(tabOfInput(input), 'has-error', true);
                hasErrors = true;
                return;
            }
            else
            {
                // Den GEPRUEFTEN Wert schicken, nicht den Rohstring: Frueher
                // ging input.value ungetrimmt hinaus (Teilbefund aus OI-21).
                value = String(value);
            }
        }
        else if(input.type === 'hidden' && key === 'organization_logo'){
            value = input.value;

            if (!value && !systemSettings[key]) {
                return; // Beide leer → keine Änderung
            }
        }
        else {
            value = input.value;
        }           

        // Nur speichern wenn Wert sich WIRKLICH geändert hat
        const oldValue = systemSettings[key];
        
        // Typ-sichere Vergleiche
        const normalizedOld = oldValue === undefined ? '' : String(oldValue);
        const normalizedNew = value === undefined ? '' : String(value);
        
        if (normalizedOld !== normalizedNew) {
            updates.push({ key, value });
        }                
    });
    
    if (!rateThresholdsOrdered()) {
        hasErrors = true;
    }

    if (hasErrors) {
        springZumErstenFehler();
        showToast('Bitte korrigiere die ungültigen Eingaben', 'error');
        return;
    }

    if (updates.length === 0) {
        showToast('Keine Änderungen zum Speichern', 'info');
        return;
    }

    const abgelehnt = [];

    try {
        // Alle Änderungen nacheinander speichern. Ein Fehlschlag bricht NICHT
        // ab (OI-31): Die uebrigen Schluessel werden abgearbeitet, die
        // gescheiterten gesammelt und am Ende benannt — sonst bliebe
        // unklar, was gespeichert wurde und was nicht.
        for (const update of updates) {
            const result = await apiCall('settings', 'PUT', {
                setting_key: update.key,
                setting_value: update.value
            });

            if (!result || !result.success) {
                abgelehnt.push(update.key);

                const feld = document.querySelector(`[data-key="${update.key}"]`);
                if (feld) {
                    feld.classList.add('invalid');
                    markTab(tabOfInput(feld), 'has-error', true);
                }

                continue;
            }

            // Lokalen Cache aktualisieren
            systemSettings[update.key] = update.value;

            // Feiertage haengen am Bundesland -- der Kalender laedt sie neu (FI-16).
            if (update.key === 'holiday_region') {
                Object.keys(dataCache.holidays).forEach(year => delete dataCache.holidays[year]);
            }
        }

        applyTheme(systemSettings);

        // Wurde die Zeiterfassung ein- oder ausgeschaltet, muss die Navigation
        // sofort folgen — sonst bliebe der Punkt bis zum Neuladen falsch.
        if (updates.some(u => u.key === 'worktime_enabled')) {
            const { resetWorktimeEnabled, checkWorktimeEnabled } = await import('./worktime.js');
            resetWorktimeEnabled();
            await checkWorktimeEnabled();
        }

        // Stations-PIN-Einstellungen sind in members.js gecacht (Modal-Feld
        // erscheint nur bei aktivierter Anmeldung) — nach einer Änderung hier
        // muss der Cache verworfen werden, sonst zeigt das Modal bis zum
        // nächsten Neuladen den alten Zustand.
        if (updates.some(u => u.key === 'station_pin_enabled' || u.key === 'station_pin_min_length')) {
            const { resetStationPinSettings } = await import('./members.js');
            resetStationPinSettings();
        }

        // Das Check-in-Fenster ist zugleich der Vorlauf, ab dem ein Termin als
        // begonnen gilt (OI-89) -- appointments.js haelt ihn zwischengespeichert.
        if (updates.some(u => u.key === 'checkin_tolerance_hours')) {
            const { resetAttendanceLead } = await import('./appointments.js');
            resetAttendanceLead();
        }

        // Gruppendialog und Gruppenliste zeigen das Wort ohne Neuladen der Seite
        if (updates.some(u => u.key === 'subgroup_label')) {
            updateSubgroupLabelElements();
        }

        const gespeichert = updates.length - abgelehnt.length;

        hasUnsavedChanges = abgelehnt.length > 0;
        updateSaveButtonState();

        if (abgelehnt.length === 0) {
            clearTabMarks('has-changes');
            showToast(`${gespeichert} Einstellung(en) gespeichert`, 'success');
        } else {
            springZumErstenFehler();
            showToast(`${gespeichert} gespeichert, ${abgelehnt.length} abgelehnt`, 'error');
        }
        
    } catch (error) {
        showToast('Fehler beim Speichern', 'error');
    }
}


/** Zeigt den ersten Tab, auf dem ein Feld beanstandet wurde. */
function springZumErstenFehler() {
    const fehler = document.querySelector('.settings-tab-btn.has-error');
    if (fehler) {
        showSettingsTab(fehler.dataset.settingsTab);
        return;
    }

    const feld = document.querySelector('.setting-input.invalid');
    const tab  = tabOfInput(feld);
    if (tab) {
        showSettingsTab(tab);
    }
}

function setupLogoUpload() {
    const uploadBtn = document.getElementById('upload-logo-btn');
    const removeBtn = document.getElementById('remove-logo-btn');
    const fileInput = document.getElementById('logo-upload');
    const preview = document.getElementById('logo-preview');
    const hiddenInput = document.getElementById('setting_organization_logo');
    
    if (!uploadBtn || !fileInput) return;

    // Upload Button
    uploadBtn?.addEventListener('click', () => {
        fileInput.click();
    });
    
    // File ausgewählt
    fileInput?.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        
        // Validierung
        const validTypes = ['image/png', 'image/jpeg', 'image/svg+xml'];
        if (!validTypes.includes(file.type)) {
            showToast('Nur PNG, JPG oder SVG erlaubt', 'error');
            return;
        }
        
        if (file.size > 500 * 1024) { // 500KB
            showToast('Datei zu groß (max. 500KB)', 'error');
            return;
        }
        
        // Upload
        try {
            const formData = new FormData();
            formData.append('logo', file);
            formData.append('csrf_token', sessionStorage.getItem('csrf_token')); // CSRF Token hinzufügen    
            
            debug.log("Uploading Image - API Call")
            const response = await fetch(`${API_BASE}?resource=upload-logo`, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            debug.log("API Response:", result);
            
            if (result.success && result.path) {
                // Vorschau aktualisieren
                preview.src = result.path;
                preview.style.display = 'block';
                removeBtn.style.display = 'inline-block';
                
                // Hidden input aktualisieren
                hiddenInput.value = result.path;
                
                markAsChanged();
                showToast('Logo hochgeladen', 'success');
                return;
            } else {
                debug.log("Upload Failed", result);
                showToast(result.error || 'Upload fehlgeschlagen', 'error');
            }
        } catch (error) {
            debug.log("Upload Exception", error)
            showToast('Upload fehlgeschlagen', 'error');
        }
    });
    
    // Remove Button
    removeBtn?.addEventListener('click', () => {
        preview.style.display = 'none';
        preview.src = '';
        removeBtn.style.display = 'none';
        hiddenInput.value = '';
        fileInput.value = '';
        markAsChanged();
    });
}

// ============================================
// DATA cleanup
// ============================================

export async function performCleanup() {
    const years         = parseInt(document.getElementById('cleanup_years').value, 10);
    const yearsWorktime = parseInt(document.getElementById('cleanup_years_worktime').value, 10);
    const yearsAudit    = parseInt(document.getElementById('cleanup_years_audit').value, 10);

    // Eine Frist von 0 ergäbe den heutigen Tag als Stichtag und löschte damit
    // den gesamten Bestand. Der Server weist das ebenfalls ab; hier steht die
    // Prüfung, damit der Nutzer eine Meldung statt eines Fehlers bekommt.
    if ([years, yearsWorktime, yearsAudit].some(v => !Number.isInteger(v) || v < 1)) {
        document.getElementById('cleanup_result').innerHTML = `
            <div class="error-message">❌ Jede Frist muss eine ganze Zahl ab 1 Jahr sein.</div>
        `;
        return;
    }

    // Die Bereinigung rechnet mit den Werten, die im Formular STEHEN — nicht
    // mit den gespeicherten. Wer eine Frist geaendert und nicht gespeichert
    // hat, loescht also nach einer Zahl, die nirgends hinterlegt ist. Das
    // gehoert in die Rueckfrage, sonst faellt es niemandem auf.
    const ungespeicherteFristen = [
        ['cleanup_years_records',  years],
        ['cleanup_years_worktime', yearsWorktime],
        ['cleanup_years_audit',    yearsAudit],
    ].some(([key, wert]) => String(systemSettings[key] ?? '') !== String(wert));

    const fristHinweis = ungespeicherteFristen
        ? '\n⚠️ Die angezeigten Fristen sind nicht gespeichert. Gelöscht wird nach den '
          + 'Werten oben, nicht nach den hinterlegten.\n'
        : '';

    const confirmed = await showConfirm(
        'Wirklich endgültig löschen?\n\n'
        + `• Anwesenheiten und Ausnahmen älter als ${years} Jahre\n`
        + `• Arbeitszeiten samt Änderungshistorie älter als ${yearsWorktime} Jahre\n`
        + `• Änderungshistorie ohne Sitzung älter als ${yearsAudit} Jahre wird anonymisiert\n`
        + fristHinweis
        + '\nDieser Vorgang kann nicht rückgängig gemacht werden!',
        'Warnung'
    );

    if(confirmed)
    {
        try {
            // Ohne Timeout (OI-85): Loeschfristen ueber einen grossen Bestand
            // duerfen laenger als 20 s laufen.
            const result = await apiCall('cleanup', 'POST', {
                years: years,
                years_worktime: yearsWorktime,
                years_audit: yearsAudit
            }, {}, { timeout: 0 });

            document.getElementById('cleanup_result').innerHTML = `
                <div class="success-message">
                    ✅ Bereinigung erfolgreich<br>
                    ${result.deleted_records} Anwesenheiten, ${result.deleted_exceptions} Ausnahmen
                    (älter als ${result.cutoff_date})<br>
                    ${result.deleted_work_sessions} Arbeitszeiten, ${result.deleted_work_session_log} Einträge
                    der Änderungshistorie (älter als ${result.cutoff_date_worktime})<br>
                    ${result.anonymized_work_session_log} verwaiste Einträge anonymisiert
                    (älter als ${result.cutoff_date_audit})
                </div>
            `;

            showToast('Datenlöschung erfolgreich','success');

        } catch(error) {
            document.getElementById('cleanup_result').innerHTML = `
                <div class="error-message">❌ Fehler: ${error.message}</div>
            `;
        }
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

// ============================================
// UPDATES
// ============================================

/** Gespeicherten Stand laden -- geht nicht nach außen. */
export async function loadUpdateStatus() {
    const status = await apiCall('update_check');
    if (status && status.success) {
        renderUpdateStatus(status);
    }
}

/** Auf Knopfdruck GitHub fragen. */
export async function checkForUpdates() {
    const knopf = document.getElementById('update_check_btn');
    const box   = document.getElementById('update_check_result');
    if (!box) {
        return;
    }

    if (knopf) knopf.disabled = true;
    box.textContent = 'Frage GitHub an …';

    try {
        // Ohne Timeout (OI-85): Das Holen des Pakets von GitHub darf dauern.
        const status = await apiCall('update_check', 'POST', {}, {}, { silentStatuses: [502], timeout: 0 });
        if (status && status.success) {
            renderUpdateStatus(status);
        } else {
            box.textContent = '❌ ' + (status?.message || 'Die Prüfung ist fehlgeschlagen.');
        }
    } finally {
        if (knopf) knopf.disabled = false;
    }
}

/**
 * Setzt den Stand ein. Alles, was aus der GitHub-Antwort stammt, geht nur über
 * textContent in die Seite.
 */
function renderUpdateStatus(status) {
    const installiert = document.getElementById('update_installed');
    const zuletzt     = document.getElementById('update_last_checked');
    const box         = document.getElementById('update_check_result');
    if (!installiert || !zuletzt || !box) {
        return;
    }

    installiert.textContent = 'v' + status.installed;
    zuletzt.textContent     = status.last_checked ? status.last_checked.slice(0, 16) : 'noch nie';
    box.replaceChildren();

    if (!status.latest) {
        return;
    }

    const meldung = document.createElement('div');
    if (!status.update_available) {
        meldung.className   = 'alert alert-info';
        meldung.textContent = `Diese Installation ist aktuell (neueste Version: ${status.latest.version}).`;
        box.append(meldung);
        return;
    }

    const datum = status.latest.published_at ? `, veröffentlicht am ${status.latest.published_at.slice(0, 10)}` : '';
    meldung.className   = 'alert alert-warning';
    meldung.textContent = `Version ${status.update_available} ist verfügbar${datum}.`;
    box.append(meldung);

    const anleitung = document.createElement('ol');
    [
        'Datenbank sichern.',
        'Auf dem Server den Inhalt von public/update/.htaccess leeren.',
        'Den Update-Assistenten unter /update aufrufen und „Neueste Version abfragen" wählen.',
    ].forEach(text => {
        const punkt = document.createElement('li');
        punkt.textContent = text;
        anleitung.append(punkt);
    });
    box.append(anleitung);

    if (typeof status.latest.html_url === 'string' && status.latest.html_url.startsWith('https://github.com/')) {
        const link = document.createElement('a');
        link.href        = status.latest.html_url;
        link.target      = '_blank';
        link.rel         = 'noopener noreferrer';
        link.textContent = 'Änderungen auf GitHub ansehen';
        box.append(link);
    }
}

window.checkForUpdates = checkForUpdates;
window.performCleanup = performCleanup;


// ============================================
// RATE LIMITING
// ============================================

/** Fenster, in dem eine Stoerung als aktuell gilt. */
const RATE_LIMITER_ALERT_HOURS = 24;

/**
 * Zeigt, ob der Schutz vor zu vielen Anfragen gerade arbeitet (OI-28).
 *
 * Der Server laesst eine Anfrage bewusst durch, wenn er seine Zaehler nicht
 * schreiben kann -- eine gestoerte Datenbank soll niemanden aussperren.
 * Sichtbar war das bisher nirgends: Bei OI-40 lief der Limiter unter strengem
 * SQL-Modus seit jeher ins Leere, ohne dass es jemandem auffiel. Die beiden
 * Schluessel setzt ausschliesslich der Server (rate_limiter.php).
 */
function updateRateLimiterStatus(settings) {
    const ziel = document.getElementById('rate_limiter_status');
    if (!ziel) return;

    const stempel = settings.rate_limiter_last_error_at;

    if (!stempel) {
        ziel.className = 'alert-success';
        ziel.style.padding = '12px';
        ziel.textContent = 'Arbeitet normal. Bisher keine Störung verzeichnet.';
        return;
    }

    // 'YYYY-MM-DD HH:MM:SS' ist Wanduhrzeit des Servers; Safari verlangt das T.
    const zeitpunkt = new Date(String(stempel).replace(' ', 'T'));
    const gueltig = !isNaN(zeitpunkt.getTime());
    const alterStunden = gueltig ? (Date.now() - zeitpunkt.getTime()) / 3600000 : Infinity;
    const wann = gueltig ? zeitpunkt.toLocaleString('de-DE') : escapeHtml(String(stempel));
    const code = settings.rate_limiter_last_error_code;

    if (alterStunden <= RATE_LIMITER_ALERT_HOURS) {
        ziel.className = 'alert-warning';
        ziel.style.padding = '12px';
        ziel.innerHTML = '<strong>Der Schutz greift derzeit nicht.</strong><br>'
            + 'Letzte Störung: ' + escapeHtml(wann)
            + (code ? ' (Fehler ' + escapeHtml(String(code)) + ')' : '')
            + '.<br>Anfragen werden unbegrenzt durchgelassen, bis die Datenbank die Zähler '
            + 'wieder annimmt. Die vollständige Meldung steht im Fehlerprotokoll von PHP.';
        return;
    }

    ziel.className = 'alert-success';
    ziel.style.padding = '12px';
    ziel.innerHTML = 'Arbeitet normal. Letzte Störung: ' + escapeHtml(wann)
        + (code ? ' (Fehler ' + escapeHtml(String(code)) + ')' : '') + '.';
}
