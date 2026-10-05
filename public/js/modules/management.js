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
import { showToast, showConfirm, dataCache, isCacheValid, invalidateCache, subgroupLabel,
         updateSubgroupLabelElements } from './ui.js';
import { sharedLoad } from './pending_loads.js';
import { loadMembers } from './members.js';
import { formatDateTime, updateModalId, escapeHtml, safeTypeColor } from './utils.js';
import { groupChips, CHIPS_APPOINTMENT_TYPES, countChips, filterByChip,
         renderFilterChips } from './filter_chips.js';
import {debug} from '../app.js'
import { registerActions } from './actions.js';
import { isFeatureOn } from './features.js';

// ============================================
// MANAGEMENT (Groups & Types)
// ============================================

// Aktive Chips der Verwaltungstabellen (Sichtung 23.09.2026: die Zaehlzeilen
// filtern jetzt, wie in den uebrigen Listen).
let groupChip = 'all';
let typeChip  = 'all';

export async function showGroupSection(forceReload = false)
{
    // subgroup_label liegt (Kategorie 'public') bereits global in
    // sessionStorage, von theme.js beim Seitenaufruf geladen — subgroupLabel()
    // liest direkt von dort. Hier nur die [data-subgroup-label]-Elemente im
    // Gruppendialog synchronisieren, kein eigener Ladeschritt mehr nötig.
    updateSubgroupLabelElements();

    const groupData = await loadGroups(forceReload);
    renderGroups(groupData);

    // Terminarten nur mit Terminplanung (OI-62, Etappe 2); der Block traegt
    // data-feature="appointments" und ist dann ausgeblendet.
    if (isFeatureOn('appointments')) {
        const typeData = await loadTypes(forceReload);
        renderTypeGroupOverview(typeData);
    }
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

    return sharedLoad('groups', forceReload, async () => {
        debug.log('Loading groups from API');
        const groups = await apiCall('member_groups');

        dataCache.groups.data = groups;
        dataCache.groups.timestamp = Date.now();

        return groups;        
    });
}

function renderGroups(groupData)
{
const tbody = document.getElementById('groupsTableBody');
    tbody.innerHTML = '';

    const gruppenDefs = groupChips(subgroupLabel());
    renderFilterChips(
        document.getElementById('groupChipsRow'),
        gruppenDefs, countChips(groupData, gruppenDefs), groupChip,
        key => { groupChip = key; renderGroups(groupData); },
        { label: 'Gruppen nach Art' }
    );

    // "Alle" ist zugleich das Zuruecksetzen -- kein eigener Knopf.
    const sichtbar = filterByChip(groupData, gruppenDefs, groupChip);

    if (!sichtbar.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="loading">Keine Gruppen für diese Auswahl</td></tr>';
        return;
    }

    // Register stehen unter JEDER ihrer Gruppen (eingerückt); Register ohne
    // Gruppe am Ende mit Hinweis. Ein Register, dessen Gruppen alle ausgeblendet
    // sind (Chip-Filter), steht ohne Einrückung am Ende, damit es nicht verschwindet.
    const isRegister = g => g.is_subgroup == 1;
    const parentsOf = g => Array.isArray(g.parent_group_ids) ? g.parent_group_ids.map(Number) : [];
    const plain = sichtbar.filter(g => !isRegister(g));
    const plainIds = new Set(plain.map(g => Number(g.group_id)));
    const registers = sichtbar.filter(isRegister);
    const rows = [];
    plain.forEach(group => {
        rows.push({ group, kind: 'plain' });
        registers
            .filter(r => parentsOf(r).includes(Number(group.group_id)))
            .forEach(r => rows.push({ group: r, kind: 'sub' }));
    });
    registers
        .filter(r => parentsOf(r).length > 0 && !parentsOf(r).some(p => plainIds.has(p)))
        .forEach(r => rows.push({ group: r, kind: 'orphan' }));
    registers
        .filter(r => parentsOf(r).length === 0)
        .forEach(r => rows.push({ group: r, kind: 'unassigned' }));

    rows.forEach(({ group, kind }) => {
        const isDefaultBadge = group.is_default
            ? '<span class="status-badge status-approved">✓ Ja</span>'
            : '<span class="type-badge">Nein</span>';

        const subgroupBadge = group.is_subgroup == 1
            ? ` <span class="status-badge status-approved badge-small">${escapeHtml(subgroupLabel())}</span>`
            : '';

        const unassignedHint = kind === 'unassigned'
            ? '<small class="group-unassigned-hint">ohne Gruppe — bitte zuordnen; bis dahin keine Registerstatistik</small>'
            : '';

        const rowClass = kind === 'sub' ? 'group-row--sub'
            : kind === 'unassigned' ? 'group-row--unassigned'
            : '';

        // Mitgliederanzahl anzeigen
        const memberCount = group.member_count || 0;

        const row = `
            <tr class="${rowClass}">
                <td><strong>${escapeHtml(group.group_name)}</strong>${subgroupBadge}${unassignedHint}</td>
                <td>${group.description ? escapeHtml(group.description) : '-'}</td>
                <td>${memberCount}</td>
                <td>${isDefaultBadge}</td>
                <td class="actions-cell">
                    <button class="action-btn btn-icon btn-edit" data-action="open-group-modal" data-id="${Number(group.group_id)}" title="Bearbeiten">
                        ✎
                    </button>
                    <button class="action-btn btn-icon btn-delete" data-action="delete-group" data-id="${Number(group.group_id)}" title="Löschen">
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
    await loadGroups(true);

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
        fillGroupParentList(null, []);
        toggleGroupExclusivity();
        membersGroup.style.display = 'none';
        updateModalId('groupModal', null)
    }
    
    modal.classList.add('active');
}

export function closeGroupModal() {
    document.getElementById('groupModal').classList.remove('active');
}

/**
 * Untergruppe und Standardgruppe schliessen sich aus (Server weist die
 * Kombination mit 400 ab, siehe handleMemberGroups() in member_groups.php).
 * Hier nur die sichtbare Seite davon: das jeweils andere Haekchen sperren,
 * sobald eines gesetzt ist, mit kurzem Hinweis, warum -- statt erst beim
 * Speichern auf die Fehlermeldung zu laufen.
 */
export function toggleGroupExclusivity() {
    const isDefault = document.getElementById('group_is_default');
    const isSubgroup = document.getElementById('group_is_subgroup');
    const defaultHint = document.getElementById('group_is_default_conflict_hint');
    const subgroupHint = document.getElementById('group_is_subgroup_conflict_hint');

    isSubgroup.disabled = isDefault.checked;
    subgroupHint.style.display = isDefault.checked ? 'block' : 'none';

    isDefault.disabled = isSubgroup.checked;
    defaultHint.style.display = isSubgroup.checked ? 'block' : 'none';

    // Die Gruppen eines Registers sind nur bei einer Untergruppe wählbar.
    document.getElementById('group_parent_row').hidden = !isSubgroup.checked;
}

/**
 * Checkbox-Liste "Gehört zu": alle gewöhnlichen Gruppen außer der eigenen.
 * Quelle ist der Gruppen-Cache (openGroupModal lädt ihn vorher).
 */
function fillGroupParentList(currentGroupId, selectedIds) {
    const container = document.getElementById('group_parent_list');
    const selected = new Set((selectedIds || []).map(Number));
    const candidates = (dataCache.groups.data || [])
        .filter(g => g.is_subgroup != 1 && Number(g.group_id) !== Number(currentGroupId));

    if (candidates.length === 0) {
        container.innerHTML = '<small class="input-hint">Keine Gruppen verfügbar</small>';
        return;
    }

    container.innerHTML = candidates.map(g => `
        <label class="group-choice group-parent-choice">
            <input type="checkbox" class="group-parent-checkbox" value="${Number(g.group_id)}"
                   ${selected.has(Number(g.group_id)) ? 'checked' : ''}>
            <span>${escapeHtml(g.group_name)}</span>
        </label>
    `).join('');
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
        fillGroupParentList(group.group_id, group.parent_group_ids || []);
        toggleGroupExclusivity();

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
            ${escapeHtml(m.surname)}, ${escapeHtml(m.name)} ${m.member_number ? `(${escapeHtml(m.member_number)})` : ''}
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
    const isSubgroup = document.getElementById('group_is_subgroup').checked;
    const parentGroupIds = Array.from(document.querySelectorAll('.group-parent-checkbox:checked'))
        .map(cb => parseInt(cb.value, 10));

    const data = {
        group_name: document.getElementById('group_name').value,
        description: document.getElementById('group_description').value || null,
        is_default: isDefault,
        is_subgroup: isSubgroup ? 1 : 0,
        sort_order: Number.isFinite(sortOrderRaw) ? sortOrderRaw : 0,
        parent_group_ids: isSubgroup ? parentGroupIds : []
    };
    
    let result;
    if (groupId) {
        result = await apiCall('member_groups', 'PUT', data, { id: groupId });
    } else {
        result = await apiCall('member_groups', 'POST', data);
    }
    
    if (result.success) {
        closeGroupModal();

        // Die Gruppen eines Registers verschieben, wer in der Gliederung und in
        // "erwartet" steht, und der Server ergaenzt Mitglieder in der Gruppe eines
        // Registers (added_groups). Betroffen sind Mitgliederliste (Gruppennamen,
        // alle Jahre), Termine ("erwartet", Zaehler) und Anwesenheiten (Gliederung).
        // Bewusst ohne Jahr: die Zuordnung gilt fuer alle.
        await invalidateCache('members');
        await invalidateCache('appointments');
        await invalidateCache('records');
        await showGroupSection(true);
        showToast(
            groupId ? 'Gruppe erfolgreich aktualisiert' : 'Gruppe erfolgreich erstellt',
            'success'
        );

        // Folgen der Mitgliedschaftsregel als Hinweis (Spec 2026-10-02, 4.2)
        const added = Array.isArray(result.added_groups) ? result.added_groups.length : 0;
        const warned = Array.isArray(result.group_warnings) ? result.group_warnings.length : 0;
        if (added > 0) {
            showToast(`${added} Mitglied(er) zusätzlich der Gruppe des Registers zugeordnet`, 'info');
        }
        if (warned > 0) {
            showToast(`${warned} Mitglied(er) stehen in keiner der Gruppen des Registers — bitte prüfen`, 'warning');
        }
    }
}

export async function deleteGroup(groupId) {
    // Name aus dem Cache holen statt aus dem onclick-Attribut: ein Gruppenname mit
    // Apostroph oder HTML sprengte dort sonst den Aufruf bzw. liesse sich als Code
    // einschleusen (Defense in Depth, die CSP blockt Inline-Code ohnehin).
    const group = dataCache.groups.data.find(g => g.group_id == groupId);
    const groupName = group ? group.group_name : '';

    const confirmed = await showConfirm(
        `Gruppe "${groupName}" wirklich löschen?`,
        'Gruppe löschen'
    );
    
    if (confirmed) {
        const result = await apiCall('member_groups', 'DELETE', null, { id: groupId });
        if (result.success) {
            // Mit der Gruppe fallen per ON DELETE CASCADE auch ihre
            // member_group_assignments und appointment_type_groups weg -- "erwartet"
            // im Kalender aendert sich dadurch. Bewusst ohne Jahr, die Zuordnung
            // gilt fuer alle Jahre.
            await invalidateCache('appointments');

            // Auch subgroup_parents und appointment_type_groups fallen per CASCADE
            // weg: Anwesenheiten (Gliederung) und Terminarten (Gruppenanzeige).
            await invalidateCache('records');
            await invalidateCache('types');

            // Die Mitgliederliste zeigt die Gruppen je Mitglied (alle Jahre).
            await invalidateCache('members');
            await showGroupSection(true);
            // showToast() setzt die Nachricht als Text (OI-111) -- nicht selbst maskieren.
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

    return sharedLoad('types', forceReload, async () => {
        debug.log('Loading Appointment Types from API');
        const types = await apiCall('appointment_types');        

        dataCache.types.data = types;
        dataCache.types.timestamp = Date.now();
    
        return types;       
    });
}

export async function renderTypeGroupOverview(typeData)
{
    const tbody = document.getElementById('typesTableBody');
    tbody.innerHTML = '';

    renderFilterChips(
        document.getElementById('typeChipsRow'),
        CHIPS_APPOINTMENT_TYPES, countChips(typeData, CHIPS_APPOINTMENT_TYPES), typeChip,
        key => { typeChip = key; renderTypeGroupOverview(typeData); },
        { label: 'Terminarten nach Rückmeldung' }
    );

    // "Alle" ist zugleich das Zuruecksetzen -- kein eigener Knopf.
    const sichtbar = filterByChip(typeData, CHIPS_APPOINTMENT_TYPES, typeChip);

    if (!sichtbar.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="loading">Keine Terminarten für diese Auswahl</td></tr>';
        return;
    }

    sichtbar.forEach(type => {
        const isDefaultBadge = type.is_default 
            ? '<span class="status-badge status-approved">✓ Ja</span>' 
            : '<span class="type-badge">Nein</span>';
        
        // Farbwert kommt frei aus der DB (kein Server-seitiger Format-Zwang) und landet
        // in einem style-Attribut -- escapeHtml() haelt dort zwar das Attribut zusammen,
        // laesst aber beliebiges CSS durch. Die Pruefung steht seit OI-94 nur
        // noch einmal im Dashboard, als safeTypeColor() in utils.js; die Check-in-App
        // fuehrt weiter ihre eigene Fassung (public/checkin/js/app.js) -- sie war von
        // OI-94 ausdruecklich ausgenommen. Die gemeinsame Fassung ist zugleich enger
        // als die fruehere Kopie an dieser Stelle: nur 3, 4, 6 oder 8 Hexstellen. Eine
        // Laenge wie 5 galt hier als sicher, ergab aber ungueltiges CSS, das der
        // Browser wortlos verwirft -- die Kachel blieb dann farblos statt grau.
        const safeColor = safeTypeColor(type.color);
        const colorBadge = `<span style="display: inline-block; width: 20px; height: 20px; background: ${safeColor}; border-radius: 3px; border: 1px solid #ddd;"></span>`;

        // Lade Gruppen für diese Terminart
        const groupsText = '-'; // Wird später gefüllt

        const responsesBadge = Number(type.responses_enabled) === 1
            ? ' <span title="Rückmeldung aktiv">💬</span>'
            : '';

        const row = `
            <tr>
                <td><strong>${escapeHtml(type.type_name)}</strong>${responsesBadge}</td>
                <td>${type.description ? escapeHtml(type.description) : '-'}</td>
                <td>${colorBadge}</td>
                <td id="type_groups_${type.type_id}">Lädt...</td>
                <td>${isDefaultBadge}</td>
                <td class="actions-cell">
                    <button class="action-btn btn-icon btn-edit" data-action="open-type-modal" data-id="${Number(type.type_id)}" title="Bearbeiten">
                        ✎
                    </button>
                    <button class="action-btn btn-icon btn-delete" data-action="delete-type" data-id="${Number(type.type_id)}" title="Löschen">
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
    // Zeile kann durch den Chipfilter verschwunden sein, waehrend dieser Aufruf noch lief.
    if (!cell) return;

    if (type && type.groups && type.groups.length > 0) {
        cell.innerHTML = type.groups.map(g => `<span class="type-badge">${escapeHtml(g.group_name)}</span>`).join(' ');
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
    const all = dataCache.groups.data;
    const nameOf = id => all.find(g => Number(g.group_id) === Number(id))?.group_name || '';
    const parentNames = g => (Array.isArray(g.parent_group_ids) ? g.parent_group_ids : [])
        .map(nameOf).filter(Boolean).sort((a, b) => a.localeCompare(b, 'de'));

    // Register stehen unter ihrer ersten Gruppe (nach Name); weitere Gruppen
    // als Unterzeile. Register ohne Gruppe am Ende.
    const plain = all.filter(g => g.is_subgroup != 1);
    const registers = all.filter(g => g.is_subgroup == 1);
    const firstParentId = r => {
        const ids = (Array.isArray(r.parent_group_ids) ? r.parent_group_ids : []).map(Number)
            .filter(id => nameOf(id));
        ids.sort((a, b) => nameOf(a).localeCompare(nameOf(b), 'de'));
        return ids[0];
    };

    const registerNote = 'nur für eigene Termine, z. B. Registerprobe';
    const choice = (group, sub, extraLine) => `
        <label class="group-choice${sub ? ' group-choice--sub' : ''}">
            <input type="checkbox"
                   class="type-group-checkbox"
                   value="${Number(group.group_id)}"
                   ${selectedIds.includes(group.group_id) ? 'checked' : ''}>
            <span class="group-choice-name">${escapeHtml(group.group_name)}</span>
            ${group.description ? `<small class="group-choice-note">${escapeHtml(group.description)}</small>` : ''}
            ${sub ? `<small class="group-choice-note">${escapeHtml(registerNote)}</small>` : ''}
            ${extraLine ? `<small class="group-choice-note">${escapeHtml(extraLine)}</small>` : ''}
        </label>
    `;

    const html = [];
    plain.forEach(group => {
        html.push(choice(group, false, ''));
        registers.filter(r => firstParentId(r) === Number(group.group_id)).forEach(r => {
            const others = parentNames(r).filter(n => n !== group.group_name);
            html.push(choice(r, true, others.length ? 'auch in: ' + others.join(', ') : ''));
        });
    });
    registers.filter(r => firstParentId(r) === undefined)
        .forEach(r => html.push(choice(r, true, 'ohne Gruppe — bitte zuordnen')));

    container.innerHTML = html.join('');
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
            // showToast() setzt die Nachricht als Text (OI-111) -- nicht selbst maskieren.
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

        // group_ids schreibt appointment_type_groups fort -- damit verschiebt sich,
        // wer zu einem Termin dieser Art erwartet wird. Die Zahlen im Kalender
        // kommen mit dem Terminabruf des Jahres; bewusst ohne Jahr verwerfen.
        await invalidateCache('appointments');

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
            // Mit der Terminart fallen ihre appointment_type_groups weg -- die
            // Termine dieser Art haben danach niemanden mehr, der erwartet wird.
            // Bewusst ohne Jahr verwerfen.
            await invalidateCache('appointments');

            //invalidateCache('types');
            //await loadTypes(true);
            await showGroupSection(true);
            // showToast() setzt die Nachricht als Text (OI-111) -- nicht selbst maskieren.
            showToast(`Terminart "${typeName}" wurde gelöscht`, 'success');
        }
    }
}

registerActions({
    'close-group-modal': () => closeGroupModal(),
    'close-type-modal': () => closeTypeModal(),
    'delete-group': (el) => deleteGroup(Number(el.dataset.id)),
    'delete-type': (el) => deleteType(Number(el.dataset.id)),
    'open-group-modal': (el) => openGroupModal(el.dataset.id ? Number(el.dataset.id) : null),
    'open-type-modal': (el) => openTypeModal(el.dataset.id ? Number(el.dataset.id) : null),
    'save-group': () => saveGroup(),
    'save-type': () => saveType(),
    'toggle-group-exclusivity': () => toggleGroupExclusivity(),
    'toggle-type-response-fields': () => toggleTypeResponseFields(),
});
