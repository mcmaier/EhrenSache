/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 * 
 * Copyright (c) 2026 Martin Maier
 * 
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

import {TOAST_DURATION} from '../config.js';
import {escapeHtml} from './utils.js';
import {apiCall, isAdmin, isAdminOrManager, currentUser} from './api.js';
import {loadProfile, initProfileEventHandler} from './profile.js';
import {loadUsers, showUserSection, initUsersEventHandlers} from'./users.js';
import {showDeviceSection} from'./devices.js';
import {loadAppointments, setCalendarToYear, showAppointmentSection, initAppointmentEventHandlers} from'./appointments.js';
import {loadExceptions, showExceptionSection, initExceptionEventHandlers} from'./exceptions.js';
import {loadRecords, showRecordsSection, initRecordEventHandlers, resetRecordFilter} from'./records.js';
import {loadMembers, showMemberSection} from'./members.js';
import {loadGroups, loadTypes, showGroupSection} from './management.js';
import {initStatisticsEventHandlers, showStatisticsSection} from './statistics.js';
import {showWorktimeSection, loadWorkSessions, loadActivityTypes, renderActivityTypes,
        checkWorktimeEnabled, initWorktimeEventHandlers} from './worktime.js';
import {debug} from '../app.js'
import {renderSystemSettings} from './settings.js';
import {loadImportLogs} from './import_export.js';

// ============================================
// UI
// Reference:
// import {} from './ui.js'
// ============================================

let yearFiltersInitialized = false;

// ============================================
// CACHING
// ============================================

export const dataCache = {
    userData: { data: [], timestamp: null},
    //members: { data: [], year: null, timestamp: null },
    users: { data: [], timestamp: null },
    devices: { data: [], timestamp: null },
    groups: { data: [], timestamp: null },
    types: { data: [], timestamp: null },
    availableYears: { data: [], timestamp: null }, 
    
    //Jahresabhängige Daten separat
    members: {},
    appointments: {},
    records: {},
    exceptions: {},
    workSessions: {},
    holidays: {},

    // Systemeinstellungen (nur admin-lesbar; subgroupLabel() liest NICHT von
    // hier, siehe dort — settings.js pflegt diesen Eintrag für seine eigenen
    // admin-only Zwecke weiter)
    settings: { data: {}, timestamp: null }
};

const CACHE_TTL = 10 * 60 * 1000; // 10 Minuten

export function isCacheValid(cacheKey, year = null) {

    const cached = dataCache[cacheKey];

    // Jahresabhängige Daten
    if (year !== null) {
        if (!cached[year] || !cached[year].data || !cached[year].timestamp) {
            return false;
        }
        return (Date.now() - cached[year].timestamp) < CACHE_TTL;
    }
    
    // Normale Daten
    if (!cached.data || !cached.timestamp) return false;
    return (Date.now() - cached.timestamp) < CACHE_TTL;
}

export async function invalidateCache(cacheKey = null, year = null) {
    if (cacheKey) {
        if (year !== null) {
            // Spezifisches Jahr invalidieren
            if (dataCache[cacheKey][year]) {
                dataCache[cacheKey][year] = { data: [], timestamp: null };
            }
        } else if (typeof dataCache[cacheKey] === 'object' && !Array.isArray(dataCache[cacheKey])) {
            // Alle Jahre invalidieren
            Object.keys(dataCache[cacheKey]).forEach(y => {
                dataCache[cacheKey][y] = { data: [], timestamp: null };
            });
        } else {
            // Normale Cache-Einträge
            dataCache[cacheKey].data = [];
            dataCache[cacheKey].timestamp = null;
        }
    } else {
        // Alles invalidieren
        Object.keys(dataCache).forEach(key => {
            if (typeof dataCache[key] === 'object' && !Array.isArray(dataCache[key]) && !dataCache[key].data) {
                dataCache[key] = {};
            } else {
                dataCache[key].data = [];
                dataCache[key].timestamp = null;
            }
        });
    }
}

// ============================================
// Untergruppen-Bezeichnung
// ============================================

/**
 * Liefert das eingestellte Wort für Untergruppen (z. B. "Register").
 * `subgroup_label` liegt in der Kategorie 'public' (wie Vereinsname, Farben,
 * Datenschutz-URL) und kommt deshalb über denselben Weg wie diese: theme.js
 * lädt beim Seitenaufruf `resource=appearance` — ohne Anmeldung, ohne
 * Adminrechte — und legt das Ergebnis unter sessionStorage 'theme-settings'
 * ab (siehe public/js/theme.js, loadTheme()). Kein eigener, admin-only
 * Ladeweg nötig; gilt deshalb für alle Rollen gleich.
 */
export function subgroupLabel() {
    let wert = '';
    try {
        const raw = sessionStorage.getItem('theme-settings');
        if (raw) {
            const settings = JSON.parse(raw);
            wert = (settings?.subgroup_label || '').trim();
        }
    } catch (error) {
        wert = '';
    }
    return wert === '' ? 'Untergruppe' : wert;
}

/** Füllt alle [data-subgroup-label]-Elemente im Dokument mit dem aktuellen Wort. */
export function updateSubgroupLabelElements() {
    const label = subgroupLabel();
    document.querySelectorAll('[data-subgroup-label]').forEach(el => {
        el.textContent = label;
    });
}

/**
 * Baut die <option>-Einträge für ein Gruppen-Auswahlfeld. Zugehörigkeitsgruppen
 * und Untergruppen (`is_subgroup`) erscheinen fachlich getrennt: native
 * <optgroup>-Überschriften statt Farbe oder Trennzeichen, ohne Zusatzaufwand
 * barrierefrei und ohne CSS. Erwartet `groups` bereits serverseitig sortiert
 * (sort_order, group_name — siehe member_groups.php), sortiert hier nicht neu,
 * filtert nur in zwei Töpfe.
 *
 * Gibt es keine Untergruppe in der Liste, bleibt die Ausgabe eine flache
 * Options-Liste wie vor 1.8.0 — keine leere zweite Überschrift, keine
 * Optgroup nur für die erste.
 */
export function groupSelectOptionsHtml(groups) {
    const list = Array.isArray(groups) ? groups : [];
    // g.group_name kommt aus der Gruppenverwaltung (DB) -- ohne CSP (OI-17)
    // muss hier selbst maskiert werden.
    const option = g => `<option value="${g.group_id}">${escapeHtml(g.group_name)}</option>`;
    const subgroups = list.filter(g => g.is_subgroup == 1);

    if (subgroups.length === 0) {
        return list.map(option).join('');
    }

    const main = list.filter(g => g.is_subgroup != 1);
    // subgroupLabel() ist Freitext aus den Einstellungen -- für den
    // Attribut-Kontext reicht das Escaping von escapeHtml() (utils.js) nicht,
    // da es Anführungszeichen im Textknoten nicht kodiert.
    const attrLabel = subgroupLabel().replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    return `<optgroup label="Gruppen">${main.map(option).join('')}</optgroup>`
        + `<optgroup label="${attrLabel}">${subgroups.map(option).join('')}</optgroup>`;
}

// ============================================
// Jahresabhängige Filterung
// ============================================
export let currentYear = sessionStorage.getItem('selectedYear') || new Date().getFullYear();

export function setCurrentYear(year) {
    currentYear = parseInt(year);
    sessionStorage.setItem('selectedYear', year);
    
    // Alle Jahresfilter synchronisieren
    syncAllYearFilters(year);
    
    // Jahresabhängige Daten neu laden
    loadYearDependentData();
}

function syncAllYearFilters(year) {
    // Alle Jahres-Select-Felder finden und synchronisieren
    const yearSelects = document.querySelectorAll('[id$="YearFilter"], [id*="year"]');
    yearSelects.forEach(select => {
        if (select.tagName === 'SELECT' && select.value !== year.toString()) {
            select.value = year;
        }
    });
}

export async function populateYearFilter(selectElement) {
    const years = await loadAvailableYears();
    const savedYear = sessionStorage.getItem('selectedYear') || currentYear;

    debug.log("Populating year filter.", years)
    
    selectElement.innerHTML = '';
    years.forEach(year => {
        const option = document.createElement('option');
        option.value = year;
        option.textContent = year;
        if (year === parseInt(savedYear)) option.selected = true;
        selectElement.appendChild(option);
    });
}

export async function initAllYearFilters() {

    // Verhindere Mehrfach-Initialisierung
    if (yearFiltersInitialized) {
        debug.log("Year filters already initialized, skipping");
        return;
    }

    // Alle Jahresfilter identifizieren und befüllen
    const yearFilters = [
        'memberYearFilter',
        'appointmentYearFilter',
        'recordYearFilter', 
        'exceptionYearFilter',
        'statisticYearFilter',
        'worktimeYearFilter'
    ];

    debug.log("Initializing Year Filters");

    // PHASE 1: Alle Dropdowns befüllen (OHNE Events zu triggern)
    for (const filterId of yearFilters) {
        const element = document.getElementById(filterId);
        if (element) {
            await populateYearFilter(element);
            element.value = currentYear;
        }
    }

    // PHASE 2: Jetzt Event-Listener registrieren (nachdem alle befüllt sind)
    for (const filterId of yearFilters) {
        const element = document.getElementById(filterId);
        if (element) {
            element.addEventListener('change', (e) => {
                const newYear = e.target.value;
                setCurrentYear(newYear);
                setCalendarToYear();

                const section = sessionStorage.getItem('currentSection');                
                if (section === 'anwesenheit') {
                    resetRecordFilter();
                }
            });
        }
    }
    
    yearFiltersInitialized = true;
    debug.log("Year filters initialized successfully");
    
}

export async function loadAvailableYears(forceReload = false) {

    if (!forceReload && isCacheValid('availableYears')) {
        debug.log('Loading AVAILABLE YEARS from CACHE');
        return dataCache.availableYears.data;
    }
    
    debug.log('Loading AVAILABLE YEARS from API');
    const years = await apiCall('available_years');  // Direkt das Array    
    
    if (Array.isArray(years)) {
        dataCache.availableYears.data = years;
        dataCache.availableYears.timestamp = Date.now();
        return years;
    }
    
    // Fallback
    return [new Date().getFullYear()];
}

// ============================================
// UI Helper
// ============================================

export function showScreen(screenName) {
    const screens = {
        //login: document.getElementById('loginPage'),
        main: document.getElementById('dashboard')
    };
    
    Object.values(screens).forEach(screen => screen.classList.remove('active'));
    screens[screenName].classList.add('active');
}


// Separate Funktion für Button-Erstellung
export function createMobileMenuButton() {

    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    mobileMenuBtn.setAttribute('aria-label', 'Menu');
    mobileMenuBtn.innerHTML = `
        <span></span>
        <span></span>
        <span></span>
    `;
    
    // Initial IMMER versteckt
    mobileMenuBtn.style.display = 'none';
    document.body.appendChild(mobileMenuBtn);

    //const mobileScreen = document.getElementById('dashboard');
        
    const sidebar = document.querySelector('.sidebar');
    //const sidebarOverlay = document.createElement('div');
    //sidebarOverlay.className = 'sidebar-overlay';
    //mobileScreen.appendChild(sidebarOverlay);    
    
    // Toggle Funktion
    mobileMenuBtn.addEventListener('click', function() {
        sidebar.classList.toggle('mobile-open');
        //sidebarOverlay.classList.toggle('active');
        this.classList.toggle('active');
    });
    
    
    /*
    // Schließen bei Overlay-Click
    sidebarOverlay.addEventListener('click', function() {
        sidebar.classList.remove('mobile-open');
        this.classList.remove('active');
        mobileMenuBtn.classList.remove('active');
    });
    */
    
    // Responsive Handler
    window.addEventListener('resize', updateMobileMenuVisibility);
}

export function toggleMobileMenuButton()
{
    // Mobile Menu Toggle - nach window.load Event
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.querySelector('.sidebar');

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-open');
            this.classList.toggle('active');
        });
        
        // Schließe Sidebar bei Click außerhalb
        document.addEventListener('click', function(e) {
            if (!sidebar.contains(e.target) && !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('mobile-open');
                mobileMenuBtn.classList.remove('active');
            }
        });
    }
}

export function showToast(message, type = 'info', duration = TOAST_DURATION) {
    const container = document.getElementById('toastContainer');
    
    // Icons je nach Typ
    const icons = {
        success: '✓',
        error: '✕',
        warning: '⚠',
        info: 'ℹ'
    };
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.style.position = 'relative';
    
    toast.innerHTML = `
        <div class="toast-icon">${icons[type] || icons.info}</div>
        <div class="toast-content">
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">×</button>
        ${duration > 0 ? '<div class="toast-progress"></div>' : ''}
    `;
    
    container.appendChild(toast);
    
    // Limitiere auf max 3 Toasts
    while(container.children.length > 3) {
        container.firstChild.remove();
    }
    
    // Auto-Remove nach duration
    if(duration > 0) {
        setTimeout(() => {
            toast.classList.add('removing');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
}

export async function showConfirm(message, title = 'Bestätigung') {
    return new Promise((resolve) => {
        const modal = document.getElementById('confirmModal');
        const messageEl = document.getElementById('confirmMessage');
        const titleEl = document.getElementById('confirmTitle');
        const okBtn = document.getElementById('confirmOk');
        const cancelBtn = document.getElementById('confirmCancel');
        
        messageEl.textContent = message;
        titleEl.textContent = title;
        
        modal.classList.add('active');
        
        // Focus auf Abbrechen-Button (sicherer)
        cancelBtn.focus();
        
        function cleanup() {
            modal.classList.remove('active');
            okBtn.replaceWith(okBtn.cloneNode(true));  // Remove event listeners
            cancelBtn.replaceWith(cancelBtn.cloneNode(true));
            
            // Neue Referenzen holen nach cloneNode
            const newOkBtn = document.getElementById('confirmOk');
            const newCancelBtn = document.getElementById('confirmCancel');
        }
        
        // OK geklickt
        document.getElementById('confirmOk').addEventListener('click', function handler() {
            cleanup();
            resolve(true);
        }, { once: true });
        
        // Abbrechen geklickt
        document.getElementById('confirmCancel').addEventListener('click', function handler() {
            cleanup();
            resolve(false);
        }, { once: true });
        
        // ESC-Taste
        function escHandler(e) {
            if (e.key === 'Escape') {
                cleanup();
                document.removeEventListener('keydown', escHandler);
                resolve(false);
            }
        }
        document.addEventListener('keydown', escHandler);
    });
}

/**
 * Auswahl zwischen mehreren Wegen, z. B. "Nur dieser" / "Dieser und alle
 * folgenden". Liefert den value der gewaehlten Moeglichkeit oder null bei
 * Abbrechen und ESC.
 *
 * @param {string} message
 * @param {string} title
 * @param {Array<{value: string, label: string, className?: string}>} choices
 * @returns {Promise<string|null>}
 */
export function showChoice(message, title, choices) {
    return new Promise((resolve) => {
        const modal = document.getElementById('choiceModal');
        const footer = document.getElementById('choiceButtons');
        document.getElementById('choiceTitle').textContent = title;
        document.getElementById('choiceMessage').textContent = message;
        footer.innerHTML = '';

        const finish = (value) => {
            modal.classList.remove('active');
            document.removeEventListener('keydown', onKey);
            resolve(value);
        };
        const onKey = (e) => {
            if (e.key === 'Escape') finish(null);
        };

        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn-cancel';
        cancel.textContent = 'Abbrechen';
        cancel.addEventListener('click', () => finish(null));
        footer.appendChild(cancel);

        choices.forEach(choice => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = choice.className || 'btn-save';
            btn.textContent = choice.label;
            btn.addEventListener('click', () => finish(choice.value));
            footer.appendChild(btn);
        });

        document.addEventListener('keydown', onKey);
        modal.classList.add('active');
        cancel.focus();
    });
}

/**
 * Eigener Begruendungsdialog als Ersatz fuer window.prompt(): blockierende
 * Browserdialoge passen sich nicht an die Optik der Oberflaeche an und
 * werden z.B. in installierten PWAs teils unterdrueckt.
 *
 * @param {Object}   options
 * @param {string}   options.title         Modal-Titel
 * @param {string}   [options.message]     Erlaeuternder Text ueber dem Feld
 * @param {string}   [options.value]       Vorbelegter Text (z.B. bestehende Bemerkung)
 * @param {string}   [options.placeholder] Platzhaltertext des Feldes
 * @param {string}   [options.confirmLabel] Beschriftung des Bestaetigen-Buttons
 * @returns {Promise<string|null>} Getrimmter Text bei Bestaetigung, null bei
 *          Abbruch/Escape/Schliessen
 */
export async function showReasonDialog({ title = 'Begründung', message = '', value = '', placeholder = '', confirmLabel = 'Bestätigen' } = {}) {
    return new Promise((resolve) => {
        const modal = document.getElementById('reasonModal');
        const titleEl = document.getElementById('reasonTitle');
        const messageEl = document.getElementById('reasonMessage');
        const textarea = document.getElementById('reasonText');
        const okBtn = document.getElementById('reasonOk');
        const cancelBtn = document.getElementById('reasonCancel');

        titleEl.textContent = title;
        messageEl.textContent = message;
        messageEl.hidden = !message;
        textarea.value = value ?? '';
        textarea.placeholder = placeholder ?? '';
        okBtn.textContent = confirmLabel;

        function updateOkState() {
            okBtn.disabled = textarea.value.trim() === '';
        }
        updateOkState();

        modal.classList.add('active');
        textarea.focus();
        textarea.select();

        function cleanup() {
            modal.classList.remove('active');
            textarea.removeEventListener('input', updateOkState);
            textarea.removeEventListener('keydown', textareaKeyHandler);
            document.removeEventListener('keydown', escHandler);
            okBtn.replaceWith(okBtn.cloneNode(true));  // Remove event listeners
            cancelBtn.replaceWith(cancelBtn.cloneNode(true));
        }

        function confirmAction() {
            if (textarea.value.trim() === '') return;
            const text = textarea.value.trim();
            cleanup();
            resolve(text);
        }

        function cancelAction() {
            cleanup();
            resolve(null);
        }

        // Enter ohne Umschalt bestaetigt, mit Umschalt bleibt es ein Umbruch.
        function textareaKeyHandler(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                confirmAction();
            }
        }

        function escHandler(e) {
            if (e.key === 'Escape') {
                cancelAction();
            }
        }

        textarea.addEventListener('input', updateOkState);
        textarea.addEventListener('keydown', textareaKeyHandler);
        document.addEventListener('keydown', escHandler);

        document.getElementById('reasonOk').addEventListener('click', confirmAction, { once: true });
        document.getElementById('reasonCancel').addEventListener('click', cancelAction, { once: true });
    });
}

export function updateUIForRole() {

    // Sections für Admin und Manager sichtbar
    // Inline "block" bricht Flex-Zeilen (Filterleiste); ein leerer Wert
    // laesst die Stylesheet-Regel gelten.
    document.querySelectorAll('[data-role="admin"]').forEach(el => {
        el.style.display = isAdmin ? '' : 'none';
    });

    document.querySelectorAll('[data-role="manager"]').forEach(el => {
        el.style.display = isAdminOrManager ? '' : 'none';
    });

    // Zeige Tabs nur für Admins
    const navTabs = document.querySelector('.nav-tabs');
    if (navTabs && isAdmin) {
        navTabs.style.display = 'flex';
    }
    
    // Initialisiere Tabs
    if (isAdmin) {
        initNavTabs();
    }
    
    // Filter-Leiste anpassen
    if (!isAdminOrManager) {
        const filterMember = document.getElementById('filterMember');
        if (filterMember) {
            filterMember.style.display = 'none';
            const label = document.querySelector('label[for="filterMember"]');
            if (label) label.style.display = 'none';
        }
    }
}

/**
 * Wortgleich mit der Bedingung in public/css/responsive.css.
 *
 * Die Sichtbarkeit des Knopfes setzt diese Datei als Inline-Style, und der
 * schlaegt jede CSS-Regel — die Schwelle steht damit zwangslaeufig zweimal da.
 * Wer sie hier aendert, aendert sie auch dort, sonst blendet das eine ein, was
 * das andere ausgeblendet laesst. Der Hoehenteil ist fuer das Querformat:
 * Ein Telefon ist quer breiter als 768 px, aber nur rund 400 px hoch.
 */
const MOBILE_NAV_QUERY = '(max-width: 768px), (max-height: 500px)';

// Separate Funktion für Sichtbarkeit
export function updateMobileMenuVisibility() {
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (!mobileMenuBtn) return;
    
    const isLoggedIn = document.getElementById('dashboard').classList.contains('active');
    const isMobile = window.matchMedia(MOBILE_NAV_QUERY).matches;
    
    mobileMenuBtn.style.display = (isLoggedIn && isMobile) ? 'flex' : 'none';
}


function closeMobileSidebar(){
    // Schließe Sidebar auf Mobile nach Klick auf Menu Item
    const sidebar = document.querySelector('.sidebar');
    //const overlay = document.querySelector('.sidebar-overlay');
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    
    if (sidebar && sidebar.classList.contains('mobile-open')) {
        sidebar.classList.remove('mobile-open');
        //if (overlay) overlay.classList.remove('active');
        if (mobileMenuBtn) mobileMenuBtn.classList.remove('active');
    }
}

// Nach Login/Session-Check setzen
export function showDashboard() {
    document.getElementById('dashboard').classList.add('active');
    
    if (currentUser) {
        // User-Name anzeigen (falls member_id vorhanden)
        let displayName = currentUser.email;
        
        if (currentUser.member_id && currentUser.member_name) {
            // Wenn Mitglied verknüpft ist, zeige Namen
            displayName = currentUser.member_name;
        }

        document.getElementById('currentUser').textContent = displayName;

        // Rolle übersetzen
        const roleTranslations = {
            'admin': 'Administrator',
            'manager': 'Manager',
            'user': 'Benutzer'
        };

        const roleName = roleTranslations[currentUser.role] || currentUser.role;
        document.getElementById('userRole').textContent = roleName;
        
        // UI anpassen nach Rolle
        updateUIForRole();
        updateTableHeaders();

        // Setze aktive Navigation basierend auf gespeicherter Section
        let section = sessionStorage.getItem('currentSection');
                
        // Validiere Section für User
        if (!section || (section === 'mitglieder' && !isAdminOrManager)) {
            section = 'profil';
            sessionStorage.setItem('currentSection', section);
        }

        const userTerminInfo = document.getElementById('userTerminInfo');
        if (userTerminInfo) {
            userTerminInfo.style.display = isAdminOrManager ? 'none' : 'block';
        }
        
        // Aktiviere entsprechende Navigation
        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
        
        const navItem = document.querySelector(`.nav-item[data-section="${section}"]`);
        const contentSection = document.getElementById(section);
        
        if (navItem && contentSection) {
            navItem.classList.add('active');
            contentSection.classList.add('active');
        }

        // Zeige Button falls Mobile
        updateMobileMenuVisibility();
    }
}    

// Funktion um Tabellen-Header dynamisch anzupassen
export function updateTableHeaders() {
    const tables = [
        { id: 'membersTableBody', headers: ['Name', 'Vorname', 'Mitgliedsnummer', 'Gruppen','Status'] },
        { id: 'appointmentsTableBody', headers: ['Termin', 'Terminart', 'Beschreibung', 'Rückmeldung'] },
        { id: 'recordsTableBody', headers: ['Termin', 'Terminart', 'Mitglied', 'Ankunftszeit', 'Status','Quelle'] },
        { id: 'exceptionsTableBody', headers: ['Typ', 'Mitglied', 'Termin', 'Begründung', 'Gewünschte Zeit', 'Status', 'Erstellt am'] }
    ];
    
    tables.forEach(table => {
        const tbody = document.getElementById(table.id);
        const thead = tbody.closest('table').querySelector('thead tr');
        
        // Entferne alle th
        thead.innerHTML = '';
        
        // Füge Header hinzu
        table.headers.forEach(header => {
            thead.innerHTML += `<th>${header}</th>`;
        });
        
        // Füge Aktionen-Spalte nur für Admins hinzu
        if (isAdminOrManager || (table.id === 'exceptionsTableBody')) {
            thead.innerHTML += '<th>Aktionen</th>';
        }
    });
}

export async function initNavigation() {
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', async function() {
            // Ungespeicherte Einstellungen gingen beim Bereichswechsel bisher
            // kommentarlos verloren. Mit den Untertabs ist die offene Aenderung
            // womoeglich gar nicht sichtbar — deshalb eine Rueckfrage.
            const verlaesstEinstellungen =
                sessionStorage.getItem('currentSection') === 'einstellungen'
                && this.getAttribute('data-section') !== 'einstellungen';

            if (verlaesstEinstellungen) {
                const { hasUnsavedSettings } = await import('./settings.js');

                if (hasUnsavedSettings()) {
                    const weiter = await showConfirm(
                        'Es gibt ungespeicherte Einstellungen. Der Bereich wird ohne Speichern verlassen.',
                        'Änderungen verwerfen?'
                    );

                    if (!weiter) {
                        return;
                    }
                }
            }

            document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
            document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
            
            this.classList.add('active');
            const section = this.getAttribute('data-section');
            document.getElementById(section).classList.add('active');            

             // Speichere aktuelle Section
            sessionStorage.setItem('currentSection', section);

            debug.log("==== SECTION CHANGED ===>", section);            
            loadAllData();

            closeMobileSidebar();
            
        });
    });
}


export function initNavTabs() {
    const tabs = document.querySelectorAll('.nav-tab-btn');
    const mainNav = document.querySelector('.nav-menu[data-nav-group="main"]');
    const systemNav = document.querySelector('.nav-menu[data-nav-group="system"]');
    
    if (!tabs.length || !mainNav || !systemNav) return;

    const savedTab = sessionStorage.getItem('currentNavTab') || 'main';
    
    // Entferne ALLE active Klassen zuerst
    tabs.forEach(t => t.classList.remove('active'));

    // INITIAL STATE - zeige main
    if (savedTab === 'main') {
        mainNav.style.display = 'block';
        systemNav.style.display = 'none';
        tabs[0].classList.add('active');
    } else {
        mainNav.style.display = 'none';
        systemNav.style.display = 'block';
        tabs[1].classList.add('active');
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.stopPropagation();

            const targetGroup = tab.dataset.navTab;            
            
             // Tab-Auswahl speichern
            sessionStorage.setItem('currentNavTab', targetGroup);

            // Tab aktiv setzen
            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            
            // Navigation umschalten
            if (targetGroup === 'main') {
                mainNav.style.display = 'block';
                systemNav.style.display = 'none';
            } else {
                mainNav.style.display = 'none';
                systemNav.style.display = 'block';
            }
            
            // Prüfe ob aktives Item noch sichtbar ist
            const activeItem = document.querySelector('.nav-item.active');
            const visibleNav = targetGroup === 'main' ? mainNav : systemNav;        

           // Wenn aktives Item nicht in sichtbarer Nav ist
            if (!activeItem || !visibleNav.contains(activeItem)) {
                const firstItem = visibleNav.querySelector('.nav-item');
                if (firstItem) {
                    // NICHT .click() verwenden - manuell aktivieren!
                    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
                    document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
                    
                    firstItem.classList.add('active');
                    const section = firstItem.getAttribute('data-section');
                    const contentSection = document.getElementById(section);
                    if (contentSection) {
                        contentSection.classList.add('active');
                    }
                    
                    sessionStorage.setItem('currentSection', section);
                    loadAllData();                
                }
            }
        });
    });
}

export async function initEventHandlers()
{
    initAppointmentEventHandlers();
    initRecordEventHandlers();
    initExceptionEventHandlers();
    initStatisticsEventHandlers();
    initUsersEventHandlers();
    initProfileEventHandler();
    initWorktimeEventHandlers();

    // Blendet Navigationspunkt und Stammdatenblock ein, sofern die
    // Zeiterfassung freigeschaltet ist. Ist sie es nicht, antwortet
    // activity_types mit 404 und beides bleibt verborgen.
    if (await checkWorktimeEnabled()) {
        const block = document.getElementById('activityTypesBlock');
        if (block && isAdmin) {
            block.style.display = '';
            await loadActivityTypes(true);
        }
    }
}


export async function loadAllData() {
    const section = sessionStorage.getItem('currentSection') || 'profil';

    const sectionsUsingTypes = ['verwaltung', 'anwesenheit', 'termine'];

    debug.log("== LOAD ALL DATA == ")

    switch(section)
    {
        case 'profil':
            await loadProfile(true);
            break;
        case 'mitglieder':
            await showMemberSection(true);
            break;
        case 'termine':
            await showAppointmentSection(true);
            break;
        case 'anwesenheit':
            await showRecordsSection(true);
            break;
        case 'antraege':
            await showExceptionSection(true);
            break;
        case 'benutzer':
            if(isAdmin){
                await showUserSection(true);
            }            
            break;
        case 'geraete':
            if(isAdmin){
                await showDeviceSection(true);
            }            
            break;
        case 'verwaltung':
            if(isAdminOrManager){
                await showGroupSection(true);
            }
            break;
        case 'statistik':            
            await showStatisticsSection();            
            break;
        case 'zeiterfassung':
            await showWorktimeSection(true);
            break;
        case  'einstellungen':
            if(isAdmin){
                await renderSystemSettings();
            }
            break;
        case 'import-logs':
            if(isAdmin){
                await loadImportLogs();
            }
            break;

        default:
            break;
    }        

    
    // Hintergrund-Laden nur für ungecachte Daten
    setTimeout(() => {
        if ((section !== 'mitglieder') && !isCacheValid('members', currentYear))
            loadMembers();
        if ((section !== 'termine') && !isCacheValid('appointments',currentYear)) 
            loadAppointments();
        if ((section !== 'anwesenheit') && !isCacheValid('records',currentYear)) {
            loadRecords();
        }
        if ((section !== 'antraege') && !isCacheValid('exceptions',currentYear)) {
            loadExceptions();
        }  
        if(!sectionsUsingTypes.includes(section))
        {            
            if (!isCacheValid('groups')) loadGroups();
            if (!isCacheValid('types')) loadTypes();
        }        
        if(isAdmin)
        {
            if((section !== 'benutzer') && !isCacheValid('users'))
            {
                loadUsers(true);
            }
        }
    }, 500);
    
}


async function loadYearDependentData() {
    const section = sessionStorage.getItem('currentSection');
    
    // Nur aktive Section neu laden
    if (section === 'termine') {
        await showAppointmentSection();
    } else if (section === 'anwesenheit') {        
        await showRecordsSection();
    } else if (section === 'antraege') {
        await showExceptionSection();
    } else if (section === 'statistik') {
        await showStatisticsSection();
    } else if(section === 'mitglieder') {
        await showMemberSection();
    } else if (section === 'zeiterfassung') {
        await showWorktimeSection();
    }
}

export function initModalEscHandler() {
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            // Finde alle aktiven Modals (außer Confirmation-Modal)
            const activeModals = document.querySelectorAll('.modal.active:not(#confirmModal)');
            
            // Schließe das zuletzt geöffnete Modal
            if (activeModals.length > 0) {
                const lastModal = activeModals[activeModals.length - 1];
                lastModal.classList.remove('active');
            }
        }
    });
}

// ============================================
// VERSION
// ============================================

export async function loadVersion() {
    try {
        const version = await apiCall('version');

        if(version.success)
        {
            document.getElementById('app-version').textContent = `v${version.version}`;
            showConfigFormatHint(version.config_format);
            showUpdateHint(version.update_available);
        }

    } catch (error) {
        console.error('Version load failed:', error);
    }
}

/**
 * Weist den Admin darauf hin, dass private/config/config.php noch in der alten
 * Form vorliegt. Das passiert, wenn die Migration auf 1.6.0 sie nicht schreiben
 * konnte -- die Anwendung laeuft dann weiter, aber die Umstellung fehlt noch.
 * Das Feld kommt nur fuer die Rolle admin aus der API.
 */
function showConfigFormatHint(format) {
    if (format !== 'legacy' || document.getElementById('config-format-hint')) {
        return;
    }

    const ziel = document.querySelector('.main-content');
    if (!ziel) {
        return;
    }

    const hinweis = document.createElement('div');
    hinweis.id = 'config-format-hint';
    hinweis.className = 'alert alert-warning';
    hinweis.textContent = 'Die Konfigurationsdatei liegt noch in der alten Form vor. '
        + 'Bitte den Update-Assistenten erneut ausführen oder '
        + 'private/config/config.php beschreibbar machen.';
    ziel.prepend(hinweis);
}

/**
 * Nennt dem Admin eine verfügbare neuere Version. Die Nummer stammt aus der
 * zuletzt auf Knopfdruck gespeicherten Prüfung; der Server liefert sie nur,
 * solange sie höher ist als die installierte.
 */
function showUpdateHint(available) {
    if (!available || document.getElementById('update-available-hint')) {
        return;
    }

    const ziel = document.querySelector('.main-content');
    if (!ziel) {
        return;
    }

    const hinweis = document.createElement('div');
    hinweis.id          = 'update-available-hint';
    hinweis.className   = 'alert alert-info';
    hinweis.textContent = `Version ${available} ist verfügbar. Anleitung unter Einstellungen → Updates.`;
    ziel.prepend(hinweis);
}

// ============================================
// PWA QUICK ACCESS
// ============================================

export function initPWAQuickAccess() {
    const openPWABtn = document.getElementById('openPWABtn');
    if (!openPWABtn) return;
    
    openPWABtn.addEventListener('click', () => {
        const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
        const baseUrl = window.location.origin + window.location.pathname.replace('index.html', '');
        const pwaUrl = baseUrl + 'checkin/';
        
        if (isMobile) {
            // Direkt öffnen auf Mobile
            window.open(pwaUrl, '_blank');
        } else {
            // QR-Code auf Desktop
            showQRModal({
                title: '📱 Check-In App öffnen',
                url: pwaUrl,
                hint: 'Scanne den QR-Code mit deinem Smartphone'
            });
        }
    });
}

/**
 * QR-Modal für beliebige Adressen. Wird vom PWA-Quicklink und von der
 * Schnellinbetriebnahme des Kiosks benutzt.
 *
 * @param {{title: string, url: string, hint?: string, warning?: string}} options
 */
export function showQRModal({ title, url, hint, warning }) {
    let modal = document.getElementById('qrModal');

    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'qrModal';
        modal.className = 'qr-modal';
        modal.innerHTML = `
            <div class="qr-modal-content">
                <h2 id="qrModalTitle"></h2>
                <div id="qrcode"></div>
                <p id="qrModalHint"></p>
                <p id="qrModalWarning" class="qr-modal-warning"></p>
                <p style="font-size: 12px; margin-top: 10px;">
                    <strong>Oder kopiere:</strong><br>
                    <input type="text" id="qrModalUrl" readonly
                           style="width: 100%; padding: 8px; margin-top: 5px; font-size: 12px; text-align: center;">
                </p>
                <button class="btn-close-qr">Schließen</button>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.btn-close-qr').addEventListener('click', () => {
            modal.classList.remove('active');
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    }

    // Inhalt bei JEDEM Aufruf setzen, nicht nur beim ersten: das Modal wird von
    // mehreren Stellen benutzt und behielte sonst Titel, Adresse und QR-Bild
    // des vorigen Aufrufers.
    document.getElementById('qrModalTitle').textContent = title;
    document.getElementById('qrModalHint').textContent = hint || '';
    document.getElementById('qrModalUrl').value = url;

    const warningEl = document.getElementById('qrModalWarning');
    warningEl.textContent = warning || '';
    warningEl.style.display = warning ? 'block' : 'none';

    // Erst zeigen, dann zeichnen: schlaegt die QR-Erzeugung fehl, bleibt das
    // Modal mit der kopierbaren Adresse offen und sagt, was fehlt. Vor dem
    // Vendoring fing der Fallback-Zweig genau das ab — ohne Ersatz waere ein
    // defektes Deployment nur ein Knopf, der sichtbar nichts tut.
    modal.classList.add('active');

    try {
        const qr = qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        document.getElementById('qrcode').innerHTML = qr.createSvgTag(5, 2);
    } catch (e) {
        console.error('QR-Erzeugung fehlgeschlagen:', e);
        document.getElementById('qrcode').textContent =
            'QR-Code konnte nicht erzeugt werden — bitte die Adresse unten verwenden.';
    }
}
