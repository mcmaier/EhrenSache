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
    const match = window.location.pathname.match(/^(.*?)\/station(?:\/|$)/);
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
    'Already paused':                       'Die Zeiterfassung ist bereits pausiert.',
    'Not paused':                           'Die Zeiterfassung ist nicht pausiert.',
    'Activity type not allowed for this member': 'Diese Tätigkeit ist für deine Gruppe nicht vorgesehen.',
    'activity_id must be a positive integer': 'Bitte eine Tätigkeit wählen.',
    // Generischer 404 von api.php, wenn keine Route passt — im Stations-Kontext
    // kann das nur heissen: die Zeiterfassung ist abgeschaltet.
    'Endpoint not found':                   'Die Zeiterfassung ist abgeschaltet.',
    'Kiosk device token required':          'Token gehört nicht zu einer virtuellen Station.',
    'Invalid or inactive API token':        'Token ungültig oder Gerät deaktiviert',
    'API token expired':                    'Token abgelaufen – bitte neu einrichten',
    'Device is inactive':                   'Dieses Gerät ist deaktiviert',
    'Rate limit exceeded':                  'Zu viele Anfragen – bitte kurz warten',
    'Unknown activity_id':                  'Diese Tätigkeit gibt es nicht mehr',
    'Activity type is retired':             'Diese Tätigkeit ist stillgelegt'
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
    autoTimer: null,       // Rueckfall zum Ruhebild nach done()/Sperre (M2)
    wakeLock: null,
    wakeLockHintShown: false,
    pressTimer: null,
    blocked: false,        // 403/409 auf Geraeteebene: Token bleibt, es wird auf Besserung gepollt (I1)
    nextCode: null,        // naechster TOTP-Code, fuer einen kurzen Uebergang bei fehlgeschlagenem Refresh (I2)
    nextUntil: 0,
    clockOffset: 0,        // Differenz Server-/Tablet-Uhr, aus der letzten erfolgreichen TOTP-Antwort
    validUntil: 0,         // Gueltigkeit des aktuell angezeigten Codes (Serverzeit), fuer renderBar()
    period: 30             // TOTP-Periode aus der letzten erfolgreichen Antwort, fuer renderBar()
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
        $('stampStart').disabled = true; // M7: erst wieder erlauben, wenn der Server wieder antwortet
        return { ok: false, status: 0, data: null, error: 'Keine Verbindung zum Server' };
    }
    $('stampStart').disabled = false;

    let data = null;
    try { data = await response.json(); } catch (e) { /* keine JSON-Antwort */ }

    const raw = data?.message || `HTTP ${response.status}`;
    const translated = SERVER_MESSAGES[raw] || raw;
    // Eine falsche PIN kommt ebenfalls als 401, meint aber keinen Tokenverlust
    // (die kommt von stationRequireMember(), nicht von api.php) — sonst wirft
    // eine vertippte PIN den Kiosk bis zur Einrichtung zurueck.
    const pinRejected = response.status === 401 && raw === 'Invalid member number or PIN';

    if (response.status === 401 && !pinRejected) {
        // Token abgelaufen oder Geraet geloescht: zurueck zur Einrichtung.
        // Ist bereits die Einrichtung aktiv (z. B. weil setupSave selbst den
        // 401 ausgeloest hat), nicht erneut umschalten/ueberschreiben — sonst
        // verliert setupSave die konkrete Fehlermeldung an diesen Text hier (M1).
        forgetToken();
        if (!$('screen-setup').classList.contains('active')) {
            showScreen('setup');
            showError('setupError', translated);
        }
    } else if (response.status === 403 || (response.status === 409 && raw === 'Device has no name')) {
        // Geraet gesperrt/deaktiviert/unbenannt: Token BEHALTEN und auf
        // Besserung pollen, statt wie bei 401 die Einrichtung zu verlangen (I1).
        enterBlocked(translated);
    } else {
        setBanner(null);
    }

    return { ok: response.ok, status: response.status, data, error: translated };
}

// ============================================
// Anzeige-Helfer
// ============================================

function showScreen(name) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    $(`screen-${name}`).classList.add('active');
    // C1: 'setup' und 'settings' zeigen keine Ruhezeit-relevante Eingabe — ohne
    // Verbindung (setup) oder waehrend der Konfiguration (settings) soll kein
    // Idle-Timer laufen, der spaeter ins Leere feuert.
    if (name === 'idle' || name === 'setup' || name === 'settings') {
        stopIdleTimer();
    } else {
        restartIdleTimer();
    }
    // Die Breiten-Transition der TOTP-Leiste laeuft nicht weiter, waehrend das
    // Ruhebild versteckt ist (display:none kappt CSS-Transitions) — bei der
    // Rueckkehr stand die Leiste sonst bis zum naechsten Refresh grau/leer,
    // obwohl totpTimer im Hintergrund laengst weiter aktualisiert hat.
    if (name === 'idle' && state.status?.totp_enabled) {
        if (!state.totpTimer) {
            refreshTotp(); // Refresh war waehrend des Verstecks fehlgeschlagen — jetzt nachholen
        } else if ($('totpCode').textContent !== '------') {
            renderBar();
        }
    }
}

function clearCodeDisplay() {
    $('totpCode').textContent = '------';
    $('qr').innerHTML = '';
}

function setBanner(text) {
    const banner = $('banner');
    banner.hidden = !text;
    banner.textContent = text || '';
    if (text) {
        clearCodeDisplay();
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
    // C1: ohne Token gibt es kein Ruhebild, zu dem zurueckgekehrt werden
    // koennte — ein noch laufender Timer aus der Zeit vor dem 401 wuerde
    // sonst ins Leere feuern und einen Screen ohne Verbindung aktivieren.
    if (state.token === null) return;
    if (state.autoTimer) clearTimeout(state.autoTimer);
    state.autoTimer = null;
    state.memberNumber = '';
    state.pin = '';
    state.identity = null;
    state.selectedActivity = null;
    showError('pinError', null);
    showError('actionError', null);
    // M5: Reste der letzten Sitzung nicht mit ins naechste Ruhebild nehmen.
    $('numberDisplay').textContent = '';
    $('pinDisplay').textContent = '';
    $('greeting').textContent = '';
    $('attendanceInfo').textContent = '';
    $('worktimeInfo').textContent = '';
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
    state.blocked = false;
    showScreen('idle');
    applyStatus();
    startClock();
    // C2: Intervall und Wake Lock VOR dem await auf refreshTotp() scharf
    // schalten — wirft refreshTotp() (Netzwerk, kaputtes JSON, Rendering),
    // sollen Status-Polling und Bildschirm-wach trotzdem laufen.
    if (state.statusTimer) clearInterval(state.statusTimer);
    state.statusTimer = setInterval(refreshStatus, STATUS_EVERY);
    requestWakeLock();
    await refreshTotp();
}

function stopIdleLoops() {
    if (state.totpTimer) clearTimeout(state.totpTimer);
    if (state.statusTimer) clearInterval(state.statusTimer);
    if (state.clockTimer) clearInterval(state.clockTimer);
    state.totpTimer = state.statusTimer = state.clockTimer = null;
}

/**
 * 403 (Geraet deaktiviert/falscher Typ) oder 409 "Device has no name": Token
 * bleibt erhalten, Ruhebild-Schleifen stehen still, ein leichtes 60s-Intervall
 * fragt weiter status ab, bis der Fehler behoben ist (I1).
 */
function enterBlocked(message) {
    setBanner(message);
    clearCodeDisplay();
    if (state.blocked) return; // Erholungs-Polling laeuft schon, nur die Banner-Meldung auffrischen
    state.blocked = true;
    stopIdleLoops();
    state.statusTimer = setInterval(async () => {
        const res = await api('status');
        if (res.ok) {
            state.status = res.data;
            state.blocked = false;
            enterIdle();
        }
    }, 60000);
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
        // I2: kurz nach Ablauf des zuletzt gezeigten Codes den bereits
        // bekannten naechsten Code einmal weiter anzeigen statt sofort auf
        // Striche zu springen — gegen die SERVERuhr gerechnet (clockOffset).
        const nowOnServer = Date.now() / 1000 + state.clockOffset;
        if (state.nextCode && nowOnServer < state.nextUntil) {
            try {
                renderTotp(state.nextCode);
            } catch (e) {
                debug.error('renderTotp', e);
                clearCodeDisplay();
            }
            state.totpTimer = setTimeout(refreshTotp, 5000);
            return;
        }

        clearCodeDisplay();
        if (res.status === 0) {
            state.totpTimer = setTimeout(refreshTotp, 10000);
        } else if (res.status !== 404) {
            // 404 (Code abgeschaltet): kein eigener Retry, das naechste Status-Intervall reicht
            state.totpTimer = setTimeout(refreshTotp, 30000);
        }
        return;
    }

    if (!res.data || typeof res.data.code !== 'string') {
        clearCodeDisplay();
        state.totpTimer = setTimeout(refreshTotp, 10000);
        return;
    }

    const { code, valid_until, now, period, next_code } = res.data;
    try {
        renderTotp(code);
    } catch (e) {
        debug.error('renderTotp', e);
        clearCodeDisplay();
    }

    state.clockOffset = now - Date.now() / 1000;
    state.nextCode  = typeof next_code === 'string' ? next_code : null;
    state.nextUntil = valid_until + period;
    state.validUntil = valid_until;
    state.period = period;

    // Restlaufzeit gegen die SERVERuhr; die Tablet-Uhr geht oft falsch
    const fetchedAt = Date.now();
    const remaining = Math.max(1, valid_until - now);
    renderBar();

    state.totpTimer = setTimeout(refreshTotp, remaining * 1000 - (Date.now() - fetchedAt) + 200);
}

/**
 * Zeichnet die Restlaufzeit-Leiste des aktuellen Codes neu. Ausgelagert aus
 * refreshTotp(), damit showScreen('idle') die Leiste auch dann wieder in
 * Gang setzen kann, wenn seit dem letzten Refresh Zeit vergangen ist,
 * waehrend der Bildschirm versteckt war (CSS-Transitions laufen nicht unter
 * display:none weiter).
 */
function renderBar() {
    const period = state.period || 30;
    const remaining = state.validUntil - (Date.now() / 1000 + state.clockOffset);
    if (remaining <= 0) {
        refreshTotp();
        return;
    }
    const bar = $('totpBar');
    bar.style.transition = 'none';
    bar.style.width = `${(remaining / period) * 100}%`;
    requestAnimationFrame(() => {
        bar.style.transition = `width ${remaining}s linear`;
        bar.style.width = '0%';
    });
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
    if (state.wakeLock && !state.wakeLock.released) return; // M4: schon aktiv
    if (!('wakeLock' in navigator)) {
        showWakeLockHint();
        return;
    }
    try {
        state.wakeLock = await navigator.wakeLock.request('screen');
    } catch (e) {
        debug.log('Wake Lock nicht verfügbar:', e);
        showWakeLockHint();
    }
}

/** I5: ohne Wake Lock schlaeft das Tablet frueher oder spaeter ein — einmaliger Hinweis. */
function showWakeLockHint() {
    if (state.wakeLockHintShown) return;
    state.wakeLockHintShown = true;
    const name = state.status?.device_name || '';
    $('deviceName').textContent = `${name} · Bildschirm-Timeout im Tablet deaktivieren`;
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
        if (key === '') {
            btn.disabled = true;
            btn.style.visibility = 'hidden'; // M9: Platzhalter reagiert nicht auf Klicks
        } else {
            btn.addEventListener('click', () => onKey(key));
        }
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
    if (state.autoTimer) clearTimeout(state.autoTimer); // M2
    state.autoTimer = null;
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
            state.autoTimer = setTimeout(resetToIdle, 4000); // M2
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
    const btn = $('attendanceBtn');
    btn.disabled = true; // M3
    try {
        const res = await api('checkin', 'POST', creds());
        if (!res.ok) { showError('actionError', res.error); return; }
        const apt = res.data?.appointment; // I3
        done('Anwesenheit gespeichert', apt ? `${apt.title} – ${String(apt.start_time).slice(0, 5)} Uhr` : '');
    } finally {
        btn.disabled = false;
    }
});

$('worktimeStartBtn').addEventListener('click', async () => {
    if (!state.selectedActivity) { showError('actionError', 'Bitte eine Tätigkeit wählen.'); return; }
    const btn = $('worktimeStartBtn');
    btn.disabled = true; // M3
    try {
        const res = await api('work_start', 'POST', { ...creds(), activity_id: Number(state.selectedActivity) });
        if (!res.ok) { showError('actionError', res.error); return; }
        const s = res.data?.session; // I3
        done('Arbeitszeit gestartet', s?.activity_name || '');
    } finally {
        btn.disabled = false;
    }
});

$('worktimePauseBtn').addEventListener('click', async () => {
    const btn = $('worktimePauseBtn');
    const paused = state.identity?.running_session?.is_paused;
    btn.disabled = true; // M3
    try {
        const res = await api(paused ? 'work_resume' : 'work_pause', 'POST', creds());
        if (!res.ok) { showError('actionError', res.error); return; }
        const s = res.data?.session; // I3
        done(paused ? 'Weiter geht’s' : 'Pause', s?.activity_name || '');
    } finally {
        btn.disabled = false;
    }
});

$('worktimeStopBtn').addEventListener('click', async () => {
    const btn = $('worktimeStopBtn');
    btn.disabled = true; // M3
    try {
        const res = await api('work_stop', 'POST', creds());
        if (!res.ok) { showError('actionError', res.error); return; }
        const s = res.data?.session; // I3
        const h = Math.floor((s?.duration_minutes || 0) / 60);
        const m = (s?.duration_minutes || 0) % 60;
        done('Arbeitszeit beendet', s ? `${s.activity_name} – ${h} h ${m} min` : '');
    } finally {
        btn.disabled = false;
    }
});

function done(title, text) {
    // Nummer und PIN sofort verwerfen — nicht erst nach der Ruhezeit
    state.memberNumber = '';
    state.pin = '';
    state.identity = null;
    $('doneTitle').textContent = title;
    $('doneText').textContent = text || '';
    showScreen('done');
    state.autoTimer = setTimeout(resetToIdle, DONE_SECONDS * 1000); // M2
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
    // C1: gegen den gespeicherten Token pruefen, nicht gegen state.token — der
    // ist nach einem 401 bereits null, waehrend der Nutzer noch den alten
    // Token im Kopf hat, um die Station wieder einzurichten.
    const stored = loadToken();
    if (stored === null) {
        showScreen('setup');
        return;
    }
    const entered = window.prompt('Zum Schutz der Einstellungen: API-Token des Geräts eingeben');
    if (entered === null) return;
    if (entered.trim() !== stored) {
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
        const fetchOptions = { credentials: 'omit' };
        // I4: das Branding darf den Boot nicht aufhalten, wenn der oeffentliche
        // Endpunkt haengt — nach 3 s aufgeben.
        if (typeof AbortSignal.timeout === 'function') {
            fetchOptions.signal = AbortSignal.timeout(3000);
        }
        const res = await fetch(url, fetchOptions);
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

    loadAppearance(); // I4: nicht blockierend — der Boot haengt nicht am Branding

    const token = loadToken();
    if (token && !(await connect(token))) {
        await enterIdle();
    } else {
        showScreen('setup');
    }
})();
