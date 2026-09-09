<?php

/**
 * EhrenSache - Anwesenheitserfassung fürs Ehrenamt
 *
 * Copyright (c) 2026 Martin Maier
 *
 * Dieses Programm ist unter der AGPL-3.0-Lizenz für gemeinnützige Nutzung
 * oder unter einer kommerziellen Lizenz verfügbar.
 * Siehe LICENSE und COMMERCIAL-LICENSE.md für Details.
 */

declare(strict_types=1);

/**
 * Demo-Modus für öffentlich erreichbare Installationen.
 *
 * Eingeschaltet wird er ausschließlich über `define('DEMO_MODE', true);` in
 * private/config/config.php — einer Datei, die nicht im Repository liegt.
 * Ohne diese Zeile ist alles hier wirkungslos.
 *
 * Die Regel lautet: GET geht immer durch, jeder schreibende Zugriff muss in
 * DEMO_WRITE_ALLOWED stehen. Eine Erlaubnisliste und keine Sperrliste, damit
 * eine künftig neue Ressource von selbst gesperrt ist, bis jemand bewusst
 * entscheidet. tests/suites/demo_mode.php erzwingt diese Entscheidung.
 */

/** Schreibende Zugriffe, die ein Demo-Besucher ausführen darf. */
const DEMO_WRITE_ALLOWED = [
    'login'             => ['POST'],
    'logout'            => ['POST'],
    'auth'              => ['POST'],
    'members'           => ['POST', 'PUT', 'DELETE'],
    'appointments'      => ['POST', 'PUT', 'DELETE'],
    'records'           => ['POST', 'PUT', 'DELETE'],
    'exceptions'        => ['POST', 'PUT', 'DELETE'],
    'work_sessions'     => ['POST', 'PUT', 'DELETE'],
    'activity_types'    => ['POST', 'PUT', 'DELETE'],
    'membership_dates'  => ['POST', 'PUT', 'DELETE'],
    'member_groups'     => ['POST', 'PUT', 'DELETE'],
    'appointment_types' => ['POST', 'PUT', 'DELETE'],
    'auto_checkin'      => ['POST'],
    'totp_checkin'      => ['POST'],
    'station'           => ['POST'],
];

/**
 * Ressourcen, deren Schreibzugriffe bewusst gesperrt sind.
 *
 * Diese Liste sperrt nichts — das tut schon das Fehlen in DEMO_WRITE_ALLOWED.
 * Sie hält fest, dass die Entscheidung getroffen wurde, damit die
 * Vollständigkeitsprüfung „bedacht und gesperrt" von „vergessen" unterscheiden
 * kann.
 */
const DEMO_WRITE_DENIED = [
    'change_password',        // macht die veröffentlichten Zugangsdaten unbrauchbar
    'change_pin',             // macht die veröffentlichte Kiosk-PIN unbrauchbar
    'users',                  // Konten und Rollen
    'activate_user',          // Konten
    'user_status',            // Konten
    'register',               // Mailversand an fremde Adressen
    'password_reset_request', // Mailversand an fremde Adressen
    'settings',               // könnte smtp_configured setzen und den Mailschutz aufheben
    'upload-logo',            // Dateiannahme
    'import',                 // Dateiannahme
    'cleanup',                // löscht Daten
    'regenerate_token',       // erzeugt API-Zugangsmittel
];

/**
 * Ressourcen, die ausschließlich lesen.
 *
 * Ein Eintrag hier ist eine Behauptung: „diese Ressource verändert nichts".
 * Sie trägt die Annahme, auf der die Regel „GET geht durch" ruht.
 */
const DEMO_READ_ONLY = [
    'ping',
    'appearance',
    'me',
    'version',
    'session_info',
    'my_data',
    'statistics',
    'available_years',
    'attendance_list',
    'import_logs',
    'export',
];

/** Ist der Demo-Modus eingeschaltet? Streng auf true, damit 'false' als Zeichenkette nicht greift. */
function demoModeActive(): bool
{
    return defined('DEMO_MODE') && DEMO_MODE === true;
}

/**
 * Darf diese Kombination aus Ressource und Methode schreiben?
 *
 * Reine Entscheidung ohne Nebenwirkung und ohne Rücksicht auf DEMO_MODE —
 * deshalb im Test ohne Klimmzüge prüfbar.
 */
function demoWriteAllowed(string $resource, string $method): bool
{
    if ($method === 'GET') {
        return true;
    }

    return in_array($method, DEMO_WRITE_ALLOWED[$resource] ?? [], true);
}

/** Bricht die Anfrage ab, wenn der Demo-Modus sie nicht zulässt. */
function demoGuard(string $resource, string $method): void
{
    if (!demoModeActive() || demoWriteAllowed($resource, $method)) {
        return;
    }

    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'In der Demo ist diese Funktion abgeschaltet. '
                   . 'Die vollständige Anwendung steht zum Herunterladen bereit.',
        'demo'    => true,
    ]);
    exit();
}
