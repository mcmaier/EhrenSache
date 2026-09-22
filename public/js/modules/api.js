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
import { handleLogout, resetSessionTimeout } from './auth.js';
import { debug } from '../app.js'

// Globale State
export let currentUser = null;
export let isAdmin = false;
export let isManager = false;
export let isAdminOrManager = false;
export let csrfToken = null;
//export let isInitialLoad = true;

export async function setCurrentUser(user) {
    currentUser = user;
    isAdmin = user?.role === 'admin';
    isManager = user?.role === 'manager';
    isAdminOrManager = isAdmin || isManager;

    // Lade Member-Name falls vorhanden
    if (user && user.member_id) {
        try {
            const member = await apiCall('members', 'GET', null, { id: user.member_id });
            if (member) {
                currentUser.member_name = `${member.name} ${member.surname}`;
            }
        } catch (error) {
            debug.log('Member-Name konnte nicht geladen werden:', error);
        }
    }
}

export function setCsrfToken(token) {
    csrfToken = token;
}

export function setInitialLoad(value) {
    //isInitialLoad = value;
}

// ============================================
// LADEANZEIGE UND TIMEOUT (OI-85, seit 1.12.1)
// ============================================
//
// Ein schmaler Balken am oberen Rand, erst nach LOADING_DELAY_MS -- schnelle
// Antworten sollen nicht flackern. Er blockiert nichts. Er haengt an einem
// Zaehler offener Anfragen, nicht an einem Schalter: Sonst blendete ihn die
// erste fertige Antwort aus, waehrend die zweite noch laeuft.
//
// Dieselbe Regel steht in public/checkin/js/app.js; die PWA ist ein
// eigenstaendiges Skript ohne Zugriff auf diese Module.

export const API_TIMEOUT_MS   = 20000;
export const LOADING_DELAY_MS = 300;

let openRequests = 0;
let loadingTimer = null;

function setLoadingBar(visible) {
    let bar = document.getElementById('apiLoadingBar');
    if (!bar) {
        if (!visible) return;
        bar = document.createElement('div');
        bar.id = 'apiLoadingBar';
        bar.className = 'api-loading-bar';
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-label', 'Lädt …');
        document.body.appendChild(bar);
    }
    bar.hidden = !visible;
}

function loadingStart() {
    openRequests++;
    if (openRequests === 1) {
        loadingTimer = setTimeout(() => setLoadingBar(true), LOADING_DELAY_MS);
    }
}

function loadingEnd() {
    openRequests = Math.max(0, openRequests - 1);
    if (openRequests === 0) {
        clearTimeout(loadingTimer);
        loadingTimer = null;
        setLoadingBar(false);
    }
}

/**
 * Meldung nach einem Timeout. Er bricht nur das Warten ab, nicht die Anfrage:
 * Ein POST, der danach doch durchlaeuft, hat trotzdem angelegt. "Fehlgeschlagen"
 * verleitete dazu, dasselbe ein zweites Mal abzuschicken.
 */
export function apiTimeoutMessage(method) {
    return method === 'GET'
        ? 'Der Server antwortet nicht. Bitte erneut versuchen.'
        : 'Keine Antwort vom Server. Ob gespeichert wurde, ist unklar – bitte die Ansicht neu laden, bevor du es erneut versuchst.';
}

/**
 * API Helper Funktion
 *
 * @param callOptions.silentStatuses HTTP-Stati, die der Aufrufer selbst
 *        auswertet. Fuer sie unterbleibt der Fehler-Toast — die Antwort wird
 *        trotzdem zurueckgegeben. Gedacht fuer Faelle, in denen ein
 *        Fehlerstatus eine gueltige Auskunft ist: ein abgeschaltetes Feature
 *        antwortet bewusst mit 404, statt seine Existenz preiszugeben.
 * @param callOptions.timeout Millisekunden bis zum Abbruch des Wartens,
 *        Vorgabe API_TIMEOUT_MS; 0 heisst ohne Timeout (Loeschfristen,
 *        Update-Paket). Ein Timeout liefert { success: false, timedOut: true }.
 */
export async function apiCall(resource, method = 'GET', data = null, params = {}, callOptions = {}) {
    const url = new URL(API_BASE, window.location.origin);
    url.searchParams.append('resource', resource);

    let result = {success:false};
    
    // Query-Parameter hinzufügen
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined) {
            url.searchParams.set(key, value);
        }
    }

    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json'
        },
        credentials: 'include'
    };

    if (data && (method === 'POST' || method === 'PUT')) {
        
        const csrfToken = sessionStorage.getItem('csrf_token');
        if (csrfToken) {
            data.csrf_token = csrfToken;
        }
        options.body = JSON.stringify(data);
    }

    // Bei DELETE: CSRF-Token als Query-Parameter
    if (method === 'DELETE' && currentUser) {
        const csrfToken = sessionStorage.getItem('csrf_token');
        if (csrfToken) {
            url.searchParams.append('csrf_token', csrfToken);
        }
    }

    const timeout = callOptions.timeout ?? API_TIMEOUT_MS;
    const controller = new AbortController();
    const abortTimer = timeout > 0 ? setTimeout(() => controller.abort(), timeout) : null;
    options.signal = controller.signal;

    loadingStart();

    try {
        const response = await fetch(url, options);
        result = await response.json();

        result.success = response.ok;

        // Bei erfolgreichem API-Call: Session verlängern
        if (response.ok && currentUser) {
            resetSessionTimeout();
        }
                
        //Debug
        debug.log("API call:",resource, method, params, data);

        if (!response.ok)
        {            
            if(response.status === 401) 
            {
                handleLogout(true);                           
                return null;
            }
            
            // Andere Fehler als Toast anzeigen
            const errorMessages = {
                400: 'Ungültige Anfrage',
                403: 'Zugriff verweigert',
                404: 'Nicht gefunden',
                409: 'Konflikt',
                500: 'Serverfehler'
            };

            if (!(callOptions.silentStatuses || []).includes(response.status)) {
                const errorTitle = errorMessages[response.status] || 'Fehler';
                const errorMessage = result.message || result.hint || 'Ein unbekannter Fehler ist aufgetreten';
                const { showToast } = await import('./ui.js');
                showToast(errorMessage, 'error');
            }

            return result;
        }
        
        //Reset Flag
        //isInitialLoad = false;        

        return result;
    } catch (error) {
        debug.error('API Error:', error);
        const { showToast } = await import('./ui.js');

        if (error.name === 'AbortError') {
            showToast(apiTimeoutMessage(method), 'error', 8000);
            return { success: false, timedOut: true, message: apiTimeoutMessage(method) };
        }

        showToast('Fehler bei der Kommunikation mit dem Server', 'error', 6000);
        return result;
    } finally {
        clearTimeout(abortTimer);
        loadingEnd();
    }
}

/**
 * Auth-Header für Aufrufe, die nicht über apiCall() laufen (z. B. FormData,
 * Blob-Downloads).
 *
 * Der Header entsteht NUR, wenn tatsächlich ein Token vorliegt. Ohne diese
 * Bedingung stand hier `Bearer null` — ein gültig aussehender Header ohne
 * Inhalt, und der ist schlimmer als gar keiner:
 *
 * `api.php` startet die Session nur, wenn KEIN Token mitkommt
 * (`if (!$apiToken) session_start()`). Ein `Bearer null` verdrängt also die
 * Session, läuft in den Token-Zweig und endet mit
 * `401 Invalid or inactive API token` — obwohl der Nutzer angemeldet ist.
 *
 * Das Dashboard legt gar keinen Token ab; `sessionStorage` hält hier nur
 * `csrf_token` und `current_user`. Der Header war deshalb immer leer. Vier
 * Monate lang blieb das folgenlos, weil Apache den Authorization-Header
 * verschluckte — bis `3d1a30e` (2026-04-16) ihn für die Token-Auth der PWA
 * durchreichte und damit die CSV-Exporte lahmlegte. Siehe OI-24.
 */
export function getAuthHeaders(skipContentType = false) {
    const headers = {};

    const token = sessionStorage.getItem('api_token');
    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    if (!skipContentType) {
        headers['Content-Type'] = 'application/json';
    }

    return headers;
}
