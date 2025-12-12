import { apiCall, isAdmin } from './api.js';
import { showToast, showConfirm, dataCache, isCacheValid, invalidateCache} from './ui.js';
import { loadUserData } from './users.js';
import { loadRecordFilters } from './records.js';
import { loadExceptionFilters } from './exceptions.js';
import { updateModalId } from './utils.js';
import { loadGroups } from './management.js';


// ============================================
// MEMBERS
// Reference:
// import {} from './members.js'
// ============================================

// ============================================
// DATA FUNCTIONS (Cache, API-Calls)
// ============================================

let currentMembershipDates = [];
let currentMemberGroups = [];
//let allAvailableGroups = [];

export async function loadMembers(forceReload = false) {
    // Cache verwenden wenn vorhanden und nicht forceReload    
    if (!forceReload && isCacheValid('members')) {
        console.log("Loading MEMBERS from CACHE");
        //renderMembers(dataCache.members.data);
        return dataCache.members.data;
    }

    // Userprofil abfragen (falls nicht gecacht)
    await loadUserData();

    // Mitglieder laden basierend auf gecachten userDetails
    const { userDetails } = dataCache.userData.data;

    //console.log("Getting user data:", dataCache.userData.data);

    let members = [];

    console.log("Loading MEMBERS from API");
            
    if(isAdmin){
        // Admin sieht alle Mitglieder
        members = await apiCall('members');        
    }
    else {        
        if (userDetails && userDetails.member_id) {
            const member = await apiCall('members', 'GET', null, { id: userDetails.member_id });
            members = member ? [member] : [];
        } 
    }     

    // Cache speichern
    dataCache.members.data = members;
    dataCache.members.timestamp = Date.now();

    //renderMembers(dataCache.members.data);
    return members;
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

function renderMembers(memberData) {
    if (!memberData || memberData.length === 0) {
        const tbody = document.getElementById('membersTableBody');
        tbody.innerHTML = '<tr><td colspan="7" class="loading">Kein Profil verknüpft</td></tr>';
        return;
    }
    
    const tbody = document.getElementById('membersTableBody');
    tbody.innerHTML = '';
    
    memberData.forEach(member => {
        // Gruppen-Badges erstellen
        const groupBadges = member.group_names 
            ? member.group_names.split(', ').map(name => 
                `<span class="type-badge" style="margin: 2px;">${name}</span>`
              ).join('')
            : '<span style="color: #7f8c8d;">Keine</span>';

        const actionsHtml = isAdmin ? `
            <td>
                <button class="action-btn btn-edit" onclick="openMemberModal(${member.member_id})">
                    Bearbeiten
                </button>
                <button class="action-btn btn-delete" onclick="deleteMember(${member.member_id}, '${member.name} ${member.surname}')">
                    Löschen
                </button>
            </td>
        ` : '';
        
        const row = `
            <tr>
                <td>${member.surname}</td>
                <td>${member.name}</td>
                <td>${member.member_number || '-'}</td>
                <td>${groupBadges}</td>
                <td>${member.active ? 'Aktiv' : 'Inaktiv'}</td>
                ${actionsHtml}
            </tr>
        `;
        tbody.innerHTML += row;
    });

    // Statistik nur für Admin
    if (isAdmin) {
        const activeCount = memberData.filter(m => m.active).length;
        document.getElementById('statActiveMembersCount').textContent = activeCount;
    } else {
        document.getElementById('statActiveMembersCount').textContent = '-';
    }            
}

export async function showMemberSection(forceReload = false) {

    console.log("Show Member Section ()");

    const allMembers = await loadMembers(forceReload);
    renderMembers(allMembers);
}

// ============================================
// MODAL FUNCTIONS
// ============================================

export async function openMemberModal(memberId = null) {

    const modal = document.getElementById('memberModal');
    const title = document.getElementById('memberModalTitle');
    const membershipGroup = document.getElementById('membershipDatesGroup');

    // Lade alle verfügbaren Gruppen
    if (dataCache.groups.data.length === 0) {
        loadGroups(true);
    }
    
    if (memberId) {
        // Bearbeiten
        title.textContent = 'Mitglied bearbeiten';
        await loadMemberData(memberId);

        // Zeige ID im Header
        updateModalId('memberModal', memberId);

        if (isAdmin) {
            membershipGroup.style.display = 'block';
            await loadMembershipDates(memberId);
        }

    } else {
        // Neu erstellen
        title.textContent = 'Neues Mitglied';
        document.getElementById('memberForm').reset();
        document.getElementById('member_id').value = '';
        document.getElementById('member_active').checked = true;
        membershipGroup.style.display = 'none';
        currentMembershipDates = [];

        // Keine ID anzeigen
        updateModalId('memberModal', null);

        // Setze Standard-Gruppe als vorausgewählt
        const defaultGroup = dataCache.groups.data.find(g => g.is_default);
        currentMemberGroups = defaultGroup ? [defaultGroup.group_id] : [];
    }

    renderMemberGroups();
    
    modal.classList.add('active');
}

export function closeMemberModal() {
    document.getElementById('memberModal').classList.remove('active');
}

export async function loadMemberData(memberId) {
    const member = await apiCall('members', 'GET', null, { id: memberId });
    
    if (member) {
        document.getElementById('member_id').value = member.member_id;
        document.getElementById('member_name').value = member.name;
        document.getElementById('member_surname').value = member.surname;
        document.getElementById('member_number').value = member.member_number || '';
        document.getElementById('member_active').checked = member.active == 1;
        // Speichere ausgewählte Gruppen
        currentMemberGroups = member.groups ? member.groups.map(g => g.group_id) : [];
    
    }
}

function renderMemberGroups() {
    const container = document.getElementById('memberGroupsList');
    
    if (dataCache.groups.data.length === 0) {
        container.innerHTML = '<p style="color: #7f8c8d;">Keine Gruppen verfügbar</p>';
        return;
    }
    
    container.innerHTML = dataCache.groups.data.map(group => `
        <label style="display: flex; align-items: flex-start; padding: 8px; cursor: pointer; border-radius: 4px;" 
               onmouseover="this.style.background='#f5f5f5'" 
               onmouseout="this.style.background='transparent'">
            <input type="checkbox" 
                   class="member-group-checkbox" 
                   value="${group.group_id}" 
                   ${currentMemberGroups.includes(group.group_id) ? 'checked' : ''}>
            <span style="margin-left: 8px; flex: 1;">
                <strong>${group.group_name}</strong>
                ${group.is_default ? ' <span class="status-badge status-approved" style="font-size: 10px; padding: 2px 6px;">Standard</span>' : ''}
                ${group.description ? `<br><small style="color: #7f8c8d;">${group.description}</small>` : ''}
            </span>
        </label>
    `).join('');
}

// ============================================
// CRUD FUNCTIONS
// ============================================

export async function saveMember() {
    // Validierung
    const form = document.getElementById('memberForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const memberId = document.getElementById('member_id').value;

    // Sammle ausgewählte Gruppen
    const groupCheckboxes = document.querySelectorAll('.member-group-checkbox:checked');
    const groupIds = Array.from(groupCheckboxes).map(cb => parseInt(cb.value));    

    const data = {
        name: document.getElementById('member_name').value,
        surname: document.getElementById('member_surname').value,
        member_number: document.getElementById('member_number').value || null,
        active: document.getElementById('member_active').checked,
        group_ids: groupIds
    };
    
    let result;
    if (memberId) {
        // Update
        result = await apiCall('members', 'PUT', data, { id: memberId });
        
        // Update Mitgliedschaftszeiträume (nur Admin)
        if (result && isAdmin && currentMembershipDates.length > 0) {
            await saveMembershipDates(memberId);
        }
    } else {
        // Create
        result = await apiCall('members', 'POST', data);
        
        // Erstelle Mitgliedschaftszeiträume falls vorhanden
        if (result && result.id && isAdmin && currentMembershipDates.length > 0) {
            await saveMembershipDates(result.id);
        }
    }
    
    if (result) {
        closeMemberModal();

        // Cache invalidieren und neu laden
        //invalidateCache('members');
        showMemberSection(true);

        // Erfolgs-Toast
        showToast(
            memberId ? 'Mitglied wurde erfolgreich aktualisiert' : 'Mitglied wurde erfolgreich erstellt',
            'success'
        );
    }    
}

export async function deleteMember(memberId, name) {
    const confirmed = await showConfirm(
        `Mitglied "${name}" wirklich löschen?`,
        'Mitglied löschen'
    );
    
    if (confirmed) {
        const result = await apiCall('members', 'DELETE', null, { id: memberId });
        if (result) {

            // Cache invalidieren und neu laden
            //invalidateCache('members');            
            showMemberSection(true);

            showToast('Mitglied erfolgreich gelöscht', 'success');
        }
    }
}

// ============================================
// MEMBERSHIP DATES
// ============================================

async function loadMembershipDates(memberId) {
    // API muss erweitert werden für membership_dates
    const response = await apiCall('membership_dates', 'GET', null, { member_id: memberId });
    currentMembershipDates = response || [];
    renderMembershipDates();
}

function renderMembershipDates() {
    const container = document.getElementById('membershipDatesList');
    container.innerHTML = '';
    
    if (currentMembershipDates.length === 0) {
        container.innerHTML = '<p style="color: #7f8c8d;">Keine Zeiträume vorhanden</p>';
        return;
    }
    
    currentMembershipDates.forEach((period, index) => {
        const div = document.createElement('div');
        div.style.cssText = 'border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; border-radius: 5px;';
        div.innerHTML = `
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="date" value="${period.start_date}" 
                       onchange="updateMembershipDate(${index}, 'start_date', this.value)" 
                       style="flex: 1;">
                <span>bis</span>
                <input type="date" value="${period.end_date || ''}" 
                       onchange="updateMembershipDate(${index}, 'end_date', this.value)" 
                       style="flex: 1;" placeholder="laufend">
                <select onchange="updateMembershipDate(${index}, 'status', this.value)" 
                        style="flex: 1;">
                    <option value="active" ${period.status === 'active' ? 'selected' : ''}>Aktiv</option>
                    <option value="expired" ${period.status === 'inactive' ? 'selected' : ''}>Inaktiv</option>
                </select>
                <button type="button" class="action-btn btn-delete" 
                        onclick="removeMembershipDate(${index})">×</button>
            </div>
        `;
        container.appendChild(div);
    });
}

function addMembershipDate() {
    currentMembershipDates.push({
        membership_date_id: null,
        start_date: new Date().toISOString().split('T')[0],
        end_date: null,
        status: 'active'
    });
    renderMembershipDates();
}

function updateMembershipDate(index, field, value) {
    currentMembershipDates[index][field] = value || null;
}

async function removeMembershipDate(index) {
    const confirmed = await showConfirm(
        'Zeitraum wirklich entfernen?',
        'Zeitraum löschen'
    );

    if (confirmed) {
        currentMembershipDates.splice(index, 1);
        renderMembershipDates();
    }
}

async function saveMembershipDates(memberId) {
    // Hole bestehende Zeiträume
    const existing = await apiCall('membership_dates', 'GET', null, { member_id: memberId });
    const existingIds = existing ? existing.map(e => e.membership_date_id) : [];
    
    // Verarbeite alle aktuellen Zeiträume
    for (const period of currentMembershipDates) {
        if (period.membership_date_id) {
            // Update bestehender Zeitraum
            await apiCall('membership_dates', 'PUT', {
                start_date: period.start_date,
                end_date: period.end_date,
                status: period.status
            }, { id: period.membership_date_id });
            
            // Entferne aus existingIds Liste
            const index = existingIds.indexOf(period.membership_date_id);
            if (index > -1) {
                existingIds.splice(index, 1);
            }
        } else {
            // Neuer Zeitraum
            await apiCall('membership_dates', 'POST', {
                member_id: memberId,
                start_date: period.start_date,
                end_date: period.end_date,
                status: period.status
            });
        }
    }
    
    // Lösche Zeiträume die nicht mehr vorhanden sind
    for (const deletedId of existingIds) {
        await apiCall('membership_dates', 'DELETE', null, { id: deletedId });
    }
}

// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

window.openMemberModal = openMemberModal;
window.saveMember = saveMember;
window.closeMemberModal = () => document.getElementById('memberModal').classList.remove('active');
window.deleteMember = deleteMember;
window.addMembershipDate = addMembershipDate;
window.removeMembershipDate = removeMembershipDate;