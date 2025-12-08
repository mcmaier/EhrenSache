import { apiCall, setCurrentUser, currentUser, setCsrfToken} from './api.js';
import { loadAllData, showLogin, showScreen, showToast, showDashboard, updateUIForRole} from './ui.js';

// ============================================
// AUTH
// Reference:
// import {} from './auth.js'
// ============================================


export async function handleLogin(e) {
    e.preventDefault();

    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    const errorDiv = document.getElementById('loginError');
    
    errorDiv.textContent = '';
    
    const result = await apiCall('login', 'POST', { email, password });
    
    if (result && result.success) {
        setCurrentUser(result.user);

         // Speichere CSRF-Token
        if (result.csrf_token) {
            sessionStorage.setItem('csrf_token', result.csrf_token);
            setCsrfToken(result.csrfToken);
            console.log('CSRF token stored:', result.csrf_token.substring(0, 16) + '...');
        }

        showDashboard();
        loadAllData();
    } else {
        errorDiv.textContent = result?.message || 'Login fehlgeschlagen';
    }
}

export async function handleLogout() {
    await apiCall('logout', 'POST');  
    sessionStorage.removeItem('csrf_token');
    setCsrfToken(null);
    sessionStorage.removeItem('currentSection');
    showLogin();
}

// Event Listeners
export function initAuth() {
    loginForm.addEventListener('submit', (e) => {
        e.preventDefault();  // Verhindert URL-Parameter
        handleLogin(e);
    });
    
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }
}
