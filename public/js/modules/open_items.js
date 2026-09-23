/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

/**
 * Offene Punkte (FI-17): Karte oben in „Mein Profil".
 *
 * Reine Leseansicht. Antippen springt an die Stelle, an der man den Punkt
 * erledigt; alle Aktionen laufen ueber die vorhandenen Wege.
 * Spec: docs/superpowers/specs/2026-09-22-offene-punkte-design.md
 */

import { apiCall } from './api.js';
import { escapeHtml } from './utils.js';

const WEEKDAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

/**
 * 'YYYY-MM-DD' oder 'YYYY-MM-DD HH:MM:SS' -> 'Do 25.09.' bzw. 'Do 25.09. 19:30'.
 *
 * Das Jahr steht nur dabei, wenn das Datum nicht im laufenden Jahr liegt: Ein
 * wartender Antrag oder eine wartende Arbeitszeit kann beliebig alt sein, und
 * „Mi 05.05." sieht sonst aus wie dieses Jahr.
 */
function formatWhen(dateStr, timeStr = '') {
    const d = new Date(String(dateStr).slice(0, 10) + 'T00:00:00');
    if (isNaN(d.getTime())) return '';
    const jahr = d.getFullYear() === new Date().getFullYear() ? '' : String(d.getFullYear());
    const day = `${WEEKDAYS[d.getDay()]} ${String(d.getDate()).padStart(2, '0')}.${String(d.getMonth() + 1).padStart(2, '0')}.${jahr}`;
    const time = escapeHtml(String(timeStr).slice(0, 5));
    return time ? `${day} ${time}` : day;
}

function formatMinutes(minutes) {
    const m = Number(minutes) || 0;
    return `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')} h`;
}

function chipHtml(state) {
    return state === 'rejected'
        ? '<span class="status-badge status-rejected">abgelehnt</span>'
        : '<span class="status-badge status-pending">wartet</span>';
}

function itemHtml(item) {
    if (item.kind === 'response') {
        const [dDate, dTime] = String(item.deadline).split(' ');
        return `<button type="button" class="open-item" data-kind="response" data-appointment-id="${Number(item.appointment_id)}">
            <span class="open-item__title">${escapeHtml(item.title)} · ${formatWhen(item.date, item.start_time)}</span>
            <span class="open-item__meta">Rückmeldung bis ${formatWhen(dDate, dTime)}</span>
        </button>`;
    }
    if (item.kind === 'exception') {
        const label = item.exception_type === 'absence' ? 'Entschuldigung' : 'Zeitkorrektur';
        return `<button type="button" class="open-item" data-kind="exception">
            <span class="open-item__title">${label} · ${escapeHtml(item.title)} ${formatWhen(item.date)}</span>
            ${chipHtml(item.state)}
        </button>`;
    }
    const [sDate] = String(item.start_time).split(' ');
    return `<button type="button" class="open-item" data-kind="work_session">
        <span class="open-item__title">Arbeitszeit · ${escapeHtml(item.activity_name)} ${formatWhen(sDate)} · ${formatMinutes(item.duration_minutes)}</span>
        ${chipHtml(item.state)}
    </button>`;
}

function goToSection(section) {
    document.querySelector(`.nav-item[data-section="${section}"]`)?.click();
}

function onListClick(event) {
    const btn = event.target.closest('.open-item');
    if (!btn) return;
    if (btn.dataset.kind === 'response') {
        window.openResponsesModal?.(Number(btn.dataset.appointmentId));
    } else if (btn.dataset.kind === 'exception') {
        goToSection('antraege');
    } else {
        goToSection('zeiterfassung');
    }
}

let bound = false;
let loadSeq = 0;

export async function loadOpenItems() {
    const card = document.getElementById('openItemsCard');
    const list = document.getElementById('openItemsList');
    if (!card || !list) return;

    if (!bound) {
        list.addEventListener('click', onListClick);
        // Nach einer Aenderung im Rueckmeldungsdialog nachladen, aber nur,
        // wenn das Profil gerade sichtbar ist.
        document.addEventListener('responses:changed', () => {
            if (document.getElementById('profil')?.classList.contains('active')) loadOpenItems();
        });
        bound = true;
    }

    const seq = ++loadSeq;

    try {
        // apiCall() liefert bei 401 null, sonst bei Fehlern (403, 500,
        // Netzwerk/Timeout) ein Objekt ohne `items` -- daher der explizite
        // Array-Check statt eines Felds wie `success`, das eine erfolgreiche
        // Antwort gar nicht traegt.
        const data = await apiCall('my_open_items');

        // Waehrenddessen wurde ein neuerer Abruf gestartet: diese Antwort
        // ist ueberholt und darf die Karte nicht mehr anfassen.
        if (seq !== loadSeq) return;

        if (!data || data.member === false || !Array.isArray(data.items)) {
            card.hidden = true;
            return;
        }

        card.hidden = false;
        list.innerHTML = data.items.length === 0
            ? '<p class="open-items-empty">Nichts offen ✓</p>'
            : data.items.map(itemHtml).join('');
    } catch (error) {
        if (seq !== loadSeq) return;
        card.hidden = true;
    }
}
