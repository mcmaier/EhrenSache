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
import { apiCall, isAdmin, isAdminOrManager } from './api.js';
import { showToast, showConfirm, dataCache, isCacheValid, invalidateCache, currentYear } from './ui.js';
import { debug } from '../app.js';

// ============================================
// ZUSTAND
// ============================================

let activityTypes = [];
let worktimeEnabled = null;   // null = noch nicht geprüft

// Die Badge-Klassen stammen aus components/badges.css — kein eigenes CSS noetig
const STATUS_BADGE = {
    confirmed: ['status-badge status-approved', 'bestätigt'],
    submitted: ['status-badge status-pending',  'wartet auf Freigabe'],
    rejected:  ['status-badge status-rejected', 'abgelehnt']
};

const PROOF_BADGE = {
    hours: ['status-badge status-approved', 'stundenbelegt'],
    start: ['status-badge status-pending',  'teilbelegt'],
    none:  ['type-badge',                   'unbelegt']
};

const VERIFICATION_LABEL = {
    none: 'kein Nachweis',
    start: 'Start belegen',
    start_end: 'Start und Ende belegen'
};

// ============================================
// HILFSFUNKTIONEN
// ============================================

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

/** Minuten als "3:45 h" — die Form, in der Vereine über Stunden sprechen. */
export function formatMinutes(minutes) {
    const m = parseInt(minutes, 10) || 0;
    const h = Math.floor(m / 60);
    return `${h}:${String(m % 60).padStart(2, '0')} h`;
}

/** Nachweisgrad einer Sitzung aus den beiden Ortsfeldern. */
function proofOf(session) {
    if (session.start_location_name && session.end_location_name) return 'hours';
    if (session.start_location_name) return 'start';
    return 'none';
}

/**
 * Ist die Zeiterfassung freigeschaltet? Ist sie es nicht, antwortet die
 * Ressource mit 404 — dann bleibt der Navigationspunkt verborgen.
 */
/**
 * Verwirft das gemerkte Ergebnis, damit checkWorktimeEnabled() neu prueft.
 * Wird nach dem Speichern der Einstellungen aufgerufen: sonst bliebe der
 * Navigationspunkt bis zum naechsten Neuladen verborgen bzw. sichtbar.
 */
export function resetWorktimeEnabled() {
    worktimeEnabled = null;
    activityTypes = [];
}

export async function checkWorktimeEnabled() {
    if (worktimeEnabled !== null) return worktimeEnabled;

    const result = await apiCall('activity_types', 'GET');
    worktimeEnabled = Array.isArray(result);

    if (worktimeEnabled) {
        activityTypes = result;
    }

    document.querySelectorAll('[data-section="zeiterfassung"]').forEach(el => {
        el.style.display = worktimeEnabled ? '' : 'none';
    });

    const block = document.getElementById('activityTypesBlock');
    if (block) {
        block.style.display = (worktimeEnabled && isAdmin) ? '' : 'none';
    }

    return worktimeEnabled;
}

// ============================================
// SITZUNGEN LADEN UND ANZEIGEN
// ============================================

export async function showWorktimeSection(forceReload = false) {
    if (!(await checkWorktimeEnabled())) {
        return;
    }

    await loadActivityTypes(forceReload);
    fillWorktimeFilters();
    await loadWorkSessions(forceReload);
}

export async function loadWorkSessions(forceReload = false) {
    const year = currentYear;

    if (!forceReload && isCacheValid('workSessions', year)) {
        renderWorkSessions(dataCache.workSessions[year].data);
        return dataCache.workSessions[year].data;
    }

    const result = await apiCall('work_sessions', 'GET', null, { year });
    const sessions = Array.isArray(result) ? result : [];

    if (!dataCache.workSessions[year]) {
        dataCache.workSessions[year] = {};
    }
    dataCache.workSessions[year].data = sessions;
    dataCache.workSessions[year].timestamp = Date.now();

    renderWorkSessions(sessions);
    return sessions;
}

/** Wendet die Filterleiste auf die geladenen Sitzungen an. */
function applyWorktimeFilters(sessions) {
    const status = document.getElementById('filterWorktimeStatus')?.value || '';
    const activity = document.getElementById('filterWorktimeActivity')?.value || '';
    const member = document.getElementById('filterWorktimeMember')?.value || '';

    return sessions.filter(s => {
        if (status && s.status !== status) return false;
        if (activity && String(s.activity_id) !== String(activity)) return false;
        if (member && String(s.member_id) !== String(member)) return false;
        return true;
    });
}

export function renderWorkSessions(sessions) {
    const tbody = document.getElementById('worktimeTableBody');
    if (!tbody) return;

    const filtered = applyWorktimeFilters(sessions || []);
    updateWorktimeStats(sessions || [], filtered);

    if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="loading">Keine Einträge für diese Auswahl.</td></tr>';
        return;
    }

    tbody.innerHTML = filtered.map(s => {
        const proof = proofOf(s);
        const running = !s.end_time;

        const locations = [s.start_location_name, s.end_location_name]
            .filter(Boolean).map(escapeHtml).join(' → ');

        return `<tr>
            <td>${escapeHtml(s.surname || '')}, ${escapeHtml(s.name || '')}
                ${s.member_number ? `<br><small>${escapeHtml(s.member_number)}</small>` : ''}</td>
            <td>${escapeHtml(s.activity_name || '—')}</td>
            <td>${escapeHtml(String(s.start_time || '').substring(0, 16))}</td>
            <td>${running ? '<em>läuft</em>' : formatMinutes(s.duration_minutes)}
                ${s.break_minutes > 0 ? `<br><small>${s.break_minutes} Min. Pause</small>` : ''}</td>
            <td>${escapeHtml(s.appointment_title || '—')}</td>
            <td><span class="${PROOF_BADGE[proof][0]}">${PROOF_BADGE[proof][1]}</span>
                ${locations ? `<br><small>${locations}</small>` : ''}</td>
            <td><span class="${(STATUS_BADGE[s.status] || ['type-badge'])[0]}">${(STATUS_BADGE[s.status] || [null, s.status])[1]}</span></td>
            <td class="actions-cell">${renderWorktimeActions(s)}</td>
        </tr>`;
    }).join('');
}

function renderWorktimeActions(session) {
    const buttons = [];

    if (isAdminOrManager && session.status === 'submitted' && session.end_time) {
        buttons.push(`<button class="action-btn btn-icon btn-approve" title="Freigeben"
            onclick="approveWorkSession(${session.session_id})">✓</button>`);
        buttons.push(`<button class="action-btn btn-icon btn-reject" title="Ablehnen"
            onclick="rejectWorkSession(${session.session_id})">✗</button>`);
    }

    // Mitglieder duerfen eigene Eintraege korrigieren; jede Aenderung entzieht
    // die Bestaetigung und schickt den Eintrag zurueck in die Freigabe.
    //
    // Ein Vergleich mit currentUser.member_id waere hier falsch: Die
    // Login-Antwort enthaelt dieses Feld nicht. Er ist auch unnoetig — der
    // Server liefert Nicht-Managern ausschliesslich eigene Sitzungen, jede
    // sichtbare Zeile gehoert ihnen also.
    const isOwn = !isAdminOrManager;

    if ((isAdminOrManager || isOwn) && session.end_time) {
        buttons.push(`<button class="action-btn btn-icon btn-edit" title="Bearbeiten"
            onclick="openWorkSessionModal(${session.session_id})">✎</button>`);
    }

    if (isAdmin) {
        buttons.push(`<button class="action-btn btn-icon btn-delete" title="Löschen"
            onclick="deleteWorkSession(${session.session_id})">🗑</button>`);
    }

    return buttons.join(' ') || '—';
}

function updateWorktimeStats(all, filtered) {
    const confirmedMinutes = filtered
        .filter(s => s.status === 'confirmed' && s.end_time)
        .reduce((sum, s) => sum + (parseInt(s.duration_minutes, 10) || 0), 0);

    const pending = all.filter(s => s.status === 'submitted' && s.end_time).length;
    const open = all.filter(s => !s.end_time).length;

    const set = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };

    set('statWorktimeTotal', formatMinutes(confirmedMinutes));
    set('statWorktimePending', pending);
    set('statWorktimeOpen', open);
}

/** Füllt die Auswahlfelder der Filterleiste. */
function fillWorktimeFilters() {
    const activitySelect = document.getElementById('filterWorktimeActivity');
    if (activitySelect) {
        const current = activitySelect.value;
        activitySelect.innerHTML = '<option value="">Alle Tätigkeiten</option>'
            + activityTypes.map(a =>
                `<option value="${a.activity_id}">${escapeHtml(a.activity_name)}</option>`).join('');
        activitySelect.value = current;
    }

    const memberSelect = document.getElementById('filterWorktimeMember');
    if (memberSelect && isAdminOrManager) {
        // Der Members-Cache ist jahresbasiert: dataCache.members[jahr].data
        const members = dataCache.members?.[currentYear]?.data || [];
        const current = memberSelect.value;
        memberSelect.innerHTML = '<option value="">Alle Mitglieder</option>'
            + members.map(m =>
                `<option value="${m.member_id}">${escapeHtml(m.surname)}, ${escapeHtml(m.name)}</option>`).join('');
        memberSelect.value = current;
    }

    const modalActivity = document.getElementById('workSessionActivity');
    if (modalActivity) {
        modalActivity.innerHTML = activityTypes
            .filter(a => a.is_active == 1)
            .map(a => `<option value="${a.activity_id}">${escapeHtml(a.activity_name)}</option>`).join('');
    }
}

export function resetWorktimeFilter() {
    ['filterWorktimeStatus', 'filterWorktimeActivity', 'filterWorktimeMember'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    loadWorkSessions();
}

// ============================================
// FREIGABE
// ============================================

async function setSessionStatus(sessionId, action, successText) {
    const result = await apiCall('work_sessions', 'PUT', { action }, { id: sessionId });

    if (!result || result.success === false) {
        return;
    }

    showToast(successText, 'success');
    await invalidateCache('workSessions', currentYear);
    await loadWorkSessions(true);
}

export async function approveWorkSession(sessionId) {
    await setSessionStatus(sessionId, 'approve', 'Eintrag freigegeben');
}

export async function rejectWorkSession(sessionId) {
    const ok = await showConfirm(
        'Der Eintrag zählt dann nicht im Nachweis. Das Mitglied kann ihn korrigieren '
        + 'und erneut einreichen.',
        'Eintrag ablehnen?'
    );
    if (!ok) return;

    await setSessionStatus(sessionId, 'reject', 'Eintrag abgelehnt');
}

export async function deleteWorkSession(sessionId) {
    const ok = await showConfirm(
        'Der Eintrag wird endgültig entfernt. Die Änderungshistorie bleibt erhalten und '
        + 'hält den letzten Stand fest.',
        'Eintrag löschen?'
    );
    if (!ok) return;

    const result = await apiCall('work_sessions', 'DELETE', null, { id: sessionId });
    if (!result || result.success === false) return;

    showToast('Eintrag gelöscht', 'success');
    await invalidateCache('workSessions', currentYear);
    await loadWorkSessions(true);
}

// ============================================
// MANUELLER EINTRAG / KORREKTUR
// ============================================

export async function openWorkSessionModal(sessionId = null) {
    const modal = document.getElementById('workSessionModal');
    if (!modal) return;

    await loadActivityTypes();
    fillWorktimeFilters();

    document.getElementById('workSessionId').value = sessionId || '';
    document.getElementById('workSessionModalTitle').textContent =
        sessionId ? 'Eintrag bearbeiten' : 'Zeit nachtragen';

    // Der Hinweis muss zur Rolle passen: Manager und Admin sind die freigebende
    // Instanz, ihre Eintraege gelten sofort.
    const hint = document.getElementById('workSessionHint');
    if (hint) {
        hint.textContent = isAdminOrManager
            ? 'Als Manager erfasste Zeiten gelten sofort und brauchen keine Freigabe.'
            : 'Nachträglich erfasste Zeiten gelten erst nach Freigabe durch einen Manager.';
    }

    const memberGroup = document.getElementById('workSessionMemberGroup');
    const memberSelect = document.getElementById('workSessionMember');

    if (isAdminOrManager && memberSelect) {
        memberGroup.style.display = '';
        // Der Members-Cache ist jahresbasiert: dataCache.members[jahr].data
        const members = dataCache.members?.[currentYear]?.data || [];
        memberSelect.innerHTML = members.map(m =>
            `<option value="${m.member_id}">${escapeHtml(m.surname)}, ${escapeHtml(m.name)}</option>`).join('');
    } else if (memberGroup) {
        memberGroup.style.display = 'none';
    }

    if (sessionId) {
        const session = await apiCall('work_sessions', 'GET', null, { id: sessionId });
        if (!session || session.success === false) return;

        document.getElementById('workSessionActivity').value = session.activity_id;
        document.getElementById('workSessionStart').value = toLocalInput(session.start_time);
        document.getElementById('workSessionEnd').value = toLocalInput(session.end_time);
        document.getElementById('workSessionBreak').value = session.break_minutes || 0;
        document.getElementById('workSessionNote').value = session.note || '';
        if (memberSelect) memberSelect.value = session.member_id;
    } else {
        document.getElementById('workSessionStart').value = '';
        document.getElementById('workSessionEnd').value = '';
        document.getElementById('workSessionBreak').value = 0;
        document.getElementById('workSessionNote').value = '';
    }

    modal.classList.add('active');
}

/** "2026-09-01 14:30:00" → "2026-09-01T14:30" für datetime-local. */
function toLocalInput(value) {
    if (!value) return '';
    return String(value).substring(0, 16).replace(' ', 'T');
}

/** Umgekehrt, für den Server. */
function fromLocalInput(value) {
    if (!value) return '';
    return value.replace('T', ' ') + ':00';
}

export function closeWorkSessionModal() {
    document.getElementById('workSessionModal')?.classList.remove('active');
}

export async function saveWorkSession() {
    const sessionId = document.getElementById('workSessionId').value;

    const body = {
        activity_id: parseInt(document.getElementById('workSessionActivity').value, 10),
        start_time: fromLocalInput(document.getElementById('workSessionStart').value),
        end_time: fromLocalInput(document.getElementById('workSessionEnd').value),
        break_minutes: parseInt(document.getElementById('workSessionBreak').value, 10) || 0,
        note: document.getElementById('workSessionNote').value.trim()
    };

    const memberSelect = document.getElementById('workSessionMember');
    if (isAdminOrManager && memberSelect && memberSelect.value) {
        body.member_id = parseInt(memberSelect.value, 10);
    }

    if (!body.start_time || !body.end_time) {
        showToast('Beginn und Ende sind erforderlich', 'error');
        return;
    }

    const result = sessionId
        ? await apiCall('work_sessions', 'PUT', body, { id: sessionId })
        : await apiCall('work_sessions', 'POST', body);

    if (!result || result.success === false) {
        return;
    }

    closeWorkSessionModal();
    showToast(sessionId ? 'Eintrag geändert' : 'Zeit nachgetragen', 'success');

    await invalidateCache('workSessions', currentYear);
    await loadWorkSessions(true);
}

// ============================================
// EXPORT
// ============================================

export function exportWorktimeMember() {
    const member = document.getElementById('filterWorktimeMember')?.value || '';
    let url = `${API_BASE}?resource=export&type=worktime_member&year=${currentYear}`;
    if (member) url += `&member_id=${member}`;
    window.location.href = url;
}

export function exportWorktimeActivity() {
    window.location.href =
        `${API_BASE}?resource=export&type=worktime_activity&year=${currentYear}`;
}

// ============================================
// TÄTIGKEITSARTEN (Stammdaten, Admin)
// ============================================

export async function loadActivityTypes(forceReload = false) {
    if (!forceReload && activityTypes.length) {
        return activityTypes;
    }

    const result = await apiCall('activity_types', 'GET');
    activityTypes = Array.isArray(result) ? result : [];

    renderActivityTypes();
    return activityTypes;
}

export function renderActivityTypes() {
    const tbody = document.getElementById('activityTypesTableBody');
    if (!tbody) return;

    if (!activityTypes.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="loading">Noch keine Tätigkeitsarten angelegt.</td></tr>';
        return;
    }

    tbody.innerHTML = activityTypes.map(a => `<tr>
        <td>${escapeHtml(a.activity_name)}</td>
        <td>${escapeHtml(a.description || '—')}</td>
        <td><span style="display: inline-block; width: 20px; height: 20px;
            background: ${escapeHtml(a.color || '#1F5FBF')}; border-radius: 3px;
            border: 1px solid #ddd;"></span></td>
        <td>${VERIFICATION_LABEL[a.verification] || escapeHtml(a.verification)}</td>
        <td>${a.is_active == 1
                ? '<span class="status-badge status-approved">aktiv</span>'
                : '<span class="type-badge">ausgemustert</span>'}</td>
        <td class="actions-cell">
            <button class="action-btn btn-icon btn-edit" title="Bearbeiten"
                onclick="openActivityTypeModal(${a.activity_id})">✎</button>
            <button class="action-btn btn-icon btn-delete" title="Löschen"
                onclick="deleteActivityType(${a.activity_id}, '${escapeHtml(a.activity_name).replace(/'/g, "\\'")}')">🗑</button>
        </td>
    </tr>`).join('');
}

export async function openActivityTypeModal(activityId = null) {
    const modal = document.getElementById('activityTypeModal');
    if (!modal) return;

    document.getElementById('activityTypeId').value = activityId || '';
    document.getElementById('activityTypeModalTitle').textContent =
        activityId ? 'Tätigkeitsart bearbeiten' : 'Neue Tätigkeitsart';

    const activity = activityId
        ? activityTypes.find(a => String(a.activity_id) === String(activityId))
        : null;

    document.getElementById('activityTypeName').value = activity?.activity_name || '';
    document.getElementById('activityTypeDescription').value = activity?.description || '';
    document.getElementById('activityTypeColor').value = activity?.color || '#1F5FBF';
    document.getElementById('activityTypeVerification').value = activity?.verification || 'none';
    document.getElementById('activityTypeActive').checked = activity ? activity.is_active == 1 : true;

    modal.classList.add('active');
}

export function closeActivityTypeModal() {
    document.getElementById('activityTypeModal')?.classList.remove('active');
}

export async function saveActivityType() {
    const activityId = document.getElementById('activityTypeId').value;

    const body = {
        activity_name: document.getElementById('activityTypeName').value.trim(),
        description: document.getElementById('activityTypeDescription').value.trim(),
        color: document.getElementById('activityTypeColor').value,
        verification: document.getElementById('activityTypeVerification').value,
        is_active: document.getElementById('activityTypeActive').checked ? 1 : 0
    };

    if (!body.activity_name) {
        showToast('Ein Name ist erforderlich', 'error');
        return;
    }

    const result = activityId
        ? await apiCall('activity_types', 'PUT', body, { id: activityId })
        : await apiCall('activity_types', 'POST', body);

    if (!result || result.success === false) {
        return;
    }

    closeActivityTypeModal();
    showToast(activityId ? 'Tätigkeitsart geändert' : 'Tätigkeitsart angelegt', 'success');
    await loadActivityTypes(true);
}

export async function deleteActivityType(activityId, name) {
    const ok = await showConfirm(
        `„${name}" wird entfernt. Hängen bereits erfasste Zeiten daran, ist das Löschen `
        + 'nicht möglich — dann die Art stattdessen ausmustern.',
        'Tätigkeitsart löschen?'
    );
    if (!ok) return;

    // 409 bei ON DELETE RESTRICT: an der Art haengen Sitzungen. apiCall zeigt
    // die Meldung des Servers bereits als Toast.
    const result = await apiCall('activity_types', 'DELETE', null, { id: activityId });
    if (!result || result.success === false) return;

    showToast('Tätigkeitsart gelöscht', 'success');
    await loadActivityTypes(true);
}

// ============================================
// EVENT-HANDLER
// ============================================

export function initWorktimeEventHandlers() {
    ['filterWorktimeStatus', 'filterWorktimeActivity', 'filterWorktimeMember'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => {
            const sessions = dataCache.workSessions?.[currentYear]?.data || [];
            renderWorkSessions(sessions);
        });
    });
}

// Global verfügbar machen, weil die Oberfläche onclick-Attribute nutzt
window.openWorkSessionModal = openWorkSessionModal;
window.closeWorkSessionModal = closeWorkSessionModal;
window.saveWorkSession = saveWorkSession;
window.approveWorkSession = approveWorkSession;
window.rejectWorkSession = rejectWorkSession;
window.deleteWorkSession = deleteWorkSession;
window.resetWorktimeFilter = resetWorktimeFilter;
window.exportWorktimeMember = exportWorktimeMember;
window.exportWorktimeActivity = exportWorktimeActivity;

window.openActivityTypeModal = openActivityTypeModal;
window.closeActivityTypeModal = closeActivityTypeModal;
window.saveActivityType = saveActivityType;
window.deleteActivityType = deleteActivityType;
