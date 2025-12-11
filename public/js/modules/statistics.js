import { apiCall, isAdmin } from './api.js';
import { loadGroups } from './management.js';
import { showToast, showConfirm, dataCache, isCacheValid,invalidateCache, currentYear, setCurrentYear} from './ui.js';
import { round } from './utils.js';

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadStatistics(forceReload = false) {
    const year = currentYear; 

    // Cache-Check
    if (!forceReload && isCacheValid('statistics', year)) {
        console.log(`Loading statistics for ${year} from cache`);
        renderStatistics(dataCache.statistics[year].data);
        return;
    }

    const groupId = document.getElementById('statGroup').value;
    const memberId = isAdmin ? document.getElementById('statMember').value : null;

    // API-Call mit Jahr
    const stats = await apiCall('statistics', 'GET', null, { year: year });
    
    // Cache speichern
    if (!dataCache.statistics[year]) {
        dataCache.statistics[year] = {};
    }
    dataCache.statistics[year].data = stats;
    dataCache.statistics[year].timestamp = Date.now();
    
    renderStatistics(stats);
    
    /*
    let params = {};
    if(year) params.year = year;
    if (groupId) params.group_id = groupId;
    if (memberId) params.member_id = memberId;
    
    try {
        const response = await apiCall('statistics','GET', null, params);
        renderStatistics(response);
    } catch (error) {
        showToast('Fehler beim Laden der Statistik', 'error');
        console.error(error);
    }
    */
}

export async function reloadStatisticsFilters() {
    // Jahr-Filter (aktuelle + letzte 5 Jahre)
    
    const yearSelect = document.getElementById('statisticYearFilter');

    // Gespeichertes/aktuelles Jahr setzen
    yearSelect.value = currentYear;

    // Event Listener für Synchronisation (falls noch nicht vorhanden)
    if (!yearSelect.dataset.listenerAdded) {
        yearSelect.addEventListener('change', (e) => {
            setCurrentYear(e.target.value);
        });
        yearSelect.dataset.listenerAdded = 'true';
    }

    // Gruppen laden
    try {
        loadGroups(false);

        const groups = dataCache.groups.data;
        const groupSelect = document.getElementById('statGroup');
        groupSelect.innerHTML = '<option value="">Alle Gruppen</option>';
        groups.forEach(group => {
            groupSelect.innerHTML += `<option value="${group.group_id}">${group.group_name}</option>`;
        });
    } catch (error) {
        console.error('Fehler beim Laden der Gruppen:', error);
    }
    
    // Mitglieder-Filter nur für Admins
    if (isAdmin) {
        document.getElementById('statMemberFilterGroup').style.display = 'block';
        await loadMemberFilterOptions();
    }
    else
    {
        document.getElementById('statMemberFilterGroup').style.display = 'none';
    }
}


async function loadMemberFilterOptions() {
    const groupId = document.getElementById('statGroup').value;
    const currentMemberId = document.getElementById('statMember').value;
    const memberSelect = document.getElementById('statMember');
    
    try {
        let members;
        let params = {};

        if (groupId) {
            // Lade nur Mitglieder der ausgewählten Gruppe
            params.group_id = groupId;
            members = await apiCall('members', 'GET', null, params);     
        } else {
            // Alle aktiven Mitglieder
            members = await apiCall('members', 'GET');
        }
        
        // Select neu befüllen
        memberSelect.innerHTML = '<option value="">Alle Mitglieder</option>';
        members.forEach(member => {
            memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
        });
        
        // Prüfe ob aktuell ausgewähltes Mitglied noch in der Liste ist
        if (currentMemberId) {
            const memberStillAvailable = members.some(m => m.member_id == currentMemberId);
            if (memberStillAvailable) {
                memberSelect.value = currentMemberId;
            } else {
                // Zurücksetzen auf "Alle Mitglieder"
                memberSelect.value = '';
            }
        }
        
    } catch (error) {
        console.error('Fehler beim Laden der Mitglieder:', error);
        memberSelect.innerHTML = '<option value="">Alle Mitglieder</option>';
    }
}

// ============================================
// RENDERING
// ============================================

function renderStatistics(data) {
    const container = document.getElementById('statisticsContainer');
    
    if (!data.statistics || data.statistics.length === 0) {
        container.innerHTML = '<p class="info-message">Keine Daten für die ausgewählten Filter vorhanden.</p>';
        // Stats auf 0 setzen
        updateOverallStats(null);
        return;
    }

    updateOverallStats(data.summary);
    
    let html = '';
    
    data.statistics.forEach(group => {
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
                                                <div class="rate-fill" style="width: ${member.attendance_rate}%"></div>
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

export async function initStatistics() {

    // Change-Listener für automatische Aktualisierung
    //document.getElementById('statYear').addEventListener('change', loadStatistics);
    document.getElementById('statGroup').addEventListener('change', async function() {
        if (isAdmin) {
            await loadMemberFilterOptions();
        }
        loadStatistics();
    });
    
    if (isAdmin) {
        document.getElementById('statMember').addEventListener('change', loadStatistics);
    }
}