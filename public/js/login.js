/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

const API_BASE = 'api/api.php';

// ============================================
// TAB SWITCHING
// ============================================

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabName = btn.dataset.tab;
        
        // Update buttons
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        // Update content
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById(tabName + 'Tab').classList.add('active');
    });
});

// ============================================
// LOGIN
// ============================================

document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const email = document.getElementById('loginEmail').value;
    const password = document.getElementById('loginPassword').value;
    const errorDiv = document.getElementById('loginError');
    
    errorDiv.style.display = 'none';
    
    try {
        const response = await fetch(`${API_BASE}?resource=login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ email, password })
        });
        
        const data = await response.json();
        
        if (data.success) {

            // CSRF-Token speichern
            if (data.csrf_token) {
                sessionStorage.setItem('csrf_token', data.csrf_token);
            }
            
            // User-Daten speichern
            if (data.user) {
                sessionStorage.setItem('current_user', JSON.stringify(data.user));
            }

            // Redirect zu Dashboard            
            window.location.href = 'index.html';
        } else {
            errorDiv.textContent = data.message || 'Login fehlgeschlagen';
            errorDiv.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Anmelden';
        }
    } catch (error) {
        console.error('Login error:', error);
        errorDiv.textContent = 'Verbindungsfehler';
        errorDiv.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Anmelden';
    }
});

// ============================================
// REGISTRATION
// ============================================

document.getElementById('registerForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const name = document.getElementById('registerName').value;
    const email = document.getElementById('registerEmail').value;
    const password = document.getElementById('registerPassword').value;
    const passwordConfirm = document.getElementById('registerPasswordConfirm').value;
    const errorDiv = document.getElementById('registerError');
    const successDiv = document.getElementById('registerSuccess');
    
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    
    // Validierung
    if (password !== passwordConfirm) {
        errorDiv.textContent = 'Passwörter stimmen nicht überein';
        errorDiv.style.display = 'block';
        return;
    }
    
    if (password.length < 8) {
        errorDiv.textContent = 'Passwort muss mindestens 8 Zeichen lang sein';
        errorDiv.style.display = 'block';
        return;
    }

    // Privacy Policy Validierung (nur wenn sichtbar)
    const privacyCheckbox = document.getElementById('acceptPrivacy');
    const privacyGroup = document.getElementById('privacyPolicyGroup');
    
    if (privacyGroup.style.display !== 'none' && !privacyCheckbox.checked) {
        errorDiv.textContent = 'Bitte akzeptieren Sie die Datenschutzerklärung';
        errorDiv.style.display = 'block';
        return;
    }
    
    try {
        const response = await fetch(`${API_BASE}?resource=register`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ name, email, password })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Erfolg oder teilweiser Erfolg
            if (data.partial) {
                // Warnung: Registrierung OK, aber Mail-Problem
                successDiv.innerHTML = `
                    <strong>⚠️ Registrierung erfolgreich mit Einschränkung</strong><br>
                    ${data.message}
                `;
                successDiv.style.background = '#fff3cd';
                successDiv.style.borderColor = '#ffc107';
                successDiv.style.color = '#856404';
            } else {
                // Voller Erfolg
                successDiv.textContent = data.message;
            }

            successDiv.style.display = 'block';
            document.getElementById('registerForm').reset();

            // Checkbox zurücksetzen (falls vorhanden)
            if (privacyCheckbox) {
                privacyCheckbox.checked = false;
            }

             setTimeout(() => {
                document.querySelector('[data-tab="login"]').click();
            }, 5000)
        } else {
            errorDiv.textContent = data.message || 'Registrierung fehlgeschlagen';
            errorDiv.style.display = 'block';
        }
    } catch (error) {
        errorDiv.textContent = 'Verbindungsfehler';
        errorDiv.style.display = 'block';
    }
    finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Anmelden';
    }
});

// ============================================
// PASSWORD RESET
// ============================================

document.getElementById('forgotPasswordLink').addEventListener('click', (e) => {
    e.preventDefault();
    document.getElementById('forgotPasswordModal').classList.add('active');
});

window.closeForgotPasswordModal = function() {
    document.getElementById('forgotPasswordModal').classList.remove('active');
};

document.getElementById('forgotPasswordForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const email = document.getElementById('resetEmail').value;
    const errorDiv = document.getElementById('resetError');
    const successDiv = document.getElementById('resetSuccess');
    
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    
    try {
        const response = await fetch(`${API_BASE}?resource=password_reset_request`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
        });
        
        const data = await response.json();
        
        if (data.success) {
            successDiv.textContent = data.message;
            successDiv.style.display = 'block';
            document.getElementById('forgotPasswordForm').reset();
        } else {
            errorDiv.textContent = data.message || 'Fehler beim Senden';
            errorDiv.style.display = 'block';
        }
    } catch (error) {
        errorDiv.textContent = 'Verbindungsfehler';
        errorDiv.style.display = 'block';
    }
});

// ============================================
// CHECK IF ALREADY LOGGED IN
// ============================================

async function checkAuth() {
    try {
        const response = await fetch(`${API_BASE}?resource=me`, {
            credentials: 'include'  // ← WICHTIG
        });

        if (response.ok) {
            // Bereits eingeloggt → Redirect zu Dashboard
            window.location.href = 'index.html';
        }
    } catch (error) {
        // Nicht eingeloggt = OK, auf Login-Seite bleiben
         console.log('Not logged in');
    }
}

checkAuth();