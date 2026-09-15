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
import { showToast, showConfirm, invalidateCache } from './ui.js';
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

// Race-Schutz: reloadResponses() wird mehrfach ueberlappend aufgerufen
// (schneller Klick auf mehrere Termine, Aenderung waehrend ein Abruf laeuft).
// loadToken zaehlt jeden Abruf durch, openAppointmentId haelt fest, welcher
// Termin gerade im Modal steht -- nur die aktuellste Antwort fuer den noch
// offenen Termin darf current ueberschreiben.
let loadToken = 0;
let openAppointmentId = null;

// Nach einer Aenderung wird NICHT sofort die ganze Terminliste neu geladen
// (das waere pro Klick in der Tabelle ein weiterer API-Aufruf). Stattdessen
// nur die Anfrage-Zwischenspeicher des Jahres verwerfen und einmal beim
// Schliessen des Modals nachladen, wenn sich tatsaechlich etwas geaendert hat.
let listDirty = false;

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

    // Wer selbst nicht zu diesem Termin erwartet wird und auch nicht
    // verwaltet, bekommt keine anklickbare Zelle -- das Modal wuerde ihm
    // ohnehin nur eine leere oder fremde Mitgliederliste zeigen.
    if (!apt.responses.expected && !isAdminOrManager) {
        return `<span class="response-summary-text">${formatResponseSummary(apt.responses)}</span>${own}`;
    }

    return `<button type="button" class="response-summary-btn" onclick="openResponsesModal(${Number(apt.appointment_id)})" title="Rückmeldungen anzeigen">${formatResponseSummary(apt.responses)}</button>${own}`;
}

export async function openResponsesModal(appointmentId) {
    currentFilter = 'all';
    openAppointmentId = appointmentId;
    document.getElementById('responsesModalBody').innerHTML = '<p class="loading">Lade Rückmeldungen...</p>';
    document.getElementById('responsesModal').classList.add('active');
    await reloadResponses(appointmentId);
}

export function closeResponsesModal() {
    document.getElementById('responsesModal').classList.remove('active');
    current = null;
    openAppointmentId = null;

    // Erst jetzt, statt nach jeder einzelnen Aenderung, die Terminliste
    // nachladen -- auf der bereits angezeigten Seite, ohne den Aufruf,
    // wenn im Modal gar nichts geaendert wurde.
    if (listDirty) {
        listDirty = false;
        window.refreshAppointmentsKeepPage?.();
    }
}

async function reloadResponses(appointmentId) {
    const token = ++loadToken;
    const result = await apiCall('appointment_responses', 'GET', null, { appointment_id: appointmentId });

    // Waehrend des Abrufs wurde das Modal geschlossen, ein anderer Termin
    // geoeffnet, oder ein neuerer Abruf fuer denselben Termin gestartet:
    // diese Antwort ist ueberholt und darf current nicht mehr anfassen.
    if (token !== loadToken || openAppointmentId !== appointmentId) {
        return;
    }

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
        ? 'Frist abgelaufen – Änderung wird als kurzfristig vermerkt.'
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
                aria-pressed="${own?.status === s ? 'true' : 'false'}"
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
        <button type="button" class="response-tile${currentFilter === filter ? ' is-active' : ''}"
                aria-pressed="${currentFilter === filter ? 'true' : 'false'}" onclick="filterResponses('${filter}')">
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
        const memberLabel = `${escapeHtml(m.name)} ${escapeHtml(m.surname)}`;

        return `${groupRow}
            <tr>
                <td>${escapeHtml(m.surname)}, ${escapeHtml(m.name)}</td>
                <td>${statusBadge(m.status)}${m.is_late ? ' <span class="response-late">kurzfristig</span>' : ''}${excuse}</td>
                <td>${escapeHtml(m.comment ?? '')}</td>
                <td>${escapeHtml(formatDateTimeDe(m.status_changed_at))}</td>
                ${started ? `<td>${m.present ? 'anwesend' : '–'}</td>` : ''}
                <td>
                    <select class="response-set" aria-label="Rückmeldung für ${memberLabel} setzen"
                            onchange="setMemberResponse(${Number(m.member_id)}, this.value); this.value = '';">
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
                    ${started ? '<th>Anwesenheit</th>' : ''}<th>Aktion</th>
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

/**
 * Nach einer erfolgreichen Aenderung: Terminliste als veraltet markieren
 * (einmalig beim Schliessen nachgeladen, siehe closeResponsesModal) und,
 * falls das Modal noch denselben Termin zeigt, dessen Rueckmeldungen neu
 * laden. appointmentId/year kommen als Parameter vom Aufrufer, der sie VOR
 * seinem eigenen await aus current gelesen hat -- current kann sich waehrend
 * eines await veraendert haben (anderer Termin geoeffnet, Modal geschlossen).
 */
async function afterChange(appointmentId, year) {
    invalidateCache('appointments', year);
    listDirty = true;
    if (openAppointmentId === appointmentId) {
        await reloadResponses(appointmentId);
    }
}

async function submitResponse(body, memberId = null) {
    if (!current) return;
    const appointmentId = current.appointment.appointment_id;
    const year = Number(current.appointment.date.substring(0, 4));

    const params = { appointment_id: appointmentId };
    if (memberId !== null) params.member_id = memberId;

    const result = await apiCall('appointment_responses', 'PUT', body, params);
    if (!result || !result.success) return;

    showToast('Rückmeldung gespeichert', 'success');
    await afterChange(appointmentId, year);
}

export async function setOwnResponse(status) {
    if (!current) return;
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
    if (!current) return;
    const appointmentId = current.appointment.appointment_id;
    const year = Number(current.appointment.date.substring(0, 4));
    const pendingExcuse = current.own?.excuse_state === 'pending';

    const confirmed = await showConfirm(pendingExcuse
        ? 'Rückmeldung zurücknehmen? Der offene Entschuldigungsantrag wird ebenfalls gelöscht.'
        : 'Rückmeldung zurücknehmen?');
    if (!confirmed) return;
    // Waehrend des Confirm-Dialogs kann das Modal geschlossen oder ein
    // anderer Termin geoeffnet worden sein -- mit den vorher erfassten
    // Werten weiterarbeiten, aber nur, wenn dieser Termin noch offen ist.
    if (openAppointmentId !== appointmentId) return;

    const result = await apiCall('appointment_responses', 'DELETE', null, { appointment_id: appointmentId });
    if (!result || !result.success) return;
    showToast('Rückmeldung zurückgenommen', 'success');
    await afterChange(appointmentId, year);
}

export async function setMemberResponse(memberId, value) {
    if (value === '' || !current) return;
    const appointmentId = current.appointment.appointment_id;
    const year = Number(current.appointment.date.substring(0, 4));

    if (value === 'delete') {
        const member = current.members?.find(m => Number(m.member_id) === Number(memberId));
        const pendingExcuse = member?.excuse_state === 'pending';

        const confirmed = await showConfirm(pendingExcuse
            ? 'Rückmeldung zurücknehmen? Der offene Entschuldigungsantrag wird ebenfalls gelöscht.'
            : 'Rückmeldung zurücknehmen?');
        if (!confirmed) return;
        if (openAppointmentId !== appointmentId) return;

        const result = await apiCall('appointment_responses', 'DELETE', null,
            { appointment_id: appointmentId, member_id: memberId });
        if (result && result.success) {
            showToast('Rückmeldung zurückgenommen', 'success');
            await afterChange(appointmentId, year);
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
