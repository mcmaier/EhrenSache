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
import { apiCall } from './api.js';
import { debug } from '../app.js'
import { getAuthHeaders } from './api.js';
import { showToast } from './ui.js';
import { loadAppointments } from './appointments.js';
import { showRecordsSection } from './records.js';
import { showAppointmentSection } from './appointments.js';
import { showMemberSection } from './members.js';
import { showConfirm } from './ui.js';

// ============================================
// EXPORT
// ============================================

export function exportMembers() {
    const url = `${API_BASE}?resource=export&type=members`;
    
    // Download über versteckten Link
    const a = document.createElement('a');
    a.href = url;
    a.download = `members_export_${new Date().toISOString().split('T')[0]}.csv`;
    
    // Auth-Header kann bei direktem Download nicht gesetzt werden
    // Daher: Fetch verwenden und als Blob speichern
    fetch(url, {
        method: 'GET',
        headers: getAuthHeaders()
    })
    .then(response => {
        if (!response.ok) throw new Error('Export failed');
        return response.blob();
    })
    .then(blob => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `members_export_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        showToast('Export erfolgreich', 'success');
    })
    .catch(error => {
        debug.error('Export error:', error);
        showToast('Export fehlgeschlagen', 'error');
    });
}

export function exportAppointments() {
    const year = document.getElementById('appointmentYearFilter')?.value || new Date().getFullYear();
    const url = `${API_BASE}?resource=export&type=appointments&year=${year}`;
    
    fetch(url, {
        method: 'GET',
        headers: getAuthHeaders()
    })
    .then(response => response.blob())
    .then(blob => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `appointments_export_${year}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        showToast('Export erfolgreich', 'success');
    })
    .catch(error => {
        debug.error('Export error:', error);
        showToast('Export fehlgeschlagen', 'error');
    });
}

export function exportRecords() {
    const year = document.getElementById('recordYearFilter')?.value || new Date().getFullYear();
    const url = `${API_BASE}?resource=export&type=records&year=${year}`;
    
    fetch(url, {
        method: 'GET',
        headers: getAuthHeaders(),
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) throw new Error('Export failed');
        return response.blob();
    })
    .then(blob => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `records_export_${year}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        showToast('Records erfolgreich exportiert', 'success');
    })
    .catch(error => {
        debug.error('Export error:', error);
        showToast('Export fehlgeschlagen', 'error');
    });
}

// ============================================
// IMPORT
// ============================================

export function openImportModal() {
    const modal = document.getElementById('importModal');
    const importBtn = document.getElementById('importBtn');
    const cancelBtn = modal.querySelector('.btn-cancel');
    
    modal.classList.add('active');
    document.getElementById('importFile').value = '';
    document.getElementById('importProgress').style.display = 'none';
    document.getElementById('importResult').style.display = 'none';
    
    // Button zurücksetzen
    importBtn.textContent = 'Importieren';
    importBtn.disabled = false;
    importBtn.onclick = executeImport;
    
    // Abbrechen-Button wieder anzeigen
    cancelBtn.style.display = 'inline-block';
}

export function closeImportModal() {
    document.getElementById('importModal').classList.remove('active');
}

export async function executeImport() {
    const fileInput = document.getElementById('importFile');
    const file = fileInput.files[0];
    
    if (!file) {
        showToast('Bitte wähle eine CSV-Datei aus', 'error');
        return;
    }
    
    if (!file.name.endsWith('.csv')) {
        showToast('Nur CSV-Dateien erlaubt', 'error');
        return;
    }

    // UI aktualisieren
    const importBtn = document.getElementById('importBtn');
    const cancelBtn = document.querySelector('#importModal .btn-cancel');
    
    // UI aktualisieren
    importBtn.disabled = true;
    document.getElementById('importProgress').style.display = 'block';
    document.getElementById('importProgressFill').style.width = '50%';
    document.getElementById('importStatus').textContent = 'Importiere Daten...';
    
    // FormData für File Upload
    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', sessionStorage.getItem('csrf_token')); // CSRF Token hinzufügen    
    
    try {
        const response = await fetch(`${API_BASE}?resource=import&type=members`, {
            method: 'POST',
            credentials: 'same-origin', // Session-Cookies mit senden
            body: formData
        });        
        
        if (!response.ok) {
            const errorText = await response.text();
            debug.error('Import error response:', errorText);
            throw new Error(`Import failed: ${response.status}`);
        }
        
        const result = await response.json();

        debug.log('Import result:',result);
        
        // Progress auf 100%
        document.getElementById('importProgressFill').style.width = '100%';
        document.getElementById('importStatus').textContent = 'Abgeschlossen!';
        
        // Ergebnis anzeigen
        displayImportResult(result);
        
        
        // Button umwandeln zu "Schließen"
        importBtn.disabled = false;
        importBtn.textContent = 'Schließen';
        importBtn.onclick = function() {
            closeImportModal();
            showMemberSection(true);
        };       

        // Abbrechen-Button ausblenden
        cancelBtn.style.display = 'none';
        
    } catch (error) {
        debug.error('Import error:', error);
        document.getElementById('importProgress').style.display = 'none';
        showToast('Import fehlgeschlagen', 'error');
        importBtn.disabled = false;
    }
}

function displayImportResult(result) {
    const resultDiv = document.getElementById('importResult');
    const contentDiv = document.getElementById('importResultContent');

    if (!result || !result.success) {
        let html = '<div class="import-error">';
        html += `✗ Import fehlgeschlagen<br>`;
        html += result?.message || 'Unbekannter Fehler';
        html += '</div>';
        contentDiv.innerHTML = html;
        resultDiv.style.display = 'block';
        return;
    }
    
    let html = '<div class="import-success">';
    html += `✓ ${result.imported || 0} neue Mitglieder importiert<br>`;
    html += `✓ ${result.updated || 0} Mitglieder aktualisiert`;
    html += '</div>';
    
    if (result.errors && result.errors.length > 0) {
        html += '<div class="import-error">';
        html += '<strong>Warnungen:</strong><ul>';
        result.errors.forEach(error => {
            html += `<li>${error}</li>`;
        });
        html += '</ul></div>';
    }
    
    contentDiv.innerHTML = html;
    resultDiv.style.display = 'block';
}

// ============================================
// RECORDS IMPORT
// ============================================

export function openRecordsImportModal() {
    const modal = document.getElementById('recordsImportModal');
    const importBtn = document.getElementById('recordsImportBtn');
    const cancelBtn = modal.querySelector('.btn-cancel');
    
    modal.classList.add('active');
    document.getElementById('recordsImportFile').value = '';
    document.getElementById('recordsImportProgress').style.display = 'none';
    document.getElementById('recordsImportResult').style.display = 'none';
    
    // Button zurücksetzen
    importBtn.textContent = 'Importieren';
    importBtn.disabled = false;
    importBtn.onclick = executeRecordsImport;
    
    // Abbrechen-Button wieder anzeigen
    cancelBtn.style.display = 'inline-block';
}

export function closeRecordsImportModal() {
    document.getElementById('recordsImportModal').classList.remove('active');
}

export async function executeRecordsImport() {
    const fileInput = document.getElementById('recordsImportFile');
    const file = fileInput.files[0];
    
    if (!file) {
        showToast('Bitte wähle eine CSV-Datei aus', 'error');
        return;
    }
    
    if (!file.name.endsWith('.csv')) {
        showToast('Nur CSV-Dateien erlaubt', 'error');
        return;
    }
    
    // UI aktualisieren
    const importBtn = document.getElementById('recordsImportBtn');
    const cancelBtn = document.querySelector('#recordsImportModal .btn-cancel');
    
    importBtn.disabled = true;
    document.getElementById('recordsImportProgress').style.display = 'block';
    document.getElementById('recordsImportProgressFill').style.width = '50%';
    document.getElementById('recordsImportStatus').textContent = 'Importiere Daten...';
    
    // FormData für File Upload
    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', sessionStorage.getItem('csrf_token'));
    
    try {
        const response = await fetch(`${API_BASE}?resource=import&type=records`, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });        
        
        if (!response.ok) {
            const errorText = await response.text();
            debug.error('Import error response:', errorText);
            throw new Error(`Import failed: ${response.status}`);
        }
        
        const result = await response.json();
        
        // Progress auf 100%
        document.getElementById('recordsImportProgressFill').style.width = '100%';
        document.getElementById('recordsImportStatus').textContent = 'Abgeschlossen!';
        
        // Ergebnis anzeigen
        displayRecordsImportResult(result);
        
        // Button umwandeln zu "Schließen"
        importBtn.disabled = false;
        importBtn.textContent = 'Schließen';
        importBtn.onclick = function() {
            closeRecordsImportModal();
            // Records neu laden
            showRecordsSection(true);            
        };
        
        // Abbrechen-Button ausblenden
        cancelBtn.style.display = 'none';
        
    } catch (error) {
        debug.error('Import error:', error);
        document.getElementById('recordsImportProgress').style.display = 'none';
        showToast('Import fehlgeschlagen', 'error');
        importBtn.disabled = false;
    }
}

function displayRecordsImportResult(result) {
    const resultDiv = document.getElementById('recordsImportResult');
    const contentDiv = document.getElementById('recordsImportResultContent');
    
    if (!result || !result.success) {
        let html = '<div class="import-error">';
        html += `✗ Import fehlgeschlagen<br>`;
        html += result?.message || 'Unbekannter Fehler';
        html += '</div>';
        contentDiv.innerHTML = html;
        resultDiv.style.display = 'block';
        return;
    }
    
    let html = '<div class="import-success">';
    html += `✓ ${result.imported || 0} neue Anwesenheiten importiert<br>`;
    html += `✓ ${result.updated || 0} Anwesenheiten aktualisiert`;
    html += '</div>';
    
    if (result.errors && result.errors.length > 0) {
        html += '<div class="import-error">';
        html += '<strong>Warnungen:</strong><ul>';
        result.errors.forEach(error => {
            html += `<li>${error}</li>`;
        });
        html += '</ul></div>';
    }
    
    contentDiv.innerHTML = html;
    resultDiv.style.display = 'block';
}

// Tab-Wechsel
export function switchImportTab(tab) {
    // Tabs umschalten
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.import-tab-content').forEach(content => content.style.display = 'none');
    
    event.target.classList.add('active');
    document.getElementById(`import-tab-${tab}`).style.display = 'block';
}


// ============================================
// APPOINTMENTS EXTRACTOR
// ============================================

export async function analyzeCsvForAppointments() {
    const fileInput = document.getElementById('csv-analyze-file');
    const minRecords = document.getElementById('min-records').value || 5;
    const roundMinutes = document.getElementById('round-minutes').value || 15;
    const file = fileInput.files[0];

    let result = { success: false };
    
    if (!file) {
        alert('Bitte CSV-Datei auswählen');
        return;        
    }    
    
    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', sessionStorage.getItem('csrf_token'));
    formData.append('min_records', minRecords);
    formData.append('round_minutes', roundMinutes);

        try {
        const response = await fetch(`${API_BASE}?resource=import&type=extract_appointments`, {
            method: 'POST',
            credentials: 'same-origin', // Session-Cookies mit senden
            body: formData
        });

        if (!response.ok) {
            const errorText = await response.text();
            debug.error('Import error response:', errorText);
            throw new Error(`Import failed: ${response.status}`);
        }
        
        result = await response.json();

        debug.log('Import result:',result);    
    } catch (error) {
        debug.error('Import error:', error);
        showToast('Import fehlgeschlagen', 'error');
    }
        
    if (result.success) {
        displaySuggestions(result);
    }
}

function displaySuggestions(result) {
    const container = document.getElementById('suggestions-container');
    const list = document.getElementById('suggestions-list');
    const count = document.getElementById('suggestion-count');
    
    const suggestions = result.suggestions || [];
    
    if (suggestions.length === 0) {
        // Keine Vorschläge gefunden
        count.textContent = '(0 gefunden)';
        list.innerHTML = `
            <div class="no-suggestions">
                <p><strong>Keine Termine gefunden</strong></p>
                <p>Mit den aktuellen Parametern wurden keine passenden Termincluster gefunden.</p>
                <ul>
                    <li>Mindestanzahl Records: ${result.parameters.min_records}</li>
                    <li>Toleranz: ${result.parameters.tolerance_hours} Stunden</li>
                    <li>Verarbeitete Records: ${result.total_records}</li>
                </ul>
                <p><em>Tipp: Versuche die Mindestanzahl zu reduzieren oder die Toleranz zu erhöhen.</em></p>
            </div>
        `;
        container.style.display = 'block';
        return;
    }
    
    // Vorschläge vorhanden
    count.textContent = `(${suggestions.length} gefunden)`;
    
    list.innerHTML = suggestions.map((s, idx) => `
        <div class="suggestion-item">
            <input type="checkbox" id="sugg-${idx}" data-suggestion='${JSON.stringify(s)}' checked>
            <label for="sugg-${idx}">
                <strong>${s.date} um ${s.start_time.substring(0,5)} Uhr</strong>
                (${s.record_count} Records)
                <small>Zeitspanne: ${s.time_range.earliest.substring(0,5)} - ${s.time_range.latest.substring(0,5)} Uhr</small>
            </label>
        </div>
    `).join('');
    
    container.style.display = 'block';
}

// Ausgewählte Termine anlegen
async function createSelectedAppointments() {
    const checkboxes = document.querySelectorAll('#suggestions-list input[type="checkbox"]:checked');
    const appointments = Array.from(checkboxes).map(cb => JSON.parse(cb.dataset.suggestion));
    
    if (appointments.length === 0) {
        showToast('Keine Termine ausgewählt', 'error');
        return;
    }
    
    if (!confirm(`${appointments.length} Termine anlegen?`)) {
        return;
    }
    
    let created = 0;
    let errors = [];
    
    for (const apt of appointments) {
        try {
            const data = {
                title: `Import ${apt.date}`,
                description: `Automatisch erstellt aus ${apt.record_count} Records`,
                //type_id: '', // Leer lassen oder Standard-Type-ID wenn vorhanden
                date: apt.date,
                start_time: apt.start_time,
                created_by: '' // Wird serverseitig aus Session gesetzt
            };

            const result = await apiCall('appointments', 'POST', data);
            
            if (result.success) {
                created++;
            } else {
                errors.push(`${apt.date}: ${result.message || result.error || 'Unbekannter Fehler'}`);
            }
        } catch (error) {
            errors.push(`${apt.date}: ${error.message}`);
        }
    }
    
    // Erfolgsmeldung
    if (created > 0) {
        showToast(`${created} Termine erfolgreich angelegt`, 'success');
        clearSuggestions();
        await loadAppointments(true); // Refresh Terminliste
    }
    
    // Fehlermeldungen
    if (errors.length > 0) {
        showToast(`Fehler bei ${errors.length} Termin(en)`, 'error');
        console.error('Appointment creation errors:', errors);
    }
}

// Vorschläge verwerfen
function clearSuggestions() {
    document.getElementById('suggestions-container').style.display = 'none';
    document.getElementById('csv-analyze-file').value = '';
}

// ============================================
// APPOINTMENTS IMPORT
// ============================================

export function openAppointmentsImportModal() {
    const modal = document.getElementById('appointmentsImportModal');
    const importBtn = document.getElementById('appointmentsImportBtn');
    const cancelBtn = modal.querySelector('.btn-cancel');
    
    modal.classList.add('active');
    document.getElementById('appointmentsImportFile').value = '';
    document.getElementById('appointmentsImportProgress').style.display = 'none';
    document.getElementById('appointmentsImportResult').style.display = 'none';
    
    // Button zurücksetzen
    importBtn.textContent = 'Importieren';
    importBtn.disabled = false;
    importBtn.onclick = executeAppointmentsImport;
    
    // Abbrechen-Button wieder anzeigen
    cancelBtn.style.display = 'inline-block';
}

export function closeAppointmentsImportModal() {
    document.getElementById('appointmentsImportModal').classList.remove('active');
}

export async function executeAppointmentsImport() {
    const fileInput = document.getElementById('appointmentsImportFile');
    const file = fileInput.files[0];
    
    if (!file) {
        showToast('Bitte wähle eine CSV-Datei aus', 'error');
        return;
    }
    
    if (!file.name.endsWith('.csv')) {
        showToast('Nur CSV-Dateien erlaubt', 'error');
        return;
    }
    
    // UI aktualisieren
    const importBtn = document.getElementById('appointmentsImportBtn');
    const cancelBtn = document.querySelector('#appointmentsImportModal .btn-cancel');
    
    importBtn.disabled = true;
    document.getElementById('appointmentsImportProgress').style.display = 'block';
    document.getElementById('appointmentsImportProgressFill').style.width = '50%';
    document.getElementById('appointmentsImportStatus').textContent = 'Importiere Daten...';
    
    // FormData für File Upload
    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', sessionStorage.getItem('csrf_token'));
    
    try {
        const response = await fetch(`${API_BASE}?resource=import&type=appointments`, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });        
        
        if (!response.ok) {
            const errorText = await response.text();
            debug.error('Import error response:', errorText);
            throw new Error(`Import failed: ${response.status}`);
        }
        
        const result = await response.json();
        
        // Progress auf 100%
        document.getElementById('appointmentsImportProgressFill').style.width = '100%';
        document.getElementById('appointmentsImportStatus').textContent = 'Abgeschlossen!';
        
        // Ergebnis anzeigen
        displayAppointmentsImportResult(result);
        
        // Button umwandeln zu "Schließen"
        importBtn.disabled = false;
        importBtn.textContent = 'Schließen';
        importBtn.onclick = function() {
            closeAppointmentsImportModal();
            // Termine neu laden
            showAppointmentSection(true);            
        };
        
        // Abbrechen-Button ausblenden
        cancelBtn.style.display = 'none';
        
    } catch (error) {
        debug.error('Import error:', error);
        document.getElementById('appointmentsImportProgress').style.display = 'none';
        showToast('Import fehlgeschlagen', 'error');
        importBtn.disabled = false;
    }
}

function displayAppointmentsImportResult(result) {
    const resultDiv = document.getElementById('appointmentsImportResult');
    const contentDiv = document.getElementById('appointmentsImportResultContent');
    
    if (!result || !result.success) {
        let html = '<div class="import-error">';
        html += `✗ Import fehlgeschlagen<br>`;
        html += result?.message || 'Unbekannter Fehler';
        html += '</div>';
        contentDiv.innerHTML = html;
        resultDiv.style.display = 'block';
        return;
    }
    
    let html = '<div class="import-success">';
    html += `✓ ${result.imported || 0} neue Termine importiert<br>`;
    html += `✓ ${result.updated || 0} Termine aktualisiert`;
    html += '</div>';
    
    if (result.errors && result.errors.length > 0) {
        html += '<div class="import-error">';
        html += '<strong>Warnungen:</strong><ul>';
        result.errors.forEach(error => {
            html += `<li>${error}</li>`;
        });
        html += '</ul></div>';
    }
    
    contentDiv.innerHTML = html;
    resultDiv.style.display = 'block';
}

// ============================================
// IMPORT LOG SECTION
// ============================================

export async function initImportLogs() {
    console.log('Import Logs initialisiert');
    
    // Filter Event Listener
    const filterSelect = document.getElementById('log-type-filter');
    if (filterSelect) {
        filterSelect.addEventListener('change', loadImportLogs);
    }
    
    // Modal Close Handler
    const modal = document.getElementById('log-details-modal');
    const closeBtn = modal?.querySelector('.close');
    if (closeBtn) {
        closeBtn.addEventListener('click', () => closeModal('log-details-modal'));
    }
    
    await loadImportLogs();
}

export async function loadImportLogs() {    
    const container = document.getElementById('import-logs-list');
    if (!container) return;
    
    container.innerHTML = '<p class="loading">Lade Import-Logs...</p>';
    
    try {        
        const logs = await apiCall('import_logs', 'GET');

        //const result = await apiCall('import_logs', 'GET', null, { id: logId });
        
        if (!logs || logs.length === 0) {
            container.innerHTML = '<p class="empty-state">📋 Keine Import-Logs vorhanden</p>';
            return;
        }
        
        renderLogsList(logs);
        
    } catch (error) {
        console.error('Fehler beim Laden der Logs:', error);
        showToast('Fehler beim Laden der Import-Logs', 'error');
        container.innerHTML = '<p class="error-state">Fehler beim Laden der Logs</p>';
    }
}

function renderLogsList(logs) {
    const container = document.getElementById('import-logs-list');
    
    const html = `
        <table class="data-table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Typ</th>
                    <th>Datei</th>
                    <th>Benutzer</th>
                    <th>Gesamt</th>
                    <th>Erfolgreich</th>
                    <th>Fehler</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                ${logs.map(log => `
                    <tr>
                        <td>${log.created_at}</td>
                        <td><span class="badge badge-${log.import_type}">${getTypeLabel(log.import_type)}</span></td>
                        <td>${log.filename || 'N/A'}</td>
                        <td>${log.user_name}</td>
                        <td>${log.total_rows}</td>
                        <td class="text-success"><strong>${log.successful_rows}</strong></td>
                        <td class="text-danger"><strong>${log.failed_rows}</strong></td>
                        <td class="actions-cell">
                            <button class="action-btn btn-icon btn-view" onclick="window.ImportLogs.showDetails(${log.log_id})" title="Details anzeigen">
                                👁
                            </button>
                            <button class="action-btn btn-icon btn-delete" onclick="window.ImportLogs.deleteLog(${log.log_id})" title="Log löschen">
                                🗑
                            </button>
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
    
    container.innerHTML = html;
}

async function showDetails(logId) {
    try {
        const log = await apiCall('import_logs','GET',null,{id: logId});        
        const modal = document.getElementById('log-details-modal');
                
        // Zusammenfassung befüllen
        document.getElementById('log_created_at').textContent = log.created_at;
        document.getElementById('log_filename').textContent = log.filename || 'N/A';
        document.getElementById('log_user_name').textContent = log.user_email;
        document.getElementById('log_total_rows').textContent = log.total_rows;
        document.getElementById('log_successful_rows').textContent = log.successful_rows;
        document.getElementById('log_failed_rows').textContent = log.failed_rows;

        // Typ-Badge
        const typeBadge = document.getElementById('log_type_badge');
        typeBadge.className = `badge badge-${log.import_type}`;
        typeBadge.textContent = getTypeLabel(log.import_type);
        
        // Fehleranzahl
        document.getElementById('log_error_count').textContent = 
            log.failed_rows > 0 ? `(${log.failed_rows})` : '';
        
        // Fehlerliste
        const errorsList = document.getElementById('log_errors_list');
        
        if (!log.errors || log.errors.length === 0) {
            errorsList.innerHTML = '<p class="empty-state">✅ Keine Fehler</p>';
        } else {
            errorsList.innerHTML = log.errors.map((error, index) => `
                <div class="error-item">
                    <span class="error-number">#${index + 1}</span>
                    <span class="error-text">${error}</span>
                </div>
            `).join('');
        }

    
        modal.classList.add('active');        
        
    } catch (error) {
        console.error('Fehler beim Laden der Details:', error);
        showToast('Fehler beim Laden der Details', 'error');
    }
}

export function closeLogModal() {
    document.getElementById('log-details-modal').classList.remove('active');
}


async function deleteLog(logId) {
    const confirmed = await showConfirm(
            'Log wirklich löschen?',
            'Log löschen'
        );

    if (confirmed)
    {    
        try {
            const result = await apiCall('import_logs', 'DELETE', null, { id: logId });
            if(result.success)
            {
                showToast('Log gelöscht', 'success');
                await loadImportLogs();
                return;
            }
        } catch (error) {
            console.error('Fehler beim Löschen:', error);
            showToast('Fehler beim Löschen des Logs', 'error');
        }
    }
}

function getTypeLabel(type) {
    const labels = {
        'members': 'Mitglieder',
        'records': 'Anwesenheiten',
        'appointments': 'Termine',
        'extract_appointments': 'Termine (extrahiert)'
    };
    return labels[type] || type;
}

function refresh() {
    loadImportLogs();
}

// Globaler Zugriff für onclick-Handler
window.ImportLogs = {
    showDetails,
    deleteLog,
    refresh
};


// Globale Funktionen
window.openAppointmentsImportModal = openAppointmentsImportModal;
window.closeAppointmentsImportModal = closeAppointmentsImportModal;
window.executeAppointmentsImport = executeAppointmentsImport;
window.exportAppointments = exportAppointments;
window.closeLogModal = closeLogModal;

// Globale Funktionen für Members
window.exportMembers = exportMembers;
window.openImportModal = openImportModal;
window.closeImportModal = closeImportModal;
window.executeImport = executeImport;

// Globale Funktionen für Records
window.openRecordsImportModal = openRecordsImportModal;
window.closeRecordsImportModal = closeRecordsImportModal;
window.executeRecordsImport = executeRecordsImport;
window.exportRecords = exportRecords;
window.switchImportTab = switchImportTab;
window.analyzeCsvForAppointments = analyzeCsvForAppointments;
window.createSelectedAppointments = createSelectedAppointments;
window.clearSuggestions = clearSuggestions;