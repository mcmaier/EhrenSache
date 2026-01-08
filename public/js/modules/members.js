import { apiCall, isAdminOrManager } from './api.js';
import { showToast, showConfirm, dataCache, isCacheValid, invalidateCache} from './ui.js';
import { loadUserData } from './users.js';
import { updateModalId } from './utils.js';
import { loadGroups } from './management.js';
import {debug} from '../app.js'
import { globalPaginationValue } from './settings.js';


// ============================================
// MEMBERS
// Reference:
// import {} from './members.js'
// ============================================

let currentMembersPage = 1;
let membersPerPage = 25;
let allFilteredMembers = [];

// ============================================
// DATA FUNCTIONS (Cache, API-Calls)
// ============================================

let currentMembershipDates = [];
let currentMemberGroups = [];

export async function loadMembers(forceReload = false) {
    // Cache verwenden wenn vorhanden und nicht forceReload    
    if (!forceReload && isCacheValid('members')) {
        debug.log("Loading MEMBERS from CACHE");
        return dataCache.members.data;
    }

    // Userprofil abfragen (falls nicht gecacht)
    await loadUserData();

    // Mitglieder laden basierend auf gecachten userDetails
    const { userDetails } = dataCache.userData.data;

    let members = [];

    debug.log("Loading MEMBERS from API");
            
    if(isAdminOrManager){
        // Admin sieht alle Mitglieder
        members = await apiCall('members');    
        
        // GROUP_CONCAT Strings zu Arrays konvertieren
        members.forEach(member => {
            if (member.group_ids && typeof member.group_ids === 'string') {
                member.group_ids_array = member.group_ids
                    .split(',')
                    .map(id => parseInt(id.trim()));
            } else {
                member.group_ids_array = [];
            }
        });
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

    return members;
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

function renderMembers(members, page = 1) {
    debug.log("Render Members()");

    const tbody = document.getElementById('membersTableBody');
    tbody.innerHTML = '';

    if (!members || members.length === 0) {
        
        tbody.innerHTML = '<tr><td colspan="7" class="loading">Kein Profil verknüpft</td></tr>';
        updateMemberStats(members);
        return;
    }
    
    // Alle Members speichern für Pagination
    allFilteredMembers = members;
    currentMembersPage = page;

    membersPerPage = globalPaginationValue;

    updateMemberStats(members);

    // Pagination berechnen
    const totalMembers = members.length;
    const totalPages = Math.ceil(totalMembers / membersPerPage);
    const startIndex = (page - 1) * membersPerPage;
    const endIndex = startIndex + membersPerPage;
    const pageMembers = members.slice(startIndex, endIndex);


    debug.log(`Rendering page ${page}/${totalPages} (${pageMembers.length} of ${totalMembers} members)`);

    // DocumentFragment für Performance
    const fragment = document.createDocumentFragment();
    
    pageMembers.forEach(member => {  
        const tr = document.createElement('tr');
        
        // Gruppen-Badges erstellen
        const groupBadges = member.group_names 
            ? member.group_names.split(', ').map(name => 
                `<span class="type-badge" style="margin: 2px;">${name}</span>`
              ).join('')
            : '<span style="color: #7f8c8d;">Keine</span>';

        const actionsHtml = isAdminOrManager ? `
            <td class="actions-cell">
                      <button class="action-btn btn-icon btn-edit" onclick="openMemberModal(${member.member_id})" title="Bearbeiten">
                    ✎
                </button>
                <button class="action-btn btn-icon btn-delete" onclick="deleteMember(${member.member_id}, '${member.name} ${member.surname}')" title="Löschen">
                    🗑
                </button>
            </td>
        ` : '';

        tr.innerHTML = `
                <td>${member.surname}</td>
                <td>${member.name}</td>
                <td>${member.member_number || '-'}</td>
                <td>${groupBadges}</td>
                <td>${member.active ? 'Aktiv' : 'Inaktiv'}</td>
                ${actionsHtml}
                `;

              fragment.appendChild(tr);
    });
    
    tbody.innerHTML = '';
    tbody.appendChild(fragment);
    
    // Pagination Controls
    renderMembersPagination(page, totalPages, totalMembers);        
}


function renderMembersPagination(currentPage, totalPages, totalMembers) {
    const container = document.getElementById('membersPagination');
    if (!container) return;
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const startMember = (currentPage - 1) * membersPerPage + 1;
    const endMember = Math.min(currentPage * membersPerPage, totalMembers);
    
    let html = `
        <div class="pagination-container">
            <div class="pagination-info">
                Zeige ${startMember} - ${endMember} von ${totalMembers} Einträgen
            </div>
            <div class="pagination-buttons">
    `;
    

    if (totalPages <= 5) {
        // Wenige Seiten (≤5): Alle Seitenzahlen ohne Pfeile
        for (let i = 1; i <= totalPages; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToMembersPage(${i})">${i}</button>`;
        }
    } 
    else
    {
        // Erste Seite Button
        if (currentPage > 1) {
            //html += `<button onclick="goToMembersPage(1)" title="Erste Seite">
            //            «
            //        </button>`;
            html += `<button onclick="goToMembersPage(${currentPage - 1})" title="Vorherige Seite">
                        ‹
                    </button>`;
        }
        
        // Seitenzahlen (max 5 anzeigen)
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            html += `<button onclick="goToMembersPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToMembersPage(${i})">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
            html += `<button onclick="goToMembersPage(${totalPages})">${totalPages}</button>`;
        }
        
        // Letzte Seite Button
        if (currentPage < totalPages) {
            html += `<button onclick="goToMembersPage(${currentPage + 1})" title="Nächste Seite">
                        ›
                    </button>`;
            //html += `<button onclick="goToMembersPage(${totalPages})" title="Letzte Seite">
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
window.goToMembersPage = function(page) {

    // Aktuelle Scroll-Position der Tabelle speichern
    const tableContainer = document.querySelector('.data-table')?.parentElement;
    const scrollBefore = tableContainer?.scrollTop || 0;

    renderMembers(allFilteredMembers, page);
    
    // Scroll nach oben zur Tabelle
    //document.getElementById('membersTableBody').scrollIntoView({ behavior: 'smooth', block: 'start' });

    // KEIN automatisches Scrollen - Position beibehalten
    // ODER: Sanft zur Tabelle scrollen
    if (scrollBefore === 0) {
        // Nur scrollen wenn User nicht gescrollt hat
        const paginationElement = document.getElementById('membersPagination');
        if (paginationElement) {
            paginationElement.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'nearest' 
            });
        }
    }
};

function updateMemberStats(members)
{
// Statistik nur für Admin
    if (isAdminOrManager) {
        const activeCount = members.filter(m => m.active).length;
        document.getElementById('statActiveMembersCount').textContent = activeCount;
    } else {
        document.getElementById('statActiveMembersCount').textContent = '-';
    }   
}

export async function showMemberSection(forceReload = false, page = 1) {

    debug.log("Show Member Section ()");    

    const allMembers = await loadMembers(forceReload);

    const currentSection = sessionStorage.getItem('currentSection');
    if(currentSection === 'mitglieder')
    {
        renderMembers(allMembers, page);
    }
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

        if (isAdminOrManager) {
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
        if (result && isAdminOrManager && currentMembershipDates.length > 0) {
            await saveMembershipDates(memberId);
        }
    } else {
        // Create
        result = await apiCall('members', 'POST', data);
        
        // Erstelle Mitgliedschaftszeiträume falls vorhanden
        if (result && result.id && isAdminOrManager && currentMembershipDates.length > 0) {
            await saveMembershipDates(result.id);
        }
    }
    
    if (result) {
        closeMemberModal();

        // Cache invalidieren und neu laden
        //invalidateCache('members');
        showMemberSection(true, currentMembersPage);

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
            showMemberSection(true, currentMembersPage);

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

    debug.log("Getting membership dates:", response);
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
                    <option value="inactive" ${period.status === 'inactive' ? 'selected' : ''}>Inaktiv</option>
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

    debug.log("Current Membership Dates: ", currentMembershipDates);

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
window.updateMembershipDate = updateMembershipDate;