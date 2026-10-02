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
 * Abschnitte für Listen: alphabetisch, nach Gruppe, nach Untergruppe.
 *
 * Der Server liefert je Mitglied `groups` und `subgroups`; hier entstehen
 * daraus die Abschnitte. Wer in zwei Gruppen steht, erscheint in beiden --
 * das ist Absicht (Spec 3.2), deshalb zählt groupingDuplicateCount() die
 * Mehrfachnennungen, damit die Oberfläche sie ausweisen kann.
 */

import { escapeHtml } from './utils.js';

export const GROUPING_STAGES = ['alpha', 'group', 'subgroup'];

function sortMembers(members) {
    return [...members].sort((a, b) =>
        (a.surname || '').localeCompare(b.surname || '', 'de') ||
        (a.name || '').localeCompare(b.name || '', 'de'));
}

function listFor(member, stage) {
    const list = stage === 'group' ? member.groups : member.subgroups;
    return Array.isArray(list) ? list : [];
}

/** Welche Stufen lohnen sich für diese Liste? 'alpha' immer. */
export function groupingAvailableStages(members) {
    const stages = ['alpha'];
    if (members.some(m => listFor(m, 'group').length > 0)) stages.push('group');
    if (members.some(m => listFor(m, 'subgroup').length > 0)) stages.push('subgroup');
    return stages;
}

/**
 * @returns {{key: string, label: string|null, members: object[]}[]}
 *   Bei 'alpha' ein einziger Abschnitt ohne Überschrift.
 */
export function groupingSections(members, stage, emptyLabel) {
    if (stage !== 'group' && stage !== 'subgroup') {
        return [{ key: 'all', label: null, members: sortMembers(members) }];
    }

    const buckets = new Map();
    const without = [];

    members.forEach(member => {
        const list = listFor(member, stage);
        if (list.length === 0) {
            without.push(member);
            return;
        }
        list.forEach(group => {
            const key = String(group.group_id);
            if (!buckets.has(key)) {
                buckets.set(key, { key, label: group.group_name,
                                   sort: Number(group.sort_order) || 0, members: [] });
            }
            buckets.get(key).members.push(member);
        });
    });

    const sections = [...buckets.values()]
        .sort((a, b) => a.sort - b.sort || a.label.localeCompare(b.label, 'de'))
        .map(section => ({ ...section, members: sortMembers(section.members) }));

    if (without.length > 0) {
        sections.push({ key: 'none', label: emptyLabel, members: sortMembers(without) });
    }

    return sections;
}

/** Wie viele Mitglieder stehen in mehr als einem Abschnitt? */
export function groupingDuplicateCount(members, stage) {
    if (stage !== 'group' && stage !== 'subgroup') return 0;
    return members.filter(m => listFor(m, stage).length > 1).length;
}

/**
 * Gemerkte Wahl. Fällt auf die erste sinnvolle Stufe zurück: Untergruppe, wenn
 * es sie gibt, sonst die Vorgabe des Aufrufers. Jeder Zugriff ist gekapselt --
 * im privaten Fenster wirft localStorage.
 */
export function groupingStored(key, available, fallback) {
    let gespeichert = null;
    try {
        gespeichert = window.localStorage.getItem(key);
    } catch (e) {
        gespeichert = null;
    }
    if (gespeichert && available.includes(gespeichert)) return gespeichert;
    if (available.includes('subgroup')) return 'subgroup';
    return available.includes(fallback) ? fallback : 'alpha';
}

export function groupingStore(key, stage) {
    try {
        window.localStorage.setItem(key, stage);
    } catch (e) {
        /* ohne Gedächtnis weiterarbeiten */
    }
}

export const GROUPING_KEY_ATTENDANCE = 'es_grouping_attendance';
export const GROUPING_KEY_RESPONSES  = 'es_grouping_responses';

/** Zählt Rückmeldungen eines Abschnitts; alles außer yes/maybe/no ist offen. */
export function groupingStatusCounts(members) {
    const counts = { yes: 0, maybe: 0, no: 0, open: 0 };
    members.forEach(m => {
        const key = ['yes', 'maybe', 'no'].includes(m.status) ? m.status : 'open';
        counts[key]++;
    });
    return counts;
}

/**
 * Kopfzeile eines zuklappbaren Abschnitts (Spec 2026-10-02, Abschnitt 6):
 * Knopf mit Name, Balken, vier Icon-Chips (chipsHtml, vom Aufrufer
 * mit responseChipsHtml() gebaut, Zusagen eingeschlossen) und am Ende "von 5" -- grouping.js darf responses.js nicht
 * importieren, das waere ein Zyklus; der Vollsatz steht im aria-label). Die Breiten
 * der Balkenteile sind Zahlen -- sie gehen über toFixed() ins style-Attribut.
 * Der Aufrufer maskiert nichts vorher; hier wird alles maskiert.
 */
export function groupingSectionHeaderHtml({ key, label, counts, expanded, disabled = false, chipsHtml = '' }) {
    const total = counts.yes + counts.maybe + counts.no + counts.open;
    // Vorlesetext: Die Icon-Chips sind aria-hidden bzw. nur Symbole, der Knopf
    // bekommt deshalb den vollen Satz als aria-label.
    const spoken = `${label}: ${counts.yes} von ${total} zugesagt, ${counts.maybe} unsicher, `
        + `${counts.no} ${counts.no === 1 ? 'Absage' : 'Absagen'}, ${counts.open} ohne Antwort`;
    const segments = total === 0 ? '' : ['yes', 'maybe', 'no', 'open']
        .filter(k => counts[k] > 0)
        .map(k => `<span class="section-bar__seg section-bar__seg--${k}" style="width:${(counts[k] / total * 100).toFixed(2)}%"></span>`)
        .join('');

    return `<button type="button" class="section-head" aria-label="${escapeHtml(spoken)}" aria-expanded="${expanded ? 'true' : 'false'}"${disabled ? ' disabled' : ''}
                data-action="toggle-response-section" data-key="${escapeHtml(key)}">
            <span class="section-head__chevron" aria-hidden="true">${expanded ? '▾' : '▸'}</span>
            <span class="section-head__label" title="${escapeHtml(label)}">${escapeHtml(label)}</span>
            <span class="section-bar" aria-hidden="true">${segments}</span>
            <span class="section-head__summary" aria-hidden="true">${chipsHtml}<span class="section-head__total">von ${total}</span></span>
        </button>`;
}
