/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Status-Chips mit Zaehler (Spec 2026-09-22, OI-86).
//
// Bewusst OHNE Imports: tests/js/filter_chips.test.mjs laedt dieses Modul
// unter Node. Die DOM-Funktionen am Ende greifen erst beim Aufruf auf
// document zu.
//
// Ein Chip ohne match ist "Alle". Die uebrigen Chips eines Satzes schliessen
// sich gegenseitig aus und ergeben zusammen "Alle" -- das pruefen die Tests.
//
// Zaehlregel (facettiert): Die Module zaehlen auf der Liste, auf die alle
// UEBRIGEN Filter schon angewandt sind, aber nicht der Chip selbst. Sonst
// stuenden alle anderen Chips auf 0, sobald einer aktiv ist.

const isOne = value => Number(value) === 1;
const hasEnd = s => Boolean(s.end_time);

export const CHIPS_EXCEPTIONS = Object.freeze([
    { key: 'all',      label: 'Alle' },
    { key: 'pending',  label: 'Ausstehend', variant: 'pending', match: e => e.status === 'pending' },
    { key: 'approved', label: 'Genehmigt',  variant: 'ok',      match: e => e.status === 'approved' },
    { key: 'rejected', label: 'Abgelehnt',  variant: 'danger',  match: e => e.status === 'rejected' },
]);

// "Laeuft" ist kein Datenbankstatus, sondern "Ende fehlt". Eine laufende
// Sitzung traegt status = 'submitted' und darf nicht zusaetzlich unter
// "Wartet" zaehlen -- deshalb verlangen die drei Statuschips ein Ende.
export const CHIPS_WORKTIME = Object.freeze([
    { key: 'all',       label: 'Alle' },
    { key: 'running',   label: 'Läuft',     variant: 'info',    match: s => !hasEnd(s) },
    { key: 'submitted', label: 'Wartet',    variant: 'pending', match: s => hasEnd(s) && s.status === 'submitted' },
    { key: 'confirmed', label: 'Bestätigt', variant: 'ok',      match: s => hasEnd(s) && s.status === 'confirmed' },
    { key: 'rejected',  label: 'Abgelehnt', variant: 'danger',  match: s => hasEnd(s) && s.status === 'rejected' },
]);

export const CHIPS_MEMBERS = Object.freeze([
    { key: 'all',      label: 'Alle' },
    { key: 'active',   label: 'Aktiv',   variant: 'ok', match: m => isOne(m.is_active_in_period) },
    { key: 'inactive', label: 'Inaktiv',                match: m => !isOne(m.is_active_in_period) },
]);

export const CHIPS_USERS = Object.freeze([
    { key: 'all',       label: 'Alle' },
    { key: 'pending',   label: 'Ausstehend', variant: 'pending', match: u => u.account_status === 'pending' },
    { key: 'active',    label: 'Aktiv',      variant: 'ok',      match: u => u.account_status === 'active' },
    { key: 'suspended', label: 'Gesperrt',   variant: 'danger',  match: u => u.account_status === 'suspended' },
]);

export const CHIPS_DEVICES = Object.freeze([
    { key: 'all',      label: 'Alle' },
    { key: 'active',   label: 'Aktiv',   variant: 'ok', match: d => isOne(d.is_active) },
    { key: 'inactive', label: 'Inaktiv',                match: d => !isOne(d.is_active) },
]);

// Alle Eintraege: Es gibt nur erfasste Datensaetze, "Fehlend" kann nicht vorkommen.
export const CHIPS_RECORDS_ALL = Object.freeze([
    { key: 'all',     label: 'Alle' },
    { key: 'present', label: 'Anwesend',     variant: 'ok',      match: r => r.status === 'present' },
    { key: 'excused', label: 'Entschuldigt', variant: 'pending', match: r => r.status === 'excused' },
]);

// Anwesenheitsliste (Termin oder Mitglied gewaehlt): status null = kein Datensatz = fehlend.
export const CHIPS_RECORDS_LIST = Object.freeze([
    ...CHIPS_RECORDS_ALL,
    { key: 'missing', label: 'Fehlend', variant: 'danger',
      match: r => r.status !== 'present' && r.status !== 'excused' },
]);

// Statistik: reine Anzeige, deshalb ohne match und ohne "Alle". Die Zahlen
// bilden bewusst KEINE Partition -- "Termine" zaehlt Termine, die naechsten
// drei zaehlen Anwesenheitsdatensaetze, die letzten beiden sind Quoten in
// Prozent. Ihr Erklaertext steht im Tooltip (title) und, wenn eine Quote "–"
// zeigt, zusaetzlich als sichtbarer Hinweis unter der Zeile.
//
// "Durchschnitt" stand bis zur zweiten Sichtung (23.09.2026) hier mit drin.
// Sieben Chips waren zu viel fuer eine Kopfzeile -- der Durchschnitt steht
// jetzt je Gruppe unter deren Ueberschrift (statistics.js).
export const CHIPS_STATISTICS = Object.freeze([
    { key: 'appointments', label: 'Termine' },
    { key: 'present',      label: 'Anwesend',        variant: 'ok' },
    { key: 'excused',      label: 'Entschuldigt',    variant: 'pending' },
    { key: 'unexcused',    label: 'Unentschuldigt',  variant: 'danger' },
    { key: 'punctuality',  label: 'Pünktlichkeit',   variant: 'info' },
    { key: 'reliability',  label: 'Zuverlässigkeit', variant: 'info' },
]);

// Verwaltungstabellen: echte Partitionen -- die Summe der beiden hinteren
// Chips ergibt "Alle". Seit der zweiten Sichtung (23.09.2026) filtern sie
// ihre Tabelle, statt sie nur zu zaehlen; "Alle" ist die Vorgabe und
// zugleich das Zuruecksetzen.

/** Gruppen; das Wort fuer Untergruppen kommt aus den Einstellungen (subgroupLabel()). */
export function groupChips(subgroupWord) {
    return [
        { key: 'all',  label: 'Alle' },
        { key: 'main', label: 'Hauptgruppen',       match: g => !isOne(g.is_subgroup) },
        // 'ok' passend zum gruenen Abzeichen derselben Zeile in der Tabelle.
        { key: 'sub',  label: String(subgroupWord || 'Untergruppe'), variant: 'ok', match: g => isOne(g.is_subgroup) },
    ];
}

export const CHIPS_APPOINTMENT_TYPES = Object.freeze([
    { key: 'all',       label: 'Alle' },
    { key: 'responses', label: 'mit Rückmeldung',  variant: 'ok', match: t => isOne(t.responses_enabled) },
    { key: 'plain',     label: 'ohne Rückmeldung',                match: t => !isOne(t.responses_enabled) },
]);

export const CHIPS_ACTIVITY_TYPES = Object.freeze([
    { key: 'all',      label: 'Alle' },
    { key: 'active',   label: 'Aktiv',       variant: 'ok', match: a => isOne(a.is_active) },
    { key: 'inactive', label: 'Ausgemustert',               match: a => !isOne(a.is_active) },
]);

/** Termine: reine Anzeige. Heute zaehlt als kommend. */
export function appointmentTimeChips(todayIso) {
    const day = a => String(a.date ?? '').slice(0, 10);
    return [
        { key: 'all',      label: 'Alle', variant: 'info' },
        { key: 'past',     label: 'Vergangen', match: a => day(a) < todayIso },
        { key: 'upcoming', label: 'Kommend',   match: a => day(a) >= todayIso },
    ];
}

/** Heutiges Datum als YYYY-MM-DD in lokaler Zeit (new Date('YYYY-MM-DD') waere UTC). */
export function localTodayIso(now = new Date()) {
    const pad = n => String(n).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Zaehler je Chip-Schluessel. */
export function countChips(items, defs) {
    const list = items || [];
    const counts = {};
    for (const def of defs) {
        counts[def.key] = def.match ? list.filter(def.match).length : list.length;
    }
    return counts;
}

/** Liste nach aktivem Chip; "Alle" oder unbekannter Schluessel laesst alles durch. */
export function filterByChip(items, defs, activeKey) {
    const list = items || [];
    const def = defs.find(d => d.key === activeKey);
    return def && def.match ? list.filter(def.match) : list;
}

/** activeKey, falls der Satz ihn kennt, sonst fallbackKey. */
export function resolveActiveChip(defs, activeKey, fallbackKey) {
    return defs.some(d => d.key === activeKey) ? activeKey : fallbackKey;
}

// ============================================
// DOM
// ============================================

/**
 * Rendert einen Chipsatz in container (ersetzt den Inhalt).
 * options.static: reine Anzeige (span statt button, kein Klick) fuer den
 *                 GANZEN Satz. def.static: dasselbe fuer EINEN einzelnen
 *                 Chip -- so stehen anzeigende und klickbare Chips
 *                 nebeneinander in derselben Zeile (Arbeitszeit: "Bestaetigte
 *                 Stunden" neben den Status-Chips, Sichtung 23.09.2026).
 * options.label:  aria-label der Gruppe.
 * onChange(key) wird nur bei Wechsel auf einen anderen Chip gerufen.
 * def.title:      optionaler Tooltip des Chips.
 * Der Zaehler nimmt jeden Wert aus counts entgegen, nicht nur Zahlen --
 * Textwerte wie '214:42 h' oder '65,4 %' werden unveraendert angezeigt.
 */
export function renderFilterChips(container, defs, counts, activeKey, onChange, options = {}) {
    if (!container) return;
    const isStatic = options.static === true;
    const focusedKey = container.contains(document.activeElement)
        ? document.activeElement.dataset.chip : null;

    container.replaceChildren();
    container.setAttribute('role', 'group');
    if (options.label) container.setAttribute('aria-label', options.label);

    for (const def of defs) {
        const istAnzeige = isStatic || def.static === true;
        const chip = document.createElement(istAnzeige ? 'span' : 'button');
        chip.className = 'filter-chip';
        if (def.variant) chip.classList.add(`filter-chip--${def.variant}`);
        if (def.title) chip.title = def.title;

        if (istAnzeige) {
            chip.classList.add('filter-chip--static');
            // Der Tooltip (title) erreicht Screenreader auf Touch-Geraeten nicht --
            // deshalb denselben Erklaertext zusaetzlich als aria-label.
            if (def.title) {
                const wert = counts?.[def.key] ?? 0;
                chip.setAttribute('aria-label', `${def.label}: ${wert} — ${def.title}`);
            }
        } else {
            const active = def.key === activeKey;
            chip.type = 'button';
            chip.dataset.chip = def.key;
            chip.setAttribute('aria-pressed', active ? 'true' : 'false');
            if (active) chip.classList.add('is-active');
            chip.addEventListener('click', () => {
                if (def.key !== activeKey && typeof onChange === 'function') onChange(def.key);
            });
        }

        const label = document.createElement('span');
        label.className = 'filter-chip__label';
        label.textContent = def.label;

        const count = document.createElement('span');
        count.className = 'filter-chip__count';
        count.textContent = String(counts?.[def.key] ?? 0);

        chip.append(label, ' ', count);
        container.appendChild(chip);
    }

    // Neu gezeichnet wird bei jedem Wechsel -- ohne das fiele der Tastaturfokus auf <body>.
    if (focusedKey) container.querySelector(`[data-chip="${focusedKey}"]`)?.focus();
}

/**
 * Zuruecksetzen-Knopf: dauerhaft sichtbar, im Ruhezustand ausgegraut.
 * Frueher wurde er ein- und ausgeblendet -- dabei sprangen die Auswahlfelder
 * daneben bei jeder Aenderung (Sichtung 23.09.2026).
 */
export function setResetEnabled(button, enabled) {
    if (button) button.disabled = !enabled;
}
