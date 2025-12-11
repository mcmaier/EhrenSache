import { apiCall, currentUser, isAdmin } from './api.js';
import { loadAppointments } from './appointments.js';
import { loadTypes } from './management.js';
import { loadMembers } from './members.js';
import { showToast, showConfirm, dataCache, isCacheValid,invalidateCache, currentYear} from './ui.js';
import { translateRecordStatus,datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime, updateModalId } from './utils.js';

// ============================================
// RECORDS
// Reference:
// import {} from './records.js'
// ============================================

let appointmentTypeCache = {};

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadRecords(forceReload = false) {
    const year = currentYear;
    
    // Cache-Check: Nur laden wenn nötig
    if (!forceReload && isCacheValid('records', year)) {
        console.log('Loading records from cache for ${year}', year);
        renderRecords(dataCache.records[year].data);        
        return;
    }

    let params = {};

    params.year = year;
  
    if (currentFilter.appointment) {
        params.appointment_id = currentFilter.appointment;
    }
    if (currentFilter.member) {
        params.member_id = currentFilter.member;
    }

    await loadTypes();
    await loadRecordFilters();
    await loadRecordMemberFilter(); 
    //await loadAppointmentTypesCache();       

    console.log("Loading records from API for ${year}", year);
    const records = await apiCall('records', 'GET', null, params);

    if(!dataCache.records[year])
    {
        dataCache.records[year] = {};
    }

    dataCache.records[year].data = records;
    dataCache.records[year].timestamp = Date.now();    

    renderRecords(records);
}

function renderRecords(recordData)
{
   const tbody = document.getElementById('recordsTableBody');
    tbody.innerHTML = '';
    
    if (recordData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="loading">Keine Einträge gefunden</td></tr>';
        document.getElementById('statTotalRecords').textContent = '0';
        return;
    }    
    
    recordData.forEach(record => {
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

        // Check-in Source Badge
        const sourceInfo = getSourceBadge(record);
        
        const actionsHtml = isAdmin ? `
            <td>
                <button class="action-btn btn-edit" onclick="openRecordModal(${record.record_id})">
                    Bearbeiten
                </button>
                <button class="action-btn btn-delete" onclick="deleteRecord(${record.record_id}, '${record.name} ${record.surname}', '${record.title}')">
                    Löschen
                </button>
            </td>
        ` : '';
        
        const row = `
            <tr>
                <td>${appointmentInfo}</td>
                <td>${appointmentTypeBadge}</td>
                <td>${record.surname}, ${record.name}</td>
                <td>${formattedTime}</td>
                <td>${translateRecordStatus(record.status)}</td>
                <td>${sourceInfo}</td>
                ${actionsHtml}
            </tr>
        `;
        tbody.innerHTML += row;
    });

    document.getElementById('statTotalRecords').textContent = recordData.length;
}


export function applyRecordYearFilter() {
    const year = document.getElementById('filterRecordYear').value;
    currentRecordYear = year || null;

    // Lade Termin-Filter NEU mit dem gewählten Jahr
    loadRecordAppointmentFilter(currentRecordYear);
    
    // Reset Termin-Filter (da sich die verfügbaren Termine geändert haben)
    document.getElementById('filterAppointment').value = '';
    currentFilter.appointment = null;

    loadRecords();
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

export async function loadRecordDropdowns() {
    
    
    // Lade Mitglieder
    //if (dataCache.members.data.length === 0) {
    //    const members =  await loadMembers(true);
    //}
    
    const members =  await loadMembers();
    const memberSelect = document.getElementById('record_member');
    memberSelect.innerHTML = '<option value="">Bitte wählen...</option>';
    
    members
        .filter(m => m.active)
        .forEach(member => {
            memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
        });
    
    const appointments = await loadAppointments();    
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

// Filter-Status
let currentFilter = {
    appointment: null,
    member: null
};

// Lade Filter-Dropdowns
export async function loadRecordFilters() {

    await loadRecordAppointmentFilter();
    await loadRecordMemberFilter();
}

// Separate Funktion NUR für Termine (jahresabhängig)
export async function loadRecordAppointmentFilter() {
    
    await loadAppointments(false);    

    // Lade nur Termine des gefilterten Jahres
    const appointments = dataCache.appointments[currentYear].data;
    const appointmentSelect = document.getElementById('filterAppointment');
    appointmentSelect.innerHTML = '<option value="">Alle Termine</option>';
    
    if (appointments) {
        appointments.forEach(apt => {
            appointmentSelect.innerHTML += `<option value="${apt.appointment_id}">${apt.title} (${apt.date})</option>`;
        });
    }
}

// Separate Funktion NUR für Mitglieder (jahresunabhängig)
export async function loadRecordMemberFilter() {
    
    // Cache laden falls leer
    if (!dataCache.members.data || dataCache.members.data.length === 0) {
        await loadMembers(true);
    }

    // Safe extraction
    let members = dataCache.members.data;

    //console.log(members);

    // Falls API-Response-Wrapper: {success: true, data: [...]}
    if (!Array.isArray(members) && members.data) {
        members = members.data;
    }

    const memberSelect = document.getElementById('filterMember');
    const currentValue = memberSelect.value;
    
    memberSelect.innerHTML = '<option value="">Alle Mitglieder</option>';
    
    if (members) {
        members
            .filter(m => m.active)
            .forEach(member => {
                memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
            });
    }
    
    // Stelle vorherigen Wert wieder her
    if (currentValue) {
        memberSelect.value = currentValue;
    }
}

// Filter anwenden
export async function applyRecordFilter() {
    const appointmentId = document.getElementById('filterAppointment').value;
    const memberId = document.getElementById('filterMember').value;
    
    currentFilter.appointment = appointmentId || null;
    currentFilter.member = memberId || null;
    
    await loadRecords();
}

// Filter zurücksetzen
export async function resetRecordFilter() {
    document.getElementById('filterAppointment').value = '';
    document.getElementById('filterMember').value = '';
    currentFilter.appointment = null;
    currentFilter.member = null;

    // Jahresfilter NICHT zurücksetzen, aber Termin-Filter neu laden
    //loadRecordFilters(currentRecordYear);
    await loadRecords();
}


function getSourceBadge(record) {
    const sources = {
        'admin': { icon: '👤', label: 'Admin', color: '#3498db' },
        'user_totp': { icon: '📱', label: 'App', color: '#27ae60' },
        'device_auth': { icon: '🔐', label: 'Gerät', color: '#9b59b6' },        
        'auto_checkin': { icon: '🤖', label: 'Auto', color: '#95a5a6' },
        'import':{icon: '📤', label: 'Import', color: '#dbbb2dff'}
    };
    
    const source = sources[record.checkin_source] || sources['admin'];
    
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


async function loadAppointmentTypesCache() {

    await loadTypes();    
        
    if (dataCache.types.data) {
        // Erstelle Lookup-Objekt: { type_id: { type_name, color, ... } }    
        dataCache.types.data.forEach(type => {
            appointmentTypeCache[type.type_id] = {
                type_name: type.type_name,
                color: type.color || '#667eea',
                description: type.description
            };
        });        
        console.log('Appointment types cached:', appointmentTypeCache);
    }
}

// ============================================
// MODAL FUNCTIONS
// ============================================

export async function openRecordModal(recordId = null) {
    const modal = document.getElementById('recordModal');
    const title = document.getElementById('recordModalTitle');
    
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
    appointmentSelect.removeEventListener('change', updateAppointmentTypeDisplay);
    appointmentSelect.removeEventListener('change',updateArrivalTimeFromAppointment);
    appointmentSelect.addEventListener('change', updateAppointmentTypeDisplay);
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
        updateAppointmentTypeDisplay();
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
    
    if (result) {
        closeRecordModal();

        invalidateCache('records', currentYear);
        await loadRecords(true);
        // Cache leeren für nächstes Mal
        //membersCache = [];
        //appointmentsCache = [];
        
        // Erfolgs-Toast
        showToast(
            recordId ? 'Eintrag wurde erfolgreich aktualisiert' : 'Eintrag wurde erfolgreich erstellt',
            'success'
        );
    }
}

export async function deleteRecord(recordId, memberName, appointmentTitle) {
     const confirmed = await showConfirm(
        `Anwesenheit von "${memberName}" am "${appointmentTitle}" wirklich löschen?`,
        'Anwesenheit löschen'
    );

    if (confirmed) {
        const result = await apiCall('records', 'DELETE', null, { id: recordId });
        if (result) {
            invalidateCache('records', currentYear);
            loadRecords(true);
            showToast(`Eintrag wurde gelöscht`, 'success');
        }
    }
}

function updateAppointmentTypeDisplay() {
    const appointmentSelect = document.getElementById('record_appointment');
    const selectedOption = appointmentSelect.options[appointmentSelect.selectedIndex];
    const typeGroup = document.getElementById('recordAppointmentTypeGroup');
    const typeBadge = document.getElementById('recordAppointmentTypeBadge');
    
    // Kein Termin gewählt
    if (!selectedOption.value) {
        typeGroup.style.display = 'none';
        return;
    }
    
    // Termin gewählt
    typeGroup.style.display = 'block';
    
    const typeId = selectedOption.dataset.typeId;
    const typeName = selectedOption.dataset.typeName;
    
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
window.resetRecordFilter = resetRecordFilter;
window.applyRecordFilter = applyRecordFilter;
window.applyRecordYearFilter = applyRecordYearFilter;