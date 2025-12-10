// ============================================
// UTILS
// Reference:
//import {translateStatus,datetimeLocalToMysql, mysqlToDatetimeLocal, formatDateTime } from './utils.js';
// ============================================

export function translateRecordStatus(status) {
    const translations = {
        'present': 'Anwesend',
        'excused': 'Entschuldigt'
    };
    return translations[status] || status;
}

export function translateExceptionStatus(status) {
    const translations = {
        'pending': 'Ausstehend',
        'approved': 'Genehmigt',
        'rejected': 'Abgelehnt'
    };
    return translations[status] || status;
}

export function translateExceptionType(type) {
    const translations = {
        'absence': 'Entschuldigung',
        'time_correction': 'Zeitkorrektur'
    };
    return translations[type] || type;
}


export function datetimeLocalToMysql(datetimeLocalValue) {
    return datetimeLocalValue.replace('T', ' ') + ':00';
}

export function mysqlToDatetimeLocal(mysqlDateTime) {
    return mysqlDateTime.slice(0, 16).replace(' ', 'T');
}

export function formatDateTime(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    
    return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

// Zeigt/Versteckt ID-Badge im Modal-Header
export function updateModalId(modalHeaderId, entityId) {
    const header = document.querySelector(`#${modalHeaderId} .modal-header`);
    if (!header) return;
    
    // Entferne alten Badge falls vorhanden
    const oldBadge = header.querySelector('.modal-id-badge');
    if (oldBadge) oldBadge.remove();
    
    // Füge neuen Badge hinzu wenn ID vorhanden
    if (entityId) {
        const badge = document.createElement('span');
        badge.className = 'modal-id-badge';
        badge.textContent = entityId;
        
        // Füge vor dem Close-Button ein
        const closeBtn = header.querySelector('.close-modal');
        if (closeBtn) {
            header.insertBefore(badge, closeBtn);
        } else {
            header.appendChild(badge);
        }
    }
}

export function round(value, decimals) {
    return Number(Math.round(value + 'e' + decimals) + 'e-' + decimals);
}