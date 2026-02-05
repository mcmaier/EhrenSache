/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { apiCall, isAdminOrManager } from './api.js';
import { loadAppointments } from './appointments.js';
import { loadTypes } from './management.js';
import { loadMembers } from './members.js';
import { showToast, showConfirm, dataCache, isCacheValid, currentYear} from './ui.js';
import { datetimeLocalToMysql, mysqlToDatetimeLocal, updateModalId, escapeHtml } from './utils.js';
import { debug } from '../app.js'
import { globalPaginationValue } from './settings.js';

// ============================================
// RECORDS
// Reference:
// import {} from './records.js'
// ============================================

const RecordMode = Object.freeze({
    ALL_RECORDS: 'all',
    ATTENDANCE_BY_APPOINTMENT: 'appointment',
    ATTENDANCE_BY_MEMBER: 'member'
});

let currentRecordsPage = 1;
let recordsPerPage = 25;
let allFilteredRecords = [];
//let isAttendanceMode = false;
let currentMode = RecordMode.ALL_RECORDS;
let currentAppointmentId = null;
let currentMemberId = null;

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadRecords(forceReload = false) {
    const year = currentYear;
    
    // Cache-Check: Nur laden wenn nötig
    if (!forceReload && isCacheValid('records', year)) {
        debug.log(`Loading RECORDS from CACHE for ${year}`);           
        return dataCache.records[year].data;
    }

    debug.log(`Loading RECORDS from API for ${year}`);
    const records = await apiCall('records', 'GET', null, {year:year});

    if(!dataCache.records[year]){
        dataCache.records[year] = {};
    }
    dataCache.records[year].data = records;
    dataCache.records[year].timestamp = Date.now();    
    
    return records;
}

export function filterRecords(records, filters = {}) {
    debug.log("Filter Records ()");

    if (!records || records.length === 0) return [];
    
    let filtered = [...records];
    
    // Filter: Termin
    if (filters.appointment && filters.appointment !== '') {
        filtered = filtered.filter(r => r.appointment_id == filters.appointment);
    }
    
    // Filter: Mitglied
    if (filters.member && filters.member !== '') {
        filtered = filtered.filter(r => r.member_id == filters.member);
    }
    
    // Filter: Gruppe
    if (filters.group && filters.group !== '') {
        filtered = filtered.filter(r => r.group_id == filters.group);
    }
    
    // Optionaler Filter: Status (z.B. verspätet/pünktlich)
    if (filters.status && filters.status !== '') {
        filtered = filtered.filter(r => r.status == filters.status);
    }
    
    return filtered;
}

export async function renderRecords(records, page = 1)
{
    debug.log("Render Records()");

    await loadTypes();    

    const tbody = document.getElementById('recordsTableBody');

    updateTableHeader(false); // false = Record-Modus
    
    if (!records || (records.length === 0)) {
        tbody.innerHTML = '<tr><td colspan="7" class="loading">Keine Einträge gefunden</td></tr>';
        updateRecordStats([]);
        return;
    }    

    // Alle Records speichern für Pagination
    allFilteredRecords = records;
    currentRecordsPage = page;

    updateRecordStats(records);

    recordsPerPage = globalPaginationValue;

    // Pagination berechnen
    const totalRecords = records.length;
    const totalPages = Math.ceil(totalRecords / recordsPerPage);
    const startIndex = (page - 1) * recordsPerPage;
    const endIndex = startIndex + recordsPerPage;
    const pageRecords = records.slice(startIndex, endIndex);


    debug.log(`Rendering page ${page}/${totalPages} (${pageRecords.length} of ${totalRecords} records)`);
    
    // DocumentFragment für Performance
    const fragment = document.createDocumentFragment();
    
     pageRecords.forEach(record => {
        const tr = document.createElement('tr');
        
        const arrivalTime = new Date(record.arrival_time);
        const formattedTime = arrivalTime.toLocaleString('de-DE');

         // Termin-Info mit Terminart
        let appointmentInfo = '-';
        if (record.appointment_id && record.title) {
            appointmentInfo = `<div style="line-height: 1.4;">
                <strong>${record.title}</strong>`;
            
            if (record.date && record.start_time) {
                const aptDate = new Date(record.date + 'T00:00:00');
                const formattedAptDate = aptDate.toLocaleDateString('de-DE');
                appointmentInfo += `<br><small style="color: #7f8c8d;">${formattedAptDate}, ${record.start_time.substring(0, 5)}</small>`;
            }
            
            appointmentInfo += '</div>';
        }

        // Terminart Badge
        let appointmentTypeBadge = '-';    
        const typeId = record.appointment_type_id;
        const typeName = record.appointment_type_name;
            
        // Type-ID vorhanden → Lookup im Cache (Array durchsuchen)
        if (typeId && dataCache.types.data && Array.isArray(dataCache.types.data)) {
            const type = dataCache.types.data.find(t => t.type_id == typeId);
            appointmentTypeBadge = `<span class="type-badge" style="background: ${type.color}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                                        ${type.type_name}
                                    </span>`;
        } 
        // Fallback: type_name vorhanden (aus Dropdown), aber nicht im Cache
        else if (typeName) {
            appointmentTypeBadge = `<span class="type-badge" style="background: #667eea; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                                        ${type.type_name}
                                    </span>`;
        } 
        // Termin ohne Type
        else {
            appointmentTypeBadge = `<span class="type-badge" style="background: #95a5a6; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                                        Allgemein
                                    </span>`;
        }

        // Member-Info mit Mitgliedsnr. wenn vorhanden       
        let memberInfo = `<div style="line-height: 1.4;">${record.surname}, ${record.name}`;    
        if (record.member_number) {            
            memberInfo += `<br><small style="color: #7f8c8d;">${escapeHtml(record.member_number)}</small>`;
        }    
        memberInfo += '</div>';

        let arrivalHtml = '-';
        if (record.arrival_time) {
            const arrivalDate = new Date(record.arrival_time);
            const formattedDate = arrivalDate.toLocaleDateString('de-DE');
            const formattedTime = arrivalDate.toLocaleTimeString('de-DE', { 
                hour: '2-digit', 
                minute: '2-digit' ,
                second: '2-digit'
            });
            
            arrivalHtml = `<div style="line-height: 1.4;">${formattedTime}<br>
                <small style="color: #7f8c8d;">${formattedDate}</small>
            </div>`;
        }

        // Check-in Source Badge
        const sourceInfo = getSourceBadge(record);

        // Status mit Icon und Farbe
        const statusHtml = record.status === 'present'
            ? '<span style="color: #258b3d; font-weight: 500;">✓ Anwesend</span>'
            : '<span style="color: #e97a13; font-weight: 500;">⚠ Entschuldigt</span>';

        const actionsHtml = isAdminOrManager ? `
                        <td class="actions-cell">
                            <button class="action-btn btn-icon btn-edit" 
                                    onclick="openRecordModal(${record.record_id})"
                                    title="Bearbeiten">
                                ✎
                            </button>
                            <button class="action-btn btn-icon btn-delete" 
                                    onclick="deleteRecord(${record.record_id},'${record.name}','${record.title}')"
                                    title="Löschen">
                                🗑
                            </button>
                        </td>` : '';

        tr.innerHTML = `
                <td>${appointmentInfo}</td>
                <td>${appointmentTypeBadge}</td>
                <td>${memberInfo}</td>
                <td>${arrivalHtml}</td>
                <td>${statusHtml}</td>
                <td>${sourceInfo}</td>
                ${actionsHtml}
        `;        
        
        fragment.appendChild(tr);
    });
    
    tbody.innerHTML = '';
    tbody.appendChild(fragment);
    
    // Pagination Controls
    renderRecordsPagination(page, totalPages, totalRecords); 
}

function renderRecordsPagination(currentPage, totalPages, totalRecords) {
    const container = document.getElementById('recordsPagination');
    if (!container) return;
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const startRecord = (currentPage - 1) * recordsPerPage + 1;
    const endRecord = Math.min(currentPage * recordsPerPage, totalRecords);
    
    let html = `
        <div class="pagination-container">
            <div class="pagination-info">
                Zeige ${startRecord} - ${endRecord} von ${totalRecords} Einträgen
            </div>
            <div class="pagination-buttons">
    `;
    

    if (totalPages <= 5) {
        // Wenige Seiten (≤5): Alle Seitenzahlen ohne Pfeile
        for (let i = 1; i <= totalPages; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToRecordsPage(${i})">${i}</button>`;
        }
    } 
    else
    {
        // Erste Seite Button
        if (currentPage > 1) {
            //html += `<button onclick="goToRecordsPage(1)" title="Erste Seite">«</button>`;
            html += `<button onclick="goToRecordsPage(${currentPage - 1})" title="Vorherige Seite">‹</button>`;
        }
        
        // Seitenzahlen (max 3 anzeigen)
        const startPage = Math.max(1, currentPage - 1);
        const endPage = Math.min(totalPages, currentPage + 1);
        
        if (startPage > 1) {
            html += `<button onclick="goToRecordsPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToRecordsPage(${i})">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
            html += `<button onclick="goToRecordsPage(${totalPages})">${totalPages}</button>`;
        }
        
        // Letzte Seite Button
        if (currentPage < totalPages) {
            html += `<button onclick="goToRecordsPage(${currentPage + 1})" title="Nächste Seite">›</button>`;
            //html += `<button onclick="goToRecordsPage(${totalPages})" title="Letzte Seite">»</button>`;
        }
    }
    
    html += `
            </div>
        </div>
    `;
    
    container.innerHTML = html;
}

// Global für onclick
window.goToRecordsPage = function(page) {

    // Aktuelle Scroll-Position der Tabelle speichern
    const tableContainer = document.querySelector('.data-table')?.parentElement;
    const scrollBefore = tableContainer?.scrollTop || 0;

    renderRecords(allFilteredRecords, page);
    
    // Scroll nach oben zur Tabelle
    //document.getElementById('recordsTableBody').scrollIntoView({ behavior: 'smooth', block: 'start' });

    // KEIN automatisches Scrollen - Position beibehalten
    // ODER: Sanft zur Tabelle scrollen
    if (scrollBefore === 0) {
        // Nur scrollen wenn User nicht gescrollt hat
        const paginationElement = document.getElementById('recordsPagination');
        if (paginationElement) {
            paginationElement.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'nearest' 
            });
        }
    }
};

function updateRecordStats(records) {    
    const totalRecords = records.length;
    const present = records.filter(r => r.status === 'present').length;
    //const excused = records.filter(r => r.status === 'excused').length;
    //const absent = records.filter(r => r.status === 'excused').length;

    if(currentMode === RecordMode.ATTENDANCE_BY_APPOINTMENT)
    {
        document.getElementById('statTotalRecordsTitle').innerHTML = 'Anwesende Mitglieder zum Termin';        
        document.getElementById('statTotalRecords').textContent = present;
    }
    else if(currentMode === RecordMode.ATTENDANCE_BY_MEMBER)
    {
        document.getElementById('statTotalRecordsTitle').innerHTML = 'Anwesend bei Terminen';
        document.getElementById('statTotalRecords').textContent = present;
    } 
    else
    {
        document.getElementById('statTotalRecordsTitle').innerHTML = 'Erfasste Anwesenheitseinträge';
        document.getElementById('statTotalRecords').textContent = totalRecords;
    }       
    
    //document.getElementById('statOnTime').textContent = onTime;
    //document.getElementById('statLate').textContent = late;
    //document.getElementById('statExcused').textContent = excused;
}

export async function loadRecordFilters(forceReload = false) {

    debug.log("Load Record Filters ()");

    const year = currentYear;
    
    // Termine für Jahr laden (aus Cache wenn möglich)
    const appointments = await loadAppointments(forceReload);
    
    // Mitglieder laden (jahresunabhängig)
    const members = await loadMembers(forceReload);
    
    // Gruppen laden
    //if (!isCacheValid('groups')) {
    //    await loadGroups(true);
    //}
    //const groups = await loadGroups();
    
    // Termin-Filter befüllen
    const appointmentSelect = document.getElementById('filterAppointment');
    const currentAppointmentValue = appointmentSelect.value;
    
    appointmentSelect.innerHTML = '<option value="">Alle Termine</option>';
    if (appointments && appointments.length > 0) {
        appointments.forEach(app => {
            appointmentSelect.innerHTML += `<option value="${app.appointment_id}">${app.title} (${app.date})</option>`;
        });
    }
    appointmentSelect.value = currentAppointmentValue;
    
    // Mitglieder-Filter befüllen
    const memberSelect = document.getElementById('filterMember');
    const currentMemberValue = memberSelect.value;
    
    memberSelect.innerHTML = '<option value="">Alle Mitglieder</option>';
    if (members && members.length > 0) {
        members
            .filter(m => m.active)
            .forEach(member => {
                memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
            });
    }
    memberSelect.value = currentMemberValue;
    
    // Gruppen-Filter befüllen
    /*
    const groupSelect = document.getElementById('filterGroup');
    const currentGroupValue = groupSelect.value;
    
    groupSelect.innerHTML = '<option value="">Alle Gruppen</option>';
    if (groups && groups.length > 0) {
        groups.forEach(group => {
            groupSelect.innerHTML += `<option value="${group.group_id}">${group.group_name}</option>`;
        });
    }
    groupSelect.value = currentGroupValue;
    */
}


export async function applyRecordFilters(forceReload = false, currentPage = 1) {
    // Records laden (aus Cache wenn möglich)
    const allRecords = await loadRecords(forceReload);

    debug.log("Apply Record Filters ()");

    // Aktuelle Filter auslesen
    const filters = {
        appointment: document.getElementById('filterAppointment')?.value || null,
        member: document.getElementById('filterMember')?.value || null,
        //group: document.getElementById('filterGroup')?.value || null
    };

    // Filtern
    const filteredRecords = filterRecords(allRecords, filters);

    // Rendern (nur wenn auf Records-Section)
    //const currentSection = sessionStorage.getItem('currentSection');
    //if (currentSection === 'anwesenheit') {
    //renderRecords(filteredRecords);
    //}

    // Rendern (nur wenn auf Records-Section) - IMMER Seite 1 nach Filter-Änderung
    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'anwesenheit') {
        renderRecords(filteredRecords, currentPage); // ← Reset auf Seite 1
        debug.log('Records rendered');
    }
    
    //return filteredRecords;    
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

// Im Init oder beim Section-Wechsel registrieren
export async function initRecordEventHandlers() {

    debug.log("Init Record Event Handlers ()");

    // Filter-Änderungen
    /*document.getElementById('filterAppointment')?.addEventListener('change', () => {
        applyRecordFilters();
    });*/

    // Event-Listener für Termin-Filter
    document.getElementById('filterAppointment').addEventListener('change', async function() {
        const appointmentId = this.value;
        const memberFilter = document.getElementById('filterMember');
        
        if (appointmentId && appointmentId !== '') {
            // Attendance-Modus: Member-Filter deaktivieren
            //isAttendanceMode = true;
            currentMode = RecordMode.ATTENDANCE_BY_APPOINTMENT;
            currentAppointmentId = appointmentId;
            currentMemberId = null;
            memberFilter.disabled = true;
            memberFilter.value = '';
            //await loadAndRenderAttendanceList(appointmentId);
            await loadAttendanceList(appointmentId);
        } else {
            // Records-Modus: Member-Filter aktivieren
            //isAttendanceMode = false;
            currentMode = RecordMode.ALL_RECORDS;
            currentAppointmentId = null;
            currentMemberId = null;
            memberFilter.disabled = false;
            await applyRecordFilters(true); // Normale Filterung
        }
    });

    document.getElementById('filterMember')?.addEventListener('change', async function() {
        const memberId = this.value;
        const appointmentFilter = document.getElementById('filterAppointment');
        
        if (memberId && memberId !== '') {
            // Attendance-by-Member-Modus
            currentMode = RecordMode.ATTENDANCE_BY_MEMBER;
            currentMemberId = memberId;
            currentAppointmentId = null;
            appointmentFilter.disabled = true;
            appointmentFilter.value = '';
            await loadMemberAttendanceList(memberId);
        } else {
            // Zurück zu ALL_RECORDS falls kein Appointment gewählt
            currentMode = RecordMode.ALL_RECORDS;
            currentMemberId = null;
            currentAppointmentId = null;
            appointmentFilter.disabled = false;
            await applyRecordFilters(true);
        }
    });
    
    /*
    document.getElementById('filterMember')?.addEventListener('change', () => {
        applyRecordFilters();
    });
    
    document.getElementById('filterGroup')?.addEventListener('change', () => {
        applyRecordFilters();
    });*/
    
    // Reset-Button
    document.getElementById('resetRecordFilter')?.addEventListener('click', async function() {

        debug.log("Reset Filters");
        const appointmentFilter = document.getElementById('filterAppointment');
        const memberFilter = document.getElementById('filterMember');
        //document.getElementById('filterGroup').value = '';
        appointmentFilter.disabled = false;
        memberFilter.disabled = false;
        appointmentFilter.value = '';
        memberFilter.value = '';
        //isAttendanceMode = false;
        currentMode = RecordMode.ALL_RECORDS;
        currentAppointmentId = null;
        currentMemberId = null;
        
        await applyRecordFilters();
    });
}

export async function showRecordsSection(forceReload = false) {

    debug.log("Show Record Section ()");

    // Filter-Optionen laden
    await loadRecordFilters();

    if((currentMode === RecordMode.ATTENDANCE_BY_APPOINTMENT) && currentAppointmentId)
    {
        await loadAttendanceList(currentAppointmentId);
    }
    else if((currentMode === RecordMode.ATTENDANCE_BY_MEMBER) && currentMemberId)
    {
        await loadMemberAttendanceList(currentMemberId);
    }
    else
    {
        await applyRecordFilters(forceReload);
    }
}

export async function loadRecordDropdowns() {
        
    const members =  await loadMembers(false);
    const memberSelect = document.getElementById('record_member');
    memberSelect.innerHTML = '<option value="">Bitte wählen...</option>';
    
    members
        .filter(m => m.active)
        .forEach(member => {
            memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
        });
    
    const appointments = await loadAppointments(false,currentYear);    
    const appointmentSelect = document.getElementById('record_appointment');    
    appointmentSelect.innerHTML = '<option value="">Bitte wählen...</option>';
    
    appointments.forEach(appointment => {
            const date = new Date(appointment.date + 'T00:00:00');
            const formattedDate = date.toLocaleDateString('de-DE');
            const startTime = appointment.start_time ? appointment.start_time.substring(0, 5) : '';
            
            // Terminart-Anzeige im Dropdown-Text
            let displayText = `${appointment.title} (${formattedDate} ${startTime})`;
            
            if (appointment.type_name) {
                displayText += ` - [${appointment.type_name}]`;
            }
            
            // Erstelle Option mit data-Attributen
            const option = document.createElement('option');
            option.value = appointment.appointment_id;
            option.textContent = displayText;
            
            // Speichere Type-Daten für Badge-Anzeige
            option.dataset.typeId = appointment.type_id || '';
            option.dataset.typeName = appointment.type_name || '';
            
            appointmentSelect.appendChild(option);
        });
}


function getSourceBadge(record) {
    const sources = {
        'none': { icon: '', label: '-', color: '#c3c5c7' },
        'admin': { icon: '👤', label: 'Admin', color: '#72afd8' },
        'user_totp': { icon: '📱', label: 'App', color: '#65c48c' },
        'device_auth': { icon: '🔐', label: 'Gerät', color: '#d89c57' },        
        'auto_checkin': { icon: '🤖', label: 'Auto', color: '#95a5a6' },
        'import':{icon: '📤', label: 'Import', color: 'rgb(231, 209, 109)'}
    };
    
    const source = sources[record.checkin_source] || sources['none'];
    
    let badge = `<span class="source-badge" style="background: ${source.color}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                    ${source.icon} ${source.label}
                 </span>`;
    
    // Zusatzinfo
    const details = [];
    if (record.location_name) {
        details.push(`📍 ${record.location_name}`);
    }
    if (record.source_device) {
        details.push(`🔧 ${record.source_device}`);
    }
    
    if (details.length > 0) {
        badge += `<br><small style="color: #7f8c8d;">${details.join(' • ')}</small>`;
    }
    
    return badge;
}

// ============================================
// MODAL FUNCTIONS
// ============================================

export async function openRecordModal(recordId = null) {
    const modal = document.getElementById('recordModal');
    const title = document.getElementById('recordModalTitle');
    const form = document.getElementById('recordForm');

    form.reset();
    
    // Lade Mitglieder und Termine für Dropdowns
    await loadRecordDropdowns();
    
    if (recordId) {
        title.textContent = 'Anwesenheit bearbeiten';
        await loadRecordData(recordId);

        updateModalId('recordModal', recordId);

    } else {
        title.textContent = 'Anwesenheit erfassen';
        document.getElementById('recordForm').reset();
        document.getElementById('record_id').value = '';
        document.getElementById('record_status').value = 'present';

        // Keine ID anzeigen
        updateModalId('recordModal', null);
        
        // Setze aktuelle Zeit als Standard
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        document.getElementById('record_arrival_time').value = `${year}-${month}-${day}T${hours}:${minutes}`;

        // Verstecke Terminart-Anzeige
        document.getElementById('recordAppointmentTypeGroup').style.display = 'none';            
    }    

    // Event Listener für Termin-Auswahl (nur einmal registrieren)
    const appointmentSelect = document.getElementById('record_appointment');
    //appointmentSelect.removeEventListener('change', updateAppointmentTypeDisplay);
    appointmentSelect.removeEventListener('change',updateArrivalTimeFromAppointment);

    //appointmentSelect.addEventListener('change', updateAppointmentTypeDisplay);
    appointmentSelect.addEventListener('change',updateArrivalTimeFromAppointment);
    
    modal.classList.add('active');
}

export function closeRecordModal() {
    document.getElementById('recordModal').classList.remove('active');
}

// ============================================
// CRUD FUNCTIONS
// ============================================

export async function loadRecordData(recordId) {
    const record = await apiCall('records', 'GET', null, { id: recordId });
    
    if (record) {
        document.getElementById('record_id').value = record.record_id;
        document.getElementById('record_member').value = record.member_id;
        document.getElementById('record_appointment').value = record.appointment_id;
        
        // Konvertiere Timestamp zu datetime-local Format
        document.getElementById('record_arrival_time').value = mysqlToDatetimeLocal(record.arrival_time);        
        document.getElementById('record_status').value = record.status;

        // Terminart anzeigen
        //updateAppointmentTypeDisplay();
    }
}


export async function saveRecord() {
    // Form-Validierung prüfen
    const form = document.getElementById('recordForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const recordId = document.getElementById('record_id').value;
    
    // Konvertiere datetime-local zu MySQL DATETIME Format
    const mysqlDateTime = datetimeLocalToMysql(document.getElementById('record_arrival_time').value);
    
    const data = {
        member_id: parseInt(document.getElementById('record_member').value),
        appointment_id: parseInt(document.getElementById('record_appointment').value),
        arrival_time: mysqlDateTime,
        status: document.getElementById('record_status').value
    };
    
    let result;
    if (recordId) {
        // Update
        result = await apiCall('records', 'PUT', data, { id: recordId });
    } else {
        // Create
        result = await apiCall('records', 'POST', data);
    }
    
    if (result.success) {
        closeRecordModal();
   
        if(currentMode === RecordMode.ATTENDANCE_BY_APPOINTMENT)
        {
            loadAttendanceList(currentAppointmentId);                
        }
        else if(currentMode === RecordMode.ATTENDANCE_BY_MEMBER)
        {
            loadMemberAttendanceList(currentMemberId);
        }    
        else
        {                    
            applyRecordFilters(true, currentRecordsPage);
        } 

        // Erfolgs-Toast
        showToast(
            recordId ? 'Eintrag wurde erfolgreich aktualisiert' : 'Eintrag wurde erfolgreich erstellt',
            'success'
        );
    }
}

export async function deleteRecord(recordId, memberName, appointmentTitle) {    
     const confirmed = await showConfirm(
        `Anwesenheit von ${memberName} bei ${appointmentTitle} wirklich löschen?`,
        'Anwesenheit löschen'
    );

    if (confirmed) {
        const result = await apiCall('records', 'DELETE', null, { id: recordId });
        if (result.success) {
            if(currentMode === RecordMode.ATTENDANCE_BY_APPOINTMENT)
            {
                loadAttendanceList(currentAppointmentId);                
            }
            else if(currentMode === RecordMode.ATTENDANCE_BY_MEMBER)
            {
                loadMemberAttendanceList(currentMemberId);
            }
            else
            {
                //await invalidateCache('records', currentYear);
                applyRecordFilters(true, currentRecordsPage);
            }
            //await loadRecords(true, currentYear);
            showToast(`Eintrag wurde gelöscht`, 'success');
        }
    }
}

async function updateAppointmentTypeDisplay() {
    const appointmentSelect = document.getElementById('record_appointment');
    const selectedOption = appointmentSelect.options[appointmentSelect.selectedIndex];
    const typeGroup = document.getElementById('recordAppointmentTypeGroup');
    const typeBadge = document.getElementById('recordAppointmentTypeBadge');
        
    // Kein Termin gewählt
    if (!selectedOption.value) {
        typeGroup.style.display = 'none';
        return;
    }

    await loadTypes();
    
    // Termin gewählt
    typeGroup.style.display = 'block';
    
    const typeId = selectedOption.dataset.typeId;
    const typeName = selectedOption.dataset.typeName;

    debug.log("Updating Appointment Type: ",selectedOption, typeId, typeName);
    
    // Type-ID vorhanden → Lookup im Cache für Farbe
    if (typeId && dataCache.types.data[typeId]) {
        const type = dataCache.types.data[typeId];
        typeBadge.innerHTML = `<span class="type-badge" style="background: ${type.color}; color: white; padding: 6px 12px; border-radius: 4px; font-size: 13px;">
                                  ${type.type_name}
                               </span>`;
    } 
    // Fallback: type_name vorhanden (aus Dropdown), aber nicht im Cache
    else if (typeName) {
        typeBadge.innerHTML = `<span class="type-badge" style="background: #667eea; color: white; padding: 6px 12px; border-radius: 4px; font-size: 13px;">
                                  ${typeName}
                               </span>`;
    } 
    // Termin ohne Type
    else {
        typeBadge.innerHTML = `<span class="type-badge" style="background: #95a5a6; color: white; padding: 6px 12px; border-radius: 4px; font-size: 13px;">
                                  Allgemein
                               </span>`;
    }
}

// ============================================
// ATTENDANCE LIST
// ============================================

async function loadAttendanceList(appointmentId) {
    try {
        const attendance = await apiCall('attendance_list', 'GET', null, {appointment_id:appointmentId});
        
        debug.log("Attendance Data:", attendance);
        if (attendance.success) {
            renderAttendanceList(attendance.members);
        }
    } catch (error) {
        console.error('Fehler beim Laden der Anwesenheitsliste:', error);
    }
}

function renderAttendanceList(attendanceData) {
    const tbody = document.getElementById('recordsTableBody');

    updateRecordStats(attendanceData);

    const container = document.getElementById('recordsPagination');
    if (!container) return;

    container.innerHTML = '';    

    tbody.innerHTML = '';    
    
    updateTableHeader('appointment');

    attendanceData.forEach(member => {
        const tr = document.createElement('tr'); 

        // Member-Info mit Mitgliedsnr. wenn vorhanden       
        let memberInfo = `<div style="line-height: 1.4;">${member.surname}, ${member.name}`;    
        if (member.member_number) {            
            memberInfo += `<br><small style="color: #7f8c8d;">${escapeHtml(member.member_number)}</small>`;                            
        }    
        memberInfo += '</div>'        
        
        let arrivalHtml = '-';
        if (member.arrival_time) {
            const arrivalDate = new Date(member.arrival_time);
            const formattedDate = arrivalDate.toLocaleDateString('de-DE');
            const formattedTime = arrivalDate.toLocaleTimeString('de-DE', { 
                hour: '2-digit', 
                minute: '2-digit' ,
                second: '2-digit'
            });
            
            arrivalHtml = `<div style="line-height: 1.4;">${formattedTime}<br>
                <small style="color: #7f8c8d;">${formattedDate}</small>
            </div>`;
        }

        // Check-in Source Badge
        const sourceInfo = getSourceBadge(member);
        
        // Status-Icon und Styling
        let statusHtml, rowClass;
        if (member.status === 'present') {
            statusHtml = '<span style="color: #258b3d; font-weight: 500;">✓ Anwesend</span';
            rowClass = '';
        } else if (member.status === 'excused') {
            statusHtml = '<span style="color: #e97a13; font-weight: 500;">⚠ Entschuldigt</span>';
            rowClass = '';
        } else {
            statusHtml = '<span style="color: #dc3545; font-weight: 500;">✗ Fehlend</span>';
            rowClass = 'table-secondary'; // Grau ausgegraut
        }

        let actionsHtml;
        if (member.record_id) {
            // Eintrag vorhanden → Edit & Delete
            actionsHtml = `
                <button class="action-btn btn-icon btn-edit" 
                        onclick="openRecordModal(${member.record_id})"
                        title="Bearbeiten">
                    ✎
                </button>
                <button class="action-btn btn-icon btn-delete" 
                        onclick="deleteRecord(${member.record_id},'${member.name}','diesem Termin')"
                        title="Löschen">
                    🗑
                </button>
            `;
        } else {
            // Kein Eintrag → Anwesend & Entschuldigt
            actionsHtml = `
                <button class="action-btn btn-icon btn-approve" 
                        onclick="quickCreateRecordForMember(${member.member_id}, 'present')"
                        title="Anwesend">
                    ✓
                </button>
                <button class="action-btn btn-icon btn-edit" 
                        onclick="quickCreateRecordForMember(${member.member_id}, 'excused')"
                        title="Entschuldigt">
                    ⚠
                </button>
            `;
        }   
        
        tr.className = rowClass;
        tr.innerHTML = `
            <td>${memberInfo}</td>     
            <td>${arrivalHtml}</td>
            <td>${statusHtml}</td>
            <td>${sourceInfo}</td>
            <td>${actionsHtml}</td>
        `;
        
        tbody.appendChild(tr);
    });
}

async function loadMemberAttendanceList(memberId) {
    try {
        // Jahr aus bestehendem Filter oder aktuelles Jahr
        const year = document.getElementById('filterYear')?.value || new Date().getFullYear();
        
        const attendance = await apiCall('attendance_list', 'GET', null, {
            member_id: memberId,
            year: currentYear
        });
        
        debug.log("Member Attendance Data:", attendance);
        if (attendance.success) {
            renderMemberAttendanceList(attendance.appointments, attendance.member);
        }
    } catch (error) {
        console.error('Fehler beim Laden der Mitglieder-Anwesenheit:', error);
    }
}

function renderMemberAttendanceList(appointmentsData, memberInfo) {
    const tbody = document.getElementById('recordsTableBody');

    updateRecordStats(appointmentsData);

    const container = document.getElementById('recordsPagination');
    if (!container) return;

    container.innerHTML = '';    
    tbody.innerHTML = '';    
    
    // Neuer Header-Modus für Member-Ansicht
    updateTableHeader('member'); // 'member' = Member-Attendance-Modus

    appointmentsData.forEach(appointment => {
        const tr = document.createElement('tr');        
        
        const arrivalTime = new Date(appointment.arrival_time);
        const formattedTime = arrivalTime.toLocaleString('de-DE');

         // Termin-Info mit Terminart
        let appointmentInfo = '-';
        if (appointment.appointment_id && appointment.title) {
            appointmentInfo = `<div style="line-height: 1.4;">
                <strong>${appointment.title}</strong>`;
            
            if (appointment.date && appointment.start_time) {
                const aptDate = new Date(appointment.date + 'T00:00:00');
                const formattedAptDate = aptDate.toLocaleDateString('de-DE');
                appointmentInfo += `<br><small style="color: #7f8c8d;">${formattedAptDate}, ${appointment.start_time.substring(0, 5)}</small>`;
            }
            
            appointmentInfo += '</div>';
        }


        //Ankunftszeitpunkt
        let arrivalHtml = '-';
        if (appointment.arrival_time) {
            const arrivalDate = new Date(appointment.arrival_time);
            const formattedDate = arrivalDate.toLocaleDateString('de-DE');
            const formattedTime = arrivalDate.toLocaleTimeString('de-DE', { 
                hour: '2-digit', 
                minute: '2-digit' ,
                second: '2-digit'
            });
            
            arrivalHtml = `<div style="line-height: 1.4;">${formattedTime}<br>
                <small style="color: #7f8c8d;">${formattedDate}</small>
            </div>`;
        }        

        // Check-in Source Badge
        const sourceInfo = getSourceBadge(appointment);
        
        // Status-Icon und Styling
        let statusHtml, rowClass;
        if (appointment.status === 'present') {
            statusHtml = '<span style="color: #258b3d; font-weight: 500;">✓ Anwesend</span>';
            rowClass = '';
        } else if (appointment.status === 'excused') {
            statusHtml = '<span style="color: #e97a13; font-weight: 500;">⚠ Entschuldigt</span>';
            rowClass = '';
        } else {
            statusHtml = '<span style="color: #dc3545; font-weight: 500;">✗ Fehlend</span>';
            rowClass = 'table-secondary';
        }


        // Terminart Badge
        let appointmentTypeBadge = '-';    
        const typeId = appointment.type_id;
        const typeName = appointment.type_name;
            
        // Type-ID vorhanden → Lookup im Cache (Array durchsuchen)
        if (typeId && dataCache.types.data && Array.isArray(dataCache.types.data)) {
            const type = dataCache.types.data.find(t => t.type_id == typeId);
            appointmentTypeBadge = `<span class="type-badge" style="background: ${type.color}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                                        ${type.type_name}
                                    </span>`;
        } 
        // Fallback: type_name vorhanden (aus Dropdown), aber nicht im Cache
        else if (typeName) {
            appointmentTypeBadge = `<span class="type-badge" style="background: #667eea; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                                        ${type.type_name}
                                    </span>`;
        } 
        // Termin ohne Type
        else {
            appointmentTypeBadge = `<span class="type-badge" style="background: #95a5a6; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                                        Allgemein
                                    </span>`;
        }

        let actionsHtml;
        if (appointment.record_id) {
            // Eintrag vorhanden → Edit & Delete
            actionsHtml = `
                <button class="action-btn btn-icon btn-edit" 
                        onclick="openRecordModal(${appointment.record_id})"
                        title="Bearbeiten">
                    ✎
                </button>
                <button class="action-btn btn-icon btn-delete" 
                        onclick="deleteRecord(${appointment.record_id},'${memberInfo.name}','diesem Termin')"
                        title="Löschen">
                    🗑
                </button>
            `;
        } else {
            // Kein Eintrag → Anwesend & Entschuldigt
            actionsHtml = `
                <button class="action-btn btn-icon btn-approve" 
                        onclick="quickCreateRecordForAppointment(${appointment.appointment_id}, 'present')"
                        title="Anwesend">
                    ✓
                </button>
                <button class="action-btn btn-icon btn-edit" 
                        onclick="quickCreateRecordForAppointment(${appointment.appointment_id}, 'excused')"
                        title="Entschuldigt">
                    ⚠
                </button>
            `;
        }   
        
        tr.className = rowClass;
        tr.innerHTML = `
            <td>${appointmentInfo}</td>
            <td>${appointmentTypeBadge}</td>
            <td>${arrivalHtml}</td>
            <td>${statusHtml}</td>
            <td>${sourceInfo}</td>
            <td>${actionsHtml}</td>
        `;
        
        tbody.appendChild(tr);
    });
}

async function quickCreateRecordForMember(memberId, status = 'present') {
    const appointmentId = currentAppointmentId;
    
    if (!appointmentId) {
        showToast('Kein Termin ausgewählt', 'error');
        return;
    }
    
    try {
        await apiCall('records', 'POST', {
            member_id: parseInt(memberId),
            appointment_id: parseInt(appointmentId),
            status: status
            // status wird automatisch 'present'
        });
        
        const message = status === 'excused' ? 'Entschuldigung erfasst' : 'Anwesenheit erfasst';
        showToast(message, 'success');
        
        // Anwesenheitsliste neu laden
        await loadAttendanceList(appointmentId);
        
    } catch (error) {
        console.error('Fehler beim Erstellen:', error);
        showToast('Fehler beim Erstellen der Anwesenheit', 'error');
    }
}


// Quick-Create für Member-Ansicht (umgekehrte Logik)
async function quickCreateRecordForAppointment(appointmentId, status = 'present') {
    const memberId = currentMemberId;
    
    if (!memberId) {
        showToast('Kein Mitglied ausgewählt', 'error');
        return;
    }
    
    try {
        await apiCall('records', 'POST', {
            member_id: parseInt(memberId),
            appointment_id: parseInt(appointmentId),
            status: status
        });
        
        const message = status === 'excused' ? 'Entschuldigung erfasst' : 'Anwesenheit erfasst';
        showToast(message, 'success');
        
        // Member-Anwesenheitsliste neu laden
        await loadMemberAttendanceList(memberId);
        
    } catch (error) {
        console.error('Fehler beim Erstellen:', error);
        showToast('Fehler beim Erstellen der Anwesenheit', 'error');
    }
}

function updateTableHeader(mode) {
    const thead = document.querySelector('#recordsTable thead tr');
    
    if (mode === 'member') {
        // Member-Attendance: Termine auflisten
        thead.innerHTML = '<th>Termin</th><th>Typ</th><th>Ankunft</th><th>Status</th><th>Quelle</th><th>Aktionen</th>';
    } else if (mode === 'appointment') {
        // Appointment-Attendance: Mitglieder auflisten
        thead.innerHTML = '<th>Mitglied</th><th>Ankunft</th><th>Status</th><th>Quelle</th><th>Aktionen</th>';
    } else {
        // ALL_RECORDS: Alle Felder
        thead.innerHTML = '<th>Termin</th><th>Typ</th><th>Mitglied</th><th>Ankunft</th><th>Status</th><th>Quelle</th><th>Aktionen</th>';
    }
}


// ============================================
// HELPERS
// ============================================

// Neue Funktion: Aktualisiere Ankunftszeit basierend auf gewähltem Termin
function updateArrivalTimeFromAppointment() {
    const appointmentSelect = document.getElementById('record_appointment');
    const arrivalTimeInput = document.getElementById('record_arrival_time');
    
    const selectedAppointmentId = appointmentSelect.value;
    
    if (!selectedAppointmentId) return;
    
    // Finde den gewählten Termin im Cache
    const appointment = dataCache.appointments[currentYear].data.find(apt => apt.appointment_id == selectedAppointmentId);
    
    if (appointment && appointment.date && appointment.start_time) {
        // Konvertiere zu datetime-local Format
        const dateTime = `${appointment.date}T${appointment.start_time.substring(0, 5)}`;
        arrivalTimeInput.value = dateTime;
    }
}

// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

// Globale Funktionen für HTML onclick
window.openRecordModal = openRecordModal;
window.saveRecord = saveRecord;
window.closeRecordModal = () => document.getElementById('recordModal').classList.remove('active');
window.deleteRecord = deleteRecord;
window.applyRecordFilters = applyRecordFilters;
window.quickCreateRecordForMember = quickCreateRecordForMember;
window.quickCreateRecordForAppointment = quickCreateRecordForAppointment;