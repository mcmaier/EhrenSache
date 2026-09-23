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
import { loadGroups } from './management.js';
import { loadMembers, getUserGroupIds } from './members.js';
import { showToast, showConfirm, currentYear, groupSelectOptionsHtml, subgroupLabel, dataCache} from './ui.js';
import {debug} from '../app.js'
import { escapeHtml } from './utils.js';
import { CHIPS_STATISTICS, renderFilterChips, setResetVisible } from './filter_chips.js';

// ============================================
// DATA FUNCTIONS (API-Calls)
// ============================================

export async function loadStatistics(filters = {}) {
    const year = currentYear;

    // API-Call mit Jahr
    debug.log(`Loading STATISTICS from API for ${year} with filters:`, filters);    
    
    const params = {year: year};

    if (filters.group && filters.group !== '') {
        params.group_id = filters.group;
    }
    
    if (filters.member && filters.member !== '') {
        params.member_id = filters.member;
    }
    
    const stats = await apiCall('statistics', 'GET', null, params);

    return stats;    

}

// ============================================
// FILTERING
// ============================================

// Von der Auto-Vorwahl unten gesetzt, keine Nutzerauswahl -- applyStatisticsFilters()
// darf sie deshalb nicht als abweichenden Filter werten.
let statGroupPreselected = '';

export async function loadStatisticsFilters() {

    // Gruppen laden
    const groups = await loadGroups();

    // Wird unten neu ermittelt, falls die Auto-Vorwahl greift -- sonst bleibt sie
    // leer, damit ein spaeterer Re-Init keinen alten Wert stehen laesst.
    statGroupPreselected = '';

    // Gruppen-Filter befüllen
    const groupSelect = document.getElementById('statGroup');
    if (groupSelect) {
        const currentValue = groupSelect.value;

        groupSelect.innerHTML = '<option value="">Alle Gruppen</option>'
            + groupSelectOptionsHtml(groups || []);

        if (currentValue) groupSelect.value = currentValue;

        // Für non-Admin: nur eigene Gruppen anzeigen
        const userGroupIds = await getUserGroupIds();
        if (userGroupIds !== null) {
            Array.from(groupSelect.options).forEach(opt => {
                if (opt.value !== '' && !userGroupIds.includes(parseInt(opt.value))) {
                    opt.remove();
                }
            });
            // Leere Optgroup-Überschrift hinterlässt keine Gruppe ohne Einträge
            Array.from(groupSelect.querySelectorAll('optgroup')).forEach(optgroup => {
                if (optgroup.options.length === 0) optgroup.remove();
            });
            // Automatisch vorauswählen wenn nur eine Gruppe vorhanden
            if (!groupSelect.value && groupSelect.options.length === 2) {
                groupSelect.selectedIndex = 1;
                statGroupPreselected = groupSelect.value;
            }
        }
    }    

    // Der Mitgliederfilter ist Verwaltern vorbehalten. Die Spaltenlogik der
    // frueheren filter-card entfaellt -- die Klasse gibt es seit Task 3 nicht
    // mehr, die filter-bar bricht von selbst um.
    if (isAdminOrManager) {
        document.getElementById('statMemberFilterGroup').style.display = '';
        // Initial alle Mitglieder anzeigen
        await loadMembers();

        await updateStatisticsFilters();

    } else {
        document.getElementById('statMemberFilterGroup').style.display = 'none';
    }

}

export async function updateStatisticsFilters() {
    if (!isAdminOrManager) return;
    
    const groupSelect = document.getElementById('statGroup');
    const memberSelect = document.getElementById('statMember');
    
    //if (!groupSelect || !memberSelect) return;
    
    const selectedGroupId = groupSelect.value;
    const currentMemberId = memberSelect.value;
    
    debug.log('Updating member filter for group:', selectedGroupId);
    
    // Alle Mitglieder laden
    const allMembers = await loadMembers();
    
    // Filtern nach Gruppe (wenn ausgewählt)
    let filteredMembers = allMembers.filter(m => m.is_active_in_period);
    
    if (selectedGroupId && selectedGroupId !== '') {
        filteredMembers = filteredMembers.filter(m => {
            return m.group_ids_array && m.group_ids_array.includes(parseInt(selectedGroupId));
        });
    }
    
    // Dropdown neu befüllen
    memberSelect.innerHTML = '<option value="">Alle Mitglieder</option>';
    filteredMembers.forEach(member => {
        memberSelect.innerHTML += `<option value="${member.member_id}">${escapeHtml(member.surname)}, ${escapeHtml(member.name)}</option>`;
    });    
    
    if (currentMemberId && filteredMembers.some(m => m.member_id == currentMemberId)) {
        memberSelect.value = currentMemberId;
    } else {
        memberSelect.value = '';
    }    
}

export async function applyStatisticsFilters() {

    debug.log("Load Statistics with Filters ()");

    // Aktuelle Filter auslesen
    const filters = {
        member:isAdminOrManager ? (document.getElementById('statMember')?.value || null) : null,
        group: document.getElementById('statGroup')?.value || null
    };

    // Eine automatisch vorausgewaehlte Gruppe (nur eine Gruppe vorhanden, siehe
    // loadStatisticsFilters()) zaehlt nicht als Filter -- der Knopf blieb sonst
    // von Anfang an sichtbar, obwohl niemand etwas ausgewaehlt hat.
    const statGroupValue = document.getElementById('statGroup')?.value ?? '';
    setResetVisible(document.getElementById('resetStatisticsFilter'),
        (statGroupValue !== statGroupPreselected)
        || Boolean(document.getElementById('statMember')?.value));

    // Statistik laden
    const stats = await loadStatistics(filters);

    //Rendern, wenn Sektion aktiv
    const currentSection = sessionStorage.getItem('currentSection');
    if (currentSection === 'statistik')
    {
        renderStatistics(stats);
    }
}



/**
 * Setzt die Filter der Statistik zurück (OI-71).
 *
 * Das Jahr bleibt stehen -- wie bei den anderen Ansichten, deren
 * Zurücksetzen ebenfalls nur die Filterleiste meint und nicht den
 * Jahreswechsel rückgängig macht (vgl. resetMemberFilter).
 */
export async function resetStatisticsFilters() {
    const gruppe   = document.getElementById('statGroup');
    const mitglied = document.getElementById('statMember');

    // Zuruecksetzen stellt den Ausgangszustand her -- fuer Nutzer mit genau
    // einer Gruppe ist das deren Vorauswahl, nicht "Alle Gruppen".
    if (gruppe)   { gruppe.value   = statGroupPreselected; }
    if (mitglied) { mitglied.value = ''; }

    await applyStatisticsFilters();
}

// ============================================
// RENDERING
// ============================================

export async function showStatisticsSection()
{
    debug.log("== Show Statistics Section == ")

    await loadStatisticsFilters();

    await applyStatisticsFilters();
}

/**
 * Ordnet eine Quote einem von vier Farbbaendern zu.
 *
 * Frueher trug der Balken einen Verlauf von Rot ueber Gelb nach Gruen, den
 * eine Maske von rechts beschnitt. Die Farbe sagte damit nichts: Jeder
 * Balken begann bei Rot, auch der eines Mitglieds mit 92 Prozent, und bei
 * ihm war gut die Haelfte der Flaeche rot. Unterscheidbar war allein die
 * Laenge. Jetzt sagen Laenge und Farbe dasselbe.
 *
 * Die Schwellen sind ein Urteil darueber, was gute Anwesenheit ist, und das
 * faellt je nach Verein verschieden aus -- ein Blasorchester mit
 * Wochenprobe sieht 65 Prozent anders als eine Feuerwehr mit Monatsdienst.
 * Siehe OI-55. Sie stehen deshalb an genau einer Stelle.
 */
/**
 * Ist das aktuell gewählte statGroup eine Untergruppe? Die Statistik rechnet
 * über Terminarten und deren Gruppen -- einer Untergruppe sind nie
 * Terminarten zugeordnet, sie bleibt deshalb immer ergebnislos. Erkennung
 * über dataCache.groups.data (von loadGroups() in loadStatisticsFilters()
 * gefüllt), nicht über den Options-HTML, der nur die Darstellung trägt.
 */
function isSubgroupSelected() {
    const groupId = document.getElementById('statGroup')?.value;
    if (!groupId) return false;
    const group = dataCache.groups.data.find(g => String(g.group_id) === String(groupId));
    return !!group && group.is_subgroup == 1;
}

/** Vorgabe, wenn die Antwort keine Baender traegt (alter Server, Fehlerfall). */
const RATE_BANDS_DEFAULT = { mid: 40, fair: 60, good: 80 };

/**
 * Die Schwellen kommen seit 1.9.0 aus den Einstellungen und reisen im
 * Statistik-Payload mit (`rate_bands`) -- loadSystemSettings() waere Admins
 * vorbehalten, die Statistik sehen alle Rollen.
 */
function rateBandsFrom(statsData) {
    const bands = statsData && statsData.rate_bands ? statsData.rate_bands : null;

    if (!bands) {
        return RATE_BANDS_DEFAULT;
    }

    const werte = ['mid', 'fair', 'good']
        .map(k => parseInt(bands[k], 10))
        .map((w, i) => (Number.isInteger(w) && w >= 1 && w <= 99)
            ? w
            : RATE_BANDS_DEFAULT[['mid', 'fair', 'good'][i]]);

    werte.sort((a, b) => a - b);

    return { mid: werte[0], fair: werte[1], good: werte[2] };
}

function rateBand(rate, bands = RATE_BANDS_DEFAULT) {
    if (rate < bands.mid)  return 'rate-low';
    if (rate < bands.fair) return 'rate-mid';
    if (rate < bands.good) return 'rate-fair';
    return 'rate-good';
}

export async function renderStatistics(statsData) {
    const container = document.getElementById('statisticsContainer');
    const bands = rateBandsFrom(statsData);

    debug.log("Rendering stats:", statsData)

    // Die Pruefung hiess frueher `!statsData.statistics === 0` und war dadurch
    // immer falsch: `!x === 0` vergleicht einen Boolean mit einer Zahl und ist
    // nie wahr. Der Leerfall fiel durch und lief in einen Fehler beim forEach.
    if (!statsData || !Array.isArray(statsData.statistics) || statsData.statistics.length === 0) {
        container.innerHTML = isSubgroupSelected()
            ? `<p class="info-message">Für ${escapeHtml(subgroupLabel())} gibt es noch keine Auswertung: `
                + `Die Statistik rechnet über Terminarten, und ${escapeHtml(subgroupLabel())} sind keinen `
                + `Terminarten zugeordnet.</p>`
            : '<p class="info-message">Keine Daten für die ausgewählten Filter vorhanden.</p>';
        updateOverallStats(statsData ? statsData.summary : null);
        updateBehaviorStats(statsData);
        return;
    }

    updateOverallStats(statsData.summary);
    updateBehaviorStats(statsData);

    let html = '';

    statsData.statistics.forEach(group => {
        const typeCount = group.appointment_types.length;

        const typeHeaders = group.appointment_types
            .map(t => `<th>${escapeHtml(t.type_name || 'ohne Terminart')}</th>`)
            .join('');

        // Gruppenzeile: Ohne sie stehen die Terminartspalten unbeschriftet
        // neben "Quote" -- "Auftritt" allein sagt nicht, dass darunter
        // ebenfalls eine Quote steht. Dieselbe Gliederung wie im Ausdruck.
        // Eine Gruppe ohne Terminart erreicht diese Stelle nicht (der Handler
        // ueberspringt sie), die Pruefung steht trotzdem da.
        const groupRow = typeCount > 0
            ? `<tr class="stat-colgroup">
                   <th></th>
                   <th colspan="5">Anwesenheit</th>
                   <th colspan="${typeCount}">Quote je Terminart</th>
               </tr>`
            : '';

        html += `
            <div class="statistics-group">
                <h2>${escapeHtml(group.group_name)}</h2>
                <div class="statistics-table-wrapper">
                    <table class="data-table">
                        <thead>
                            ${groupRow}
                            <tr>
                                <th>Mitglied</th>
                                <th class="stat-count">Termine</th>
                                <th class="stat-count">Anwesend</th>
                                <th class="stat-count">Ent&shy;schuldigt</th>
                                <th class="stat-count">Unent&shy;schuldigt</th>
                                <th>Quote</th>
                                ${typeHeaders}
                            </tr>
                        </thead>
                        <tbody>
                            ${group.members.map(member => `
                                <tr>
                                    <td>${escapeHtml(member.member_name)}</td>
                                    <td class="stat-count">${member.total_appointments}</td>
                                    <td class="stat-count stat-present">${member.attended}</td>
                                    <td class="stat-count">${member.excused}</td>
                                    <td class="stat-count stat-unexcused">${member.unexcused_absences}</td>
                                    <td class="stat-rate">
                                        <div class="attendance-rate">
                                            <div class="rate-bar">
                                                <div class="rate-fill ${rateBand(member.attendance_rate, bands)}" style="width: ${member.attendance_rate}%"></div>
                                            </div>
                                            <span class="rate-text">${member.attendance_rate}%</span>
                                        </div>
                                    </td>
                                    ${member.by_type.map(t => t.total_appointments > 0
                                        ? `<td class="stat-type" title="${t.attended} von ${t.total_appointments}">
                                               <span class="type-value">${t.attendance_rate}%</span>
                                               <span class="type-track">
                                                   <span class="type-fill ${rateBand(t.attendance_rate, bands)}" style="width: ${t.attendance_rate}%"></span>
                                               </span>
                                           </td>`
                                        : `<td class="stat-type stat-type-empty" title="keine Termine dieser Art">–</td>`
                                    ).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
}

// ============================================
// HELPERS
// ============================================

// Die vier Zaehlwerte stehen seit 1.13.0 als Anzeige-Chips im Kopf, nur der
// Durchschnitt bleibt eine Karte. Ohne Daten steht ueberall "-" wie bisher.
function updateOverallStats(summary) {
    const leer = { appointments: '-', present: '-', excused: '-', unexcused: '-' };
    const zahlen = summary
        ? {
            appointments: summary.total_appointments,
            present:      summary.total_present,
            excused:      summary.total_excused,
            unexcused:    summary.total_unexcused,
        }
        : leer;

    renderFilterChips(
        document.getElementById('statisticsChips'),
        CHIPS_STATISTICS, zahlen, null, null,
        { static: true, label: 'Kennzahlen der Auswahl' }
    );

    const schnitt = document.getElementById('statOverallAverage');
    if (schnitt) {
        schnitt.textContent = summary ? `${formatGerman(summary.overall_average)} %` : '-';
    }
}

/** Zahl in deutscher Schreibweise, hoechstens eine Nachkommastelle. */
function formatGerman(value) {
    return Number(value).toLocaleString('de-DE', { maximumFractionDigits: 1 });
}

/**
 * Kacheln fuer Puenktlichkeit und Zuverlaessigkeit.
 *
 * Eine abgeschaltete Kennzahl liefert der Server als {enabled: false}; ihre
 * Kachel verschwindet dann ganz, statt leer dazustehen. Die Formulierungen
 * folgen der Spec (Abschnitt 8) und nennen immer die Bezugsgroesse.
 */
function updateBehaviorStats(statsData) {
    const punctuality = statsData?.punctuality ?? { enabled: false };
    const reliability = statsData?.reliability ?? { enabled: false };

    const pCard = document.getElementById('statPunctualityCard');
    const rCard = document.getElementById('statReliabilityCard');

    pCard.hidden = !punctuality.enabled;
    rCard.hidden = !reliability.enabled;

    if (punctuality.enabled) {
        const value  = document.getElementById('statPunctuality');
        const detail = document.getElementById('statPunctualityDetail');

        if (punctuality.total_count === 0) {
            // "0 von mindestens 5 Messungen" klaenge nach einer Erfassungsluecke.
            value.textContent  = '–';
            detail.textContent = 'Keine Termine im gewählten Zeitraum';
        } else if (!punctuality.sufficient) {
            value.textContent  = '–';
            detail.textContent = `Zu wenige Messungen (${punctuality.measured_count} von mindestens ${punctuality.min_measurements})`;
        } else {
            value.textContent = `${formatGerman(punctuality.rate)} %`;

            const lines = [
                `Pünktlich bei ${punctuality.on_time_count} von ${punctuality.measured_count} gemessenen Ankünften`,
                `Gemessen bei ${punctuality.measured_count} von ${punctuality.total_count} Terminen`,
            ];
            if (punctuality.avg_late_minutes !== null) {
                lines.push(`Wenn zu spät, dann im Schnitt ${formatGerman(punctuality.avg_late_minutes)} Minuten`);
            }
            detail.textContent = lines.join(' · ');
        }
    }

    if (reliability.enabled) {
        const value  = document.getElementById('statReliability');
        const detail = document.getElementById('statReliabilityDetail');

        if (reliability.total === 0) {
            value.textContent  = '–';
            detail.textContent = 'Keine Termine im gewählten Zeitraum';
        } else {
            value.textContent  = `${formatGerman(reliability.rate)} %`;
            detail.textContent = `Erschienen oder rechtzeitig abgemeldet: ${reliability.appeared + reliability.excused_in_time} von ${reliability.total}`;
        }
    }
}

/**
 * Öffnet den Anwesenheitsbericht mit der aktuellen Filterauswahl.
 *
 * Kein fetch: Der Bericht ist eine Seite, kein Datensatz. Er öffnet in einem
 * eigenen Tab, damit das Dashboard stehen bleibt und der Bericht vor dem
 * Drucken gelesen werden kann.
 *
 * Für ein Mitglied ist #statMember leer und unbefüllt -- es wird dann kein
 * member_id mitgeschickt, und der Server setzt die eigene Person ein. Hier
 * steht bewusst keine Rollenabfrage: Die Rechteentscheidung gehört an genau
 * eine Stelle, und die liegt im Server.
 */
export function openStatisticsReport() {
    const year   = document.getElementById('statisticYearFilter')?.value || '';
    const group  = document.getElementById('statGroup')?.value || '';
    const member = document.getElementById('statMember')?.value || '';

    const params = new URLSearchParams({ resource: 'statistics_report' });

    if (year)   params.set('year', year);
    if (group)  params.set('group_id', group);
    if (member) params.set('member_id', member);

    window.open(`${API_BASE}?${params.toString()}`, '_blank', 'noopener');
}

export async function initStatisticsEventHandlers() {

    // Change-Listener für automatische Aktualisierung
    document.getElementById('statGroup').addEventListener('change', () => {
            updateStatisticsFilters();
            applyStatisticsFilters();
        });

    if (isAdminOrManager) {
        document.getElementById('statMember').addEventListener('change', () => {
            updateStatisticsFilters();
            applyStatisticsFilters();
        });
    }

    document.getElementById('btnStatisticsReport')
        ?.addEventListener('click', openStatisticsReport);
}

window.updateStatisticsFilters = updateStatisticsFilters;
window.applyStatisticsFilters = applyStatisticsFilters;
window.resetStatisticsFilter = resetStatisticsFilters;
