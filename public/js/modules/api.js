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

/**
 * API Helper Funktion
 *
 * @param callOptions.silentStatuses HTTP-Stati, die der Aufrufer selbst
 *        auswertet. Fuer sie unterbleibt der Fehler-Toast — die Antwort wird
 *        trotzdem zurueckgegeben. Gedacht fuer Faelle, in denen ein
 *        Fehlerstatus eine gueltige Auskunft ist: ein abgeschaltetes Feature
 *        antwortet bewusst mit 404, statt seine Existenz preiszugeben.
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
        showToast('Fehler bei der Kommunikation mit dem Server', 'error', 6000);
        return result;
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
