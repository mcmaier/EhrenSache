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
import { apiCall, isAdminOrManager } from './api.js';
import { showToast, showConfirm, dataCache, isCacheValid, invalidateCache,currentYear, setCurrentYear} from './ui.js';
import {datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime, updateModalId } from './utils.js';
import { loadTypes } from './management.js';
import {debug} from '../app.js'
import { globalPaginationValue } from './settings.js';

// ============================================
// APPOINTMENTS
// Reference:
// import {} from './appointments.js'
// ============================================

let currentCalendarDate = new Date();

let currentAppointmentsPage = 1;
let appointmentsPerPage = 25;
let allFilteredAppointments = [];

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadAppointments(forceReload = false) {
    const year = currentYear;

    // Cache verwenden wenn vorhanden und nicht forceReload
    if (!forceReload && isCacheValid('appointments', year)) {
        debug.log(`Loading APPOINTMENTS from CACHE for ${year}`);
        return dataCache.appointments[year].data;
    }
    
    debug.log(`Loading APPOINTMENTS from API for ${year}`);
    const appointments = await apiCall('appointments', 'GET', null, {year: year});

    // Cache für dieses Jahr speichern
    if (!dataCache.appointments[year]) {
        dataCache.appointments[year] = {};
    }

    dataCache.appointments[year].data = appointments;
    dataCache.appointments[year].timestamp = Date.now();

    return appointments;    
}

async function loadAppointmentData(appointmentId) {
    const apt = await apiCall('appointments', 'GET', null, { id: appointmentId });
    
    if (apt) {
        document.getElementById('appointment_id').value = apt.appointment_id;
        document.getElementById('appointment_title').value = apt.title;
        document.getElementById('appointment_type').value = apt.type_id || '';
        document.getElementById('appointment_description').value = apt.description || '';
        document.getElementById('appointment_date').value = apt.date;
        document.getElementById('appointment_time').value = apt.start_time;
    }
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

export async function showAppointmentSection(forceReload = false, page = 1)
{
    debug.log("== Show Appointment Section == ")
    const appointmentData = await loadAppointments(forceReload);

    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'termine')
    {
        renderAppointments(appointmentData,page);
    }
}

async function renderAppointments(appointments, page = 1) {
    
    const tbody = document.getElementById('appointmentsTableBody');
    if (!appointments){
        tbody.innerHTML = '<tr><td colspan="4" class="loading">Keine Einträge gefunden</td></tr>';
        updateAppointmentStats(0);
        return;
    }

    appointmentsPerPage = globalPaginationValue;

    // Alle Appointments speichern für Pagination
    allFilteredAppointments = appointments;
    currentAppointmentsPage = page;

    updateAppointmentStats(appointments);

    // Pagination berechnen
    const totalAppointments = appointments.length;
    const totalPages = Math.ceil(totalAppointments / appointmentsPerPage);
    const startIndex = (page - 1) * appointmentsPerPage;
    const endIndex = startIndex + appointmentsPerPage;
    const pageAppointments = appointments.slice(startIndex, endIndex);


    debug.log(`Rendering page ${page}/${totalPages} (${pageAppointments.length} of ${totalAppointments} appointments)`);
    
    

    // DocumentFragment für Performance
    const fragment = document.createDocumentFragment();

    pageAppointments.forEach(apt => {
        
        const tr = document.createElement('tr');
    
        // Termin-Info mit Terminart
        let appointmentInfo = '-';
        if (apt.appointment_id && apt.title) {
            appointmentInfo = `<div style="line-height: 1.4;">
                <strong>${apt.title}</strong>`;
            
            if (apt.date && apt.start_time) {
                const aptDate = new Date(apt.date + 'T00:00:00');
                const formattedAptDate = aptDate.toLocaleDateString('de-DE');
                appointmentInfo += `<br><style="color: #7f8c8d;">${formattedAptDate}, ${apt.start_time.substring(0, 5)}`;
            }
            
            appointmentInfo += '</div>';
        }

        const typeBadge = apt.type_name 
            ? `<span class="type-badge" style="background: ${apt.color || '#667eea'}; color: white;">${apt.type_name}</span>`
            : '<span class="type-badge">-</span>';

        const actionsHtml = isAdminOrManager ? `
            <td class="actions-cell">
                    <button class="action-btn btn-icon btn-edit" 
                            onclick="openAppointmentModal(${apt.appointment_id})"
                            title="Bearbeiten">
                        ✎
                    </button>
                    <button class="action-btn btn-icon btn-delete" 
                            onclick="deleteAppointment(${apt.appointment_id}, '${apt.title}')"
                            title="Löschen">
                        🗑
                    </button>
                </td>
        ` : '';
        
        tr.innerHTML = `
                <td>${appointmentInfo}</td>
                <td>${typeBadge}</td>
                <td>${apt.description || '-'}</td>
                ${actionsHtml}
                `;
        fragment.appendChild(tr);
    });

    tbody.innerHTML = '';
    tbody.appendChild(fragment);
    
    // Pagination Controls
    renderAppointmentsPagination(page, totalPages, totalAppointments); 
    
    renderCalendar();    
}


function renderAppointmentsPagination(currentPage, totalPages, totalAppointments) {
    const container = document.getElementById('appointmentsPagination');
    if (!container) return;
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const startAppointment = (currentPage - 1) * appointmentsPerPage + 1;
    const endAppointment = Math.min(currentPage * appointmentsPerPage, totalAppointments);
    
    let html = `
        <div class="pagination-container">
            <div class="pagination-info">
                Zeige ${startAppointment} - ${endAppointment} von ${totalAppointments} Einträgen
            </div>
            <div class="pagination-buttons">
    `;

    if (totalPages <= 5) {
        // Wenige Seiten (≤5): Alle Seitenzahlen ohne Pfeile
        for (let i = 1; i <= totalPages; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToAppointmentsPage(${i})">${i}</button>`;
        }
    } 
    else {
        // Erste Seite Button
        if (currentPage > 1) {
            //html += `<button onclick="goToAppointmentsPage(1)" title="Erste Seite">
            //            «
            //        </button>`;
            html += `<button onclick="goToAppointmentsPage(${currentPage - 1})" title="Vorherige Seite">
                        ‹
                    </button>`;
        }
        
        // Seitenzahlen (max 5 anzeigen)
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            html += `<button onclick="goToAppointmentsPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToAppointmentsPage(${i})">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
            html += `<button onclick="goToAppointmentsPage(${totalPages})">${totalPages}</button>`;
        }
        
        // Letzte Seite Button
        if (currentPage < totalPages) {
            html += `<button onclick="goToAppointmentsPage(${currentPage + 1})" title="Nächste Seite">
                        ›
                    </button>`;
            //html += `<button onclick="goToAppointmentsPage(${totalPages})" title="Letzte Seite">
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
window.goToAppointmentsPage = function(page) {

    // Aktuelle Scroll-Position der Tabelle speichern
    const tableContainer = document.querySelector('.data-table')?.parentElement;
    const scrollBefore = tableContainer?.scrollTop || 0;

    renderAppointments(allFilteredAppointments, page);
    
    // Scroll nach oben zur Tabelle
    //document.getElementById('appointmentsTableBody').scrollIntoView({ behavior: 'smooth', block: 'start' });

    // KEIN automatisches Scrollen - Position beibehalten
    // ODER: Sanft zur Tabelle scrollen
    if (scrollBefore === 0) {
        // Nur scrollen wenn User nicht gescrollt hat
        const paginationElement = document.getElementById('appointmentsPagination');
        if (paginationElement) {
            paginationElement.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'nearest' 
            });
        }
    }
};

function updateAppointmentStats(appointments)
{
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()); // Heute 00:00 Uhr
    
    let pastCount = 0;
    let upcomingCount = 0;
    
    appointments.forEach(appointment => {
        // Appointment-Datum parsen
        const appointmentDate = new Date(appointment.date);
        
        if (appointmentDate < today) {
            pastCount++;
        } else {
            upcomingCount++;
        }
    });
    
    // Statistiken aktualisieren
    document.getElementById('statPastAppointments').textContent = pastCount;
    document.getElementById('statUpcomingAppointments').textContent = upcomingCount

    /*
    if(!appointments || appointments.length === 0)
    {
        document.getElementById('statUpcomingAppointments').textContent = '0';
        return;
    }

    // Statistiken
    const today = new Date().toISOString().split('T')[0];
    const upcoming = appointments.filter(a => a.date >= today).length;
    document.getElementById('statUpcomingAppointments').textContent = upcoming;    */    
}



function renderCalendar() {
    const year = currentCalendarDate.getFullYear();
    const month = currentCalendarDate.getMonth();
    
    // Monat/Jahr Header
    const monthNames = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    document.getElementById('calendarMonthYear').textContent = `${monthNames[month]} ${year}`;
    
    // Tage berechnen
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const prevLastDay = new Date(year, month, 0);
    
    const firstDayOfWeek = firstDay.getDay() === 0 ? 6 : firstDay.getDay() - 1; // Mo = 0
    const lastDate = lastDay.getDate();
    const prevLastDate = prevLastDay.getDate();
    
    const container = document.getElementById('calendarDaysContainer');
    if (!container) {
        debug.error('calendarDaysContainer not found');
        return;
    }
    
    container.innerHTML = '';
    
    // Vorheriger Monat (ausgegraut)
    for (let i = firstDayOfWeek; i > 0; i--) {
        const day = createCalendarDay(prevLastDate - i + 1, year, month - 1, true);
        container.appendChild(day);
    }
    
    // Aktueller Monat
    const today = new Date();
    for (let i = 1; i <= lastDate; i++) {
        const isToday = year === today.getFullYear() && 
                       month === today.getMonth() && 
                       i === today.getDate();
        
        const day = createCalendarDay(i, year, month, false, isToday);
        container.appendChild(day);
    }
    
    // Nächster Monat (auffüllen)
    const totalCells = container.children.length;
    const remainingCells = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
    for (let i = 1; i <= remainingCells; i++) {
        const day = createCalendarDay(i, year, month + 1, true);
        container.appendChild(day);
    }
}

function createCalendarDay(dayNum, year, month, isOtherMonth, isToday = false) {
    const day = document.createElement('div');
    day.className = 'calendar-day';
    day.textContent = dayNum;
    
    if (isOtherMonth) {
        day.classList.add('other-month');
    }
    
    if (isToday) {
        day.classList.add('today');
    }
    
    // Prüfe ob Termine an diesem Tag
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(dayNum).padStart(2, '0')}`;
    const dayAppointments = dataCache.appointments[currentYear].data.filter(apt => apt.date === dateStr);
    
    if (dayAppointments.length > 0) {
        day.classList.add('has-event');
        
        // Verwende Farbe der ersten Terminart
        const firstType = dayAppointments[0];
        if (firstType.color) {
            day.style.borderLeftColor = firstType.color;
            day.style.borderLeftWidth = '3px';
            day.style.borderLeftStyle = 'solid';
        }
        
        // Tooltip mit allen Terminen
        day.title = dayAppointments.map(a => {
            const typeName = a.type_name ? `[${a.type_name}] ` : '';
            return `${a.start_time} - ${typeName}${a.title}`;
        }).join('\n');
        
        // Click-Handler für Termin-Details
        day.onclick = (e) => showAppointmentPopup(e, dayAppointments);
    }
    
    return day;
}

function showAppointmentPopup(event, appointments) {
    event.stopPropagation();
    
    // Entferne altes Popup
    const oldPopup = document.querySelector('.calendar-event-popup');
    if (oldPopup) oldPopup.remove();
    
    // Erstelle neues Popup
    const popup = document.createElement('div');
    popup.className = 'calendar-event-popup active';
    
    let html = `<h4>${appointments[0].date}</h4>`;
    appointments.forEach(apt => {
        // Terminart-Badge mit Farbe
        const typeBadge = apt.type_name 
            ? `<span class="calendar-type-badge" style="background: ${apt.color || '#667eea'}; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">${apt.type_name}</span>`
            : '';

        html += `
            <div class="calendar-event-item">
                <div class="calendar-event-time">${apt.start_time}</div>
                <div>
                    ${apt.title}
                    ${typeBadge}
                </div>
                ${apt.description ? `<div style="font-size: 11px; color: #7f8c8d;">${apt.description}</div>` : ''}
            </div>
        `;
    });
    
    popup.innerHTML = html;
    
    // Position berechnen
    const rect = event.target.getBoundingClientRect();
    popup.style.position = 'fixed';
    popup.style.left = rect.left + 'px';
    popup.style.top = (rect.bottom + 5) + 'px';
    
    // Füge zum Body hinzu
    document.body.appendChild(popup);
    
    // Schließen bei Click außerhalb
    setTimeout(() => {
        document.addEventListener('click', function closePopup() {
            popup.remove();
            document.removeEventListener('click', closePopup);
        });
    }, 10);
}

function previousMonth() {
    currentCalendarDate.setMonth(currentCalendarDate.getMonth() - 1);
    renderCalendar();
}

function nextMonth() {
    currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
    renderCalendar();
}

// ============================================
// MODAL FUNCTIONS
// ============================================

export async function openAppointmentModal(appointmentId = null) {
    const modal = document.getElementById('appointmentModal');
    const title = document.getElementById('appointmentModalTitle');
    
    // Lade Terminarten
    await loadAppointmentTypes();

    if (appointmentId) {
        title.textContent = 'Termin bearbeiten';
        await loadAppointmentData(appointmentId);

        // Zeige ID im Header
        updateModalId('appointmentModal', appointmentId);
    } else {
        title.textContent = 'Neuer Termin';
        document.getElementById('appointmentForm').reset();
        document.getElementById('appointment_id').value = '';

        // Keine ID anzeigen
        updateModalId('appointmentModal', null);

         // Setze Default-Terminart falls vorhanden
        const defaultType = dataCache.types.data.find(t => t.is_default);
        if (defaultType) {
            document.getElementById('appointment_type').value = defaultType.type_id;
        }
    }
    
    modal.classList.add('active');
}

export function closeAppointmentModal() {
    document.getElementById('appointmentModal').classList.remove('active');
}

// ============================================
// CRUD FUNCTIONS
// ============================================

export async function saveAppointment() {
    // Form-Validierung prüfen
    const form = document.getElementById('appointmentForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const appointmentId = document.getElementById('appointment_id').value;
    const data = {
        title: document.getElementById('appointment_title').value,
        type_id: parseInt(document.getElementById('appointment_type').value) || null,        
        description: document.getElementById('appointment_description').value || null,
        date: document.getElementById('appointment_date').value,
        start_time: document.getElementById('appointment_time').value
    };
    
    let result;
    if (appointmentId) {
        result = await apiCall('appointments', 'PUT', data, { id: appointmentId });
    } else {
        result = await apiCall('appointments', 'POST', data);
    }
    
    if (result) {
        closeAppointmentModal();

        // Cache invalidieren und neu laden
        showAppointmentSection(true, currentAppointmentsPage);

        // Erfolgs-Toast
        showToast(
            appointmentId ? 'Termin wurde erfolgreich aktualisiert' : 'Termin wurde erfolgreich erstellt',
            'success'
        );
    }
}

export async function deleteAppointment(appointmentId, title) {
    const confirmed = await showConfirm(
        `Termin "${title}" wirklich löschen?`,
        'Termin löschen'
    );

    if (confirmed) {
        const result = await apiCall('appointments', 'DELETE', null, { id: appointmentId });
        if (result) {
            // Cache invalidieren und neu laden            
            showAppointmentSection(true, currentAppointmentsPage);

            showToast(`Termin "${title}" wurde gelöscht`, 'success');
        }
    }
}

// Neue Funktion: Terminarten laden
async function loadAppointmentTypes() {    
    const types =  await loadTypes(true);    
    
    const select = document.getElementById('appointment_type');
    select.innerHTML = '<option value="">Bitte wählen...</option>';
    
    types.forEach(type => {
        const option = document.createElement('option');
        option.value = type.type_id;
        option.textContent = type.type_name;
        
        // Farbe als Data-Attribut für spätere Verwendung
        option.setAttribute('data-color', type.color);
        
        // Visual: Farb-Indikator
        option.style.paddingLeft = '20px';
        option.style.background = `linear-gradient(to right, ${type.color} 0%, ${type.color} 10px, transparent 10px)`;
        
        select.appendChild(option);
    });
}

async function goToToday() {
    currentCalendarDate = new Date();
    // Setze Filter auf aktuelles Jahr
    setCurrentYear(currentCalendarDate.getFullYear());    
    document.getElementById('appointmentYearFilter').value = currentYear;
    
    showAppointmentSection();
}

export async function setCalendarToYear() {
    // Springe zum entsprechenden Jahr im Kalender
    if (currentYear) {
        // Setze Kalender auf Januar des gewählten Jahres
        currentCalendarDate = new Date(parseInt(currentYear), 0, 1);
    } else {
        // Setze Kalender auf aktuellen Monat
        currentCalendarDate = new Date();
    }

}
// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

// Globale Funktionen für HTML onclick
window.openAppointmentModal = openAppointmentModal;
window.saveAppointment = saveAppointment;
window.closeAppointmentModal = () => document.getElementById('appointmentModal').classList.remove('active');
window.deleteAppointment = deleteAppointment;
window.previousMonth = previousMonth;
window.nextMonth = nextMonth;
window.goToToday = goToToday;