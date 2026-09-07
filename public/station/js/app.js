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
// STATION — virtuelle Station (Kiosk)
// ============================================
// Ein Tablet zeigt den Stations-Code und nimmt Mitgliedsnummer + PIN entgegen.
// Spec: docs/superpowers/specs/2026-09-04-station-pin-kiosk-design.md, Abschnitt 6.
//
// Grundsaetze:
// - Nummer und PIN leben nur im Speicher und werden nach jeder Aktion und nach
//   der Ruhezeit verworfen. Persistent sind nur Token und Ruhezeit.
// - Ohne Verbindung kein Code und kein Stempeln — kein Offline-Betrieb.
// - Restlaufzeit des Codes wird gegen die Serveruhr gerechnet (Feld now).
// - Die Antwort einer Aktion ist massgeblich, nicht der Kandidat aus identify:
//   an der Toleranzgrenze koennen beide auseinanderlaufen.

const DEBUG = false;
const debug = {
    log:   (...a) => DEBUG && console.log(...a),
    error: (...a) => DEBUG && console.error(...a)
};

const API_BASE = (() => {
    // /EhrenSache/public/station/ → /EhrenSache/public/api/api.php
    const match = window.location.pathname.match(/^(.*?)\/station\//);
    const basePath = match ? match[1] : '';
    return `${window.location.origin}${basePath}/api/api.php`;
})();

const STORAGE_TOKEN = 'station_token';
const STORAGE_IDLE  = 'station_idle_seconds';
const DEFAULT_IDLE  = 30;
const DONE_SECONDS  = 3;
const STATUS_EVERY  = 5 * 60 * 1000;

const SERVER_MESSAGES = {
    'Invalid member number or PIN':         'Nummer oder PIN falsch',
    'Too many attempts':                    'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.',
    'Station temporarily locked':           'Die Station ist vorübergehend gesperrt. Bitte in 15 Minuten erneut versuchen.',
    'Station PIN login is disabled':        'Die Stations-Anmeldung ist abgeschaltet.',
    'Device has no name':                   'Dieser Station fehlt der Gerätename. Bitte in der Geräteverwaltung eintragen.',
    'Kein passender Termin gefunden':       'Kein Termin im Zeitfenster – bitte beim Vorstand melden.',
    'A session is already running':         'Es läuft bereits eine Zeiterfassung.',
    'No running session':                   'Es läuft keine Zeiterfassung.',
    'Activity type not allowed for this member': 'Diese Tätigkeit ist für deine Gruppe nicht vorgesehen.',
    'activity_id must be a positive integer': 'Bitte eine Tätigkeit wählen.',
    'Endpoint not found':                   'Die Zeiterfassung ist abgeschaltet.',
    'Kiosk devices may only use the station resource': 'Token gehört nicht zu einer virtuellen Station.',
    'Kiosk device token required':          'Token gehört nicht zu einer virtuellen Station.'
};

const state = {
    token: null,
    status: null,          // Antwort von action=status
    memberNumber: '',
    pin: '',
    identity: null,        // Antwort von action=identify
    selectedActivity: null,
    alpha: false,          // Buchstabentastatur aktiv
    idleTimer: null,
    totpTimer: null,
    statusTimer: null,
    clockTimer: null,
    wakeLock: null,
    pressTimer: null
};

const $ = (id) => document.getElementById(id);

// ============================================
// API
// ============================================

async function api(action, method = 'GET', body = null) {
    const url = new URL(API_BASE);
    url.searchParams.set('resource', 'station');
    url.searchParams.set('action', action);

    const options = {
        method,
        headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${state.token}` },
        credentials: 'omit'
    };
    if (body) options.body = JSON.stringify(body);

    let response;
    try {
        response = await fetch(url, options);
    } catch (e) {
        setBanner('Keine Verbindung zum Server');
        return { ok: false, status: 0, data: null, error: 'Keine Verbindung zum Server' };
    }
    setBanner(null);

    let data = null;
    try { data = await response.json(); } catch (e) { /* keine JSON-Antwort */ }

    if (response.status === 401) {
        // Token abgelaufen oder Geraet geloescht: zurueck zur Einrichtung
        forgetToken();
        showScreen('setup');
        showError('setupError', 'Token ungültig oder abgelaufen');
    }

    const raw = data?.message || `HTTP ${response.status}`;
    return { ok: response.ok, status: response.status, data, error: SERVER_MESSAGES[raw] || raw };
}

// ============================================
// Anzeige-Helfer
// ============================================

function showScreen(name) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    $(`screen-${name}`).classList.add('active');
    if (name === 'idle') {
        stopIdleTimer();
    } else if (name !== 'setup' && name !== 'settings') {
        restartIdleTimer();
    }
}

function setBanner(text) {
    const banner = $('banner');
    banner.hidden = !text;
    banner.textContent = text || '';
    if (text) {
        $('totpCode').textContent = '------';
        $('qr').innerHTML = '';
    }
}

function showError(id, text) {
    const el = $(id);
    el.textContent = text || '';
    el.hidden = !text;
}

function idleSeconds() {
    let v = NaN;
    try { v = parseInt(localStorage.getItem(STORAGE_IDLE) || '', 10); } catch (e) { /* kein Speicher */ }
    return Number.isFinite(v) && v >= 10 ? v : DEFAULT_IDLE;
}

// ============================================
// Ruhezeit — jede Beruehrung setzt sie zurueck
// ============================================

function restartIdleTimer() {
    stopIdleTimer();
    state.idleTimer = setTimeout(resetToIdle, idleSeconds() * 1000);
}

function stopIdleTimer() {
    if (state.idleTimer) clearTimeout(state.idleTimer);
    state.idleTimer = null;
}

function resetToIdle() {
    state.memberNumber = '';
    state.pin = '';
    state.identity = null;
    state.selectedActivity = null;
    showError('pinError', null);
    showError('actionError', null);
    showScreen('idle');
}

['pointerdown', 'keydown'].forEach(evt => {
    document.addEventListener(evt, () => {
        const active = document.querySelector('.screen.active');
        if (active && !['screen-idle', 'screen-setup', 'screen-settings'].includes(active.id)) {
            restartIdleTimer();
        }
    }, { passive: true });
});

// ============================================
// Einrichtung
// ============================================

function loadToken() {
    try {
        const stored = localStorage.getItem(STORAGE_TOKEN);
        return stored ? atob(stored) : null;
    } catch (e) {
        return null;
    }
}

function saveToken(token) {
    try { localStorage.setItem(STORAGE_TOKEN, btoa(token)); } catch (e) { /* kein Speicher */ }
}

function forgetToken() {
    try { localStorage.removeItem(STORAGE_TOKEN); } catch (e) { /* kein Speicher */ }
    state.token = null;
    stopIdleLoops();
}

async function connect(token) {
    state.token = token;
    const res = await api('status');
    if (!res.ok) {
        state.token = null;
        return res.error;
    }
    state.status = res.data;
    saveToken(token);
    return null;
}

$('setupSave').addEventListener('click', async () => {
    const token = $('setupToken').value.trim();
    if (!token) return;
    showError('setupError', null);
    const error = await connect(token);
    if (error) {
        showError('setupError', error);
        return;
    }
    $('setupToken').value = '';
    await enterIdle();
});

// ============================================
// Ruhebild: Uhr, Status, Code
// ============================================

async function enterIdle() {
    showScreen('idle');
    applyStatus();
    startClock();
    await refreshTotp();
    if (state.statusTimer) clearInterval(state.statusTimer);
    state.statusTimer = setInterval(refreshStatus, STATUS_EVERY);
    requestWakeLock();
}

function stopIdleLoops() {
    if (state.totpTimer) clearTimeout(state.totpTimer);
    if (state.statusTimer) clearInterval(state.statusTimer);
    if (state.clockTimer) clearInterval(state.clockTimer);
    state.totpTimer = state.statusTimer = state.clockTimer = null;
}

function applyStatus() {
    const s = state.status || {};
    $('deviceName').textContent = s.device_name || '';
    $('totpPanel').hidden = !s.totp_enabled;
    $('pinPanel').hidden = !s.pin_enabled;
}

async function refreshStatus() {
    const res = await api('status');
    if (res.ok) {
        state.status = res.data;
        applyStatus();
        if (state.status.totp_enabled && !state.totpTimer) refreshTotp();
    }
}

function startClock() {
    const tick = () => {
        $('clock').textContent = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    };
    tick();
    if (state.clockTimer) clearInterval(state.clockTimer);
    state.clockTimer = setInterval(tick, 1000);
}

async function refreshTotp() {
    if (state.totpTimer) clearTimeout(state.totpTimer);
    state.totpTimer = null;

    if (!state.status?.totp_enabled) return;

    const res = await api('totp');
    if (!res.ok) {
        // Bei Verbindungsverlust in 10 s erneut versuchen; bei 404 (Code abgeschaltet) beim naechsten Status
        if (res.status === 0) state.totpTimer = setTimeout(refreshTotp, 10000);
        return;
    }

    const { code, valid_until, now, period } = res.data;
    renderTotp(code);

    // Restlaufzeit gegen die SERVERuhr; die Tablet-Uhr geht oft falsch
    const fetchedAt = Date.now();
    const remaining = Math.max(1, valid_until - now);
    const bar = $('totpBar');
    bar.style.transition = 'none';
    bar.style.width = `${(remaining / period) * 100}%`;
    requestAnimationFrame(() => {
        bar.style.transition = `width ${remaining}s linear`;
        bar.style.width = '0%';
    });

    state.totpTimer = setTimeout(refreshTotp, remaining * 1000 - (Date.now() - fetchedAt) + 200);
}

function renderTotp(code) {
    $('totpCode').textContent = code;
    // Inhalt wie an einer Hardware-Station: die Check-in-PWA erwartet CHECKIN:<code>
    const qr = qrcode(0, 'M');
    qr.addData(`CHECKIN:${code}`);
    qr.make();
    $('qr').innerHTML = qr.createSvgTag(8, 2);
}

// ============================================
// Bildschirm anlassen
// ============================================

async function requestWakeLock() {
    if (!('wakeLock' in navigator)) return;
    try {
        state.wakeLock = await navigator.wakeLock.request('screen');
    } catch (e) {
        debug.log('Wake Lock nicht verfügbar:', e);
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && state.token) {
        requestWakeLock();
        refreshTotp();
    }
});

// ============================================
// Tastaturen
// ============================================

const DIGITS = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', '⌫'];
const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ-'.split('').concat(['⌫']);

function renderPad(containerId, keys, onKey, alpha = false) {
    const pad = $(containerId);
    pad.classList.toggle('alpha', alpha);
    pad.innerHTML = '';
    keys.forEach(key => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = key;
        if (key === '') { btn.disabled = true; btn.style.visibility = 'hidden'; }
        btn.addEventListener('click', () => onKey(key));
        pad.appendChild(btn);
    });
}

function numberKey(key) {
    if (key === '⌫') {
        state.memberNumber = state.memberNumber.slice(0, -1);
    } else if (state.memberNumber.length < 20) {
        state.memberNumber += key;
    }
    $('numberDisplay').textContent = state.memberNumber;
}

function pinKey(key) {
    if (key === '⌫') {
        state.pin = state.pin.slice(0, -1);
    } else if (state.pin.length < 8) {
        state.pin += key;
    }
    $('pinDisplay').textContent = '●'.repeat(state.pin.length);
}

function renderNumberPad() {
    renderPad('numberPad', state.alpha ? LETTERS : DIGITS, numberKey, state.alpha);
    $('numberAbc').textContent = state.alpha ? '123' : 'ABC';
}

$('stampStart').addEventListener('click', () => {
    state.memberNumber = '';
    state.pin = '';
    state.alpha = false;
    $('numberDisplay').textContent = '';
    renderNumberPad();
    showScreen('number');
});

$('numberAbc').addEventListener('click', () => {
    state.alpha = !state.alpha;   // E3: Mitgliedsnummern duerfen Buchstaben tragen
    renderNumberPad();
});

$('numberCancel').addEventListener('click', resetToIdle);

$('numberNext').addEventListener('click', () => {
    if (!state.memberNumber) return;
    state.pin = '';
    $('pinDisplay').textContent = '';
    showError('pinError', null);
    renderPad('pinPad', DIGITS, pinKey);
    showScreen('pin');
});

$('pinCancel').addEventListener('click', resetToIdle);

$('pinOk').addEventListener('click', identify);

// ============================================
// identify → Aktion
// ============================================

function creds() {
    return { member_number: state.memberNumber, pin: state.pin };
}

async function identify() {
    const minLength = state.status?.pin_min_length || 4;
    if (state.pin.length < minLength) {
        showError('pinError', `Die PIN hat mindestens ${minLength} Ziffern`);
        return;
    }
    $('pinOk').disabled = true;
    const res = await api('identify', 'POST', creds());
    $('pinOk').disabled = false;

    if (!res.ok) {
        showError('pinError', res.error);
        if (res.status === 423 || res.status === 409) {
            setTimeout(resetToIdle, 4000);
        } else {
            state.pin = '';
            $('pinDisplay').textContent = '';
        }
        return;
    }

    state.identity = res.data;
    renderAction();
    showScreen('action');
}

function renderAction() {
    const id = state.identity;
    $('greeting').textContent = `Hallo ${id.member.name} ${id.member.surname}`;
    showError('actionError', null);

    // Anwesenheit
    const c = id.checkin_candidate;
    const btn = $('attendanceBtn');
    if (!c) {
        $('attendanceInfo').textContent = 'Kein Termin im Zeitfenster.';
        btn.disabled = true;
    } else if (c.already_checked_in) {
        $('attendanceInfo').textContent = `${c.title} (${c.start_time.slice(0, 5)} Uhr) – bereits eingecheckt.`;
        btn.disabled = true;
    } else if (c.record_status === 'excused') {
        $('attendanceInfo').textContent = `${c.title} – ${c.start_time.slice(0, 5)} Uhr (als entschuldigt eingetragen)`;
        btn.disabled = false;
    } else {
        $('attendanceInfo').textContent = `${c.title} – ${c.start_time.slice(0, 5)} Uhr`;
        btn.disabled = false;
    }

    // Arbeitszeit
    $('worktimeCard').hidden = !id.worktime_enabled;
    if (!id.worktime_enabled) return;

    const running = id.running_session;
    $('worktimeRunning').hidden = !running;
    $('worktimeStart').hidden = !!running;

    if (running) {
        const since = String(running.start_time).slice(11, 16);
        $('worktimeInfo').textContent = `${running.activity_name} – läuft seit ${since} Uhr`
            + (running.is_paused ? ' (pausiert)' : '');
        $('worktimePauseBtn').textContent = running.is_paused ? 'Weiter' : 'Pause';
    } else {
        const list = $('activityList');
        list.innerHTML = '';
        state.selectedActivity = null;
        id.activities.forEach(a => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = a.activity_name;
            b.dataset.id = a.activity_id;
            if (a.is_default == 1 && state.selectedActivity === null) {
                state.selectedActivity = a.activity_id;
                b.classList.add('selected');
            }
            b.addEventListener('click', () => {
                list.querySelectorAll('button').forEach(x => x.classList.remove('selected'));
                b.classList.add('selected');
                state.selectedActivity = a.activity_id;
            });
            list.appendChild(b);
        });
        if (id.activities.length === 0) {
            list.textContent = 'Keine Tätigkeitsart für deine Gruppe.';
        }
        $('worktimeStartBtn').disabled = id.activities.length === 0;
    }
}

$('actionDone').addEventListener('click', resetToIdle);

$('attendanceBtn').addEventListener('click', async () => {
    const res = await api('checkin', 'POST', creds());
    if (!res.ok) { showError('actionError', res.error); return; }
    const apt = res.data.appointment;
    done('Anwesenheit gespeichert', `${apt.title} – ${String(apt.start_time).slice(0, 5)} Uhr`);
});

$('worktimeStartBtn').addEventListener('click', async () => {
    if (!state.selectedActivity) { showError('actionError', 'Bitte eine Tätigkeit wählen.'); return; }
    const res = await api('work_start', 'POST', { ...creds(), activity_id: Number(state.selectedActivity) });
    if (!res.ok) { showError('actionError', res.error); return; }
    done('Arbeitszeit gestartet', res.data.session.activity_name);
});

$('worktimePauseBtn').addEventListener('click', async () => {
    const paused = state.identity?.running_session?.is_paused;
    const res = await api(paused ? 'work_resume' : 'work_pause', 'POST', creds());
    if (!res.ok) { showError('actionError', res.error); return; }
    done(paused ? 'Weiter geht’s' : 'Pause', res.data.session.activity_name);
});

$('worktimeStopBtn').addEventListener('click', async () => {
    const res = await api('work_stop', 'POST', creds());
    if (!res.ok) { showError('actionError', res.error); return; }
    const s = res.data.session;
    const h = Math.floor((s.duration_minutes || 0) / 60);
    const m = (s.duration_minutes || 0) % 60;
    done('Arbeitszeit beendet', `${s.activity_name} – ${h} h ${m} min`);
});

function done(title, text) {
    // Nummer und PIN sofort verwerfen — nicht erst nach der Ruhezeit
    state.memberNumber = '';
    state.pin = '';
    state.identity = null;
    $('doneTitle').textContent = title;
    $('doneText').textContent = text || '';
    showScreen('done');
    setTimeout(resetToIdle, DONE_SECONDS * 1000);
}

// ============================================
// Einstellungen: 5 s auf die Uhr, dann Token bestaetigen
// ============================================

$('clock').addEventListener('pointerdown', () => {
    state.pressTimer = setTimeout(openSettings, 5000);
});
['pointerup', 'pointerleave', 'pointercancel'].forEach(evt => {
    $('clock').addEventListener(evt, () => { if (state.pressTimer) clearTimeout(state.pressTimer); });
});

function openSettings() {
    const entered = window.prompt('Zum Schutz der Einstellungen: API-Token des Geräts eingeben');
    if (entered === null) return;
    if (entered.trim() !== state.token) {
        window.alert('Token stimmt nicht.');
        return;
    }
    $('settingIdle').value = idleSeconds();
    showScreen('settings');
}

$('settingsClose').addEventListener('click', () => {
    const v = parseInt($('settingIdle').value, 10);
    if (Number.isFinite(v) && v >= 10 && v <= 300) {
        try { localStorage.setItem(STORAGE_IDLE, String(v)); } catch (e) { /* kein Speicher */ }
    }
    enterIdle();
});

$('settingsReload').addEventListener('click', () => window.location.reload());

$('settingsForget').addEventListener('click', () => {
    if (window.confirm('Token löschen? Die Station muss danach neu eingerichtet werden.')) {
        forgetToken();
        showScreen('setup');
    }
});

// ============================================
// Erscheinungsbild (oeffentlicher Endpunkt)
// ============================================

async function loadAppearance() {
    try {
        const url = new URL(API_BASE);
        url.searchParams.set('resource', 'appearance');
        const res = await fetch(url, { credentials: 'omit' });
        if (!res.ok) return;
        const s = (await res.json()).settings || {};
        if (s.organization_name) $('orgName').textContent = s.organization_name;
        if (s.primary_color) document.documentElement.style.setProperty('--primary-color', s.primary_color);
        if (s.background_color) document.documentElement.style.setProperty('--background-color', s.background_color);
        if (s.organization_logo) {
            const src = `../${s.organization_logo}`;
            $('idleLogo').src = src;
            $('setupLogo').src = src;
        }
    } catch (e) {
        debug.log('appearance nicht geladen', e);
    }
}

// ============================================
// Start
// ============================================

(async function init() {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('service-worker.js').catch(e => debug.log('SW', e));
    }

    await loadAppearance();

    const token = loadToken();
    if (token && !(await connect(token))) {
        await enterIdle();
    } else {
        showScreen('setup');
    }
})();
