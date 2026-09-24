/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { apiCall, isAdminOrManager, currentUser } from './api.js';
import { loadAppointments, setCalendarMonth } from './appointments.js';
import { loadGroups, loadTypes } from './management.js';
import { loadMembers, getUserGroupIds } from './members.js';
import { showToast, showConfirm, dataCache, isCacheValid, invalidateCache, currentYear, subgroupLabel, setCurrentYear, navigateToSection } from './ui.js';
import { datetimeLocalToMysql, mysqlToDatetimeLocal, updateModalId, escapeHtml, getCompatibleAppointments, getCompatibleMembers } from './utils.js';
import { debug } from '../app.js'
import { globalPaginationValue } from './settings.js';
import { groupingAvailableStages, groupingSections, groupingDuplicateCount, groupingStored, groupingStore, GROUPING_KEY_ATTENDANCE } from './grouping.js';
import { CHIPS_RECORDS_ALL, CHIPS_RECORDS_LIST, CHIPS_RECORDS_MEMBER, attendanceState, countChips, filterByChip, resolveActiveChip, renderFilterChips, setResetEnabled } from './filter_chips.js';

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
let currentMode = RecordMode.ALL_RECORDS;
let currentAppointmentId = null;
let currentMemberId = null;
let currentAppointmentType = null;
let isLoadingFilters = false;
// Sprung aus dem Kalender (Spec 2026-09-22-kalender-anwesenheit): Ziel des
// Rueckwegs und zwei Zaehler, damit showRecordsSection() waehrend eines
// Sprungs den Rueckweg nicht gleich wieder verwirft. Muster wie navSeq in
// ui.js: jumpSeq erkennt den ueberholten Sprung (zwei schnelle Klicks auf
// verschiedene Termine), jumpActive ist eine Tiefe, kein Zustand -- der
// langsamere Sprung darf die Marke des neueren nicht loeschen.
let attendanceReturn = null;   // { date: 'YYYY-MM-DD', from: 'calendar'|'list', appointmentId }
let jumpSeq = 0;
let jumpActive = 0;
let jumpBaseline = null;       // Stand vor dem ERSTEN Sprung einer Kette
// Aktiver Status-Chip (Spec 2026-09-22). "missing" gibt es nur in den
// Listenmodi; beim Wechsel zu ALL_RECORDS faellt er auf "all" zurueck.
let recordStatusChip = 'all';

/**
 * Einziger Weg, currentMode zu aendern.
 *
 * #recordsGroupingBar (Untergruppen-Umschalter) gehoert nur zur Terminansicht
 * ATTENDANCE_BY_APPOINTMENT -- er wurde bisher ausschliesslich in
 * renderAttendanceList() befuellt und sonst nirgends geleert. Beim Wechsel in
 * eine andere Ansicht (Terminart, Mitglied, Filter zuruecksetzen) blieb er
 * dadurch stehen und filterte die dort gezeigten Daten fälschlich mit.
 *
 * Statt das Aufraeumen an jeder der Stellen zu wiederholen, die currentMode
 * setzen, laeuft jede davon durch diese Funktion.
 */
function setRecordMode(mode) {
    currentMode = mode;
    if (mode !== RecordMode.ATTENDANCE_BY_APPOINTMENT) {
        const bar = document.getElementById('recordsGroupingBar');
        if (bar) bar.innerHTML = '';
    }
}

// State für Cross-Filtering im Record-Modal
let _recordAllMembers = [];
let _recordAllAppointments = [];
let _recordTypes = [];

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
    if (filters.aptType && filters.aptType !== '') {
        filtered = filtered.filter(r => r.appointment_type_id == filters.aptType);
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
        // Ohne diese beiden Zeilen blieb die Paginierung des vorigen Filters
        // stehen, und ein Klick darauf zeigte dessen Einträge wieder (OI-84)
        allFilteredRecords = [];
        renderRecordsPagination(1, 0, 0);
        return;
    }

    // Alle Records speichern für Pagination
    allFilteredRecords = records;
    currentRecordsPage = page;

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
        
         // Termin-Info mit Terminart
        let appointmentInfo = '-';
        if (record.appointment_id && record.title) {
            appointmentInfo = `<div style="line-height: 1.4;">
                <strong>${escapeHtml(record.title)}</strong>`;
            
            if (record.date && record.start_time) {
                const aptDate = new Date(record.date + 'T00:00:00');
                const formattedAptDate = aptDate.toLocaleDateString('de-DE');
                appointmentInfo += `<br><small style="color: #7f8c8d;">${formattedAptDate}, ${record.start_time.substring(0, 5)}</small>`;
            }
            
            appointmentInfo += '</div>';
        }

        const typeId = record.appointment_type_id;

        // Terminart Badge
        const appointmentTypeBadge = createAppointmentTypeBadge(typeId);                

        // Member-Info mit Mitgliedsnr. wenn vorhanden       
        let memberInfo = `<div style="line-height: 1.4;">${escapeHtml(record.surname)}, ${escapeHtml(record.name)}`;
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
                                    onclick="deleteRecord(${record.record_id})"
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
    
    // KEIN automatisches Scrollen - Position beibehalten
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

/**
 * Zeichnet die Status-Chips fuer den aktuellen Modus und liefert die nach
 * Chip gefilterte Liste. base ist die Liste NACH allen uebrigen Filtern und
 * VOR dem Chip (facettierte Zaehlung).
 */
/** Chipsatz je Ansicht; die Mitgliedsansicht trennt kommende Termine ab (OI-89). */
function recordChipDefs() {
    if (currentMode === RecordMode.ALL_RECORDS) return CHIPS_RECORDS_ALL;
    if (currentMode === RecordMode.ATTENDANCE_BY_MEMBER) return CHIPS_RECORDS_MEMBER;
    return CHIPS_RECORDS_LIST;
}

/**
 * Statuszelle der Anwesenheitsliste. "Kommend" (OI-89) in neutraler Farbe:
 * Der Termin hat noch nicht begonnen, fehlen kann noch niemand.
 */
function attendanceStatusCell(row) {
    switch (attendanceState(row)) {
        case 'present':
            return { statusHtml: '<span style="color: #258b3d; font-weight: 500;">✓ Anwesend</span>', rowClass: '' };
        case 'excused':
            return { statusHtml: '<span style="color: #e97a13; font-weight: 500;">⚠ Entschuldigt</span>', rowClass: '' };
        case 'upcoming':
            return { statusHtml: '<span class="attendance-upcoming">◷ Kommend</span>', rowClass: '' };
        default:
            return { statusHtml: '<span style="color: #dc3545; font-weight: 500;">✗ Fehlend</span>', rowClass: 'table-secondary' };
    }
}

function applyRecordChips(base, rerender) {
    const defs = recordChipDefs();
    recordStatusChip = resolveActiveChip(defs, recordStatusChip, 'all');

    renderFilterChips(
        document.getElementById('recordStatusChips'),
        defs, countChips(base, defs), recordStatusChip,
        key => { recordStatusChip = key; rerender(); },
        { label: 'Status der Anwesenheit' }
    );

    const anyFilter = ['filterAptType', 'filterAppointment', 'filterMember']
        .some(id => Boolean(document.getElementById(id)?.value));
    setResetEnabled(document.getElementById('resetRecordFilter'),
        anyFilter || recordStatusChip !== 'all');

    return filterByChip(base, defs, recordStatusChip);
}

export async function loadRecordFilters(forceReload = false) {

    debug.log("Load Record Filters ()");

    // Verhindere Event-Trigger während des Ladens
    if (isLoadingFilters) {
        debug.log("Already loading filters, skipping");
        return;
    }

    isLoadingFilters = true;

    // Terminarten und User-Gruppen laden
    const aptTypes = await loadTypes(forceReload);
    const userGroupIds = await getUserGroupIds();

    // Terminart-Filter befüllen
    const aptTypeSelect = document.getElementById('filterAptType');
    const currentAptTypeValue = aptTypeSelect.value;

    aptTypeSelect.innerHTML = '<option value="">Alle Termine</option>';
    if (aptTypes && aptTypes.length > 0) {
        aptTypes.forEach(aptt => {
            // Für non-Admin: nur Terminarten mit passender Gruppenverknüpfung anzeigen
            if (userGroupIds !== null) {
                // Typen ohne Gruppen ausblenden (noch nicht konfiguriert)
                if (!aptt.groups || aptt.groups.length === 0) return;
                // Nur Typen anzeigen, die mindestens eine der eigenen Gruppen haben
                const hasMatch = aptt.groups.some(g => userGroupIds.includes(g.group_id));
                if (!hasMatch) return;
            }

            // Terminart-Anzeige im Dropdown-Text
            let displayText = `${aptt.type_name}`;

            // Erstelle Option mit data-Attributen
            const option = document.createElement('option');
            option.value = aptt.type_id;
            option.textContent = displayText;

            // Füge Farbe hinzu (funktioniert in den meisten Browsern)
            if (aptt.color) {
                option.style.color = aptt.color;
                option.style.fontWeight = '500';
            }

            // Speichere Type-Daten für Badge-Anzeige
            option.dataset.typeId = aptt.type_id || '';
            option.dataset.typeName = aptt.type_name || '';

            aptTypeSelect.appendChild(option);
        });
    }
    aptTypeSelect.value = currentAptTypeValue;
    // Terminfilter und Mitgliedsfilter sind Verwaltern vorbehalten; das
    // regelt data-role am Wrapper (updateUIForRole), nicht mehr diese
    // Funktion -- sonst blieb die leere form-group als Luecke stehen.

    loadAppointmentFilter(forceReload);

    loadMemberFilter(forceReload);

    isLoadingFilters = false;
}

async function loadAppointmentFilter(forceReload = false, appointmentType = null)
{
    // Termine für Jahr laden (aus Cache wenn möglich)
    const appointments = await loadAppointments(forceReload);

    // Termin-Filter befüllen
    const appointmentSelect = document.getElementById('filterAppointment');
    const currentAppointmentValue = appointmentSelect.value;

    let filtered = [...appointments];

    if (appointmentType) {
        filtered = filtered.filter(r => r.type_id == appointmentType);
    }
    
    appointmentSelect.innerHTML = '<option value="">Alle Termine</option>';
    if (filtered && filtered.length > 0) {
        filtered.forEach(app => {

            const date = new Date(app.date + 'T00:00:00');
            const formattedDate = date.toLocaleDateString('de-DE');
            const startTime = app.start_time ? app.start_time.substring(0, 5) : '';
            
            // Terminart-Anzeige im Dropdown-Text
            let displayText = `${app.title} (${formattedDate} - ${startTime})`;
            
            if (app.type_name) {
                displayText = `${displayText}`;
            }
            
            // Erstelle Option mit data-Attributen
            const option = document.createElement('option');
            option.value = app.appointment_id;
            option.textContent = displayText;

            // Füge Farbe hinzu (funktioniert in den meisten Browsern)
            if (app.color) {
                option.style.color = app.color;
                option.style.fontWeight = '500';
            }
            
            // Speichere Type-Daten für Badge-Anzeige
            option.dataset.typeId = app.type_id || '';
            option.dataset.typeName = app.type_name || '';
            
            appointmentSelect.appendChild(option);         
            
        });
    }
    appointmentSelect.value = currentAppointmentValue;
}

async function loadMemberFilter(forceReload = false, appointmentType = null)
{
    // Mitglieder laden (jahresunabhängig)
    const members = await loadMembers();    

    // Mitglieder-Filter befüllen
    const memberSelect = document.getElementById('filterMember');
    const currentMemberValue = memberSelect.value;
    
    let filtered = [...members];

    if (appointmentType) {
        // Hole member_group_ids für diesen appointment_type
        const allowedGroupIds = await getAppointmentTypeGroups(appointmentType);
        
        if (allowedGroupIds.length > 0) {
            filtered = filtered.filter(member => {
                // Member group_ids: "1, 2" → [1, 2]
                const memberGroupIds = member.group_ids 
                    ? member.group_ids.split(',').map(id => parseInt(id.trim()))
                    : [];
                
                // Hat Member mindestens eine erlaubte Gruppe?
                return memberGroupIds.some(gid => allowedGroupIds.includes(gid));
            });
        }
    }

    memberSelect.innerHTML = '<option value="">Alle Mitglieder</option>';
    if (filtered  && filtered .length > 0) {
        filtered
            .filter(m => m.is_active_in_period)
            .forEach(member => {
                memberSelect.innerHTML += `<option value="${member.member_id}">${escapeHtml(member.surname)}, ${escapeHtml(member.name)}</option>`;
            });
    }
    memberSelect.value = currentMemberValue;
}

async function getAppointmentTypeGroups(appointmentTypeId) {
    try {
        const response = await apiCall(`appointment_types`, 'GET', null, {id: appointmentTypeId});
        
        debug.log("Response: ", response);

        if (response.success && response.groups && Array.isArray(response.groups)) {
            // Extrahiere group_ids aus dem Objekt-Array
            return response.groups.map(group => group.group_id);
        }
        return [];
    } catch (error) {
        debug.error('Fehler beim Laden der Type-Gruppen:', error);
        return [];
    }
}

export async function applyRecordFilters(forceReload = false, currentPage = 1) {
    // Records laden (aus Cache wenn möglich)
    const allRecords = await loadRecords(forceReload);

    debug.log("Apply Record Filters ()");

    // Aktuelle Filter auslesen
    const filters = {
        appointment: document.getElementById('filterAppointment')?.value || null,
        member: document.getElementById('filterMember')?.value || null,
        aptType: document.getElementById('filterAptType')?.value || null
    };

    // Filtern
    const filteredRecords = filterRecords(allRecords, filters);

    // Einträge inaktiver Mitglieder für das gewählte Jahr ausblenden
    const members = await loadMembers();
    const activeFilteredRecords = filteredRecords.filter(r => {
        const member = members.find(m => m.member_id === r.member_id);
        // Nicht gefundenes Mitglied (z.B. gelöscht) → trotzdem anzeigen
        return !member || member.is_active_in_period;
    });

    // Rendern (nur wenn auf Records-Section)
    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'anwesenheit') {
        const shown = applyRecordChips(activeFilteredRecords, () => applyRecordFilters(false, 1));
        renderRecords(shown, currentPage);
        debug.log('Records rendered');
    }

    //return filteredRecords;
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

/**
 * Wechselt in die Anwesenheitsliste eines Termins -- derselbe Weg fuer die
 * Auswahl im Termin-Filter und fuer den Sprung aus dem Kalender.
 */
async function enterAppointmentAttendance(appointmentId) {
    // Der Filter liefert einen String, der Sprung eine Zahl -- currentAppointmentId
    // soll ueber beide Wege gleich typisiert sein.
    appointmentId = String(appointmentId);

    const memberFilter = document.getElementById('filterMember');
    const aptTypeFilter = document.getElementById('filterAptType');

    setRecordMode(RecordMode.ATTENDANCE_BY_APPOINTMENT);
    currentAppointmentId = appointmentId;
    currentMemberId = null;
    // Nicht hart auf null: das Auswahlfeld wird nur gesperrt, nicht geleert.
    // Sonst kaeme die Mitgliedsliste nach "Terminart, Termin, Termin leeren,
    // Mitglied" ungefiltert, obwohl das Feld die Terminart noch zeigt. Der
    // Sprung hat filterAptType zuvor selbst geleert.
    currentAppointmentType = aptTypeFilter.value || null;
    memberFilter.disabled = true;
    memberFilter.value = '';
    aptTypeFilter.disabled = true;
    await loadAttendanceList(appointmentId);
}

/** Verwirft den gemerkten Rueckweg und verbirgt den Knopf. */
function clearAttendanceReturn() {
    attendanceReturn = null;
    const back = document.getElementById('recordsBackToAppointments');
    if (back) back.hidden = true;
}

/**
 * Sprung aus Kalender-Popup oder Terminliste: Jahr des Termins setzen, in den
 * Bereich Anwesenheit wechseln und dort die Anwesenheitsliste des Termins
 * oeffnen. Steht der Termin nicht in den Terminen seines Jahres, bleibt die
 * Ansicht, wo sie ist.
 */
/**
 * Stand des Bereichs, so wie ihn ein Sprung vorfindet: Modulzustand, Jahr,
 * Rueckweg und die Auswahlfelder. Ohne die DOM-Haelfte stellte ein
 * gescheiterter Sprung zwar currentMode her, applyRecordFilters() lese aber
 * anschliessend die stehen gebliebenen Feldwerte.
 */
function captureRecordState() {
    const aptType = document.getElementById('filterAptType');
    const appointment = document.getElementById('filterAppointment');
    const member = document.getElementById('filterMember');

    return {
        year: Number(currentYear),
        mode: currentMode,
        appointmentId: currentAppointmentId,
        memberId: currentMemberId,
        appointmentType: currentAppointmentType,
        chip: recordStatusChip,
        attendanceReturn: attendanceReturn,
        aptTypeValue: aptType ? aptType.value : '',
        aptTypeDisabled: aptType ? aptType.disabled : false,
        appointmentValue: appointment ? appointment.value : '',
        appointmentDisabled: appointment ? appointment.disabled : false,
        memberValue: member ? member.value : '',
        memberDisabled: member ? member.disabled : false
    };
}

/** Gegenstueck zu captureRecordState(). */
function restoreRecordState(state) {
    if (!state) return;

    if (Number(currentYear) !== state.year) {
        setCurrentYear(state.year, { reload: false });
    }

    setRecordMode(state.mode);
    currentAppointmentId = state.appointmentId;
    currentMemberId = state.memberId;
    currentAppointmentType = state.appointmentType;
    recordStatusChip = state.chip;

    const aptType = document.getElementById('filterAptType');
    if (aptType) {
        aptType.value = state.aptTypeValue;
        aptType.disabled = state.aptTypeDisabled;
    }
    const appointment = document.getElementById('filterAppointment');
    if (appointment) {
        appointment.value = state.appointmentValue;
        appointment.disabled = state.appointmentDisabled;
    }
    const member = document.getElementById('filterMember');
    if (member) {
        member.value = state.memberValue;
        member.disabled = state.memberDisabled;
    }

    // Sonst zeigte der Knopf nach einem gescheiterten zweiten Sprung noch auf
    // den Termin des ersten.
    attendanceReturn = state.attendanceReturn;
    const back = document.getElementById('recordsBackToAppointments');
    if (back) back.hidden = !attendanceReturn;
}

/**
 * Vorlauf des Sprungs: Datum pruefen, Jahr stellen, Termine des Jahres holen
 * und den Termin darin suchen. Liefert false, wenn es nicht weitergeht --
 * jeder Abbruch meldet sich selbst.
 */
async function resolveJumpTarget(appointmentId, date) {
    // Ohne diese Pruefung macht ein fehlendes Datum aus dem Jahr NaN --
    // setCurrentYear(NaN) schreibt "NaN" in den sessionStorage und die
    // Anwendung ist bis zur naechsten Jahreswahl unbrauchbar.
    if (!/^\d{4}-\d{2}-\d{2}/.test(String(date))) {
        debug.error('openAttendanceForAppointment: unbrauchbares Datum', date);
        showToast('Anwesenheitsliste konnte nicht geoeffnet werden', 'error');
        return false;
    }

    const year = Number(String(date).slice(0, 4));
    if (year !== Number(currentYear)) {
        setCurrentYear(year, { reload: false });
    }

    const appointments = await loadAppointments(false);

    // apiCall() wirft nie: bei HTTP-Fehler kommt {success:false} zurueck, bei
    // 401 null. Beides ist keine Liste -- ohne diese Unterscheidung zerbricht
    // .some(), oder es erscheint faelschlich "Termin nicht gefunden",
    // waehrend gerade abgemeldet wird.
    if (!Array.isArray(appointments)) {
        // Das Fehlerobjekt liegt jetzt im Cache; ohne Verwerfen wiederholte
        // sich die Meldung zehn Minuten lang ohne neuen Versuch.
        await invalidateCache('appointments', year);
        showToast('Termine konnten nicht geladen werden', 'error');
        return false;
    }

    if (!appointments.some(a => String(a.appointment_id) === String(appointmentId))) {
        showToast('Termin nicht gefunden', 'error');
        return false;
    }

    return true;
}

export async function openAttendanceForAppointment(appointmentId, date, from = 'calendar') {
    debug.log('Jump to attendance', appointmentId, date, from);

    const seq = ++jumpSeq;

    // Die Baseline nur beim ersten Sprung einer Kette ziehen: startet ein
    // zweiter, waehrend der erste noch laeuft, saehe er dessen halben Stand
    // (das bereits gestellte Jahr) als "vorher" an und stellte am Ende genau
    // den wieder her -- der Nutzer bliebe dauerhaft, ueber den
    // sessionStorage sogar ueber einen Reload hinweg, in einem fremden Jahr.
    if (jumpActive === 0) {
        jumpBaseline = captureRecordState();
    }
    jumpActive++;

    let done = false;

    try {
        if (!await resolveJumpTarget(appointmentId, date)) return;
        if (seq !== jumpSeq) return;

        // Modus, Termin und Statusfilter stehen VOR dem Bereichswechsel:
        // navigateToSection() ruft showRecordsSection(), und die laedt anhand
        // genau dieser Werte. Stuenden sie noch auf der vorigen Auswahl,
        // lueden wir erst eine Liste, die niemand sehen will, und danach ein
        // zweites Mal die richtige. Der Sprung zeigt dabei immer die ganze
        // Liste, nicht den zuletzt gewaehlten Statusfilter des Bereichs.
        setRecordMode(RecordMode.ATTENDANCE_BY_APPOINTMENT);
        currentAppointmentId = String(appointmentId);
        currentMemberId = null;
        currentAppointmentType = null;
        recordStatusChip = 'all';

        if (!await navigateToSection('anwesenheit')) {
            return;
        }
        if (seq !== jumpSeq) return;

        // Der Termin-Filter wird in loadRecordFilters() ohne await befuellt --
        // hier ausdruecklich abwarten, bevor gewaehlt wird.
        await loadAppointmentFilter(false);
        if (seq !== jumpSeq) return;

        document.getElementById('filterAptType').value = '';
        document.getElementById('filterAppointment').value = String(appointmentId);

        await enterAppointmentAttendance(appointmentId);
        if (seq !== jumpSeq) return;

        attendanceReturn = {
            date: String(date),
            from: from === 'list' ? 'list' : 'calendar',
            appointmentId: String(appointmentId)
        };
        const back = document.getElementById('recordsBackToAppointments');
        if (back) back.hidden = false;
        done = true;
    } catch (e) {
        debug.error('Sprung in die Anwesenheit fehlgeschlagen', appointmentId, e);
        showToast('Anwesenheitsliste konnte nicht geoeffnet werden', 'error');
    } finally {
        jumpActive--;
        // Aufraeumen darf nur der letzte aussteigende Sprung, und nur wenn
        // ihn kein neuerer ueberholt hat -- sonst draehte er dessen Arbeit
        // zurueck.
        if (!done && jumpActive === 0 && seq === jumpSeq) {
            restoreRecordState(jumpBaseline);
        }
    }
}

/**
 * "← Zurueck zu Termine": dorthin, wo der Sprung begann. Kalender und Liste
 * stehen im selben Bereich untereinander, es gibt keine Reiter -- deshalb
 * entscheidet die gemerkte Herkunft, wohin gerollt wird.
 */
export async function backToAppointments() {
    const target = attendanceReturn;
    clearAttendanceReturn();
    if (!target) return;

    setCurrentYear(Number(target.date.slice(0, 4)), { reload: false });

    // Nur fuer den Kalender. Kam der Sprung aus der Liste, bleibt der Monat
    // so stehen, wie der Nutzer ihn verlassen hat.
    if (target.from !== 'list') {
        setCalendarMonth(target.date);
    }

    // Der Knopf ist schon weg -- scheitert der Wechsel, saesse der Nutzer
    // sonst ohne Hinweis fest.
    if (!await navigateToSection('termine')) {
        showToast('Wechsel zu den Terminen nicht moeglich', 'error');
        return;
    }

    scrollToAppointmentOrigin(target);
}

/** Rollt zum Ausgangspunkt des Sprungs: Zeile der Terminliste oder Kalender. */
function scrollToAppointmentOrigin(target) {
    if (target.from === 'list') {
        const id = Number(target.appointmentId);
        const row = Number.isFinite(id)
            ? document.querySelector(`#appointmentsTableBody tr[data-appointment-id="${id}"]`)
            : null;
        if (row) {
            row.scrollIntoView({ block: 'center' });
            return;
        }

        // Andere Seite der Paginierung oder anderer Filter: dann ist der Kopf
        // der Liste naeher am Ziel als der Anfang des Bereichs.
        const list = document.getElementById('appointmentsTableBody');
        if (list) list.scrollIntoView({ block: 'start' });
        return;
    }

    const calendar = document.getElementById('calendarDaysContainer');
    if (calendar) calendar.scrollIntoView({ block: 'start' });
}

// Im Init oder beim Section-Wechsel registrieren
export async function initRecordEventHandlers() {

    debug.log("Init Record Event Handlers ()");

    // Event-Listener für Gruppen-Filter
    document.getElementById('filterAptType').addEventListener('change', async function() {
        clearAttendanceReturn();
        const appointmentTypeId = this.value;
        const appointmentFilter = document.getElementById('filterAppointment');
        const memberFilter = document.getElementById('filterMember');

        if (appointmentTypeId && appointmentTypeId !== '') 
        {
            currentAppointmentType = appointmentTypeId;  
        }
        else 
        {
            currentAppointmentType = null;
        }
                
        setRecordMode(RecordMode.ALL_RECORDS);
        currentAppointmentId = null;
        currentMemberId = null;
        appointmentFilter.disabled = false;
        appointmentFilter.value = '';
        memberFilter.disabled = false;
        memberFilter.value = '';

        loadAppointmentFilter(false,appointmentTypeId);
        loadMemberFilter(false,appointmentTypeId);

        await applyRecordFilters(false);
    });

    // Event-Listener für Termin-Filter
    document.getElementById('filterAppointment').addEventListener('change', async function() {
        clearAttendanceReturn();
        const appointmentId = this.value;
        const memberFilter = document.getElementById('filterMember');
        const aptTypeFilter = document.getElementById('filterAptType');

        if (appointmentId && appointmentId !== '') {
            // Attendance-Modus: Member-Filter deaktivieren
            await enterAppointmentAttendance(appointmentId);
        } else {
            // Records-Modus: Member-Filter aktivieren
            setRecordMode(RecordMode.ALL_RECORDS);
            currentAppointmentId = null;
            currentMemberId = null;
            memberFilter.disabled = false;
            aptTypeFilter.disabled = false
            await applyRecordFilters(false); // Normale Filterung
        }
    });

    // Event-Listener für Member-Filter
    document.getElementById('filterMember')?.addEventListener('change', async function() {
        clearAttendanceReturn();
        const memberId = this.value;
        const appointmentFilter = document.getElementById('filterAppointment');
        const aptTypeFilter = document.getElementById('filterAptType');
        
        if (memberId && memberId !== '') {
            // Attendance-by-Member-Modus
            setRecordMode(RecordMode.ATTENDANCE_BY_MEMBER);
            currentMemberId = memberId;
            currentAppointmentId = null;
            aptTypeFilter.disabled = true;
            appointmentFilter.disabled = true;
            appointmentFilter.value = '';
            await loadMemberAttendanceList(memberId, currentAppointmentType);
        } else {
            // Zurück zu ALL_RECORDS falls kein Appointment gewählt
            setRecordMode(RecordMode.ALL_RECORDS);
            currentMemberId = null;
            currentAppointmentId = null;
            appointmentFilter.disabled = false;
            aptTypeFilter.disabled = false;
            await applyRecordFilters(false);
        }
    });
    
    /*
    // Reset-Button
    document.getElementById('resetRecordFilter')?.addEventListener('click', async function() {

        debug.log("Reset Filters");
        const aptTypeFilter = document.getElementById('filterAptType');
        const appointmentFilter = document.getElementById('filterAppointment');
        const memberFilter = document.getElementById('filterMember');
        appointmentFilter.disabled = false;
        memberFilter.disabled = false;  
        aptTypeFilter.disabled = false
        aptTypeFilter.value ='';      
        appointmentFilter.value = '';
        memberFilter.value = '';
        //isAttendanceMode = false;
        currentMode = RecordMode.ALL_RECORDS;
        currentAppointmentId = null;
        currentMemberId = null;
        currentAppointmentType = null;

        loadAppointmentFilter(false);
        loadMemberFilter(false);
        
        await applyRecordFilters();
    });*/
}

export async function resetRecordFilter()
{
        debug.log("Reset Filters");
        clearAttendanceReturn();
        const aptTypeFilter = document.getElementById('filterAptType');
        const appointmentFilter = document.getElementById('filterAppointment');
        const memberFilter = document.getElementById('filterMember');
        appointmentFilter.disabled = false;
        memberFilter.disabled = false;  
        aptTypeFilter.disabled = false
        aptTypeFilter.value ='';      
        appointmentFilter.value = '';
        memberFilter.value = '';
        //isAttendanceMode = false;
        setRecordMode(RecordMode.ALL_RECORDS);
        recordStatusChip = 'all';
        currentAppointmentId = null;
        currentMemberId = null;
        currentAppointmentType = null;

        loadAppointmentFilter(false);
        loadMemberFilter(false);

        await applyRecordFilters();
}

export async function showRecordsSection(forceReload = false) {

    debug.log("Show Record Section ()");

    // Ein normaler Aufruf des Bereichs (Navigation, Jahreswechsel) verwirft
    // den Rueckweg; waehrend eines Sprungs bleibt er stehen.
    if (jumpActive === 0) {
        clearAttendanceReturn();
    }

    // Filter-Optionen laden
    await loadRecordFilters();

    if((currentMode === RecordMode.ATTENDANCE_BY_APPOINTMENT) && currentAppointmentId)
    {
        await loadAttendanceList(currentAppointmentId);
    }
    else if((currentMode === RecordMode.ATTENDANCE_BY_MEMBER) && currentMemberId)
    {
        await loadMemberAttendanceList(currentMemberId, currentAppointmentType);
    }
    else
    {
        await applyRecordFilters();
    }
}

function getSourceBadge(record) {
    const sources = {
        'none': { icon: '', label: '-', color: '#c3c5c7' },
        'admin': { icon: '👤', label: 'Admin', color: '#72afd8' },
        'user_totp': { icon: '📱', label: 'App', color: '#65c48c' },
        'device_auth': { icon: '🔐', label: 'Gerät', color: '#d89c57' },        
        'auto_checkin': { icon: '🤖', label: 'Auto', color: '#95a5a6' },
        'import':{icon: '📤', label: 'Import', color: 'rgb(231, 209, 109)'},
        // Entsteht, wenn eine Arbeitszeitsitzung mit Terminbezug startet
        'timer': { icon: '⏱️', label: 'Timer', color: '#a98bd0' },
        // Mitgliedsnummer + PIN an einer virtuellen Station (Kiosk). Die PIN ist
        // weitergebbar — darum eigene Kennzeichnung statt 'device_auth'.
        'station_pin': { icon: '🖥️', label: 'Station (PIN)', color: '#5b8def' },
        // Genehmigter Zeitkorrektur-Antrag. Admin-Farbe, weil die Freigabe vom
        // Admin kommt — eigenes Label, weil die Uhrzeit es nicht tut: Sie
        // stammt aus der Angabe des Mitglieds. Ohne diese Unterscheidung sähe
        // eine beantragte Zeit aus wie eine vom Verwalter beobachtete.
        'exception_request': { icon: '📝', label: 'Antrag', color: '#72afd8' }
    };
    
    const source = sources[record.checkin_source] || sources['none'];
    
    let badge = `<span class="source-badge" style="background: ${source.color}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                    ${source.icon} ${source.label}
                 </span>`;
    
    // Zusatzinfo
    // location_name/source_device kommen bei auto_checkin/totp_checkin direkt aus dem
    // Client-Request (private/handlers/auto_checkin.php, totp_checkin.php) -- jedes
    // angemeldete Konto (auch Rolle "user") kann sie beim eigenen Check-in setzen, hier
    // sieht sie aber Admin/Manager in der Anwesenheitsliste. Ohne CSP (OI-17) daher
    // zwingend escapeHtml().
    const details = [];
    if (record.location_name) {
        details.push(`📍 ${escapeHtml(record.location_name)}`);
    }
    if (record.source_device) {
        details.push(`🔧 ${escapeHtml(record.source_device)}`);
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

    const memberSelect = document.getElementById('record_member');
    const appointmentSelect = document.getElementById('record_appointment'); 

    form.reset();

    memberSelect.disabled = false;
    appointmentSelect.disabled = false;      
    
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
        
        document.getElementById('record_member').disabled = false;
        document.getElementById('record_appointment').disabled = false;

        // Keine ID anzeigen
        updateModalId('recordModal', null);
        
        // Status zurueck auf den Vorgabewert, danach die Sichtbarkeit des
        // Ankunftsfelds nachziehen -- sonst bliebe es versteckt, wenn zuvor
        // ein entschuldigter Eintrag bearbeitet wurde.
        document.getElementById('record_status').value = 'present';

        // Keine Vorbelegung der Ankunftszeit.
        //
        // Frueher stand hier die aktuelle Uhrzeit. Ein Nachtrag im Dashboard
        // geschieht aber meist spaeter: Fuer die Erfassung im Moment gibt es
        // die Anwesenheitsliste der PWA, fuer vergessenes Stempeln den Antrag
        // des Mitglieds. Eine vorbelegte Zeit waere hier die Uhrzeit des
        // Abhakens, nicht die der Ankunft -- und niemand sieht ihr das an.
        //
        // Wer vom Terminbeginn ausgehen will, benutzt den Knopf daneben.
        document.getElementById('record_arrival_time').value = '';
        toggleArrivalTimeField();

        // Verstecke Terminart-Anzeige
        document.getElementById('recordAppointmentTypeGroup').style.display = 'none';            
    }    

    memberSelect.removeEventListener('change', onRecordMemberChange);
    appointmentSelect.removeEventListener('change', onRecordAppointmentChange);
    memberSelect.addEventListener('change', onRecordMemberChange);
    appointmentSelect.addEventListener('change', onRecordAppointmentChange);
    
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

        // Sichtbarkeit nachziehen: Bei einem entschuldigten Eintrag gehoert
        // das Ankunftsfeld weg, auch wenn der Dialog gerade erst befuellt wurde.
        toggleArrivalTimeField();

        document.getElementById('record_member').disabled = true;
        document.getElementById('record_appointment').disabled = true;

        // Terminart anzeigen
        //updateAppointmentTypeDisplay();
    }
}


function buildRecordMemberOptions(members) {
    const select = document.getElementById('record_member');
    const currentVal = select.value;
    select.innerHTML = '<option value="">Bitte wählen...</option>';
    members.forEach(member => {
        const opt = document.createElement('option');
        opt.value = member.member_id;
        opt.textContent = `${member.surname}, ${member.name}`;
        select.appendChild(opt);
    });
    if (members.some(m => m.member_id == currentVal)) select.value = currentVal;
}

function buildRecordAppointmentOptions(appointments) {
    const select = document.getElementById('record_appointment');
    const currentVal = select.value;
    select.innerHTML = '<option value="">Bitte wählen...</option>';
    appointments.forEach(appointment => {
        const date = new Date(appointment.date + 'T00:00:00');
        const formattedDate = date.toLocaleDateString('de-DE');
        const startTime = appointment.start_time ? appointment.start_time.substring(0, 5) : '';
        let displayText = `${appointment.title} (${formattedDate} - ${startTime})`;
        if (appointment.type_name) displayText += ` [${appointment.type_name}]`;
        const option = document.createElement('option');
        option.value = appointment.appointment_id;
        option.textContent = displayText;
        if (appointment.color) {
            option.style.color = appointment.color;
            option.style.fontWeight = '500';
        }
        option.dataset.typeId = appointment.type_id || '';
        option.dataset.typeName = appointment.type_name || '';
        select.appendChild(option);
    });
    if (appointments.some(a => a.appointment_id == currentVal)) select.value = currentVal;
}

function onRecordMemberChange() {
    const memberSelect = document.getElementById('record_member');
    const selectedMember = _recordAllMembers.find(m => m.member_id == memberSelect.value);
    if (!selectedMember) {
        buildRecordAppointmentOptions(_recordAllAppointments);
        return;
    }
    const compatible = getCompatibleAppointments(selectedMember, _recordAllAppointments, _recordTypes);
    buildRecordAppointmentOptions(compatible);
}

function onRecordAppointmentChange() {
    // Die Ankunftszeit wird hier bewusst NICHT mehr gesetzt. Bis 1.5.0 trug
    // der Terminwechsel die Startzeit des Termins ein — wer danach speicherte,
    // erzeugte einen Datensatz, der konstruiert puenktlich war, ohne es zu
    // merken. Den Terminbeginn gibt es jetzt auf Knopfdruck.
    const appointmentSelect = document.getElementById('record_appointment');
    const selectedAppointment = _recordAllAppointments.find(a => a.appointment_id == appointmentSelect.value);
    if (!selectedAppointment) {
        buildRecordMemberOptions(_recordAllMembers);
        return;
    }
    const compatible = getCompatibleMembers(selectedAppointment, _recordAllMembers, _recordTypes);
    buildRecordMemberOptions(compatible);
}

export async function loadRecordDropdowns() {
    _recordTypes = await loadTypes();
    const members = await loadMembers();
    _recordAllMembers = members.filter(m => m.is_active_in_period);
    _recordAllAppointments = await loadAppointments(false, currentYear);

    buildRecordMemberOptions(_recordAllMembers);
    buildRecordAppointmentOptions(_recordAllAppointments);
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

        // Die Zahlen im Kalender haengen am Terminabruf des Jahres (Schritt 2b).
        // Ohne Verwerfen zeigte er bis zu zehn Minuten die alten Werte.
        await invalidateCache('appointments', currentYear);

        if(currentMode === RecordMode.ATTENDANCE_BY_APPOINTMENT)
        {
            loadAttendanceList(currentAppointmentId);                
        }
        else if(currentMode === RecordMode.ATTENDANCE_BY_MEMBER)
        {
            loadMemberAttendanceList(currentMemberId, currentAppointmentType);
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
    // Alle Aufrufstellen uebergeben nur noch die ID (Spec-Pruefung 16.09.2026):
    // ein Mitglieds- oder Terminname mit Apostroph oder HTML sprengte dort sonst
    // den onclick-Aufruf bzw. liesse sich als Code einschleusen (kein CSP im
    // Projekt) -- Muster aus deleteGroup()/deleteType() in management.js
    // (Commit ad200ba). Name und Termin kommen stattdessen aus den bereits
    // geladenen Daten der jeweils aktuell angezeigten Liste.
    if (memberName === undefined) {
        if (currentMode === RecordMode.ATTENDANCE_BY_MEMBER) {
            const appointment = _lastMemberAttendanceData?.appointments?.find(a => a.record_id == recordId);
            const member = _lastMemberAttendanceData?.memberInfo;
            memberName = member ? `${member.name} ${member.surname}` : 'diesem Mitglied';
            appointmentTitle = appointment?.title ?? 'diesem Termin';
        } else if (currentMode === RecordMode.ALL_RECORDS) {
            const record = allFilteredRecords?.find(r => r.record_id == recordId);
            memberName = record ? `${record.name} ${record.surname}` : 'diesem Mitglied';
            appointmentTitle = record?.title ?? 'diesem Termin';
        } else {
            const member = _lastAttendanceData?.find(m => m.record_id == recordId);
            memberName = member ? `${member.name} ${member.surname}` : 'diesem Mitglied';
            appointmentTitle = appointmentTitle ?? 'diesem Termin';
        }
    }

     const confirmed = await showConfirm(
        `Anwesenheit von ${memberName} bei ${appointmentTitle} wirklich löschen?`,
        'Anwesenheit löschen'
    );

    if (confirmed) {
        const result = await apiCall('records', 'DELETE', null, { id: recordId });
        if (result.success) {
            // Die Zahlen im Kalender haengen am Terminabruf des Jahres (Schritt 2b).
            // Ohne Verwerfen zeigte er bis zu zehn Minuten die alten Werte.
            await invalidateCache('appointments', currentYear);

            if(currentMode === RecordMode.ATTENDANCE_BY_APPOINTMENT)
            {
                loadAttendanceList(currentAppointmentId);                
            }
            else if(currentMode === RecordMode.ATTENDANCE_BY_MEMBER)
            {
                loadMemberAttendanceList(currentMemberId, currentAppointmentType);
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

    typeGroup.style.display = 'block';
    typeBadge.innerHTML = await createAppointmentTypeBadge(selectedOption.dataset.typeId);

}

// ============================================
// ATTENDANCE LIST
// ============================================

// Zuletzt geladene Anwesenheitsliste -- der Gruppierungs-Umschalter rendert
// aus diesen Daten neu, ohne einen weiteren API-Aufruf (Spec 3.5).
let _lastAttendanceData = null;

// Zuletzt geladene Anwesenheitshistorie eines einzelnen Mitglieds (Modus
// ATTENDANCE_BY_MEMBER) -- deleteRecord() holt Mitglieds- und Terminnamen
// fuer den Bestaetigungsdialog von hier statt aus dem onclick-Attribut.
let _lastMemberAttendanceData = null;

// Spalten der Anwesenheitsliste im Modus 'appointment' (siehe updateTableHeader()).
const ATTENDANCE_LIST_COLSPAN = 5;

// Serverregel zur Selbstgenehmigung (OI-87). Vorgabe true: Ohne Auskunft
// lieber keinen Knopf zeigen, den der Server ohnehin abweist.
let _attendanceSelfBlocked = true;

// Ein im Antragsdialog beschiedener Antrag verschwindet aus der Liste, sobald
// sie neu geladen ist. Ein Ereignis statt eines Imports: exceptions.js müsste
// sonst records.js einbinden, das umgekehrt schon geschieht.
document.addEventListener('exception-saved', () => {
    if (currentMode === RecordMode.ATTENDANCE_BY_APPOINTMENT && currentAppointmentId) {
        loadAttendanceList(currentAppointmentId);
    }
});

async function loadAttendanceList(appointmentId) {
    try {
        const attendance = await apiCall('attendance_list', 'GET', null, {appointment_id:appointmentId});

        debug.log("Attendance Data:", attendance);
        if (attendance.success) {
            _attendanceSelfBlocked = attendance.self_approval_blocked !== false;
            renderAttendanceList(attendance.members);
        }
    } catch (error) {
        debug.error('Fehler beim Laden der Anwesenheitsliste:', error);
        showToast('Anwesenheitsliste konnte nicht geladen werden', 'error');
    }
}

function renderAttendanceList(attendanceData) {
    // Volle Liste merken: Gruppierung und Chip-Wechsel rendern daraus neu.
    _lastAttendanceData = attendanceData;

    const tbody = document.getElementById('recordsTableBody');
    const shown = applyRecordChips(attendanceData, () => renderAttendanceList(_lastAttendanceData));

    const container = document.getElementById('recordsPagination');
    if (!container) return;

    container.innerHTML = '';

    tbody.innerHTML = '';

    updateTableHeader('appointment');

    renderAttendanceRequestChip(attendanceData);
    renderAttendanceGroupingBar(shown);

    if (shown.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${ATTENDANCE_LIST_COLSPAN}" class="loading">Keine Mitglieder für diese Auswahl</td></tr>`;
        return;
    }

    // Umschalter-Stufen und gemerkte Wahl (Spec 6.1/6.2). Fuer den Abschnitt
    // ohne Gruppe passt "Ohne Gruppe" nur bei 'group' -- bei 'subgroup' zeigt
    // er das eingestellte Wort (z.B. "Ohne Register").
    const stages = groupingAvailableStages(shown);
    const stage  = groupingStored(GROUPING_KEY_ATTENDANCE, stages, 'alpha');
    const emptyLabel = stage === 'subgroup' ? `Ohne ${subgroupLabel()}` : 'Ohne Gruppe';
    const sections = groupingSections(shown, stage, emptyLabel);

    const fragment = document.createDocumentFragment();

    sections.forEach(section => {
        if (section.label !== null) {
            const kopf = document.createElement('tr');
            kopf.className = 'response-group-row';
            kopf.innerHTML = `<td colspan="${ATTENDANCE_LIST_COLSPAN}">${escapeHtml(section.label)} · ${section.members.length}</td>`;
            fragment.appendChild(kopf);
        }
        section.members.forEach(member => fragment.appendChild(buildAttendanceRow(member)));
    });

    tbody.appendChild(fragment);
}

/** Umschalter Alphabetisch/Gruppe/Untergruppe + Hinweiszeile bei Mehrfachnennung (Spec 6.1, 6.4). */
function renderAttendanceGroupingBar(attendanceData) {
    const bar = document.getElementById('recordsGroupingBar');
    if (!bar) return;

    const stages = groupingAvailableStages(attendanceData);
    if (stages.length <= 1) {
        bar.innerHTML = '';
        return;
    }

    const stage = groupingStored(GROUPING_KEY_ATTENDANCE, stages, 'alpha');
    const duplicates = groupingDuplicateCount(attendanceData, stage);

    const stageLabels = { alpha: 'Alphabetisch', group: 'Gruppe', subgroup: escapeHtml(subgroupLabel()) };
    const buttons = stages.map(s => `
        <button type="button" class="list-grouping__btn${stage === s ? ' is-active' : ''}"
                aria-pressed="${stage === s ? 'true' : 'false'}"
                onclick="setAttendanceGrouping('${s}')">${stageLabels[s]}</button>`).join('');

    let hint = '';
    if (duplicates > 0) {
        const text = duplicates === 1 ? '1 Mitglied steht' : `${duplicates} Mitglieder stehen`;
        hint = `<p class="list-grouping-hint">${text} in mehreren Abschnitten.</p>`;
    }

    bar.innerHTML = `<div class="list-grouping">${buttons}</div>${hint}`;
}

/** Baut eine Tabellenzeile der Anwesenheitsliste fuer ein Mitglied. */
/**
 * „⏳ n offene Anträge“ über der Liste (OI-87).
 *
 * Reine Anzeige, kein Filter: Ein offener Antrag liegt quer zu den
 * Status-Chips — das Mitglied ist dabei auch anwesend oder fehlend, und die
 * Status-Chips einer Reihe müssen sich gegenseitig ausschließen. Gezählt wird
 * über die volle Liste, nicht über die vom Chip gefilterte, und je Antrag
 * einmal: Ein Mitglied mit mehreren Untergruppen steht mehrfach in der Liste.
 */
function renderAttendanceRequestChip(members) {
    const container = document.getElementById('recordRequestChip');
    if (!container) return;

    const ids = new Set();
    (members || []).forEach(m => (m.pending_exceptions || []).forEach(e => ids.add(e.exception_id)));

    if (ids.size === 0) {
        container.replaceChildren();
        return;
    }

    renderFilterChips(container,
        [{ key: 'pending', label: 'offene Anträge', variant: 'pending',
           title: 'Anträge zu diesem Termin, über die noch nicht entschieden ist' }],
        { pending: ids.size }, null, null,
        { static: true, label: 'Offene Anträge' });
}

/** Offene Anträge eines Mitglieds als Zeilenzusatz und Knöpfe (OI-87). */
function attendanceRequestParts(member) {
    const antraege = member.pending_exceptions || [];
    if (antraege.length === 0) return { hinweis: '', aktionen: '' };

    // Der eigene Antrag: Der Server weist die Genehmigung ab, solange ein
    // zweiter Verwalter da ist. Ohne zweiten bleibt sie erlaubt — dann zeigt
    // die Zeile die Knöpfe wie bei jedem anderen Antrag.
    const eigenerAntrag = _attendanceSelfBlocked
        && currentUser?.member_id
        && String(currentUser.member_id) === String(member.member_id);

    const hinweis = antraege.map(a => {
        const zeit = a.requested_arrival_time ? String(a.requested_arrival_time).slice(11, 16) : '';
        const art = a.exception_type === 'absence'
            ? 'Entschuldigung'
            : (zeit ? `Zeitantrag ${zeit} Uhr` : 'Zeitantrag');

        return `<div class="attendance-request-hint" title="${escapeHtml(a.reason || '')}">⏳ ${escapeHtml(art)}</div>`;
    }).join('');

    if (eigenerAntrag) {
        return {
            hinweis: hinweis + '<div class="attendance-request-hint">Eigener Antrag – ein anderer Verwalter entscheidet</div>',
            aktionen: ''
        };
    }

    // Ein Zeichen je Knopf: Die Aktionsknöpfe sind 32 × 32 px, zwei Zeichen
    // brachen darin um (Sanduhr über dem Haken). Dass es um den Antrag geht und
    // nicht um „anwesend setzen“, trägt die eigene Form (btn-request, gestrichelter
    // Rand in der Antragsfarbe) zusammen mit dem Hinweis in derselben Zeile.
    const aktionen = antraege.map(a => `
            <button class="action-btn btn-icon btn-request btn-request--approve"
                    onclick="quickApproveException(${Number(a.exception_id)})"
                    title="Antrag genehmigen">
                ✓
            </button>
            <button class="action-btn btn-icon btn-request btn-request--reject"
                    onclick="quickRejectException(${Number(a.exception_id)})"
                    title="Antrag ablehnen">
                ✗
            </button>
    `).join('');

    return { hinweis, aktionen };
}

function buildAttendanceRow(member) {
    const tr = document.createElement('tr');

    // Member-Info mit Mitgliedsnr. wenn vorhanden
    let memberInfo = `<div style="line-height: 1.4;">${escapeHtml(member.surname)}, ${escapeHtml(member.name)}`;
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
    const { hinweis, aktionen } = attendanceRequestParts(member);

    let { statusHtml, rowClass } = attendanceStatusCell(member);

    // Offene Anträge stehen unter dem Status: Sie sagen, was noch aussteht,
    // während der Status sagt, was gilt.
    statusHtml += hinweis;

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
                    onclick="deleteRecord(${member.record_id})"
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
        <td>${aktionen}${actionsHtml}</td>
    `;

    return tr;
}

/** Umschalter-Klick (Spec 6.2): merkt die Wahl und rendert aus den vorliegenden
 * Daten neu -- kein erneuter API-Aufruf. */
window.setAttendanceGrouping = function(stage) {
    groupingStore(GROUPING_KEY_ATTENDANCE, stage);
    if (_lastAttendanceData) {
        renderAttendanceList(_lastAttendanceData);
    }
};

async function loadMemberAttendanceList(memberId, appointmentTypeId = null) {
    try {        
        const attendance = await apiCall('attendance_list', 'GET', null, {
            member_id: memberId,
            year: currentYear,
            type_id: appointmentTypeId
        });
        
        debug.log("Member Attendance Data:", attendance);
        if (attendance.success) {
            renderMemberAttendanceList(attendance.appointments, attendance.member);
        }
    } catch (error) {
        debug.error('Fehler beim Laden der Mitglieder-Anwesenheit:', error);
    }
}

function renderMemberAttendanceList(appointmentsData, memberInfo) {
    _lastMemberAttendanceData = { appointments: appointmentsData, memberInfo };

    const tbody = document.getElementById('recordsTableBody');

    // Volle Liste bleibt in _lastMemberAttendanceData; gezeichnet wird die nach Chip gefilterte.
    const shown = applyRecordChips(appointmentsData,
        () => renderMemberAttendanceList(_lastMemberAttendanceData.appointments, _lastMemberAttendanceData.memberInfo));

    const container = document.getElementById('recordsPagination');
    if (!container) return;

    container.innerHTML = '';    
    tbody.innerHTML = '';    
    
    // Neuer Header-Modus für Member-Ansicht
    updateTableHeader('member'); // 'member' = Member-Attendance-Modus

    if (shown.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="loading">Keine Termine für diese Auswahl</td></tr>';
        return;
    }

    shown.forEach(appointment => {
        const tr = document.createElement('tr');        
        
         // Termin-Info mit Terminart
        let appointmentInfo = '-';
        if (appointment.appointment_id && appointment.title) {
            appointmentInfo = `<div style="line-height: 1.4;">
                <strong>${escapeHtml(appointment.title)}</strong>`;
            
            if (appointment.date && appointment.start_time) {
                const aptDate = new Date(appointment.date + 'T00:00:00');
                const formattedAptDate = aptDate.toLocaleDateString('de-DE');
                appointmentInfo += `<br><small style="color: #7f8c8d;">${formattedAptDate}, ${appointment.start_time.substring(0, 5)}</small>`;
            }
            
            appointmentInfo += '</div>';
        }

        /*
        // Wenn Mitglied zu diesem Termin inaktiv war → visuell kennzeichnen
        if (appointment.member_was_active === 0) {
            rowClass += ' inactive-period';
            // Tooltip oder Badge hinzufügen
            statusHtml = '<span style="color: #6c757d; font-weight: 500;">○ Inaktiv</span>';
        }*/


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
        const { statusHtml, rowClass } = attendanceStatusCell(appointment);


        // Terminart Badge
        let appointmentTypeBadge = createAppointmentTypeBadge(appointment.type_id);

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
                        onclick="deleteRecord(${appointment.record_id})"
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

function createAppointmentTypeBadge(appointment_type_id = null)
{
    const types = dataCache.types.data;
        
    // Type-ID vorhanden UND types ist Array
    if (appointment_type_id && Array.isArray(types)) {
        const type = types.find(t => t.type_id == appointment_type_id);
        
        if (type) {
            // type.color/type.type_name kommen aus der Terminart (DB) -- ohne CSP (OI-17)
            // muss hier selbst maskiert werden: Farbe per Whitelist, Text per escapeHtml().
            const safeTypeColor = /^#[0-9a-f]{3,8}$/i.test(type.color || '') ? type.color : '#667eea';
            return `<span class="type-badge" style="background: ${safeTypeColor}; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                        ${escapeHtml(type.type_name)}
                    </span>`;
        }
    }
    
    // Fallback: Termin ohne Type ODER nicht gefunden
    return `<span class="type-badge" style="background: #95a5a6; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                Allgemein
            </span>`;
}

async function quickCreateRecordForMember(memberId, status = 'present') {
    const appointmentId = currentAppointmentId;
    
    if (!appointmentId) {
        showToast('Kein Termin ausgewählt', 'error');
        return;
    }
    
    try {
        const result = await apiCall('records', 'POST', {
            member_id: parseInt(memberId),
            appointment_id: parseInt(appointmentId),
            status: status
            // status wird automatisch 'present'
        });

        // apiCall wirft nicht: bei einem Serverfehler kommt null oder success=false
        // zurueck. Ohne diese Pruefung meldete die Oberflaeche Erfolg, obwohl nichts
        // gespeichert wurde.
        if (!result || !result.success) {
            showToast('Anwesenheit konnte nicht erfasst werden', 'error');
            return;
        }

        const message = status === 'excused' ? 'Entschuldigung erfasst' : 'Anwesenheit erfasst';
        showToast(message, 'success');

        // Die Zahlen im Kalender haengen am Terminabruf des Jahres (Schritt 2b).
        // Ohne Verwerfen zeigte er bis zu zehn Minuten die alten Werte.
        await invalidateCache('appointments', currentYear);

        // Anwesenheitsliste neu laden
        await loadAttendanceList(appointmentId);
        
    } catch (error) {
        debug.error('Fehler beim Erstellen:', error);
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
        const result = await apiCall('records', 'POST', {
            member_id: parseInt(memberId),
            appointment_id: parseInt(appointmentId),
            status: status
        });

        // apiCall wirft nicht: bei einem Serverfehler kommt null oder success=false
        // zurueck. Ohne diese Pruefung meldete die Oberflaeche Erfolg, obwohl nichts
        // gespeichert wurde.
        if (!result || !result.success) {
            showToast('Anwesenheit konnte nicht erfasst werden', 'error');
            return;
        }

        const message = status === 'excused' ? 'Entschuldigung erfasst' : 'Anwesenheit erfasst';
        showToast(message, 'success');

        // Die Zahlen im Kalender haengen am Terminabruf des Jahres (Schritt 2b).
        // Ohne Verwerfen zeigte er bis zu zehn Minuten die alten Werte.
        await invalidateCache('appointments', currentYear);

        // Member-Anwesenheitsliste neu laden
        await loadMemberAttendanceList(memberId, currentAppointmentType);
        
    } catch (error) {
        debug.error('Fehler beim Erstellen:', error);
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
        if(isAdminOrManager)
        {
            thead.innerHTML = '<th>Termin</th><th>Typ</th><th>Mitglied</th><th>Ankunft</th><th>Status</th><th>Quelle</th><th>Aktionen</th>';
        }
        else
        {   
            // Keine Aktionen für User            
            thead.innerHTML = '<th>Termin</th><th>Typ</th><th>Mitglied</th><th>Ankunft</th><th>Status</th><th>Quelle</th>';
        }
    }
}


// ============================================
// HELPERS
// ============================================

/**
 * Setzt den Terminbeginn als Ankunftszeit — nur auf Knopfdruck.
 *
 * Bis 1.5.0 geschah das automatisch beim Terminwechsel. Der Unterschied ist
 * nicht kosmetisch: Wer den Knopf drückt, trifft eine Aussage und passt die
 * Minuten an; wer ihn nicht drückt, lässt die Ankunft leer. Vorher entstand
 * dieselbe Uhrzeit, ohne dass jemand sie gewollt hätte.
 */
function setArrivalTimeFromAppointment() {
    const appointmentSelect = document.getElementById('record_appointment');
    const arrivalTimeInput = document.getElementById('record_arrival_time');

    const selectedAppointmentId = appointmentSelect.value;

    if (!selectedAppointmentId) {
        showToast('Bitte zuerst einen Termin wählen', 'warning');
        return;
    }

    const appointment = dataCache.appointments[currentYear].data.find(apt => apt.appointment_id == selectedAppointmentId);

    if (appointment && appointment.date && appointment.start_time) {
        arrivalTimeInput.value = `${appointment.date}T${appointment.start_time.substring(0, 5)}`;
        arrivalTimeInput.focus();
    }
}

/**
 * Blendet die Ankunftszeit aus, wenn der Status "entschuldigt" lautet.
 *
 * Wer nicht da war, ist nicht angekommen. Das Feld wird dabei geleert, damit
 * eine zuvor eingetippte Zeit nicht unsichtbar mitgespeichert wird.
 */
function toggleArrivalTimeField() {
    const status = document.getElementById('record_status').value;
    const group  = document.getElementById('recordArrivalTimeGroup');
    const input  = document.getElementById('record_arrival_time');

    if (status === 'excused') {
        group.style.display = 'none';
        input.value = '';
    } else {
        group.style.display = '';
    }
}

// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

// Globale Funktionen für HTML onclick
window.openRecordModal = openRecordModal;
window.saveRecord = saveRecord;
window.toggleArrivalTimeField = toggleArrivalTimeField;
window.setArrivalTimeFromAppointment = setArrivalTimeFromAppointment;
window.closeRecordModal = () => document.getElementById('recordModal').classList.remove('active');
window.deleteRecord = deleteRecord;
window.resetRecordFilter = resetRecordFilter;
window.backToAppointments = backToAppointments;
window.quickCreateRecordForMember = quickCreateRecordForMember;
window.quickCreateRecordForAppointment = quickCreateRecordForAppointment;