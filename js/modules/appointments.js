
import { apiCall, isAdmin } from './api.js';
import { showToast, showConfirm } from './ui.js';
import { loadRecordFilters } from './records.js';
import { loadExceptionFilters } from './exceptions.js';
import {datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime } from './utils.js';

// ============================================
// APPOINTMENTS
// Reference:
// import {} from './appointments.js'
// ============================================

let currentCalendarDate = new Date();
let allAppointments = [];

const appointmentsCache = {
    appointments: { data: null, loaded: false }
};

function invalidateAppointmentsCache(resource) {
    if (appointmentsCache) {
        appointmentsCache.loaded = false;
        appointmentsCache.data = null;
    }
}

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadAppointments(forceReload = false) {
    // Cache verwenden wenn vorhanden und nicht forceReload
    if (!forceReload && appointmentsCache.loaded && appointmentsCache.data) {
        renderAppointments(appointmentsCache.data);
        return;
    }

    let appointments;

    appointments = await apiCall('appointments');

    // Cache speichern
    appointmentsCache.data = appointments;
    appointmentsCache.loaded = true;

    renderAppointments(appointmentsCache.data);

}

async function loadAppointmentData(appointmentId) {
    const apt = await apiCall('appointments', 'GET', null, { id: appointmentId });
    
    if (apt) {
        document.getElementById('appointment_id').value = apt.appointment_id;
        document.getElementById('appointment_title').value = apt.title;
        document.getElementById('appointment_description').value = apt.description || '';
        document.getElementById('appointment_date').value = apt.date;
        document.getElementById('appointment_time').value = apt.start_time;
    }
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

function renderAppointments(cachedData) {

    const appointments = cachedData;
    
    if (!appointments) return;

    allAppointments = appointments;
    
    const tbody = document.getElementById('appointmentsTableBody');
    tbody.innerHTML = '';
    
    appointments.forEach(apt => {
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
                <td>${apt.appointment_id}</td>
                <td>${apt.title}</td>
                <td>${apt.description || '-'}</td>
                <td>${apt.date}</td>
                <td>${apt.start_time}</td>
                ${actionsHtml}
            </tr>
        `;
        tbody.innerHTML += row;
    });

    // Statistiken
    const today = new Date().toISOString().split('T')[0];
    const upcoming = appointments.filter(a => a.date >= today).length;
    document.getElementById('statUpcomingAppointments').textContent = upcoming;    
    
    // Diese Woche
    const weekStart = new Date();
    weekStart.setDate(weekStart.getDate() - weekStart.getDay() + 1);
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekStart.getDate() + 6);
    
    const thisWeek = appointments.filter(a => {
        const aptDate = new Date(a.date);
        return aptDate >= weekStart && aptDate <= weekEnd;
    }).length;    
    
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
    const dayAppointments = allAppointments.filter(apt => apt.date === dateStr);
    
    if (dayAppointments.length > 0) {
        day.classList.add('has-event');
        day.title = dayAppointments.map(a => `${a.start_time} - ${a.title}`).join('\n');
        
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
        html += `
            <div class="calendar-event-item">
                <div class="calendar-event-time">${apt.start_time}</div>
                <div>${apt.title}</div>
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

export function openAppointmentModal(appointmentId = null) {
    const modal = document.getElementById('appointmentModal');
    const title = document.getElementById('appointmentModalTitle');
    
    if (appointmentId) {
        title.textContent = 'Termin bearbeiten';
        loadAppointmentData(appointmentId);
    } else {
        title.textContent = 'Neuer Termin';
        document.getElementById('appointmentForm').reset();
        document.getElementById('appointment_id').value = '';
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
        invalidateAppointmentsCache();

        await loadAppointments(true);        
        await loadRecordFilters();
        await loadExceptionFilters();

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
            invalidateAppointmentsCache();

            await loadAppointments(true);        
            await loadRecordFilters();
            await loadExceptionFilters();

            showToast(`Termin "${title}" wurde gelöscht`, 'success');
        }
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