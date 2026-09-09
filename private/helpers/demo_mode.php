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
 * Eingeschaltet wird er über `define('DEMO_MODE', ...);` in
 * private/config/config.php — einer Datei, die nicht im Repository liegt.
 * Fehlt die Konstante, ist der Wächter wirkungslos. Wie der Wert genau
 * gelesen wird — auch bei einem unsauberen Wert wie `1` statt `true` —
 * steht bei demoModeActive().
 *
 * Die Regel lautet: GET und HEAD gehen durch, sofern die Ressource in einer
 * der drei Listen steht (DEMO_WRITE_ALLOWED, DEMO_WRITE_DENIED oder
 * DEMO_READ_ONLY) — eine unbekannte Ressource ist auch lesend gesperrt.
 * Jeder schreibende Zugriff muss ausdrücklich in DEMO_WRITE_ALLOWED stehen.
 * Eine künftig neue Ressource ist damit von selbst gesperrt, bis jemand
 * bewusst entscheidet, in welche Liste sie gehört.
 *
 * Eine Vollständigkeitsprüfung, die diese drei Listen gegen die tatsächlich
 * in api.php gerouteten Ressourcen abgleicht, folgt in einer späteren Aufgabe.
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
 * Den Sperreffekt liefert weiterhin allein das Fehlen in DEMO_WRITE_ALLOWED
 * — diese Liste selbst sperrt keinen Schreibzugriff. Seit demoRequestAllowed()
 * aber nur bekannte Ressourcen lesend durchlässt, macht ein Eintrag hier die
 * Ressource bekannt und öffnet damit GET und HEAD. Wer einen Eintrag als rein
 * kosmetisch entfernt, sperrt damit unbemerkt auch das Lesen dieser Ressource.
 *
 * Daneben hält die Liste fest, dass die Entscheidung „gesperrt" bewusst
 * getroffen wurde, damit die Vollständigkeitsprüfung „bedacht und gesperrt"
 * von „vergessen" unterscheiden kann.
 */
const DEMO_WRITE_DENIED = [
    'change_password',        // macht die veröffentlichten Zugangsdaten unbrauchbar
    'change_pin',             // sperrt den direkten Weg; über members PUT bleibt
                              // pin_hash erreichbar, das fängt der stündliche Reset auf
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
 * Sie trägt die Annahme, auf der die Regel „GET und HEAD gehen durch, sofern
 * die Ressource bekannt ist" (demoRequestAllowed()) ruht.
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

/**
 * Ist der Demo-Modus eingeschaltet?
 *
 * Fällt zur sicheren Seite: Fehlt die Konstante, ist der Modus aus, und
 * `false` bleibt ein gültiges „aus" — ein Betreiber muss den Modus
 * ausdrücklich abschalten können. Aber jeder andere Wert als `true` oder
 * `false` (z. B. `1`, `'true'`, `'false'` als Zeichenkette) gilt als „an".
 *
 * Grund: Ein `define('DEMO_MODE', 1);` in der Konfigurationsdatei sollte den
 * Wächter nicht stillschweigend abschalten. Ein früherer Entwurf prüfte
 * streng auf `=== true` — damit hätte ein Tippfehler in config.php die
 * öffentliche Demo ungeschützt gelassen, ohne jeden Hinweis. Ein unsauberer
 * Wert ist ein Versehen, kein bewusstes „aus", und wird deshalb als „an"
 * behandelt; zusätzlich landet ein Hinweis im error_log, damit das Versehen
 * nicht unbemerkt bleibt.
 */
function demoModeActive(): bool
{
    if (!defined('DEMO_MODE')) {
        return false;
    }

    if (DEMO_MODE === false) {
        return false;
    }

    if (DEMO_MODE === true) {
        return true;
    }

    error_log(
        'DEMO_MODE ist gesetzt, aber weder true noch false (Wert: '
        . var_export(DEMO_MODE, true) . '). Wird als eingeschaltet behandelt, '
        . 'weil ein unsauberer Wert zur sicheren Seite fallen muss.'
    );

    return true;
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

    // HEAD ist semantisch ein GET ohne Körper, deshalb behandelt der Wächter
    // es hier gleich. Ob die Handler dahinter HEAD tatsächlich annehmen, ist
    // ihre Sache — heute überwiegend nein.
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
