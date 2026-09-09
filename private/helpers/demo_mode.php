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
 * Die Regel lautet: GET und HEAD gehen durch, sofern die Ressource in einer
 * der drei Listen steht (DEMO_WRITE_ALLOWED, DEMO_WRITE_DENIED oder
 * DEMO_READ_ONLY) — eine unbekannte Ressource ist auch lesend gesperrt.
 * Jeder schreibende Zugriff muss ausdrücklich in DEMO_WRITE_ALLOWED stehen.
 * Eine künftig neue Ressource ist damit von selbst gesperrt, bis jemand
 * bewusst entscheidet, in welche Liste sie gehört.
 *
 * Eine Vollständigkeitsprüfung, die diese drei Listen gegen die tatsächlich
 * in api.php geroutete Ressourcen abgleicht, folgt in einer späteren Aufgabe.
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
    'change_pin',             // sperrt den direkten Weg; ueber members PUT bleibt
                               // pin_hash erreichbar, das faengt der stuendliche Reset auf
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
 * Darf diese Kombination aus Ressource und Methode durch?
 *
 * Reine Entscheidung ohne Nebenwirkung und ohne Rücksicht auf DEMO_MODE —
 * deshalb im Test ohne Klimmzüge prüfbar. Der Name sagt bewusst nicht
 * "Write" — die Funktion entscheidet auch über lesende Anfragen.
 */
function demoRequestAllowed(string $resource, string $method): bool
{
    $known = isset(DEMO_WRITE_ALLOWED[$resource])
          || in_array($resource, DEMO_WRITE_DENIED, true)
          || in_array($resource, DEMO_READ_ONLY, true);

    // HEAD verhaelt sich wie GET. Ohne diesen Zweig liefe eine Ueberwachung
    // oder ein Linkpruefer in der Demo in ein 403.
    if ($method === 'GET' || $method === 'HEAD') {
        return $known;
    }

    return in_array($method, DEMO_WRITE_ALLOWED[$resource] ?? [], true);
}

/** Bricht die Anfrage ab, wenn der Demo-Modus sie nicht zulässt. */
function demoGuard(string $resource, string $method): void
{
    if (!demoModeActive() || demoRequestAllowed($resource, $method)) {
        return;
    }

    http_response_code(403);
    echo json_encode([
        'message' => 'In der Demo ist diese Funktion abgeschaltet. '
                   . 'Die vollständige Anwendung steht zum Herunterladen bereit.',
        'demo'    => true,
    ]);
    exit();
}
