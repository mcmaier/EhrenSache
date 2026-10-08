/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// "Letzter Stand" der Check-in-App ohne Verbindung (OI-43, Stufe 2).
//
// Nach jedem erfolgreichen Laden von Terminen bzw. Verlauf legt app.js den
// jeweiligen Teil hier ab. Startet die App ohne Verbindung, bietet der
// Startbildschirm ihn als reine Leseansicht an -- klar als alter Stand
// gekennzeichnet, ohne jede Aktion.
//
// Geschrieben wird nur bei gespeichertem Token ("Angemeldet bleiben"): Ohne
// ihn zeigt die App offline ohnehin die Anmeldemaske. Geloescht wird
// zusammen mit dem Token (forgetSavedLogin() in app.js).
//
// Klassisches Skript wie app.js, vor ihm geladen. Kein innerHTML: Alles aus
// dem Schnappschuss wird per textContent gesetzt (Waechter
// tests/suites/pwa_snapshot_frontend.php). Logiktests: tests/js/pwa_snapshot.test.mjs.

const SNAPSHOT_KEY = 'offline_snapshot';
const SNAPSHOT_VERSION = 1;
const SNAPSHOT_RESPONSE_TEXT = { yes: 'Zugesagt', no: 'Abgesagt', maybe: 'Unsicher' };
// Ab diesem Abstand nennt die Ansicht je Abschnitt seinen eigenen Stand.
const SNAPSHOT_PART_GAP_MS = 60 * 60 * 1000;

/** Gespeicherter Schnappschuss oder null (fehlt, unlesbar, fremde Version). */
function readSnapshot() {
    try {
        const snap = JSON.parse(localStorage.getItem(SNAPSHOT_KEY) || 'null');
        return snap && snap.version === SNAPSHOT_VERSION && snap.member_id ? snap : null;
    } catch (error) {
        return null;
    }
}

function clearSnapshot() {
    try {
        localStorage.removeItem(SNAPSHOT_KEY);
    } catch (error) {
        // Gesperrter Speicher (privates Fenster): es gibt nichts zu loeschen.
    }
}

/**
 * Ersetzt einen Teil ('appointments' | 'history') und laesst den anderen
 * stehen. Ein Schnappschuss eines anderen Mitglieds wird ganz ersetzt.
 * Fehler (voller oder gesperrter Speicher) werden geschluckt: Die App laeuft
 * ohne Schnappschuss weiter.
 */
function saveSnapshotPart(part, items, memberId, header, now = new Date()) {
    try {
        if (!localStorage.getItem('api_token') || !memberId) return;

        let snap = readSnapshot();
        if (!snap || Number(snap.member_id) !== Number(memberId)) {
            snap = { version: SNAPSHOT_VERSION, member_id: Number(memberId), appointments: null, history: null };
        }

        snap[part] = { saved_at: now.toISOString(), items };
        snap.header = header;
        snap.saved_at = [snap.appointments && snap.appointments.saved_at, snap.history && snap.history.saved_at]
            .filter(Boolean).sort().pop();

        localStorage.setItem(SNAPSHOT_KEY, JSON.stringify(snap));
    } catch (error) {
        // siehe Funktionskommentar
    }
}

/** Nach erfolgreichem "me": einen Schnappschuss eines anderen Mitglieds verwerfen. */
function discardForeignSnapshot(memberId) {
    const snap = readSnapshot();
    if (snap && Number(snap.member_id) !== Number(memberId)) {
        clearSnapshot();
    }
}

/** Ein Eintrag aus appointment_responses?upcoming=1&with_info=1, auf das Noetige verkleinert. */
function snapshotAppointment(item) {
    const apt = item.appointment || {};
    return {
        date: String(apt.date || '').slice(0, 10),
        start_time: apt.start_time || '',
        end_time: apt.end_time || null,
        title: apt.title || '',
        location: apt.location || '',
        // 'info': Termin ohne Rueckmeldung -- dort gibt es nichts anzuzeigen.
        response: apt.responses_enabled ? (item.own ? item.own.status : null) : 'info'
    };
}

/** Termine ab heute; vergangene fallen bei der Anzeige weg, nicht beim Speichern. */
function upcomingSnapshotAppointments(items, todayIso) {
    return items.filter(item => item.date >= todayIso);
}

function snapshotResponseText(response) {
    if (response === 'info') return '';
    return SNAPSHOT_RESPONSE_TEXT[response] || 'Keine Rückmeldung';
}

/** "Do. 08.10., 18:42" in Ortszeit; '' bei einem unlesbaren Wert. */
function snapshotStamp(iso) {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const day = d.toLocaleDateString('de-DE', { weekday: 'short' });
    const date = d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
    const time = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    return `${day} ${date}, ${time}`;
}

/** 'YYYY-MM-DD' in Ortszeit -- toISOString() waere UTC und kippte nachts den Tag. */
function localIsoDate(d) {
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${dd}`;
}

/** Datum und Uhrzeit eines Termins fuer die Leseansicht. */
function snapshotWhen(date, start, end) {
    const d = new Date(`${date}T00:00:00`);
    const day = d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' });
    const from = String(start || '').slice(0, 5);
    if (!from) return day;
    return end ? `${day} · ${from}–${String(end).slice(0, 5)}` : `${day} · ${from}`;
}

/** Liest die gerade angezeigte Verlaufsliste als Text (Beschriftungen wie im Tab). */
function historyFromList(listEl) {
    if (!listEl) return [];
    const text = (el, sel) => (el.querySelector(sel)?.textContent || '').trim();
    return Array.from(listEl.querySelectorAll('.history-item')).map(el => ({
        title: text(el, '.history-title'),
        when: text(el, '.history-when'),
        meta: text(el, '.history-meta'),
        status: text(el, '.response-chip')
    }));
}

function snapshotEl(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
}

/** Ein Abschnitt der Leseansicht; part ist null, wenn er nie geladen wurde. */
function snapshotSection(title, part, showStamp, emptyText, rows) {
    const section = snapshotEl('section', 'snapshot-section');
    section.appendChild(snapshotEl('h3', '', title));
    if (part && showStamp) {
        section.appendChild(snapshotEl('p', 'snapshot-section__stamp', `Stand ${snapshotStamp(part.saved_at)}`));
    }
    if (!part) {
        section.appendChild(snapshotEl('p', 'snapshot-empty', 'Nicht geladen, solange Verbindung bestand.'));
    } else if (rows.length === 0) {
        section.appendChild(snapshotEl('p', 'snapshot-empty', emptyText));
    } else {
        rows.forEach(row => section.appendChild(row));
    }
    return section;
}

function snapshotEntry(title, lines) {
    const entry = snapshotEl('div', 'snapshot-entry');
    entry.appendChild(snapshotEl('div', 'snapshot-entry__title', title));
    lines.filter(Boolean).forEach(line => entry.appendChild(snapshotEl('div', 'snapshot-entry__line', line)));
    return entry;
}

/** Baut die Leseansicht in root auf; stampEl ist die Leiste oben. */
function renderSnapshotView(root, stampEl, snap, now = new Date()) {
    stampEl.textContent = `Stand von ${snapshotStamp(snap.saved_at)} – ohne Verbindung`;
    root.replaceChildren();

    const header = snap.header || {};
    const head = snapshotEl('div', 'snapshot-header');
    head.appendChild(snapshotEl('h2', '', header.organization || 'EhrenSache'));
    if (header.member) head.appendChild(snapshotEl('p', '', header.member));
    root.appendChild(head);

    const apts = snap.appointments;
    const hist = snap.history;
    const showStamps = !!(apts && hist)
        && Math.abs(new Date(apts.saved_at) - new Date(hist.saved_at)) > SNAPSHOT_PART_GAP_MS;

    const aptRows = apts
        ? upcomingSnapshotAppointments(apts.items || [], localIsoDate(now)).map(a => snapshotEntry(a.title, [
            snapshotWhen(a.date, a.start_time, a.end_time),
            a.location ? `📍 ${a.location}` : '',
            snapshotResponseText(a.response)
        ]))
        : [];
    root.appendChild(snapshotSection('Kommende Termine', apts, showStamps,
        'Keine kommenden Termine im letzten Stand.', aptRows));

    const histRows = hist
        ? (hist.items || []).map(h => snapshotEntry(h.title, [h.when, h.meta, h.status]))
        : [];
    root.appendChild(snapshotSection('Verlauf', hist, showStamps,
        'Kein Verlauf im letzten Stand.', histRows));
}
