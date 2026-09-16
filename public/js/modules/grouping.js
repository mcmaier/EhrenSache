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
