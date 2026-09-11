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
import { loadGroups } from './management.js';
import { loadMembers, getUserGroupIds } from './members.js';
import { showToast, showConfirm, currentYear} from './ui.js';
import {debug} from '../app.js'
import { escapeHtml } from './utils.js';

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadStatistics(filters = {}) {
    const year = currentYear; 

    const groupId = document.getElementById('statGroup').value;
    const memberId = isAdminOrManager ? document.getElementById('statMember').value : null;

    // API-Call mit Jahr
    debug.log(`Loading STATISTICS from API for ${year} with filters:`, filters);    
    
    const params = {year: year};

    if (filters.group && filters.group !== '') {
        params.group_id = filters.group;
    }
    
    if (filters.member && filters.member !== '') {
        params.member_id = filters.member;
    }
    
    const stats = await apiCall('statistics', 'GET', null, params);

    return stats;    

}

// ============================================
// FILTERING
// ============================================

export async function loadStatisticsFilters() {

    // Gruppen laden
    const groups = await loadGroups();    
    
    // Gruppen-Filter befüllen
    const groupSelect = document.getElementById('statGroup');
    if (groupSelect) {
        const currentValue = groupSelect.value;

        groupSelect.innerHTML = '<option value="">Alle Gruppen</option>';
        if (groups && groups.length > 0) {
            groups.forEach(group => {
                groupSelect.innerHTML += `<option value="${group.group_id}">${group.group_name}</option>`;
            });
        }

        if (currentValue) groupSelect.value = currentValue;

        // Für non-Admin: nur eigene Gruppen anzeigen
        const userGroupIds = await getUserGroupIds();
        if (userGroupIds !== null) {
            Array.from(groupSelect.options).forEach(opt => {
                if (opt.value !== '' && !userGroupIds.includes(parseInt(opt.value))) {
                    opt.remove();
                }
            });
            // Automatisch vorauswählen wenn nur eine Gruppe vorhanden
            if (!groupSelect.value && groupSelect.options.length === 2) {
                groupSelect.selectedIndex = 1;
            }
        }
    }    

    // Grid-Klasse für Layout setzen
    const filterGrid = document.querySelector('.filter-grid');

    // Mitglieder-Filter (nur für Admins)
    if (isAdminOrManager) {
        document.getElementById('statMemberFilterGroup').style.display = 'block';              
        // Initial alle Mitglieder anzeigen         
        await loadMembers();
        
        // Grid für 2 Spalten
        if (filterGrid) {
            filterGrid.classList.remove('single-filter');
            filterGrid.classList.add('dual-filter');
        }

        await updateStatisticsFilters(); 
        
    } else {
        document.getElementById('statMemberFilterGroup').style.display = 'none';

        // Grid für 1 Spalte
        if (filterGrid) {
            filterGrid.classList.remove('dual-filter');
            filterGrid.classList.add('single-filter');
        }
    }
    
}

export async function updateStatisticsFilters() {
    if (!isAdminOrManager) return;
    
    const groupSelect = document.getElementById('statGroup');
    const memberSelect = document.getElementById('statMember');
    
    //if (!groupSelect || !memberSelect) return;
    
    const selectedGroupId = groupSelect.value;
    const currentMemberId = memberSelect.value;
    
    debug.log('Updating member filter for group:', selectedGroupId);
    
    // Alle Mitglieder laden
    const allMembers = await loadMembers();
    
    // Filtern nach Gruppe (wenn ausgewählt)
    let filteredMembers = allMembers.filter(m => m.is_active_in_period);
    
    if (selectedGroupId && selectedGroupId !== '') {
        filteredMembers = filteredMembers.filter(m => {
            return m.group_ids_array && m.group_ids_array.includes(parseInt(selectedGroupId));
        });
    }
    
    // Dropdown neu befüllen
    memberSelect.innerHTML = '<option value="">Alle Mitglieder</option>';
    filteredMembers.forEach(member => {
        memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
    });    
    
    if (currentMemberId && filteredMembers.some(m => m.member_id == currentMemberId)) {
        memberSelect.value = currentMemberId;
    } else {
        memberSelect.value = '';
    }    
}

export async function applyStatisticsFilters() {

    debug.log("Load Statistics with Filters ()");

    // Aktuelle Filter auslesen
    const filters = {        
        member:isAdminOrManager ? (document.getElementById('statMember')?.value || null) : null,
        group: document.getElementById('statGroup')?.value || null
    };
    
    // Statistik laden
    const stats = await loadStatistics(filters);
    
    //Rendern, wenn Sektion aktiv
    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'statistik')
    {        
        renderStatistics(stats);  
    }
}


// ============================================
// RENDERING
// ============================================

export async function showStatisticsSection()
{
    debug.log("== Show Statistics Section == ")

    await loadStatisticsFilters();

    await applyStatisticsFilters();
}

export async function renderStatistics(statsData) {
    const container = document.getElementById('statisticsContainer');

    debug.log("Rendering stats:", statsData)

    // Die Pruefung hiess frueher `!statsData.statistics === 0` und war dadurch
    // immer falsch: `!x === 0` vergleicht einen Boolean mit einer Zahl und ist
    // nie wahr. Der Leerfall fiel durch und lief in einen Fehler beim forEach.
    if (!statsData || !Array.isArray(statsData.statistics) || statsData.statistics.length === 0) {
        container.innerHTML = '<p class="info-message">Keine Daten für die ausgewählten Filter vorhanden.</p>';
        updateOverallStats(statsData ? statsData.summary : null);
        return;
    }

    updateOverallStats(statsData.summary);

    let html = '';

    statsData.statistics.forEach(group => {
        const typeCount = group.appointment_types.length;

        const typeHeaders = group.appointment_types
            .map(t => `<th>${escapeHtml(t.type_name || 'ohne Terminart')}</th>`)
            .join('');

        // Gruppenzeile: Ohne sie stehen die Terminartspalten unbeschriftet
        // neben "Quote" -- "Auftritt" allein sagt nicht, dass darunter
        // ebenfalls eine Quote steht. Dieselbe Gliederung wie im Ausdruck.
        // Eine Gruppe ohne Terminart erreicht diese Stelle nicht (der Handler
        // ueberspringt sie), die Pruefung steht trotzdem da.
        const groupRow = typeCount > 0
            ? `<tr class="stat-colgroup">
                   <th></th>
                   <th colspan="5">Anwesenheit</th>
                   <th colspan="${typeCount}">Quote je Terminart</th>
               </tr>`
            : '';

        html += `
            <div class="statistics-group">
                <h2>${escapeHtml(group.group_name)}</h2>
                <div class="statistics-table-wrapper">
                    <table class="data-table">
                        <thead>
                            ${groupRow}
                            <tr>
                                <th>Mitglied</th>
                                <th class="stat-count">Termine</th>
                                <th class="stat-count">Anwesend</th>
                                <th class="stat-count">Ent&shy;schuldigt</th>
                                <th class="stat-count">Unent&shy;schuldigt</th>
                                <th>Quote</th>
                                ${typeHeaders}
                            </tr>
                        </thead>
                        <tbody>
                            ${group.members.map(member => `
                                <tr>
                                    <td>${escapeHtml(member.member_name)}</td>
                                    <td class="stat-count">${member.total_appointments}</td>
                                    <td class="stat-count stat-present">${member.attended}</td>
                                    <td class="stat-count">${member.excused}</td>
                                    <td class="stat-count stat-unexcused">${member.unexcused_absences}</td>
                                    <td class="stat-rate">
                                        <div class="attendance-rate">
                                            <div class="rate-bar">
                                                <div class="rate-fill-gradient"></div>
                                                <div class="rate-fill-mask" style="width: ${100 - member.attendance_rate}%"></div>
                                            </div>
                                            <span class="rate-text">${member.attendance_rate}%</span>
                                        </div>
                                    </td>
                                    ${member.by_type.map(t => t.total_appointments > 0
                                        ? `<td class="stat-type" title="${t.attended} von ${t.total_appointments}">
                                               <span class="type-value">${t.attendance_rate}%</span>
                                               <span class="type-track">
                                                   <span class="type-grad"></span>
                                                   <span class="type-mask" style="width: ${100 - t.attendance_rate}%"></span>
                                               </span>
                                           </td>`
                                        : `<td class="stat-type stat-type-empty" title="keine Termine dieser Art">–</td>`
                                    ).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

// ============================================
// HELPERS
// ============================================

function updateOverallStats(summary) {
    if (!summary) {
        document.getElementById('statTotalAppointments').textContent = '-';
        document.getElementById('statTotalPresent').textContent = '-';
        document.getElementById('statTotalExcused').textContent = '-';
        document.getElementById('statTotalUnexcused').textContent = '-';
        document.getElementById('statOverallAverage').textContent = '-';
        return;
    }
    
    document.getElementById('statTotalAppointments').textContent = summary.total_appointments;
    document.getElementById('statTotalPresent').textContent = summary.total_present;
    document.getElementById('statTotalExcused').textContent = summary.total_excused;
    document.getElementById('statTotalUnexcused').textContent = summary.total_unexcused;
    document.getElementById('statOverallAverage').textContent = summary.overall_average + '%';
}

/**
 * Öffnet den Anwesenheitsbericht mit der aktuellen Filterauswahl.
 *
 * Kein fetch: Der Bericht ist eine Seite, kein Datensatz. Er öffnet in einem
 * eigenen Tab, damit das Dashboard stehen bleibt und der Bericht vor dem
 * Drucken gelesen werden kann.
 *
 * Für ein Mitglied ist #statMember leer und unbefüllt -- es wird dann kein
 * member_id mitgeschickt, und der Server setzt die eigene Person ein. Hier
 * steht bewusst keine Rollenabfrage: Die Rechteentscheidung gehört an genau
 * eine Stelle, und die liegt im Server.
 */
export function openStatisticsReport() {
    const year   = document.getElementById('statisticYearFilter')?.value || '';
    const group  = document.getElementById('statGroup')?.value || '';
    const member = document.getElementById('statMember')?.value || '';

    const params = new URLSearchParams({ resource: 'statistics_report' });

    if (year)   params.set('year', year);
    if (group)  params.set('group_id', group);
    if (member) params.set('member_id', member);

    window.open(`${API_BASE}?${params.toString()}`, '_blank', 'noopener');
}

export async function initStatisticsEventHandlers() {

    // Change-Listener für automatische Aktualisierung
    document.getElementById('statGroup').addEventListener('change', () => {
            updateStatisticsFilters();
            applyStatisticsFilters();
        });

    if (isAdminOrManager) {
        document.getElementById('statMember').addEventListener('change', () => {
            updateStatisticsFilters();
            applyStatisticsFilters();
        });
    }

    document.getElementById('btnStatisticsReport')
        ?.addEventListener('click', openStatisticsReport);
}

window.updateStatisticsFilters = updateStatisticsFilters;
window.applyStatisticsFilters = applyStatisticsFilters;