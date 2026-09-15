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
import { showToast, showConfirm, showReasonDialog, invalidateCache } from './ui.js';
import { escapeHtml, translateExceptionStatus } from './utils.js';

// ============================================
// TERMINRUECKMELDUNG (FI-1)
// Spec: docs/superpowers/specs/2026-09-14-terminrueckmeldung-design.md
//
// Kein dataCache: Rueckmeldungen sollen aktuell sein, und der Abruf je
// Termin ist klein. Nach einer Aenderung wird nur die Terminliste des
// Jahres invalidiert, damit ihre Summen stimmen.
// ============================================

export const RESPONSE_LABELS = { yes: 'Zusage', maybe: 'Unsicher', no: 'Absage', open: 'Ohne Antwort' };
export const RESPONSE_ICONS = { yes: '✓', maybe: '?', no: '✗', open: '—' };

// Reihenfolge der Ampel-Chips in Terminliste und Modal (yes/maybe/no/open).
const CHIP_ORDER = ['yes', 'maybe', 'no', 'open'];

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

/** Ausgeschriebener Tooltip-Text der Ampel, z.B. fuer title/aria-label. */
export function responseSummaryTitle(summary) {
    return `${Number(summary.yes)} Zusagen · ${Number(summary.maybe)} unsicher · `
        + `${Number(summary.no)} Absagen · ${Number(summary.open)} ohne Antwort`;
}

/**
 * Chip-Gruppe der Ampel (Zusage/Unsicher/Absage/Ohne Antwort). `large` schaltet
 * die beschriftete Modal-Variante ein (Terminliste bleibt bei Icon + Zahl).
 */
export function responseChipsHtml(summary, { large = false } = {}) {
    const chips = CHIP_ORDER.map(key => {
        const count = Number(summary[key] ?? 0);
        const zero = count === 0 ? ' is-zero' : '';
        const label = large ? `<span class="response-chip__label">${RESPONSE_LABELS[key]}</span>` : '';
        return `<span class="response-chip response-chip--${key}${zero}">${label}`
            + `<span class="response-chip__icon" aria-hidden="true">${RESPONSE_ICONS[key]}</span>`
            + `<span class="response-chip__count">${count}</span></span>`;
    }).join('');
    return `<span class="response-chip-group${large ? ' response-chip-group--lg' : ''}">${chips}</span>`;
}

/** Summenblock des Modals: beschriftete Chips plus gestapelter Balken. */
function responseSummaryBlock(summary) {
    const total = CHIP_ORDER.reduce((sum, key) => sum + Number(summary[key] ?? 0), 0);
    const bar = total > 0 ? `
        <div class="response-bar" role="img" aria-label="${responseSummaryTitle(summary)}">
            ${CHIP_ORDER.filter(key => Number(summary[key]) > 0).map(key =>
                `<span class="response-bar__seg response-bar__seg--${key}" style="width:${(Number(summary[key]) / total * 100).toFixed(2)}%"></span>`
            ).join('')}
        </div>` : '';
    return `<div class="response-summary">${responseChipsHtml(summary, { large: true })}</div>${bar}`;
}

/** Zelle der Terminliste. */
export function responseSummaryCell(apt) {
    if (!apt.responses) {
        return '<span class="response-none">–</span>';
    }
    const own = apt.responses.own
        ? ` <span class="response-own response-own--${apt.responses.own}" title="Eigene Rückmeldung: ${RESPONSE_LABELS[apt.responses.own]}">${RESPONSE_ICONS[apt.responses.own]}</span>`
        : '';
    const title = responseSummaryTitle(apt.responses);
    const chips = responseChipsHtml(apt.responses);

    // Wer selbst nicht zu diesem Termin erwartet wird und auch nicht
    // verwaltet, bekommt keine anklickbare Zelle -- das Modal wuerde ihm
    // ohnehin nur eine leere oder fremde Mitgliederliste zeigen.
    if (!apt.responses.expected && !isAdminOrManager) {
        return `<span class="response-summary-text" title="${title}">${chips}</span>${own}`;
    }

    return `<button type="button" class="response-summary-btn" onclick="openResponsesModal(${Number(apt.appointment_id)})" title="${title}">${chips}</button>${own}`;
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
    if (data.started) {
        // Unterscheidung nach Kalendertag, nicht nach Uhrzeit: ein heute
        // begonnener Termin bekommt weiterhin den alten Text, ein laengst
        // vergangener einen eigenen -- der bisherige Text wirkte sonst auch
        // Wochen spaeter noch, als koennte man gleich mitmachen.
        const heute = new Date();
        const heuteStr = `${heute.getFullYear()}-${String(heute.getMonth() + 1).padStart(2, '0')}-${String(heute.getDate()).padStart(2, '0')}`;
        if (data.appointment.date < heuteStr) {
            return 'Der Termin ist vorbei – Rückmeldungen sind abgeschlossen.';
        }
        return 'Der Termin hat begonnen.';
    }
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
    html += responseSummaryBlock(data.summary);

    if (data.expected) html += ownResponseHtml(data);
    if (data.comparison) html += comparisonHtml(data.comparison);
    if (data.members) html += isAdminOrManager ? managerTableHtml(data) : namesListHtml(data.members);

    document.getElementById('responsesModalBody').innerHTML = html;
    document.getElementById('responsesPrintButton').hidden = !isAdminOrManager;
}

function ownResponseHtml(data) {
    // G7: Ein vergangener Termin bekommt keine Bedienelemente mehr (Formular
    // waere nutzlos), nur noch eine kompakte, lesende Zeile.
    if (data.started) {
        return ownResponseCompactHtml(data);
    }

    const own = data.own;
    const buttons = ['yes', 'maybe', 'no'].map(s => `
        <button type="button" class="response-segment__btn response-segment__btn--${s}${own?.status === s ? ' is-active' : ''}"
                aria-pressed="${own?.status === s ? 'true' : 'false'}"
                onclick="setOwnResponse('${s}')">${RESPONSE_ICONS[s]} ${RESPONSE_LABELS[s]}</button>`).join('');

    // G6: "kurzfristig" nur bei einer Absage zeigen -- Spec 3.4 spricht von
    // Absagen, is_late bleibt in der API fuer jeden Status unveraendert.
    return `
        <div class="response-own-block">
            <h3>Meine Rückmeldung ${own?.status === 'no' && own?.is_late ? '<span class="response-late">kurzfristig</span>' : ''}</h3>
            <div class="response-segment">${buttons}</div>
            <div class="form-group">
                <label for="responseOwnComment">Bemerkung</label>
                <textarea id="responseOwnComment" rows="2" maxlength="255">${escapeHtml(own?.comment ?? '')}</textarea>
                ${data.settings.require_excuse ? '<small class="input-hint">Eine Absage wird als Entschuldigung eingereicht und braucht eine Begründung.</small>' : ''}
                ${own?.excuse_state ? `<small class="input-hint">Entschuldigung: ${escapeHtml(translateExceptionStatus(own.excuse_state))}</small>` : ''}
            </div>
            ${own ? `
                <div class="response-own-actions">
                    <button type="button" class="btn-secondary" onclick="saveOwnComment()">Bemerkung speichern</button>
                    <button type="button" class="btn-cancel" onclick="withdrawOwnResponse()">Zurücknehmen</button>
                </div>` : ''}
        </div>`;
}

/**
 * G7: Kompakte, nur lesende Zeile der eigenen Rueckmeldung fuer einen
 * bereits begonnenen/vergangenen Termin -- ersetzt Segmentgruppe, Textfeld
 * und Aktionen dort vollstaendig. statusBadge() liefert denselben Badge wie
 * die Verwaltungstabelle, auch "keine Antwort" fuer einen fehlenden Datensatz.
 */
function ownResponseCompactHtml(data) {
    const own = data.own;
    const late = own?.status === 'no' && own?.is_late
        ? ' <span class="response-late">kurzfristig</span>' : '';
    const comment = own?.comment
        ? ` <span class="response-own-compact__comment">${escapeHtml(own.comment)}</span>` : '';
    const excuse = own?.excuse_state
        ? ` <span class="response-own-compact__excuse">Entschuldigung: ${escapeHtml(translateExceptionStatus(own.excuse_state))}</span>` : '';

    return `
        <div class="response-own-block response-own-block--compact">
            <p class="response-own-compact"><strong>Meine Rückmeldung:</strong> ${statusBadge(own?.status ?? null)}${late}${comment}${excuse}</p>
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

/** Vier quadratische Icon-Aktionen (Zusage/Unsicher/Absage/Zuruecknehmen) je Mitgliederzeile. */
function memberActionButtons(m) {
    const memberLabel = `${escapeHtml(m.name)} ${escapeHtml(m.surname)}`;
    const buttons = ['yes', 'maybe', 'no'].map(s => {
        const active = m.status === s;
        return `<button type="button" class="action-btn btn-icon response-action response-action--${s}${active ? ' is-active' : ''}"
                    aria-pressed="${active ? 'true' : 'false'}"
                    title="${RESPONSE_LABELS[s]} für ${memberLabel} setzen"
                    aria-label="${RESPONSE_LABELS[s]} für ${memberLabel} setzen"
                    onclick="setMemberResponse(${Number(m.member_id)}, '${s}')">${RESPONSE_ICONS[s]}</button>`;
    }).join('');

    const reset = m.status !== null
        ? `<button type="button" class="action-btn btn-icon response-action response-action--reset"
                title="Rückmeldung für ${memberLabel} zurücknehmen"
                aria-label="Rückmeldung für ${memberLabel} zurücknehmen"
                onclick="setMemberResponse(${Number(m.member_id)}, 'delete')">↺</button>`
        : '';

    return buttons + reset;
}

function managerTableHtml(data) {
    const started = data.started;
    let lastGroup = null;
    const colspan = started ? 6 : 5;
    const allCount = data.members.length;
    const openCount = data.members.filter(m => m.status === null).length;

    const rows = data.members.filter(m => matchesFilter(m, currentFilter)).map(m => {
        let groupRow = '';
        if (m.group_name !== lastGroup) {
            lastGroup = m.group_name;
            groupRow = `<tr class="response-group-row"><td colspan="${colspan}">${escapeHtml(m.group_name)}</td></tr>`;
        }
        const excuse = m.excuse_state
            ? `<br><small>Entschuldigung: ${escapeHtml(translateExceptionStatus(m.excuse_state))}</small>` : '';

        // G6: "kurzfristig" nur bei einer Absage.
        return `${groupRow}
            <tr>
                <td>${escapeHtml(m.surname)}, ${escapeHtml(m.name)}</td>
                <td>${statusBadge(m.status)}${m.status === 'no' && m.is_late ? ' <span class="response-late">kurzfristig</span>' : ''}${excuse}</td>
                <td>${escapeHtml(m.comment ?? '')}</td>
                <td>${escapeHtml(formatDateTimeDe(m.status_changed_at))}</td>
                ${started ? `<td>${m.present ? 'anwesend' : '–'}</td>` : ''}
                <td class="actions-cell">${memberActionButtons(m)}</td>
            </tr>`;
    }).join('');

    return `
        <div class="response-filter">
            <button type="button" class="response-filter__btn${currentFilter === 'all' ? ' is-active' : ''}"
                    aria-pressed="${currentFilter === 'all' ? 'true' : 'false'}" onclick="filterResponses('all')">Alle (${allCount})</button>
            <button type="button" class="response-filter__btn${currentFilter === 'open' ? ' is-active' : ''}"
                    aria-pressed="${currentFilter === 'open' ? 'true' : 'false'}" onclick="filterResponses('open')">Keine Antwort (${openCount})</button>
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

/** Gruppenname eines Mitglieds, null statt leer/undefiniert fuer "Ohne Gruppe" --
 * wie responseGroupKey() in der PWA (public/checkin/js/app.js). */
function responseGroupKey(m) {
    return m.group_name && String(m.group_name).trim() !== '' ? m.group_name : null;
}

/** Nachname vor Vorname zur Sortierung, "Vorname Nachname" bleibt die Anzeige --
 * wie sortByNameSurname() in der PWA. */
function sortByNameSurname(members) {
    return [...members].sort((a, b) =>
        a.surname.localeCompare(b.surname, 'de') || a.name.localeCompare(b.name, 'de'));
}

/** Ein Namens-Chip, nach Status eingefaerbt und mit Icon-Praefix statt nur
 * Farbe (Kontrast/Nicht-nur-Farbe) -- wie responseNameChip() in der PWA. */
function responseNameChip(m) {
    const key = m.status ?? 'open';
    return `<span class="response-name-chip response-name-chip--${key}">${RESPONSE_ICONS[key]} ${escapeHtml(m.name)} ${escapeHtml(m.surname)}</span>`;
}

/**
 * Antworten anderer Mitglieder (Rolle user, names_visible): nach Gruppe
 * gegliedert statt einer flachen Liste mit Aufzaehlungspunkten -- analog zu
 * responseNamesHtml() in der PWA (public/checkin/js/app.js). Gruppen
 * alphabetisch, Mitglieder ohne Gruppe zuletzt als "Ohne Gruppe"; je Gruppe
 * eine Ampel-Zeile aus responseChipsHtml() und darunter Namens-Chips
 * Zusage -> Unsicher -> Absage -> ohne Antwort (CHIP_ORDER), darin nach
 * Nachname/Vorname.
 */
function namesListHtml(members) {
    const groupNames = [...new Set(members.map(responseGroupKey))].sort((a, b) => {
        if (a === null) return 1;
        if (b === null) return -1;
        return a.localeCompare(b, 'de');
    });

    const groups = groupNames.map(groupName => {
        const groupMembers = members.filter(m => responseGroupKey(m) === groupName);
        const counts = { yes: 0, maybe: 0, no: 0, open: 0 };
        groupMembers.forEach(m => counts[m.status ?? 'open']++);

        const chips = CHIP_ORDER.map(key =>
            sortByNameSurname(groupMembers.filter(m => (m.status ?? 'open') === key)).map(responseNameChip).join('')
        ).join('');

        const label = groupName === null ? 'Ohne Gruppe' : groupName;

        return `<div class="response-name-group">
            <div class="response-name-group__heading"><span class="response-name-group__label">${escapeHtml(label)}</span> ${responseChipsHtml(counts)}</div>
            <div class="response-name-chips">${chips}</div>
        </div>`;
    }).join('');

    return `<div class="response-names-grouped">${groups}</div>`;
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

/**
 * Text der Ruecknahme-Bestaetigung (G3): Die Loeschung eines Antrags wird nur
 * angekuendigt, wenn die Rueckmeldung ihn selbst angelegt hat und er noch
 * offen ist. Ein nur verknuepfter, offener Antrag bleibt bei der Ruecknahme
 * bestehen -- das wird stattdessen gesagt.
 */
function withdrawConfirmText(excuseState, excuseCreated) {
    if (excuseState === 'pending' && excuseCreated) {
        return 'Rückmeldung zurücknehmen? Der offene Entschuldigungsantrag wird ebenfalls gelöscht.';
    }
    if (excuseState === 'pending') {
        return 'Rückmeldung zurücknehmen? Der verknüpfte Antrag bleibt bestehen.';
    }
    return 'Rückmeldung zurücknehmen?';
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

    // G4: Wechsel von einer Absage zu Zusage/Unsicher, waehrend das Feld noch
    // unveraendert die gespeicherte Begruendung der Absage traegt -- wie in
    // der PWA (public/checkin/js/app.js submitResponse()) nicht automatisch
    // an die neue Antwort haengen. Eine bewusst angepasste Bemerkung bleibt.
    const previousStatus = current.own?.status ?? null;
    const previousComment = current.own?.comment ?? '';
    const effectiveComment = (status !== 'no' && previousStatus === 'no' && comment === previousComment)
        ? ''
        : comment;

    await submitResponse({ status, comment: effectiveComment || null });
}

export async function saveOwnComment() {
    if (!current?.own) return;
    await setOwnResponse(current.own.status);
}

export async function withdrawOwnResponse() {
    if (!current) return;
    const appointmentId = current.appointment.appointment_id;
    const year = Number(current.appointment.date.substring(0, 4));

    const confirmed = await showConfirm(withdrawConfirmText(current.own?.excuse_state, current.own?.excuse_created));
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

    const member = current.members?.find(m => Number(m.member_id) === Number(memberId));

    // Klick auf den bereits aktiven Status der Zeile: kein erneuter Request.
    if (value !== 'delete' && member?.status === value) return;

    if (value === 'delete') {
        const confirmed = await showConfirm(withdrawConfirmText(member?.excuse_state, member?.excuse_created));
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

    // W1: Ein Verwalter, der nur den Status setzt, darf die bestehende
    // Bemerkung des Mitglieds nicht loeschen -- sie wird mitgeschickt, bei
    // einer Absage mit Entschuldigungspflicht als Vorgabe im Dialog.
    const existingComment = member?.comment ?? null;
    let comment = existingComment;
    if (value === 'no' && current.settings.require_excuse) {
        const memberLabel = member ? `${member.name} ${member.surname}` : 'das Mitglied';
        // Eigenes Dialogfeld statt eines blockierenden Browser-Prompts: passt
        // sich der Optik der Oberflaeche an und ist einheitlich bedienbar.
        const begruendung = await showReasonDialog({
            title: 'Absage mit Begründung',
            message: `Die Absage für ${memberLabel} wird als Entschuldigung eingereicht.`,
            value: existingComment ?? '',
            placeholder: 'Begründung der Absage',
            confirmLabel: 'Absage speichern',
        });
        if (begruendung === null) return;
        comment = begruendung;
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
