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
import { showToast, showConfirm, dataCache, isCacheValid,invalidateCache,currentYear} from './ui.js';
import {translateExceptionStatus, translateExceptionType, datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime , updateModalId} from './utils.js';
import { loadAppointments } from './appointments.js';
import { loadMembers } from './members.js';
import {debug} from '../app.js'
import { globalPaginationValue } from './settings.js';

// ============================================
// EXCEPTIONS
// Reference:
// import {} from './exceptions.js'
// ============================================

let currentExceptionsPage = 1;
let exceptionsPerPage = 25;
let allFilteredExceptions = [];

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadExceptions(forceReload = false) {
    const year = currentYear;

    // Cache-Check: Nur laden wenn nötig
    if (!forceReload && isCacheValid('exceptions', year)) {
        debug.log(`Loading EXCEPTIONS from CACHE for ${year}`);   
        return dataCache.exceptions[year].data;
    }

    debug.log(`Loading EXCEPTIONS from API for ${year}`);
    const exceptions = await apiCall('exceptions', 'GET', null, {year: year});

    if(!dataCache.exceptions[year])
    {
        dataCache.exceptions[year] = {};
    }

    dataCache.exceptions[year].data = exceptions;
    dataCache.exceptions[year].timestamp = Date.now();

    return exceptions;
}

export async function renderExceptions(exceptions, page = 1)
{   
    debug.log("Rendering Exceptions...");
    
    const tbody = document.getElementById('exceptionsTableBody');    

    if (!exceptions || (exceptions.length === 0)) {
        tbody.innerHTML = '<tr><td colspan="8" class="loading">Keine Einträge gefunden</td></tr>';
        updateExceptionStats([]);
        return;
    }

    exceptionsPerPage = globalPaginationValue;

    // Alle Exceptions speichern für Pagination
    allFilteredExceptions = exceptions;
    currentExceptionsPage = page;
    
    updateExceptionStats(exceptions);

    // Pagination berechnen
    const totalExceptions = exceptions.length;
    const totalPages = Math.ceil(totalExceptions / exceptionsPerPage);
    const startIndex = (page - 1) * exceptionsPerPage;
    const endIndex = startIndex + exceptionsPerPage;
    const pageExceptions = exceptions.slice(startIndex, endIndex);

    debug.log(`Rendering page ${page}/${totalPages} (${pageExceptions.length} of ${totalExceptions} exceptions)`);
    
    // DocumentFragment für Performance
    const fragment = document.createDocumentFragment();

    pageExceptions.forEach(exception => {
        const tr = document.createElement('tr');  
        const createdAt = new Date(exception.created_at);
        const formattedCreated = createdAt.toLocaleString('de-DE');        
        const requestedTime = exception.requested_arrival_time 
            ? new Date(exception.requested_arrival_time).toLocaleString('de-DE')
            : '-';
        
        const statusBadge = `<span class="status-badge status-${exception.status}">${translateExceptionStatus(exception.status)}</span>`;
        const typeBadge = `<span class="type-badge">${translateExceptionType(exception.exception_type)}</span>`;
        
        //TODO Terminzuordnung
        let appointmentInfo = 'Kein Termin';
        if (exception.appointment_id && dataCache.appointments[currentYear]) {
            const appointment = dataCache.appointments[currentYear].data.find(
                a => a.appointment_id == exception.appointment_id
            );
            if (appointment) {
                appointmentInfo = `${appointment.title} (${appointment.date})`;
            }
        }

        // Aktionen: User sehen nur bei pending eigene Anträge Buttons
        let actionsHtml = '';
        if (isAdminOrManager && (exception.status === 'pending')) {
            actionsHtml = `
                <td class="actions-cell">
                    <button class="action-btn btn-icon btn-approve" 
                            onclick="quickApproveException(${exception.exception_id})"
                            title="Genehmigen">
                        ✓
                    </button>
                    <button class="action-btn btn-icon btn-reject" 
                            onclick="quickRejectException(${exception.exception_id})"
                            title="Ablehnen">
                        ✗
                    </button>
                    <button class="action-btn btn-icon btn-edit" 
                            onclick="openExceptionModal(${exception.exception_id})"
                            title="Bearbeiten">
                        ✎
                    </button>
                    <button class="action-btn btn-icon btn-delete" 
                            onclick="deleteException(${exception.exception_id})"
                            title="Löschen">
                        🗑
                    </button>
                </td>
            `;
        } else if (isAdminOrManager) {
                actionsHtml = `
                    <td class="actions-cell">
                        <button class="action-btn btn-icon btn-view" 
                                onclick="openExceptionModal(${exception.exception_id})"
                                title="Ansehen">
                            👁
                        </button>
                    </td>
                `;
        }
        else{
            if(exception.status === 'pending')
            {
                actionsHtml = `
                    <td class="actions-cell">
                        <button class="action-btn btn-icon btn-edit" 
                                onclick="openExceptionModal(${exception.exception_id})"
                                title="Bearbeiten">
                            ✎
                        </button>
                        <button class="action-btn btn-icon btn-delete" 
                                onclick="deleteException(${exception.exception_id})"
                                title="Löschen">
                            🗑
                        </button>
                    </td>
                `;
            }
            else
            {
               actionsHtml = '<td></td>' 
            }
        }
        
        const row = 
        tr.innerHTML = `            
            <td>${typeBadge}</td>
            <td>${exception.surname}, ${exception.name}</td>
            <td>${appointmentInfo}</td>
            <td>${exception.reason}</td>
            <td>${requestedTime}</td>
            <td>${statusBadge}</td>
            <td>${formattedCreated}</td>
            ${actionsHtml}            
        `;
        fragment.appendChild(tr);
    });

    tbody.innerHTML = '';
    tbody.appendChild(fragment);
    
    // Pagination Controls
    renderExceptionsPagination(page, totalPages, totalExceptions);
}


function renderExceptionsPagination(currentPage, totalPages, totalExceptions) {
    const container = document.getElementById('exceptionsPagination');
    if (!container) return;
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const startException = (currentPage - 1) * exceptionsPerPage + 1;
    const endException = Math.min(currentPage * exceptionsPerPage, totalExceptions);
    
    let html = `
        <div class="pagination-container">
            <div class="pagination-info">
                Zeige ${startException} - ${endException} von ${totalExceptions} Einträgen
            </div>
            <div class="pagination-buttons">
    `;
    
    if (totalPages <= 5) {
        // Wenige Seiten (≤5): Alle Seitenzahlen ohne Pfeile
        for (let i = 1; i <= totalPages; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToExceptionsPage(${i})">${i}</button>`;
        }
    } 
    else {
        // Erste Seite Button
        if (currentPage > 1) {
            //html += `<button onclick="goToExceptionsPage(1)" title="Erste Seite">
            //           «
            //        </button>`;
            html += `<button onclick="goToExceptionsPage(${currentPage - 1})" title="Vorherige Seite">
                        ‹
                    </button>`;
        }
        
        // Seitenzahlen (max 5 anzeigen)
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            html += `<button onclick="goToExceptionsPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToExceptionsPage(${i})">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
            html += `<button onclick="goToExceptionsPage(${totalPages})">${totalPages}</button>`;
        }
        
        // Letzte Seite Button
        if (currentPage < totalPages) {
            html += `<button onclick="goToExceptionsPage(${currentPage + 1})" title="Nächste Seite">
                        ›
                    </button>`;
            //html += `<button onclick="goToExceptionsPage(${totalPages})" title="Letzte Seite">
            //            »
            //        </button>`;
        }
    }
    
    html += `
            </div>
        </div>
    `;
    
    container.innerHTML = html;
}

// Global für onclick
window.goToExceptionsPage = function(page) {

    // Aktuelle Scroll-Position der Tabelle speichern
    const tableContainer = document.querySelector('.data-table')?.parentElement;
    const scrollBefore = tableContainer?.scrollTop || 0;

    renderExceptions(allFilteredExceptions, page);
    
    // Scroll nach oben zur Tabelle
    //document.getElementById('exceptionsTableBody').scrollIntoView({ behavior: 'smooth', block: 'start' });

    // KEIN automatisches Scrollen - Position beibehalten
    // ODER: Sanft zur Tabelle scrollen
    if (scrollBefore === 0) {
        // Nur scrollen wenn User nicht gescrollt hat
        const paginationElement = document.getElementById('exceptionsPagination');
        if (paginationElement) {
            paginationElement.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'nearest' 
            });
        }
    }
};

function updateExceptionStats(exceptions){

    debug.log("Update Exception Stats ()");
    
    if(exceptions !== null)
    {
        // Statistiken
        const pending = exceptions.filter(e => e.status === 'pending').length;
        const approved = exceptions.filter(e => e.status === 'approved').length;
        document.getElementById('statPendingExceptions').textContent = pending;
        document.getElementById('statApprovedExceptions').textContent = approved;
    }
}

export function filterExceptions(exceptions, filters = {}) {
    debug.log("Filter Exceptions ()");

    if (!exceptions || exceptions.length === 0) return [];
    
    let filtered = [...exceptions];
    
    // Filter: ExceptionType
    if (filters.exceptionType && filters.exceptionType !== '') {
        filtered = filtered.filter(e => e.exception_type === filters.exceptionType);
    }
    
    // Filter: ExceptionStatus
    if (filters.exceptionStatus && filters.exceptionStatus !== '') {
        filtered = filtered.filter(e => e.status === filters.exceptionStatus);
    }
    
    return filtered;
}


export async function applyExceptionFilters(forceReload = false, page = 1) {
    // Exceptions laden (aus Cache wenn möglich)
    const allExceptions = await loadExceptions(forceReload);

    await loadAppointments();

    debug.log("Apply Exception Filters ()");

    // Aktuelle Filter auslesen
    const filters = {
        exceptionStatus: document.getElementById('filterExceptionStatus')?.value || null,
        exceptionType: document.getElementById('filterExceptionType')?.value || null,
        //group: document.getElementById('filterGroup')?.value || null
    };

    // Filtern
    const filteredExceptions = filterExceptions(allExceptions, filters);

    debug.log("Filtering:", allExceptions, filteredExceptions, filters);

    // Rendern (nur wenn auf Exceptions-Section)
    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'antraege') {
        renderExceptions(filteredExceptions, page);
    } 
}


// Filter zurücksetzen
export async function resetExceptionFilter() {
    document.getElementById('filterExceptionType').value = '';
    document.getElementById('filterExceptionStatus').value = '';

    await showExceptionSection();
}    

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

// Im Init oder beim Section-Wechsel registrieren
export async function initExceptionEventHandlers() {

    debug.log("Init Exception Event Handlers ()");

    // Filter-Änderungen
    document.getElementById('filterExceptionStatus')?.addEventListener('change', () => {
        applyExceptionFilters();
    });
    
    document.getElementById('filterExceptionType')?.addEventListener('change', () => {
        applyExceptionFilters();
    });
    
    // Reset-Button (optional)
    document.getElementById('resetExceptionFilter')?.addEventListener('click', () => {
        document.getElementById('filterExceptionStatus').value = '';
        document.getElementById('filterExceptionType').value = '';
        applyExceptionFilters();
    });
}

export async function showExceptionSection(forceReload = false) {
    
    debug.log("Show Exception Section ()");    
    
    // Daten laden, filtern und anzeigen
    await applyExceptionFilters(forceReload);

}

export async function loadExceptionModalFilters(forceReload = false) {

    const appointments = await loadAppointments(forceReload);

    const appointmentSelect = document.getElementById('exception_appointment');
    appointmentSelect.innerHTML = '<option value="">Bitte wählen...</option>';
    
    if (appointments) {
     
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
    
    // Lade Mitglieder für Dropdown (nur für Admin)
    if (isAdminOrManager) {
        const members = await loadMembers(forceReload);

        //const members = await apiCall('members');
        const memberSelect = document.getElementById('exception_member');
        memberSelect.innerHTML = '<option value="">Bitte wählen...</option>';
        
        if (members) {
            members.filter(m => m.active).forEach(member => {
                memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
            });
        }
    }
}

// ============================================
// MODAL FUNCTIONS
// ============================================

export async function openExceptionModal(exceptionId = null) {
    const modal = document.getElementById('exceptionModal');
    const title = document.getElementById('exceptionModalTitle');
    
    await loadExceptionModalFilters();
    
    // Admin-Felder ein/ausblenden
    document.getElementById('exceptionStatusGroup').style.display = isAdminOrManager ? 'block' : 'none';
    document.getElementById('exceptionMemberGroup').style.display = isAdminOrManager ? 'block' : 'none';
    
    if (exceptionId) {
        title.textContent = isAdminOrManager ? 'Antrag bearbeiten' : 'Antrag ansehen';
        await loadExceptionData(exceptionId);
        updateModalId('exceptionModal', exceptionId)
    } else {
        title.textContent = 'Neuer Antrag';
        document.getElementById('exceptionForm').reset();
        document.getElementById('exception_id').value = '';
        document.getElementById('exception_type').value = 'absence';
        document.getElementById('exception_status').value = 'pending';

        updateModalId('exceptionModal', null);

        // User: Automatisch eigene Member-ID setzen
        //TODO Daten aus Cache laden
        if (!isAdminOrManager) {
            const userData = await apiCall('me');
            const userDetails = await apiCall('users', 'GET', null, { id: userData.user_id });
            if (userDetails && userDetails.member_id) {
                document.getElementById('exception_member').value = userDetails.member_id;                
            }
        }
        
        toggleExceptionFields();
    }
    
    modal.classList.add('active');
}

export function closeExceptionModal() {
    document.getElementById('exceptionModal').classList.remove('active');
}


export function toggleExceptionFields() {
    const type = document.getElementById('exception_type').value;
    const timeGroup = document.getElementById('requestedTimeGroup');
    
    if (type === 'time_correction') {
        timeGroup.style.display = 'block';
        document.getElementById('exception_requested_time').required = true;
    } else {
        timeGroup.style.display = 'none';
        document.getElementById('exception_requested_time').required = false;
    }

    if(isAdminOrManager)
    {
        document.getElementById('exception_member').required = true;

        document.getElementById('exception_member').disabled = false;
        document.getElementById('exception_appointment').disabled = false;
        document.getElementById('exception_type').disabled = false;
    }
}

// Schnelles Genehmigen
window.quickApproveException = async function(exceptionId) {
    // Modal öffnen mit Status = approved vorausgewählt
    await openExceptionModal(exceptionId);
    
    // Status auf "approved" setzen und Felder sperren
    document.getElementById('exception_status').value = 'approved';
    document.getElementById('exception_member').disabled = true;
    document.getElementById('exception_appointment').disabled = true;
    document.getElementById('exception_type').disabled = true;
    
    // Fokus auf Admin-Notizen
    document.getElementById('exception_reason')?.focus();
};

// Schnelles Ablehnen
window.quickRejectException = async function(exceptionId) {
    // Modal öffnen mit Status = rejected vorausgewählt
    await openExceptionModal(exceptionId);
    
    // Status auf "rejected" setzen und Felder sperren
    document.getElementById('exception_status').value = 'rejected';
    document.getElementById('exception_member').disabled = true;
    document.getElementById('exception_appointment').disabled = true;
    document.getElementById('exception_type').disabled = true;
    
    // Fokus auf Admin-Notizen (Grund für Ablehnung)
    document.getElementById('exception_reason')?.focus();
};

// ============================================
// CRUD FUNCTIONS
// ============================================

export async function loadExceptionData(exceptionId) {
    const exception = await apiCall('exceptions', 'GET', null, { id: exceptionId });
    
    if (exception) {
        document.getElementById('exception_id').value = exception.exception_id;
        document.getElementById('exception_member').value = exception.member_id;
        document.getElementById('exception_appointment').value = exception.appointment_id;
        document.getElementById('exception_type').value = exception.exception_type;
        document.getElementById('exception_reason').value = exception.reason;
        document.getElementById('exception_status').value = exception.status;
        
        if (exception.requested_arrival_time) {
            document.getElementById('exception_requested_time').value = mysqlToDatetimeLocal(exception.requested_arrival_time);
        }
        
        toggleExceptionFields();                  
        
        // Bei nicht-pending Status: Felder readonly für User
        if (!isAdminOrManager && exception.status !== 'pending') {
            document.querySelectorAll('#exceptionForm input, #exceptionForm select, #exceptionForm textarea').forEach(field => {
                field.disabled = true;
                field.required = false;
            });
            document.querySelector('.btn-save').style.display = 'none';
        }
    }
}

export async function saveException() {

    // Form-Validierung prüfen
    const form = document.getElementById('exceptionForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const exceptionId = document.getElementById('exception_id').value;
    
    let member_id;
    if (isAdminOrManager) {
        member_id = parseInt(document.getElementById('exception_member').value);
    } else {
        //TODO Load User Data from cache
        const userData = await apiCall('me');
        const userDetails = await apiCall('users', 'GET', null, { id: userData.user_id });
        member_id = userDetails.member_id;        
    }
    
    const type = document.getElementById('exception_type').value;
    let requested_time = null;
    
    if (type === 'time_correction') {
        const timeValue = document.getElementById('exception_requested_time').value;
        if (timeValue) {
            requested_time = datetimeLocalToMysql(timeValue);
        }
    }
    
    const data = {
        member_id: member_id,
        appointment_id: parseInt(document.getElementById('exception_appointment').value),
        exception_type: type,
        reason: document.getElementById('exception_reason').value,
        requested_arrival_time: requested_time,
        status: isAdminOrManager ? document.getElementById('exception_status').value : 'pending'
    };
    
    debug.log("Exception data:",data);

    let result;
    if (exceptionId) {
        result = await apiCall('exceptions', 'PUT', data, { id: exceptionId });
    } else {
        result = await apiCall('exceptions', 'POST', data);
    }
    
    if (result) {
        closeExceptionModal();        

        //await invalidateCache('exceptions');
        await loadExceptions(true);
        
        // Wenn Zeitkorrektur genehmigt wurde, Records neu laden
        if (isAdminOrManager && data.status === 'approved' && data.exception_type === 'time_correction') {
            invalidateCache('records');
        }

        applyExceptionFilters(true, currentExceptionsPage);

        // Erfolgs-Toast
        showToast(
            exceptionId ? 'Eintrag wurde erfolgreich aktualisiert' : 'Eintrag wurde erfolgreich erstellt',
            'success'
        );

    }
}

export async function deleteException(exceptionId) {
    const confirmed = await showConfirm(
        'Antrag wirklich löschen?',
        'Antrag löschen'
    );

    if (confirmed) {
        const result = await apiCall('exceptions', 'DELETE', null, { id: exceptionId });
        if (result) {
            invalidateCache('exceptions',currentYear);
            applyExceptionFilters(true, currentExceptionsPage);
             showToast(`Eintrag wurde gelöscht`, 'success');

        }
    }
}

// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

window.openExceptionModal = openExceptionModal;
window.saveException = saveException;
window.toggleExceptionFields = toggleExceptionFields;
window.closeExceptionModal = () => document.getElementById('exceptionModal').classList.remove('active');
window.deleteException = deleteException;
window.resetExceptionFilter = resetExceptionFilter;
window.applyExceptionFilter = applyExceptionFilters;