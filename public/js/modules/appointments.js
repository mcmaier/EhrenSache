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
import {datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime, updateModalId, escapeHtml, formatTimeRange } from './utils.js';
import { loadTypes } from './management.js';
import { getUserGroupIds } from './members.js';
import {debug} from '../app.js'
import { globalPaginationValue } from './settings.js';
import { responseSummaryCell, responseChipsHtml, responseSummaryTitle, RESPONSE_ICONS, RESPONSE_LABELS } from './responses.js';

// ============================================
// APPOINTMENTS
// Reference:
// import {} from './appointments.js'
// ============================================

let currentCalendarDate = new Date();

// Der zuletzt gerenderte, bereits gefilterte Bestand. renderCalendar() wird
// auch aus previousMonth()/nextMonth() ohne Argumente gerufen -- ein Parameter
// allein wuerde den Kalender beim Blaettern leeren.
let calendarAppointments = [];

let currentAppointmentsPage = 1;
let appointmentFiltersBound = false;
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
        document.getElementById('appointment_end_time').value = apt.end_time ? apt.end_time.substring(0, 5) : '';
        document.getElementById('appointment_location').value = apt.location || '';
    }
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

/**
 * Baut den Terminart-Filter auf. Neu aufgebaut wird nur bei forceReload oder
 * solange ausser der Vorgabe nichts drinsteht -- showAppointmentSection() ist
 * zugleich der Einstiegspunkt der Filter-Listener und liefe sonst bei jedem
 * Filterwechsel erneut durch.
 */
async function fillAppointmentFilters(forceReload = false) {
    const select = document.getElementById('filterAppointmentType');
    if (!select) return;

    if (!forceReload && select.options.length > 1) return;

    const aptTypes      = await loadTypes(forceReload);
    const userGroupIds  = await getUserGroupIds();
    const vorherigeWahl = select.value;

    select.innerHTML = '<option value="">Alle Terminarten</option>';

    (aptTypes || []).forEach(aptt => {
        // getUserGroupIds() liefert null fuer Admin und Manager -- die sehen alles.
        if (userGroupIds !== null) {
            if (!aptt.groups || aptt.groups.length === 0) return;
            if (!aptt.groups.some(g => userGroupIds.includes(g.group_id))) return;
        }

        const option = document.createElement('option');
        option.value = aptt.type_id;
        option.textContent = aptt.type_name;
        if (aptt.color) {
            option.style.color = aptt.color;
            option.style.fontWeight = '500';
        }
        select.appendChild(option);
    });

    // Auswahl nur wiederherstellen, wenn es sie noch gibt -- sonst bleibt
    // der Browser bei der leeren Vorgabe.
    select.value = vorherigeWahl;
}

export function initAppointmentEventHandlers() {
    if (appointmentFiltersBound) return;

    document.getElementById('filterAppointmentType')?.addEventListener('change', () => {
        showAppointmentSection(false, 1);
    });

    document.getElementById('filterAppointmentOrigin')?.addEventListener('change', () => {
        showAppointmentSection(false, 1);
    });

    document.getElementById('resetAppointmentFilter')?.addEventListener('click', () => {
        resetAppointmentFilter();
    });

    appointmentFiltersBound = true;
}

export async function resetAppointmentFilter() {
    const typ      = document.getElementById('filterAppointmentType');
    const herkunft = document.getElementById('filterAppointmentOrigin');

    if (typ) typ.value = '';
    if (herkunft) herkunft.value = '';

    await showAppointmentSection(false, 1);
}

export async function showAppointmentSection(forceReload = false, page = 1)
{
    debug.log("== Show Appointment Section == ")
    const appointmentData = await loadAppointments(forceReload);

    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'termine')
    {
        await fillAppointmentFilters(forceReload);

        // Der Filter arbeitet auf den bereits geladenen Daten. Ein eigener
        // Serverparameter waere hier ohne Gewinn: Die Termine eines Jahres
        // liegen ohnehin vollstaendig im Cache. Die Werte kommen aus dem DOM,
        // weil loadYearDependentData() (ui.js) diese Funktion ohne Parameter ruft.
        const typ      = document.getElementById('filterAppointmentType')?.value || '';
        const herkunft = document.getElementById('filterAppointmentOrigin')?.value || '';

        let gefiltert = appointmentData || [];

        if (typ) {
            gefiltert = gefiltert.filter(a => String(a.type_id) === typ);
        }
        if (herkunft === 'auto') {
            gefiltert = gefiltert.filter(a => Number(a.is_auto_created) === 1);
        } else if (herkunft === 'manual') {
            gefiltert = gefiltert.filter(a => Number(a.is_auto_created) !== 1);
        }

        renderAppointments(gefiltert, page);
    }
}

async function renderAppointments(appointments, page = 1) {
    
    const tbody = document.getElementById('appointmentsTableBody');
    if (!appointments){
        tbody.innerHTML = '<tr><td colspan="5" class="loading">Keine Einträge gefunden</td></tr>';
        calendarAppointments = [];
        updateAppointmentStats([]);
        return;
    }

    appointmentsPerPage = globalPaginationValue;

    // Alle Appointments speichern für Pagination
    allFilteredAppointments = appointments;
    calendarAppointments = appointments;
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
            // Ein vom Check-in erzeugter Termin ist von einem geplanten nicht zu
            // unterscheiden — er zaehlt aber genauso in die Anwesenheitsstatistik.
            const autoBadge = Number(apt.is_auto_created) === 1
                ? ' <span class="type-badge" style="background: #95a5a6; color: white;" '
                  + 'title="Beim Check-in automatisch angelegt">🤖 automatisch</span>'
                : '';

            // Serientermin (FI-7): abgeloeste tragen ein eigenes Kennzeichen.
            const seriesBadge = apt.series_id
                ? (Number(apt.is_detached) === 1
                    ? ' <span class="series-badge series-badge--detached" title="Aus einer Serie, einzeln geändert" aria-label="Aus einer Serie, einzeln geändert">🔁</span>'
                    : ' <span class="series-badge" title="Teil einer Serie" aria-label="Teil einer Serie">🔁</span>')
                : '';

            appointmentInfo = `<div style="line-height: 1.4;">
                <strong>${escapeHtml(apt.title)}</strong>${autoBadge}${seriesBadge}`;

            if (apt.date && apt.start_time) {
                const aptDate = new Date(apt.date + 'T00:00:00');
                const formattedAptDate = aptDate.toLocaleDateString('de-DE');
                appointmentInfo += `<br><small style="color: #7f8c8d;">${formattedAptDate}, ${formatTimeRange(apt.start_time, apt.end_time)}</small>`;
                if (apt.location) {
                    appointmentInfo += `<br><small style="color: #7f8c8d;">📍 ${escapeHtml(apt.location)}</small>`;
                }
            }
            
            appointmentInfo += '</div>';
        }

        // apt.color/apt.type_name kommen aus der Terminart (DB) -- ohne CSP (OI-17)
        // muss hier selbst maskiert werden: Farbe per Whitelist, Text per escapeHtml().
        const safeAptColor = /^#[0-9a-f]{3,8}$/i.test(apt.color || '') ? apt.color : '#667eea';
        const typeBadge = apt.type_name
            ? `<span class="type-badge" style="background: ${safeAptColor}; color: white;">${escapeHtml(apt.type_name)}</span>`
            : '<span class="type-badge">-</span>';

        const actionsHtml = isAdminOrManager ? `
            <td class="actions-cell">
                    <button class="action-btn btn-icon btn-edit" 
                            onclick="openAppointmentModal(${apt.appointment_id})"
                            title="Bearbeiten">
                        ✎
                    </button>
                    <button class="action-btn btn-icon btn-delete"
                            onclick="deleteAppointment(${apt.appointment_id})"
                            title="Löschen">
                        🗑
                    </button>
                </td>
        ` : '';
        
        tr.innerHTML = `
                <td>${appointmentInfo}</td>
                <td>${typeBadge}</td>
                <td>${apt.description ? escapeHtml(apt.description) : '-'}</td>
                <td class="response-cell">${responseSummaryCell(apt)}</td>
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
        const day = createCalendarDay(prevLastDate - i + 1, year, month - 1, true, false, calendarAppointments);
        container.appendChild(day);
    }
    
    // Aktueller Monat
    const today = new Date();
    for (let i = 1; i <= lastDate; i++) {
        const isToday = year === today.getFullYear() && 
                       month === today.getMonth() && 
                       i === today.getDate();
        
        const day = createCalendarDay(i, year, month, false, isToday, calendarAppointments);
        container.appendChild(day);
    }
    
    // Nächster Monat (auffüllen)
    const totalCells = container.children.length;
    const remainingCells = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
    for (let i = 1; i <= remainingCells; i++) {
        const day = createCalendarDay(i, year, month + 1, true, false, calendarAppointments);
        container.appendChild(day);
    }
}

function createCalendarDay(dayNum, year, month, isOtherMonth, isToday = false, appointments = []) {
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
    const dayAppointments = (appointments || []).filter(apt => apt.date === dateStr);

    // Feiertag (FI-16) -- nur im laufenden Monat; die ausgegrauten Tage der
    // Nachbarmonate tragen ein unnormiertes Datum (Monat -1 bzw. 12).
    if (!isOtherMonth) {
        const holidayName = holidaysOfYear(year)[dateStr];
        if (holidayName) {
            day.classList.add('calendar-day--holiday');
            const tag = document.createElement('span');
            tag.className = 'calendar-holiday-name';
            tag.textContent = holidayName;
            day.appendChild(tag);
        }
    }

    if (dayAppointments.length > 0) {
        day.classList.add('has-event');
        
        // Verwende Farbe der ersten Terminart
        const firstType = dayAppointments[0];
        if (firstType.color) {
            day.style.borderLeftColor = firstType.color;
            day.style.borderLeftWidth = '3px';
            day.style.borderLeftStyle = 'solid';
        }
        
        // Rueckmeldungs-Markierung (FI-1): kleiner Punkt, wenn mindestens ein
        // Termin des Tages eine Ampel fuehrt. Hervorgehoben, wenn davon
        // mindestens einer noch offen ist -- vom Anwesenden erwartet, noch
        // ohne eigene Antwort und noch nicht begonnen. Als eigenes Element
        // angehaengt, NACH dem textContent oben, damit die Tageszahl davon
        // unberuehrt bleibt (sie wird an anderer Stelle nirgends mehr gelesen).
        const withResponses = dayAppointments.filter(a => a.responses);
        let responseOpen = false;
        if (withResponses.length > 0) {
            const now = new Date();
            responseOpen = withResponses.some(a => a.responses.expected === true && !a.responses.own
                && new Date(`${a.date}T${a.start_time}`) > now);

            const dot = document.createElement('span');
            dot.className = 'calendar-response-dot' + (responseOpen ? ' calendar-response-dot--open' : '');
            dot.setAttribute('aria-hidden', 'true');
            day.appendChild(dot);
        }

        // Termine des Tages als Vorlesetext.
        //
        // Ersetzt das frueher gesetzte title-Attribut: Der native Tooltip kam
        // erst nach rund einer Sekunde, war unformatiert -- und liefe jetzt
        // zusaetzlich zum eigenen Popup auf, das dieselben Termine zeigt.
        day.setAttribute('aria-label', `${dayNum}., ` + dayAppointments.map(a => {
            const typeName = a.type_name ? `${a.type_name}, ` : '';
            return `${a.start_time} ${typeName}${a.title}`;
        }).join('; ')
            + withResponses.map(a => `; ${responseSummaryTitle(a.responses)}`).join('')
            + (responseOpen ? '; Rückmeldung offen' : ''));

        // Ueberfahren zeigt dasselbe Popup wie der Klick, nur fluechtig. Die
        // kleine Verzoegerung verhindert, dass beim Wandern ueber den Kalender
        // an jedem Tag kurz etwas aufblitzt.
        day.addEventListener('mouseenter', () => {
            clearTimeout(kalenderHoverTimer);
            kalenderHoverTimer = setTimeout(
                () => showAppointmentPopup(day, dayAppointments, false), 180
            );
        });

        day.addEventListener('mouseleave', () => {
            clearTimeout(kalenderHoverTimer);
            const popup = document.querySelector('.calendar-event-popup');
            // Ein per Klick geoeffnetes Popup bleibt stehen -- sonst waere es
            // nicht lesbar, sobald der Zeiger es erreicht.
            if (popup && !popup.dataset.fest) popup.remove();
        });

        // Klick haelt das Popup fest. Auf Touch-Geraeten gibt es kein
        // Ueberfahren, dort ist das der einzige Weg.
        day.addEventListener('click', (e) => {
            e.stopPropagation();
            clearTimeout(kalenderHoverTimer);
            showAppointmentPopup(day, dayAppointments, true);
        });
    } else if (!isOtherMonth && isAdminOrManager) {
        // OI-64: Ein leerer Tag legt einen Termin an. Einfache Nutzer legen
        // keine Termine an und sehen deshalb keine Aenderung.
        const createLabel = `Neuen Termin am ${String(dayNum).padStart(2, '0')}.${String(month + 1).padStart(2, '0')}.${year} anlegen`;
        day.classList.add('calendar-day--can-create');
        day.title = 'Neuen Termin anlegen';
        day.setAttribute('role', 'button');
        day.setAttribute('tabindex', '0');
        day.setAttribute('aria-label', createLabel);
        const createHandler = (e) => {
            e.stopPropagation();
            // Ein festgehaltenes Popup eines anderen Tages muss weichen --
            // sonst schwebt es ueber dem neuen Dialog (Review Task 11).
            document.querySelector('.calendar-event-popup')?.remove();
            openAppointmentModal(null, { date: dateStr });
        };
        day.addEventListener('click', createHandler);
        day.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                createHandler(e);
            }
        });
    }

    return day;
}

/** Verzoegerung zwischen Ueberfahren und Anzeige. */
let kalenderHoverTimer = null;

/** Jahre, deren Feiertage gerade geladen werden -- verhindert Doppelanfragen. */
const holidayRequests = new Set();

/**
 * Feiertage eines Jahres aus dem Cache. Fehlen sie, werden sie nachgeladen
 * und der Kalender danach neu gezeichnet; bis dahin gilt: keine Feiertage.
 */
function holidaysOfYear(year) {
    if (isCacheValid('holidays', year)) {
        return dataCache.holidays[year].data;
    }
    if (!holidayRequests.has(year)) {
        holidayRequests.add(year);
        apiCall('holidays', 'GET', null, { from: `${year}-01-01`, to: `${year}-12-31` },
            { silentStatuses: [400, 403, 404, 500] })
            .then(res => {
                if (res && res.success) {
                    dataCache.holidays[year] = { data: res.holidays || {}, timestamp: Date.now() };
                    renderCalendar();
                } else {
                    // Fehlschlag ebenfalls fuer die TTL merken -- sonst fragt jeder
                    // Kalender-Rerender erneut an und haemmert mit Fehler-Toasts.
                    dataCache.holidays[year] = { data: {}, timestamp: Date.now(), failed: true };
                }
            })
            .finally(() => holidayRequests.delete(year));
    }
    return {};
}

/**
 * Rueckmeldungs-Zeile eines Termins im Kalender-Popup: dieselbe Chip-Gruppe
 * wie die Terminliste (responseChipsHtml), dazu die eigene Antwort. Nur im
 * festgehaltenen Popup (fest=true) anklickbar, und auch dort nur, wenn
 * responseSummaryCell() fuer denselben Termin ebenfalls einen Button liefern
 * wuerde (erwartet oder Verwaltung) -- sonst wie in der Terminliste nur Text.
 */
function calendarResponseLineHtml(apt, fest) {
    const r = apt.responses;
    const title = responseSummaryTitle(r);
    const chips = responseChipsHtml(r);
    const own = r.own
        ? ` <span class="response-own response-own--${r.own}" title="Eigene Rückmeldung: ${RESPONSE_LABELS[r.own]}">${RESPONSE_ICONS[r.own]}</span>`
        : '';
    const clickable = fest && (r.expected || isAdminOrManager);

    if (!clickable) {
        // Im festgehaltenen Popup zusaetzlich sagen, warum die Zeile nicht
        // anklickbar ist -- sonst wirkt sie wie eine tote Schaltflaeche.
        // Im fluechtigen (Hover-)Popup faellt der Hinweis weg, dort ist
        // ohnehin nichts anklickbar.
        const hinweis = fest && !r.expected && !isAdminOrManager
            ? ' <span class="response-not-invited">nicht eingeladen</span>'
            : '';
        return `<div class="calendar-event-responses"><span class="response-summary-text" title="${escapeHtml(title)}">${chips}</span>${own}${hinweis}</div>`;
    }

    // Der Dokument-Klick-Handler in showAppointmentPopup() entfernt das Popup
    // ohnehin beim Bubbling -- aber erst danach, und nur, wenn er ueberhaupt
    // registriert ist (10ms Verzoegerung). Hier explizit vorher entfernen,
    // damit ein schneller Klick nicht ins Leere modaliert.
    return `<div class="calendar-event-responses">
        <button type="button" class="response-summary-btn" title="${escapeHtml(title)}"
            onclick="document.querySelector('.calendar-event-popup')?.remove(); window.openResponsesModal(${Number(apt.appointment_id)})">${chips}</button>${own}
    </div>`;
}

/**
 * Zeigt die Termine eines Tages neben dem Kalenderfeld.
 *
 * @param {HTMLElement} ziel         Das Kalenderfeld, an dem das Popup haengt
 * @param {Array}       appointments Termine dieses Tages
 * @param {boolean}     fest         true = bleibt bis zum Klick daneben stehen,
 *                                   false = verschwindet beim Verlassen
 */
function showAppointmentPopup(ziel, appointments, fest = true) {
    // Entferne altes Popup
    const oldPopup = document.querySelector('.calendar-event-popup');
    if (oldPopup) {
        // FEHLERBEHEBUNG (FI-1): Ein bereits festgehaltenes Popup (fest=true)
        // darf nicht von einem bloss fluechtigen Hover-Popup eines anderen
        // Tages verdraengt werden -- der mouseleave-Handler unten laesst ein
        // festes Popup aus genau diesem Grund stehen, showAppointmentPopup()
        // selbst pruefte das bislang nicht. Wanderte der Mauszeiger beim Weg
        // vom Kalendertag zur angepinnten Ampel-Zeile ueber einen
        // Nachbartag mit Terminen, ersetzte dessen 180ms-Hover-Timer das
        // gerade angepinnte Popup durch sein eigenes -- die eigentlich
        // angeklickte Rueckmeldungs-Zeile verschwand unter dem Mauszeiger,
        // der Klick traf ins Leere und wirkte, als taete er nichts.
        if (!fest && oldPopup.dataset.fest) {
            return;
        }
        oldPopup.remove();
    }

    // Erstelle neues Popup
    const popup = document.createElement('div');
    popup.className = 'calendar-event-popup active';
    if (fest) {
        popup.dataset.fest = '1';
    }

    // Datum und Uhrzeit wie ueberall sonst in der Oberflaeche: deutsches
    // Format, Uhrzeit ohne Sekunden. Roh gezeigt las sich der Kopf als
    // "2026-09-04" und die Zeile als "20:00:00" -- seit das Popup schon beim
    // Ueberfahren erscheint, faellt das staendig auf.
    const tag = new Date(appointments[0].date + 'T00:00:00');
    const kopf = isNaN(tag.getTime())
        ? appointments[0].date
        : tag.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });

    let html = `<h4>${kopf}</h4>`;
    appointments.forEach(apt => {
        // Terminart-Badge mit Farbe. apt.color kommt aus der Terminart und
        // landet ungeprueft in einem style-Attribut -- ohne CSP (OI-17) muss
        // das selbst geschehen. Bei ungueltigem Wert bleibt es beim Default.
        const color = /^#[0-9a-f]{3,8}$/i.test(apt.color || '') ? apt.color : '#667eea';
        const typeBadge = apt.type_name
            ? `<span class="calendar-type-badge" style="background: ${color}; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px;">${escapeHtml(apt.type_name)}</span>`
            : '';

        html += `
            <div class="calendar-event-item">
                <div class="calendar-event-time">${formatTimeRange(apt.start_time, apt.end_time)}</div>
                <div>
                    ${escapeHtml(apt.title)}
                    ${typeBadge}
                </div>
                ${apt.description ? `<div style="font-size: 11px; color: #7f8c8d;">${escapeHtml(apt.description)}</div>` : ''}
                ${apt.location ? `<div style="font-size: 11px; color: #7f8c8d;">📍 ${escapeHtml(apt.location)}</div>` : ''}
                ${apt.responses ? calendarResponseLineHtml(apt, fest) : ''}
                ${fest && isAdminOrManager ? `<button type="button" class="calendar-event-edit"
                    onclick="document.querySelector('.calendar-event-popup')?.remove(); window.openAppointmentModal(${Number(apt.appointment_id)})">Bearbeiten</button>` : ''}
            </div>
        `;
    });

    // OI-64: Auch an belegten Tagen einen weiteren Termin anlegen koennen.
    const tagDatum = appointments[0].date;
    if (fest && isAdminOrManager && /^\d{4}-\d{2}-\d{2}$/.test(tagDatum)) {
        html += `<button type="button" class="calendar-event-add"
            onclick="document.querySelector('.calendar-event-popup')?.remove(); window.openAppointmentModal(null, { date: '${tagDatum}' })">+ Termin an diesem Tag</button>`;
    }

    popup.innerHTML = html;

    // Erst anhängen, dann messen: Vorher steht die Größe nicht fest.
    popup.style.position = 'fixed';
    popup.style.visibility = 'hidden';
    document.body.appendChild(popup);

    // Am Rand des Fensters umklappen statt hinauslaufen. Ohne das verschwindet
    // das Popup in der rechten Kalenderspalte und in der letzten Zeile aus dem
    // Bild -- beim Klick selten, beim Ueberfahren staendig.
    const rand = 8;
    const feld = ziel.getBoundingClientRect();
    let links = feld.left;
    let oben  = feld.bottom + 5;

    // Klammerung gegen die Groesse des Sichtbereichs OHNE Scrollbalken
    // (documentElement.clientWidth/clientHeight). Die frueher genutzte
    // Fenstergroesse schloss die Breite eines sichtbaren Scrollbalkens mit
    // ein -- das Popup lief dort in der letzten Spalte unter dem
    // Scrollbalken hinaus statt daneben umzuklappen.
    const viewportBreite = document.documentElement.clientWidth;
    const viewportHoehe = document.documentElement.clientHeight;

    if (links + popup.offsetWidth > viewportBreite - rand) {
        links = viewportBreite - popup.offsetWidth - rand;
    }
    if (links < rand) {
        links = rand;
    }
    if (oben + popup.offsetHeight > viewportHoehe - rand) {
        oben = feld.top - popup.offsetHeight - 5;
    }
    if (oben < rand) {
        oben = rand;
    }

    popup.style.left = links + 'px';
    popup.style.top = oben + 'px';
    popup.style.visibility = '';

    // Nur ein festgehaltenes Popup wartet auf einen Klick daneben. Ein
    // fluechtiges verschwindet ohnehin, sobald der Zeiger das Feld verlaesst.
    if (!fest) {
        return;
    }

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

/** Vorschlaege fuer das Ortsfeld: bisher verwendete Orte, vom Server (FI-23). */
async function fillLocationSuggestions() {
    const list = document.getElementById('appointmentLocations');
    if (!list) return;
    const orte = await apiCall('appointments', 'GET', null, { locations: 1 });
    list.innerHTML = (Array.isArray(orte) ? orte : [])
        .map(o => `<option value="${escapeHtml(o)}"></option>`).join('');
}

export async function openAppointmentModal(appointmentId = null, preset = {}) {
    const modal = document.getElementById('appointmentModal');
    const title = document.getElementById('appointmentModalTitle');

    // Lade Terminarten
    await loadAppointmentTypes();
    await fillLocationSuggestions();

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

        if (preset.date && /^\d{4}-\d{2}-\d{2}$/.test(preset.date)) {
            document.getElementById('appointment_date').value = preset.date;
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
        start_time: document.getElementById('appointment_time').value,
        end_time: document.getElementById('appointment_end_time').value || null,
        location: document.getElementById('appointment_location').value.trim() || null
    };
    
    let result;
    if (appointmentId) {
        result = await apiCall('appointments', 'PUT', data, { id: appointmentId });
    } else {
        result = await apiCall('appointments', 'POST', data);
    }
    
    if (result.success) {
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

export async function deleteAppointment(appointmentId) {
    // Titel aus dem Cache holen statt aus dem onclick-Attribut: ein Termin-Titel
    // mit Apostroph oder HTML sprengte dort sonst den Aufruf bzw. liesse sich als
    // Code einschleusen (kein CSP im Projekt) -- Muster aus deleteGroup()/
    // deleteType() in management.js (Commit ad200ba).
    const cached = dataCache.appointments[currentYear]?.data?.find(a => a.appointment_id == appointmentId);
    const title = cached ? cached.title : 'diesem Termin';

    const confirmed = await showConfirm(
        `Termin "${title}" wirklich löschen?`,
        'Termin löschen'
    );

    if (confirmed) {
        const result = await apiCall('appointments', 'DELETE', null, { id: appointmentId });
        if (result.success) {
            // Cache invalidieren und neu laden            
            showAppointmentSection(true, currentAppointmentsPage);

            // showToast() setzt die Nachricht per innerHTML (ui.js) -- der Titel
            // kommt aus der Terminverwaltung (Admin/Manager) und muss daher escaped werden.
            showToast(`Termin "${escapeHtml(title)}" wurde gelöscht`, 'success');
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
window.showAppointmentSection = showAppointmentSection;
window.resetAppointmentFilter = resetAppointmentFilter;

// Fuer responses.js (FI-1): Terminliste auf der aktuell gezeigten Seite neu
// laden, ohne die Seite zu wechseln. Der Cache wurde vorher per
// invalidateCache('appointments', jahr) geleert, forceReload=false reicht
// deshalb -- loadAppointments() faellt automatisch auf den API-Abruf zurueck.
window.refreshAppointmentsKeepPage = () => showAppointmentSection(false, currentAppointmentsPage);