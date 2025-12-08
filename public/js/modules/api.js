import { API_BASE } from '../config.js';
import { showLogin, showScreen, showToast, showDashboard, updateUIForRole} from './ui.js';

// Globale State
export let currentUser = null;
export let isAdmin = false;
export let csrfToken = null;
export let isInitialLoad = true;

export function setCurrentUser(user) {
    currentUser = user;
    isAdmin = user?.role === 'admin';
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
    
    for (const [key, value] of Object.entries(params)) {
        url.searchParams.append(key, value);
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
        const result = await response.json();
        
        if (!response.ok)
        {            
            if(response.status === 401) 
            {
                //Session abgelaufen
                csrfToken = null;                
                if (!isInitialLoad) {
                    const { showToast } = await import('./ui.js');
                    showToast('Sitzung abgelaufen. Bitte erneut anmelden.', 'warning');
                }
                const { showLogin } = await import('./ui.js');
                showLogin();
                
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
            showToast(errorMessage, 'error', errorTitle);
            return null;
        }
        
        //Reset Flag
        isInitialLoad = false;
        return result;
    } catch (error) {
        console.error('API Error:', error);
        const { showToast } = await import('./ui.js');
        showToast('Fehler bei der Kommunikation mit dem Server', 'error');
        return null;
    }
}