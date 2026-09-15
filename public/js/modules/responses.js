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
import { apiCall, isAdminOrManager } from './api.js';
import { showToast, invalidateCache } from './ui.js';
import { escapeHtml, translateExceptionStatus } from './utils.js';

// ============================================
// TERMINRUECKMELDUNG (FI-1)
// Spec: docs/superpowers/specs/2026-09-14-terminrueckmeldung-design.md
//
// Kein dataCache: Rueckmeldungen sollen aktuell sein, und der Abruf je
// Termin ist klein. Nach einer Aenderung wird nur die Terminliste des
// Jahres invalidiert, damit ihre Summen stimmen.
// ============================================

export const RESPONSE_LABELS = { yes: 'Zusage', maybe: 'Unsicher', no: 'Absage' };
const RESPONSE_ICONS = { yes: '✓', maybe: '?', no: '✗' };

let current = null;          // letzte API-Antwort des offenen Modals
let currentFilter = 'all';

function formatDateTimeDe(mysql) {
    if (!mysql) return '';
    const d = new Date(mysql.replace(' ', 'T'));
    return `${d.toLocaleDateString('de-DE')} ${d.toTimeString().substring(0, 5)}`;
}

export function formatResponseSummary(summary) {
    if (!summary) return '';
    return `✓ ${summary.yes} · ? ${summary.maybe} · ✗ ${summary.no} · — ${summary.open}`;
}

/** Zelle der Terminliste. */
export function responseSummaryCell(apt) {
    if (!apt.responses) {
        return '<span class="response-none">–</span>';
    }
    const own = apt.responses.own
        ? ` <span class="response-own response-own--${apt.responses.own}" title="Eigene Rückmeldung: ${RESPONSE_LABELS[apt.responses.own]}">${RESPONSE_ICONS[apt.responses.own]}</span>`
        : '';

    return `<button type="button" class="response-summary-btn" onclick="openResponsesModal(${Number(apt.appointment_id)})" title="Rückmeldungen anzeigen">${formatResponseSummary(apt.responses)}</button>${own}`;
}

export async function openResponsesModal(appointmentId) {
    currentFilter = 'all';
    document.getElementById('responsesModalBody').innerHTML = '<p class="loading">Lade Rückmeldungen...</p>';
    document.getElementById('responsesModal').classList.add('active');
    await reloadResponses(appointmentId);
}

export function closeResponsesModal() {
    document.getElementById('responsesModal').classList.remove('active');
    current = null;
}

async function reloadResponses(appointmentId) {
    const result = await apiCall('appointment_responses', 'GET', null, { appointment_id: appointmentId });
    if (!result || !result.success) {
        closeResponsesModal();   // apiCall hat den Fehler bereits gemeldet
        return;
    }
    current = result;
    renderResponsesModal();
}

function deadlineText(data) {
    if (data.started) return 'Der Termin hat begonnen.';
    const deadline = new Date(data.settings.deadline.replace(' ', 'T'));
    return deadline < new Date()
        ? 'Frist abgelaufen – Änderungen werden als kurzfristig vermerkt.'
        : `Rückmeldung bis ${formatDateTimeDe(data.settings.deadline)} Uhr`;
}

function renderResponsesModal() {
    const data = current;
    const apt = data.appointment;
    const date = new Date(apt.date + 'T00:00:00').toLocaleDateString('de-DE');

    document.getElementById('responsesModalTitle').textContent =
        `Rückmeldungen: ${apt.title} (${date}, ${apt.start_time.substring(0, 5)})`;

    let html = `<p class="response-deadline">${escapeHtml(deadlineText(data))}</p>`;
    html += `<div class="response-summary">${formatResponseSummary(data.summary)}</div>`;

    if (data.expected) html += ownResponseHtml(data);
    if (data.comparison) html += comparisonHtml(data.comparison);
    if (data.members) html += isAdminOrManager ? managerTableHtml(data) : namesListHtml(data.members);

    document.getElementById('responsesModalBody').innerHTML = html;
    document.getElementById('responsesPrintButton').hidden = !isAdminOrManager;
}

function ownResponseHtml(data) {
    const own = data.own;
    const disabled = data.started ? 'disabled' : '';
    const buttons = ['yes', 'maybe', 'no'].map(s => `
        <button type="button" class="response-segment__btn response-segment__btn--${s}${own?.status === s ? ' is-active' : ''}"
                ${disabled} onclick="setOwnResponse('${s}')">${RESPONSE_ICONS[s]} ${RESPONSE_LABELS[s]}</button>`).join('');

    return `
        <div class="response-own-block">
            <h3>Meine Rückmeldung ${own?.is_late ? '<span class="response-late">kurzfristig</span>' : ''}</h3>
            <div class="response-segment">${buttons}</div>
            <label for="responseOwnComment">Bemerkung</label>
            <textarea id="responseOwnComment" rows="2" maxlength="255" ${disabled}>${escapeHtml(own?.comment ?? '')}</textarea>
            ${data.settings.require_excuse ? '<small class="input-hint">Eine Absage wird als Entschuldigung eingereicht und braucht eine Begründung.</small>' : ''}
            ${own?.excuse_state ? `<small class="input-hint">Entschuldigung: ${escapeHtml(translateExceptionStatus(own.excuse_state))}</small>` : ''}
            ${own && !data.started ? `
                <div class="response-own-actions">
                    <button type="button" class="btn-secondary" onclick="saveOwnComment()">Bemerkung speichern</button>
                    <button type="button" class="btn-cancel" onclick="withdrawOwnResponse()">Zurücknehmen</button>
                </div>` : ''}
        </div>`;
}

function comparisonHtml(c) {
    const tiles = [
        ['yes_present', 'Zugesagt und gekommen', c.yes_present],
        ['yes_absent', 'Zugesagt, nicht gekommen', c.yes_absent],
        ['no_present', 'Abgesagt, trotzdem da', c.no_present],
        ['open', 'Keine Antwort', c.none_present + c.none_absent],
    ];

    return `<div class="response-tiles">${tiles.map(([filter, label, count]) => `
        <button type="button" class="response-tile${currentFilter === filter ? ' is-active' : ''}" onclick="filterResponses('${filter}')">
            <span class="response-tile__count">${Number(count)}</span>
            <span class="response-tile__label">${label}</span>
        </button>`).join('')}</div>`;
}

function matchesFilter(m, filter) {
    switch (filter) {
        case 'open':        return m.status === null;
        case 'yes_present': return m.status === 'yes' && m.present === true;
        case 'yes_absent':  return m.status === 'yes' && m.present === false;
        case 'no_present':  return m.status === 'no' && m.present === true;
        default:            return true;
    }
}

function statusBadge(status) {
    return status === null
        ? '<span class="response-badge response-badge--none">keine Antwort</span>'
        : `<span class="response-badge response-badge--${status}">${RESPONSE_LABELS[status]}</span>`;
}

function managerTableHtml(data) {
    const started = data.started;
    let lastGroup = null;
    const colspan = started ? 6 : 5;

    const rows = data.members.filter(m => matchesFilter(m, currentFilter)).map(m => {
        let groupRow = '';
        if (m.group_name !== lastGroup) {
            lastGroup = m.group_name;
            groupRow = `<tr class="response-group-row"><td colspan="${colspan}">${escapeHtml(m.group_name)}</td></tr>`;
        }
        const excuse = m.excuse_state
            ? `<br><small>Entschuldigung: ${escapeHtml(translateExceptionStatus(m.excuse_state))}</small>` : '';

        return `${groupRow}
            <tr>
                <td>${escapeHtml(m.surname)}, ${escapeHtml(m.name)}</td>
                <td>${statusBadge(m.status)}${m.is_late ? ' <span class="response-late">kurzfristig</span>' : ''}${excuse}</td>
                <td>${escapeHtml(m.comment ?? '')}</td>
                <td>${escapeHtml(formatDateTimeDe(m.status_changed_at))}</td>
                ${started ? `<td>${m.present ? 'anwesend' : '–'}</td>` : ''}
                <td>
                    <select class="response-set" onchange="setMemberResponse(${Number(m.member_id)}, this.value); this.value = '';">
                        <option value="">Setzen …</option>
                        <option value="yes">Zusage</option>
                        <option value="maybe">Unsicher</option>
                        <option value="no">Absage</option>
                        ${m.status !== null ? '<option value="delete">Zurücknehmen</option>' : ''}
                    </select>
                </td>
            </tr>`;
    }).join('');

    return `
        <div class="response-filter">
            <label><input type="radio" name="responseFilter" ${currentFilter === 'all' ? 'checked' : ''} onchange="filterResponses('all')"> Alle</label>
            <label><input type="radio" name="responseFilter" ${currentFilter === 'open' ? 'checked' : ''} onchange="filterResponses('open')"> Keine Antwort</label>
        </div>
        <div class="data-table">
            <table>
                <thead><tr>
                    <th>Name</th><th>Rückmeldung</th><th>Bemerkung</th><th>Zeitpunkt</th>
                    ${started ? '<th>Anwesenheit</th>' : ''}<th></th>
                </tr></thead>
                <tbody>${rows || `<tr><td colspan="${colspan}" class="loading">Keine Einträge</td></tr>`}</tbody>
            </table>
        </div>`;
}

function namesListHtml(members) {
    return `<ul class="response-names">${members.map(m =>
        `<li>${escapeHtml(m.name)} ${escapeHtml(m.surname)}: ${m.status ? RESPONSE_LABELS[m.status] : 'keine Antwort'}</li>`
    ).join('')}</ul>`;
}

export function filterResponses(filter) {
    currentFilter = filter;
    renderResponsesModal();
}

async function afterChange() {
    const apt = current.appointment;
    invalidateCache('appointments', Number(apt.date.substring(0, 4)));
    await reloadResponses(apt.appointment_id);
    // window statt Import: appointments.js importiert dieses Modul bereits.
    await window.showAppointmentSection?.(true);
}

async function submitResponse(body, memberId = null) {
    const params = { appointment_id: current.appointment.appointment_id };
    if (memberId !== null) params.member_id = memberId;

    const result = await apiCall('appointment_responses', 'PUT', body, params);
    if (!result || !result.success) return;

    showToast('Rückmeldung gespeichert', 'success');
    await afterChange();
}

export async function setOwnResponse(status) {
    const comment = document.getElementById('responseOwnComment')?.value.trim() ?? '';
    if (status === 'no' && current.settings.require_excuse && comment === '') {
        showToast('Bitte eine Begründung für die Absage eintragen', 'warning');
        document.getElementById('responseOwnComment')?.focus();
        return;
    }
    await submitResponse({ status, comment: comment || null });
}

export async function saveOwnComment() {
    if (!current?.own) return;
    await setOwnResponse(current.own.status);
}

export async function withdrawOwnResponse() {
    const result = await apiCall('appointment_responses', 'DELETE', null,
        { appointment_id: current.appointment.appointment_id });
    if (!result || !result.success) return;
    showToast('Rückmeldung zurückgenommen', 'success');
    await afterChange();
}

export async function setMemberResponse(memberId, value) {
    if (value === '') return;

    if (value === 'delete') {
        const result = await apiCall('appointment_responses', 'DELETE', null,
            { appointment_id: current.appointment.appointment_id, member_id: memberId });
        if (result && result.success) {
            showToast('Rückmeldung zurückgenommen', 'success');
            await afterChange();
        }
        return;
    }

    let comment = null;
    if (value === 'no' && current.settings.require_excuse) {
        comment = (window.prompt('Begründung der Absage (wird als Entschuldigung eingereicht):') ?? '').trim();
        if (comment === '') {
            showToast('Ohne Begründung wird die Absage nicht gespeichert', 'warning');
            return;
        }
    }
    await submitResponse({ status: value, comment }, memberId);
}

export function printResponses() {
    const id = current?.appointment?.appointment_id;
    if (!id) return;
    const params = new URLSearchParams({ resource: 'appointment_responses', appointment_id: id, format: 'html' });
    window.open(`${API_BASE}?${params.toString()}`, '_blank', 'noopener');
}

window.openResponsesModal = openResponsesModal;
window.closeResponsesModal = closeResponsesModal;
window.setOwnResponse = setOwnResponse;
window.saveOwnComment = saveOwnComment;
window.withdrawOwnResponse = withdrawOwnResponse;
window.setMemberResponse = setMemberResponse;
window.filterResponses = filterResponses;
window.printResponses = printResponses;
