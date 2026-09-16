/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { apiCall, isAdminOrManager } from './api.js';
import { showToast, showConfirm, dataCache, isCacheValid,invalidateCache, subgroupLabel,
         updateSubgroupLabelElements } from './ui.js';
import { loadMembers } from './members.js';
import { formatDateTime, updateModalId, escapeHtml } from './utils.js';
import {debug} from '../app.js'

// ============================================
// MANAGEMENT (Groups & Types)
// ============================================

export async function showGroupSection(forceReload = false)
{
    // subgroup_label liegt (Kategorie 'public') bereits global in
    // sessionStorage, von theme.js beim Seitenaufruf geladen — subgroupLabel()
    // liest direkt von dort. Hier nur die [data-subgroup-label]-Elemente im
    // Gruppendialog synchronisieren, kein eigener Ladeschritt mehr nötig.
    updateSubgroupLabelElements();

    const groupData = await loadGroups(forceReload);
    renderGroups(groupData);

    const typeData = await loadTypes(forceReload);
    renderTypeGroupOverview(typeData);
}

// ============================================
// GROUPS - Data Loading
// ============================================

export async function loadGroups(forceReload = false) {
    // Cache-Check: Nur laden wenn nötig
    if (!forceReload && isCacheValid('groups')) {
        debug.log('Loading groups from cache');
        return dataCache.groups.data;
    }

    debug.log('Loading groups from API');
    const groups = await apiCall('member_groups');

    dataCache.groups.data = groups;
    dataCache.groups.timestamp = Date.now();

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

        const subgroupBadge = group.is_subgroup == 1
            ? ` <span class="status-badge status-approved" style="font-size: 10px; padding: 2px 6px;">${escapeHtml(subgroupLabel())}</span>`
            : '';

        // Mitgliederanzahl anzeigen
        const memberCount = group.member_count || 0;

        const row = `
            <tr>
                <td><strong>${escapeHtml(group.group_name)}</strong>${subgroupBadge}</td>
                <td>${group.description ? escapeHtml(group.description) : '-'}</td>
                <td>${memberCount}</td>
                <td>${isDefaultBadge}</td>
                <td class="actions-cell">
                    <button class="action-btn btn-icon btn-edit" onclick="openGroupModal(${group.group_id})" title="Bearbeiten">
                        ✎
                    </button>
                    <button class="action-btn btn-icon btn-delete" onclick="deleteGroup(${group.group_id})" title="Löschen">
                        🗑
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

    await loadMembers();       
    
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
        document.getElementById('group_is_subgroup').checked = false;
        document.getElementById('group_sort_order').value = 0;
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
        document.getElementById('group_is_subgroup').checked = group.is_subgroup == 1;
        document.getElementById('group_sort_order').value = group.sort_order ?? 0;

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

        const groups = await loadGroups();            

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

    const sortOrderRaw = parseInt(document.getElementById('group_sort_order').value, 10);

    const data = {
        group_name: document.getElementById('group_name').value,
        description: document.getElementById('group_description').value || null,
        is_default: isDefault,
        is_subgroup: document.getElementById('group_is_subgroup').checked ? 1 : 0,
        sort_order: Number.isFinite(sortOrderRaw) ? sortOrderRaw : 0
    };
    
    let result;
    if (groupId) {
        result = await apiCall('member_groups', 'PUT', data, { id: groupId });
    } else {
        result = await apiCall('member_groups', 'POST', data);
    }
    
    if (result.success) {
        closeGroupModal();
        //invalidateCache('groups'); 
        //await loadGroups(true);
        await showGroupSection(true);
        showToast(
            groupId ? 'Gruppe erfolgreich aktualisiert' : 'Gruppe erfolgreich erstellt',
            'success'
        );
    }
}

export async function deleteGroup(groupId) {
    // Name aus dem Cache holen statt aus dem onclick-Attribut: ein Gruppenname mit
    // Apostroph oder HTML sprengte dort sonst den Aufruf bzw. liesse sich als Code
    // einschleusen (kein CSP im Projekt).
    const group = dataCache.groups.data.find(g => g.group_id == groupId);
    const groupName = group ? group.group_name : '';

    const confirmed = await showConfirm(
        `Gruppe "${groupName}" wirklich löschen?`,
        'Gruppe löschen'
    );
    
    if (confirmed) {
        const result = await apiCall('member_groups', 'DELETE', null, { id: groupId });
        if (result.success) {
            //invalidateCache('groups'); 
            //await loadGroups(true);
            await showGroupSection(true);
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
        debug.log('Loading Appointment Types from CACHE');
        return dataCache.types.data;
    }
    
    debug.log('Loading Appointment Types from API');
    const types = await apiCall('appointment_types');        

    dataCache.types.data = types;
    dataCache.types.timestamp = Date.now();
    
    return types;       
}

export async function renderTypeGroupOverview(typeData)
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
                <td class="actions-cell">
                    <button class="action-btn btn-icon btn-edit" onclick="openTypeModal(${type.type_id})" title="Bearbeiten">
                        ✎
                    </button>
                    <button class="action-btn btn-icon btn-delete" onclick="deleteType(${type.type_id})" title="Löschen">
                        🗑
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
    
    const types = await loadTypes(false);

    const type = types.find(t => t.type_id == typeId);
    const cell = document.getElementById(`type_groups_${typeId}`);
    
    if (type && type.groups && type.groups.length > 0) {
        cell.innerHTML = type.groups.map(g => `<span class="type-badge">${g.group_name}</span>`).join(' ');
    } else {
        cell.innerHTML = '<span style="color: #7f8c8d;">Keine</span>';
    }
}

// ============================================
// TYPES - Rueckmeldung (FI-1)
// ============================================

/** Die drei abhaengigen Felder wirken nur bei eingeschalteter Rueckmeldung. */
export function toggleTypeResponseFields() {
    const enabled = document.getElementById('type_responses_enabled').checked;
    const dependent = document.getElementById('typeResponseDependent');

    dependent.classList.toggle('is-disabled', !enabled);
    dependent.querySelectorAll('input').forEach(input => { input.disabled = !enabled; });
}

/** Platzhalter der Frist mit dem aktuell gueltigen globalen Wert. */
async function setDeadlinePlaceholder() {
    const input = document.getElementById('type_response_deadline_hours');
    const result = await apiCall('settings');
    const setting = result?.settings?.find(s => s.setting_key === 'response_deadline_hours');
    input.placeholder = setting ? `global (${setting.setting_value} h)` : 'global';
}

function fillTypeResponseFields(type) {
    document.getElementById('type_responses_enabled').checked = Number(type?.responses_enabled) === 1;
    document.getElementById('type_responses_names_visible').checked = Number(type?.responses_names_visible) === 1;
    document.getElementById('type_responses_require_excuse').checked = Number(type?.responses_require_excuse) === 1;
    document.getElementById('type_response_deadline_hours').value =
        type?.response_deadline_hours === null || type?.response_deadline_hours === undefined
            ? '' : type.response_deadline_hours;
    toggleTypeResponseFields();
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
        fillTypeResponseFields(null);
        updateModalId('typeModal', null);
    }

    setDeadlinePlaceholder();
    modal.classList.add('active');
}

export function closeTypeModal() {
    document.getElementById('typeModal').classList.remove('active');
}

async function loadTypeData(typeId) {

    const types = await loadTypes(false);
    const type = dataCache.types.data.find(t => t.type_id == typeId);
    
    if (type) {
        document.getElementById('type_id').value = type.type_id;
        document.getElementById('type_name').value = type.type_name;
        document.getElementById('type_description').value = type.description || '';
        document.getElementById('type_color').value = type.color || '#667eea';
        document.getElementById('type_is_default').checked = type.is_default == 1;
        
        renderTypeGroups(type.groups || []);
        fillTypeResponseFields(type);
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
    
    const deadlineRaw = document.getElementById('type_response_deadline_hours').value.trim();
    if (deadlineRaw !== '' && (!/^\d+$/.test(deadlineRaw) || Number(deadlineRaw) > 720)) {
        showToast('Die Frist muss leer oder eine ganze Zahl von 0 bis 720 sein', 'warning');
        return;
    }

    const typeId = document.getElementById('type_id').value;
    const data = {
        type_name: document.getElementById('type_name').value,
        description: document.getElementById('type_description').value || null,
        color: document.getElementById('type_color').value,
        is_default: isDefault,
        group_ids: groupIds,
        responses_enabled: document.getElementById('type_responses_enabled').checked,
        responses_names_visible: document.getElementById('type_responses_names_visible').checked,
        responses_require_excuse: document.getElementById('type_responses_require_excuse').checked,
        response_deadline_hours: deadlineRaw === '' ? null : Number(deadlineRaw)
    };
    
    let result;
    if (typeId) {
        result = await apiCall('appointment_types', 'PUT', data, { id: typeId });
    } else {
        result = await apiCall('appointment_types', 'POST', data);
    }
    
    if (result.success) {
        closeTypeModal();
        //invalidateCache('types');
        //await loadTypes(true);

        await showGroupSection(true);
        showToast(
            typeId ? 'Terminart erfolgreich aktualisiert' : 'Terminart erfolgreich erstellt',
            'success'
        );
    }
}

export async function deleteType(typeId) {
    const type = dataCache.types.data.find(t => t.type_id == typeId);
    const typeName = type ? type.type_name : '';

    const confirmed = await showConfirm(
        `Terminart "${typeName}" wirklich löschen?`,
        'Terminart löschen'
    );
    
    if (confirmed) {
        const result = await apiCall('appointment_types', 'DELETE', null, { id: typeId });
        if (result.success) {
            //invalidateCache('types');
            //await loadTypes(true);
            await showGroupSection(true);
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
window.toggleTypeResponseFields = toggleTypeResponseFields;