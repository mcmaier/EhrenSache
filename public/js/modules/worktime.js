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
import { loadGroups } from './management.js';

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

/**
 * Farbpunkt einer Taetigkeitsart.
 *
 * Die Farbe war bislang nur dort zu sehen, wo man sie einstellt — im
 * Stammdatenblock. Wiedererkennung stiftet sie erst in den Listen, in denen
 * viele Eintraege untereinander stehen.
 */
function activityDot(color) {
    return `<span class="activity-dot" style="background: ${escapeHtml(color || '#1F5FBF')}"></span>`;
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

    // Der 404 IST hier die Antwort „Feature aus" — kein Fehler, der das
    // Mitglied etwas anginge.
    const result = await apiCall('activity_types', 'GET', null, {},
                                 { silentStatuses: [404] });
    const freigeschaltet = Array.isArray(result);

    if (freigeschaltet) {
        activityTypes = result;
    }

    // Eine leere Liste heisst fuer ein Mitglied: freigeschaltet, aber in seinen
    // Gruppen liegt keine Taetigkeitsart — der Bereich waere leer.
    //
    // Fuer Administrator und Manager gilt das NICHT: sie bekommen ungefiltert
    // alles, eine leere Liste bedeutet dort schlicht, dass noch keine Art
    // angelegt ist. Blendete man ihnen den Bereich aus, koennten sie die erste
    // nie anlegen — eine Sackgasse direkt nach der Freischaltung.
    worktimeEnabled = freigeschaltet && (isAdminOrManager || result.length > 0);

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
            <td>${activityDot(s.color)}${escapeHtml(s.activity_name || '—')}</td>
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

        // Hier wird gekennzeichnet, nicht ausgeschlossen: Die Filterleiste
        // dient dem Sichten vorhandener Eintraege. Geleistete Stunden bleiben
        // ein gueltiger Nachweis, auch wenn das Mitglied ausgetreten ist —
        // waeren sie nicht mehr auffindbar, fehlten sie in der Auswertung.
        // Beim Nachtragen gilt das Gegenteil, siehe openWorkSessionModal().
        memberSelect.innerHTML = '<option value="">Alle Mitglieder</option>'
            + members.map(m => {
                const hinweis = m.is_active_in_period ? '' : ' (inaktiv)';
                return `<option value="${m.member_id}">`
                     + `${escapeHtml(m.surname)}, ${escapeHtml(m.name)}${hinweis}</option>`;
            }).join('');
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

    // Die Sitzung wird VOR dem Aufbau der Auswahl geholt: ihr Mitglied muss in
    // der Liste stehen, auch wenn es im gewaehlten Jahr nicht mehr aktiv ist.
    let session = null;
    if (sessionId) {
        session = await apiCall('work_sessions', 'GET', null, { id: sessionId });
        if (!session || session.success === false) return;
    }

    const memberGroup = document.getElementById('workSessionMemberGroup');
    const memberSelect = document.getElementById('workSessionMember');

    if (isAdminOrManager && memberSelect) {
        memberGroup.style.display = '';
        // Der Members-Cache ist jahresbasiert: dataCache.members[jahr].data
        const members = dataCache.members?.[currentYear]?.data || [];

        // Wer im gewaehlten Jahr keine Mitgliedschaft hatte, steht nicht zur
        // Wahl — fuer einen Zeitraum ohne Mitgliedschaft soll gar kein Eintrag
        // entstehen koennen. Dasselbe Kriterium nutzen Statistik, Anwesenheit
        // und Ausnahmen (is_active_in_period).
        //
        // Ausnahme ist das Mitglied eines BESTEHENDEN Eintrags: faellt es aus
        // der Liste, bliebe das Auswahlfeld leer und das Speichern schoebe den
        // Eintrag stillschweigend auf ein anderes Mitglied. Es bleibt drin und
        // wird gekennzeichnet.
        const waehlbar = members.filter(m =>
            m.is_active_in_period || (session && m.member_id == session.member_id));

        memberSelect.innerHTML = waehlbar.map(m => {
            const hinweis = m.is_active_in_period ? '' : ' (inaktiv)';
            return `<option value="${m.member_id}">`
                 + `${escapeHtml(m.surname)}, ${escapeHtml(m.name)}${hinweis}</option>`;
        }).join('');
    } else if (memberGroup) {
        memberGroup.style.display = 'none';
    }

    if (session) {
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

/** ISO-Datum (YYYY-MM-DD) ohne Zeitzonenversatz. */
function isoDate(date) {
    const pad = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Obergrenze eines Auswertungszeitraums in Monaten.
 *
 * Spiegelt WORKTIME_MAX_PERIOD_MONTHS aus private/helpers/worktime.php. Die
 * Prüfung dort bleibt die verbindliche; hier steht sie, damit der Fehler als
 * Meldung im Dialog erscheint und nicht als nacktes JSON in einem neuen Tab —
 * der Bericht wird über einen Seitenaufruf geholt, nicht über fetch.
 */
const REPORT_MAX_PERIOD_MONTHS = 24;

/**
 * Letzter zulässiger Tag zu einem Beginn: Beginn + 24 Monate - 1 Tag.
 * Dieselbe Rechnung wie serverseitig, damit Client und Server nicht an
 * unterschiedlichen Stellen die Grenze ziehen.
 */
function latestAllowedTo(fromIso) {
    const [y, m, d] = fromIso.split('-').map(Number);
    const limit = new Date(y, m - 1, d);
    limit.setMonth(limit.getMonth() + REPORT_MAX_PERIOD_MONTHS);
    limit.setDate(limit.getDate() - 1);
    return isoDate(limit);
}

/**
 * Belegt die Zeitraumfelder vor.
 *
 * Die Schnellwahl ist genau das — eine Vorbelegung zweier Felder. Der Server
 * kennt nur from/to; ein Monat ist dort kein eigener Fall.
 */
export function setWorktimeReportRange(kind) {
    const now  = new Date();
    const from = document.getElementById('reportFrom');
    const to   = document.getElementById('reportTo');
    if (!from || !to) return;

    let start, end;

    if (kind === 'this-month') {
        start = new Date(now.getFullYear(), now.getMonth(), 1);
        end   = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    } else if (kind === 'last-month') {
        start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
        end   = new Date(now.getFullYear(), now.getMonth(), 0);
    } else {
        // Das laufende Jahr richtet sich nach dem Jahresfilter der Anwendung,
        // nicht nach dem heutigen Datum -- sonst widerspraeche der Bericht der
        // Liste, die daneben steht.
        start = new Date(currentYear, 0, 1);
        end   = new Date(currentYear, 11, 31);
    }

    from.value = isoDate(start);
    to.value   = isoDate(end);
}

export function openWorktimeReportModal() {
    const modal = document.getElementById('worktimeReportModal');
    if (!modal) return;

    // Mitgliederliste aus der Filterleiste uebernehmen: Dort sind die
    // Mitgliedschaftszeitraeume bereits beruecksichtigt und ausgetretene
    // Mitglieder gekennzeichnet statt ausgeschlossen.
    const filterMembers = document.getElementById('filterWorktimeMember');
    const reportMember  = document.getElementById('reportMember');
    if (filterMembers && reportMember) {
        reportMember.innerHTML = filterMembers.innerHTML;
        reportMember.value     = filterMembers.value || '';
    }

    setWorktimeReportRange('year');
    updateWorktimeReportForm();
    modal.classList.add('active');
}

export function closeWorktimeReportModal() {
    document.getElementById('worktimeReportModal')?.classList.remove('active');
}

/** Die Mitgliedsauswahl gilt nur fuer den Stundennachweis. */
function updateWorktimeReportForm() {
    const type  = document.getElementById('reportType')?.value;
    const group = document.getElementById('reportMemberGroup');
    if (group) {
        group.style.display = (type === 'worktime_member') ? '' : 'none';
    }
}

/**
 * Oeffnet den Bericht im gewaehlten Format.
 *
 * CSV laedt herunter, die Druckansicht oeffnet eine eigene Seite -- sie soll
 * gelesen werden koennen, bevor sie gedruckt wird, und das Dashboard soll
 * dabei stehen bleiben.
 */
export function runWorktimeReport(format) {
    const type = document.getElementById('reportType')?.value || 'worktime_member';
    const from = document.getElementById('reportFrom')?.value || '';
    const to   = document.getElementById('reportTo')?.value || '';

    if (!from || !to) {
        showToast('Bitte einen Zeitraum angeben', 'error');
        return;
    }
    if (to < from) {
        showToast('Das Ende des Zeitraums liegt vor seinem Beginn', 'error');
        return;
    }
    if (to > latestAllowedTo(from)) {
        showToast(`Der Zeitraum darf höchstens ${REPORT_MAX_PERIOD_MONTHS} Monate umfassen`,
                  'error');
        return;
    }

    const params = new URLSearchParams({ resource: 'export', type, from, to });

    if (type === 'worktime_member') {
        const member = document.getElementById('reportMember')?.value || '';
        if (member) params.set('member_id', member);
    }
    if (format === 'html') {
        params.set('format', 'html');
    }

    const url = `${API_BASE}?${params.toString()}`;

    if (format === 'html') {
        window.open(url, '_blank', 'noopener');
    } else {
        window.location.href = url;
    }

    closeWorktimeReportModal();
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
        tbody.innerHTML = '<tr><td colspan="7" class="loading">Noch keine Tätigkeitsarten angelegt.</td></tr>';
        return;
    }

    tbody.innerHTML = activityTypes.map(a => {
        const groupBadges = (a.groups && a.groups.length > 0)
            ? a.groups.map(g => `<span class="type-badge">${escapeHtml(g.group_name)}</span>`).join(' ')
            : '<span style="color: #7f8c8d;">Keine</span>';

        return `<tr>
        <td>${activityDot(a.color)}${escapeHtml(a.activity_name)}</td>
        <td>${escapeHtml(a.description || '—')}</td>
        <td><span style="display: inline-block; width: 20px; height: 20px;
            background: ${escapeHtml(a.color || '#1F5FBF')}; border-radius: 3px;
            border: 1px solid #ddd;"></span></td>
        <td>${VERIFICATION_LABEL[a.verification] || escapeHtml(a.verification)}</td>
        <td>${groupBadges}</td>
        <td>${a.is_active == 1
                ? '<span class="status-badge status-approved">aktiv</span>'
                : '<span class="type-badge">ausgemustert</span>'}</td>
        <td class="actions-cell">
            <button class="action-btn btn-icon btn-edit" title="Bearbeiten"
                onclick="openActivityTypeModal(${a.activity_id})">✎</button>
            <button class="action-btn btn-icon btn-delete" title="Löschen"
                onclick="deleteActivityType(${a.activity_id}, '${escapeHtml(a.activity_name).replace(/'/g, "\\'")}')">🗑</button>
        </td>
    </tr>`;
    }).join('');
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

    // dataCache.groups.data ist nicht garantiert gefüllt (nur via loadGroups() in
    // management.js) — die Zeiterfassung kann geöffnet werden, ohne dass die
    // Mitgliederverwaltung je besucht wurde.
    await loadGroups();
    renderActivityGroups(activity ? (activity.groups || []) : []);

    modal.classList.add('active');
}

function renderActivityGroups(selectedGroups) {
    const container = document.getElementById('activityGroupsList');
    if (!container) return;

    const selectedIds = (selectedGroups || []).map(g => g.group_id);

    container.innerHTML = dataCache.groups.data.map(group => `
        <label style="display: block; padding: 8px; cursor: pointer; border-radius: 4px;">
            <input type="checkbox"
                   class="activity-group-checkbox"
                   value="${group.group_id}"
                   ${selectedIds.includes(group.group_id) ? 'checked' : ''}>
            <span style="margin-left: 8px;">${escapeHtml(group.group_name)}</span>
        </label>
    `).join('');
}

export function closeActivityTypeModal() {
    document.getElementById('activityTypeModal')?.classList.remove('active');
}

export async function saveActivityType() {
    const activityId = document.getElementById('activityTypeId').value;

    const groupIds = [...document.querySelectorAll('.activity-group-checkbox:checked')]
        .map(cb => parseInt(cb.value, 10));

    const body = {
        activity_name: document.getElementById('activityTypeName').value.trim(),
        description: document.getElementById('activityTypeDescription').value.trim(),
        color: document.getElementById('activityTypeColor').value,
        verification: document.getElementById('activityTypeVerification').value,
        is_active: document.getElementById('activityTypeActive').checked ? 1 : 0,
        group_ids: groupIds
    };

    if (!body.activity_name) {
        showToast('Ein Name ist erforderlich', 'error');
        return;
    }

    // Der Server weist das ebenfalls ab; hier steht es nur, damit die Meldung
    // sofort kommt und beim Feld bleibt, statt als Serverfehler zurueck.
    if (groupIds.length === 0) {
        showToast('Bitte mindestens eine Gruppe auswählen', 'warning');
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

    // Der Berichtsdialog haengt bewusst an addEventListener statt an
    // onclick-Attributen: Jedes weitere Inline-Attribut verlaengert den Weg zu
    // einer wirksamen CSP (OI-17 in docs/OPEN-ITEMS.md).
    document.getElementById('btnWorktimeReport')
        ?.addEventListener('click', openWorktimeReportModal);
    document.getElementById('btnWorktimeReportClose')
        ?.addEventListener('click', closeWorktimeReportModal);
    document.getElementById('btnWorktimeReportCancel')
        ?.addEventListener('click', closeWorktimeReportModal);
    document.getElementById('btnWorktimeReportCsv')
        ?.addEventListener('click', () => runWorktimeReport('csv'));
    document.getElementById('btnWorktimeReportPrint')
        ?.addEventListener('click', () => runWorktimeReport('html'));
    document.getElementById('reportType')
        ?.addEventListener('change', updateWorktimeReportForm);

    document.querySelectorAll('[data-report-range]').forEach(btn => {
        btn.addEventListener('click', () => setWorktimeReportRange(btn.dataset.reportRange));
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
window.openWorktimeReportModal = openWorktimeReportModal;
window.closeWorktimeReportModal = closeWorktimeReportModal;

window.openActivityTypeModal = openActivityTypeModal;
window.closeActivityTypeModal = closeActivityTypeModal;
window.saveActivityType = saveActivityType;
window.deleteActivityType = deleteActivityType;
