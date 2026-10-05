/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

/**
 * Abschaltbare Funktionen (OI-62). Der Stand kommt aus der Antwort von me
 * (Feld features); private/helpers/features.php ist die Quelle.
 *
 * applyFeatureVisibility() blendet Elemente mit data-feature aus, deren
 * Funktion aus ist, und blendet nur die wieder ein, die es selbst
 * ausgeblendet hat (data-feature-hidden). So bleibt ein Element, das ein
 * Modul aus eigenen Gruenden verbirgt (etwa die Zeiterfassung fuer ein
 * Mitglied ohne Taetigkeitsart, worktime.js), verborgen.
 */

let features = {};

export function setFeatures(obj) {
    features = (obj && typeof obj === 'object') ? { ...obj } : {};
}

export function isFeatureOn(key) {
    return features[key] === true;
}

export function applyFeatureVisibility() {
    document.querySelectorAll('[data-feature]').forEach(el => {
        if (!isFeatureOn(el.dataset.feature)) {
            el.style.display = 'none';
            el.dataset.featureHidden = '1';
        } else if (el.dataset.featureHidden === '1') {
            el.style.display = '';
            delete el.dataset.featureHidden;
        }
    });
}

/** Nach dem Speichern der Einstellungen: Stand neu holen und anwenden. */
export async function refreshFeatures() {
    // Schlaegt `me` fehl, bleibt der bisherige Stand bewusst stehen (apiCall zeigt schon einen Toast).
    const { apiCall } = await import('./api.js');
    const me = await apiCall('me');
    if (me && me.features) {
        setFeatures(me.features);
        applyFeatureVisibility();
    }
}
