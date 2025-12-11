
import { apiCall, isAdmin } from './api.js';
import { showToast, showConfirm, dataCache, isCacheValid, invalidateCache,currentYear} from './ui.js';
import { loadRecordFilters } from './records.js';
import { loadExceptionFilters } from './exceptions.js';
import {datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime, updateModalId } from './utils.js';
import { loadTypes } from './management.js';

// ============================================
// APPOINTMENTS
// Reference:
// import {} from './appointments.js'
// ============================================

let currentCalendarDate = new Date();
//let allAppointments = [];
//let appointmentTypesCache = [];
let currentAppointmentYear = null;
//let yearsLoaded = false;


// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadAppointments(forceReload = false) {
    const year = currentYear;

    // Cache verwenden wenn vorhanden und nicht forceReload
    if (!forceReload && isCacheValid('appointments', year)) {
        console.log("Loading appointments from cache for ${year}", year);
        renderAppointments(dataCache.appointments[year].data);
        return;
    }
    
    console.log("Loading appointments from API for ${year}", year);
    const appointments = await apiCall('appointments', 'GET', null, {year: year});

    // Cache für dieses Jahr speichern
    if (!dataCache.appointments[year]) {
        dataCache.appointments[year] = {};
    }

    dataCache.appointments[year].data = appointments;
    dataCache.appointments[year].timestamp = Date.now();

    renderAppointments(appointments);
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

// Lade verfügbare Jahre
async function loadAppointmentYears() {
    /*
    const appointments = await apiCall('appointments');
    if (!appointments) return;
    
    // Extrahiere eindeutige Jahre
    //const years = [...new Set(appointments.map(a => new Date(a.date).getFullYear()))];
    //years.sort((a, b) => b - a); // Neueste zuerst
    
    //const select = document.getElementById('filterAppointmentYear');
    //select.innerHTML = '<option value="">Alle Jahre</option>';
    
    years.forEach(year => {
        select.innerHTML += `<option value="${year}">${year}</option>`;
    });    */

    return 0;
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

function renderAppointments(appointmentsData) {

    
    if (!appointmentsData) return;

    //allAppointments = appointments;
    
    const tbody = document.getElementById('appointmentsTableBody');
    tbody.innerHTML = '';
        
    appointmentsData.forEach(apt => {
        // Termin-Info mit Terminart
        let appointmentInfo = '-';
        if (apt.appointment_id && apt.title) {
            appointmentInfo = `<div style="line-height: 1.4;">
                <strong>${apt.title}</strong>`;
            
            if (apt.date && apt.start_time) {
                const aptDate = new Date(apt.date + 'T00:00:00');
                const formattedAptDate = aptDate.toLocaleDateString('de-DE');
                appointmentInfo += `<br><style="color: #7f8c8d;">${formattedAptDate}, ${apt.start_time.substring(0, 5)}</small>`;
            }
            
            appointmentInfo += '</div>';
        }

        const typeBadge = apt.type_name 
            ? `<span class="type-badge" style="background: ${apt.color || '#667eea'}; color: white;">${apt.type_name}</span>`
            : '<span class="type-badge">-</span>';

        const actionsHtml = isAdmin ? `
            <td>
                <button class="action-btn btn-edit" onclick="openAppointmentModal(${apt.appointment_id})">
                    Bearbeiten
                </button>
                <button class="action-btn btn-delete" onclick="deleteAppointment(${apt.appointment_id}, '${apt.title}')">
                    Löschen
                </button>
            </td>
        ` : '';
        
        const row = `
            <tr>
                <td>${appointmentInfo}</td>
                <td>${typeBadge}</td>
                <td>${apt.description || '-'}</td>
                ${actionsHtml}
            </tr>
        `;
        tbody.innerHTML += row;
    });

    // Statistiken
    const today = new Date().toISOString().split('T')[0];
    const upcoming = appointmentsData.filter(a => a.date >= today).length;
    document.getElementById('statUpcomingAppointments').textContent = upcoming;    
    
    /*
    // Diese Woche
    const weekStart = new Date();
    weekStart.setDate(weekStart.getDate() - weekStart.getDay() + 1);
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekStart.getDate() + 6);
    
    const thisWeek = appointments.filter(a => {
        const aptDate = new Date(a.date);
        return aptDate >= weekStart && aptDate <= weekEnd;
    }).length; */  
    
    // Kalender rendern
    renderCalendar();
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
        console.error('calendarDaysContainer not found');
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
    checkCalendarFilterMismatch();
}

function nextMonth() {
    currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
    renderCalendar();
    checkCalendarFilterMismatch();
}

export function applyAppointmentYearFilter() {
    const year = document.getElementById('filterAppointmentYear').value;
    currentAppointmentYear = year || null;

    // Springe zum entsprechenden Jahr im Kalender
    if (currentAppointmentYear) {
        // Setze Kalender auf Januar des gewählten Jahres
        currentCalendarDate = new Date(parseInt(currentAppointmentYear), 0, 1);
    } else {
        // Setze Kalender auf aktuellen Monat
        currentCalendarDate = new Date();
    }

    // Cache invalidieren und neu laden
    invalidateCache('appointments',currentYear);
    loadAppointments(true);
}

function checkCalendarFilterMismatch() {
    if (currentAppointmentYear) {
        const calendarYear = currentCalendarDate.getFullYear();
        const filterYear = parseInt(currentAppointmentYear);
        
        if (calendarYear !== filterYear) {
            // Visueller Hinweis im Kalender-Header
            const header = document.querySelector('.calendar-header h2');
            if (header) {
                header.style.color = '#e67e22'; // Orange für "außerhalb Filter"
                header.title = `Kalender zeigt ${calendarYear}, aber Filter ist auf ${filterYear} gesetzt`;
            }
        } else {
            // Normal
            const header = document.querySelector('.calendar-header h2');
            if (header) {
                header.style.color = '';
                header.title = '';
            }
        }
    }
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
        invalidateCache('appointments',currentYear);
        await loadAppointments(true);        
        //await loadRecordFilters();
        //await loadExceptionFilters();

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
            invalidateCache('appointments',currentYear);
            await loadAppointments(true);        
            //await loadRecordFilters();
            //await loadExceptionFilters();

            showToast(`Termin "${title}" wurde gelöscht`, 'success');
        }
    }
}

// Neue Funktion: Terminarten laden
async function loadAppointmentTypes() {
    if (dataCache.types.data.length === 0) {
        await loadTypes(true);
    }
    
    const select = document.getElementById('appointment_type');
    select.innerHTML = '<option value="">Bitte wählen...</option>';
    
    dataCache.types.data.forEach(type => {
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
    //const currentYear = new Date().getFullYear();
    document.getElementById('filterAppointmentYear').value = currentYear;
    currentAppointmentYear = currentYear;
    checkCalendarFilterMismatch();
    
    // Lade Appointments neu
    invalidateCache('appointments',currentYear);
    await loadAppointments(true);
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
window.applyAppointmentYearFilter = applyAppointmentYearFilter;
window.goToToday = goToToday;