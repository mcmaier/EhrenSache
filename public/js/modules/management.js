import { apiCall, isAdmin } from './api.js';
import { showToast, showConfirm, dataCache, isCacheValid,invalidateCache} from './ui.js';
import { updateModalId } from './utils.js';

// ============================================
// MANAGEMENT (Groups & Types)
// ============================================

//let allMembers = [];
//let allGroups = [];

// ============================================
// GROUPS - Data Loading
// ============================================

export async function loadGroups(forceReload = false) {
    // Cache-Check: Nur laden wenn nötig
    if (!forceReload && isCacheValid('groups')) {
        console.log('Loading groups from cache');
        renderGroups(dataCache.groups.data);
        return dataCache.groups.data;
    }

    console.log('Loading groups from API');
    const groups = await apiCall('member_groups');

    dataCache.groups.data = groups;
    dataCache.groups.timestamp = Date.now();

    renderGroups(dataCache.groups.data);
    return groups;        
}

function renderGroups(groupData)
{
const tbody = document.getElementById('groupsTableBody');
    tbody.innerHTML = '';
    
    groupData.forEach(group => {
        const isDefaultBadge = group.is_default 
            ? '<span class="status-badge status-approved">✓ Ja</span>' 
            : '<span class="type-badge">Nein</span>';

        // Mitgliederanzahl anzeigen
        const memberCount = group.member_count || 0;
        
        const row = `
            <tr>
                <td><strong>${group.group_name}</strong></td>
                <td>${group.description || '-'}</td>                
                <td>${memberCount}</td>
                <td>${isDefaultBadge}</td>
                <td>
                    <button class="action-btn btn-edit" onclick="openGroupModal(${group.group_id})">
                        Bearbeiten
                    </button>
                    <button class="action-btn btn-delete" onclick="deleteGroup(${group.group_id}, '${group.group_name}')">
                        Löschen
                    </button>
                </td>
            </tr>
        `;
        tbody.innerHTML += row;
    });
}

// ============================================
// GROUPS - Modal Functions
// ============================================

export async function openGroupModal(groupId = null) {
    const modal = document.getElementById('groupModal');
    const title = document.getElementById('groupModalTitle');
    const membersGroup = document.getElementById('groupMembersGroup');

    if(dataCache.members.length === 0)
    {
        await loadMembers(true);
    }
    
    // Lade alle Mitglieder falls nötig
    /*
    if (allMembers.length === 0) {
        allMembers = await apiCall('members') || [];
    }*/
    
    if (groupId) {
        title.textContent = 'Gruppe bearbeiten';
        await loadGroupData(groupId);
        membersGroup.style.display = 'block';
        updateModalId('groupModal', groupId)

    } else {
        title.textContent = 'Neue Gruppe';
        document.getElementById('groupForm').reset();
        document.getElementById('group_id').value = '';
        document.getElementById('group_is_default').checked = false;
        membersGroup.style.display = 'none';
        updateModalId('groupModal', null)
    }
    
    modal.classList.add('active');
}

export function closeGroupModal() {
    document.getElementById('groupModal').classList.remove('active');
}

async function loadGroupData(groupId) {
    const group = await apiCall('member_groups', 'GET', null, { id: groupId });
    
    if (group) {
        document.getElementById('group_id').value = group.group_id;
        document.getElementById('group_name').value = group.group_name;
        document.getElementById('group_description').value = group.description || '';
        document.getElementById('group_is_default').checked = group.is_default == 1;
        
        // Zeige Mitglieder in dieser Gruppe
        renderGroupMembers(group.members || []);
    }
}

function renderGroupMembers(members) {
    const container = document.getElementById('groupMembersList');
    
    if (members.length === 0) {
        container.innerHTML = '<p style="color: #7f8c8d;">Keine Mitglieder in dieser Gruppe</p>';
        return;
    }

    // Sortiere alphabetisch nach Nachname, dann Vorname
    const sortedMembers = [...members].sort((a, b) => {
        const surnameCompare = a.surname.localeCompare(b.surname, 'de');
        if (surnameCompare !== 0) return surnameCompare;
        return a.name.localeCompare(b.name, 'de');
    });
    
    container.innerHTML = sortedMembers.map(m => `
        <div style="padding: 5px 0; border-bottom: 1px solid #eee;">
            ${m.surname}, ${m.name} ${m.member_number ? `(${m.member_number})` : ''}
        </div>
    `).join('');
}

// ============================================
// GROUPS - CRUD
// ============================================

export async function saveGroup() {
    const form = document.getElementById('groupForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const groupId = document.getElementById('group_id').value;
    const isDefault = document.getElementById('group_is_default').checked;

     // Bei neuer Standard-Gruppe: Warnung wenn bereits eine existiert
    if (isDefault) {        
        // Lade aktuelle Gruppen falls Cache leer
        if(dataCache.groups.data.length === 0)
        {
            await loadGroups(true);
        }
        
        const groups = dataCache.groups.data;

        // Falls API-Response-Wrapper: {success: true, data: [...]}
        /*if (!Array.isArray(members) && members.data) {
            members = members.data;
        }*/

        // Finde aktuelle Standard-Gruppe (aber nicht die, die wir gerade bearbeiten)
        const currentDefault = groups.find(g => g.is_default && g.group_id != groupId);
        
        if (currentDefault) {
            const confirmed = await showConfirm(
                `Die Gruppe "${currentDefault.group_name}" ist aktuell Standard. Diese wird durch die neue Standard-Gruppe ersetzt.`,
                'Standard-Gruppe ändern'
            );
            if (!confirmed) return;
        }
    }

    const data = {
        group_name: document.getElementById('group_name').value,
        description: document.getElementById('group_description').value || null,
        is_default: isDefault
    };
    
    let result;
    if (groupId) {
        result = await apiCall('member_groups', 'PUT', data, { id: groupId });
    } else {
        result = await apiCall('member_groups', 'POST', data);
    }
    
    if (result) {
        closeGroupModal();
        invalidateCache('groups'); 
        await loadGroups(true);
        showToast(
            groupId ? 'Gruppe erfolgreich aktualisiert' : 'Gruppe erfolgreich erstellt',
            'success'
        );
    }
}

export async function deleteGroup(groupId, groupName) {
    const confirmed = await showConfirm(
        `Gruppe "${groupName}" wirklich löschen?`,
        'Gruppe löschen'
    );
    
    if (confirmed) {
        const result = await apiCall('member_groups', 'DELETE', null, { id: groupId });
        if (result) {
            invalidateCache('groups'); 
            await loadGroups(true);
            showToast(`Gruppe "${groupName}" wurde gelöscht`, 'success');
        }
    }
}

// ============================================
// TYPES - Data Loading
// ============================================

export async function loadTypes(forceReload = false) {
    // Cache-Check: Nur laden wenn nötig
    if (!forceReload && isCacheValid('types')) {
        console.log('Loading Appointment Types from cache',dataCache.types.data);
        renderTypeGroupOverview(dataCache.types.data);
        return dataCache.types.data;
    }
    
    const types = await apiCall('appointment_types');    
    console.log('Loading Appointment Types from API', types);

    dataCache.types.data = types;
    dataCache.types.timestamp = Date.now();
    
    renderTypeGroupOverview(dataCache.types.data);  
    return types;       
}

function renderTypeGroupOverview(typeData)
{
    const tbody = document.getElementById('typesTableBody');
    tbody.innerHTML = '';
    
    typeData.forEach(type => {
        const isDefaultBadge = type.is_default 
            ? '<span class="status-badge status-approved">✓ Ja</span>' 
            : '<span class="type-badge">Nein</span>';
        
        const colorBadge = `<span style="display: inline-block; width: 20px; height: 20px; background: ${type.color}; border-radius: 3px; border: 1px solid #ddd;"></span>`;
        
        // Lade Gruppen für diese Terminart
        const groupsText = '-'; // Wird später gefüllt
        
        const row = `
            <tr>
                <td><strong>${type.type_name}</strong></td>
                <td>${type.description || '-'}</td>
                <td>${colorBadge}</td>
                <td id="type_groups_${type.type_id}">Lädt...</td>
                <td>${isDefaultBadge}</td>
                <td>
                    <button class="action-btn btn-edit" onclick="openTypeModal(${type.type_id})">
                        Bearbeiten
                    </button>
                    <button class="action-btn btn-delete" onclick="deleteType(${type.type_id}, '${type.type_name}')">
                        Löschen
                    </button>
                </td>
            </tr>
        `;
        tbody.innerHTML += row;
        
        // Lade Gruppen asynchron
        loadTypeGroup(type.type_id);
    });
}

async function loadTypeGroup(typeId) {
    let type;

    if(dataCache.types.data.typeId === typeId)
    {
        type = dataCache.types.data[typeId];
    }
    else
    {
        type = await apiCall('appointment_types', 'GET', null, { id: typeId });
    }

    const cell = document.getElementById(`type_groups_${typeId}`);
    
    if (type && type.groups && type.groups.length > 0) {
        cell.innerHTML = type.groups.map(g => `<span class="type-badge">${g.group_name}</span>`).join(' ');
    } else {
        cell.innerHTML = '<span style="color: #7f8c8d;">Keine</span>';
    }
}

// ============================================
// TYPES - Modal Functions
// ============================================

export async function openTypeModal(typeId = null) {
    const modal = document.getElementById('typeModal');
    const title = document.getElementById('typeModalTitle');

    /*
    if(dataCache.groups.data.length === 0)
    {
        await apiCall('member_groups');
    } */
   await loadGroups();  
    
    if (typeId) {
        title.textContent = 'Terminart bearbeiten';
        await loadTypeData(typeId);
        updateModalId('typeModal', typeId);
    } else {
        title.textContent = 'Neue Terminart';
        document.getElementById('typeForm').reset();
        document.getElementById('type_id').value = '';
        document.getElementById('type_is_default').checked = false;
        document.getElementById('type_color').value = '#667eea';
        renderTypeGroups([]);
        updateModalId('typeModal', null);
    }
    
    modal.classList.add('active');
}

export function closeTypeModal() {
    document.getElementById('typeModal').classList.remove('active');
}

async function loadTypeData(typeId) {
    const type = await apiCall('appointment_types', 'GET', null, { id: typeId });
    
    if (type) {
        document.getElementById('type_id').value = type.type_id;
        document.getElementById('type_name').value = type.type_name;
        document.getElementById('type_description').value = type.description || '';
        document.getElementById('type_color').value = type.color || '#667eea';
        document.getElementById('type_is_default').checked = type.is_default == 1;
        
        renderTypeGroups(type.groups || []);
    }
}

function renderTypeGroups(selectedGroups) {
    const container = document.getElementById('typeGroupsList');
    const selectedIds = selectedGroups.map(g => g.group_id);
    
    container.innerHTML = dataCache.groups.data.map(group => `
        <label style="display: block; padding: 8px; cursor: pointer; border-radius: 4px;" 
               onmouseover="this.style.background='#f5f5f5'" 
               onmouseout="this.style.background='transparent'">
            <input type="checkbox" 
                   class="type-group-checkbox" 
                   value="${group.group_id}" 
                   ${selectedIds.includes(group.group_id) ? 'checked' : ''}>
            <span style="margin-left: 8px;">${group.group_name}</span>
            ${group.description ? `<small style="color: #7f8c8d; display: block; margin-left: 28px;">${group.description}</small>` : ''}
        </label>
    `).join('');
}

// ============================================
// TYPES - CRUD
// ============================================

export async function saveType() {
    const form = document.getElementById('typeForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Sammle ausgewählte Gruppen
    const groupCheckboxes = document.querySelectorAll('.type-group-checkbox:checked');
    const groupIds = Array.from(groupCheckboxes).map(cb => parseInt(cb.value));
    
    if (groupIds.length === 0) {
        showToast('Bitte mindestens eine Gruppe auswählen', 'warning');
        return;
    }

    const isDefault = document.getElementById('type_is_default').checked;
    
    // Validierung: Standard-Terminart muss "Alle Mitglieder" enthalten
    if (isDefault) {
        // Hole die "Alle Mitglieder" Gruppe (normalerweise group_id = 1)
        const allMembersGroup = dataCache.groups.data.find(g => g.is_default);
        
        if (allMembersGroup && !groupIds.includes(allMembersGroup.group_id)) {
            showToast(
                `Standard-Terminart muss die Gruppe "${allMembersGroup.group_name}" enthalten`,
                'warning'
            );
            return;
        }
    }
    
    const typeId = document.getElementById('type_id').value;
    const data = {
        type_name: document.getElementById('type_name').value,
        description: document.getElementById('type_description').value || null,
        color: document.getElementById('type_color').value,
        is_default: isDefault,
        group_ids: groupIds
    };
    
    let result;
    if (typeId) {
        result = await apiCall('appointment_types', 'PUT', data, { id: typeId });
    } else {
        result = await apiCall('appointment_types', 'POST', data);
    }
    
    if (result) {
        closeTypeModal();
        //invalidateCache('types');
        await loadTypes(true);
        showToast(
            typeId ? 'Terminart erfolgreich aktualisiert' : 'Terminart erfolgreich erstellt',
            'success'
        );
    }
}

export async function deleteType(typeId, typeName) {
    const confirmed = await showConfirm(
        `Terminart "${typeName}" wirklich löschen?`,
        'Terminart löschen'
    );
    
    if (confirmed) {
        const result = await apiCall('appointment_types', 'DELETE', null, { id: typeId });
        if (result) {
            //invalidateCache('types');
            await loadTypes(true);
            showToast(`Terminart "${typeName}" wurde gelöscht`, 'success');
        }
    }
}

// ============================================
// GLOBAL EXPORTS
// ============================================

window.openGroupModal = openGroupModal;
window.closeGroupModal = closeGroupModal;
window.saveGroup = saveGroup;
window.deleteGroup = deleteGroup;

window.openTypeModal = openTypeModal;
window.closeTypeModal = closeTypeModal;
window.saveType = saveType;
window.deleteType = deleteType;