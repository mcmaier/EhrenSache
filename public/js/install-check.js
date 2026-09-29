/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

// Installationspruefung des Dashboards. Stand bis OI-17 Etappe 2 als
// Inline-Script am Ende von index.html; die CSP (script-src 'self') fuehrt
// Inline-Skripte nicht aus. Die frueheren console.log/console.warn entfallen
// (assets.php erlaubt ungeschuetzt nur console.error).
(async function checkInstallation() {
    try {
        // Prüfe ob API erreichbar und DB konfiguriert ist
        const response = await fetch('./api/api.php?resource=ping', {
            method: 'GET',
            headers: { 'Content-Type': 'application/json' }
        });

        const result = await response.json();

        // Wenn "not_installed" Status → Weiterleitung
        if (result.status === 'not_installed') {
            window.location.href = './install/';
        }
    } catch (error) {
        // Bei Fehler auch zur Installation
        try {
            const installCheck = await fetch('./install/index.php');
            if (installCheck.ok) {
                window.location.href = './install/';
            }
        } catch (e) {
            console.error('Weder API noch Installation erreichbar');
        }
    }
})();
