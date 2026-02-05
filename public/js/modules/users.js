/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { apiCall, currentUser, isAdmin } from './api.js';
import { loadMembers } from './members.js';
import { showToast, showConfirm, dataCache, isCacheValid} from './ui.js';
import { updateModalId, escapeHtml } from './utils.js';
import {debug} from '../app.js'

// ============================================
// USERS
// Reference:
// import {} from './users.js'
// ============================================

let currentUsersPage = 1;
const usersPerPage = 25;
let allFilteredUsers = [];
let currentUserStatusFilter = 'all'; 

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadUsers(forceReload = false) {

    if(!isAdmin)
    {
        return;
    }

    if (!forceReload && isCacheValid('users')) {
        debug.log("Loading USERS from CACHE");        
        return dataCache.users.data;
    }

    debug.log("Loading USERS from API");
    const users = await apiCall('users','GET',null,{user_type:'human'});
    
    // Cache speichern
    dataCache.users.data = users;
    dataCache.users.timestamp = Date.now();
    
    return users;    
}

export async function loadUserData(forceReload = false) {

    if(!forceReload && isCacheValid('userData'))
    {
        debug.log("Loading USER DETAILS (ME) from CACHE");
        return;
    }

    debug.log("Loading USER DETAILS (ME) from API");
    const userData = await apiCall('me');
    const userDetails = await apiCall('users', 'GET', null, { id: userData.user_id });
        
    // userData Cache separat speichern
    dataCache.userData.data = { userData, userDetails };
    dataCache.userData.timestamp = Date.now();
}

// ============================================
// RENDER FUNCTIONS (DOM-Manipulation)
// ============================================

function renderUsers(users, page = 1)
{
    debug.log("Render Users()");

    if(!isAdmin)
        return;

    const tbody = document.getElementById('usersTableBody');
    tbody.innerHTML = '';

    updateUserStats(users);

    // Alle Users speichern für Pagination
    allFilteredUsers = users;
    currentUsersPage = page;

    // Pagination berechnen
    const totalUsers = users.length;
    const totalPages = Math.ceil(totalUsers / usersPerPage);
    const startIndex = (page - 1) * usersPerPage;
    const endIndex = startIndex + usersPerPage;
    const pageUsers = users.slice(startIndex, endIndex);

    debug.log(`Rendering page ${page}/${totalPages} (${pageUsers.length} of ${totalUsers} users)`);
    
    // DocumentFragment für Performance
    const fragment = document.createDocumentFragment();
    
    pageUsers.forEach(user => {
    const tr = document.createElement('tr');

    const createdAt = new Date(user.created_at);
    const formattedCreated = createdAt.toLocaleDateString('de-DE');

    // Rollentext mit Device-Type
    let roleText = user.role === 'admin' ? 'Admin' : 
                    user.role === 'manager' ? 'Manager' :
                    'Benutzer';   
                    
    // Status-Badge
    let statusBadge = '';
    let memberInfo = '';

     // User-Info mit verknüpftem Mitglied       
    let userInfo = `<div style="line-height: 1.3;">${user.email}`;    
    if (user.user_name) {
       // userInfo += `<br><small><style="color: #7f8c8d;">${user.user_name}</small>`;
        userInfo += `<br><div class="linked-member">${escapeHtml(user.user_name)}</div>`;
    }    
    userInfo += '</div>';      

    if (user.account_status === 'pending' && !user.email_verified) {
        statusBadge = '<span class="status-badge email-pending">📧 Email-Bestätigung</span>';
        
    } else if (user.account_status === 'pending' && user.email_verified && !user.pending_member_id) {
        statusBadge = '<span class="status-badge pending">⏳ Member-Verknüpfung fehlt</span>';
        
    } else if (user.account_status === 'pending' && user.email_verified && user.pending_member_id) {
        statusBadge = '<span class="status-badge pending">⏳ Bereit zur Aktivierung</span>';
            
        // Member-Number kann null sein
        const memberNumber = user.pending_member_number 
            ? ` (${user.pending_member_number})` 
            : '';
        memberInfo = `<div class="linked-member">→ wird verknüpft: ${escapeHtml(user.pending_member_surname)}, ${escapeHtml(user.pending_member_name)} ${memberNumber}</div>`;
        
    } else if (user.account_status === 'active') {
        statusBadge = '<span class="status-badge active">✓ Aktiv</span>';
        if (user.member_id) {
            const memberNumber = user.member_number 
                ? ` (${user.member_number})` 
                : '';

            memberInfo = `<div class="linked-member">→ ${escapeHtml(user.member_surname)}, ${escapeHtml(user.member_name)} ${memberNumber}</div>`;
        }        
    } else if (user.account_status === 'suspended') {
        statusBadge = '<span class="status-badge suspended">🚫 Gesperrt</span>';
        if (user.member_id) {
            const memberNumber = user.member_number 
                ? ` (${user.member_number})` 
                : '';
            memberInfo = `<div class="linked-member">→ ${escapeHtml(user.member_surname)}, ${escapeHtml(user.member_name)}${memberNumber}</div>`;
        }
    }
    
    // Lösch-Button nicht für den eigenen Account anzeigen
    const deleteBtn = (currentUser && user.user_id !== currentUser.user_id) ? `
        <button class="action-btn btn-icon btn-delete" onclick="deleteUser(${user.user_id}, '${user.email}')">
            🗑
        </button>
    ` : '';
    
        tr.innerHTML = `
            <td>${userInfo}</td>
            <td><span class="type-badge">${roleText}</span></td>
            <td> ${statusBadge} ${memberInfo}</td>
            <td>${formattedCreated}</td>
            <td class="actions-cell">
                <button class="action-btn btn-icon btn-edit" onclick="openUserModal(${user.user_id})">
                    ✎
                </button>
                ${deleteBtn}
            </td> `;                     
            fragment.appendChild(tr);
        });
        
    tbody.innerHTML = '';
    tbody.appendChild(fragment);
    
    // Pagination Controls
    renderUsersPagination(page, totalPages, totalUsers); 
}


function renderUsersPagination(currentPage, totalPages, totalUsers) {
    const container = document.getElementById('usersPagination');
    if (!container) return;
    
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const startUser = (currentPage - 1) * usersPerPage + 1;
    const endUser = Math.min(currentPage * usersPerPage, totalUsers);
    
    let html = `
        <div class="pagination-container">
            <div class="pagination-info">
                Zeige ${startUser} - ${endUser} von ${totalUsers} Einträgen
            </div>
            <div class="pagination-buttons">
    `;

    
    if (totalPages <= 5) {
        // Wenige Seiten (≤5): Alle Seitenzahlen ohne Pfeile
        for (let i = 1; i <= totalPages; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToUsersPage(${i})">${i}</button>`;
        }
    } 
    else {

        // Erste Seite Button
        if (currentPage > 1) {
            //html += `<button onclick="goToUsersPage(1)" title="Erste Seite">
            //            «
            //        </button>`;
            html += `<button onclick="goToUsersPage(${currentPage - 1})" title="Vorherige Seite">
                        ‹
                    </button>`;
        }
        
        // Seitenzahlen (max 5 anzeigen)
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        
        if (startPage > 1) {
            html += `<button onclick="goToUsersPage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'active' : '';
            html += `<button class="${activeClass}" onclick="goToUsersPage(${i})">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="pagination-ellipsis">...</span>`;
            }
            html += `<button onclick="goToUsersPage(${totalPages})">${totalPages}</button>`;
        }
        
        // Letzte Seite Button
        if (currentPage < totalPages) {
            html += `<button onclick="goToUsersPage(${currentPage + 1})" title="Nächste Seite">
                        ›
                    </button>`;
            //html += `<button onclick="goToUsersPage(${totalPages})" title="Letzte Seite">
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

function updateUserStats(users) {
    const totalUsers = users.length;
    const activeUsers = users.filter(u => u.account_status === 'active').length;    
    const pendingUsers = users.filter(u => u.account_status === 'pending').length;      
    const suspendedUsers = users.filter(u => u.account_status === 'suspended').length;     
        
    document.getElementById('count-all').textContent = totalUsers || 0;           
    document.getElementById('count-pending').textContent = pendingUsers || 0;   
    document.getElementById('count-active').textContent = activeUsers || 0;     
    document.getElementById('count-suspended').textContent = suspendedUsers || 0;     
    
}

// Global für onclick
window.goToUsersPage = function(page) {

    // Aktuelle Scroll-Position der Tabelle speichern
    const tableContainer = document.querySelector('.data-table')?.parentElement;
    const scrollBefore = tableContainer?.scrollTop || 0;

    renderUsers(allFilteredUsers, page);
    
    // KEIN automatisches Scrollen - Position beibehalten
    // ODER: Sanft zur Tabelle scrollen
    if (scrollBefore === 0) {
        // Nur scrollen wenn User nicht gescrollt hat
        const paginationElement = document.getElementById('usersPagination');
        if (paginationElement) {
            paginationElement.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'nearest' 
            });
        }
    }
}

export async function showUserSection(forceReload = false, page = 1)
{
    debug.log("Show User Section ()");

    applyUserFilters(forceReload, page);
}

// ============================================
// FILTER FUNCTIONS
// ============================================

window.setUserStatusFilter = function(status) {
    currentUserStatusFilter = status;
    
    // Button-Status aktualisieren
    document.querySelectorAll('.status-filter-group .filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelector(`[data-status="${status}"]`).classList.add('active');
    
    applyUserFilters();
};


export async function applyUserFilters(forceReload = false, page = 1) {
    debug.log('applyUserFilters called');
    
    // Users laden (aus Cache wenn möglich)
    const allUsers = await loadUsers(forceReload);
    debug.log('Loaded users:', allUsers.length);
    
    // Aktuelle Filter auslesen
    const filters = {
        role: document.getElementById('userRoleFilter')?.value || null,
        status: currentUserStatusFilter !== 'all' ? currentUserStatusFilter : null
    };
    debug.log('Active filters:', filters);
    
    // Filtern
    const filteredUsers = filterUsers(allUsers, filters);
    
    // Rendern (nur wenn auf User-Section) - Reset auf Seite 1
    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'benutzer') {
        renderUsers(filteredUsers, page);
        debug.log('Users rendered');
    }
    
    return filteredUsers;
}

export function filterUsers(users, filters = {}) {
    debug.log("filterUsers() called with filters:", filters);
    
    if (!users || users.length === 0) return [];
    
    let filtered = [...users];
    
    // Filter: Rolle
    if (filters.role && filters.role !== '') {
        filtered = filtered.filter(u => u.role === filters.role);
        debug.log(`After role filter (${filters.role}):`, filtered.length);
    }
    
    // Filter: Status 
    if (filters.status && filters.status !== '') {
        filtered = filtered.filter(u => u.account_status === filters.status);
        debug.log(`After status filter (${filters.status}):`, filtered.length);
    }
    
    debug.log(`Final filtered users:`, filtered.length);
    return filtered;
}

export async function initUsersEventHandlers()
{
    debug.log("Trying to register User Event Handler. IsAdmin?", isAdmin);
    if (!isAdmin) return;

        // Filter-Änderungen
        document.getElementById('userRoleFilter')?.addEventListener('change', () => {
            applyUserFilters();
        });
        
        // Reset-Button
        document.getElementById('btnResetUserFilters')?.addEventListener('click', () => {
            // Status-Filter zurücksetzen
            currentUserStatusFilter = 'all';
            document.querySelectorAll('.status-filter-group .filter-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector('[data-status="all"]').classList.add('active');
            
            // Rolle zurücksetzen
            document.getElementById('userRoleFilter').value = '';
            
            applyUserFilters();
        });
            

    // Users laden und anzeigen
    await applyUserFilters();
}

// ============================================
// USER MODAL FUNCTIONS
// ============================================

export async function openUserModal(userId = null) {
    const modal = document.getElementById('userModal');
    const title = document.getElementById('userModalTitle');
    const statusSection = document.getElementById('statusSection');
    const userMemberGroup = document.getElementById('userMemberGroup');
    
    // Lade Mitglieder für Dropdown

    const members = await loadMembers(true);    

    const memberSelect = document.getElementById('user_member');
    memberSelect.innerHTML = '<option value="">Kein Mitglied</option>';
    
    if (members) {
        members.forEach(member => {
            memberSelect.innerHTML += `<option value="${member.member_id}">${member.surname}, ${member.name}</option>`;
        });
    }

    if (userId) {
        title.textContent = 'Benutzer bearbeiten';
        await loadUserFormData(userId);
        document.getElementById('user_password').required = false;        

        //Status-Section anzeigen (nur beim Bearbeiten)
        statusSection.style.display = 'block';
        // Member-Dropdown verstecken (wird in Status-Section angezeigt)
        userMemberGroup.style.display = 'none';

        updateModalId('userModal', userId);
        
        if(userId === currentUser.user_id)
        {
            document.getElementById('user_role').disabled = true;
        }
        else
        {
            document.getElementById('user_role').disabled = false;
        }

    } else {
        title.textContent = 'Neuer Benutzer';
        document.getElementById('userForm').reset();
        document.getElementById('user_id').value = '';
        //document.getElementById('user_active').checked = true;
        document.getElementById('user_password').required = true;

        updateModalId('userModal', null);
        statusSection.style.display = 'none';
                
        // Member-Dropdown anzeigen (bei Neu-Erstellung)
        userMemberGroup.style.display = 'block';
        
        // Token-Gruppe verstecken und zurücksetzen
        resetTokenDisplay();
    }
    modal.classList.add('active');
}

export function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
    document.getElementById('userForm').reset();
    
    // Edit-Modus zurücksetzen
    document.getElementById('memberDisplayMode').style.display = 'block';
    document.getElementById('memberEditMode').style.display = 'none';
    
    // Sections verstecken
    document.getElementById('statusSection').style.display = 'none';
    document.getElementById('memberLinkSection').style.display = 'none';
    document.getElementById('activationGroup').style.display = 'none';
}

function updateStatusSection(user) {    
    const statusInfo = document.getElementById('currentStatus');
    const emailVerificationInfo = document.getElementById('emailVerificationInfo');
    const emailVerificationStatus = document.getElementById('emailVerificationStatus');
    const activationGroup = document.getElementById('activationGroup');
    const memberLinkSection = document.getElementById('memberLinkSection');
    const userMemberGroup = document.getElementById('userMemberGroup');

    // Action Buttons
    const activateBtn = document.getElementById('activateBtn');
    const suspendBtn = document.getElementById('suspendBtn');
    const reactivateBtn = document.getElementById('reactivateBtn');
    const resendVerificationBtn = document.getElementById('resendVerificationBtn');

    // Alle Gruppen zurücksetzen
    emailVerificationInfo.style.display = 'none';
    activationGroup.style.display = 'none';
    memberLinkSection.style.display = 'none';
    userMemberGroup.style.display = 'none';

    // Buttons verstecken
    activateBtn.style.display = 'none';
    suspendBtn.style.display = 'none';
    reactivateBtn.style.display = 'none';
    resendVerificationBtn.style.display = 'none';

    // Event-Listener entfernen (um Duplikate zu vermeiden)
    activateBtn.replaceWith(activateBtn.cloneNode(true));
    suspendBtn.replaceWith(suspendBtn.cloneNode(true));
    reactivateBtn.replaceWith(reactivateBtn.cloneNode(true));
    resendVerificationBtn.replaceWith(resendVerificationBtn.cloneNode(true)); // NEU
    
    // Neu referenzieren nach replaceWith
    const newActivateBtn = document.getElementById('activateBtn');
    const newSuspendBtn = document.getElementById('suspendBtn');
    const newReactivateBtn = document.getElementById('reactivateBtn');
    const newResendBtn = document.getElementById('resendVerificationBtn'); // NEU
    
    // Status-Box Styling
    statusInfo.className = 'status-info ' + user.account_status;

    if(user.user_id === currentUser.user_id)
    {
        statusInfo.innerHTML = '<strong>✓ Aktiv</strong>';
        statusInfo.className = 'status-info active';                    
        memberLinkSection.style.display = 'block';
        displayMemberLink(user);
    }
    else
    {        
        // Status: Pending - Email NICHT bestätigt
        if (user.account_status === 'pending' && !user.email_verified) {
            // Warten auf Email-Bestätigung
            statusInfo.innerHTML = '<strong>📧 Email-Bestätigung ausstehend</strong>';
            statusInfo.className = 'status-info pending';
            
            // NEU: Button zum erneuten Versenden der Verifikations-Mail
            newResendBtn.style.display = 'inline-block';
            newResendBtn.addEventListener('click', () => resendVerificationEmail(user.user_id, user.email));
        
            
        } 
        // Status: Pending - Email bestätigt, aber keine Member-ID
        else if (user.account_status === 'pending' && user.email_verified && !user.pending_member_id) {
            statusInfo.innerHTML = '<strong>⏳ Member-Verknüpfung fehlt</strong><br><small>Email bestätigt, aber noch kein Mitglied zugeordnet</small>';
            statusInfo.className = 'status-info pending';
            
            newActivateBtn.style.display = 'inline-block';
            activationGroup.style.display = 'block';
            
            newActivateBtn.addEventListener('click', () => activateUser(user.user_id));
            loadMembersForActivation(user.pending_member_id);
        }
        // Status: Pending - Email bestätigt UND Member-ID vorhanden
        else if (user.account_status === 'pending' && user.email_verified && user.pending_member_id) {
            statusInfo.innerHTML = '<strong>⏳ Bereit zur Aktivierung</strong><br><small>Email bestätigt und Mitglied zugeordnet</small>';
            statusInfo.className = 'status-info pending';
            
            newActivateBtn.style.display = 'inline-block';
            activationGroup.style.display = 'block';
            
            newActivateBtn.addEventListener('click', () => activateUser(user.user_id));
            loadMembersForActivation(user.pending_member_id);
        }
        // Status: Aktiv
        else if (user.account_status === 'active') {
            statusInfo.innerHTML = '<strong>✓ Aktiv</strong>';
            statusInfo.className = 'status-info active';
            
            newSuspendBtn.style.display = 'inline-block';
            memberLinkSection.style.display = 'block';
            
            newSuspendBtn.addEventListener('click', () => suspendUser(user.user_id));
            displayMemberLink(user);
        }
        // Status: Gesperrt
        else if (user.account_status === 'suspended') {
            statusInfo.innerHTML = '<strong>🚫 Gesperrt</strong>';
            statusInfo.className = 'status-info suspended';
            
            newReactivateBtn.style.display = 'inline-block';
            memberLinkSection.style.display = 'block';
            
            newReactivateBtn.addEventListener('click', () => reactivateUser(user.user_id));
            displayMemberLink(user);
        }
    }
}

async function loadMembersForActivation(preselectedId = null) {
    const members = await loadMembers(true);
    const select = document.getElementById('linkMemberId');
    select.innerHTML = '<option value="">-- Mitglied auswählen --</option>';
    
    if (members) {
        members.forEach(member => {
            const option = document.createElement('option');
            option.value = member.member_id;
        
            const memberNumber = member.member_number 
                        ? `(${member.member_number}) - ` 
                        : '';            

            option.textContent = `${memberNumber}${member.surname}, ${member.name}`;
            if (member.member_id === preselectedId) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }
}

function displayMemberLink(user) {
    const linkedMemberInfo = document.getElementById('linkedMemberInfo');
    const memberLinkSection = document.getElementById('memberLinkSection');
    const editMemberId = document.getElementById('editMemberId');

    memberLinkSection.style.display = 'block';
    
    if (user.member_id) {
            const memberNumber = user.member_number 
                ? ` Mitgliedsnummer: ${user.member_number}` 
                : '';

        linkedMemberInfo.className = 'linked-member-info';
        linkedMemberInfo.innerHTML = `
            <strong>${escapeHtml(user.member_name)} ${escapeHtml(user.member_surname)}</strong>
            <small>${memberNumber}</small>
        `;
    } else {
        linkedMemberInfo.className = 'linked-member-info no-member';
        linkedMemberInfo.innerHTML = `Kein Mitglied verknüpft`;
    }
    
    // Edit-Modus zurücksetzen
    document.getElementById('memberEditMode').style.display = 'none';
    document.getElementById('memberDisplayMode').style.display = 'block';
    document.getElementById('editMemberBtn').style.display = 'inline-block';

    // Edit-Select füllen (für späteren Edit-Modus)
    loadMembersForEdit(user.member_id);
}


// ============================================
// MEMBER-VERKNÜPFUNG EDITIEREN
// ============================================

window.toggleMemberEdit = async function() {
    const displayMode = document.getElementById('memberDisplayMode');
    const editMode = document.getElementById('memberEditMode');
    //const editBtn = document.getElementById('editMemberBtn');    
    //const linkedInfo = document.getElementById('linkedMemberInfo');
    
    if (editMode.style.display === 'none') {
        // Edit-Modus aktivieren
        displayMode.style.display = 'none';
        editMode.style.display = 'block';
        
        // Aktuellen Wert in Edit-Select übernehmen
        const currentMemberId = document.getElementById('currentMemberId')?.value || '';
        const editSelect = document.getElementById('editMemberId');
        editSelect.value = currentMemberId;
        
    } else {
        // Edit-Modus schließen (ohne Speichern)
        displayMode.style.display = 'block';
        editMode.style.display = 'none';
    }
}



async function loadMembersForEdit(currentMemberId = null) {

    try {
        const members = await apiCall('members', 'GET');
        const editSelect = document.getElementById('editMemberId');
        
        editSelect.innerHTML = '<option value="">Kein Mitglied</option>';
        
        members.forEach(member => {
            const option = document.createElement('option');

            const memberNumber = member.member_number 
                ? ` (${member.member_number})` 
                : '';

            option.value = member.member_id;
            option.textContent = `${member.surname}, ${member.name}${memberNumber}`;
            
            if (currentMemberId && member.member_id === currentMemberId) {
                option.selected = true;
            }
            
            editSelect.appendChild(option);
        });
        
    } catch (error) {
        console.error('Fehler beim Laden der Mitglieder:', error);
    }
}

window.saveMemberLink = async function() {
    const userId = document.getElementById('user_id').value;
    const memberId = document.getElementById('editMemberId').value;

    const confirmed = await showConfirm(
        `Mitgliedsverknüpfung ändern?`,
        'Änderung bestätigen'
    );

    if(!confirmed)
    {
        return;
    }
    
    try {
        const response = await apiCall('users', 'PUT', {
            member_id: memberId ? parseInt(memberId) : null
        }, { id: userId });
        
        if (response.success || response.message === 'User updated') {
            showToast('Verknüpfung aktualisiert', 'success');
            
            // Modal neu laden
            await loadUserFormData(userId);
            cancelMemberEdit();
        } else {
            showToast('Fehler: ' + response.message, 'error');
        }
    } catch (error) {
        showToast('Fehler beim Speichern: ' + error.message, 'error');
    }
};

// ============================================
// CRUD FUNCTIONS
// ============================================

export async function loadUserFormData(userId) {
    const user = await apiCall('users', 'GET', null, { id: userId });
    
    if (!user) {
        showToast('User nicht gefunden', 'error');
        return;
    }

    debug.log('User loaded from API:', user);

    document.getElementById('user_id').value = user.user_id;
    document.getElementById('user_email').value = user.email;
    document.getElementById('user_name').value = user.user_name || '';
    document.getElementById('user_role').value = user.role;
    //document.getElementById('user_member').value = user.member_id || '';
    //document.getElementById('user_active').checked = user.is_active;
    document.getElementById('user_password').value = '';

    if(userId === currentUser.user_id)
    {
        document.getElementById('user_role').disabled = true;
    }
    else
    {
        document.getElementById('user_role').disabled = false;
    }

    // Hidden Field für aktuelle Member-ID
    let currentMemberIdField = document.getElementById('currentMemberId');
    if (!currentMemberIdField) {
        currentMemberIdField = document.createElement('input');
        currentMemberIdField.type = 'hidden';
        currentMemberIdField.id = 'currentMemberId';
        document.getElementById('userForm').appendChild(currentMemberIdField);
    }
    currentMemberIdField.value = user.member_id || '';

    updateStatusSection(user);

    // Member-Info anzeigen (wenn aktiv/gesperrt)
    if (user.account_status === 'active' || user.account_status === 'suspended') {
        displayMemberLink(user);
    }

    // Token-Anzeige aktualisieren
    if (user.api_token) {
        updateTokenDisplay(user.api_token, user.api_token_expires_at);
    } else {
        resetTokenDisplay();
    }
      
}

export async function saveUser() {
    // Form-Validierung prüfen
    const form = document.getElementById('userForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const userId = document.getElementById('user_id').value;
    const password = document.getElementById('user_password').value;
    const role = document.getElementById('user_role').value;
    const name = document.getElementById('user_name').value;
    
    const userData = {
        email: document.getElementById('user_email').value,
        role: role,
        name: name
        //is_active: document.getElementById('user_active').checked
    };
    
    // Passwort nur mitschicken wenn gesetzt
    if (password) { 
        userData.password = password;
    }

    // Member-ID: Edit-Modus hat Vorrang, sonst aktueller Wert
    const editMemberId = document.getElementById('editMemberId');
    const currentMemberId = document.getElementById('currentMemberId');

    if (editMemberId.parentElement.style.display !== 'none') {
        // Edit-Modus aktiv → Wert aus Edit-Select
        const memberId = editMemberId.value;
        userData.member_id = memberId ? parseInt(memberId) : null;
    } else if (currentMemberId) {
        // Kein Edit-Modus → Aktuellen Wert beibehalten
        const memberId = currentMemberId.value;
        if (memberId) {
            userData.member_id = parseInt(memberId);
        }
    }

    debug.log('Saving User:', userData);
    
    let result;
    if (userId) {
        result = await apiCall('users', 'PUT', userData, { id: userId });

    } else {
        if (!password) {
            alert('Passwort ist erforderlich für neue Benutzer');
            return;
        }

        userData.password = password;
        
        result = await apiCall('users', 'POST', userData); 
    }
    
    if (result.success) {
        closeUserModal();
        showUserSection(true, currentUsersPage);

        showToast(
            userId ? 'Benutzer wurde erfolgreich aktualisiert' : 'Benutzer wurde erfolgreich erstellt',
            'success'
        );                      
    }
}

export async function deleteUser(userId, email) {
    const confirmed = await showConfirm(
        `Benutzer "${email}" wirklich löschen?`,
        'Benutzer löschen'
    );

    if (confirmed) {
        const result = await apiCall('users', 'DELETE', null, { id: userId });
        if (result) {
            showUserSection(true, currentUsersPage);
            showToast(`User "${email}" wurde gelöscht`, 'success');        
        }
    }
}

// ============================================
// TOKEN MANAGEMENT
// ============================================

function updateTokenDisplay(token, expiresAt) {
    const tokenGroup = document.getElementById('userTokenGroup');
    const tokenInput = document.getElementById('user_token');
    const expiryInfo = document.getElementById('tokenExpiryInfo');
    
    tokenGroup.style.display = 'block';
    tokenInput.value = token;
    tokenInput.type = 'password';
    document.getElementById('toggleUserTokenBtn').textContent = '👁️';
    
    if (expiresAt) {
        const expiresDate = new Date(expiresAt);
        const now = new Date();
        const isExpired = now > expiresDate;
        
        const expiresText = expiresDate.toLocaleDateString('de-DE', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
        
        expiryInfo.innerHTML = isExpired 
            ? `<span style="color: #e74c3c;">⚠️ Abgelaufen am: ${expiresText}</span>`
            : `<span style="color: var(--text-dark);">Gültig bis: ${expiresText}</span>`;
        expiryInfo.style.display = 'block';
    } else if (token) {
        expiryInfo.innerHTML = '<span style="color: var(--text-dark);">Kein Ablaufdatum</span>';
        expiryInfo.style.display = 'block';
    }
}

function resetTokenDisplay() {
    const tokenGroup = document.getElementById('userTokenGroup');
    const tokenInput = document.getElementById('user_token');
    const expiryInfo = document.getElementById('tokenExpiryInfo');
    
    tokenGroup.style.display = 'none';
    tokenInput.value = '';
    tokenInput.type = 'password';
    document.getElementById('toggleUserTokenBtn').textContent = '👁️';
    expiryInfo.style.display = 'none';
    expiryInfo.innerHTML = '';
}

export function copyUserToken() {
    const tokenInput = document.getElementById('user_token');
    if (tokenInput) {
        tokenInput.select();
        tokenInput.setSelectionRange(0, 99999);
        
        navigator.clipboard.writeText(tokenInput.value).then(() => {
            showToast('Token in Zwischenablage kopiert!', 'success');
        }).catch(() => {
            document.execCommand('copy');
            showToast('Token kopiert!', 'success');
        });
    }
}

export function toggleUserTokenVisibility() {
    const tokenInput = document.getElementById('user_token');
    const toggleBtn = document.getElementById('toggleUserTokenBtn');
    
    if (tokenInput.type === 'password') {
        tokenInput.type = 'text';
        toggleBtn.textContent = '🙈'; // Auge durchgestrichen
    } else {
        tokenInput.type = 'password';
        toggleBtn.textContent = '👁️'; // Auge offen
    }
}

export async function regenerateUserToken() {
    const userId = document.getElementById('user_id').value;
    if (!userId) return;
    
    const confirmed = await showConfirm(
        'Token wirklich neu generieren? Der alte Token wird ungültig!',
        'Token neu generieren'
    );
    
    if (confirmed) {
        // Mit user_id → Token für anderen User
        const result = await apiCall('regenerate_token', 'POST', { 
            user_id: parseInt(userId)
        });
        
        if (result && result.api_token) {
            updateTokenDisplay(result.api_token, result.expires_at);            
            showToast('Neuer Token generiert!', 'success');
        }
    }
}

// ============================================
// AKTIVIERUNGS-FUNKTIONEN
// ============================================

async function resendVerificationEmail(userId, email) {
    const confirmed = await showConfirm(
        `Verifikations-Email erneut an ${email} senden?`,
        'Email erneut senden'
    );
    
    if (!confirmed) {
        return;
    }
    
    try {
        const response = await apiCall('users', 'POST',{
            action: 'resend_verification',
            mail_user_id: userId
        });
        
        if (response.success) {
            showToast('Verifikations-Email wurde erneut versendet', 'success');
        } else {
            showToast('Fehler: ' + response.message, 'error');
        }
        
    } catch (error) {
        console.error('Resend verification error:', error);
        showToast('Fehler beim Versenden: ' + error.message, 'error');
    }
}

async function activateUser(userId) {    
    const memberId = document.getElementById('linkMemberId')?.value;
    
    if (!memberId) {
        showToast('Bitte wählen Sie ein Mitglied aus', 'error');
        return;
    }
    
    const confirmed = await showConfirm(
        `User aktivieren und mit Mitglied verknüpfen?`,
        'Benutzer aktivieren'
    );

    if(!confirmed)
    {
        return;
    }
    
    try {
        const response = await apiCall('activate_user', 'POST', {
            user_id: userId,
            member_id: parseInt(memberId)
        });
        
        if (response.success) {
            closeUserModal();
            showToast('User erfolgreich aktiviert', 'success');            
            showUserSection(true,currentUsersPage);       
        } else {
            showToast('Fehler: ' + response.message, 'error');
        }
    } catch (error) {
        showToast('Fehler beim Aktivieren: ' + error.message, 'error');
    }
};

async function suspendUser(userId)  {
    const confirmed = await showConfirm(
        `Benutzer sperren? Der User kann sich dann nicht mehr einloggen.`,
        'Benutzer sperren'
    );

    if(!confirmed)
    {
        return;
    }    
    
    try {
        const response = await apiCall('user_status', 'POST', {
            user_id: userId,
            status: 'suspended'
        });
        
        if (response.success) {
            closeUserModal();
            showToast('User gesperrt', 'success');            
            showUserSection(true,currentUsersPage);
        } else {
            showToast('Fehler: ' + response.message, 'error');
        }
    } catch (error) {
        showToast('Fehler beim Sperren: ' + error.message, 'error');
    }
};

async function reactivateUser(userId) {    
    const confirmed = await showConfirm(
        `Sperrung wirklich aufheben?`,
        'Benutzer entsperren'
    );

    if(!confirmed)
    {
        return;
    }
    
    try {
        const response = await apiCall('user_status', 'POST', {
            user_id: userId,
            status: 'active'
        });
        
        if (response.success) {
            closeUserModal();
            showToast('Sperrung aufgehoben', 'success');            
            showUserSection(true,currentUsersPage);
        } else {
            showToast('Fehler: ' + response.message, 'error');
        }
    } catch (error) {
        showToast('Fehler beim Reaktivieren: ' + error.message, 'error');
    }
};

// ============================================
// GLOBAL EXPORTS (für onclick in HTML)
// ============================================

window.openUserModal = openUserModal;
window.saveUser = saveUser;
window.closeUserModal = closeUserModal;
window.deleteUser = deleteUser;
window.regenerateUserToken = regenerateUserToken;
window.copyUserToken = copyUserToken;
window.toggleUserTokenVisibility = toggleUserTokenVisibility;
window.applyUserFilters = applyUserFilters;

