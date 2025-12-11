
import { apiCall, currentUser, isAdmin } from './api.js';
import { showToast, showConfirm, dataCache, isCacheValid,invalidateCache,currentYear} from './ui.js';
import { loadRecords } from './records.js';
import {translateExceptionStatus, translateExceptionType, datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime , updateModalId} from './utils.js';
import { loadUserData } from './users.js';
import { loadAppointments } from './appointments.js';
import { loadMembers } from './members.js';

// ============================================
// EXCEPTIONS
// Reference:
// import {} from './exceptions.js'
// ============================================

let currentExceptionFilter = {
    status: null,
    type: null
};

let currentExceptionYear = null;

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadExceptions(forceReload = false) {
    const year = currentYear;

    // Cache-Check: Nur laden wenn nötig
    if (!forceReload && isCacheValid('exceptions', year)) {
        console.log('Loading exceptions from cache for ${year}', year);
        renderExceptions(dataCache.exceptions[year].data);        
        return dataCache.exceptions[year].data;
    }

    console.log('Loading exceptions from API for ${year}', year);

    let params = {};

    if (currentExceptionFilter.status) {
        params.status = currentExceptionFilter.status;
    }
    if (currentExceptionFilter.type) {
        params.type = currentExceptionFilter.type;
    }

    const exceptions = await apiCall('exceptions', 'GET', null, {year: year});

    if(!dataCache.exceptions[year])
    {
        dataCache.exceptions[year] = {};
    }

    dataCache.exceptions[year].data = exceptions;
    dataCache.exceptions[year].timestamp = Date.now();

    renderExceptions(exceptions);
    return exceptions;
}

function renderExceptions(exceptionData)
{   
    if (!exceptionData) return;
    
    const tbody = document.getElementById('exceptionsTableBody');
    tbody.innerHTML = '';
    
    if (exceptionData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="loading">Keine Anträge gefunden</td></tr>';
        document.getElementById('statPendingExceptions').textContent = '0';
        document.getElementById('statApprovedExceptions').textContent = '0';
        return;
    }
    
    exceptionData.forEach(exception => {
        const createdAt = new Date(exception.created_at);
        const formattedCreated = createdAt.toLocaleString('de-DE');
        
        const requestedTime = exception.requested_arrival_time 
            ? new Date(exception.requested_arrival_time).toLocaleString('de-DE')
            : '-';
        
        const statusBadge = `<span class="status-badge status-${exception.status}">${translateExceptionStatus(exception.status)}</span>`;
        const typeBadge = `<span class="type-badge">${translateExceptionType(exception.exception_type)}</span>`;
        
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
        if (isAdmin) {
            actionsHtml = `
                <td>
                    <button class="action-btn btn-edit" onclick="openExceptionModal(${exception.exception_id})">
                        ${exception.status === 'pending' ? 'Bearbeiten' : 'Ansehen'}
                    </button>
                    ${exception.status === 'pending' ? `
                        <button class="action-btn btn-delete" onclick="deleteException(${exception.exception_id})">
                            Löschen
                        </button>
                    ` : ''}
                </td>
            `;
        } else if (exception.status === 'pending') {
            actionsHtml = `
                <td>
                    <button class="action-btn btn-edit" onclick="openExceptionModal(${exception.exception_id})">
                        Bearbeiten
                    </button>
                    <button class="action-btn btn-delete" onclick="deleteException(${exception.exception_id})">
                        Löschen
                    </button>
                </td>
            `;
        }
        
        const row = `
            <tr>
                <td>${typeBadge}</td>
                <td>${exception.surname}, ${exception.name}</td>
                <td>${appointmentInfo}</td>
                <td>${exception.reason}</td>
                <td>${requestedTime}</td>
                <td>${statusBadge}</td>
                <td>${formattedCreated}</td>
                ${actionsHtml}
            </tr>
        `;
        tbody.innerHTML += row;
    });

    // Statistiken
    const pending = exceptionData.filter(e => e.status === 'pending').length;
    const approved = exceptionData.filter(e => e.status === 'approved').length;
    document.getElementById('statPendingExceptions').textContent = pending;
    document.getElementById('statApprovedExceptions').textContent = approved;
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

export async function loadExceptionFilters(forceReload = false) {

    //await loadAppointments();

    const appointments = await loadAppointments();//dataCache.appointments[currentYear].data;

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
    //TODO Members aus Cache laden
    if (isAdmin) {
        const members = await loadMembers();

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

export function applyExceptionFilter() {
    currentExceptionFilter.status = document.getElementById('filterExceptionStatus').value || null;
    currentExceptionFilter.type = document.getElementById('filterExceptionType').value || null;
    loadExceptions();
}

export function resetExceptionFilter() {
    document.getElementById('filterExceptionStatus').value = '';
    document.getElementById('filterExceptionType').value = '';
    currentExceptionFilter.status = null;
    currentExceptionFilter.type = null;
    loadExceptions();
}

// ============================================
// MODAL FUNCTIONS
// ============================================

export async function openExceptionModal(exceptionId = null) {
    const modal = document.getElementById('exceptionModal');
    const title = document.getElementById('exceptionModalTitle');
    
    await loadExceptionFilters();
    
    // Admin-Felder ein/ausblenden
    document.getElementById('exceptionStatusGroup').style.display = isAdmin ? 'block' : 'none';
    document.getElementById('exceptionMemberGroup').style.display = isAdmin ? 'block' : 'none';
    
    if (exceptionId) {
        title.textContent = isAdmin ? 'Antrag bearbeiten' : 'Antrag ansehen';
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
        if (!isAdmin) {
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

    if(isAdmin)
    {
        document.getElementById('exception_member').required = true;
    }
}

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
        if (!isAdmin && exception.status !== 'pending') {
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
    if (isAdmin) {
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
        status: isAdmin ? document.getElementById('exception_status').value : 'pending'
    };
    
    console.log("Exception data:",data);

    let result;
    if (exceptionId) {
        result = await apiCall('exceptions', 'PUT', data, { id: exceptionId });
    } else {
        result = await apiCall('exceptions', 'POST', data);
    }
    
    if (result) {
        closeExceptionModal();        
        invalidateCache('exceptions',currentYear);
        loadExceptions(true);

        // Wenn Zeitkorrektur genehmigt wurde, Records neu laden
        if (isAdmin && data.status === 'approved' && data.exception_type === 'time_correction') {
            invalidateCache('exceptions',currentYear);
            invalidateCache('records',currentYear);
            //loadRecords();
        }

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
            loadExceptions(true);
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
window.applyExceptionFilter = applyExceptionFilter;