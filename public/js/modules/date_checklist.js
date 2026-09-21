/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import { escapeHtml } from './utils.js';

// ============================================
// DATUMSLISTE mit Haekchen und Zaehler
// Genutzt von der Serienvorschau (appointments.js) und der Import-Vorschau
// (import_export.js). Die Eintraege bleiben als Objekte im Speicher; das DOM
// traegt nur die Darstellung.
// ============================================

let checklistCounter = 0;

/** Datum als "Di., 04.03.2031". */
export function formatChecklistDate(date) {
    const d = new Date(date + 'T00:00:00');
    return isNaN(d.getTime())
        ? date
        : d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
}

/**
 * @param {HTMLElement} container
 * @param {Array<{date: string, label?: string, note?: string, noteClass?: string,
 *                checked: boolean, disabled?: boolean, payload?: any}>} items
 * @param {{onChange?: (selectedCount: number) => void}} options
 */
export function renderDateChecklist(container, items, options = {}) {
    const state = items.map(item => ({ ...item, checked: Boolean(item.checked) && !item.disabled }));
    const listId = ++checklistCounter;

    container.innerHTML = '';
    const counter = document.createElement('div');
    counter.className = 'date-checklist-counter';
    const list = document.createElement('ul');
    list.className = 'date-checklist';

    const update = () => {
        const selectable = state.filter(s => !s.disabled).length;
        const selected = state.filter(s => s.checked).length;
        counter.textContent = `${selected} von ${selectable} ausgewählt`;
        if (options.onChange) options.onChange(selected);
    };

    state.forEach((entry, idx) => {
        const li = document.createElement('li');
        li.className = 'date-checklist-item' + (entry.disabled ? ' is-disabled' : '');

        const box = document.createElement('input');
        box.type = 'checkbox';
        box.id = `date-check-${listId}-${idx}`;
        box.checked = entry.checked;
        box.disabled = Boolean(entry.disabled);
        box.addEventListener('change', () => {
            entry.checked = box.checked;
            update();
        });

        const label = document.createElement('label');
        label.htmlFor = box.id;
        const noteClass = /^[a-z-\s]*$/.test(entry.noteClass || '') ? entry.noteClass || '' : '';
        label.innerHTML = `<strong>${escapeHtml(entry.label ?? formatChecklistDate(entry.date))}</strong>`
            + (entry.note ? ` <span class="date-checklist-note ${noteClass}">${escapeHtml(entry.note)}</span>` : '');

        li.append(box, label);
        list.appendChild(li);
    });

    container.append(counter, list);
    update();

    return {
        getSelected: () => state.filter(s => s.checked).map(s => s.date),
        getDeselected: () => state.filter(s => !s.checked && !s.disabled).map(s => s.date),
        getSelectedItems: () => state.filter(s => s.checked),
    };
}
