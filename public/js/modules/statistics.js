import { apiCall, isAdminOrManager } from './api.js';
import { loadGroups } from './management.js';
import { loadMembers } from './members.js';
import { showToast, showConfirm, currentYear} from './ui.js';
import {debug} from '../app.js'

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
    const groups = await loadGroups(true);
    
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
    }    

    // Grid-Klasse für Layout setzen
    const filterGrid = document.querySelector('.filter-grid');

    // Mitglieder-Filter (nur für Admins)
    if (isAdminOrManager) {
        document.getElementById('statMemberFilterGroup').style.display = 'block';              
        // Initial alle Mitglieder anzeigen         
        
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
    let filteredMembers = allMembers.filter(m => m.active);
    
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

    if (!statsData || !statsData.statistics === 0) {        
        // Stats auf 0 setzen
        container.innerHTML = '<p class="info-message">Keine Daten für die ausgewählten Filter vorhanden.</p>';
        updateOverallStats(null);
        return;
    }

    updateOverallStats(statsData.summary);
    
    let html = '';
    
    statsData.statistics.forEach(group => {
        html += `
            <div class="statistics-group">
                <h2>${group.group_name}</h2>
                <div class="statistics-table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Mitglied</th>
                                <th>Termine gesamt</th>
                                <th>Anwesend</th>
                                <th>Unentschuldigt</th>
                                <th>Anwesenheitsquote</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${group.members.map(member => `
                                <tr>
                                    <td>${member.member_name}</td>
                                    <td>${member.total_appointments}</td>
                                    <td class="stat-present">${member.attended}</td>
                                    <td class="stat-unexcused">${member.unexcused_absences}</td>
                                    <td>
                                        <div class="attendance-rate">
                                            <div class="rate-bar">
                                                <div class="rate-fill-gradient"></div>
                                                <div class="rate-fill-mask" style="width: ${100 - member.attendance_rate}%"></div>
                                            </div>
                                            <span class="rate-text">${member.attendance_rate}%</span>
                                        </div>
                                    </td>
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
}

window.updateStatisticsFilters = updateStatisticsFilters;
window.applyStatisticsFilters = applyStatisticsFilters;