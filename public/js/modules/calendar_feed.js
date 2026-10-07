/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// ============================================
// KALENDER-ABO (FI-8) - Karte in „Mein Profil“
// ============================================
// Der Link steht nur direkt nach dem Erzeugen zur Verfuegung: Der Server
// speichert nur den Hash und kann ihn spaeter nicht mehr zeigen.

import { apiCall } from './api.js';
import { showToast, showConfirm } from './ui.js';
import { isFeatureOn } from './features.js';
import { registerActions } from './actions.js';

// { url, webcal_url } nur bis zum naechsten Laden der Seite
let freshLink = null;
// Verhindert doppelte Aufrufe (Doppelklick) und uebereinanderliegende Rueckfragen
let busy = false;

function byId(id) {
    return document.getElementById(id);
}

function formatDateTime(value) {
    if (!value) {
        return null;
    }
    const d = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) {
        return null;
    }
    return d.toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });
}

// Demo-Betrieb: theme.js legt das Merkmal aus resource=appearance ('demo')
// in sessionStorage ab und setzt die Klasse has-demo-banner -- dieselbe Quelle
// wie das Demo-Band. Der Server sperrt POST/PUT/DELETE ohnehin (demo_mode.php).
function isDemoInstallation() {
    if (document.body.classList.contains('has-demo-banner')) {
        return true;
    }
    try {
        return sessionStorage.getItem('theme-demo') === '1';
    } catch {
        return false;
    }
}

function render(status) {
    // In der Demo nur der Hinweis, keine Knoepfe zum Erzeugen, Erneuern, Beenden
    const demo = isDemoInstallation();
    byId('calendarFeedDemo').classList.toggle('hidden', !demo);
    const noMember = !status.member_linked;
    byId('calendarFeedNoMember').classList.toggle('hidden', demo || !noMember);
    byId('calendarFeedInactive').classList.toggle('hidden', demo || noMember || status.active);
    byId('calendarFeedActive').classList.toggle('hidden', demo || !status.active);
    byId('calendarFeedFresh').classList.toggle('hidden', demo || noMember || !status.active || !freshLink);
    // Abo ohne Mitglied: nur beenden, der Server wuerde Aendern und Erneuern abweisen
    byId('calendarFeedHideDeclinedLabel').classList.toggle('hidden', noMember);
    byId('calendarFeedRenew').classList.toggle('hidden', noMember);

    if (status.active) {
        const seit = formatDateTime(status.created_at) ?? '–';
        const abruf = formatDateTime(status.last_fetched_at);
        byId('calendarFeedStatus').textContent =
            `Aktiv seit ${seit} · ` + (abruf ? `zuletzt abgerufen ${abruf}` : 'noch nie abgerufen');
        byId('calendarFeedHideDeclined').checked = !!status.hide_declined;
    }

    if (freshLink) {
        byId('calendarFeedUrl').value = freshLink.url;
        byId('calendarFeedWebcal').href = freshLink.webcal_url;
    }
}

export async function loadCalendarFeedCard() {
    if (!byId('calendarFeedCard') || !isFeatureOn('calendar_feed')) {
        return;
    }
    // Der Link gilt nur direkt nach dem Erzeugen: bei jedem Laden vergessen
    freshLink = null;
    const status = await apiCall('calendar_feed', 'GET', null, {}, { silentStatuses: [403, 404] });
    if (!status?.success) {
        return;
    }
    render(status);
}

async function createCalendarFeed(replace) {
    if (busy) {
        return;
    }
    busy = true;
    try {
        await doCreateCalendarFeed(replace);
    } finally {
        busy = false;
    }
}

async function doCreateCalendarFeed(replace) {
    if (replace) {
        const ok = await showConfirm(
            'Neuen Link erzeugen? Der bisherige Link funktioniert danach nicht mehr – auch nicht in Kalendern, die ihn schon abonniert haben.',
            'Neuen Abo-Link erzeugen'
        );
        if (!ok) {
            return;
        }
    }
    const result = await apiCall('calendar_feed', 'POST', {});
    if (!result?.success) {
        return;
    }
    freshLink = { url: result.url, webcal_url: result.webcal_url };
    render({
        member_linked: true,
        active: true,
        created_at: result.created_at,
        last_fetched_at: null,
        hide_declined: result.hide_declined
    });
    showToast('Abo-Link erzeugt', 'success');
    byId('calendarFeedUrl').focus();
    byId('calendarFeedUrl').select();
}

async function endCalendarFeed() {
    if (busy) {
        return;
    }
    busy = true;
    try {
        await doEndCalendarFeed();
    } finally {
        busy = false;
    }
}

async function doEndCalendarFeed() {
    const ok = await showConfirm(
        'Abo beenden? Kalender, die den Link abonniert haben, erhalten danach keine Termine mehr.',
        'Kalender-Abo beenden'
    );
    if (!ok) {
        return;
    }
    const result = await apiCall('calendar_feed', 'DELETE');
    if (!result?.success) {
        return;
    }
    freshLink = null;
    render({ member_linked: true, active: false });
    showToast('Kalender-Abo beendet', 'success');
}

async function setHideDeclined(checkbox) {
    if (busy) {
        checkbox.checked = !checkbox.checked;
        return;
    }
    busy = true;
    try {
        const result = await apiCall('calendar_feed', 'PUT', { hide_declined: checkbox.checked });
        if (!result?.success) {
            checkbox.checked = !checkbox.checked;
            return;
        }
        showToast(checkbox.checked ? 'Abgesagte Termine werden ausgeblendet' : 'Abgesagte Termine werden angezeigt', 'success');
    } finally {
        busy = false;
    }
}

function copyCalendarFeedUrl() {
    const input = byId('calendarFeedUrl');
    if (!input?.value) {
        return;
    }
    const fallback = () => {
        input.select();
        const ok = document.execCommand('copy');
        showToast(ok ? 'Link kopiert' : 'Bitte den Link manuell kopieren', ok ? 'success' : 'warning');
    };
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(input.value).then(
            () => showToast('Link kopiert', 'success'),
            fallback
        );
    } else {
        fallback();
    }
}

registerActions({
    'calendar-feed-copy': () => copyCalendarFeedUrl(),
    'calendar-feed-create': () => createCalendarFeed(false),
    'calendar-feed-end': () => endCalendarFeed(),
    'calendar-feed-hide-declined': (el) => setHideDeclined(el),
    'calendar-feed-renew': () => createCalendarFeed(true),
});
