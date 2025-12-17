import { API_BASE } from '../config.js';
import { debug } from '../app.js'
import { getAuthHeaders } from './api.js';
import { showToast } from './ui.js';
import { loadMembers } from './members.js';
import { showRecordsSection } from './records.js';
import { showAppointmentSection } from './appointments.js';
import { showMemberSection } from './members.js';

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
    const year = document.getElementById('filterAppointmentYear')?.value || new Date().getFullYear();
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
    const year = document.getElementById('filterRecordYear')?.value || new Date().getFullYear();
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

// Globale Funktionen
window.openAppointmentsImportModal = openAppointmentsImportModal;
window.closeAppointmentsImportModal = closeAppointmentsImportModal;
window.executeAppointmentsImport = executeAppointmentsImport;
window.exportAppointments = exportAppointments;

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

