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
import { resetSessionTimeout } from './auth.js';
import { debug } from '../app.js'

// Globale State
export let currentUser = null;
export let isAdmin = false;
export let isManager = false;
export let isAdminOrManager = false;
export let csrfToken = null;
export let isInitialLoad = true;

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
    isInitialLoad = value;
}

// API Helper Funktion
export async function apiCall(resource, method = 'GET', data = null, params = {}) {
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
                //Session abgelaufen
                csrfToken = null;     
                window.location.href = 'login.html';             
                if (!isInitialLoad) {
                    const { showToast } = await import('./ui.js');
                    showToast('Sitzung abgelaufen. Bitte erneut anmelden.', 'warning');
                    isInitialLoad = true;
                }                              
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

            const errorTitle = errorMessages[response.status] || 'Fehler';
            const errorMessage = result.message || result.hint || 'Ein unbekannter Fehler ist aufgetreten';
            const { showToast } = await import('./ui.js');
            showToast(errorMessage, 'error');
            return result;
        }
        
        //Reset Flag
        isInitialLoad = false;        

        return result;
    } catch (error) {
        debug.error('API Error:', error);
        const { showToast } = await import('./ui.js');
        showToast('Fehler bei der Kommunikation mit dem Server', 'error', 6000);
        return result;
    }
    
}

// Neue Hilfsfunktion für Auth-Headers (auch für FormData)
export function getAuthHeaders(skipContentType = false) {
    const headers = {
        'Authorization': `Bearer ${sessionStorage.getItem('api_token')}`
    };
    
    if (!skipContentType) {
        headers['Content-Type'] = 'application/json';
    }
    
    return headers;
}
